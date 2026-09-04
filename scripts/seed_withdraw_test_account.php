<?php
/**
 * scripts/seed_withdraw_test_account.php
 *
 * M4 (plan/62-63) test fixture for db_webtable — dedicated account for
 * testing withdrawal double-submit / debounce guards and admin approve/decline.
 *
 * Creates / re-creates (idempotent, cleanup by phone first):
 *   - 1 user:  phone 081288880001, password "password123" (PASSWORD_BCRYPT),
 *              balance 500000.00 (users.balance cache + authoritative
 *              wallet_ledger credit row — C4: ledger is the single source),
 *              is_banned = 0, role 'user'
 *   - 1 bank binding (Bank BCA / 1234567890 / Nama Penguji, is_primary)
 *   - 1 active rental (user_rentals): status 'active', expired_at = now + 30
 *     hari, sehingga has_active_rental() === true (gatekeeper penarikan).
 *
 * Nominal rental sengaja mengikuti spesifikasi fixture (bukan turunan produk):
 *   purchase_price 100000.00, daily_roi 5000.00, total_days 30.
 *
 * Run:  php scripts/seed_withdraw_test_account.php
 * Env:  DB_HOSTNAME / DB_USERNAME / DB_PASSWORD / DB_DATABASE (defaults
 *       localhost / root / root / db_webtable; falls back to 127.0.0.1 TCP).
 */

error_reporting(E_ALL);
date_default_timezone_set('Asia/Jakarta');

define('FIXTURE_PHONE', '081288880001');
define('FIXTURE_PASSWORD', 'password123');
define('FIXTURE_BALANCE', '500000.00');

function db_connect()
{
    // mysqli exception-reporting (php.ini) would throw on the unix-socket
    // connect attempt before the TCP fallback runs — return errors instead.
    mysqli_report(MYSQLI_REPORT_OFF);

    $creds = [
        'host' => getenv('DB_HOSTNAME') ?: 'localhost',
        'user' => getenv('DB_USERNAME') ?: 'root',
        'pass' => getenv('DB_PASSWORD') ?: 'root',
        'name' => getenv('DB_DATABASE') ?: 'db_webtable',
    ];
    // localhost may resolve to a unix socket that does not exist; retry TCP.
    $hosts = array_values(array_unique([$creds['host'], '127.0.0.1']));
    foreach ($hosts as $host) {
        $m = @new mysqli($host, $creds['user'], $creds['pass'], $creds['name'], 3306);
        if (!$m->connect_errno) {
            $m->set_charset('utf8mb4');
            $m->query("SET time_zone = '+07:00'"); // WIB session (parity aplikasi)
            echo "Connected to {$creds['name']} @ {$host}:3306\n";
            return $m;
        }
    }
    fwrite(STDERR, "CONNECT FAIL: {$m->connect_error}\n");
    exit(1);
}

function q($m, $v)
{
    if ($v === null) return 'NULL';
    return "'" . $m->real_escape_string((string) $v) . "'";
}

function gen_invite_code($m)
{
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $q = $m->query("SELECT 1 FROM users WHERE invite_code = '" . $m->real_escape_string($code) . "' LIMIT 1");
    } while ($q && $q->num_rows > 0);
    return $code;
}

/** Daftar tabel dependent (berisi kolom user_id) yang benar-benar ada di DB. */
function dependent_tables($m)
{
    $want = ['user_notifications', 'system_audit_logs', 'wallet_ledger', 'withdrawals',
             'deposits', 'user_rentals', 'bank_accounts', 'otp_logs'];
    $have = [];
    foreach ($want as $t) {
        $r = $m->query("SELECT 1 FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $m->real_escape_string($t) . "'
                          AND COLUMN_NAME = 'user_id' LIMIT 1");
        if ($r && $r->num_rows > 0) { $have[] = $t; }
    }
    return $have;
}

$m = db_connect();
$errs = [];

// ---------------------------------------------------------------- cleanup (idempotent)
$row = $m->query("SELECT id FROM users WHERE phone = '" . FIXTURE_PHONE . "' LIMIT 1")->fetch_assoc();
if ($row) {
    $uid = (int) $row['id'];
    echo "Removing previous fixture (user id: {$uid})...\n";
    foreach (dependent_tables($m) as $t) {
        $m->query("DELETE FROM `{$t}` WHERE user_id = {$uid}") or $errs[] = "cleanup {$t}: {$m->error}";
    }
    $m->query("DELETE FROM users WHERE id = {$uid}") or $errs[] = "cleanup users: {$m->error}";
}
if ($errs) {
    fwrite(STDERR, "Cleanup failed:\n - " . implode("\n - ", $errs) . "\nABORTED\n");
    exit(1);
}

// ---------------------------------------------------------------- context
$userCols = [];
foreach ($m->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'") as $r) {
    $userCols[$r['COLUMN_NAME']] = true;
}

// product_id: produk aktif termurah (fixture memberi nilai rental sendiri).
$prod = $m->query("SELECT id FROM gpu_products WHERE is_active = 1 ORDER BY price ASC LIMIT 1")->fetch_assoc();
if (!$prod) {
    $prod = $m->query("SELECT id FROM gpu_products ORDER BY id ASC LIMIT 1")->fetch_assoc();
}
if (!$prod) {
    fwrite(STDERR, "gpu_products is empty — cannot attach rental.\n");
    exit(1);
}
$productId = (int) $prod['id'];
echo "Using gpu_product id={$productId} for the active rental.\n";

$hash      = password_hash(FIXTURE_PASSWORD, PASSWORD_BCRYPT);
$now       = date('Y-m-d H:i:s');
$expiredAt = date('Y-m-d H:i:s', time() + 30 * 86400); // 30 days from now

$m->begin_transaction();
try {
    // ------------------------------------------------------------- user
    $invite  = gen_invite_code($m);
    $uCols = ['phone', 'password', 'invite_code', 'parent_id', 'balance', 'level_id',
              'is_banned', 'must_change_password', 'is_level_1_claimed',
              'last_wage_claimed_at', 'created_at'];
    $uVals = [q($m, FIXTURE_PHONE), q($m, $hash), q($m, $invite), 'NULL',
              q($m, FIXTURE_BALANCE), '0', '0', '0', '0', 'NULL', q($m, $now)];
    if (isset($userCols['username'])) { $uCols[] = 'username'; $uVals[] = q($m, 'wd_test'); }
    if (isset($userCols['role']))     { $uCols[] = 'role';     $uVals[] = q($m, 'user'); }
    $m->query("INSERT INTO users (`" . implode('`,`', $uCols) . "`) VALUES (" . implode(',', $uVals) . ")")
        or throw new RuntimeException("user insert: {$m->error}");
    $userId = (int) $m->insert_id;

    // ------------------------------------------- balance: ledger (authoritative) + cache
    $txId = 'SEED-WD-TEST-' . $userId . '-' . date('YmdHis');
    $m->query("INSERT INTO wallet_ledger (`user_id`, `transaction_id`, `type`, `amount`, `description`, `created_at`)
               VALUES ({$userId}, " . q($m, $txId) . ", 'credit', " . q($m, FIXTURE_BALANCE)
               . ", 'Seed akun uji penarikan (M4)', " . q($m, $now) . ")")
        or throw new RuntimeException("wallet_ledger insert: {$m->error}");

    // ------------------------------------------------------------- bank binding
    $m->query("INSERT INTO bank_accounts (`user_id`, `bank_name`, `account_number`, `account_holder`, `is_primary`, `created_at`)
               VALUES ({$userId}, 'Bank BCA', '1234567890', 'Nama Penguji', 1, " . q($m, $now) . ")")
        or throw new RuntimeException("bank_accounts insert: {$m->error}");

    // ------------------------------------------------------------- active rental
    $m->query("INSERT INTO user_rentals
               (`user_id`, `product_id`, `purchase_price`, `daily_roi`, `total_days`, `days_processed`,
                `status`, `expired_at`, `last_claimed_at`, `created_at`)
               VALUES ({$userId}, {$productId}, '100000.00', '5000.00', 30, 0,
                       'active', " . q($m, $expiredAt) . ", NULL, " . q($m, $now) . ")")
        or throw new RuntimeException("user_rentals insert: {$m->error}");

    $m->commit();
    printf("Inserted fixture user id=%d (phone %s).\n", $userId, FIXTURE_PHONE);
} catch (Throwable $e) {
    $m->rollback();
    fwrite(STDERR, "SEED FAILED (rolled back): " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------- verification
echo "\n=== VERIFICATION ===\n";
$u = $m->query("SELECT id, phone, username, invite_code, balance, is_banned
                FROM users WHERE phone = '" . FIXTURE_PHONE . "'")->fetch_assoc();
if (!$u) { fwrite(STDERR, "VERIFY FAIL: user not found\n"); exit(2); }

// Saldo otoritatif = agregat wallet_ledger (get_balance, Wallet_model::get_balance)
$lb = (float) $m->query(
    "SELECT COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0)
          - COALESCE(SUM(CASE WHEN type = 'debit'  THEN amount ELSE 0 END), 0) AS bal
     FROM wallet_ledger WHERE user_id = {$u['id']}"
)->fetch_assoc()['bal'];

// has_active_rental — SQL identik Rental_model::has_active_rental (plan/60)
$rental = $m->query(
    "SELECT id, product_id, status, expired_at, purchase_price, daily_roi, total_days
     FROM user_rentals
     WHERE user_id = {$u['id']} AND status = 'active' AND expired_at > '" . date('Y-m-d H:i:s') . "'
     LIMIT 1"
)->fetch_assoc();

$bank = $m->query("SELECT bank_name, account_number, account_holder, is_primary
                   FROM bank_accounts WHERE user_id = {$u['id']} LIMIT 1")->fetch_assoc();

printf("User ID:       %d\n", $u['id']);
printf("Phone:         %s\n", $u['phone']);
printf("Username:      %s\n", $u['username']);
printf("Invite Code:   %s\n", $u['invite_code']);
printf("users.balance: %s\n", $u['balance']);
printf("Ledger balance (get_balance): Rp %s\n", number_format($lb, 0, ',', '.'));
printf("is_banned:     %s\n", $u['is_banned'] ? 'YES (BAD)' : '0 (active)');
printf("has_active_rental: %s\n", $rental ? 'true' : 'false');
if ($rental) {
    printf("  rental id=%d product_id=%d status=%s purchase_price=%s daily_roi=%s total_days=%s\n",
        $rental['id'], $rental['product_id'], $rental['status'],
        $rental['purchase_price'], $rental['daily_roi'], $rental['total_days']);
    printf("  expired_at=%s\n", $rental['expired_at']);
}
printf("Bank binding:  %s\n", $bank ? "{$bank['bank_name']} {$bank['account_number']} a.n. {$bank['account_holder']} (primary={$bank['is_primary']})" : 'MISSING');

$ok = $rental && $lb >= 500000.00 && (int) $u['is_banned'] === 0 && $bank;
echo "\n" . ($ok ? "FIXTURE READY ✔" : "FIXTURE INCOMPLETE ✘") . "\n";
echo "\n=== LOGIN CREDENTIALS ===\n";
echo "  Phone:    " . FIXTURE_PHONE . "\n";
echo "  Password: " . FIXTURE_PASSWORD . "\n";
echo "  Saldo:    Rp " . number_format($lb, 0, ',', '.') . " (cukup untuk uji penarikan)\n";
$m->close();
exit($ok ? 0 : 2);
