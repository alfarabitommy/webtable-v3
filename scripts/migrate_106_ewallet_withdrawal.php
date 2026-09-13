<?php
/**
 * Synapse (db_webtable) — Plan 106 E-Wallet Withdrawal Gateway Migration CLI
 *
 * One-off operator tool: menyiapkan katalog provider e-wallet (`ewallet_providers`),
 * memetakan binding rekening bank legacy ke provider e-wallet, dan MENGARSIPKAN
 * binding legacy (`is_primary = 0`) sehingga member dapat mengikat ulang nomor HP
 * e-wallet yang valid.
 *
 *   1. PRE-FLIGHT : koneksi DB + tabel `bank_accounts` / `withdrawals` / `users`
 *                   ada + kolom `bank_accounts` sesuai bentuk yang diharapkan.
 *   2. INSPECT    : apakah `ewallet_providers` ada; klasifikasi nama bank legacy;
 *                   baris yang direferensikan withdrawal (in-flight warning);
 *                   binding aktif yang nomornya BUKAN nomor HP e-wallet (= kandidat
 *                   arsip). Dry-run berhenti di sini.
 *   3. DDL        : CREATE TABLE IF NOT EXISTS `ewallet_providers` (idempoten).
 *   4. SEED       : INSERT IGNORE 4 code kanonik (DANA, SHOPEEPAY, OVO, GOPAY) —
 *                   TIDAK pernah menimpa rename/status yang sudah diubah admin.
 *   5. BACKFILL   : nama bank legacy → nama provider (peta deterministik + default
 *                   untuk nama tak dikenal). Idempoten.
 *   6. ARSIP      : UPDATE `is_primary` = 0 untuk binding aktif yang nomornya tidak
 *                   lolos ^08[0-9]{8,11}$. TIDAK PERNAH menghapus baris — FK
 *                   `fk_withdrawals_bank` ON DELETE RESTRICT + seluruh kartu riwayat
 *                   penarikan membaca nama provider/nomor dari baris tersebut.
 *   7. VERIFY     : 4 code kanonik aktif; tanpa drift nama; semua binding aktif
 *                   bernomor HP valid (diverifikasi ulang dengan helper aplikasi);
 *                   0 orphan FK; total baris `bank_accounts` tidak berkurang.
 *
 *   --dry-run             (default) inspeksi + rencana, TIDAK menulis apa pun
 *   --apply               DDL + seed + backfill + arsip + verifikasi
 *   --verify              hanya verifikasi (read-only); exit 2 bila tidak sesuai
 *   --keep-bindings       lewati fase ARSIP (escape hatch operator — binding legacy
 *                         dibiarkan aktif; member TIDAK dipaksa re-bind)
 *   --default-provider=C  provider tujuan untuk nama bank tak dikenal (default DANA)
 *   --help | -h           usage
 *
 * Safety:
 * - Idempotent: re-run = no-op (peta & arsip berbasis predikat, bukan asumsi state).
 * - Aturan nomor HP TIDAK diduplikasi sebagai logika: script ini meng-include
 *   `application/helpers/ewallet_helper.php` (setelah BASEPATH didefinisikan) dan
 *   memakai `ewallet_phone_is_valid()` sebagai verifikator otoritatif per baris.
 *   Filter massal memakai REGEXP SQL dengan pola yang SAMA, lalu diverifikasi ulang
 *   di PHP sehingga keduanya tidak bisa berbeda tanpa terdeteksi.
 * - Kredensial dibaca dari application/config/database.php (CI3), sama seperti
 *   scripts/migrate_102_qris_deposits.php / migrate_104_* / migrate_105_*.
 *
 * Rollback (arsip reversibel — daftar ID dicetak saat --apply):
 *   UPDATE `bank_accounts` SET `is_primary` = 1 WHERE `id` IN (…);
 *   Catatan: member yang sudah mengikat ulang akan memegang DUA baris aktif;
 *   `Wallet_model::get_user_ewallet()` deterministik (ORDER BY id DESC LIMIT 1)
 *   dan fase VERIFY menandainya sebagai WARN.
 *
 * Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal.
 */

define('BASEPATH', 'cli-migrate-106-runner');
define('ENVIRONMENT', 'development');

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Jakarta');   // WIB — otoritas waktu aplikasi (M2)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ROOT = dirname(__DIR__);

// Pola otoritatif nomor HP e-wallet: numerik, diawali 08, panjang 10–13 digit.
// Sumber kebenaran runtime = application/helpers/ewallet_helper.php (di-include
// di bawah); konstanta ini hanya cermin SQL dari aturan yang sama.
const EWALLET_PHONE_REGEXP = '^08[0-9]{8,11}$';

// Katalog provider kanonik (code => name) — sama dengan seed database.sql.
const CANONICAL_PROVIDERS = array(
    'DANA'      => 'DANA',
    'SHOPEEPAY' => 'ShopeePay',
    'OVO'       => 'OVO',
    'GOPAY'     => 'GoPay',
);

// Peta deterministik nama bank legacy → nama provider (key = huruf kecil).
// Baris diarsipkan oleh fase 6, jadi peta ini praktis hanya memengaruhi label
// riwayat; pemetaan tetap deterministik & idempoten.
const LEGACY_NAME_MAP = array(
    'bca'                          => 'DANA',
    'bank bca'                     => 'DANA',
    'bank central asia (bca)'      => 'DANA',
    'bni'                          => 'DANA',
    'bank negara indonesia (bni)'  => 'DANA',
    'mandiri'                      => 'OVO',
    'bank mandiri'                 => 'OVO',
    'bri'                          => 'GoPay',
    'bank rakyat indonesia (bri)'  => 'GoPay',
    'cimb'                         => 'ShopeePay',
    'bank cimb niaga'              => 'ShopeePay',
);

$args       = $argv ?: [];
$apply      = in_array('--apply', $args, true);
$verifyOnly = in_array('--verify', $args, true);
$keepBind   = in_array('--keep-bindings', $args, true);
$dryRun     = ( ! $apply && ! $verifyOnly);

$defaultProvider = 'DANA';
foreach ($args as $a)
{
    if (strpos($a, '--default-provider=') === 0)
    {
        $defaultProvider = strtoupper(trim(substr($a, strlen('--default-provider='))));
    }
}

if (in_array('--help', $args, true) || in_array('-h', $args, true))
{
    echo "Usage: php scripts/migrate_106_ewallet_withdrawal.php [--dry-run|--apply|--verify]\n"
       . "       [--keep-bindings] [--default-provider=CODE] [--help]\n"
       . "  --dry-run           (default) inspeksi + cetak rencana, tanpa menulis\n"
       . "  --apply             DDL + seed provider + backfill nama + arsip binding legacy + verify\n"
       . "  --verify            hanya verifikasi (read-only); exit 2 bila tidak sesuai\n"
       . "  --keep-bindings     lewati fase ARSIP (binding legacy tetap aktif)\n"
       . "  --default-provider  provider untuk nama bank tak dikenal (default: DANA)\n";
    exit(0);
}

// ---------------------------------------------------------------- helper
require_once $ROOT . '/application/helpers/ewallet_helper.php';

if ( ! function_exists('ewallet_phone_is_valid') || ! function_exists('ewallet_phone_validate'))
{
    fwrite(STDERR, "[FATAL] Helper ewallet_helper.php tidak memuat ewallet_phone_is_valid()/ewallet_phone_validate()\n");
    exit(1);
}

// ---------------------------------------------------------------- config
function load_db_config($file)
{
    if ( ! is_file($file)) return NULL;

    $db           = NULL;
    $active_group = NULL;
    include $file;

    if ( ! is_array($db)) return NULL;

    // CI3 semantics: gunakan grup AKTIF ($active_group), lalu fallback ke
    // 'default' / grup pertama yang tersedia (config lokal bisa memakai nama
    // grup lain, mis. 'local' / 'live').
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

if ( ! isset(CANONICAL_PROVIDERS[$defaultProvider]))
{
    fwrite(STDERR, "[FATAL] --default-provider={$defaultProvider} bukan code kanonik ("
        . implode(', ', array_keys(CANONICAL_PROVIDERS)) . ")\n");
    $m->close();
    exit(1);
}

echo "== Plan 106 — E-wallet withdrawal gateway (provider catalog + binding migrasi) ==\n";
echo "Server: {$m->server_info}  DB: {$DB}  Mode: " . ($dryRun ? 'DRY-RUN' : ($verifyOnly ? 'VERIFY' : 'APPLY'))
   . ($keepBind ? '  (--keep-bindings)' : '') . "\n\n";

// ---------------------------------------------------------------- pre-flight
foreach (array('bank_accounts', 'withdrawals', 'users') as $need)
{
    $t = $m->query("SHOW TABLES LIKE '" . $m->real_escape_string($need) . "'");
    if ( ! $t || $t->num_rows === 0)
    {
        fwrite(STDERR, "[FATAL] Tabel `{$need}` tidak ditemukan di {$DB} — hentikan.\n");
        $m->close();
        exit(1);
    }
}

$bankCols = array();
$r = $m->query("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'bank_accounts'");
while ($row = $r->fetch_assoc()) { $bankCols[$row['COLUMN_NAME']] = strtolower($row['DATA_TYPE']); }

foreach (array('id', 'user_id', 'bank_name', 'account_number', 'account_holder', 'is_primary') as $need)
{
    if ( ! isset($bankCols[$need]))
    {
        fwrite(STDERR, "[FATAL] Kolom `bank_accounts`.`{$need}` tidak ada — hentikan.\n");
        $m->close();
        exit(1);
    }
}

// ---------------------------------------------------------------- helpers
function table_exists(mysqli $m, $name)
{
    $t = $m->query("SHOW TABLES LIKE '" . $m->real_escape_string($name) . "'");
    return (bool) ($t && $t->num_rows > 0);
}

function scalar(mysqli $m, $sql, $params = array(), $types = '')
{
    if ( ! $params)
    {
        $res = $m->query($sql);
        $row = $res ? $res->fetch_row() : array(0);
        return $row ? $row[0] : 0;
    }
    $stmt = $m->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : array(0);
    $stmt->close();
    return $row ? $row[0] : 0;
}

/**
 * Snapshot keadaan saat ini (read-only) — dipakai fase 2 (inspeksi) dan 7 (verify).
 *
 * @return array
 */
function inspect_state(mysqli $m, $DB)
{
    $s = array();

    $s['has_table']  = table_exists($m, 'ewallet_providers');
    $s['providers']  = array();
    if ($s['has_table'])
    {
        $r = $m->query("SELECT id, code, name, is_active FROM ewallet_providers ORDER BY id");
        while ($row = $r->fetch_assoc()) { $s['providers'][] = $row; }
    }

    $s['bank_total']    = (int) scalar($m, "SELECT COUNT(*) FROM bank_accounts");
    $s['bank_active']   = (int) scalar($m, "SELECT COUNT(*) FROM bank_accounts WHERE is_primary = 1");
    $s['bank_archived'] = (int) scalar($m, "SELECT COUNT(*) FROM bank_accounts WHERE is_primary = 0");

    // Klasifikasi nama: sudah nama provider (katalog) vs legacy/tak dikenal.
    $known = array();
    foreach ($s['providers'] as $p) { $known[] = $p['name']; }

    $s['name_counts'] = array();
    $r = $m->query("SELECT bank_name, COUNT(*) c, SUM(is_primary = 1) act
                      FROM bank_accounts GROUP BY bank_name ORDER BY c DESC, bank_name ASC");
    while ($row = $r->fetch_assoc())
    {
        $s['name_counts'][] = array(
            'name'     => $row['bank_name'],
            'total'    => (int) $row['c'],
            'active'   => (int) $row['act'],
            'is_known' => in_array($row['bank_name'], $known, true),
            'mapped'   => isset(LEGACY_NAME_MAP[strtolower(trim((string) $row['bank_name']))])
                            ? LEGACY_NAME_MAP[strtolower(trim((string) $row['bank_name']))]
                            : NULL,
        );
    }

    // Binding aktif yang nomornya BUKAN nomor HP e-wallet → kandidat arsip.
    $s['invalid_active'] = array();
    $r = $m->query("SELECT id, user_id, bank_name, account_number, is_primary
                      FROM bank_accounts WHERE is_primary = 1 ORDER BY id");
    while ($row = $r->fetch_assoc())
    {
        if ( ! ewallet_phone_is_valid((string) $row['account_number']))
        {
            $s['invalid_active'][] = $row;
        }
    }

    // Referensi withdrawal (in-flight awareness + bukti FK tidak boleh dihapus).
    $s['referenced'] = array();
    $r = $m->query("SELECT bank_account_id, COUNT(*) c, GROUP_CONCAT(status) st
                      FROM withdrawals WHERE bank_account_id IS NOT NULL
                     GROUP BY bank_account_id ORDER BY bank_account_id");
    while ($row = $r->fetch_assoc())
    {
        $s['referenced'][(int) $row['bank_account_id']] = array('count' => (int) $row['c'], 'status' => $row['st']);
    }

    $s['in_flight'] = (int) scalar($m, "SELECT COUNT(*) FROM withdrawals WHERE status IN ('pending','processing')");
    $s['fk_orphans'] = (int) scalar($m, "SELECT COUNT(*) FROM withdrawals w
                                          LEFT JOIN bank_accounts b ON b.id = w.bank_account_id
                                         WHERE w.bank_account_id IS NOT NULL AND b.id IS NULL");
    $s['drift'] = $s['has_table']
        ? (int) scalar($m, "SELECT COUNT(*) FROM bank_accounts b
                             LEFT JOIN ewallet_providers p ON p.name = b.bank_name
                            WHERE b.is_primary = 1 AND p.id IS NULL")
        : -1;
    $s['multi_active'] = (int) scalar($m, "SELECT COUNT(*) FROM (
                                            SELECT user_id FROM bank_accounts
                                             WHERE is_primary = 1 GROUP BY user_id HAVING COUNT(*) > 1) t");

    return $s;
}

/**
 * Verifikasi keadaan target. Read-only; mengembalikan array(fail, warn).
 */
function verify_target(mysqli $m, $DB)
{
    $fail = array();
    $warn = array();

    if ( ! table_exists($m, 'ewallet_providers'))
    {
        $fail[] = 'tabel `ewallet_providers` belum ada';
        return array($fail, $warn);
    }

    // Bentuk tabel + unique index code.
    $cols = array();
    $r = $m->query("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'ewallet_providers'");
    while ($row = $r->fetch_assoc()) { $cols[$row['COLUMN_NAME']] = strtolower($row['DATA_TYPE']); }
    foreach (array('id', 'code', 'name', 'is_active', 'created_at', 'updated_at') as $need)
    {
        if ( ! isset($cols[$need])) { $fail[] = "kolom `ewallet_providers`.`{$need}` hilang"; }
    }
    if (isset($cols['code']) && $cols['code'] !== 'varchar') { $fail[] = 'kolom `code` bukan VARCHAR'; }
    if (isset($cols['name']) && $cols['name'] !== 'varchar') { $fail[] = 'kolom `name` bukan VARCHAR'; }

    $idx = array();
    $r = $m->query("SELECT DISTINCT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'ewallet_providers'");
    while ($row = $r->fetch_assoc()) { $idx[$row['INDEX_NAME']] = (int) $row['NON_UNIQUE']; }
    if ( ! isset($idx['uk_ewallet_code']) || $idx['uk_ewallet_code'] !== 0)
    {
        $fail[] = 'unique index `uk_ewallet_code` hilang / tidak unik';
    }

    // V1 — 4 code kanonik ada & aktif.
    $codes = array();
    $r = $m->query("SELECT code, name, is_active FROM ewallet_providers");
    while ($row = $r->fetch_assoc()) { $codes[$row['code']] = $row; }
    $missing = array();
    foreach (CANONICAL_PROVIDERS as $code => $name)
    {
        if ( ! isset($codes[$code]))                        { $missing[] = "{$code} (hilang)"; }
        elseif ((int) $codes[$code]['is_active'] !== 1)     { $missing[] = "{$code} (nonaktif)"; }
    }
    if ($missing) { $fail[] = 'provider kanonik tidak siap: ' . implode(', ', $missing); }

    $names = array();
    foreach ($codes as $row) { $names[] = $row['name']; }

    // V2 — tanpa drift: binding aktif harus menunjuk nama provider yang ada.
    $drift = (int) scalar($m, "SELECT COUNT(*) FROM bank_accounts b
                                LEFT JOIN ewallet_providers p ON p.name = b.bank_name
                               WHERE b.is_primary = 1 AND p.id IS NULL");
    if ($drift > 0) { $fail[] = "{$drift} binding AKTIF menunjuk provider yang tidak ada di katalog (drift)"; }

    // V3 — semua binding aktif bernomor HP e-wallet valid (verifikasi PHP otoritatif).
    $bad = array();
    $r = $m->query("SELECT id, user_id, bank_name, account_number FROM bank_accounts WHERE is_primary = 1");
    while ($row = $r->fetch_assoc())
    {
        if ( ! ewallet_phone_is_valid((string) $row['account_number'])) { $bad[] = (int) $row['id']; }
    }
    if ($bad) { $fail[] = count($bad) . ' binding AKTIF bernomor tidak valid (id: ' . implode(', ', array_slice($bad, 0, 10)) . ')'; }

    // V4 — integritas FK (bukti tidak ada baris yang dihapus).
    $orphans = (int) scalar($m, "SELECT COUNT(*) FROM withdrawals w
                                  LEFT JOIN bank_accounts b ON b.id = w.bank_account_id
                                 WHERE w.bank_account_id IS NOT NULL AND b.id IS NULL");
    if ($orphans > 0) { $fail[] = "{$orphans} withdrawal menunjuk bank_accounts yang hilang (FK rusak)"; }

    // V5 — maksimum satu binding aktif per user (WARN: bisa terjadi setelah re-bind).
    $multi = (int) scalar($m, "SELECT COUNT(*) FROM (
                                SELECT user_id FROM bank_accounts
                                 WHERE is_primary = 1 GROUP BY user_id HAVING COUNT(*) > 1) t");
    if ($multi > 0) { $warn[] = "{$multi} user memiliki lebih dari satu binding aktif (setelah re-bind) — aman: get_user_ewallet() memilih id terbesar"; }

    return array($fail, $warn);
}

// ---------------------------------------------------------------- inspect
$before = inspect_state($m, $DB);

echo "Inspeksi:\n";
echo '  tabel ewallet_providers   ' . ($before['has_table'] ? 'ADA (' . count($before['providers']) . ' baris)' : 'BELUM ADA') . "\n";
if ($before['has_table'])
{
    foreach ($before['providers'] as $p)
    {
        echo '      #' . str_pad((string) $p['id'], 3) . str_pad($p['code'], 12) . str_pad($p['name'], 14)
           . ((int) $p['is_active'] === 1 ? 'aktif' : 'NONAKTIF') . "\n";
    }
}
echo '  bank_accounts             ' . $before['bank_total'] . ' baris ('
   . $before['bank_active'] . ' aktif / ' . $before['bank_archived'] . ' arsip)' . "\n";
echo '  withdrawal in-flight      ' . $before['in_flight'] . ' (pending/processing)' . "\n";
echo '  bank_account direferensikan ' . count($before['referenced']) . " baris\n";
echo "\n  Distribusi nama (aktif/total → peta):\n";
foreach ($before['name_counts'] as $nc)
{
    $dest = $nc['is_known'] ? '(sudah provider)' : ($nc['mapped'] !== NULL ? '→ ' . $nc['mapped'] : '→ DEFAULT');
    echo '      ' . str_pad($nc['name'], 30) . str_pad($nc['active'] . '/' . $nc['total'], 8) . $dest . "\n";
}
echo "\n  Binding aktif yang nomornya BUKAN nomor HP e-wallet: " . count($before['invalid_active']) . "\n";
foreach (array_slice($before['invalid_active'], 0, 20) as $row)
{
    echo '      #' . str_pad((string) $row['id'], 4) . 'user ' . str_pad((string) $row['user_id'], 5)
       . str_pad($row['bank_name'], 14) . $row['account_number']
       . (isset($before['referenced'][(int) $row['id']]) ? '  [direferensikan withdrawal: ' . $before['referenced'][(int) $row['id']]['status'] . ']' : '')
       . "\n";
}
if (count($before['invalid_active']) > 20) { echo '      … (+' . (count($before['invalid_active']) - 20) . " lainnya)\n"; }
echo "\n";

// ---------------------------------------------------------------- verify-only
if ($verifyOnly)
{
    echo "[verify] Memeriksa keadaan target (read-only) ...\n";
    list($fail, $warn) = verify_target($m, $DB);

    foreach ($warn as $w) { echo "  [WARN] {$w}\n"; }

    if ($fail)
    {
        fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
        $m->close();
        exit(2);
    }

    echo "  provider kanonik (4)      OK\n";
    echo "  tanpa drift nama          OK\n";
    echo "  nomor HP e-wallet valid   OK\n";
    echo "  integritas FK withdrawal  OK\n";
    echo "  unique uk_ewallet_code    OK\n";
    $m->close();
    echo "\n[OK] Verifikasi plan/106 lulus.\n";
    exit(0);
}

// ---------------------------------------------------------------- dry-run
if ($dryRun)
{
    echo "[dry-run] Rencana perubahan (TIDAK ada yang ditulis):\n";
    echo '  1. ' . ($before['has_table'] ? 'SKIP  ' : 'CREATE') . " TABLE IF NOT EXISTS ewallet_providers (id, code UNIQUE, name, is_active, created_at, updated_at)\n";
    echo "  2. INSERT IGNORE 4 provider kanonik: " . implode(', ', array_keys(CANONICAL_PROVIDERS)) . "\n";
    foreach ($before['name_counts'] as $nc)
    {
        if ($nc['is_known']) { continue; }
        $dest = $nc['mapped'] !== NULL ? $nc['mapped'] : $defaultProvider . ' (default)';
        echo '  3. UPDATE bank_accounts SET bank_name = ' . str_pad($dest, 14) . ' WHERE LOWER(bank_name) = ' . strtolower($nc['name']) . "  ({$nc['total']} baris)\n";
    }
    if ($keepBind)
    {
        echo "  4. SKIP   fase ARSIP (--keep-bindings) — binding legacy tetap aktif\n";
    }
    else
    {
        echo '  4. UPDATE bank_accounts SET is_primary = 0 WHERE is_primary = 1 AND account_number NOT REGEXP '
           . EWALLET_PHONE_REGEXP . '  (' . count($before['invalid_active']) . " baris)\n";
    }
    echo "  5. Verifikasi: 4 provider aktif, tanpa drift, nomor HP valid, 0 orphan FK.\n";
    echo "\nJalankan ulang dengan --apply untuk menerapkan.\n";
    $m->close();
    exit(0);
}

// ---------------------------------------------------------------- apply
$step = 0;
$totalSteps = 4 + ($keepBind ? 0 : 1);

// 1) DDL
$step++;
echo "[apply] {$step}/5 DDL tabel `ewallet_providers` ...\n";
if ($before['has_table'])
{
    echo "  [skip] tabel sudah ada — bentuk diperiksa di fase verifikasi\n";
}
else
{
    $m->query("CREATE TABLE IF NOT EXISTS `ewallet_providers` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `code` VARCHAR(50) NOT NULL,
                  `name` VARCHAR(100) NOT NULL,
                  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uk_ewallet_code` (`code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  [ok] tabel dibuat\n";
}

// 2) Seed
$step++;
echo "[apply] {$step}/5 Seed provider kanonik (INSERT IGNORE) ...\n";
$stmt = $m->prepare("INSERT IGNORE INTO ewallet_providers (code, name, is_active) VALUES (?, ?, 1)");
foreach (CANONICAL_PROVIDERS as $code => $name)
{
    $stmt->bind_param('ss', $code, $name);
    $stmt->execute();
    echo '  ' . str_pad($code, 12) . ($stmt->affected_rows > 0 ? 'ditambahkan' : 'sudah ada (dibiarkan)') . "\n";
}
$stmt->close();

// 3) Backfill nama legacy → provider
$step++;
echo "[apply] {$step}/5 Backfill nama bank legacy → provider ...\n";
$mapped = 0;
$stmt = $m->prepare("UPDATE bank_accounts SET bank_name = ?
                      WHERE LOWER(bank_name) = ?
                        AND bank_name NOT IN (SELECT name FROM ewallet_providers)");
foreach (LEGACY_NAME_MAP as $legacy => $provider)
{
    $stmt->bind_param('ss', $provider, $legacy);
    $stmt->execute();
    if ($stmt->affected_rows > 0)
    {
        $mapped += $stmt->affected_rows;
        echo '  [ok] ' . str_pad($legacy, 30) . '→ ' . str_pad($provider, 12) . $stmt->affected_rows . " baris\n";
    }
}
$stmt->close();

// Sisa nama tak dikenal → provider default.
$stmt = $m->prepare("UPDATE bank_accounts SET bank_name = ?
                      WHERE bank_name NOT IN (SELECT name FROM ewallet_providers)");
$stmt->bind_param('s', $defaultProvider);
$stmt->execute();
$leftover = $stmt->affected_rows;
$stmt->close();
if ($leftover > 0)
{
    $mapped += $leftover;
    echo '  [ok] ' . str_pad('(nama tak dikenal)', 30) . '→ ' . str_pad($defaultProvider, 12) . $leftover . " baris\n";
}
echo '  Total baris dipetakan: ' . $mapped . ($mapped === 0 ? " (idempoten — tidak ada yang perlu diubah)" : '') . "\n";

// 4) Arsip binding legacy
$step++;
$archivedIds = array();
if ($keepBind)
{
    echo "[apply] {$step}/5 Fase ARSIP DILEWATI (--keep-bindings) — binding legacy tetap aktif\n";
    $skipped = 0;
}
else
{
    echo "[apply] {$step}/5 Arsip binding legacy (is_primary = 0) ...\n";

    // Daftar ID dicetak untuk rollback (read-only, sebelum UPDATE).
    $r = $m->query("SELECT id FROM bank_accounts WHERE is_primary = 1 AND account_number NOT REGEXP '" . EWALLET_PHONE_REGEXP . "' ORDER BY id");
    while ($row = $r->fetch_row()) { $archivedIds[] = (int) $row[0]; }

    $stmt = $m->prepare("UPDATE bank_accounts SET is_primary = 0
                          WHERE is_primary = 1 AND account_number NOT REGEXP ?");
    $pattern = EWALLET_PHONE_REGEXP;
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $skipped = $stmt->affected_rows;
    $stmt->close();

    echo '  [ok] ' . $skipped . ' binding diarsipkan (member wajib re-bind)'
       . ($skipped === 0 ? ' — idempoten, tidak ada yang perlu diarsipkan' : '') . "\n";
    if ($archivedIds)
    {
        echo '  ID terarsip (untuk rollback): ' . implode(', ', $archivedIds) . "\n";
        echo "  Rollback: UPDATE `bank_accounts` SET `is_primary` = 1 WHERE `id` IN (" . implode(',', $archivedIds) . ");\n";
    }
}

// 5) Verify
$step++;
echo "[apply] {$step}/5 Verifikasi ...\n";
$after = inspect_state($m, $DB);
list($fail, $warn) = verify_target($m, $DB);

if ($after['bank_total'] !== $before['bank_total'])
{
    $fail[] = 'jumlah baris bank_accounts berubah (' . $before['bank_total'] . ' → ' . $after['bank_total']
            . ') — migrasi TIDAK boleh menghapus baris';
}
if ( ! $keepBind && count($before['invalid_active']) > 0 && $after['bank_archived'] < $before['bank_archived'] + count($before['invalid_active']))
{
    $fail[] = 'jumlah baris terarsip lebih kecil dari yang diharapkan ('
            . $after['bank_archived'] . ' < ' . ($before['bank_archived'] + count($before['invalid_active'])) . ')';
}

foreach ($warn as $w) { echo "  [WARN] {$w}\n"; }

echo '  baris bank_accounts       ' . $after['bank_total'] . ' (tetap, tidak ada yang dihapus)' . "\n";
echo '  binding aktif / arsip     ' . $after['bank_active'] . ' / ' . $after['bank_archived'] . "\n";
echo '  provider kanonik (4)      ' . ($after['has_table'] ? 'OK' : 'HILANG') . "\n";
echo '  drift nama                ' . ($after['drift'] === 0 ? 'OK' : $after['drift']) . "\n";
echo '  orphan FK withdrawal      ' . $after['fk_orphans'] . ($after['fk_orphans'] === 0 ? ' OK' : ' GAGAL') . "\n";

$m->close();

if ($fail)
{
    fwrite(STDERR, "\n[FATAL] Migrasi/verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
    exit(2);
}

echo "\n[OK] Migrasi plan/106 selesai & terverifikasi.\n";
exit(0);
