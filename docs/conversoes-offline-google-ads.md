# Conversoes offline do Google Ads (do clique ao cliente que fecha)

## O problema que isto resolve

O site dispara a conversao **Contato** quando a pessoa clica no botao do WhatsApp.
Isso mede CLIQUE, nao cliente. Em 18-28/09/2026 o Google reportou 284 conversoes
enquanto o CRM tinha 204 contatos de anuncio e 20 agendamentos reais. O Smart
Bidding estava otimizando por clique, e as conversoes de valor (orcamento/compra)
ficavam em zero.

## Como o rastreio funciona agora (ponta a ponta)

1. Clique no anuncio do Google -> o site abre com `gclid` na URL.
2. `index.php` (site) monta o link do WhatsApp passando pelo rastreio do CRM:
   `projetocrm/ir.php?src=google&gclid=...`
3. `ir.php` guarda o clique em `ads_src_hits` (com o `gclid`) e devolve na
   mensagem do WhatsApp um codigo curto `[ref:xxxxxxxx]` (o gclid e longo demais
   para o cliente ver).
4. A ponte Baileys le o `[ref:...]` e envia em `api/origin_bridge.php`.
5. `origin_bridge.php` troca `ref` -> `gclid` e grava em
   `ads_origin_hits.click_id`. A partir daqui o CRM sabe QUAL clique trouxe
   aquele telefone.
6. Quando o lead avanca no funil (a IA casou a conversa com um agendamento) ou
   fecha na pipeline, a conversao entra na fila `ads_offline_conv`.
7. A fila e enviada ao Google Ads pela **Data Manager API** (`events:ingest`)
   nas acoes "Orcamento (CRM)" e "Compra (CRM)", ambas do tipo
   *Import from clicks* (UPLOAD_CLICKS).

## Por que Data Manager API e nao `uploadClickConversions`

Desde 09/2026 o `ConversionUploadService.UploadClickConversions` da API do
Google Ads esta fechado para novas integracoes
(`CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE`). O caminho oficial passou a ser a
Data Manager API, que exige o escopo OAuth
`https://www.googleapis.com/auth/datamanager`.

## Comandos

```bat
REM conferencia: o que o Google reporta x o que o CRM tem
php tools\ads_conversao_offline.php --relatorio --dias=30

REM simula o envio (nao toca na API)
php tools\ads_conversao_offline.php --simular --dias=30

REM descobre/cria as acoes "Import from clicks" e grava os ids
php tools\ads_conversao_offline.php --preparar

REM valida no Google sem gravar nada (validateOnly)
php tools\ads_conversao_offline.php --validar --dias=30

REM envia de verdade
php tools\ads_conversao_offline.php --apply --dias=30
```

A tarefa agendada **Projetocrm AdsConversao Offline** roda o `--apply` todo dia
as 09:00. Para desligar:

```powershell
Disable-ScheduledTask -TaskName 'Projetocrm AdsConversao Offline'
```

## Tabelas envolvidas

- `ads_src_hits` — clique no link de anuncio (src, gclid, ref_code).
- `ads_origin_hits` — contato de anuncio na ponte (phone, origin, click_id).
- `ads_offline_conv` — fila de conversoes reais (gclid + acao + valor + status).

## Detalhe que confunde: `contatos` x `conversoes`

- `ads_origin_hits` conta CONTATOS: 1 telefone = 1 contato (deduplicado).
- O Google conta CONVERSOES de "Contato" a cada clique no botao, entao o numero
  dele e sempre maior. Depois que as conversoes offline entrarem, compare pelas
  acoes "Orcamento (CRM)" e "Compra (CRM)" — sao essas que espelham o CRM.

## Janela de tempo da Data Manager API

A API recusa evento no futuro ou recente demais
(`EVENT_TIME_INVALID: Event did not occur within the acceptable time window`).
Medido em 28/09/2026: "agora" e "+5 min" falham; "-10 min" ja passa. Passado
distante e aceito (testado ate -60 dias). Por isso `ads_conv_offline_enviar()`
deixa na fila qualquer evento com menos de 15 minutos de idade: ele e enviado na
rodada seguinte, em vez de virar erro.

## Pre-requisitos no Google Cloud / Google Ads (ja feitos)

1. Escopo OAuth `https://www.googleapis.com/auth/datamanager` (reconectar em
   `google_ads_oauth_start.php`).
2. Data Manager API habilitada no projeto 845448328086.
3. Acoes de conversao `Orcamento (CRM)` e `Compra (CRM)` do tipo
   *Import from clicks* (criadas por `--preparar`).
