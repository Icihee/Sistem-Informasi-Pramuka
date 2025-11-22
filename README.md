# ⚜️ Sistem Informasi Manajemen Pramuka (SIP)

Aplikasi berbasis web untuk manajemen administrasi, keuangan, dan
informasi kegiatan Pramuka yang terintegrasi mulai dari tingkat Induk
(Pusat), Unit (Sekolah), hingga Regu (Siswa).

Aplikasi ini dibangun menggunakan **PHP Native** dengan integrasi
**Cloud Storage (Backblaze B2)** untuk penyimpanan file yang aman,
cepat, dan efisien.

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)
![Backblaze](https://img.shields.io/badge/Backblaze%20B2-Cloud%20Storage-red?style=for-the-badge)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

## ✨ Fitur Utama

### 🏢 1. Role Induk (Super Admin)

-   **Manajemen Unit:** CRUD data Unit/Sekolah + akun login.
-   **Broadcast Pengumuman:** Rich Text Editor (Summernote), upload
    gambar otomatis ke Cloud (Backblaze B2).
-   **Monitoring:** Statistik Unit, Regu, Siswa.
-   **Analitik Konten:** Cek viewer & reaksi (Like/Love) pengumuman.
-   **Pengaturan Sistem:** Kustomisasi Kop Surat untuk PDF otomatis.

### 🏫 2. Role Unit (Admin Sekolah/Pembina)

-   **Manajemen Regu & Siswa:** Data anggota, pembagian regu, akun ketua
    regu.
-   **Verifikasi Keuangan:** Preview bukti bayar via Presigned URL.
-   **Laporan Kas:** Pencatatan kas masuk & keluar.
-   **Cetak Absensi:** Generate laporan absensi per regu.

### ⛺ 3. Role Regu (Siswa/Ketua Regu)

-   **News Feed:** Pengumuman dengan tampilan modern (Card View).
-   **Interaksi:** Like/Love dan Share ke WhatsApp (Open Graph valid).
-   **Lapor Iuran:** Upload bukti bayar dengan **auto image
    compression**.
-   **Riwayat Pembayaran:** Menunggu Verifikasi / Lunas.
-   **Mobile Friendly:** Navigasi bawah + FAB untuk lapor iuran.

## 🛠️ Teknologi yang Digunakan

-   **Backend:** PHP Native (PDO)
-   **Frontend:** Bootstrap 5, FontAwesome 6, Summernote WYSIWYG
-   **Database:** MySQL
-   **Cloud Storage:** Backblaze B2 (via AWS SDK for PHP)
-   **Library Tambahan:**
    -   `vlucas/phpdotenv`
    -   `aws/aws-sdk-php`

## 🚀 Panduan Instalasi & Konfigurasi

### 1. Clone Repository

``` bash
git clone https://github.com/Icihee/Sistem-Informasi-Pramuka.git
cd Sistem-Informasi-Pramuka
```

### 2. Install Dependencies

Pastikan Composer sudah terinstall.

``` bash
composer install
```

### 3. Konfigurasi Database

1.  Buat database baru, contoh: `db_pramuka`
2.  Import `pramuka.sql`
3.  Sesuaikan konfigurasi di `.env`

### 4. Konfigurasi Environment (.env)

    DB_HOST=localhost
    DB_NAME=db_pramuka
    DB_USER=root
    DB_PASS=

    B2_KEY_ID=key_id_anda
    B2_APP_KEY=application_key_anda
    B2_BUCKET_NAME=nama_bucket_anda
    B2_REGION=us-west-004
    B2_ENDPOINT=https://s3.us-west-004.backblazeb2.com

### 5. Jalankan Aplikasi

    http://localhost/sistem-pramuka/

## 📂 Struktur Folder Penting

    sistem-pramuka/
    ├── assets/
    ├── auth/
    ├── config/
    ├── induk/
    ├── unit/
    ├── regu/
    ├── vendor/
    ├── .env
    ├── panduan.php
    ├── post.php
    └── index.php

## 🔒 Keamanan

-   Presigned URL (20 menit)
-   password_hash()
-   PDO Prepared Statements
-   Session validation
-   `.env` untuk kredensial

## 📖 Panduan Pengguna

Akses `panduan.php` untuk panduan digital.

## 📄 Lisensi

Proyek ini bebas digunakan untuk edukasi dan pengembangan.
