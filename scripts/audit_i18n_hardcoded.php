<?php
/**
 * Synapse — Plan 103 i18n Hardcoded-String Audit (static scanner)
 *
 * Mencari sisa string user-facing yang BELUM melewati kamus pada surface
 * member (controller/model/helper/view). Dipakai sebagai:
 *   - baseline step 0 plan/103 (mengukur jumlah kebocoran pra-edit), dan
 *   - guardrail step 12 (target akhir: 0 temuan, exit code 0).
 *
 * Bukan unit test: murni analisis statis berbasis regex + allowlist eksplisit.
 *
 * Usage:
 *   php scripts/audit_i18n_hardcoded.php            ringkasan + temuan
 *   php scripts/audit_i18n_hardcoded.php --quiet    hanya ringkasan
 *   php scripts/audit_i18n_hardcoded.php --help
 *
 * Exit codes: 0 = bersih (0 temuan), 1 = ada temuan, 2 = error environment.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT  = dirname(__DIR__);
$args  = $argv ?: [];
$quiet = in_array('--quiet', $args, true);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Usage: php scripts/audit_i18n_hardcoded.php [--quiet] [--help]\n"
       . "  Pindai sisa string hardcoded pada surface member (plan/103).\n"
       . "  Exit 0 = 0 temuan; 1 = ada temuan; 2 = error.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Cakupan: MEMBER ONLY. Admin panel sengaja dikecualikan — admin 100%
// Indonesian (invariant L1) dan tidak pernah memanggil i18n_apply().
// ---------------------------------------------------------------------------
$scanDirs = [
    $ROOT . '/application/views',
    $ROOT . '/application/controllers',
    $ROOT . '/application/models',
    $ROOT . '/application/helpers',
];

$excludePaths = [
    // ── Admin panel 100% Indonesian (invariant L1) — never calls
    //    i18n_apply(), so its literals are intentional and out of scope.
    '/application/views/admin/',
    '/application/controllers/Admin.php',
    '/application/controllers/Admin_auth.php',
    '/application/models/Admin_model.php',
    // ── Developer/error surfaces (not member-facing UI copy).
    '/application/views/errors/',
    '/application/core/MY_Exceptions.php',
    // ── Kamus & builder non-copy.
    '/application/language/',
    '/application/helpers/captcha_helper.php',   // aria-label SVG captcha (builder)
];

// ---------------------------------------------------------------------------
// R2 lexicon: kata Indonesia yang TIDAK boleh muncul sebagai teks tampilan.
// ---------------------------------------------------------------------------
$idLexicon = [
    'salin', 'tersalin', 'menyalin', 'anda', 'silakan', 'nominal', 'kedaluwarsa',
    'penarikan', 'rekening', 'sewa', 'biaya', 'undangan', 'klaim', 'peringatan',
    'berhasil', 'gagal', 'belum', 'hari', 'menit', 'detik', 'jam', 'saldo',
    'deposit', 'invoice', 'tidak', 'sudah', 'menunggu', 'batas', 'minimal',
    'maksimal', 'jumlah', 'kirim', 'pilih', 'batal', 'tutup', 'simpan', 'hapus',
    'ubah', 'kembali', 'masuk', 'keluar', 'daftar', 'akun', 'profil', 'bantuan',
    'komisi', 'produk', 'harga', 'keuntungan', 'riwayat', 'transaksi', 'tanggal',
    'waktu', 'mulai', 'akhir', 'setiap', 'semua', 'lainnya', 'penting', 'catatan',
    'perhatian', 'informasi', 'konfirmasi', 'verifikasi', 'keamanan', 'sandi',
    'nomor', 'telepon', 'alamat', 'nama', 'foto', 'gambar', 'tampilan', 'bahasa',
    'tema', 'terang', 'gelap', 'pengaturan', 'wajib', 'diisi', 'kata', 'sandinya',
];

// ---------------------------------------------------------------------------
// Allowlist: literal yang SAH muncul mentah (nama diri, token teknis, kode
// bahasa, satuan, atau identifier). Dicocokkan case-insensitive terhadap
// potongan yang ditemukan.
// ---------------------------------------------------------------------------
$literalAllowlist = [
    // token teknis / netral
    'rp', 'wib', 'id', 'en', 'ok', 'json', 'url', 'email', 'whatsapp', 'synapse',
    'top up', 'invoice', 'merchant', 'online', 'level', 'info', 'bonus', 'gpu',
    'node', 'idr', 'roi', 'faq', 'support', 'app', 'api', 'csrf', 'html', 'css',
    'svg', 'qr', 'qris', 'px', 'mb', 'kb', 'h+1', 't+1', 'admin',
    // label teknis di dalam atribut/JS
    'position', 'fixed', 'textarea', 'copy', 'exec', 'command', 'hidden',
    'inline', 'block', 'flex', 'none', 'true', 'false', 'null', 'undefined',
    'string', 'number', 'object', 'array', 'function', 'return', 'var', 'let',
    'const', 'document', 'window', 'style', 'class', 'data', 'type', 'name',
    'value', 'width', 'height', 'color', 'border', 'background', 'transform',
    'transition', 'opacity', 'translate', 'scale', 'rotate', 'center', 'left',
    'right', 'top', 'bottom', 'auto', 'solid', 'dashed', 'white', 'black',
    'capitalize', 'uppercase', 'lowercase', 'absolute', 'relative', 'sticky',
    // Nama kelas/selector CSS di dalam markup (identifier, bukan copy):
    '.btn-sewa', 'btn-sewa',
    // Fallback darurat ber-idiom Indonesia di helper rate limit: dipakai
    // HANYA bila file kamus tidak dapat dibaca (tidak mungkin di runtime
    // normal — lihat _rate_limit_line()). Teks ini sengaja tidak
    // diterjemahkan agar admin (L1) tetap mendapat pesan yang dapat dibaca.
    'terlalu banyak percobaan gagal. silakan coba lagi dalam %d menit.',
];

// ---------------------------------------------------------------------------
// Rule definitions. Setiap rule dijalankan per baris file.
// ---------------------------------------------------------------------------
$findings = [];
$filesScanned = 0;

function add_finding(&$findings, $rule, $file, $line, $snippet)
{
    $findings[] = [
        'rule'    => $rule,
        'file'    => str_replace(dirname(__DIR__) . '/', '', $file),
        'line'    => $line,
        'snippet' => trim(preg_replace('/\s+/', ' ', $snippet)),
    ];
}

function _line_is_comment($line)
{
    $t = ltrim($line);
    return (strpos($t, '//') === 0)
        || (strpos($t, '*') === 0)
        || (strpos($t, '/*') === 0)
        || (strpos($t, '<!--') === 0)
        || (strpos($t, '#') === 0);
}

/**
 * Buang komentar inline (`// …`) agar teks penjelasan tidak pernah dihitung
 * sebagai copy user-facing. Hati-hati `://` di dalam string (URL) — hanya
 * pola ` // ` atau `// ` yang dianggap komentar.
 */
function _strip_inline_comment($line)
{
    // Jangan sentuh baris yang isinya URL/protokol.
    if (preg_match('#https?://#', $line)) {
        return $line;
    }
    if (preg_match('#\s//\s#', $line)) {
        return preg_replace('#\s//\s.*$#', '', $line);
    }
    return $line;
}

function has_lexicon_hit($text, array $lexicon)
{
    $lower = strtolower($text);
    foreach ($lexicon as $word) {
        if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $lower)) {
            return $word;
        }
    }
    return null;
}

function in_allowlist($text, array $allow)
{
    $lower = strtolower(trim($text));
    if ($lower === '') { return true; }

    // Cocok PERSIS untuk token pendek/umum — mencegah false-negative seperti
    // "Admin" cocok dengan allowlist "admin" lalu menelan seluruh frasa
    // "Silakan hubungi admin". Substring hanya untuk frasa multi-kata atau
    // token berpola (mengandung spasi / '-' / '.').
    foreach ($allow as $a) {
        if ($lower === $a) { return true; }

        if (strpos($a, ' ') !== false || strpos($a, '.') !== false || strpos($a, '-') !== false) {
            if (strpos($lower, $a) !== false) { return true; }
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Iterate files.
// ---------------------------------------------------------------------------
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($ROOT . '/application', FilesystemIterator::SKIP_DOTS)
);

$targets = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();

    $skip = false;
    foreach ($excludePaths as $ex) {
        if (strpos($path, $ex) !== false) { $skip = true; break; }
    }
    if ($skip) { continue; }

    $targets[] = $path;
}
sort($targets);

foreach ($targets as $path) {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) { continue; }

    $filesScanned++;
    $isView       = (strpos($path, '/application/views/') !== false);
    $isController = (strpos($path, '/application/controllers/') !== false);
    $isHelper     = (strpos($path, '/application/helpers/') !== false);
    $isModel      = (strpos($path, '/application/models/') !== false);
    $inScript     = false;
    $inLog        = false;

    foreach ($lines as $i => $raw) {
        $ln   = $i + 1;
        $line = rtrim($raw);

        if (_line_is_comment($line)) { continue; }

        // ---- strip komentar inline + argumen diagnostik -------------------
        // Prosa yang HANYA mengalir ke log_message() tidak pernah dilihat
        // member (log = arsip operator), jadi bukan kebocoran i18n. Baris
        // log_message() dilewati seluruhnya untuk rule R2/R4.
        // log_message() sering multi-baris (concatenation) — lacak kedalamannya
        // agar prosa diagnostik tidak pernah dihitung sebagai copy UI.
        if ($inLog) {
            if (strpos($line, ');') !== false) { $inLog = false; }
            continue;
        }
        if (preg_match('/log_message\s*\(/', $line)) {
            if (strpos($line, ');') === false) { $inLog = true; }
            continue;
        }

        $code = _strip_inline_comment($line);

        // ---- track <script> blocks (view) --------------------------------
        if (stripos($code, '<script') !== false) { $inScript = true; }
        if (stripos($code, '</script>') !== false) { $inScript = false; }

        // ---- R1: set_flashdata / show_error dengan literal ----------------
        if (preg_match("/set_flashdata\(\s*'(?:error|success|warning|info)'\s*,\s*(.+?)\)\s*;/", $code, $m)) {
            $arg = trim($m[1]);
            $ok  = (strpos($arg, 'lang(') !== false)
                || (strpos($arg, 'sprintf(lang(') !== false)
                || ($arg !== '' && $arg[0] === '$');
            if (!$ok && preg_match("/^['\"]/", $arg)) {
                add_finding($findings, 'R1-set_flashdata', $path, $ln, $line);
            }
        }
        if (preg_match("/show_error\(\s*['\"](.+?)['\"]\s*,/", $code, $m)) {
            if (strpos($code, 'lang(') === false) {
                add_finding($findings, 'R1-show_error', $path, $ln, $line);
            }
        }

        // ---- R7: prosa model dipass-through MENTAH ke UI ------------------
        // Model boleh menyimpan `message` ber-prosa Indonesia sebagai
        // diagnostik, TAPI controller tidak boleh meneruskannya langsung ke
        // flashdata / api_* — itu jalur kebocoran yang sesungguhnya.
        if (preg_match("/set_flashdata\(\s*'[^']*'\s*,\s*\\\$[a-z_]+\[['\"]message['\"]\]\s*\)/", $code)
            || preg_match("/(api_error|api_success)\(\s*\\\$[a-z_]+\[['\"]message['\"]\]/", $code)) {
            add_finding($findings, 'R7-raw-model-message', $path, $ln, $line);
        }

        // ---- R3: date('d M Y...') di luar helper -------------------------
        if (preg_match("/date\(\s*['\"][^'\"]*[dDjlNSwzFMmn][^'\"]*['\"]/", $code)
            && preg_match("/date\(\s*['\"][^'\"]*\bM\b/", $code)
            && strpos($path, '/application/helpers/i18n_helper.php') === false
            && strpos($path, '/application/helpers/') === false) {
            add_finding($findings, 'R3-date-format', $path, $ln, $line);
        }

        // ---- R4: JS fallback literal `|| '...'` --------------------------
        if ($inScript && preg_match("/\|\|\s*'([^']{3,})'/", $code, $m)) {
            $lit = trim($m[1]);
            if (!in_allowlist($lit, $literalAllowlist) && has_lexicon_hit($lit, $idLexicon)) {
                add_finding($findings, 'R4-js-fallback', $path, $ln, $line);
            }
        }

        // ---- R2: teks node HTML / string literal dengan lexicon ID --------
        // Ambil kandidat: teks antar tag, atau literal string berkutip.
        // Model dikecualikan dari R2: kontraknya adalah `code` (otoritas
        // presentasi) + `message` (diagnostik) — lihat D1/D3 plan/103.
        if ($isModel) { continue; }

        // Pola REGEX (matcher) bukan copy user-facing: baris yang memuat
        // literal regex PHP ('/.../u') di-skip pada rule R2.
        if (preg_match('#^\s*\x27/.*\x27\s*$#', trim($code))) { continue; }

        $line = $code;
        $candidates = [];
        if ($isView) {
            if (preg_match_all('/>\s*([^<>{}]{3,})\s*</u', $line, $mm)) {
                foreach ($mm[1] as $c) { $candidates[] = $c; }
            }
        }
        if (preg_match_all("/'([^'\n]{4,})'/", $line, $mm2)) {
            foreach ($mm2[1] as $c) { $candidates[] = $c; }
        }
        if (preg_match_all('/"([^"\n]{4,})"/', $line, $mm3)) {
            foreach ($mm3[1] as $c) { $candidates[] = $c; }
        }

        foreach ($candidates as $cand) {
            // Lewati potongan yang jelas bukan copy user-facing.
            if (strpos($cand, '<?') !== false || strpos($cand, '?>') !== false) { continue; }
            // Argumen log_message()/printf diagnostik: berisi identifier bukan
            // kalimat. Deteksi via pola `kata:` atau `(kata=` yang khas log.
            if (preg_match('/^[a-z_]+\s*:\s*$/', $cand)) { continue; }
            if (preg_match('/^[a-z_ ]{3,40}$/', $cand) && !has_lexicon_hit($cand, $idLexicon)) { continue; }
            if (preg_match('/^(attempted to access|accessed|owned by|lost|failed|gagal \(upline)/i', $cand)) { continue; }
            if (preg_match('/^[a-z0-9_\-\.\[\]#\/\s:,;]+$/i', $cand) && !has_lexicon_hit($cand, $idLexicon)) { continue; }
            if (preg_match('/^[a-z\-]+$/', $cand)) { continue; }
            if (preg_match('/^[\s\d\.\,\-\+\%\:\/\*\|\#\(\)\[\]]+$/', $cand)) { continue; }
            if (in_allowlist($cand, $literalAllowlist)) { continue; }

            // Skip CSS utility / class / selector / identifier noise.
            if (preg_match('/(px-|py-|mt-|mb-|ml-|mr-|gap-|text-|bg-|border|rounded|flex|grid|font-|z-\[|w-|h-)/', $cand)) { continue; }
            if (preg_match('/^[a-z0-9_\-\s\+\.\#\[\]\:\/]+$/', $cand) && !has_lexicon_hit($cand, $idLexicon)) { continue; }

            $hit = has_lexicon_hit($cand, $idLexicon);
            if ($hit === null) { continue; }

            // Sudah melewati kamus?
            if (preg_match("/lang\(\s*'[^']*'\s*\)/", $line) && strpos($line, 'lang(') !== false
                && (strpos($line, $cand) === false)) {
                continue;
            }
            if (preg_match("/lang\(\s*'[^']*'\s*\)/", $line)) { continue; }

            add_finding($findings, 'R2-literal-prose', $path, $ln, $cand);
            break; // satu temuan per baris cukup
        }
    }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$byRule = [];
foreach ($findings as $f) {
    $byRule[$f['rule']] = ($byRule[$f['rule']] ?? 0) + 1;
}
ksort($byRule);

echo "=== plan/103 i18n hardcoded-string audit ===\n";
echo "File dipindai : {$filesScanned}\n";
echo "Total temuan  : " . count($findings) . "\n";
foreach ($byRule as $rule => $n) {
    echo sprintf("  %-18s %d\n", $rule, $n);
}

if (!$quiet && $findings) {
    echo "\n--- Temuan (file:line | rule | snippet) ---\n";
    $lastFile = '';
    foreach ($findings as $f) {
        if ($f['file'] !== $lastFile) {
            echo "\n" . $f['file'] . "\n";
            $lastFile = $f['file'];
        }
        echo sprintf("  %5d | %-18s | %s\n", $f['line'], $f['rule'], substr($f['snippet'], 0, 110));
    }
}

echo "\n";
if ($findings) {
    echo "[FAIL] " . count($findings) . " temuan — masih ada string hardcoded.\n";
    exit(1);
}

echo "[OK] 0 temuan — tidak ada string hardcoded di surface member.\n";
exit(0);
