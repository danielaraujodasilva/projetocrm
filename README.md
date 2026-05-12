# Projeto CRM

Alpha da plataforma multi-estudio.

Este projeto roda separado do sistema atual, mas fica online em `/projetocrm`.

## Alpha atual

- Banco central da plataforma.
- Instalacao do primeiro gerente.
- Login administrativo.
- Cadastro de estudios.
- Instalacao/atualizacao do banco isolado pelo painel gerente.
- Login operacional por estudio.
- Inicio do CRM por estudio com metricas iniciais.
- Cadastro simples de clientes.
- Cadastro simples de leads com status, etapa, origem, valor estimado e nota 0-10.
- Agenda inicial com cliente, lead, data, horario, valor, sinal e status.
- Central inicial de WhatsApp por estudio, pronta para receber conversas do Baileys multi-sessao.
- Financeiro por estudio com despesas, categorias e resultado simples do mes.
- Respostas rapidas por estudio para atendimento humano e futura IA.
- Relatorios de leads, agenda e despesas.
- Configuracoes por estudio para regras de IA, modelo e WhatsApp.

## SQL

Rode `database/platform_alpha.sql` no phpMyAdmin para criar o banco central.

Se voce ja criou o banco antes da area de login dos estudios, rode tambem `database/platform_alpha_002_studio_users.sql`.

Depois de cadastrar um estudio no painel, abra o estudio e clique em "Instalar banco do estudio". Se preferir fazer manualmente, use "Ver SQL do banco do estudio" e rode o SQL gerado no phpMyAdmin.

## Configuracao local

A configuracao padrao usa:

- host: `localhost`
- banco: `projetocrm_platform`
- usuario: `root`
- senha vazia

Se precisar alterar, copie `config/database.local.example.php` para `config/database.local.php`.

## Deploy

O `deploy.php` deste projeto recebe o webhook do repositorio `projetocrm`.
