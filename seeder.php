<?php

require_once __DIR__ . '/env_loader.php';

// Load .env
loadEnv(__DIR__ . '/.env');

// Ambil kredensial
$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

// Koneksi PDO
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    exit("Koneksi gagal: " . $e->getMessage());
}

// List user yang mau di-seed
$users = [
    ['username' => 'superadmin', 'password' => '12345678'],
    ['username' => 'unit',       'password' => '12345678'],
    ['username' => 'regu',       'password' => '12345678'],
];

$sql = "INSERT INTO users (username, password, created_at, updated_at)
        VALUES (:username, :password, NOW(), NOW())
        ON DUPLICATE KEY UPDATE password = VALUES(password), updated_at = NOW()";

$stmt = $pdo->prepare($sql);

foreach ($users as $u) {
    $stmt->execute([
        ':username' => $u['username'],
        ':password' => password_hash($u['password'], PASSWORD_BCRYPT)
    ]);
}

echo "Seeder selesai: superadmin, unit, dan regu berhasil dibuat/diperbarui.\n";
