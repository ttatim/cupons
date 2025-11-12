<?php
function uploadImagem($file, $pasta) {
    // Validar tipo de arquivo
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed)) {
        throw new Exception('Tipo de arquivo não permitido. Use: ' . implode(', ', $allowed));
    }
    
    // Validar tamanho (máx 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('Arquivo muito grande. Máximo 5MB.');
    }
    
    // Validar se é imagem real
    $check = getimagesize($file['tmp_name']);
    if ($check === false) {
        throw new Exception('Arquivo não é uma imagem válida.');
    }
    
    // Criar diretório se não existir
    if (!file_exists($pasta)) {
        mkdir($pasta, 0755, true);
    }
    
    // Gerar nome único
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $path = $pasta . '/' . $filename;
    
    // Mover arquivo
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new Exception('Erro ao fazer upload do arquivo.');
    }
    
    // Criar versões otimizadas se necessário
    otimizarImagem($path);
    
    return $filename;
}

function otimizarImagem($caminho) {
    // Implementar otimização de imagem aqui
    // Pode usar bibliotecas como GD ou ImageMagick
}

function excluirImagem($filename, $pasta) {
    if ($filename && file_exists($pasta . '/' . $filename)) {
        unlink($pasta . '/' . $filename);
    }
}

// Função para redimensionar imagens
function redimensionarImagem($caminho, $largura, $altura) {
    $info = getimagesize($caminho);
    $tipo = $info[2];
    
    switch ($tipo) {
        case IMAGETYPE_JPEG:
            $image = imagecreatefromjpeg($caminho);
            break;
        case IMAGETYPE_PNG:
            $image = imagecreatefrompng($caminho);
            break;
        case IMAGETYPE_GIF:
            $image = imagecreatefromgif($caminho);
            break;
        default:
            return false;
    }
    
    $resized = imagescale($image, $largura, $altura);
    
    switch ($tipo) {
        case IMAGETYPE_JPEG:
            imagejpeg($resized, $caminho, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($resized, $caminho, 8);
            break;
        case IMAGETYPE_GIF:
            imagegif($resized, $caminho);
            break;
    }
    
    imagedestroy($image);
    imagedestroy($resized);
    
    return true;
}
?>