-- Etapa 03: nonce anti-replay do relay INTERNO assinado (não é webhook Monetizze).
-- Aplicar uma vez após sql/database.sql, sem importar dados ou alterar tabelas existentes.
-- Não guarda payload, evento, conta, token, comprador ou valores financeiros.
-- A chave primária impede claims concorrentes e reenvios após reinícios.
-- NÃO automatizar limpeza até aprovação formal de retenção; o verificador
-- também recusa envelopes fora da janela temporal de 300 segundos.
CREATE TABLE IF NOT EXISTS monetizze_relay_nonces (
    nonce CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (nonce),
    KEY idx_monetizze_relay_nonce_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
