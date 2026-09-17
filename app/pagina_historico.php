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
    'de'       => trim((string)($_GET['h_de'] ?? '')),
    'ate'      => trim((string)($_GET['h_ate'] ?? '')),
];
$hf = array_filter($hf, static fn($v) => $v !== '');

$histModo = (string)($_GET['h_modo'] ?? 'conversas');
if (!in_array($histModo, ['conversas', 'mensagens'], true)) {
    $histModo = 'conversas';
}
$histLimite = (int)($_GET['h_limite'] ?? 100);
if (!in_array($histLimite, [50, 100, 200, 500], true)) {
    $histLimite = 100;
}

// Telefone selecionado (visão de conversa única).
$histFoneAberto = preg_replace('/\D+/', '', (string)($_GET['h_fone'] ?? ''));

// Constrói a query base preservando os filtros.
$histQueryBase = static function (array $extra = []) use ($hf, $histModo, $histLimite): string {
    $q = array_merge(['page' => 'studio_historico', 'h_modo' => $histModo, 'h_limite' => $histLimite], $hf, $extra);
    return http_build_query($q);
};

echo '<style>
 .hist-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
 .hist-card{background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:13px 15px}
 .hist-card .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#667085;font-weight:700}
 .hist-card .val{font-size:21px;font-weight:800;color:#101828;margin-top:5px}
 .hist-card .sub{font-size:11px;color:#98a2b3;margin-top:3px}
 .hist-filters{background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:14px;margin-bottom:16px}
 .hist-filters .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
 .hist-filters label{display:flex;flex-direction:column;gap:4px;font-size:11px;color:#475467;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
 .hist-filters input,.hist-filters select{border:1px solid #d0d5dd;border-radius:9px;padding:7px 10px;font-size:13px;min-width:120px}
 .hist-btn{background:#2f6fed;color:#fff;border:0;border-radius:9px;padding:8px 15px;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;display:inline-block}
 .hist-btn.sec{background:#eef2f6;color:#344054}
 .hist-table{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden;font-size:13px}
 .hist-table th{background:#f9fafb;text-align:left;padding:9px 11px;color:#475467;font-weight:700;border-bottom:1px solid #eaecf0;font-size:11px;text-transform:uppercase}
 .hist-table td{padding:8px 11px;border-bottom:1px solid #f2f4f7;color:#344054;vertical-align:top}
 .hist-table tr:hover td{background:#fcfcfd}
 .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap}
 .pill.ad{background:#e8f5ee;color:#0a6b3d}
 .pill.in{background:#eef2ff;color:#3538cd}
 .pill.out{background:#f4f0ff;color:#6941c6}
 .pill.audio{background:#fff4e5;color:#b54708}
 .pill.plain{background:#f2f4f7;color:#475467}
 .hist-chat{max-width:820px;margin:0 auto}
 .hist-msg{padding:9px 13px;border-radius:12px;margin-bottom:8px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
 .hist-msg.in{background:#fff;border:1px solid #e6e8ee}
 .hist-msg.out{background:#eef4ff;border:1px solid #dbe6fb;margin-left:44px}
 .hist-msg .meta{font-size:10px;color:#98a2b3;margin-bottom:4px;font-weight:700;letter-spacing:.02em}
 .hist-empty{background:#fff;border:1px dashed #d0d5dd;border-radius:14px;padding:26px;text-align:center;color:#667085}
 .hist-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
 .hist-bar a{padding:6px 13px;border-radius:999px;font-size:12px;font-weight:700;text-decoration:none;background:#eef2f6;color:#344054}
 .hist-bar a.on{background:#101828;color:#fff}
 .hist-help{font-size:12px;color:#667085;margin:-6px 0 14px}
 .hist-source-inline{font-size:11px;font-weight:700;border:1px solid #d0d5dd;border-radius:999px;padding:2px 7px;background:#fff;color:#344054;max-width:160px;cursor:pointer}
 .hist-source-inline:disabled{opacity:.55}
 .hist-hint{font-size:10px;color:#98a2b3;margin-top:3px}
</style>';

render_studio_shell(
    'Histórico de Conversas',
    'Todas as mensagens arquivadas do WhatsApp — com origem, transcrição de áudio e busca.',
    'historico',
    function () use ($histStats, $histPonte, $histArquivo, $histTamanho, $hf, $histModo, $histLimite, $histFoneAberto, $histQueryBase) {

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
        echo '<a class="' . ($histModo === 'conversas' ? 'on' : '') . '" href="?' . $escreve($histQueryBase(['h_modo' => 'conversas', 'h_fone' => ''])) . '">Por conversa</a>';
        echo '<a class="' . ($histModo === 'mensagens' ? 'on' : '') . '" href="?' . $escreve($histQueryBase(['h_modo' => 'mensagens', 'h_fone' => ''])) . '">Todas as mensagens</a>';
        echo '<a class="" href="' . $escreve('?' . $histQueryBase(['h_origem' => 'com', 'h_fone' => ''])) . '" title="Somente conversas de anúncio">Só origem de anúncio</a>';
        echo '<a class="" href="?' . $escreve(http_build_query(['page' => 'studio_historico'])) . '" title="Limpar tudo">Limpar filtros</a>';
        echo '</div>';

        // ---- Filtros ----------------------------------------------------
        echo '<form class="hist-filters" method="get">';
        echo '<input type="hidden" name="page" value="studio_historico">';
        echo '<input type="hidden" name="h_modo" value="' . $escreve($histModo) . '">';
        echo '<div class="row">';
        echo '<label>Telefone<input name="h_telefone" value="' . $escreve($hf['telefone'] ?? '') . '" placeholder="11999 ou 5511..."></label>';
        echo '<label>Nome<input name="h_nome" value="' . $escreve($hf['nome'] ?? '') . '" placeholder="parte do nome"></label>';
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
        echo '<label>De<input type="date" name="h_de" value="' . $escreve($hf['de'] ?? '') . '"></label>';
        echo '<label>Até<input type="date" name="h_ate" value="' . $escreve($hf['ate'] ?? '') . '"></label>';
        echo '<label>Mostrar<select name="h_limite">';
        foreach ([50, 100, 200, 500] as $n) {
            echo '<option value="' . $n . '"' . ($histLimite === $n ? ' selected' : '') . '>' . $n . '</option>';
        }
        echo '</select></label>';
        echo '<button class="hist-btn" type="submit">Filtrar</button>';
        echo '</div>';
        echo '</form>';

        // ---- Conversa única aberta --------------------------------------
        if ($histFoneAberto !== '') {
            $conv = historico_ler_mensagens(['telefone' => $histFoneAberto], 500, 0);
            $itens = array_reverse($conv['itens']); // cronológico

            echo '<p><a class="hist-btn sec" href="?' . $escreve($histQueryBase(['h_fone' => ''])) . '">&larr; Voltar para a lista</a></p>';
            echo '<h3 class="h5">Conversa com <code>' . $escreve($histFoneAberto) . '</code> (' . count($itens) . ' mensagens)</h3>';
            echo '<div class="hist-chat">';
            if (!$itens) {
                echo '<div class="hist-empty">Nenhuma mensagem encontrada para esse telefone com os filtros atuais.</div>';
            }
            foreach ($itens as $m) {
                $out = !empty($m['from_me']);
                $quando = (string)($m['sent_at'] ?? $m['at'] ?? '');
                $ts = $quando !== '' ? date('d/m H:i', strtotime($quando)) : '';
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
                echo '</div>';
            }
            echo '</div>';
            echo '<p style="margin-top:14px"><a class="hist-btn sec" href="?' . $escreve($histQueryBase(['h_fone' => ''])) . '">&larr; Voltar para a lista</a></p>';
            return;
        }

        // ---- Lista ------------------------------------------------------
        if ($histModo === 'conversas') {
            $res = historico_conversas_agrupadas($hf, $histLimite);
            // Liga cada conversa ao lead do CRM (para poder editar a origem daqui).
            $leadsMapa = [];
            try {
                $pdoHist = studio_db($studio);
                $leadsMapa = historico_mapa_leads($pdoHist, array_column($res['conversas'], 'phone'));
            } catch (Throwable $e) {
                $leadsMapa = [];
            }
            echo '<p class="hist-help">' . (int)$res['total_conversas'] . ' conversa(s)' . ($hf ? ' com os filtros aplicados' : '') . '. Clique no telefone para ver o histórico completo. A coluna <b>Origem</b> é editável quando a conversa tem lead no CRM.</p>';
            echo '<table class="hist-table"><thead><tr><th>Última</th><th>Telefone</th><th>Nome</th><th>Msgs</th><th>Origem (editável)</th><th>Última mensagem</th></tr></thead><tbody>';
            if (!$res['conversas']) {
                echo '<tr><td colspan="6" class="hist-empty" style="border:0">Nenhuma conversa encontrada.</td></tr>';
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
                    $cel = '<select class="hist-source-inline" data-lead-source="' . (int)$leadDoFone['id'] . '" title="Origem do lead #' . (int)$leadDoFone['id'] . '">';
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
                    // Sem lead: so mostra o que a ponte detectou (nao da para editar).
                    $cel = $c['origem']
                        ? '<span class="pill ad">' . $escreve((string)($c['origem']['platform'] ?? 'anúncio')) . '</span>'
                        : '<span class="pill plain">—</span>';
                    $cel .= '<div class="hist-hint">sem lead no CRM</div>';
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
                echo '<td>' . $cel . '</td>';
                echo '<td class="muted">' . $escreve(mb_substr((string)$c['preview'], 0, 90)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            return;
        }

        // Modo "todas as mensagens".
        $res = historico_ler_mensagens($hf, $histLimite, 0);
        echo '<p class="hist-help">' . (int)$res['total_filtrado'] . ' mensagem(ns) encontradas' . ($res['truncado'] ? ', mostrando as ' . $histLimite . ' mais recentes' : '') . '. Arquivo tem ' . (int)$res['total_arquivo'] . ' no total.</p>';
        echo '<table class="hist-table"><thead><tr><th>Quando</th><th>Fone</th><th>Nome</th><th>Dir.</th><th>Tipo</th><th>Origem</th><th>Texto / transcrição</th></tr></thead><tbody>';
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
    },
    null
);

// JavaScript do seletor de origem. Fica FORA do shell para nao conflitar com o
// HTML gerado e poder usar o csrf_token da pagina.
?>
<script>
(function () {
  var selects = document.querySelectorAll('.hist-source-inline');
  if (!selects.length) return;

  function csrf() {
    var el = document.querySelector('input[name="csrf_token"]');
    return el ? el.value : '';
  }

  selects.forEach(function (sel) {
    var anterior = sel.value;
    sel.addEventListener('change', function () {
      var leadId = sel.getAttribute('data-lead-source') || '';
      if (!leadId) return;
      sel.disabled = true;
      sel.style.borderColor = '#f5c542';

      var body = new URLSearchParams();
      body.set('action', 'set_lead_source');
      body.set('lead_id', leadId);
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
          setTimeout(function () { sel.style.borderColor = ''; }, 1600);
        })
        .catch(function (err) {
          sel.value = anterior;
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
