<?php
// FILE: post.php (Simpan di ROOT FOLDER)
session_start();
date_default_timezone_set('Asia/Jakarta');

// --- 1. SYSTEM SETUP ---
require 'vendor/autoload.php'; // Load Library AWS
require_once 'config/database.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Dotenv\Dotenv;

// Load Environment Variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Inisialisasi S3 Client
$s3 = new S3Client([
    'version'     => 'latest',
    'region'      => $_ENV['B2_REGION'],
    'endpoint'    => $_ENV['B2_ENDPOINT'],
    'credentials' => [
        'key'    => $_ENV['B2_KEY_ID'],
        'secret' => $_ENV['B2_APP_KEY'],
    ],
]);

// Fungsi Get URL Aman (Presigned URL)
function getSecureUrl($filename) {
    global $s3;
    try {
        if (empty($filename)) return null;
        $cmd = $s3->getCommand('GetObject', ['Bucket' => $_ENV['B2_BUCKET_NAME'], 'Key' => $filename]);
        $request = $s3->createPresignedRequest($cmd, '+20 minutes');
        return (string)$request->getUri();
    } catch (Exception $e) { return null; }
}

// Koneksi Database
try {
    $db = (new Database())->getConnection();
} catch (Exception $e) {
    die("Gagal koneksi database.");
}

// 2. Ambil Data Postingan
$id_post = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $db->prepare("SELECT * FROM pengumuman WHERE id = ?");
$stmt->execute([$id_post]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

// Jika postingan tidak ditemukan
if (!$post) {
    die("
    <div style='text-align:center; padding:50px; font-family:sans-serif;'>
        <h3>Maaf, postingan tidak ditemukan atau telah dihapus.</h3>
        <br>
        <a href='index.php' style='text-decoration:none; background:#0d6efd; color:white; padding:10px 20px; border-radius:5px;'>Kembali ke Beranda</a>
    </div>");
}

// 3. Hitung Reaction (Hanya View)
$countLike = $db->query("SELECT COUNT(*) FROM pengumuman_reactions WHERE pengumuman_id=$id_post AND reaction_type='like'")->fetchColumn();
$countLove = $db->query("SELECT COUNT(*) FROM pengumuman_reactions WHERE pengumuman_id=$id_post AND reaction_type='love'")->fetchColumn();

// 4. Siapkan Data Tampilan
// URL Halaman ini (untuk share)
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$currentUrl = "$protocol://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

// Ambil Gambar dari Bucket (Jika ada)
$gambarUrl = '';
if ($post['gambar']) {
    $gambarUrl = getSecureUrl($post['gambar']);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($post['judul']) ?> - Info Pramuka</title>
    
    <meta property="og:type" content="article" />
    <meta property="og:title" content="<?= htmlspecialchars($post['judul']) ?>" />
    <meta property="og:description" content="<?= htmlspecialchars(substr(strip_tags($post['isi']), 0, 100)) ?>..." />
    <?php if($gambarUrl): ?>
    <meta property="og:image" content="<?= $gambarUrl ?>" />
    <?php endif; ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; padding-bottom: 50px; }
        .main-container { max-width: 700px; margin: 30px auto; padding: 0 15px; }
        .card { border-radius: 15px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .card-img-top { max-height: 450px; object-fit: cover; }
        .reaction-badge { background: #f8f9fa; padding: 6px 15px; border-radius: 20px; font-size: 1rem; color: #555; display: inline-flex; align-items: center; margin-right: 10px; border: 1px solid #eee; }
        .reaction-badge i { margin-right: 6px; }
        .btn-login { border-radius: 20px; padding: 8px 25px; font-weight: 600; }
        .navbar-brand { font-weight: bold; color: #5d4037 !important; }
        
        /* CSS untuk Konten Rich Text agar Responsif */
        .post-content { font-size: 1.05rem; line-height: 1.8; color: #333; }
        .post-content img { max-width: 100% !important; height: auto !important; border-radius: 8px; margin: 10px 0; }
        .post-content h1, .post-content h2, .post-content h3 { color: #2c3e50; margin-top: 20px; }
        .post-content ul, .post-content ol { padding-left: 20px; }
    </style>
</head>
<body>

<nav class="navbar navbar-light bg-white shadow-sm sticky-top mb-4">
    <div class="container justify-content-between">
        <a class="navbar-brand" href="#"><i class="fas fa-campground me-2"></i>INFO PRAMUKA</a>
        <?php if(isset($_SESSION['is_login'])): ?>
            <?php if($_SESSION['role'] == 'regu'): ?>
                <a href="regu/dashboard.php" class="btn btn-primary btn-sm btn-login">Dashboard</a>
            <?php elseif($_SESSION['role'] == 'unit'): ?>
                <a href="unit/dashboard.php" class="btn btn-primary btn-sm btn-login">Dashboard</a>
            <?php elseif($_SESSION['role'] == 'induk'): ?>
                <a href="induk/dashboard.php" class="btn btn-primary btn-sm btn-login">Dashboard</a>
            <?php endif; ?>
        <?php else: ?>
            <a href="auth/login.php" class="btn btn-outline-primary btn-sm btn-login">Login</a>
        <?php endif; ?>
    </div>
</nav>

<div class="main-container">
    <div class="card">
        <?php if($gambarUrl): ?>
            <img src="<?= $gambarUrl ?>" class="card-img-top" alt="Gambar Postingan">
        <?php endif; ?>

        <div class="card-body p-4">
            <h3 class="card-title fw-bold mb-2" style="color: #2c3e50;"><?= htmlspecialchars($post['judul']) ?></h3>
            <small class="text-muted d-block mb-4 border-bottom pb-3">
                <i class="far fa-calendar-alt me-1"></i> <?= date('d F Y, H:i', strtotime($post['tanggal'])) ?> WIB
            </small>
            
            <div class="post-content mb-5">
                <?= $post['isi'] ?>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top">
                <div>
                    <span class="reaction-badge">
                        <i class="fas fa-thumbs-up text-primary"></i> <?= $countLike ?>
                    </span>
                    <span class="reaction-badge">
                        <i class="fas fa-heart text-danger"></i> <?= $countLove ?>
                    </span>
                </div>
                
                <a href="https://wa.me/?text=<?= urlencode("*INFO PRAMUKA*\n".$post['judul']."\n\nLihat selengkapnya: ".$currentUrl) ?>" target="_blank" class="btn btn-success btn-sm rounded-pill px-4 py-2 shadow-sm fw-bold">
                    <i class="fab fa-whatsapp me-1"></i> Bagikan
                </a>
            </div>
        </div>
        
        <?php if(!isset($_SESSION['is_login'])): ?>
        <div class="card-footer bg-light text-center p-3">
            <small class="text-muted">Anggota Pramuka? Silakan login untuk berinteraksi.</small><br>
            <a href="auth/login.php" class="text-decoration-none fw-bold mt-1 d-inline-block">Masuk ke Aplikasi</a>
        </div>
        <?php endif; ?>
    </div>

    <div class="text-center mt-5 mb-5">
        <small class="text-muted">&copy; <?= date('Y') ?> Pramuka Digital Apps</small>
    </div>
</div>

</body>
</html>