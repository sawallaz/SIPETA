| Field | Value |
|---|---|
| **Title** | SIPETA Panduan Operator |
| **Purpose** | Petunjuk lengkap untuk operator kelurahan dalam menggunakan SIPETA setiap hari. |
| **Scope** | Login, dashboard, pencarian, tambah data, OCR, edit, ekspor, backup, restore. |
| **Version** | 1.0.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `docs/REQUIREMENTS.md`, `docs/FEATURES.md`, `.ai/ui-ux.md`, `.ai/workflow.md`, `.ai/ocr.md` |

---

# SIPETA Panduan Operator

Panduan ini ditulis untuk Operator Kelurahan Tanete yang menggunakan SIPETA untuk pertama kali. Setiap langkah diuraikan secara jelas dalam Bahasa Indonesia. Tidak perlu pengetahuan teknis.

## Daftar Isi

1. [Persiapan Awal](#1-persiapan-awal)
2. [Login](#2-login)
3. [Dashboard](#3-dashboard)
4. [Pencarian Data](#4-pencarian-data)
5. [Filter Data](#5-filter-data)
6. [Menambah Penduduk](#6-menambah-penduduk)
7. [Upload Foto KK](#7-upload-foto-kk)
8. [OCR Kartu Keluarga](#8-ocr-kartu-keluarga)
9. [Melihat Detail Penduduk](#9-melihat-detail-penduduk)
10. [Mengedit Data Penduduk](#10-mengedit-data-penduduk)
11. [Mengubah Status Penduduk](#11-mengubah-status-penduduk)
12. [Mengganti Foto KK](#12-mengganti-foto-kk)
13. [Ekspor Laporan](#13-ekspor-laporan)
14. [Backup Data](#14-backup-data)
15. [Restore Data](#15-restore-data)
16. [Pengaturan](#16-pengaturan)
17. [Pesan Kesalahan Umum](#17-pesan-kesalahan-umum)
18. [Tips Harian](#18-tips-harian)

## 1. Persiapan Awal

Sebelum mulai:

1. Pastikan aplikasi SIPETA sudah terpasang.
2. Pastikan ada ikon SIPETA di Desktop.
3. Siapkan data penduduk yang akan dimasukkan (Kartu Keluarga dan KTP/NIK).
4. Siapkan foto Kartu Keluarga yang jelas (jika tersedia).

Jika ikon SIPETA belum ada di Desktop, hubungi developer.

## 2. Login

Langkah membuka aplikasi:

1. Klik dua kali ikon **SIPETA** di Desktop.
2. Tunggu beberapa detik sampai jendela aplikasi terbuka.
3. Masukkan **Nama Pengguna** (default: `admin`).
4. Masukkan **Kata Sandi**.
5. Klik tombol **MASUK**.

Jika berhasil, Anda akan melihat halaman Dashboard.

Jika gagal:

- Periksa Caps Lock tidak aktif.
- Periksa ejaan nama pengguna dan kata sandi.
- Setelah 5 kali gagal, akun akan terkunci sementara selama 15 menit. Hubungi developer jika terkunci.

Untuk keluar, klik tombol **KELUAR** di pojok kanan atas. Tutup aplikasi jika selesai.

## 3. Dashboard

Halaman Dashboard menampilkan ringkasan data penduduk.

Informasi yang ditampilkan:

- **Penduduk Aktif** — jumlah penduduk yang berstatus Aktif.
- **Total KK** — jumlah Kartu Keluarga yang tercatat.
- **Laki-laki** — jumlah penduduk laki-laki aktif.
- **Perempuan** — jumlah penduduk perempuan aktif.
- **Pindah** — jumlah penduduk yang berstatus Pindah.
- **Meninggal** — jumlah penduduk yang berstatus Meninggal.

Grafik yang tersedia:

- **Penduduk per RT** — bar chart.
- **Penduduk per Lingkungan** — bar chart.
- **Penduduk per Pekerjaan** — bar chart.

Catatan: Semua angka dashboard dihitung otomatis dari data yang tersimpan.

## 4. Pencarian Data

Halaman utama bekerja sebagai Data Penduduk.

Untuk mencari data:

1. Klik menu **Data Penduduk** di sidebar kiri.
2. Klik kotak **Cari** di bagian atas.
3. Ketik salah satu: Nama, NIK, atau Nomor KK.
4. Hasil akan langsung muncul saat Anda mengetik.

Hasil pencarian otomatis mengikuti ejaan sebagian. Contoh: ketik "Sud" untuk menemukan "Sudirman".

Tips:
- Pencarian tidak membedakan huruf besar/kecil.
- Untuk hasil lebih spesifik, gunakan Filter.

## 5. Filter Data

Filter berada di bawah kotak pencarian. Anda bisa menggunakan lebih dari satu filter sekaligus.

Filter yang tersedia:

- **RT** — nomor RT.
- **RW** — nomor RW.
- **Lingkungan** — nama lingkungan.
- **Status** — Aktif, Pindah, atau Meninggal.
- **Umur** — umur pasti.
- **Rentang Umur** — например 0–5, 6–12, dst.
- **Pekerjaan** — pekerjaan penduduk.
- **Pendidikan** — tingkat pendidikan.
- **Agama** — agama.
- **Jenis Kelamin** — Laki-laki / Perempuan.

Untuk menggunakan filter:

1. Pilih nilai pada filter yang ingin digunakan.
2. Tabel akan otomatis menampilkan data yang cocok.
3. Untuk menghapus semua filter, klik tombol **RESET FILTER**.

Catatan: Hasil ekspor selalu mengikuti filter yang sedang aktif.

## 6. Menambah Penduduk

Untuk menambah data penduduk:

1. Klik tombol **+ TAMBAH PENDUDUK** di kanan atas.
2. Anda akan melihat dua pilihan:
   - **Upload Foto KK** — gunakan jika Anda punya foto Kartu Keluarga.
   - **Input Manual** — gunakan jika tidak ada foto KK.
3. Untuk Input Manual, isi formulir:
   - **Nomor KK** — nomor Kartu Keluarga.
   - **Alamat** — alamat lengkap.
   - **RT**, **RW**, **Lingkungan**.
   - **Kode Pos** (opsional).
   - **Nama Lengkap** — nama penduduk.
   - **NIK** — 16 digit.
   - **Tempat Lahir**.
   - **Tanggal Lahir** — pilih dari kalender.
   - **Jenis Kelamin** — Laki-laki / Perempuan.
   - **Agama**.
   - **Pendidikan**.
   - **Pekerjaan**.
   - **Status Pernikahan**.
   - **Status Hubungan Keluarga** — misalnya Kepala Keluarga, Istri, Anak.
4. Klik **SIMPAN**.

Setelah tersimpan, data akan muncul di tabel.

## 7. Upload Foto KK

Foto Kartu Keluarga disimpan satu kali per KK, milik KK, bukan per penduduk.

Untuk menambahkan foto KK:

1. Buka detail KK (lihat bagian 9).
2. Klik tombol **UNGGAH FOTO KK**.
3. Pilih file gambar (JPG, JPEG, atau PNG, maksimal 5 MB).
4. Klik **UNGGAH**.

Foto akan tampil di halaman detail KK.

## 8. OCR Kartu Keluarga

OCR adalah fitur pembacaan otomatis teks dari foto Kartu Keluarga. Fitur ini membantu mengurangi pengetikan manual.

Langkah OCR:

1. Klik tombol **+ TAMBAH PENDUDUK**.
2. Pilih **Upload Foto KK**.
3. Pilih foto Kartu Keluarga dari komputer.
4. Klik **MULAI OCR**.
5. Tunggu beberapa detik. Akan muncul progress.
6. Setelah selesai, formulir akan terisi otomatis.
7. **Periksa setiap kolom** dengan teliti.
8. Kolom yang kurang yakin akan ditandai dengan warna kuning.
9. Perbaiki jika ada kesalahan.
10. Klik **SIMPAN** jika semua sudah benar.

Hal yang perlu diketahui:

- OCR **tidak** menyimpan data secara otomatis. Anda tetap harus klik SIMPAN.
- Jika OCR gagal, formulir input manual tetap muncul.
- Jika KK sudah pernah di-scan, akan muncul peringatan duplikat.

Tips agar OCR optimal:

- Foto harus lurus (tidak miring).
- Pencahayaan cukup terang.
- Teks terlihat jelas, tidak blur.
- Resolusi minimal 800×600.

## 9. Melihat Detail Penduduk

Untuk melihat detail:

1. Cari data penduduk (lihat bagian 4).
2. Klik tombol **DETAIL** pada baris penduduk.
3. Halaman detail akan menampilkan:
   - Informasi penduduk.
   - Informasi Kartu Keluarga.
   - Status saat ini.
   - Riwayat status.
   - Foto KK.

## 10. Mengedit Data Penduduk

Untuk mengedit:

1. Buka detail penduduk (lihat bagian 9).
2. Klik tombol **EDIT**.
3. Ubah data yang salah.
4. Klik **SIMPAN**.

Tidak semua kolom bisa diedit. Nomor KK dan NIK biasanya dikunci setelah disimpan untuk menjaga konsistensi. Hubungi developer jika perlu mengubahnya.

## 11. Mengubah Status Penduduk

Status penduduk bisa berubah dari waktu ke waktu:

- **Aktif** — masih tinggal di kelurahan.
- **Pindah** — sudah pindah ke tempat lain.
- **Meninggal** — sudah meninggal dunia.

Untuk mengubah status:

1. Buka detail penduduk.
2. Klik tombol **UBAH STATUS**.
3. Pilih status baru:
   - Jika **Pindah**: isi tanggal pindah dan catatan.
   - Jika **Meninggal**: isi tanggal meninggal dan catatan.
4. Klik **SIMPAN**.

Catatan penting:
- Status Pindah atau Meninggal **tidak menghapus** data dari sistem.
- Data historis tetap ada untuk kebutuhan laporan.
- Untuk kembali ke status Aktif, ubah lagi statusnya.

## 12. Mengganti Foto KK

Jika ada foto KK yang lebih jelas:

1. Buka detail KK.
2. Klik tombol **GANTI FOTO KK**.
3. Pilih foto baru.
4. Klik **UNGGAH**.

Foto lama akan diganti dengan foto baru. Data tidak berubah.

## 13. Ekspor Laporan

Ekspor menghasilkan file laporan sesuai dengan filter yang sedang aktif.

Untuk mengekspor:

1. Terapkan filter jika perlu (lihat bagian 5).
2. Klik tombol **PDF**, **EXCEL**, atau **CSV** di bawah tabel.
3. Pilih lokasi penyimpanan di komputer.
4. Tunggu sampai file selesai dibuat.

Format file:

- **PDF** — untuk dicetak.
- **Excel** (.xlsx) — untuk diedit dengan Microsoft Excel.
- **CSV** — untuk dibuka dengan program apa pun.

Nama file ekspor otomatis memuat tanggal dan ringkasan filter.

## 14. Backup Data

Backup membuat salinan seluruh data agar aman jika terjadi masalah.

Untuk membuat backup:

1. Klik menu **BACKUP** di sidebar.
2. Klik tombol **BUAT BACKUP**.
3. Tunggu sampai proses selesai.
4. File backup bernama `backup_TANGGAL_JAM.zip` akan tersimpan di folder yang ditentukan.

Backup berisi:

- Database (seluruh data penduduk).
- Foto KK.
- Pengaturan aplikasi.

Catatan:
- Backup lama **tidak** dihapus.
- Lakukan backup secara berkala, misalnya setiap minggu.

## 15. Restore Data

Restore mengembalikan data dari file backup. **Hati-hati**: restore akan menimpa data yang ada.

Untuk restore:

1. Klik menu **BACKUP** di sidebar.
2. Klik tombol **RESTORE**.
3. Pilih file backup (.zip).
4. Sistem akan memvalidasi file.
5. Klik **KONFIRMASI** untuk melanjutkan.
6. Tunggu sampai proses selesai.
7. **Restart aplikasi** seperti yang diminta.

Catatan:
- Restore tidak dapat dibatalkan.
- Jika ragu, buat backup data saat ini terlebih dahulu sebelum restore.

## 16. Pengaturan

Halaman Pengaturan berisi identitas kelurahan.

Untuk membuka:

1. Klik menu **PENGATURAN** di sidebar.
2. Edit field berikut jika perlu:
   - **Nama Kelurahan**.
   - **Nama Kecamatan**.
   - **Nama Kabupaten**.
   - **Nama Provinsi**.
   - **Logo** (opsional).
   - **Lokasi Backup**.
3. Klik **SIMPAN**.

Pengaturan ini akan muncul di laporan dan dashboard.

## 17. Pesan Kesalahan Umum

| Pesan | Artinya | Solusi |
|-------|---------|--------|
| "Nomor KK sudah ada" | KK ini sudah pernah disimpan | Gunakan KK Number yang berbeda, atau cari KK existing |
| "NIK sudah ada" | NIK ini sudah pernah disimpan | Cek data NIK, atau cari penduduk existing |
| "OCR gagal membaca foto" | Foto tidak terbaca | Coba foto lain, atau input manual |
| "Akun terkunci" | 5x gagal login | Tunggu 15 menit |
| "File terlalu besar" | Foto KK > 5 MB | Kecilkan ukuran foto |
| "Format file tidak didukung" | Bukan JPG/PNG | Ubah format file |

## 18. Tips Harian

- Backup data setiap minggu.
- Selalu periksa hasil OCR sebelum klik SIMPAN.
- Gunakan filter untuk mempercepat pencarian.
- Jangan bagikan kata sandi Anda.
- Jika ada data yang salah, lebih baik ubah status menjadi perbaikan daripada dihapus.
- Untuk data yang sensitif, tutup aplikasi setelah selesai.

## 19. Kontak Developer

Jika menemukan masalah yang tidak bisa diselesaikan sendiri:

- Catat pesan kesalahan yang muncul.
- Catat langkah yang sudah dilakukan.
- Hubungi developer untuk bantuan.

## 20. Glosarium

- **KK** — Kartu Keluarga.
- **NIK** — Nomor Induk Kependudukan (16 digit).
- **RT / RW** — Rukun Tetangga / Rukun Warga.
- **Lingkungan** — pembagian wilayah di kelurahan.
- **OCR** — pembacaan otomatis teks dari gambar.
- **Backup** — salinan data untuk keamanan.
- **Restore** — mengembalikan data dari backup.
- **Ekspor** — menghasilkan file laporan.
