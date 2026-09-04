<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Native SVG CAPTCHA renderer — zero external dependency (plan/72).
 *
 * Pure rendering only: given a code, produce a theme-neutral inline SVG
 * string. No session, no DB, no disk I/O, no outbound calls. The challenge
 * lifecycle (issue/verify/TTL/single-use) lives in Auth (session-bound).
 *
 * Distinguishable alphabet: 5 chars drawn from 56 glyphs — visually
 * ambiguous characters (0, O, o, 1, I, l) are excluded so humans can
 * transcribe reliably. Comparison is case-insensitive (see Auth).
 */

if (!defined('BASEPATH')) {
    // Defensive double-guard for direct include outside CI (tests).
    exit('No direct script access allowed');
}

if (!function_exists('captcha_alphabet')) {
    /**
     * Returns the unambiguous character pool.
     * Uppercase A–Z minus {I, O} (24) + lowercase a–z minus {l, o} (24)
     * + digits 2–9 (8) = 56 characters.
     */
    function captcha_alphabet() {
        static $alphabet = NULL;
        if ($alphabet === NULL) {
            $upper    = array_values(array_diff(range('A', 'Z'), array('I', 'O')));
            $lower    = array_values(array_diff(range('a', 'z'), array('l', 'o')));
            $alphabet = implode('', array_merge($upper, $lower, range('2', '9')));
        }
        return $alphabet;
    }
}

if (!function_exists('captcha_rand')) {
    /**
     * Small int helper — random_int wrapper (CSPRNG). Min/max inclusive.
     */
    function captcha_rand($min, $max) {
        return random_int((int) $min, (int) $max);
    }
}

if (!function_exists('build_captcha_svg')) {
    /**
     * Builds the inline SVG CAPTCHA for a given code.
     *
     * Canvas: 150x50, transparent background (no <rect>) so the glyphs stay
     * sharp on BOTH light (#fff) and dark (slate-800/#0b1120) card surfaces.
     * Palette is theme-neutral high-contrast: indigo #6366f1, cyan #06b6d4,
     * violet #8b5cf6.
     *
     * Each of the 5 glyphs is its own <text text-anchor="middle"> positioned
     * at a jittered slot and rotated ±22° about its own center — the layout
     * does not depend on client font metrics. A noise layer (lines + dots,
     * fill="none") is drawn underneath to defeat naive OCR segmentation.
     *
     * @param string $code The 5-character challenge (from captcha_alphabet()).
     * @return string SVG markup (no user-controlled data reaches the output).
     */
    function build_captcha_svg($code) {
        $code    = (string) $code;
        $codeLen = strlen($code);
        if ($codeLen === 0) {
            return '';
        }

        $width     = 150;
        $height    = 50;
        $slotStart = 16; // x of the first of 5 slots; step 27 -> 16..124
        $slotStep  = 27;

        $palette      = array('#6366f1', '#06b6d4', '#8b5cf6'); // indigo / cyan / violet
        $noisePalette = array('#6366f1', '#06b6d4', '#8b5cf6', '#94a3b8', '#64748b'); // + slate

        // Escape helpers keep every emitted value numeric/whitelisted.
        $svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '
              . $width . ' ' . $height
              . '" width="100%" height="100%" preserveAspectRatio="xMidYMid meet"'
              . ' role="img" aria-label="Kode keamanan captcha">';

        // ── Disturbance layer (under the glyphs) ────────────────────────
        $svg .= '<g fill="none">';

        $nLines = captcha_rand(4, 5);
        for ($i = 0; $i < $nLines; $i++) {
            $x1 = captcha_rand(0, $width);
            $y1 = captcha_rand(0, $height);
            $x2 = captcha_rand(0, $width);
            $y2 = captcha_rand(0, $height);
            $svg .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2
                  . '" y2="' . $y2 . '" stroke="' . $noisePalette[captcha_rand(0, count($noisePalette) - 1)]
                  . '" stroke-width="' . (captcha_rand(6, 14) / 10)
                  . '" opacity="' . (captcha_rand(15, 30) / 100) . '"/>';
        }

        $nDots = captcha_rand(18, 24);
        for ($i = 0; $i < $nDots; $i++) {
            $svg .= '<circle cx="' . captcha_rand(0, $width) . '" cy="' . captcha_rand(0, $height)
                  . '" r="' . (captcha_rand(6, 16) / 10)
                  . '" stroke="' . $noisePalette[captcha_rand(0, count($noisePalette) - 1)]
                  . '" stroke-width="0.8" opacity="' . (captcha_rand(20, 50) / 100) . '"/>';
        }

        $svg .= '</g>';

        // ── Glyph layer ─────────────────────────────────────────────────
        $svg .= '<g fill="' . $palette[0] . '">'; // fill overridden per char
        for ($i = 0; $i < $codeLen; $i++) {
            $ch    = $code[$i];
            $x     = ($slotStart + $i * $slotStep) + captcha_rand(-3, 3);
            $y     = 33 + captcha_rand(-3, 3);
            $rot   = captcha_rand(-22, 22);
            $color = $palette[captcha_rand(0, count($palette) - 1)];
            $op    = captcha_rand(85, 100) / 100; // 0.85 – 1.0

            $svg .= '<text x="0" y="0" text-anchor="middle" dominant-baseline="central"'
                  . ' transform="translate(' . $x . ' ' . $y . ') rotate(' . $rot . ')"'
                  . ' font-family="ui-monospace, Menlo, Consolas, monospace"'
                  . ' font-size="26" font-weight="700"'
                  . ' fill="' . $color . '" stroke="' . $color . '" stroke-width="0.8"'
                  . ' fill-opacity="' . $op . '" stroke-opacity="' . $op . '">'
                  . htmlspecialchars($ch, ENT_QUOTES, 'UTF-8')
                  . '</text>';
        }
        $svg .= '</g>';

        $svg .= '</svg>';

        return $svg;
    }
}
