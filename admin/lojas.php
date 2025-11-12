<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Processar formulário
if ($_POST) {
    $nome = $_POST['nome'];
    $slug = criarSlug($nome);
    
    $logo = null;
    if (!empty($_FILES['logo']['name'])) {
        $logo = uploadLogo($_FILES['logo']);
    }
    
    $stmt = $pdo->prepare("INSERT INTO lojas (nome, slug, logo) VALUES (?, ?, ?)");
    $stmt->execute([$nome, $slug, $logo]);
    
    header('Location: lojas.php?success=1');
    exit;
}

function criarSlug($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    
    if (empty($text)) {
        return 'n-a';
    }
    
    return $text;
}

function uploadLogo($file) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (in_array($ext, $allowed)) {
        $filename = uniqid() . '.' . $ext;
        $path = '../assets/images/lojas/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $path)) {
            return $filename;
        }
    }
    return null;
}

// Buscar lojas
$stmt = $pdo->query("SELECT * FROM lojas ORDER BY nome");
$lojas = $stmt->fetchAll();
?>
<!-- Estrutura HTML similar aos outros arquivos -->