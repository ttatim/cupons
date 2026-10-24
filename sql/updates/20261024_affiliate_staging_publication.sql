-- Aplicar DEPOIS de 20261024_affiliate_product_staging.sql e da criação de produtos.
-- Registra a publicação supervisionada e impede duplicações acidentais.
-- Não importa dados reais nem altera produtos já publicados.
ALTER TABLE affiliate_product_staging
    ADD COLUMN published_product_id INT NULL DEFAULT NULL,
    ADD COLUMN published_at DATETIME NULL DEFAULT NULL,
    ADD UNIQUE KEY uq_staging_published_product (published_product_id);
