# Etapa 02 — Compatibilidade e pré-requisitos PHP 8.3+ (24/10/2026)

## Escopo implementado
- Requisito de PHP 8.3 ou superior verificado no bootstrap, antes da conexão PDO.
- Extensões obrigatórias validadas: `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo_mysql`.
- Diagnóstico sem segredos via `php scripts/check_runtime.php` (código de saída 0 em ambiente válido e 1 em ambiente incompatível).
- Testes unitários da validação de versão/extensões, incluindo PHP 8.3, 8.4 e erros esperados.
- Correção das consultas de metadados do banco em `Database::getDatabaseInfo()`: placeholders agora são executados por `PDO::prepare()`, não por `PDO::query()`.
- Recuperação de páginas legadas com erros de sintaxe e referências a colunas antigas: `oferta.php`, `ajax/produto.php` e `ajax/cupom.php`. Os CTAs usam `go.php` para contabilizar cliques.
- Recuperação do endpoint `ajax/upload.php` com autenticação, CSRF, limite de 5 MiB e verificação real de MIME/imagem.
- Teste de instalação limpa ampliado para a página de oferta e listagens AJAX de produtos e cupons.
- Pipeline de CI em matriz PHP 8.3 e PHP 8.4, MySQL 8.0, análise sintática de **todos** os arquivos PHP, testes do importador, instalação limpa e criação de administrador de teste.

## Requisitos do servidor
- PHP 8.3+ com `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl` e `pdo_mysql`; `gd` é necessária para os fluxos legados de imagens.
- MySQL 8.0+ (ou MariaDB compatível, ainda não validado em CI).
- Apache ou Nginx com PHP-FPM, HTTPS e diretórios sensíveis fora da área pública ou bloqueados por regras do servidor.
- Não foi adicionado Node.js, Next.js, TypeScript ou processo de build nesta etapa.

## Como verificar
1. Execute `php -v` e `php scripts/check_runtime.php` no servidor.
2. Execute `php tests/test_runtime_requirements.php` e `php tests/test_offer_importer.php`.
3. Para lint completo: `find . -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l`.
4. Em um **banco vazio de testes**, configure `APP_ENV=testing` e as variáveis `DB_*`; execute `php tests/test_clean_install.php`. Este teste **insere dados fictícios no banco de teste**: nunca executá-lo contra produção.
5. Valide a execução do workflow `.github/workflows/item-01-php.yml` nas versões PHP 8.3 e 8.4 antes de publicar.

## Limitações / próximas verificações
- A compatibilidade sintática não equivale a uma auditoria de todos os fluxos legados. Erros de lógica, warnings e integração real com fornecedores ainda exigem testes de ponta a ponta.
- As extensões obrigatórias são verificadas no bootstrap; a aplicação retorna HTTP 503 se o runtime estiver incompleto.
- `gd` permanece requisito funcional dos uploads legados, mas não bloqueia a página pública por ser uma dependência condicional.
- A atualização não altera os dados do banco nem remove credenciais existentes.
- Os itens 3 a 11 permanecem pendentes, incluindo isolamento multiusuário e integrações externas.
