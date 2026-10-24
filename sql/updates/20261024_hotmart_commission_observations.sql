-- Histórico de observações Hotmart, sem PII, saldo ou dados reais.
-- Aplicar APÓS 20261024_hotmart_commission_staging.sql.
-- Uma observação distinta é identificada por HMAC (conta, transação, papel,
-- moeda, status consultado, valor e quantidade). observed_at é hora LOCAL de coleta.
CREATE TABLE IF NOT EXISTS hotmart_commission_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    transaction_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source VARCHAR(16) NOT NULL,
    currency CHAR(3) NOT NULL,
    observed_status VARCHAR(24) NOT NULL,
    amount_cents BIGINT NOT NULL,
    commission_count SMALLINT UNSIGNED NOT NULL,
    observation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hotmart_observation (account_hash, observation_hash),
    KEY idx_hotmart_observations_summary (account_hash, observed_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
