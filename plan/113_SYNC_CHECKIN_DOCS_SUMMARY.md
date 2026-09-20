# PLAN 113 — SUMMARY: SINKRONISASI DOKUMENTASI FITUR ABSENSI HARIAN (PLAN 112)

**Status:** ✅ SELESAI — V1–V9 dijalankan, seluruhnya lulus
**Tanggal:** 2026-09-20
**Plan:** `plan/113_SYNC_CHECKIN_DOCS_PLAN.md`
**Keputusan owner:** `dec-c38d618a49de30aa` (D-A relokasi blok milestone · D-B **Card 6** sesuai kode · D-C hanya koreksi rujukan versi)
**Branch:** `docs/113-sync-checkin-ground-truth`
**Sifat pekerjaan:** **dokumen saja** — nol perubahan kode (`application/`, `system/`,
`database.sql`, `database_seed.sql`, `scripts/`, kamus i18n).

---

## 1. Berkas yang berubah

| # | Berkas | Jenis | Δ (+/−) | Baris akhir | Isi |
|---|---|---|---|---|---|
| 1 | `plan/113_SYNC_CHECKIN_DOCS_PLAN.md` | baru | — | 547 | Blueprint (disetujui; E0) |
| 2 | `docs/2_ERD.md` | ubah | +46 / −2 | 599 | **v5.1 → v5.2**: 2 kolom `users`, 4 kunci `system_settings`, konvensi `CHK-`, invariant C1–C5 |
| 3 | `docs/4_UI_UX_GUIDELINES.md` | ubah | +82 / −3 | 588 | **v5.1 → v5.2**: §5.**I** widget `.hm112-card`, §8.**D** kartu admin (Card 6), §2 z-index, §7 animasi |
| 4 | `docs/1_PRD.md` | ubah | +5 / −3 | 390 | §4.F: kontrak endpoint + urutan TX; 3 koreksi rujukan versi (tetap v5.2) |
| 5 | `docs/3_ROADMAP.md` | ubah | +15 / −12 | 298 | Relokasi blok plan/112 ke akhir log + 2 poles + catatan header plan/113 + 1 rujukan |
| 6 | `plan/113_SYNC_CHECKIN_DOCS_SUMMARY.md` | baru | — | — | Ringkasan + matriks bukti ini |

**Perintah bukti diff:**

```
$ git diff --numstat
5       3       docs/1_PRD.md
46      2       docs/2_ERD.md
15      12      docs/3_ROADMAP.md
82      3       docs/4_UI_UX_GUIDELINES.md

$ git diff --name-only | grep -v '^docs/'   # → (kosong)
```

Nol berkas di luar `docs/` (dan `plan/113_*`) tersentuh — termasuk nol diff pada
`application/**`, `system/**`, `database.sql`, `database_seed.sql`, `scripts/**`.

---

## 2. Perubahan per dokumen

### 2.1 `docs/2_ERD.md` → **v5.2** (E2)

| Lokasi | Perubahan |
|---|---|
| Header (baris 1) | Judul `Database Schema v5.1` → **`v5.2`** |
| Header (baris 8–16) | **Blok catatan sinkronisasi v5.2 baru** (plan/112 + plan/113): 2 kolom `users`, 4 kunci `checkin_*`, `CHK-{user_id}-{Ymd}`, **14 tabel kanonik tetap**; blok v5.1 dipertahankan di bawahnya |
| §0 Mermaid `users` (baris 57–58) | `INTU checkin_streak` + `DATE checkin_last_date` (komentar plan/112), konvensi tipe mengikuti `SMALLINTU`/`TINYINT1` yang sudah ada |
| §0 Mermaid `system_settings`/relasi | **tidak diubah** — tidak ada entitas/FK baru |
| §2 Tabel `users` (baris 249–250) | 2 bullet kolom baru (`checkin_streak`, `checkin_last_date`) |
| §2 Tabel `users` (baris 251) | Blok **Invariant absensi harian**: otoritas PHP WIB, fail-closed tanggal masa depan, tanpa index tambahan, histori = derivasi `LIKE 'CHK-%'` (tanpa kolom total) |
| §3 `wallet_ledger` (baris 352) | Daftar contoh `transaction_id` + **`CHK-{user_id}-{Ymd}`** |
| §3 `wallet_ledger` (baris 360) | Blok **Idempotensi harian absensi**: `uk_wallet_ledger_user_tx_type` → maks satu kredit/user/hari; 1062/23000 → `already_claimed`; deskripsi kanonik + pola `ledger_checkin` |
| §3 `wallet_ledger` (baris 368) | Blok **C4/M8**: kredit absensi lewat satu-satunya jalur `Wallet_model::credit()` |
| §4 `system_settings` (baris 447–450) | 4 baris key: `checkin_enabled` (`'0'`/`'1'`, fail-closed 403) · `checkin_base_reward` (`1 … 1.000.000`) · `checkin_max_reward` (`1 … 10.000.000`) · `checkin_streak_policy` (`reset`/`continue`) |
| §4 `system_settings` (baris 454–466) | Paragraf **fallback absensi harian**: `config/checkin_rewards.php`, invarian `1 ≤ base ≤ max` + fallback **atomik**, ambang satu sumber `BASE_MAX`/`MAX_MAX`, kontrak validator `{ok,errors,notices,field_errors,values}`, `migrate_112 --verify` → exit 2 |
| §7 (baris 593–598) | Sub-blok baru **“Invariant arsitektural tambahan (plan/112 — Daily Check-in)”**: C1 idempotensi harian DB · C2 fail-closed dua sisi · C3 otoritas PHP WIB · C4 tanpa tabel/index/kolom total · C5 satu jalur uang + TX terkunci |

### 2.2 `docs/4_UI_UX_GUIDELINES.md` → **v5.2** (E3)

| Lokasi | Perubahan |
|---|---|
| Header (baris 1) | Judul `Guidelines v5.1` → **`v5.2`** |
| Header (baris 6–10) | Catatan sinkronisasi v5.2 (plan/112 + plan/113); catatan v5.1 dipertahankan |
| §2 Z-Index Layering (baris 39) | Layer `z-[60]` kini mencakup **Toast notifikasi member** (`.u-toast`, mis. `#hm112-toast`); rule diperjelas “jangan memperkenalkan layer liar” |
| §5.**I** BARU (baris 313–353, setelah §5.H) | **“Member Dashboard Check-in Widget (`.hm112-card`) — plan/112”**: lokasi STRICT (antara hero plan/99 & kartu identitas), fail-closed DOM, tabel 9 tingkat hierarki visual (badge → chip `fa-fire` → nominal → bar cap `intdiv` ceil → strip 7 hari `grid-cols-7` → countdown WIB dari epoch server → tombol → hint → `aria-live`), label timer dua-keadaan, tabel state tombol (**pulse vs `disabled`**), alur klaim `csrfFetch` + repaint dari `status` server, toast `z-[60]`, a11y, tema `--u-*`/`html.dark`, scoped CSS + `prefers-reduced-motion`, aturan uang/i18n (L6/P3), Font Awesome only, 4 anti-pattern |
| §7 Animation (baris 447) | Butir baru: **check-in claim pulse** 2.4 s `ease-out` `infinite`, hanya saat tombol aktif, mati saat `disabled` & reduced-motion |
| §8.**D** BARU (baris 558–586, setelah §8.C) | **“Check-in Settings — Admin (`/admin/settings`, Kartu 6) — plan/112”**: STRICT **kartu ke-6 dari 6** (urutan kartu didaftarkan), posisi di dalam `form_open` (CSRF + `data-guard-submit`), copy 100% Indonesia (L1), tabel 5 elemen layout, tabel semantik dropdown `reset`/`continue` (**N+1, gap dibekukan**), error inline blocking (`$checkin_errors` per-prefix) + all-or-nothing, aturan repopulasi `settings_form_state`, persist via `Admin_model::update_system_settings()` + audit otomatis, 4 anti-pattern |

### 2.3 `docs/1_PRD.md` — tetap **v5.2** (E4)

| Lokasi | Perubahan |
|---|---|
| §4.F (setelah bullet “UI member”, baris 219–220) | **2 bullet baru:** (a) **Kontrak endpoint** — POST+AJAX-only (`show_404()` selain itu), rate limit `checkin_claim:{user_id}` **5/60** → 429, envelope `api_*` + key legacy, peta kode→HTTP: `ok`/`already_claimed`/`user_unavailable` (200), `disabled` (**403**), `error` (500), `unauthenticated` (**401**), `status` segar pada sukses & penolakan; (b) **Urutan TX yang mengikat** — `users FOR UPDATE` → hitung hari → `UPDATE` kondisional + `affected_rows() === 1` → `credit()` → commit, `db_debug` lokal + `error()` dibaca sebelum rollback sehingga 1062/23000 tetap JSON |
| §5 (baris 296 → kini 298) | Rujukan `docs/4_UI_UX_GUIDELINES.md (v5.1)` → **`(v5.2)`** |
| §4.H (baris 252 → 254) | `see ERD v5.1 §5` → **`see ERD v5.2 §5`** |
| §8 (baris 376 → 378) | `see ERD v5.1 §5` → **`see ERD v5.2 §5`** |

> Konten check-in §4.F (baris 212–218) **sudah akurat** sejak plan/112 — diverifikasi
> ulang, tidak ditulis ulang (lihat §3 V1). Yang ditambahkan hanya **delta** di atas.

### 2.4 `docs/3_ROADMAP.md` — tetap **v6.0** (E5)

| Lokasi | Perubahan |
|---|---|
| Log milestone | **Blok plan/112 direlokasi** dari posisi lama (antara plan/100 & plan/105) ke **akhir log milestone** — kini baris 276–284, **setelah plan/110** dan **sebelum `## Upcoming Phases`** (D-A) |
| Blok plan/112 — bullet Admin | + penanda **“Card 6 dari 6”** (konsisten D-B) |
| Blok plan/112 — bullet Kamus | + keterangan pola renderer deskripsi ledger di `application/helpers/i18n_helper.php` |
| Header (baris 12–16) | Satu kalimat baru: **plan/113** menyinkronkan ERD v5.2 & UI/UX v5.2 (versi ROADMAP tetap v6.0) + rujukan ringkasan plan/113 |
| Phase 7D1 (baris 85) | Rujukan `(ERD v5.1 §5)` → **`(ERD v5.2 §5)`** |
| Rujukan historis | **Dibiarkan apa adanya** (baris 31 “ERD v5.0”, baris 108 “PRD v5.1 §4.G”, anotasi plan/111) — konteks waktu yang benar |

**Bukti relokasi** (`git diff docs/3_ROADMAP.md`): hunk `-` di posisi lama dan hunk
`+` di akhir log berisi **teks yang sama**, berbeda hanya pada 2 poles di atas —
tidak ada baris bukti historis yang hilang.

---

## 3. Matriks verifikasi (hasil yang benar-benar dijalankan)

| # | Kriteria plan | Perintah / metode | Hasil teramati | Status |
|---|---|---|---|---|
| **V1** | Tidak ada klaim bertentangan dengan kode | Grep silang setiap kolom/key/angka baru di `docs/` vs `database.sql` + `Checkin_model.php` + view | **0 mismatch** (bukti rinci di V2–V6) | ✅ |
| **V2** | 2 kolom `users` di ERD ≡ DDL | `grep -n checkin_streak\|checkin_last_date database.sql docs/2_ERD.md` | `database.sql:31-32` = `ERD:57-58` (Mermaid) = `ERD:249-250`; tipe & default identik (`INT UNSIGNED NOT NULL DEFAULT 0` / `DATE NULL DEFAULT NULL`) | ✅ |
| **V3** | 4 key `system_settings` di ERD ≡ seed | Diff tabel ERD §4 vs `database.sql:406-409` + `database_seed.sql:322-325` | 4/4 key ada dengan default sama (`1`/`50`/`10000`/`reset`); **0 key hilang, 0 key fiktif** | ✅ |
| **V4** | Ambang validasi di ERD ≡ kode | Diff vs `Checkin_model.php:29-30` | `BASE_MAX = 1000000`, `MAX_MAX = 10000000` → ERD menulis `1 … 1.000.000` / `1 … 10.000.000` + invarian `1 ≤ base ≤ max` → **identik** | ✅ |
| **V5** | Invariant §7 ≡ `claim()` | Diff `CHK-{user_id}-{Ymd}` + `uk_wallet_ledger_user_tx_type` | `database.sql:206` (index) & `Checkin_model.php:20, 410, 521` → ERD baris 13/352/358/360/595 → **identik** (termasuk translasi `1062, 23000` → `already_claimed`) | ✅ |
| **V6** | §5.I & §8.D ≡ view | 12 id/class widget + 7 field admin di-grep di view & dokumen | Semua simbol widget ada di **kedua** sisi (`hm112-card`, `hm112-amount`, `hm112-bar`, `hm112-timer`, `hm112-toast`, `hm112-claim`, `hm112-day-next`, `hm112-pulse`, `data-window-ts`, `grid-cols-7 gap-1`, `u-progress-track`, `z-[60]`); `grep -c '<!-- Card [0-9]' settings.php` = **6** → “kartu ke-6 dari 6” benar; batas input `max="1000000"`/`max="10000000"` & `data-guard-submit` ada di view | ✅ |
| **V7** | Tidak ada referensi mati | Grep `Card 7`, `user_checkins`, `checkin_total`, `checkin_reward`, `cron.*checkin` di `docs/` | **0 temuan** di kelima berkas `docs/` | ✅ |
| **V8** | Rujukan versi konsisten | Grep `ERD v5.1`, `GUIDELINES.md (v5.1)`, `Schema v5.1`; grep versi baru | **0 rujukan usang tersisa**; `ERD v5.2` ada di `1_PRD.md:254`, `1_PRD.md:378`, `3_ROADMAP.md:85`; `Schema v5.2` di `2_ERD.md:1`; UI/UX v5.2 di header + `1_PRD.md:298` | ✅ |
| **V9** | Diff bersih | `git status --short` + `git diff --stat` + `git diff --name-only \| grep -v '^docs/'` | Hanya **4 berkas `docs/`** yang termodifikasi + 2 berkas `plan/113_*`; **nol** berkas kode/skema/seed/script | ✅ |

### 3.1 Gate yang **tidak** dijalankan (jujur)

| Gate | Alasan |
|---|---|
| `php -l` | **Nol** berkas PHP berubah → tidak ada yang bisa di-lint |
| `scripts/audit_i18n_parity.php` / `audit_i18n_hardcoded.php` | **Nol** perubahan copy member / `application/language/**` / view → gate tidak relevan |
| `scripts/migrate_112_daily_checkin.php --verify` | Nol perubahan skema; migrasi plan/112 sudah dijalankan & diverifikasi di plan/112 sendiri |
| HTTP smoke / `curl` | Nol perubahan runtime |
| Test suite | **Repo ini tidak punya test suite** — tidak ada klaim apa pun soal ini |

---

## 4. Non-goals yang tetap tidak dikerjakan

1. `Checkin_model::get_lifetime_claims()` **belum punya konsumen UI** — tidak
   ditambahkan surface baru (halaman riwayat absensi tetap non-goal plan/112 §7).
2. `scripts/audit_i18n_hardcoded.php` masih memindai `application/logs/*` —
   rekomendasi pengecualian **tidak** dilaksanakan (kerja kode, di luar scope).
3. Blok milestone **plan/111** tetap tidak ada di log ROADMAP (keputusan D-C).
4. Rujukan historis “ERD v3.0/v5.0” (`2_ERD.md:519`, `3_ROADMAP.md:31`) dan
   “PRD v5.1 §4.G” (`3_ROADMAP.md:108`) **dibiarkan** — konteks waktu yang benar.
5. `AGENTS.md` dan `docs/5_AUDIT_REPORT.md` tidak disentuh.
6. Nol perubahan kode/skema/seed/route/migrasi/i18n.

---

## 5. Definition of Done — status

- [x] `docs/2_ERD.md` **v5.2**: 2 kolom `users`, 4 kunci `checkin_*` (+ ambang &
      fallback), konvensi `CHK-{user_id}-{Ymd}` di `wallet_ledger`, invariant C1–C5.
- [x] `docs/4_UI_UX_GUIDELINES.md` **v5.2**: §5.I (widget `.hm112-card`, lokasi,
      hierarki, state tombol, scoped CSS + Font Awesome, fail-closed) & §8.D
      (kartu **Card 6**, layout, dropdown, error inline, repopulasi), plus §2 & §7.
- [x] `docs/1_PRD.md` §4.F: kontrak AJAX (`POST /checkin/claim`, rate limit 5/60,
      peta kode→HTTP, envelope `api_*`, anti-double-claim 1062→`already_claimed`)
      dan semantik `continue` = **N+1 (gap dibekukan)** eksplisit.
- [x] `docs/3_ROADMAP.md`: blok plan/112 **berurutan** di akhir log milestone +
      catatan header plan/113.
- [x] Rujukan versi lintas-dokumen konsisten (0 `v5.1` tersisa sebagai referensi aktif).
- [x] Nol perubahan pada `application/**`, `system/**`, skema, seed, route, migrasi, i18n.
- [x] V1–V9 lulus dengan bukti perintah yang dicatat apa adanya (§3).
- [x] Diff akhir hanya 4 berkas `docs/` + 2 berkas `plan/113_*`.

---

## 6. Kebersihan lingkungan

- **Nol scratch**: tidak ada skrip probe, keluaran HTML/JSON, atau berkas sementara
  yang dibuat — seluruh verifikasi memakai `read_file`/`grep` di dalam repo.
- **Nol berkas uji di `/tmp`** yang perlu dibersihkan.
- Working tree hanya memuat perubahan yang diniatkan (lihat V9).
- Branch kerja: `docs/113-sync-checkin-ground-truth`.
