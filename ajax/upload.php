<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    http_response_code(401);
    exit('<div class="alert alert-danger">Autenticação necessária.</div>');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('<div class="alert alert-danger">Método inválido.</div>');
}
$csrf = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
if (!Security::validateCSRFToken($csrf)) {
    http_response_code(403);
    exit('<div class="alert alert-danger">Token CSRF inválido.</div>');
}

$tipo = (string)($_POST['tipo'] ?? '');
if (!in_array($tipo, ['produto', 'loja'], true)) {
    http_response_code(422);
    exit('<div class="alert alert-danger">Tipo de upload inválido.</div>');
}
$arquivo = $_FILES['arquivo'] ?? null;
if (!is_array($arquivo) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(422);
    exit('<div class="alert alert-danger">Nenhuma imagem válida foi enviada.</div>');
}
if (!isset($arquivo['size'], $arquivo['tmp_name']) || !is_string($arquivo['tmp_name'])
    || (int)$arquivo['size'] <= 0 || (int)$arquivo['size'] > 5 * 1024 * 1024
    || !is_uploaded_file($arquivo['tmp_name'])) {
    http_response_code(422);
    exit('<div class="alert alert-danger">Arquivo inválido ou maior que 5 MB.</div>');
}

$tiposPermitidos = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']);
if (!is_string($mime) || !isset($tiposPermitidos[$mime]) || getimagesize($arquivo['tmp_name']) === false) {
    http_response_code(422);
    exit('<div class="alert alert-danger">Somente imagens JPEG, PNG, GIF e WebP são aceitas.</div>');
}

$subpasta = $tipo === 'produto' ? 'produtos' : 'lojas';
$diretorio = dirname(__DIR__) . '/assets/uploads/' . $subpasta;
if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true) && !is_dir($diretorio)) {
    http_response_code(500);
    exit('<div class="alert alert-danger">Diretório de upload indisponível.</div>');
}
$nome = bin2hex(random_bytes(16)) . '.' . $tiposPermitidos[$mime];
$caminho = $diretorio . '/' . $nome;
if (!move_uploaded_file($arquivo['tmp_name'], $caminho)) {
    http_response_code(500);
    exit('<div class="alert alert-danger">Não foi possível salvar a imagem.</div>');
}
chmod($caminho, 0644);
$url = '/assets/uploads/' . $subpasta . '/' . $nome;
?>
<div class="alert alert-success">
    Upload realizado com sucesso.
    <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Ver imagem</a>
</div>
