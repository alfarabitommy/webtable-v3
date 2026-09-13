<?php
/**
 * Synapse (db_webtable) — Plan 105 WhatsApp Group Link Migration CLI
 *
 * One-off operator tool: menyediakan key `system_settings.wa_group_link`
 * (default '') yang dipakai kartu Komunitas di halaman Bantuan member
 * (/help) dan dikelola admin dari /admin/settings.
 *
 *   1. PRE-FLIGHT : tabel `system_settings` ada, unique index `uk_key_name`
 *                   ada, kolom `key_value` bertipe text.
 *   2. INSPECT    : apakah baris `wa_group_link` sudah ada (cetak nilai).
 *   3. SEED       : INSERT IGNORE ('wa_group_link','') — key-value store,
 *                   jadi TIDAK ADA DDL/ALTER/BACKFILL pada migrasi ini.
 *                   Nilai live TIDAK PERNAH ditimpa (tanpa flag --force).
 *   4. VERIFY     : baris ada; nilai kanonik menurut helper aplikasi
 *                   (wa_group_link_normalize() !== null) → deteksi tamper;
 *                   nilai valid tapi non-kanonik hanya diperingatkan.
 *
 *   --dry-run   (default) inspeksi + rencana, TIDAK menulis apa pun
 *   --apply     INSERT IGNORE key + verifikasi
 *   --verify    hanya verifikasi (read-only); exit 2 bila tidak sesuai
 *   --help|-h   usage
 *
 * Safety:
 * - Idempotent: re-run = no-op ("sudah ada (dibiarkan)"), pola seed
 *   kanonik `INSERT IGNORE` di database.sql / database_seed.sql.
 * - Aturan validasi tautan TIDAK diduplikasi: script ini meng-include
 *   application/helpers/wa_group_helper.php (setelah BASEPATH didefinisikan)
 *   sehingga CLI & aplikasi memakai sumber aturan yang sama.
 * - Kredensial dibaca dari application/config/database.php (CI3), sama
 *   seperti scripts/migrate_102_qris_deposits.php & migrate_104_*.
 *
 * Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal.
 */

define('BASEPATH', 'cli-migrate-105-runner');
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
    echo "Usage: php scripts/migrate_105_wa_group_link.php [--dry-run|--apply|--verify] [--help]\n"
       . "  --dry-run  (default) inspeksi tabel/key + cetak rencana, tanpa menulis\n"
       . "  --apply    seed key system_settings.wa_group_link ('') lalu verifikasi\n"
       . "  --verify   hanya verifikasi (read-only); exit 2 bila tidak sesuai\n"
       . "  Catatan: migrasi ini TIDAK mengubah skema (tanpa DDL/ALTER/backfill).\n";
    exit(0);
}

// ---------------------------------------------------------------- helper
// Aturan kanonik tautan = satu sumber (dipakai juga admin & render member).
require_once $ROOT . '/application/helpers/wa_group_helper.php';

if ( ! function_exists('wa_group_link_normalize'))
{
    fwrite(STDERR, "[FATAL] Helper wa_group_helper.php tidak memuat wa_group_link_normalize()\n");
    exit(1);
}

// ---------------------------------------------------------------- config
function load_db_config($file)
{
    if ( ! is_file($file)) return NULL;
    include $file;
    return isset($db['default']) ? $db['default'] : NULL;
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

$DB  = $db['database'];
$KEY = 'wa_group_link';
$VAL = '';

echo "== Plan 105 — WhatsApp group link (system_settings key) ==\n";
echo "Server: {$m->server_info}  DB: {$DB}  Mode: " . ($dryRun ? 'DRY-RUN' : ($verifyOnly ? 'VERIFY' : 'APPLY')) . "\n\n";

// ---------------------------------------------------------------- pre-flight
$tbl = $m->query("SHOW TABLES LIKE 'system_settings'");
if ( ! $tbl || $tbl->num_rows === 0)
{
    fwrite(STDERR, "[FATAL] Tabel `system_settings` tidak ditemukan di {$DB} — hentikan.\n");
    exit(1);
}

$cols = array();
$r = $m->query("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'system_settings'");
while ($row = $r->fetch_assoc()) { $cols[$row['COLUMN_NAME']] = strtolower($row['DATA_TYPE']); }

foreach (array('key_name', 'key_value') as $need)
{
    if ( ! isset($cols[$need]))
    {
        fwrite(STDERR, "[FATAL] Kolom `{$need}` tidak ada di system_settings — hentikan.\n");
        exit(1);
    }
}
$valueIsText = ($cols['key_value'] === 'text');
if ( ! $valueIsText)
{
    fwrite(STDERR, "[FATAL] Kolom `key_value` bukan TEXT (terdeteksi: {$cols['key_value']}) — hentikan.\n");
    exit(1);
}

$idx = array();
$r = $m->query("SELECT DISTINCT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'system_settings'");
while ($row = $r->fetch_assoc()) { $idx[$row['INDEX_NAME']] = (int) $row['NON_UNIQUE']; }

if ( ! isset($idx['uk_key_name']) || $idx['uk_key_name'] !== 0)
{
    fwrite(STDERR, "[FATAL] Unique index `uk_key_name` tidak ada / tidak unik — hentikan.\n");
    exit(1);
}

// ---------------------------------------------------------------- inspect
$totalRows = (int) $m->query("SELECT COUNT(*) c FROM system_settings")->fetch_assoc()['c'];

$stmt = $m->prepare("SELECT key_value FROM system_settings WHERE key_name = ?");
$stmt->bind_param('s', $KEY);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

$rowExists = (bool) $existing;
$stored    = $rowExists ? (string) $existing['key_value'] : NULL;

echo "Inspeksi `system_settings` (baris total: {$totalRows}):\n";
echo '  key ' . str_pad($KEY, 20) . ($rowExists ? 'ADA' : 'BELUM ADA') . "\n";
if ($rowExists)
{
    $shown = $stored === '' ? "(kosong)" : (strlen($stored) > 80 ? substr($stored, 0, 80) . '…' : $stored);
    echo "  nilai tersimpan       {$shown}\n";
}
echo '  unique uk_key_name    OK' . "\n";
echo '  kolom key_value       ' . strtoupper($cols['key_value']) . "\n\n";

// ---------------------------------------------------------------- verify fn
/**
 * Verifikasi keadaan target. Read-only; mengembalikan daftar kegagalan.
 */
function verify_target(mysqli $m, $DB, $KEY)
{
    $fail = array();
    $warn = array();

    $stmt = $m->prepare("SELECT key_value FROM system_settings WHERE key_name = ?");
    $stmt->bind_param('s', $KEY);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ( ! $row)
    {
        $fail[] = "baris `{$KEY}` belum ada";
        return array($fail, $warn);
    }

    $value     = (string) $row['key_value'];
    $canonical = wa_group_link_normalize($value);

    if ($canonical === NULL)
    {
        $fail[] = "nilai `{$KEY}` TIDAK valid menurut wa_group_link_normalize() (tamper?) — "
                . 'harus bentuk https://chat.whatsapp.com/<token> atau string kosong';
    }
    elseif ($canonical !== $value)
    {
        $warn[] = "nilai tersimpan non-kanonik (kanonik: {$canonical}) — pertimbangkan normalisasi via /admin/settings";
    }

    $idx = array();
    $r = $m->query("SELECT DISTINCT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'system_settings'");
    while ($x = $r->fetch_assoc()) { $idx[$x['INDEX_NAME']] = (int) $x['NON_UNIQUE']; }
    if ( ! isset($idx['uk_key_name']) || $idx['uk_key_name'] !== 0)
    {
        $fail[] = 'unique index `uk_key_name` hilang / tidak unik';
    }

    $r = $m->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'system_settings' AND COLUMN_NAME = 'key_value'");
    $type = $r && ($x = $r->fetch_assoc()) ? strtolower($x['DATA_TYPE']) : '';
    if ($type !== 'text')
    {
        $fail[] = "kolom key_value bukan TEXT (terdeteksi: {$type})";
    }

    return array($fail, $warn);
}

// ---------------------------------------------------------------- verify-only
if ($verifyOnly)
{
    echo "[verify] Memeriksa key `{$KEY}` (read-only) ...\n";
    list($fail, $warn) = verify_target($m, $DB, $KEY);

    foreach ($warn as $w) { echo "  [WARN] {$w}\n"; }

    if ($fail)
    {
        fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
        $m->close();
        exit(2);
    }

    echo "  baris key        OK\n";
    echo "  nilai kanonik    OK\n";
    echo "  unique index     uk_key_name OK\n";
    echo "  kolom key_value  TEXT OK\n";
    $m->close();
    echo "\n[OK] Verifikasi plan/105 lulus.\n";
    exit(0);
}

// ---------------------------------------------------------------- dry-run
if ($dryRun)
{
    echo "[dry-run] Rencana perubahan (TIDAK ada yang ditulis):\n";
    if ($rowExists)
    {
        echo "  (baris `{$KEY}` sudah ada — seed akan dilewati, nilai live tidak diubah)\n";
    }
    else
    {
        echo "  1. INSERT IGNORE INTO system_settings (key_name, key_value) VALUES ('{$KEY}', '{$VAL}')\n";
    }
    echo "  2. Tidak ada DDL / ALTER TABLE / backfill (key-value store — baris saja).\n";
    echo "  3. Verifikasi: baris ada + nilai kanonik (wa_group_link_normalize) + unique index.\n";
    echo "\nJalankan ulang dengan --apply untuk menerapkan.\n";
    $m->close();
    exit(0);
}

// ---------------------------------------------------------------- apply
echo "[apply] 1/2 Seed key `{$KEY}` (INSERT IGNORE) ...\n";
$stmt = $m->prepare("INSERT IGNORE INTO system_settings (key_name, key_value) VALUES (?, ?)");
$stmt->bind_param('ss', $KEY, $VAL);
$stmt->execute();
echo '  ' . str_pad($KEY, 20) . ($stmt->affected_rows > 0 ? 'ditambahkan' : 'sudah ada (dibiarkan)') . "\n";
$stmt->close();

echo "[apply] 2/2 Verifikasi ...\n";
list($fail, $warn) = verify_target($m, $DB, $KEY);

foreach ($warn as $w) { echo "  [WARN] {$w}\n"; }

echo "  baris key        " . (in_array("baris `{$KEY}` belum ada", $fail, true) ? 'HILANG' : 'OK') . "\n";
echo "  nilai kanonik    " . ($fail ? 'GAGAL' : 'OK') . "\n";
echo "  unique index     uk_key_name OK\n";

$m->close();

if ($fail)
{
    fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
    exit(2);
}

echo "\n[OK] Migrasi plan/105 selesai & terverifikasi.\n";
exit(0);
