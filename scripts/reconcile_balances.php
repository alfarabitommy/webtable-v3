<?php
/**
 * Synapse (db_webtable) — Balance Reconciliation CLI (C4, plan/54 §4)
 *
 * Single source of truth: wallet_ledger (Σcredit − Σdebit). users.balance is
 * an atomic read-cache that every money move keeps in sync in the same TX
 * (Wallet_model::credit()/debit()). This runner detects legacy/accidental
 * drift between the cache and the ledger and repairs it.
 *
 *   --verify    (default) dry-run: detect & quantify drift, writes NOTHING
 *   --apply     repair users.balance = ledger aggregate per user, atomically,
 *               with automatic backup dump (backups/pre_reconcile_*.sql)
 *   --check     post-apply validation only (expect zero drift)
 *   --json      machine-readable summary on stdout (for cron/nagios)
 *   --no-backup skip the automatic PHP backup dump
 *
 * Idempotent: the drift query is exact-DECIMAL (u.balance <> ledger SUM), so a
 * re-run after --apply finds nothing. Repair is race-safe: session isolation
 * READ COMMITTED + per-user SELECT ... FOR UPDATE + re-aggregate after the lock
 * wait (pola lock_and_get_balance, plan/48 §3.1) → concurrent money moves
 * either commit before our lock (seen by the re-aggregate) or serialize after
 * (relative cache updates apply on top of the corrected value).
 *
 * Credentials parsed from application/config/database.php (CI3).
 * Exit codes: 0 = clean, 1 = pre-flight failure, 2 = apply failure,
 *             3 = drift present (verify or post-check).
 */

define('BASEPATH', 'cli-reconcile-runner');
define('ENVIRONMENT', 'development');

error_reporting(E_ALL);
ini_set('display_errors', '1');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ROOT = dirname(__DIR__);

$args = $argv ?: [];
$apply     = in_array('--apply', $args, true);
$checkOnly = in_array('--check', $args, true);
$json      = in_array('--json', $args, true);
$noBackup  = in_array('--no-backup', $args, true);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Usage: php scripts/reconcile_balances.php [--verify|--apply|--check] [--json] [--no-backup]\n";
    echo "Exit codes: 0 = clean, 1 = pre-flight failure, 2 = apply failure, 3 = drift present\n";
    exit(0);
}
$mode = $checkOnly ? 'check' : ($apply ? 'apply' : 'verify');

// ---------------------------------------------------------------- output
function out($msg = '')
{
    global $json;
    if (!$json) {
        echo $msg . "\n";
    }
}

function die_fatal($msg)
{
    global $json;
    if ($json) {
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDERR, "[FATAL] {$msg}\n");
    }
    exit(1);
}

/** Kode keluar akhir + (opsional) ringkasan JSON bila --json. */
function finish($code, $summary)
{
    global $json;
    if ($json) {
        echo json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";
    }
    exit($code);
}

// ---------------------------------------------------------------- config
$db = load_db_config($ROOT . '/application/config/database.php');
if (!$db) {
    die_fatal('Cannot read DB credentials from application/config/database.php');
}
$host = $db['hostname'] === 'localhost' ? '127.0.0.1' : $db['hostname']; // TCP (no unix socket in this env)

// ---------------------------------------------------------------- connect
try {
    $m = new mysqli($host, $db['username'], $db['password'], $db['database'], 3306);
    $m->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    die_fatal("Cannot connect to {$db['database']}: " . $e->getMessage());
}

out('== Balance reconciliation (C4) ==');
out("Server: {$m->server_info}  DB: {$db['database']}  mode: {$mode}");

// ---------------------------------------------------------------- pre-flight
$must_tables = ['users', 'wallet_ledger'];
foreach ($must_tables as $t) {
    $q = $m->query("SHOW TABLES LIKE '" . $m->real_escape_string($t) . "'");
    if (!$q || $q->num_rows === 0) {
        die_fatal("Missing table: {$t}");
    }
}
$q = $m->query("SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = '" . $m->real_escape_string($db['database']) . "'
                  AND TABLE_NAME   = 'users'
                  AND COLUMN_NAME  = 'balance'");
if (!$q || $q->num_rows === 0) {
    die_fatal('users.balance missing — run the schema/seed baseline first');
}
// Repair race-safety: READ COMMITTED → setiap consistent read melihat data
// committed terbaru (snapshot tidak dibekukan di read pertama TX), sehingga
// re-aggregate setelah lock wait tidak pernah melewatkan commit konkuren.
$m->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

// ---------------------------------------------------------------- drift query
// Exact-DECIMAL compare: u.balance <> ledger aggregate — tanpa float dust.
function fetch_drift($m)
{
    return $m->query(
        "SELECT u.id,
                u.balance AS cache_balance,
                COALESCE(l.ledger_balance, 0) AS ledger_balance,
                (u.balance - COALESCE(l.ledger_balance, 0)) AS delta
         FROM users u
         LEFT JOIN (
             SELECT user_id,
                    COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) AS ledger_balance
             FROM wallet_ledger
             GROUP BY user_id
         ) l ON l.user_id = u.id
         WHERE u.balance <> COALESCE(l.ledger_balance, 0)
         ORDER BY u.id"
    );
}

$scanned = (int) $m->query('SELECT COUNT(*) AS c FROM users')->fetch_assoc()['c'];
$res     = fetch_drift($m);

$drift     = [];
$sumAbs    = 0.0;   // display-only aggregation
$sumDelta  = 0.0;
while ($r = $res->fetch_assoc()) {
    $r['cache_balance']   = (string) $r['cache_balance'];
    $r['ledger_balance']  = (string) $r['ledger_balance'];
    $r['delta']           = (string) $r['delta'];
    $drift[] = $r;
    $sumAbs   += abs((float) $r['delta']);
    $sumDelta += (float) $r['delta'];
}
$drifted = count($drift);

// ---------------------------------------------------------------- report
out('');
out('Scanned users: ' . $scanned);
out('Drifted accounts (users.balance <> ledger): ' . $drifted);
out('Sum |delta|: Rp ' . number_format($sumAbs, 0, ',', '.') . '   Sum delta: Rp ' . number_format($sumDelta, 0, ',', '.') . '');

if ($drifted > 0) {
    out('');
    out('Drift detail:');
    printf("  %-6s %14s %14s %14s\n", 'user_id', 'cache', 'ledger', 'delta');
    foreach ($drift as $r) {
        printf("  %-6d %14s %14s %14s\n",
            (int) $r['id'], $r['cache_balance'], $r['ledger_balance'], $r['delta']);
    }
}

$summaryBase = [
    'ok'            => false, // di-set tiap mode
    'script'        => 'reconcile_balances',
    'mode'          => $mode,
    'database'      => $db['database'],
    'scanned_users' => $scanned,
    'drifted_users' => $drifted,
    'sum_abs_delta' => number_format($sumAbs, 2, '.', ''),
    'sum_delta'     => number_format($sumDelta, 2, '.', ''),
    'drift'         => $drift,
    'backup_file'   => null,
];

if ($mode === 'verify') {
    if ($drifted === 0) {
        out("\nCLEAN — no drift between users.balance and wallet_ledger.");
        $summaryBase['ok'] = true;
        finish(0, $summaryBase);
    }
    out("\nDRY-RUN (--verify): " . $drifted . " drifted account(s) found — no changes made.");
    out('Repair with: php scripts/reconcile_balances.php --apply');
    $summaryBase['ok'] = false;
    finish(3, $summaryBase);
}

if ($mode === 'check') {
    if ($drifted === 0) {
        out("\nCHECK PASSED — 0 drifted accounts remain.");
        $summaryBase['ok'] = true;
        finish(0, $summaryBase);
    }
    out("\nCHECK FAILED — " . $drifted . " drifted account(s) still present (run --apply).");
    $summaryBase['ok'] = false;
    finish(3, $summaryBase);
}

// ================================================================ mode: apply
if ($drifted === 0) {
    out("\nNothing to reconcile — users.balance already matches the ledger.");
    $summaryBase['ok'] = true;
    finish(0, $summaryBase);
}

// ---------------------------------------------------------------- backup
if (!$noBackup) {
    $backup = backup_users($m, $ROOT . '/backups');
    out("\nBackup written: {$backup}");
    $summaryBase['backup_file'] = basename($backup);
} else {
    out("\n--no-backup: skip backup dump.");
}

// ---------------------------------------------------------------- apply
out("\nRepairing " . $drifted . " account(s) in one transaction (per-user lock + re-aggregate)...");
$m->begin_transaction();
try {
    $lockStmt = $m->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
    $aggStmt  = $m->prepare(
        "SELECT COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) AS bal
         FROM wallet_ledger WHERE user_id = ?"
    );
    $updStmt  = $m->prepare('UPDATE users SET balance = ? WHERE id = ?');

    foreach ($drift as $u) {
        $uid = (int) $u['id'];

        // 1. Kunci baris users (current read) — serialisasi vs mutasi konkuren.
        $lockStmt->bind_param('i', $uid);
        $lockStmt->execute();
        $lock = $lockStmt->get_result();
        if ($lock->num_rows === 0) {
            throw new mysqli_sql_exception("users row {$uid} vanished during repair");
        }

        // 2. Re-aggregate otoritatif SETELAH lock wait (READ COMMITTED → fresh).
        $aggStmt->bind_param('i', $uid);
        $aggStmt->execute();
        $bal = (string) $aggStmt->get_result()->fetch_assoc()['bal'];

        // 3. Cache := aggregate. Update relatif pasca-invariant berjalan di atasnya.
        $updStmt->bind_param('si', $bal, $uid);
        $updStmt->execute();
    }

    $lockStmt->close();
    $aggStmt->close();
    $updStmt->close();
    $m->commit();
    out('  commit OK');
} catch (mysqli_sql_exception $e) {
    $m->rollback();
    fwrite(STDERR, "[FAIL] apply: " . $e->getMessage() . "\n");
    fwrite(STDERR, "  Rolling back — no rows changed.\n");
    exit(2); // contract: 2 = apply failure
}

// ---------------------------------------------------------------- validate
out("\n=== Post-apply validation ===");
$checkRes  = fetch_drift($m);
$remaining = 0;
$leftover  = [];
while ($r = $checkRes->fetch_assoc()) {
    $remaining++;
    $leftover[] = (int) $r['id'];
}
$summaryBase['drifted_users'] = $remaining;
$summaryBase['drift']         = $leftover;
$summaryBase['sum_abs_delta'] = '0.00';
$summaryBase['sum_delta']     = '0.00';

if ($remaining === 0) {
    out('[PASS] 0 drifted accounts remain after --apply.');
    out("\n=== ALL VALIDATIONS PASSED ===");
    $summaryBase['ok'] = true;
    finish(0, $summaryBase);
}

out('[FAIL] ' . $remaining . ' account(s) still drifted: ' . implode(', ', $leftover));
$summaryBase['ok'] = false;
finish(3, $summaryBase);

// ================================================================ helpers

function load_db_config($file)
{
    if (!is_file($file)) {
        return null;
    }
    include $file;
    return isset($db['default']) ? $db['default'] : null;
}

/** Backup penuh tabel users (CREATE TABLE + INSERT per baris). */
function backup_users($m, $dir)
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $file = $dir . '/pre_reconcile_' . date('Ymd_His') . '.sql';
    $fh   = fopen($file, 'w');
    fwrite($fh, "-- Synapse db_webtable pre-reconcile backup (" . date('c') . ")\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $create = $m->query('SHOW CREATE TABLE users')->fetch_row();
    fwrite($fh, $create[1] . ";\n\n");
    $rows = $m->query('SELECT * FROM users');
    while ($r = $rows->fetch_assoc()) {
        $cols = [];
        $vals = [];
        foreach ($r as $k => $v) {
            $cols[] = '`' . str_replace('`', '``', $k) . '`';
            $vals[] = $v === null ? 'NULL' : "'" . $m->real_escape_string($v) . "'";
        }
        fwrite($fh, 'INSERT INTO `users` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ");\n");
    }
    fclose($fh);
    return $file;
}
