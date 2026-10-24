-- Etapa 03: observações mínimas Hotmart para revisão, SEM conciliação, saldo ou PII.
-- Execute uma vez após sql/database.sql; tabela vazia e isolada por hash HMAC de conta.
-- HOTMART_COMMISSION_HASH_KEY deve ser mantida estável fora do banco/repositório.
CREATE TABLE IF NOT EXISTS hotmart_commission_staging (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    transaction_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source VARCHAR(16) NOT NULL,
    currency CHAR(3) NOT NULL,
    amount_cents BIGINT NOT NULL,
    commission_count SMALLINT UNSIGNED NOT NULL,
    observed_status VARCHAR(24) NOT NULL,
    review_required TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hotmart_commission (account_hash, transaction_hash, source, currency),
    KEY idx_hotmart_review (account_hash, review_required, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
