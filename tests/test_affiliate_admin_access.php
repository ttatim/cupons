<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/affiliate_admin_access.php';

function affiliateAdminCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Affiliate admin access: ' . $message);
    }
}
$lookups = [];
$lookup = static function (int $id) use (&$lookups): ?string {
    $lookups[] = $id;
    return match ($id) {
        11 => 'admin',
        12 => 'editor',
        13 => null, // revoked/inactive/nonexistent
        default => null,
    };
};
AffiliateAdminAccess::requireActiveAdmin(['user_id' => 11], $lookup);
AffiliateAdminAccess::requireActiveAdmin(['user_id' => '11'], $lookup);
affiliateAdminCheck($lookups === [11, 11], 'administrador ativo autorizado');
foreach ([[], ['user_id' => 0], ['user_id' => -1], ['user_id' => '01'],
    ['user_id' => '11abc'], ['user_id' => '9223372036854775808'],
    ['user_id' => ['11']], ['user_id' => 12], ['user_id' => 13]] as $session) {
    try {
        AffiliateAdminAccess::requireActiveAdmin($session, $lookup);
        throw new LogicException('Sessão sem autorização foi aceita.');
    } catch (RuntimeException $exception) {
        affiliateAdminCheck($exception->getMessage() === 'Acesso negado.', 'negação genérica');
    }
}
try {
    AffiliateAdminAccess::requireActiveAdmin(['user_id' => 11], static function (int $id): ?string {
        throw new RuntimeException('DB_SECRET_CONNECTION_ERROR');
    });
    throw new LogicException('Falha de banco foi aceita.');
} catch (RuntimeException $exception) {
    affiliateAdminCheck($exception->getMessage() === 'Acesso negado.', 'erro de banco não vaza detalhes');
}
affiliateAdminCheck($lookups === [11, 11, 12, 13], 'IDs inválidos não consultam banco');
echo "Affiliate admin access: administrador ativo, negações e falha fechada aprovados.\n";
