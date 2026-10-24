-- Etapa 03: catálogo externo em revisão; NÃO publica produtos nem inclui dados de compradores.
-- Execute uma vez em uma base já instalada; instalação nova também requer esta migração.
CREATE TABLE IF NOT EXISTS affiliate_product_staging (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    account_hash CHAR(64) NOT NULL,
    external_id VARCHAR(128) NOT NULL,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'desconhecido',
    currency CHAR(3) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_product (provider, account_hash, external_id),
    KEY idx_affiliate_product_updated (provider, account_hash, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
