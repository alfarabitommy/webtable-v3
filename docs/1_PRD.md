# Product Requirements Document (PRD) v2.0 - Extended
**Project Name:** Synapse (AI GPU Renta)
**Platform:** Web Application (100% Mobile-First / SPA-like experience)
**Core Tech Stack:** CodeIgniter 3 (CI3), PHP 8.1, MySQL 8.4, Vanilla JavaScript, Tailwind CSS

## 1. Product Overview & Objective
Synapse adalah platform investasi dan penyewaan daya komputasi awan (GPUaaS). Pengguna dapat menyewa *node* GPU dengan durasi tertentu (Short-Term & Long-Term) untuk mendapatkan *Return of Investment* (ROI) berupa pendapatan harian. Sistem ini dilengkapi dengan akuntansi *double-entry ledger* yang ketat dan sistem afiliasi (*Agency*) multi-level berjenjang dengan *reward* pencapaian dan gaji mingguan otomatis.

## 2. User Roles & Permissions
* **Guest (Unauthenticated):** Hanya dapat melihat halaman Login, Register, Lupa Sandi, dan Halaman Download/Landing Page.
* **User (Authenticated):** Memiliki dompet (*wallet*), dapat menyewa GPU, menarik dana, dan memiliki *referral link* untuk membangun tim/agensi.
* **System/Cron:** Menjalankan tugas otomatis di latar belakang (pembagian hasil harian, perhitungan gaji mingguan agen).

## 3. Core Modules & Exact Business Logic

### A. Authentication & Onboarding
* **Pendaftaran (Register):**
    * Kolom: Nomor Telepon (Unique, Numeric, Min: 9, Max: 15), Kata Sandi (Min: 8 karakter kombinasi huruf & angka), Kode OTP/SMS, Kode Undangan (Referral).
    * **Constraint:** *Kode Undangan bersifat WAJIB*. Setiap user baru harus menjadi *downline* dari user lain.
    * Aksi Sistem: Saat pendaftaran berhasil, sistem men-generate "Kode Undangan" unik sepanjang 6 karakter alfanumerik untuk user baru tersebut.
* **Login:** Menggunakan Nomor Telepon dan Kata Sandi. Session disimpan menggunakan sistem file CI3.
* **Profil:** User dapat mengunggah Avatar (format: JPG/PNG, max: 2MB) dan mengubah nama pengguna (maks 50 karakter).

### B. Marketplace & Asset Rental (Sewa)
* **Katalog GPUaaS (Produk):**
    * Data produk harus dinamis dari database, menampilkan: Nama Produk, Tipe (Short/Long Term), Harga Sewa (Rp), Durasi (Hari), Pendapatan Harian (Rp), dan Estimasi Total Penghasilan.
* **Logika Transaksi Sewa:**
    * Saat tombol "Sewa" ditekan, sistem mengecek `balance` (saldo) user di tabel `users`.
    * Jika saldo < Harga Sewa: Tampilkan *error* "Saldo tidak mencukupi" dan arahkan ke halaman Isi Ulang.
    * Jika saldo >= Harga Sewa:
        1. Kurangi saldo user.
        2. Buat *record* di tabel `transactions` (Tipe: `rental_payment`, Amount: Negatif).
        3. Tambahkan data ke tabel `rentals` dengan status `active`, catat waktu `started_at` dan estimasi `ends_at`.
    * **Constraint:** Proses transaksi harus dibungkus dalam *Database Transaction* (Commit & Rollback) untuk menghindari saldo minus jika koneksi terputus.

### C. Financial Ledger & Wallet
* **Aturan Emas:** Saldo utama user BUKAN angka statis, melainkan representasi dari kalkulasi mutasi di tabel `transactions` (Uang Masuk - Uang Keluar).
* **Penarikan Dana (Withdrawal):**
    * **Pengikatan Bank:** User wajib menambahkan Kartu Bank (Nama Bank, Nomor Rekening, Nama Pemegang) sebelum bisa melakukan penarikan.
    * **Jam Operasional:** Penarikan hanya dapat diajukan pada hari Senin - Sabtu pukul 07:00 - 19:00 WIB. Permintaan pada hari Minggu/di luar jam ditolak/ditunda.
    * **Batas Minimum & Maksimum:** Minimum Rp 30.000, Maksimum Rp 50.000.000 per penarikan.
    * **Limit Frekuensi:** Hanya diperbolehkan 1 kali penarikan per hari per user.
    * **Struktur Biaya Penarikan (Fee Calculation Rule):**
        * Rp 20.000 ~ Rp 500.000: Biaya 10% + Rp 6.500
        * Rp 500.000 ~ Rp 1.000.000: Biaya 7.5% + Rp 6.500
        * Rp 1.000.000 ~ Rp 2.000.000: Biaya 6.5% + Rp 6.500
        * Rp 2.000.000 ~ Rp 5.000.000: Biaya 5% + Rp 6.500
        * Rp 5.000.000 ~ Rp 10.000.000: Biaya 4% + Rp 6.500
        * Rp 10.000.000 ~ Rp 50.000.000: Biaya 3% + Rp 6.500
    * Dana yang dikurangi dari saldo user adalah `gross_amount` (Penarikan Kotor).

### D. System Automation (Cron Jobs)
* **Distribusi Pendapatan Harian (Daily Revenue):**
    * Dieksekusi setiap hari pada jam 00:01 WIB.
    * Sistem mencari semua `rentals` dengan status `active` yang belum mencapai `total_days`.
    * Menambahkan saldo harian ke user (Insert ke tabel `transactions` dengan tipe `daily_revenue`).
    * Jika `days_processed` mencapai `total_days`, ubah status `rentals` menjadi `completed`.

### E. Affiliate & Agency System (MLM Structure)
* **Hierarki:** Sistem melacak keturunan langsung (Agen B) dan keturunan lapis kedua (Agen C). Kombinasi B+C disebut sebagai *Total Active Downline*.
* Definisi **Active Downline**: User *downline* yang minimal pernah melakukan 1 kali transaksi sewa produk GPU.
* **Agency Rules & Rewards:**
    * **Level 1:** Syarat (3 Agen B+C Aktif & Total Sales B+C > Rp 330.000). Reward: Rp 80.000 (Bonus pencapaian satu kali/One-time).
    * **Level 2:** Syarat (9 Agen B+C Aktif). Reward: Gaji Rp 200.000 / Minggu.
    * **Level 3:** Syarat (18 Agen B+C Aktif). Reward: Gaji Rp 500.000 / Minggu.
    * **Level 4:** Syarat (40 Agen B+C Aktif). Reward: Gaji Rp 1.500.000 / Minggu.
    * **Level 5:** Syarat (90 Agen B+C Aktif). Reward: Gaji Rp 4.000.000 / Minggu.
    * **Level 6:** Syarat (190 Agen B+C Aktif). Reward: Gaji Rp 9.000.000 / Minggu.
* **Otomatisasi Gaji:** Cron Job berjalan setiap hari Senin pukul 01:00 WIB untuk mengevaluasi kualifikasi setiap user dan mendistribusikan "Weekly Wage" ke dompet mereka secara otomatis.

## 4. UI/UX & Frontend Guidelines
* **Visual Murni:** 100% menggunakan Tailwind CSS via CDN atau Build. TIDAK BOLEH menggunakan Bootstrap.
* **Interaktivitas:** Menggunakan Vanilla JavaScript. Semua *pop-up* (seperti konfirmasi *withdraw*, *alert* sukses/gagal, *modal* ubah profil) harus dirender tanpa *reload* halaman.
* **Navigasi:** *Bottom Navigation Bar* persisten di 5 halaman utama (Beranda, Sewa Saya, Bantuan, Marketplace, Profil).

## 5. Security & OpSec Requirements
* **CSRF Protection:** Wajib diaktifkan di konfigurasi CI3 (`$config['csrf_protection'] = TRUE;`).
* **Rate Limiting / Mutex Lock:** Halaman eksekusi finansial (Tombol Beli Sewa, Tombol Tarik Dana) wajib memiliki *lock* atau *disable state* pada JavaScript dan divalidasi di PHP agar tidak terjadi *Double Spending* jika *user* melakukan klik dua kali dengan cepat.
* **Data Masking:** Rekening bank yang tampil di antarmuka harus disensor sebagian (misal: 1234*****789).
* **Infrastruktur:** Aplikasi akan di-deploy di balik proxy (seperti Cloudflare). Konfigurasi CI3 harus menangkap `HTTP_X_FORWARDED_FOR` untuk mencatat log IP asli user, bukan IP dari proxy.