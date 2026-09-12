<?php
/**
 * Synapse (db_webtable) — Plan 103 Notification i18n Migration CLI
 *
 * One-off operator tool: menjadikan `user_notifications` dwibahasa dengan
 * menyimpan KEY + PARAMETER, bukan hanya prosa beku satu bahasa.
 *
 *   1. ALTER   : tambah `title_key` VARCHAR(64) NULL + `params` JSON NULL.
 *   2. BACKFILL: cocokkan (title, message) baris lama terhadap daftar pola
 *                kanonik 16 call-site → isi title_key + params (JSON).
 *                Baris yang TIDAK dikenali dibiarkan title_key = NULL
 *                (tetap dirender apa adanya — nol kehilangan data).
 *   3. VERIFY  : kolom ada, hitungan backfill, dan 0 baris rusak (title_key
 *                terisi tetapi params tidak bisa di-decode).
 *
 *   --dry-run   (default) inspeksi + rencana, TIDAK menulis apa pun
 *   --apply     jalankan DDL + backfill, lalu verifikasi
 *   --help|-h   usage
 *
 * Safety:
 * - Idempotent: kolom diperiksa via information_schema lebih dulu; backfill
 *   hanya menyentuh baris `title_key IS NULL`, sehingga re-run = no-op.
 * - DDL MySQL auto-commit (tidak bisa di-rollback): tiap tahap diverifikasi
 *   SETELAH eksekusi; gagal → exit code 2.
 * - Kolom `title`/`message` TIDAK dihapus/diubah (retensi + fallback audit).
 * - Kredensial dibaca dari application/config/database.php, pola sama dengan
 *   scripts/migrate_102_qris_deposits.php.
 *
 * Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal.
 */

define('BASEPATH', 'cli-migrate-103-runner');
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
    echo "Usage: php scripts/migrate_103_notification_i18n.php [--dry-run|--apply] [--help]\n"
       . "  --dry-run  (default) inspeksi kolom + cetak rencana backfill, tanpa menulis\n"
       . "  --apply    tambah title_key/params lalu backfill notifikasi lama\n";
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
$host = $db['hostname'] === 'localhost' ? '127.0.0.1' : $db['hostname'];

// ---------------------------------------------------------------- connect
try {
    $m = new mysqli($host, $db['username'], $db['password'], $db['database'], 3306);
    $m->set_charset('utf8mb4');
    $m->query("SET time_zone = '+07:00'");
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] Koneksi DB gagal: " . $e->getMessage() . "\n");
    exit(1);
}

$TABLE = 'user_notifications';

// ---------------------------------------------------------------- helpers
function col_exists(mysqli $m, $table, $col)
{
    $t = $m->real_escape_string($table);
    $c = $m->real_escape_string($col);
    $r = $m->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$c}'");
    return ((int) $r->fetch_assoc()['c']) > 0;
}

/**
 * Daftar pola backfill: title_key => [regex title, regex message, param extractor]
 *
 * Regex sengaja longgar pada angka (pemisah ribuan berubah-ubah) namun ketat
 * pada teks, sehingga hanya baris yang benar-benar berasal dari template
 * kanonik yang dipetakan. Urutan penting: pola paling spesifik lebih dulu.
 */
function backfill_rules()
{
    $idr = '(Rp\s*[0-9][0-9\.]*)';

    return array(
        // ---- ROI harian
        array('notif_roi', '/^ROI Harian Cair$/u',
              '/^ROI sebesar ' . $idr . ' telah masuk ke saldo \(kontrak #(\d+)\)\.$/u',
              function ($mm) { return array($mm[1], (int) $mm[2]); }),

        // ---- Deposit
        array('notif_deposit_approved', '/^Deposit Berhasil$/u',
              '/^Top Up sebesar ' . $idr . ' telah masuk ke saldo Anda\.$/u',
              function ($mm) { return array($mm[1]); }),

        array('notif_deposit_declined', '/^Deposit Ditolak$/u',
              '/^Deposit .+ ditolak\.(?: Alasan: (.*))?$/u',
              function ($mm) { return array(isset($mm[1]) ? $mm[1] : ''); }),

        // ---- Penarikan
        array('notif_wd_approved', '/^Penarikan Berhasil$/u',
              '/^Penarikan sebesar ' . $idr . ' telah diproses\.$/u',
              function ($mm) { return array($mm[1]); }),

        array('notif_wd_declined', '/^Penarikan Ditolak$/u',
              '/^Penarikan sebesar ' . $idr . ' ditolak\. Dana telah dikembalikan ke saldo\.(?: Alasan: (.*))?$/u',
              function ($mm) { return array($mm[1], isset($mm[2]) ? $mm[2] : ''); }),

        // ---- Rebate & bonus
        array('notif_rebate', '/^Komisi Rebate Cair$/u',
              '/^Komisi Level (\d+) sebesar ' . $idr . ' dari pembelian sewa .+ telah masuk ke saldo Anda\.$/u',
              function ($mm) { return array((int) $mm[1], $mm[2]); }),

        array('notif_bonus_l1', '/^Bonus Level 1 Cair$/u',
              '/^Selamat! Bonus Level 1 sebesar ' . $idr . ' telah masuk ke saldo\.$/u',
              function ($mm) { return array($mm[1]); }),

        array('notif_wage', '/^Gaji Mingguan Cair$/u',
              '/^Selamat! Gaji mingguan Level \d+ sebesar ' . $idr . ' telah masuk ke saldo\.$/u',
              function ($mm) { return array($mm[1]); }),

        // ---- Promoter
        array('notif_promoter_approved', '/^Reward Promotor Cair$/u',
              '/^Kontrak (.+?) \(reward, tanpa biaya\) telah diaktifkan\. Klaim ROI harian dimulai H\+1\.$/u',
              function ($mm) { return array($mm[1]); }),

        array('notif_promoter_rejected', '/^Klaim Reward Ditolak$/u',
              '/^Klaim (.+?) ditolak(?:: (.*?))?\. Omzet Anda telah dikembalikan ke saldo redeemable\.$/u',
              function ($mm) { return array($mm[1], isset($mm[2]) ? $mm[2] : ''); }),

        // ---- Akun
        array('notif_unbanned', '/^Akun Diaktifkan Kembali$/u',
              '/^Akun Anda telah dibuka blokirnya oleh admin\. Silakan login kembali\.$/u',
              function ($mm) { return array(); }),

        array('notif_promoter_on', '/^Status Promotor Aktif$/u',
              '/^Selamat! Anda kini promotor/u',
              function ($mm) { return array(); }),

        array('notif_promoter_off', '/^Status Promotor Dicabut$/u',
              '/^Status promotor Anda dicabut oleh admin/u',
              function ($mm) { return array(); }),

        // ---- Saldo & rental (admin)
        array('notif_balance_credit', '/^Saldo Ditambahkan Admin$/u',
              '/^Saldo sebesar ' . $idr . ' telah ditambahkan ke saldo Anda oleh admin\.(?: Keterangan: (.*))?$/u',
              function ($mm) {
                  $note = isset($mm[2]) && $mm[2] !== '' ? sprintf(' Keterangan: %s', $mm[2]) : '';
                  return array($mm[1], $note);
              }),

        array('notif_balance_debit', '/^Saldo Dipotong Admin$/u',
              '/^Saldo sebesar ' . $idr . ' telah dipotong dari saldo Anda oleh admin\.(?: Keterangan: (.*))?$/u',
              function ($mm) {
                  $note = isset($mm[2]) && $mm[2] !== '' ? sprintf(' Keterangan: %s', $mm[2]) : '';
                  return array($mm[1], $note);
              }),

        array('notif_rental_injected', '/^Sewa Diaktifkan Admin$/u',
              '/^Kontrak sewa \(produk #(\d+)\) telah diaktifkan untuk akun Anda oleh admin\.$/u',
              function ($mm) { return array((int) $mm[1]); }),

        array('notif_rental_expired', '/^Kontrak Sewa Selesai$/u',
              '/^Masa sewa kontrak #(\d+) telah berakhir dan kontrak ditutup otomatis\.$/u',
              function ($mm) { return array((int) $mm[1]); }),
    );
}

// ---------------------------------------------------------------- pre-flight
echo "=== plan/103 — notifikasi i18n migration ===\n";
echo "Mode: " . ($apply ? "APPLY" : "DRY-RUN") . "\n\n";

$hasTitleKey = col_exists($m, $TABLE, 'title_key');
$hasParams   = col_exists($m, $TABLE, 'params');

echo "[1/3] Kolom\n";
echo "  title_key : " . ($hasTitleKey ? "ADA" : "BELUM ADA") . "\n";
echo "  params    : " . ($hasParams ? "ADA" : "BELUM ADA") . "\n";

$total = (int) $m->query("SELECT COUNT(*) c FROM `{$TABLE}`")->fetch_assoc()['c'];
echo "  baris total: {$total}\n\n";

// ---------------------------------------------------------------- DDL
if (!$hasTitleKey || !$hasParams) {
    $clauses = array();
    if (!$hasTitleKey) { $clauses[] = "ADD COLUMN `title_key` VARCHAR(64) NULL AFTER `message`"; }
    if (!$hasParams)   { $clauses[] = "ADD COLUMN `params` JSON NULL AFTER `title_key`"; }

    $ddl = "ALTER TABLE `{$TABLE}` " . implode(', ', $clauses);

    echo "[2/3] DDL\n  {$ddl}\n";
    if ($apply) {
        $m->query($ddl);
        $hasTitleKey = col_exists($m, $TABLE, 'title_key');
        $hasParams   = col_exists($m, $TABLE, 'params');
        if (!$hasTitleKey || !$hasParams) {
            fwrite(STDERR, "\n[FATAL] ALTER gagal — kolom tidak terdeteksi setelah eksekusi.\n");
            $m->close();
            exit(2);
        }
        echo "  -> diterapkan & terverifikasi (2/2 kolom ADA)\n";
    } else {
        echo "  -> (dry-run) tidak dieksekusi\n";
    }
} else {
    echo "[2/3] DDL\n  -> dilewati (kolom sudah sesuai)\n";
}
echo "\n";

// ---------------------------------------------------------------- BACKFILL
echo "[3/3] Backfill\n";

$rules = backfill_rules();

// Dry-run tanpa kolom: rencanakan terhadap SELURUH baris (prediksi backfill);
// dengan kolom: hanya baris yang belum ber-key (idempotent).
$rowsSql = $hasTitleKey
    ? "SELECT id, title, message FROM `{$TABLE}` WHERE `title_key` IS NULL"
    : "SELECT id, title, message FROM `{$TABLE}`";
$rows = $m->query($rowsSql);

$plan     = array();   // id => [key, params_json]
$unmatched = 0;

while ($r = $rows->fetch_assoc()) {
    $matched = false;
    foreach ($rules as $rule) {
        list($key, $titleRe, $msgRe, $extract) = $rule;
        if (!preg_match($titleRe, (string) $r['title'])) {
            continue;
        }
        if (!preg_match($msgRe, (string) $r['message'], $mm)) {
            continue;
        }
        $params = $extract($mm);
        $plan[(int) $r['id']] = array($key, $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null);
        $matched = true;
        break;
    }
    if (!$matched) { $unmatched++; }
}

$byKey = array();
foreach ($plan as $entry) {
    $byKey[$entry[0]] = ($byKey[$entry[0]] ?? 0) + 1;
}
ksort($byKey);

echo "  kandidat dipetakan : " . count($plan) . "\n";
echo "  tidak dikenali     : {$unmatched} (dibiarkan title_key = NULL)\n";
foreach ($byKey as $k => $n) {
    echo sprintf("    %-28s %d\n", $k, $n);
}

if ($apply && $plan) {
    $stmt = $m->prepare("UPDATE `{$TABLE}` SET `title_key` = ?, `params` = ? WHERE `id` = ? AND `title_key` IS NULL");
    $done = 0;
    $m->begin_transaction();
    try {
        foreach ($plan as $id => $entry) {
            $stmt->bind_param('ssi', $entry[0], $entry[1], $id);
            $stmt->execute();
            $done += $stmt->affected_rows;
        }
        $m->commit();
    } catch (Throwable $e) {
        $m->rollback();
        fwrite(STDERR, "\n[FATAL] Backfill gagal: " . $e->getMessage() . "\n");
        $m->close();
        exit(2);
    }
    $stmt->close();
    echo "  -> diterapkan: {$done} baris di-update\n";
} elseif (!$apply) {
    echo "  -> (dry-run) tidak dieksekusi\n";
} else {
    echo "  -> tidak ada kandidat; no-op\n";
}
echo "\n";

// ---------------------------------------------------------------- VERIFY
$fail = array();

$colTitleKey = col_exists($m, $TABLE, 'title_key');
$colParams   = col_exists($m, $TABLE, 'params');

if (!$colTitleKey) { $fail[] = "kolom title_key hilang"; }
if (!$colParams)   { $fail[] = "kolom params hilang"; }

if (!$colTitleKey || !$colParams) {
    echo "VERIFY\n  -> dilewati (kolom belum ada; mode dry-run)\n";
    $m->close();
    echo "\n[OK] Dry-run selesai — jalankan --apply untuk menerapkan.\n";
    exit(0);
}

$stillNull = (int) $m->query("SELECT COUNT(*) c FROM `{$TABLE}` WHERE `title_key` IS NULL")->fetch_assoc()['c'];
$badJson   = (int) $m->query("SELECT COUNT(*) c FROM `{$TABLE}`
                               WHERE `title_key` IS NOT NULL AND `params` IS NOT NULL
                                 AND JSON_VALID(`params`) = 0")->fetch_assoc()['c'];
if ($badJson > 0) { $fail[] = "{$badJson} baris params bukan JSON valid"; }

$orphanKeys = (int) $m->query("SELECT COUNT(*) c FROM `{$TABLE}`
                                WHERE `title_key` IS NOT NULL
                                  AND `title_key` NOT IN ('notif_roi','notif_deposit_approved','notif_deposit_declined',
                                      'notif_wd_approved','notif_wd_declined','notif_rebate','notif_bonus_l1','notif_wage',
                                      'notif_promoter_approved','notif_promoter_rejected','notif_unbanned',
                                      'notif_promoter_on','notif_promoter_off','notif_balance_credit',
                                      'notif_balance_debit','notif_rental_injected','notif_rental_expired')")->fetch_assoc()['c'];
if ($orphanKeys > 0) { $fail[] = "{$orphanKeys} baris memakai title_key di luar daftar kanonik"; }

echo "VERIFY\n";
echo "  title_key NULL tersisa : {$stillNull}\n";
echo "  params JSON invalid    : {$badJson}\n";
echo "  title_key non-kanonik  : {$orphanKeys}\n";

$m->close();

if ($fail) {
    fwrite(STDERR, "\n[FATAL] Verifikasi GAGAL:\n  - " . implode("\n  - ", $fail) . "\n");
    exit(2);
}

echo "\n[OK] Migrasi plan/103 notifikasi selesai & terverifikasi.\n";
exit(0);
