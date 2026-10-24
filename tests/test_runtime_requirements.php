<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/runtime_requirements.php';

function expectRuntime(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }
}

$required = RuntimeRequirements::REQUIRED_EXTENSIONS;
expectRuntime(RuntimeRequirements::diagnose(80300, $required) === [], 'PHP 8.3 com extensões completas');
expectRuntime(RuntimeRequirements::diagnose(80400, $required) === [], 'PHP 8.4 com extensões completas');
$old = RuntimeRequirements::diagnose(80299, $required);
expectRuntime(count($old) === 1 && str_contains($old[0], '8.3'), 'PHP antigo deve ser recusado');
$missing = RuntimeRequirements::diagnose(80300, ['json', 'openssl']);
expectRuntime(count($missing) === count($required) - 2, 'extensões faltantes detectadas');
expectRuntime(in_array('Extensão PHP obrigatória ausente: pdo_mysql', $missing, true), 'pdo_mysql obrigatório');
$upper = array_map('strtoupper', $required);
expectRuntime(RuntimeRequirements::diagnose(80300, $upper) === [], 'nomes de extensões sem diferenciação de caixa');
echo "Requisitos do runtime validados.\n";
