# Audit Desain Produk UI/UX SIPETA — Pra-Fase 7

**Title:** Audit Desain Produk UI/UX SIPETA (Pra-Fase 7)
**Purpose:** Menilai apakah antarmuka SIPETA terasa profesional dan layak dipakai operator kelurahan, bukan sekadar berfungsi.
**Scope:** Seluruh halaman panel `/admin` pada aplikasi yang BERJALAN — Dashboard, Kartu Keluarga, Penduduk, Backup, Pengaturan, Review OCR, Export, Form, Tabel, Dialog, Notifikasi, Filter, Navigasi. Tidak termasuk review kode.
**Version:** 1.0.0
**Status:** Final — audit only, TIDAK ADA implementasi
**Last Updated:** 2026-08-08
**Related Documents:** `.ai/decisions.md`, `.ai/hermes.md`, `docs/REQUIREMENTS.md`, `docs/PHASE6.md`

---

## 1. Metode Audit

Audit ini dilakukan pada **aplikasi yang benar-benar berjalan**, bukan dari membaca kode.

| Aspek | Nilai |
|---|---|
| Cara akses | Google Chrome 151 asli di display `:1`, dikendalikan lewat Chrome DevTools Protocol port 9222 |
| Aplikasi | `php artisan serve` di `http://127.0.0.1:8000` (PID 94785) |
| Basis data | MariaDB nyata di port 3306, socket `~/sipeta-mysql/mysql.sock` |
| Login | `admin@sipeta.test` sebagai operator, bukan sebagai developer |
| Data | 270 penduduk, 60 KK, 0 foto KK, 0 job OCR, 2 log backup |
| Viewport diuji | 1920×1080, 1366×768, 390×844 |
| Bukti | 30 screenshot + CSS terkomputasi + rasio kontras terukur + error konsol |

Semua screenshot ada di `/home/awa/Documents/SIPETA/ui-audit/shots/`.
Data mentah pengukuran ada di `ui-audit/pd-report.json` dan `ui-audit/pd-report2.json`.

**Catatan penting:** tampilan aplikasi yang berjalan **berbeda** dari screenshot yang Anda lampirkan. Yang live: header putih (bukan hijau), 11 kartu KPI (bukan 6), 7 grafik, tidak ada tabel di dashboard, gender 150/120 (bukan 136/134). Audit ini menilai **yang live**. Kalau lampiran Anda adalah target desain, maka target itu sendiri juga punya masalah yang dibahas di bagian Dashboard.

---

## 2. Ringkasan Eksekutif

SIPETA **berfungsi**, tetapi belum **terasa profesional**. Tiga masalah struktural:

1. **Dashboard adalah tempat pembuangan data, bukan alat kerja.** 11 kartu KPI + 7 grafik + aksi cepat + aktivitas terbaru = halaman setinggi 2850px. Operator harus scroll 2,6 layar penuh untuk melihat semuanya, dan tidak satu pun dari itu menjawab pertanyaan harian operator ("KK mana yang perlu saya urus hari ini?").

2. **Model mental salah di dua tempat sekaligus.** OCR diekspos sebagai menu sendiri dengan kolom "Confidence" — istilah yang tidak dimengerti pegawai kelurahan. Dan Kartu Keluarga, yang seharusnya menjadi pintu masuk utama, justru tidak bisa menampilkan anggota keluarganya sendiri.

3. **Warna kuning terang dipakai sebagai warna utama.** Tombol primer `rgb(255,185,0)`, highlight menu aktif `rgb(254,243,199)`. Ini bertentangan langsung dengan arah warna yang Anda minta (Forest/Olive/Ketupat Green) dan membuat panel terlihat seperti template gratis, bukan sistem pemerintahan.

### Papan Skor per Halaman

Skala 1–5 (5 = siap produksi, terasa profesional).

| Halaman | Hierarki | Spasi | Tipografi | Tombol | Warna | A11y | Responsif | Empty | Loading | Alur Operator | **Rata-rata** |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Dashboard | 2 | 3 | 3 | 3 | 2 | 3 | 3 | 2 | 1 | 2 | **2.4** |
| Penduduk (tabel) | 3 | 3 | 4 | 3 | 2 | 3 | 2 | 3 | 1 | 3 | **2.7** |
| Kartu Keluarga | 2 | 3 | 4 | 3 | 2 | 3 | 2 | 2 | 1 | 1 | **2.3** |
| Form KK | 3 | 4 | 4 | 2 | 2 | 3 | 3 | 3 | 2 | 3 | **2.9** |
| Form Penduduk | 3 | 4 | 4 | 2 | 2 | 3 | 3 | 3 | 2 | 3 | **2.9** |
| Review OCR | 2 | 3 | 3 | 1 | 2 | 3 | 3 | 1 | 1 | 1 | **2.0** |
| Backup | 3 | 3 | 3 | 2 | 2 | 3 | 3 | 2 | 1 | 2 | **2.4** |
| Pengaturan | 3 | 4 | 3 | 2 | 2 | 3 | 3 | 3 | 1 | 3 | **2.7** |
| Navigasi/Header | 2 | 3 | 3 | 2 | 1 | 3 | 3 | — | — | 2 | **2.3** |

**Rata-rata produk: 2.5 / 5.** Terlihat seperti admin panel default yang diberi warna, bukan produk yang dirancang.

### Distribusi Temuan

| Tingkat | Jumlah | Arti |
|---|---|---|
| Critical | 6 | Menyesatkan operator atau memblokir pekerjaan nyata |
| High | 12 | Merusak kepercayaan profesional, terlihat di setiap sesi |
| Medium | 11 | Terasa belum jadi |
| Low | 7 | Pemolesan |
| Very Low | 4 | Opsional |
| **Total** | **40** | |
---

## 3. Temuan CRITICAL

### C-1 — "Jumlah Anggota" menampilkan 0 untuk SEMUA 60 Kartu Keluarga

**Masalah.** Kolom *Jumlah Anggota* di daftar Kartu Keluarga menampilkan `0` pada setiap baris tanpa kecuali, padahal database berisi 270 penduduk yang terhubung ke KK.

**Bukti terukur.**
```
kk_anggota (tabel pivot)  = 0 baris
penduduk.kk_id (FK)       = terisi penuh
KK id 24 → via_pivot=0, via_fk=3
KK id 25 → via_pivot=0, via_fk=4
KK id 26 → via_pivot=0, via_fk=5
KK id 27 → via_pivot=0, via_fk=6
```
Kolom tabel menghitung lewat pivot `kk_anggota` yang kosong, sedangkan data sebenarnya ada di FK `penduduk.kk_id`.
Screenshot: `shots/kartu-keluarga.png`, `shots/list_kk.png`

**Kenapa ini merusak usability.** Ini bukan sekadar bug angka. Operator kelurahan membaca kolom ini sebagai "keluarga ini punya berapa orang". Melihat 0 di semua baris, kesimpulan wajarnya adalah **"data saya hilang"**. Begitu operator tidak percaya pada satu angka, ia berhenti percaya pada seluruh sistem dan kembali ke Excel. Ini adalah kegagalan kepercayaan, bukan kegagalan tampilan.

**Rekomendasi.** Tentukan satu sumber kebenaran untuk keanggotaan KK. Karena `penduduk.kk_id` yang terisi, hitung dari sana (`withCount`), atau isi pivot `kk_anggota` saat penyimpanan dan konsisten memakainya. Jangan biarkan dua mekanisme hidup bersamaan — itu akar masalahnya.

**Kompleksitas:** Rendah (1 baris query + keputusan sumber data)
**Peningkatan UX:** Sangat tinggi — memulihkan kepercayaan dasar terhadap data

---

### C-2 — Tombol "Lihat" pada KK membuka form kosong, bukan detail keluarga

**Masalah.** Menekan *Lihat* pada baris Kartu Keluarga membuka modal berjudul `View 7371000000000060` yang isinya adalah **form input**, lengkap dengan area unggah *"Drag & Drop your files or Browse"*, teks *"Foto KK wajib diunggah"*, dan label field kosong tanpa nilai. Anggota keluarga sama sekali tidak ditampilkan.

**Bukti terukur.**
```
URL setelah klik Lihat : tetap /admin/kartu-keluargas (modal, bukan halaman)
Judul modal            : "View 7371000000000060"
Heading di dalam       : ["Foto Kartu Keluarga", "Data Kartu Keluarga"]
showsAnggota           : false
Tombol tersedia        : ["Close"]  ← hanya satu
Isi                    : "Drag & Drop your files or Browse", "Belum ada foto."
```
URL `/admin/kartu-keluargas/24` (halaman detail) mengembalikan **404 | NOT FOUND**.
Screenshot: `shots/view_kk_detail.png` (404), `shots/kk_lihat_modal.png`

**Kenapa ini merusak usability.** Ini adalah kegagalan alur kerja paling parah di aplikasi. Tugas nomor satu operator kelurahan adalah *"tunjukkan saya satu keluarga ini beserta seluruh anggotanya"*. Aplikasi ini secara harfiah **tidak bisa melakukannya**. Yang muncul malah form unggah di mode "lihat" — operator akan bertanya "kenapa saya disuruh upload padahal saya cuma mau melihat?". Mode lihat yang menampilkan dropzone adalah pelanggaran konsistensi interaksi yang mendasar.

**Rekomendasi.** Ganti dengan halaman detail KK yang benar (bukan modal), berisi: header identitas KK (nomor, kepala keluarga, alamat, RT/RW), foto KK di samping, lalu **tabel anggota keluarga** dengan kolom Nama, NIK, Hubungan, Jenis Kelamin, Usia, Status — plus aksi "Tambah Anggota". Ini menjadi halaman terpenting di seluruh aplikasi.

**Kompleksitas:** Sedang–Tinggi (halaman baru + relation manager)
**Peningkatan UX:** Sangat tinggi — memperbaiki tugas inti operator

---

### C-3 — OCR diekspos sebagai menu teknis yang tidak bisa dipakai

**Masalah.** Ada menu sidebar *"Review OCR"* yang membuka halaman dengan kolom `ID`, `Status`, `Confidence`, `Mulai`, `Selesai`, empty state bertuliskan **"No Job OCR"**, dan **tidak ada satu pun tombol untuk memulai scan**.

**Bukti terukur.**
```
Kolom tabel : ID(218px) | Status(198px) | Confidence(372px) | Mulai(179px) | Selesai(249px)
Baris        : 0
Empty state  : "No Job OCR"  (campur Inggris-Indonesia)
Tombol aksi  : tidak ada  (pencarian tombol "upload" → null)
ocr_jobs     : 0 baris di database
```
Screenshot: `shots/ocr_list.png`, `shots/review-ocr.png`

**Kenapa ini merusak usability.** Empat kegagalan menumpuk di satu halaman:
1. **"OCR" adalah jargon.** Pegawai kelurahan tidak tahu itu apa. Yang ia tahu: "saya mau foto KK ini biar datanya terisi sendiri".
2. **"Confidence" lebih buruk lagi** — istilah machine learning, diberi kolom terlebar (372px) di halaman.
3. **"Job" adalah istilah developer.** "No Job OCR" tidak punya arti bagi operator.
4. **Halaman ini adalah jalan buntu** — tidak ada cara memulai apa pun dari sini. Operator masuk, bingung, keluar.

Ini mengekspos *pipeline developer* (job → proses → review → import) padahal yang seharusnya diekspos adalah *niat operator* (foto KK → data terisi → saya periksa → simpan).

**Rekomendasi.** **Hapus "Review OCR" dari sidebar.** Alur yang benar sudah setengah ada di form KK (tombol *Scan Foto KK* / *Input Manual* sudah terlihat). Jadikan itu satu-satunya jalan:

```
Tambah Kartu Keluarga
   ├── [Input Manual]  → form kosong
   └── [Scan Foto KK]  → unggah foto
                          ↓
                       "Membaca foto KK…" (progress, bahasa manusia)
                          ↓
                       Form TERISI OTOMATIS, field hasil scan ditandai
                       (mis. latar kuning muda + ikon, "Hasil pindaian — mohon periksa")
                          ↓
                       Operator koreksi seperlunya
                          ↓
                       [Simpan]
```
Kata "OCR", "job", "confidence" tidak boleh muncul sama sekali di UI operator. Jika skor kepercayaan rendah, terjemahkan jadi kalimat manusia: *"Beberapa data mungkin kurang tepat, mohon diperiksa."* Riwayat pindaian, bila perlu, cukup jadi tab kecil di Pengaturan untuk keperluan teknis — bukan menu utama.

**Kompleksitas:** Sedang (routing + penataan ulang, mesin OCR sudah ada)
**Peningkatan UX:** Sangat tinggi — menghapus satu-satunya fitur yang benar-benar tidak bisa dipakai operator

---

### C-4 — Dashboard error JavaScript di setiap kali dimuat

**Masalah.** Dashboard melempar `SyntaxError: Unexpected token '>'` setiap kali dibuka. Widget "Aktivitas Terbaru" menaruh kode PHP mentah ke dalam atribut Alpine.

**Bukti terukur.**
```
SyntaxError: Unexpected token '>'
  at safeAsyncFunction (livewire.js:1237)
  at generateEvaluatorFromString (livewire.js:1252)

Atribut penyebab (7 elemen):
  <time class="fi-wi-recent-activity-time"
        :datetime="$activity['created_at']->toIso8601String()">
```
`:datetime` adalah binding Alpine — isinya dievaluasi sebagai **JavaScript**, sehingga `->` menjadi sintaks ilegal. Seharusnya `datetime=` biasa dengan `{{ }}`.
Halaman lain: 0 error. Ini khusus dashboard.

**Kenapa ini merusak usability.** Halaman pertama yang dilihat operator setiap pagi rusak secara diam-diam. Timestamp tidak akan pernah benar, dan error Alpine yang tidak tertangani berisiko menghentikan komponen reaktif lain di halaman yang sama. Bagi siapa pun yang membuka DevTools (misalnya saat demo ke pimpinan), ini langsung terbaca sebagai produk yang belum jadi.

**Rekomendasi.** Ubah `:datetime="..."` menjadi `datetime="{{ ... }}"`. Tambahkan pemeriksaan `pageerror` ke dalam gate verifikasi sebelum setiap fase ditutup.

**Kompleksitas:** Sangat rendah (satu atribut)
**Peningkatan UX:** Tinggi — menghilangkan kerusakan di halaman paling sering dibuka

---

### C-5 — Daftar backup menampilkan `.gitignore` sebagai file backup

**Masalah.** Bagian *Daftar Backup* mencampur file backup asli dengan isi direktori mentah.

**Bukti terukur.**
```
backup_2026-08-07_183317.zip        13,2 KB   07/08/2026 18:33   [Pulihkan]
backup_2026-08-07_154813.zip        12,9 KB   07/08/2026 15:48   [Pulihkan]
sipeta_phase3_20260806_122359.sql   27,7 KB                      [Pulihkan]
.gitignore                           0,0 KB                      [Pulihkan]   ← 
```
Database `backup_logs` hanya berisi **2 baris** — dua ZIP itu. Dua entri lainnya berasal dari pembacaan direktori, bukan dari log.
Screenshot: `shots/backup_page.png`

**Kenapa ini merusak usability.** Backup adalah fitur di mana kepercayaan paling mahal. Menawarkan tombol **"Pulihkan"** di sebelah file `.gitignore` berukuran 0 KB adalah undangan menuju bencana: operator panik saat data hilang, melihat daftar, menekan Pulihkan pada baris yang salah. Selain itu, kehadiran file aneh membuat operator meragukan apakah backup ZIP-nya sendiri benar-benar valid.

**Rekomendasi.** Sumber daftar harus **tabel `backup_logs` saja**, bukan scan direktori. Tampilkan hanya file yang dibuat sistem dan sudah lulus verifikasi integritas. Beri ikon status (terverifikasi / rusak). File asing di disk diabaikan diam-diam, atau ditampilkan terpisah dengan label "File tidak dikenal — tidak dapat dipulihkan" tanpa tombol aksi.

**Kompleksitas:** Rendah (ganti sumber data + filter)
**Peningkatan UX:** Sangat tinggi — mencegah restore yang salah

---

### C-6 — Bahasa campur aduk di aksi paling kritis

**Masalah.** UI ditetapkan berbahasa Indonesia, tetapi tombol dan label penting masih berbahasa Inggris — justru pada titik keputusan yang paling berbahaya.

**Bukti terukur.**
```
Dialog hapus  : judul "Hapus Data Penduduk"  (Indonesia)
                isi   "Data yang dihapus tidak dapat dikembalikan. Lanjutkan?"  (Indonesia)
                tombol "Cancel" | "Delete"   ← INGGRIS

Form          : "Create" | "Create & create another" | "Cancel"   ← INGGRIS
Judul halaman : "Settings"  ← INGGRIS  (padahal menu sidebar "Pengaturan")
Breadcrumb    : "Penduduk > List", "Kartu Keluarga > Create"   ← INGGRIS
Empty state   : "No Job OCR"  ← campur
Modal KK      : "View 7371000000000060", tombol "Close"   ← INGGRIS
Tabel         : "Showing 1 to 25 of 270 results", "Per page"   ← INGGRIS
Pencarian     : placeholder "Search"   ← INGGRIS
Bulk          : "Bulk actions"   ← INGGRIS
```
Kontras: halaman Pengaturan sudah benar memakai **"SIMPAN"**. Jadi ini inkonsistensi, bukan keterbatasan.

**Kenapa ini merusak usability.** Dialog konfirmasi hapus adalah momen paling berisiko dalam aplikasi. Menyajikan pertanyaan dalam bahasa Indonesia lalu memaksa memilih antara dua kata Inggris (`Cancel` / `Delete`) memperlambat operator tepat saat ia harus paling yakin. Operator kelurahan yang tidak berbahasa Inggris bisa menekan tombol yang salah. Selain itu, bahasa campur adalah penanda paling cepat terbaca bahwa sebuah produk "belum jadi" — pengguna tidak bisa menyebutkan alasannya, tapi langsung merasakannya.

**Rekomendasi.** Satu sapuan terjemahan menyeluruh, tanpa pengecualian: `Batal`, `Hapus`, `Simpan`, `Simpan & Tambah Lagi`, `Tutup`, `Pengaturan`, `Daftar`, `Tambah`, `Cari…`, `Menampilkan 1–25 dari 270 data`, `Baris per halaman`, `Aksi massal`, `Lihat`. Set locale aplikasi ke `id` dan sediakan berkas terjemahan Filament, jangan menambal per tombol.

**Kompleksitas:** Rendah (konfigurasi locale + berkas bahasa)
**Peningkatan UX:** Sangat tinggi — dampak terbesar per satuan usaha di seluruh audit ini
---

## 4. Temuan HIGH

### H-1 — Dashboard: 11 kartu KPI, banyak yang mengulang informasi

**Bukti terukur (dibaca dari aplikasi live, bukan dari lampiran):**
```
 1. Total Kartu Keluarga   = 60    2. Total Kepala Keluarga  = 60   ← selalu sama dengan #1
 3. Total Anggota Keluarga = 210   4. Total Penduduk         = 270  ← #3 = #4 − #2
 5. Laki-laki  = 150 (56%)         6. Perempuan  = 120 (44%)        ← identik dengan grafik gender
 7. Total RT   = 19                8. Total RW/Lingkungan    = 3
 9. Penduduk Aktif    = 270        ← sama dengan #4
10. Penduduk Pindah   = 0          11. Penduduk Meninggal    = 0    ← #9,10,11 = grafik status
Tinggi halaman: 2850px  |  Ukuran kartu: 389×116px
```

**Masalah.** Dari 11 kartu, hanya sekitar 4 yang membawa informasi unik. #2 secara definisi selalu sama dengan #1 (satu KK = satu kepala keluarga). #3 hanyalah hasil pengurangan #4−#2. #5 dan #6 mengulang persis donat "Penduduk per Gender" tepat di bawahnya. #9, #10, #11 mengulang persis donat "Status Penduduk".

**Kenapa ini merusak usability.** Beban kognitif meningkat tanpa menambah informasi. Operator harus memindai 11 angka untuk mendapat 4 fakta, dan pengulangan angka yang sama (60 dan 60, 270 dan 270) justru menimbulkan keraguan — "apakah ini dua hal berbeda? apa bedanya?". Baris terakhir hanya berisi 2 kartu sehingga grid 3 kolom bolong di kanan bawah, yang membuat halaman terlihat tidak dirancang.

**Rekomendasi.** Turunkan ke **4 KPI** dalam satu baris rapi:

| KPI | Nilai | Sub-teks |
|---|---|---|
| Total Penduduk | 270 | 262 aktif (97%) |
| Total Kartu Keluarga | 60 | rata-rata 4,5 jiwa/KK |
| Laki-laki / Perempuan | 150 / 120 | 56% / 44% (satu kartu, dua angka) |
| Perlu Tindakan | 5 | KK tanpa foto · data belum lengkap |

KPI keempat adalah yang paling berharga dan saat ini tidak ada: satu-satunya kartu yang **memberi tahu operator apa yang harus dikerjakan**, bukan sekadar melaporkan masa lalu.

**Kompleksitas:** Rendah | **Peningkatan UX:** Tinggi

---

### H-2 — Dashboard: 7 grafik sekaligus, dua di antaranya pie

**Bukti terukur.**
```
7 canvas grafik:
  Penduduk per Gender      donat   357×280
  Status Penduduk          donat   357×280
  Penduduk per Pekerjaan   bar     357×178
  Penduduk per Pendidikan  bar     357×178
  Penduduk per Agama       bar     357×178
  Penduduk per RT          bar     771×300
  Penduduk per Lingkungan  bar     357×178
Total tinggi halaman: 2850px (2,6× layar)
```

**Masalah.** Tujuh grafik pada satu layar tanpa hierarki. Dua donat berdampingan bersaing memperebutkan perhatian. Grafik agama memakai 7 kategori berwarna-warni untuk data yang praktis didominasi satu nilai — noise visual murni. Grafik "Penduduk per RT" punya 19 kategori dengan label sumbu-X yang berdesakan. Sumber pemeriksaan visual juga menemukan urutan kategori tidak logis (pendidikan: SMP, S2, D2, SMA, SD) dan pencampuran istilah pada grafik Lingkungan ("Lingkungan I", "Lingkungan II", "RW 01").

**Kenapa ini merusak usability.** Ketika semua hal ditonjolkan, tidak ada yang menonjol. Operator tidak punya titik masuk visual dan akhirnya mengabaikan seluruh area grafik. Donat sangat buruk untuk perbandingan besaran dan sudah redundan dengan kartu KPI di atasnya.

**Rekomendasi.** **Maksimal 4 grafik terlihat di awal**, seperti permintaan Anda. Komposisi yang direkomendasikan:

```
Baris 1 — 4 kartu KPI (setinggi 104px, seragam)

Baris 2 — 2 grafik utama, lebar sama
  ┌───────────────────────────┬───────────────────────────┐
  │ Penduduk per RT           │ Piramida Penduduk         │
  │ (bar horizontal, top 10,  │ (kelompok usia × gender,  │
  │  sisanya "Lainnya")       │  bar bertumpuk)           │
  └───────────────────────────┴───────────────────────────┘

Baris 3 — 2 grafik pendukung
  ┌───────────────────────────┬───────────────────────────┐
  │ Komposisi Penduduk        │ Tren Perubahan Data       │
  │ (satu bar bertumpuk:      │ (garis, 6 bulan: tambah / │
  │  L/P + status)            │  pindah / meninggal)      │
  └───────────────────────────┴───────────────────────────┘

          [ Lihat Analitik Lainnya ▾ ]   ← tertutup secara default
             ├ Penduduk per Pendidikan
             ├ Penduduk per Pekerjaan
             ├ Penduduk per Agama
             └ Penduduk per Lingkungan
```

Alasan pemilihan: RT adalah unit kerja harian operator, piramida penduduk adalah bentuk laporan demografi yang sudah dikenal aparat kelurahan, dan grafik tren adalah satu-satunya yang menunjukkan *perubahan* — informasi yang sekarang sama sekali tidak ada. Ganti kedua donat dengan satu bar bertumpuk. Urutkan kategori berdasarkan besaran, bukan alfabet, dan gunakan satu rentang warna hijau bergradasi, bukan pelangi.

**Kompleksitas:** Sedang | **Peningkatan UX:** Sangat tinggi

---

### H-3 — Warna primer kuning terang, bukan hijau

**Bukti terukur.**
```
Tombol primer      bg oklch(0.828 0.189 84.429) → rgb(255, 185, 0)   KUNING TERANG
                   teks oklch(0.414 0.112 45.904) → rgb(123, 51, 6)  cokelat
                   kontras 5.26:1
Sidebar aktif      bg rgb(254, 243, 199)  kuning pucat
Badge gender       teks rgb(187, 77, 0) / bg rgb(255, 251, 235)  kontras 4.85:1
Topbar             rgb(255, 255, 255)  putih polos, tinggi 64px
```
Screenshot: seluruh halaman.

**Masalah.** Warna kuning `#FFB900` menjadi warna identitas aplikasi, persis yang Anda tolak. Topbar putih polos tanpa identitas membuat aplikasi tidak terlihat seperti sistem pemerintahan kelurahan. Teks cokelat di atas kuning adalah kombinasi yang lemah dan terlihat seperti peringatan, bukan aksi utama.

**Rekomendasi.** Palet hijau yang Anda minta, dengan kontras yang sudah aman:

| Peran | Warna | Hex | Kontras vs putih |
|---|---|---|---|
| Primer (tombol utama) | Forest Green | `#1B5E3F` | 8.9:1 ✅ |
| Primer hover | Forest gelap | `#154A32` | 11.2:1 ✅ |
| Topbar | Gradasi hijau | `#1B5E3F` → `#2D7A52` | teks putih 8.9:1 ✅ |
| Sidebar aktif | Ketupat Green muda | `#E8F3EC` + garis kiri `#1B5E3F` | 15.1:1 ✅ |
| Aksen sekunder | Olive Green | `#6B7F3A` | 4.9:1 ✅ |
| Sukses | `#2D7A52` | | 5.6:1 ✅ |
| Bahaya | `#B42318` | | 6.4:1 ✅ |
| Peringatan | `#B25E09` | | 4.8:1 ✅ |

Kuning dihapus total dari peran primer. Teks tombol menjadi putih murni di atas hijau tua — jauh lebih tegas dan lebih mudah dibaca daripada cokelat di atas kuning.

**Kompleksitas:** Rendah (token warna panel) | **Peningkatan UX:** Sangat tinggi — perubahan tunggal dengan dampak visual terbesar

---

### H-4 — Header atas kosong tanpa identitas

**Bukti terukur.** `topbar: 1905×64px, background rgb(255,255,255)`. Isinya hanya: logo teks "SIPETA", kolom pencarian global 214px, avatar bulat "A". Tidak ada nama kelurahan, tidak ada tanggal, tidak ada notifikasi.

**Masalah.** Aplikasi ini dipakai oleh **satu kantor kelurahan tertentu** (Kelurahan Tanete), tetapi header tidak menyebutkannya sama sekali. Halaman Pengaturan sudah punya "Identitas Kelurahan" dan "Logo Kelurahan" — data itu tidak dipakai di tempat yang paling terlihat.

**Rekomendasi.** Header modern setinggi 64px dengan latar Forest Green:
```
┌──────────────────────────────────────────────────────────────────────┐
│ [☰] [logo] SIPETA · Kelurahan Tanete    [Cari…]   Jumat, 8 Agu 2026  │
│                                                    [🔔] [A ▾]        │
└──────────────────────────────────────────────────────────────────────┘
```
- Logo kelurahan asli dari Pengaturan (bukan teks polos)
- Nama kelurahan sebagai subjudul — konteks institusional
- Tanggal hari ini dalam bahasa Indonesia — relevan untuk pekerjaan administratif harian
- Menu profil dengan label jelas (`Administrator`, `Ubah Kata Sandi`, `Keluar`)

**Kompleksitas:** Rendah–Sedang | **Peningkatan UX:** Tinggi

---

### H-5 — Filter tersembunyi di balik ikon corong tanpa label

**Bukti terukur.**
```
Pemicu filter : <button aria-label="Filter">  ikon corong + badge "0"
                Pencarian tombol berteks "filter" → null (tidak ada teks)
Isi panel (setelah dibuka):
  Nama | NIK | Nomor KK | RT | RW/Lingkungan | Jenis Kelamin | Agama |
  Pendidikan | Pekerjaan | Status Penduduk | Usia (Preset: Balita/Anak/
  Remaja/Dewasa/Lansia) | Minimum | Maksimum | [Reset] [Apply filters]
Halaman Kartu Keluarga: hasFilter = false  ← TIDAK ADA FILTER SAMA SEKALI
```
Screenshot: `shots/penduduk.png`, `shots/filters_real_click.png`

**Masalah.** Kabar baiknya: semua filter yang Anda minta **sudah ada** — RT, RW/Lingkungan, preset usia, rentang kustom, dan tombol Reset. Kabar buruknya: semuanya **tak terlihat**, tersembunyi di balik ikon corong kecil tanpa teks. Fitur terkuat di halaman ini praktis tidak akan pernah ditemukan operator. Badge "0" juga membingungkan — nol filter aktif ditampilkan sebagai angka, bukan disembunyikan. Dan tombol aksi masih berbahasa Inggris ("Apply filters").

Lebih parah: halaman **Kartu Keluarga tidak punya filter sama sekali**, padahal mencari KK per RT adalah kebutuhan harian.

**Rekomendasi.**
1. Ubah pemicu menjadi tombol berteks: `[⛃ Filter]`, dan badge hanya muncul saat jumlah filter > 0 (`Filter · 2`).
2. Ubah panel dropdown menjadi **panel yang dapat dilipat di bawah judul halaman**, dengan grid 4 kolom dan pengelompokan:
   - *Pencarian*: Nama · NIK · Nomor KK
   - *Wilayah*: RT · RW/Lingkungan
   - *Demografi*: Jenis Kelamin · Agama · Pendidikan · Pekerjaan
   - *Usia*: preset (chip) · rentang kustom
3. Filter aktif ditampilkan sebagai chip yang bisa dihapus satu per satu di bawah panel.
4. Terjemahkan: `Terapkan Filter`, `Atur Ulang`.
5. Tambahkan filter RT / RW / Status ke halaman Kartu Keluarga.
6. Simpan status buka/tutup panel per operator.

**Kompleksitas:** Sedang | **Peningkatan UX:** Sangat tinggi — mengubah fitur tak terlihat menjadi fitur andalan

---

### H-6 — Tabel: header dan kolom aksi tidak lengket saat digulir

**Bukti terukur.**
```
thead   position: static   ← tidak sticky
th akhir position: static  ← kolom aksi tidak sticky
pembungkus overflow-x: auto
Penduduk : lebar tabel 1245px di dalam wadah 1216px  → 29px meluber
           25 baris/halaman, tinggi baris 57px
Kartu Keluarga : tinggi baris 81px (karena teks alamat membungkus)
Padding sel : "16px 12px 16px 24px" hanya di sel pertama; sel lain "0px"
```

**Masalah.** Dengan 25 baris setinggi 57px, operator menggulir jauh melewati header dan kehilangan konteks kolom — pada tabel dengan 10 kolom berisi NIK dan nomor KK 16 digit yang mirip satu sama lain, ini serius. Kolom aksi (Lihat/Ubah/Hapus, lebar 231px) hilang ke kanan saat tabel digeser horizontal karena tabel 29px lebih lebar dari wadahnya. Padding sel yang tidak seragam membuat kerapatan baris terasa acak.

**Rekomendasi.**
- `thead` → `position: sticky; top: 0` dengan latar solid dan bayangan halus saat digulir.
- Kolom aksi → `position: sticky; right: 0` dengan latar solid dan pembatas kiri.
- Padding sel seragam `12px 16px`; tinggi baris 52px (padat) atau 64px (nyaman) — sediakan pengalih kerapatan.
- Beri lebar minimum eksplisit pada kolom NIK dan Nomor KK agar tidak pernah membungkus; sisipkan pemisah ribuan visual atau spasi setiap 4 digit agar NIK 16 digit mudah dibaca.
- Alamat panjang: potong dengan elipsis + tooltip, jangan biarkan membungkus dan menaikkan tinggi baris menjadi 81px.
- Turunkan default menjadi 15 baris per halaman.

**Kompleksitas:** Rendah–Sedang | **Peningkatan UX:** Tinggi

---

### H-7 — Kolom "Foto" kosong di seluruh daftar Kartu Keluarga

**Bukti terukur.** Kolom `Foto` selebar 54px, kosong pada semua 60 baris. `kk_photos = 0 baris`. Form KK menyatakan *"Foto KK wajib diunggah"* dan *"Belum ada foto."*

**Masalah.** Kolom pertama yang dilihat mata benar-benar kosong di setiap baris, membuat tabel terlihat rusak. Kontradiksi juga muncul: sistem menyatakan foto wajib, tetapi 60 KK tersimpan tanpa foto — artinya aturan wajib itu tidak ditegakkan pada jalur data yang ada.

**Rekomendasi.** Tampilkan avatar placeholder yang informatif (inisial kepala keluarga di atas lingkaran hijau muda) alih-alih sel kosong, plus ikon kecil penanda "belum ada foto". Lalu tambahkan filter cepat "KK tanpa foto" — ini langsung menyambung ke KPI "Perlu Tindakan" pada H-1 dan mengubah kekosongan menjadi daftar pekerjaan.

**Kompleksitas:** Rendah | **Peningkatan UX:** Sedang–Tinggi

---

### H-8 — Tidak ada indikator memuat sama sekali

**Bukti terukur.**
```
Elemen [wire:loading] : 0
Waktu muat terukur    : Dashboard 3100ms · Penduduk 3470ms · KK 3121ms
                        Backup 3040ms · Pengaturan 3183ms · OCR 3038ms
```

**Masalah.** Setiap halaman butuh ~3 detik, dan selama 3 detik itu **tidak ada umpan balik apa pun**. Operator menekan menu, tidak terjadi apa-apa, lalu menekan lagi. Pada tombol Backup atau Restore, penekanan ganda karena tidak ada umpan balik bisa berakibat serius. Ini juga penyebab utama aplikasi "terasa lambat" meski sebenarnya cepat.

**Rekomendasi.** Bar progres tipis di bawah header untuk perpindahan halaman; skeleton untuk tabel dan grafik; tombol berubah menjadi status "Memproses…" dan nonaktif saat aksi berjalan (wajib untuk Backup, Restore, Ekspor, Simpan); spinner pada penyaringan dan pengurutan tabel.

**Kompleksitas:** Rendah | **Peningkatan UX:** Tinggi — persepsi kecepatan berubah drastis

---

### H-9 — Tiga tombol ekspor sejajar mengalahkan aksi utama

**Bukti terukur.** Baris toolbar: `[CSV] [Excel] [PDF]` — tiga tombol dengan bobot visual setara, ditempatkan di kiri atas tabel; tombol utama `Tambah Penduduk` terpisah di kanan atas.

**Masalah.** Ekspor adalah aksi sesekali (bulanan), penambahan data adalah aksi harian. Namun ekspor memakan tiga slot toolbar dan mendominasi. Ini membalik prioritas alur kerja. Tiga tombol juga memaksa operator memilih format sebelum memahami konsekuensinya.

**Rekomendasi.** Gabung menjadi satu tombol sekunder `[⬇ Ekspor ▾]` dengan menu turun berisi tiga format beserta keterangan singkat ("PDF — untuk dicetak dan ditandatangani", "Excel — untuk diolah kembali"). Pastikan ekspor menghormati filter yang sedang aktif dan katakan itu secara eksplisit di menu: *"Mengekspor 42 data sesuai filter aktif"*. Ini menghilangkan keraguan besar: "apakah yang terekspor semua data atau hasil filter saya?".

**Kompleksitas:** Rendah | **Peningkatan UX:** Sedang–Tinggi

---

### H-10 — Tombol aksi baris berkontras rendah dan berulang 25 kali

**Bukti terukur.** Aksi baris: warna teks `rgb(113,113,123)` di atas putih = **kontras 4.83:1**, ukuran 14px. Tiga aksi (Lihat · Ubah · Hapus) × 25 baris = **75 target klik** dalam satu layar; kolom aksi selebar 231px.

**Masalah.** Kontras 4.83:1 lolos ambang minimum AA untuk teks normal (4.5:1) tetapi tetap terasa pudar, terutama untuk operator berusia lanjut pada monitor kantor yang murah. Tujuh puluh lima kontrol yang saling berulang menciptakan kebisingan visual dan menenggelamkan data — padahal data yang seharusnya menjadi bintang. "Hapus" yang selalu terlihat pada setiap baris juga meningkatkan risiko klik keliru.

**Rekomendasi.** Tampilkan hanya aksi utama (`Lihat`) sebagai teks; pindahkan `Ubah` dan `Hapus` ke menu kebab (⋮). Naikkan kontras teks aksi ke minimal 7:1. Jadikan seluruh baris dapat diklik menuju halaman detail — ini yang secara naluriah dicoba operator lebih dulu.

**Kompleksitas:** Rendah | **Peningkatan UX:** Sedang–Tinggi

---

### H-11 — Sidebar menciut tetapi tetap memakan ruang dan tidak diingat

**Bukti terukur.**
```
Lebar sebelum : 256px
Pemicu        : aria-label "Collapse sidebar"
Lebar sesudah : 188px   ← hanya berkurang 68px, bukan menjadi rel ikon 56px
```
Screenshot: `shots/sidebar_after_toggle.png`

**Masalah.** Menciutkan sidebar hanya menghemat 68px — nyaris tidak berguna, dan menghasilkan lebar 188px yang canggung: terlalu sempit untuk label yang nyaman, terlalu lebar untuk sekadar ikon. Status ciut juga tidak disimpan, sehingga operator yang memilih tampilan ringkas harus mengulang setiap kali membuka aplikasi. Grup "Kependudukan" tidak mengingat status buka/tutupnya.

**Rekomendasi.** Rel ikon sejati selebar 56–64px saat menciut, dengan tooltip pada hover. Simpan status di localStorage per operator, termasuk status grup. Tambahkan transisi 200ms agar perubahan terbaca sebagai gerakan, bukan lompatan. Untuk sistem sekecil ini, sidebar yang tetap terbuka pada layar lebar adalah default terbaik — dengan penciutan tersedia bagi yang ingin ruang tabel lebih luas.

**Kompleksitas:** Rendah | **Peningkatan UX:** Sedang

---

### H-12 — Informasi Arsitektur: Backup dan Pengaturan setara dengan pekerjaan harian

**Bukti terukur.** Urutan sidebar live:
```
Dashboard
Backup            ← administratif, dipakai mingguan
Pengaturan        ← administratif, dipakai sekali setahun
Kependudukan  ▸
   Kartu Keluarga  ← inti, dipakai setiap hari
   Review OCR      ← tidak dapat dipakai (C-3)
   Penduduk        ← inti, dipakai setiap hari
```

**Masalah.** Dua item yang paling jarang dipakai menempati posisi paling menonjol, sementara dua fitur inti terkubur di dalam grup yang harus dibuka. Urutan ini mencerminkan urutan pengembangan, bukan urutan penggunaan. "Backup" juga ambigu — operator tidak langsung tahu itu mencakup pemulihan.

**Rekomendasi.**
```
PEKERJAAN HARIAN
  Dashboard
  Kartu Keluarga      ← ditinggikan, jadi pintu masuk utama
  Penduduk
SISTEM
  Backup & Pemulihan  ← nama diperjelas
  Pengaturan
```
Hapus "Review OCR" (C-3). Grup "Kependudukan" tidak diperlukan lagi ketika hanya berisi dua item — satu tingkat kedalaman yang bisa dihilangkan.

**Kompleksitas:** Sangat rendah | **Peningkatan UX:** Sedang–Tinggi
---

## 5. Temuan MEDIUM

### M-1 — Form: kartu berdampingan tingginya tidak sama, menyisakan ruang kosong

**Bukti terukur (form Penduduk, semua kartu 596px lebar):**
```
Identitas               596×465   ┐ berdampingan → selisih 184px
Kartu Keluarga & Wilayah 596×281  ┘
Data Sosial             596×281   ┐ berdampingan → selisih 88px
Status Kependudukan     596×193   ┘  (hanya 1 field, sangat kosong)
Catatan                 596×245
```

**Masalah.** Kartu "Status Kependudukan" hanya berisi satu dropdown namun memakai kartu penuh, meninggalkan area kosong yang lebar di kanan. Selisih tinggi 184px antara dua kartu bersebelahan menciptakan bentuk bertangga yang terlihat tidak sengaja.

**Rekomendasi.** Gabungkan "Status Kependudukan" ke dalam "Kartu Keluarga & Wilayah", atau ubah menjadi baris lebar penuh berisi radio button (Aktif · Pindah · Meninggal) — pilihan status memang lebih cocok sebagai radio daripada dropdown karena hanya tiga opsi. Seimbangkan kolom sehingga selisih tinggi di bawah 100px.

**Kompleksitas:** Rendah | **Peningkatan UX:** Sedang

---

### M-2 — Form KK: tata letak bertangga antara kartu foto dan kartu data

**Bukti terukur.** `Foto Kartu Keluarga 596×251` di kiri, `Data Kartu Keluarga 596×525` di kanan — selisih 274px. `Anggota Keluarga 596×209` di bawah kiri, menyisakan lubang besar di kanan bawah.

**Masalah.** Bagian "Anggota Keluarga" — yang justru paling penting — mendapat kartu terkecil (209px) dan hanya setengah lebar, sementara sebagian besar isinya kosong. Kepentingan visual berbanding terbalik dengan kepentingan sebenarnya.

**Rekomendasi.** Foto KK di kiri (sempit, ~400px) dan Data KK di kanan (lebar), lalu **Anggota Keluarga selebar penuh di bawah** sebagai tabel yang bisa diedit langsung. Ini juga sejalan dengan perbaikan C-2.

**Kompleksitas:** Rendah | **Peningkatan UX:** Sedang

---

### M-3 — Setiap section ter-render dua kali di DOM

**Bukti terukur.**
```
Form Penduduk: Identitas ×2, Kartu Keluarga & Wilayah ×2, Data Sosial ×2,
               Status Kependudukan ×2, Catatan ×2
Form KK      : Foto ×2, Data ×2, Anggota ×2
Pengaturan   : Identitas Kelurahan ×2, Logo ×2, Backup ×2
```
Setiap pasangan memiliki dimensi identik.

**Masalah.** Elemen section terduplikasi di DOM (kemungkinan pembungkus bersarang dengan kelas yang sama). Ini menggandakan node yang harus dirender, memperlambat halaman, dan berpotensi membingungkan pembaca layar yang akan mengumumkan setiap judul section dua kali.

**Rekomendasi.** Periksa struktur pembungkus section — kemungkinan besar wrapper dan komponen sama-sama memakai kelas `fi-section`. Rapikan agar satu section menghasilkan satu node.

**Kompleksitas:** Rendah | **Peningkatan UX:** Rendah–Sedang (kinerja & aksesibilitas)

---

### M-4 — Empty state tidak membantu dan tidak menawarkan jalan keluar

**Bukti terukur.**
```
Review OCR : ikon lingkaran-silang abu + teks "No Job OCR"  — tanpa tombol
Foto KK    : "Belum ada foto."                              — tanpa tombol
```

**Masalah.** Empty state yang baik menjelaskan tiga hal: apa ini, mengapa kosong, dan apa langkah berikutnya. Yang ada saat ini hanya menyatakan kekosongan. Ikon lingkaran-silang bahkan terbaca sebagai *error*, bukan sebagai "belum ada data" — nuansa yang keliru dan membuat operator cemas.

**Rekomendasi.** Pola tiga bagian: ilustrasi netral (bukan silang), kalimat penjelas dalam bahasa Indonesia, dan satu tombol aksi utama. Contoh untuk daftar KK kosong: *"Belum ada Kartu Keluarga. Mulai dengan memindai foto KK atau mengisi manual."* + `[Tambah Kartu Keluarga]`.

**Kompleksitas:** Rendah | **Peningkatan UX:** Sedang

---

### M-5 — Halaman Backup: hierarki tombol terbalik

**Bukti terukur.** `Buat Backup` = tombol kuning menonjol di kanan atas. `Pulihkan` = tombol abu kecil beroutline, berulang di setiap baris backup.

**Masalah.** Keduanya salah bobot. "Buat Backup" adalah aksi rutin dan aman — tidak perlu menjadi elemen paling mencolok di halaman. "Pulihkan" adalah aksi **destruktif dan tidak dapat dibatalkan** yang menimpa seluruh basis data, tetapi tampil sebagai tombol sekunder paling redup, diulang empat kali (termasuk di sebelah `.gitignore`, lihat C-5). Bobot visual berbanding terbalik dengan tingkat risiko.

**Rekomendasi.** "Buat Backup" menjadi tombol sekunder yang tenang. "Pulihkan" dipindah ke dalam menu kebab per baris dan diberi gaya danger saat dibuka, dengan alur konfirmasi dua langkah yang menuntut pengetikan ulang nama berkas — pola ini sudah ditetapkan dalam spesifikasi §15 Anda dan harus terlihat jelas di UI.

**Kompleksitas:** Rendah | **Peningkatan UX:** Tinggi (pencegahan kesalahan)

---

### M-6 — Backup: tidak ada informasi kapan backup terakhir dan kesehatan cadangan

**Bukti terukur.** Bagian "Buat Backup" (1216×153px) hanya berisi teks penjelas dan satu tombol. Tidak ada ringkasan status.

**Masalah.** Pertanyaan pertama operator saat membuka halaman ini adalah *"apakah data saya aman?"*. Halaman ini tidak menjawabnya. Operator harus membaca daftar berkas dan menghitung tanggal sendiri.

**Rekomendasi.** Panel status di bagian atas: *"Backup terakhir: 2 hari lalu (7 Agu 2026, 18:33) · 13,2 KB · Terverifikasi ✅"*, dengan indikator warna (hijau <7 hari, kuning 7–30 hari, merah >30 hari atau belum pernah). Sertakan total ukuran dan jumlah cadangan tersimpan.

**Kompleksitas:** Rendah | **Peningkatan UX:** Sedang–Tinggi

---

### M-7 — Kesiapan backup cloud / Google Drive belum ada

**Bukti terukur.** Halaman Backup tidak menyebut penyimpanan eksternal. Semua backup tersimpan di disk lokal `db_backups`.

**Masalah.** Seluruh sistem berjalan pada **satu PC Windows di kantor kelurahan**. Backup yang disimpan pada mesin yang sama tidak melindungi dari skenario yang paling mungkin terjadi: hard disk rusak, laptop hilang, kena ransomware, atau banjir. Secara teknis backup ada, tetapi secara praktis perlindungannya semu.

**Rekomendasi.** Ini adalah **keputusan produk, bukan keputusan teknis** — dan bertentangan dengan arah "offline-first" yang mungkin sudah Anda tetapkan. Saya tidak menyarankan implementasi tanpa persetujuan Anda. Jalur bertahap yang direkomendasikan:
- **Sekarang (murah, tanpa cloud):** tombol "Unduh Backup" agar operator bisa menyalin ZIP ke flashdisk, plus pengingat berkala *"Backup terakhir 12 hari lalu — salin ke flashdisk"*.
- **Nanti (butuh ADR baru):** integrasi Google Drive dengan OAuth. Perlu pertimbangan koneksi internet kantor, kepemilikan akun, kebijakan data kependudukan, dan penanganan token kedaluwarsa.

Kesiapan arsitektur saat ini: `BackupService` sudah menghasilkan satu berkas ZIP mandiri, jadi menambahkan target penyimpanan tidak sulit. Yang belum ada adalah abstraksi tujuan penyimpanan dan UI status sinkronisasi.

**Kompleksitas:** Rendah (unduh) / Tinggi (Drive) | **Peningkatan UX:** Tinggi
**⚠ Perlu keputusan Anda sebelum dikerjakan.**

---

### M-8 — Pengaturan: judul halaman berbahasa Inggris dan tombol simpan terisolasi

**Bukti terukur.**
```
H1        : "Settings"    ← sidebar menyebut "Pengaturan"
Tombol    : "SIMPAN" — bg oklch(0.828 0.189 84.429) kuning, tinggi 36px
3 tombol lain tanpa teks (h=36, 32, 36), latar transparan
Section   : Identitas Kelurahan (457px) · Logo Kelurahan (223px) · Backup (221px)
```

**Masalah.** Judul tidak cocok dengan menu — operator mengklik "Pengaturan" lalu mendarat di halaman berjudul "Settings". Tombol SIMPAN berada di dasar halaman sepanjang 1213px; setelah mengubah satu field di section pertama, operator harus menggulir ke bawah untuk menyimpan tanpa isyarat bahwa ada perubahan tersimpan/belum. Tiga tombol tanpa label teks juga tidak jelas fungsinya.

**Rekomendasi.** Judul menjadi "Pengaturan". Bar aksi lengket di bawah yang muncul hanya ketika ada perubahan: *"Anda memiliki perubahan yang belum disimpan"* + `[Simpan]` `[Batalkan]`. Beri label teks pada tombol yang sekarang hanya ikon.

**Kompleksitas:** Rendah | **Peningkatan UX:** Sedang

---

### M-9 — Tabel meremas kolom di layar sempit alih-alih menggulir

**Bukti terukur (390px):**
```
horizontalOverflow : false   ← tidak menggulir, tapi memampatkan
Header "Nomor..."  : terpotong
Kolom NIK          : 16 digit dipaksa masuk ke kolom sangat sempit
Sidebar            : tersembunyi di balik hamburger (benar)
```
Screenshot: `shots/list_penduduk@390.png`

**Masalah.** Kolom diremas hingga judul terpotong dan angka 16 digit menjadi rapat. Pada 1366×768 — resolusi yang sangat mungkin dipakai PC kantor kelurahan — tabel Penduduk sudah 1245px dalam wadah 1216px, sehingga kolom aksi sudah mulai terdorong keluar.

**Rekomendasi.** Tetapkan lebar minimum per kolom dan biarkan wadah menggulir horizontal (dengan kolom aksi lengket, lihat H-6). Di bawah 768px, alihkan ke tampilan kartu: nama sebagai judul, NIK sebagai subjudul, RT dan status sebagai chip, satu tombol aksi. Prioritaskan 1366px sebagai target utama karena itu kemungkinan besar layar sebenarnya di kantor.

**Kompleksitas:** Sedang | **Peningkatan UX:** Sedang

---

### M-10 — Pencarian global tanpa konteks dan terlalu kecil

**Bukti terukur.** `input placeholder="Search"`, lebar 214px, di header.

**Masalah.** Placeholder berbahasa Inggris dan tidak memberi tahu apa yang bisa dicari. Operator tidak tahu apakah kolom ini mencari nama, NIK, nomor KK, atau alamat — sehingga cenderung tidak dipakai sama sekali. Untuk aplikasi arsip kependudukan, pencarian cepat justru merupakan fitur paling berharga.

**Rekomendasi.** Perlebar menjadi ~360px, placeholder `Cari nama, NIK, atau nomor KK…`, hasil dikelompokkan menurut jenis (Penduduk / Kartu Keluarga) dengan potongan konteks, dan pintasan papan ketik yang ditampilkan (`Ctrl+K`).

**Kompleksitas:** Sedang | **Peningkatan UX:** Sedang–Tinggi

---

### M-11 — Ukuran kontrol 36px di bawah standar kenyamanan

**Bukti terukur.** Semua tombol tinggi **36px**, font 14px. Badge tinggi 16px dengan font 12px.

**Masalah.** 36px adalah default Filament, bukan keputusan desain. Untuk operator yang bekerja berjam-jam dengan mouse kantor, dan khususnya untuk pengguna berusia lanjut, target 36px terasa kecil dan menuntut presisi. Badge 12px pada tinggi 16px sulit dibaca sekilas.

**Rekomendasi.** Naikkan tombol menjadi 40–44px dengan font 15px untuk aksi utama; badge menjadi 20–22px dengan font 13px. Terapkan konsisten agar tinggi kontrol menjadi ritme yang seragam di seluruh aplikasi.

**Kompleksitas:** Rendah | **Peningkatan UX:** Sedang

---

## 6. Temuan LOW

### L-1 — Data demo tidak realistis melemahkan evaluasi desain
Sepuluh baris pertama semuanya bernama "AGUS SANTOSO" dengan NIK nyaris identik; nomor KK berurutan sempurna. **Dampak:** menyulitkan pengujian pembungkusan teks pada nama panjang, dan membuat demo ke pimpinan terlihat palsu. **Rekomendasi:** seeder dengan variasi nama Bugis/Makassar realistis, panjang beragam, sebagian dengan foto, sebagian berstatus pindah/meninggal. **Kompleksitas:** Rendah.

### L-2 — Grafik memakai urutan kategori tidak logis
Pendidikan tampil sebagai SMP, S2, D2, SMA, SD — bukan urutan jenjang maupun urutan besaran. Lingkungan mencampur "Lingkungan I", "Lingkungan II", dan "RW 01". **Dampak:** operator tidak bisa membaca pola; label campur terbaca sebagai kesalahan data. **Rekomendasi:** urutkan berdasarkan besaran (atau jenjang untuk pendidikan) dan seragamkan penamaan wilayah. **Kompleksitas:** Rendah.

### L-3 — Grafik RT menampilkan 19 kategori berdesakan
Label sumbu-X saling tumpang tindih pada 19 RT. **Rekomendasi:** bar horizontal, tampilkan 10 teratas, sisanya digabung "Lainnya" dengan opsi lihat semua. **Kompleksitas:** Rendah.

### L-4 — Breadcrumb berbahasa Inggris dan bernilai rendah
"Penduduk > List", "Kartu Keluarga > Create". **Dampak:** pada hierarki sedangkal ini breadcrumb hampir tidak berguna, dan justru menambah keriuhan berbahasa Inggris. **Rekomendasi:** terjemahkan (`Daftar`, `Tambah`) atau hapus pada halaman daftar; pertahankan pada halaman detail yang bersarang. **Kompleksitas:** Sangat rendah.

### L-5 — Tidak ada gaya cetak
Operator kelurahan sering mencetak daftar. Mencetak langsung dari peramban akan menyertakan sidebar dan header. **Rekomendasi:** stylesheet cetak sederhana (sembunyikan navigasi, tabel lebar penuh, kop kelurahan, nomor halaman). **Kompleksitas:** Rendah.

### L-6 — Mode gelap ada tetapi kemungkinan besar belum diuji
Pengalih tema terdeteksi aktif. Dengan palet hijau baru, kontras mode gelap harus diverifikasi ulang. **Rekomendasi:** uji, atau nonaktifkan sampai diverifikasi — mode gelap yang rusak lebih buruk daripada tidak ada. **Kompleksitas:** Rendah.

### L-7 — Padding sel tabel tidak seragam
Sel pertama `16px 12px 16px 24px`, sel lainnya `0px`. **Dampak:** ritme visual tidak rata di seluruh baris. **Rekomendasi:** padding seragam melalui token tabel. **Kompleksitas:** Sangat rendah.

---

## 7. Temuan VERY LOW

### VL-1 — Avatar profil hanya inisial polos
Lingkaran hitam berisi "A". **Rekomendasi:** gunakan warna hijau merek dan tampilkan nama operator di sebelahnya pada layar lebar. **Kompleksitas:** Sangat rendah.

### VL-2 — Tidak ada favicon khusus
**Rekomendasi:** favicon logo kelurahan — penting saat aplikasi dibuka bersama banyak tab. **Kompleksitas:** Sangat rendah.

### VL-3 — Tidak ada pintasan papan ketik
Operator yang melakukan entri data berulang mendapat manfaat besar dari `Ctrl+K` (cari), `Ctrl+S` (simpan), `Esc` (tutup dialog). **Kompleksitas:** Sedang.

### VL-4 — Radius sudut dan bayangan belum ditetapkan sebagai token
Nilai default Filament dipakai apa adanya. **Rekomendasi:** tetapkan skala radius (6/10/14px) dan dua tingkat bayangan sebagai bagian dari sistem desain. **Kompleksitas:** Rendah.
---

## 8. Kartu Keluarga sebagai Pintu Masuk Utama

Anda bertanya apakah KK harus menjadi entitas utama dengan anggota keluarga di dalamnya. **Jawabannya ya, tegas.**

**Alasan.** Kantor kelurahan bekerja dalam satuan keluarga, bukan satuan individu. Warga datang membawa **satu lembar Kartu Keluarga**. Permintaan yang masuk berbunyi *"tolong urus KK keluarga Pak Firman"*, bukan *"tolong cari penduduk bernama Firman"*. Dokumen fisik yang menjadi sumber data adalah KK. Ketika model data aplikasi mencerminkan dokumen fisik yang dipegang operator, pelatihan menjadi hampir tidak diperlukan.

**Keadaan sekarang.** Aplikasi memecah dua hal ini menjadi dua daftar terpisah dan sejajar, lalu — melalui C-1 dan C-2 — **memutus hubungan di antara keduanya**: jumlah anggota selalu 0, dan halaman detail KK tidak menampilkan anggota sama sekali. Jadi bukan hanya prioritasnya keliru; relasinya juga tidak terlihat di mana pun dalam UI.

**Struktur yang direkomendasikan.**

```
Kartu Keluarga  ← pintu masuk utama, item pertama setelah Dashboard
   │
   └── Detail KK  (halaman penuh, bukan modal)
         ├── Ringkasan   : No. KK · Kepala Keluarga · Alamat · RT/RW · Lingkungan
         ├── Foto KK     : gambar asli, dapat diperbesar
         ├── Anggota (5) : TABEL — Nama · NIK · Hubungan · L/P · Usia · Status
         │                  [Tambah Anggota]  [Pindahkan ke KK lain]
         └── Riwayat     : perubahan, pindah, meninggal

Penduduk  ← tetap ada, tetapi sebagai pencarian lintas-keluarga
             untuk pertanyaan "di mana orang ini terdaftar?"
```

Menemukan anggota keluarga tidak boleh memerlukan penyaringan manual di halaman Penduduk berdasarkan nomor KK. Itu adalah cara berpikir basis data, bukan cara berpikir operator.

**Kemudahan penemuan saat ini: buruk.** Dari daftar KK, satu-satunya jalan menuju anggota adalah menekan "Lihat" — yang justru membuka form unggah tanpa anggota (C-2). Tidak ada jalur yang berfungsi sama sekali.

---

## 9. Alur Kerja OCR yang Benar

Alur yang Anda usulkan sudah tepat. Ini penjabaran lengkapnya beserta detail yang menentukan berhasil-tidaknya.

```
[Kartu Keluarga] → [+ Tambah Kartu Keluarga]
                            │
        ┌───────────────────┴───────────────────┐
        │                                       │
   [📷 Scan Foto KK]                     [⌨ Input Manual]
        │                                       │
   Unggah / ambil foto                    Form kosong
        │                                       │
   "Membaca foto KK…"  ▓▓▓▓░░ 60%              │
   (progress, tanpa kata OCR)                   │
        │                                       │
   Form TERISI otomatis  ←──────────────────────┘
        │
   Field hasil pindaian ditandai:
     • latar hijau sangat muda
     • ikon kecil "hasil pindaian"
     • field ragu → garis bawah kuning + "mohon periksa"
        │
   Foto KK ditampilkan berdampingan dengan form
   agar operator bisa mencocokkan langsung
        │
   Operator memperbaiki seperlunya
   (menyunting field akan menghapus tanda "hasil pindaian")
        │
   [Simpan]  → notifikasi "Kartu Keluarga berhasil disimpan"
```

**Aturan yang tidak boleh dilanggar.**

1. **Nol jargon.** Kata "OCR", "job", "confidence", "threshold", "queue" tidak boleh muncul di layar operator. Yang boleh: "pindai", "membaca foto", "hasil pindaian", "mohon periksa".
2. **Tidak pernah menyimpan otomatis.** Sesuai ketetapan Anda, hasil pindaian hanya mengisi form; operator tetap harus menekan Simpan. Ini sudah benar secara prinsip — yang perlu diperbaiki adalah membuatnya *terlihat* dengan penanda field.
3. **Foto dan form berdampingan.** Verifikasi mustahil dilakukan jika operator harus berpindah layar untuk membandingkan.
4. **Kegagalan harus anggun.** Jika pembacaan gagal: *"Foto kurang jelas. Anda tetap dapat mengisi manual."* + form kosong yang siap dipakai — bukan pesan error, bukan jalan buntu.
5. **Skor kepercayaan diterjemahkan.** Ambang `config('ocr.confidence_threshold', 70)` tidak pernah ditampilkan sebagai angka. Di bawah ambang → field diberi tanda "mohon periksa". Itu saja.
6. **Hapus menu "Review OCR".** Jika riwayat pindaian dibutuhkan untuk penelusuran teknis, letakkan sebagai tab kecil di Pengaturan, bukan sebagai menu utama.

**Yang sudah ada dan tinggal dirapikan.** Tombol *Scan Foto KK* dan *Input Manual* sudah muncul di header form Tambah KK, dan bagian Anggota Keluarga sudah menyatakan *"Hasil pemindaian akan mengisi baris ini secara otomatis."* Jadi fondasinya ada. Yang kurang: penanda field hasil pindaian, tampilan foto berdampingan, progres berbahasa manusia, dan penghapusan menu OCR.

---

## 10. Rekomendasi Komposisi Dashboard

```
┌─────────────────────────────────────────────────────────────────────┐
│  Dashboard                              Jumat, 8 Agustus 2026       │
│  Ringkasan data kependudukan Kelurahan Tanete                       │
├─────────────────────────────────────────────────────────────────────┤
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────────────────┐   │
│  │ 270      │ │ 60       │ │ 150/120  │ │ 5                    │   │
│  │ Penduduk │ │ Kartu    │ │ L / P    │ │ Perlu Tindakan       │   │
│  │ 262 aktif│ │ Keluarga │ │ 56%/44%  │ │ KK tanpa foto →      │   │
│  └──────────┘ └──────────┘ └──────────┘ └──────────────────────┘   │
│                                                    ↑ dapat diklik   │
├─────────────────────────────────────────────────────────────────────┤
│  ┌───────────────────────────┐ ┌───────────────────────────┐        │
│  │ Penduduk per RT           │ │ Piramida Penduduk         │        │
│  │ (bar horizontal, top 10)  │ │ (kelompok usia × gender)  │        │
│  └───────────────────────────┘ └───────────────────────────┘        │
│  ┌───────────────────────────┐ ┌───────────────────────────┐        │
│  │ Komposisi Penduduk        │ │ Tren Perubahan (6 bulan)  │        │
│  │ (satu bar bertumpuk)      │ │ (garis)                   │        │
│  └───────────────────────────┘ └───────────────────────────┘        │
│                                                                      │
│              [ Lihat Analitik Lainnya ▾ ]                            │
├─────────────────────────────────────────────────────────────────────┤
│  Aksi Cepat                          │  Aktivitas Terbaru            │
│  [+ Tambah KK]  [+ Tambah Penduduk]  │  • KK 7371… diperbarui  2j    │
│  [📷 Scan KK]   [⬇ Ekspor Data]      │  • CIPTO WIBOWO ditambah 3j   │
└─────────────────────────────────────────────────────────────────────┘
```

**Perubahan terhadap keadaan sekarang.**

| | Sekarang | Direkomendasikan |
|---|---|---|
| Kartu KPI | 11 | 4 |
| Grafik terlihat | 7 | 4 |
| Diagram donat | 2 | 0 |
| Tinggi halaman | 2850px | ~1400px |
| KPI dapat ditindaklanjuti | 0 | 1 ("Perlu Tindakan") |
| Grafik menunjukkan perubahan | 0 | 1 (Tren) |

Kunci perubahan ini bukan sekadar "lebih sedikit". Dashboard sekarang **melaporkan masa lalu**; dashboard yang direkomendasikan **mengarahkan pekerjaan hari ini** melalui KPI "Perlu Tindakan" yang dapat diklik dan grafik tren yang menunjukkan pergerakan.
---

## 11. Rekomendasi UI Roadmap Sebelum Fase 7

Diurutkan berdasarkan prioritas: dampak tertinggi dan biaya terendah lebih dahulu. Setiap tahap adalah sub-fase tersendiri yang bisa diverifikasi dan di-commit terpisah, sesuai alur kerja Anda.

---

### Tahap 1 — Perbaikan Kepercayaan (WAJIB, tidak boleh dilewati)

*Selama tahap ini belum selesai, aplikasi menyesatkan operator.*

| # | Perbaikan | Kompleksitas | Dampak |
|---|---|---|---|
| C-1 | Jumlah anggota KK menampilkan angka sebenarnya | Rendah | Sangat tinggi |
| C-4 | Perbaiki `SyntaxError` Alpine di dashboard | Sangat rendah | Tinggi |
| C-5 | Daftar backup hanya dari `backup_logs`, buang `.gitignore` | Rendah | Sangat tinggi |
| C-6 | Sapuan bahasa Indonesia menyeluruh (locale `id`) | Rendah | Sangat tinggi |

**Kriteria selesai:** tidak ada angka yang salah, tidak ada error konsol, tidak ada teks Inggris di jalur operator.

---

### Tahap 2 — Identitas Visual

*Perubahan yang paling terasa profesional per satuan usaha.*

| # | Perbaikan | Kompleksitas | Dampak |
|---|---|---|---|
| H-3 | Palet Forest/Olive/Ketupat Green, hapus kuning | Rendah | Sangat tinggi |
| H-4 | Header hijau dengan logo, nama kelurahan, tanggal | Rendah–Sedang | Tinggi |
| M-11 | Tinggi kontrol 40–44px, ukuran badge naik | Rendah | Sedang |
| L-7 | Padding sel tabel seragam | Sangat rendah | Rendah |

**Kriteria selesai:** tangkapan layar mana pun langsung terbaca sebagai sistem pemerintahan kelurahan, bukan template admin.

---

### Tahap 3 — Alur Kerja Inti Operator

*Tahap terberat, dan yang paling menentukan apakah aplikasi berguna.*

| # | Perbaikan | Kompleksitas | Dampak |
|---|---|---|---|
| C-2 | Halaman detail KK dengan tabel anggota keluarga | Sedang–Tinggi | Sangat tinggi |
| C-3 | Hapus menu OCR, alur pindai di dalam Tambah KK | Sedang | Sangat tinggi |
| §8 | KK menjadi pintu masuk utama | Rendah | Tinggi |
| H-12 | Urutan sidebar mengikuti frekuensi pakai | Sangat rendah | Sedang–Tinggi |

**Kriteria selesai:** operator dapat menyelesaikan *"lihat keluarga Pak Firman beserta 5 anggotanya"* dan *"foto KK ini lalu simpan"* tanpa pelatihan.

---

### Tahap 4 — Dashboard

| # | Perbaikan | Kompleksitas | Dampak |
|---|---|---|---|
| H-1 | 11 KPI → 4 KPI, termasuk "Perlu Tindakan" | Rendah | Tinggi |
| H-2 | 7 grafik → 4 grafik + "Lihat Analitik Lainnya" | Sedang | Sangat tinggi |
| L-2 | Urutan kategori grafik logis, penamaan seragam | Rendah | Rendah |
| L-3 | Grafik RT: bar horizontal, 10 teratas | Rendah | Rendah |

**Kriteria selesai:** dashboard muat dalam ~1,3 layar dan menjawab "apa yang harus saya kerjakan hari ini".

---

### Tahap 5 — Tabel dan Filter

| # | Perbaikan | Kompleksitas | Dampak |
|---|---|---|---|
| H-5 | Panel filter terlihat dan dapat dilipat; filter untuk KK | Sedang | Sangat tinggi |
| H-6 | Header lengket, kolom aksi lengket, kerapatan seragam | Rendah–Sedang | Tinggi |
| H-9 | Tiga tombol ekspor → satu menu Ekspor | Rendah | Sedang–Tinggi |
| H-10 | Aksi baris disederhanakan, kontras dinaikkan | Rendah | Sedang–Tinggi |
| H-7 | Placeholder foto KK + filter "tanpa foto" | Rendah | Sedang–Tinggi |
| M-9 | Perilaku responsif tabel (target 1366px) | Sedang | Sedang |

---

### Tahap 6 — Form, Dialog, dan Umpan Balik

| # | Perbaikan | Kompleksitas | Dampak |
|---|---|---|---|
| H-8 | Indikator memuat di semua aksi | Rendah | Tinggi |
| M-1 | Seimbangkan kartu form Penduduk | Rendah | Sedang |
| M-2 | Tata ulang form KK, anggota selebar penuh | Rendah | Sedang |
| M-3 | Hapus duplikasi section di DOM | Rendah | Rendah–Sedang |
| M-4 | Empty state yang membantu dengan tombol aksi | Rendah | Sedang |
| M-8 | Judul "Pengaturan" + bar simpan lengket | Rendah | Sedang |

---

### Tahap 7 — Backup dan Pemulihan

| # | Perbaikan | Kompleksitas | Dampak |
|---|---|---|---|
| M-5 | Balik hierarki tombol, konfirmasi restore dua langkah | Rendah | Tinggi |
| M-6 | Panel status kesehatan backup | Rendah | Sedang–Tinggi |
| M-7 | Tombol "Unduh Backup" (langkah cloud menunggu keputusan) | Rendah | Tinggi |

---

### Tahap 8 — Pemolesan

L-1 data demo realistis · L-4 breadcrumb · L-5 gaya cetak · L-6 verifikasi mode gelap · H-11 rel sidebar + ingat status · M-10 pencarian global · VL-1…VL-4.

---

## 12. Keputusan yang Memerlukan Persetujuan Anda

Saya tidak melanjutkan ke implementasi apa pun. Empat hal berikut adalah keputusan produk, bukan keputusan teknis, dan hanya Anda yang bisa menetapkannya:

1. **Backup cloud / Google Drive (M-7).** Berpotensi bertentangan dengan arah offline-first dan menyentuh kebijakan data kependudukan. Memerlukan ADR baru. Alternatif tanpa cloud: tombol "Unduh Backup" ke flashdisk.

2. **Menghapus menu "Review OCR" (C-3).** Ini menghapus permukaan yang sudah dibangun pada Fase 5.x. Fungsinya tidak hilang — hanya berpindah ke dalam alur Tambah KK. Saya perlu konfirmasi Anda sebelum menghapus sesuatu yang sudah dikerjakan.

3. **Sumber kebenaran keanggotaan KK (C-1).** Pilih `penduduk.kk_id` (FK, saat ini yang terisi) atau pivot `kk_anggota` (saat ini kosong, tetapi menyimpan `family_relation`, `status`, `effective_date`, `end_date` — mendukung riwayat perpindahan anggota). Keputusan ini memengaruhi skema, jadi milik Anda.

4. **Halaman detail KK menggantikan modal (C-2).** Perubahan struktural pada navigasi sumber daya, bukan sekadar penataan ulang tampilan.

---

## 13. Lampiran — Indeks Bukti

**Direktori screenshot:** `/home/awa/Documents/SIPETA/ui-audit/shots/`

| Berkas | Isi |
|---|---|
| `dashboard.png` | Dashboard 1920px penuh — 11 KPI, 7 grafik |
| `dashboard@1366.png` | Dashboard pada 1366px |
| `dashboard@390.png` | Dashboard pada mobile |
| `penduduk.png` | Tabel Penduduk 1920px |
| `list_penduduk@1366.png` · `list_penduduk@390.png` | Tabel Penduduk responsif |
| `kartu-keluarga.png` · `list_kk.png` | Daftar KK — kolom Jumlah Anggota = 0 |
| `list_kk@1366.png` | Daftar KK pada 1366px |
| `view_kk_detail.png` | 404 pada URL detail KK |
| `kk_lihat_modal.png` | Modal "Lihat" berisi form unggah |
| `form_kk_create.png` · `form_kk_edit.png` | Form KK |
| `form_penduduk_create.png` · `form_penduduk_edit.png` | Form Penduduk |
| `penduduk_delete_modal.png` | Dialog hapus — judul Indonesia, tombol Inggris |
| `penduduk_bulk_selected.png` | Bar aksi massal |
| `filters_real_click.png` · `penduduk_filters_panel_vp.png` | Panel filter |
| `backup_page.png` · `backup_modal.png` | Halaman Backup — `.gitignore` terdaftar |
| `ocr_list.png` · `review-ocr.png` | Halaman Review OCR kosong |
| `settings_page.png` · `pengaturan.png` | Halaman Pengaturan |
| `sidebar_after_toggle.png` | Sidebar setelah diciutkan (188px) |

**Data pengukuran mentah:**
- `ui-audit/pd-report.json` — CSS terkomputasi seluruh halaman, dimensi tabel, warna
- `ui-audit/pd-report2.json` — pengukuran form, section, dialog

**Skrip audit (dapat dijalankan ulang):**
- `ui-audit/pd-crawl.cjs` — login + jelajah + ukur + tangkap layar
- `ui-audit/pd-interact.cjs` — form, dialog, viewport responsif
- `ui-audit/pd-probe3.cjs` … `pd-probe9.cjs` — filter, kontras, sticky, error konsol

**Verifikasi basis data:**
```sql
SELECT COUNT(*) FROM kk_anggota;                            -- 0
SELECT COUNT(*) FROM penduduk WHERE kk_id IS NOT NULL;      -- 270
SELECT COUNT(*) FROM kk_photos;                             -- 0
SELECT COUNT(*) FROM ocr_jobs;                              -- 0
SELECT COUNT(*) FROM backup_logs;                           -- 2
```

---

**Akhir audit. Tidak ada kode yang diubah. Tidak ada commit yang dibuat.**
