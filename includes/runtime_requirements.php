<?php
declare(strict_types=1);

/**
 * Requisitos mínimos do runtime. Não depende do banco nem inicia sessão.
 * Compatível com PHP 8.3+; permite testes sem extensões de produção.
 */
final class RuntimeRequirements
{
    public const MIN_PHP_VERSION_ID = 80300;

    public const REQUIRED_EXTENSIONS = [
        'curl',
        'dom',
        'fileinfo',
        'json',
        'mbstring',
        'openssl',
        'pdo_mysql',
    ];

    /**
     * @param list<string>|null $extensions Lista injetável para testes.
     * @return list<string> Problemas encontrados, sem dados sensíveis.
     */
    public static function diagnose(?int $phpVersionId = null, ?array $extensions = null): array
    {
        $version = $phpVersionId ?? PHP_VERSION_ID;
        $loaded = array_map('strtolower', $extensions ?? get_loaded_extensions());
        $issues = [];

        if ($version < self::MIN_PHP_VERSION_ID) {
            $issues[] = 'PHP 8.3 ou superior é obrigatório.';
        }

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!in_array(strtolower($extension), $loaded, true)) {
                $issues[] = 'Extensão PHP obrigatória ausente: ' . $extension;
            }
        }

        return $issues;
    }

    public static function assertCompatible(): void
    {
        $issues = self::diagnose();
        if ($issues !== []) {
            throw new RuntimeException(implode(' ', $issues));
        }
    }
}
