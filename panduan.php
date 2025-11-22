<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buku Panduan Sistem Informasi Pramuka</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        body {
            background: #4b4f52;
            font-family: "Times New Roman", serif;
        }

        .page {
            background: white;
            width: 21cm;
            min-height: 29.7cm;
            padding: 2.2cm;
            margin: 1.2cm auto;
            box-shadow: 0 0 15px rgba(0,0,0,0.45);
            position: relative;
        }

        /* COVER */
        .cover {
            text-align: center;
            padding-top: 2.8cm;
        }

        .cover-logo {
            width: 140px;
            margin-bottom: 25px;
        }

        .cover h1 {
            font-size: 28pt;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }

        .cover h3 {
            font-size: 16pt;
            color: #555;
            margin-bottom: 40px;
        }

        .line {
            width: 75%;
            height: 2px;
            background: #ddd;
            margin: 20px auto 35px;
        }

        .cover-author {
            font-size: 12.5pt;
            color: #333;
            line-height: 1.6;
            margin-top: 40px;
        }


        /* HEADINGS */
        h2 {
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            margin-top: 25px;
            font-size: 20pt;
            color: #2c3e50;
            font-family: "Segoe UI", sans-serif;
        }

        h4 {
            font-size: 14.5pt;
            margin-top: 18px;
            color: #0d6efd;
            font-family: "Segoe UI", sans-serif;
        }

        /* TEXT */
        p, li {
            font-size: 12.5pt;
            line-height: 1.65;
            text-align: justify;
        }

        ul li, ol li {
            margin-bottom: 4px;
        }

        /* BADGES */
        .role-badge {
            padding: 5px 10px;
            border-radius: 4px;
            color: white;
            font-size: 10pt;
            font-family: "Segoe UI", sans-serif;
        }
        .bg-induk { background: #2c3e50; }
        .bg-unit { background: #0d6efd; }
        .bg-regu { background: #198754; }

        /* NOTES */
        .note {
            background: #fff3cd;
            padding: 12px;
            border-left: 5px solid #ffc107;
            font-size: 11.5pt;
            margin-top: 15px;
            border-radius: 4px;
        }

        /* PRINT MODE */
        @media print {
            body { background: white; margin: 0; }
            .page {
                box-shadow: none;
                margin: 0;
                width: 100%;
                page-break-after: always;
            }
            .no-print { display: none !important; }
        }

        /* FLOATING PDF BUTTON */
        .fab {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #dc3545;
            color: white;
            padding: 15px 25px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: bold;
            font-family: "Segoe UI", sans-serif;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            transition: 0.3s;
            z-index: 9999;
        }

        .fab:hover {
            background: #b22a34;
            transform: scale(1.05);
        }
    </style>
</head>
<body>

<a href="javascript:window.print()" class="fab no-print">
    <i class="fas fa-file-pdf me-2"></i> Simpan PDF
</a>


<!-- COVER -->
<div class="page">
    <div class="cover">
        <img src="https://cdn.fastrs.id/uploads/fastrsfavicon.png" class="cover-logo" alt="Logo">

        <h1>PANDUAN PENGGUNA<br>SISTEM INFORMASI PRAMUKA</h1>
        <h3>Versi 2.0 (Cloud & Realtime Update)</h3>

        <div class="line"></div>

        <p class="cover-author">
            <strong>Dibuat Oleh:</strong><br>
            Richie Fatur Cahyadi<br>
            Tahun <?= date('Y') ?>
        </p>
    </div>
</div>


<!-- PAGE 1 -->
<div class="page">
    <h2>1. Pendahuluan</h2>
    <p>Sistem Informasi Pramuka adalah aplikasi berbasis web yang dirancang untuk mempermudah pengelolaan administrasi kepramukaan, mulai dari tingkat Induk (Pusat), Unit (Sekolah), hingga Regu (Siswa). Sistem ini dilengkapi dengan penyimpanan berbasis Cloud (Backblaze B2) untuk keamanan data foto dan dokumen.</p>

    <h2>2. Hak Akses (Role)</h2>
    <p>Terdapat 3 level pengguna dalam sistem ini:</p>

    <ul>
        <li><span class="role-badge bg-induk">INDUK</span> <strong>(Super Admin)</strong>: Memiliki akses penuh untuk membuat Unit sekolah, mengelola pengumuman, dan pengaturan sistem.</li>

        <li><span class="role-badge bg-unit">UNIT</span> <strong>(Admin Sekolah)</strong>: Mengelola data regu, siswa, absensi, laporan kas, serta verifikasi iuran.</li>

        <li><span class="role-badge bg-regu">REGU</span> <strong>(Ketua Regu/Siswa)</strong>: Mengakses informasi, laporan iuran, dan fitur pengumuman.</li>
    </ul>
</div>


<!-- PAGE 2 -->
<div class="page">
    <h2>3. Panduan Pengguna: INDUK</h2>

    <h4>A. Dashboard & Statistik</h4>
    <p>Pada halaman utama, Induk dapat melihat ringkasan jumlah Unit, Regu, dan total Siswa di seluruh sistem.</p>

    <h4>B. Mengelola Unit (Sekolah)</h4>
    <ol>
        <li>Buka menu <strong>Unit Pembelajaran</strong>.</li>
        <li>Isi form lengkap (Nama Sekolah, Jenjang, Alamat, Username & Password admin).</li>
        <li>Klik <strong>Tambah Unit</strong>.</li>
    </ol>

    <h4>C. Membuat Pengumuman</h4>
    <ol>
        <li>Buka menu <strong>Pengumuman</strong>.</li>
        <li>Isi Judul dan pilih Tujuan (Semua/Unit/Regu).</li>
        <li>Gunakan editor teks untuk styling.</li>
        <li>Upload gambar banner jika diperlukan.</li>
        <li>Klik <strong>Terbitkan</strong>.</li>
    </ol>

    <h4>D. Pengaturan Sistem</h4>
    <ul>
        <li>Ganti logo kiri/kanan untuk Kop Surat PDF.</li>
        <li>Edit nama instansi dan alamat.</li>
        <li>Ubah password akun Induk.</li>
    </ul>
</div>


<!-- PAGE 3 -->
<div class="page">
    <h2>4. Panduan Pengguna: UNIT (Sekolah)</h2>

    <h4>A. Menambah Regu</h4>
    <ol>
        <li>Buka menu <strong>Data Regu</strong>.</li>
        <li>Isi Nama Regu dan Jenis Kelamin.</li>
        <li>Buat Username & Password untuk Ketua Regu.</li>
        <li>Klik <strong>Tambah</strong>.</li>
    </ol>

    <h4>B. Mengelola Siswa & Absensi</h4>
    <ol>
        <li>Buka menu <strong>Data Siswa</strong>.</li>
        <li>Tambah siswa ke regu tertentu.</li>
        <li>Klik <strong>Cetak Absensi</strong> untuk unduhan format absen siap print.</li>
    </ol>

    <h4>C. Verifikasi Iuran & Kas</h4>
    <ol>
        <li>Buka menu <strong>Iuran</strong>.</li>
        <li>Lihat kotak “Verifikasi Pembayaran”.</li>
        <li>Klik <strong>Lihat Bukti</strong> untuk membuka foto dari Cloud Storage.</li>
        <li>Setujui dengan klik <strong>Centang (✔)</strong>.</li>
    </ol>

    <div class="note">
        <strong>Catatan:</strong> Nominal iuran dapat diatur di menu Iuran bagian "Pengaturan Iuran".
    </div>
</div>


<!-- PAGE 4 -->
<div class="page">
    <h2>5. Panduan Pengguna: REGU (Siswa)</h2>

    <h4>A. Melihat Pengumuman</h4>
    <ul>
        <li>Bisa memberi reaksi Like atau Love.</li>
        <li>Bisa membagikan pengumuman ke grup WhatsApp.</li>
    </ul>

    <h4>B. Lapor Iuran</h4>
    <ol>
        <li>Buka menu <strong>Lapor Iuran</strong>.</li>
        <li>Pilih anggota yang membayar.</li>
        <li>Upload foto bukti pembayaran.</li>
        <li>Masukkan nominal.</li>
        <li>Klik <strong>Kirim</strong> dan tunggu tanda centang hijau.</li>
    </ol>

    <h4>C. Melihat Riwayat Pembayaran</h4>
    <ul>
        <li><span class="badge bg-warning text-dark">MENUNGGU</span> menandakan menunggu verifikasi.</li>
        <li><span class="badge bg-success">LUNAS</span> pembayaran sudah diterima.</li>
    </ul>
</div>

</body>
</html>
