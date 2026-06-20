<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= isset($page_title) ? $page_title . ' · ' : '' ?>Synapse</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { -webkit-tap-highlight-color: transparent; }
        body { overscroll-behavior: none; }
    </style>
</head>
<body class="bg-slate-200 flex justify-center">
<div class="w-full max-w-[480px] bg-slate-50 min-h-screen relative shadow-2xl overflow-x-hidden pb-24">

    <!-- Sticky Top Header -->
    <header class="h-14 bg-white/80 backdrop-blur-md border-b border-slate-100 flex items-center justify-between px-4 sticky top-0 z-40">
        <div class="flex items-center">
            <img src="https://placehold.co/100x100/0f172a/ffffff?text=S" class="h-7 w-7 rounded-lg shadow-sm mr-3" alt="Logo">
            <h1 class="text-lg font-extrabold text-slate-900 tracking-tight">Synapse</h1>
        </div>
        <button class="text-slate-500 relative" title="Notifikasi">
            <i class="fas fa-bell text-lg"></i>
            <span class="absolute -top-1 -right-1 w-2.5 h-2.5 bg-rose-500 rounded-full border-2 border-white"></span>
        </button>
    </header>
