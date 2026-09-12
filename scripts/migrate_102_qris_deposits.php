<?php
/**
 * Synapse (db_webtable) — Plan 102 Manual QRIS Gateway Migration CLI
 *
 * One-off operator tool: mengubah tabel `deposits` menjadi kendaraan gateway
 * QRIS manual (kode unik 3 digit + verifikasi admin), plus BACKFILL yang wajib
 * agar baris lama tetap konsisten:
 *
 *   1. ALTER  : kolom unique_code/total_amount/reserved_code_key/expires_at/
 *               confirmed_at/processed_at/decline_reason, enum status diperluas
 *               (waiting_approval/rejected/expired), UNIQUE uk_reserved_code_key
 *               (eksklusivitas reservasi kode), INDEX idx_status_expires (sweep).
 *   2. BACKFILL total_amount = amount  (parity kredit: jalur approve lama
 *               mengkredit kolom `amount`).
 *   3. BACKFILL expires_at untuk baris 'pending' yang menggantung (WIB now +
 *               deposit_expiry_minutes) — tanpa ini baris produksi langsung
 *               tersapu 'expired' oleh sweep lazy.
 *   4. SEED   : 6 key system_settings plan/102 (INSERT IGNORE — tidak pernah
 *               menimpa nilai live) agar fitur langsung dapat dikonfigurasi.
 *   5. VERIFY : kolom, enum, index, sisa backfill, dan 2 query invarian.
 *
 *   --dry-run   (default) inspeksi + rencana, TIDAK menulis apa pun
 *   --apply     jalankan DDL + backfill + seed, lalu verifikasi
 *   --help|-h   usage
 *
 * Safety:
 * - Idempotent: setiap langkah diperiksa lewat information_schema lebih dulu,
 *   sehingga aman dijalankan berulang (MySQL 8 tidak punya ADD COLUMN
 *   IF NOT EXISTS).
 * - ALTER TABLE di MySQL adalah DDL (auto-commit, tidak bisa di-rollback):
 *   tool ini memverifikasi hasilnya SETELAH setiap tahap, dan berhenti dengan
 *   exit code 2 bila verifikasi gagal.
 * - Backfill bersifat aditif: hanya menyentuh baris dengan nilai default
 *   (total_amount = 0) atau baris 'pending' tanpa expires_at.
 * - Kredensial dibaca dari application/config/database.php (CI3), sama seperti
 *   scripts/expire_rentals.php & scripts/migrate_m7_settings.php.
 *
 * Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal.
 */

define('BASEPATH', 'cli-migrate-102-runner');
define('ENVIRONMENT', 'development');

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Jakarta');   // WIB — otoritas waktu aplikasi (M2)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ROOT = dirname(__DIR__);

$args   = $argv ?: [];
$apply  = in_array('--apply', $args, true);
$dryRun = !$apply;
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Usage: php scripts/migrate_102_qris_deposits.php [--dry-run|--apply] [--help]\n"
       . "  --dry-run  (default) inspeksi kolom/enum/index + cetak rencana, tanpa menulis\n"
       . "  --apply    jalankan ALTER + backfill total_amount/expires_at + seed key, lalu verifikasi\n";
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
    $m->query("SET time_zone = '+07:00'");
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, "[FATAL] Cannot connect to {$db['database']}: " . $e->getMessage() . "\n");
    exit(1);
}

$DB = $db['database'];

echo "== Plan 102 — manual QRIS deposits migration ==\n";
echo "Server: {$m->server_info}  DB: {$DB}  Mode: " . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n\n";

// ---------------------------------------------------------------- spec
$cols = [
    'unique_code'       => "ADD COLUMN `unique_code` SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `amount`",
    'total_amount'      => "ADD COLUMN `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `unique_code`",
    'reserved_code_key' => "ADD COLUMN `reserved_code_key` VARCHAR(24) NULL DEFAULT NULL AFTER `total_amount`",
    'expires_at'        => "ADD COLUMN `expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `reserved_code_key`",
    'confirmed_at'      => "ADD COLUMN `confirmed_at` TIMESTAMP NULL DEFAULT NULL AFTER `expires_at`",
    'processed_at'      => "ADD COLUMN `processed_at` TIMESTAMP NULL DEFAULT NULL AFTER `confirmed_at`",
    'decline_reason'    => "ADD COLUMN `decline_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `processed_at`",
];
$statusEnum = "ENUM('pending','waiting_approval','success','failed','rejected','expired')";
$idxUnique  = ['name' => 'uk_reserved_code_key', 'sql' => "ADD UNIQUE KEY `uk_reserved_code_key` (`reserved_code_key`)"];
$idxSweep   = ['name' => 'idx_status_expires',   'sql' => "ADD INDEX `idx_status_expires` (`status`, `expires_at`)"];

// Key system_settings plan/102 (INSERT IGNORE → tidak menimpa nilai live).
$seedKeys = [
    'qris_image'                 => '',
    'qris_merchant_name'         => 'Synapse',
    'qris_payment_instructions'  => 'Scan QRIS di atas menggunakan aplikasi bank/e-wallet Anda, lalu transfer sejumlah TEPAT nominal yang tertera (termasuk 3 digit kode unik). Deposit diverifikasi manual oleh admin pada jam kerja.',
    'deposit_expiry_minutes'     => '60',
    'deposit_min_amount'         => '10000',
    'deposit_max_amount'         => '50000000',
];

// ---------------------------------------------------------------- pre-flight
$tbl = $m->query("SHOW TABLES LIKE 'deposits'");
if (!$tbl || $tbl->num_rows === 0) {
    fwrite(STDERR, "[FATAL] Tabel `deposits` tidak ditemukan di {$DB} — hentikan.\n");
    exit(1);
}
$sys = $m->query("SHOW TABLES LIKE 'system_settings'");
if (!$sys || $sys->num_rows === 0) {
    fwrite(STDERR, "[FATAL] Tabel `system_settings` tidak ditemukan di {$DB} — hentikan.\n");
    exit(1);
}

// ---------------------------------------------------------------- inspect
$existingCols = [];
$r = $m->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'deposits'");
while ($row = $r->fetch_assoc()) { $existingCols[$row['COLUMN_NAME']] = true; }

$missingCols = [];
foreach ($cols as $name => $sql) { if (!isset($existingCols[$name])) { $missingCols[$name] = $sql; } }

$statusType = '';
$r = $m->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'deposits' AND COLUMN_NAME = 'status'");
if ($row = $r->fetch_assoc()) { $statusType = strtolower(str_replace(' ', '', $row['COLUMN_TYPE'])); }
$wantStatus = strtolower(str_replace(' ', '', $statusEnum));
$enumOk = ($statusType === $wantStatus);

$existingIdx = [];
$r = $m->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'deposits'");
while ($row = $r->fetch_assoc()) { $existingIdx[$row['INDEX_NAME']] = true; }

$needUnique = !isset($existingIdx[$idxUnique['name']]);
$needSweep  = !isset($existingIdx[$idxSweep['name']]);

$rowsTotal   = (int) $m->query("SELECT COUNT(*) c FROM deposits")->fetch_assoc()['c'];
$rowsPending = (int) $m->query("SELECT COUNT(*) c FROM deposits WHERE status = 'pending'")->fetch_assoc()['c'];

echo "Inspeksi tabel `deposits` (baris: {$rowsTotal}; pending: {$rowsPending}):\n";
foreach (array_keys($cols) as $name) {
    echo '  kolom ' . str_pad($name, 20) . (isset($existingCols[$name]) ? 'ADA' : 'BELUM ADA') . "\n";
}
echo '  enum status              ' . ($enumOk ? "OK ({$statusType})" : "PERLU DIPERBARUI ({$statusType})") . "\n";
echo '  index uk_reserved_code_key ' . ($needUnique ? 'BELUM ADA' : 'ADA') . "\n";
echo '  index idx_status_expires   ' . ($needSweep ? 'BELUM ADA' : 'ADA') . "\n\n";

// Nilai expiry untuk backfill: utamakan key dinamis, fallback 60 menit.
$expiryMinutes = 60;
$rowExp = $m->query("SELECT key_value FROM system_settings WHERE key_name = 'deposit_expiry_minutes'");
if ($rowExp && ($er = $rowExp->fetch_assoc())) {
    $cand = (int) $er['key_value'];
    if ($cand >= 5 && $cand <= 1440) { $expiryMinutes = $cand; }
}
$expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));

$backfillTotalSql = "UPDATE deposits SET total_amount = amount WHERE total_amount = 0";
$backfillExpSql   = "UPDATE deposits SET expires_at = '{$expiresAt}'
                      WHERE status = 'pending' AND expires_at IS NULL";

$nothingToDo = (empty($missingCols) && $enumOk && !$needUnique && !$needSweep);

if ($dryRun) {
    echo "[dry-run] Rencana perubahan (TIDAK ada yang ditulis):\n";
    if ($nothingToDo) {
        echo "  (skema sudah sesuai plan/102 — tidak ada DDL yang diperlukan)\n";
    } else {
        echo "  1. ALTER TABLE `deposits`\n";
        if (!$enumOk)          { echo "       MODIFY `status` {$statusEnum} NOT NULL DEFAULT 'pending'\n"; }
        foreach ($missingCols as $name => $sql) { echo '       ' . $sql . "\n"; }
        if ($needUnique)       { echo '       ' . $idxUnique['sql'] . "\n"; }
        if ($needSweep)        { echo '       ' . $idxSweep['sql'] . "\n"; }
    }
    echo "  2. {$backfillTotalSql}\n";
    echo "  3. UPDATE deposits SET expires_at = '{$expiresAt}' WHERE status='pending' AND expires_at IS NULL\n";
    echo "       (waktu WIB now + {$expiryMinutes} menit — jendela bayar baru untuk invoice menggantung)\n";
    echo "  4. INSERT IGNORE 6 key system_settings plan/102: " . implode(', ', array_keys($seedKeys)) . "\n";
    echo "  5. Verifikasi: kolom/enum/index + sisa backfill + invarian reservasi\n";
    echo "\nJalankan ulang dengan --apply untuk menerapkan.\n";
    $m->close();
    exit(0);
}

// ---------------------------------------------------------------- apply
echo "[apply] 1/5 DDL ALTER TABLE `deposits` ...\n";
if ($nothingToDo) {
    echo "  (dilewati — skema sudah sesuai plan/102)\n";
} else {
    $parts = [];
    if (!$enumOk) { $parts[] = "MODIFY `status` {$statusEnum} NOT NULL DEFAULT 'pending'"; }
    foreach ($missingCols as $sql) { $parts[] = $sql; }
    if ($needUnique) { $parts[] = $idxUnique['sql']; }
    if ($needSweep)  { $parts[] = $idxSweep['sql']; }
    $alter = "ALTER TABLE `deposits`\n  " . implode(",\n  ", $parts);
    try {
        $m->query($alter);
        echo "  [OK] ALTER diterapkan (" . count($parts) . " klausa).\n";
    } catch (mysqli_sql_exception $e) {
        fwrite(STDERR, "[FATAL] ALTER gagal: " . $e->getMessage() . "\n");
        $m->close();
        exit(2);
    }
}

echo "[apply] 2/5 Backfill total_amount ...\n";
$m->query($backfillTotalSql);
echo '  affected: ' . $m->affected_rows . "\n";

echo "[apply] 3/5 Backfill expires_at (pending tanpa jendela) ...\n";
$m->query($backfillExpSql);
echo '  affected: ' . $m->affected_rows . "\n";

echo "[apply] 4/5 Seed key system_settings plan/102 (INSERT IGNORE) ...\n";
$stmt = $m->prepare("INSERT IGNORE INTO system_settings (key_name, key_value) VALUES (?, ?)");
foreach ($seedKeys as $k => $v) {
    $stmt->bind_param('ss', $k, $v);
    $stmt->execute();
    echo '  ' . str_pad($k, 28) . ($stmt->affected_rows > 0 ? 'ditambahkan' : 'sudah ada (dibiarkan)') . "\n";
}
$stmt->close();

// ---------------------------------------------------------------- verify
echo "[apply] 5/5 Verifikasi ...\n";
$fail = [];

$existingCols = [];
$r = $m->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'deposits'");
while ($row = $r->fetch_assoc()) { $existingCols[$row['COLUMN_NAME']] = true; }
foreach (array_keys($cols) as $name) {
    if (!isset($existingCols[$name])) { $fail[] = "kolom {$name} hilang"; }
}

$statusType = '';
$r = $m->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'deposits' AND COLUMN_NAME = 'status'");
if ($row = $r->fetch_assoc()) { $statusType = strtolower(str_replace(' ', '', $row['COLUMN_TYPE'])); }
if ($statusType !== $wantStatus) { $fail[] = "enum status tidak sesuai: {$statusType}"; }

$existingIdx = [];
$r = $m->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'deposits'");
while ($row = $r->fetch_assoc()) { $existingIdx[$row['INDEX_NAME']] = true; }
foreach ([$idxUnique['name'], $idxSweep['name']] as $iname) {
    if (!isset($existingIdx[$iname])) { $fail[] = "index {$iname} hilang"; }
}

$leftTotal = (int) $m->query("SELECT COUNT(*) c FROM deposits WHERE total_amount = 0 AND amount > 0")->fetch_assoc()['c'];
if ($leftTotal > 0) { $fail[] = "{$leftTotal} baris masih total_amount = 0"; }

$leftExp = (int) $m->query("SELECT COUNT(*) c FROM deposits WHERE status = 'pending' AND expires_at IS NULL")->fetch_assoc()['c'];
if ($leftExp > 0) { $fail[] = "{$leftExp} baris pending tanpa expires_at"; }

$inconsistent = (int) $m->query(
    "SELECT COUNT(*) c FROM deposits
      WHERE (status IN ('pending','waiting_approval')) <> (reserved_code_key IS NOT NULL)"
)->fetch_assoc()['c'];
if ($inconsistent > 0) { $fail[] = "{$inconsistent} baris melanggar invarian reservasi"; }

$dupReserved = (int) $m->query(
    "SELECT COUNT(*) c FROM (
        SELECT reserved_code_key FROM deposits
         WHERE reserved_code_key IS NOT NULL
         GROUP BY reserved_code_key HAVING COUNT(*) > 1
     ) x"
)->fetch_assoc()['c'];
if ($dupReserved > 0) { $fail[] = "{$dupReserved} reserved_code_key duplikat"; }

$missingKeys = [];
foreach (array_keys($seedKeys) as $k) {
    $row = $m->query("SELECT key_value FROM system_settings WHERE key_name = '" . $m->real_escape_string($k) . "'")->fetch_assoc();
    if (!$row) { $missingKeys[] = $k; }
}
if ($missingKeys) { $fail[] = 'key hilang: ' . implode(', ', $missingKeys); }

echo '  kolom: ' . count($cols) . '/' . count($cols) . " OK\n";
echo "  enum status: OK\n";
echo "  index: uk_reserved_code_key + idx_status_expires OK\n";
echo "  backfill: total_amount sisa {$leftTotal}, pending tanpa expires_at {$leftExp}\n";
echo "  invarian reservasi: {$inconsistent} inconsistent, {$dupReserved} duplikat\n";

$m->close();

if ($fail) {
    fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
    exit(2);
}

echo "\n[OK] Migrasi plan/102 selesai & terverifikasi.\n";
exit(0);
