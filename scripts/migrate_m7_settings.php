<?php
/**
 * Synapse (db_webtable) — M7 Settings Consolidation Migration CLI (plan/70 §3)
 *
 * One-off operator tool: migrates the two legacy keys from the
 * decommissioned `site_settings` table into `system_settings`
 * (single settings store), then drops `site_settings`.
 *
 *   --dry-run   (default) inspect + verify plan, writes nothing
 *   --apply     copy (INSERT IGNORE ... SELECT) -> verify -> DROP TABLE
 *   --help|-h   usage
 *
 * Safety:
 * - INSERT IGNORE never overwrites a live `system_settings` value and never
 *   fails on duplicate key (same convention as the seed files).
 * - DROP TABLE is NOT transactional in MySQL/MariaDB, so `--apply` refuses
 *   to drop unless the post-copy verification passes: both keys exist in
 *   system_settings with values identical to the source rows.
 * - Idempotent: if `site_settings` is already gone, the tool is a no-op.
 * Credentials parsed from application/config/database.php (CI3), same as
 * scripts/expire_rentals.php. Exit codes: 0 = clean, 1 = pre-flight failure,
 * 2 = apply/verify failure.
 */

define('BASEPATH', 'cli-m7-migration-runner');
define('ENVIRONMENT', 'development');

error_reporting(E_ALL);
ini_set('display_errors', '1');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ROOT = dirname(__DIR__);
const MIGRATE_DB = 'db_webtable';

$args   = $argv ?: [];
$apply  = in_array('--apply', $args, true);
$dryRun = !$apply;
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Usage: php scripts/migrate_m7_settings.php [--dry-run|--apply] [--help]\n"
       . "  --dry-run  (default) inspect site_settings rows + dry verification, writes nothing\n"
       . "  --apply    copy keys into system_settings, verify, then DROP TABLE site_settings\n";
    exit(0);
}

// ---------------------------------------------------------------- config
function load_db_config($file)
{
    if (!is_file($file)) return null;
    include $file;
    return isset($db['default']) ? $db['default'] : null;
}

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
echo "== M7 settings consolidation migration ==\n";
echo "Server: {$m->server_info}  DB: {$db['database']}\n\n";

// ---------------------------------------------------------------- pre-flight
$has_site = false;
$q = $m->query("SHOW TABLES LIKE 'site_settings'");
if ($q && $q->num_rows > 0) {
    $has_site = true;
}
$q2 = $m->query("SHOW TABLES LIKE 'system_settings'");
$has_system = $q2 && $q2->num_rows > 0;

if (!$has_site) {
    echo "site_settings sudah tidak ada — migration sudah diterapkan sebelumnya (no-op).\n";
    exit(0);
}
if (!$has_system) {
    fwrite(STDERR, "[FATAL] system_settings tidak ditemukan — hentikan.\n");
    exit(1);
}

// Source rows (legacy store).
$source = [];
$r = $m->query("SELECT key_name, setting_value FROM site_settings ORDER BY key_name");
while ($row = $r->fetch_assoc()) {
    $source[$row['key_name']] = $row['setting_value'];
}
echo "Isi site_settings (sumber migrasi):\n";
foreach ($source as $k => $v) {
    echo "  - {$k} = {$v}\n";
}
if (empty($source)) {
    echo "(kosong — tidak ada baris untuk disalin)\n";
}

if ($dryRun) {
    echo "\n[dry-run] Rencana:\n";
    echo "  1. INSERT IGNORE INTO system_settings (key_name, key_value, updated_at)\n";
    echo "     SELECT key_name, setting_value, updated_at FROM site_settings;\n";
    echo "  2. Verifikasi: setiap key source ada di system_settings dengan nilai sama.\n";
    echo "  3. DROP TABLE site_settings;\n";
    echo "Tidak ada perubahan ditulis (mode dry-run).\n";
    exit(0);
}

// ---------------------------------------------------------------- apply
echo "\n[apply] Menyalin key ke system_settings (INSERT IGNORE) ...\n";
$m->query(
    "INSERT IGNORE INTO system_settings (key_name, key_value, updated_at)
     SELECT key_name, setting_value, updated_at FROM site_settings"
);
echo '  affected/inserted: ' . $m->affected_rows . "\n";

// ---------------------------------------------------------------- verify
echo "[apply] Verifikasi hasil salin ...\n";
$bad = [];
foreach ($source as $k => $v) {
    $row = $m->query(
        "SELECT key_value FROM system_settings WHERE key_name = '" . $m->real_escape_string($k) . "'"
    )->fetch_assoc();
    if (!$row || (string) $row['key_value'] !== (string) $v) {
        $bad[] = $k;
    } else {
        echo "  OK  {$k} = {$row['key_value']}\n";
    }
}
if ($bad) {
    fwrite(STDERR, "[FATAL] Verifikasi gagal untuk key: " . implode(', ', $bad) . " — tabel site_settings TIDAK di-drop.\n");
    exit(2);
}

// ---------------------------------------------------------------- drop
echo "[apply] Menghapus tabel legacy site_settings ...\n";
$m->query("DROP TABLE site_settings");
$after = $m->query("SHOW TABLES LIKE 'site_settings'");
echo $after && $after->num_rows > 0
    ? "[ERROR] site_settings masih ada setelah DROP.\n"
    : "[OK] site_settings berhasil di-drop. Store pengaturan tunggal: system_settings.\n";

$m->close();
exit(0);
