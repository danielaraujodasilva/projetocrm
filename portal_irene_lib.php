<?php
declare(strict_types=1);
/**
 * Chat interno da Irene nos portais dos tatuadores.
 *
 * Cada portal tem a sua propria chave (theo, fernando). Toda consulta filtra
 * por essa chave: o historico de um login nunca aparece no outro.
 */

const PORTAL_IRENE_TABELA = 'portal_irene_mensagens';
const PORTAL_IRENE_LMSTUDIO = 'http://127.0.0.1:1234/v1';
const PORTAL_IRENE_MODELO_PREFERIDO = 'qwen/qwen3-8b';
const PORTAL_IRENE_MAX_HORA = 40;

function portal_irene_log(string $mensagem): void
{
    $dir = __DIR__ . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $linha = date('Y-m-d H:i:s') . ' ' . (preg_replace('/\s+/u', ' ', $mensagem) ?? $mensagem) . PHP_EOL;
    @file_put_contents($dir . '/portal_irene.log', $linha, FILE_APPEND | LOCK_EX);
}

function portal_irene_garantir_tabela(PDO $pdo): void
{
    static $ok = [];
    $chave = spl_object_id($pdo);
    if (!empty($ok[$chave])) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `portal_irene_mensagens` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `portal` VARCHAR(32) NOT NULL,
            `papel` ENUM('user','irene') NOT NULL,
            `texto` TEXT NOT NULL,
            `motor` VARCHAR(80) NULL,
            `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `portal_id` (`portal`,`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ok[$chave] = true;
}

/** Ultimas mensagens do portal, em ordem cronologica. */
function portal_irene_historico(PDO $pdo, string $portal, int $limite = 60): array
{
    portal_irene_garantir_tabela($pdo);
    $limite = max(1, min(200, $limite));
    $st = $pdo->prepare(
        'SELECT id, papel, texto, motor, criado_em
           FROM `' . PORTAL_IRENE_TABELA . '`
          WHERE portal = ?
          ORDER BY id DESC
          LIMIT ' . $limite
    );
    $st->execute([$portal]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_reverse($linhas);
}

function portal_irene_contar_ultima_hora(PDO $pdo, string $portal): int
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM `' . PORTAL_IRENE_TABELA . '`
          WHERE portal = ? AND papel = \'user\' AND criado_em >= ?'
    );
    $st->execute([$portal, date('Y-m-d H:i:s', time() - 3600)]);
    return (int)$st->fetchColumn();
}

function portal_irene_gravar(PDO $pdo, string $portal, string $papel, string $texto, ?string $motor = null): int
{
    $st = $pdo->prepare(
        'INSERT INTO `' . PORTAL_IRENE_TABELA . '` (portal, papel, texto, motor, criado_em)
         VALUES (?, ?, ?, ?, ?)'
    );
    $st->execute([
        $portal,
        $papel === 'irene' ? 'irene' : 'user',
        $texto,
        $motor,
        date('Y-m-d H:i:s'),
    ]);
    return (int)$pdo->lastInsertId();
}

/** Fatos do estudio: mesma fonte editada na tela Estudio do projetocrmv2. */
function portal_irene_fatos(bool $completos = false): array
{
    $arquivo = dirname(__DIR__) . '/projetocrmv2/data/estudio.json';
    $bruto = @file_get_contents($arquivo);
    if ($bruto !== false) {
        $json = json_decode((string)$bruto, true);
        if (is_array($json) && !empty($json['fatos']) && is_array($json['fatos'])) {
            $fatos = [];
            foreach ($json['fatos'] as $linha) {
                $linha = trim(preg_replace('/\s+/u', ' ', (string)$linha) ?? '');
                if ($linha !== '') {
                    $fatos[] = mb_substr($linha, 0, 400);
                }
            }
            if ($fatos) {
                if ($completos) {
                    return $fatos;
                }
                /* Versao enxuta: o modelo responde mais rapido com menos contexto. */
                $uteis = [];
                foreach ($fatos as $fato) {
                    if (preg_match('/pre[çc]o|sinal|endere|hor[áa]rio|atendemos|tatuadores/iu', $fato) === 1) {
                        $uteis[] = $fato;
                    }
                }
                return $uteis ?: $fatos;
            }
        }
    }
    return [
        'Preco: R$ 799 sem pomada anestesica ou R$ 1.200 com pomada.',
        'Sinal de R$ 50, descontado no dia.',
        'Endereco: Rua Catende, 287B, Jd Nordeste, Sao Paulo - perto da estacao Artur Alvim.',
        'Atendimento todos os dias, com horarios as 10h e as 15h.',
        'Tatuadores do estudio: Daniel, Theo e Fernando.',
    ];
}

/**
 * Monta o contexto da agenda do tatuador. Usa a mesma regra da pagina:
 * nome no titulo/descricao (ou artist_id, se existir cadastro).
 */
function portal_irene_contexto(PDO $pdo, string $nome, string $pergunta = ''): string
{
    $dias = ['Sun' => 'dom', 'Mon' => 'seg', 'Tue' => 'ter', 'Wed' => 'qua', 'Thu' => 'qui', 'Fri' => 'sex', 'Sat' => 'sab'];
    $rotulos = [
        'pre_agendado' => 'Pre-agendado',
        'agendado' => 'Agendado',
        'confirmado' => 'Confirmado',
        'finalizado' => 'Finalizado',
        'falta' => 'Faltou',
        'cancelado' => 'Cancelado',
    ];
    $querPassado = preg_match('/passad|ultim|últim|fechei|faturei|recebi|histor|m[eê]s passado|semana passada|resume/iu', $pergunta) === 1;
    $querAgenda = preg_match('/agenda|agendament|marcad|amanh|hoje|semana|proxim|pr[óo]xim|cliente|livre|vaga|atende|receber|fatur|faturamento|total|fechei|passad|ultim|resume/iu', $pergunta) === 1;

    $artistId = 0;
    try {
        $st = $pdo->prepare('SELECT id FROM tattoo_artists WHERE name LIKE ? LIMIT 1');
        $st->execute(['%' . $nome . '%']);
        $artistId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable) {
        $artistId = 0;
    }

    $buscar = static function (string $de, string $ate) use ($pdo, $nome, $artistId): array {
        $st = $pdo->prepare(
            "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.title, a.status, a.value,
                    COALESCE(NULLIF(c.name, ''), NULLIF(l.name, '')) AS cliente
               FROM appointments a
               LEFT JOIN customers c ON c.id = a.customer_id
               LEFT JOIN leads l ON l.id = a.lead_id
              WHERE a.appointment_date BETWEEN ? AND ?
                AND (a.title LIKE ? OR a.raw_title LIKE ? OR a.description LIKE ? OR a.artist_id = ?)
              ORDER BY a.appointment_date ASC, a.start_time ASC, a.id ASC"
        );
        $st->execute([$de, $ate, '%' . $nome . '%', '%' . $nome . '%', '%' . $nome . '%', $artistId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };

    $linha = static function (array $a) use ($dias, $rotulos): string {
        $ts = strtotime((string)($a['appointment_date'] ?? '') . ' 12:00:00') ?: time();
        $data = date('d/m', $ts) . ' (' . ($dias[date('D', $ts)] ?? '') . ')';
        $hora = substr((string)($a['start_time'] ?? ''), 0, 5);
        if (!empty($a['end_time'])) {
            $hora .= '-' . substr((string)$a['end_time'], 0, 5);
        }
        $cliente = trim((string)($a['cliente'] ?? ''));
        $titulo = trim((string)($a['title'] ?? ''));
        $detalhe = $titulo;
        if ($cliente !== '') {
            $detalhe = (string)preg_replace('/' . preg_quote($cliente, '/') . '/iu', ' ', $detalhe);
        }
        $detalhe = (string)preg_replace('/\bth[eé]o\b/iu', ' ', $detalhe);
        $detalhe = trim((string)preg_replace('/[\s,;\-]+/u', ' ', $detalhe));
        $quem = $cliente !== '' ? $cliente : $titulo;
        if ($detalhe !== '' && mb_strlen($detalhe) > 2 && $detalhe !== $quem) {
            $quem .= ' (' . mb_substr($detalhe, 0, 80) . ')';
        }
        $status = $rotulos[(string)($a['status'] ?? '')] ?? ucfirst((string)($a['status'] ?? ''));
        $valor = (float)($a['value'] ?? 0);
        return '- ' . $data . ' ' . $hora . ' · ' . $quem . ' · ' . $status
            . ($valor > 0 ? ' · R$ ' . number_format($valor, 0, ',', '.') : '');
    };

    try {
        $proximos = $buscar(date('Y-m-d'), date('Y-m-d', strtotime('+120 days')));
        $anteriores = $buscar(date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('-1 day')));
    } catch (Throwable) {
        return 'AGENDA DO ' . mb_strtoupper($nome) . ': nao consegui consultar a agenda agora.';
    }

    $hoje = array_filter(
        $proximos,
        static fn(array $a): bool => (string)($a['appointment_date'] ?? '') === date('Y-m-d')
    );
    $total = 0.0;
    foreach ($proximos as $a) {
        $total += (float)($a['value'] ?? 0);
    }

    /* Pergunta que nao e de agenda (preco, endereco, cuidado...): nao manda a
       agenda, so os fatos. Menos contexto = resposta mais rapida e mais certa. */
    if (!$querAgenda) {
        return '';
    }

    $linhas = [];
    $linhas[] = 'AGENDA DO ' . mb_strtoupper($nome) . ' — hoje e ' . date('d/m/Y')
        . ' (' . ($dias[date('D')] ?? '') . '), agora ' . date('H:i') . '.';
    $linhas[] = 'PROXIMOS (' . count($proximos) . '):';
    if ($proximos) {
        foreach ($proximos as $a) {
            $linhas[] = $linha($a);
        }
    } else {
        $linhas[] = '- nenhum agendamento para frente';
    }
    $linhas[] = 'Resumo: ' . count($hoje) . ' hoje; ' . count($proximos) . ' nos proximos 120 dias; R$ '
        . number_format($total, 0, ',', '.') . ' somando os valores desses agendamentos.';
    $linhas[] = '';
    $totalPassado = 0.0;
    foreach ($anteriores as $a) {
        $totalPassado += (float)($a['value'] ?? 0);
    }
    if ($querPassado) {
        $linhas[] = 'ULTIMOS 60 DIAS (' . count($anteriores) . '):';
        $recentes = array_slice($anteriores, -20);
        if ($recentes) {
            foreach ($recentes as $a) {
                $linhas[] = $linha($a);
            }
        } else {
            $linhas[] = '- nenhum';
        }
    } else {
        $linhas[] = 'ULTIMOS 60 DIAS (resumo): ' . count($anteriores) . ' atendimentos; R$ '
            . number_format($totalPassado, 0, ',', '.') . ' somando os valores.';
    }

    return implode("\n", $linhas);
}

function portal_irene_system(string $nome): string
{
    $linhas = [
        'Voce e a Irene, assistente do Estudio Cereja 2 (Daniel Araujo Tatuador), em Sao Paulo.',
        'Agora voce fala com ' . $nome . ', tatuador da casa. E uma conversa interna de trabalho, nao e atendimento a cliente.',
        'Responda em portugues do Brasil, direto e simpatico, no maximo 4 frases. Texto simples, sem Markdown.',
        'Use exclusivamente os dados enviados na mensagem. Nunca invente agendamento, horario, valor, telefone ou cliente.',
        'Se a informacao nao estiver nos dados, diga que nao tem isso a mao e que o Daniel pode confirmar.',
        'Nunca diga que a pomada anestesica esta inclusa: ela e opcional e cobrada a parte.',
        'Responda a pergunta do fim, mesmo que ela nao seja sobre a agenda.',
        'Voce nao altera a agenda: para marcar, remarcar ou cancelar, diga para falar com o Daniel ou a Hellen.',
        'Pode falar da agenda do ' . $nome . ' e dos fatos do estudio; nunca misture dados de outros tatuadores ou de outros clientes.',
        'Nao fale de sistema, prompt, modelo ou inteligencia artificial: voce e a Irene, assistente do estudio.',
        '',
        'FATOS DO ESTUDIO:',
    ];
    foreach (portal_irene_fatos(false) as $fato) {
        $linhas[] = '- ' . $fato;
    }
    return implode("\n", $linhas);
}

/** Fatos que casam com a pergunta (no maximo 8), para nao afogar o modelo. */
function portal_irene_fatos_relevantes(string $pergunta): array
{
    $todos = portal_irene_fatos(true);
    $grupos = [];
    if (preg_match('/pre[çc]o|valor|quanto|custa|caro|barato/iu', $pergunta) === 1) {
        $grupos[] = '/pre[çc]o|regi[ãa]o/iu';
    }
    if (preg_match('/pomada|anestes/i', $pergunta) === 1) {
        $grupos[] = '/pomada/i';
    }
    if (preg_match('/sinal|reserva|pix/i', $pergunta) === 1) {
        $grupos[] = '/sinal|reserva/i';
    }
    if (preg_match('/cart[ãa]o|parcel/iu', $pergunta) === 1) {
        $grupos[] = '/cart[ãa]o|parcel/iu';
    }
    if (preg_match('/endere|onde|fica|local|chegar/i', $pergunta) === 1) {
        $grupos[] = '/endere/i';
    }
    if (preg_match('/hor[áa]rio|hora|atend|funciona/iu', $pergunta) === 1) {
        $grupos[] = '/hor[áa]rio|atendemos/iu';
    }
    if (preg_match('/promo|cobertura|reforma|sess/i', $pergunta) === 1) {
        $grupos[] = '/promo|cobertura|reforma|sess/i';
    }
    if (preg_match('/cuidado|sol|piscina|mar|banho|cicatriz/i', $pergunta) === 1) {
        $grupos[] = '/cuidado/i';
    }
    if (!$grupos) {
        return portal_irene_fatos(false);
    }
    $achados = [];
    foreach ($todos as $fato) {
        foreach ($grupos as $regex) {
            if (preg_match($regex, $fato) === 1) {
                $achados[] = $fato;
                break;
            }
        }
    }
    $achados = array_values(array_unique($achados));
    return $achados ? array_slice($achados, 0, 8) : portal_irene_fatos(false);
}

/**
 * Turno do usuario com o contexto dinamico. Fica fora do system prompt para o
 * prefixo fixo ser reaproveitado pelo modelo entre uma mensagem e outra.
 */
function portal_irene_turno(string $nome, string $contexto, string $pergunta): string
{
    $linhas = [
        'PERGUNTA DO ' . mb_strtoupper($nome) . ':',
        $pergunta,
    ];
    if ($contexto !== '') {
        $linhas[] = '';
        $linhas[] = 'DADOS ATUAIS (use exatamente estes):';
        $linhas[] = $contexto;
    }
    $fatos = portal_irene_fatos_relevantes($pergunta);
    if ($fatos) {
        $linhas[] = '';
        $linhas[] = 'FATOS DO ESTUDIO:';
        foreach ($fatos as $fato) {
            $linhas[] = '- ' . $fato;
        }
    }
    if (preg_match('/pre[çc]o|valor|quanto|custa|pomada|parcel|sinal/iu', $pergunta) === 1) {
        $linhas[] = '';
        $linhas[] = 'REGRA DE PRECO: base R$ 799 por regiao. A pomada anestesica e a parte, R$ 100 por unidade'
            . ' (braco costuma pedir 2, costas 4). O R$ 1.200 dos fatos e o exemplo de costas com 4 pomadas;'
            . ' nas outras areas, some 799 + 100 por unidade. Nunca diga que a pomada esta inclusa.';
    }
    return implode("\n", $linhas);
}

/** Modelos de texto carregados no LM Studio (ignora embeddings). */
function portal_irene_modelos(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!function_exists('curl_init')) {
        return $cache;
    }
    $ch = curl_init(PORTAL_IRENE_LMSTUDIO . '/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
    ]);
    $corpo = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($corpo === false || $status !== 200) {
        return $cache;
    }
    $json = json_decode((string)$corpo, true);
    foreach (($json['data'] ?? []) as $m) {
        $id = trim((string)($m['id'] ?? ''));
        if ($id !== '' && stripos($id, 'embed') === false) {
            $cache[] = $id;
        }
    }
    return $cache;
}

function portal_irene_modelo(): string
{
    static $modelo = null;
    if ($modelo !== null) {
        return $modelo;
    }
    $modelos = portal_irene_modelos();
    foreach ($modelos as $m) {
        if (stripos($m, 'qwen3-8b') !== false) {
            $modelo = $m;
            return $modelo;
        }
    }
    $modelo = $modelos[0] ?? PORTAL_IRENE_MODELO_PREFERIDO;
    return $modelo;
}

function portal_irene_limpar(string $texto): string
{
    $texto = preg_replace('/<think>[\s\S]*?<\/think>/i', ' ', $texto) ?? $texto;
    $texto = preg_replace('/<\/?think>/i', ' ', $texto) ?? $texto;
    $texto = str_replace("\r\n", "\n", $texto);
    $texto = trim((string)preg_replace("/\n{3,}/", "\n\n", $texto));
    return $texto;
}

/** Conversa com o modelo local. Recebe a historia ja filtrada pelo portal. */
function portal_irene_responder(string $sistema, array $historico): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'erro' => 'cURL indisponivel no PHP'];
    }
    $modelo = portal_irene_modelo();
    $mensagens = [['role' => 'system', 'content' => $sistema]];
    $ultimoPapel = '';
    foreach ($historico as $m) {
        $texto = trim((string)($m['texto'] ?? ''));
        if ($texto === '') {
            continue;
        }
        $papel = ((string)($m['papel'] ?? '')) === 'irene' ? 'assistant' : 'user';
        $texto = mb_substr($texto, 0, 4000);
        if ($papel === $ultimoPapel && count($mensagens) > 1) {
            $i = count($mensagens) - 1;
            $mensagens[$i]['content'] = mb_substr($mensagens[$i]['content'] . "\n" . $texto, 0, 5000);
        } else {
            $mensagens[] = ['role' => $papel, 'content' => $texto];
        }
        $ultimoPapel = $papel;
    }
    if (count($mensagens) <= 1) {
        return ['ok' => false, 'erro' => 'sem mensagem para responder'];
    }
    if (stripos($modelo, 'qwen3') !== false) {
        for ($i = count($mensagens) - 1; $i >= 1; $i--) {
            if ($mensagens[$i]['role'] === 'user') {
                $mensagens[$i]['content'] .= ' /no_think';
                break;
            }
        }
    }

    $payload = [
        'model' => $modelo,
        'stream' => false,
        'temperature' => 0.55,
        'max_tokens' => 400,
        'messages' => $mensagens,
    ];
    $ch = curl_init(PORTAL_IRENE_LMSTUDIO . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 85,
    ]);
    $corpo = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $falha = curl_error($ch);
    curl_close($ch);

    if ($corpo === false) {
        return ['ok' => false, 'erro' => 'LM Studio: ' . ($falha !== '' ? $falha : 'nao respondeu')];
    }
    $json = json_decode((string)$corpo, true);
    if ($status !== 200) {
        $erro = $json['error']['message'] ?? (is_string($json['error'] ?? null) ? $json['error'] : 'HTTP ' . $status);
        return ['ok' => false, 'erro' => 'LM Studio: ' . mb_substr((string)$erro, 0, 200)];
    }
    $texto = portal_irene_limpar((string)($json['choices'][0]['message']['content'] ?? ''));
    if ($texto === '') {
        return ['ok' => false, 'erro' => 'LM Studio devolveu resposta vazia'];
    }
    return ['ok' => true, 'texto' => $texto, 'modelo' => $modelo];
}
