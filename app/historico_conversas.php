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

/**
 * Percorre o arquivo de conversas em STREAMING (uma linha por vez),
 * do mais recente para o mais antigo, sem carregar o arquivo na memoria.
 *
 * POR QUE EXISTE
 * A versao anterior lia todas as linhas para um array antes de filtrar. Com o
 * arquivo crescendo, isso vira estouro de memoria e resposta lenta. Aqui a
 * leitura e incremental: o callback decide o que guardar.
 *
 * TECNICA: NDJSON e append-only, entao cada linha e um JSON completo. Da para
 * ler o arquivo de tras para frente em blocos e emitir as linhas na ordem
 * "mais recente primeiro" sem nunca ter o arquivo inteiro em memoria.
 *
 * $callback(array $mensagem): void - chamado por linha (mais recente primeiro)
 * $pararQuando(): bool             - o callback sinaliza quando ja tem o suficiente
 *
 * Devolve quantas linhas validas foram lidas ate parar (para contadores).
 */
function historico_percorrer_reverso(callable $callback, ?callable $pararQuando = null, int $bloco = 262144): int
{
    $path = historico_conversas_path();
    if (!is_file($path)) {
        return 0;
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return 0;
    }
    $stat = fstat($fh);
    $tamanho = (int)($stat['size'] ?? 0);
    if ($tamanho <= 0) {
        fclose($fh);
        return 0;
    }

    $total = 0;
    $sobra = '';     // inicio da linha cortada entre blocos
    $pos = $tamanho; // cursor lendo de tras para frente

    while ($pos > 0) {
        $tam = min($bloco, $pos);
        $pos -= $tam;
        if (fseek($fh, $pos) !== 0) {
            break;
        }
        $pedaco = fread($fh, $tam);
        if ($pedaco === false || $pedaco === '') {
            break;
        }
        // Junta com a sobra do bloco anterior (o comeco daquela linha).
        $buffer = $pedaco . $sobra;
        $linhas = explode("\n", $buffer);
        // O primeiro item e linha incompleta (continua no bloco anterior).
        $sobra = array_shift($linhas);

        for ($i = count($linhas) - 1; $i >= 0; $i--) {
            $linha = trim($linhas[$i]);
            if ($linha === '') {
                continue;
            }
            $m = json_decode($linha, true);
            if (!is_array($m)) {
                continue;
            }
            $total++;
            $callback($m);
            if ($pararQuando !== null && $pararQuando()) {
                fclose($fh);
                return $total;
            }
        }
    }
    // Primeira linha do arquivo (nao tem \n antes dela).
    $linha = trim($sobra);
    if ($linha !== '') {
        $m = json_decode($linha, true);
        if (is_array($m)) {
            $total++;
            $callback($m);
        }
    }
    fclose($fh);
    return $total;
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
    return historico_ponte_porta() > 0;
}

/** Porta do painel da ponte (0 quando nao responde). */
function historico_ponte_porta(int $porta = 3210): int
{
    $fp = @fsockopen('127.0.0.1', $porta, $errno, $errstr, 0.6);
    if (is_resource($fp)) {
        fclose($fp);
        return $porta;
    }
    return 0;
}

/**
 * Remove duplicatas de ENVIO do arquivo da ponte.
 *
 * POR QUE EXISTE
 * Quando o CRM responde, a ponte grava uma linha marcada `enviado_pelo_crm`.
 * Pouco depois o proprio WhatsApp devolve o eco da mesma mensagem como
 * `from_me` (sem marca), o que apareceria como bolha repetida no chat.
 * Aqui a linha do eco e descartada quando ja existe a do envio do CRM com o
 * MESMO texto e horario proximo.
 *
 * $itens deve estar ordenado cronologicamente (mais antigo primeiro).
 */
function historico_deduplicar_envios(array $itens): array
{
    $out = [];
    foreach ($itens as $m) {
        $ehEco = !empty($m['from_me']) && empty($m['enviado_pelo_crm']);
        if ($ehEco) {
            $texto = trim((string)($m['text'] ?? ''));
            $quando = strtotime((string)($m['sent_at'] ?? $m['at'] ?? '')) ?: 0;
            $dup = false;
            // Compara com as ultimas linhas ja aceitas (janela curta).
            for ($i = count($out) - 1, $n = 0; $i >= 0 && $n < 8; $i--, $n++) {
                $ant = $out[$i];
                if (empty($ant['enviado_pelo_crm'])) {
                    continue;
                }
                $textoAnt = trim((string)($ant['text'] ?? ''));
                $quandoAnt = strtotime((string)($ant['sent_at'] ?? $ant['at'] ?? '')) ?: 0;
                if ($texto !== '' && $texto === $textoAnt && abs($quando - $quandoAnt) <= 120) {
                    $dup = true;
                    break;
                }
            }
            if ($dup) {
                continue;
            }
        }
        $out[] = $m;
    }
    return $out;
}

/**
 * Historico UNIFICADO de uma conversa: arquivo da ponte + banco do CRM.
 *
 * POR QUE JUNTAR
 * O arquivo da ponte tem o que chegou e o que a ponte enviou; o banco do CRM
 * tem o que foi respondido por dentro do CRM. Nenhuma das duas fontes sozinha
 * mostra a conversa inteira, entao aqui elas sao unidas por telefone e ordenadas
 * cronologicamente.
 *
 * Devolve lista de itens no formato das mensagens da ponte, com um campo
 * `fonte` = 'ponte' | 'crm' e `local_id` quando vier do banco.
 */
function historico_mensagens_unificadas(array $studio, string $telefone, int $limite = 500): array
{
    $itens = [];
    $vistos = [];

    // 1) Arquivo da ponte (mais recentes primeiro; invertemos no fim).
    $conv = historico_ler_mensagens(['telefone' => $telefone], $limite, 0, 'antigas');
    foreach ($conv['itens'] as $m) {
        $chave = 'ponte|' . ($m['sent_at'] ?? $m['at'] ?? '') . '|' . (string)($m['text'] ?? '') . '|' . (string)($m['media_type'] ?? '');
        if (isset($vistos[$chave])) {
            continue;
        }
        $vistos[$chave] = true;
        $m['fonte'] = 'ponte';
        $itens[] = $m;
    }

    // 2) Banco do CRM (upload/mensagens da API), casando pelo telefone.
    try {
        $pdo = studio_db($studio);
        $alvo = preg_replace('/\D+/', '', $telefone);
        $convDb = null;
        $st = $pdo->prepare("SELECT id FROM whatsapp_conversations WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-',''),' ','') LIKE ? ORDER BY id DESC LIMIT 1");
        $st->execute(['%' . $alvo . '%']);
        $convId = (int)($st->fetchColumn() ?: 0);
        if ($convId > 0) {
            $msgSt = $pdo->prepare('SELECT id, message_id, direction, body, message_type, media_path, created_at, sender_type FROM whatsapp_messages WHERE conversation_id = ? ORDER BY created_at ASC, id ASC LIMIT ' . max(1, min(2000, $limite)));
            $msgSt->execute([$convId]);
            foreach ($msgSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $texto = (string)($row['body'] ?? '');
                $quando = (string)($row['created_at'] ?? '');
                $chave = 'crm|' . $quando . '|' . $texto . '|' . (string)($row['message_type'] ?? '');
                if (isset($vistos[$chave])) {
                    continue;
                }
                // Evita duplicar o que ja veio da ponte (mesmo texto no mesmo minuto).
                $dupPonte = false;
                $minutoCrm = substr($quando, 0, 16);
                foreach ($itens as $existe) {
                    if (($existe['fonte'] ?? '') !== 'ponte') {
                        continue;
                    }
                    $minutoPonte = substr((string)($existe['sent_at'] ?? $existe['at'] ?? ''), 0, 16);
                    if ($minutoCrm !== '' && $minutoCrm === $minutoPonte && trim((string)($existe['text'] ?? '')) === trim($texto)) {
                        $dupPonte = true;
                        break;
                    }
                }
                if ($dupPonte) {
                    continue;
                }
                $vistos[$chave] = true;
                $itens[] = [
                    'at' => $quando,
                    'sent_at' => $quando,
                    'phone' => $alvo,
                    'name' => '',
                    'from_me' => strtolower((string)($row['direction'] ?? '')) === 'outbound',
                    'media_type' => (string)($row['message_type'] ?? 'text'),
                    'text' => $texto,
                    'transcription' => null,
                    'origin' => null,
                    'fonte' => 'crm',
                    'local_id' => (int)($row['id'] ?? 0),
                ];
            }
        }
    } catch (Throwable $e) {
        // Sem banco, so a ponte: melhor mostrar parte que quebrar a pagina.
    }

    usort($itens, static function (array $a, array $b): int {
        $qa = (string)($a['sent_at'] ?? $a['at'] ?? '');
        $qb = (string)($b['sent_at'] ?? $b['at'] ?? '');
        return strcmp($qa, $qb);
    });

    return historico_deduplicar_envios($itens);
}

/**
 * Envia uma resposta pelo Baileys (o numero que RECEBEU a mensagem).
 *
 * POR QUE ASSIM
 * O CRM responde pelo nao-oficial, nao pela API oficial. Quem tem o socket
 * pareado e a ponte, que expoe POST /send em 127.0.0.1 (nunca na internet).
 * Aqui so falamos com esse endpoint local.
 *
 * Devolve ['ok'=>bool, 'error'=>string, 'messageId'=>string].
 */
function historico_ponte_enviar(string $telefone, string $texto, string $nome = '', string $ator = ''): array
{
    $porta = historico_ponte_porta();
    if ($porta === 0) {
        return ['ok' => false, 'error' => 'Ponte do WhatsApp offline (nao da para enviar agora).'];
    }
    $texto = trim($texto);
    if ($texto === '') {
        return ['ok' => false, 'error' => 'Mensagem vazia.'];
    }
    $destino = preg_replace('/\D+/', '', $telefone);
    if ($destino === '') {
        return ['ok' => false, 'error' => 'Telefone invalido para envio.'];
    }

    $payload = json_encode([
        'to' => $destino,
        'message' => $texto,
        'name' => $nome,
        'by' => $ator,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('http://127.0.0.1:' . $porta . '/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resposta = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        return ['ok' => false, 'error' => 'Falha ao falar com a ponte: ' . $erroCurl];
    }
    $json = json_decode((string)$resposta, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Resposta inesperada da ponte (HTTP ' . $codigo . ').'];
    }
    if (empty($json['ok'])) {
        return ['ok' => false, 'error' => (string)($json['error'] ?? 'A ponte recusou o envio.')];
    }
    return ['ok' => true, 'error' => '', 'messageId' => (string)($json['messageId'] ?? '')];
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
function historico_ler_mensagens(array $filtros = [], int $limite = 200, int $offset = 0, string $ordenar = 'recentes'): array
{
    if (!is_file(historico_conversas_path())) {
        return ['itens' => [], 'total_filtrado' => 0, 'total_arquivo' => 0, 'truncado' => false];
    }

    // A leitura em streaming e sempre "mais recente primeiro". Para as mais
    // antigas nao precisamos guardar todas: basta manter uma JANELA com os
    // (offset+limite) mais antigos vistos ate agora. A memoria fica limitada ao
    // tamanho da pagina, nao ao filtro inteiro.
    if ($ordenar === 'antigas') {
        $janela = [];           // fila dos (offset+limite) mais antigos, do mais novo ao mais antigo
        $capacidade = $offset + $limite;
        $totalFiltrado = 0;
        historico_percorrer_reverso(function (array $m) use ($filtros, &$janela, $capacidade, &$totalFiltrado): void {
            if (!historico_casa_filtros($m, $filtros)) {
                return;
            }
            $totalFiltrado++;
            if ($capacidade <= 0) {
                return;
            }
            // Como o streaming vem do mais novo para o mais antigo, cada novo item
            // e mais ANTIGO que todos os da janela: entra na frente e, se passou
            // da capacidade, o mais novo (ultimo) sai.
            array_unshift($janela, $m);
            if (count($janela) > $capacidade) {
                array_pop($janela);
            }
        });
        // $janela esta do mais antigo (indice 0) para o mais novo; a pagina
        // pedida comeca em $offset dentro dessa janela ja ordenada.
        return [
            'itens' => array_slice($janela, $offset, $limite),
            'total_filtrado' => $totalFiltrado,
            'total_arquivo' => historico_total_linhas(),
            'truncado' => $totalFiltrado > ($offset + $limite),
        ];
    }

    $itens = [];
    $filtrados = 0;
    $ultimoIndice = $offset + $limite; // quantos filtrados precisamos ate poder parar

    historico_percorrer_reverso(
        function (array $m) use ($filtros, $offset, $limite, &$itens, &$filtrados): void {
            if (!historico_casa_filtros($m, $filtros)) {
                return;
            }
            $filtrados++;
            if ($filtrados <= $offset) {
                return; // antes da janela
            }
            if (count($itens) < $limite) {
                $itens[] = $m;
            }
        },
        function () use (&$filtrados, $ultimoIndice): bool {
            // Ja passou da janela: nada mais a montar.
            return $filtrados >= $ultimoIndice + 1;
        }
    );

    // Total do arquivo vem do contador cacheado (nao depende de onde paramos).
    $totalArquivo = historico_total_linhas();

    // Se paramos por ja ter a janela + 1, existe pelo menos mais uma mensagem:
    // ai o total filtrado exige varrer o resto.
    $truncado = $filtrados > ($offset + $limite);
    $totalFiltrado = $truncado ? historico_contar_mensagens($filtros) : $filtrados;

    return [
        'itens' => $itens,
        'total_filtrado' => $totalFiltrado,
        'total_arquivo' => $totalArquivo,
        'truncado' => $truncado,
    ];
}

/**
 * Conta mensagens de um filtro. Usa um indice por hash do arquivo quando
 * disponivel (invalidado quando o arquivo muda), para nao varrer a cada pagina.
 */
function historico_contar_mensagens(array $filtros = []): int
{
    if (!$filtros) {
        // Sem filtro, o total e o numero de linhas validas do arquivo.
        return historico_total_linhas();
    }
    $n = 0;
    historico_percorrer_reverso(function (array $m) use ($filtros, &$n): void {
        if (historico_casa_filtros($m, $filtros)) {
            $n++;
        }
    });
    return $n;
}

/**
 * Total de linhas validas do arquivo, com cache por (caminho, tamanho, mtime).
 * Enquanto a ponte nao escreve nada novo, o numero nao muda - entao nao ha
 * por que varrer o arquivo a cada requisicao.
 */
function historico_total_linhas(): int
{
    static $cache = [];
    $path = historico_conversas_path();
    if (!is_file($path)) {
        return 0;
    }
    $stat = @stat($path);
    if (!$stat) {
        return 0;
    }
    $chave = $path . '|' . (int)$stat['size'] . '|' . (int)$stat['mtime'];
    if (isset($cache[$chave])) {
        return $cache[$chave];
    }
    $n = 0;
    historico_percorrer_reverso(function () use (&$n): void {
        $n++;
    });
    $cache[$chave] = $n;
    return $n;
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
 * Streaming: percorre linha a linha e acumula contadores, sem carregar o
 * arquivo nem as mensagens na memoria. Cada chave guardada e um dia/origem/tipo
 * (poucas dezenas), nunca uma mensagem.
 */
function historico_estatisticas(): array
{
    $stats = [
        'total' => 0, 'recebidas' => 0, 'enviadas' => 0, 'audios' => 0,
        'autos_transcritos' => 0, 'com_origem' => 0,
        'por_dia' => [], 'por_origem' => [], 'por_tipo' => [],
        'primeira' => '', 'ultima' => '',
    ];
    $phones = [];
    $comOrigem = [];

    historico_percorrer_reverso(function (array $m) use (&$stats, &$phones, &$comOrigem): void {
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
            $foneC = historico_telefone_canonico($m);
            if ($foneC !== '') {
                $comOrigem[$foneC] = true;
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
        $fone = historico_telefone_canonico($m);
        if ($fone !== '') {
            $phones[$fone] = true;
        }
    });

    ksort($stats['por_dia']);
    arsort($stats['por_origem']);
    arsort($stats['por_tipo']);

    return $stats + [
        'conversas' => count($phones),
        'conversas_com_origem' => count($comOrigem),
    ];
}

/**
 * Agrupa as mensagens por telefone (uma "conversa" por contato).
 *
 * MEMORIA: percorre o arquivo em STREAMING (nunca carrega o arquivo todo) e
 * guarda apenas UM registro por contato - o que cresce e o numero de contatos,
 * nao o de mensagens. O preview guarda so o texto mais recente.
 *
 * $ordenar: 'recentes' (padrao) | 'antigas' | 'nome' | 'msgs' | 'origem'.
 * $offset/$limite paginam depois de ordenar, entao a fatia e estavel.
 */
function historico_conversas_agrupadas(array $filtros = [], int $limite = 100, int $offset = 0, string $ordenar = 'recentes'): array
{
    $porFone = [];

    historico_percorrer_reverso(function (array $m) use ($filtros, &$porFone): void {
        if (!historico_casa_filtros($m, $filtros)) {
            return;
        }
        // Junta a linha antiga (LID) com a conversa de telefone real.
        $fone = historico_telefone_canonico($m);
        if ($fone === '') {
            return;
        }
        if (!isset($porFone[$fone])) {
            $porFone[$fone] = [
                'phone' => $fone,
                'name' => '',
                'ultima_em' => '',
                'total' => 0,
                'recebidas' => 0,
                'enviadas' => 0,
                'audios' => 0,
                'transcritos' => 0,
                'origem' => null,
                'preview' => '',
                'ultima_cliente' => '',
                'ultima_atendimento' => '',
            ];
        }
        $quando = (string)($m['sent_at'] ?? $m['at'] ?? '');
        $porFone[$fone]['total']++;
        // Como varremos do mais recente para o mais antigo, o PRIMEIRO valor
        // visto de cada campo e o mais recente: basta gravar se ainda vazio.
        if ($porFone[$fone]['ultima_em'] === '') {
            $porFone[$fone]['ultima_em'] = $quando;
            $porFone[$fone]['preview'] = mb_substr(
                (string)($m['text'] ?? '') !== ''
                    ? (string)$m['text']
                    : '[' . (string)($m['media_type'] ?? 'midia') . ']',
                0,
                120
            );
        }
        if (!empty($m['name']) && $porFone[$fone]['name'] === '') {
            $porFone[$fone]['name'] = (string)$m['name'];
        }
        if (!empty($m['origin']) && empty($porFone[$fone]['origem'])) {
            $porFone[$fone]['origem'] = $m['origin'];
        }
        if (empty($m['from_me'])) {
            $porFone[$fone]['recebidas']++;
            if ($porFone[$fone]['ultima_cliente'] === '') {
                $porFone[$fone]['ultima_cliente'] = $quando;
            }
        } else {
            $porFone[$fone]['enviadas']++;
            if ($porFone[$fone]['ultima_atendimento'] === '') {
                $porFone[$fone]['ultima_atendimento'] = $quando;
            }
        }
        if (strtolower((string)($m['media_type'] ?? '')) === 'audio') {
            $porFone[$fone]['audios']++;
            if (!empty($m['transcription'])) {
                $porFone[$fone]['transcritos']++;
            }
        }
    });

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

    // Ordenacao (escolhida pelo cabecalho da tabela na pagina).
    $cmpTexto = static fn(string $a, string $b): int => strcmp(historico_sem_acento($a), historico_sem_acento($b));
    switch ($ordenar) {
        case 'antigas':
            usort($porFone, static fn(array $a, array $b): int => strcmp((string)$a['ultima_em'], (string)$b['ultima_em']));
            break;
        case 'nome':
            usort($porFone, static fn(array $a, array $b): int => $cmpTexto((string)$a['name'], (string)$b['name']));
            break;
        case 'nome_desc':
            usort($porFone, static fn(array $a, array $b): int => $cmpTexto((string)$b['name'], (string)$a['name']));
            break;
        case 'msgs':
            usort($porFone, static fn(array $a, array $b): int => ((int)$a['total']) <=> ((int)$b['total']));
            break;
        case 'msgs_desc':
            usort($porFone, static fn(array $a, array $b): int => ((int)$b['total']) <=> ((int)$a['total']));
            break;
        case 'origem':
            usort($porFone, static function (array $a, array $b) use ($cmpTexto): int {
                return $cmpTexto((string)($a['origem']['platform'] ?? ''), (string)($b['origem']['platform'] ?? ''))
                    ?: strcmp((string)$b['ultima_em'], (string)$a['ultima_em']);
            });
            break;
        case 'origem_desc':
            usort($porFone, static function (array $a, array $b) use ($cmpTexto): int {
                return $cmpTexto((string)($b['origem']['platform'] ?? ''), (string)($a['origem']['platform'] ?? ''))
                    ?: strcmp((string)$b['ultima_em'], (string)$a['ultima_em']);
            });
            break;
        case 'resposta':
            // Sem resposta primeiro (e o que precisa de atencao).
            usort($porFone, static fn(array $a, array $b): int => ((int)$a['respondida']) <=> ((int)$b['respondida'])
                ?: strcmp((string)$b['ultima_em'], (string)$a['ultima_em']));
            break;
        case 'resposta_desc':
            usort($porFone, static fn(array $a, array $b): int => ((int)$b['respondida']) <=> ((int)$a['respondida'])
                ?: strcmp((string)$b['ultima_em'], (string)$a['ultima_em']));
            break;
        case 'recentes':
        default:
            usort($porFone, static fn(array $a, array $b): int => strcmp((string)$b['ultima_em'], (string)$a['ultima_em']));
            break;
    }

    $todas = array_values($porFone);
    $offset = max(0, $offset);

    return [
        'conversas' => array_slice($todas, $offset, $limite),
        'total_conversas' => count($todas),
        'offset' => $offset,
        'limite' => $limite,
        'ordenar' => $ordenar,
    ];
}

/**
 * Indice leve (telefone/nome/ultima) para o autocomplete dos filtros.
 * Streaming: guarda um registro por contato, nunca as mensagens.
 */
function historico_indice_contatos(): array
{
    $porFone = [];
    historico_percorrer_reverso(function (array $m) use (&$porFone): void {
        $fone = historico_telefone_canonico($m);
        if ($fone === '') {
            return;
        }
        if (!isset($porFone[$fone])) {
            $porFone[$fone] = [
                'phone' => $fone,
                'name' => '',
                'ultima_em' => (string)($m['sent_at'] ?? $m['at'] ?? ''),
            ];
        }
        if (!empty($m['name']) && $porFone[$fone]['name'] === '') {
            $porFone[$fone]['name'] = (string)$m['name'];
        }
    });
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
