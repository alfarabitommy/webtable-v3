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
})();
</script>
