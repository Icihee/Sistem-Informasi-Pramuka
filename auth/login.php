<?php
session_start();
require_once '../config/database.php';

// --- 1. TANGKAP "FLOW" DARI URL (FITUR BARU) ---
// Contoh: login.php?flow=/regu/dashboard.php?page=members
if (isset($_GET['flow']) && !empty($_GET['flow'])) {
    $target = $_GET['flow'];
    
    // SECURITY CHECK Sederhana:
    // Pastikan link-nya aman (tidak melempar ke website orang lain/phishing)
    // Kita hanya izinkan jika link mengandung domain kita sendiri ATAU link relatif (diawali /)
    $my_domain = $_SERVER['HTTP_HOST']; // contoh: pramuka.fastrs.id
    
    if (strpos($target, $my_domain) !== false || strpos($target, '/') === 0) {
        $_SESSION['redirect_url'] = $target;
    }
}

// --- 2. CEK SESSION LOGIN ---
if (isset($_SESSION['is_login']) && $_SESSION['is_login'] === true) {
    // Prioritas 1: Redirect ke link titipan (flow)
    if (isset($_SESSION['redirect_url'])) {
        $url = $_SESSION['redirect_url'];
        unset($_SESSION['redirect_url']); // Hapus agar tidak looping
        header("Location: " . $url);
    } else {
        // Prioritas 2: Redirect default dashboard
        header("Location: ../" . $_SESSION['role'] . "/dashboard.php");
    }
    exit();
}

$error = ''; 

// --- 3. PROSES LOGIN (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $database = new Database();
    $db = $database->getConnection();

    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = "Username dan Password wajib diisi!";
    } else {
        try {
            $query = "SELECT * FROM users WHERE username = :username LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":username", $username);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $password_valid = false;

                if (password_verify($password, $row['password'])) {
                    $password_valid = true;
                } elseif ($password == $row['password']) {
                    // Fallback admin awal
                    $password_valid = true;
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $update = $db->prepare("UPDATE users SET password = :pass WHERE id = :id");
                    $update->execute([':pass' => $newHash, ':id' => $row['id']]);
                }

                if ($password_valid) {
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['username'] = $row['username'];
                    $_SESSION['role'] = $row['role'];
                    $_SESSION['related_id'] = $row['related_id'];
                    $_SESSION['is_login'] = true;

                    // --- REDIRECT SETELAH LOGIN SUKSES ---
                    // Cek apakah ada titipan link dari ?flow=... tadi?
                    if (isset($_SESSION['redirect_url'])) {
                        $tujuan = $_SESSION['redirect_url'];
                        unset($_SESSION['redirect_url']); // Hapus session link
                        header("Location: " . $tujuan);
                    } else {
                        // Redirect normal
                        switch ($row['role']) {
                            case 'induk': header("Location: ../induk/dashboard.php"); break;
                            case 'unit':  header("Location: ../unit/dashboard.php"); break;
                            case 'regu':  header("Location: ../regu/dashboard.php"); break;
                            default:      $error = "Role akun tidak dikenali!"; break;
                        }
                    }
                    exit();
                    
                } else {
                    $error = "Password salah!";
                }
            } else {
                $error = "Username tidak ditemukan!";
            }
        } catch (PDOException $e) {
            $error = "Terjadi kesalahan sistem: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistem Pramuka</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .card-login { width: 100%; max-width: 400px; padding: 20px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .btn-pramuka { background-color: #5d4037; color: #fff; }
        .btn-pramuka:hover { background-color: #4e342e; color: #fff; }
    </style>
</head>
<body>
    <div class="card card-login bg-white">
        <div class="text-center mb-4">
            <h4>Sistem Informasi Pramuka</h4>
            <p class="text-muted">Silakan login untuk melanjutkan</p>
            
            <?php if(isset($_GET['flow'])): ?>
                <div class="alert alert-info py-1 small">
                    Anda akan diarahkan ke halaman tujuan setelah login.
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger text-center" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST">
            <div class="mb-3"><label>Username</label><input type="text" class="form-control" name="username" required autofocus></div>
            <div class="mb-3"><label>Password</label><input type="password" class="form-control" name="password" required></div>
            <div class="d-grid gap-2"><button type="submit" class="btn btn-pramuka">Masuk</button></div>
        </form>
    </div>
</body>
</html>