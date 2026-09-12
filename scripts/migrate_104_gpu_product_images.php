<?php
/**
 * Synapse (db_webtable) — Plan 104 GPU Product Image Migration CLI
 *
 * One-off operator tool: menambahkan kolom `gpu_products.image` dan
 * menghubungkan 8 aset gambar produk yang sudah ada di disk
 * (`uploads/products/`) ke 8 paket kanonik (RTX 3060 Starter s.d.
 * H200 Sovereign).
 *
 *   1. ALTER   : tambah `image` VARCHAR(255) NULL DEFAULT NULL AFTER `name`.
 *   2. BACKFILL: peta KEYED BY `name` (bukan `id`!) — id live bisa 5-12
 *                sementara seed kanonik database.sql memakai id 1-8, dan
 *                prefix angka pada nama berkas = urutan lineup, bukan id.
 *                Default: berkas di-*rename* ke slug (hapus spasi) supaya
 *                URL bersih; `--keep-filenames` menyimpan nama apa adanya.
 *   3. VERIFY  : kolom ada, 8/8 baris kanonik terisi, tiap berkas ada di
 *                disk, dan 0 referensi rusak (nama terisi tanpa berkas).
 *                Berkas > 2048 KB diperingatkan (di atas batas unggah admin).
 *
 *   --dry-run         (default) inspeksi + rencana, TIDAK menulis apa pun
 *   --apply           DDL + rename aset + backfill, lalu verifikasi
 *   --verify          hanya verifikasi (read-only)
 *   --keep-filenames  jangan rename berkas (simpan nama aset apa adanya)
 *   --help|-h         usage
 *
 * Safety:
 * - Idempotent: kolom diperiksa via information_schema lebih dulu; backfill
 *   hanya menyentuh baris `image IS NULL` (atau yang masih menunjuk nama
 *   pra-rename) sehingga re-run = no-op.
 * - Gambar hasil upload admin TIDAK PERNAH ditimpa (tidak ada flag --force).
 * - Urutan rename → UPDATE: crash di antaranya menyisakan referensi ke
 *   berkas yang sudah tiada (marketplace merender fallback banner) dan
 *   re-run menyembuhkan sendiri.
 * - DDL MySQL auto-commit (tidak bisa di-rollback): tiap tahap diverifikasi
 *   SETELAH eksekusi; gagal → exit code 2.
 * - Kredensial dibaca dari application/config/database.php, pola sama dengan
 *   scripts/migrate_102_qris_deposits.php & migrate_103_notification_i18n.php.
 *
 * Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal.
 */

define('BASEPATH', 'cli-migrate-104-runner');
define('ENVIRONMENT', 'development');

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Jakarta');   // WIB — otoritas waktu aplikasi (M2)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ROOT = dirname(__DIR__);

$args       = $argv ?: [];
$apply      = in_array('--apply', $args, true);
$verifyOnly = in_array('--verify', $args, true);
$keepNames  = in_array('--keep-filenames', $args, true);
$dryRun     = ( ! $apply && ! $verifyOnly);

if (in_array('--help', $args, true) || in_array('-h', $args, true))
{
    echo "Usage: php scripts/migrate_104_gpu_product_images.php [--dry-run|--apply|--verify]\n"
       . "                                                       [--keep-filenames] [--help]\n"
       . "  --dry-run         (default) inspeksi kolom + cetak rencana, tanpa menulis\n"
       . "  --apply           tambah kolom image lalu backfill 8 paket kanonik\n"
       . "  --verify          hanya verifikasi (read-only); exit 2 bila tidak 8/8\n"
       . "  --keep-filenames  jangan rename aset ke slug (nama ber-spasi dipertahankan)\n";
    exit(0);
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
$host = $db['hostname'] === 'localhost' ? '127.0.0.1' : $db['hostname'];

// ---------------------------------------------------------------- connect
try
{
    $m = new mysqli($host, $db['username'], $db['password'], $db['database'], 3306);
    $m->set_charset('utf8mb4');
    $m->query("SET time_zone = '+07:00'");
}
catch (Throwable $e)
{
    fwrite(STDERR, "[FATAL] Koneksi DB gagal: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------- constants
$TABLE     = 'gpu_products';
$DIR_REL   = 'uploads/products/';
$DIR_ABS   = $ROOT . '/' . $DIR_REL;
$MAX_KB    = 2048;   // batas unggah admin (KB) — selaras Upload config

/**
 * Peta backfill kanonik — KEYED BY `name`.
 *
 * 'src'  = nama berkas saat ini di uploads/products/
 * 'slug' = target nama kanonik (lowercase, tanpa spasi)
 */
function asset_map()
{
    return array(
        array('name' => 'RTX 3060 Starter',   'src' => 'product1-RTX 3060 Starter.jpeg',   'slug' => 'product1-rtx-3060-starter.jpeg'),
        array('name' => 'RTX 4060 Lite',      'src' => 'product2-RTX 4060 Lite.jpeg',      'slug' => 'product2-rtx-4060-lite.jpeg'),
        array('name' => 'RTX 4070 Basic',     'src' => 'product3-RTX 4070 Basic.jpeg',     'slug' => 'product3-rtx-4070-basic.jpeg'),
        array('name' => 'RTX 4080 Prime',     'src' => 'product4-RTX 4080 Prime.jpeg',     'slug' => 'product4-rtx-4080-prime.jpeg'),
        array('name' => 'RTX 4090 Pro',       'src' => 'product5-RTX 4090 Pro.jpeg',       'slug' => 'product5-rtx-4090-pro.jpeg'),
        array('name' => 'A100 Cloud Cluster', 'src' => 'product6-A100 Cloud Cluster.jpeg', 'slug' => 'product6-a100-cloud-cluster.jpeg'),
        array('name' => 'H100 Tensor Node',   'src' => 'product7-H100 Tensor Node.jpeg',   'slug' => 'product7-h100-tensor-node.jpeg'),
        array('name' => 'H200 Sovereign',     'src' => 'product8-H200 Sovereign.jpeg',     'slug' => 'product8-h200-sovereign.jpeg'),
    );
}

// ---------------------------------------------------------------- helpers
function col_exists(mysqli $m, $table, $col)
{
    $t = $m->real_escape_string($table);
    $c = $m->real_escape_string($col);
    $r = $m->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$c}'");
    return ((int) $r->fetch_assoc()['c']) > 0;
}

function table_exists(mysqli $m, $table)
{
    $t = $m->real_escape_string($table);
    $r = $m->query("SELECT COUNT(*) c FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'");
    return ((int) $r->fetch_assoc()['c']) > 0;
}

/**
 * Sanitasi nama berkas aset (script berjalan di luar bootstrap CI3 sehingga
 * tidak memakai product_image_helper — duplikasi minimal & sengaja).
 */
function asset_name_is_safe($name)
{
    if ( ! is_string($name) || $name === '' || strlen($name) > 255) return FALSE;
    if (strpos($name, '/') !== FALSE || strpos($name, '\\') !== FALSE) return FALSE;
    if (strpos($name, '..') !== FALSE || strpos($name, "\0") !== FALSE) return FALSE;
    if (basename($name) !== $name) return FALSE;

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), TRUE);
}

function kb_of($path)
{
    return is_file($path) ? round(filesize($path) / 1024, 2) : NULL;
}

function fmt_size($path)
{
    $kb = kb_of($path);
    return ($kb === NULL) ? '—' : number_format($kb, 2, ',', '.') . ' KB';
}

function rows_by_name(mysqli $m, $table, $name, $with_image = TRUE)
{
    // Dry-run pada DB yang belum punya kolom `image`: SELECT tidak boleh
    // menyebut kolom yang belum ada (mode ini murni read-only).
    $cols = $with_image ? '`id`, `image`, `is_active`' : '`id`, NULL AS `image`, `is_active`';

    $st = $m->prepare("SELECT {$cols} FROM `{$table}` WHERE name = ? ORDER BY id ASC");
    $st->bind_param('s', $name);
    $st->execute();
    $res = $st->get_result();
    $out = array();
    while ($row = $res->fetch_assoc()) { $out[] = $row; }
    $st->close();
    return $out;
}

// ---------------------------------------------------------------- pre-flight
echo "=== Plan 104 — GPU Product Image Migration ===\n";
echo "DB       : {$db['database']} @ {$host}\n";
echo "Mode     : " . ($apply ? 'APPLY (menulis)' : ($verifyOnly ? 'VERIFY (read-only)' : 'DRY-RUN (tanpa menulis)')) . "\n";
echo "Rename   : " . ($keepNames ? 'TIDAK (nama aset dipertahankan)' : 'YA (nama aset → slug)') . "\n\n";

$exit = 0;

if ( ! table_exists($m, $TABLE)) {
    fwrite(STDERR, "[FATAL] Tabel `{$TABLE}` tidak ditemukan.\n");
    exit(1);
}

if ( ! is_dir($DIR_ABS)) {
    if ($apply) {
        fwrite(STDERR, "[FATAL] Direktori {$DIR_REL} tidak ditemukan — buat direktori lebih dulu.\n");
        exit(1);
    }
    echo "[WARN] Direktori {$DIR_REL} tidak ditemukan (mode non-apply).\n\n";
} elseif ( ! is_writable($DIR_ABS) && $apply) {
    fwrite(STDERR, "[FATAL] Direktori {$DIR_REL} tidak writable — perbaiki permission.\n");
    exit(1);
}

// ---------------------------------------------------------------- 1. DDL
$has_col = col_exists($m, $TABLE, 'image');

echo "--- 1. Kolom `image` -------------------------------------------\n";
echo "Status awal : " . ($has_col ? 'SUDAH ADA' : 'BELUM ADA') . "\n";

if ( ! $has_col && $apply) {
    $m->query("ALTER TABLE `{$TABLE}` ADD COLUMN `image` VARCHAR(255) NULL DEFAULT NULL AFTER `name`");

    if ( ! col_exists($m, $TABLE, 'image')) {
        fwrite(STDERR, "[FATAL] ALTER selesai tanpa error tetapi kolom tidak terdeteksi — verifikasi manual.\n");
        exit(2);
    }
    $has_col = TRUE;
    echo "DDL         : ALTER TABLE `{$TABLE}` ADD COLUMN `image` … → OK\n";
} elseif ( ! $has_col) {
    echo "DDL         : " . ($verifyOnly ? 'TIDAK dijalankan (mode verify).' : 'akan dijalankan saat --apply.') . "\n";
} else {
    echo "DDL         : dilewati (idempoten — kolom sudah ada).\n";
}
echo "\n";

$col_ready = $has_col;

// ---------------------------------------------------------------- 2. Backfill
$plan_rows = array();
$stats     = array('matched' => 0, 'updated' => 0, 'noop' => 0, 'skipped_admin' => 0,
                   'missing_asset' => 0, 'renamed' => 0, 'dupe_name' => 0, 'unknown' => 0);

echo "--- 2. Backfill 8 paket kanonik (keyed by `name`) --------------\n";

foreach (asset_map() as $entry) {
    $name   = $entry['name'];
    $src    = $entry['src'];
    $slug   = $entry['slug'];
    $rows   = rows_by_name($m, $TABLE, $name, $has_col);

    if (count($rows) === 0) {
        $stats['unknown']++;
        echo sprintf("  [SKIP] %-20s — nama tidak ada di DB\n", $name);
        continue;
    }
    if (count($rows) > 1) {
        $stats['dupe_name']++;
        echo sprintf("  [WARN] %-20s — %d baris cocok (nama seharusnya unik); semua diproses\n", $name, count($rows));
    }
    $stats['matched']++;

    // ── Resolusi nama target berkas (rename bila diizinkan).
    $src_path  = $DIR_ABS . $src;
    $slug_path = $DIR_ABS . $slug;
    $target    = NULL;

    if ($keepNames) {
        $target = is_file($src_path) ? $src : (is_file($slug_path) ? $slug : NULL);
    } elseif (is_file($slug_path)) {
        $target = $slug;                                  // sudah pernah di-rename
    } elseif (is_file($src_path)) {
        if ($apply) {
            if (@rename($src_path, $slug_path)) {
                $target = $slug;
                $stats['renamed']++;
            } else {
                $target = $src;                           // rename gagal → tetap pakai nama asli
                echo sprintf("  [WARN] %-20s — rename gagal, memakai nama asli\n", $name);
            }
        } else {
            $target = $slug;                              // rencana (dry-run)
        }
    } else {
        $stats['missing_asset']++;
        echo sprintf("  [WARN] %-20s — berkas aset tidak ditemukan (%s)\n", $name, $src);
        continue;
    }

    if ($target === NULL || ! asset_name_is_safe($target)) {
        $stats['missing_asset']++;
        echo sprintf("  [WARN] %-20s — nama target tidak aman / kosong\n", $name);
        continue;
    }

    foreach ($rows as $row) {
        $id      = (int) $row['id'];
        $current = $row['image'];
        $cur     = ($current === NULL) ? NULL : (string) $current;

        // Gambar hasil upload admin (nama acak 32 hex) atau nilai lain yang
        // bukan nama pra-rename → JANGAN ditimpa.
        if ($cur !== NULL && $cur !== '' && $cur !== $target && $cur !== $src) {
            $stats['skipped_admin']++;
            echo sprintf("  [KEEP] id=%-3d %-20s — sudah punya gambar (%s), tidak ditimpa\n", $id, $name, $cur);
            continue;
        }

        if ($cur === $target) {
            $stats['noop']++;
            echo sprintf("  [OK]   id=%-3d %-20s — image sudah = %s\n", $id, $name, $target);
            continue;
        }

        if ( ! $apply) {
            $stats['updated']++;
            echo sprintf("  [PLAN] id=%-3d %-20s — SET image = '%s'\n", $id, $name, $target);
            continue;
        }

        // Guard idempotensi: hanya isi yang NULL atau yang masih menunjuk
        // nama pra-rename (recovery setelah crash di antara rename & update).
        $st = $m->prepare("UPDATE `{$TABLE}` SET `image` = ?
                            WHERE `id` = ? AND (`image` IS NULL OR `image` = ?)");
        $st->bind_param('sis', $target, $id, $src);
        $st->execute();
        $affected = $st->affected_rows;
        $st->close();

        $stats['updated']++;
        echo sprintf("  [SET]  id=%-3d %-20s — image = '%s'%s\n",
            $id, $name, $target, ($affected === 0 ? ' (0 baris berubah — cek manual)' : ''));
    }

    $plan_rows[] = array('id' => (int) $rows[0]['id'], 'name' => $name, 'image' => $target);
}

echo "\n";

// ---------------------------------------------------------------- 3. Verify
echo "--- 3. Verifikasi ----------------------------------------------\n";

$verify_ok  = TRUE;
$filled     = 0;
$missing    = array();
$oversize   = array();

if ( ! $col_ready) {
    echo "Kolom `image` belum ada — verifikasi isi dilewati (jalankan --apply).\n\n";
    $exit = $apply ? 2 : 0;
} else {
    foreach (asset_map() as $entry) {
        foreach (rows_by_name($m, $TABLE, $entry['name'], $col_ready) as $row) {
            $id = (int) $row['id'];
            $im = $row['image'];

            if ($im === NULL || $im === '') {
                $missing[] = "id={$id} ({$entry['name']}) — image masih NULL";
                continue;
            }
            $filled++;
            $path = $DIR_ABS . $im;

            if ( ! is_file($path)) {
                $missing[] = "id={$id} ({$entry['name']}) — berkas '{$im}' tidak ada di disk";
                continue;
            }
            if (kb_of($path) > $MAX_KB) {
                $oversize[] = "id={$id} ({$entry['name']}) — " . fmt_size($path) . " > {$MAX_KB} KB";
            }
        }
    }

    echo "Baris kanonik terisi : {$filled}/8\n";

    // Referensi rusak di SELURUH tabel (termasuk baris di luar 8 kanonik).
    $broken = array();
    $res = $m->query("SELECT id, name, image FROM `{$TABLE}` WHERE `image` IS NOT NULL AND `image` <> ''");
    while ($row = $res->fetch_assoc()) {
        if ( ! is_file($DIR_ABS . $row['image'])) {
            $broken[] = "id={$row['id']} ({$row['name']}) → '{$row['image']}'";
        }
    }
    echo "Referensi rusak      : " . count($broken) . " baris (seluruh tabel)\n";
    echo "Berkas > {$MAX_KB} KB     : " . count($oversize) . " berkas\n\n";

    if (count($missing) > 0) {
        $verify_ok = FALSE;
        echo "[GAGAL] Baris kanonik belum lengkap:\n";
        foreach ($missing as $line) echo "  - {$line}\n";
        echo "\n";
    }
    if (count($broken) > 0) {
        echo "[WARN] Referensi rusak (baris non-kanonik / manual) — view merender fallback:\n";
        foreach ($broken as $line) echo "  - {$line}\n";
        echo "\n";
    }
    if (count($oversize) > 0) {
        echo "[WARN] Berkas melebihi batas unggah admin ({$MAX_KB} KB) — TIDAK dapat di-upload\n";
        echo "       ulang lewat panel admin; pertimbangkan rekompresi offline:\n";
        foreach ($oversize as $line) echo "  - {$line}\n";
        echo "\n";
    }

    if ($verify_ok) {
        echo "[OK] 8/8 paket kanonik memiliki gambar yang valid di disk.\n\n";
    }
}

// ---------------------------------------------------------------- summary
echo "--- Ringkasan --------------------------------------------------\n";
echo "Cocok nama           : {$stats['matched']}/8\n";
printf("Backfill %s     : %d\n", ($apply ? 'diterapkan' : 'direncanakan'), $stats['updated']);
echo "Sudah benar (no-op)  : {$stats['noop']}\n";
echo "Rename aset          : {$stats['renamed']}\n";
echo "Dilewati (gambar ada): {$stats['skipped_admin']}\n";
echo "Aset hilang          : {$stats['missing_asset']}\n";
echo "Nama tidak dikenal   : {$stats['unknown']}\n";
echo "Nama duplikat        : {$stats['dupe_name']}\n\n";

if ($dryRun) {
    echo "[DRY-RUN] Tidak ada perubahan ditulis. Jalankan --apply untuk menerapkan.\n";
}

if ($apply && ! $verify_ok) {
    fwrite(STDERR, "[GAGAL] Apply selesai tetapi verifikasi tidak 8/8 — periksa output di atas.\n");
    exit(2);
}

if ($verifyOnly && ! $verify_ok) {
    fwrite(STDERR, "[GAGAL] Verifikasi tidak 8/8.\n");
    exit(2);
}

exit($exit);
