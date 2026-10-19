<?php
/**
 * Synapse (db_webtable) — Plan 119 Team Milestone Bonus Migration CLI
 *
 * One-off operator tool: menyediakan state + konfigurasi BONUS PENCAPAIAN TIM
 * (pengganti Gaji Mingguan):
 *
 *   1. SATU KOLOM `users.team_milestone_claimed` VARCHAR(255) NULL DEFAULT NULL
 *      (CSV target integer kanonik menaik; NULL/'' = belum ada klaim);
 *   2. DUA KEY `system_settings`:
 *        `team_milestone_enabled` ('1'|'0' — gerbang fail-closed)
 *        `team_milestone_tiers`   (JSON `[[target, nominal], …]` kanonik menaik);
 *   3. BACKFILL GRANDFATHERING (keputusan owner O3): user yang historisnya sudah
 *      menerima Gaji Mingguan ditandai SUDAH mengklaim tier yang setara dengan
 *      pencapaiannya. Murni state — TIDAK menulis `wallet_ledger`, TIDAK
 *      memindahkan saldo, TIDAK membuat notifikasi.
 *
 *   FASE
 *   1. PRE-FLIGHT : tabel `users`/`system_settings`/`wallet_ledger` ada; kolom
 *                   anchor `users.last_wage_claimed_at` ada (dipakai AFTER);
 *                   koneksi mysqli TCP siap.
 *   2. INSPECT    : keberadaan kolom + 2 key; jumlah user; jumlah baris
 *                   `WAGE-%` (bahan backfill).
 *   3. DDL        : ADD COLUMN dijaga information_schema (idempoten; MySQL 8
 *                   tidak punya ADD COLUMN IF NOT EXISTS). TANPA backfill DDL.
 *   4. SEED       : 2 key (idempoten; nilai yang sudah ada TIDAK ditimpa).
 *   5. BACKFILL   : grandfathering O3 (dilewati bila `--skip-backfill`).
 *   6. VERIFY     : kolom (tipe/nullability) + 2 key + JSON parseable & valid +
 *                   seluruh nilai `team_milestone_claimed` kanonik → tamper
 *                   = exit 2.
 *
 *   --dry-run        (default) inspeksi + rencana, TIDAK menulis apa pun
 *   --apply          DDL + seed + backfill + verifikasi
 *   --verify         hanya verifikasi (read-only); exit 2 bila tidak sesuai
 *   --skip-backfill  lewati fase 5 (tanpa grandfathering)
 *   --help|-h        usage
 *
 * Safety:
 * - Idempotent: re-run = no-op ("sudah ada (dibiarkan)" / "0 baris").
 * - TIDAK PERNAH menulis `wallet_ledger` (nol INSERT/UPDATE/DELETE), tidak
 *   menyentuh `users.balance`/`checkin_balance`, `withdrawals`, `user_rentals`.
 *   Seluruh baris ledger historis `WAGE-*` dipertahankan apa adanya (Z1/D10).
 * - Backfill hanya menulis kolom state dan HANYA pada baris yang masih NULL
 *   (guard `IS NULL`) sehingga klaim baru pasca-deploy tidak pernah tertimpa.
 * - Kredensial dibaca dari application/config/database.php memakai semantik CI3
 *   ($active_group → 'default' → grup pertama), pola scripts/migrate_106/112/114/117/118.
 *   Override lokal: DB_HOSTNAME/DB_USERNAME/DB_PASSWORD/DB_DATABASE.
 *
 * ⚠️ URUTAN RILIS: jalankan migrasi ini SEBELUM deploy kode plan/119 —
 *    `User_model::get_milestone_config()` membaca 2 key `system_settings`, dan
 *    `User_model::get_milestone_status()`/`claim_milestone()` membaca kolom
 *    `users.team_milestone_claimed` (/team, /admin/settings).
 *    Jalankan `--apply` SEGERA setelah deploy: fase backfill memakai
 *    konfigurasi tier SAAT ITU (seed default = mirror tier gaji lama).
 *
 * Exit codes: 0 = bersih/no-op, 1 = pre-flight gagal, 2 = apply/verify gagal.
 */

define('BASEPATH', 'cli-migrate-119-runner');
define('ENVIRONMENT', 'development');

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Jakarta');   // WIB — otoritas waktu aplikasi (M2)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ROOT = dirname(__DIR__);

$args         = $argv ?: [];
$apply        = in_array('--apply', $args, true);
$verifyOnly   = in_array('--verify', $args, true);
$skipBackfill = in_array('--skip-backfill', $args, true);
$dryRun       = ( ! $apply && ! $verifyOnly);

if (in_array('--help', $args, true) || in_array('-h', $args, true))
{
    echo "Usage: php scripts/migrate_119_team_milestone.php [--dry-run|--apply|--verify] [--skip-backfill] [--help]\n"
       . "  --dry-run        (default) inspeksi kolom/key + cetak rencana, tanpa menulis\n"
       . "  --apply          tambah kolom `users.team_milestone_claimed`, seed 2 key\n"
       . "                   `system_settings`, jalankan backfill grandfathering, verifikasi\n"
       . "  --verify         hanya verifikasi (read-only); exit 2 bila tidak sesuai\n"
       . "  --skip-backfill  lewati fase grandfathering (O3): seluruh user mulai tanpa klaim\n"
       . "  Catatan: tool tidak pernah menulis wallet_ledger / saldo / withdrawals / user_rentals.\n";
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

// ------------------------------------------------- helper kanonik (choke-point)
require_once $ROOT . '/application/helpers/team_milestone_helper.php';

if ( ! function_exists('team_milestone_tiers_parse'))
{
    fwrite(STDERR, "[FATAL] Helper team_milestone_helper.php tidak dapat dimuat\n");
    exit(1);
}

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
$TABLE_U  = 'users';
$COL_TM   = 'team_milestone_claimed';         // VARCHAR(255) NULL DEFAULT NULL
$ANCHOR_U = 'last_wage_claimed_at';           // AFTER klausa (pre-flight memastikan ada)

$TABLE_S  = 'system_settings';
$KEY_EN   = 'team_milestone_enabled';
$KEY_TI   = 'team_milestone_tiers';

$DEFAULT_TIERS_JSON = '[[9,200000],[30,1000000],[70,2500000],[130,5000000],[190,9000000]]';
$LIM = team_milestone_limits();

echo "== Plan 119 — Bonus Pencapaian Tim (team_milestone_claimed + 2 key + backfill) ==\n";
echo "Server: {$m->server_info}  DB: {$DB}  Mode: " . ($dryRun ? 'DRY-RUN' : ($verifyOnly ? 'VERIFY' : 'APPLY'))
   . ($skipBackfill ? '  (backfill DILEWATI)' : '') . "\n\n";

// ---------------------------------------------------------------- helpers
/**
 * Metadata satu kolom (atau NULL bila tidak ada).
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

/** Nilai satu key system_settings (NULL bila tidak ada). */
function setting_value(mysqli $m, $key)
{
    $stmt = $m->prepare("SELECT key_value FROM system_settings WHERE key_name = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (string) $row['key_value'] : NULL;
}

/** COUNT(*) dari query COUNT(*) AS c (0 bila query gagal). */
function count_scalar(mysqli $m, $sql)
{
    $res = $m->query($sql);
    if ( ! $res) return 0;
    $row = $res->fetch_assoc();
    return (int) ($row['c'] ?? 0);
}

// =====================================================================
//  FASE 1 — PRE-FLIGHT
// =====================================================================
echo "-- FASE 1: PRE-FLIGHT --\n";

$preflightOk = TRUE;
foreach (array($TABLE_U, $TABLE_S, 'wallet_ledger') as $t)
{
    if ( ! table_exists($m, $DB, $t))
    {
        $preflightOk = FALSE;
        echo "   [X] tabel `{$t}` TIDAK ADA\n";
    }
}

$anchorInfo = column_info($m, $DB, $TABLE_U, $ANCHOR_U);
if ($anchorInfo === NULL)
{
    $preflightOk = FALSE;
    echo "   [X] kolom anchor `{$TABLE_U}.{$ANCHOR_U}` tidak ada (dipakai klausa AFTER)\n";
}

if ( ! $preflightOk)
{
    fwrite(STDERR, "[FATAL] Pre-flight gagal — tidak ada perubahan dilakukan.\n");
    exit(1);
}

echo "   [v] tabel users/system_settings/wallet_ledger ada\n";
echo "   [v] kolom anchor `{$TABLE_U}.{$ANCHOR_U}` ada\n\n";

// =====================================================================
//  FASE 2 — INSPECT
// =====================================================================
echo "-- FASE 2: INSPECT --\n";

$colTm       = column_info($m, $DB, $TABLE_U, $COL_TM);
$valEnabled  = setting_value($m, $KEY_EN);
$valTiers    = setting_value($m, $KEY_TI);
$usersTotal  = count_scalar($m, "SELECT COUNT(*) c FROM `{$TABLE_U}`");
$wageRows    = count_scalar($m, "SELECT COUNT(*) c FROM wallet_ledger WHERE `type` = 'credit' AND transaction_id LIKE 'WAGE-%'");
$wageUsers   = count_scalar($m, "SELECT COUNT(DISTINCT user_id) c FROM wallet_ledger WHERE `type` = 'credit' AND transaction_id LIKE 'WAGE-%'");

echo "   kolom `{$COL_TM}`          : " . ($colTm ? "ADA ({$colTm['column_type']}, nullable={$colTm['nullable']})" : 'BELUM ADA') . "\n";
echo "   key `{$KEY_EN}`    : " . ($valEnabled === NULL ? 'BELUM ADA' : "'{$valEnabled}'") . "\n";
echo "   key `{$KEY_TI}`      : " . ($valTiers === NULL ? 'BELUM ADA' : substr($valTiers, 0, 72) . (strlen($valTiers) > 72 ? '…' : '')) . "\n";
echo "   jumlah user                 : {$usersTotal}\n";
echo "   baris ledger WAGE-%         : {$wageRows} (pemilik unik: {$wageUsers})\n\n";

// =====================================================================
//  FASE 3 — DDL
// =====================================================================
echo "-- FASE 3: DDL (`{$TABLE_U}.{$COL_TM}`) --\n";

if ($dryRun)
{
    echo $colTm
        ? "   [=] kolom sudah ada → no-op\n"
        : "   [>] AKAN: ALTER TABLE `{$TABLE_U}` ADD COLUMN `{$COL_TM}` VARCHAR(255) NULL DEFAULT NULL AFTER `{$ANCHOR_U}`\n";
}
elseif ($verifyOnly)
{
    echo "   [=] mode verify — DDL tidak dijalankan\n";
}
elseif ($colTm)
{
    echo "   [=] kolom sudah ada (dibiarkan) — " . $colTm['column_type'] . "\n";
}
else
{
    $m->query("ALTER TABLE `{$TABLE_U}` ADD COLUMN `{$COL_TM}` VARCHAR(255) NULL DEFAULT NULL AFTER `{$ANCHOR_U}`");
    echo "   [+] kolom `{$COL_TM}` ditambahkan\n";
}
echo "\n";

// =====================================================================
//  FASE 4 — SEED
// =====================================================================
echo "-- FASE 4: SEED (2 key system_settings) --\n";

// Nilai tier kanonik (parse → validasi → encode) dengan helper — satu sumber.
$seedTiers = team_milestone_tiers_parse($DEFAULT_TIERS_JSON);
if ($seedTiers === NULL || ! team_milestone_tiers_valid($seedTiers)['ok'])
{
    fwrite(STDERR, "[FATAL] nilai seed tier internal tidak valid — batalkan\n");
    exit(1);
}
$seedTiersJson = team_milestone_tiers_encode($seedTiers);

foreach (array($KEY_EN => '1', $KEY_TI => $seedTiersJson) as $k => $v)
{
    $cur = setting_value($m, $k);
    if ($cur !== NULL)
    {
        echo "   [=] `{$k}` sudah ada (dibiarkan): " . substr($cur, 0, 72) . (strlen($cur) > 72 ? '…' : '') . "\n";
        continue;
    }
    if ($dryRun || $verifyOnly)
    {
        echo "   [>] AKAN: INSERT `{$k}` = " . substr($v, 0, 72) . (strlen($v) > 72 ? '…' : '') . "\n";
        continue;
    }

    $stmt = $m->prepare("INSERT INTO system_settings (key_name, key_value) VALUES (?, ?)");
    $stmt->bind_param('ss', $k, $v);
    $stmt->execute();
    $stmt->close();
    echo "   [+] `{$k}` di-seed\n";
}
echo "\n";

// =====================================================================
//  FASE 5 — BACKFILL (GRANDFATHERING, keputusan owner O3)
// =====================================================================
$backfillPlan = array();

function build_backfill_plan(mysqli $m, array $tiers)
{
    // Pencapaian tertinggi per user: presisi dari prosa deskripsi
    // ("Gaji Mingguan Level N — M downline aktif (siklus …)"), proxy dari
    // nominal terbesar bila prosa tidak parseable (mis. baris warisan lama).
    $plan = array();

    $res = $m->query(
        "SELECT user_id, description, amount
           FROM wallet_ledger
          WHERE `type` = 'credit' AND transaction_id LIKE 'WAGE-%'
          ORDER BY user_id ASC, id ASC"
    );
    if ( ! $res) return $plan;

    $byUser = array();
    while ($row = $res->fetch_assoc())
    {
        $uid = (int) $row['user_id'];

        if ( ! isset($byUser[$uid]))
        {
            $byUser[$uid] = array('reached' => 0, 'max_amount' => 0);
        }

        if (preg_match('/—\s*(\d+)\s*downline aktif/u', (string) $row['description'], $mm))
        {
            $byUser[$uid]['reached'] = max($byUser[$uid]['reached'], (int) $mm[1]);
        }

        $byUser[$uid]['max_amount'] = max($byUser[$uid]['max_amount'], (int) $row['amount']);
    }

    foreach ($byUser as $uid => $agg)
    {
        // Presisi (prosa) → reached; proxy (nominal) melengkapi bila lebih tinggi.
        $reached = (int) $agg['reached'];
        foreach ($tiers as $tier)
        {
            if ((int) $tier['amount'] <= (int) $agg['max_amount'] && (int) $tier['target'] > $reached)
            {
                $reached = (int) $tier['target'];
            }
        }

        $targets = array();
        foreach ($tiers as $tier)
        {
            if ((int) $tier['target'] <= $reached)
            {
                $targets[] = (int) $tier['target'];
            }
        }

        if (count($targets) > 0)
        {
            $plan[$uid] = $targets;
        }
    }

    return $plan;
}

echo "-- FASE 5: BACKFILL GRANDFATHERING (O3) --\n";

if ($skipBackfill)
{
    echo "   [=] dilewati (--skip-backfill): seluruh user mulai tanpa klaim\n\n";
}
else
{
    $tierCfgRaw       = setting_value($m, $KEY_TI);
    $tiersForBackfill = ($tierCfgRaw === NULL) ? NULL : team_milestone_tiers_parse($tierCfgRaw);
    if ($tiersForBackfill === NULL)
    {
        $tiersForBackfill = $seedTiers;
        echo ($tierCfgRaw === NULL)
            ? "   [=] key tier belum ada — backfill memakai seed default (nilai seed)\n"
            : "   [!] konfigurasi tier tersimpan tidak valid — backfill memakai seed default\n";
    }
    elseif ( ! team_milestone_tiers_valid($tiersForBackfill)['ok'])
    {
        $tiersForBackfill = $seedTiers;
        echo "   [!] konfigurasi tier tersimpan di luar ambang — backfill memakai seed default\n";
    }

    $backfillPlan = build_backfill_plan($m, $tiersForBackfill);

    $dist = array();
    foreach ($backfillPlan as $uid => $targets)
    {
        $k = implode(',', $targets);
        $dist[$k] = ($dist[$k] ?? 0) + 1;
    }
    ksort($dist);

    echo "   user dengan histori WAGE-%  : " . $wageUsers . "\n";
    echo "   user yang akan ditandai     : " . count($backfillPlan) . "\n";
    if (count($dist) > 0)
    {
        echo "   distribusi (klaim ditandai → jumlah user):\n";
        foreach ($dist as $csv => $n)
        {
            echo "      [{$csv}] → {$n} user\n";
        }
    }

    if ($dryRun)
    {
        echo "   [>] AKAN: UPDATE users SET team_milestone_claimed = <csv>\n"
           . "       WHERE id = ? AND team_milestone_claimed IS NULL  (per user di atas)\n";
    }
    elseif ($verifyOnly)
    {
        echo "   [=] mode verify — backfill tidak dijalankan\n";
    }
    else
    {
        $affected = 0;
        $stmt = $m->prepare(
            "UPDATE `{$TABLE_U}` SET `{$COL_TM}` = ?
              WHERE id = ? AND `{$COL_TM}` IS NULL"
        );
        foreach ($backfillPlan as $uid => $targets)
        {
            $csv = team_milestone_claimed_encode($targets);
            if ($csv === '') continue;
            $uid = (int) $uid;
            $stmt->bind_param('si', $csv, $uid);
            $stmt->execute();
            $affected += $stmt->affected_rows;
        }
        $stmt->close();
        echo "   [+] {$affected} baris `{$COL_TM}` diisi (guard IS NULL → re-run = 0 baris)\n";
    }
}
echo "\n";

// =====================================================================
//  FASE 6 — VERIFY (tamper → exit 2)
// =====================================================================
echo "-- FASE 6: VERIFY --\n";

$failures = array();

// (a) kolom
$colTm = column_info($m, $DB, $TABLE_U, $COL_TM);
if ($colTm === NULL)
{
    $failures[] = "kolom `{$TABLE_U}.{$COL_TM}` tidak ada";
}
else
{
    if ($colTm['data_type'] !== 'varchar' || strpos($colTm['column_type'], 'varchar(255)') === FALSE)
    {
        $failures[] = "tipe kolom tidak sesuai: {$colTm['column_type']} (harap varchar(255))";
    }
    if ($colTm['nullable'] !== 'YES')
    {
        $failures[] = "kolom harus NULLABLE (sekarang {$colTm['nullable']})";
    }
    // MariaDB melaporkan DEFAULT NULL sebagai string 'NULL'; MySQL 8 sebagai
    // NULL betulan → keduanya sah (kolom tanpa DEFAULT eksplisit).
    if ($colTm['default'] !== NULL && strtoupper((string) $colTm['default']) !== 'NULL')
    {
        $failures[] = 'kolom tidak boleh punya DEFAULT (sekarang ' . var_export($colTm['default'], TRUE) . ')';
    }
}

// (b) toggle
$valEnabled = setting_value($m, $KEY_EN);
if ($valEnabled === NULL)
{
    $failures[] = "key `{$KEY_EN}` tidak ada";
}
elseif ($valEnabled !== '0' && $valEnabled !== '1')
{
    $failures[] = "nilai `{$KEY_EN}` tidak valid: '{$valEnabled}' (harap '0'/'1')";
}

// (c) tier JSON
$valTiers = setting_value($m, $KEY_TI);
$parsedTiers = NULL;
if ($valTiers === NULL)
{
    $failures[] = "key `{$KEY_TI}` tidak ada";
}
else
{
    $parsedTiers = team_milestone_tiers_parse($valTiers);
    if ($parsedTiers === NULL)
    {
        $failures[] = "nilai `{$KEY_TI}` tidak dapat diparse sebagai JSON [[target,nominal],…]";
    }
    else
    {
        $v = team_milestone_tiers_valid($parsedTiers);
        if ( ! $v['ok'])
        {
            $failures[] = "nilai `{$KEY_TI}` tidak valid (reason={$v['reason']}"
                . ($v['index'] !== NULL ? ", row={$v['index']}" : '') . ')';
        }
    }
}

// (d) seluruh state klaim kanonik
$badCsv = 0;
if ($colTm !== NULL)
{
    $res = $m->query("SELECT id, `{$COL_TM}` v FROM `{$TABLE_U}` WHERE `{$COL_TM}` IS NOT NULL AND `{$COL_TM}` <> ''");
    if ($res)
    {
        while ($row = $res->fetch_assoc())
        {
            if ( ! team_milestone_claimed_is_canonical($row['v']))
            {
                $badCsv++;
            }
        }
    }
}
if ($badCsv > 0)
{
    $failures[] = "{$badCsv} baris `{$COL_TM}` tidak kanonik (token non-integer/duplikat/tidak menaik)";
}

// (e) laporan informatif
$tableCount = count_scalar($m, "SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
$claimedRows = ($colTm !== NULL)
    ? count_scalar($m, "SELECT COUNT(*) c FROM `{$TABLE_U}` WHERE `{$COL_TM}` IS NOT NULL AND `{$COL_TM}` <> ''")
    : 0;

echo "   kolom        : " . ($colTm ? $colTm['column_type'] . ' nullable=' . $colTm['nullable'] : 'TIDAK ADA') . "\n";
echo "   enabled      : " . var_export($valEnabled, TRUE) . "\n";
echo "   tiers        : " . ($parsedTiers !== NULL ? count($parsedTiers) . ' tier' : 'TIDAK VALID') . "\n";
echo "   user terklaim: {$claimedRows} dari {$usersTotal} user\n";
echo "   total tabel  : {$tableCount} (inventory kanonik = 14; tidak ada tabel baru dari plan/119)\n";

echo "\n-- RINGKASAN --\n";
echo "   DDL      : " . ($colTm ? 'kolom ada' : 'BELUM ADA') . "\n";
echo "   Seed     : enabled=" . var_export($valEnabled, TRUE) . ', tiers=' . ($parsedTiers !== NULL ? count($parsedTiers) : 0) . " baris\n";
echo "   Backfill : " . ($skipBackfill ? 'DILEWATI (--skip-backfill)' : count($backfillPlan) . ' user direncanakan/ditandai') . "\n";

if (count($failures) === 0)
{
    echo "\n[OK] seluruh invarian plan/119 terpenuhi\n[SELESAI] exit 0\n";
    exit(0);
}

if ($dryRun)
{
    // DRY-RUN = rencana saja (tidak menulis) → temuan dilaporkan sebagai
    // "akan dibereskan oleh --apply", bukan kegagalan (exit tetap 0).
    echo "\n   [>] AKAN dibereskan oleh --apply:\n";
    foreach ($failures as $f)
    {
        echo "      - {$f}\n";
    }
    echo "\n[DRY-RUN] rencana siap — jalankan --apply untuk menerapkan (exit 0)\n";
    exit(0);
}

echo "\n   [TAMPER] temuan:\n";
foreach ($failures as $f)
{
    echo "      - {$f}\n";
}
fwrite(STDERR, "\n[GAGAL] " . count($failures) . " invarian tidak terpenuhi (exit 2)\n");
exit(2);
