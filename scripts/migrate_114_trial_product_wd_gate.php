<?php
/**
 * Synapse (db_webtable) — Plan 114 Trial Product + Withdrawal Gate Migration CLI
 *
 * One-off operator tool: menyediakan state PRODUK TRIAL ("GPU Magang") yang
 * dipakai oleh (a) jalur bebas checkout `Rental_model::checkout_rental()` dan
 * (b) gerbang penarikan anti free-rider `Rental_model::has_paid_rental()`:
 *
 *   1. SATU KOLOM `gpu_products.is_trial` TINYINT(1) NOT NULL DEFAULT 0
 *      (penanda produk trial; baris lama otomatis 0 — TANPA backfill);
 *   2. SATU BARIS `gpu_products` produk trial, DIKUNCI OLEH `name`
 *      ('GPU Magang (Trial)') — BUKAN oleh `id`, karena id live ≠ id seed
 *      (preseden plan/104): price 0, daily_rate 10000, duration_days 3,
 *      max_per_user 1, is_trial 1, is_active 1, type short_term.
 *
 *   1. PRE-FLIGHT : tabel `gpu_products` + `user_rentals` ada; kolom anchor
 *                   `gpu_products.max_per_user` ada (dipakai AFTER klausa);
 *                   peta reward promotor terbaca (guard tier).
 *   2. INSPECT    : kolom `is_trial` sudah ada / belum; baris trial sudah ada /
 *                   belum; jumlah baris `is_trial = 1` saat ini.
 *   3. DDL        : ADD COLUMN dijaga information_schema (idempoten; MySQL 8
 *                   tidak punya ADD COLUMN IF NOT EXISTS). TANPA backfill.
 *   4. SEED       : INSERT baris baru (id AUTO_INCREMENT) bila `name` belum ada,
 *                   atau UPDATE normalisasi bila sudah ada. `is_active` HANYA
 *                   ditulis pada INSERT — toggle admin TIDAK pernah dibalikkan.
 *   5. GUARD      : (a) tepat SATU baris `is_trial = 1`;
 *                   (b) id baris trial BUKAN kunci peta reward promotor
 *                   (`application/config/promoter_rewards.php`);
 *                   (c) invarian struktur baris trial: is_trial=1, price=0,
 *                   max_per_user=1, daily_rate=10000, duration_days=3
 *                   → pelanggaran = tamper → exit 2.
 *                   (d) `is_active = 0` pada baris trial = aksi admin yang SAH
 *                   → hanya [WARN], bukan tamper (tool ini tidak pernah
 *                   menyalakan kembali produk yang dimatikan admin).
 *   6. VERIFY     : tipe/nullability/default kolom + ulangi 5(a)–(c).
 *
 *   --dry-run   (default) inspeksi + rencana, TIDAK menulis apa pun
 *   --apply     DDL + seed/normalisasi baris trial + verifikasi
 *   --verify    hanya verifikasi (read-only); exit 2 bila tidak sesuai
 *   --help|-h   usage
 *
 * Safety:
 * - Idempotent: re-run = no-op ("sudah ada (dibiarkan)" / "sudah sesuai").
 * - Nilai kanonik & peta reward TIDAK diduplikasi: script meng-include
 *   application/config/promoter_rewards.php (setelah BASEPATH didefinisikan)
 *   sehingga CLI & aplikasi memakai satu sumber.
 * - TIDAK PERNAH menyentuh `user_rentals`, `wallet_ledger`, `withdrawals`,
 *   atau baris `gpu_products` lain.
 * - Kredensial dibaca dari application/config/database.php memakai semantik CI3
 *   ($active_group → 'default' → grup pertama), pola scripts/migrate_112_*.
 *   Override lokal: DB_HOSTNAME/DB_USERNAME/DB_PASSWORD/DB_DATABASE.
 *
 * ⚠️ URUTAN RILIS: jalankan migrasi ini SEBELUM deploy kode plan/114 — query
 * gerbang penarikan & GATE 0 checkout membaca `gpu_products.is_trial`.
 *
 * Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal.
 */

define('BASEPATH', 'cli-migrate-114-runner');
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
    echo "Usage: php scripts/migrate_114_trial_product_wd_gate.php [--dry-run|--apply|--verify] [--help]\n"
       . "  --dry-run  (default) inspeksi kolom/baris + cetak rencana, tanpa menulis\n"
       . "  --apply    tambah kolom `gpu_products.is_trial` + seed/normalisasi baris produk trial, lalu verifikasi\n"
       . "  --verify   hanya verifikasi (read-only); exit 2 bila tidak sesuai\n"
       . "  Catatan: TANPA backfill — baris gpu_products lama memakai DEFAULT 0.\n"
       . "           Produk trial dicocokkan lewat `name`, bukan `id` (id live ≠ id seed).\n";
    exit(0);
}

// ---------------------------------------------------------------- config app
// Satu sumber peta reward promotor (dipakai juga Promoter_model::get_tier_map()).
// Produk trial TIDAK BOLEH berada di peta ini (kalau tidak, produk trial bisa
// ditebus sebagai reward zero-cost — celah ekonomi).
$config   = array();
include $ROOT . '/application/config/promoter_rewards.php';
$REWARD_MAP = (isset($config['promoter_rewards']) && is_array($config['promoter_rewards']))
    ? array_map('intval', array_keys($config['promoter_rewards']))
    : array();

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
$TABLE  = 'gpu_products';
$COL    = 'is_trial';
$ANCHOR = 'max_per_user';   // AFTER klausa (pre-flight memastikan ada)

// Invarian STRUKTUR produk trial (tamper → exit 2). `is_active` sengaja TIDAK
// masuk daftar ini: mematikan produk trial adalah aksi admin yang sah
// (`admin/products/toggle_status`) dan tool ini tidak pernah membalikkannya.
$SPEC = array(
    'name'          => 'GPU Magang (Trial)',
    'type'          => 'short_term',
    'price'         => 0,
    'daily_rate'    => 10000,
    'duration_days' => 3,
    'is_refundable' => 0,
    'max_per_user'  => 1,
    'is_trial'      => 1,
);
$SPEC_INSERT_ACTIVE = 1;   // hanya dipakai saat INSERT baris baru.

echo "== Plan 114 — Trial Product (GPU Magang) + Withdrawal Gate: 1 kolom + 1 baris gpu_products ==\n";
echo "Server: {$m->server_info}  DB: {$DB}  Mode: " . ($dryRun ? 'DRY-RUN' : ($verifyOnly ? 'VERIFY' : 'APPLY')) . "\n";
echo 'Peta reward promotor (id yang DILARANG untuk produk trial): '
   . ($REWARD_MAP ? implode(', ', $REWARD_MAP) : '(kosong)') . "\n\n";

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

/**
 * Baris `gpu_products` berdasarkan `name` (SELECT * agar tetap aman dipanggil
 * SEBELUM kolom `is_trial` ada), atau NULL.
 */
function product_by_name(mysqli $m, $name)
{
    $stmt = $m->prepare("SELECT * FROM gpu_products WHERE `name` = ? LIMIT 1");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: NULL;
}

/**
 * Seluruh baris dengan `is_trial = 1` (hanya dipanggil bila kolom sudah ada).
 *
 * @return array<int,array>
 */
function trial_rows(mysqli $m)
{
    $out = array();
    $res = $m->query("SELECT * FROM gpu_products WHERE `is_trial` = 1 ORDER BY id ASC");
    while ($row = $res->fetch_assoc()) { $out[] = $row; }

    return $out;
}

/**
 * Baris produk berharga 0 yang BUKAN trial — tidak dapat di-checkout (GATE 0
 * fail-closed di Rental_model::checkout_rental). Bukan tamper skema, hanya
 * informasi operasional → [WARN].
 *
 * @return array<int,array>
 */
function zero_price_non_trial(mysqli $m)
{
    $out = array();
    $res = $m->query(
        "SELECT id, name, is_active FROM gpu_products
          WHERE `is_trial` = 0 AND `price` <= 0
          ORDER BY id ASC"
    );
    while ($row = $res->fetch_assoc()) { $out[] = $row; }

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

    // ── Kolom gpu_products.is_trial ────────────────────────────────────
    $col = column_info($m, $DB, $ctx['table'], $ctx['col']);
    if ($col === NULL)
    {
        $fail[] = "kolom `{$ctx['table']}.{$ctx['col']}` belum ada";

        return array($fail, $warn);   // tanpa kolom, pemeriksaan baris mustahil
    }

    if ($col['data_type'] !== 'tinyint')
    {
        $fail[] = "`{$ctx['table']}.{$ctx['col']}` harus TINYINT(1) (terdeteksi: {$col['column_type']})";
    }
    if ($col['nullable'] !== 'NO')
    {
        $fail[] = "`{$ctx['table']}.{$ctx['col']}` harus NOT NULL (terdeteksi: {$col['nullable']})";
    }
    if ((int) $col['default'] !== 0)
    {
        $fail[] = "`{$ctx['table']}.{$ctx['col']}` DEFAULT harus 0 (terdeteksi: "
                . var_export($col['default'], TRUE) . ')';
    }

    // ── Baris produk trial ─────────────────────────────────────────────
    $rows = trial_rows($m);
    $n    = count($rows);

    if ($n === 0)
    {
        $fail[] = "tidak ada baris `gpu_products` dengan `is_trial` = 1 (produk trial belum di-seed)";

        return array($fail, $warn);
    }

    if ($n > 1)
    {
        $ids = array();
        foreach ($rows as $r) { $ids[] = (int) $r['id']; }
        $fail[] = "invarian 'TEPAT SATU produk trial' dilanggar: {$n} baris (id: "
                . implode(', ', $ids) . ') — tamper?';

        return array($fail, $warn);
    }

    $t = $rows[0];
    $tid = (int) $t['id'];

    // (b) Produk trial tidak boleh masuk peta reward promotor.
    if (in_array($tid, $ctx['reward_map'], TRUE))
    {
        $fail[] = "produk trial (id {$tid}) berada di peta reward promotor "
                . '(' . implode(', ', $ctx['reward_map']) . ') — produk trial tidak boleh dapat ditebus '
                . 'sebagai kontrak reward zero-cost';
    }

    // (c) Invarian struktur.
    $checks = array(
        'is_trial'      => 1,
        'price'         => $ctx['spec']['price'],
        'daily_rate'    => $ctx['spec']['daily_rate'],
        'duration_days' => $ctx['spec']['duration_days'],
        'max_per_user'  => $ctx['spec']['max_per_user'],
    );
    foreach ($checks as $field => $expected)
    {
        if ((int) $t[$field] !== (int) $expected)
        {
            $fail[] = "`gpu_products.{$field}` baris trial (id {$tid}) harus {$expected} "
                    . '(terdeteksi: ' . var_export($t[$field], TRUE) . ') — tamper?';
        }
    }

    // Nama kanonik (kunci pencocokan migrasi) — drift nama = peringatan, bukan tamper.
    if ((string) $t['name'] !== (string) $ctx['spec']['name'])
    {
        $warn[] = "nama baris trial (id {$tid}) berbeda dari kanonik: "
                . var_export($t['name'], TRUE) . ' vs ' . var_export($ctx['spec']['name'], TRUE)
                . ' — migrasi berikutnya akan mencocokkan oleh `name` kanonik';
    }

    // (d) is_active = 0 → aksi admin yang sah.
    if ((int) $t['is_active'] !== 1)
    {
        $warn[] = "produk trial (id {$tid}) NONAKTIF (is_active = 0) — aksi admin, bukan drift; "
                . 'tool tidak menyalakannya kembali. Selama nonaktif, trial tidak ditawarkan di marketplace.';
    }

    // Info: produk harga 0 non-trial (ditolak GATE 0 checkout).
    foreach (zero_price_non_trial($m) as $z)
    {
        $warn[] = "produk '{$z['name']}' (id {$z['id']}) berharga <= 0 dan BUKAN trial — "
                . 'checkout akan ditolak fail-closed (`product_unavailable`)';
    }

    return array($fail, $warn);
}

// ---------------------------------------------------------------- pre-flight
foreach (array($TABLE, 'user_rentals') as $need)
{
    if ( ! table_exists($m, $DB, $need))
    {
        fwrite(STDERR, "[FATAL] Tabel `{$need}` tidak ditemukan di {$DB} — hentikan.\n");
        exit(1);
    }
}

if (column_info($m, $DB, $TABLE, $ANCHOR) === NULL)
{
    fwrite(STDERR, "[FATAL] Kolom `{$TABLE}.{$ANCHOR}` tidak ada (dipakai sebagai anchor AFTER) — hentikan.\n");
    exit(1);
}

$ctx = array(
    'table'      => $TABLE,
    'col'        => $COL,
    'anchor'     => $ANCHOR,
    'spec'       => $SPEC,
    'reward_map' => $REWARD_MAP,
);

// ---------------------------------------------------------------- inspect
$curCol   = column_info($m, $DB, $TABLE, $COL);
$byName   = product_by_name($m, $SPEC['name']);
$curTrial = $curCol ? trial_rows($m) : array();
$totalP   = (int) $m->query("SELECT COUNT(*) c FROM {$TABLE}")->fetch_assoc()['c'];

echo "Inspeksi (gpu_products: {$totalP} baris):\n";
echo '  kolom ' . str_pad($TABLE . '.' . $COL, 34) . ($curCol ? 'ADA (' . $curCol['column_type'] . ')' : 'BELUM ADA') . "\n";

if ($byName)
{
    echo '  baris trial by name ' . str_pad('', 14) . 'ADA (id ' . (int) $byName['id']
       . ', is_active ' . (int) $byName['is_active']
       . ', is_trial ' . (array_key_exists('is_trial', $byName) ? (int) $byName['is_trial'] : 'n/a') . ")\n";
}
else
{
    echo '  baris trial by name ' . str_pad('', 14) . "BELUM ADA\n";
}

echo '  baris is_trial = 1 ' . str_pad('', 15) . ($curCol ? count($curTrial) . ' baris' : '(kolom belum ada)') . "\n";
echo '  anchor ' . str_pad($TABLE . '.' . $ANCHOR, 41) . "ADA\n\n";

// ---------------------------------------------------------------- verify-only
if ($verifyOnly)
{
    echo "[verify] Memeriksa kolom is_trial + invarian produk trial (read-only) ...\n";
    list($fail, $warn) = verify_target($m, $DB, $ctx);

    foreach ($warn as $w) { echo "  [WARN] {$w}\n"; }

    if ($fail)
    {
        fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
        $m->close();
        exit(2);
    }

    echo "  kolom {$TABLE}.{$COL}          TINYINT(1) NOT NULL DEFAULT 0 OK\n";
    echo "  baris produk trial           TEPAT 1 (is_trial=1, price=0, max_per_user=1, "
       . "daily_rate={$SPEC['daily_rate']}, duration_days={$SPEC['duration_days']}) OK\n";
    echo "  guard peta reward promotor   OK (id trial di luar: "
       . ($REWARD_MAP ? implode(', ', $REWARD_MAP) : '(peta kosong)') . ")\n";
    $m->close();
    echo "\n[OK] Verifikasi plan/114 lulus.\n";
    exit(0);
}

// Guard awal: keadaan > 1 produk trial adalah tamper → JANGAN menulis apa pun.
if ($curCol && count($curTrial) > 1)
{
    $ids = array();
    foreach ($curTrial as $r) { $ids[] = (int) $r['id']; }
    fwrite(STDERR, "[FATAL] Ditemukan " . count($curTrial) . ' baris `is_trial = 1` (id: '
        . implode(', ', $ids) . ") — invarian 'TEPAT SATU produk trial' dilanggar.\n"
        . "        Perbaiki manual (set `is_trial` = 0 pada baris yang salah) sebelum menjalankan --apply.\n");
    $m->close();
    exit(2);
}

// ---------------------------------------------------------------- dry-run
if ($dryRun)
{
    echo "[dry-run] Rencana perubahan (TIDAK ada yang ditulis):\n";

    if ( ! $curCol)
    {
        echo "  1. ALTER TABLE `{$TABLE}` ADD COLUMN `{$COL}` TINYINT(1) NOT NULL DEFAULT 0 AFTER `{$ANCHOR}`\n";
        echo "     (TANPA backfill — seluruh baris lama otomatis 0 = non-trial)\n";
    }
    else
    {
        echo "  1. (kolom `{$TABLE}.{$COL}` sudah ada — dilewati)\n";
    }

    if ( ! $byName)
    {
        echo "  2. INSERT INTO `{$TABLE}` (`name`, `type`, `price`, `daily_rate`, `duration_days`,\n"
           . "        `is_refundable`, `max_per_user`, `is_active`, `{$COL}`)\n"
           . "        VALUES ('{$SPEC['name']}', '{$SPEC['type']}', {$SPEC['price']}.00, {$SPEC['daily_rate']}.00,\n"
           . "        {$SPEC['duration_days']}, {$SPEC['is_refundable']}, {$SPEC['max_per_user']},\n"
           . "        {$SPEC_INSERT_ACTIVE}, 1);   -- id AUTO_INCREMENT\n";
    }
    else
    {
        echo "  2. UPDATE `{$TABLE}` SET invarian kanonik → baris id " . (int) $byName['id']
           . " ('{$SPEC['name']}') sudah ada (`is_active` TIDAK diubah)\n";
    }

    echo "  3. Verifikasi: kolom bertipe benar + TEPAT 1 baris trial + invarian struktur + guard reward.\n";
    echo "\nJalankan ulang dengan --apply untuk menerapkan.\n";
    $m->close();
    exit(0);
}

// ---------------------------------------------------------------- apply
try {
    echo "[apply] 1/3 DDL kolom `{$TABLE}.{$COL}` ...\n";

    if ( ! $curCol)
    {
        $m->query(
            "ALTER TABLE `{$TABLE}` ADD COLUMN `{$COL}` TINYINT(1) NOT NULL DEFAULT 0 AFTER `{$ANCHOR}`"
        );
        echo "  {$TABLE}.{$COL}   ditambahkan (TANPA backfill — baris lama = 0)\n";
    }
    else
    {
        echo "  {$TABLE}.{$COL}   sudah ada (dibiarkan)\n";
    }

    echo "[apply] 2/3 Seed / normalisasi baris produk trial (kunci: `name`) ...\n";

    if ( ! $byName)
    {
        $stmt = $m->prepare(
            "INSERT INTO `{$TABLE}`
                (`name`, `type`, `price`, `daily_rate`, `duration_days`,
                 `is_refundable`, `max_per_user`, `is_active`, `{$COL}`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $type = $SPEC['type'];
        $stmt->bind_param(
            'ssiiiiiii',
            $SPEC['name'], $type,
            $SPEC['price'], $SPEC['daily_rate'], $SPEC['duration_days'],
            $SPEC['is_refundable'], $SPEC['max_per_user'], $SPEC_INSERT_ACTIVE, $SPEC['is_trial']
        );
        $stmt->execute();
        $newId = $m->insert_id;
        $stmt->close();
        echo "  baris trial DIBUAT (id {$newId}, price 0, daily_rate {$SPEC['daily_rate']}, "
           . "duration_days {$SPEC['duration_days']}, max_per_user {$SPEC['max_per_user']})\n";
    }
    else
    {
        $stmt = $m->prepare(
            "UPDATE `{$TABLE}`
                SET `type` = ?, `price` = ?, `daily_rate` = ?, `duration_days` = ?,
                    `is_refundable` = ?, `max_per_user` = ?, `{$COL}` = ?
              WHERE `id` = ?"
        );
        $tid_  = (int) $byName['id'];
        $type  = $SPEC['type'];
        $stmt->bind_param(
            'siiiiiii',
            $type,
            $SPEC['price'], $SPEC['daily_rate'], $SPEC['duration_days'],
            $SPEC['is_refundable'], $SPEC['max_per_user'], $SPEC['is_trial'],
            $tid_
        );
        $stmt->execute();
        $changed = $m->affected_rows;
        $stmt->close();
        echo "  baris trial id {$tid_} " . ($changed > 0
            ? "dinormalkan ({$changed} baris diubah — `is_active` tidak disentuh)"
            : 'sudah sesuai (no-op)') . "\n";
    }

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

$row = product_by_name($m, $SPEC['name']);
echo "\n[OK] Plan 114 siap: kolom `{$TABLE}.{$COL}` ada + TEPAT 1 produk trial (id "
   . (int) $row['id'] . ", is_active " . (int) $row['is_active'] . ").\n";

$m->close();
exit(0);
