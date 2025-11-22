<?php

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        // 1. Load Environment Variables manual
        $this->loadEnv();

        // 2. Set variabel dari env
        $this->host = getenv('DB_HOST');
        $this->db_name = getenv('DB_NAME');
        $this->username = getenv('DB_USER');
        $this->password = getenv('DB_PASS');
    }

    // Fungsi sederhana untuk membaca file .env (Parsing)
    private function loadEnv() {
        // Path ke file .env (naik satu folder dari config)
        $path = __DIR__ . '/../.env';

        if (!file_exists($path)) {
            die("File .env tidak ditemukan. Silakan buat file .env di root folder.");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Lewati komentar yang diawali #
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Pisahkan Key dan Value
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            // Hapus tanda kutip jika ada (misal "localhost")
            $value = str_replace('"', '', $value);

            // Simpan ke environment variable sementara
            if (!getenv($name)) {
                putenv(sprintf('%s=%s', $name, $value));
            }
        }
    }

    // Fungsi untuk koneksi
    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name;
            $this->conn = new PDO($dsn, $this->username, $this->password);
            
            // Set error mode ke Exception agar mudah debugging
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }

        return $this->conn;
    }
}

 $database = new Database();
 $db = $database->getConnection();
 
?>