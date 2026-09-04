<?php
/**
 * scripts/seed_wage_test_account.php
 *
 * M2 Scenario 3 (Weekly Wage Claim) test fixture for db_webtable.
 *
 * Creates:
 *   - 1 leader account (phone 081299990001, password "password123", balance 0,
 *     last_wage_claimed_at = NULL => immediately claimable)
 *   - 9 direct downlines (081299990002 .. 081299990010, parent_id = leader)
 *   - each downline gets exactly 1 row in `user_rentals` with status='active'
 *     and expired_at in the future, so count_all_active_downlines(leader) == 9
 *     (recursive CTE over the whole tree JOIN user_rentals status='active')
 *     => leader qualifies for Level 2 weekly wage = Rp 200.000.
 *
 * NOTE: the live rental table used by the wage counters is `user_rentals`
 * (the legacy `rentals` table is orphaned — plan/37 M10). Rows are inserted
 * there, not in `rentals`.
 *
 * Idempotent: on re-run it deletes the previous fixture rows (users matching
 * the fixture phone range + their dependent rows) before re-seeding.
 *
 * Run:  php scripts/seed_wage_test_account.php
 * Env:  DB_HOSTNAME / DB_USERNAME / DB_PASSWORD / DB_DATABASE (defaults
 *       localhost / root / root / db_webtable; falls back to 127.0.0.1).
 */

error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

define('FIXTURE_PASSWORD', 'password123');
define('LEADER_PHONE', '081299990001');
$FIXTURE_PHONES = [LEADER_PHONE];
for ($i = 2; $i <= 10; $i++) {
    $FIXTURE_PHONES[] = '0812999900' . sprintf('%02d', $i);
}

function db_connect()
{
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
            $m->query("SET time_zone = '+07:00'"); // M2 (plan/58): WIB session
            echo "Connected to {$creds['name']} @ {$host}:3306\n";
            return $m;
        }
    }
    fwrite(STDERR, "CONNECT FAIL: {$m->connect_error}\n");
    exit(1);
}

function quote_list($m, array $values)
{
    return implode(',', array_map(function ($v) use ($m) {
        return "'" . $m->real_escape_string($v) . "'";
    }, $values));
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

/** SQL literal: NULL for null, else single-quoted escaped string. */
function q($m, $v)
{
    if ($v === null) return 'NULL';
    return "'" . $m->real_escape_string((string) $v) . "'";
}

$m = db_connect();
$errs = [];

// ---------------------------------------------------------------- cleanup
$ph = quote_list($m, $FIXTURE_PHONES);
$ids = [];
foreach ($m->query("SELECT id FROM users WHERE phone IN ($ph)") as $r) {
    $ids[] = (int) $r['id'];
}
if ($ids) {
    $ids_s = implode(',', $ids);
    echo "Removing previous fixture rows (user ids: {$ids_s})...\n";
    foreach (['system_audit_logs', 'user_notifications', 'wallet_ledger', 'withdrawals', 'deposits', 'user_rentals', 'bank_accounts'] as $t) {
        $m->query("DELETE FROM `{$t}` WHERE user_id IN ({$ids_s})") or $errs[] = "cleanup {$t}: {$m->error}";
    }
    $m->query("DELETE FROM users WHERE id IN ({$ids_s})") or $errs[] = "cleanup users: {$m->error}";
}
if ($errs) {
    fwrite(STDERR, "Cleanup failed:\n - " . implode("\n - ", $errs) . "\nABORTED\n");
    exit(1);
}

// which optional user columns exist on this DB (username/role were added by reconcile)
$userCols = [];
foreach ($m->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'") as $r) {
    $userCols[$r['COLUMN_NAME']] = true;
}

// cheapest active product to attach rentals to
$prod = $m->query("SELECT id, price, daily_rate, duration_days FROM gpu_products
                   WHERE is_active = 1 ORDER BY price ASC LIMIT 1")->fetch_assoc();
if (!$prod) {
    $prod = $m->query("SELECT id, price, daily_rate, duration_days FROM gpu_products ORDER BY id ASC LIMIT 1")->fetch_assoc();
}
if (!$prod) {
    fwrite(STDERR, "gpu_products is empty — cannot create rentals.\n");
    exit(1);
}
printf("Using gpu_product id=%d price=%s daily_rate=%s duration_days=%d\n",
    $prod['id'], $prod['price'], $prod['daily_rate'], $prod['duration_days']);

$hash      = password_hash(FIXTURE_PASSWORD, PASSWORD_BCRYPT);
$now       = date('Y-m-d H:i:s');
$expiredAt = date('Y-m-d H:i:s', time() + (int) $prod['duration_days'] * 86400);

$m->begin_transaction();
try {
    // ---------------------------------------------------------------- leader
    $leaderCode = gen_invite_code($m);
    $lCols  = ['phone', 'password', 'invite_code', 'parent_id', 'balance', 'level_id', 'is_banned', 'must_change_password', 'is_level_1_claimed', 'last_wage_claimed_at', 'created_at'];
    $lVals  = [q($m, LEADER_PHONE), q($m, $hash), q($m, $leaderCode), 'NULL', '0.00', '0', '0', '0', '0', 'NULL', q($m, $now)];
    if (isset($userCols['username'])) { $lCols[] = 'username'; $lVals[] = q($m, 'wage_leader'); }
    if (isset($userCols['role']))     { $lCols[] = 'role';     $lVals[] = q($m, 'user'); }
    $m->query("INSERT INTO users (`" . implode('`,`', $lCols) . "`) VALUES (" . implode(',', $lVals) . ")")
        or throw new RuntimeException("leader insert: {$m->error}");
    $leaderId = (int) $m->insert_id;

    // ------------------------------------------------------------- downlines
    $rentalCount = 0;
    for ($i = 2; $i <= 10; $i++) {
        $phone = '0812999900' . sprintf('%02d', $i);
        $code  = gen_invite_code($m);
        $uCols = ['phone', 'password', 'invite_code', 'parent_id', 'balance', 'level_id', 'is_banned', 'must_change_password', 'is_level_1_claimed', 'last_wage_claimed_at', 'created_at'];
        $uVals = [q($m, $phone), q($m, $hash), q($m, $code), (string) $leaderId, '0.00', '0', '0', '0', '0', 'NULL', q($m, $now)];
        if (isset($userCols['username'])) { $uCols[] = 'username'; $uVals[] = q($m, 'wage_dl' . sprintf('%02d', $i - 1)); }
        if (isset($userCols['role']))     { $uCols[] = 'role';     $uVals[] = q($m, 'user'); }
        $m->query("INSERT INTO users (`" . implode('`,`', $uCols) . "`) VALUES (" . implode(',', $uVals) . ")")
            or throw new RuntimeException("downline insert {$phone}: {$m->error}");
        $dlId = (int) $m->insert_id;

        // 1 active rental per downline (user_rentals = live table, plan/37 M10)
        $m->query("INSERT INTO user_rentals
                   (`user_id`, `product_id`, `purchase_price`, `daily_roi`, `total_days`, `days_processed`,
                    `status`, `expired_at`, `last_claimed_at`, `created_at`)
                   VALUES ({$dlId}, {$prod['id']}, {$prod['price']}, {$prod['daily_rate']},
                           {$prod['duration_days']}, 0, 'active', '{$expiredAt}', NULL, '{$now}')")
            or throw new RuntimeException("rental insert for {$phone}: {$m->error}");
        $rentalCount++;
    }

    $m->commit();
    printf("Inserted leader id=%d + %d downlines, each with 1 active rental.\n", $leaderId, $rentalCount);
} catch (Throwable $e) {
    $m->rollback();
    fwrite(STDERR, "SEED FAILED (rolled back): " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------- verification
echo "\n=== VERIFICATION ===\n";
$leader = $m->query("SELECT id, phone, invite_code, last_wage_claimed_at, is_banned, must_change_password
                     FROM users WHERE phone = '" . LEADER_PHONE . "'")->fetch_assoc();
printf("Leader id=%d phone=%s referral_code=%s\n", $leader['id'], $leader['phone'], $leader['invite_code']);
printf("last_wage_claimed_at=%s (%s)\n",
    var_export($leader['last_wage_claimed_at'], true),
    $leader['last_wage_claimed_at'] === null ? 'NULL — ready to claim immediately' : 'SET (cooldown active)');

// count_all_active_downlines($leader_id) — same SQL as User_model::count_all_active_downlines
$cnt = (int) $m->query(
    "WITH RECURSIVE tree AS (
        SELECT id FROM users WHERE parent_id = {$leader['id']}
        UNION ALL
        SELECT u.id FROM users u INNER JOIN tree t ON u.parent_id = t.id
     )
     SELECT COUNT(DISTINCT t.id) AS cnt
     FROM tree t JOIN user_rentals ur ON ur.user_id = t.id
     WHERE ur.status = 'active'"
)->fetch_assoc()['cnt'];
printf("count_all_active_downlines(%d) = %d (expected 9)\n", $leader['id'], $cnt);
$tier = ($cnt >= 190) ? 'L6 Rp 9.000.000' : (($cnt >= 130) ? 'L5 Rp 5.000.000' : (($cnt >= 70) ? 'L4 Rp 2.500.000' : (($cnt >= 30) ? 'L3 Rp 1.000.000' : (($cnt >= 9) ? 'L2 Rp 200.000' : 'below L2 (not qualified)'))));
echo "Wage tier at {$cnt} active downlines => {$tier}\n";

$ok = $cnt === 9 && $leader['last_wage_claimed_at'] === null;
echo "\n" . ($ok ? "FIXTURE READY ✔" : "FIXTURE INCOMPLETE ✘") . "\n";
echo "\n=== LOGIN CREDENTIALS ===\n";
echo "  Phone:      " . LEADER_PHONE . "\n";
echo "  Password:   " . FIXTURE_PASSWORD . "\n";
echo "  Referral:   " . $leader['invite_code'] . " (share this code for downline signups)\n";
$m->close();
exit($ok ? 0 : 2);
