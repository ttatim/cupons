# Etapa 01 — Importação de produtos e cupons (24/10/2026)

## Entrega e escopo
- Vitrine pública em Bootstrap com cartões de produtos e cupons.
- Botão azul **ir para pagamento** em produtos; botão azul **Pegue seu cupom** em cupons.
- O arquivo go.php valida a URL pública HTTPS antes do redirecionamento e contabiliza cliques.
- A tela admin/importar.php importa metadados básicos de uma URL pública ou permite preencher manualmente; grava produtos e cupons nas tabelas existentes.
- O serviço includes/offer_metadata_importer.php busca HTML com limite de 1 MiB, 12 segundos, TLS verificado, DNS/IP público e até 3 redirecionamentos HTTPS, e lê OpenGraph, metatags e JSON-LD.
- Sem scraping de conteúdo autenticado, execução de scripts da página, nem garantia de extração de todas as lojas.

## Instalação limpa (sem dados de demonstração)
1. Requisitos: PHP **8.3+**, MySQL **8.0+** (ou MariaDB compatível), servidor Apache/Nginx, extensões PHP pdo_mysql, curl, dom, mbstring, openssl, json, fileinfo. gd é necessário para uploads/recursos legados.
2. Crie um **banco vazio** com utf8mb4 e usuário SQL com permissões adequadas. Não importe sql/sample-database.sql; o legado sql/database.sql tem estrutura diferente e não é o método recomendado para instalação limpa desta etapa.
3. Configure variáveis de ambiente DB_HOST, DB_NAME, DB_USER, DB_PASS, APP_ENV=production no servidor. Nunca coloque segredos no Git.
4. Abra a aplicação uma vez ou execute PHP CLI carregando includes/config.php para inicializar as tabelas via includes/database.php. O bootstrap foi alterado para **não criar usuário padrão nem lojas fictícias**.
5. Crie o administrador explicitamente no terminal, definindo ADMIN_USERNAME, ADMIN_EMAIL e ADMIN_PASSWORD (mínimo 16 caracteres) e executando php scripts/create_admin.php. Não informe a senha no histórico do shell: prefira variáveis obtidas por prompt seguro do terminal/secret manager.
6. Acesse /admin/login.php, cadastre uma loja no painel e use /admin/importar.php para criar produtos ou cupons. O cadastro não exige API de afiliados nesta etapa.
7. Garanta HTTPS, bloqueio de acesso web a includes/, scripts/, sql/, logs/, arquivos de configuração e backup. Revise permissões de escrita e de diretórios.
8. Execute php tests/test_offer_importer.php e php -l nos arquivos modificados. A workflow item-01-php.yml valida os arquivos alterados.

## Limitações e cuidados
- Algumas lojas (Shopee, Magalu etc.) bloqueiam robôs, dependem de JavaScript ou não expõem preço no HTML. O sistema avisa e exige preenchimento manual.
- O importador aceita apenas URL HTTPS pública, sem usuário/senha na URL. URLs antigas HTTP precisarão ser substituídas.
- A imagem remota é armazenada como referência, sujeita a expiração/hotlink. Upload local de até seis imagens fica para o item 9.
- O valor extraído é sugestão; o editor deve revisar preço, disponibilidade, direitos de uso de imagens, descrição e destino de checkout.
- O botão de produto **não processa pagamento**: redireciona ao checkout externo informado pelo editor.
- O botão de cupom redireciona à URL informada; não há resgate automático.
- Os recursos legados de edição/listagem e outros endpoints ainda precisam de auditoria. Não publicar em produção sem revisão completa de segurança e teste de integração com MySQL.
- O importador não usa APIs oficiais de afiliados (item 3) e não isola vendedores (itens 6/10).
- A verificação automatizada atual cobre sintaxe PHP e testes unitários do parser; não substitui teste E2E com banco/servidor.
- Não execute scripts de seed de demonstração ao montar uma versão limpa.

## Registro técnico
Branch: feat/item-01-importacao-segura-20261024.
Arquivos centrais: admin/importar.php, includes/offer_metadata_importer.php, go.php, index.php, tests/test_offer_importer.php, scripts/create_admin.php.
Requisitos 2 a 11 e complementos do item 1 permanecem no backlog. O próximo ciclo deverá priorizar testes E2E, migrações e normalização do legado para PHP 8.3.
