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
- Agenda com visualizacao em mes, semana, dia e blocos dos proximos agendamentos.
- Cadastro de tatuadores por estudio e escolha do tatuador responsavel em cada agendamento.
- Central de WhatsApp por estudio com chave de sessao isolada, QR Code, envio manual e webhook do Baileys multi-sessao.
- Financeiro por estudio com despesas, categorias e resultado simples do mes.
- Respostas rapidas por estudio para atendimento humano e futura IA.
- Relatorios de leads, agenda e despesas.
- Configuracoes por estudio para regras de IA, modelo e WhatsApp.
- Assistente IA de dados em modo somente leitura para consultar clientes, leads, agenda, WhatsApp e financeiro do estudio.

## Configuracoes do estudio

- `Permitir IA responder clientes quando a conversa estiver em modo IA`: libera a IA para responder apenas conversas marcadas como atendimento por IA.
- `Permitir conexao WhatsApp/Baileys neste estudio`: libera o uso da sessao WhatsApp daquele estudio no servico multi-estudio.
- `Atendimento padrao para novas conversas`: define se um cliente novo entra primeiro com humano ou com IA.

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

## Servico WhatsApp multi-estudio

O servico Node fica em `services/whatsapp` e usa uma pasta de sessao por estudio em `services/whatsapp/sessions`.

Primeira execucao:

```bash
cd services/whatsapp
npm install
npm start
```

Por padrao ele roda em `http://localhost:3010` e envia eventos para `http://localhost/projetocrm/api/whatsapp_webhook.php`.
No CRM do estudio, abra `WhatsApp`, clique em "Iniciar ou gerar QR" e escaneie o QR Code.

## Deploy

O `deploy.php` deste projeto recebe o webhook do repositorio `projetocrm`.
