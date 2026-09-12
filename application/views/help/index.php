<!-- ═══ Help / Bantuan — Phase 8B (Phase 32: theme tokens) ═══ -->
<div class="p-4 space-y-6">

    <!-- ═══ Header ═══ -->
    <div>
        <h2 class="text-lg font-extrabold u-text"><?= lang('help_page_title') ?></h2>
        <p class="text-xs u-text-2 mt-1"><?= lang('help_page_sub') ?></p>
    </div>

    <!-- ═══ Contact CTA ═══ -->
    <div class="u-card rounded-2xl p-5 shadow-sm">
        <h3 class="text-sm font-bold u-text mb-3 flex items-center gap-2">
            <i class="fas fa-headset text-indigo-500"></i>
            <?= lang('help_contact_title') ?>
        </h3>
        <p class="text-xs u-text-2 mb-4"><?= lang('help_contact_sub') ?></p>
        <div class="grid grid-cols-2 gap-3">
            <!-- WhatsApp Button — plan/103: pesan pra-isi dari kamus -->
            <a href="https://wa.me/<?= urlencode($wa_number) ?>?text=<?= rawurlencode(lang('help_wa_prefill')) ?>"
               target="_blank"
               rel="noopener"
               class="flex items-center justify-center gap-2 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-sm font-bold py-3 px-4 rounded-xl transition-all duration-200 active:scale-95 shadow-sm">
                <i class="fab fa-whatsapp text-lg"></i>
                <span><?= lang('help_contact_wa') ?></span>
            </a>
            <!-- Email Button -->
            <a href="mailto:<?= urlencode($support_email) ?>?subject=<?= rawurlencode(lang('help_email_subject')) ?>"
               class="flex items-center justify-center gap-2 bg-blue-500 hover:bg-blue-600 active:bg-blue-700 text-white text-sm font-bold py-3 px-4 rounded-xl transition-all duration-200 active:scale-95 shadow-sm">
                <i class="fas fa-envelope text-lg"></i>
                <span><?= lang('help_contact_email') ?></span>
            </a>
        </div>
    </div>

    <!-- ═══ FAQ Accordion ═══ -->
    <div class="space-y-3" id="faq-container">
        <h3 class="text-sm font-bold u-text flex items-center gap-2">
            <i class="fas fa-question-circle text-indigo-500"></i>
            <?= lang('help_faq_title') ?>
        </h3>

        <!-- FAQ Item 1 -->
        <div class="faq-item u-card rounded-2xl shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold u-text"><?= lang('help_q_what_title') ?></span>
                <i class="fas fa-chevron-down u-muted text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs u-text-2 leading-relaxed">
                    <?= lang('help_q_what_body') ?>
                </div>
            </div>
        </div>

        <!-- FAQ Item 2 -->
        <div class="faq-item u-card rounded-2xl shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold u-text"><?= lang('help_q_rent_title') ?></span>
                <i class="fas fa-chevron-down u-muted text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs u-text-2 leading-relaxed">
                    <?= lang('help_q_rent_body') ?>
                </div>
            </div>
        </div>

        <!-- FAQ Item 3 -->
        <div class="faq-item u-card rounded-2xl shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold u-text"><?= lang('help_q_wd_title') ?></span>
                <i class="fas fa-chevron-down u-muted text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs u-text-2 leading-relaxed">
                    <?= sprintf(lang('help_q_wd_body'), 'Rp ' . number_format(100000, 0, ',', '.')) ?>
                </div>
            </div>
        </div>

        <!-- FAQ Item 4 -->
        <div class="faq-item u-card rounded-2xl shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold u-text"><?= lang('help_q_ref_title') ?></span>
                <i class="fas fa-chevron-down u-muted text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs u-text-2 leading-relaxed">
                    <?= lang('help_q_ref_body') ?>
                </div>
            </div>
        </div>

        <!-- FAQ Item 5 — NEW: Security Rule -->
        <div class="faq-item u-card rounded-2xl shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold u-text"><?= lang('help_q_wd_fail_title') ?></span>
                <i class="fas fa-chevron-down u-muted text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs u-text-2 leading-relaxed">
                    <?= lang('help_q_wd_fail_intro') ?>
                    <ul class="list-disc list-inside space-y-1 mt-2">
                        <li><?= lang('help_q_wd_fail_req1') ?></li>
                        <li><?= lang('help_q_wd_fail_req2') ?></li>
                        <li><?= sprintf(lang('help_q_wd_fail_req3'), 'Rp ' . number_format(100000, 0, ',', '.')) ?></li>
                        <li><?= lang('help_q_wd_fail_req4') ?></li>
                        <li><?= lang('help_q_wd_fail_req5') ?></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- FAQ Item 6 -->
        <div class="faq-item u-card rounded-2xl shadow-sm overflow-hidden">
            <button onclick="toggleFaq(this)"
                    class="w-full flex items-center justify-between p-4 text-left gap-3">
                <span class="text-sm font-semibold u-text"><?= lang('help_q_topup_title') ?></span>
                <i class="fas fa-chevron-down u-muted text-xs transition-transform duration-300 shrink-0 faq-icon"></i>
            </button>
            <div class="faq-answer max-h-0 overflow-hidden transition-all duration-300 ease-in-out">
                <div class="px-4 pb-4 text-xs u-text-2 leading-relaxed">
                    <?= lang('help_q_topup_body') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Footer Note ═══ -->
    <div class="text-center pb-4">
        <p class="text-[10px] u-muted"><?= lang('help_footer_version') ?></p>
    </div>

</div>

<!-- ═══ Vanilla JS Accordion ═══ -->
<script>
(function() {
    function toggleFaq(button) {
        const faqItem = button.closest('.faq-item');
        const answer = faqItem.querySelector('.faq-answer');
        const icon = faqItem.querySelector('.faq-icon');
        const isOpen = answer.classList.contains('max-h-[500px]');

        // Close all other open FAQs
        document.querySelectorAll('.faq-item').forEach(function(item) {
            const ans = item.querySelector('.faq-answer');
            const ico = item.querySelector('.faq-icon');
            if (item !== faqItem) {
                ans.classList.remove('max-h-[500px]');
                ans.classList.add('max-h-0');
                ico.classList.remove('rotate-180');
            }
        });

        // Toggle current FAQ
        if (isOpen) {
            answer.classList.remove('max-h-[500px]');
            answer.classList.add('max-h-0');
            icon.classList.remove('rotate-180');
        } else {
            answer.classList.remove('max-h-0');
            answer.classList.add('max-h-[500px]');
            icon.classList.add('rotate-180');
        }
    }

    // Expose to global scope for inline onclick handlers
    window.toggleFaq = toggleFaq;
})();
</script>
