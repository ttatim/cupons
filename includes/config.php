<?php

require_once 'database.php';
session_start();

define('DB_HOST', 'localhost');
define('DB_NAME', 'pelando_clone');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

// Criar usuário padrão
function criarUsuarioPadrao($pdo) {
    $username = 'ttatim';
    $password = password_hash('senha123', PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
    $stmt->execute([$username]);
    
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO usuarios (username, password, email) VALUES (?, ?, ?)");
        $stmt->execute([$username, $password, 'admin@ttatim.com']);
    }
}