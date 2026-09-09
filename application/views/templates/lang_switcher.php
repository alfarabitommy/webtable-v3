<?php
defined('BASEPATH') OR exit('No direct script access allowed');
// Plan 94 (F1): Language switcher — segmented control EN | ID (member-only).
// Flag = SVG inline sederhana (tanpa aset jaringan, tanpa emoji — render
// platform tidak konsisten). Tombol aktif = ring indigo terang.
$lang_cur = isset($site_lang_code) ? $site_lang_code : 'en';
$lang_href_en = site_url('lang/switch/en');
$lang_href_id = site_url('lang/switch/id');
?>
<div class="inline-flex items-center gap-0.5 u-capsule rounded-full p-0.5" role="group" aria-label="<?= lang('lang_switch_label') ?>">
    <!-- English (default) -->
    <a href="<?= $lang_href_en ?>"
       class="w-8 h-8 rounded-full flex items-center justify-center transition-all duration-200 active:scale-95 <?= $lang_cur === 'en' ? 'ring-2 ring-indigo-500/80 shadow' : 'opacity-55 hover:opacity-100' ?>"
       title="<?= lang('lang_english') ?>" aria-label="<?= lang('lang_english') ?>">
        <svg viewBox="0 0 30 20" class="w-[18px] h-3 rounded-[2px] shadow-sm" aria-hidden="true">
            <rect width="30" height="20" fill="#012169"/>
            <path d="M0 0 30 20 M30 0 0 20" stroke="#fff" stroke-width="5"/>
            <path d="M0 0 30 20 M30 0 0 20" stroke="#C8102E" stroke-width="2.2"/>
            <rect y="7.5" width="30" height="5" fill="#fff"/>
            <rect y="8.75" width="30" height="2.5" fill="#C8102E"/>
            <rect x="12.5" width="5" height="20" fill="#fff"/>
            <rect x="13.75" width="2.5" height="20" fill="#C8102E"/>
        </svg>
    </a>
    <!-- Indonesian (secondary) -->
    <a href="<?= $lang_href_id ?>"
       class="w-8 h-8 rounded-full flex items-center justify-center transition-all duration-200 active:scale-95 <?= $lang_cur === 'id' ? 'ring-2 ring-indigo-500/80 shadow' : 'opacity-55 hover:opacity-100' ?>"
       title="<?= lang('lang_indonesian') ?>" aria-label="<?= lang('lang_indonesian') ?>">
        <svg viewBox="0 0 30 20" class="w-[18px] h-3 rounded-[2px] shadow-sm" aria-hidden="true">
            <rect width="30" height="10" fill="#CE1126"/>
            <rect y="10" width="30" height="10" fill="#fff"/>
        </svg>
    </a>
</div>
