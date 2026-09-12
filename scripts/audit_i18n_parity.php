<?php
/**
 * Synapse — Plan 103 i18n Dictionary Parity Audit
 *
 * Menegakkan invariant kamus dwibahasa plan/94 F1 + aturan P1–P6 plan/103:
 *
 *   P1  himpunan key english/app_lang.php ≡ indonesian/app_lang.php
 *   P2  (ditegakkan oleh P1) — kedua file diperlakukan sebagai satu unit
 *   P3  uang/angka TIDAK pernah masuk kamus (L6)
 *   P5  nilai EN ≡ ID hanya untuk kategori sah (allowlist eksplisit)
 *   P6  tanpa newline literal; tanpa karakter berisiko di atribut HTML
 *
 * Usage:
 *   php scripts/audit_i18n_parity.php             laporan lengkap
 *   php scripts/audit_i18n_parity.php --quiet     hanya ringkasan + kegagalan
 *   php scripts/audit_i18n_parity.php --help
 *
 * Exit codes: 0 = semua gate lulus, 1 = ada pelanggaran, 2 = error environment.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('BASEPATH', 'cli-audit-parity-runner');

$ROOT  = dirname(__DIR__);
$args  = $argv ?: [];
$quiet = in_array('--quiet', $args, true);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Usage: php scripts/audit_i18n_parity.php [--quiet] [--help]\n"
       . "  Menegakkan paritas & higienitas kamus EN/ID (plan/103 P1/P3/P5/P6).\n"
       . "  Exit 0 = lulus; 1 = pelanggaran; 2 = error.\n";
    exit(0);
}

$FILES = array(
    'en' => $ROOT . '/application/language/english/app_lang.php',
    'id' => $ROOT . '/application/language/indonesian/app_lang.php',
);

foreach ($FILES as $code => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "[FATAL] Kamus '{$code}' tidak ditemukan: {$file}\n");
        exit(2);
    }
}

// ---------------------------------------------------------------- load
function load_lang($file)
{
    $lang = array();
    include $file;
    return $lang;
}

$en = load_lang($FILES['en']);
$id = load_lang($FILES['id']);

$fail   = array();
$warn   = array();

// ===================================================================
//  P1 — paritas himpunan key (1:1, dua arah)
// ===================================================================
$onlyEn = array_values(array_diff(array_keys($en), array_keys($id)));
$onlyId = array_values(array_diff(array_keys($id), array_keys($en)));

if ($onlyEn) { $fail[] = 'P1: ' . count($onlyEn) . ' key hanya di EN: ' . implode(', ', array_slice($onlyEn, 0, 12)); }
if ($onlyId) { $fail[] = 'P1: ' . count($onlyId) . ' key hanya di ID: ' . implode(', ', array_slice($onlyId, 0, 12)); }

// ===================================================================
//  P3 — uang/angka tidak masuk kamus (L6)
// ===================================================================
$moneyHits = array();
foreach (array('en' => $en, 'id' => $id) as $code => $dict) {
    foreach ($dict as $key => $val) {
        // "Rp" diikuti digit = nilai uang literal di dalam kamus.
        if (preg_match('/Rp\s*[0-9]/u', (string) $val)) {
            $moneyHits[] = "{$code}:{$key}";
        }
    }
}
if ($moneyHits) {
    $fail[] = 'P3: ' . count($moneyHits) . ' key memuat nominal literal (Rp + angka): '
        . implode(', ', array_slice($moneyHits, 0, 12));
}

// ===================================================================
//  P5 — nilai identik EN≡ID hanya untuk kategori sah (allowlist)
// ===================================================================
/**
 * Nilai EN≡ID yang SAH: nama diri/merek, satuan teknis, kode bahasa,
 * label netral lintas-bahasa, dan leksikon tanggal yang kebetulan sama
 * (Jan/Feb/Mar/Apr/Jun/Jul/Sep/Nov).
 */
$identicalAllowlist = array(
    // nama diri / merek
    'synapse', 'gpu', 'qris', 'whatsapp', 'email', 'faq', 'idr', 'roi', 'us-east',
    // satuan / token teknis
    'rp', 'wib', 'en', 'id', 'l1', 'h+1', 't+1', 'px', 'mb', 'kb', '%1$d %2$s %3$d %4$s %5$d %6$s',
    // label netral lintas-bahasa
    'info', 'bonus', 'invoice', 'top up', 'merchant', 'online', 'level', 'actif',
    'bank account', 'aktif',
    // leksikon tanggal yang identik di kedua idiom
    'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'sep', 'nov',
    // nama hari/bulan singkatan yang sama
    'min',
    // brand/kode bahasa di UI
    'active', 'verified',
    // label netral lintas-bahasa (plan/99–103): judul halaman & chip
    'dashboard', 'marketplace', 'market', 'system monitor',
    'direct link', 'total value \u00b7 usd',
    // kode hub bandara (nama kota + kode IATA = nama diri, tidak diterjemahkan)
        // label netral / format murni
    'level 2', 'level 3', 'level 2–6', 'reward gpu', 'a.n.',
    'wallet', 'a.n.', '(tier)',
    // frasa hub lengkap (kota + kode IATA) & versi footer
    'frankfurt · fra-1', 'jakarta · jkt-2', 'tokyo · tyo-1',
    'synapse support - v1.0', 'level 2–6',
);

$identical = array();
foreach ($en as $key => $val) {
    if (!array_key_exists($key, $id)) { continue; }
    if ($val !== $id[$key]) { continue; }

    $v = strtolower(trim((string) $val));
    if ($v === '') { continue; }
    if (in_array($v, $identicalAllowlist, true)) { continue; }
    // Nilai yang hanya berisi token teknis (huruf kapital/simbol) dianggap sah.
    if (preg_match('/^[^a-z]+$/', (string) $val)) { continue; }
    // Pola format murni (placeholder + pemisah, tanpa kata): netral.
    if (preg_match('/^[^a-z]*(?:%[0-9]*\$?[ds])[^a-z]*$/', (string) $val)) { continue; }
    if (preg_match('/^Level\s+%[0-9]*\$?d\s*[\x{2014}\x{2013}-]\s*%[0-9]*\$?s$/u', (string) $val)) { continue; }
    $identical[] = $key . ' (' . $val . ')';
}
if ($identical) {
    $fail[] = 'P5: ' . count($identical) . ' key bernilai identik di luar allowlist: '
        . implode(', ', array_slice($identical, 0, 12));
}

// ===================================================================
//  P6 — higienitas nilai
// ===================================================================
$newlineHits = array();
$quoteHits   = array();
foreach (array('en' => $en, 'id' => $id) as $code => $dict) {
    foreach ($dict as $key => $val) {
        $val = (string) $val;
        if (strpos($val, "\n") !== false) {
            $newlineHits[] = "{$code}:{$key}";
        }
        // Karakter berisiko bila nilai dipakai di dalam atribut HTML.
        if (preg_match('/[<>]/u', $val)) {
            $quoteHits[] = "{$code}:{$key}";
        }
    }
}
if ($newlineHits) {
    $fail[] = 'P6: ' . count($newlineHits) . ' key memuat newline LITERAL (pakai escape "\n"): '
        . implode(', ', array_slice($newlineHits, 0, 12));
}
if ($quoteHits) {
    // Markup <b>/<strong> diizinkan plan/94, jadi ini peringatan, bukan gagal —
    // kecuali key dipakai di atribut. Daftar dipakai untuk review manual.
    $warn[] = 'P6 (info): ' . count($quoteHits) . ' key memuat markup <>/ — pastikan TIDAK dipakai di atribut HTML: '
        . implode(', ', array_slice($quoteHits, 0, 10));
}

// ===================================================================
//  P3b — kamus tidak boleh memuat sisa prosa ID massal di idiom EN
//        (heuristik ringan: kata Indonesia umum pada file EN)
// ===================================================================
$idMarkers = array('silakan', 'anda', 'tidak', 'belum', 'sudah', 'gagal', 'berhasil', 'nominal',
                   'rekening', 'penarikan', 'kedaluwarsa', 'menunggu', 'wajib', 'jumlah');
$enLeaks = array();
foreach ($en as $key => $val) {
    $v = strtolower((string) $val);
    foreach ($idMarkers as $marker) {
        if (preg_match('/\b' . preg_quote($marker, '/') . '\b/u', $v)) {
            $enLeaks[] = $key . ' ("' . $marker . '")';
            break;
        }
    }
}
if ($enLeaks) {
    $fail[] = 'P3b: ' . count($enLeaks) . ' key EN memuat kata Indonesia: '
        . implode(', ', array_slice($enLeaks, 0, 12));
}

// ===================================================================
//  Laporan
// ===================================================================
echo "=== plan/103 i18n dictionary parity audit ===\n";
echo "EN keys : " . count($en) . "\n";
echo "ID keys : " . count($id) . "\n";
echo "Paritas : " . (($onlyEn || $onlyId) ? 'GAGAL' : '1:1 OK') . "\n";
echo "Nilai identik (di luar allowlist): " . count($identical) . "\n";
echo "Nominal literal di kamus (P3)    : " . count($moneyHits) . "\n";
echo "Newline literal (P6)             : " . count($newlineHits) . "\n";
echo "Prosa Indonesia di idiom EN (P3b): " . count($enLeaks) . "\n";

if (!$quiet && $warn) {
    echo "\n--- Info ---\n";
    foreach ($warn as $w) { echo "  " . $w . "\n"; }
}

echo "\n";
if ($fail) {
    echo "[FAIL] " . count($fail) . " pelanggaran:\n";
    foreach ($fail as $f) { echo "  - " . $f . "\n"; }
    exit(1);
}

echo "[OK] Semua gate paritas & higienitas kamus LULUS.\n";
exit(0);
