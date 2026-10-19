<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// =====================================================================
// Plan 121 — LUCKY SPIN WHEEL: CHOKE-POINT BENTUK DATA & RANDOMISASI
// =====================================================================
// SATU sumber kebenaran untuk BENTUK konfigurasi segmen roda, bobotnya, dan
// pemilihan segmen berbobot (weighted pick). Semua fungsi di sini MURNI (tanpa
// DB, tanpa I/O) dan `function_exists()`-guarded supaya aman di-include dari
// CLI (`scripts/migrate_121_lucky_spin.php`) maupun dari probe pengujian.
//
// Aturan lengkap (AGENTS.md "Helper-first rule"): kontroler admin, model
// (`Spin_model`), view member, dan CLI WAJIB memakai helper ini — dilarang
// menyalin ulang parsing/encoding/randomisasi di tempat lain.
//
// ⚠️ INVARIAN GATE i18n: file ini TIDAK BOLEH memuat prosa user-facing
//    (pesan berbahasa Indonesia hidup di MODEL — `Spin_model` dikecualikan gate
//    `audit_i18n_hardcoded.php` R2, helper TIDAK). Semua "alasan" di sini
//    adalah token teknis `snake_case`.
//
// Bentuk kanonik:
//   segmen : [[nominal_idr >= 1, bobot >= 1], …]   (urutan = urutan roda)
//   state  : JSON string '[[5000,30],[10000,25]]'   (kanonik, tanpa spasi)
//   ledger : transaction_id 'SPIN-{spin_results.id}'
//
// Invarian (plan/121 §2): SP4 (randomisasi di server), SP5 (CSPRNG + fungsi
// murni yang dapat diuji), SP8 (integer IDR utuh, tanpa float).

if ( ! function_exists('spin_wheel_limits'))
{
    /**
     * Ambang administratif (SATU sumber: validator admin + CLI `--verify`).
     *
     * `max_segments` = 12 karena roda dirender dengan 12 kelas warna
     * predefined di view member (nol inline style berisi warna dinamis).
     *
     * @return array{min_segments:int,max_segments:int,max_amount:int,
     *               max_weight:int,max_total_weight:int}
     */
    function spin_wheel_limits()
    {
        return array(
            'min_segments'     => 2,
            'max_segments'     => 12,
            'max_amount'       => 100000000,
            'max_weight'       => 1000000,
            'max_total_weight' => 10000000,
        );
    }
}

if ( ! function_exists('spin_wheel_int_token'))
{
    /**
     * Token integer ketat (M8): angka bulat non-negatif tanpa tanda/titik/
     * eksponen. Mengembalikan int, atau NULL bila tidak memenuhi.
     *
     * @param  mixed $raw
     * @return int|null
     */
    function spin_wheel_int_token($raw)
    {
        if (is_int($raw))
        {
            return ($raw >= 0) ? $raw : NULL;
        }
        if ( ! is_string($raw))
        {
            return NULL;
        }
        $s = trim($raw);
        if ($s === '' || ! preg_match('/^[0-9]+$/', $s))
        {
            return NULL;
        }
        // Hindari overflow (PHP int 64-bit; batas administratif jauh di bawah).
        if (strlen($s) > 12)
        {
            return NULL;
        }
        return (int) $s;
    }
}

if ( ! function_exists('spin_wheel_segments_parse'))
{
    /**
     * Parse STRUKTURAL konfigurasi segmen. Menerima string JSON
     * (`system_settings.spin_wheel_segments`, bentuk `[[nominal,bobot],…]`)
     * ATAU array tuple. TIDAK mengurutkan input dan TIDAK memeriksa ambang —
     * ambang diperiksa `spin_wheel_segments_valid()` supaya validator admin
     * dapat melaporkan baris yang salah apa adanya (pola plan/119).
     *
     * Ketat (bukan best-effort): bentuk rusak → NULL, sehingga nilai rusak
     * tidak pernah diam-diam menjadi segmen bernominal 0.
     *
     * @param  mixed $raw
     * @return array<int,array{amount:int,weight:int}>|null
     */
    function spin_wheel_segments_parse($raw)
    {
        if (is_string($raw))
        {
            $raw = trim($raw);
            if ($raw === '')
            {
                return NULL;
            }
            $decoded = json_decode($raw, TRUE);
            if ( ! is_array($decoded))
            {
                return NULL;
            }
            $raw = $decoded;
        }

        if ( ! is_array($raw))
        {
            return NULL;
        }

        $segments = array();
        foreach ($raw as $tuple)
        {
            if (is_object($tuple))
            {
                $tuple = (array) $tuple;
            }
            if ( ! is_array($tuple))
            {
                return NULL;
            }
            // Hanya tuple berpasangan (0,1) — bentuk JSON kanonik [[a,w],…].
            if (count($tuple) !== 2)
            {
                return NULL;
            }
            $pair = array_values($tuple);
            $amount = spin_wheel_int_token($pair[0]);
            $weight = spin_wheel_int_token($pair[1]);
            if ($amount === NULL || $weight === NULL)
            {
                return NULL;
            }
            $segments[] = array('amount' => $amount, 'weight' => $weight);
        }

        return $segments;
    }
}

if ( ! function_exists('spin_wheel_segments_valid'))
{
    /**
     * Validasi ambang & invarian konfigurasi segmen.
     *
     * Aturan (plan/121 §3.4 + D3):
     *   - jumlah segmen dalam [min_segments, max_segments];
     *   - setiap nominal integer 1..max_amount  (TIDAK ADA Rp 0);
     *   - setiap bobot integer 1..max_weight;
     *   - total bobot ≤ max_total_weight;
     *   - TIDAK wajib unik/menaik (roda boleh punya nominal berulang).
     *
     * @param  mixed $segments Hasil `spin_wheel_segments_parse()`
     * @return bool
     */
    function spin_wheel_segments_valid($segments)
    {
        if ( ! is_array($segments))
        {
            return FALSE;
        }

        $lim = spin_wheel_limits();

        $count = count($segments);
        if ($count < $lim['min_segments'] || $count > $lim['max_segments'])
        {
            return FALSE;
        }

        $total = 0;
        foreach ($segments as $seg)
        {
            if (is_object($seg))
            {
                $seg = (array) $seg;
            }
            if ( ! is_array($seg) || ! array_key_exists('amount', $seg) || ! array_key_exists('weight', $seg))
            {
                return FALSE;
            }

            $amount = spin_wheel_int_token($seg['amount']);
            $weight = spin_wheel_int_token($seg['weight']);

            if ($amount === NULL || $weight === NULL)
            {
                return FALSE;
            }
            if ($amount < 1 || $amount > $lim['max_amount'])
            {
                return FALSE;
            }
            if ($weight < 1 || $weight > $lim['max_weight'])
            {
                return FALSE;
            }

            $total += $weight;
        }

        return ($total <= $lim['max_total_weight']);
    }
}

if ( ! function_exists('spin_wheel_segments_encode'))
{
    /**
     * Encode konfigurasi ke bentuk JSON KANONIK `[[nominal,bobot],…]`
     * (tanpa spasi, tanpa kunci objek, urutan dipertahankan apa adanya).
     *
     * Input tidak valid → '[]' (state kosong, tidak pernah melempar error):
     * `Spin_model` menolak state kosong sebagai `not_configured` (fail-closed).
     *
     * @param  mixed $segments
     * @return string
     */
    function spin_wheel_segments_encode($segments)
    {
        $parsed = spin_wheel_segments_parse($segments);
        if ($parsed === NULL || count($parsed) === 0)
        {
            return '[]';
        }

        $flat = array();
        foreach ($parsed as $seg)
        {
            $flat[] = array((int) $seg['amount'], (int) $seg['weight']);
        }

        $json = json_encode($flat, JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '[]';
    }
}

if ( ! function_exists('spin_wheel_total_weight'))
{
    /**
     * Total bobot (integer). Input rusak/kosong → 0.
     *
     * @param  mixed $segments
     * @return int
     */
    function spin_wheel_total_weight($segments)
    {
        if ( ! is_array($segments))
        {
            return 0;
        }

        $total = 0;
        foreach ($segments as $seg)
        {
            if (is_object($seg))
            {
                $seg = (array) $seg;
            }
            if ( ! is_array($seg) || ! isset($seg['weight']))
            {
                continue;
            }
            $weight = spin_wheel_int_token($seg['weight']);
            if ($weight === NULL)
            {
                continue;
            }
            $total += $weight;
        }

        return $total;
    }
}

if ( ! function_exists('spin_wheel_pick'))
{
    /**
     * Pemilihan segmen BERBOBOT — FUNGSI MURNI & DETERMINISTIK (dapat diuji
     * tanpa DB, tanpa RNG). Pemanggil menyuplai `$roll` dari CSPRNG:
     *
     *     $roll = random_int(1, spin_wheel_total_weight($segments));
     *     $slot = spin_wheel_pick($segments, $roll);
     *
     * Cumulative walk: bobot [30,25,18,…] membagi rentang [1..total] menjadi
     * segmen berurutan — `$roll ∈ [1..30]` → indeks 0, `[31..55]` → indeks 1,
     * dst. `$roll` di luar rentang (0, negatif, > total) → NULL: TIDAK PERNAH
     * diam-diam jatuh ke segmen 0 (invarian SP5).
     *
     * @param  array $segments Hasil `spin_wheel_segments_parse()`
     * @param  int   $roll     1..total bobot
     * @return int|null        Indeks segmen (0-based) atau NULL
     */
    function spin_wheel_pick($segments, $roll)
    {
        if ( ! is_array($segments) || count($segments) === 0)
        {
            return NULL;
        }

        $roll   = spin_wheel_int_token($roll);
        $total  = spin_wheel_total_weight($segments);

        if ($roll === NULL || $total < 1 || $roll < 1 || $roll > $total)
        {
            return NULL;
        }

        $cumulative = 0;
        $index      = 0;
        foreach ($segments as $seg)
        {
            if (is_object($seg))
            {
                $seg = (array) $seg;
            }
            $weight = (is_array($seg) && isset($seg['weight'])) ? spin_wheel_int_token($seg['weight']) : NULL;
            if ($weight === NULL)
            {
                return NULL;
            }

            $cumulative += $weight;
            if ($roll <= $cumulative)
            {
                return $index;
            }
            $index++;
        }

        return NULL;
    }
}

if ( ! function_exists('spin_wheel_odds_bp'))
{
    /**
     * Peluang per segmen dalam BASIS POINT (1 bp = 0,01%), aritmetika integer
     * murni tanpa float, dihitung dari cumulative boundary agar TOTAL seluruh
     * segmen TEPAT 10.000 bp (100,00%). Dipakai UI admin (tidak pernah masuk
     * kamus bahasa — L6).
     *
     * @param  array $segments
     * @return array<int,int> basis point per indeks (kosong bila input rusak)
     */
    function spin_wheel_odds_bp($segments)
    {
        if ( ! is_array($segments) || count($segments) === 0)
        {
            return array();
        }

        $total = spin_wheel_total_weight($segments);
        if ($total < 1)
        {
            return array();
        }

        $odds       = array();
        $cumulative = 0;
        $previous   = 0;
        $index      = 0;
        foreach ($segments as $seg)
        {
            if (is_object($seg))
            {
                $seg = (array) $seg;
            }
            $weight = (is_array($seg) && isset($seg['weight'])) ? spin_wheel_int_token($seg['weight']) : NULL;
            if ($weight === NULL)
            {
                return array();
            }

            $cumulative += $weight;
            $boundary    = (int) intdiv($cumulative * 10000, $total);
            $odds[$index] = $boundary - $previous;
            $previous     = $boundary;
            $index++;
        }

        return $odds;
    }
}

if ( ! function_exists('spin_wheel_expected_value'))
{
    /**
     * Expected value (rata-rata hadiah) per TIKET — integer IDR, tanpa float.
     *
     *     EV = Σ(nominal × bobot) / Σ(bobot)
     *
     * Dipakai UI admin sebagai informasi ekonomi (berapa rata-rata yang
     * dibayarkan platform untuk satu tiket). Input rusak → 0.
     *
     * @param  array $segments
     * @return int
     */
    function spin_wheel_expected_value($segments)
    {
        if ( ! is_array($segments) || count($segments) === 0)
        {
            return 0;
        }

        $total  = spin_wheel_total_weight($segments);
        if ($total < 1)
        {
            return 0;
        }

        $sum = 0;
        foreach ($segments as $seg)
        {
            if (is_object($seg))
            {
                $seg = (array) $seg;
            }
            if ( ! is_array($seg) || ! isset($seg['amount']) || ! isset($seg['weight']))
            {
                return 0;
            }
            $amount = spin_wheel_int_token($seg['amount']);
            $weight = spin_wheel_int_token($seg['weight']);
            if ($amount === NULL || $weight === NULL)
            {
                return 0;
            }
            $sum += ($amount * $weight);
        }

        return (int) intdiv($sum, $total);
    }
}
