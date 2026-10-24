<?php
declare(strict_types=1);

/**
 * Provisionamento seguro e explícito da primeira conta administrativa.
 * Exemplo: ADMIN_USERNAME=admin ADMIN_EMAIL=admin@example.com ADMIN_PASSWORD='senha forte' php scripts/create_admin.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}
$username = trim((string)getenv('ADMIN_USERNAME'));
$email = trim((string)getenv('ADMIN_EMAIL'));
$password = (string)getenv('ADMIN_PASSWORD');
if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen($password) < 16) {
    fwrite(STDERR, "Defina ADMIN_USERNAME (3-50 caracteres), ADMIN_EMAIL válido e ADMIN_PASSWORD com pelo menos 16 caracteres.\n");
    exit(1);
}
require_once __DIR__ . '/../includes/config.php';
$stmt = $pdo->prepare('SELECT id FROM usuarios WHERE username = ? OR email = ? LIMIT 1');
$stmt->execute([$username, $email]);
if ($stmt->fetch()) {
    fwrite(STDERR, "Já existe usuário com esse nome ou e-mail. Nenhuma alteração realizada.\n");
    exit(1);
}
$stmt = $pdo->prepare("INSERT INTO usuarios (username, email, password, nome, nivel, ativo) VALUES (?, ?, ?, ?, 'admin', 1)");
$stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), 'Administrador']);
echo "Conta administrativa criada com sucesso.\n";
