<?php
declare(strict_types=1);

/**
 * Instalação inicial sem seed. EXECUTE SOMENTE contra banco completamente vazio.
 *
 * Não serve como atualizador de instalações existentes: DDL MySQL não é
 * transacional e as migrações ALTER devem ser executadas uma única vez.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function abortInstall(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

if (getenv('INSTALL_CLEAN_CONFIRM') !== 'EMPTY_DATABASE_ONLY') {
    abortInstall('Confirme INSTALL_CLEAN_CONFIRM=EMPTY_DATABASE_ONLY para instalação inicial.');
}

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV'] as $key) {
    if (getenv($key) === false || getenv($key) === '') {
        abortInstall('Variável obrigatória ausente: ' . $key);
    }
}
if (!in_array(getenv('APP_ENV'), ['testing', 'development', 'production'], true)) {
    abortInstall('APP_ENV deve ser testing, development ou production.');
}

require_once __DIR__ . '/../includes/runtime_requirements.php';
try {
    RuntimeRequirements::assertCompatible();
} catch (RuntimeException $exception) {
    abortInstall('Runtime PHP incompatível: ' . $exception->getMessage());
}

try {
    $connection = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASS'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    // Inspeção ANTES do bootstrap, que cria tabelas automaticamente.
    $existingTables = $connection->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if ($existingTables !== []) {
        abortInstall('Instalação recusada: o banco já possui tabelas. Nada foi alterado.');
    }
} catch (PDOException $exception) {
    abortInstall('Não foi possível validar o banco vazio (SQLSTATE ' . $exception->getCode() . ').');
}

$updates = [
    '20261024_affiliate_product_staging.sql',
    '20261024_affiliate_product_review.sql',
    '20261024_affiliate_product_publication.sql',
    '20261024_hotmart_commission_staging.sql',
    '20261024_hotmart_commission_observations.sql',
    '20261024_monetizze_relay_nonces.sql',
    '20261024_monetizze_event_observations.sql',
    '20261025_monetizze_review_candidates.sql',
    '20261025_monetizze_review_decisions.sql',
];

// Verificar a disponibilidade de TODOS os arquivos antes de qualquer DDL.
foreach ($updates as $file) {
    if (!is_file(__DIR__ . '/../sql/updates/' . $file)) {
        abortInstall('Arquivo de migração ausente: ' . $file);
    }
}

try {
    require __DIR__ . '/../includes/config.php'; // Inicializa somente as tabelas base, sem seeds.
    foreach ($updates as $file) {
        $sql = file_get_contents(__DIR__ . '/../sql/updates/' . $file);
        if ($sql === false || trim($sql) === '') {
            abortInstall('Migração vazia ou ilegível: ' . $file);
        }
        $pdo->exec($sql);
    }

    // Todas as tabelas são novas; nenhum usuário, oferta ou evento pode existir.
    $requiredTables = [
        'usuarios', 'lojas', 'produtos', 'cupons', 'comissoes',
        'affiliate_product_staging', 'hotmart_commission_staging',
        'hotmart_commission_observations', 'monetizze_relay_nonces',
        'monetizze_event_observations', 'monetizze_review_candidates',
        'monetizze_review_decisions',
    ];
    foreach ($requiredTables as $table) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        if ($count !== 0) {
            abortInstall('Tabela inesperadamente populada: ' . $table);
        }
    }
} catch (Throwable $exception) {
    abortInstall(
        'Instalação incompleta (código ' . $exception->getCode() .
        '). Não execute novamente no mesmo banco; revise logs privados e use outro banco vazio.'
    );
}

fwrite(STDOUT, 'Schema base e ' . count($updates) . ' migrações aplicados; 12 tabelas críticas vazias. Sem seed.' . PHP_EOL);
