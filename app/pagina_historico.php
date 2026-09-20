<?php
/**
 * Página: Histórico de Conversas (studio_historico)
 *
 * Mostra o arquivo de conversas gravado pela ponte do WhatsApp, com filtros.
 * SOMENTE LEITURA: não altera nada no arquivo nem no banco.
 *
 * Registrada no menu em "Atendimento". Protegida por login + admin
 * (studio_historico está em $studioPages e $studioAdminOnlyPages).
 */
declare(strict_types=1);

// A página é incluída de dentro do index.php, que já carregou o bootstrap,
// a sessão do estúdio e a biblioteca de histórico.
$histStats = historico_estatisticas();
$histPonte = historico_ponte_online();
$histArquivo = historico_conversas_path();
$histTamanho = is_file($histArquivo) ? (int)filesize($histArquivo) : 0;

// ---- Filtros vindos da URL ----------------------------------------------
$hf = [
    'telefone' => trim((string)($_GET['h_telefone'] ?? '')),
    'nome'     => trim((string)($_GET['h_nome'] ?? '')),
    'texto'    => trim((string)($_GET['h_texto'] ?? '')),
    'origem'   => trim((string)($_GET['h_origem'] ?? '')),
    'tipo'     => trim((string)($_GET['h_tipo'] ?? '')),
    'direcao'  => trim((string)($_GET['h_direcao'] ?? '')),
    'resposta' => trim((string)($_GET['h_resposta'] ?? '')),
    'de'       => trim((string)($_GET['h_de'] ?? '')),
    'ate'      => trim((string)($_GET['h_ate'] ?? '')),
];
$hf = array_filter($hf, static fn($v) => $v !== '');

$histModo = (string)($_GET['h_modo'] ?? 'conversas');
if (!in_array($histModo, ['conversas', 'mensagens'], true)) {
    $histModo = 'conversas';
}
$histLimite = (int)($_GET['h_limite'] ?? 100);
if (!in_array($histLimite, [25, 50, 100, 200, 500], true)) {
    $histLimite = 100;
}

// Pagina atual (1-based). A lista e ordenada por "mais recente primeiro",
// entao a fatia e estavel entre requisicoes.
$histPagina = max(1, (int)($_GET['h_pagina'] ?? 1));
$histOffset = ($histPagina - 1) * $histLimite;

// Indice de contatos para o autocomplete dos filtros (telefone e nome).
$histContatos = historico_indice_contatos();

// Modo de ordenacao da lista de conversas (clicando no titulo da coluna).
$histOrdenar = strtolower((string)($_GET['h_ordenar'] ?? 'recentes'));
if (!in_array($histOrdenar, ['recentes', 'antigas', 'nome', 'nome_desc', 'msgs', 'msgs_desc', 'origem', 'origem_desc', 'resposta', 'resposta_desc'], true)) {
    $histOrdenar = 'recentes';
}

// Telefone selecionado (visão de conversa única).
$histFoneAberto = preg_replace('/\D+/', '', (string)($_GET['h_fone'] ?? ''));

// Constrói a query base preservando os filtros.
$histQueryBase = static function (array $extra = []) use ($hf, $histModo, $histLimite, $histOrdenar): string {
    $q = array_merge(['page' => 'studio_historico', 'h_modo' => $histModo, 'h_limite' => $histLimite, 'h_ordenar' => $histOrdenar], $hf, $extra);
    return http_build_query($q);
};
echo '<style>
 /* ============================================================
    Historico de Conversas - layout mobile first
    Base = celular. Ganhos de tela larga entram nos @media abaixo.
    Nenhuma classe foi removida: o PHP/JS existente segue igual.
    ============================================================ */
 .hist-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:16px}
 .hist-card{display:flex;flex-direction:column;justify-content:center;min-height:88px;background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:12px 13px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
 .hist-card .lbl{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#667085;font-weight:800}
 .hist-card .val{font-size:19px;font-weight:800;color:#101828;margin-top:5px;line-height:1.15;word-break:break-word}
 .hist-card .val span{font-size:12px!important}
 .hist-card .sub{font-size:10px;color:#98a2b3;margin-top:4px;line-height:1.35}
 .hist-card .val[style]{font-size:14px!important}
 .hist-filters{background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:13px;margin-bottom:14px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
 .hist-filters .row{display:grid;grid-template-columns:1fr;gap:11px;align-items:end}
 .hist-filters label{display:flex;flex-direction:column;gap:4px;font-size:10px;color:#475467;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
 .hist-filters input,.hist-filters select{width:100%;border:1px solid #d0d5dd;border-radius:9px;padding:10px 11px;font-size:14px;background:#fff;color:#344054;min-width:0}
 .hist-filters input:focus,.hist-filters select:focus{outline:2px solid #dbe6fb;border-color:#84adff}
 .hist-filters .hist-btn{width:100%;margin-top:2px}
 .hist-btn{background:#2f6fed;color:#fff;border:0;border-radius:10px;padding:11px 16px;font-weight:700;font-size:14px;cursor:pointer;text-decoration:none;display:inline-block;text-align:center}
 .hist-btn:hover{background:#2860d4}
 .hist-btn.sec{background:#eef2f6;color:#344054}
 .hist-btn.sec:hover{background:#e4e9f0}
 .hist-table{width:100%;border-collapse:separate;border-spacing:0;background:transparent;font-size:13px}
 .hist-table thead{display:none}
 .hist-table tr{display:grid;grid-template-columns:1fr 1fr;gap:2px 10px;background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:12px 13px;margin-bottom:10px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
 .hist-table tr:hover td{background:transparent}
 .hist-table td{display:block;min-width:0;padding:3px 0;border-bottom:0;color:#344054;vertical-align:top;overflow-wrap:anywhere}
 .hist-table td.muted{grid-column:1 / -1;font-size:12px;color:#475467;padding-top:7px;margin-top:4px;border-top:1px dashed #eaecf0}
 .hist-table td:first-child{font-size:11px;font-weight:800;color:#667085;letter-spacing:.02em;text-transform:uppercase}
 .hist-table td code{font-size:12px}
 .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap}
 .pill.ad{background:#e8f5ee;color:#0a6b3d}
 .pill.in{background:#eef2ff;color:#3538cd}
 .pill.out{background:#f4f0ff;color:#6941c6}
 .pill.audio{background:#fff4e5;color:#b54708}
 .pill.plain{background:#f2f4f7;color:#475467}
 .hist-chat-wrap{max-width:860px;margin:0 auto}
 .hist-chat-head{display:flex;flex-direction:column;align-items:flex-start;gap:8px;background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:12px 14px}
 .hist-chat-head strong{display:block;font-size:16px;color:#101828;word-break:break-word}
 .hist-chat-head small{font-size:11px;color:#667085;word-break:break-word}
 .hist-chat-status{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:4px 10px;border-radius:999px;white-space:nowrap}
 .hist-chat-status.ok{background:#e8f5ee;color:#0a6b3d}
 .hist-chat-status.off{background:#fdecea;color:#b42318}
 .hist-chat{max-width:820px;max-height:62vh;margin:10px auto 0;overflow-y:auto;padding:12px;background:#f7f8fa;border:1px solid #e6e8ee;border-radius:14px}
 .hist-msg{padding:9px 12px;border-radius:12px;margin-bottom:8px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
 .hist-msg.in{background:#fff;border:1px solid #e6e8ee}
 .hist-msg.out{background:#eef4ff;border:1px solid #dbe6fb;margin-left:22px}
 .hist-msg .meta{font-size:10px;color:#98a2b3;margin-bottom:4px;font-weight:700;letter-spacing:.02em}
 .hist-empty{background:#fff;border:1px dashed #d0d5dd;border-radius:14px;padding:24px 18px;text-align:center;color:#667085;font-size:13px}
 .hist-bar{display:flex;gap:7px;flex-wrap:nowrap;align-items:center;margin-bottom:14px;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:3px;scrollbar-width:none}
 .hist-bar::-webkit-scrollbar{display:none}
 .hist-bar a{flex:0 0 auto;padding:7px 13px;border-radius:999px;font-size:12px;font-weight:700;text-decoration:none;background:#eef2f6;color:#344054;white-space:nowrap}
 .hist-bar a.on{background:#101828;color:#fff}
 .hist-help{font-size:12px;color:#667085;margin:-4px 0 14px;line-height:1.5}
 .hist-source-inline{width:100%;font-size:12px;font-weight:700;border:1px solid #d0d5dd;border-radius:9px;padding:7px 9px;background:#fff;color:#344054;cursor:pointer;transition:background .15s,color .15s,border-color .15s}
 .hist-source-inline:disabled{opacity:.55}
 .hist-source-inline.op-sem{background:#f2f4f7;color:#667085;border-color:#e4e7ec}
 .hist-bolinha{display:inline-block;width:8px;height:8px;border-radius:999px;margin-right:5px;vertical-align:middle}
 .hist-hint{font-size:10px;color:#98a2b3;margin-top:3px;line-height:1.35}
 .hist-ac-wrap{position:relative}
 .hist-ac-list{position:absolute;z-index:40;top:100%;left:0;right:0;margin-top:3px;background:#fff;border:1px solid #d0d5dd;border-radius:10px;box-shadow:0 8px 24px rgba(16,24,40,.12);max-height:270px;overflow:auto;display:none}
 .hist-ac-list.on{display:block}
 .hist-ac-item{padding:9px 11px;font-size:13px;cursor:pointer;border-bottom:1px solid #f2f4f7;display:flex;justify-content:space-between;gap:10px}
 .hist-ac-item:last-child{border-bottom:0}
 .hist-ac-item:hover,.hist-ac-item.ativo{background:#eef4ff}
 .hist-ac-item .fone{color:#667085;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px}
 .hist-ac-vazio{padding:9px 11px;font-size:12px;color:#98a2b3}
 .hist-paginacao{display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:center;margin:16px 0 6px}
 .hist-paginacao a,.hist-paginacao span{padding:8px 12px;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;background:#eef2f6;color:#344054;min-width:40px;text-align:center}
 .hist-paginacao a:hover{background:#dfe6ef}
 .hist-paginacao .atual{background:#101828;color:#fff}
 .hist-paginacao .info{background:transparent;color:#667085;font-weight:600;min-width:0}
 .hist-resp{font-size:10px;font-weight:800;white-space:nowrap}
 .hist-dia{text-align:center;margin:12px 0 10px;font-size:10px;color:#98a2b3;font-weight:800;text-transform:uppercase;letter-spacing:.05em}
 .hist-msg-nova{outline:2px solid #dbe6fb}
 .hist-composer{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:10px;margin:10px auto 0;max-width:820px}
 .hist-composer textarea{flex:1 1 100%;min-width:0;border:1px solid #d0d5dd;border-radius:10px;padding:10px 12px;font-size:14px;font-family:inherit;resize:none;max-height:160px;line-height:1.45}
 .hist-composer textarea:disabled{background:#f2f4f7;color:#98a2b3}
 .hist-composer .hist-send{flex:1 1 auto;background:#2f6fed;color:#fff;border:0;border-radius:10px;padding:11px 18px;font-weight:700;font-size:14px;cursor:pointer}
 .hist-composer .hist-send:hover{background:#2860d4}
 .hist-composer .hist-send:disabled{background:#c3cbd6;cursor:not-allowed}
 .hist-composer-hint{font-size:10px;color:#98a2b3;margin-top:7px;text-align:center}
 .pill-crm{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;background:#eef4ff;color:#2f6fed}
 .hist-file-input{display:none}
 .hist-compose-tools{display:flex;gap:2px;align-items:center;padding-bottom:2px}
 .hist-compose-tools button{border:0;background:transparent;font-size:20px;line-height:1;cursor:pointer;padding:7px 8px;border-radius:10px;color:#475467}
 .hist-compose-tools button:hover{background:#eef2f6}
 .hist-compose-tools button:disabled{opacity:.4;cursor:not-allowed}
 .hist-compose-tools button.gravando{background:#fdecea;color:#b42318;animation:histPulsa 1s infinite}
 @keyframes histPulsa{0%,100%{opacity:1}50%{opacity:.45}}
 .hist-msg-acoes{display:flex;gap:8px;margin-top:5px;opacity:1;transition:opacity .12s}
 .hist-msg-acoes button{border:0;background:transparent;font-size:11px;color:#98a2b3;cursor:pointer;padding:3px 5px;font-weight:700}
 .hist-msg-acoes button:hover{color:#2f6fed}
 .hist-citacao{display:flex;justify-content:space-between;align-items:center;gap:10px;background:#fff;border:1px solid #e6e8ee;border-left:3px solid #2f6fed;border-radius:10px;padding:9px 12px;margin:10px auto 0;max-width:820px}
 .hist-citacao span{font-size:10px;font-weight:800;color:#2f6fed;text-transform:uppercase;letter-spacing:.04em}
 .hist-citacao p{margin:2px 0 0;font-size:12px;color:#475467}
 .hist-anexo{background:#eef4ff;border:1px solid #dbe6fb;border-radius:10px;padding:9px 12px;margin-top:8px;font-size:12px;color:#2f6fed;font-weight:600;word-break:break-word}
 .hist-emoji{display:grid;grid-template-columns:repeat(auto-fill,minmax(40px,1fr));gap:2px;background:#fff;border:1px solid #e6e8ee;border-radius:12px;padding:8px;margin-top:8px;max-height:210px;overflow-y:auto}
 .hist-emoji button{border:0;background:transparent;font-size:21px;cursor:pointer;padding:5px;border-radius:8px}
 .hist-emoji button:hover{background:#eef2f6}
 .hist-figurinhas{display:grid;grid-template-columns:repeat(auto-fill,minmax(66px,1fr));gap:8px;background:#fff;border:1px solid #e6e8ee;border-radius:12px;padding:10px;margin-top:8px;max-height:210px;overflow-y:auto}
 .hist-figurinhas img{width:100%;height:62px;object-fit:contain;cursor:pointer;border:1px solid #eaecf0;border-radius:10px;padding:3px;background:#f9fafb}
 .hist-figurinhas img:hover{border-color:#2f6fed;background:#eef4ff}
 .hist-fig-vazio{font-size:12px;color:#98a2b3;padding:6px}
 .hist-rapidas{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:9px auto 0;max-width:820px}
 .hist-rapidas span{font-size:10px;font-weight:800;color:#667085;text-transform:uppercase;letter-spacing:.03em}
 .hist-rapidas button{border:1px solid #d0d5dd;background:#fff;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:600;color:#344054;cursor:pointer}
 .hist-rapidas button:hover{border-color:#2f6fed;color:#2f6fed}
 .hidden{display:none!important}
 .hist-table th a{color:#475467;text-decoration:none;display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
 .hist-table th a:hover{color:#101828}
 .hist-table th .seta{color:#98a2b3;font-size:9px}
 .hist-table th.on a{color:#2f6fed}
 .hist-table th.on .seta{color:#2f6fed}

 /* --- Telas maiores: a partir daqui volta a densidade de desktop --- */
 @media (min-width:600px){
  .hist-cards{grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .hist-card{min-height:0;padding:13px 15px}
  .hist-card .val{font-size:21px}
  .hist-filters .row{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .hist-bar{flex-wrap:wrap;overflow-x:visible}
  .hist-table tr{grid-template-columns:repeat(2,minmax(0,1fr))}
 }
 @media (min-width:900px){
  .hist-cards{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}
  .hist-filters .row{display:flex;flex-wrap:wrap;align-items:flex-end}
  .hist-filters label{flex:1 1 140px}
  .hist-filters .hist-btn{width:auto}
  .hist-table{display:table;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden;table-layout:auto}
  .hist-table thead{display:table-header-group}
  .hist-table tr{display:table-row;background:transparent;border:0;border-radius:0;padding:0;margin:0;box-shadow:none}
  .hist-table th{background:#f9fafb;text-align:left;padding:9px 11px;color:#475467;font-weight:700;border-bottom:1px solid #eaecf0;font-size:11px;text-transform:uppercase;white-space:nowrap}
  .hist-table td{display:table-cell;padding:8px 11px;border-bottom:1px solid #f2f4f7;color:#344054;vertical-align:top;font-size:13px}
  .hist-table td:first-child{font-size:13px;font-weight:400;color:#344054;text-transform:none;letter-spacing:0}
  .hist-table td.muted,.hist-table td:last-child{font-size:13px;padding-top:8px;margin-top:0;border-top:0}
  .hist-table td.muted{color:#667085}
  .hist-table td.muted{color:#667085}
  .hist-table td:last-child{grid-column:auto}  .hist-table tr:hover td{background:#fcfcfd}
  .hist-source-inline{width:auto;max-width:190px;font-size:11px;border-radius:999px;padding:2px 8px}
  .hist-msg.out{margin-left:44px}
  .hist-msg-acoes{opacity:0}
  .hist-msg:hover .hist-msg-acoes{opacity:1}
  .hist-chat-head{flex-direction:row;align-items:center;justify-content:space-between}
  .hist-composer{flex-wrap:nowrap}
  .hist-composer textarea{flex:1 1 auto}
  .hist-composer .hist-send{flex:0 0 auto}
 }
</style>';

render_studio_shell(
    'Histórico de Conversas',
    'Todas as mensagens arquivadas do WhatsApp — com origem, transcrição de áudio e busca.',
    'historico',
    function () use ($studio, $histStats, $histPonte, $histArquivo, $histTamanho, $hf, $histModo, $histLimite, $histFoneAberto, $histQueryBase, $histPagina, $histOffset, $histContatos, $histOrdenar) {

        $escreve = static fn($v) => is_string($v) ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : (string)$v;

        // Token CSRF para as acoes AJAX desta pagina (o seletor de origem).
        if (function_exists('csrf_token')) {
            echo '<input type="hidden" name="csrf_token" value="' . $escreve(csrf_token()) . '">';
        }

        // ---- Aviso de estado da ponte -----------------------------------
        echo '<div class="hist-cards">';
        echo '<div class="hist-card"><div class="lbl">Ponte</div><div class="val" style="color:' . ($histPonte ? '#079455' : '#d92d20') . '">' . ($histPonte ? 'No ar' : 'Parada') . '</div><div class="sub">gravando agora</div></div>';
        echo '<div class="hist-card"><div class="lbl">Mensagens</div><div class="val">' . (int)$histStats['total'] . '</div><div class="sub">' . (int)$histStats['recebidas'] . ' recebidas · ' . (int)$histStats['enviadas'] . ' enviadas</div></div>';
        echo '<div class="hist-card"><div class="lbl">Conversas</div><div class="val">' . (int)($histStats['conversas'] ?? 0) . '</div><div class="sub">contatos distintos</div></div>';
        echo '<div class="hist-card"><div class="lbl">Com origem</div><div class="val" style="color:#3538cd">' . (int)$histStats['com_origem'] . '</div><div class="sub">' . (int)($histStats['conversas_com_origem'] ?? 0) . ' contatos de anúncio</div></div>';
        echo '<div class="hist-card"><div class="lbl">Áudios</div><div class="val">' . (int)($histStats['autos_transcritos'] ?? 0) . '<span style="font-size:12px;color:#98a2b3">/' . (int)$histStats['audios'] . '</span></div><div class="sub">transcritos</div></div>';
        echo '<div class="hist-card"><div class="lbl">Período</div><div class="val" style="font-size:14px">' . ($histStats['primeira'] !== '' ? $escreve(date('d/m', strtotime($histStats['primeira'])) . ' – ' . date('d/m', strtotime($histStats['ultima']))) : '—') . '</div><div class="sub">' . number_format($histTamanho / 1024, 1, ',', '.') . ' KB</div></div>';
        echo '</div>';

        if (!$histPonte) {
            echo '<div class="alert alert-warning" style="font-size:13px">A ponte do WhatsApp está <b>parada</b>, então nada novo está sendo gravado agora. O que já foi arquivado continua aqui. A tarefa agendada <code>ProjetoCRM WhatsApp Origin Bridge</code> reinicia o serviço automaticamente.</div>';
        }

        // ---- Modos ------------------------------------------------------
        echo '<div class="hist-bar">';
        echo '<a class="' . ($histModo === 'conversas' ? 'on' : '') . '" href="?' . $escreve($histQueryBase(['h_modo' => 'conversas', 'h_fone' => '', 'h_pagina' => 1])) . '">Por conversa</a>';
        echo '<a class="' . ($histModo === 'mensagens' ? 'on' : '') . '" href="?' . $escreve($histQueryBase(['h_modo' => 'mensagens', 'h_fone' => '', 'h_pagina' => 1])) . '">Todas as mensagens</a>';
        echo '<a class="" href="' . $escreve('?' . $histQueryBase(['h_origem' => 'com', 'h_fone' => '', 'h_pagina' => 1])) . '" title="Somente conversas de anúncio">Só origem de anúncio</a>';
        echo '<a class="" href="' . $escreve('?' . $histQueryBase(['h_origem' => 'sem', 'h_fone' => '', 'h_pagina' => 1])) . '" title="Sem origem detectada">Sem origem</a>';
        echo '<a class="" href="' . $escreve('?' . $histQueryBase(['resposta' => 'sem_resposta', 'h_fone' => '', 'h_pagina' => 1])) . '" title="Cliente falou por ultimo">Sem resposta</a>';
        echo '<a class="" href="?' . $escreve(http_build_query(['page' => 'studio_historico'])) . '" title="Limpar tudo">Limpar filtros</a>';
        echo '</div>';

        // ---- Filtros ----------------------------------------------------
        echo '<form class="hist-filters" method="get">';
        echo '<input type="hidden" name="page" value="studio_historico">';
        echo '<input type="hidden" name="h_modo" value="' . $escreve($histModo) . '">';
        echo '<div class="row">';
        echo '<label>Telefone<div class="hist-ac-wrap"><input name="h_telefone" id="h_telefone" autocomplete="off" value="' . $escreve($hf['telefone'] ?? '') . '" placeholder="11999 ou 5511..."><div class="hist-ac-list" id="ac_telefone"></div></div></label>';
        echo '<label>Nome<div class="hist-ac-wrap"><input name="h_nome" id="h_nome" autocomplete="off" value="' . $escreve($hf['nome'] ?? '') . '" placeholder="parte do nome"><div class="hist-ac-list" id="ac_nome"></div></div></label>';
        echo '<label>Texto / transcrição<input name="h_texto" value="' . $escreve($hf['texto'] ?? '') . '" placeholder="palavra na conversa"></label>';
        echo '<label>Origem<select name="h_origem">';
        foreach (['' => 'Todas', 'com' => 'Com origem', 'sem' => 'Sem origem', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'meta' => 'Meta'] as $val => $lbl) {
            echo '<option value="' . $escreve($val) . '"' . (($hf['origem'] ?? '') === $val ? ' selected' : '') . '>' . $escreve($lbl) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Tipo<select name="h_tipo">';
        foreach (['' => 'Todos', 'text' => 'Texto', 'audio' => 'Áudio', 'audio_transcrito' => 'Áudio transcrito', 'image' => 'Imagem', 'video' => 'Vídeo', 'document' => 'Documento'] as $val => $lbl) {
            echo '<option value="' . $escreve($val) . '"' . (($hf['tipo'] ?? '') === $val ? ' selected' : '') . '>' . $escreve($lbl) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Direção<select name="h_direcao">';
        foreach (['' => 'Ambas', 'recebida' => 'Recebidas', 'enviada' => 'Enviadas'] as $val => $lbl) {
            echo '<option value="' . $escreve($val) . '"' . (($hf['direcao'] ?? '') === $val ? ' selected' : '') . '>' . $escreve($lbl) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Resposta<select name="h_resposta">';
        foreach (['' => 'Todas', 'respondida' => 'Respondidas', 'sem_resposta' => 'Sem resposta'] as $val => $lbl) {
            echo '<option value="' . $escreve($val) . '"' . (($hf['resposta'] ?? '') === $val ? ' selected' : '') . '>' . $escreve($lbl) . '</option>';
        }
        echo '</select></label>';
        echo '<label>De<input type="date" name="h_de" value="' . $escreve($hf['de'] ?? '') . '"></label>';
        echo '<label>Até<input type="date" name="h_ate" value="' . $escreve($hf['ate'] ?? '') . '"></label>';
        echo '<label>Mostrar<select name="h_limite">';
        foreach ([25, 50, 100, 200, 500] as $n) {
            echo '<option value="' . $n . '"' . ($histLimite === $n ? ' selected' : '') . '>' . $n . '</option>';
        }
        echo '</select></label>';
        echo '<button class="hist-btn" type="submit">Filtrar</button>';
        echo '</div>';
        echo '</form>';

        // JavaScript do seletor de origem.
        // ATENCAO: este bloco PRECISA ficar ANTES dos `return;` das visoes,
        // senao o script nunca e emitido. E precisa estar dentro do callback,
        // porque o render_studio_shell fecha </body></html> no fim.
        ?>
        <script>
        (function () {
          function csrf() {
            var el = document.querySelector('input[name="csrf_token"]');
            return el ? el.value : '';
          }

          // ---- Autocomplete de telefone e nome ----------------------------
          var contatos = <?php echo json_encode(array_values($histContatos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

          function semAcento(v) {
            return String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
          }
          function montarAc(inputId, listId, tipo) {
            var input = document.getElementById(inputId);
            var lista = document.getElementById(listId);
            if (!input || !lista) return;
            var ativo = -1;
            var itens = [];

            function fechar() { lista.classList.remove('on'); ativo = -1; }
            function desenhar(filtro) {
              var alvo = semAcento(filtro);
              itens = contatos.filter(function (c) {
                if (!alvo) return true;
                if (tipo === 'fone') return String(c.phone || '').indexOf(String(filtro).replace(/\D+/g, '')) !== -1;
                return semAcento(c.name).indexOf(alvo) !== -1;
              }).slice(0, 40);

              if (!itens.length) {
                lista.innerHTML = '<div class="hist-ac-vazio">nada encontrado</div>';
                lista.classList.add('on');
                return;
              }
              lista.innerHTML = itens.map(function (c, i) {
                var nome = c.name ? c.name : '(sem nome)';
                return '<div class="hist-ac-item" data-i="' + i + '"><span>' + nome.replace(/[&<>\"]/g, function (ch) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]; }) + '</span><span class="fone">' + c.phone + '</span></div>';
              }).join('');
              lista.classList.add('on');
            }
            function escolher(i) {
              var c = itens[i];
              if (!c) return;
              // Preenche o filtro que o usuario esta usando; o outro fica, se vazio, completo.
              input.value = tipo === 'fone' ? c.phone : (c.name || c.phone);
              fechar();
            }
            input.addEventListener('focus', function () { desenhar(input.value); });
            input.addEventListener('input', function () { desenhar(input.value); });
            input.addEventListener('keydown', function (ev) {
              if (!lista.classList.contains('on')) {
                if (ev.key === 'ArrowDown') { desenhar(input.value); ev.preventDefault(); }
                return;
              }
              if (ev.key === 'ArrowDown') { ativo = Math.min(ativo + 1, itens.length - 1); }
              else if (ev.key === 'ArrowUp') { ativo = Math.max(ativo - 1, 0); }
              else if (ev.key === 'Enter') { if (ativo >= 0) { escolher(ativo); ev.preventDefault(); } return; }
              else if (ev.key === 'Escape') { fechar(); return; }
              else { return; }
              ev.preventDefault();
              Array.prototype.forEach.call(lista.querySelectorAll('.hist-ac-item'), function (el, i) {
                el.classList.toggle('ativo', i === ativo);
              });
            });
            lista.addEventListener('mousedown', function (ev) {
              var el = ev.target.closest('.hist-ac-item');
              if (!el) return;
              ev.preventDefault();
              escolher(parseInt(el.getAttribute('data-i'), 10));
            });
            document.addEventListener('click', function (ev) {
              if (!ev.target.closest('.hist-ac-wrap')) fechar();
            });
          }
          montarAc('h_telefone', 'ac_telefone', 'fone');
          montarAc('h_nome', 'ac_nome', 'nome');

          // ---- Cores das origens (tinta o seletor pela origem escolhida) ---
          // Mapa codigo -> cor, montado no servidor a partir do catalogo unico.
          var CORES_ORIGEM = <?php
            $coresMapa = [];
            foreach (ads_origem_catalogo() as $cod => $info) {
                $coresMapa[$cod] = (string)$info[2];
            }
            echo json_encode($coresMapa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          ?>;

          function pintarOrigem(sel) {
            var cod = sel.value;
            var cor = CORES_ORIGEM[cod];
            if (!cod || !cor) {
              sel.classList.add('op-sem');
              sel.style.background = '';
              sel.style.color = '';
              sel.style.borderColor = '';
              sel.setAttribute('data-cor', '#98a2b3');
              return;
            }
            sel.classList.remove('op-sem');
            // Fundo claro (cor com transparencia) e texto na cor cheia: legivel
            // sem depender de calculo de contraste.
            sel.style.background = cor + '1a';
            sel.style.color = cor;
            sel.style.borderColor = cor + '66';
            sel.setAttribute('data-cor', cor);
          }
          document.querySelectorAll('.hist-source-inline').forEach(function (sel) {
            pintarOrigem(sel);
            sel.addEventListener('change', function () { pintarOrigem(sel); });
          });

          document.querySelectorAll('.hist-source-inline').forEach(function (sel) {
            var anterior = sel.value;
            sel.addEventListener('change', function () {
              var leadId = sel.getAttribute('data-lead-source') || '';
              var leadPhone = sel.getAttribute('data-lead-phone') || '';
              if (!leadId && !leadPhone) return;
              sel.disabled = true;
              sel.style.borderColor = '#f5c542';

              var body = new URLSearchParams();
              body.set('action', leadId ? 'set_lead_source' : 'create_lead_source');
              if (leadId) {
                body.set('lead_id', leadId);
              } else {
                body.set('phone', leadPhone);
                body.set('name', sel.getAttribute('data-lead-name') || '');
              }
              body.set('source', sel.value);
              body.set('inline', '1');
              body.set('csrf_token', csrf());

              fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: {
                  'X-Requested-With': 'XMLHttpRequest',
                  'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body.toString()
              })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                  if (!json.ok) throw new Error(json.error || 'Falha ao salvar');
                  sel.style.borderColor = '#079455';
                  // Se acabou de criar o lead, o seletor passa a apontar para ele
                  // e o aviso deixa de dizer que precisa criar.
                  if (json.lead_id && !sel.getAttribute('data-lead-source')) {
                    sel.setAttribute('data-lead-source', String(json.lead_id));
                    sel.removeAttribute('data-lead-phone');
                    var hint = sel.parentNode ? sel.parentNode.querySelector('.hist-hint') : null;
                    if (hint) hint.textContent = 'lead #' + json.lead_id + ' criado';
                  }
                  setTimeout(function () { pintarOrigem(sel); }, 1600);
                })
                .catch(function (err) {
                  sel.value = anterior;
                  pintarOrigem(sel);
                  sel.style.borderColor = '#d92d20';
                  alert(err.message || 'Nao foi possivel salvar a origem');
                })
                .finally(function () {
                  sel.disabled = false;
                });
            });
          });
        })();
        </script>
        <?php

        // ---- Conversa aberta: CHAT (le e responde) -------------------
        if ($histFoneAberto !== '') {
            // Junta arquivo da ponte + banco do CRM, em ordem cronologica.
            $itens = historico_mensagens_unificadas($studio, $histFoneAberto, 500);
            $totalItens = count($itens);
            $nomeConversa = '';
            foreach ($itens as $m) {
                if (trim((string)($m['name'] ?? '')) !== '') {
                    $nomeConversa = (string)$m['name'];
                    break;
                }
            }
            $leadDoFone = null;
            try {
                $leadDoFone = historico_lead_por_telefone(studio_db($studio), $histFoneAberto);
            } catch (Throwable $e) {
                $leadDoFone = null;
            }
            $ponteOk = $histPonte;
            $semTelefoneReal = !historico_telefone_utilizavel($histFoneAberto);

            echo '<p><a class="hist-btn sec" href="?' . $escreve($histQueryBase(['h_fone' => ''])) . '">&larr; Voltar para a lista</a></p>';

            // Cabecalho do chat.
            echo '<div class="hist-chat-wrap">';
            echo '<div class="hist-chat-head">';
            echo '<div><strong>' . $escreve($nomeConversa !== '' ? $nomeConversa : $histFoneAberto) . '</strong>';
            echo '<small><code>' . $escreve($histFoneAberto) . '</code> · ' . $totalItens . ' mensagem(ns)';
            if ($leadDoFone) {
                echo ' · <a href="' . $escreve(app_url('studio_lead', ['id' => (int)$leadDoFone['id']])) . '">lead #' . (int)$leadDoFone['id'] . '</a>';
            }
            echo '</small></div>';
            echo '<span class="hist-chat-status ' . ($ponteOk ? 'ok' : 'off') . '">' . ($ponteOk ? 'ponte no ar' : 'ponte parada') . '</span>';
            echo '</div>';

            if (!$ponteOk) {
                echo '<div class="alert alert-warning" style="font-size:13px">A ponte está <b>parada</b>: dá para ler o histórico, mas o envio não vai funcionar agora.</div>';
            }
            if ($semTelefoneReal) {
                echo '<div class="alert alert-warning" style="font-size:13px">Este contato só tem ID interno do WhatsApp (sem número real): não é possível responder.</div>';
            }

            echo '<div class="hist-chat" id="histChat">';
            if (!$itens) {
                echo '<div class="hist-empty">Nenhuma mensagem encontrada para este telefone.</div>';
            }
            $diaAnterior = '';
            foreach ($itens as $m) {
                $out = !empty($m['from_me']);
                $quando = (string)($m['sent_at'] ?? $m['at'] ?? '');
                $ts = $quando !== '' ? date('d/m H:i', strtotime($quando)) : '';
                $dia = $quando !== '' ? date('Y-m-d', strtotime($quando)) : '';
                // Separador de dia, como em um chat de verdade.
                if ($dia !== '' && $dia !== $diaAnterior) {
                    $diaAnterior = $dia;
                    echo '<div class="hist-dia">' . $escreve(date('d/m/Y', strtotime($quando))) . '</div>';
                }
                $tags = '';
                if (!empty($m['origin'])) {
                    $tags .= '<span class="pill ad">anúncio ' . $escreve((string)($m['origin']['platform'] ?? '')) . '</span> ';
                }
                $tipo = strtolower((string)($m['media_type'] ?? ''));
                if ($tipo === 'audio') {
                    $tags .= '<span class="pill audio">áudio' . (!empty($m['transcription']) ? ' transcrito' : '') . '</span> ';
                } elseif ($tipo !== 'text' && $tipo !== '') {
                    $tags .= '<span class="pill plain">' . $escreve($tipo) . '</span> ';
                }
                if (($m['fonte'] ?? '') === 'crm') {
                    $tags .= '<span class="pill-crm">pelo CRM</span> ';
                }

                $texto = (string)($m['text'] ?? '');
                if ($texto === '' && !empty($m['transcription'])) {
                    $texto = (string)$m['transcription'];
                }
                if ($texto === '') {
                    $texto = '[' . ($tipo !== '' ? $tipo : 'mensagem') . ']';
                }

                echo '<div class="hist-msg ' . ($out ? 'out' : 'in') . '">';
                echo '<div class="meta">' . $escreve($ts) . ' · ' . ($out ? 'atendimento' : 'cliente') . ' ' . $tags . '</div>';
                echo $escreve($texto);
                if (!empty($m['origin']['source_id'])) {
                    echo '<div class="meta" style="margin-top:6px">ID do anúncio: ' . $escreve((string)$m['origin']['source_id']) . '</div>';
                }
                // Acao da bolha: responder citando. (Salvar figurinha fica no
                // painel, a partir da galeria do CRM: o arquivo da ponte nao
                // guarda stickers, so o texto/transcricao e os audios.)
                $previewCitacao = mb_substr($texto, 0, 120);
                echo '<div class="hist-msg-acoes">';
                echo '<button type="button" data-citar="' . $escreve((string)($m['message_id'] ?? '')) . '" data-preview="' . $escreve($previewCitacao) . '" title="Responder citando">responder</button>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';

            // Composer completo: responde pelo Baileys (o numero que recebeu).
            $podeEnviar = $ponteOk && !$semTelefoneReal;
            $respostasRapidas = [];
            $figurinhas = [];
            $usuarioAtual = current_studio_user();
            $usuarioAtualId = is_array($usuarioAtual) ? (int)($usuarioAtual['id'] ?? 0) : 0;
            try {
                if (function_exists('studio_list_quick_replies')) {
                    $respostasRapidas = array_values(array_filter(
                        studio_list_quick_replies($studio),
                        static fn(array $r): bool => !empty($r['is_active'])
                    ));
                }
            } catch (Throwable $e) {
                $respostasRapidas = [];
            }
            try {
                if ($usuarioAtualId > 0 && function_exists('studio_list_whatsapp_stickers')) {
                    $figurinhas = studio_list_whatsapp_stickers($studio, $usuarioAtualId, 24);
                }
            } catch (Throwable $e) {
                $figurinhas = [];
            }

            // Previa de resposta citada.
            echo '<div class="hist-citacao hidden" id="histCitacao"><div><span>Respondendo</span><p id="histCitacaoTexto"></p></div><button type="button" id="histCancelarCitacao" aria-label="Cancelar" style="border:0;background:transparent;cursor:pointer;font-size:15px;color:#667085">&times;</button></div>';

            echo '<form class="hist-composer" id="histComposer" method="post" enctype="multipart/form-data" autocomplete="off">';
            echo '<input type="hidden" name="csrf_token" value="' . $escreve(csrf_token()) . '">';
            echo '<input type="hidden" name="action" value="historico_responder">';
            echo '<input type="hidden" name="telefone" value="' . $escreve($histFoneAberto) . '">';
            echo '<input type="hidden" name="nome" value="' . $escreve($nomeConversa) . '">';
            echo '<input type="hidden" name="inline" value="1">';
            echo '<input type="hidden" name="context_message_id" id="histContextId" value="">';
            echo '<input type="hidden" name="context_preview" id="histContextPreview" value="">';
            echo '<input type="file" id="histArquivo" name="media_file" class="hist-file-input" accept="image/*,audio/*,video/*,.webp,.pdf,.doc,.docx,.txt,.zip">';
            // Botoes de recurso (emoji, figurinha, anexo) + campo + enviar.
            echo '<div class="hist-compose-tools">';
            echo '<button type="button" id="histEmojiBtn" title="Emoji" aria-label="Emoji">&#128522;</button>';
            echo '<button type="button" id="histFigurinhaBtn" title="Figurinhas" aria-label="Figurinhas">&#127991;&#65039;</button>';
            echo '<button type="button" id="histAnexoBtn" title="Anexar" aria-label="Anexar"' . ($podeEnviar ? '' : ' disabled') . '>&#128206;</button>';
            echo '<button type="button" id="histGravarBtn" title="Gravar audio" aria-label="Gravar audio"' . ($podeEnviar ? '' : ' disabled') . '>&#127908;</button>';
            echo '</div>';
            echo '<textarea id="histMessage" name="mensagem" rows="1" placeholder="'
                . ($podeEnviar ? 'Escreva uma resposta...' : 'Envio indisponivel') . '"'
                . ($podeEnviar ? '' : ' disabled') . '></textarea>';
            echo '<button type="submit" class="hist-send"' . ($podeEnviar ? '' : ' disabled') . ' aria-label="Enviar">Enviar</button>';
            echo '</form>';
            echo '<div class="hist-anexo hidden" id="histAnexoPreview"></div>';
            echo '<div class="hist-emoji hidden" id="histEmojiPanel"></div>';
            echo '<div class="hist-figurinhas hidden" id="histFigurinhaPanel"></div>';
            echo '<div class="hist-composer-hint" id="histHint">Enviado pelo numero que recebeu a mensagem. Enter envia; Shift+Enter quebra linha.</div>';

            if ($respostasRapidas) {
                echo '<div class="hist-rapidas"><span>Respostas rapidas:</span>';
                foreach (array_slice($respostasRapidas, 0, 16) as $rr) {
                    echo '<button type="button" data-rapida="' . $escreve((string)$rr['body']) . '">' . $escreve((string)$rr['title']) . '</button>';
                }
                echo '</div>';
            }

            // Dados para o JS.
            echo '<script>' . "\n";
            $emojis = ['😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😍','😘','😗','😙','😚','😋','😛','😜','🤪','😎','🥳','😏','😒','😞','😔','😢','😭','😤','😡','🤯','🥺','😬','😴','🤔','🫠','🤝','👍','👎','👌','🤌','👏','🙌','🙏','💪','🤘','✌️','👀','🫶','❤️','🧡','💛','💚','💙','💜','🖤','🤍','💔','💕','💞','💘','🔥','✨','⭐','💫','💥','💯','✅','❌','⚠️','📅','⏰','📍','💰','💳','📸','🎨','🖊️','🌹','🐍','🦁','🐺','🦋','🌙','☀️','⚡','👑','💀','👻','🍒','🍺','☕','🎯','🚀','📲','🤖'];
            echo 'window.HIST_EMOJIS = ' . json_encode($emojis, JSON_UNESCAPED_UNICODE) . ";\n";
            echo 'window.HIST_FIGURINHAS = ' . json_encode(array_map(static function (array $f): array {
                return [
                    'id' => (int)($f['id'] ?? 0),
                    'url' => (string)($f['mediaUrl'] ?? $f['media_url'] ?? ''),
                ];
            }, $figurinhas), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
            echo 'window.HIST_PODE_ENVIAR = ' . ($podeEnviar ? 'true' : 'false') . ';' . "\n";
            echo '</script>';
            echo '</div>';
            ?>
            <script>
            (function () {
              var form = document.getElementById('histComposer');
              var ta = document.getElementById('histMessage');
              var chat = document.getElementById('histChat');
              if (!form || !ta) return;

              if (chat) { chat.scrollTop = chat.scrollHeight; }

              var input = document.getElementById('histArquivo');
              var preview = document.getElementById('histAnexoPreview');
              var emojiPanel = document.getElementById('histEmojiPanel');
              var figPanel = document.getElementById('histFigurinhaPanel');
              var citacao = document.getElementById('histCitacao');
              var citacaoTexto = document.getElementById('histCitacaoTexto');
              var ctxId = document.getElementById('histContextId');
              var ctxPreview = document.getElementById('histContextPreview');
              var hint = document.getElementById('histHint');
              var gravando = false, recorder = null, pedacos = [], streamAtivo = null, tick = null, inicio = 0;

              function altura() {
                ta.style.height = 'auto';
                ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
              }
              ta.addEventListener('input', altura);
              altura();

              // ---- Resposta citada -------------------------------------
              function limparCitacao() {
                if (ctxId) ctxId.value = '';
                if (ctxPreview) ctxPreview.value = '';
                if (citacao) citacao.classList.add('hidden');
              }
              document.querySelectorAll('[data-citar]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                  if (!citacao) return;
                  ctxId.value = btn.getAttribute('data-citar') || '';
                  ctxPreview.value = btn.getAttribute('data-preview') || '';
                  if (citacaoTexto) citacaoTexto.textContent = (btn.getAttribute('data-preview') || '').slice(0, 160);
                  citacao.classList.remove('hidden');
                  ta.focus();
                });
              });
              var cancelar = document.getElementById('histCancelarCitacao');
              if (cancelar) cancelar.addEventListener('click', limparCitacao);

              // ---- Emoji ------------------------------------------------
              if (emojiPanel) {
                (window.HIST_EMOJIS || []).forEach(function (e) {
                  var b = document.createElement('button');
                  b.type = 'button';
                  b.textContent = e;
                  b.addEventListener('click', function () {
                    var p = ta.selectionStart || ta.value.length;
                    ta.value = ta.value.slice(0, p) + e + ta.value.slice(ta.selectionEnd || p);
                    ta.selectionStart = ta.selectionEnd = p + e.length;
                    altura();
                    ta.focus();
                  });
                  emojiPanel.appendChild(b);
                });
              }
              var emojiBtn = document.getElementById('histEmojiBtn');
              if (emojiBtn) emojiBtn.addEventListener('click', function () {
                emojiPanel.classList.toggle('hidden');
                if (figPanel) figPanel.classList.add('hidden');
              });

              // ---- Figurinhas -------------------------------------------
              if (figPanel) {
                var lista = window.HIST_FIGURINHAS || [];
                if (!lista.length) {
                  figPanel.innerHTML = '<div class="hist-fig-vazio">Nenhuma figurinha salva. Salve uma recebida pelo botao na conversa.</div>';
                } else {
                  lista.forEach(function (f) {
                    if (!f.url) return;
                    var img = document.createElement('img');
                    img.src = f.url;
                    img.alt = 'figurinha';
                    img.addEventListener('click', function () { enviarMidia('sticker', f.url, ''); });
                    figPanel.appendChild(img);
                  });
                }
              }
              var figBtn = document.getElementById('histFigurinhaBtn');
              if (figBtn) figBtn.addEventListener('click', function () {
                figPanel.classList.toggle('hidden');
                if (emojiPanel) emojiPanel.classList.add('hidden');
              });

              // ---- Anexo ------------------------------------------------
              var anexoBtn = document.getElementById('histAnexoBtn');
              if (anexoBtn && input) anexoBtn.addEventListener('click', function () { input.click(); });
              if (input) input.addEventListener('change', function () {
                if (!input.files || !input.files.length) { if (preview) preview.classList.add('hidden'); return; }
                var f = input.files[0];
                if (preview) {
                  preview.textContent = 'Anexo: ' + f.name + ' (' + Math.round(f.size / 1024) + ' KB)';
                  preview.classList.remove('hidden');
                }
              });

              // ---- Gravacao de audio ------------------------------------
              var gravarBtn = document.getElementById('histGravarBtn');
              if (gravarBtn) gravarBtn.addEventListener('click', async function () {
                if (gravando) { if (recorder && recorder.state !== 'inactive') recorder.stop(); return; }
                var libera = navigator.mediaDevices && window.MediaRecorder;
                if (!libera) { alert('Este navegador nao liberou gravacao de audio.'); return; }
                try {
                  streamAtivo = await navigator.mediaDevices.getUserMedia({ audio: true });
                  var prefere = MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')
                    ? 'audio/ogg;codecs=opus'
                    : (MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : '');
                  recorder = new MediaRecorder(streamAtivo, prefere ? { mimeType: prefere } : {});
                  pedacos = [];
                  inicio = Date.now();
                  gravando = true;
                  gravarBtn.classList.add('gravando');
                  gravarBtn.textContent = '\u25A0';
                  if (hint) hint.textContent = 'Gravando... clique no quadrado para parar e enviar.';
                  tick = setInterval(function () {
                    var s = Math.floor((Date.now() - inicio) / 1000);
                    if (hint) hint.textContent = 'Gravando ' + String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0') + ' - clique no quadrado para parar.';
                  }, 500);
                  recorder.ondataavailable = function (ev) { if (ev.data && ev.data.size > 0) pedacos.push(ev.data); };
                  recorder.onstop = function () {
                    if (tick) { clearInterval(tick); tick = null; }
                    if (streamAtivo) { streamAtivo.getTracks().forEach(function (t) { t.stop(); }); streamAtivo = null; }
                    gravando = false;
                    gravarBtn.classList.remove('gravando');
                    gravarBtn.textContent = '\uD83C\uDF99\uFE0F';
                    var mime = recorder.mimeType || prefere || 'audio/webm';
                    var ext = (mime.indexOf('ogg') !== -1 || mime.indexOf('opus') !== -1) ? 'ogg' : 'webm';
                    var blob = new Blob(pedacos, { type: mime });
                    var arquivo = new File([blob], 'audio_' + Date.now() + '.' + ext, { type: mime });
                    var dt = new DataTransfer();
                    dt.items.add(arquivo);
                    input.files = dt.files;
                    if (preview) { preview.textContent = 'Audio pronto (' + Math.round(blob.size / 1024) + ' KB) - clique em Enviar.'; preview.classList.remove('hidden'); }
                    if (hint) hint.textContent = 'Audio pronto. Clique em Enviar.';
                  };
                  recorder.start();
                } catch (e) {
                  gravando = false;
                  gravarBtn.classList.remove('gravando');
                  gravarBtn.textContent = '\uD83C\uDF99\uFE0F';
                  alert('Nao foi possivel iniciar a gravacao.');
                }
              });

              // ---- Respostas rapidas ------------------------------------
              document.querySelectorAll('[data-rapida]').forEach(function (b) {
                b.addEventListener('click', function () {
                  ta.value = b.getAttribute('data-rapida') || '';
                  altura();
                  ta.focus();
                });
              });

              // ---- Envio (texto, midia e figurinha) ---------------------
              function bolha(rotulo, texto, mediaUrl) {
                var agora = new Date();
                var hh = String(agora.getHours()).padStart(2, '0');
                var mm = String(agora.getMinutes()).padStart(2, '0');
                var div = document.createElement('div');
                div.className = 'hist-msg out hist-msg-nova';
                var meta = document.createElement('div');
                meta.className = 'meta';
                meta.textContent = hh + ':' + mm + ' (enviando)';
                div.appendChild(meta);
                if (mediaUrl && /^data:image\//.test(mediaUrl)) {
                  var img = document.createElement('img');
                  img.src = mediaUrl; img.style.maxWidth = '180px'; img.style.borderRadius = '8px';
                  div.appendChild(img);
                }
                if (texto) { var p = document.createElement('div'); p.textContent = texto; div.appendChild(p); }
                if (!texto && !mediaUrl) { var q = document.createElement('div'); q.textContent = rotulo; div.appendChild(q); }
                chat.appendChild(div);
                chat.scrollTop = chat.scrollHeight;
                return div;
              }

              function enviarMidia(kind, base64, nome, textoExtra) {
                var corpo = new FormData();
                corpo.append('action', 'historico_responder');
                corpo.append('inline', '1');
                corpo.append('telefone', form.querySelector('[name="telefone"]').value);
                corpo.append('nome', form.querySelector('[name="nome"]').value);
                corpo.append('mensagem', textoExtra || '');
                corpo.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
                if (ctxId && ctxId.value) {
                  corpo.append('context_message_id', ctxId.value);
                  corpo.append('context_preview', ctxPreview.value);
                }
                corpo.append('midia_kind', kind);
                corpo.append('midia_base64', base64);
                corpo.append('midia_nome', nome || '');
                fetch(window.location.pathname + window.location.search, {
                  method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: corpo
                })
                  .then(function (r) { return r.json(); })
                  .then(function (json) {
                    if (!json.ok) throw new Error(json.error || 'Falha ao enviar');
                    var b = bolha('[' + kind + ']', textoExtra || '', base64);
                    var m = b.querySelector('.meta');
                    if (m) m.textContent = m.textContent.replace('(enviando)', '(enviado)');
                    limparCitacao();
                  })
                  .catch(function (err) { alert(err.message || 'Nao foi possivel enviar'); });
              }

              // Figurinha salva: manda pelo caminho, o servidor le o arquivo.
              window.enviarFigurinhaSalva = function (url) {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', url, true);
                xhr.responseType = 'blob';
                xhr.onload = function () {
                  if (xhr.status !== 200) { alert('Nao consegui ler a figurinha.'); return; }
                  var fr = new FileReader();
                  fr.onload = function () { enviarMidia('sticker', String(fr.result), 'figurinha.webp', ''); };
                  fr.readAsDataURL(xhr.response);
                };
                xhr.onerror = function () { alert('Nao consegui ler a figurinha.'); };
                xhr.send();
              };

              // Enter envia; Shift+Enter quebra linha.
              ta.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' && !ev.shiftKey) {
                  ev.preventDefault();
                  form.requestSubmit ? form.requestSubmit() : form.submit();
                }
              });

              form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var texto = String(ta.value || '').trim();
                var temArquivo = input && input.files && input.files.length > 0;
                if (!texto && !temArquivo) return;
                var botao = form.querySelector('.hist-send');
                if (botao) { botao.disabled = true; botao.textContent = '...'; }

                var corpo = new FormData();
                corpo.append('action', 'historico_responder');
                corpo.append('inline', '1');
                corpo.append('telefone', form.querySelector('[name="telefone"]').value);
                corpo.append('nome', form.querySelector('[name="nome"]').value);
                corpo.append('mensagem', texto);
                corpo.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
                if (ctxId && ctxId.value) {
                  corpo.append('context_message_id', ctxId.value);
                  corpo.append('context_preview', ctxPreview.value);
                }
                if (temArquivo) corpo.append('media_file', input.files[0]);

                var arquivoLocal = temArquivo ? input.files[0] : null;
                var mediaUrlLocal = '';
                fetch(window.location.pathname + window.location.search, {
                  method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: corpo
                })
                  .then(function (r) { return r.json(); })
                  .then(function (json) {
                    if (!json.ok) throw new Error(json.error || 'Falha ao enviar');
                    var b;
                    if (arquivoLocal && /^image\//.test(arquivoLocal.type)) {
                      mediaUrlLocal = URL.createObjectURL(arquivoLocal);
                    }
                    b = bolha(arquivoLocal ? '[arquivo]' : '', texto, mediaUrlLocal);
                    var m = b.querySelector('.meta');
                    if (m) m.textContent = m.textContent.replace('(enviando)', '(enviado)');
                    ta.value = '';
                    if (input) input.value = '';
                    if (preview) preview.classList.add('hidden');
                    altura();
                    limparCitacao();
                  })
                  .catch(function (err) { alert(err.message || 'Nao foi possivel enviar'); })
                  .finally(function () {
                    if (botao) { botao.disabled = false; botao.textContent = 'Enviar'; }
                    ta.focus();
                  });
              });
            })();
            </script>
            <?php
            return;
        }

        // ---- Lista ------------------------------------------------------
        if ($histModo === 'conversas') {
            $res = historico_conversas_agrupadas($hf, $histLimite, $histOffset, $histOrdenar);
            // Liga cada conversa ao lead do CRM (para poder editar a origem daqui).
            $leadsMapa = [];
            try {
                $pdoHist = studio_db($studio);
                $leadsMapa = historico_mapa_leads($pdoHist, array_column($res['conversas'], 'phone'));
            } catch (Throwable $e) {
                $leadsMapa = [];
            }
            $totalConv = (int)$res['total_conversas'];
            $totalPag = max(1, (int)ceil($totalConv / $histLimite));
            $primeiro = $totalConv ? ($histOffset + 1) : 0;
            $ultimo = min($totalConv, $histOffset + $histLimite);
            echo '<p class="hist-help">' . $totalConv . ' conversa(s)' . ($hf ? ' com os filtros aplicados' : '') . ($totalConv ? ' — mostrando ' . $primeiro . '–' . $ultimo : '') . '. Clique no telefone para ver o histórico completo. A coluna <b>Origem</b> é editável.</p>';
            // Cabecalho clicavel. Cada titulo ordena por aquela coluna; quando a
            // coluna ja esta ativa, clicar inverte o sentido. As chaves de
            // ordenacao ficam em historico_conversas_agrupadas().
            $thOrd = static function (string $rotulo, string $asc, string $desc) use ($histOrdenar, $histQueryBase, $escreve): string {
                $ativo = $histOrdenar === $asc || $histOrdenar === $desc;
                $proximo = ($histOrdenar === $asc) ? $desc : $asc;
                $seta = !$ativo ? '\u{2195}' : ($histOrdenar === $asc ? '\u{2191}' : '\u{2193}');
                $href = '?' . $histQueryBase(['h_ordenar' => $proximo, 'h_pagina' => 1, 'h_fone' => '']);
                return '<th class="' . ($ativo ? 'on' : '') . '"><a href="' . $escreve($href) . '" title="Ordenar por ' . $escreve($rotulo) . '">' . $escreve($rotulo) . ' <span class="seta">' . $seta . '</span></a></th>';
            };
            echo '<table class="hist-table"><thead><tr>'
                . $thOrd('Última', 'recentes', 'antigas')
                . '<th>Telefone</th>'
                . $thOrd('Nome', 'nome', 'nome_desc')
                . $thOrd('Msgs', 'msgs', 'msgs_desc')
                . $thOrd('Resposta', 'resposta', 'resposta_desc')
                . $thOrd('Origem (editável)', 'origem', 'origem_desc')
                . '<th>Última mensagem</th>'
                . '</tr></thead><tbody>';
            if (!$res['conversas']) {
                echo '<tr><td colspan="7" class="hist-empty" style="border:0">Nenhuma conversa encontrada.</td></tr>';
            }
            foreach ($res['conversas'] as $c) {
                $quando = (string)$c['ultima_em'];
                $ts = $quando !== '' ? date('d/m H:i', strtotime($quando)) : '';
                $fone = (string)$c['phone'];
                $leadDoFone = $leadsMapa[$fone] ?? null;

                if ($leadDoFone) {
                    // Tem lead: mostra seletor que salva no CRM.
                    $origemAtual = ads_origem_normalizar((string)$leadDoFone['source']);
                    $detectada = !empty($c['origem']) ? (string)($c['origem']['platform'] ?? '') : '';
                    $cel = '<select class="hist-source-inline" data-lead-source="' . (int)$leadDoFone['id'] . '" data-cor="' . $escreve(ads_origem_cor($origemAtual)) . '" title="Origem do lead #' . (int)$leadDoFone['id'] . '">';
                    if ($origemAtual === '') {
                        $cel .= '<option value="">— sem origem —</option>';
                    }
                    foreach (ads_origem_opcoes_agrupadas() as $grupo) {
                        $cel .= '<optgroup label="' . $escreve((string)$grupo['label']) . '">';
                        foreach ($grupo['itens'] as $codigo => $rotulo) {
                            $sel = ((string)$codigo === $origemAtual) ? ' selected' : '';
                            $cel .= '<option value="' . $escreve((string)$codigo) . '"' . $sel . '>' . $escreve((string)$rotulo) . '</option>';
                        }
                        $cel .= '</optgroup>';
                    }
                    $cel .= '</select>';
                    if ($detectada !== '') {
                        $cel .= '<div class="hist-hint">rastreado: ' . $escreve($detectada) . '</div>';
                    }
                } else {
                    // Sem lead no CRM: o seletor tambem aparece. Ao escolher uma
                    // origem, o CRM cria o lead com este telefone e grava a origem
                    // (acao create_lead_source). Assim toda conversa e classificavel.
                    $utilizavel = historico_telefone_utilizavel($fone);
                    $cel = '<select class="hist-source-inline" data-lead-phone="' . $escreve($fone) . '" data-lead-name="' . $escreve((string)($c['name'] ?? '')) . '" data-cor="#98a2b3" title="Sem lead no CRM - escolher cria o lead">';
                    $cel .= '<option value="">- sem origem -</option>';
                    foreach (ads_origem_opcoes_agrupadas() as $grupo) {
                        $cel .= '<optgroup label="' . $escreve((string)$grupo['label']) . '">';
                        foreach ($grupo['itens'] as $codigo => $rotulo) {
                            $cel .= '<option value="' . $escreve((string)$codigo) . '">' . $escreve((string)$rotulo) . '</option>';
                        }
                        $cel .= '</optgroup>';
                    }
                    $cel .= '</select>';
                    $detectada = !empty($c['origem']) ? (string)($c['origem']['platform'] ?? '') : '';
                    $cel .= '<div class="hist-hint">';
                    if ($detectada !== '') {
                        $cel .= 'rastreado: ' . $escreve($detectada) . ' - ';
                    }
                    $cel .= $utilizavel
                        ? 'salvar cria o lead no CRM'
                        : 'ID interno do WhatsApp - salvar cria o lead mesmo assim';
                    $cel .= '</div>';
                }

                $link = '?' . $histQueryBase(['h_fone' => $fone]);
                echo '<tr>';
                echo '<td style="white-space:nowrap">' . $escreve($ts) . '</td>';
                echo '<td><a href="' . $escreve($link) . '"><code>' . $escreve($fone) . '</code></a>';
                if ($leadDoFone) {
                    echo '<div class="hist-hint"><a href="' . $escreve(app_url('studio_lead', ['id' => (int)$leadDoFone['id']])) . '">lead #' . (int)$leadDoFone['id'] . '</a></div>';
                }
                echo '</td>';
                echo '<td>' . $escreve((string)($c['name'] !== '' ? $c['name'] : '—')) . '</td>';
                echo '<td>' . (int)$c['total'];
                if ((int)$c['audios'] > 0) {
                    echo ' <span class="pill audio">' . (int)$c['audios'] . ' áudio' . ((int)$c['transcritos'] > 0 ? '/' . (int)$c['transcritos'] . ' ok' : '') . '</span>';
                }
                echo '</td>';
                // Estado de resposta: respondida quando o atendimento falou por ultimo.
                if (!empty($c['respondida'])) {
                    echo '<td><span class="hist-resp" style="color:#079455">respondida</span></td>';
                } else {
                    echo '<td><span class="hist-resp" style="color:#d92d20">sem resposta</span></td>';
                }
                echo '<td>' . $cel . '</td>';
                echo '<td class="muted">' . $escreve(mb_substr((string)$c['preview'], 0, 90)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            // Paginacao: janela de ate 7 numeros em volta da pagina atual.
            if ($totalPag > 1) {
                $linkPag = static function (int $p) use ($histQueryBase): string {
                    return '?' . $histQueryBase(['h_pagina' => $p, 'h_fone' => '']);
                };
                echo '<div class="hist-paginacao">';
                if ($histPagina > 1) {
                    echo '<a href="' . $escreve($linkPag($histPagina - 1)) . '">&larr; Anterior</a>';
                }
                $ini = max(1, $histPagina - 3);
                $fim = min($totalPag, $histPagina + 3);
                if ($ini > 1) {
                    echo '<a href="' . $escreve($linkPag(1)) . '">1</a>';
                    if ($ini > 2) {
                        echo '<span class="info">…</span>';
                    }
                }
                for ($p = $ini; $p <= $fim; $p++) {
                    if ($p === $histPagina) {
                        echo '<span class="atual">' . $p . '</span>';
                    } else {
                        echo '<a href="' . $escreve($linkPag($p)) . '">' . $p . '</a>';
                    }
                }
                if ($fim < $totalPag) {
                    if ($fim < $totalPag - 1) {
                        echo '<span class="info">…</span>';
                    }
                    echo '<a href="' . $escreve($linkPag($totalPag)) . '">' . $totalPag . '</a>';
                }
                if ($histPagina < $totalPag) {
                    echo '<a href="' . $escreve($linkPag($histPagina + 1)) . '">Próxima &rarr;</a>';
                }
                echo '<span class="info">página ' . $histPagina . ' de ' . $totalPag . '</span>';
                echo '</div>';
            }
            return;
        }

        // Modo "todas as mensagens".
        $res = historico_ler_mensagens($hf, $histLimite, $histOffset, $histOrdenar);
        $totalMsg = (int)$res['total_filtrado'];
        $totalPag = max(1, (int)ceil($totalMsg / $histLimite));
        echo '<p class="hist-help">' . $totalMsg . ' mensagem(ns) encontradas — mostrando ' . ($totalMsg ? ($histOffset + 1) : 0) . '–' . min($totalMsg, $histOffset + $histLimite) . '. Arquivo tem ' . (int)$res['total_arquivo'] . ' no total.</p>';
        // Ordenacao clicavel do modo mensagens (só recentes/antigas fazem sentido).
        $thMsg = static function (string $rotulo, string $asc, string $desc) use ($histOrdenar, $histQueryBase, $escreve): string {
            $ativo = $histOrdenar === $asc || $histOrdenar === $desc;
            $proximo = ($histOrdenar === $asc) ? $desc : $asc;
            $seta = !$ativo ? '\u{2195}' : ($histOrdenar === $asc ? '\u{2191}' : '\u{2193}');
            $href = '?' . $histQueryBase(['h_ordenar' => $proximo, 'h_pagina' => 1, 'h_fone' => '']);
            return '<th class="' . ($ativo ? 'on' : '') . '"><a href="' . $escreve($href) . '">' . $escreve($rotulo) . ' <span class="seta">' . $seta . '</span></a></th>';
        };
        echo '<table class="hist-table"><thead><tr>'
            . $thMsg('Quando', 'recentes', 'antigas')
            . '<th>Fone</th><th>Nome</th><th>Dir.</th><th>Tipo</th><th>Origem</th><th>Texto / transcrição</th>'
            . '</tr></thead><tbody>';
        if (!$res['itens']) {
            echo '<tr><td colspan="7" class="hist-empty" style="border:0">Nenhuma mensagem encontrada.</td></tr>';
        }
        foreach ($res['itens'] as $m) {
            $quando = (string)($m['sent_at'] ?? $m['at'] ?? '');
            $ts = $quando !== '' ? date('d/m H:i', strtotime($quando)) : '';
            $dir = !empty($m['from_me']) ? '<span class="pill out">enviada</span>' : '<span class="pill in">recebida</span>';
            $origem = !empty($m['origin'])
                ? '<span class="pill ad">' . $escreve((string)($m['origin']['platform'] ?? 'anúncio')) . '</span>'
                : '<span class="pill plain">—</span>';
            $tipo = strtolower((string)($m['media_type'] ?? ''));
            $tipoTag = $tipo === 'audio'
                ? '<span class="pill audio">áudio</span>'
                : '<span class="pill plain">' . $escreve($tipo !== '' ? $tipo : '—') . '</span>';
            $texto = (string)($m['text'] ?? '');
            if ($texto === '' && !empty($m['transcription'])) {
                $texto = (string)$m['transcription'];
            }
            if ($texto === '') {
                $texto = '[' . $tipo . ']';
            }
            $fone = (string)($m['phone'] ?? '');
            echo '<tr>';
            echo '<td style="white-space:nowrap">' . $escreve($ts) . '</td>';
            echo '<td><a href="?' . $escreve($histQueryBase(['h_fone' => $fone])) . '"><code>' . $escreve($fone) . '</code></a></td>';
            echo '<td>' . $escreve((string)($m['name'] ?? '—')) . '</td>';
            echo '<td>' . $dir . '</td>';
            echo '<td>' . $tipoTag . '</td>';
            echo '<td>' . $origem . '</td>';
            echo '<td>' . $escreve(mb_substr($texto, 0, 260)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Paginacao (mesmo padrao da lista de conversas).
        if ($totalPag > 1) {
            $linkPag = static function (int $p) use ($histQueryBase): string {
                return '?' . $histQueryBase(['h_pagina' => $p, 'h_fone' => '']);
            };
            echo '<div class="hist-paginacao">';
            if ($histPagina > 1) {
                echo '<a href="' . $escreve($linkPag($histPagina - 1)) . '">&larr; Anterior</a>';
            }
            $ini = max(1, $histPagina - 3);
            $fim = min($totalPag, $histPagina + 3);
            if ($ini > 1) {
                echo '<a href="' . $escreve($linkPag(1)) . '">1</a>';
                if ($ini > 2) { echo '<span class="info">…</span>'; }
            }
            for ($p = $ini; $p <= $fim; $p++) {
                if ($p === $histPagina) {
                    echo '<span class="atual">' . $p . '</span>';
                } else {
                    echo '<a href="' . $escreve($linkPag($p)) . '">' . $p . '</a>';
                }
            }
            if ($fim < $totalPag) {
                if ($fim < $totalPag - 1) { echo '<span class="info">…</span>'; }
                echo '<a href="' . $escreve($linkPag($totalPag)) . '">' . $totalPag . '</a>';
            }
            if ($histPagina < $totalPag) {
                echo '<a href="' . $escreve($linkPag($histPagina + 1)) . '">Próxima &rarr;</a>';
            }
            echo '<span class="info">página ' . $histPagina . ' de ' . $totalPag . '</span>';
            echo '</div>';
        }
    },
    null
);
