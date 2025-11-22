<?php
session_start();

// Jika sudah login, lempar ke dashboard sesuai role
if (isset($_SESSION['is_login']) && $_SESSION['is_login'] === true) {
    $role = $_SESSION['role'];
    header("Location: $role/dashboard.php");
    exit();
} else {
    // Jika belum login, lempar ke halaman login
    header("Location: auth/login.php");
    exit();
}
?>