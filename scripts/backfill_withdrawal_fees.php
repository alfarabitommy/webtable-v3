<?php
/**
 * Synapse (db_webtable) — Withdrawal Fee Backfill CLI (C3, plan/52 §6)
 *
 * Repairs LEGACY `withdrawals` rows where gross_amount/fee_amount/net_amount
 * are 0.00 or NULL (created before the C3 schema/code fix) by recomputing
 * them from `amount` using the PRD fee tiers in
 * application/config/withdrawal_fees.php (single source of truth).
 *
 *   --verify   (default) dry-run: list affected rows + before/after diff, writes nothing
 *   --apply    recompute + UPDATE inside ONE transaction
 *   --check    post-apply validation only (re-verifies an already-backfilled DB)
 *   --no-backup  skip the automatic PHP backup dump (default: writes backups/pre_backfill_*.sql)
 *
 * Idempotent: the guarded WHERE targets only rows still 0/NULL, so re-runs are no-ops.
 * Credentials are parsed from application/config/database.php (CI3).
 * Exit codes: 0 = clean, 1 = pre-flight failure, 2 = apply failure, 3 = validation failure.
 */

define('BASEPATH', 'cli-backfill-runner');
define('ENVIRONMENT', 'development');

error_reporting(E_ALL);
ini_set('display_errors', '1');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ROOT = dirname(__DIR__);
const BACKFILL_DB = 'db_webtable';

$args = $argv ?: [];
$apply     = in_array('--apply', $args, true);
$checkOnly = in_array('--check', $args, true);
$noBackup  = in_array('--no-backup', $args, true);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Usage: php scripts/backfill_withdrawal_fees.php [--verify|--apply|--check] [--no-backup]\n";
    exit(0);
}

// ---------------------------------------------------------------- config
$db = load_db_config($ROOT . '/application/config/database.php');
if (!$db) {
    fwrite(STDERR, "[FATAL] Cannot read DB credentials from application/config/database.php\n");
    exit(1);
}
$host = $db['hostname'] === 'localhost' ? '127.0.0.1' : $db['hostname']; // TCP (no unix socket in this env)

// Fee tiers — same single source the app model uses (plan/52 §4).
$fee_cfg = require $ROOT . '/application/config/withdrawal_fees.php';

// ---------------------------------------------------------------- connect
try {
    $m = new mysqli($host, $db['username'], $db['password'], $db['database'], 3306);
    $m->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, "[FATAL] Cannot connect to {$db['database']}: " . $e->getMessage() . "\n");
    exit(1);
}
echo "== Withdrawal fee backfill (C3) ==\n";
echo "Server: {$m->server_info}  DB: {$db['database']}\n\n";

// ---------------------------------------------------------------- pre-flight
$must_tables = ['withdrawals'];
foreach ($must_tables as $t) {
    $q = $m->query("SHOW TABLES LIKE '" . $m->real_escape_string($t) . "'");
    if (!$q || $q->num_rows === 0) {
        fwrite(STDERR, "[FATAL] Missing table: {$t}\n");
        exit(1);
    }
}
foreach (['amount', 'gross_amount', 'fee_amount', 'net_amount'] as $col) {
    $q = $m->query("SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = '" . $m->real_escape_string(BACKFILL_DB) . "'
                      AND TABLE_NAME   = 'withdrawals'
                      AND COLUMN_NAME  = '" . $m->real_escape_string($col) . "'");
    if (!$q || $q->num_rows === 0) {
        fwrite(STDERR, "[FATAL] withdrawals.{$col} missing — run schema reconciliation (seed runner / plan/52 §3.1) first\n");
        exit(1);
    }
}

// ---------------------------------------------------------------- scan
$legacy_sql = "SELECT id, amount, gross_amount, fee_amount, net_amount
               FROM withdrawals
               WHERE gross_amount IS NULL OR gross_amount = 0
                  OR fee_amount    IS NULL OR fee_amount    = 0
                  OR net_amount    IS NULL OR net_amount    = 0
               ORDER BY id";
$res = $m->query($legacy_sql);
$legacy = [];
while ($r = $res->fetch_assoc()) {
    $r['amount']       = (float) $r['amount'];
    $r['gross_amount'] = (float) $r['gross_amount'];
    $r['fee_amount']   = (float) $r['fee_amount'];
    $r['net_amount']   = (float) $r['net_amount'];
    $legacy[] = $r;
}

echo 'Legacy rows (gross/fee/net 0 or NULL): ' . count($legacy) . "\n";
if (!$legacy) {
    echo "Nothing to backfill — withdrawals already carry gross/fee/net.\n";
    if ($apply || $checkOnly) exit(run_validation($m));
    exit(0);
}

// Rows where amount == 0 too (fully empty) are reported, not silently zeroed.
$actionable = array_values(array_filter($legacy, fn($r) => $r['amount'] > 0));
$skipped    = count($legacy) - count($actionable);
if ($skipped > 0) {
    echo "  note: {$skipped} row(s) also have amount = 0 — nothing derivable, left untouched.\n";
}

echo "\nBefore/after diff (verify plan):\n";
printf("  %-6s %14s %14s %14s %14s  ->  %14s %14s %14s\n",
       'id', 'amount', 'gross_old', 'fee_old', 'net_old', 'gross_new', 'fee_new', 'net_new');
$updates = [];
foreach ($actionable as $r) {
    $gross = (int) $r['amount'];                       // gross := amount (legacy contract)
    $calc  = fee_for_gross($fee_cfg, $gross);
    $updates[] = ['id' => (int) $r['id'], 'gross' => $gross, 'fee' => $calc['fee'], 'net' => $calc['net']];
    printf("  %-6d %14d %14.2f %14.2f %14.2f  ->  %14d %14d %14d\n",
           (int) $r['id'], $gross, $r['gross_amount'], $r['fee_amount'], $r['net_amount'],
           $gross, $calc['fee'], $calc['net']);
}

if (!$apply) {
    echo "\nDRY-RUN (--verify): no changes made. Run: php scripts/backfill_withdrawal_fees.php --apply\n";
    exit(0);
}

// ---------------------------------------------------------------- backup
if ($apply && !$noBackup) {
    $backup = backup_table($m, $ROOT . '/backups');
    echo "\nBackup written: {$backup}\n";
}

// ---------------------------------------------------------------- apply
echo "\nApplying " . count($updates) . " UPDATE(s) in one transaction...\n";
$m->begin_transaction();
try {
    $stmt = $m->prepare("UPDATE withdrawals
                         SET gross_amount = ?, fee_amount = ?, net_amount = ?
                         WHERE id = ?");
    foreach ($updates as $u) {
        $stmt->bind_param('dddi', $u['gross'], $u['fee'], $u['net'], $u['id']);
        $stmt->execute();
    }
    $stmt->close();
    $m->commit();
    echo "  commit OK\n\n";
} catch (mysqli_sql_exception $e) {
    $m->rollback();
    fwrite(STDERR, "[FAIL] backfill: " . $e->getMessage() . "\n");
    fwrite(STDERR, "  Rolling back — no rows changed.\n");
    exit(2);
}

// ---------------------------------------------------------------- validate
exit(run_validation($m));

// ================================================================ helpers

function load_db_config($file)
{
    if (!is_file($file)) return null;
    include $file;
    return isset($db['default']) ? $db['default'] : null;
}

/** Mirror of Wallet_model::calculate_withdrawal_fee() (plan/52 §4.2). */
function fee_for_gross($cfg, $gross)
{
    $gross = (int) $gross;
    $bps   = end($cfg['tiers'])[2];
    foreach ($cfg['tiers'] as $tier) {
        if ($gross >= $tier[0] && $gross < $tier[1]) {
            $bps = $tier[2];
            break;
        }
    }
    $fee = (int) floor($gross * $bps / 10000) + (int) $cfg['fixed_fee'];
    return ['fee' => $fee, 'net' => $gross - $fee, 'bps' => $bps];
}

/** Lightweight PHP backup of the withdrawals table. */
function backup_table($m, $dir)
{
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $file = $dir . '/pre_backfill_' . date('Ymd_His') . '.sql';
    $fh = fopen($file, 'w');
    fwrite($fh, "-- Synapse db_webtable pre-backfill backup (" . date('c') . ")\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $create = $m->query("SHOW CREATE TABLE withdrawals")->fetch_row();
    fwrite($fh, $create[1] . ";\n\n");
    $rows = $m->query("SELECT * FROM withdrawals");
    while ($r = $rows->fetch_assoc()) {
        $cols = [];
        $vals = [];
        foreach ($r as $k => $v) {
            $cols[] = '`' . str_replace('`', '``', $k) . '`';
            $vals[] = $v === null ? 'NULL' : "'" . $m->real_escape_string($v) . "'";
        }
        fwrite($fh, "INSERT INTO `withdrawals` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n");
    }
    fclose($fh);
    return $file;
}

/** Post-apply validation. Returns exit code (0 = all green, 3 = failures). */
function run_validation($m)
{
    $fail = 0;
    $ok = function ($label, $cond, $extra = '') use (&$fail) { report($label, $cond, $extra, $fail); };

    echo "=== Validation ===\n";
    $q = $m->query("SELECT COUNT(*) FROM withdrawals
                    WHERE gross_amount IS NULL OR gross_amount = 0
                       OR fee_amount    IS NULL OR fee_amount    = 0
                       OR net_amount    IS NULL OR net_amount    = 0");
    $left = (int) $q->fetch_row()[0];
    $ok('no 0/NULL gross/fee/net rows remain', $left === 0, "remaining: {$left}");

    // Fee math spot-check against the tier config on a few recent rows.
    // Pre-C3 rows carrying old (non-zero) tiers are NOT backfill targets and are
    // reported as warnings, not failures — the hard gate is the 0/NULL invariant.
    $fee_cfg = require dirname(__DIR__) . '/application/config/withdrawal_fees.php';
    $q = $m->query("SELECT id, gross_amount, fee_amount, net_amount
                    FROM withdrawals
                    WHERE gross_amount > 0
                    ORDER BY id DESC LIMIT 25");
    $checked = 0;
    $warn = 0;
    while ($r = $q->fetch_assoc()) {
        $calc = fee_for_gross($fee_cfg, (int) $r['gross_amount']);
        $good = abs($r['fee_amount'] - $calc['fee']) < 0.01
             && abs($r['net_amount'] - $calc['net']) < 0.01;
        $checked++;
        if (!$good) {
            $warn++;
            printf("  [WARN] row id %d (gross %s): stored fee %s/net %s vs tier-calc %d/%d (pre-C3 seed tier — fix via re-seed plan/52 §5.4)\n",
                   (int) $r['id'], $r['gross_amount'], $r['fee_amount'], $r['net_amount'], $calc['fee'], $calc['net']);
        }
    }
    $ok('fee/net math sampled (mismatches are WARN only)', $checked > 0, "checked: {$checked}, mismatches: {$warn}");

    echo "\n=== " . ($fail === 0 ? 'ALL VALIDATIONS PASSED' : "VALIDATION FAILURES: {$fail}") . " ===\n";
    return $fail === 0 ? 0 : 3;
}

function report($label, $cond, $extra, &$fail)
{
    $mark = $cond ? 'PASS' : 'FAIL';
    printf("  [%s] %s %s\n", $mark, $label, $extra ? "($extra)" : '');
    if (!$cond) $fail++;
}
