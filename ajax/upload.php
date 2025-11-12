<?php
// ajax/upload.php — processa upload via formulário padrão (multipart/form-data) e retorna HTML
// Campos esperados: tipo (produto|loja), arquivo (input file), opcional: nome_arquivo
header('Content-Type: text/html; charset=utf-8');
$root = dirname(__DIR__);
@require_once $root . '/includes/config.php';
@require_once $root . '/includes/security.php';


function log_upload($msg) {
$file = dirname(__DIR__) . '/logs/api.log';
if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
@file_put_contents($file, '['.date('c')."] UPLOAD " . $msg . "\n", FILE_APPEND);
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
echo '<div class="alert alert-error">Método inválido.</div>';
exit;
}


if (function_exists('csrf_verify')) {
if (!csrf_verify($_POST['csrf'] ?? '')) {
echo '<div class="alert alert-error">CSRF inválido.</div>';
exit;
}
}


$tipo = $_POST['tipo'] ?? '';
if (!in_array($tipo, ['produto', 'loja'], true)) {
echo '<div class="alert alert-error">Tipo de upload inválido.</div>';
exit;
}


if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
echo '<div class="alert alert-error">Nenhum arquivo enviado.</div>';
exit;
}


$destDir = $root . '/assets/uploads/' . ($tipo === 'produto' ? 'produtos' : 'lojas');
if (!is_dir($destDir)) @mkdir($destDir, 0775, true);


$origName = $_FILES['arquivo']['name'];
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$allowed = ['jpg','jpeg','png','gif','webp'];
if (!in_array($ext, $allowed, true)) {
echo '<div class="alert alert-error">Formato não permitido.</div>';
exit;
}


$base = preg_replace('/[^a-z0-9_-]/i', '-', pathinfo($origName, PATHINFO_FILENAME));
$final = ($base ?: 'imagem') . '-' . date('Ymd-His') . '.' . $ext;
$destPath = $destDir . '/' . $final;


if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $destPath)) {
echo '<div class="alert alert-error">Falha ao salvar arquivo.</div>';
exit;
}


log_upload("$tipo => $final");
$url = '/assets/uploads/' . ($tipo === 'produto' ? 'produtos' : 'lojas') . '/' . $final;
?>
<div class="upload-ok">
<p>Upload realizado com sucesso!</p>
<p><a href="<?= htmlspecialchars($url) ?>" target="_blank">Ver arquivo</a></p>
<?php if ($tipo === 'produto'): ?>
</div>