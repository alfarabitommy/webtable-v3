<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!-- Phase 10C: CSRF meta tags — wajib ada di tiap halaman sebelum JS AJAX dipakai -->
<meta name="csrf-token-name" content="<?= $this->security->get_csrf_token_name(); ?>">
<meta name="csrf-token-hash" content="<?= $this->security->get_csrf_hash(); ?>">
<script>
(function () {
    var name = document.querySelector('meta[name="csrf-token-name"]');
    var hash = document.querySelector('meta[name="csrf-token-hash"]');
    if (!name || !hash) { console.error('CSRF meta tags missing'); return; }
    var TOKEN_NAME = name.content, TOKEN_HASH = hash.content;

    window.getCsrfTokenName = function () { return TOKEN_NAME; };
    window.getCsrfTokenHash = function () { return TOKEN_HASH; };

    /** Wrapper fetch standar: selalu menyuntik token CSRF ke body POST.
     *  CI 3.1.13 hanya memvalidasi $_POST -> token wajib di body (bukan header).
     *  - body FormData -> token di-append (guard has()).
     *  - body string urlencoded -> token ditambahkan sebagai field.
     *  - body string JSON -> token disuntik ke payload JSON.
     *  - tanpa body -> FormData berisi token. */
    window.csrfFetch = function (url, options) {
        options = options || {};
        options.headers = options.headers || {};
        options.headers['X-Requested-With'] = 'XMLHttpRequest';
        var body = options.body;

        if (typeof FormData !== 'undefined' && body instanceof FormData) {
            if (!body.has(TOKEN_NAME)) body.append(TOKEN_NAME, TOKEN_HASH);
        } else if (typeof body === 'string') {
            if (body.charAt(0) === '{') {
                try {
                    var obj = JSON.parse(body);
                    obj[TOKEN_NAME] = TOKEN_HASH;
                    options.body = JSON.stringify(obj);
                } catch (e) {
                    options.body = body + (body.length ? '&' : '') + encodeURIComponent(TOKEN_NAME) + '=' + encodeURIComponent(TOKEN_HASH);
                }
            } else {
                options.body = body + (body.length ? '&' : '') + encodeURIComponent(TOKEN_NAME) + '=' + encodeURIComponent(TOKEN_HASH);
            }
        } else if (body === undefined || body === null) {
            var fd = new FormData();
            fd.append(TOKEN_NAME, TOKEN_HASH);
            options.body = fd;
        }
        return fetch(url, options);
    };

    /* ===== M4 (plan/62): DOUBLE-CLICK / DOUBLE-SUBMIT GUARD (Vanilla JS) =====
       Otomatis aktif untuk <form data-guard-submit="1">:
       - Submit PERTAMA TIDAK di-preventDefault — POST native + redirect +
         flashdata tetap berjalan utuh (pola guard claim-form plan/46).
       - Saat submit pertama: flag data-submitting="1", semua tombol submit
         di-disable, tombol pertama menampilkan spinner "Memproses…".
       - Submit kedua+ diblokir (preventDefault) lewat flag data-submitting.
       Catatan:
       - Bukan pengganti otoritas server (CAS `WHERE status='pending'` +
         `affected_rows()===1` di model) — hanya mencegah klik ganda dan
         POST kedua yang membawa token CSRF basi (csrf_regenerate=true).
       - Dipasang bubble-phase di document: inline onsubmit (mis. confirm()
         di dashboard admin) berjalan lebih dulu; jika user batal, event
         berhenti sebelum guard menandai form.
       - Bisa dipanggil manual via window.guardFormSubmit(form). */
    window.guardFormSubmit = function (form) {
        if (!form || form.getAttribute('data-submitting') === '1') { return; }
        form.setAttribute('data-submitting', '1');
        var btns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        for (var i = 0; i < btns.length; i++) {
            var b = btns[i];
            b.disabled = true;
            b.classList.add('opacity-60', 'cursor-not-allowed');
            if (i === 0) {
                if (b.tagName === 'BUTTON') {
                    b.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Memproses...';
                } else {
                    b.value = 'Memproses...';
                }
            }
        }
    };

    document.addEventListener('submit', function (e) {
        var t = e.target;
        var form = (t && t.tagName === 'FORM') ? t
                 : (t && t.closest ? t.closest('form') : null);
        if (!form || !form.hasAttribute('data-guard-submit')) { return; }
        if (form.getAttribute('data-submitting') === '1') {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        window.guardFormSubmit(form);
    });
})();
</script>
