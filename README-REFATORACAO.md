# TTaTim Cupons — Refatorado p/ PHP 8.1

Este pacote mantém **a mesma estrutura de arquivos/pastas** do original, com ajustes pontuais para rodar em PHP 8.1 e MySQL via PDO.

## O que foi ajustado
- `includes/config.php`: definições de `ENVIRONMENT` e `DB_CHARSET` e **ordem de carregamento** (constantes primeiro, depois `database.php`). Criação de `$pdo` com PDO (modo exceção, fetch assoc, emulação off).
- Bootstrap mais resiliente: em `production` apenas loga erros críticos de DB; em `development` mostra erro.
- Semente opcional de usuário padrão em `development`.

> Observação: A classe `Database` existente continua ativa e é instanciada no bootstrap **após** as constantes, respeitando o comportamento de auto-criar tabelas se implementado.

## Como rodar local
1. Crie banco MySQL e usuário com acesso.
2. Copie `.env.example` para `.env` e ajuste:
   ```env
   APP_ENV=development
   DB_HOST=localhost
   DB_NAME=pelando_clone
   DB_USER=root
   DB_PASS=
   DB_CHARSET=utf8mb4
   ```
3. Configure seu servidor (Apache/Nginx) apontando para a raiz do projeto.
4. Acesse `index.php` (público) e `/admin/login.php` (painel).

## Estrutura preservada
Mantida conforme o original (ver diretórios `admin/`, `ajax/`, `assets/`, `includes/`, etc.).

## Notas de compatibilidade PHP 8.1
- Evitamos recursos deprecados e habilitamos `PDO::ATTR_EMULATE_PREPARES=false`.
- Caso algum arquivo use `mysqli_*` ou padrão diferente, recomendo padronizar em `$pdo`. Se encontrar algum erro em páginas específicas, me informe o arquivo/rota que ajusto.

---
Gerado em: 2025-11-12T20:20:01
