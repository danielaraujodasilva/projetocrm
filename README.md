# Projeto CRM

Alpha da plataforma multi-estudio.

Este projeto roda separado do sistema atual, mas fica online em `/projetocrm`.

## Alpha atual

- Banco central da plataforma.
- Instalacao do primeiro gerente.
- Login administrativo.
- Cadastro de estudios.
- SQL individual para o banco isolado de cada estudio.
- Tela base do CRM por estudio.

## SQL

Rode `database/platform_alpha.sql` no phpMyAdmin para criar o banco central.

Se voce ja criou o banco antes da area de login dos estudios, rode tambem `database/platform_alpha_002_studio_users.sql`.

Depois de cadastrar um estudio no painel, abra "Ver SQL do banco do estudio" e rode o SQL gerado para criar o banco isolado daquele estudio.

## Configuracao local

A configuracao padrao usa:

- host: `localhost`
- banco: `projetocrm_platform`
- usuario: `root`
- senha vazia

Se precisar alterar, copie `config/database.local.example.php` para `config/database.local.php`.

## Deploy

O `deploy.php` deste projeto recebe o webhook do repositorio `projetocrm`.
