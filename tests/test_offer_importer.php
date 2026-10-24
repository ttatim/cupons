<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/offer_metadata_importer.php';

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }
}
foreach ([
    'http://example.com/produto',
    'https://localhost/admin',
    'https://127.0.0.1/private',
    'https://192.168.1.1/',
    'https://user:password@example.com/',
    'https://host.internal/private',
] as $url) {
    try {
        OfferMetadataImporter::validatePublicUrl($url);
        throw new RuntimeException('URL proibida foi aceita: ' . $url);
    } catch (InvalidArgumentException $expected) {
        // esperado
    }
}
check(OfferMetadataImporter::validatePublicUrl('https://loja.exemplo.com/produto') === 'https://loja.exemplo.com/produto', 'URL pública');
$importer = new OfferMetadataImporter();
$html = '<!doctype html><html><head><title>Título simples</title>'
    . '<meta property="og:title" content="Tênis de corrida">'
    . '<meta name="description" content="Leve e confortável">'
    . '<meta property="product:price:amount" content="129.90">'
    . '<meta property="og:image" content="https://cdn.exemplo.com/tenis.jpg">'
    . '</head></html>';
$data = $importer->parseHtml($html, 'https://loja.exemplo.com/item');
check($data['title'] === 'Tênis de corrida', 'OG title');
check($data['description'] === 'Leve e confortável', 'description');
check($data['price'] === '129.90', 'price');
check($data['image'] === 'https://cdn.exemplo.com/tenis.jpg', 'image');
check($data['manual_required'] === false, 'dados suficientes');
$json = '<script type="application/ld+json">'
    . '{"@type":"Product","name":"Produto JSON","description":"Detalhes","offers":{"price":"89.90","priceCurrency":"BRL"}}'
    . '</script>';
$data = $importer->parseHtml('<html><head>' . $json . '</head></html>', 'https://loja.exemplo.com/item');
check($data['title'] === 'Produto JSON' && $data['price'] === '89.90', 'JSON-LD');
$data = $importer->parseHtml('<html><head><title>Sem preço</title></head></html>', 'https://loja.exemplo.com/item');
check($data['manual_required'] === true, 'fallback manual');
echo "Testes do importador passaram.\n";
