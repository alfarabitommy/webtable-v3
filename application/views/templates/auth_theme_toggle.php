<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// Plan 97: Theme toggle Sun/Moon — auth standalone (tanpa Font Awesome).
// Persist key 'user_theme' (parity dgn templates/header.php toggleUserTheme).
// Ikon dikontrol CSS murni (html.dark -> sun) agar konsisten sejak paint pertama.
?>
<button type="button" id="auth-theme-toggle" class="auth-toggle-btn"
        aria-label="<?= lang('common_toggle_theme') ?>" title="<?= lang('common_toggle_theme') ?>">
    <!-- Moon (state light: klik untuk beralih ke gelap) -->
    <svg class="auth-ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
    </svg>
    <!-- Sun (state dark: klik untuk beralih ke terang) -->
    <svg class="auth-ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4"></circle>
        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"></path>
    </svg>
</button>
<script>
(function () {
    if (window.__authThemeInit) { return; }
    window.__authThemeInit = true;
    var btn = document.getElementById('auth-theme-toggle');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var html = document.documentElement;
        var dark = html.classList.toggle('dark');
        try { localStorage.setItem('user_theme', dark ? 'dark' : 'light'); } catch (e) {}
        window.dispatchEvent(new CustomEvent('user-theme-change', { detail: { dark: dark } }));
    });
})();
</script>
