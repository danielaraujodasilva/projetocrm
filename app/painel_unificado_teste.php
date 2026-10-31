<?php
/**
 * PAGINA TESTE (dentro do shell do estudio): uniao de
 * "Retorno dos Anuncios" + "Funil de Vendas".
 *
 * Por que existe: testar se as duas telas viram UMA. Mobile first, so dados.
 *
 * REGRA ANTI-REPETICAO (por que cada numero aparece UMA vez):
 *   - Gasto (R$ e por canal) .......... so no bloco Anuncios
 *   - Saldo das contas ................ so no bloco Anuncios
 *   - Funil 5 etapas .................. so no bloco Funil
 *   - Marcados / Futuros / Realizados . so no bloco Agenda
 *   - Origem real ..................... uma tabela so (nao duas)
 *   Nao ha card repetindo "conversas" nem "leads de anuncio": os dois numeros
 *   ja aparecem como etapa do funil. O unico reaproveitamento e o VALOR
 *   realizado, que aparece como destaque no topo e na tabela dia a dia -
 *   recorte diferente (total vs por dia), igual as telas originais.
 *
 * SOMENTE LEITURA: nao escreve em banco nenhum.
 */
declare(strict_types=1);

require_once APP_BASE_PATH . '/app/historico_conversas.php';
require_once APP_BASE_PATH . '/app/historico_leads_map.php';
require_once APP_BASE_PATH . '/app/funil.php';
require_once APP_BASE_PATH . '/app/ads_origem_catalogo.php';

$studio = require_studio();
$pdo = studio_db($studio);

// ---------------------------------------------------------------------------
// Periodo: mesmos presets do ROI (roi_days / roi_from+roi_to).
// ---------------------------------------------------------------------------
$dateOk = static function (string $v): bool {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1
        && DateTime::createFromFormat('Y-m-d', $v) instanceof DateTime;
};
$from = trim((string)($_GET['roi_from'] ?? ''));
$to = trim((string)($_GET['roi_to'] ?? ''));
if ($dateOk($from) && $dateOk($to)) {
    if ($from > $to) { [$from, $to] = [$to, $from]; }
    $start = $from; $end = $to; $preset = null; $custom = true;
} else {
    $dias = (int)($_GET['roi_days'] ?? 1);
    if (!in_array($dias, [1, 7, 15, 30, 60, 90], true)) { $dias = 1; }
    $start = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));
    $end = date('Y-m-d'); $preset = $dias; $custom = false;
}
$diasPeriodo = (int)((strtotime($end) - strtotime($start)) / 86400) + 1;
$qPeriodo = $custom ? ['roi_from' => $start, 'roi_to' => $end] : ['roi_days' => (int)$preset];
// O funil de vendas tem parametros proprios (f_de/f_ate) - nao entende roi_days.
// Sem isso, o clique no funil caia na pagina antiga com o periodo padrao (30 dias).
$qFunilPeriodo = ['f_de' => $start, 'f_ate' => $end];
// Escopo de verificacao do periodo (usado nos links das tabelas).
$qVerif = $qPeriodo;

// ---------------------------------------------------------------------------
// Dados: anuncios (ROI)
// ---------------------------------------------------------------------------
$s = ads_roi_summary($pdo, $start, $end);
$agReal = ads_roi_agendado_realizado($pdo, $start, $end);
$futurosTotal = ads_roi_agendado_realizado($pdo, date('Y-m-d'), date('Y-m-d', strtotime('+365 days')));
$projecao = ads_roi_monthly_projection($pdo);
$saldoMeta = studio_meta_ads_balance_status($studio);
$saldoGoogle = ads_roi_google_balance_status($studio);

// Marcados no periodo: mesma regra do card do ROI (google_created_at, sem pessoal).
$criadosStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM appointments
      WHERE DATE(COALESCE(google_created_at, created_at)) BETWEEN ? AND ?
        AND YEAR(appointment_date) BETWEEN 2000 AND 2100
        AND (import_source IS NULL OR import_source = "" OR google_created_at IS NOT NULL)
        AND NOT (
             LOWER(title) REGEXP "limpeza|luna|niver|aniversario|casamento|inss|pericia|cafe da manha|estorno|escola|tv vizinho|lembrar|sabado da|spa day|manutencao"
        )'
);
$criadosStmt->execute([$start, $end]);
$marcadosPeriodo = (int)$criadosStmt->fetchColumn();

// ---------------------------------------------------------------------------
// Dados: funil de vendas
// ---------------------------------------------------------------------------
$escopo = funil_leads_ads($pdo, $start, $end);
$funil = funil_calcular($pdo, $start, $end, $escopo);

// O detalhe do funil nao traz lead_id (funil.php:805). Resolvemos aqui pelo
// telefone, para o accordion linkar o lead certo em vez de id=0.
$leadIdPorFone = [];
try {
    $stMap = $pdo->query(
        'SELECT REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, \'\'), \'(\', \'\'), \')\', \'\'), \'-\', \'\'), \' \', \'\') AS fone, MAX(id) AS lead_id
           FROM leads
          WHERE phone IS NOT NULL AND phone <> \'\'
          GROUP BY fone'
    );
    foreach ($stMap->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $f = preg_replace('/\D+/', '', (string)$r['fone']);
        if ($f !== '') { $leadIdPorFone[$f] = (int)$r['lead_id']; }
    }
} catch (Throwable $e) {
    $leadIdPorFone = [];
}

$origens = $funil['origens'];
$totalOrigem = 0;
foreach ($origens as $o) { $totalOrigem += (int)$o['total']; }

// Definicao do dono (23/09/2026): "importados sem rastreio" (lead vindo de
// importacao de agenda, sem clique em anuncio) ficam FORA do percentual de
// canal e aparecem em linha separada, para nao poluir Meta/Instagram.
$importadosOrigem = 0;
try {
    $stImp = $pdo->prepare(
        'SELECT COUNT(DISTINCT REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, \'\'), \'(\', \'\'), \')\', \'\'), \'-\', \'\'), \' \', \'\'))
           FROM leads
          WHERE DATE(created_at) BETWEEN ? AND ?
            AND source = \'Google Agenda\''
    );
    $stImp->execute([$start, $end]);
    $importadosOrigem = (int)$stImp->fetchColumn();
} catch (Throwable $e) {
    $importadosOrigem = 0;
}

// ---------------------------------------------------------------------------
// Formatadores
// ---------------------------------------------------------------------------
$fmt = static function ($v): string {
    if ($v === null || $v === '') { return '-'; }
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
};
$fmtK = static function ($v): string {
    if ($v === null) { return '-'; }
    $n = (float)$v;
    if (abs($n) >= 1000) { return 'R$ ' . number_format($n / 1000, 1, ',', '.') . 'k'; }
    return 'R$ ' . number_format($n, 2, ',', '.');
};
$pct = static function (?float $p): string {
    return $p === null ? '-' : number_format($p, 1, ',', '.') . '%';
};
$diaMes = static function (string $iso): string {
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $iso);
    return $d ? $d->format('d/m') : $iso;
};

$saldoTot = 0.0; $temSaldo = false;
if (!empty($saldoMeta['ok']) && isset($saldoMeta['balance'])) { $saldoTot += (float)$saldoMeta['balance']; $temSaldo = true; }
if (!empty($saldoGoogle['ok']) && isset($saldoGoogle['balance']) && $saldoGoogle['balance'] !== null) { $saldoTot += (float)$saldoGoogle['balance']; $temSaldo = true; }

$leadsAds = (int)($funil['etapas'][0]['total'] ?? 0);
$realizados = (int)$agReal['realizados'];
$valorRealizado = (float)$agReal['realizados_valor'];
$spendTotal = (float)$s['spend_total'];

$cpl = ($leadsAds > 0 && $spendTotal > 0) ? $spendTotal / $leadsAds : null;
// CPA: divide so pelos atendidos COM valor (mede dinheiro, nao atendimento).
$realizadosComValor = (int)($agReal['realizados_com_valor'] ?? 0);
$cpa = ($realizadosComValor > 0 && $spendTotal > 0) ? $spendTotal / $realizadosComValor : null;
$roas = ($spendTotal > 0 && $valorRealizado > 0) ? $valorRealizado / $spendTotal : null;

$metaBaixa = (!empty($saldoMeta['ok']) && isset($saldoMeta['balance']) && (float)$saldoMeta['balance'] <= 30);
$gooBaixa = (!empty($saldoGoogle['ok']) && isset($saldoGoogle['balance']) && $saldoGoogle['balance'] !== null && (float)$saldoGoogle['balance'] <= 50);
$alertaSaldo = $metaBaixa || $gooBaixa;
?>
<style>
    .pu{--line:#e6e8ee;--ink:#101828;--ink2:#475467;--mut:#98a2b3;--ok:#079455;--okbg:#ecfdf3;--bad:#d92d20;
        --blue:#3538cd;--bluebg:#eef2ff;--r:14px,max-width:820px;margin:0 auto}
    .pu *{box-sizing:border-box}
    .pu-per{position:sticky;top:0;z-index:15;background:#f6f7f9;padding:8px 0 9px;margin:-6px 0 4px}
    /* O corte seco do ultimo chip parecia item faltando: a sombra na borda
       direita mostra que a faixa de periodos rola para o lado. */
    .pu-per-scroll{display:flex;gap:6px;overflow-x:auto;padding-bottom:3px;scrollbar-width:none;
        -webkit-mask-image:linear-gradient(90deg,#000 calc(100% - 28px),transparent);
        mask-image:linear-gradient(90deg,#000 calc(100% - 28px),transparent)}
    .pu-per-scroll::-webkit-scrollbar{display:none}
    .pu-per-scroll a{flex:0 0 auto;text-decoration:none;font-size:12px;font-weight:700;color:var(--ink2);
        background:#fff;border:1px solid var(--line);border-radius:999px;padding:7px 13px;white-space:nowrap}
    .pu-per-scroll a.on{background:#101828;color:#fff;border-color:#101828}
    .pu-per-range{margin-top:6px;font-size:11.5px;color:var(--mut);font-weight:600}

    /* Ritmo vertical uniforme entre secoes (era 15/20/24/28/32 misturados). */
    .pu-sec{margin-bottom:22px}
    .pu-sec>h2{margin:0 0 7px;font-size:11.5px;font-weight:800;color:var(--mut);
        text-transform:uppercase;letter-spacing:.05em}

    .pu-hero{display:grid;grid-template-columns:1fr;gap:8px;margin-bottom:8px}
    .pu-hc{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:13px 14px}
    .pu-hc .lbl{font-size:10.5px;font-weight:800;color:var(--mut);text-transform:uppercase;letter-spacing:.05em}
    .pu-hc .val{font-size:26px;font-weight:800;margin-top:4px;letter-spacing:-.02em;line-height:1.1}
    .pu-hc .sub{font-size:11px;color:var(--mut);margin-top:3px}
    .pu-hc.good{background:linear-gradient(135deg,#f4fdf7,#eafaf0);border-color:#a6f4c5}
    .pu-hc.good .val{color:var(--ok)}
    .pu-hc .val.bad{color:var(--bad)}
    .pu-hc .val.good{color:var(--ok)}

    .pu-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
    .pu-m{background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px 11px;display:block;
        text-decoration:none;color:inherit}
    .pu-m .lbl{font-size:10px;font-weight:800;color:var(--mut);text-transform:uppercase;letter-spacing:.04em;
        white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    /* Icone discreto antes do rotulo: ajuda a bater o olho e achar o card. */
    .pu .lbl i{font-size:10px;opacity:.62;margin-right:2px}
    .pu-m .val{font-size:19px;font-weight:800;margin-top:3px;letter-spacing:-.01em}
    .pu-m .sub{font-size:10.5px;color:var(--mut);margin-top:2px}
    .pu-m .val.bad{color:var(--bad)}
    a.pu-m:active{background:#fafbfc}
    /* Card com dois destinos: o numero abre a lista, o rodape abre a tela */
    .pu-m .pu-hit{display:block;text-decoration:none;color:inherit}
    .pu-m .pu-go{display:inline-block;margin-top:7px;font-size:10.5px;font-weight:700;color:var(--blue);
        text-decoration:none;border-bottom:1px dashed #c7d2fe;line-height:1.3}
    .pu-m .pu-go:active{color:#1d4ed8}
    .pu-link{color:inherit;text-decoration:none}
    td.pu-cel a{color:var(--blue);text-decoration:none;font-weight:700}
    td.pu-cel a:active{text-decoration:underline}

    .pu-funil{display:flex;flex-direction:column;gap:6px}
    /* Cada etapa e um <details>: resumo sempre visivel, lista expande.
       Sem JS - o shell ja usa esse padrao no menu mobile. */
    .pu-fx{background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden}
    .pu-fx>summary{display:flex;align-items:center;gap:9px;padding:10px 12px;cursor:pointer;list-style:none}
    .pu-fx>summary::-webkit-details-marker{display:none}
    .pu-fx>summary:active{background:#fafbfc}
    .pu-fx .bar{height:22px;border-radius:6px;background:linear-gradient(90deg,#4c6ef5,#22b8cf);flex:0 0 auto;min-width:4px}
    .pu-fx .txt{flex:1 1 auto;min-width:0}
    .pu-fx .nm{font-size:13px;font-weight:700;color:var(--ink);display:block}
    .pu-fx .ds{font-size:10.5px;color:var(--mut);display:block}
    .pu-fx .n{font-size:19px;font-weight:800;flex:0 0 auto;letter-spacing:-.01em}
    .pu-fx .pc{font-size:10.5px;color:var(--mut);font-weight:700;flex:0 0 auto;min-width:46px;text-align:right}
    .pu-fx .chev{flex:0 0 auto;color:var(--mut);font-size:11px;transition:transform .15s}
    .pu-fx[open] .chev{transform:rotate(180deg)}
    .pu-fx.tat{background:var(--okbg);border-color:#a6f4c5}
    .pu-fx.tat .bar{background:linear-gradient(90deg,#12b76a,#079455)}
    /* Lista de quem esta na etapa (decisao do dono: o accordion abre a lista). */
    .pu-fx-list{border-top:1px solid var(--line);padding:8px 12px 11px;display:flex;flex-direction:column;gap:6px;background:#fff}
    .pu-fx-item{display:flex;align-items:center;gap:8px;padding:5px 0;text-decoration:none;color:inherit}
    .pu-fx-item+.pu-fx-item{border-top:1px solid #f4f6f8}
    .pu-fx-item .qn{font-size:12.5px;font-weight:600;color:var(--ink);flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .pu-fx-item .qt{font-size:11px;color:var(--mut);flex:0 0 auto}
    /* Tooltip clicavel "como e calculada" - sem JS, via CSS + atributo. */
    .pu-help{display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;
             margin-left:4px;border:1px solid var(--line);border-radius:50%;background:#fff;
             font-size:9.5px;font-weight:800;color:var(--mut);cursor:help;padding:0;vertical-align:middle}
    .pu-help:focus{outline:2px solid #bfdbfe;outline-offset:1px}

    .pu-tbl{background:#fff;border:1px solid var(--line);border-radius:var(--r);overflow:hidden;overflow-x:auto}
    .pu table{width:100%;border-collapse:collapse}
    .pu th{background:#f9fafb;text-align:left;padding:8px 10px;font-size:10.5px;font-weight:800;color:var(--ink2);
        text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}
    .pu td{padding:9px 10px;border-top:1px solid #f2f4f7;font-size:13px;color:#344054;white-space:nowrap}
    /* Primeira coluna pode quebrar (rotulo longo nao pode empurrar a tabela para
       fora da tela e esconder a ultima coluna), mas so no espaco: sem o
       overflow-wrap:normal a regra global do app.css parte a palavra no meio
       ("Importado" / "s") e parece defeito. */
    .pu-tbl td:first-child,.pu-tbl th:first-child{white-space:normal;overflow-wrap:normal;word-break:normal}
    @media (max-width:430px){
        .pu td,.pu th{padding:8px 7px;font-size:12px}
        .pu-tbl td:first-child{min-width:0}
    }
    .pu td.n,.pu th.n{text-align:right}
    .pu tr.hi td{background:#f6fdf9}
    /* Rodape de total: fecha a soma das colunas. */
    .pu tfoot td{background:#f9fafb;font-weight:800;color:var(--ink);border-top:2px solid var(--line)}
    /* Linha separada: importados nao entram no percentual de canal. */
    .pu tfoot.imp td{background:#fff;font-weight:600;color:var(--muted);font-style:italic;border-top:1px dashed var(--line)}
    .pu .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:800}
    .pu .pill.meta{background:var(--bluebg);color:var(--blue)}
    .pu .pill.goo{background:var(--okbg);color:var(--ok)}
    .pu-vazio{background:#fff;border:1px dashed #d0d5dd;border-radius:12px;padding:18px;
        text-align:center;color:var(--mut);font-size:12.5px}
    /* Aviso compacto: linha unica, sem o vazio de antes. */
    .pu-aviso{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#fffaeb;
        border:1px solid #fedf89;border-radius:12px;padding:9px 12px;
        font-size:12px;color:#93370d;margin-bottom:14px}
    .pu-aviso i{font-size:12px}
    .pu-aviso-txt{color:#a26415}
    /* Total com peso proprio: nao compete em igualdade com os indicadores. */
    .pu-m.tot{background:linear-gradient(135deg,#f8fafc,#eef2f7);border-color:#cbd5e1}
    .pu-m.tot .val{color:var(--ink)}
        @media (min-width:560px){
        .pu-hero{grid-template-columns:repeat(3,minmax(0,1fr))}
        .pu-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
        .pu-hc .val{font-size:22px}
    }
    /* Secao colapsavel: o titulo vira o gatilho. Sem JS (<details> nativo). */
    .pu-colaps>summary{display:flex;align-items:center;gap:7px;cursor:pointer;list-style:none;
        padding:2px 0;margin:0 0 7px}
    .pu-colaps>summary::-webkit-details-marker{display:none}
    /* Mesmo padrao de titulo das outras secoes: a h2 pura ficava grande e
       destoava de "ORIGEM REAL", "GASTO POR CANAL" etc. */
    .pu-colaps>summary>h2{margin:0;flex:0 1 auto;font-size:11.5px;font-weight:800;color:var(--mut);
        text-transform:uppercase;letter-spacing:.05em;overflow-wrap:normal}
    .pu-colaps>summary .chev{color:var(--mut);font-size:11px;transition:transform .15s;margin-left:auto}
    .pu-colaps[open]>summary .chev{transform:rotate(180deg)}
    .pu-hint{font-weight:600;color:var(--mut);font-size:10.5px;margin-left:7px}
    .pu-hint::before{content:'\00b7';margin-right:7px;color:var(--line)}
    </style>

<div class="pu">

    <div class="pu-per">
        <div class="pu-per-scroll">
            <?php foreach ([1 => 'Hoje', 7 => '7 dias', 15 => '15 dias', 30 => '30 dias', 60 => '60 dias', 90 => '90 dias'] as $p => $rot): ?>
                <a class="<?= (!$custom && (int)$preset === $p) ? 'on' : '' ?>"
                   href="<?= h(app_url('studio_painel_teste', ['roi_days' => $p])) ?>"><?= h($rot) ?></a>
            <?php endforeach; ?>
            <?php $ontem = date('Y-m-d', strtotime('-1 day')); ?>
            <a class="<?= ($custom && $start === $ontem && $end === $ontem) ? 'on' : '' ?>"
               href="<?= h(app_url('studio_painel_teste', ['roi_from' => $ontem, 'roi_to' => $ontem])) ?>">Ontem</a>
        </div>
        <div class="pu-per-range"><?= h(date('d/m/Y', strtotime($start))) ?> a <?= h(date('d/m/Y', strtotime($end))) ?> - <?= (int)$diasPeriodo ?> <?= (int)$diasPeriodo === 1 ? 'dia' : 'dias' ?></div>
    </div>

    <?php if ($alertaSaldo): ?>
        <div class="pu-aviso"><i class="fa-solid fa-triangle-exclamation"></i>
            <b>Recarga necessária:</b>
            <?= $metaBaixa ? '<span class="pill meta">Meta</span> ' : '' ?><?= $gooBaixa ? '<span class="pill goo">Google</span>' : '' ?>
            <span class="pu-aviso-txt">saldo baixo — a campanha pausa sem recarga</span>
        </div>
    <?php endif; ?>

    <!-- ============ ANÚNCIOS ============ -->
    <div class="pu-sec">
        <h2>Anúncios</h2>
        <div class="pu-hero">
            <div class="pu-hc">
                <div class="lbl"><i class="fa-solid fa-sack-dollar"></i> Gasto</div>
                <div class="val"><?= h($fmt($spendTotal)) ?></div>
                <div class="sub">Meta <?= h($fmtK($s['spend_meta'])) ?> + Google <?= h($fmtK($s['spend_google'])) ?></div>
            </div>
            <div class="pu-hc <?= (!is_null($roas) && $roas >= 1) ? 'good' : '' ?>">
                <div class="lbl"><i class="fa-solid fa-hand-holding-dollar"></i> Faturado</div>
                <div class="val"><?= h($fmt($valorRealizado)) ?></div>
                <div class="sub"><?= (int)$realizados ?> tatuagem(ns) feita(s)</div>
            </div>
            <div class="pu-hc">
                <div class="lbl"><i class="fa-solid fa-chart-line"></i> ROAS</div>
                <div class="val <?= is_null($roas) ? '' : ($roas < 1 ? 'bad' : 'good') ?>">
                    <?= is_null($roas) ? '-' : number_format($roas, 2, ',', '.') . 'x' ?>
                </div>
                <div class="sub">faturado ÷ gasto</div>
            </div>
        </div>

        <div class="pu-grid">
            <div class="pu-m">
                <div class="lbl"><i class="fa-solid fa-wallet"></i> Saldo Meta</div>
                <div class="val <?= $metaBaixa ? 'bad' : 'good' ?>">
                    <?= (!empty($saldoMeta['ok']) && isset($saldoMeta['balance'])) ? h($fmtK((float)$saldoMeta['balance'])) : '-' ?>
                </div>
                <div class="sub"><?= (!empty($saldoMeta['ok'])) ? 'disponível' : h(mb_substr((string)($saldoMeta['error'] ?? 'indisponível'), 0, 34)) ?></div>
            </div>
            <div class="pu-m">
                <div class="lbl"><i class="fa-solid fa-wallet"></i> Saldo Google</div>
                <div class="val <?= $gooBaixa ? 'bad' : 'good' ?>">
                    <?= (!empty($saldoGoogle['ok']) && isset($saldoGoogle['balance']) && $saldoGoogle['balance'] !== null) ? h($fmtK((float)$saldoGoogle['balance'])) : '-' ?>
                </div>
                <div class="sub"><?= (!empty($saldoGoogle['ok'])) ? 'disponível' : 'indisponível' ?></div>
            </div>
            <div class="pu-m">
                <div class="lbl"><i class="fa-solid fa-user-plus"></i> Custo por lead</div>
                <div class="val"><?= h($fmt($cpl)) ?></div>
                <div class="sub">gasto ÷ leads de anúncio</div>
            </div>
            <div class="pu-m">
                <div class="lbl"><i class="fa-solid fa-pen-nib"></i> Custo por realizada</div>
                <div class="val"><?= h($fmt($cpa)) ?></div>
                <div class="sub">gasto ÷ tatuagens feitas</div>
            </div>
            <div class="pu-m">
                <div class="lbl"><i class="fa-solid fa-calendar-days"></i> Projeção mensal</div>
                <div class="val"><?= h($fmtK($projecao['monthly'])) ?></div>
                <div class="sub"><?= h($fmt($projecao['daily'])) ?>/dia</div>
            </div>
            <div class="pu-m tot">
                <div class="lbl"><i class="fa-solid fa-coins"></i> Total disponível</div>
                <div class="val"><?= $temSaldo ? h($fmtK($saldoTot)) : '-' ?></div>
                <div class="sub">Meta + Google</div>
            </div>
        </div>
    </div>

    <!-- ============ FUNIL ============ -->
    <div class="pu-sec">
        <h2>Funil de vendas</h2>
        <div class="pu-funil">
            <?php
            $baseEtapa = max(1, $leadsAds);
            foreach ($funil['etapas'] as $e):
                $t = (int)$e['total'];
                $larg = max(5, min(100, ($t / $baseEtapa) * 100));
                $ehTat = ($e['chave'] === 'tatuou');
                $ch = (string)$e['chave'];
                $quem = $funil['detalhes'][$ch] ?? [];
            ?>
                <details class="pu-fx <?= $ehTat ? 'tat' : '' ?>" <?= $ehTat ? 'open' : '' ?>>
                    <summary title="Ver os <?= (int)$t ?> desta etapa">
                        <span class="bar" style="width:<?= (int)round($larg * 0.55) ?>px"></span>
                        <span class="txt">
                            <span class="nm"><?= h($e['nome']) ?>
                                <button type="button" class="pu-help"
                                        data-tip="<?= h((string)($e['desc'] ?? '')) ?>"
                                        aria-label="Como esta etapa e calculada">?</button>
                            </span>
                            <span class="ds"><?= h($e['desc']) ?></span>
                        </span>
                        <span class="n"><?= (int)$t ?></span>
                        <span class="pc"><?= h($pct($e['pct_base'])) ?></span>
                        <span class="chev"><i class="fa-solid fa-chevron-down"></i></span>
                    </summary>
                    <div class="pu-fx-list">
                        <?php if (empty($quem)): ?>
                            <div class="pu-vazio">Ninguem nesta etapa no periodo.</div>
                        <?php else: ?>
                            <?php foreach ($quem as $q): ?>
                                <?php
                                    $foneQ = preg_replace('/\D+/', '', (string)($q['telefone'] ?? ''));
                                    $leadQ = 0;
                                    if ($foneQ !== '') {
                                        $leadQ = $leadIdPorFone[$foneQ] ?? 0;
                                        if ($leadQ === 0 && strlen($foneQ) >= 11) {
                                            $leadQ = $leadIdPorFone[substr($foneQ, -11)] ?? 0;
                                        }
                                    }
                                ?>
                                <?php $temLead = $leadQ > 0; ?>
                                <a class="pu-fx-item"<?= $temLead ? '' : ' data-sem-lead="1"' ?>
                                   href="<?= h($temLead ? app_url('studio_lead', ['id' => $leadQ]) : app_url('studio_people')) ?>">
                                    <span class="qn"><?= h((string)($q['nome'] ?? '(sem nome)')) ?></span>
                                    <?php if (!empty($q['telefone'])): ?>
                                        <span class="qt"><?= h((string)$q['telefone']) ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                            <a class="pu-go" href="<?= h(app_url('studio_funil', $qFunilPeriodo + ['f_etapa' => $ch])) ?>#leads-<?= h($ch) ?>">abrir no funil completo <i class="fa-solid fa-arrow-right"></i></a>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ============ AGENDA ============ -->
    <div class="pu-sec">
        <h2>Agenda</h2>
        <div class="pu-grid">
            <div class="pu-m">
                <a class="pu-hit" href="<?= h(app_url('studio_verificar_card', $qPeriodo + ['escopo' => 'marcados'])) ?>">
                    <div class="lbl"><i class="fa-solid fa-calendar-check"></i> Marcados</div>
                    <div class="val"><?= (int)$marcadosPeriodo ?></div>
                    <div class="sub">fecharam no</div>
                </a>
                <a class="pu-go" href="<?= h(app_url('studio_agenda')) ?>">abrir agenda</a>
            </div>
            <div class="pu-m">
                <a class="pu-hit" href="<?= h(app_url('studio_verificar_card', $qPeriodo + ['escopo' => 'futuros'])) ?>">
                    <div class="lbl"><i class="fa-solid fa-calendar-plus"></i> Futuros</div>
                    <div class="val"><?= (int)($futurosTotal['futuros'] ?? 0) ?></div>
                    <div class="sub">agenda daqui pra frente</div>
                </a>
                <a class="pu-go" href="<?= h(app_url('studio_agenda', ['cal_view' => 'list'])) ?>">ver na agenda</a>
            </div>
            <div class="pu-m">
                <a class="pu-hit" href="<?= h(app_url('studio_verificar_card', $qPeriodo + ['escopo' => 'realizados'])) ?>">
                    <div class="lbl"><i class="fa-solid fa-circle-check"></i> Realizados</div>
                    <div class="val"><?= (int)$realizados ?></div>
                    <div class="sub">tatuagens que aconteceram</div>
                </a>
                <a class="pu-go" href="<?= h(app_url('studio_agenda', ['cal_view' => 'list'])) ?>">ver na agenda</a>
            </div>
        </div>
    </div>

    <!-- ============ ORIGEM REAL ============ -->
    <div class="pu-sec">
        <h2>Origem real</h2>
        <?php if ($totalOrigem === 0): ?>
            <div class="pu-vazio">Sem dado de origem no período.</div>
        <?php else: ?>
            <div class="pu-tbl">
                <table>
                    <thead>
                        <tr><th>Origem</th><th class="n">Contatos</th><th class="n">Agendou</th><th class="n">Tatuou</th><th class="n">%</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($origens as $o):
                            $tot = (int)$o['total'];
                            if ($tot === 0) { continue; }
                        ?>
                            <tr class="<?= ((int)$o['tatuou'] > 0) ? 'hi' : '' ?>">
                                <td class="pu-cel"><a href="<?= h(app_url('studio_verificar_card', $qVerif + ['escopo' => 'canal', 'origem' => (string)$o['origem']])) ?>"
                                    title="Abrir as conversas deste canal"><?= h((string)$o['rotulo']) ?></a></td>
                                <td class="n"><a class="pu-link" href="<?= h(app_url('studio_verificar_card', $qVerif + ['escopo' => 'leads_origem'])) ?>"
                                    title="Ver todos os contatos rastreados"><?= (int)$tot ?></a></td>
                                <td class="n"><?= (int)$o['agendou'] ?></td>
                                <td class="n"><?= (int)$o['tatuou'] ?></td>
                                <td class="n"><?= h(number_format($tot / max(1, $totalOrigem) * 100, 1, ',', '.')) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
</tbody>
                    <tfoot>
                        <?php
                        // Rodape de total: fecha a soma das colunas (definicao 4).
                        $totAg = 0; $totTat = 0;
                        foreach ($origens as $o) {
                            if ((int)$o['total'] === 0) { continue; }
                            $totAg += (int)$o['agendou'];
                            $totTat += (int)$o['tatuou'];
                        }
                        ?>
                        <tr class="total">
                            <td>Total</td>
                            <td class="n"><?= (int)$totalOrigem ?></td>
                            <td class="n"><?= (int)$totAg ?></td>
                            <td class="n"><?= (int)$totTat ?></td>
                            <td class="n">100,0%</td>
                        </tr>
                    </tfoot>
                    <?php if ($importadosOrigem > 0): ?>
                    <tfoot class="imp">
                        <tr>
                            <td>Importados (sem rastreio)</td>
                            <td class="n"><?= (int)$importadosOrigem ?></td>
                            <td class="n">—</td>
                            <td class="n">—</td>
                            <td class="n">—</td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============ GASTO POR CANAL ============ -->
    <div class="pu-sec">
        <h2>Gasto por canal</h2>
        <div class="pu-tbl">
            <table>
                <thead><tr><th>Canal</th><th class="n">Gasto</th><th class="n">%</th></tr></thead>
                <tbody>
                    <?php $totSpend = max(0.01, $spendTotal); ?>
                    <tr>
                        <td><a class="pu-link" href="<?= h(app_url('studio_verificar_card', $qVerif + ['escopo' => 'gasto'])) ?>"><span class="pill meta">META</span></a></td>
                        <td class="n"><?= h($fmt($s['spend_meta'])) ?></td>
                        <td class="n"><?= h(number_format((float)$s['spend_meta'] / $totSpend * 100, 1, ',', '.')) ?>%</td>
                    </tr>
                    <tr>
                        <td><a class="pu-link" href="<?= h(app_url('studio_verificar_card', $qVerif + ['escopo' => 'gasto'])) ?>"><span class="pill goo">GOOGLE</span></a></td>
                        <td class="n"><?= h($fmt($s['spend_google'])) ?></td>
                        <td class="n"><?= h(number_format((float)$s['spend_google'] / $totSpend * 100, 1, ',', '.')) ?>%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============ DIA A DIA ============ -->
    <details class="pu-sec pu-colaps">
        <summary><h2>Dia a dia</h2><span class="pu-hint">ver detalhe por dia</span><span class="chev"><i class="fa-solid fa-chevron-down"></i></span></summary>
        <div class="pu-colaps-body">
        <div class="pu-tbl">
            <table>
                <thead>
                    <tr><th>Data</th><th class="n">Gasto</th><th class="n">Futuros</th><th class="n">Realiz.</th><th class="n">Valor</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($s['series']) as $d): ?>
                        <tr>
                            <td class="pu-cel"><a href="<?= h(app_url('studio_funil', ['f_de' => (string)$d['date'], 'f_ate' => (string)$d['date']])) ?>"
                                title="Abrir o funil deste dia"><?= h($diaMes((string)$d['date'])) ?></a></td>
                            <td class="n"><?= ((float)$d['spend_total'] > 0) ? h($fmt($d['spend_total'])) : '-' ?></td>
                            <td class="n"><?= (int)($d['agendados_futuros'] ?? 0) ?></td>
                            <td class="n"><?= (int)$d['realizados'] ?></td>
                            <td class="n"><?= ((float)$d['valor_realizado'] > 0) ? h($fmt($d['valor_realizado'])) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </div>
    </details>

</div>
<script>
// Vindo da pagina unificada com ?f_etapa=<chave>, abre o bloco de detalhe
// daquela etapa e rola ate ele. Sem isso, o clique cai no topo do funil.
(function () {
    var m = /[?&]f_etapa=([a-z_]+)/.exec(window.location.search);
    if (!m) { return; }
    var box = document.getElementById('leads-' + m[1]);
    if (!box) { return; }
    box.open = true;
    setTimeout(function () { box.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 60);
})();
</script>
