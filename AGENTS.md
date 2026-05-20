# AGENTS.md

## Objetivo principal

Trabalhe neste repositório de forma econômica, cirúrgica e segura.

Este repositório contém vários subprojetos independentes, incluindo site, CRM, ficha, zap/analisador, WhatsApp/Baileys e páginas promocionais. Não trate o repositório inteiro como uma única aplicação, a menos que a tarefa peça isso explicitamente.

## Regra de economia de tokens

- Não leia o projeto inteiro sem necessidade.
- Não faça buscas amplas se a tarefa indicar arquivos, pastas ou trechos específicos.
- Antes de abrir vários arquivos, identifique o menor conjunto provável de arquivos envolvidos.
- Prefira alterações pequenas, diretas e localizadas.
- Não produza explicações longas ao final.
- Ao terminar, responda apenas:
  1. arquivos alterados
  2. resumo curto do que mudou
  3. como testar

## Escopo de alteração

- Altere somente os arquivos necessários para a tarefa.
- Não modifique arquivos fora do escopo sem justificar antes.
- Não refatore código que não foi pedido.
- Não reorganize pastas.
- Não renomeie funções, variáveis, rotas, tabelas ou arquivos existentes sem necessidade real.
- Não altere layout, estilos ou comportamento fora do que foi solicitado.
- Não crie dependências novas se a solução puder ser feita com o que já existe.

## Estilo de implementação

- Faça o menor diff possível.
- Preserve o padrão visual e estrutural já existente.
- Em PHP, mantenha compatibilidade com servidor XAMPP/Apache/MySQL.
- Em JavaScript/Node, preserve a estrutura atual e evite trocar bibliotecas.
- Em CSS/Bootstrap/Tailwind, altere apenas as classes necessárias.
- Para banco de dados, nunca assuma que pode apagar ou recriar tabelas.
- Quando criar SQL, prefira scripts incrementais e seguros.

## Antes de alterar

Para tarefas médias ou grandes:

1. Liste os arquivos que pretende tocar.
2. Explique o plano em no máximo 5 linhas.
3. Só depois aplique a alteração.

Para tarefas pequenas e bem específicas, pode aplicar direto.

## Testes

- Quando possível, informe um teste manual simples.
- Não rode comandos destrutivos.
- Não execute migrations perigosas automaticamente.
- Não delete dados.
- Não sobrescreva arquivos grandes sem necessidade.

## Projetos conhecidos

### CRM

Pasta provável: `crm/`

Sistema em PHP com painel, leads, pipelines, status, drag-and-drop, configurações e integração futura com WhatsApp.

Regras:
- Preservar o funcionamento atual do `index.php`.
- Preservar handler/actions existentes.
- Não quebrar o fluxo de pipeline.
- Não alterar visual geral sem pedido explícito.

### WhatsApp / Baileys

Pastas prováveis: `crm/whatsapp/`, `whatsapp/` ou similares.

Regras:
- Não trocar Baileys por outra biblioteca.
- Não alterar número, sessão ou autenticação sem pedido.
- Preservar integração com CRM.
- Evitar mudanças grandes no bot principal.

### Ficha / formulários

Pasta provável: `ficha/`

Regras:
- Preservar campos existentes.
- Manter compatibilidade com PHP e JSON/MySQL conforme já usado.
- Não alterar endpoints sem atualizar chamadas relacionadas.

### Zap / analisador

Pasta provável: `zap/`

Regras:
- Preservar integração com API/modelo existente.
- Não trocar provedor/modelo sem pedido.
- Evitar respostas longas no código e no output.

## Segurança

- Nunca exponha secrets, tokens, senhas, chaves de API ou credenciais.
- Não commite arquivos `.env`, sessões do WhatsApp, dumps de banco ou backups sensíveis.
- Se encontrar segredo no código, avise no resumo.
- Não envie dados reais de clientes para serviços externos sem pedido explícito.

## Quando estiver em dúvida

- Não chute.
- Não faça “melhorias” não solicitadas.
- Prefira perguntar ou parar com uma explicação curta.
- Se houver múltiplas opções, escolha a mais simples e reversível.
