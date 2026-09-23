<?php
/**
 * Painel de verificacao dos cards do Retorno dos Anuncios.
 *
 * POR QUE EXISTE
 * Um numero que o dono nao consegue abrir e conferir nao serve para decidir.
 * Este endpoint recebe o que o card representa (periodo + tipo) e devolve a
 * LISTA por tras do numero, com link direto para a conversa e para o lead.
 * Assim todo card vira clicavel e verificavel em um toque, com volta facil.
 *
 * O QUE FAZ
 *   - GET idempotente, sem efeito colateral: nao escreve no banco.
 *   - Monta a lista por escopo: leads de anuncio, conversas, vendas, canal.
 *   - Para cada item, inclui conversation_id e lead_id para o link "abrir".
 *
 * SEGURANCA
 *   - Exige o mesmo contexto do painel (require_studio + admin), como a pagina
 *     de ROI, porque expoe nome e telefone de cliente.
 */
declare(strict_types=1);

if (!defined('APP_BASE_PATH')) {
    require __DIR__ . '/app/bootstrap.php';
}
// Helpers de leitura da ponte (conversas) e de casamento telefone -> lead.
require_once APP_BASE_PATH . '/app/historico_conversas.php';
require_once APP_BASE_PATH . '/app/historico_leads_map.php';

$studio = require_studio();
$pdo = studio_db($studio);

// Mesmo periodo da pagina de ROI: De/Ate tem prioridade sobre os presets.
$dateOk = static function (string $v): bool {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1
        && DateTime::createFromFormat('Y-m-d', $v) instanceof DateTime;
};
$from = trim((string)($_GET['roi_from'] ?? ''));
$to = trim((string)($_GET['roi_to'] ?? ''));
if ($dateOk($from) && $dateOk($to)) {
    if ($from > $to) { [$from, $to] = [$to, $from]; }
    $start = $from;
    $end = $to;
} else {
    $dias = (int)($_GET['roi_days'] ?? 1);
    if (!in_array($dias, [1, 7, 15, 30, 60, 90], true)) {
        $dias = 1;
    }
    $start = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));
    $end = date('Y-m-d');
}

$escopo = trim((string)($_GET['escopo'] ?? 'leads_anuncio'));

// Volta para a mesma visualizacao anterior: a pagina de ROI com o mesmo periodo.
$voltarParams = ['roi_days' => (int)($_GET['roi_days'] ?? 1), 'visto' => 1];
if ($dateOk($from) && $dateOk($to)) {
    $voltarParams = ['roi_from' => $start, 'roi_to' => $end, 'visto' => 1];
}
$voltarUrl = app_url('studio_ads_roi', $voltarParams);

/**
 * Roda uma consulta e devolve as linhas (ou [] em falha).
 */
$consultar = static function (PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
};

$titulo = '';
$subtitulo = '';
$linhas = [];

switch ($escopo) {
    // --- Leads de anuncio: a tabela por tras do card azul ---
    case 'leads_anuncio':
        $titulo = 'Leads de anúncio';
        $subtitulo = 'Contatos de anúncio rastreados no período (ponte do WhatsApp).';
        // Desde a migracao para a ponte (22/09/2026), a contagem do card vem de
        // ads_origin_hits (1 hit = 1 contato). Mostra o registro da ponte quando
        // existir; sem ponte no periodo, cai para os leads da tabela `leads`.
        $ponteLinhas = $consultar($pdo,
            'SELECT phone, origin, platform, greeting, chat_name, message_id, created_at
             FROM ads_origin_hits
             WHERE DATE(created_at) BETWEEN ? AND ?
               AND LOWER(TRIM(COALESCE(NULLIF(TRIM(platform), ""), origin))) IN ("meta", "facebook", "instagram", "google", "tiktok")
             GROUP BY phone, platform, origin
             ORDER BY created_at DESC', [$start, $end]);
        if ($ponteLinhas) {
            $linhas = array_map(static function (array $r) use ($pdo): array {
                $fone = preg_replace('/\D+/', '', (string)($r['phone'] ?? ''));
                $canal = strtolower(trim((string)($r['platform'] ?? ''))) ?: strtolower(trim((string)($r['origin'] ?? '')));
                // Liga a pessoa ao lead do CRM quando existir (mesma tolerancia de telefone).
                $lead = $fone !== '' ? historico_lead_por_telefone($pdo, $fone) : null;
                return [
                    'id' => (int)($lead['id'] ?? 0),
                    'lead_id' => (int)($lead['id'] ?? 0),
                    'name' => (string)($lead['name'] ?? $r['chat_name'] ?? ''),
                    'phone' => (string)($r['phone'] ?? ''),
                    'source' => $canal !== '' ? $canal : 'meta',
                    'created_at' => (string)($r['created_at'] ?? ''),
                    'conversation_id' => 0,
                ];
            }, $ponteLinhas);
            break;
        }
        $linhas = $consultar($pdo,
            'SELECT l.id, l.name, l.phone, l.source, l.created_at, l.status,
                    (SELECT wc.id FROM whatsapp_conversations wc WHERE wc.lead_id = l.id ORDER BY wc.id DESC LIMIT 1) AS conversation_id,
                    (SELECT COUNT(*) FROM whatsapp_messages m
                        WHERE m.conversation_id = (SELECT wc2.id FROM whatsapp_conversations wc2 WHERE wc2.lead_id = l.id ORDER BY wc2.id DESC LIMIT 1)) AS total_msgs
             FROM leads l
             WHERE DATE(l.created_at) BETWEEN ? AND ?
               AND l.source IN ("meta", "instagram", "google", "tiktok")
             ORDER BY l.created_at DESC', [$start, $end]);
        break;

    // --- Conversas iniciadas: o que o card "Conversa iniciada" conta ---
    case 'conversas':
        $titulo = 'Conversas iniciadas';
        $subtitulo = 'Conversas com 3+ mensagens recebidas no período.';
        // Fonte principal: a ponte (conversa real do WhatsApp atual).
        require_once APP_BASE_PATH . '/app/historico_conversas.php';
        $convPonte = ads_conversas_ponte_periodo($start, $end);
        if (!empty($convPonte['telefones'])) {
            $linhas = [];
            foreach (array_keys($convPonte['telefones']) as $fone) {
                $lead = historico_lead_por_telefone($pdo, (string)$fone);
                $linhas[] = [
                    'conversation_id' => 0,
                    'phone' => (string)$fone,
                    'lead_id' => (int)($lead['id'] ?? 0),
                    'lead_name' => (string)($lead['name'] ?? ''),
                ];
            }
            break;
        }
        // Sem ponte no periodo (periodo historico): usa a analise de venda do CRM.
        $linhas = $consultar($pdo,
            'SELECT cv.conversation_id, cv.phone, cv.fechou, cv.confianca, cv.valor, cv.motivo,
                    cv.origem_lead, cv.analisado_em,
                    wc.name AS conv_name, wc.lead_id,
                    l.name AS lead_name
             FROM conversa_venda cv
             LEFT JOIN whatsapp_conversations wc ON wc.id = cv.conversation_id
             LEFT JOIN leads l ON l.id = wc.lead_id
             WHERE DATE(cv.analisado_em) BETWEEN ? AND ?
               AND COALESCE(cv.modelo, "") <> "ignorada"
             ORDER BY cv.analisado_em DESC', [$start, $end]);
        break;

    // --- Vendas concretizadas: os que a IA marcou como fechado ---
    case 'vendas':
        $titulo = 'Vendas concretizadas';
        $subtitulo = 'Conversas que a IA identificou como fechamento, com o valor combinado.';
        $linhas = $consultar($pdo,
            'SELECT cv.conversation_id, cv.phone, cv.valor, cv.confianca, cv.motivo,
                    cv.origem_lead, cv.analisado_em,
                    wc.name AS conv_name, wc.lead_id,
                    l.name AS lead_name
             FROM conversa_venda cv
             LEFT JOIN whatsapp_conversations wc ON wc.id = cv.conversation_id
             LEFT JOIN leads l ON l.id = wc.lead_id
             WHERE DATE(cv.analisado_em) BETWEEN ? AND ?
               AND cv.fechou = 1
               AND COALESCE(cv.modelo, "") <> "ignorada"
             ORDER BY cv.valor DESC, cv.analisado_em DESC', [$start, $end]);
        break;

    // --- Conversas de um canal especifico (clique na tabela por origem) ---
    case 'canal':
        $origem = trim((string)($_GET['origem'] ?? ''));
        $titulo = 'Conversas do canal: ' . ($origem !== '' ? ads_origem_rotulo($origem) : 'sem origem');
        $subtitulo = 'Conversas analisadas no período para este canal de origem.';
        if ($origem === '' || $origem === 'sem_origem') {
            $linhas = $consultar($pdo,
                'SELECT cv.conversation_id, cv.phone, cv.fechou, cv.confianca, cv.valor, cv.motivo, cv.analisado_em,
                        wc.name AS conv_name, wc.lead_id, l.name AS lead_name
                 FROM conversa_venda cv
                 LEFT JOIN whatsapp_conversations wc ON wc.id = cv.conversation_id
                 LEFT JOIN leads l ON l.id = wc.lead_id
                 WHERE DATE(cv.analisado_em) BETWEEN ? AND ?
                   AND (cv.origem_lead IS NULL OR TRIM(cv.origem_lead) = "")
                   AND COALESCE(cv.modelo, "") <> "ignorada"
                 ORDER BY cv.analisado_em DESC', [$start, $end]);
        } else {
            $linhas = $consultar($pdo,
                'SELECT cv.conversation_id, cv.phone, cv.fechou, cv.confianca, cv.valor, cv.motivo, cv.analisado_em,
                        wc.name AS conv_name, wc.lead_id, l.name AS lead_name
                 FROM conversa_venda cv
                 LEFT JOIN whatsapp_conversations wc ON wc.id = cv.conversation_id
                 LEFT JOIN leads l ON l.id = wc.lead_id
                 WHERE DATE(cv.analisado_em) BETWEEN ? AND ?
                   AND LOWER(TRIM(cv.origem_lead)) = ?
                   AND COALESCE(cv.modelo, "") <> "ignorada"
                 ORDER BY cv.analisado_em DESC', [$start, $end, strtolower($origem)]);
        }
        break;

    // --- Leads por origem: TODOS os leads rastreados do periodo (nao so anuncio) ---
    case 'leads_origem':
        $titulo = 'Leads por origem';
        $subtitulo = 'Todos os contatos rastreados no período, com a origem de cada um.';
        $linhas = $consultar($pdo,
            'SELECT l.id, l.name, l.phone, l.source, l.created_at, l.status,
                    (SELECT wc.id FROM whatsapp_conversations wc WHERE wc.lead_id = l.id ORDER BY wc.id DESC LIMIT 1) AS conversation_id,
                    (SELECT COUNT(*) FROM whatsapp_messages m
                        WHERE m.conversation_id = (SELECT wc2.id FROM whatsapp_conversations wc2 WHERE wc2.lead_id = l.id ORDER BY wc2.id DESC LIMIT 1)) AS total_msgs
             FROM leads l
             WHERE DATE(l.created_at) BETWEEN ? AND ?
               AND l.source IS NOT NULL AND TRIM(l.source) <> ""
               AND LOWER(TRIM(l.source)) <> "whatsapp"
             ORDER BY l.created_at DESC', [$start, $end]);
        break;

    // --- Marcados hoje: quem FECHOU no periodo (data real de criacao do evento).
    // E o card "Marcados hoje" do painel. Usa google_created_at quando existe,
    // porque nos importados o created_at e a data da IMPORTACAO e mente.
    // Exclui compromisso pessoal/operacional, igual ao card do ROI.
    case 'marcados':
        $titulo = 'Marcados no per\u00edodo';
        $subtitulo = 'Clientes que fecharam tatuagem no per\u00edodo (data de cria\u00e7\u00e3o do agendamento).';
        $linhas = $consultar($pdo,
            'SELECT a.id, a.value, a.deposit_value, a.status, a.appointment_date, a.start_time, a.title,
                    COALESCE(a.google_created_at, a.created_at) AS criado_em,
                    l.id AS lead_id, l.name AS lead_name, l.phone AS lead_phone, l.source AS lead_source,
                    (SELECT wc.id FROM whatsapp_conversations wc WHERE wc.lead_id = l.id ORDER BY wc.id DESC LIMIT 1) AS conversation_id
             FROM appointments a
             LEFT JOIN leads l ON l.id = a.lead_id
             WHERE DATE(COALESCE(a.google_created_at, a.created_at)) BETWEEN ? AND ?
               AND YEAR(a.appointment_date) BETWEEN 2000 AND 2100
               AND (a.import_source IS NULL OR a.import_source = "" OR a.google_created_at IS NOT NULL)
               AND NOT (
                    LOWER(a.title) REGEXP "limpeza|luna|niver|aniversario|anivers\u00e1rio|casamento|inss|pericia|per\u00edcia|caf\u00e9 da manh\u00e3|cafe da manha|estorno|escola|tv vizinho|lembrar|s\u00e1bado da|sabado da|spa day|manuten\u00e7\u00e3o|manutencao"
               )
             ORDER BY COALESCE(a.google_created_at, a.created_at) DESC', [$start, $end]);
        break;

    // --- Futuros: a agenda daqui pra frente (independe do filtro, que olha pra tras).
    // Responde "quantos clientes eu tenho marcados". Mesmo corte do card do ROI.
    case 'futuros':
        $titulo = 'Agendamentos futuros';
        $subtitulo = 'Tatuagens marcadas de hoje em diante.';
        $linhas = $consultar($pdo,
            'SELECT a.id, a.value, a.deposit_value, a.status, a.appointment_date, a.start_time, a.title, a.created_at,
                    l.id AS lead_id, l.name AS lead_name, l.phone AS lead_phone, l.source AS lead_source,
                    (SELECT wc.id FROM whatsapp_conversations wc WHERE wc.lead_id = l.id ORDER BY wc.id DESC LIMIT 1) AS conversation_id
             FROM appointments a
             LEFT JOIN leads l ON l.id = a.lead_id
             WHERE a.appointment_date >= CURDATE()
               AND YEAR(a.appointment_date) BETWEEN 2000 AND 2100
               AND LOWER(TRIM(a.status)) IN ("confirmado", "pre_agendado", "agendado")
               AND NOT (
                    LOWER(a.title) REGEXP "limpeza|luna|niver|aniversario|anivers\u00e1rio|casamento|inss|pericia|per\u00edcia|caf\u00e9 da manh\u00e3|cafe da manha|estorno|escola|tv vizinho|lembrar|s\u00e1bado da|sabado da|spa day|manuten\u00e7\u00e3o|manutencao"
               )
             ORDER BY a.appointment_date ASC, a.start_time ASC', []);
        break;

    // --- Realizados: tatuagem que ACONTECEU no periodo.
    // Regra de sempre (funil_appt_aconteceu): 'cancelado' nunca conta; 'finalizado'
    // conta sempre; qualquer outro status com data+hora JA PASSADAS conta.
    case 'realizados':
        $titulo = 'Realizados';
        $subtitulo = 'Tatuagens que aconteceram no per\u00edodo.';
        $candidatos = $consultar($pdo,
            'SELECT a.id, a.value, a.deposit_value, a.status, a.appointment_date, a.start_time, a.title, a.created_at,
                    l.id AS lead_id, l.name AS lead_name, l.phone AS lead_phone, l.source AS lead_source,
                    (SELECT wc.id FROM whatsapp_conversations wc WHERE wc.lead_id = l.id ORDER BY wc.id DESC LIMIT 1) AS conversation_id
             FROM appointments a
             LEFT JOIN leads l ON l.id = a.lead_id
             WHERE a.appointment_date BETWEEN ? AND ?
               AND YEAR(a.appointment_date) BETWEEN 2000 AND 2100
             ORDER BY a.appointment_date DESC, a.start_time DESC', [$start, $end]);
        require_once APP_BASE_PATH . '/app/funil.php';
        $linhas = [];
        foreach ($candidatos as $c) {
            if (funil_appt_aconteceu((string)($c['status'] ?? ''), (string)($c['appointment_date'] ?? ''), (string)($c['start_time'] ?? ''))) {
                $linhas[] = $c;
            }
        }
        break;
    // --- Agendamentos com valor: o que compoe o card de agendamentos ---
    case 'agendamentos':
        $titulo = 'Agendamentos com valor';
        $subtitulo = 'Agendamentos criados no período, com o valor cadastrado e o status.';
        $linhas = $consultar($pdo,
            'SELECT a.id, a.value, a.deposit_value, a.status, a.appointment_date, a.start_time, a.title, a.created_at,
                    l.id AS lead_id, l.name AS lead_name, l.phone AS lead_phone, l.source AS lead_source,
                    (SELECT wc.id FROM whatsapp_conversations wc WHERE wc.lead_id = l.id ORDER BY wc.id DESC LIMIT 1) AS conversation_id
             FROM appointments a
             LEFT JOIN leads l ON l.id = a.lead_id
             WHERE DATE(a.created_at) BETWEEN ? AND ?
             ORDER BY a.created_at DESC', [$start, $end]);
        break;

    // --- Gasto no periodo: de onde saiu o dinheiro (por canal e por dia) ---
    case 'gasto':
        $titulo = 'Gasto no período';
        $subtitulo = 'Linha a linha do gasto registrado, por canal e por dia.';
        $linhas = $consultar($pdo,
            'SELECT channel, campaign_date, campaign_name, spend, leads_direct
             FROM ads_daily
             WHERE campaign_date BETWEEN ? AND ?
             ORDER BY campaign_date DESC, spend DESC', [$start, $end]);
        break;

    default:
        $titulo = 'Detalhe';
        $subtitulo = 'Escopo não reconhecido.';
}

$periodoLabel = format_date_pt($start, false) . ' a ' . format_date_pt($end, false);

render_studio_shell($titulo, $subtitulo, 'ads_roi', function () use ($titulo, $subtitulo, $linhas, $escopo, $periodoLabel, $voltarUrl) {
    echo '<style>
        .verif-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
        .verif-voltar{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:700;color:#3538cd;text-decoration:none;background:#eef2ff;border:1px solid #c7d2fe;border-radius:999px;padding:7px 14px}
        .verif-voltar:hover{background:#e0e7ff;color:#2c2fa8}
        .verif-periodo{font-size:12px;color:#667085;font-weight:600}
        .verif-count{font-size:13px;color:#475467;margin-bottom:10px}
        .verif-count b{color:#101828;font-size:17px}
        .verif-list{display:flex;flex-direction:column;gap:9px}
        .verif-item{background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:12px 14px;box-shadow:0 1px 3px rgba(16,24,40,.05)}
        .verif-item .top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap}
        .verif-item .nome{font-weight:800;font-size:15px;color:#101828;line-height:1.25}
        .verif-item .tel{font-size:12px;color:#667085;margin-top:2px}
        .verif-item .meta{font-size:12px;color:#475467;margin-top:7px;display:flex;gap:12px;flex-wrap:wrap}
        .verif-item .acoes{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
        .verif-item .acoes a{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;text-decoration:none;border-radius:10px;padding:8px 13px;border:1px solid transparent}
        .verif-abrir{background:#3538cd;color:#fff}
        .verif-abrir:hover{background:#2c2fa8;color:#fff}
        .verif-lead{background:#fff;color:#3538cd;border-color:#c7d2fe}
        .verif-lead:hover{background:#eef2ff}
        .verif-off{background:#f2f4f7;color:#98a2b3;border-color:#eaecf0;cursor:not-allowed}
        .verif-selo{display:inline-block;font-size:10px;font-weight:800;padding:2px 8px;border-radius:999px;letter-spacing:.03em}
        .verif-selo.sim{background:#ecfdf3;color:#027a48}
        .verif-selo.nao{background:#f2f4f7;color:#475467}
        .verif-vazio{background:#fff;border:1px dashed #d0d5dd;border-radius:14px;padding:26px 18px;text-align:center;color:#667085;font-size:13px}
        .verif-motivo{font-size:12px;color:#667085;margin-top:6px;font-style:italic}
    </style>';

    echo '<div class="verif-head">';
    echo '<a class="verif-voltar" href="' . h($voltarUrl) . '"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Voltar para Retorno dos Anúncios</a>';
    echo '<span class="verif-periodo">Período: ' . h($periodoLabel) . '</span>';
    echo '</div>';

    echo '<div class="verif-count"><b>' . count($linhas) . '</b> ' . (count($linhas) === 1 ? 'registro' : 'registros') . '</div>';

    if (!$linhas) {
        echo '<div class="verif-vazio">Nenhum registro neste período.<br><span style="font-size:12px">Ajuste o período na tela anterior e volte aqui.</span></div>';
        return;
    }

    echo '<div class="verif-list">';
    foreach ($linhas as $l) {
        $convId = (int)($l['conversation_id'] ?? 0);
        $leadId = (int)($l['lead_id'] ?? $l['id'] ?? 0);

        // Nome do item: cada escopo tem sua fonte natural.
        $nome = trim((string)($l['lead_name'] ?? $l['conv_name'] ?? $l['name'] ?? ''));
        if ($nome === '' && isset($l['title'])) { $nome = trim((string)$l['title']); }
        if ($nome === '' && isset($l['channel'])) {
            $nome = ads_origem_rotulo((string)$l['channel']);
        }
        if ($nome === '') { $nome = 'Registro'; }
        $tel = trim((string)($l['phone'] ?? $l['lead_phone'] ?? ''));

        echo '<div class="verif-item">';
        echo '<div class="top"><div>';
        echo '<div class="nome">' . h($nome) . '</div>';
        if ($tel !== '') { echo '<div class="tel">' . h($tel) . '</div>'; }
        echo '</div>';

        // Selo de venda, quando o escopo tiver esse dado.
        if (array_key_exists('fechou', $l)) {
            $fechou = (int)$l['fechou'] === 1;
            echo '<span class="verif-selo ' . ($fechou ? 'sim' : 'nao') . '">' . ($fechou ? 'VENDA' : 'NÃO FECHOU') . '</span>';
        }
        // Selo de status do agendamento.
        if (array_key_exists('status', $l) && isset($l['appointment_date'])) {
            echo '<span class="verif-selo nao">' . h(mb_strtoupper((string)$l['status'])) . '</span>';
        }
        echo '</div>';

        echo '<div class="meta">';
        if (!empty($l['created_at'])) {
            echo '<span><i class="fa-regular fa-calendar" aria-hidden="true"></i> ' . h(format_date_pt((string)$l['created_at'])) . '</span>';
        }
        if (!empty($l['analisado_em'])) {
            echo '<span><i class="fa-solid fa-robot" aria-hidden="true"></i> analisado ' . h(format_date_pt((string)$l['analisado_em'], false)) . '</span>';
        }
        // Data do agendamento (quando for o escopo de agendamentos).
        if (!empty($l['appointment_date'])) {
            $hora = trim((string)($l['start_time'] ?? ''));
            echo '<span><i class="fa-solid fa-calendar-check" aria-hidden="true"></i> agenda ' . h(format_date_pt((string)$l['appointment_date'], false)) . ($hora !== '' ? ' ' . h(substr($hora, 0, 5)) : '') . '</span>';
        }
        // Gasto (escopo de gasto).
        if (isset($l['spend']) && !isset($l['fechou'])) {
            echo '<span><i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i> R$ ' . number_format((float)$l['spend'], 2, ',', '.') . '</span>';
        }
        if (isset($l['campaign_date'])) {
            echo '<span><i class="fa-regular fa-calendar" aria-hidden="true"></i> ' . h(format_date_pt((string)$l['campaign_date'], false)) . '</span>';
        }
        if (isset($l['campaign_name']) && trim((string)$l['campaign_name']) !== '') {
            echo '<span><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> ' . h((string)$l['campaign_name']) . '</span>';
        }
        if (isset($l['leads_direct']) && (int)$l['leads_direct'] > 0) {
            echo '<span><i class="fa-solid fa-user-plus" aria-hidden="true"></i> ' . (int)$l['leads_direct'] . ' lead(s) direto(s)</span>';
        }
        if (isset($l['source']) && trim((string)$l['source']) !== '') {
            echo '<span><i class="fa-solid fa-tag" aria-hidden="true"></i> ' . h(ads_origem_rotulo((string)$l['source'])) . '</span>';
        }
        if (isset($l['origem_lead'])) {
            echo '<span><i class="fa-solid fa-tag" aria-hidden="true"></i> ' . h(ads_origem_rotulo((string)$l['origem_lead'])) . '</span>';
        }
        // Valor: vale tanto para venda (conversa_venda.valor) quanto para agenda (appointments.value).
        if (isset($l['valor']) && (float)$l['valor'] > 0) {
            echo '<span><i class="fa-solid fa-money-bill" aria-hidden="true"></i> R$ ' . number_format((float)$l['valor'], 2, ',', '.') . '</span>';
        }
        if (isset($l['value']) && (float)$l['value'] > 0) {
            echo '<span><i class="fa-solid fa-money-bill" aria-hidden="true"></i> R$ ' . number_format((float)$l['value'], 2, ',', '.') . '</span>';
        }
        if (isset($l['total_msgs'])) {
            echo '<span><i class="fa-regular fa-comment" aria-hidden="true"></i> ' . (int)$l['total_msgs'] . ' msg</span>';
        }
        echo '</div>';

        if (!empty($l['motivo'])) {
            echo '<div class="verif-motivo">' . h(mb_substr((string)$l['motivo'], 0, 160)) . '</div>';
        }

        // Acoes: so aparecem quando ha para onde levar o dono.
        $temAcao = ($convId > 0 || $leadId > 0);
        if ($temAcao) {
            echo '<div class="acoes">';
            if ($convId > 0) {
                echo '<a class="verif-abrir" href="' . h(app_url('studio_whatsapp_conversation', ['id' => $convId])) . '"><i class="fa-solid fa-comments" aria-hidden="true"></i> Ver conversa</a>';
            }
            if ($leadId > 0) {
                echo '<a class="verif-lead" href="' . h(app_url('studio_lead', ['id' => $leadId])) . '"><i class="fa-solid fa-user" aria-hidden="true"></i> Abrir lead</a>';
            }
            echo '</div>';
        }

        echo '</div>';
    }
    echo '</div>';
}, null);
