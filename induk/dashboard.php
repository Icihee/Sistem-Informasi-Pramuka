<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

// --- 1. SYSTEM SETUP ---
require __DIR__ . '/../vendor/autoload.php';
require_once '../config/database.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Dotenv\Dotenv;

// Load Environment Variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
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

// Cek Login Induk
if (!isset($_SESSION['is_login']) || $_SESSION['role'] !== 'induk') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();
$message = ""; 
$page = isset($_GET['page']) ? $_GET['page'] : 'home';
$print_mode = isset($_GET['print']); 

// --- 2. HELPER FUNCTIONS ---

// Upload ke B2 (Untuk Pengumuman/File Besar)
function uploadKeB2($file, $subfolder = 'PENGUMUMAN') {
    global $s3;
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = time() . '_' . uniqid() . '.' . $ext;
    $keyName = "PROJECT-APPS-PRAMUKA/" . $subfolder . "/" . $filename;
    try {
        $s3->putObject(['Bucket' => $_ENV['B2_BUCKET_NAME'], 'Key' => $keyName, 'SourceFile' => $file['tmp_name']]);
        return $keyName;
    } catch (Exception $e) { return null; }
}

// Get URL Aman (Presigned URL)
function getSecureUrl($filename) {
    global $s3;
    try {
        if (empty($filename)) return null;
        $cmd = $s3->getCommand('GetObject', ['Bucket' => $_ENV['B2_BUCKET_NAME'], 'Key' => $filename]);
        $request = $s3->createPresignedRequest($cmd, '+20 minutes');
        return (string)$request->getUri();
    } catch (Exception $e) { return null; }
}

// Upload Lokal (Khusus Logo Kop Surat agar cepat load)
function uploadLocal($file, $target_dir = "../assets/uploads/") {
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
    $fileType = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($fileType, $allowed)) return false;
    $filename = time() . "_" . uniqid() . "." . $fileType;
    if (move_uploaded_file($file["tmp_name"], $target_dir . $filename)) return $filename;
    return false;
}

// Ambil Settings
$settings = ['nama_instansi' => '', 'alamat_instansi' => '', 'logo_kiri' => '', 'logo_kanan' => ''];
$stmt = $db->query("SELECT * FROM system_settings");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// --- 3. LOGIKA ACTION ---

if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = $_GET['id']; $type = $_GET['type'];
    try {
        $db->beginTransaction();
        if ($type == 'unit') {
            $db->prepare("DELETE FROM users WHERE role='unit' AND related_id=?")->execute([$id]);
            $db->prepare("DELETE FROM units WHERE id=?")->execute([$id]); $pg = 'units';
        } elseif ($type == 'regu') {
            $db->prepare("DELETE FROM users WHERE role='regu' AND related_id=?")->execute([$id]);
            $db->prepare("DELETE FROM regus WHERE id=?")->execute([$id]); $pg = 'regus';
        } elseif ($type == 'member') {
            $db->prepare("DELETE FROM anggota_regu WHERE id=?")->execute([$id]); $pg = 'members';
        } elseif ($type == 'info') {
            $db->prepare("DELETE FROM pengumuman_reactions WHERE pengumuman_id=?")->execute([$id]);
            $db->prepare("DELETE FROM pengumuman_views WHERE pengumuman_id=?")->execute([$id]);
            $db->prepare("DELETE FROM pengumuman WHERE id=?")->execute([$id]); $pg = 'info';
        }
        $db->commit();
        header("Location: dashboard.php?page=$pg&msg=deleted"); exit();
    } catch (Exception $e) { $db->rollBack(); $message = "<div class='alert alert-danger'>Gagal: " . $e->getMessage() . "</div>"; }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['save_member'])) {
            if (!empty($_POST['member_id'])) $db->prepare("UPDATE anggota_regu SET nama_anggota=?, jabatan=?, regu_id=? WHERE id=?")->execute([$_POST['nama_anggota'], $_POST['jabatan'], $_POST['regu_id'], $_POST['member_id']]);
            else $db->prepare("INSERT INTO anggota_regu (regu_id, nama_anggota, jabatan) VALUES (?, ?, ?)")->execute([$_POST['regu_id'], $_POST['nama_anggota'], $_POST['jabatan']]);
            $message = "<div class='alert alert-success'>Data siswa disimpan!</div>";
        } elseif (isset($_POST['add_unit'])) {
            $db->beginTransaction();
            $db->prepare("INSERT INTO units (nama_sekolah, jenjang, alamat) VALUES (?, ?, ?)")->execute([$_POST['nama_sekolah'], $_POST['jenjang'], $_POST['alamat']]);
            $newId = $db->lastInsertId();
            $db->prepare("INSERT INTO users (username, password, role, related_id) VALUES (?, ?, 'unit', ?)")->execute([$_POST['username'], password_hash($_POST['password'], PASSWORD_DEFAULT), $newId]);
            $db->commit(); $message = "<div class='alert alert-success'>Unit ditambahkan!</div>";
        } elseif (isset($_POST['add_regu'])) {
            $db->beginTransaction();
            $db->prepare("INSERT INTO regus (unit_id, nama_regu, jenis_kelamin) VALUES (?, ?, ?)")->execute([$_POST['unit_id'], $_POST['nama_regu'], $_POST['jk']]);
            $newId = $db->lastInsertId();
            $db->prepare("INSERT INTO users (username, password, role, related_id) VALUES (?, ?, 'regu', ?)")->execute([$_POST['username'], password_hash($_POST['password'], PASSWORD_DEFAULT), $newId]);
            $db->commit(); $message = "<div class='alert alert-success'>Regu ditambahkan!</div>";
        } elseif (isset($_POST['add_info'])) {
            // UPLOAD KE BACKBLAZE
            $imgName = null;
            if (!empty($_FILES['gambar']['name'])) $imgName = uploadKeB2($_FILES['gambar'], 'PENGUMUMAN');
            
            $stmt = $db->prepare("INSERT INTO pengumuman (judul, isi, gambar, user_id, tujuan_role, tanggal) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_POST['judul'], $_POST['isi'], $imgName, $_SESSION['user_id'], $_POST['tujuan']]);
            $message = "<div class='alert alert-success'>Pengumuman diterbitkan!</div>";
        } elseif (isset($_POST['update_settings'])) {
            function upsert($db, $k, $v) {
                if($db->query("SELECT id FROM system_settings WHERE setting_key='$k'")->rowCount() > 0)
                    $db->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute([$v, $k]);
                else $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
            }
            if (!empty($_FILES['logo_kiri']['name'])) upsert($db, 'logo_kiri', uploadLocal($_FILES['logo_kiri']));
            if (!empty($_FILES['logo_kanan']['name'])) upsert($db, 'logo_kanan', uploadLocal($_FILES['logo_kanan']));
            upsert($db, 'nama_instansi', $_POST['nama_instansi']);
            upsert($db, 'alamat_instansi', $_POST['alamat_instansi']);
            if(!empty($_POST['username'])) {
                $q = "UPDATE users SET username=?"; $p = [$_POST['username']];
                if (!empty($_POST['password'])) { $q .= ", password=?"; $p[] = password_hash($_POST['password'], PASSWORD_DEFAULT); }
                $q .= " WHERE id=?"; $p[] = $_SESSION['user_id'];
                $db->prepare($q)->execute($p);
                $_SESSION['username'] = $_POST['username'];
            }
            $message = "<div class='alert alert-success'>Pengaturan disimpan!</div>";
            $stmt = $db->query("SELECT * FROM system_settings");
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) { if ($db->inTransaction()) $db->rollBack(); $message = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>"; }
}

if (isset($_GET['msg']) && $_GET['msg']=='deleted') $message = "<div class='alert alert-warning'>Data berhasil dihapus.</div>";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Induk</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">

    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; padding-bottom: 80px; }
        
        /* Sidebar */
        .sidebar { min-height: 100vh; width: 260px; background: #fff; border-right: 1px solid #eee; position: fixed; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { padding: 25px; font-weight: 800; color: #2c3e50; font-size: 1.3rem; border-bottom: 1px solid #f8f9fa; }
        .sidebar a { color: #7f8c8d; text-decoration: none; display: block; padding: 15px 20px; font-weight: 500; transition: 0.2s; border-left: 4px solid transparent; }
        .sidebar a:hover, .sidebar a.active { background: #f8f9fa; color: #34495e; border-left-color: #34495e; }
        
        /* Content */
        .main-content { margin-left: 260px; padding: 30px; }
        
        /* Cards */
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); transition: 0.2s; }
        .card-header { background: white; border-bottom: 1px solid #f1f1f1; font-weight: 700; padding: 15px 20px; border-radius: 12px 12px 0 0 !important; }
        
        /* Responsive */
        .mobile-header, .bottom-nav { display: none; }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 15px; }
            .mobile-header { display: flex; justify-content: space-between; align-items: center; background: #34495e; color: white; padding: 15px 20px; border-radius: 0 0 20px 20px; margin-bottom: 20px; box-shadow: 0 5px 15px rgba(52, 73, 94, 0.3); }
            .bottom-nav { display: flex; position: fixed; bottom: 0; left: 0; width: 100%; background: white; justify-content: space-around; padding: 12px 0; z-index: 1050; box-shadow: 0 -5px 20px rgba(0,0,0,0.05); border-radius: 20px 20px 0 0; }
            .nav-item { text-align: center; color: #95a5a6; text-decoration: none; font-size: 10px; flex: 1; display: flex; flex-direction: column; align-items: center; }
            .nav-item i { font-size: 20px; margin-bottom: 4px; transition: 0.2s; }
            .nav-item.active { color: #34495e; font-weight: 700; }
            .nav-item.active i { transform: translateY(-2px); }
        }

        /* Print */
        .kop-surat { display: none; }
        @media print {
            .sidebar, .bottom-nav, .mobile-header, .no-print, .btn, .alert { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
            .card { box-shadow: none !important; border: 1px solid #ddd !important; }
            .kop-surat { display: flex !important; justify-content: space-between; border-bottom: 3px double black; padding-bottom: 10px; margin-bottom: 20px; align-items: center; }
            .kop-logo { height: 80px; }
        }
        
        /* Summernote & Stats */
        .rich-text-content img { max-width: 100%; height: auto; border-radius: 8px; }
    </style>
</head>
<body>

<div class="sidebar d-none d-md-block">
    <div class="brand"><i class="fas fa-campground me-2"></i> PANEL INDUK</div>
    <div class="mt-3">
        <a href="?page=home" class="<?= $page=='home'?'active':'' ?>"><i class="fas fa-th-large me-3"></i> Dashboard</a>
        <a href="?page=units" class="<?= $page=='units'?'active':'' ?>"><i class="fas fa-school me-3"></i> Data Unit</a>
        <a href="?page=regus" class="<?= $page=='regus'?'active':'' ?>"><i class="fas fa-users me-3"></i> Data Regu</a>
        <a href="?page=members" class="<?= $page=='members'?'active':'' ?>"><i class="fas fa-user-graduate me-3"></i> Data Siswa</a>
        <a href="?page=kas" class="<?= $page=='kas'?'active':'' ?>"><i class="fas fa-wallet me-3"></i> Laporan Kas</a>
        <a href="?page=info" class="<?= $page=='info'?'active':'' ?>"><i class="fas fa-bullhorn me-3"></i> Pengumuman</a>
        <a href="?page=settings" class="<?= $page=='settings'?'active':'' ?>"><i class="fas fa-cogs me-3"></i> Pengaturan</a>
    </div>
    <div class="px-4 mt-5">
        <a href="../auth/logout.php" class="btn btn-outline-danger w-100 rounded-pill"><i class="fas fa-sign-out-alt me-2"></i> Logout</a>
    </div>
</div>

<div class="mobile-header">
    <div>
        <h5 class="m-0 fw-bold">Panel Induk</h5>
        <small class="opacity-75">Administrator</small>
    </div>
    <a href="../auth/logout.php" class="text-white"><i class="fas fa-sign-out-alt fa-lg"></i></a>
</div>

<div class="main-content">
    
    <div class="kop-surat">
        <div><?php if($settings['logo_kiri']) echo "<img src='../assets/uploads/{$settings['logo_kiri']}' class='kop-logo'>"; ?></div>
        <div class="text-center w-100">
            <h3 class="fw-bold text-uppercase m-0"><?= $settings['nama_instansi'] ?></h3>
            <p class="m-0"><?= $settings['alamat_instansi'] ?></p>
        </div>
        <div><?php if($settings['logo_kanan']) echo "<img src='../assets/uploads/{$settings['logo_kanan']}' class='kop-logo'>"; ?></div>
    </div>

    <?= $message ?>

    <?php switch ($page) { 
        // ================= DASHBOARD =================
        case 'home': 
            $c_unit = $db->query("SELECT COUNT(*) FROM units")->fetchColumn();
            $c_regu = $db->query("SELECT COUNT(*) FROM regus")->fetchColumn();
            $c_siswa = $db->query("SELECT COUNT(*) FROM anggota_regu")->fetchColumn();
        ?>
            <h4 class="mb-4 fw-bold text-secondary no-print">Dashboard Overview</h4>
            <div class="row g-3">
                <div class="col-md-4 col-6">
                    <div class="card bg-primary text-white p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><h2 class="m-0 fw-bold"><?= $c_unit ?></h2><small>Unit Sekolah</small></div>
                            <i class="fas fa-school fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-6">
                    <div class="card bg-success text-white p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><h2 class="m-0 fw-bold"><?= $c_regu ?></h2><small>Total Regu</small></div>
                            <i class="fas fa-users fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <div class="card bg-warning text-dark p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><h2 class="m-0 fw-bold"><?= $c_siswa ?></h2><small>Total Siswa</small></div>
                            <i class="fas fa-user-graduate fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        <?php break; 

        // ================= UNIT =================
        case 'units':
            $units = $db->query("SELECT u.*, us.username FROM units u LEFT JOIN users us ON us.related_id = u.id AND us.role='unit' ORDER BY u.nama_sekolah ASC");
        ?>
            <div class="card shadow-sm">
                <div class="card-header"><i class="fas fa-school me-2"></i> Kelola Unit</div>
                <div class="card-body">
                    <form method="POST" class="row g-2 mb-4 no-print bg-light p-3 rounded">
                        <div class="col-md-4"><input type="text" name="nama_sekolah" class="form-control" placeholder="Nama Sekolah" required></div>
                        <div class="col-md-2"><select name="jenjang" class="form-select"><option>SD</option><option>SMP</option><option>SMA</option><option>SMK</option></select></div>
                        <div class="col-md-6"><input type="text" name="alamat" class="form-control" placeholder="Alamat"></div>
                        <div class="col-md-4"><input type="text" name="username" class="form-control" placeholder="Username Login" required></div>
                        <div class="col-md-4"><input type="text" name="password" class="form-control" placeholder="Password" required></div>
                        <div class="col-md-4"><button name="add_unit" class="btn btn-primary w-100">Tambah Unit</button></div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light"><tr><th>Nama Sekolah</th><th>Jenjang</th><th>Akun</th><th class="no-print text-center">Aksi</th></tr></thead>
                            <tbody><?php while($u = $units->fetch(PDO::FETCH_ASSOC)): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($u['nama_sekolah']) ?></td>
                                    <td><span class="badge bg-secondary"><?= $u['jenjang'] ?></span></td>
                                    <td><?= $u['username'] ?></td>
                                    <td class="no-print text-center"><a href="?action=delete&type=unit&id=<?= $u['id'] ?>" class="btn btn-sm btn-light text-danger" onclick="return confirm('Hapus Unit ini? Semua regu didalamnya akan terhapus.')"><i class="fas fa-trash"></i></a></td>
                                </tr>
                            <?php endwhile; ?></tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php break;

        // ================= REGU =================
        case 'regus':
            $list_unit = $db->query("SELECT * FROM units ORDER BY nama_sekolah ASC");
            $regus = $db->query("SELECT r.*, u.nama_sekolah, us.username FROM regus r JOIN units u ON r.unit_id = u.id LEFT JOIN users us ON us.related_id = r.id AND us.role='regu' ORDER BY u.nama_sekolah ASC");
        ?>
            <div class="card shadow-sm">
                <div class="card-header"><i class="fas fa-users me-2"></i> Kelola Regu</div>
                <div class="card-body">
                    <form method="POST" class="row g-2 mb-4 no-print bg-light p-3 rounded">
                        <div class="col-md-3">
                            <select name="unit_id" class="form-select" required>
                                <option value="">Pilih Unit...</option>
                                <?php while($lu = $list_unit->fetch(PDO::FETCH_ASSOC)): ?><option value="<?= $lu['id'] ?>"><?= $lu['nama_sekolah'] ?></option><?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-3"><input type="text" name="nama_regu" class="form-control" placeholder="Nama Regu" required></div>
                        <div class="col-md-2"><select name="jk" class="form-select"><option value="L">Putra</option><option value="P">Putri</option></select></div>
                        <div class="col-md-2"><input type="text" name="username" class="form-control" placeholder="User Login" required></div>
                        <div class="col-md-2"><button name="add_regu" class="btn btn-success w-100">Tambah</button></div>
                        <input type="hidden" name="password" value="123456">
                    </form>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light"><tr><th>Regu</th><th>Unit</th><th>JK</th><th>Akun</th><th class="no-print text-center">Aksi</th></tr></thead>
                            <tbody><?php while($r = $regus->fetch(PDO::FETCH_ASSOC)): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($r['nama_regu']) ?></td>
                                    <td><?= htmlspecialchars($r['nama_sekolah']) ?></td>
                                    <td><?= $r['jenis_kelamin'] ?></td>
                                    <td><?= $r['username'] ?></td>
                                    <td class="no-print text-center"><a href="?action=delete&type=regu&id=<?= $r['id'] ?>" class="btn btn-sm btn-light text-danger" onclick="return confirm('Hapus Regu?')"><i class="fas fa-trash"></i></a></td>
                                </tr>
                            <?php endwhile; ?></tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php break;

        // ================= PENGUMUMAN (B2 & STATS) =================
        case 'info':
            $infos = $db->query("SELECT * FROM pengumuman ORDER BY tanggal DESC");
        ?>
            <div class="row">
                <div class="col-md-12 mb-4 no-print">
                    <div class="card shadow-sm border-warning">
                        <div class="card-header bg-warning bg-opacity-10 fw-bold"><i class="fas fa-pen me-2"></i>Buat Pengumuman</div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <div class="row g-2 mb-2">
                                    <div class="col-md-8"><input type="text" name="judul" class="form-control" placeholder="Judul Pengumuman" required></div>
                                    <div class="col-md-2">
                                        <select name="tujuan" class="form-select">
                                            <option value="semua">Semua</option>
                                            <option value="unit">Unit Only</option>
                                            <option value="regu">Regu Only</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2"><input type="file" name="gambar" class="form-control"></div>
                                </div>
                                <div class="mb-3"><textarea id="summernote" name="isi" required></textarea></div>
                                <button name="add_info" class="btn btn-warning w-100 fw-bold"><i class="fas fa-paper-plane"></i> Terbitkan</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-12">
                    <h5 class="mb-3 border-bottom pb-2">Riwayat & Monitoring</h5>
                    <div class="row g-3">
                    <?php while($row = $infos->fetch(PDO::FETCH_ASSOC)): 
                        $pid = $row['id'];
                        $views = $db->query("SELECT COUNT(*) FROM pengumuman_views WHERE pengumuman_id=$pid")->fetchColumn();
                        $likes = $db->query("SELECT COUNT(*) FROM pengumuman_reactions WHERE pengumuman_id=$pid AND reaction_type='like'")->fetchColumn();
                        $loves = $db->query("SELECT COUNT(*) FROM pengumuman_reactions WHERE pengumuman_id=$pid AND reaction_type='love'")->fetchColumn();
                        // Gambar dari B2
                        $secureUrl = $row['gambar'] ? getSecureUrl($row['gambar']) : '';
                    ?>
                        <div class="col-md-6">
                            <div class="card h-100 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between mb-2">
                                        <h5 class="card-title fw-bold text-primary mb-0"><?= htmlspecialchars($row['judul']) ?></h5>
                                        <small class="text-muted"><?= date('d/m H:i', strtotime($row['tanggal'])) ?></small>
                                    </div>
                                    
                                    <?php if($secureUrl): ?>
                                        <img src="<?= $secureUrl ?>" class="img-fluid rounded mb-2" style="max-height: 150px; width: 100%; object-fit: cover;">
                                    <?php endif; ?>

                                    <p class="text-muted small"><?= substr(strip_tags($row['isi']), 0, 100) ?>...</p>
                                    
                                    <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded">
                                        <div class="small">
                                            <span class="me-2 text-primary"><i class="fas fa-eye"></i> <?= $views ?></span>
                                            <span class="me-2 text-success"><i class="fas fa-thumbs-up"></i> <?= $likes ?></span>
                                            <span class="text-danger"><i class="fas fa-heart"></i> <?= $loves ?></span>
                                        </div>
                                        <div>
                                            <button class="btn btn-sm btn-info text-white" data-bs-toggle="modal" data-bs-target="#statModal<?= $pid ?>"><i class="fas fa-chart-bar"></i> Cek</button>
                                            <a href="?action=delete&type=info&id=<?= $pid ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus postingan ini?')"><i class="fas fa-trash"></i></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="statModal<?= $pid ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Statistik</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <ul class="nav nav-tabs mb-3" id="myTab<?= $pid ?>">
                                            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#view<?= $pid ?>">Views (<?= $views ?>)</a></li>
                                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#react<?= $pid ?>">Reactions (<?= $likes+$loves ?>)</a></li>
                                        </ul>
                                        <div class="tab-content">
                                            <div class="tab-pane fade show active" id="view<?= $pid ?>">
                                                <ul class="list-group list-group-flush small">
                                                    <?php
                                                    $qView = $db->query("SELECT u.role, r.nama_regu, un.nama_sekolah FROM pengumuman_views v JOIN users u ON v.user_id=u.id LEFT JOIN regus r ON u.related_id=r.id LEFT JOIN units un ON (u.related_id=un.id AND u.role='unit') OR (r.unit_id=un.id) WHERE v.pengumuman_id=$pid ORDER BY v.viewed_at DESC");
                                                    while($v = $qView->fetch(PDO::FETCH_ASSOC)) {
                                                        $nm = ($v['role']=='regu') ? "Regu ".$v['nama_regu'] : "Unit ".$v['nama_sekolah'];
                                                        echo "<li class='list-group-item py-1'>$nm <span class='text-muted ms-1'>(".($v['nama_sekolah']??'-').")</span></li>";
                                                    }
                                                    if($views==0) echo "<li class='list-group-item text-muted'>Belum ada view</li>";
                                                    ?>
                                                </ul>
                                            </div>
                                            <div class="tab-pane fade" id="react<?= $pid ?>">
                                                <ul class="list-group list-group-flush small">
                                                    <?php
                                                    $qReact = $db->query("SELECT rx.reaction_type, u.role, r.nama_regu, un.nama_sekolah FROM pengumuman_reactions rx JOIN users u ON rx.user_id=u.id LEFT JOIN regus r ON u.related_id=r.id LEFT JOIN units un ON (u.related_id=un.id AND u.role='unit') OR (r.unit_id=un.id) WHERE rx.pengumuman_id=$pid ORDER BY rx.created_at DESC");
                                                    while($rx = $qReact->fetch(PDO::FETCH_ASSOC)) {
                                                        $icon = $rx['reaction_type']=='like' ? '👍' : '❤️';
                                                        $nm = ($rx['role']=='regu') ? "Regu ".$rx['nama_regu'] : "Unit ".$rx['nama_sekolah'];
                                                        echo "<li class='list-group-item py-1'>$icon $nm <span class='text-muted ms-1'>(".($rx['nama_sekolah']??'-').")</span></li>";
                                                    }
                                                    if(($likes+$loves)==0) echo "<li class='list-group-item text-muted'>Belum ada reaksi</li>";
                                                    ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                    </div>
                </div>
            </div>
        <?php break;

        // ================= MEMBERS / SISWA =================
        case 'members':
            $filter_unit = $_GET['filter_unit'] ?? '';
            $edit_id = $_GET['edit_id'] ?? '';
            $edit_data = null;
            if($edit_id) $edit_data = $db->query("SELECT * FROM anggota_regu WHERE id=$edit_id")->fetch(PDO::FETCH_ASSOC);
            $qSiswa = "SELECT a.*, r.nama_regu, u.nama_sekolah FROM anggota_regu a JOIN regus r ON a.regu_id=r.id JOIN units u ON r.unit_id=u.id";
            if($filter_unit) $qSiswa .= " WHERE u.id=$filter_unit";
            $qSiswa .= " ORDER BY u.nama_sekolah, r.nama_regu, a.nama_anggota";
            $members = $db->query($qSiswa);
        ?>
            <div class="row mb-3 no-print">
                <div class="col-md-8">
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="page" value="members">
                        <select name="filter_unit" class="form-select w-50" onchange="this.form.submit()">
                            <option value="">-- Semua Unit --</option>
                            <?php 
                            $us = $db->query("SELECT * FROM units ORDER BY nama_sekolah");
                            while($u=$us->fetch(PDO::FETCH_ASSOC)) echo "<option value='{$u['id']}' ".($filter_unit==$u['id']?'selected':'').">{$u['nama_sekolah']}</option>";
                            ?>
                        </select>
                        <?php if($filter_unit): ?>
                            <a href="?page=members&filter_unit=<?= $filter_unit ?>&print=true" target="_blank" class="btn btn-secondary"><i class="fas fa-print"></i> Print Absensi</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php if(!$print_mode): ?>
            <div class="card mb-3 no-print shadow-sm">
                <div class="card-header bg-dark text-white"><?= $edit_data ? 'Edit Siswa' : 'Tambah Siswa' ?></div>
                <div class="card-body">
                    <form method="POST" class="row g-2">
                        <input type="hidden" name="member_id" value="<?= $edit_data['id']??'' ?>">
                        <div class="col-md-4">
                            <select name="regu_id" class="form-select" required>
                                <option value="">Pilih Regu...</option>
                                <?php 
                                $rs = $db->query("SELECT r.id, r.nama_regu, u.nama_sekolah FROM regus r JOIN units u ON r.unit_id=u.id ORDER BY u.nama_sekolah, r.nama_regu");
                                while($r=$rs->fetch(PDO::FETCH_ASSOC)) {
                                    $sel = ($edit_data && $edit_data['regu_id']==$r['id']) ? 'selected' : '';
                                    echo "<option value='{$r['id']}' $sel>{$r['nama_sekolah']} - {$r['nama_regu']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-4"><input type="text" name="nama_anggota" class="form-control" value="<?= $edit_data['nama_anggota']??'' ?>" placeholder="Nama Siswa" required></div>
                        <div class="col-md-2"><select name="jabatan" class="form-select"><option>Anggota</option><option>Ketua Regu</option></select></div>
                        <div class="col-md-2"><button name="save_member" class="btn btn-primary w-100">Simpan</button></div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            <div class="card shadow-sm">
                <div class="card-body">
                    <h4 class="text-center">DATA ABSENSI SISWA</h4>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm text-center align-middle">
                            <thead class="table-light"><tr><th>No</th><th>Nama</th><th>Jabatan</th><th>Regu (Sekolah)</th><th>H</th><th>I</th><th>S</th><th>A</th><?php if(!$print_mode) echo "<th class='no-print'>Aksi</th>"; ?></tr></thead>
                            <tbody>
                                <?php $no=1; while($m=$members->fetch(PDO::FETCH_ASSOC)): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td class="text-start ps-3"><?= htmlspecialchars($m['nama_anggota']) ?></td>
                                    <td><?= $m['jabatan'] ?></td>
                                    <td><?= $m['nama_regu'] ?> <span class="text-muted">(<?= $m['nama_sekolah'] ?>)</span></td>
                                    <td></td><td></td><td></td><td></td>
                                    <?php if(!$print_mode): ?>
                                    <td class="no-print">
                                        <a href="?page=members&edit_id=<?= $m['id'] ?>" class="text-primary"><i class="fas fa-edit"></i></a>
                                        <a href="?action=delete&type=member&id=<?= $m['id'] ?>" class="text-danger ms-2" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i></a>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php if($print_mode) echo "<script>window.print()</script>"; break;

        // ================= SETTINGS =================
        case 'settings': ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">Pengaturan Akun & Kop</div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <div class="mb-3"><label>Nama Instansi</label><input type="text" name="nama_instansi" class="form-control" value="<?= $settings['nama_instansi'] ?>"></div>
                                <div class="mb-3"><label>Alamat</label><textarea name="alamat_instansi" class="form-control"><?= $settings['alamat_instansi'] ?></textarea></div>
                                <div class="row mb-3">
                                    <div class="col-6"><label>Logo Kiri</label><input type="file" name="logo_kiri" class="form-control form-control-sm"></div>
                                    <div class="col-6"><label>Logo Kanan</label><input type="file" name="logo_kanan" class="form-control form-control-sm"></div>
                                </div>
                                <hr>
                                <div class="mb-2"><label>Username Induk</label><input type="text" name="username" value="<?= $_SESSION['username'] ?>" class="form-control"></div>
                                <div class="mb-3"><label>Password Baru (Opsional)</label><input type="password" name="password" class="form-control"></div>
                                <button name="update_settings" class="btn btn-primary w-100">Simpan Perubahan</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">Preview Kop Surat (Saat Print)</div>
                        <div class="card-body text-center border bg-light">
                            <div class="d-flex justify-content-between align-items-center border-bottom border-dark pb-2 mb-2">
                                <img src="../assets/uploads/<?= $settings['logo_kiri'] ?>" style="height:60px" alt="Logo">
                                <div>
                                    <h5 class="fw-bold text-uppercase m-0"><?= $settings['nama_instansi'] ?></h5>
                                    <small><?= $settings['alamat_instansi'] ?></small>
                                </div>
                                <img src="../assets/uploads/<?= $settings['logo_kanan'] ?>" style="height:60px" alt="Logo">
                            </div>
                            <p class="text-muted mt-5">Area Konten Laporan...</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php break;

        // ================= KAS =================
        case 'kas':
            $t_awal = $_GET['tgl_awal'] ?? date('Y-m-01');
            $t_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
            $kas = $db->query("SELECT k.*, u.username, r.nama_regu, un.nama_sekolah FROM uang_kas k JOIN users u ON k.input_by_user_id=u.id LEFT JOIN regus r ON u.related_id=r.id LEFT JOIN units un ON r.unit_id=un.id WHERE k.tanggal BETWEEN '$t_awal' AND '$t_akhir' ORDER BY k.tanggal DESC");
        ?>
            <div class="card shadow-sm">
                <div class="card-header no-print">
                    <form method="GET" class="row g-2 align-items-center">
                        <input type="hidden" name="page" value="kas">
                        <div class="col-auto"><input type="date" name="tgl_awal" value="<?= $t_awal ?>" class="form-control"></div>
                        <div class="col-auto"><input type="date" name="tgl_akhir" value="<?= $t_akhir ?>" class="form-control"></div>
                        <div class="col-auto"><button class="btn btn-primary">Filter</button></div>
                        <div class="col-auto"><a href="?page=kas&tgl_awal=<?= $t_awal ?>&tgl_akhir=<?= $t_akhir ?>&print=true" target="_blank" class="btn btn-secondary"><i class="fas fa-print"></i> Print</a></div>
                    </form>
                </div>
                <div class="card-body">
                    <h4 class="text-center">LAPORAN KAS PRAMUKA</h4>
                    <p class="text-center mb-4">Periode: <?= $t_awal ?> s/d <?= $t_akhir ?></p>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-dark"><tr><th>Tgl</th><th>Oleh</th><th>Keterangan</th><th>Masuk</th><th>Keluar</th></tr></thead>
                            <tbody>
                                <?php $tm=0; $tk=0; while($k=$kas->fetch(PDO::FETCH_ASSOC)): 
                                    $m = $k['tipe']=='masuk'?$k['jumlah']:0; $k_out=$k['tipe']=='keluar'?$k['jumlah']:0;
                                    $tm+=$m; $tk+=$k_out;
                                    $siapa = $k['nama_regu'] ? "Regu ".$k['nama_regu'] : "Induk";
                                ?>
                                <tr>
                                    <td><?= date('d/m/y', strtotime($k['tanggal'])) ?></td>
                                    <td><?= $siapa ?></td>
                                    <td><?= htmlspecialchars($k['keterangan']) ?></td>
                                    <td class="text-success text-end"><?= $m>0?number_format($m):'-' ?></td>
                                    <td class="text-danger text-end"><?= $k_out>0?number_format($k_out):'-' ?></td>
                                </tr>
                                <?php endwhile; ?>
                                <tr class="fw-bold table-secondary">
                                    <td colspan="3" class="text-end">TOTAL</td>
                                    <td class="text-end"><?= number_format($tm) ?></td>
                                    <td class="text-end"><?= number_format($tk) ?></td>
                                </tr>
                                <tr class="fw-bold table-dark"><td colspan="3" class="text-end">SALDO AKHIR</td><td colspan="2" class="text-center">Rp <?= number_format($tm-$tk) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php if($print_mode) echo "<script>window.print()</script>"; break;
    } ?>
    </div>

    <?php if(!$print_mode): ?>
    <div class="bottom-nav d-md-none">
        <a href="?page=home" class="<?= $page=='home'?'active':'' ?>"><i class="fas fa-tachometer-alt"></i><span>Home</span></a>
        <a href="?page=units" class="<?= $page=='units'?'active':'' ?>"><i class="fas fa-school"></i><span>Unit</span></a>
        <a href="?page=regus" class="<?= $page=='regus'?'active':'' ?>"><i class="fas fa-users"></i><span>Regu</span></a>
        <a href="?page=info" class="<?= $page=='info'?'active':'' ?>"><i class="fas fa-bullhorn"></i><span>Info</span></a>
        <a href="?page=settings" class="<?= $page=='settings'?'active':'' ?>"><i class="fas fa-cogs"></i><span>Set</span></a>
    </div>
    <?php endif; ?>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>

<script>
    $(document).ready(function() {
        $('#summernote').summernote({
            placeholder: 'Tulis isi pengumuman...',
            tabsize: 2,
            height: 200,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'underline', 'clear']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['insert', ['link']],
                ['view', ['fullscreen', 'codeview', 'help']]
            ]
        });
    });
</script>

</body>
</html>