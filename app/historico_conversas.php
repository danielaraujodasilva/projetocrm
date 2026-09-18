<?php
/**
 * Leitor do arquivo de conversas gravado pela ponte do WhatsApp.
 *
 * A ponte (C:\Users\server_spd\Documents\whatsapp-origin-bridge) grava um
 * NDJSON em dados/conversas.jsonl: uma linha JSON por mensagem. Este arquivo
 * le e transforma em estruturas para a pagina de historico.
 *
 * POR QUE NDJSON E NAO MYSQL
 * A ponte roda fora do CRM e nao depende do banco. O NDJSON e append-only
 * (nao corrompe em queda no meio da escrita) e serve de fonte da verdade.
 * A leitura aqui e sob demanda; se o volume crescer muito, um importador
 * leva isso para MySQL sem mudar o formato de origem.
 *
 * IMPORTANTE: este arquivo NUNCA escreve no NDJSON. Somente leitura.
 */
declare(strict_types=1);

/** Caminho do arquivo de conversas da ponte. */
function historico_conversas_path(): string
{
    $env = trim((string)(getenv('BRIDGE_CONVERSAS_PATH') ?: ''));
    if ($env !== '' && is_file($env)) {
        return $env;
    }
    return 'C:\\Users\\server_spd\\Documents\\whatsapp-origin-bridge\\dados\\conversas.jsonl';
}

/**
 * Telefone utilizavel como telefone de lead?
 *
 * LID (identificador interno do WhatsApp, 14+ digitos, sem formato de telefone)
 * nao serve: criar lead com ele poluiria a base. Nesse caso a origem pode ser
 * definida, mas avisamos que o contato nao tem numero real.
 */
function historico_telefone_utilizavel(string $fone): bool
{
    $d = preg_replace('/\D+/', '', $fone);
    if ($d === '') {
        return false;
    }
    // Telefone BR/DDI: 10 a 13 digitos. LID tem 14+.
    return strlen($d) >= 10 && strlen($d) <= 13;
}

function historico_eventos_path(): string
{
    return 'C:\\Users\\server_spd\\Documents\\whatsapp-origin-bridge\\dados\\eventos.jsonl';
}

/**
 * Mapa LID -> telefone real, montado a partir do proprio arquivo.
 *
 * POR QUE EXISTE
 * O arquivo da ponte guarda duas geracoes de linhas:
 *   - novas: `phone` = numero real e `jid_original` = LID (ex.: 78516867584147@lid)
 *   - antigas: `phone` = o LID cru, sem `jid_original` (gravadas antes do fix do
 *     resolvePhone em scan.js)
 *
 * Sem isso, a linha antiga aparece como uma CONVERSA separada, com "telefone"
 * que nao casa com lead nenhum no CRM - por isso a coluna Origem dela nao era
 * editavel. Aqui reconstruimos o de/para (a linha nova diz que aquele LID
 * corresponde a aquele numero) para reaproveitar o telefone real.
 *
 * Somente leitura.
 */
function historico_mapa_lid_telefone(): array
{
    static $mapa = null;
    if (is_array($mapa)) {
        return $mapa;
    }
    $mapa = [];
    $path = historico_conversas_path();
    if (!is_file($path)) {
        return $mapa;
    }
    $fh = @fopen($path, 'r');
    if (!$fh) {
        return $mapa;
    }
    while (($linha = fgets($fh)) !== false) {
        $m = json_decode(trim($linha), true);
        if (!is_array($m)) {
            continue;
        }
        // So as linhas novas servem: trazem o par (LID, telefone real).
        $jid = (string)($m['jid_original'] ?? '');
        $fone = (string)($m['phone'] ?? '');
        if ($jid === '' || $fone === '' || !str_contains($jid, '@lid')) {
            continue;
        }
        $lid = preg_replace('/@.*$/', '', $jid);
        if ($lid !== '' && $lid !== $fone) {
            $mapa[$lid] = $fone;
        }
    }
    fclose($fh);
    return $mapa;
}

/**
 * Telefone canonico de uma mensagem do arquivo.
 *
 * Se o `phone` gravado e um LID antigo, troca pelo numero real conhecido.
 * Devolve '' quando e um LID sem correspondencia (nao e telefone utilizavel).
 */
function historico_telefone_canonico(array $m): string
{
    $fone = (string)($m['phone'] ?? '');
    if ($fone === '') {
        return '';
    }
    // Linha nova: o proprio campo diz que o telefone foi resolvido.
    if (!empty($m['phone_resolvido'])) {
        return $fone;
    }
    // Linha antiga: o `phone` pode ser LID. Tenta traduzir.
    $mapa = historico_mapa_lid_telefone();
    if (isset($mapa[$fone])) {
        return (string)$mapa[$fone];
    }
    // LID sem equivalente conhecido: nao ha telefone real para casar.
    if (strlen($fone) > 15 || str_contains((string)($m['jid_original'] ?? ''), '@lid')) {
        return '';
    }
    return $fone;
}

/** A ponte esta no ar? (checa a porta do painel local) */
function historico_ponte_online(): bool
{
    $fp = @fsockopen('127.0.0.1', 3210, $errno, $errstr, 0.6);
    if (is_resource($fp)) {
        fclose($fp);
        return true;
    }
    return false;
}

/**
 * Le o arquivo de conversas e devolve as mensagens.
 *
 * Para arquivos grandes, le de tras para frente (as mais recentes primeiro)
 * e para ao atingir o limite - evita carregar tudo na memoria a cada request.
 *
 * $filtros aceita:
 *   telefone   - casa por parte do numero
 *   texto      - busca no texto e na transcricao
 *   origem     - 'com' (so com anuncio), 'sem' (so sem), ou a plataforma
 *   tipo       - text|audio|image|video|document|other|audio_transcrito
 *   de         - data inicial (Y-m-d)
 *   ate        - data final (Y-m-d)
 *   direcao    - recebida|enviada
 *   nome       - parte do nome
 */
function historico_ler_mensagens(array $filtros = [], int $limite = 200, int $offset = 0): array
{
    $path = historico_conversas_path();
    if (!is_file($path)) {
        return ['itens' => [], 'total_filtrado' => 0, 'total_arquivo' => 0, 'truncado' => false];
    }

    $fh = @fopen($path, 'r');
    if (!$fh) {
        return ['itens' => [], 'total_filtrado' => 0, 'total_arquivo' => 0, 'truncado' => false];
    }

    $linhas = [];
    while (($linha = fgets($fh)) !== false) {
        $linha = trim($linha);
        if ($linha !== '') {
            $linhas[] = $linha;
        }
    }
    fclose($fh);

    $totalArquivo = count($linhas);

    // Mais recentes primeiro.
    $linhas = array_reverse($linhas);

    $itens = [];
    $filtrados = 0;
    foreach ($linhas as $linha) {
        $m = json_decode($linha, true);
        if (!is_array($m)) {
            continue;
        }
        if (!historico_casa_filtros($m, $filtros)) {
            continue;
        }
        $filtrados++;
        if ($filtrados <= $offset) {
            continue;
        }
        if (count($itens) >= $limite) {
            continue; // segue contando o total
        }
        $itens[] = $m;
    }

    return [
        'itens' => $itens,
        'total_filtrado' => $filtrados,
        'total_arquivo' => $totalArquivo,
        'truncado' => $filtrados > ($offset + $limite),
    ];
}

/** Aplica os filtros a uma mensagem. */
function historico_casa_filtros(array $m, array $f): bool
{
    // Telefone (casa por parte).
    if (($f['telefone'] ?? '') !== '') {
        $alvo = preg_replace('/\D+/', '', (string)$f['telefone']);
        $fone = preg_replace('/\D+/', '', (string)($m['phone'] ?? ''));
        if ($alvo !== '' && !str_contains($fone, $alvo)) {
            return false;
        }
    }

    // Nome.
    if (($f['nome'] ?? '') !== '') {
        if (!str_contains(
            historico_sem_acento((string)($m['name'] ?? '')),
            historico_sem_acento((string)$f['nome'])
        )) {
            return false;
        }
    }

    // Texto livre (busca no texto E na transcricao).
    if (($f['texto'] ?? '') !== '') {
        $alvo = historico_sem_acento((string)$f['texto']);
        $campo = historico_sem_acento((string)($m['text'] ?? '') . ' ' . (string)($m['transcription'] ?? ''));
        if (!str_contains($campo, $alvo)) {
            return false;
        }
    }

    // Origem.
    $origemFiltro = strtolower(trim((string)($f['origem'] ?? '')));
    if ($origemFiltro !== '') {
        $temOrigem = !empty($m['origin']);
        if ($origemFiltro === 'com' && !$temOrigem) {
            return false;
        }
        if ($origemFiltro === 'sem' && $temOrigem) {
            return false;
        }
        if (!in_array($origemFiltro, ['com', 'sem'], true)) {
            if (!$temOrigem) {
                return false;
            }
            $plat = strtolower((string)($m['origin']['platform'] ?? ''));
            $det = strtolower((string)($m['origin']['detected_by'] ?? ''));
            if (!str_contains($plat, $origemFiltro) && !str_contains($det, $origemFiltro)) {
                return false;
            }
        }
    }

    // Tipo de midia.
    $tipo = strtolower(trim((string)($f['tipo'] ?? '')));
    if ($tipo !== '') {
        if ($tipo === 'audio_transcrito') {
            if (empty($m['transcription'])) {
                return false;
            }
        } elseif (strtolower((string)($m['media_type'] ?? '')) !== $tipo) {
            return false;
        }
    }

    // Direcao.
    $dir = strtolower(trim((string)($f['direcao'] ?? '')));
    if ($dir === 'recebida' && !empty($m['from_me'])) {
        return false;
    }
    if ($dir === 'enviada' && empty($m['from_me'])) {
        return false;
    }

    // Periodo (usa sent_at, com fallback para at).
    $de = trim((string)($f['de'] ?? ''));
    $ate = trim((string)($f['ate'] ?? ''));
    if ($de !== '' || $ate !== '') {
        $quando = substr((string)($m['sent_at'] ?? $m['at'] ?? ''), 0, 10);
        if ($quando === '') {
            return false;
        }
        if ($de !== '' && $quando < $de) {
            return false;
        }
        if ($ate !== '' && $quando > $ate) {
            return false;
        }
    }

    return true;
}

function historico_sem_acento(string $v): string
{
    $v = mb_strtolower($v, 'UTF-8');
    $map = [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ];
    return strtr($v, $map);
}

/**
 * Estatisticas do arquivo: totais, por dia, por origem, por tipo.
 * Le o arquivo inteiro (para os numeros serem exatos).
 */
function historico_estatisticas(): array
{
    $path = historico_conversas_path();
    if (!is_file($path)) {
        return [
            'total' => 0, 'recebidas' => 0, 'enviadas' => 0, 'audios' => 0,
            'autos_transcritos' => 0, 'com_origem' => 0, 'conversas' => 0,
            'por_dia' => [], 'por_origem' => [], 'por_tipo' => [], 'primeira' => '', 'ultima' => '',
        ];
    }

    $stats = [
        'total' => 0, 'recebidas' => 0, 'enviadas' => 0, 'audios' => 0,
        'autos_transcritos' => 0, 'com_origem' => 0,
        'por_dia' => [], 'por_origem' => [], 'por_tipo' => [],
        'primeira' => '', 'ultima' => '',
    ];
    $phones = [];
    $comOrigem = [];

    $fh = @fopen($path, 'r');
    if (!$fh) {
        return $stats + ['conversas' => 0];
    }
    while (($linha = fgets($fh)) !== false) {
        $m = json_decode(trim($linha), true);
        if (!is_array($m)) {
            continue;
        }
        $stats['total']++;
        if (!empty($m['from_me'])) {
            $stats['enviadas']++;
        } else {
            $stats['recebidas']++;
        }
        $tipo = strtolower((string)($m['media_type'] ?? 'other'));
        $stats['por_tipo'][$tipo] = ($stats['por_tipo'][$tipo] ?? 0) + 1;
        if ($tipo === 'audio') {
            $stats['audios']++;
            if (!empty($m['transcription'])) {
                $stats['autos_transcritos']++;
            }
        }
        if (!empty($m['origin'])) {
            $stats['com_origem']++;
            $plat = strtolower((string)($m['origin']['platform'] ?? 'outro')) ?: 'outro';
            $stats['por_origem'][$plat] = ($stats['por_origem'][$plat] ?? 0) + 1;
            $fone = (string)($m['phone'] ?? '');
            if ($fone !== '') {
                $comOrigem[$fone] = true;
            }
        }
        $dia = substr((string)($m['sent_at'] ?? $m['at'] ?? ''), 0, 10);
        if ($dia !== '') {
            $stats['por_dia'][$dia] = ($stats['por_dia'][$dia] ?? 0) + 1;
            if ($stats['primeira'] === '' || $dia < $stats['primeira']) {
                $stats['primeira'] = $dia;
            }
            if ($stats['ultima'] === '' || $dia > $stats['ultima']) {
                $stats['ultima'] = $dia;
            }
        }
        $fone = (string)($m['phone'] ?? '');
        if ($fone !== '') {
            $phones[$fone] = true;
        }
    }
    fclose($fh);

    ksort($stats['por_dia']);
    arsort($stats['por_origem']);
    arsort($stats['por_tipo']);

    return $stats + [
        'conversas' => count($phones),
        'conversas_com_origem' => count($comOrigem),
    ];
}

/**
 * Agrupa as mensagens por telefone (uma "conversa" por contato),
 * para a visao de lista. Sempre le do mais recente para o mais antigo.
 *
 * $offset permite paginar: a ordenacao e sempre "mais recente primeiro",
 * entao a fatia [offset, offset+limite) e estavel entre requisicoes.
 */
function historico_conversas_agrupadas(array $filtros = [], int $limite = 100, int $offset = 0): array
{
    $res = historico_ler_mensagens($filtros, 5000, 0);
    $porFone = [];
    foreach ($res['itens'] as $m) {
        // Junta a linha antiga (LID) com a conversa de telefone real.
        $fone = historico_telefone_canonico($m);
        if ($fone === '') {
            continue;
        }
        if (!isset($porFone[$fone])) {
            $porFone[$fone] = [
                'phone' => $fone,
                'name' => (string)($m['name'] ?? ''),
                'ultima_em' => (string)($m['sent_at'] ?? $m['at'] ?? ''),
                'total' => 0,
                'recebidas' => 0,
                'enviadas' => 0,
                'audios' => 0,
                'transcritos' => 0,
                'origem' => $m['origin'] ?? null,
                'preview' => '',
                // Ultima mensagem de cada lado: define se a conversa foi respondida.
                'ultima_cliente' => '',
                'ultima_atendimento' => '',
            ];
        }
        $quando = (string)($m['sent_at'] ?? $m['at'] ?? '');
        $porFone[$fone]['total']++;
        if (empty($m['from_me'])) {
            $porFone[$fone]['recebidas']++;
            if ($quando > $porFone[$fone]['ultima_cliente']) {
                $porFone[$fone]['ultima_cliente'] = $quando;
            }
        } else {
            $porFone[$fone]['enviadas']++;
            if ($quando > $porFone[$fone]['ultima_atendimento']) {
                $porFone[$fone]['ultima_atendimento'] = $quando;
            }
        }
        if (strtolower((string)($m['media_type'] ?? '')) === 'audio') {
            $porFone[$fone]['audios']++;
            if (!empty($m['transcription'])) {
                $porFone[$fone]['transcritos']++;
            }
        }
        if (!empty($m['name']) && $porFone[$fone]['name'] === '') {
            $porFone[$fone]['name'] = (string)$m['name'];
        }
        if (!empty($m['origin']) && empty($porFone[$fone]['origem'])) {
            $porFone[$fone]['origem'] = $m['origin'];
        }
        if ($porFone[$fone]['preview'] === '') {
            $txt = (string)($m['text'] ?? '');
            if ($txt === '') {
                $txt = '[' . (string)($m['media_type'] ?? 'midia') . ']';
            }
            $porFone[$fone]['preview'] = mb_substr($txt, 0, 120);
        }
    }

    // Estado de resposta: respondida quando o atendimento falou depois da ultima
    // mensagem do cliente. Sem mensagem do cliente, nao ha o que responder.
    foreach ($porFone as &$c) {
        $c['respondida'] = $c['ultima_cliente'] === ''
            ? true
            : ($c['ultima_atendimento'] !== '' && $c['ultima_atendimento'] >= $c['ultima_cliente']);
    }
    unset($c);

    // Filtro por estado de resposta (nivel conversa, nao mensagem).
    $resposta = strtolower(trim((string)($filtros['resposta'] ?? '')));
    if ($resposta === 'respondida' || $resposta === 'sem_resposta') {
        $querRespondida = $resposta === 'respondida';
        $porFone = array_filter($porFone, static fn(array $c): bool => (bool)$c['respondida'] === $querRespondida);
    }

    // Ordena pela mensagem mais recente.
    usort($porFone, static fn(array $a, array $b): int => strcmp((string)$b['ultima_em'], (string)$a['ultima_em']));

    $todas = array_values($porFone);
    $offset = max(0, $offset);

    return [
        'conversas' => array_slice($todas, $offset, $limite),
        'total_conversas' => count($todas),
        'offset' => $offset,
        'limite' => $limite,
    ];
}

/**
 * Indice leve (telefone/nome/ultima) para o autocomplete dos filtros.
 * Nao carrega as mensagens: so o suficiente para sugerir.
 */
function historico_indice_contatos(): array
{
    $res = historico_ler_mensagens([], 5000, 0);
    $porFone = [];
    foreach ($res['itens'] as $m) {
        $fone = historico_telefone_canonico($m);
        if ($fone === '') {
            continue;
        }
        if (!isset($porFone[$fone])) {
            $porFone[$fone] = ['phone' => $fone, 'name' => '', 'ultima_em' => ''];
        }
        if (!empty($m['name']) && $porFone[$fone]['name'] === '') {
            $porFone[$fone]['name'] = (string)$m['name'];
        }
        $quando = (string)($m['sent_at'] ?? $m['at'] ?? '');
        if ($quando > $porFone[$fone]['ultima_em']) {
            $porFone[$fone]['ultima_em'] = $quando;
        }
    }
    $out = array_values($porFone);
    usort($out, static fn(array $a, array $b): int => strcmp((string)$b['ultima_em'], (string)$a['ultima_em']));
    return $out;
}

/** Lista os telefones que ja tiveram origem de anuncio detectada. */
function historico_telefones_com_origem(): array
{
    $res = historico_ler_mensagens(['origem' => 'com'], 5000, 0);
    $out = [];
    foreach ($res['itens'] as $m) {
        $fone = (string)($m['phone'] ?? '');
        if ($fone !== '') {
            $out[$fone] = $m['origin'] ?? null;
        }
    }
    return $out;
}
