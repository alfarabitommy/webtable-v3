<?php
/**
 * Synapse (db_webtable) — Plan 118 Check-in Balance & Wallet Type Migration CLI
 *
 * One-off operator tool: menyediakan state DOMPET ABSEN + AUDIT MULTI-DOMPET
 * yang dipakai oleh mesin absensi harian, mesin checkout (split payment), dan
 * panel admin:
 *
 *   1. SATU KOLOM `users.checkin_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00
 *      (dompet kedua "Saldo Absen"; TIDAK dapat ditarik — invarian CK3);
 *   2. SATU KOLOM `wallet_ledger.wallet_type` ENUM('main','checkin')
 *      NOT NULL DEFAULT 'main' (audit dompet per baris ledger);
 *   3. SATU KEY `system_settings.checkin_require_active_rental` = '0'
 *      (gerbang syarat sewa aktif; default OFF).
 *
 *   1. PRE-FLIGHT : tabel `users`/`wallet_ledger`/`system_settings` ada; kolom
 *                   anchor `users.balance` + `wallet_ledger.type` ada (dipakai
 *                   klausa AFTER); koneksi mysqli TCP siap.
 *   2. INSPECT    : keberadaan 2 kolom + 1 key; jumlah user; jumlah baris
 *                   ledger (+ sebaran `wallet_type` bila kolom sudah ada);
 *                   drift mirror-vs-ledger kedua dompet (informasional).
 *   3. DDL        : 2 × ADD COLUMN dijaga information_schema (idempoten;
 *                   MySQL 8 tidak punya ADD COLUMN IF NOT EXISTS).
 *                   TANPA backfill.
 *   4. SEED       : key `checkin_require_active_rental` = '0' (idempoten;
 *                   nilai yang sudah ada TIDAK ditimpa).
 *   5. NORMALIZE  : `UPDATE wallet_ledger SET wallet_type = 'main'
 *                    WHERE wallet_type IS NULL` (defensif; harapan 0 baris).
 *   6. GUARD      : tipe/nullability/default kedua kolom + COLUMN_TYPE ENUM
 *                   tepat + key ada → pelanggaran = tamper → exit 2.
 *                   Nilai key di luar '0'/'1' hanya [WARN] (aplikasi jatuh ke
 *                   fallback per-key `application/config/checkin_rewards.php`).
 *   7. VERIFY     : ulangi 6 secara read-only.
 *
 *   --dry-run   (default) inspeksi + rencana, TIDAK menulis apa pun
 *   --apply     DDL + seed + normalisasi + verifikasi
 *   --verify    hanya verifikasi (read-only); exit 2 bila tidak sesuai
 *   --help|-h   usage
 *
 * Safety:
 * - Idempotent: re-run = no-op ("sudah ada (dibiarkan)" / "0 baris").
 * - TIDAK PERNAH menulis `wallet_ledger` selain normalisasi `wallet_type IS
 *   NULL` (harapan 0 baris), dan TIDAK menyentuh `users.balance`,
 *   `users.checkin_balance`, `withdrawals`, `user_rentals`, `bank_accounts` —
 *   ledger tetap append-only (Z1) dan bonus absensi historis TIDAK dipindah
 *   ke dompet checkin (keputusan D3).
 * - Kredensial dibaca dari application/config/database.php memakai semantik CI3
 *   ($active_group → 'default' → grup pertama), pola scripts/migrate_106/112/114/117.
 *   Override lokal: DB_HOSTNAME/DB_USERNAME/DB_PASSWORD/DB_DATABASE.
 *
 * ⚠️ URUTAN RILIS: jalankan migrasi ini SEBELUM deploy kode plan/118 —
 *    `Wallet_model::_post()` (kolom cache `balance`/`checkin_balance`),
 *    `Wallet_model::lock_and_get_balances()` + `get_balance()` (filter
 *    `wallet_ledger.wallet_type`), `Checkin_model::claim()` (kredit dompet
 *    checkin), `Rental_model::checkout_rental()` (split debit), dan seluruh
 *    agregat `Admin_model` membaca kolom/kolom-filter tersebut.
 *
 * Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal.
 */

define('BASEPATH', 'cli-migrate-118-runner');
define('ENVIRONMENT', 'development');

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Jakarta');   // WIB — otoritas waktu aplikasi (M2)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ROOT = dirname(__DIR__);

$args       = $argv ?: [];
$apply      = in_array('--apply', $args, true);
$verifyOnly = in_array('--verify', $args, true);
$dryRun     = ( ! $apply && ! $verifyOnly);

if (in_array('--help', $args, true) || in_array('-h', $args, true))
{
    echo "Usage: php scripts/migrate_118_checkin_refactor.php [--dry-run|--apply|--verify] [--help]\n"
       . "  --dry-run  (default) inspeksi kolom/key + cetak rencana, tanpa menulis\n"
       . "  --apply    tambah kolom `users.checkin_balance` + `wallet_ledger.wallet_type`,\n"
       . "             seed key `checkin_require_active_rental`, normalisasi defensif,\n"
       . "             lalu verifikasi\n"
       . "  --verify   hanya verifikasi (read-only); exit 2 bila tidak sesuai\n"
       . "  Catatan: TANPA backfill — `checkin_balance` seluruh user lama = 0.00,\n"
       . "           `wallet_type` seluruh baris ledger lama = 'main'.\n"
       . "           Tool tidak pernah menulis saldo/withdrawals/user_rentals.\n";
    exit(0);
}

// ---------------------------------------------------------------- config db
function load_db_config($file)
{
    if ( ! is_file($file)) return NULL;

    $db           = NULL;
    $active_group = NULL;
    include $file;

    if ( ! is_array($db)) return NULL;

    // CI3 semantics: grup AKTIF ($active_group) → 'default' → grup pertama
    // (config lokal memakai nama grup lain, mis. 'local' / 'dev' / 'live').
    if (is_string($active_group) && isset($db[$active_group])) { return $db[$active_group]; }
    if (isset($db['default']))                                 { return $db['default']; }

    $first = reset($db);
    return is_array($first) ? $first : NULL;
}

$db = load_db_config($ROOT . '/application/config/database.php');
if ( ! $db)
{
    fwrite(STDERR, "[FATAL] Cannot read DB credentials from application/config/database.php\n");
    exit(1);
}
$host = $db['hostname'] === 'localhost' ? '127.0.0.1' : $db['hostname']; // TCP (no unix socket in this env)

// ---------------------------------------------------------------- connect
try {
    $m = new mysqli($host, $db['username'], $db['password'], $db['database'], 3306);
    $m->set_charset('utf8mb4');
    $m->query("SET time_zone = '+07:00'");
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, "[FATAL] Cannot connect to {$db['database']}: " . $e->getMessage() . "\n");
    exit(1);
}

$DB = $db['database'];

// Target.
$TABLE_U  = 'users';
$COL_CB   = 'checkin_balance';   // DECIMAL(15,2) NOT NULL DEFAULT 0.00
$ANCHOR_U = 'balance';           // AFTER klausa (pre-flight memastikan ada)

$TABLE_W  = 'wallet_ledger';
$COL_WT   = 'wallet_type';       // ENUM('main','checkin') NOT NULL DEFAULT 'main'
$ANCHOR_W = 'type';              // AFTER klausa (pre-flight memastikan ada)

$TABLE_S  = 'system_settings';
$KEY_NAME = 'checkin_require_active_rental';
$KEY_VAL  = '0';

echo "== Plan 118 — Dompet Absen (checkin_balance) + wallet_type + 1 key ==\n";
echo "Server: {$m->server_info}  DB: {$DB}  Mode: " . ($dryRun ? 'DRY-RUN' : ($verifyOnly ? 'VERIFY' : 'APPLY')) . "\n\n";

// ---------------------------------------------------------------- helpers
/**
 * Metadata satu kolom (atau NULL bila tidak ada).
 *
 * @return array{data_type:string,column_type:string,nullable:string,default:mixed}|null
 */
function column_info(mysqli $m, $DB, $table, $column)
{
    $stmt = $m->prepare(
        "SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param('sss', $DB, $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ( ! $row) return NULL;

    return array(
        'data_type'   => strtolower((string) $row['DATA_TYPE']),
        'column_type' => strtolower((string) $row['COLUMN_TYPE']),
        'nullable'    => strtoupper((string) $row['IS_NULLABLE']),
        'default'     => $row['COLUMN_DEFAULT'],
    );
}

/** Apakah tabel ada? */
function table_exists(mysqli $m, $DB, $table)
{
    $stmt = $m->prepare(
        "SELECT COUNT(*) c FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
    );
    $stmt->bind_param('ss', $DB, $table);
    $stmt->execute();
    $c = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return ($c > 0);
}

/** COUNT(*) sederhana dari query COUNT(*) AS c (0 bila query gagal). */
function count_scalar(mysqli $m, $sql)
{
    $res = $m->query($sql);
    if ( ! $res) return 0;
    $row = $res->fetch_assoc();

    return (int) ($row['c'] ?? 0);
}

/** Nilai satu key `system_settings` (NULL bila baris tidak ada). */
function setting_value(mysqli $m, $key)
{
    $stmt = $m->prepare("SELECT `key_value` v FROM `system_settings` WHERE `key_name` = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string) $row['v'] : NULL;
}

/**
 * Statistik operator (hanya dipanggil bila kolom terkait sudah ada).
 *
 * @return array{ledger:int,main:int,checkin:int,users_nonzero_cb:int,
 *               drift_main:int,drift_checkin:int}
 */
function balance_stats(mysqli $m, $has_wallet_type, $has_checkin_balance)
{
    $out = array(
        'ledger'           => count_scalar($m, "SELECT COUNT(*) c FROM `wallet_ledger`"),
        'main'             => 0,
        'checkin'          => 0,
        'users_nonzero_cb' => 0,
        'drift_main'       => 0,
        'drift_checkin'    => 0,
    );

    if ($has_wallet_type)
    {
        $out['main']    = count_scalar($m, "SELECT COUNT(*) c FROM `wallet_ledger` WHERE `wallet_type` = 'main'");
        $out['checkin'] = count_scalar($m, "SELECT COUNT(*) c FROM `wallet_ledger` WHERE `wallet_type` = 'checkin'");
    }

    if ($has_checkin_balance)
    {
        $out['users_nonzero_cb'] = count_scalar($m, "SELECT COUNT(*) c FROM `users` WHERE `checkin_balance` <> 0");
    }

    // Drift mirror-vs-ledger (informasional; perbaikan ada di
    // scripts/reconcile_balances.php). Kedua kolom wajib ada.
    if ($has_wallet_type && $has_checkin_balance)
    {
        $out['drift_main'] = count_scalar($m, "
            SELECT COUNT(*) c FROM (
                SELECT u.id, u.balance,
                       COALESCE(l.main_balance, 0) AS ledger_balance
                  FROM `users` u
                  LEFT JOIN (
                      SELECT user_id,
                             COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) AS main_balance
                        FROM `wallet_ledger`
                       WHERE `wallet_type` = 'main'
                       GROUP BY user_id
                  ) l ON l.user_id = u.id
            ) d WHERE d.balance <> d.ledger_balance");

        $out['drift_checkin'] = count_scalar($m, "
            SELECT COUNT(*) c FROM (
                SELECT u.id, u.checkin_balance,
                       COALESCE(l.checkin_balance, 0) AS ledger_balance
                  FROM `users` u
                  LEFT JOIN (
                      SELECT user_id,
                             COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) AS checkin_balance
                        FROM `wallet_ledger`
                       WHERE `wallet_type` = 'checkin'
                       GROUP BY user_id
                  ) l ON l.user_id = u.id
            ) d WHERE d.checkin_balance <> d.ledger_balance");
    }

    return $out;
}

/**
 * Verifikasi keadaan target. Read-only.
 *
 * @return array{0:string[],1:string[]} [fail, warn]
 */
function verify_target(mysqli $m, $DB, array $ctx)
{
    $fail = array();
    $warn = array();

    // ── users.checkin_balance ──────────────────────────────────────────
    $colCb = column_info($m, $DB, $ctx['table_u'], $ctx['col_cb']);
    if ($colCb === NULL)
    {
        $fail[] = "kolom `{$ctx['table_u']}.{$ctx['col_cb']}` belum ada";

        return array($fail, $warn);   // tanpa kolom, pemeriksaan lanjutan mustahil
    }

    if ($colCb['data_type'] !== 'decimal' || $colCb['column_type'] !== 'decimal(15,2)')
    {
        $fail[] = "`{$ctx['table_u']}.{$ctx['col_cb']}` harus DECIMAL(15,2) (terdeteksi: {$colCb['column_type']})";
    }
    if ($colCb['nullable'] !== 'NO')
    {
        $fail[] = "`{$ctx['table_u']}.{$ctx['col_cb']}` harus NOT NULL (terdeteksi: {$colCb['nullable']})";
    }
    // DECIMAL DEFAULT dilaporkan sebagai STRING ('0.00') oleh MySQL & MariaDB
    // → bandingkan secara numerik, bukan tipe.
    $def_cb = $colCb['default'];
    if ($def_cb === NULL || ! is_numeric($def_cb) || (float) $def_cb !== 0.0)
    {
        $fail[] = "`{$ctx['table_u']}.{$ctx['col_cb']}` DEFAULT harus 0.00 (terdeteksi: "
                . var_export($def_cb, TRUE) . ')';
    }

    // ── wallet_ledger.wallet_type ──────────────────────────────────────
    $colWt = column_info($m, $DB, $ctx['table_w'], $ctx['col_wt']);
    if ($colWt === NULL)
    {
        $fail[] = "kolom `{$ctx['table_w']}.{$ctx['col_wt']}` belum ada";

        return array($fail, $warn);
    }

    if ($colWt['data_type'] !== 'enum' || $colWt['column_type'] !== "enum('main','checkin')")
    {
        $fail[] = "`{$ctx['table_w']}.{$ctx['col_wt']}` harus ENUM('main','checkin') (terdeteksi: "
                . "{$colWt['column_type']})";
    }
    if ($colWt['nullable'] !== 'NO')
    {
        $fail[] = "`{$ctx['table_w']}.{$ctx['col_wt']}` harus NOT NULL (terdeteksi: {$colWt['nullable']})";
    }
    // MySQL mengembalikan DEFAULT ENUM sebagai `main`; MariaDB mengembalikan
    // literal ber-quote `'main'` → normalkan kutip sebelum membandingkan.
    $def_wt = strtolower(trim((string) $colWt['default'], " \t\n\r\0\x0B'\""));
    if ($def_wt !== 'main')
    {
        $fail[] = "`{$ctx['table_w']}.{$ctx['col_wt']}` DEFAULT harus 'main' (terdeteksi: "
                . var_export($colWt['default'], TRUE) . ')';
    }

    // ── system_settings.checkin_require_active_rental ──────────────────
    $value = setting_value($m, $ctx['key_name']);
    if ($value === NULL)
    {
        $fail[] = "key `{$ctx['table_s']}.{$ctx['key_name']}` belum ada (jalankan --apply)";
    }
    elseif ($value !== '0' && $value !== '1')
    {
        $warn[] = "key `{$ctx['key_name']}` bernilai " . var_export($value, TRUE)
                . " (di luar '0'/'1') — aplikasi jatuh ke fallback per-key "
                . 'application/config/checkin_rewards.php (tidak fatal)';
    }

    // ── Invarian CK2/CK8: mirror = agregat ledger per dompet ───────────
    $stats = balance_stats($m, TRUE, TRUE);

    if ($stats['drift_main'] > 0)
    {
        $warn[] = "{$stats['drift_main']} user punya `balance` <> Σ ledger dompet 'main' — "
                . 'perbaiki dengan: php scripts/reconcile_balances.php --apply';
    }
    if ($stats['drift_checkin'] > 0)
    {
        $warn[] = "{$stats['drift_checkin']} user punya `checkin_balance` <> Σ ledger dompet 'checkin' — "
                . 'perbaiki dengan: php scripts/reconcile_balances.php --apply';
    }

    return array($fail, $warn);
}

// ---------------------------------------------------------------- pre-flight
foreach (array($TABLE_U, $TABLE_W, $TABLE_S) as $t)
{
    if ( ! table_exists($m, $DB, $t))
    {
        fwrite(STDERR, "[FATAL] Tabel `{$t}` tidak ditemukan di {$DB} — hentikan.\n");
        exit(1);
    }
}

if (column_info($m, $DB, $TABLE_U, $ANCHOR_U) === NULL)
{
    fwrite(STDERR, "[FATAL] Kolom `{$TABLE_U}.{$ANCHOR_U}` tidak ada (dipakai sebagai anchor AFTER) — hentikan.\n");
    exit(1);
}

if (column_info($m, $DB, $TABLE_W, $ANCHOR_W) === NULL)
{
    fwrite(STDERR, "[FATAL] Kolom `{$TABLE_W}.{$ANCHOR_W}` tidak ada (dipakai sebagai anchor AFTER) — hentikan.\n");
    exit(1);
}

$ctx = array(
    'table_u'  => $TABLE_U,
    'col_cb'   => $COL_CB,
    'anchor_u' => $ANCHOR_U,
    'table_w'  => $TABLE_W,
    'col_wt'   => $COL_WT,
    'anchor_w' => $ANCHOR_W,
    'table_s'  => $TABLE_S,
    'key_name' => $KEY_NAME,
    'key_val'  => $KEY_VAL,
);

// ---------------------------------------------------------------- inspect
$curCb = column_info($m, $DB, $TABLE_U, $COL_CB);
$curWt = column_info($m, $DB, $TABLE_W, $COL_WT);
$curKv = setting_value($m, $KEY_NAME);
$stats = balance_stats($m, (bool) $curWt, (bool) $curCb);
$total = count_scalar($m, "SELECT COUNT(*) c FROM `{$TABLE_U}`");

echo "Inspeksi ({$TABLE_U}: {$total} baris):\n";
echo '  kolom ' . str_pad($TABLE_U . '.' . $COL_CB, 34) . ($curCb ? 'ADA (' . $curCb['column_type'] . ')' : 'BELUM ADA') . "\n";
echo '  kolom ' . str_pad($TABLE_W . '.' . $COL_WT, 34) . ($curWt ? 'ADA (' . $curWt['column_type'] . ')' : 'BELUM ADA') . "\n";
echo '  key   ' . str_pad($TABLE_S . '.' . $KEY_NAME, 34) . (($curKv !== NULL) ? "ADA ('{$curKv}')" : 'BELUM ADA') . "\n";
echo '  baris ledger ' . str_pad('', 20) . $stats['ledger'] . " baris\n";

if ($curWt)
{
    echo '  ledger dompet main ' . str_pad('', 14) . $stats['main'] . " baris\n";
    echo '  ledger dompet checkin ' . str_pad('', 11) . $stats['checkin'] . " baris\n";
}

if ($curCb)
{
    echo '  user checkin_balance <> 0 ' . str_pad('', 8) . $stats['users_nonzero_cb'] . " baris\n";
}

if ($curCb && $curWt)
{
    echo '  drift mirror main ' . str_pad('', 16) . $stats['drift_main'] . " baris\n";
    echo '  drift mirror checkin ' . str_pad('', 13) . $stats['drift_checkin'] . " baris\n";
}

echo '  anchor ' . str_pad($TABLE_U . '.' . $ANCHOR_U, 33) . "ADA\n";
echo '  anchor ' . str_pad($TABLE_W . '.' . $ANCHOR_W, 33) . "ADA\n\n";

// ---------------------------------------------------------------- verify-only
if ($verifyOnly)
{
    echo "[verify] Memeriksa 2 kolom dompet + 1 key syarat sewa (read-only) ...\n";
    list($fail, $warn) = verify_target($m, $DB, $ctx);

    foreach ($warn as $w) { echo "  [WARN] {$w}\n"; }

    if ($fail)
    {
        fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
        $m->close();
        exit(2);
    }

    $after = balance_stats($m, TRUE, TRUE);
    echo "  kolom {$TABLE_U}.{$COL_CB} " . str_pad('', 9) . "DECIMAL(15,2) NOT NULL DEFAULT 0.00 OK\n";
    echo "  kolom {$TABLE_W}.{$COL_WT}  " . str_pad('', 9) . "ENUM('main','checkin') NOT NULL DEFAULT 'main' OK\n";
    echo "  key   {$KEY_NAME}  " . str_pad('', 9) . "ADA (" . var_export($curKv, TRUE) . ") OK\n";
    echo "  ledger per dompet (CK2)  OK (main={$after['main']}, checkin={$after['checkin']} baris)\n";
    $m->close();
    echo "\n[OK] Verifikasi plan/118 lulus.\n";
    exit(0);
}

// ---------------------------------------------------------------- dry-run
if ($dryRun)
{
    echo "[dry-run] Rencana perubahan (TIDAK ada yang ditulis):\n";

    if ( ! $curCb)
    {
        echo "  1. ALTER TABLE `{$TABLE_U}`\n"
           . "       ADD COLUMN `{$COL_CB}` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `{$ANCHOR_U}`;\n"
           . "     (TANPA backfill — seluruh baris lama otomatis 0.00)\n";
    }
    else
    {
        echo "  1. (kolom `{$TABLE_U}.{$COL_CB}` sudah ada — dilewati)\n";
    }

    if ( ! $curWt)
    {
        echo "  2. ALTER TABLE `{$TABLE_W}`\n"
           . "       ADD COLUMN `{$COL_WT}` ENUM('main','checkin') NOT NULL DEFAULT 'main' AFTER `{$ANCHOR_W}`;\n"
           . "     (TANPA backfill — seluruh baris lama otomatis 'main')\n";
    }
    else
    {
        echo "  2. (kolom `{$TABLE_W}.{$COL_WT}` sudah ada — dilewati)\n";
    }

    if ($curKv === NULL)
    {
        echo "  3. INSERT INTO `{$TABLE_S}` (`key_name`,`key_value`)\n"
           . "       VALUES ('{$KEY_NAME}', '{$KEY_VAL}')\n"
           . "       ON DUPLICATE KEY UPDATE `key_name` = `key_name`;\n";
    }
    else
    {
        echo "  3. (key `{$KEY_NAME}` sudah ada = '{$curKv}' — dilewati, TIDAK ditimpa)\n";
    }

    if ($curWt)
    {
        echo "  4. UPDATE `{$TABLE_W}` SET `{$COL_WT}` = 'main' WHERE `{$COL_WT}` IS NULL;\n"
           . "     (harapan saat ini: 0 baris — DEFAULT menjamin NOT NULL)\n";
    }
    else
    {
        echo "  4. (normalisasi `{$COL_WT}` dilewati — kolom belum ada)\n";
    }

    echo "  5. Verifikasi: tipe/nullability/default 2 kolom + COLUMN_TYPE ENUM + key ada;"
       . " drift mirror dilaporkan sebagai [WARN].\n";
    echo "\nJalankan ulang dengan --apply untuk menerapkan.\n";
    $m->close();
    exit(0);
}

// ---------------------------------------------------------------- apply
try {
    echo "[apply] 1/4 DDL kolom `{$TABLE_U}.{$COL_CB}` + `{$TABLE_W}.{$COL_WT}` ...\n";

    if ( ! $curCb)
    {
        $m->query(
            "ALTER TABLE `{$TABLE_U}` ADD COLUMN `{$COL_CB}` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `{$ANCHOR_U}`"
        );
        echo "  {$TABLE_U}.{$COL_CB}  ditambahkan (TANPA backfill — baris lama = 0.00)\n";
    }
    else
    {
        echo "  {$TABLE_U}.{$COL_CB}  sudah ada (dibiarkan)\n";
    }

    if ( ! $curWt)
    {
        $m->query(
            "ALTER TABLE `{$TABLE_W}` ADD COLUMN `{$COL_WT}` ENUM('main','checkin') NOT NULL DEFAULT 'main' AFTER `{$ANCHOR_W}`"
        );
        echo "  {$TABLE_W}.{$COL_WT}   ditambahkan (TANPA backfill — baris lama = 'main')\n";
    }
    else
    {
        echo "  {$TABLE_W}.{$COL_WT}   sudah ada (dibiarkan)\n";
    }

    echo "[apply] 2/4 Seed key `{$KEY_NAME}` (idempoten; nilai lama TIDAK ditimpa) ...\n";
    $m->query(
        "INSERT INTO `{$TABLE_S}` (`key_name`, `key_value`) VALUES ('{$KEY_NAME}', '{$KEY_VAL}')
         ON DUPLICATE KEY UPDATE `key_name` = `key_name`"
    );
    echo '  ' . $m->affected_rows . " baris tertulis (0 = key sudah ada)\n";

    echo "[apply] 3/4 Normalisasi defensif (`{$COL_WT}` NULL → 'main') ...\n";
    $m->query("UPDATE `{$TABLE_W}` SET `{$COL_WT}` = 'main' WHERE `{$COL_WT}` IS NULL");
    echo '  ' . $m->affected_rows . " baris dinormalkan (harapan 0)\n";

    echo "[apply] 4/4 Verifikasi ...\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] Apply gagal: " . $e->getMessage() . "\n");
    $m->close();
    exit(2);
}

list($fail, $warn) = verify_target($m, $DB, $ctx);

foreach ($warn as $w) { echo "  [WARN] {$w}\n"; }

if ($fail)
{
    fwrite(STDERR, "\n[FATAL] Verifikasi pasca-apply GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
    $m->close();
    exit(2);
}

$after = balance_stats($m, TRUE, TRUE);
echo "\n[OK] Plan 118 siap: `{$TABLE_U}.{$COL_CB}` + `{$TABLE_W}.{$COL_WT}` ada, "
   . "key `{$KEY_NAME}` = " . var_export(setting_value($m, $KEY_NAME), TRUE) . "\n";
echo "     Ledger per dompet: main={$after['main']} baris, checkin={$after['checkin']} baris "
   . "(tanpa backfill — bonus absensi historis tetap dompet 'main').\n";
echo "     Drift mirror: main={$after['drift_main']}, checkin={$after['drift_checkin']} "
   . "(harapan 0 — bila tidak, jalankan php scripts/reconcile_balances.php --apply).\n";

$m->close();
exit(0);
