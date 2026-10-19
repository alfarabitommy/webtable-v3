<?php
/**
 * Synapse (db_webtable) — Plan 121 Lucky Spin Wheel Migration CLI
 *
 * One-off operator tool: menyediakan state LUCKY SPIN WHEEL —
 *   1. SATU KOLOM `users`   : `spin_tickets` INT UNSIGNED NOT NULL DEFAULT 0
 *                             (counter tiket; BUKAN uang — tidak pernah masuk
 *                             `wallet_ledger`, tidak dapat ditarik);
 *   2. DUA TABEL BARU       : `spin_ticket_grants` (UNIQUE `downline_id` =
 *                             guard "1 tiket per downline seumur hidup") dan
 *                             `spin_results` (jejak audit spin + snapshot bobot);
 *   3. DUA KUNCI `system_settings`: `spin_wheel_enabled` + `spin_wheel_segments`.
 *
 *   1. PRE-FLIGHT : tabel `users`, `user_rentals`, `system_settings` ada; kolom
 *                   anchor `users.checkin_last_date` ada (dipakai AFTER);
 *                   `key_value` bertipe TEXT; unique index `uk_key_name` ada;
 *                   fallback config + helper choke-point dapat dimuat.
 *   2. INSPECT    : kolom/tabel/kunci sudah ada atau belum.
 *   3. DDL        : ADD COLUMN dijaga information_schema (idempoten; MySQL 8
 *                   tidak punya ADD COLUMN IF NOT EXISTS) + CREATE TABLE
 *                   IF NOT EXISTS × 2. TANPA backfill (keputusan owner D4).
 *   4. SEED       : INSERT IGNORE 2 kunci (nilai live TIDAK PERNAH ditimpa).
 *   5. VERIFY     : kolom bertipe benar; kedua tabel + nama index + FK tepat;
 *                   kedua kunci ada & segmen parseable/valid (tamper → exit 2);
 *                   DRIFT: `users.spin_tickets` = Σgrant − Σspin (SP6).
 *
 *   --dry-run   (default) inspeksi + rencana, TIDAK menulis apa pun
 *   --apply     DDL + seed 2 kunci + verifikasi
 *   --verify    hanya verifikasi (read-only); exit 2 bila tidak sesuai
 *   --help|-h   usage
 *
 * Safety:
 * - Idempotent: re-run = no-op ("sudah ada (dibiarkan)"), pola seed kanonik
 *   `INSERT IGNORE` di database.sql / database_seed.sql.
 * - Ambang batas & default TIDAK diduplikasi: script ini meng-include
 *   application/config/spin_wheel.php + application/helpers/spin_wheel_helper.php
 *   (setelah BASEPATH didefinisikan) sehingga CLI & aplikasi memakai satu sumber.
 * - Kredensial dibaca dari application/config/database.php memakai semantik CI3
 *   ($active_group → 'default' → grup pertama), pola scripts/migrate_106/112.
 *
 * Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal.
 */

define('BASEPATH', 'cli-migrate-121-runner');
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
    echo "Usage: php scripts/migrate_121_lucky_spin.php [--dry-run|--apply|--verify] [--help]\n"
       . "  --dry-run  (default) inspeksi kolom/tabel/key + cetak rencana, tanpa menulis\n"
       . "  --apply    tambah kolom `users.spin_tickets`, buat 2 tabel, seed 2 kunci, lalu verifikasi\n"
       . "  --verify   hanya verifikasi (read-only); exit 2 bila tidak sesuai\n"
       . "  Catatan: TANPA backfill — tiket mulai dihitung sejak rilis (keputusan D4).\n";
    exit(0);
}

// ---------------------------------------------------------------- config app
// Satu sumber default & ambang batas (dipakai juga Spin_model + admin UI).
$FALLBACK = require $ROOT . '/application/config/spin_wheel.php';

if ( ! is_array($FALLBACK) || ! array_key_exists('spin_wheel_enabled', $FALLBACK))
{
    fwrite(STDERR, "[FATAL] application/config/spin_wheel.php tidak mengembalikan peta setelan.\n");
    exit(1);
}

require $ROOT . '/application/helpers/spin_wheel_helper.php';

if ( ! function_exists('spin_wheel_segments_parse'))
{
    fwrite(STDERR, "[FATAL] Helper spin_wheel tidak dapat dimuat.\n");
    exit(1);
}

$LIM = spin_wheel_limits();

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
$host = ($db['hostname'] === 'localhost') ? '127.0.0.1' : $db['hostname']; // TCP (no unix socket in this env)

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
$COL        = 'spin_tickets';
$ANCHOR     = 'checkin_last_date';       // AFTER klausa (pre-flight memastikan ada)
$T_GRANTS   = 'spin_ticket_grants';
$T_RESULTS  = 'spin_results';

$KEYS = array(
    'spin_wheel_enabled'  => ((int) $FALLBACK['spin_wheel_enabled'] === 1) ? '1' : '0',
    'spin_wheel_segments' => spin_wheel_segments_encode($FALLBACK['spin_wheel_segments']),
);

// INDEX yang WAJIB ada (nama kanonik database.sql).
$INDEXES = array(
    $T_GRANTS  => array('PRIMARY', 'uk_spin_grant_downline', 'idx_spin_grant_upline'),
    $T_RESULTS => array('PRIMARY', 'uk_spin_tx', 'idx_spin_user_created'),
);

// FK yang WAJIB ada.
$FKS = array(
    $T_GRANTS  => array('fk_spin_grant_upline', 'fk_spin_grant_downline', 'fk_spin_grant_rental'),
    $T_RESULTS => array('fk_spin_result_user'),
);

echo "== Plan 121 — Lucky Spin Wheel: 1 kolom users + 2 tabel + 2 kunci system_settings ==\n";
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

/** Nama index yang ada pada satu tabel (map nama → NON_UNIQUE). */
function index_map(mysqli $m, $DB, $table)
{
    $out  = array();
    $stmt = $m->prepare(
        "SELECT DISTINCT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
    );
    $stmt->bind_param('ss', $DB, $table);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc())
    {
        $out[(string) $row['INDEX_NAME']] = (int) $row['NON_UNIQUE'];
    }
    $stmt->close();

    return $out;
}

/** Nama constraint FK pada satu tabel. */
function fk_names(mysqli $m, $DB, $table)
{
    $out  = array();
    $stmt = $m->prepare(
        "SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $stmt->bind_param('ss', $DB, $table);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc())
    {
        $out[] = (string) $row['CONSTRAINT_NAME'];
    }
    $stmt->close();

    return $out;
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

/** Jumlah user yang counter tiketnya menyimpang dari event (SP6). */
function ticket_drift_count(mysqli $m)
{
    $sql = "SELECT COUNT(*) c FROM users u
             WHERE u.spin_tickets <> (
                   (SELECT COUNT(*) FROM spin_ticket_grants g WHERE g.upline_id = u.id)
                 - (SELECT COUNT(*) FROM spin_results s WHERE s.user_id = u.id))";
    $row = $m->query($sql)->fetch_assoc();

    return (int) $row['c'];
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
    $col = column_info($m, $DB, 'users', $ctx['col']);
    if ($col === NULL)
    {
        $fail[] = "kolom `users.{$ctx['col']}` belum ada";
    }
    else
    {
        if ($col['data_type'] !== 'int' || strpos($col['column_type'], 'unsigned') === FALSE)
        {
            $fail[] = "kolom `users.{$ctx['col']}` harus INT UNSIGNED (terdeteksi: {$col['column_type']})";
        }
        if ($col['nullable'] !== 'NO')
        {
            $fail[] = "kolom `users.{$ctx['col']}` harus NOT NULL (terdeteksi: NULLable)";
        }
        if ($col['default'] === NULL || (string) $col['default'] !== '0')
        {
            $fail[] = "kolom `users.{$ctx['col']}` harus DEFAULT 0 (terdeteksi: "
                . var_export($col['default'], TRUE) . ')';
        }
    }

    // ── Tabel + index + FK ─────────────────────────────────────────────
    foreach (array($ctx['t_grants'], $ctx['t_results']) as $table)
    {
        if ( ! table_exists($m, $DB, $table))
        {
            $fail[] = "tabel `{$table}` belum ada";
            continue;
        }

        $idx = index_map($m, $DB, $table);
        foreach ($ctx['indexes'][$table] as $name)
        {
            if ( ! array_key_exists($name, $idx))
            {
                $fail[] = "index `{$name}` tidak ada di `{$table}`";
            }
        }

        // Index UNIQUE wajib benar-benar unik (guard idempotensi SP2/SP9).
        if ($table === $ctx['t_grants'] && isset($idx['uk_spin_grant_downline']) && $idx['uk_spin_grant_downline'] !== 0)
        {
            $fail[] = "index `uk_spin_grant_downline` harus UNIQUE (terdeteksi: non-unique)";
        }

        $fks = fk_names($m, $DB, $table);
        foreach ($ctx['fks'][$table] as $name)
        {
            if ( ! in_array($name, $fks, TRUE))
            {
                $fail[] = "foreign key `{$name}` tidak ada di `{$table}`";
            }
        }
    }

    // ── Kunci system_settings ──────────────────────────────────────────
    $enabled = setting_row($m, 'spin_wheel_enabled');
    if ($enabled === NULL)
    {
        $fail[] = 'key `spin_wheel_enabled` belum ada';
    }
    elseif ($enabled !== '0' && $enabled !== '1')
    {
        $fail[] = "key `spin_wheel_enabled` bukan '0'/'1' (terdeteksi: {$enabled})";
    }

    $raw = setting_row($m, 'spin_wheel_segments');
    if ($raw === NULL)
    {
        $fail[] = 'key `spin_wheel_segments` belum ada';
    }
    else
    {
        $segments = spin_wheel_segments_parse($raw);
        if ($segments === NULL)
        {
            $fail[] = 'key `spin_wheel_segments` tidak dapat diparse sebagai [[nominal,bobot], …]';
        }
        elseif ( ! spin_wheel_segments_valid($segments))
        {
            $fail[] = 'key `spin_wheel_segments` melanggar ambang (2..'
                . $ctx['lim']['max_segments'] . ' segmen, nominal 1..'
                . $ctx['lim']['max_amount'] . ', bobot 1..'
                . $ctx['lim']['max_weight'] . ')';
        }
        else
        {
            $warn[] = 'segmen aktif: ' . count($segments) . ' segmen, total bobot '
                . spin_wheel_total_weight($segments) . ', expected value Rp '
                . number_format(spin_wheel_expected_value($segments), 0, ',', '.') . '/tiket';
        }
    }

    // ── Drift counter vs event (SP6) ───────────────────────────────────
    if (table_exists($m, $DB, $ctx['t_grants']) && table_exists($m, $DB, $ctx['t_results']))
    {
        $drift = ticket_drift_count($m);
        if ($drift > 0)
        {
            $fail[] = "DRIFT: {$drift} baris `users.spin_tickets` tidak sama dengan Σgrant − Σspin";
        }
    }

    return array($fail, $warn);
}

// ---------------------------------------------------------------- pre-flight
foreach (array('users', 'user_rentals', 'system_settings') as $need)
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

$idxSettings = index_map($m, $DB, 'system_settings');
if ( ! isset($idxSettings['uk_key_name']) || $idxSettings['uk_key_name'] !== 0)
{
    fwrite(STDERR, "[FATAL] Unique index `uk_key_name` tidak ada / tidak unik — hentikan.\n");
    exit(1);
}

$ctx = array(
    'col'       => $COL,
    'anchor'    => $ANCHOR,
    't_grants'  => $T_GRANTS,
    't_results' => $T_RESULTS,
    'indexes'   => $INDEXES,
    'fks'       => $FKS,
    'lim'       => $LIM,
);

// ---------------------------------------------------------------- inspect
$curCol   = column_info($m, $DB, 'users', $COL);
$hasGrant = table_exists($m, $DB, $T_GRANTS);
$hasRes   = table_exists($m, $DB, $T_RESULTS);

$totalUsers = (int) $m->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
$totalSet   = (int) $m->query("SELECT COUNT(*) c FROM system_settings")->fetch_assoc()['c'];

echo "Inspeksi (users: {$totalUsers} baris, system_settings: {$totalSet} baris):\n";
echo '  kolom users.' . str_pad($COL, 22) . ($curCol ? 'ADA (' . $curCol['column_type'] . ')' : 'BELUM ADA') . "\n";
echo '  tabel ' . str_pad($T_GRANTS, 28) . ($hasGrant ? 'ADA' : 'BELUM ADA') . "\n";
echo '  tabel ' . str_pad($T_RESULTS, 28) . ($hasRes ? 'ADA' : 'BELUM ADA') . "\n";

foreach (array_keys($KEYS) as $key)
{
    $stored = setting_row($m, $key);
    $state  = ($stored === NULL) ? 'BELUM ADA' : "ADA (nilai: " . ($stored === '' ? '(kosong)' : $stored) . ')';
    echo '  key ' . str_pad($key, 24) . $state . "\n";
}
echo '  unique uk_key_name      OK' . "\n";
echo '  kolom key_value         ' . strtoupper($cols['key_value']) . "\n\n";

// ---------------------------------------------------------------- verify-only
if ($verifyOnly)
{
    echo "[verify] Memeriksa kolom + 2 tabel + 2 kunci + drift (read-only) ...\n";
    list($fail, $warn) = verify_target($m, $DB, $ctx);

    foreach ($warn as $w) { echo "  [INFO] {$w}\n"; }

    if ($fail)
    {
        fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
        $m->close();
        exit(2);
    }

    echo "  kolom users.{$COL}    INT UNSIGNED NOT NULL DEFAULT 0 OK\n";
    echo "  tabel {$T_GRANTS}     OK (uk_spin_grant_downline UNIQUE + 3 FK)\n";
    echo "  tabel {$T_RESULTS}    OK (uk_spin_tx + idx_spin_user_created + 1 FK)\n";
    echo "  2 kunci system_settings    OK (segmen parseable & dalam ambang)\n";
    echo "  drift counter tiket        OK (spin_tickets = Σgrant − Σspin)\n";
    $m->close();
    echo "\n[OK] Verifikasi plan/121 lulus.\n";
    exit(0);
}

// ---------------------------------------------------------------- DDL teks
$SQL_GRANTS = "CREATE TABLE IF NOT EXISTS `{$T_GRANTS}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `upline_id` BIGINT UNSIGNED NOT NULL,
  `downline_id` BIGINT UNSIGNED NOT NULL,
  `rental_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spin_grant_downline` (`downline_id`),
  INDEX `idx_spin_grant_upline` (`upline_id`, `created_at`),
  CONSTRAINT `fk_spin_grant_upline` FOREIGN KEY (`upline_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_spin_grant_downline` FOREIGN KEY (`downline_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_spin_grant_rental` FOREIGN KEY (`rental_id`) REFERENCES `user_rentals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$SQL_RESULTS = "CREATE TABLE IF NOT EXISTS `{$T_RESULTS}` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `slot_index` SMALLINT UNSIGNED NOT NULL,
  `prize_amount` DECIMAL(15,2) NOT NULL,
  `weight_snapshot` INT UNSIGNED NOT NULL,
  `total_weight` INT UNSIGNED NOT NULL,
  `transaction_id` VARCHAR(50) NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spin_tx` (`transaction_id`),
  INDEX `idx_spin_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_spin_result_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// ---------------------------------------------------------------- dry-run
if ($dryRun)
{
    echo "[dry-run] Rencana perubahan (TIDAK ada yang ditulis):\n";

    if ( ! $curCol)
    {
        echo "  1.  ALTER TABLE `users` ADD COLUMN `{$COL}` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `{$ANCHOR}`\n";
    }
    else
    {
        echo "  1.  (kolom `users.{$COL}` sudah ada — dilewati)\n";
    }

    echo '  2a. ' . ($hasGrant ? "({$T_GRANTS} sudah ada — dilewati)" : "CREATE TABLE IF NOT EXISTS `{$T_GRANTS}` (UNIQUE uk_spin_grant_downline + 3 FK)") . "\n";
    echo '  2b. ' . ($hasRes ? "({$T_RESULTS} sudah ada — dilewati)" : "CREATE TABLE IF NOT EXISTS `{$T_RESULTS}` (uk_spin_tx + idx_spin_user_created + 1 FK)") . "\n";

    $missing = array();
    foreach ($KEYS as $key => $value)
    {
        if (setting_row($m, $key) === NULL) { $missing[] = "('{$key}', '{$value}')"; }
    }

    if ($missing)
    {
        echo "  3.  INSERT IGNORE INTO system_settings (key_name, key_value) VALUES\n        " . implode(",\n        ", $missing) . ";\n";
    }
    else
    {
        echo "  3.  (2 kunci sudah ada — seed dilewati, nilai live tidak diubah)\n";
    }

    echo "  4.  Backfill: TIDAK ADA (keputusan D4 — tiket mulai dihitung sejak rilis).\n";
    echo "  5.  Verifikasi: tipe kolom, 2 tabel + index/FK, 2 kunci valid, drift counter = 0.\n";
    echo "\nJalankan ulang dengan --apply untuk menerapkan.\n";
    $m->close();
    exit(0);
}

// ---------------------------------------------------------------- apply
echo "[apply] 1/3 DDL kolom `users` + 2 tabel ...\n";

if ( ! $curCol)
{
    $m->query("ALTER TABLE `users` ADD COLUMN `{$COL}` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `{$ANCHOR}`");
    echo "  users.{$COL}    ditambahkan\n";
}
else
{
    echo "  users.{$COL}    sudah ada (dibiarkan)\n";
}

$m->query($SQL_GRANTS);
echo '  ' . str_pad($T_GRANTS, 22) . ($hasGrant ? 'sudah ada (dibiarkan)' : 'dibuat') . "\n";

$m->query($SQL_RESULTS);
echo '  ' . str_pad($T_RESULTS, 22) . ($hasRes ? 'sudah ada (dibiarkan)' : 'dibuat') . "\n";

echo "[apply] 2/3 Seed 2 kunci `system_settings` (INSERT IGNORE) ...\n";
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

foreach ($warn as $w) { echo "  [INFO] {$w}\n"; }

echo '  kolom users.' . str_pad($COL, 20) . (column_info($m, $DB, 'users', $COL) ? 'OK' : 'HILANG') . "\n";
echo '  tabel ' . str_pad($T_GRANTS, 22) . (table_exists($m, $DB, $T_GRANTS) ? 'OK' : 'HILANG') . "\n";
echo '  tabel ' . str_pad($T_RESULTS, 22) . (table_exists($m, $DB, $T_RESULTS) ? 'OK' : 'HILANG') . "\n";
echo '  nilai setelan          ' . ($fail ? 'GAGAL' : 'OK') . "\n";

$m->close();

if ($fail)
{
    fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
    exit(2);
}

echo "\n[OK] Migrasi plan/121 selesai & terverifikasi.\n";
exit(0);
