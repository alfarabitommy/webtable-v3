<?php
/**
 * Synapse (db_webtable) — Plan 112 Daily Check-in (Absensi Harian) Migration CLI
 *
 * One-off operator tool: menyediakan state absensi harian —
 *   1. DUA KOLOM `users`  : `checkin_streak` (INT UNSIGNED NOT NULL DEFAULT 0),
 *                           `checkin_last_date` (DATE NULL DEFAULT NULL);
 *   2. EMPAT KUNCI `system_settings`: checkin_enabled / checkin_base_reward /
 *                           checkin_max_reward / checkin_streak_policy.
 *
 *   1. PRE-FLIGHT : tabel `users` + `system_settings` ada, kolom anchor
 *                   `users.last_wage_claimed_at` ada (dipakai AFTER), kolom
 *                   `key_value` bertipe TEXT, unique index `uk_key_name` ada.
 *   2. INSPECT    : kolom absensi sudah ada / belum; 4 kunci sudah ada / belum.
 *   3. DDL        : ADD COLUMN dijaga information_schema (idempoten; MySQL 8
 *                   tidak punya ADD COLUMN IF NOT EXISTS). TANPA backfill —
 *                   baris lama cukup memakai DEFAULT 0 / NULL.
 *   4. SEED       : INSERT IGNORE 4 kunci (nilai live TIDAK PERNAH ditimpa).
 *   5. VERIFY     : kolom ada dengan tipe/nullability/default benar; 4 baris ada;
 *                   nilai tidak rusak (tamper → exit 2).
 *
 *   --dry-run   (default) inspeksi + rencana, TIDAK menulis apa pun
 *   --apply     DDL + seed 4 kunci + verifikasi
 *   --verify    hanya verifikasi (read-only); exit 2 bila tidak sesuai
 *   --help|-h   usage
 *
 * Safety:
 * - Idempotent: re-run = no-op ("sudah ada (dibiarkan)"), pola seed kanonik
 *   `INSERT IGNORE` di database.sql / database_seed.sql.
 * - Ambang batas & default TIDAK diduplikasi: script ini meng-include
 *   application/config/checkin_rewards.php (setelah BASEPATH didefinisikan)
 *   sehingga CLI & aplikasi memakai satu sumber angka.
 * - Kredensial dibaca dari application/config/database.php memakai semantik CI3
 *   ($active_group → 'default' → grup pertama), pola scripts/migrate_106_*.
 *
 * Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal.
 */

define('BASEPATH', 'cli-migrate-112-runner');
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
    echo "Usage: php scripts/migrate_112_daily_checkin.php [--dry-run|--apply|--verify] [--help]\n"
       . "  --dry-run  (default) inspeksi kolom/key + cetak rencana, tanpa menulis\n"
       . "  --apply    tambah 2 kolom `users` + seed 4 kunci system_settings, lalu verifikasi\n"
       . "  --verify   hanya verifikasi (read-only); exit 2 bila tidak sesuai\n"
       . "  Catatan: TANPA backfill — baris users lama memakai DEFAULT 0 / NULL.\n";
    exit(0);
}

// ---------------------------------------------------------------- config app
// Satu sumber default & ambang batas (dipakai juga Checkin_model).
$FALLBACK = require $ROOT . '/application/config/checkin_rewards.php';

if ( ! is_array($FALLBACK) || ! isset($FALLBACK['checkin_base_reward']))
{
    fwrite(STDERR, "[FATAL] application/config/checkin_rewards.php tidak mengembalikan peta setelan.\n");
    exit(1);
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

// Target: kolom + kunci.
$COL_STREAK = 'checkin_streak';
$COL_DATE   = 'checkin_last_date';
$ANCHOR     = 'last_wage_claimed_at';   // AFTER klausa (pre-flight memastikan ada)
$KEYS       = array(
    'checkin_enabled'       => (string) $FALLBACK['checkin_enabled'],
    'checkin_base_reward'   => (string) $FALLBACK['checkin_base_reward'],
    'checkin_max_reward'    => (string) $FALLBACK['checkin_max_reward'],
    'checkin_streak_policy' => (string) $FALLBACK['checkin_streak_policy'],
);

echo "== Plan 112 — Daily Check-in (absensi harian): 2 kolom users + 4 kunci system_settings ==\n";
echo "Server: {$m->server_info}  DB: {$DB}  Mode: " . ($dryRun ? 'DRY-RUN' : ($verifyOnly ? 'VERIFY' : 'APPLY')) . "\n\n";

// ---------------------------------------------------------------- helpers
/**
 * Metadata satu kolom (atau NULL bila tidak ada).
 *
 * @return array{data_type:string,column_type:string,nullable:string,default:mixed,latest:bool}|null
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

/** Baris `system_settings` untuk satu key (atau NULL). */
function setting_row(mysqli $m, $key)
{
    $stmt = $m->prepare("SELECT key_value FROM system_settings WHERE key_name = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string) $row['key_value'] : NULL;
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

    // ── Kolom users ────────────────────────────────────────────────────
    $streak = column_info($m, $DB, 'users', $ctx['col_streak']);
    if ($streak === NULL)
    {
        $fail[] = "kolom `users.{$ctx['col_streak']}` belum ada";
    }
    else
    {
        if ($streak['data_type'] !== 'int' || strpos($streak['column_type'], 'unsigned') === FALSE)
        {
            $fail[] = "`users.{$ctx['col_streak']}` harus INT UNSIGNED (terdeteksi: {$streak['column_type']})";
        }
        if ($streak['nullable'] !== 'NO')
        {
            $fail[] = "`users.{$ctx['col_streak']}` harus NOT NULL (terdeteksi: {$streak['nullable']})";
        }
        if ((int) $streak['default'] !== 0)
        {
            $fail[] = "`users.{$ctx['col_streak']}` DEFAULT harus 0 (terdeteksi: "
                    . var_export($streak['default'], TRUE) . ')';
        }
    }

    $lastDate = column_info($m, $DB, 'users', $ctx['col_date']);
    if ($lastDate === NULL)
    {
        $fail[] = "kolom `users.{$ctx['col_date']}` belum ada";
    }
    else
    {
        if ($lastDate['data_type'] !== 'date')
        {
            $fail[] = "`users.{$ctx['col_date']}` harus DATE (terdeteksi: {$lastDate['column_type']})";
        }
        if ($lastDate['nullable'] !== 'YES')
        {
            $fail[] = "`users.{$ctx['col_date']}` harus NULL-able (terdeteksi: {$lastDate['nullable']})";
        }
    }

    // ── Baris system_settings + validasi nilai (tamper) ────────────────
    $values = array();
    foreach ($ctx['keys'] as $key => $unused)
    {
        $values[$key] = setting_row($m, $key);
        if ($values[$key] === NULL)
        {
            $fail[] = "baris `system_settings.{$key}` belum ada";
        }
    }

    if (isset($values['checkin_enabled']) && $values['checkin_enabled'] !== NULL
        && ! in_array($values['checkin_enabled'], array('0', '1'), TRUE))
    {
        $fail[] = "nilai `checkin_enabled` harus '0' atau '1' (terdeteksi: "
                . var_export($values['checkin_enabled'], TRUE) . ') — tamper?';
    }

    if (isset($values['checkin_streak_policy']) && $values['checkin_streak_policy'] !== NULL
        && ! in_array($values['checkin_streak_policy'], array('reset', 'continue'), TRUE))
    {
        $fail[] = "nilai `checkin_streak_policy` harus 'reset' atau 'continue' (terdeteksi: "
                . var_export($values['checkin_streak_policy'], TRUE) . ') — tamper?';
    }

    $base = NULL;
    $max  = NULL;

    if (isset($values['checkin_base_reward']) && $values['checkin_base_reward'] !== NULL)
    {
        if ( ! preg_match('/^[1-9][0-9]*$/', $values['checkin_base_reward']))
        {
            $fail[] = "nilai `checkin_base_reward` harus integer IDR positif tanpa pecahan (terdeteksi: "
                    . var_export($values['checkin_base_reward'], TRUE) . ') — tamper?';
        }
        else
        {
            $base = (int) $values['checkin_base_reward'];
        }
    }

    if (isset($values['checkin_max_reward']) && $values['checkin_max_reward'] !== NULL)
    {
        if ( ! preg_match('/^[1-9][0-9]*$/', $values['checkin_max_reward']))
        {
            $fail[] = "nilai `checkin_max_reward` harus integer IDR positif tanpa pecahan (terdeteksi: "
                    . var_export($values['checkin_max_reward'], TRUE) . ') — tamper?';
        }
        else
        {
            $max = (int) $values['checkin_max_reward'];
        }
    }

    // Invarian inti: 1 ≤ base ≤ max (model mem-fallback-kan pasangan ini bila
    // dilanggar; di tingkat DB ini adalah kondisi rusak yang harus terlihat).
    if ($base !== NULL && $max !== NULL && $base > $max)
    {
        $fail[] = "invarian `checkin_base_reward <= checkin_max_reward` dilanggar ({$base} > {$max})";
    }

    // Ambang atas administratif (di luar ini ditolak form admin) → peringatan.
    if ($base !== NULL && $base > 1000000)
    {
        $warn[] = "`checkin_base_reward` di atas ambang form admin (1.000.000): {$base}";
    }
    if ($max !== NULL && $max > 10000000)
    {
        $warn[] = "`checkin_max_reward` di atas ambang form admin (10.000.000): {$max}";
    }

    // ── Struktur pendukung ─────────────────────────────────────────────
    $r = $m->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'system_settings' AND COLUMN_NAME = 'key_value'");
    $type = $r && ($x = $r->fetch_assoc()) ? strtolower($x['DATA_TYPE']) : '';
    if ($type !== 'text')
    {
        $fail[] = "kolom system_settings.key_value bukan TEXT (terdeteksi: {$type})";
    }

    $idx = array();
    $r = $m->query("SELECT DISTINCT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = '{$DB}' AND TABLE_NAME = 'system_settings'");
    while ($x = $r->fetch_assoc()) { $idx[$x['INDEX_NAME']] = (int) $x['NON_UNIQUE']; }
    if ( ! isset($idx['uk_key_name']) || $idx['uk_key_name'] !== 0)
    {
        $fail[] = 'unique index `uk_key_name` hilang / tidak unik';
    }

    return array($fail, $warn);
}

// ---------------------------------------------------------------- pre-flight
foreach (array('users', 'system_settings') as $need)
{
    if ( ! table_exists($m, $DB, $need))
    {
        fwrite(STDERR, "[FATAL] Tabel `{$need}` tidak ditemukan di {$DB} — hentikan.\n");
        exit(1);
    }
}

if (column_info($m, $DB, 'users', $ANCHOR) === NULL)
{
    fwrite(STDERR, "[FATAL] Kolom `users.{$ANCHOR}` tidak ada (dipakai sebagai anchor AFTER) — hentikan.\n");
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
if ($cols['key_value'] !== 'text')
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

$ctx = array(
    'col_streak' => $COL_STREAK,
    'col_date'   => $COL_DATE,
    'anchor'     => $ANCHOR,
    'keys'       => $KEYS,
);

// ---------------------------------------------------------------- inspect
$curStreak = column_info($m, $DB, 'users', $COL_STREAK);
$curDate   = column_info($m, $DB, 'users', $COL_DATE);

$totalUsers = (int) $m->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
$totalSet   = (int) $m->query("SELECT COUNT(*) c FROM system_settings")->fetch_assoc()['c'];

echo "Inspeksi (users: {$totalUsers} baris, system_settings: {$totalSet} baris):\n";
echo '  kolom users.' . str_pad($COL_STREAK, 20) . ($curStreak ? 'ADA (' . $curStreak['column_type'] . ')' : 'BELUM ADA') . "\n";
echo '  kolom users.' . str_pad($COL_DATE, 20) . ($curDate ? 'ADA (' . $curDate['column_type'] . ')' : 'BELUM ADA') . "\n";

foreach (array_keys($KEYS) as $key)
{
    $stored = setting_row($m, $key);
    $state  = ($stored === NULL) ? 'BELUM ADA' : "ADA (nilai: " . ($stored === '' ? '(kosong)' : $stored) . ')';
    echo '  key ' . str_pad($key, 24) . $state . "\n";
}
echo '  unique uk_key_name    OK' . "\n";
echo '  kolom key_value       ' . strtoupper($cols['key_value']) . "\n\n";

// ---------------------------------------------------------------- verify-only
if ($verifyOnly)
{
    echo "[verify] Memeriksa kolom users + 4 kunci system_settings (read-only) ...\n";
    list($fail, $warn) = verify_target($m, $DB, $ctx);

    foreach ($warn as $w) { echo "  [WARN] {$w}\n"; }

    if ($fail)
    {
        fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
        $m->close();
        exit(2);
    }

    echo "  kolom users.{$COL_STREAK}    INT UNSIGNED NOT NULL DEFAULT 0 OK\n";
    echo "  kolom users.{$COL_DATE}      DATE NULL DEFAULT NULL OK\n";
    echo "  4 kunci system_settings      OK (enabled/policy whitelist, base ≤ max)\n";
    echo "  unique index uk_key_name     OK\n";
    $m->close();
    echo "\n[OK] Verifikasi plan/112 lulus.\n";
    exit(0);
}

// ---------------------------------------------------------------- dry-run
if ($dryRun)
{
    echo "[dry-run] Rencana perubahan (TIDAK ada yang ditulis):\n";

    if ( ! $curStreak)
    {
        echo "  1a. ALTER TABLE `users` ADD COLUMN `{$COL_STREAK}` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `{$ANCHOR}`\n";
    }
    else
    {
        echo "  1a. (kolom `users.{$COL_STREAK}` sudah ada — dilewati)\n";
    }

    if ( ! $curDate)
    {
        echo "  1b. ALTER TABLE `users` ADD COLUMN `{$COL_DATE}` DATE NULL DEFAULT NULL AFTER `{$COL_STREAK}`\n";
    }
    else
    {
        echo "  1b. (kolom `users.{$COL_DATE}` sudah ada — dilewati)\n";
    }

    $missing = array();
    foreach ($KEYS as $key => $value)
    {
        if (setting_row($m, $key) === NULL) { $missing[] = "('{$key}', '{$value}')"; }
    }

    if ($missing)
    {
        echo "  2.  INSERT IGNORE INTO system_settings (key_name, key_value) VALUES\n        " . implode(",\n        ", $missing) . ";\n";
    }
    else
    {
        echo "  2.  (4 kunci sudah ada — seed dilewati, nilai live tidak diubah)\n";
    }

    echo "  3.  Backfill: TIDAK ADA (baris users lama memakai DEFAULT 0 / NULL).\n";
    echo "  4.  Verifikasi: kolom bertipe benar + 4 baris ada + nilai tidak rusak.\n";
    echo "\nJalankan ulang dengan --apply untuk menerapkan.\n";
    $m->close();
    exit(0);
}

// ---------------------------------------------------------------- apply
echo "[apply] 1/3 DDL kolom `users` ...\n";

if ( ! $curStreak)
{
    $m->query("ALTER TABLE `users` ADD COLUMN `{$COL_STREAK}` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `{$ANCHOR}`");
    echo "  users.{$COL_STREAK}    ditambahkan\n";
}
else
{
    echo "  users.{$COL_STREAK}    sudah ada (dibiarkan)\n";
}

if ( ! $curDate)
{
    $m->query("ALTER TABLE `users` ADD COLUMN `{$COL_DATE}` DATE NULL DEFAULT NULL AFTER `{$COL_STREAK}`");
    echo "  users.{$COL_DATE}  ditambahkan\n";
}
else
{
    echo "  users.{$COL_DATE}  sudah ada (dibiarkan)\n";
}

echo "[apply] 2/3 Seed 4 kunci `system_settings` (INSERT IGNORE) ...\n";
$stmt = $m->prepare("INSERT IGNORE INTO system_settings (key_name, key_value) VALUES (?, ?)");
foreach ($KEYS as $key => $value)
{
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    echo '  ' . str_pad($key, 24) . ($stmt->affected_rows > 0 ? 'ditambahkan' : 'sudah ada (dibiarkan)') . "\n";
}
$stmt->close();

echo "[apply] 3/3 Verifikasi ...\n";
list($fail, $warn) = verify_target($m, $DB, $ctx);

foreach ($warn as $w) { echo "  [WARN] {$w}\n"; }

echo '  kolom users.' . str_pad($COL_STREAK, 20) . (column_info($m, $DB, 'users', $COL_STREAK) ? 'OK' : 'HILANG') . "\n";
echo '  kolom users.' . str_pad($COL_DATE, 20) . (column_info($m, $DB, 'users', $COL_DATE) ? 'OK' : 'HILANG') . "\n";
echo '  nilai setelan          ' . ($fail ? 'GAGAL' : 'OK') . "\n";

$m->close();

if ($fail)
{
    fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
    exit(2);
}

echo "\n[OK] Migrasi plan/112 selesai & terverifikasi.\n";
exit(0);
