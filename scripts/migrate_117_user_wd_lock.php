<?php
/**
 * Synapse (db_webtable) — Plan 117 Per-User Withdrawal Lock Migration CLI
 *
 * One-off operator tool: menyediakan state KUNCI PENARIKAN PER-USER
 * ("Withdrawal Lock") yang dipakai oleh gerbang penarikan member dan panel
 * admin:
 *
 *   1. SATU KOLOM `users.is_wd_locked` TINYINT(1) NOT NULL DEFAULT 0
 *      (1 = pengajuan penarikan BARU diblokir; baris lama otomatis 0 —
 *      TANPA backfill);
 *   2. SATU KOLOM `users.wd_lock_reason` VARCHAR(255) NULL DEFAULT NULL
 *      (alasan INTERNAL admin; TIDAK PERNAH dikirim ke permukaan member).
 *
 *   1. PRE-FLIGHT : tabel `users` ada; kolom anchor `users.checkin_last_date`
 *                   ada (dipakai klausa AFTER); koneksi mysqli TCP siap.
 *   2. INSPECT    : kedua kolom sudah ada / belum; hitung jumlah user,
 *                   jumlah user terkunci, orphan reason
 *                   (`is_wd_locked = 0 AND wd_lock_reason IS NOT NULL`), dan
 *                   user terkunci tanpa alasan.
 *   3. DDL        : 2 × ADD COLUMN dijaga information_schema (idempoten;
 *                   MySQL 8 tidak punya ADD COLUMN IF NOT EXISTS).
 *                   TANPA backfill.
 *   4. NORMALIZE  : tegakkan invarian W4 —
 *                   `UPDATE users SET wd_lock_reason = NULL
 *                     WHERE is_wd_locked = 0 AND wd_lock_reason IS NOT NULL`.
 *   5. GUARD      : tipe/nullability/default kedua kolom + orphan reason == 0
 *                   → pelanggaran = tamper → exit 2.
 *                   `is_wd_locked = 1` tanpa alasan hanya [WARN] (sah: admin
 *                   bisa mengunci dengan alasan yang diisi belakangan).
 *   6. VERIFY     : ulangi 5 secara read-only.
 *
 *   --dry-run   (default) inspeksi + rencana, TIDAK menulis apa pun
 *   --apply     DDL + normalisasi + verifikasi
 *   --verify    hanya verifikasi (read-only); exit 2 bila tidak sesuai
 *   --help|-h   usage
 *
 * Safety:
 * - Idempotent: re-run = no-op ("sudah ada (dibiarkan)" / "0 baris").
 * - TIDAK PERNAH menyentuh `withdrawals`, `wallet_ledger`, `bank_accounts`,
 *   `user_rentals`, maupun baris user mana pun selain kolom di atas —
 *   kunci penarikan murni kebijakan (keputusan D1/D2 plan/117, imutabilitas Z1).
 * - Kredensial dibaca dari application/config/database.php memakai semantik CI3
 *   ($active_group → 'default' → grup pertama), pola scripts/migrate_106/112/114.
 *   Override lokal: DB_HOSTNAME/DB_USERNAME/DB_PASSWORD/DB_DATABASE.
 *
 * ⚠️ URUTAN RILIS: jalankan migrasi ini SEBELUM deploy kode plan/117 —
 *    `Admin_model::get_users()` (kolom `is_wd_locked`/`wd_lock_reason`),
 *    `Wallet_model::is_withdrawal_locked()` (gate TX penarikan), dan
 *    `Admin::toggle_wd_lock()` membaca kolom tersebut.
 *
 * Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal.
 */

define('BASEPATH', 'cli-migrate-117-runner');
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
    echo "Usage: php scripts/migrate_117_user_wd_lock.php [--dry-run|--apply|--verify] [--help]\n"
       . "  --dry-run  (default) inspeksi kolom + cetak rencana, tanpa menulis\n"
       . "  --apply    tambah kolom `users.is_wd_locked` + `users.wd_lock_reason`,\n"
       . "             normalisasi invarian W4, lalu verifikasi\n"
       . "  --verify   hanya verifikasi (read-only); exit 2 bila tidak sesuai\n"
       . "  Catatan: TANPA backfill — baris users lama memakai DEFAULT 0 / NULL.\n"
       . "           Tool tidak pernah menulis `withdrawals`/`wallet_ledger`.\n";
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
$TABLE  = 'users';
$COL_L  = 'is_wd_locked';     // TINYINT(1) NOT NULL DEFAULT 0
$COL_R  = 'wd_lock_reason';   // VARCHAR(255) NULL DEFAULT NULL
$ANCHOR = 'checkin_last_date'; // AFTER klausa (pre-flight memastikan ada)

echo "== Plan 117 — Per-User Withdrawal Lock: 2 kolom `users` ==\n";
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

/**
 * Statistik operator (hanya dipanggil bila kedua kolom sudah ada).
 *
 * @return array{total:int,locked:int,orphan_reason:int,locked_without_reason:int}
 */
function users_lock_stats(mysqli $m)
{
    return array(
        'total' => count_scalar($m, "SELECT COUNT(*) c FROM users"),
        'locked' => count_scalar(
            $m, "SELECT COUNT(*) c FROM users WHERE `is_wd_locked` = 1"
        ),
        'orphan_reason' => count_scalar(
            $m, "SELECT COUNT(*) c FROM users
                  WHERE `is_wd_locked` = 0 AND `wd_lock_reason` IS NOT NULL"
        ),
        'locked_without_reason' => count_scalar(
            $m, "SELECT COUNT(*) c FROM users
                  WHERE `is_wd_locked` = 1
                    AND (`wd_lock_reason` IS NULL OR `wd_lock_reason` = '')"
        ),
    );
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

    // ── users.is_wd_locked ─────────────────────────────────────────────
    $colL = column_info($m, $DB, $ctx['table'], $ctx['col_l']);
    if ($colL === NULL)
    {
        $fail[] = "kolom `{$ctx['table']}.{$ctx['col_l']}` belum ada";

        return array($fail, $warn);   // tanpa kolom, pemeriksaan statistik mustahil
    }

    if ($colL['data_type'] !== 'tinyint')
    {
        $fail[] = "`{$ctx['table']}.{$ctx['col_l']}` harus TINYINT(1) (terdeteksi: {$colL['column_type']})";
    }
    if ($colL['nullable'] !== 'NO')
    {
        $fail[] = "`{$ctx['table']}.{$ctx['col_l']}` harus NOT NULL (terdeteksi: {$colL['nullable']})";
    }
    if ((int) $colL['default'] !== 0)
    {
        $fail[] = "`{$ctx['table']}.{$ctx['col_l']}` DEFAULT harus 0 (terdeteksi: "
                . var_export($colL['default'], TRUE) . ')';
    }

    // ── users.wd_lock_reason ───────────────────────────────────────────
    $colR = column_info($m, $DB, $ctx['table'], $ctx['col_r']);
    if ($colR === NULL)
    {
        $fail[] = "kolom `{$ctx['table']}.{$ctx['col_r']}` belum ada";

        return array($fail, $warn);
    }

    if ($colR['data_type'] !== 'varchar' || $colR['column_type'] !== 'varchar(255)')
    {
        $fail[] = "`{$ctx['table']}.{$ctx['col_r']}` harus VARCHAR(255) (terdeteksi: {$colR['column_type']})";
    }
    if ($colR['nullable'] !== 'YES')
    {
        $fail[] = "`{$ctx['table']}.{$ctx['col_r']}` harus NULLABLE (terdeteksi: {$colR['nullable']})";
    }
    // MariaDB melaporkan `DEFAULT NULL` sebagai STRING 'NULL' di
    // information_schema (MySQL mengembalikan NULL PHP) → terima keduanya.
    $def_r    = $colR['default'];
    $def_r_ok = ($def_r === NULL)
             || (is_string($def_r) && strtoupper(trim($def_r)) === 'NULL');
    if ( ! $def_r_ok)
    {
        $fail[] = "`{$ctx['table']}.{$ctx['col_r']}` DEFAULT harus NULL (terdeteksi: "
                . var_export($def_r, TRUE) . ')';
    }

    // ── Invarian W4: alasan tidak boleh tertinggal saat kunci terbuka ──
    $stats = users_lock_stats($m);

    if ($stats['orphan_reason'] > 0)
    {
        $fail[] = "invarian W4 dilanggar: {$stats['orphan_reason']} baris `users` punya "
                . '`wd_lock_reason` terisi padahal `is_wd_locked` = 0 (jalankan --apply untuk normalisasi)';
    }

    if ($stats['locked_without_reason'] > 0)
    {
        $warn[] = "{$stats['locked_without_reason']} user terkunci (`is_wd_locked` = 1) TANPA alasan — "
                . 'sah secara data (alasan bersifat opsional-teknis), tidak menutup gerbang penarikan';
    }

    return array($fail, $warn);
}

// ---------------------------------------------------------------- pre-flight
if ( ! table_exists($m, $DB, $TABLE))
{
    fwrite(STDERR, "[FATAL] Tabel `{$TABLE}` tidak ditemukan di {$DB} — hentikan.\n");
    exit(1);
}

if (column_info($m, $DB, $TABLE, $ANCHOR) === NULL)
{
    fwrite(STDERR, "[FATAL] Kolom `{$TABLE}.{$ANCHOR}` tidak ada (dipakai sebagai anchor AFTER) — hentikan.\n");
    exit(1);
}

$ctx = array(
    'table' => $TABLE,
    'col_l' => $COL_L,
    'col_r' => $COL_R,
    'anchor' => $ANCHOR,
);

// ---------------------------------------------------------------- inspect
$curL  = column_info($m, $DB, $TABLE, $COL_L);
$curR  = column_info($m, $DB, $TABLE, $COL_R);
$stats = ($curL && $curR) ? users_lock_stats($m) : NULL;
$total = count_scalar($m, "SELECT COUNT(*) c FROM `{$TABLE}`");

echo "Inspeksi (users: {$total} baris):\n";
echo '  kolom ' . str_pad($TABLE . '.' . $COL_L, 40) . ($curL ? 'ADA (' . $curL['column_type'] . ')' : 'BELUM ADA') . "\n";
echo '  kolom ' . str_pad($TABLE . '.' . $COL_R, 40) . ($curR ? 'ADA (' . $curR['column_type'] . ')' : 'BELUM ADA') . "\n";

if ($stats)
{
    echo '  user terkunci (is_wd_locked = 1) ' . str_pad('', 8) . $stats['locked'] . " baris\n";
    echo '  orphan reason (W4) ' . str_pad('', 21) . $stats['orphan_reason'] . " baris\n";
    echo '  terkunci tanpa alasan ' . str_pad('', 17) . $stats['locked_without_reason'] . " baris\n";
}
else
{
    echo "  statistik kunci " . str_pad('', 25) . "(kolom belum lengkap)\n";
}

echo '  anchor ' . str_pad($TABLE . '.' . $ANCHOR, 41) . "ADA\n\n";

// ---------------------------------------------------------------- verify-only
if ($verifyOnly)
{
    echo "[verify] Memeriksa 2 kolom kunci penarikan + invarian W4 (read-only) ...\n";
    list($fail, $warn) = verify_target($m, $DB, $ctx);

    foreach ($warn as $w) { echo "  [WARN] {$w}\n"; }

    if ($fail)
    {
        fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
        $m->close();
        exit(2);
    }

    $after = users_lock_stats($m);
    echo "  kolom {$TABLE}.{$COL_L}   " . str_pad('', 12) . "TINYINT(1) NOT NULL DEFAULT 0 OK\n";
    echo "  kolom {$TABLE}.{$COL_R}   " . str_pad('', 12) . "VARCHAR(255) NULL DEFAULT NULL OK\n";
    echo "  invarian W4 (reason NULL saat terbuka)  OK ({$after['locked']} user terkunci, "
       . "{$after['orphan_reason']} orphan)\n";
    $m->close();
    echo "\n[OK] Verifikasi plan/117 lulus.\n";
    exit(0);
}

// ---------------------------------------------------------------- dry-run
if ($dryRun)
{
    echo "[dry-run] Rencana perubahan (TIDAK ada yang ditulis):\n";

    if ( ! $curL || ! $curR)
    {
        $parts = array();
        if ( ! $curL) { $parts[] = "ADD COLUMN `{$COL_L}` TINYINT(1) NOT NULL DEFAULT 0 AFTER `{$ANCHOR}`"; }
        if ( ! $curR) { $parts[] = "ADD COLUMN `{$COL_R}` VARCHAR(255) NULL DEFAULT NULL AFTER `{$COL_L}`"; }
        echo "  1. ALTER TABLE `{$TABLE}`\n       " . implode(",\n       ", $parts) . "\n";
        echo "     (TANPA backfill — seluruh baris lama otomatis 0 = terbuka)\n";
    }
    else
    {
        echo "  1. (kedua kolom kunci penarikan sudah ada — dilewati)\n";
    }

    $orphan = $stats ? $stats['orphan_reason'] : 0;
    echo "  2. UPDATE `{$TABLE}` SET `{$COL_R}` = NULL WHERE `{$COL_L}` = 0 AND `{$COL_R}` IS NOT NULL;\n"
       . "     (harapan saat ini: {$orphan} baris)\n";
    echo "  3. Verifikasi: tipe/nullability/default 2 kolom + invarian W4 (orphan reason = 0).\n";
    echo "\nJalankan ulang dengan --apply untuk menerapkan.\n";
    $m->close();
    exit(0);
}

// ---------------------------------------------------------------- apply
try {
    echo "[apply] 1/3 DDL kolom `{$TABLE}.{$COL_L}` + `{$TABLE}.{$COL_R}` ...\n";

    if ( ! $curL)
    {
        $m->query(
            "ALTER TABLE `{$TABLE}` ADD COLUMN `{$COL_L}` TINYINT(1) NOT NULL DEFAULT 0 AFTER `{$ANCHOR}`"
        );
        echo "  {$TABLE}.{$COL_L}    ditambahkan (TANPA backfill — baris lama = 0)\n";
    }
    else
    {
        echo "  {$TABLE}.{$COL_L}    sudah ada (dibiarkan)\n";
    }

    // `AFTER` kolom pertama agar posisi fisik = urutan `database.sql`
    // (dan aman bila tool dijalankan pada DB yang belum punya kolom ini).
    if ( ! $curR)
    {
        $m->query(
            "ALTER TABLE `{$TABLE}` ADD COLUMN `{$COL_R}` VARCHAR(255) NULL DEFAULT NULL AFTER `{$COL_L}`"
        );
        echo "  {$TABLE}.{$COL_R}  ditambahkan (TANPA backfill — baris lama = NULL)\n";
    }
    else
    {
        echo "  {$TABLE}.{$COL_R}  sudah ada (dibiarkan)\n";
    }

    echo "[apply] 2/3 Normalisasi invarian W4 (`{$COL_R}` = NULL saat `{$COL_L}` = 0) ...\n";
    $m->query(
        "UPDATE `{$TABLE}` SET `{$COL_R}` = NULL
          WHERE `{$COL_L}` = 0 AND `{$COL_R}` IS NOT NULL"
    );
    echo '  ' . $m->affected_rows . " baris dinormalkan\n";

    echo "[apply] 3/3 Verifikasi ...\n";
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

$after = users_lock_stats($m);
echo "\n[OK] Plan 117 siap: kolom `{$TABLE}.{$COL_L}` + `{$TABLE}.{$COL_R}` ada "
   . "({$after['locked']} user terkunci, {$after['orphan_reason']} orphan reason).\n";

$m->close();
exit(0);
