# Plan 107 — VIRAL PROMOTIONAL COPYWRITING KIT (Synapse)

**Status:** Deliverable copywriting — siap pakai (bukan perubahan kode).
**Tanggal:** 2026-02-19
**Scope:** Materi promosi bahasa Indonesia, 3 format, untuk kanal WhatsApp/Telegram, affiliate/leader, dan social media.
**Sumber fakta:** `database.sql` (seed `gpu_products` + `system_settings`), `application/config/rebate_commission.php`, `application/config/promoter_rewards.php`, `application/models/User_model.php` (`WAGE_TIERS`, `LEVEL1_BONUS`), `docs/1_PRD.md`.

> Nol perubahan pada `application/`, `system/`, skema, atau i18n. Ini murni dokumen konten pemasaran.

---

## 0. GROUND TRUTH — angka & aturan yang WAJIB dipatuhi copy

Copy di bawah **hanya** memakai angka yang sudah diverifikasi dari kode/database. Jangan mengarang angka baru.

### Lineup produk (live — `gpu_products`)

| Produk | Harga | Harian | Hari | Total kembali | ROI |
|---|---|---|---|---|---|
| RTX 3060 Starter | Rp 150.000 | Rp 7.500 | 25 | Rp 187.500 | +25% |
| RTX 4060 Lite | Rp 300.000 | Rp 13.500 | 30 | Rp 405.000 | +35% |
| RTX 4070 Basic | Rp 600.000 | Rp 28.000 | 30 | Rp 840.000 | +40% |
| RTX 4080 Prime | Rp 1.200.000 | Rp 57.600 | 35 | Rp 2.016.000 | +68% |
| RTX 4090 Pro | Rp 2.500.000 | Rp 125.000 | 40 | Rp 5.000.000 | +100% |
| A100 Cloud Cluster | Rp 4.500.000 | Rp 234.000 | 45 | Rp 10.530.000 | +134% |
| H100 Tensor Node | Rp 7.000.000 | Rp 378.000 | 50 | Rp 18.900.000 | +170% |
| H200 Sovereign | Rp 10.000.000 | Rp 560.000 | 60 | Rp 33.600.000 | +236% |

### Aturan operasional (jangan dilanggar dalam copy)

- **Pendapatan harian = KLAIM manual** (`/rentals/claim/{id}`, `Rental_model::claim_roi`). **BUKAN** auto-credit. Copy harus memakai kata "klaim", bukan "otomatis masuk sendiri".
- **Penarikan: Senin–Sabtu, 07:00–19:00 WIB.** Di luar jam itu ditolak/ditunda.
- **Minimum penarikan Rp 100.000**, maksimum Rp 50.000.000 per pengajuan, dan **1 penarikan per hari per user**.
- **Ada biaya penarikan** (10% + Rp 6.500 untuk nominal Rp 20.000–500.000, turun bertahap sampai 3% + Rp 6.500 di Rp 10 juta ke atas). **Wajib disebut jujur** — jangan klaim "tanpa potongan".
- **Deposit QRIS diverifikasi manual admin pada jam kerja** (bukan instan otomatis).
- **Withdraw masuk ke e-wallet** DANA / ShopeePay / OVO / GoPay (nomor HP `08xxxxxxxxxx`).
- **Rebate 3 tier:** L1 **5%**, L2 **3%**, L3 **1%** (`rebate_commission.php`; dinamis via `system_settings`).
- **Bonus Level 1:** Rp 80.000 **sekali saja** — syarat 3 downline aktif **dan** omset Rp 330.000.
- **Gaji mingguan (live `User_model::WAGE_TIERS`):** 9 agen → Rp 200.000 · 30 → Rp 1.000.000 · 70 → Rp 2.500.000 · 130 → Rp 5.000.000 · 190 → Rp 9.000.000.
  - ⚠️ **Catatan:** §G `docs/1_PRD.md` masih menulis 9/18/40/90/190 (Rp 200rb/500rb/1,5jt/4jt/9jt). **Kode = otoritatif** (`plan/80` P5). Dokumen ini memakai angka kode. PRD sebaiknya disinkronkan (follow-up).
- **Reward node GPU gratis (omzet burn, `promoter_rewards.php`):** tebus omzet → kontrak zero-cost. RTX 3060 = Rp 1.500.000 · RTX 4060 = Rp 3.500.000 · RTX 4070 = Rp 7.000.000 · RTX 4080 = Rp 15.000.000. Produk di luar 4 ini **tidak** bisa ditebus.
- **Grup WhatsApp komunitas resmi** aktif (plan/105, `/help`).

### Aturan bahasa (anti-scam)

✅ Pakai: *potensi, estimasi, skema, simulasi, sesuai paket, klaim harian, jam operasional.*
❌ Hindari: *dijamin untung, pasti balik modal, profit tetap, tanpa risiko, auto cuan, 100% aman, passive income otomatis.*
❌ Jangan janjikan waktu pencairan instan. Jangan sembunyikan biaya penarikan & jam operasional.

---

## FORMAT 1 — WHATSAPP / TELEGRAM BROADCAST

**Target:** audiens umum pencari penghasilan harian.
**Angle:** tren AI + modal kecil Rp 150.000 + cashflow harian ke e-wallet.
**Cara pakai:** kirim sebagai 1 pesan; ikon bold WA/Telegram ikut ter-render. Ganti `[LINK_DAFTAR]` & `[GRUP_WA]`.

---

### 1A. VERSI UTAMA (rekomendasi)

```
🤖 *AI lagi panas. Sekarang kamu bisa ikut ambil bagiannya — mulai Rp 150.000.*

Semua orang ribut soal AI. Yang jarang dibahas: di balik setiap AI, ada *GPU* yang disewa mahal.

Di *SYNAPSE*, kamu yang menyewakan daya komputernya. 🖥️⚡

*Cara kerjanya simpel:*
1️⃣ Pilih node GPU (RTX 3060 sampai H200)
2️⃣ Sewa mulai *Rp 150.000*
3️⃣ Klaim pendapatan harian kamu
4️⃣ Tarik ke *DANA / OVO / GoPay / ShopeePay*

*Contoh nyata — Node RTX 3060 Starter:*
💰 Modal: *Rp 150.000*
📅 Pendapatan: *Rp 7.500 / hari*
⏳ Durasi: *25 hari*
📈 Total kembali: *Rp 187.500*

➡️ Potensi selisih: *+Rp 37.500* dari satu node. Punya 4 node? Kalikan.

*Kenapa orang mulai pindah ke sini:*
✅ Modal awal cuma seharga 2 gelas kopi
✅ Bayar pakai *QRIS* — scan, selesai
✅ Tarik dana langsung ke e-wallet kamu
✅ Ada grup WhatsApp komunitas, bisa tanya langsung
✅ Sistem afiliasi 3 level (bonus bulanan buat yang serius)

*⏰ Catatan penting (biar kamu nggak kaget):*
• Pendapatan diklaim *harian* — bukan masuk sendiri
• Penarikan: *Senin–Sabtu, 07.00–19.00 WIB*
• Minimum tarik *Rp 100.000* (ada biaya admin, transparan di app)

Slot node terbatas tiap batch. Batch lalu penuh dalam hitungan hari.

👉 Daftar gratis dulu, lihat sendiri dashboardnya:
[LINK_DAFTAR]

Masih ragu? Masuk grup, tanya apa saja dulu:
[GRUP_WA]

_Keputusan tetap di tangan kamu. Cek dulu, baru putuskan._
```

---

### 1B. VERSI PENDEK (untuk follow-up / broadcast ulang)

```
💰 *Rp 150.000 hari ini. Rp 7.500 masuk tiap hari selama 25 hari.*

Itu bukan janji. Itu *simulasi paket RTX 3060 Starter* di Synapse.

Sewa daya GPU untuk beban kerja AI → klaim pendapatan harian → tarik ke *DANA/OVO/GoPay/ShopeePay*.

🎯 Tanpa skill teknis. Tanpa install apa pun. Dari HP.
🎯 Modal mulai *Rp 150.000* — pakai QRIS.
🎯 Afiliasi 3 level buat kamu yang punya jaringan.

📌 Klaim harian · Tarik *Senin–Sabtu 07.00–19.00 WIB* · Min. tarik Rp 100.000

👉 [LINK_DAFTAR]
💬 Tanya dulu: [GRUP_WA]
```

---

### 1C. VERSI "COBA DULU" (micro-commitment — konversi tertinggi untuk audiens dingin)

```
❓ *"Rp 150.000 itu buat apa ya?"*

Kopi 3x. Boba 2x. Atau... *1 node GPU AI yang bayar kamu tiap hari.*

Begini alurnya di *Synapse*:

🔹 Sewa node GPU mulai *Rp 150.000*
🔹 Klaim pendapatan *Rp 7.500/hari selama 25 hari*
🔹 Dana di dompet → tarik ke *DANA / OVO / GoPay / ShopeePay*

*Kamu nggak perlu ngerti AI.* Sistem yang bekerja, kamu yang klaim.

Saya nggak minta kamu langsung percaya. Saya minta kamu *lihat dulu* — daftar gratis, cek dashboard, cek katalognya. Baru putuskan.

👉 Daftar & lihat: [LINK_DAFTAR]
👉 Tanya komunitas: [GRUP_WA]

⏳ Batch node terbatas. Kalau penuh, tunggu batch berikutnya.
```

---

## FORMAT 2 — AFFILIATE / LEADER PITCH

**Target:** team builder, affiliate, komunitas leader.
**Angle:** rebate 3 tier (5%–3%–1%) + gaji mingguan + aset digital jangka panjang.
**Cara pakai:** untuk recruitment 1-on-1, caption rekrutmen, atau closing di grup leader.

---

### 2A. VERSI UTAMA (leader / rekrutmen serius)

```
⚡ *BUILDERS — ini yang kamu tunggu.*

Kamu sudah capek bangun tim, tapi komisi cuma 1 level? Di *Synapse*, kerja sekali dibayar 3 lapis.

📊 *REBATE 3 TIER — beli sekali, dibayar 3 generasi:*
• *Level 1 → 5%*
• *Level 2 → 3%*
• *Level 3 → 1%*

Katakan tim kamu punya 10 orang L1, 50 L2, 200 L3. Setiap kali mereka sewa node, kamu dibayar dari *ketiga* lapisan — bukan cuma yang kamu rekrut langsung.

🎖️ *GAJI MINGGUAN (bukan bonus sekali):*
| Agen aktif | Gaji / minggu |
|---|---|
| 9 | *Rp 200.000* |
| 30 | *Rp 1.000.000* |
| 70 | *Rp 2.500.000* |
| 130 | *Rp 5.000.000* |
| 190 | *Rp 9.000.000* |

Ini *gaji*. Bukan hadiah. Bukan sekali. *Mingguan.*

🏆 *BONUS LEVEL 1:* Rp 80.000 — klaim begitu 3 downline aktif & omset Rp 330.000. Secepat itu kamu sudah dibayar balik.

🎁 *PROGRAM PROMOTER — NODE GPU GRATIS:*
Tebus omzet → dapat kontrak node *zero-cost*. Node-nya tetap hasilkan pendapatan harian untukmu.
• Rp 1.500.000 omzet → RTX 3060
• Rp 3.500.000 → RTX 4060
• Rp 7.000.000 → RTX 4070
• Rp 15.000.000 → RTX 4080

*Kenapa ini beda dari program lain:*
🔹 Produknya *nyata & mudah dijelaskan* — sewa GPU untuk AI, bukan abstrak
🔹 Entry *Rp 150.000* → jaringan kamu gampang diajak, closing cepat
🔹 Tracking transparan, komisi masuk ke *wallet_ledger*
🔹 Ada leaderboard gaji — prestasi dihargai, bukan cuma janji
🔹 *Aset digital jangka panjang:* tim yang kamu bangun hari ini terus bayar kamu

*Realistis soal aturannya:*
• Downline dihitung *aktif* kalau punya sewa berjalan
• Supaya rebate kamu cair, kamu sendiri wajib punya *minimal 1 node sewa aktif* saat downline membeli
• Gaji dievaluasi *mingguan*
• Semua pencairan ikut jam operasional (Senin–Sabtu, 07.00–19.00 WIB)

Kamu bisa mulai sendiri dulu — satu node, Rp 150.000. Buktikan ke tim kamu dengan hasil, bukan kata-kata.

📈 *Tim yang bergerak duluan yang pegang jaringan.*

👉 Mulai bangun di sini: [LINK_DAFTAR]
👥 Masuk grup leader: [GRUP_WA]
```

---

### 2B. VERSI PENDEK (closing cepat / DM)

```
🔥 *3 LAPIS KOMISI. 1 KALI KERJA.*

Rebate Synapse: *L1 5% · L2 3% · L3 1%*

Artinya: tim kamu merekrut, kamu *tetap* dibayar sampai generasi ke-3.

Plus:
🎖️ *Gaji mingguan* — Rp 200rb (9 agen) s/d *Rp 9 juta* (190 agen)
🎁 *Node GPU gratis* dari omzet Rp 1,5 juta
💰 Bonus L1 Rp 80.000 sekali klaim

Modal awal anggotamu cuma *Rp 150.000*. Gampang dijual, gampang closing.

Yang kamu bangun hari ini = aset yang bayar kamu tahun depan.

👉 [LINK_DAFTAR] · [GRUP_WA]
```

---

### 2C. VERSI OBJEKSI-BALIK (untuk prospek yang trauma MLM)

```
🛑 *"Ah, MLM lagi."*

Wajar. Saya juga dulu begitu. Jadi saya jelaskan apa adanya — lalu kamu nilai sendiri.

*Yang BUKAN:* nggak ada produk? Salah. Produknya GPU AI sungguhan — RTX 3060 sampai H200. Ada harga, ada durasi, ada pendapatan harian yang bisa kamu hitung sendiri di dashboard.

*Yang BUKAN:* komisi dari setoran member baru? Bukan. Rebate dibayar dari *sewa node yang benar-benar terjadi* — L1 5%, L2 3%, L3 1%.

*Yang BUKAN:* janji manis doang? Betul, makanya saya kasih angkanya:
🎖️ Gaji mingguan mulai 9 agen aktif = Rp 200.000
🎖️ Sampai 190 agen aktif = Rp 9.000.000/minggu

*Yang jujur saya akui:*
• Downline harus *aktif* (punya sewa berjalan) untuk dihitung
• Kamu sendiri wajib punya *1 node aktif* supaya rebate cair — jadi kamu pun membeli produknya, bukan cuma menjual
• Pencairan ikut jam operasional & ada biaya admin
• Hasil bergantung pada seberapa serius tim kamu dibangun

Kalau kamu masih cari program yang butuh kerja nyata tapi bayarannya 3 lapis — ini dia.

👉 Cek sendiri: [LINK_DAFTAR] · [GRUP_WA]
```

---

## FORMAT 3 — SHORT SOCIAL HOOK (Story WA / Status / Reels / TikTok)

**Target:** scroller kasual. **Aturan:** 1 ide, 1 hook, 1 CTA. Hook 3 detik pertama menentukan segalanya.

---

### 3A. HOOK KOPI vs NODE (paling relatable)

```
Kopi kamu hari ini: Rp 25.000. Habis. 😅

Node GPU AI: Rp 150.000 sekali → Rp 7.500 masuk tiap hari, 25 hari.

Kopi bikin melek 2 jam.
Node bikin cashflow 25 hari.

Bedanya cuma di mana kamu taruh. ☕→🖥️

[LINK_DAFTAR]
```

### 3B. HOOK BOBA

```
Boba Rp 22.000 = habis 10 menit. 🧋

Rp 150.000 di Synapse = node GPU AI, bayar Rp 7.500/hari selama 25 hari.

Satu kamu nikmatin, satu kamu kerjain.
Pilih yang mana? 👀

[LINK_DAFTAR]
```

### 3C. HOOK ROKOK / PENGELUARAN HARIAN

```
Rokok sehari Rp 25.000 → sebulan Rp 750.000. Nol kembali.

Rp 150.000 sekali di Synapse → Rp 7.500/hari × 25 hari = Rp 187.500.

Hitung sendiri mana yang balik. 🚬→💻

[LINK_DAFTAR]
```

### 3D. HOOK "SEMUA ORANG NGOMONGIN AI"

```
Semua orang ngomongin AI. 🤖
Hampir nggak ada yang dibayar dari AI.

Kamu bisa. Sewa daya GPU-nya, klaim hariannya, tarik ke DANA. Mulai Rp 150.000.

[LINK_DAFTAR]
```

### 3E. HOOK FOMO BATCH

```
Batch node GPU kemarin penuh dalam 3 hari. ⏳

Yang telat, nunggu batch berikutnya.
Yang gerak, udah klaim harian ke-3 hari ini.

Masih ada slot. Belum lama. 👇
[LINK_DAFTAR]
```

### 3F. HOOK CURIOSITY (pancing pertanyaan)

```
Ada yang aneh dari penghasilan saya minggu ini. 🤔

Nggak dari jualan. Nggak dari endorse.
Dari 4 kotak GPU yang saya sewa, yang kerja sendiri tiap hari.

Nanti saya cerita. Atau kamu cek duluan: [LINK_DAFTAR]
```

### 3G. CAPTION REELS / TIKTOK (dengan arahan visual)

```
[VISUAL: gelap, cahaya biru server, teks besar di layar]
Teks layar: "Rp 150.000"

[VO/teks]: "Ini bukan buat beli kopi."
[Cut: dashboard Synapse, angka bergerak]
[VO/teks]: "Ini buat sewa 1 node GPU AI."
[Cut: Rp 7.500 muncul]
[VO/teks]: "Yang bayar kamu tiap hari. 25 hari."
[Teks akhir]: "Cek sendiri. Gratis daftar."

#GPU #AI #PassiveIncome #CuanHarian #Synapse
[LINK_DAFTAR]
```

---

## 4. BANK HOOK & CTA (campur-tempel untuk variasi cepat)

**Hook pembuka (pilih 1):**
- "Rp 150.000 itu seharga 2 boba. Bedanya, yang ini balik."
- "AI butuh GPU. Kamu bisa jadi yang menyewakannya."
- "Penghasilan saya nggak datang dari kerja 9-5. Datang dari 4 kotak di server."
- "Yang telat batch lalu nunggu sebulan. Yang gerak udah klaim."
- "Nggak butuh ngerti AI. Butuh ngerti hitung-hitungan aja."

**CTA (pilih 1):**
- "Daftar gratis dulu — lihat dashboard sebelum putuskan. [LINK_DAFTAR]"
- "Ada pertanyaan? Tanya langsung di grup. [GRUP_WA]"
- "Slot terbatas per batch. Amankan sekarang. [LINK_DAFTAR]"
- "Cek katalognya 30 detik, murah kok dilihatnya. [LINK_DAFTAR]"

---

## 5. PLACEHOLDER YANG WAJIB DIGANTI

| Tag | Isi |
|---|---|
| `[LINK_DAFTAR]` | URL registrasi (`http://synapse.test/register` atau domain produksi) |
| `[GRUP_WA]` | Link grup WhatsApp komunitas resmi — **ambil dari `system_settings.wa_group_link`** (kelola via admin: Settings → Kontak & Bantuan), JANGAN hardcode di materi promosi |
| `[NAMA_KAMU]` | Nama/username promoter (untuk versi personal) |
| `[KODE_REFERRAL]` | Kode/ID referral milik promoter |

**Catatan penting soal link grup:** link WA grup bersifat dinamis & bisa berganti (plan/105). Selalu ambil dari halaman `/help` member atau setting admin saat menyusun materi baru — link grup yang mati = lead hangus.

---

## 6. ARAHAN PEMAKAIAN (agar tetap kredibel)

1. **Jangan potong bagian catatan jujur.** Blok "catatan penting" (klaim harian, jam operasional, biaya) bukan sekadar etika — itu yang membuat copy *tidak* terasa seperti scam, dan justru menaikkan konversi dari audiens skeptis.
2. **Angka keuntungan selalu pakai kata "potensi/estimasi/simulasi".** Regulasi & kepercayaan audiens Indonesia makin sensitif pada klaim "dijamin".
3. **Satu pesan = satu ajakan.** Untuk broadcast, pilih CTA utama; jangan taruh 4 link sekaligus.
4. **Sesuaikan dengan status batch.** Kalimat "batch terbatas" hanya dipakai kalau memang benar — kalau selalu dipakai, audiens lelah dan kredibilitas turun.
5. **Personalisasi versi affiliate.** Ganti angka contoh dengan jaringan nyata; leader percaya pada spesifik, bukan generik.
6. **Jangan pakai kata "investasi" di materi publik kalau ingin aman secara regulasi** — gunakan "sewa aset komputasi" / "bagi hasil sewa node". Ini juga lebih akurat dengan produk sebenarnya (kamu menyewakan kapasitas GPU, bukan menyetor dana).

---

## 7. FOLLOW-UP (di luar scope, rekomendasi)

- **Sinkronkan `docs/1_PRD.md` §G** dengan `User_model::WAGE_TIERS` (9/30/70/130/190). Saat ini PRD menulis 9/18/40/90/190 — materi promosi memakai angka kode.
- Siapkan **halaman landing `/promo`** dengan angka paket dinamis dari `gpu_products` agar materi promosi tidak perlu di-edit manual saat harga berubah.
- Buat **template gambar/banner** (feed 1080×1080, story 1080×1920) mengikuti palet auth/dashboard (obsidian + cyan/violet) untuk konsistensi brand.
- Pertimbangkan **UTM tracking** pada `[LINK_DAFTAR]` per kanal agar efektivitas tiap format terukur.
