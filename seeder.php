<?php

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$host = $_ENV['DB_HOST'];
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    exit("Koneksi gagal: " . $e->getMessage());
}

$users = [
    ['username' => 'superadmin', 'password' => '12345678'],
    ['username' => 'unit',       'password' => '12345678'],
    ['username' => 'regu',       'password' => '12345678'],
];

$sql = "INSERT INTO users (username, password)
        VALUES (:username, :password)
        ON DUPLICATE KEY UPDATE password = VALUES(password)";

$stmt = $pdo->prepare($sql);

foreach ($users as $u) {
    $stmt->execute([
        ':username' => $u['username'],
        ':password' => password_hash($u['password'], PASSWORD_BCRYPT)
    ]);
}

echo "Seeder selesai.\n";
