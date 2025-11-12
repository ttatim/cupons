<?php
/**
 * Configuração central do app
 * Compatível com PHP 8.1
 */

// ---- Sessão ----
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ---- Constantes de ambiente / DB ----
// Em produção, prefira popular via .env e carregar abaixo.
// Valores padrão (dev):
define('ENVIRONMENT', getenv('APP_ENV') ?: 'development');
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'pelando_clone');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// ---- Conexão PDO compartilhada ($pdo) ----
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    // Em dev, exibe; em prod, registra somente
    if (ENVIRONMENT === 'development') {
        die("Erro na conexão: " . $e->getMessage());
    } else {
        error_log("[DB] " . $e->getMessage());
        http_response_code(500);
        die("Erro ao conectar ao banco de dados.");
    }
}

// ---- Carrega classe Database (opcional: autocriar tabelas) ----
require_once __DIR__ . '/database.php';
if (class_exists('Database')) {
    try {
        // Instancia para garantir migrações/criação de tabelas se a classe assim definir
        $db = new Database();
    } catch (Throwable $t) {
        // Não bloquear a aplicação; apenas logar o erro
        error_log("[Database bootstrap] " . $t->getMessage());
    }
}

// ---- Funções utilitárias do projeto podem depender de $pdo ----
// require_once __DIR__ . '/functions.php';

// ---- Usuário padrão opcional (apenas em dev) ----
if (!function_exists('criarUsuarioPadrao')) {
    function criarUsuarioPadrao(PDO $pdo) {
        $username = 'ttatim';
        $password = password_hash('senha123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
        $stmt->execute([$username]);
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO usuarios (username, password, email) VALUES (?, ?, ?)");
            $stmt->execute([$username, $password, 'admin@ttatim.com']);
        }
    }
}

if (ENVIRONMENT === 'development') {
    try { criarUsuarioPadrao($pdo); } catch (Throwable $t) { error_log("[seed] ".$t->getMessage()); }
}
