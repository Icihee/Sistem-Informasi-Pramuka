# ⚜️ Sistem Informasi Manajemen Pramuka (SIP)

Aplikasi berbasis web untuk manajemen administrasi, keuangan, dan informasi kegiatan Pramuka yang terintegrasi mulai dari tingkat Induk (Pusat), Unit (Sekolah), hingga Regu (Siswa).

Aplikasi ini dibangun menggunakan **PHP Native** dengan integrasi **Cloud Storage (Backblaze B2)** untuk penyimpanan file yang aman dan efisien.

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)
![Backblaze](https://img.shields.io/badge/Backblaze%20B2-Cloud%20Storage-red?style=for-the-badge)

## ✨ Fitur Utama

### 1. 🏢 Role Induk (Super Admin)
* **Manajemen Unit:** CRUD data Unit/Sekolah.
* **Broadcast Pengumuman:** Membuat pengumuman dengan *Rich Text Editor* (Summernote) + Upload Gambar ke Cloud.
* **Monitoring:** Dashboard statistik jumlah Unit, Regu, dan Siswa.
* **Pengaturan Sistem:** Kustomisasi Kop Surat (Logo Kiri/Kanan, Nama Instansi) untuk laporan PDF.
* **Statistik Interaksi:** Melihat Regu mana saja yang sudah melihat (View) dan bereaksi (Like/Love) pada pengumuman.

### 2. 🏫 Role Unit (Admin Sekolah/Pembina)
* **Manajemen Regu & Siswa:** Mengelola data anggota dan pembagian regu.
* **Verifikasi Keuangan:** Verifikasi bukti bayar iuran dari siswa dengan preview gambar dari Cloud.
* **Laporan Kas:** Pencatatan otomatis kas masuk (dari iuran) dan manual (pengeluaran).
* **Cetak Absensi:** Generate laporan absensi siswa siap print.

### 3. ⛺ Role Regu (Siswa/Ketua Regu)
* **News Feed:** Melihat pengumuman terbaru dengan tampilan modern (Card View).
* **Interaksi:** Memberikan reaksi (Like/Love) dan Share pengumuman ke WhatsApp (dengan Link Preview).
* **Lapor Iuran:** Upload bukti pembayaran dengan fitur **Auto Image Compression** (Kompresi otomatis di sisi klien agar hemat kuota & cepat).
* **Riwayat:** Cek status pembayaran (Menunggu/Lunas).
* **Mobile Friendly:** Tampilan khusus mobile dengan Navigasi Bawah dan Tombol Aksi Cepat (FAB).

---

## 🛠️ Teknologi yang Digunakan

* **Backend:** PHP Native (PDO)
* **Frontend:** Bootstrap 5, FontAwesome 6
* **Database:** MySQL
* **Cloud Storage:** Backblaze B2 (via AWS SDK for PHP)
* **Library Tambahan:**
    * `vlucas/phpdotenv`: Manajemen Environment Variable.
    * `aws/aws-sdk-php`: Koneksi ke S3 Compatible Storage.
    * `summernote`: WYSIWYG Editor.

---

## 🚀 Instalasi & Konfigurasi

Ikuti langkah-langkah ini untuk menjalankan project di komputer lokal:

### 1. Clone Repository
```bash
git clone https://github.com/Icihee/Sistem-Informasi-Pramuka.git
cd sistem-pramuka
