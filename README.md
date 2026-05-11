# Projeto CRM

Plataforma multi-estudio em desenvolvimento.

Este projeto sera separado do sistema atual, com codigo unico e isolamento por estudio.

## Estrutura inicial

- `index.php`: tela temporaria de verificacao.
- `deploy.php`: webhook de deploy do GitHub para este repositorio.
- `deploy.local.example.php`: exemplo de configuracao local do deploy.

## Deploy

Crie um arquivo `deploy.local.php` baseado em `deploy.local.example.php` com um segredo proprio para o webhook.
