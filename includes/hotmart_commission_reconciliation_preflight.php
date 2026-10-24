<?php
declare(strict_types=1);

/**
 * Pré-verificação local de comissões: não é conciliação financeira.
 * Nunca libera liquidação ou pagamento com base apenas em dados de staging.
 */
final class HotmartCommissionReconciliationPreflight
{
    /**
     * @param array<string,int> $counts
     * @param array<string,int> $integrity
     * @param array<string,int> $evidence
     * @return array{local_consistent:bool,manual_review_required:bool,financial_ready:bool,reasons:list<string>}
     */
    public static function assess(array $counts, array $integrity, array $evidence): array
    {
        $required = [
            'counts' => ['groups_total', 'groups_to_review'],
            'integrity' => [
                'staged_without_history', 'orphan_observations',
                'flagged_without_risk_history', 'unflagged_with_risk_history',
            ],
            'evidence' => [
                'positive_and_risk_groups', 'multiple_risk_status_groups',
                'amount_variation_groups',
            ],
        ];
        foreach (['counts' => $counts, 'integrity' => $integrity, 'evidence' => $evidence] as $group => $values) {
            foreach ($required[$group] as $key) {
                if (!array_key_exists($key, $values) || !is_int($values[$key]) || $values[$key] < 0) {
                    throw new InvalidArgumentException('Indicadores locais incompletos ou inválidos.');
                }
            }
        }

        $reasons = [];
        $hasIntegrityIssue = array_sum(array_intersect_key($integrity, array_flip($required['integrity']))) > 0
            || $counts['groups_to_review'] > $counts['groups_total'];
        $hasEvidence = array_sum(array_intersect_key($evidence, array_flip($required['evidence']))) > 0;

        if ($counts['groups_total'] === 0) {
            $reasons[] = 'Nenhum grupo de comissão foi registrado nesta conta.';
        }
        if ($counts['groups_to_review'] > 0) {
            $reasons[] = 'Existem alertas de reembolso ou estorno aguardando revisão humana.';
        }
        if ($hasIntegrityIssue) {
            $reasons[] = 'Existem divergências entre staging e histórico local.';
        }
        if ($hasEvidence) {
            $reasons[] = 'Existem observações divergentes que exigem análise humana.';
        }
        // Sem prova de titularidade, cobertura completa, cronologia e liquidação,
        // indicadores locais jamais autorizam reconhecimento de receita.
        $reasons[] = 'Pendente: titularidade, cobertura de consultas, cronologia e liquidação validadas com o provedor.';

        return [
            'local_consistent' => $counts['groups_total'] > 0 && !$hasIntegrityIssue,
            'manual_review_required' => $counts['groups_total'] === 0
                || $counts['groups_to_review'] > 0 || $hasIntegrityIssue || $hasEvidence,
            'financial_ready' => false,
            'reasons' => $reasons,
        ];
    }
}
