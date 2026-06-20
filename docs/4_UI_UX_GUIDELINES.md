# UI/UX & Component Guidelines v3.0 (Strict AI Specification)
**Project Name:** Synapse
**CSS Framework:** Tailwind CSS (Strictly Utility Classes)
**Design Philosophy:** Minimalist, High-Density Data Presentation, Bloomberg Terminal Aesthetic, Mobile-First.

---

## 1. Global Layout & Spatial Geometry
Aplikasi harus terasa seperti *Native Mobile App* (iOS/Android) meskipun diakses via *browser* desktop.
* **Master Wrapper (Body):** `bg-slate-100 flex justify-center min-h-screen font-sans antialiased text-slate-800`
* **App Container:** `<div class="w-full max-w-[480px] bg-white min-h-screen relative shadow-2xl overflow-x-hidden pb-20">`
    * *Catatan:* `pb-20` wajib ada untuk memberi ruang pada *Bottom Navigation* agar konten terbawah tidak tertutup.
* **Top Header/App Bar:** Ketinggian tetap `h-14`, `flex items-center justify-between px-4`, latar putih dengan batas bawah tipis `border-b border-slate-100`, dan `sticky top-0 z-40 bg-white/90 backdrop-blur-sm`.
* **Bottom Navigation:** Ketinggian tetap `h-16`, `fixed bottom-0 w-full max-w-[480px] bg-white border-t border-slate-200 z-50 flex justify-around items-center`. Ikon menggunakan SVG ukuran `w-6 h-6`. Active state: `text-blue-600 font-bold`.

---

## 2. Z-Index Layering Standard

Strict z-index management is critical to prevent UI overlap issues. The following hierarchy MUST be respected:

| Layer | z-index | Component |
|-------|---------|-----------|
| Content | `z-10` / `z-20` | Page content, card overlays, decorative elements |
| Sticky Header | `z-40` | Top App Bar (`sticky top-0`) |
| Bottom Navigation | **`z-50`** | Fixed bottom nav bar |
| Bottom Sheet Modal | **`z-[60]`** | All modal overlays, transaction sheets, confirmation dialogs |
| Modal Backdrop | `z-[59]` | Overlay behind sheet (same modal container) |

> **Rule:** Any Bottom Sheet Modal MUST use `z-[60]` on its container (`<div id="...Modal" class="fixed inset-0 z-[60] hidden">`). This ensures it renders above the Bottom Navigation (`z-50`) and does not conflict with the sticky header (`z-40`).

> **Anti-pattern:** Using `z-50` on modals will cause them to render BEHIND the Bottom Navigation on certain scroll positions. Always use `z-[60]` for modals.

---

## 3. Color Palette & Theming

### Primary Theme: Dark Professional (Bloomberg Terminal Aesthetic)
For data-heavy financial components, a dark, high-contrast aesthetic is mandatory to convey authority and precision.

* **Financial Card Background:** `bg-slate-900` — deep dark surface for balance displays and key metrics.
* **Financial Card Text:** `text-white` — high-contrast white text on dark backgrounds.
* **Financial Card Labels:** `text-slate-400` — muted labels that don't compete with primary values.
* **Status Badge (Active):** `bg-emerald-400/10 text-emerald-400` — subtle green glow on dark background.

### Secondary Theme: Light UI (General Pages)
For forms, lists, and non-financial content.
* **Backgrounds:**
    * Main App Background: `bg-white`
    * Card/Section Background: `bg-slate-50` atau `bg-slate-100` (untuk membedakan lapisan konten).
* **Primary Brand (Aksen Korporat):**
    * Solid: `bg-blue-600` (untuk tombol utama/Call to Action).
    * Hover/Active State: `bg-blue-700` atau `bg-blue-50`.
    * Teks Aktif: `text-blue-600`.

### Semantic Colors (Financial Indicators)
* **Income/Success/Active:** `text-emerald-500` & `bg-emerald-50` (light theme) / `text-emerald-400` (dark theme).
* **Expense/Withdrawal:** `text-rose-500` & `bg-rose-50` (light theme) / `text-rose-400` (dark theme).
* **Pending/Processing:** `text-amber-500` & `bg-amber-50` (light theme) / `text-amber-400` (dark theme).

### Typography Colors
* **Judul Utama/Saldo:** `text-slate-900`.
* **Teks Paragraf/Label:** `text-slate-500`.
* **Placeholder Form:** `text-slate-300`.

---

## 4. Typography & Data Density
Mengingat ini adalah platform dengan data finansial yang masif (harga sewa, ROI harian, saldo), pengaturan huruf adalah prioritas mutlak.
* **Font Family:** Standar *sans-serif* (`font-sans`).
* **Financial Numbers:** Setiap angka yang menampilkan saldo atau uang WAJIB menggunakan *utility* `tabular-nums` dan `tracking-tight` agar lebar angka konsisten dan sejajar rapi saat dibuat dalam bentuk daftar/tabel.
* **IDR Formatting (Frontend):** Gunakan `new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })` di JavaScript untuk format konsisten. Di PHP views: `number_format($value, 0, ',', '.')` dengan prefix `Rp `.
* **Text Sizing System:**
    * Big Balance/Display: `text-3xl font-bold tracking-tight text-slate-900` (light) atau `text-3xl font-bold font-mono tracking-tight text-white` (dark/terminal).
    * Page Title: `text-lg font-semibold text-slate-800`.
    * Normal Body: `text-sm text-slate-600`.
    * Micro Labels (Status/Date): `text-[11px] uppercase tracking-wider text-slate-400 font-medium`.

---

## 5. Component Standards

### A. Buttons (Tombol)

**Primary Button:**
```html
w-full h-12 bg-blue-600 text-white rounded-xl font-semibold flex items-center justify-center
transition-colors active:bg-blue-700 disabled:bg-blue-300 disabled:cursor-not-allowed
```

**Secondary Button:**
```html
w-full h-12 bg-slate-100 text-slate-700 rounded-xl font-semibold hover:bg-slate-200
```

**Micro Button (Aksi kecil di dalam list):**
```html
px-3 py-1.5 bg-blue-50 text-blue-600 rounded-lg text-xs font-semibold
```

**Active State Button (amount selection / toggle):**
Buttons that toggle between active/inactive states MUST explicitly swap background and text colors to maintain readability at all times:
* **Inactive state:** `bg-slate-50 border border-slate-200 text-slate-700`
* **Active state:** `bg-blue-600 border border-blue-600 text-white shadow-md`

> **Rule:** Never use semi-transparent backgrounds on active buttons. Always use solid fills with high-contrast text. `text-white` on `bg-blue-600` — never `text-blue-600` on `bg-blue-100` for primary actions.

**Destructive/Warning Button (Wallet redirect):**
```html
w-full h-14 bg-rose-500 hover:bg-rose-600 text-white rounded-2xl font-bold shadow-lg transition-all
```

### B. Input Forms
* **Text/Password/Number Inputs:** `w-full h-12 px-4 rounded-xl border border-slate-200 bg-slate-50 text-slate-800 text-sm focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-600 transition-all`.
* **Form Group Spacing:** Selalu gunakan `space-y-4` antar elemen input. Beri label di atas input dengan `text-xs font-semibold text-slate-700 mb-1 block`.

### C. Cards (Kartu Produk/Riwayat)

**Product Card (Marketplace):**
```html
bg-white rounded-2xl p-4 shadow-sm border border-slate-100 flex flex-col
```
Layout: Image → Header (name) → Description → Data Grid (Price vs. ROI) → Action Button. Price and ROI displayed in a `flex justify-between` row with micro labels above each value.

**Financial Balance Card (Bloomberg Terminal Aesthetic):**
```html
bg-slate-900 text-white p-6 rounded-2xl shadow-xl relative overflow-hidden
```
Includes a subtle CSS grid overlay pattern (`opacity-5`) for terminal feel. Balance displayed in `text-3xl font-bold font-mono tracking-tight`. Action buttons (Top Up / Tarik Dana) rendered as a `flex gap-2` row below the balance.

**Transaction/Ledger Item:**
```html
px-5 py-3 flex items-center justify-between hover:bg-slate-50 transition
```
Left: icon circle (emerald for credit, rose for debit) + description + timestamp. Right: amount with sign prefix (`+`/`-`) and `font-mono` for alignment.

### D. Bottom Sheet Modal (One-Screen Checkout)

The Bottom Sheet Modal is the primary checkout mechanism in the Marketplace. It MUST follow this exact specification:

**Container:**
```html
<div id="transactionModal" class="fixed inset-0 z-[60] hidden">
```

**Overlay (tap to close):**
```html
<div id="modalOverlay" class="absolute inset-0 bg-black/60 backdrop-blur-sm transition-opacity"></div>
```

**Sheet Body (slides up from bottom):**
```html
<div id="modalSheet" class="absolute bottom-0 w-full max-w-[480px] mx-auto bg-white rounded-t-3xl p-6 pb-12
     translate-y-full transition-transform duration-300 ease-out shadow-2xl">
```

**Animation:**
* Open: remove `hidden`, then set `sheet.style.transform = 'translateY(0)'` via `requestAnimationFrame`.
* Close: set `sheet.style.transform = 'translateY(100%)'`, then add `hidden` after 300ms transition completes.

**Content Structure:**
1. Drag handle: `w-10 h-1 bg-slate-300 rounded-full mx-auto mb-5`
2. Title: "Detail Transaksi" — `text-lg font-bold text-slate-900`
3. Info rows: Product name, Price (IDR-formatted), Separator (`h-px bg-slate-100`), User balance.
4. Action button container: dynamically rendered based on balance check.

**Balance-Dependent Action Rendering (Vanilla JS):**
* `userBalance >= price`: Render a `<form>` with POST to `/rentals/create`, submit button `bg-blue-600 text-white`. Balance indicator: `text-emerald-600`.
* `userBalance < price`: Render an `<a>` link to `/wallet`, styled `bg-rose-500 text-white` with text "Saldo Tidak Mencukupi — Isi Saldo". Balance indicator: `text-rose-600`.

### E. Toggle-Hidden Form Pattern (Clean Dashboard Rule)

Secondary input forms (Top-Up amount selection, custom amount input) MUST be hidden by default and revealed via a primary action button. This conserves screen real estate on mobile.

**Implementation:**
1. The form container has `class="hidden"` by default.
2. A primary button (e.g., "Top Up") triggers a JS toggle: `container.classList.toggle('hidden')`.
3. On reveal, scroll into view: `container.scrollIntoView({ behavior: 'smooth', block: 'nearest' })`.
4. The button label/icon should visually indicate the toggle state (e.g., `fa-plus` icon).

```html
<button id="btn-toggle-topup" class="flex-1 bg-blue-600 ...">
    <i class="fas fa-plus mr-1"></i> Top Up
</button>
<div id="topup-form-container" class="hidden transition-all duration-300 ease-in-out origin-top ...">
    <!-- form content -->
</div>
```

---

## 6. Animation & Transition Standards

| Element | Property | Duration | Easing |
|---------|----------|----------|--------|
| Bottom Sheet Modal | `transform: translateY` | 300ms | `ease-out` |
| Toggle forms | `max-height` / `opacity` | 300ms | `ease-in-out` |
| Button press | `transform: scale` | 150ms | default |
| Card hover | `background-color` | 200ms | default |
| Flash messages | `animate-bounce-in` | custom | — |

* **Button tactile feedback:** `active:scale-[0.98]` on product "Sewa Sekarang" buttons.
* **Modal backdrop:** `transition-opacity` for fade in/out.
* **Form toggle:** `origin-top` transform origin for natural expand feel.
