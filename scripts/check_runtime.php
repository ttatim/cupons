<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/runtime_requirements.php';

$issues = RuntimeRequirements::diagnose();
if ($issues !== []) {
    fwrite(STDERR, "Ambiente incompatível:\n- " . implode("\n- ", $issues) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Ambiente OK: PHP %s; extensões obrigatórias disponíveis.\n",
    PHP_VERSION
));
