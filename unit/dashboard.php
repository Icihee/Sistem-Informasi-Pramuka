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

// Cek Keamanan
if (!isset($_SESSION['is_login']) || $_SESSION['role'] !== 'unit') {
    header("Location: ../auth/login.php"); exit();
}

$db = (new Database())->getConnection();
$unit_id = $_SESSION['related_id'];
$user_id = $_SESSION['user_id'];
$tahun_ini = date('Y');
$page = $_GET['page'] ?? 'home';
$print_mode = isset($_GET['print']); 

// --- 2. HELPER FUNCTIONS ---

// Upload ke B2 (Digunakan untuk Pengumuman & Bukti)
function uploadKeB2($file, $subfolder = 'UMUM') {
    global $s3;
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = time() . '_' . uniqid() . '.' . $ext;
    // Path: PROJECT-APPS-PRAMUKA/NAMA_FOLDER/namafile.jpg
    $keyName = "PROJECT-APPS-PRAMUKA/" . $subfolder . "/" . $filename;
    try {
        $s3->putObject([
            'Bucket' => $_ENV['B2_BUCKET_NAME'], 
            'Key' => $keyName, 
            'SourceFile' => $file['tmp_name']
        ]);
        return $keyName;
    } catch (Exception $e) { return null; }
}

// Get URL Aman (Presigned URL)
function getSecureUrl($filename) {
    global $s3;
    try {
        if (empty($filename)) return null;
        $cmd = $s3->getCommand('GetObject', ['Bucket' => $_ENV['B2_BUCKET_NAME'], 'Key' => $filename]);
        $request = $s3->createPresignedRequest($cmd, '+24 hours');
        return (string)$request->getUri();
    } catch (Exception $e) { return null; }
}

function bulanIndo($bln){
    $bulan = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Ags", "Sep", "Okt", "Nov", "Des"];
    return $bulan[$bln-1];
}

function setFlash($type, $msg) { $_SESSION['flash'] = ['type'=>$type, 'msg'=>$msg]; }

// Ambil Data Unit & Settings
$my_unit = $db->query("SELECT * FROM units WHERE id=$unit_id")->fetch(PDO::FETCH_ASSOC);
$settings = [];
$stmt = $db->query("SELECT * FROM system_settings");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) $settings[$row['setting_key']] = $row['setting_value'];

// Tentukan Folder Penyimpanan Unit
$folder_unit = 'LAINNYA';
if (strpos(strtoupper($my_unit['nama_sekolah']), 'SMK') !== false) $folder_unit = 'SMK';
elseif (strpos(strtoupper($my_unit['nama_sekolah']), 'SMA') !== false) $folder_unit = 'SMA';
elseif (strpos(strtoupper($my_unit['nama_sekolah']), 'SMP') !== false) $folder_unit = 'SMP';
elseif (strpos(strtoupper($my_unit['nama_sekolah']), 'SD') !== false) $folder_unit = 'SD';

// --- 3. LOGIKA ACTION ---

// A. DELETE
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = $_GET['id']; $type = $_GET['type'];
    try {
        $db->beginTransaction();
        if($type=='regu'){
            $db->prepare("DELETE FROM users WHERE role='regu' AND related_id=?")->execute([$id]);
            $db->prepare("DELETE FROM regus WHERE id=? AND unit_id=?")->execute([$id, $unit_id]); $pg='regus';
        } elseif($type=='member'){ $db->prepare("DELETE FROM anggota_regu WHERE id=?")->execute([$id]); $pg='members';
        } elseif($type=='info'){ 
            $db->prepare("DELETE FROM pengumuman_views WHERE pengumuman_id=?")->execute([$id]);
            $db->prepare("DELETE FROM pengumuman_reactions WHERE pengumuman_id=?")->execute([$id]);
            $db->prepare("DELETE FROM pengumuman WHERE id=? AND user_id=$user_id")->execute([$id]); $pg='info';
        } elseif($type=='kas'){ $db->prepare("DELETE FROM uang_kas WHERE id=? AND milik_role='unit' AND milik_id=?")->execute([$id, $unit_id]); $pg='kas'; }
        $db->commit();
        setFlash('warning', 'Data berhasil dihapus.');
        header("Location: dashboard.php?page=$pg"); exit();
    } catch(Exception $e) { $db->rollBack(); setFlash('danger', 'Gagal hapus.'); }
}

// B. VERIFIKASI IURAN
if (isset($_GET['action']) && $_GET['action'] == 'verify' && isset($_GET['id'])) {
    try {
        $db->beginTransaction();
        $trx = $db->query("SELECT t.*, a.nama_anggota FROM transaksi_iuran t JOIN anggota_regu a ON t.anggota_id=a.id WHERE t.id=" . $_GET['id'])->fetch(PDO::FETCH_ASSOC);
        $set_iuran = $db->query("SELECT nominal, periode FROM setting_iuran WHERE unit_id=$unit_id")->fetch(PDO::FETCH_ASSOC);
        $nominal = $set_iuran['nominal'] ?? 0;
        
        $db->prepare("UPDATE transaksi_iuran SET status='lunas' WHERE id=? AND unit_id=?")->execute([$_GET['id'], $unit_id]);
        
        if ($nominal > 0) {
            $ket_waktu = ($set_iuran['periode'] == 'mingguan') ? "M-".$trx['minggu']." ".bulanIndo($trx['bulan']) : bulanIndo($trx['bulan']);
            $db->prepare("INSERT INTO uang_kas (milik_role, milik_id, tipe, jumlah, keterangan, tanggal, input_by_user_id) VALUES ('unit', ?, 'masuk', ?, ?, ?, ?)")->execute([$unit_id, $nominal, "Iuran: ".$trx['nama_anggota']." ($ket_waktu)", date('Y-m-d'), $user_id]);
        }
        $db->commit();
        setFlash('success', 'Pembayaran diverifikasi & masuk kas.');
        header("Location: dashboard.php?page=iuran"); exit();
    } catch (Exception $e) { $db->rollBack(); die($e->getMessage()); }
}

// C. POST HANDLER
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // 1. Save Kas
        if (isset($_POST['save_kas'])) {
            if(!empty($_POST['kas_id'])){
                $stmt = $db->prepare("UPDATE uang_kas SET tanggal=?, tipe=?, jumlah=?, keterangan=? WHERE id=? AND milik_id=?");
                $stmt->execute([$_POST['tanggal'], $_POST['tipe'], $_POST['jumlah'], $_POST['keterangan'], $_POST['kas_id'], $unit_id]);
                setFlash('success', 'Kas diperbarui');
            } else {
                $db->prepare("INSERT INTO uang_kas (milik_role, milik_id, tipe, jumlah, keterangan, tanggal, input_by_user_id) VALUES ('unit', ?, ?, ?, ?, ?, ?)")->execute([$unit_id, $_POST['tipe'], $_POST['jumlah'], $_POST['keterangan'], $_POST['tanggal'], $user_id]);
                setFlash('success', 'Kas dicatat');
            }
            header("Location: ?page=kas"); exit();
        } 
        // 2. Add Regu
        elseif (isset($_POST['add_regu'])) { 
            $db->beginTransaction();
            $db->prepare("INSERT INTO regus (unit_id, nama_regu, jenis_kelamin) VALUES (?, ?, ?)")->execute([$unit_id, $_POST['nama_regu'], $_POST['jk']]);
            $newId = $db->lastInsertId();
            $db->prepare("INSERT INTO users (username, password, role, related_id) VALUES (?, ?, 'regu', ?)")->execute([$_POST['username'], password_hash($_POST['password'], PASSWORD_DEFAULT), $newId]);
            $db->commit(); 
            setFlash('success', 'Regu ditambahkan');
            header("Location: ?page=regus"); exit();
        } 
        // 3. Save Member
        elseif (isset($_POST['save_member'])) {
            if(!empty($_POST['member_id'])) {
                $db->prepare("UPDATE anggota_regu SET nama_anggota=?, angkatan=?, kelas=?, jabatan=?, regu_id=? WHERE id=?")->execute([$_POST['nama_anggota'], $_POST['angkatan'], $_POST['kelas'], $_POST['jabatan'], $_POST['regu_id'], $_POST['member_id']]);
                setFlash('success', 'Data siswa diupdate');
            } else {
                $db->prepare("INSERT INTO anggota_regu (regu_id, nama_anggota, angkatan, kelas, jabatan) VALUES (?, ?, ?, ?, ?)")->execute([$_POST['regu_id'], $_POST['nama_anggota'], $_POST['angkatan'], $_POST['kelas'], $_POST['jabatan']]);
                setFlash('success', 'Siswa ditambahkan');
            }
            header("Location: ?page=members"); exit();
        } 
        // 4. Setting Iuran
        elseif (isset($_POST['save_iuran_setting'])) {
            $cek = $db->query("SELECT id FROM setting_iuran WHERE unit_id=$unit_id")->fetch();
            if($cek) $db->prepare("UPDATE setting_iuran SET nominal=?, status=?, periode=? WHERE unit_id=?")->execute([$_POST['nominal'], $_POST['status'], $_POST['periode'], $unit_id]);
            else $db->prepare("INSERT INTO setting_iuran (unit_id, nominal, status, periode) VALUES (?, ?, ?, ?)")->execute([$unit_id, $_POST['nominal'], $_POST['status'], $_POST['periode']]);
            setFlash('success', 'Setting iuran disimpan');
            header("Location: ?page=iuran"); exit();
        } 
        // 5. Bayar Manual
        elseif (isset($_POST['bayar_manual'])) {
            $db->beginTransaction();
            $minggu = $_POST['minggu'] ?? 0;
            $db->prepare("INSERT INTO transaksi_iuran (unit_id, anggota_id, bulan, minggu, tahun, tanggal_bayar, status, input_by_role) VALUES (?, ?, ?, ?, ?, ?, 'lunas', 'unit')")->execute([$unit_id, $_POST['anggota_id'], $_POST['bulan'], $minggu, $tahun_ini, date('Y-m-d')]);
            
            $nm = $db->query("SELECT nama_anggota FROM anggota_regu WHERE id=".$_POST['anggota_id'])->fetchColumn();
            $set_iuran = $db->query("SELECT nominal, periode FROM setting_iuran WHERE unit_id=$unit_id")->fetch(PDO::FETCH_ASSOC);
            $nominal = $set_iuran['nominal'] ?? 0;
            if ($nominal > 0) {
                $ket_waktu = ($set_iuran['periode'] == 'mingguan') ? "M-$minggu ".bulanIndo($_POST['bulan']) : bulanIndo($_POST['bulan']);
                $db->prepare("INSERT INTO uang_kas (milik_role, milik_id, tipe, jumlah, keterangan, tanggal, input_by_user_id) VALUES ('unit', ?, 'masuk', ?, ?, ?, ?)")->execute([$unit_id, $nominal, "Manual: $nm ($ket_waktu)", date('Y-m-d'), $user_id]);
            }
            $db->commit(); 
            setFlash('success', 'Pembayaran manual tercatat');
            header("Location: ?page=iuran"); exit();
        } 
        // 6. Add Info (UPLOAD BUCKET)
        elseif (isset($_POST['add_info'])) {
            $imgName = null;
            if (!empty($_FILES['gambar']['name'])) {
                // Upload gambar ke Bucket B2
                $imgName = uploadKeB2($_FILES['gambar'], 'PENGUMUMAN'); 
            }
            $db->prepare("INSERT INTO pengumuman (judul, isi, gambar, user_id, tujuan_role, tanggal) VALUES (?, ?, ?, ?, ?, NOW())")->execute([$_POST['judul'], $_POST['isi'], $imgName, $user_id, 'regu']);
            setFlash('success', 'Pengumuman diterbitkan');
            header("Location: ?page=info"); exit();
        }
    } catch (Exception $e) { 
        if($db->inTransaction()) $db->rollBack(); 
        setFlash('danger', 'Error: ' . $e->getMessage()); 
        header("Location: " . $_SERVER['REQUEST_URI']); exit();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Unit - <?= htmlspecialchars($my_unit['nama_sekolah']) ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">

    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; padding-bottom: 80px; }
        
        /* Sidebar & Nav */
        .sidebar { min-height: 100vh; width: 250px; background: #fff; border-right: 1px solid #e9ecef; position: fixed; top: 0; left: 0; z-index: 1000; }
        .sidebar .brand { padding: 20px; font-weight: bold; color: #0d6efd; border-bottom: 1px solid #f1f3f5; font-size: 1.2rem; }
        .sidebar a { color: #6c757d; text-decoration: none; display: block; padding: 12px 20px; font-weight: 500; transition: 0.2s; border-radius: 0 25px 25px 0; margin-right: 10px; }
        .sidebar a:hover, .sidebar a.active { background: #e7f1ff; color: #0d6efd; }
        .main-content { margin-left: 250px; padding: 25px; }

        /* Card Styling */
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 15px rgba(0,0,0,0.03); transition: transform 0.2s; }
        .card-header { background: white; border-bottom: 1px solid #f1f1f1; font-weight: bold; padding: 15px 20px; border-radius: 12px 12px 0 0 !important; }
        
        /* Mobile View */
        .mobile-header, .bottom-nav { display: none; }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 15px; }
            .mobile-header { display: flex; justify-content: space-between; align-items: center; background: #0d6efd; color: white; padding: 15px; border-radius: 0 0 20px 20px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(13,110,253,0.2); }
            .bottom-nav { display: flex; position: fixed; bottom: 0; left: 0; width: 100%; background: white; border-top: 1px solid #eee; justify-content: space-around; padding: 10px 0; z-index: 1050; box-shadow: 0 -5px 20px rgba(0,0,0,0.05); }
            .nav-item { text-align: center; color: #adb5bd; text-decoration: none; font-size: 10px; flex: 1; }
            .nav-item i { font-size: 20px; display: block; margin-bottom: 4px; }
            .nav-item.active { color: #0d6efd; font-weight: 600; }
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
    </style>
</head>
<body>

<div class="sidebar d-none d-md-block">
    <div class="brand"><i class="fas fa-school me-2"></i> PANEL UNIT</div>
    <div class="py-3">
        <a href="?page=home" class="<?= $page=='home'?'active':'' ?>"><i class="fas fa-th-large me-3"></i> Dashboard</a>
        <a href="?page=regus" class="<?= $page=='regus'?'active':'' ?>"><i class="fas fa-users me-3"></i> Data Regu</a>
        <a href="?page=members" class="<?= $page=='members'?'active':'' ?>"><i class="fas fa-user-graduate me-3"></i> Data Siswa</a>
        <a href="?page=iuran" class="<?= $page=='iuran'?'active':'' ?>"><i class="fas fa-hand-holding-usd me-3"></i> Iuran & SPP</a>
        <a href="?page=kas" class="<?= $page=='kas'?'active':'' ?>"><i class="fas fa-wallet me-3"></i> Laporan Kas</a>
        <a href="?page=info" class="<?= $page=='info'?'active':'' ?>"><i class="fas fa-bullhorn me-3"></i> Pengumuman</a>
    </div>
    <div class="px-4 mt-5">
        <a href="../auth/logout.php" class="btn btn-danger w-100 text-white rounded-pill"><i class="fas fa-sign-out-alt me-2"></i> Logout</a>
    </div>
</div>

<div class="mobile-header">
    <div>
        <h5 class="m-0 fw-bold"><?= htmlspecialchars($my_unit['nama_sekolah']) ?></h5>
        <small class="opacity-75">Administrator</small>
    </div>
    <a href="../auth/logout.php" class="text-white"><i class="fas fa-sign-out-alt fa-lg"></i></a>
</div>

<div class="main-content">
    <div class="kop-surat">
        <div><?php if($settings['logo_kiri']) echo "<img src='../assets/uploads/{$settings['logo_kiri']}' class='kop-logo'>"; ?></div>
        <div class="text-center">
            <h3 class="fw-bold text-uppercase m-0"><?= $settings['nama_instansi'] ?></h3>
            <p class="m-0"><?= $settings['alamat_instansi'] ?></p>
            <small>Unit: <?= $my_unit['nama_sekolah'] ?></small>
        </div>
        <div><?php if($settings['logo_kanan']) echo "<img src='../assets/uploads/{$settings['logo_kanan']}' class='kop-logo'>"; ?></div>
    </div>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show no-print" role="alert">
            <?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <?php switch ($page) { 
        // =================================
        // 1. DASHBOARD HOME
        // =================================
        case 'home': 
            $c_regu = $db->query("SELECT COUNT(*) FROM regus WHERE unit_id=$unit_id")->fetchColumn();
            $c_siswa = $db->query("SELECT COUNT(*) FROM anggota_regu a JOIN regus r ON a.regu_id=r.id WHERE r.unit_id=$unit_id")->fetchColumn();
            $saldo = $db->query("SELECT (SELECT COALESCE(SUM(jumlah),0) FROM uang_kas WHERE milik_role='unit' AND milik_id=$unit_id AND tipe='masuk') - (SELECT COALESCE(SUM(jumlah),0) FROM uang_kas WHERE milik_role='unit' AND milik_id=$unit_id AND tipe='keluar')")->fetchColumn();
        ?>
            <h4 class="mb-4 fw-bold text-secondary no-print">Overview Unit</h4>
            <div class="row g-3">
                <div class="col-md-4 col-6">
                    <div class="card bg-primary text-white p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><h2 class="m-0 fw-bold"><?= $c_regu ?></h2><small>Total Regu</small></div>
                            <i class="fas fa-users fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-6">
                    <div class="card bg-success text-white p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><h2 class="m-0 fw-bold"><?= $c_siswa ?></h2><small>Total Siswa</small></div>
                            <i class="fas fa-user-graduate fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <div class="card bg-warning text-dark p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><h2 class="m-0 fw-bold">Rp <?= number_format($saldo) ?></h2><small>Saldo Kas Unit</small></div>
                            <i class="fas fa-wallet fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        <?php break; 
        
        // =================================
        // 2. DATA REGU
        // =================================
        case 'regus': 
            $regus = $db->query("SELECT r.*, u.username FROM regus r LEFT JOIN users u ON u.related_id=r.id AND u.role='regu' WHERE r.unit_id=$unit_id ORDER BY r.nama_regu"); ?>
            <div class="card shadow-sm">
                <div class="card-header"><i class="fas fa-users me-2"></i> Data Regu</div>
                <div class="card-body">
                    <form method="POST" class="row g-2 mb-4 no-print p-3 bg-light rounded">
                        <div class="col-md-4"><input type="text" name="nama_regu" class="form-control" placeholder="Nama Regu" required></div>
                        <div class="col-md-2"><select name="jk" class="form-select"><option value="L">Putra</option><option value="P">Putri</option></select></div>
                        <div class="col-md-3"><input type="text" name="username" class="form-control" placeholder="Username Login" required></div>
                        <div class="col-md-2"><input type="password" name="password" class="form-control" placeholder="Password" required></div>
                        <div class="col-md-1"><button name="add_regu" class="btn btn-primary w-100"><i class="fas fa-plus"></i></button></div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light"><tr><th>Nama Regu</th><th>JK</th><th>Akun Login</th><th class="no-print text-center">Aksi</th></tr></thead>
                            <tbody><?php while($r=$regus->fetch(PDO::FETCH_ASSOC)): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($r['nama_regu']) ?></td>
                                    <td><span class="badge bg-<?= $r['jenis_kelamin']=='L'?'info':'pink' ?> text-dark"><?= $r['jenis_kelamin']=='L'?'Putra':'Putri' ?></span></td>
                                    <td><code><?= $r['username'] ?></code></td>
                                    <td class="no-print text-center"><a href="?action=delete&type=regu&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus regu ini?')"><i class="fas fa-trash"></i></a></td>
                                </tr>
                            <?php endwhile; ?></tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php break; 
        
        // =================================
        // 3. DATA SISWA (MEMBERS)
        // =================================
        case 'members': 
            $edit_id = $_GET['edit_id'] ?? '';
            $edit_data = $edit_id ? $db->query("SELECT * FROM anggota_regu WHERE id=$edit_id")->fetch(PDO::FETCH_ASSOC) : null;
            $my_regus = $db->query("SELECT * FROM regus WHERE unit_id=$unit_id");
            $members = $db->query("SELECT a.*, r.nama_regu FROM anggota_regu a JOIN regus r ON a.regu_id=r.id WHERE r.unit_id=$unit_id ORDER BY r.nama_regu, a.nama_anggota"); ?>
            
            <div class="row">
                <div class="col-md-12 mb-4 no-print">
                    <div class="card shadow-sm border-primary">
                        <div class="card-header bg-primary bg-opacity-10 text-primary fw-bold"><?= $edit_data ? 'Edit Siswa' : 'Tambah Siswa Baru' ?></div>
                        <div class="card-body">
                            <form method="POST" class="row g-2">
                                <input type="hidden" name="member_id" value="<?= $edit_data['id']??'' ?>">
                                <div class="col-md-3">
                                    <select name="regu_id" class="form-select" required>
                                        <option value="">Pilih Regu...</option>
                                        <?php while($r=$my_regus->fetch()): ?>
                                            <option value="<?= $r['id'] ?>" <?= ($edit_data && $edit_data['regu_id']==$r['id'])?'selected':'' ?>><?= $r['nama_regu'] ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-3"><input type="text" name="nama_anggota" class="form-control" value="<?= $edit_data['nama_anggota']??'' ?>" placeholder="Nama Lengkap" required></div>
                                <div class="col-md-2"><select name="jabatan" class="form-select"><option>Anggota</option><option>Ketua Regu</option><option>Wakil Ketua</option></select></div>
                                <div class="col-md-1"><input type="text" name="kelas" class="form-control" value="<?= $edit_data['kelas']??'' ?>" placeholder="Kls"></div>
                                <div class="col-md-1"><input type="text" name="angkatan" class="form-control" value="<?= $edit_data['angkatan']??'' ?>" placeholder="Thn"></div>
                                <div class="col-md-2"><button name="save_member" class="btn btn-primary w-100">Simpan</button></div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-3 no-print">
                                <h5 class="card-title">Data Siswa & Absensi</h5>
                                <a href="?page=members&print=true" target="_blank" class="btn btn-success btn-sm"><i class="fas fa-print me-2"></i>Cetak Data</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered text-center align-middle" style="font-size: 13px;">
                                    <thead class="table-light"><tr><th width="5%">No</th><th class="text-start">Nama</th><th>Kelas</th><th>Regu</th><th>H</th><th>I</th><th>S</th><th>A</th><th class="no-print">Aksi</th></tr></thead>
                                    <tbody><?php $no=1; while($m=$members->fetch(PDO::FETCH_ASSOC)): ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td class="text-start fw-bold"><?= htmlspecialchars($m['nama_anggota']) ?></td>
                                            <td><?= $m['kelas'] ?></td>
                                            <td><?= $m['nama_regu'] ?></td>
                                            <td></td><td></td><td></td><td></td>
                                            <td class="no-print">
                                                <a href="?page=members&edit_id=<?= $m['id'] ?>" class="text-primary me-2"><i class="fas fa-edit"></i></a> 
                                                <a href="?action=delete&type=member&id=<?= $m['id'] ?>" class="text-danger" onclick="return confirm('Hapus siswa ini?')"><i class="fas fa-trash"></i></a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php break; 
        
        // =================================
        // 4. IURAN & TUNGGAKAN
        // =================================
        case 'iuran': 
            $set_iuran = $db->query("SELECT * FROM setting_iuran WHERE unit_id=$unit_id")->fetch(PDO::FETCH_ASSOC);
            $periode = $set_iuran['periode'] ?? 'bulanan';
            $sel_bln = $_GET['bln'] ?? date('n');
            $cols = $periode == 'bulanan' ? range(1,12) : range(1,5);
            
            $members = $db->query("SELECT a.id, a.nama_anggota, r.nama_regu FROM anggota_regu a JOIN regus r ON a.regu_id=r.id WHERE r.unit_id=$unit_id ORDER BY r.nama_regu, a.nama_anggota")->fetchAll(PDO::FETCH_ASSOC);
            
            // Data Lunas
            $paid = [];
            $sql_paid = "SELECT anggota_id, bulan, minggu FROM transaksi_iuran WHERE unit_id=$unit_id AND tahun=$tahun_ini AND status='lunas'";
            if($periode=='mingguan') $sql_paid .= " AND bulan=$sel_bln";
            $q_paid = $db->query($sql_paid);
            while($r=$q_paid->fetch(PDO::FETCH_ASSOC)) $paid[$r['anggota_id']][$periode=='bulanan'?$r['bulan']:$r['minggu']] = true;

            // Data Menunggu Verifikasi
            $pending = $db->query("SELECT t.*, a.nama_anggota, r.nama_regu FROM transaksi_iuran t JOIN anggota_regu a ON t.anggota_id=a.id JOIN regus r ON a.regu_id=r.id WHERE t.unit_id=$unit_id AND t.status='menunggu' ORDER BY t.tanggal_bayar DESC");
        ?>
            <div class="row g-3">
                <div class="col-md-4 no-print">
                    <div class="card mb-3 shadow-sm border-danger">
                        <div class="card-header bg-danger text-white py-2"><i class="fas fa-bell me-2"></i> Verifikasi Pembayaran</div>
                        <div class="card-body p-0">
                            <?php if($pending->rowCount()>0): ?>
                            <div class="list-group list-group-flush">
                                <?php while($p=$pending->fetch(PDO::FETCH_ASSOC)): 
                                    $ket_p = ($periode=='mingguan') ? "M".$p['minggu'] : bulanIndo($p['bulan']); 
                                    // Ambil URL Gambar (Presigned)
                                    $secureUrl = ($p['bukti_foto']) ? getSecureUrl($p['bukti_foto']) : '';
                                ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <span class="fw-bold d-block"><?= $p['nama_anggota'] ?></span>
                                                <small class="text-muted"><?= $ket_p ?> - <?= $p['nama_regu'] ?></small>
                                                <div class="mt-1">Rp <?= number_format($set_iuran['nominal']??0) ?></div>
                                            </div>
                                            <a href="?action=verify&id=<?= $p['id'] ?>" class="btn btn-success btn-sm"><i class="fas fa-check"></i></a>
                                        </div>
                                        <?php if($secureUrl): ?>
                                            <button class="btn btn-outline-secondary btn-sm w-100 mt-2" style="font-size:12px" onclick="showBukti('<?= $secureUrl ?>')"><i class="fas fa-image me-1"></i> Lihat Bukti</button>
                                        <?php endif; ?>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                            <?php else: ?><div class="p-3 text-center text-muted small">Tidak ada pembayaran pending</div><?php endif; ?>
                        </div>
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-header py-2"><i class="fas fa-cog me-2"></i> Pengaturan Iuran</div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-2"><label>Periode</label><select name="periode" class="form-select form-select-sm"><option value="bulanan" <?= $periode=='bulanan'?'selected':'' ?>>Bulanan</option><option value="mingguan" <?= $periode=='mingguan'?'selected':'' ?>>Mingguan</option></select></div>
                                <div class="mb-2"><label>Nominal (Rp)</label><input type="number" name="nominal" class="form-control form-control-sm" value="<?= $set_iuran['nominal']??0 ?>"></div>
                                <div class="mb-3"><label>Status</label><select name="status" class="form-select form-select-sm"><option value="aktif">Aktif</option><option value="nonaktif">Nonaktif</option></select></div>
                                <button name="save_iuran_setting" class="btn btn-primary btn-sm w-100">Simpan Setting</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="m-0">Status Pembayaran</h5>
                                <?php if($periode=='mingguan'): ?>
                                    <form method="GET" class="no-print"><input type="hidden" name="page" value="iuran"><select name="bln" class="form-select form-select-sm" onchange="this.form.submit()"><?php for($i=1;$i<=12;$i++): ?><option value="<?= $i ?>" <?= $i==$sel_bln?'selected':'' ?>><?= bulanIndo($i) ?></option><?php endfor; ?></select></form>
                                <?php endif; ?>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm text-center align-middle" style="font-size:11px; min-width: 600px;">
                                    <thead class="table-dark"><tr><th class="text-start">Nama (Regu)</th><?php foreach($cols as $c) echo "<th>".($periode=='bulanan'?substr(bulanIndo($c),0,3):"M$c")."</th>"; ?></tr></thead>
                                    <tbody><?php foreach($members as $m): ?>
                                        <tr>
                                            <td class="text-start text-nowrap fw-bold"><?= $m['nama_anggota'] ?> <span class="fw-normal text-muted">(<?= $m['nama_regu'] ?>)</span></td>
                                            <?php foreach($cols as $c): ?>
                                                <td>
                                                    <?php if(isset($paid[$m['id']][$c])): ?>
                                                        <i class="fas fa-check-circle text-success fa-lg"></i>
                                                    <?php else: ?>
                                                        <?php if(!$print_mode): ?>
                                                            <form method="POST" onsubmit="return confirm('Tandai Lunas Manual?')">
                                                                <input type="hidden" name="bayar_manual" value="1">
                                                                <input type="hidden" name="anggota_id" value="<?= $m['id'] ?>">
                                                                <input type="hidden" name="bulan" value="<?= $periode=='mingguan'?$sel_bln:$c ?>">
                                                                <input type="hidden" name="minggu" value="<?= $periode=='mingguan'?$c:0 ?>">
                                                                <button class="btn btn-light btn-sm py-0 px-1 text-muted" style="font-size:10px"><i class="fas fa-plus"></i></button>
                                                            </form>
                                                        <?php else: ?><span class="text-danger">-</span><?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php break; 
        
        // =================================
        // 5. KAS UNIT
        // =================================
        case 'kas': 
            $tgl_awal = $_GET['tgl_awal'] ?? date('Y-m-01');
            $tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
            $edit_kas_id = $_GET['edit_kas_id'] ?? '';
            $kas_edit = $edit_kas_id ? $db->query("SELECT * FROM uang_kas WHERE id=$edit_kas_id")->fetch(PDO::FETCH_ASSOC) : null;
            $histori = $db->query("SELECT * FROM uang_kas WHERE milik_role='unit' AND milik_id=$unit_id AND tanggal BETWEEN '$tgl_awal' AND '$tgl_akhir' ORDER BY tanggal DESC"); 
        ?>
            <div class="row">
                <div class="col-md-4 col-12 no-print mb-3">
                    <div class="card shadow-sm border-warning">
                        <div class="card-header bg-warning bg-opacity-10 fw-bold"><?= $kas_edit ? 'Edit Transaksi' : 'Catat Transaksi' ?></div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="kas_id" value="<?= $kas_edit['id']??'' ?>">
                                <div class="mb-2"><label>Tanggal</label><input type="date" name="tanggal" class="form-control" value="<?= $kas_edit['tanggal'] ?? date('Y-m-d') ?>" required></div>
                                <div class="mb-2"><label>Jenis</label><select name="tipe" class="form-select"><option value="masuk" <?= ($kas_edit['tipe']??'')=='masuk'?'selected':'' ?>>Pemasukan (+)</option><option value="keluar" <?= ($kas_edit['tipe']??'')=='keluar'?'selected':'' ?>>Pengeluaran (-)</option></select></div>
                                <div class="mb-2"><label>Nominal</label><input type="number" name="jumlah" class="form-control" value="<?= $kas_edit['jumlah']??'' ?>" placeholder="Rp" required></div>
                                <div class="mb-3"><label>Keterangan</label><textarea name="keterangan" class="form-control" rows="2" required><?= $kas_edit['keterangan']??'' ?></textarea></div>
                                <button name="save_kas" class="btn btn-warning w-100 fw-bold">Simpan</button>
                                <?php if($kas_edit): ?><a href="?page=kas" class="btn btn-light w-100 mt-2">Batal</a><?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-8 col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <form method="GET" class="row g-2 mb-3 no-print align-items-center bg-light p-2 rounded">
                                <input type="hidden" name="page" value="kas">
                                <div class="col-auto">Filter:</div>
                                <div class="col-auto"><input type="date" name="tgl_awal" value="<?= $tgl_awal ?>" class="form-control form-control-sm"></div>
                                <div class="col-auto">s/d</div>
                                <div class="col-auto"><input type="date" name="tgl_akhir" value="<?= $tgl_akhir ?>" class="form-control form-control-sm"></div>
                                <div class="col-auto"><button class="btn btn-secondary btn-sm"><i class="fas fa-filter"></i></button></div>
                                <div class="col-auto ms-auto"><a href="javascript:window.print()" class="btn btn-dark btn-sm"><i class="fas fa-print me-1"></i> Print</a></div>
                            </form>

                            <h5 class="text-center fw-bold mb-3">BUKU KAS UNIT</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover align-middle" style="font-size:13px;">
                                    <thead class="table-light text-center"><tr><th>Tgl</th><th>Uraian</th><th>Masuk</th><th>Keluar</th><th class="no-print">#</th></tr></thead>
                                    <tbody>
                                        <?php $tot_m=0; $tot_k=0; while($k=$histori->fetch(PDO::FETCH_ASSOC)): 
                                            $m = $k['tipe']=='masuk' ? $k['jumlah'] : 0; 
                                            $kl = $k['tipe']=='keluar' ? $k['jumlah'] : 0; 
                                            $tot_m += $m; $tot_k += $kl; ?>
                                            <tr>
                                                <td class="text-center"><?= date('d/m/y', strtotime($k['tanggal'])) ?></td>
                                                <td><?= htmlspecialchars($k['keterangan']) ?></td>
                                                <td class="text-end text-success"><?= $m>0 ? number_format($m) : '-' ?></td>
                                                <td class="text-end text-danger"><?= $kl>0 ? number_format($kl) : '-' ?></td>
                                                <td class="no-print text-center">
                                                    <a href="?page=kas&edit_kas_id=<?= $k['id'] ?>&tgl_awal=<?=$tgl_awal?>&tgl_akhir=<?=$tgl_akhir?>" class="text-primary"><i class="fas fa-edit"></i></a> 
                                                    <a href="?action=delete&type=kas&id=<?= $k['id'] ?>" class="text-danger ms-2" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i></a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                    <tfoot class="fw-bold">
                                        <tr class="bg-light"><td colspan="2" class="text-end">Total</td><td class="text-end"><?= number_format($tot_m) ?></td><td class="text-end"><?= number_format($tot_k) ?></td><td></td></tr>
                                        <tr class="table-info"><td colspan="2" class="text-end">Saldo Akhir</td><td colspan="2" class="text-center">Rp <?= number_format($tot_m - $tot_k) ?></td><td></td></tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php break; 
        
        // =================================
        // 6. PENGUMUMAN (RICH TEXT & STATS)
        // =================================
        case 'info': ?>
            <div class="row">
                <div class="col-12 mb-4 no-print">
                    <div class="card shadow-sm border-info">
                        <div class="card-header bg-info bg-opacity-10 fw-bold"><i class="fas fa-edit me-2"></i> Buat Pengumuman</div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <div class="row g-2 mb-2">
                                    <div class="col-md-8"><input type="text" name="judul" class="form-control" placeholder="Judul Pengumuman" required></div>
                                    <div class="col-md-4"><input type="file" name="gambar" class="form-control"></div>
                                </div>
                                <div class="mb-3"><textarea id="summernote" name="isi" required></textarea></div>
                                <button name="add_info" class="btn btn-info text-white fw-bold w-100">Terbitkan</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <h5 class="mb-3 border-bottom pb-2">Riwayat Pengumuman</h5>
                    <div class="row g-3">
                    <?php 
                    $infos = $db->query("SELECT * FROM pengumuman WHERE user_id=$user_id ORDER BY tanggal DESC"); 
                    while($inf=$infos->fetch(PDO::FETCH_ASSOC)): 
                        $pid = $inf['id'];
                        // Hitung Views & Reacts
                        $views = $db->query("SELECT COUNT(*) FROM pengumuman_views WHERE pengumuman_id=$pid")->fetchColumn();
                        $likes = $db->query("SELECT COUNT(*) FROM pengumuman_reactions WHERE pengumuman_id=$pid AND reaction_type='like'")->fetchColumn();
                        $loves = $db->query("SELECT COUNT(*) FROM pengumuman_reactions WHERE pengumuman_id=$pid AND reaction_type='love'")->fetchColumn();
                        $secureImage = $inf['gambar'] ? getSecureUrl($inf['gambar']) : '';
                    ?>
                        <div class="col-md-6">
                            <div class="card h-100 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between mb-2">
                                        <h5 class="fw-bold text-primary m-0"><?= htmlspecialchars($inf['judul']) ?></h5>
                                        <small class="text-muted"><?= date('d M H:i', strtotime($inf['tanggal'])) ?></small>
                                    </div>
                                    <?php if($secureImage): ?>
                                        <div class="mb-3">
                                            <img src="<?= $secureImage ?>" class="img-fluid rounded" style="height: 150px; w-100; object-fit: cover;">
                                        </div>
                                    <?php endif; ?>
                                    
                                    <p class="text-muted small mb-3"><?= substr(strip_tags($inf['isi']), 0, 100) ?>...</p>
                                    
                                    <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded">
                                        <div class="small">
                                            <span class="me-2 text-primary" title="Dilihat"><i class="fas fa-eye"></i> <?= $views ?></span>
                                            <span class="me-2 text-success" title="Like"><i class="fas fa-thumbs-up"></i> <?= $likes ?></span>
                                            <span class="text-danger" title="Love"><i class="fas fa-heart"></i> <?= $loves ?></span>
                                        </div>
                                        <div>
                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#statModal<?= $pid ?>"><i class="fas fa-chart-bar"></i></button>
                                            <a href="?action=delete&type=info&id=<?= $pid ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="statModal<?= $pid ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h6 class="modal-title">Statistik: <?= htmlspecialchars($inf['judul']) ?></h6>
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
                                                    $qv = $db->query("SELECT u.username, r.nama_regu FROM pengumuman_views v JOIN users u ON v.user_id=u.id LEFT JOIN regus r ON u.related_id=r.id WHERE v.pengumuman_id=$pid ORDER BY v.viewed_at DESC");
                                                    while($v=$qv->fetch(PDO::FETCH_ASSOC)) echo "<li class='list-group-item'>Regu: {$v['nama_regu']}</li>";
                                                    if($views==0) echo "<li class='list-group-item text-muted'>Belum ada data</li>";
                                                    ?>
                                                </ul>
                                            </div>
                                            <div class="tab-pane fade" id="react<?= $pid ?>">
                                                <ul class="list-group list-group-flush small">
                                                    <?php 
                                                    $qr = $db->query("SELECT rx.reaction_type, r.nama_regu FROM pengumuman_reactions rx JOIN users u ON rx.user_id=u.id LEFT JOIN regus r ON u.related_id=r.id WHERE rx.pengumuman_id=$pid");
                                                    while($r=$qr->fetch(PDO::FETCH_ASSOC)) {
                                                        $ic = $r['reaction_type']=='like' ? '👍' : '❤️';
                                                        echo "<li class='list-group-item'>$ic Regu: {$r['nama_regu']}</li>";
                                                    }
                                                    if(($likes+$loves)==0) echo "<li class='list-group-item text-muted'>Belum ada data</li>";
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
        <?php break; } ?>
</div>

<nav class="bottom-nav d-md-none">
    <a href="?page=home" class="nav-item <?= $page=='home'?'active':'' ?>"><i class="fas fa-th-large"></i></a>
    <a href="?page=regus" class="nav-item <?= $page=='regus'?'active':'' ?>"><i class="fas fa-users"></i></a>
    <a href="?page=members" class="nav-item <?= $page=='members'?'active':'' ?>"><i class="fas fa-user-graduate"></i></a>
    <a href="?page=iuran" class="nav-item <?= $page=='iuran'?'active':'' ?>"><i class="fas fa-hand-holding-usd"></i></a>
    <a href="?page=kas" class="nav-item <?= $page=='kas'?'active':'' ?>"><i class="fas fa-wallet"></i></a>
    <a href="?page=info" class="nav-item <?= $page=='info'?'active':'' ?>"><i class="fas fa-bullhorn"></i></a>
</nav>

<div class="modal fade" id="modalBukti" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2 border-0">
        <h6 class="modal-title fw-bold">Bukti Transfer</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center p-0 bg-light">
        <div id="spinner" class="spinner-border text-primary my-5" role="status"><span class="visually-hidden">Loading...</span></div>
        <img id="imgBukti" src="" class="img-fluid d-none" onload="this.classList.remove('d-none'); document.getElementById('spinner').classList.add('d-none');">
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>

<script>
    $(document).ready(function() {
        $('#summernote').summernote({
            placeholder: 'Tulis isi pengumuman disini...',
            tabsize: 2,
            height: 200,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'underline', 'clear']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['insert', ['link']],
                ['view', ['fullscreen', 'codeview']]
            ]
        });
    });

    function showBukti(src) {
        document.getElementById('imgBukti').classList.add('d-none');
        document.getElementById('spinner').classList.remove('d-none');
        document.getElementById('imgBukti').src = src;
        var myModal = new bootstrap.Modal(document.getElementById('modalBukti'));
        myModal.show();
    }
</script>

<?php if($print_mode): ?><script>window.onload=function(){window.print();}</script><?php endif; ?>
</body>
</html>
