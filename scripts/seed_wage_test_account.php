<?php
/**
 * scripts/seed_wage_test_account.php
 *
 * M2 Scenario 3 (Weekly Wage Claim) test fixture for db_webtable.
 *
 * Creates:
 *   - 1 leader account (phone 081299990001, password "password123", balance 0,
 *     last_wage_claimed_at = NULL => immediately claimable)
 *   - N direct downlines (081299990002 .. 08129999NN+1, parent_id = leader)
 *   - each downline gets exactly 1 row in `user_rentals` with status='active'
 *     and expired_at in the future, from a product chosen by --product, so
 *     count_all_active_downlines(leader) == number of PAID (non-trial) rows
 *     (recursive CTE over the whole tree JOIN user_rentals status='active'
 *     JOIN gpu_products gp … AND gp.is_trial = 0 — plan/116 D2)
 *     => N paid downlines => leader qualifies for Level 2 weekly wage = Rp 200.000.
 *
 * NOTE: the live rental table used by the wage counters is `user_rentals`
 * (the legacy `rentals` table is orphaned — plan/37 M10). Rows are inserted
 * there, not in `rentals`.
 *
 * plan/116 (D2/D9) — the product row is now selected with an EXPLICIT
 * `is_trial` filter. Before plan/116 the fixture took the cheapest active
 * product (`ORDER BY price ASC`), which since plan/114 is the FREE TRIAL row
 * (price 0, is_trial 1) — the fixture would have seeded trial-only downlines
 * and silently proved nothing. The verification block below mirrors the model
 * SQL and prints BOTH counters (paid-only vs any-active) so the trial
 * exclusion is visible in the output.
 *
 * Idempotent: on re-run it deletes the previous fixture rows (users matching
 * the fixture phone range + their dependent rows) before re-seeding.
 *
 * Run:  php scripts/seed_wage_test_account.php [options]
 *   --downlines=N          number of downlines, 1..98 (default 9)
 *   --product=paid         all downlines hold a NON-trial contract (default)
 *   --product=trial        all downlines hold a TRIAL contract (price 0)
 *   --product=mixed        N-1 trial downlines + 1 paid downline (needs N >= 2)
 *   --help | -h            usage
 * Env:  DB_HOSTNAME / DB_USERNAME / DB_PASSWORD / DB_DATABASE (defaults
 *       localhost / root / root / db_webtable; falls back to 127.0.0.1).
 * Exit: 0 = fixture ready (count matches expectation), 1 = fatal/cleanup
 *       failure, 2 = fixture incomplete (count mismatch).
 */

error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

define('FIXTURE_PASSWORD', 'password123');
define('LEADER_PHONE', '081299990001');

// ---------------------------------------------------- plan/116 (D9): options
$args = $argv ?? [];
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Usage: php scripts/seed_wage_test_account.php [--downlines=N] [--product=paid|trial|mixed]\n"
       . "  --downlines=N      jumlah downline (1..98, default 9)\n"
       . "  --product=paid     semua downline pegang kontrak produk NON-trial (default)\n"
       . "  --product=trial    semua downline pegang kontrak TRIAL (harga 0)\n"
       . "  --product=mixed    N-1 trial + 1 berbayar (butuh N >= 2)\n";
    exit(0);
}

$DOWNLINES = 9;
$MODE      = 'paid';
foreach ($args as $a) {
    if (preg_match('/^--downlines=([0-9]{1,2})$/', (string) $a, $m)) {
        $DOWNLINES = (int) $m[1];
    } elseif (preg_match('/^--product=(paid|trial|mixed)$/', (string) $a, $m)) {
        $MODE = $m[1];
    } elseif (strpos((string) $a, '--') === 0) {
        fwrite(STDERR, "[FATAL] Opsi tidak dikenal: {$a} (lihat --help)\n");
        exit(1);
    }
}
if ($DOWNLINES < 1 || $DOWNLINES > 98) {
    fwrite(STDERR, "[FATAL] --downlines harus 1..98 (rentang telepon fixture 0812999900xx)\n");
    exit(1);
}
if ($MODE === 'mixed' && $DOWNLINES < 2) {
    fwrite(STDERR, "[FATAL] --product=mixed butuh minimal 2 downline\n");
    exit(1);
}

// Rentang telepon fixture: leader 081299990001, downline 081299990002..(. +N).
// Pembersihan menyapu SELURUH rentang (…-099), bukan hanya N yang diminta
// pada run ini — supaya sisa skenario sebelumnya (mis. --downlines=13) tidak
// tertinggal dan re-run apa pun tetap idempoten. (plan/116 D9)
$CLEANUP_PHONES = [LEADER_PHONE];
for ($i = 2; $i <= 99; $i++) {
    $CLEANUP_PHONES[] = '0812999900' . sprintf('%02d', $i);
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
$ph = quote_list($m, $CLEANUP_PHONES);
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

// plan/116 (D9): pilih produk secara EKSPLISIT lewat kolom `is_trial`
// (bukan harga). `paid` = produk non-trial termurah yang aktif; `trial` =
// baris produk trial (is_trial = 1, harga 0).
function pick_product($m, $want_trial)
{
    $flag = $want_trial ? 1 : 0;
    $order = $want_trial ? 'id ASC' : 'price ASC, id ASC';
    $sql = "SELECT id, name, price, daily_rate, duration_days, is_trial
              FROM gpu_products
             WHERE is_trial = {$flag} AND is_active = 1
             ORDER BY {$order} LIMIT 1";
    $res = $m->query($sql);
    $p = $res ? $res->fetch_assoc() : null;
    if (!$p) { // fallback: katalog tanpa baris aktif untuk flag tsb
        $res = $m->query("SELECT id, name, price, daily_rate, duration_days, is_trial
                            FROM gpu_products WHERE is_trial = {$flag}
                           ORDER BY {$order} LIMIT 1");
        $p = $res ? $res->fetch_assoc() : null;
    }
    return $p;
}

$paidProd  = ($MODE === 'trial') ? null : pick_product($m, false);
$trialProd = ($MODE === 'paid')  ? null : pick_product($m, true);

if ($MODE !== 'trial' && !$paidProd) {
    fwrite(STDERR, "gpu_products: tidak ada produk NON-trial — tidak bisa membuat kontrak berbayar.\n");
    exit(1);
}
if ($MODE !== 'paid' && !$trialProd) {
    fwrite(STDERR, "gpu_products: tidak ada baris produk TRIAL (is_trial = 1) — jalankan\n"
        . "            php scripts/migrate_114_trial_product_wd_gate.php --apply lebih dulu.\n");
    exit(1);
}
if ($paidProd) {
    printf("Produk BERBAYAR  id=%d '%s' price=%s is_trial=%d\n",
        $paidProd['id'], $paidProd['name'], $paidProd['price'], (int) $paidProd['is_trial']);
}
if ($trialProd) {
    printf("Produk TRIAL     id=%d '%s' price=%s is_trial=%d\n",
        $trialProd['id'], $trialProd['name'], $trialProd['price'], (int) $trialProd['is_trial']);
}
printf("Skenario: --downlines=%d --product=%s\n", $DOWNLINES, $MODE);

$hash      = password_hash(FIXTURE_PASSWORD, PASSWORD_BCRYPT);
$now       = date('Y-m-d H:i:s');

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
    $paidRows    = 0;
    $trialRows   = 0;
    for ($i = 2; $i <= $DOWNLINES + 1; $i++) {
        $phone = '0812999900' . sprintf('%02d', $i);
        $code  = gen_invite_code($m);
        $uCols = ['phone', 'password', 'invite_code', 'parent_id', 'balance', 'level_id', 'is_banned', 'must_change_password', 'is_level_1_claimed', 'last_wage_claimed_at', 'created_at'];
        $uVals = [q($m, $phone), q($m, $hash), q($m, $code), (string) $leaderId, '0.00', '0', '0', '0', '0', 'NULL', q($m, $now)];
        if (isset($userCols['username'])) { $uCols[] = 'username'; $uVals[] = q($m, 'wage_dl' . sprintf('%02d', $i - 1)); }
        if (isset($userCols['role']))     { $uCols[] = 'role';     $uVals[] = q($m, 'user'); }
        $m->query("INSERT INTO users (`" . implode('`,`', $uCols) . "`) VALUES (" . implode(',', $uVals) . ")")
            or throw new RuntimeException("downline insert {$phone}: {$m->error}");
        $dlId = (int) $m->insert_id;

        // plan/116 (D9): paid|trial|mixed → produk + harga + durasi dari baris
        // produk yang dipilih (trial: harga 0, duration_days 3).
        if ($MODE === 'trial' || ($MODE === 'mixed' && $i <= $DOWNLINES)) {
            $prod = $trialProd;
            $trialRows++;
        } else {
            $prod = $paidProd;
            $paidRows++;
        }
        $expiredAt = date('Y-m-d H:i:s', time() + (int) $prod['duration_days'] * 86400);

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

// count_all_active_downlines($leader_id) — mirror SQL User_model (plan/116 D2:
// JOIN gpu_products + is_trial = 0). Second counter = OLD predicate (any active
// contract) so the trial inflation that plan/116 removes is visible.
$cnt = (int) $m->query(
    "WITH RECURSIVE tree AS (
        SELECT id FROM users WHERE parent_id = {$leader['id']}
        UNION ALL
        SELECT u.id FROM users u INNER JOIN tree t ON u.parent_id = t.id
     )
     SELECT COUNT(DISTINCT t.id) AS cnt
     FROM tree t
     JOIN user_rentals ur ON ur.user_id = t.id
     JOIN gpu_products gp ON gp.id = ur.product_id
     WHERE ur.status = 'active'
       AND gp.is_trial = 0"
)->fetch_assoc()['cnt'];

$cnt_any = (int) $m->query(
    "WITH RECURSIVE tree AS (
        SELECT id FROM users WHERE parent_id = {$leader['id']}
        UNION ALL
        SELECT u.id FROM users u INNER JOIN tree t ON u.parent_id = t.id
     )
     SELECT COUNT(DISTINCT t.id) AS cnt
     FROM tree t JOIN user_rentals ur ON ur.user_id = t.id
     WHERE ur.status = 'active'"
)->fetch_assoc()['cnt'];

printf("Kontrak aktif dibuat: %d (paid=%d, trial=%d)\n", $rentalCount, $paidRows, $trialRows);
printf("count_all_active_downlines(%d) = %d (expected %d — produk non-trial saja)\n",
    $leader['id'], $cnt, $paidRows);
printf("predikat LAMA (tanpa is_trial)  = %d%s\n", $cnt_any,
    $cnt_any > $cnt ? '  <-- inflasi trial yang kini dikecualikan' : '');
$tier = ($cnt >= 190) ? 'L6 Rp 9.000.000' : (($cnt >= 130) ? 'L5 Rp 5.000.000' : (($cnt >= 70) ? 'L4 Rp 2.500.000' : (($cnt >= 30) ? 'L3 Rp 1.000.000' : (($cnt >= 9) ? 'L2 Rp 200.000' : 'below L2 (not qualified)'))));
echo "Wage tier at {$cnt} active downlines => {$tier}\n";
if ($tier === 'below L2 (not qualified)') {
    echo "EXPECT: POST /team/claim_wage -> {success:false, code:'not_qualified'}, 0 kredit ledger\n";
} else {
    echo "EXPECT: POST /team/claim_wage -> {success:true, amount dari tier di atas}\n";
}

$ok = $cnt === $paidRows && $leader['last_wage_claimed_at'] === null;
echo "\n" . ($ok ? "FIXTURE READY ✔" : "FIXTURE INCOMPLETE ✘") . "\n";
echo "\n=== LOGIN CREDENTIALS ===\n";
echo "  Phone:      " . LEADER_PHONE . "\n";
echo "  Password:   " . FIXTURE_PASSWORD . "\n";
echo "  Referral:   " . $leader['invite_code'] . " (share this code for downline signups)\n";
$m->close();
exit($ok ? 0 : 2);
