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

// Auth Check
if (!isset($_SESSION['is_login']) || $_SESSION['role'] !== 'regu') {
    header("Location: ../auth/login.php"); exit();
}

$db = (new Database())->getConnection();
$regu_id = $_SESSION['related_id'];
$user_id = $_SESSION['user_id'];
$tahun_ini = date('Y');
$page = $_GET['page'] ?? 'home';
$highlight_id = $_GET['highlight'] ?? 0;

// --- 2. LOGIKA BASE URL ---
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$host = $_SERVER['HTTP_HOST'];
$path = str_replace('/regu/dashboard.php', '', $_SERVER['PHP_SELF']); 
$base_url = "$protocol://$host$path"; 

// --- 3. HELPER & DATA ---
$my_regu = $db->query("SELECT r.*, u.nama_sekolah, u.id as unit_id FROM regus r JOIN units u ON r.unit_id=u.id WHERE r.id=$regu_id")->fetch(PDO::FETCH_ASSOC);
$unit_id = $my_regu['unit_id'];

// Deteksi Folder Upload
$nama_sekolah = strtoupper($my_regu['nama_sekolah'] ?? '');
$folder_kategori = 'LAINNYA';
if (strpos($nama_sekolah, 'SMK') !== false) $folder_kategori = 'SMK';
elseif (strpos($nama_sekolah, 'SMA') !== false) $folder_kategori = 'SMA';
elseif (strpos($nama_sekolah, 'SMP') !== false) $folder_kategori = 'SMP';
elseif (strpos($nama_sekolah, 'SD') !== false) $folder_kategori = 'SD';

$settings = [];
$stmt = $db->query("SELECT * FROM system_settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $settings[$row['setting_key']] = $row['setting_value'];

function bulanIndo($bln){
    $bulan = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Ags", "Sep", "Okt", "Nov", "Des"];
    return $bulan[$bln-1];
}

function uploadBuktiKeB2($file, $kategori) {
    global $s3;
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if(empty($ext)) $ext = 'jpg'; // Default ext jika dari blob
    $filename = time() . '_' . uniqid() . '.' . $ext;
    $keyName = "PROJECT-APPS-PRAMUKA/BUKTI-BAYAR/" . $kategori . "/" . $filename;
    try {
        $s3->putObject(['Bucket' => $_ENV['B2_BUCKET_NAME'], 'Key' => $keyName, 'SourceFile' => $file['tmp_name']]);
        return $keyName;
    } catch (Exception $e) { return null; }
}

// [FIX] FUNGSI HYBRID: Bisa load gambar Lokal maupun Cloud
function getImageUrl($filename) {
    global $s3;
    if (empty($filename)) return null;

    // Cek apakah ini file Cloud (Ada nama folder project kita)
    if (strpos($filename, 'PROJECT-APPS-PRAMUKA') !== false) {
        try {
            $cmd = $s3->getCommand('GetObject', ['Bucket' => $_ENV['B2_BUCKET_NAME'], 'Key' => $filename]);
            $request = $s3->createPresignedRequest($cmd, '+24 hours');
            return (string)$request->getUri();
        } catch (Exception $e) { return null; }
    } else {
        // Jika tidak, berarti file LAMA (Local Upload)
        return "../assets/uploads/" . $filename;
    }
}

function setFlash($type, $msg) { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }

// --- 4. HANDLERS ---

if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    if ($_GET['type'] == 'member') {
        $db->prepare("DELETE FROM anggota_regu WHERE id=? AND regu_id=?")->execute([$_GET['id'], $regu_id]);
        setFlash('warning', 'Anggota dihapus.');
        header("Location: ?page=members"); exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Reaction
        if (isset($_POST['toggle_reaction'])) {
            $p_id = $_POST['pengumuman_id'];
            $type = $_POST['reaction_type'];
            $check = $db->prepare("SELECT id, reaction_type FROM pengumuman_reactions WHERE pengumuman_id=? AND user_id=?");
            $check->execute([$p_id, $user_id]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['reaction_type'] == $type) $db->prepare("DELETE FROM pengumuman_reactions WHERE id=?")->execute([$existing['id']]);
                else $db->prepare("UPDATE pengumuman_reactions SET reaction_type=? WHERE id=?")->execute([$type, $existing['id']]);
            } else {
                $db->prepare("INSERT INTO pengumuman_reactions (pengumuman_id, user_id, reaction_type) VALUES (?, ?, ?)")->execute([$p_id, $user_id, $type]);
            }
            header("Location: ?page=home&highlight=$p_id#post-$p_id"); exit();
        }
        // Save Member
        elseif (isset($_POST['save_member'])) {
            if (!empty($_POST['member_id'])) {
                $db->prepare("UPDATE anggota_regu SET nama_anggota=?, angkatan=?, kelas=?, jabatan=? WHERE id=? AND regu_id=?")->execute([$_POST['nama_anggota'], $_POST['angkatan'], $_POST['kelas'], $_POST['jabatan'], $_POST['member_id'], $regu_id]);
            } else {
                $db->prepare("INSERT INTO anggota_regu (regu_id, nama_anggota, angkatan, kelas, jabatan) VALUES (?, ?, ?, ?, ?)")->execute([$regu_id, $_POST['nama_anggota'], $_POST['angkatan'], $_POST['kelas'], $_POST['jabatan']]);
            }
            setFlash('success', 'Data anggota tersimpan!'); header("Location: ?page=members"); exit();
        }
        // Lapor Bayar (AJAX Support)
        elseif (isset($_POST['lapor_bayar'])) {
            $db->beginTransaction();
            $setting = $db->query("SELECT * FROM setting_iuran WHERE unit_id=$unit_id")->fetch(PDO::FETCH_ASSOC);
            $nominal = $setting['nominal'] ?? 0; $periode = $setting['periode'] ?? 'bulanan';
            $anggota_id = $_POST['anggota_id']; $bayar = $_POST['nominal_bayar'];
            
            $img_path = uploadBuktiKeB2($_FILES['bukti'], $folder_kategori);
            
            $jml_periode = ($nominal > 0) ? floor($bayar / $nominal) : 1;

            for ($i = 1; $i <= $jml_periode; $i++) {
                $last = $db->query("SELECT MAX(bulan) as last_month, MAX(minggu) as last_week FROM transaksi_iuran WHERE anggota_id=$anggota_id AND tahun=$tahun_ini")->fetch(PDO::FETCH_ASSOC);
                $next_bln = 0; $next_mgg = 0;
                if ($periode == 'bulanan') {
                    $next_bln = ($last['last_month'] ?? 0) + 1; if ($next_bln > 12) break;
                } else {
                    $last_m = $last['last_month'] ?? date('n'); $last_w = $last['last_week'] ?? 0;
                    $next_mgg = $last_w + 1; $next_bln = $last_m;
                    if ($next_mgg > 5) { $next_mgg = 1; $next_bln++; } if ($next_bln > 12) break;
                }
                $db->prepare("INSERT INTO transaksi_iuran (unit_id, anggota_id, bulan, minggu, tahun, tanggal_bayar, status, input_by_role, bukti_foto) VALUES (?, ?, ?, ?, ?, ?, 'menunggu', 'regu', ?)")->execute([$unit_id, $anggota_id, $next_bln, $next_mgg, $tahun_ini, date('Y-m-d H:i:s'), $img_path]);
            }
            $db->commit();

            // AJAX RESPONSE
            if(isset($_POST['is_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Laporan berhasil dikirim!']);
                exit(); 
            }

            setFlash('success', "Laporan terkirim! Rp ".number_format($bayar)." disetor."); 
            header("Location: ?page=history"); exit();
        }
    } catch (Exception $e) { 
        if($db->inTransaction()) $db->rollBack(); 
        if(isset($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit();
        }
        setFlash('danger', "Error: " . $e->getMessage()); header("Location: " . $_SERVER['REQUEST_URI']); exit(); 
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Regu <?= htmlspecialchars($my_regu['nama_regu']) ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body { background: #f4f7f6; font-family: 'Segoe UI', sans-serif; padding-bottom: 100px; }
        
        /* Sidebar & UI */
        .sidebar { min-height: 100vh; width: 260px; background: #fff; position: fixed; top: 0; left: 0; z-index: 1000; border-right: 1px solid #eee; }
        .sidebar .brand { padding: 30px 25px; font-weight: 800; color: #2c3e50; font-size: 1.3rem; }
        .sidebar a { color: #7f8c8d; text-decoration: none; display: block; padding: 15px 25px; font-weight: 500; transition: 0.2s; border-left: 4px solid transparent; }
        .sidebar a:hover, .sidebar a.active { background: #f8f9fa; color: #2980b9; border-left-color: #2980b9; }
        .main-content { margin-left: 260px; padding: 30px; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.03); background: #fff; overflow: hidden; transition: 0.2s; }
        .card-header { background: transparent; border-bottom: 1px solid #f0f0f0; font-weight: 700; padding: 15px 20px; }
        
        /* Rich Text Image Fix */
        .rich-text-content img { max-width: 100%; height: auto; border-radius: 8px; margin: 10px 0; }
        .highlight-post { border: 2px solid #3498db; }
        
        /* Mobile & FAB */
        .mobile-header, .bottom-nav { display: none; }
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 15px; }
            .mobile-header { display: flex; justify-content: space-between; align-items: center; background: #2980b9; color: white; padding: 15px 20px; border-radius: 0 0 25px 25px; margin-bottom: 20px; box-shadow: 0 10px 20px rgba(41, 128, 185, 0.2); }
            .bottom-nav { display: flex; position: fixed; bottom: 0; left: 0; width: 100%; background: white; justify-content: space-between; padding: 0 15px; height: 70px; z-index: 1050; box-shadow: 0 -5px 20px rgba(0,0,0,0.05); border-radius: 20px 20px 0 0; align-items: center; }
            .nav-item { text-align: center; color: #95a5a6; text-decoration: none; font-size: 10px; flex: 1; display: flex; flex-direction: column; align-items: center; }
            .nav-item i { font-size: 20px; margin-bottom: 4px; transition: 0.2s; }
            .nav-item.active { color: #2980b9; font-weight: 700; }
            .nav-item.active i { transform: translateY(-2px); }
            .nav-fab-container { position: relative; top: -25px; flex: 1; display: flex; justify-content: center; }
            .nav-fab { width: 65px; height: 65px; background: linear-gradient(135deg, #3498db, #2980b9); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white !important; font-size: 24px; box-shadow: 0 8px 20px rgba(41, 128, 185, 0.4); border: 6px solid #f4f7f6; transition: 0.2s; }
            .nav-fab:active { transform: scale(0.95); }
        }
        .kop-surat { display: none; }
        @media print { .sidebar, .bottom-nav, .mobile-header, .no-print { display: none !important; } .main-content { margin: 0 !important; } .kop-surat { display: flex !important; border-bottom: 3px double black; } .kop-logo { height: 80px; } }

        /* ANIMASI SUKSES */
        .success-animation { margin: 20px auto; }
        .checkmark { width: 80px; height: 80px; border-radius: 50%; display: block; stroke-width: 2; stroke: #4bb71b; stroke-miterlimit: 10; box-shadow: inset 0px 0px 0px #4bb71b; animation: fill .4s ease-in-out .4s forwards, scale .3s ease-in-out .9s both; position: relative; margin: 0 auto; }
        .checkmark__circle { stroke-dasharray: 166; stroke-dashoffset: 166; stroke-width: 2; stroke-miterlimit: 10; stroke: #4bb71b; fill: #fff; animation: stroke 0.6s cubic-bezier(0.65, 0, 0.45, 1) forwards; }
        .checkmark__check { transform-origin: 50% 50%; stroke-dasharray: 48; stroke-dashoffset: 48; animation: stroke 0.3s cubic-bezier(0.65, 0, 0.45, 1) 0.8s forwards; }
        @keyframes stroke { 100% { stroke-dashoffset: 0; } }
        @keyframes scale { 0%, 100% { transform: none; } 50% { transform: scale3d(1.1, 1.1, 1); } }
        @keyframes fill { 100% { box-shadow: inset 0px 0px 0px 30px #4bb71b; } }
    </style>
</head>
<body>

<div class="sidebar d-none d-md-block">
    <div class="brand"><i class="fas fa-campground me-2"></i> REGU PANEL</div>
    <div class="mt-3">
        <a href="?page=home" class="<?= $page=='home'?'active':'' ?>"><i class="fas fa-rss me-3"></i> Feed</a>
        <a href="?page=members" class="<?= $page=='members'?'active':'' ?>"><i class="fas fa-users me-3"></i> Anggota</a>
        <a href="?page=lapor" class="<?= $page=='lapor'?'active':'' ?>"><i class="fas fa-camera me-3"></i> Lapor Iuran</a>
        <a href="?page=history" class="<?= $page=='history'?'active':'' ?>"><i class="fas fa-history me-3"></i> Riwayat</a>
    </div>
    <div class="px-4 mt-5">
        <a href="../auth/logout.php" class="btn btn-outline-danger w-100 rounded-pill"><i class="fas fa-sign-out-alt me-2"></i> Keluar</a>
    </div>
</div>

<div class="mobile-header">
    <div><h5 class="m-0 fw-bold"><?= htmlspecialchars($my_regu['nama_regu']) ?></h5><small class="opacity-75"><?= htmlspecialchars($my_regu['nama_sekolah']) ?></small></div>
</div>

<div class="main-content">
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show shadow-sm border-0 mb-4">
            <?= $_SESSION['flash']['msg'] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <?php switch ($page) {
        // 1. HOME / FEED
        case 'home': 
             $infos = $db->query("SELECT * FROM pengumuman WHERE tujuan_role IN ('semua','regu') OR (tujuan_role='unit' AND user_id=(SELECT id FROM users WHERE related_id=$unit_id AND role='unit')) ORDER BY tanggal DESC"); ?>
             <h5 class="mb-4 fw-bold text-secondary no-print ps-1">Papan Pengumuman</h5>
             <div class="row"><?php while($info=$infos->fetch(PDO::FETCH_ASSOC)): 
                $p_id = $info['id']; 
                $db->prepare("INSERT IGNORE INTO pengumuman_views (pengumuman_id, user_id, viewed_at) VALUES (?, ?, NOW())")->execute([$p_id, $user_id]); 
                
                // [FIXED] Gunakan fungsi Hybrid (bisa lokal/cloud)
                $secureFeedImage = $info['gambar'] ? getImageUrl($info['gambar']) : ''; 
                
                // Hitung React
                $countLike = $db->query("SELECT COUNT(*) FROM pengumuman_reactions WHERE pengumuman_id=$p_id AND reaction_type='like'")->fetchColumn();
                $countLove = $db->query("SELECT COUNT(*) FROM pengumuman_reactions WHERE pengumuman_id=$p_id AND reaction_type='love'")->fetchColumn();
                $myReaction = $db->query("SELECT reaction_type FROM pengumuman_reactions WHERE pengumuman_id=$p_id AND user_id=$user_id")->fetchColumn();
                
                $shareLink = $base_url . "/post.php?id=" . $p_id;
                $shareText = "*INFO PRAMUKA*\n" . $info['judul'] . "\n\nSelengkapnya: " . $shareLink;
            ?>
                <div class="col-md-8 mx-auto"><div class="card mb-4 p-3 shadow-sm" id="post-<?= $p_id ?>">
                    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                        <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($info['judul']) ?></h6>
                        <small class="text-muted" style="font-size: 11px;"><?= date('d M H:i', strtotime($info['tanggal'])) ?></small>
                    </div>
                    
                    <div class="rich-text-content small mb-3">
                        <?= $info['isi'] ?>
                    </div>
                    
                    <?php if($secureFeedImage): ?>
                        <img src="<?= $secureFeedImage ?>" class="img-fluid rounded w-100 shadow-sm mb-3" style="max-height:350px; object-fit:cover">
                    <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                        <div>
                             <form method="POST" class="d-inline">
                                <input type="hidden" name="toggle_reaction" value="1">
                                <input type="hidden" name="pengumuman_id" value="<?= $p_id ?>">
                                <input type="hidden" name="reaction_type" value="like">
                                <button class="btn btn-sm btn-light rounded-pill text-primary border <?= $myReaction=='like'?'active':'' ?>">
                                    <i class="<?= $myReaction=='like'?'fas':'far' ?> fa-thumbs-up"></i> <?= $countLike?:'' ?>
                                </button>
                            </form>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="toggle_reaction" value="1">
                                <input type="hidden" name="pengumuman_id" value="<?= $p_id ?>">
                                <input type="hidden" name="reaction_type" value="love">
                                <button class="btn btn-sm btn-light rounded-pill text-danger border <?= $myReaction=='love'?'active':'' ?>">
                                    <i class="<?= $myReaction=='love'?'fas':'far' ?> fa-heart"></i> <?= $countLove?:'' ?>
                                </button>
                            </form>
                        </div>
                        <a href="https://wa.me/?text=<?= urlencode($shareText) ?>" target="_blank" class="btn btn-success btn-sm rounded-pill px-3"><i class="fab fa-whatsapp"></i> Share</a>
                    </div>
                </div></div>
             <?php endwhile; ?></div>
        <?php break;

        case 'members': /* ...Code Members... */ 
             $members = $db->query("SELECT * FROM anggota_regu WHERE regu_id=$regu_id ORDER BY nama_anggota"); ?>
             <div class="row">
                 <div class="col-md-4 mb-3"><div class="card p-3"><h6 class="fw-bold">Tambah Anggota</h6>
                 <form method="POST"><input type="hidden" name="member_id" value=""><input type="text" name="nama_anggota" class="form-control form-control-sm mb-2" placeholder="Nama" required><div class="row g-1"><div class="col-6"><input type="text" name="kelas" class="form-control form-control-sm" placeholder="Kelas"></div><div class="col-6"><input type="text" name="angkatan" class="form-control form-control-sm" placeholder="Thn"></div></div><select name="jabatan" class="form-select form-select-sm mt-2"><option>Anggota</option><option>Ketua Regu</option></select><button name="save_member" class="btn btn-primary btn-sm w-100 mt-2 rounded-pill">Simpan</button></form>
                 </div></div>
                 <div class="col-md-8"><div class="card"><div class="table-responsive"><table class="table table-sm mb-0"><thead class="table-light"><tr><th class="ps-3">Nama</th><th>Kls</th><th>Jbt</th><th>Act</th></tr></thead><tbody><?php while($m=$members->fetch(PDO::FETCH_ASSOC)): ?><tr><td class="ps-3"><?= $m['nama_anggota'] ?></td><td><?= $m['kelas'] ?></td><td><?= $m['jabatan'] ?></td><td><a href="?action=delete&type=member&id=<?= $m['id'] ?>" class="text-danger"><i class="fas fa-trash"></i></a></td></tr><?php endwhile; ?></tbody></table></div></div></div>
             </div>
        <?php break;

        case 'lapor':
            $anggota = $db->query("SELECT * FROM anggota_regu WHERE regu_id=$regu_id");
        ?>
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card shadow-sm border-0" id="cardLapor">
                        <div class="card-body p-4">
                            <div class="text-center mb-4">
                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex p-3 mb-2"><i class="fas fa-camera fa-2x"></i></div>
                                <h5 class="fw-bold text-dark">Lapor Iuran / Kas</h5>
                            </div>
                            <form id="formLapor" enctype="multipart/form-data">
                                <input type="hidden" name="lapor_bayar" value="1">
                                <input type="hidden" name="is_ajax" value="1">
                                <div class="mb-3"><label class="form-label small fw-bold text-muted">Nama Anggota</label><select name="anggota_id" class="form-select form-select-lg bg-light border-0" required><option value="">Pilih...</option><?php while($m=$anggota->fetch(PDO::FETCH_ASSOC)): ?><option value="<?= $m['id'] ?>"><?= $m['nama_anggota'] ?></option><?php endwhile; ?></select></div>
                                <div class="mb-3"><label class="form-label small fw-bold text-muted">Bukti Foto</label><input type="file" id="fileInput" name="bukti" class="form-control" accept="image/*" capture="environment" required><div class="form-text small text-primary" id="compressInfo">Foto akan otomatis dikompresi (lebih cepat).</div></div>
                                <div class="mb-4"><label class="form-label small fw-bold text-muted">Nominal (Rp)</label><input type="number" name="nominal_bayar" class="form-control form-control-lg bg-light border-0 fw-bold text-primary" placeholder="0" required></div>
                                <button type="submit" id="btnSubmit" class="btn btn-primary w-100 btn-lg rounded-pill fw-bold shadow-sm"><span id="btnText">KIRIM LAPORAN <i class="fas fa-paper-plane ms-2"></i></span><span id="btnLoad" class="spinner-border spinner-border-sm d-none"></span></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php break;

        case 'history': 
            $histori = $db->query("SELECT t.*, a.nama_anggota FROM transaksi_iuran t JOIN anggota_regu a ON t.anggota_id=a.id WHERE t.unit_id=$unit_id ORDER BY t.id DESC LIMIT 20");
            echo "<h5 class='mb-3 ps-1'>Riwayat</h5><div class='card border-0 shadow-sm'><div class='list-group list-group-flush'>";
            while($h=$histori->fetch(PDO::FETCH_ASSOC)){
                // [FIXED] Gunakan fungsi Hybrid untuk bukti
                $url = $h['bukti_foto'] ? getImageUrl($h['bukti_foto']) : '';
                
                // Escape URL untuk JS
                $jsUrl = htmlspecialchars($url, ENT_QUOTES);

                echo "<div class='list-group-item p-3'><div class='d-flex justify-content-between'><div><h6 class='fw-bold mb-0'>{$h['nama_anggota']}</h6><small class='text-muted'>".date('d/m H:i',strtotime($h['tanggal_bayar']))."</small></div>";
                echo "<div class='text-end'><span class='badge bg-".($h['status']=='lunas'?'success':'warning')." rounded-pill'>".strtoupper($h['status'])."</span>";
                if($url) echo "<br><button onclick=\"showBuktiModal('$jsUrl', '{$h['nama_anggota']}')\" class='btn btn-sm btn-outline-primary py-0 px-2 mt-1' style='font-size:10px'>Bukti</button>";
                echo "</div></div></div>";
            }
            echo "</div></div>";
        break;
    } ?>
</div>

<div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
      <div class="modal-body text-center p-5">
        <div class="success-animation">
            <svg class="checkmark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52"><circle class="checkmark__circle" cx="26" cy="26" r="25" fill="none" /><path class="checkmark__check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8" /></svg>
        </div>
        <h4 class="fw-bold mt-3">Berhasil!</h4>
        <p class="text-muted mb-0">Laporan dikirim ke Unit.</p>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalBukti" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-body text-center p-0"><img id="imgPreview" src="" class="img-fluid rounded"></div></div></div></div>

<nav class="bottom-nav d-md-none">
    <a href="?page=home" class="nav-item <?= $page=='home'?'active':'' ?>"><i class="fas fa-rss"></i> Feed</a>
    <a href="?page=members" class="nav-item <?= $page=='members'?'active':'' ?>"><i class="fas fa-users"></i> Anggota</a>
    <div class="nav-fab-container"><a href="?page=lapor" class="nav-fab"><i class="fas fa-camera"></i></a></div>
    <a href="?page=history" class="nav-item <?= $page=='history'?'active':'' ?>"><i class="fas fa-history"></i> Riwayat</a>
    <a href="../auth/logout.php" class="nav-item text-danger"><i class="fas fa-sign-out-alt"></i> Keluar</a>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showBuktiModal(url, nama) {
    document.getElementById('imgPreview').src = url;
    new bootstrap.Modal(document.getElementById('modalBukti')).show();
}

document.addEventListener("DOMContentLoaded", function() {
    const form = document.getElementById('formLapor');
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmit');
            const btnText = document.getElementById('btnText');
            const btnLoad = document.getElementById('btnLoad');
            const fileInput = document.getElementById('fileInput');

            btn.disabled = true;
            btnText.classList.add('d-none');
            btnLoad.classList.remove('d-none');

            const formData = new FormData(form);
            
            if (fileInput.files.length > 0) {
                const compressedFile = await compressImage(fileInput.files[0]);
                formData.set('bukti', compressedFile, compressedFile.name);
            }

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.status === 'success') {
                    new bootstrap.Modal(document.getElementById('successModal')).show();
                    setTimeout(() => { window.location.href = '?page=history'; }, 1800);
                } else {
                    alert('Gagal: ' + result.message);
                    resetBtn();
                }
            } catch (error) {
                console.error(error);
                alert('Gagal kirim. Cek koneksi.');
                resetBtn();
            }
        });
    }

    function resetBtn() {
        document.getElementById('btnSubmit').disabled = false;
        document.getElementById('btnText').classList.remove('d-none');
        document.getElementById('btnLoad').classList.add('d-none');
    }

    function compressImage(file) {
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = (event) => {
                const img = new Image();
                img.src = event.target.result;
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    const MAX_WIDTH = 800; 
                    const scaleSize = MAX_WIDTH / img.width;
                    canvas.width = (img.width > MAX_WIDTH) ? MAX_WIDTH : img.width;
                    canvas.height = (img.width > MAX_WIDTH) ? (img.height * scaleSize) : img.height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob((blob) => {
                        resolve(new File([blob], file.name, { type: 'image/jpeg', lastModified: Date.now() }));
                    }, 'image/jpeg', 0.6);
                }
            }
        });
    }
});
</script>
</body>
</html>
