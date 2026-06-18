# UI/UX & Component Guidelines v2.0 (Strict AI Specification)
**Project Name:** Synapse
**CSS Framework:** Tailwind CSS (Strictly Utility Classes)
**Design Philosophy:** Minimalist, High-Density Data Presentation, Bright/Professional, Mobile-First.

## 1. Global Layout & Spatial Geometry
Aplikasi harus terasa seperti *Native Mobile App* (iOS/Android) meskipun diakses via *browser* desktop.
* **Master Wrapper (Body):** `bg-slate-100 flex justify-center min-h-screen font-sans antialiased text-slate-800`
* **App Container:** `<div class="w-full max-w-[480px] bg-white min-h-screen relative shadow-2xl overflow-x-hidden pb-20">`
    * *Catatan:* `pb-20` wajib ada untuk memberi ruang pada *Bottom Navigation* agar konten terbawah tidak tertutup.
* **Top Header/App Bar:** Ketinggian tetap `h-14`, `flex items-center justify-between px-4`, latar putih dengan batas bawah tipis `border-b border-slate-100`, dan `sticky top-0 z-40 bg-white/90 backdrop-blur-sm`.
* **Bottom Navigation:** Ketinggian tetap `h-16`, `fixed bottom-0 w-full max-w-[480px] bg-white border-t border-slate-200 z-50 flex justify-around items-center`. Ikon menggunakan SVG ukuran `w-6 h-6`.

## 2. Color Palette & Theming (Bright & Trustworthy)
Dilarang menggunakan warna-warna neon yang mencolok. Gunakan palet pastel/solid yang merepresentasikan kepercayaan dan transparansi finansial.
* **Backgrounds:**
    * Main App Background: `bg-white`
    * Card/Section Background: `bg-slate-50` atau `bg-slate-100` (untuk membedakan lapisan konten).
* **Primary Brand (Aksen Korporat):** * Solid: `bg-blue-600` (untuk tombol utama/Call to Action).
    * Hover/Active State: `bg-blue-700` atau `bg-blue-50`.
    * Teks Aktif: `text-blue-600`.
* **Semantic Colors (Financial Indicators):**
    * Income/Success/Active: `text-emerald-500` & `bg-emerald-50`.
    * Expense/Withdrawal: `text-rose-500` & `bg-rose-50`.
    * Pending/Processing: `text-amber-500` & `bg-amber-50`.
* **Typography Colors:**
    * Judul Utama/Saldo: `text-slate-900`.
    * Teks Paragraf/Label: `text-slate-500`.
    * Placeholder Form: `text-slate-300`.

## 3. Typography & Data Density 
Mengingat ini adalah platform dengan data finansial yang masif (harga sewa, ROI harian, saldo), pengaturan huruf adalah prioritas mutlak.
* **Font Family:** Standar *sans-serif* (`font-sans`).
* **Financial Numbers:** Setiap angka yang menampilkan saldo atau uang WAJIB menggunakan *utility* `tabular-nums` dan `tracking-tight` agar lebar angka konsisten dan sejajar rapi saat dibuat dalam bentuk daftar/tabel.
* **Text Sizing System:**
    * Big Balance/Display: `text-3xl font-bold tracking-tight text-slate-900`.
    * Page Title: `text-lg font-semibold text-slate-800`.
    * Normal Body: `text-sm text-slate-600`.
    * Micro Labels (Status/Date): `text-[11px] uppercase tracking-wider text-slate-400 font-medium`.

## 4. Component Standards

### A. Buttons (Tombol)
* **Primary Button:** `w-full h-12 bg-blue-600 text-white rounded-xl font-semibold flex items-center justify-center transition-colors active:bg-blue-700 disabled:bg-blue-300 disabled:cursor-not-allowed`.
* **Secondary Button:** `w-full h-12 bg-slate-100 text-slate-700 rounded-xl font-semibold hover:bg-slate-200`.
* **Micro Button (Aksi kecil di dalam list):** `px-3 py-1.5 bg-blue-50 text-blue-600 rounded-lg text-xs font-semibold`.

### B. Input Forms
* **Text/Password/Number Inputs:** `w-full h-12 px-4 rounded-xl border border-slate-200 bg-slate-50 text-slate-800 text-sm focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-600 transition-all`.
* **Form Group Spacing:** Selalu gunakan `space-y-4` antar elemen input. Beri label di atas input dengan `text-xs font-semibold text-slate-700 mb-1 block`.

### C. Cards (Kartu Produk/Riwayat)
* **Container:** `bg-white border border-slate-100 rounded-2xl p-4 shadow-sm`.
* **Layout Kartu:** Gunakan `flex justify-between items-center` untuk memastikan teks kiri (Keterangan) dan teks kanan (Nominal Uang) sejajar rata dengan baik.

### D. Modals & Overlays (Dialog Pop-up)
* **Backdrop:** `fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-end justify-center sm:items-center`.
* **Modal Body (Bottom Sheet style for mobile):** `bg-white w-full max-w-[480px] rounded-t-3xl sm:rounded-2xl p-6 transform transition-transform animate-slide-up`.