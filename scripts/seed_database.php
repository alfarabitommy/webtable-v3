<?php
/**
 * Synapse (db_webtable) — Database Seed CLI Runner
 *
 * Applies database_seed.sql safely:
 *   --verify   (default) dry-run: schema reconciliation plan + conflict scan, writes nothing
 *   --apply    transactional per-section execution (FK checks ON), then post-apply validation
 *   --check    run post-apply validation only (re-verifies an already-seeded DB)
 *   --force    with --apply: wipe the seed domain first (dev re-runs)
 *   --no-backup  skip the automatic PHP backup dump (default: writes backups/pre_seed_*.sql)
 *
 * Credentials are parsed from application/config/database.php (CI3).
 * Exit codes: 0 = clean, 1 = pre-flight failure, 2 = apply failure, 3 = validation failure.
 */

define('BASEPATH', 'cli-seed-runner');
define('ENVIRONMENT', 'development');

error_reporting(E_ALL);
ini_set('display_errors', '1');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ROOT = dirname(__DIR__);
$SEED_SQL = $ROOT . '/database_seed.sql';
const SEED_DB = 'db_webtable';

const USER_IDS = '1,2,3,4,5,6,7,8,9,10,11,12,13';
const USER_PHONES = ["081234567890","081298765432","085712345678","085798765432","087811223344","087855667788","085723456789","085798112233","087833445566","087855667799","081277889900","081288990011","081299001122"];
const USER_INVITES = ["ABC123","DEF456","GHI789","JKL012","MNO345","PQR678","STU901","VWX234","YZA567","BCD890","EFG123","HIJ456","KLM789"];

$args = $argv ?: [];
$apply     = in_array('--apply', $args, true);
$force     = in_array('--force', $args, true);
$checkOnly = in_array('--check', $args, true);
$noBackup  = in_array('--no-backup', $args, true);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Usage: php scripts/seed_database.php [--verify|--apply|--check] [--force] [--no-backup]\n";
    exit(0);
}

// ---------------------------------------------------------------- config
$db = load_db_config($ROOT . '/application/config/database.php');
if (!$db) {
    fwrite(STDERR, "[FATAL] Cannot read DB credentials from application/config/database.php\n");
    exit(1);
}
$host = $db['hostname'] === 'localhost' ? '127.0.0.1' : $db['hostname']; // TCP (no unix socket in this env)

// ---------------------------------------------------------------- connect
try {
    $m = new mysqli($host, $db['username'], $db['password'], $db['database'], 3306);
    $m->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, "[FATAL] Cannot connect to {$db['database']}: " . $e->getMessage() . "\n");
    exit(1);
}
echo "== Synapse database seeder ==\n";
echo "Server: {$m->server_info}  DB: {$db['database']}\n\n";

// ---------------------------------------------------------------- parse seed file
$sections = parse_seed_file($SEED_SQL);
if (empty($sections)) {
    fwrite(STDERR, "[FATAL] No sections found in $SEED_SQL\n");
    exit(1);
}
echo "Seed file sections: " . implode(' -> ', array_keys($sections)) . "\n\n";

// ---------------------------------------------------------------- pre-flight
$errors = [];
$tables_expected = ['admins','bank_accounts','deposits','gpu_products','otp_logs','rentals','site_settings',
                    'system_audit_logs','system_settings','transactions','users','user_notifications',
                    'user_rentals','wallet_ledger','withdrawals'];
$missing_tables = [];
foreach ($tables_expected as $t) {
    $q = $m->query("SHOW TABLES LIKE '" . $m->real_escape_string($t) . "'");
    if (!$q || $q->num_rows === 0) $missing_tables[] = $t;
}
if ($missing_tables) {
    $errors[] = 'Missing tables: ' . implode(', ', $missing_tables);
}

$existing_users = (int) $m->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$existing_admin = (int) $m->query("SELECT COUNT(*) FROM admins")->fetch_row()[0];

echo "Pre-flight:\n";
echo "  existing users: {$existing_users}, existing admins: {$existing_admin}\n";
if ($existing_users > 0 && $apply && !$force) {
    $errors[] = "users table is not empty ({$existing_users} rows) — run with --force to wipe the seed domain, or check the DB";
}
if ($existing_users > 0 && !$apply) {
    echo "  NOTE: users table has {$existing_users} rows (already seeded?) — use --apply --force to re-seed\n";
}

// conflict scan on natural keys (seed-owned ids 1-13 are not conflicts)
foreach (['phone', 'invite_code'] as $col) {
    if (!column_exists($m, 'users', $col)) continue;
    $vals = array_map(fn($v) => "'" . $m->real_escape_string($v) . "'", user_seed_values($col));
    if (!$vals) continue;
    $q = $m->query("SELECT id, $col FROM users WHERE $col IN (" . implode(',', $vals) . ") AND id NOT IN (" . USER_IDS . ")");
    while ($r = $q->fetch_assoc()) {
        $errors[] = "CONFLICT users.{$col}='{$r[$col]}' already belongs to user id {$r['id']} (outside seed domain)";
    }
}
if ($apply && !$force) {
    $inv = $m->query("SELECT invoice_number FROM deposits WHERE invoice_number LIKE 'INV-%' AND user_id NOT IN (" . USER_IDS . ") LIMIT 1");
    if ($inv && $inv->num_rows > 0) {
        $errors[] = 'existing non-seed INV- deposits detected — run with --force to re-seed cleanly';
    }
}

// reconciliation plan
echo "  schema reconciliation plan:\n";
foreach (reconcile_plan() as $stmt) {
    $info = parse_alter($stmt);
    $already = $info['kind'] === 'column'
        ? column_exists($m, $info['table'], $info['name'])
        : index_exists($m, $info['table'], $info['name']);
    echo "    " . ($already ? '[skip ] ' : '[add  ] ') . $stmt . "\n";
}

if ($errors) {
    echo "\nPre-flight FAILED:\n";
    foreach ($errors as $e) echo "  - $e\n";
    echo "\nNothing was written. Use --force only if this DB is a dev/staging instance.\n";
    exit(1);
}
echo "  pre-flight OK\n\n";

if (!$apply && !$checkOnly) {
    echo "DRY-RUN (--verify): no changes made. Run: php scripts/seed_database.php --apply\n";
    exit(0);
}

// ---------------------------------------------------------------- backup
if ($apply && !$noBackup) {
    $backup = backup_database($m, $ROOT . '/backups');
    echo "Backup written: {$backup}\n\n";
}

// ---------------------------------------------------------------- force cleanup
if ($apply && $force) {
    echo "--force: wiping seed domain (dummy ids 1-13 users, 1-4 products, 1-16 audit)...\n";
    $cleanup = [
        // Audit rows: seed rows live inside the 120-day window; id-range deletes are unsafe
        // because InnoDB bulk INSERT..SELECT burns AUTO_INCREMENT ids (gaps), so ids are not 1..16.
        "DELETE FROM system_audit_logs  WHERE created_at >= DATE_SUB(NOW(), INTERVAL 120 DAY) OR user_id IN (" . USER_IDS . ")",
        "DELETE FROM user_notifications WHERE user_id IN (" . USER_IDS . ")",
        "DELETE FROM wallet_ledger      WHERE user_id IN (" . USER_IDS . ")",
        "DELETE FROM withdrawals        WHERE user_id IN (" . USER_IDS . ")",
        "DELETE FROM deposits           WHERE user_id IN (" . USER_IDS . ")",
        "DELETE FROM user_rentals       WHERE user_id IN (" . USER_IDS . ")",
        "DELETE FROM bank_accounts      WHERE user_id IN (" . USER_IDS . ")",
        "DELETE FROM users              WHERE id IN (" . USER_IDS . ")",
        "DELETE FROM gpu_products       WHERE id BETWEEN 1 AND 4",
        "DELETE FROM admins             WHERE id = 1",
    ];
    foreach ($cleanup as $sql) { $m->query($sql); }
    echo "  cleanup done\n\n";
}

// ---------------------------------------------------------------- apply
if ($apply) {
    echo "Applying schema reconciliation (guarded, before data)...\n";
    foreach (reconcile_plan() as $stmt) {
        $info = parse_alter($stmt);
        $already = $info['kind'] === 'column'
            ? column_exists($m, $info['table'], $info['name'])
            : index_exists($m, $info['table'], $info['name']);
        if ($already) { echo "  [skip] {$stmt}\n"; continue; }
        try {
            $m->query($stmt);
            echo "  [add ] {$stmt}\n";
        } catch (mysqli_sql_exception $e) {
            fwrite(STDERR, "  [FAIL] reconcile: {$e->getMessage()}\n");
            exit(2);
        }
    }

    echo "Applying data sections (transaction per section, FK checks ON)...\n";
    foreach ($sections as $name => $statements) {
        if ($name === 'reconcile_schema') continue; // applied above, guarded
        $m->begin_transaction();
        try {
            foreach ($statements as $stmt) {
                if (trim($stmt) === '') continue;
                $m->query($stmt);
            }
            $m->commit();
            echo "  [ok] {$name} (" . count($statements) . " statement(s))\n";
        } catch (mysqli_sql_exception $e) {
            $m->rollback();
            fwrite(STDERR, "  [FAIL] section {$name}: " . $e->getMessage() . "\n");
            fwrite(STDERR, "  Rolling back section {$name}. Earlier sections remain committed.\n");
            exit(2);
        }
    }
    echo "Apply finished.\n\n";
}

// ---------------------------------------------------------------- validate
if ($apply || $checkOnly) {
    exit(run_validation($m));
}
exit(0);

// ================================================================ helpers

function load_db_config($file)
{
    if (!is_file($file)) return null;
    include $file;
    return isset($db['default']) ? $db['default'] : null;
}

/** Split the seed file into sections, each a list of top-level statements. */
function parse_seed_file($file)
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return [];
    $sections = [];
    $current = null;
    $buffer  = '';
    $pendingReconcile = false;
    $sectionRe = '/^--\s*={2,}\s*SECTION:\s*(\w+)\s*={2,}/';
    $reconcileRe = '/^\s*--\s*\[RECONCILE\]\s*$/';
    foreach ($lines as $line) {
        if (preg_match($sectionRe, $line, $m1)) {
            flush_statement($sections, $current, $buffer, $pendingReconcile);
            $current = strtolower($m1[1]);
            $buffer = '';
            $pendingReconcile = false;
            continue;
        }
        if (preg_match($reconcileRe, $line)) { $pendingReconcile = true; continue; }
        if (trim($line) === '' || preg_match('/^\s*--/', $line)) continue; // blank/comment
        $buffer .= $line . "\n";
    }
    flush_statement($sections, $current, $buffer, $pendingReconcile);
    return $sections;
}

function flush_statement(&$sections, $current, &$buffer, &$pendingReconcile)
{
    if ($current === null) return;
    foreach (split_statements($buffer) as $stmt) {
        $sections[$current][] = ($pendingReconcile ? '[RECONCILE] ' : '') . trim($stmt);
        $pendingReconcile = false;
    }
    $buffer = '';
}

/** Split on top-level ';' respecting single-quoted strings and backslash escapes. */
function split_statements($sql)
{
    $stmts = [];
    $buf = '';
    $inStr = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($inStr) {
            if ($c === '\\' && $i + 1 < $len) { $buf .= $c . $sql[++$i]; continue; }
            if ($c === "'") { $inStr = false; }
            $buf .= $c;
            continue;
        }
        if ($c === "'") { $inStr = true; $buf .= $c; continue; }
        if ($c === ';') { $stmts[] = $buf; $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $stmts[] = $buf;
    return array_values(array_filter(array_map('trim', $stmts), fn($s) => $s !== ''));
}

// (USER_IDS / USER_PHONES / USER_INVITES declared at top of file)

function user_seed_values($col)
{
    return $col === 'phone' ? USER_PHONES : USER_INVITES;
}

function reconcile_plan()
{
    return [
        "ALTER TABLE `users` ADD COLUMN `username` VARCHAR(50) NULL DEFAULT NULL",
        "ALTER TABLE `users` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'user'",
        "ALTER TABLE `users` ADD COLUMN `is_banned` TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE `users` ADD COLUMN `is_level_1_claimed` TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE `users` ADD COLUMN `last_wage_claimed_at` TIMESTAMP NULL DEFAULT NULL",
        "ALTER TABLE `withdrawals` ADD COLUMN `wd_number` VARCHAR(50) NULL DEFAULT NULL",
        "ALTER TABLE `withdrawals` ADD COLUMN `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE `withdrawals` ADD UNIQUE KEY `uk_wd_number` (`wd_number`)",
    ];
}

function parse_alter($stmt)
{
    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+COLUMN\s+`?(\w+)`?/i', $stmt, $m1)) {
        return ['kind' => 'column', 'table' => $m1[1], 'name' => $m1[2]];
    }
    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:UNIQUE\s+)?KEY\s+`?(\w+)`?\s*\(`?(\w+)`?\)/i', $stmt, $m2)) {
        return ['kind' => 'index', 'table' => $m2[1], 'name' => $m2[2]];
    }
    return ['kind' => 'unknown', 'table' => '', 'name' => ''];
}

function column_exists($m, $table, $column)
{
    $q = $m->query("SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = '" . $m->real_escape_string(SEED_DB) . "'
                      AND TABLE_NAME   = '" . $m->real_escape_string($table) . "'
                      AND COLUMN_NAME  = '" . $m->real_escape_string($column) . "'");
    return $q->num_rows > 0;
}

function index_exists($m, $table, $index)
{
    $q = $m->query("SELECT 1 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = '" . $m->real_escape_string(SEED_DB) . "'
                      AND TABLE_NAME   = '" . $m->real_escape_string($table) . "'
                      AND INDEX_NAME   = '" . $m->real_escape_string($index) . "'");
    return $q->num_rows > 0;
}

/** Lightweight PHP backup: SHOW CREATE TABLE + INSERTs for every table. */
function backup_database($m, $dir)
{
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $file = $dir . '/pre_seed_' . date('Ymd_His') . '.sql';
    $fh = fopen($file, 'w');
    fwrite($fh, "-- Synapse db_webtable pre-seed backup (" . date('c') . ")\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $tables = $m->query("SHOW TABLES")->fetch_all();
    foreach ($tables as $t) {
        $t = $t[0];
        $create = $m->query("SHOW CREATE TABLE `$t`")->fetch_row()[1];
        fwrite($fh, "\nDROP TABLE IF EXISTS `$t`;\n$create;\n");
        $rows = $m->query("SELECT * FROM `$t`");
        while ($row = $rows->fetch_assoc()) {
            $cols = array_map(fn($c) => '`' . $c . '`', array_keys($row));
            $vals = array_map(function ($v) use ($m) {
                return $v === null ? 'NULL' : "'" . $m->real_escape_string($v) . "'";
            }, array_values($row));
            fwrite($fh, "INSERT INTO `$t` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n");
        }
    }
    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return $file;
}

/** Post-apply validation. Returns exit code (0 = all green, 3 = failures). */
function run_validation($m)
{
    $fail = 0;
    $ok = function ($label, $cond, $extra = '') use (&$fail) { report($label, $cond, $extra, $fail); };

    echo "\n=== POST-APPLY VALIDATION ===\n";

    // 1. Table counts
    $counts = [
        'admins' => 1, 'gpu_products' => 4, 'users' => 13, 'bank_accounts' => 13,
        'user_rentals' => 15, 'deposits' => 15, 'withdrawals' => 4,
        'user_notifications' => 14, 'system_audit_logs' => 16,
    ];
    echo "-- table row counts (expected) --\n";
    foreach ($counts as $t => $expected) {
        $actual = (int) $m->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
        $ok(sprintf('%-22s %d rows', $t, $actual), $actual === $expected, "(expected {$expected})");
    }
    $ledger = (int) $m->query("SELECT COUNT(*) FROM wallet_ledger")->fetch_row()[0];
    $ok(sprintf('%-22s %d rows', 'wallet_ledger', $ledger), $ledger >= 170, '(expected >= 170 derived rows)');

    // 2. Ledger invariant: users.balance == SUM(credit) - SUM(debit)
    echo "-- ledger invariant: users.balance == SUM(credit) - SUM(debit) --\n";
    $q = $m->query("SELECT u.id, u.username, u.phone, u.is_banned, u.balance,
                           COALESCE(c.cr, 0) AS credit, COALESCE(d.db, 0) AS debit
                    FROM users u
                    LEFT JOIN (SELECT user_id, SUM(amount) cr FROM wallet_ledger WHERE type='credit' GROUP BY user_id) c ON c.user_id = u.id
                    LEFT JOIN (SELECT user_id, SUM(amount) db FROM wallet_ledger WHERE type='debit'  GROUP BY user_id) d ON d.user_id = u.id
                    ORDER BY u.id");
    printf("%-5s %-14s %-14s %-4s %14s %14s %14s %8s\n", 'id', 'username', 'phone', 'ban', 'credit', 'debit', 'balance', 'status');
    while ($r = $q->fetch_assoc()) {
        $diff = abs((float) $r['balance'] - ((float) $r['credit'] - (float) $r['debit']));
        $good = $diff < 0.01 && ((float) $r['balance'] >= 0 || (int) $r['is_banned'] === 1);
        printf("%-5s %-14s %-14s %-4s %14s %14s %14s %8s\n",
            $r['id'], $r['username'], $r['phone'], $r['is_banned'],
            number_format($r['credit'], 0, ',', '.'), number_format($r['debit'], 0, ',', '.'),
            number_format($r['balance'], 0, ',', '.'), $good ? 'PASS' : 'FAIL');
        if (!$good) $fail++;
    }

    // 3. Tree integrity (depth, no cycles, no orphans)
    echo "-- MLM tree integrity --\n";
    $selfParent = (int) $m->query("SELECT COUNT(*) FROM users WHERE parent_id IS NOT NULL AND id = parent_id")->fetch_row()[0];
    $ok('no self-parent', $selfParent === 0, "found {$selfParent}");
    $orphans = (int) $m->query("SELECT COUNT(*) FROM users u LEFT JOIN users p ON p.id = u.parent_id WHERE u.parent_id IS NOT NULL AND p.id IS NULL")->fetch_row()[0];
    $ok('no orphan parent_id', $orphans === 0, "found {$orphans}");
    $tree = $m->query("WITH RECURSIVE tree AS (
                         SELECT id, 0 AS depth FROM users WHERE parent_id IS NULL
                         UNION ALL
                         SELECT u.id, t.depth + 1 FROM users u JOIN tree t ON u.parent_id = t.id)
                       SELECT COUNT(*) AS reached, MAX(depth) AS max_depth FROM tree")->fetch_assoc();
    $ok('all 13 users reachable from roots', (int) $tree['reached'] === 13, "reached {$tree['reached']}");
    $ok('max tree depth <= 3', (int) $tree['max_depth'] <= 3, "depth {$tree['max_depth']}");

    // 4. Foreign key integrity
    echo "-- foreign key integrity --\n";
    $fkChecks = [
        'user_rentals.user_id'   => "SELECT COUNT(*) FROM user_rentals r LEFT JOIN users u ON u.id = r.user_id WHERE u.id IS NULL",
        'user_rentals.product_id'=> "SELECT COUNT(*) FROM user_rentals r LEFT JOIN gpu_products p ON p.id = r.product_id WHERE p.id IS NULL",
        'deposits.user_id'       => "SELECT COUNT(*) FROM deposits d LEFT JOIN users u ON u.id = d.user_id WHERE u.id IS NULL",
        'withdrawals.user_id'    => "SELECT COUNT(*) FROM withdrawals w LEFT JOIN users u ON u.id = w.user_id WHERE u.id IS NULL",
        'withdrawals.bank'       => "SELECT COUNT(*) FROM withdrawals w LEFT JOIN bank_accounts b ON b.id = w.bank_account_id WHERE b.id IS NULL",
        'bank_accounts.user_id'  => "SELECT COUNT(*) FROM bank_accounts b LEFT JOIN users u ON u.id = b.user_id WHERE u.id IS NULL",
        'wallet_ledger.user_id'  => "SELECT COUNT(*) FROM wallet_ledger l LEFT JOIN users u ON u.id = l.user_id WHERE u.id IS NULL",
        'notifications.user_id'  => "SELECT COUNT(*) FROM user_notifications n LEFT JOIN users u ON u.id = n.user_id WHERE u.id IS NULL",
        'audit.admin_id'         => "SELECT COUNT(*) FROM system_audit_logs a LEFT JOIN admins ad ON ad.id = a.admin_id WHERE a.admin_id IS NOT NULL AND ad.id IS NULL",
        'audit.user_id'          => "SELECT COUNT(*) FROM system_audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.user_id IS NOT NULL AND u.id IS NULL",
    ];
    foreach ($fkChecks as $label => $sql) {
        $n = (int) $m->query($sql)->fetch_row()[0];
        $ok('FK ' . $label, $n === 0, "orphans {$n}");
    }

    // 5. H-0 rentals (T+1 rejection test fixtures)
    echo "-- H-0 rental state (T+1) --\n";
    $h0 = (int) $m->query("SELECT COUNT(*) FROM user_rentals WHERE id IN (6,15) AND days_processed = 0 AND last_claimed_at IS NULL")->fetch_row()[0];
    $ok('rentals 6 & 15: days_processed=0, last_claimed_at NULL', $h0 === 2, "found {$h0}");

    // 6. Uniqueness of natural keys
    echo "-- natural-key uniqueness --\n";
    $uniq = [
        'users.phone'        => "SELECT COUNT(*) = COUNT(DISTINCT phone) FROM users",
        'users.invite_code'  => "SELECT COUNT(*) = COUNT(DISTINCT invite_code) FROM users",
        'deposits.invoice'   => "SELECT COUNT(*) = COUNT(DISTINCT invoice_number) FROM deposits",
        'withdrawals.wd_no'  => "SELECT COUNT(*) = COUNT(DISTINCT wd_number) FROM withdrawals",
        'admins.username'    => "SELECT COUNT(*) = COUNT(DISTINCT username) FROM admins",
    ];
    foreach ($uniq as $label => $sql) {
        $r = $m->query($sql)->fetch_row()[0];
        $ok('unique ' . $label, (int) $r === 1, $r == 0 ? 'duplicates found' : '');
    }

    // 7. Admin + active users presence
    echo "-- account presence --\n";
    $admin = $m->query("SELECT id, username FROM admins WHERE id = 1 AND username = 'admin'");
    $ok('admin account present (id=1, admin)', $admin->num_rows === 1, 'admin missing');
    $users = (int) $m->query("SELECT COUNT(*) FROM users WHERE id BETWEEN 1 AND 13")->fetch_row()[0];
    $ok('13 seed users present', $users === 13, "found {$users}");
    $active = (int) $m->query("SELECT COUNT(*) FROM users WHERE id BETWEEN 1 AND 13 AND is_banned = 0")->fetch_row()[0];
    $ok('12 active users (1 banned by design)', $active === 12, "found {$active}");

    echo "\n=== " . ($fail === 0 ? 'ALL VALIDATIONS PASSED' : "VALIDATION FAILURES: {$fail}") . " ===\n";
    return $fail === 0 ? 0 : 3;
}

function report($label, $cond, $extra, &$fail)
{
    $mark = $cond ? 'PASS' : 'FAIL';
    printf("  [%s] %s %s\n", $mark, $label, $extra ? "($extra)" : '');
    if (!$cond) $fail++;
}
