<?php
/**
 * Página: Funil de Vendas (studio_funil)
 *
 * Responde, em 5 etapas, o caminho do lead de anúncio até a tatuagem feita:
 *
 *   1) Lead do Ads  -> registros em ads_origin_hits no período (só existe lead de anúncio aqui)
 *   2) Abriu conversa -> desses leads, os que têm telefone com mensagem no arquivo da ponte
 *   3) Interagiu     -> subconjunto: telefones com >= 3 mensagens RECEBIDAS (from_me=false) no período
 *   4) Agendou       -> subconjunto: tem appointment ligado ao lead/customer OU pelo telefone
 *   5) Tatuou        -> subconjunto: appointment com status 'finalizado' (data atingida e atendido)
 *
 * SOMENTE LEITURA: não escreve em nenhuma tabela nem no NDJSON da ponte.
 * Reutiliza os helpers de app/historico_conversas.php (não reimplementa a leitura do arquivo).
 *
 * Registrada no menu em "Marketing" (admin-only), como studio_ads_roi.
 */
declare(strict_types=1);

/** Normaliza um telefone para só dígitos (chave de cruzamento). */
function funil_so_digitos(string $v): string
{
    return (string)preg_replace('/\D+/', '', $v);
}

/**
 * Período selecionável (padrão: últimos 30 dias).
 * Aceita ?f_de=YYYY-MM-DD&f_ate=YYYY-MM-DD; senão ?f_dias=N (7/30/90).
 * Datas inválidas (ou invertidas) caem no padrão em vez de gerar SQL estranho.
 */
function funil_periodo(): array
{
    $today = new DateTimeImmutable('today');
    $hoje = $today->format('Y-m-d');

    // Atalhos prioritários: ?f_atalho=hoje|ontem
    $atalho = strtolower(trim((string)($_GET['f_atalho'] ?? '')));
    if ($atalho === 'hoje') {
        return ['de' => $hoje, 'ate' => $hoje, 'dias' => 0, 'custom' => true, 'atalho' => 'hoje'];
    }
    if ($atalho === 'ontem') {
        $ontem = $today->modify('-1 day')->format('Y-m-d');
        return ['de' => $ontem, 'ate' => $ontem, 'dias' => 0, 'custom' => true, 'atalho' => 'ontem'];
    }

    $hoje = new DateTimeImmutable('today');
    $de = trim((string)($_GET['f_de'] ?? ''));
    $ate = trim((string)($_GET['f_ate'] ?? ''));
    $ok = static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

    if ($ok($de) && $ok($ate) && $de <= $ate) {
        return ['de' => $de, 'ate' => $ate, 'dias' => 0, 'custom' => true, 'atalho' => ''];
    }

    $dias = (int)($_GET['f_dias'] ?? 30);
    if (!in_array($dias, [7, 30, 90, 180], true)) {
        $dias = 30;
    }
    return [
        'de' => $hoje->modify('-' . ($dias - 1) . ' days')->format('Y-m-d'),
        'ate' => $hoje->format('Y-m-d'),
        'dias' => $dias,
        'custom' => false,
        'atalho' => '',
    ];
}

/**
 * Caminho do arquivo de flag do agendador do job de IA (funil_ia.js).
 * O PHP roda no MESMO Windows da ponte, então escreve direto no disco dela.
 * O job lê este arquivo antes de rodar: {"ativo":false} => sai sem processar.
 */
function funil_ia_agendador_path(): string
{
    return 'C:\\Users\\server_spd\\Documents\\whatsapp-origin-bridge\\dados\\funil_ia_agendador.json';
}

/**
 * Lê o estado do agendador. Devolve ['ativo'=>bool, 'atualizado_em'=>string, 'por'=>string,
 * 'disponivel'=>bool]. Se o arquivo não existe/não dá para ler, devolve ativo=true
 * (padrão seguro: o job não fica parado por acidente).
 */
function funil_ia_agendador_ler(): array
{
    $path = funil_ia_agendador_path();
    $def = ['ativo' => true, 'atualizado_em' => '', 'por' => '', 'disponivel' => false];
    if (!is_file($path)) {
        return $def;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $def;
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return $def;
    }
    return [
        'ativo' => !array_key_exists('ativo', $j) ? true : (bool)$j['ativo'],
        'atualizado_em' => (string)($j['atualizado_em'] ?? ''),
        'por' => (string)($j['por'] ?? ''),
        'disponivel' => true,
    ];
}

/**
 * Grava o estado do agendador. Escrita atômica (tmp + rename) para o job nunca
 * ler um JSON pela metade. Devolve true em sucesso.
 */
function funil_ia_agendador_gravar(bool $ativo, string $por = 'painel'): bool
{
    $path = funil_ia_agendador_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return false;
    }
    $data = [
        'ativo' => $ativo,
        'atualizado_em' => (new DateTimeImmutable('now'))->format('c'),
        'por' => $por,
    ];
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return @rename($tmp, $path);
}

/**
 * Telefones de lead do Ads no período, com a plataforma de cada um.
 *
 * Uma linha por telefone (o primeiro hit do período manda na plataforma), porque
 * a mesma pessoa pode gerar mais de um hit; contar linha a linha inflaria a
 * etapa 1 e quebraria a lógica de "subconjunto" das etapas seguintes.
 *
 * Devolve ['por_plataforma'=>[plat=>[fone=>true]], 'todos'=>[fone=>true], 'brutos'=>int].
 */
function funil_leads_ads(PDO $pdo, string $de, string $ate): array
{
    $stmt = $pdo->prepare(
        'SELECT phone, platform, created_at FROM ads_origin_hits
         WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([$de . ' 00:00:00', $ate]);

    $porPlataforma = [];
    $todos = [];
    $brutos = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $brutos++;
        $fone = funil_so_digitos((string)($row['phone'] ?? ''));
        if ($fone === '') {
            continue;
        }
        $plat = strtolower(trim((string)($row['platform'] ?? '')));
        if ($plat === '') {
            $plat = 'sem plataforma';
        }
        if (!isset($todos[$fone])) {
            $todos[$fone] = true;
            $porPlataforma[$plat][$fone] = true;
        }
    }
    return ['por_plataforma' => $porPlataforma, 'todos' => $todos, 'brutos' => $brutos];
}

/**
 * Uma passada no arquivo da ponte (streaming, sem carregar tudo na memória)
 * montando, por telefone canônico, quantas mensagens RECEBIDAS caíram no período
 * e se houve pelo menos uma mensagem do telefone em qualquer data.
 *
 * Devolve ['recebidas'=>[fone=>int], 'vistos_periodo'=>[fone=>true], 'vistos_geral'=>[fone=>true]].
 */
function funil_mensagens_ponte(string $de, string $ate): array
{
    $recebidas = [];
    $vistosPeriodo = [];
    $vistosGeral = [];

    // Só varre o arquivo se ele existir: sem ponte, as etapas 2/3 ficam em 0
    // (e a página avisa isso em vez de mentir um funil bonito).
    if (!is_file(historico_conversas_path())) {
        return ['recebidas' => [], 'vistos_periodo' => [], 'vistos_geral' => []];
    }

    historico_percorrer_reverso(function (array $m) use (&$recebidas, &$vistosPeriodo, &$vistosGeral, $de, $ate): void {
        // histórico tem helper próprio para LID -> telefone real; reutilizamos.
        $fone = historico_telefone_canonico($m);
        if ($fone === '') {
            return;
        }
        $fone = funil_so_digitos($fone);
        if ($fone === '') {
            return;
        }
        $vistosGeral[$fone] = true;
        // Filtro de período pelo mesmo campo que o resto da página de histórico usa.
        if (!historico_casa_filtros($m, ['de' => $de, 'ate' => $ate])) {
            return;
        }
        $vistosPeriodo[$fone] = true;
        if (empty($m['from_me'])) {
            $recebidas[$fone] = ($recebidas[$fone] ?? 0) + 1;
        }
    });

    return ['recebidas' => $recebidas, 'vistos_periodo' => $vistosPeriodo, 'vistos_geral' => $vistosGeral];
}

/**
 * Conjunto de telefones que ABRIRAM conversa: casam com o hit do Ads.
 *
 * Casa por igualdade de dígitos OU por variação (com/sem DDI 55, com/sem o 9),
 * usando a mesma tolerância do historico_leads_map.php.
 */
function funil_casar_telefones(array $leads, array $ponte): array
{
    // Índice inverso: cada variação de um telefone da ponte aponta para o telefone
    // canônico da ponte. Assim qualquer formato do lead casa.
    $indice = [];
    foreach (array_keys($ponte) as $fone) {
        foreach (historico_telefone_variacoes((string)$fone) as $v) {
            $indice[(string)$v] = (string)$fone;
        }
        $indice[(string)$fone] = (string)$fone;
    }

    $abriram = [];
    $brutosLeadsDentro = 0;
    foreach ($leads as $foneLead => $_) {
        $achou = null;
        foreach (historico_telefone_variacoes((string)$foneLead) as $v) {
            if (isset($indice[(string)$v])) {
                $achou = $indice[(string)$v];
                break;
            }
        }
        if ($achou !== null) {
            $abriram[(string)$foneLead] = $achou;
            $brutosLeadsDentro++;
        }
    }
    return $abriram;
}

/**
 * Telefones úteis para casar com appointments: variações do telefone do hit
 * (a agenda guarda o telefone em formatos variados, com/sem DDI e com/sem o 9).
 */
function funil_variacoes_mapa(array $leads): array
{
    $mapa = [];
    foreach (array_keys($leads) as $fone) {
        foreach (historico_telefone_variacoes((string)$fone) as $v) {
            $mapa[(string)$v] = (string)$fone;
        }
    }
    return $mapa;
}

/**
 * Etapas 4 e 5 do funil: quem AGENDOU e quem TATUOU.
 *
 * Regra do dono:
 *   - agendou  = existe appointment ligado ao lead/customer do hit OU cujo
 *                telefone do cliente/lead bate com o telefone do hit.
 *   - tatuou   = esse appointment tem status 'finalizado' (a data foi atingida
 *                e o cliente foi atendido). 'cancelado' NÃO conta.
 *
 * Janela de datas: do início do período até 180 dias à frente (a pessoa pode ter
 * agendado para frente). Datas absurdas são descartadas (há lixo tipo 2087-04-23).
 *
 * Devolve ['agendou'=>[fone=>true], 'tatuou'=>[fone=>true]].
 */
function funil_agenda(PDO $pdo, array $leads, string $de, string $ate): array
{
    $agendou = [];
    $tatuou = [];
    $nomes = [];
    if (!$leads) {
        return ['agendou' => $agendou, 'tatuou' => $tatuou, 'nomes' => $nomes];
    }

    $variacoes = funil_variacoes_mapa($leads); // variação => telefone do hit
    if (!$variacoes) {
        return ['agendou' => $agendou, 'tatuou' => $tatuou, 'nomes' => $nomes];
    }

    // Fim da janela: 180 dias depois do fim do período.
    $fim = (new DateTimeImmutable($ate))->modify('+180 days')->format('Y-m-d');
    $vals = array_keys($variacoes);
    $in = implode(',', array_fill(0, count($vals), '?'));

    // Junta o telefone do agendamento com o do lead e o do customer (o CRM liga
    // appointment a lead_id e customer_id; nem sempre o telefone está no lead).
    $sql = "SELECT a.status,
                   COALESCE(NULLIF(TRIM(l.name),''), NULLIF(TRIM(c.name),''), NULLIF(TRIM(a.title),'')) AS nome,
                   REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(l.phone,''),'(',''),')',''),'-',''),' ','') AS fone_lead,
                   REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone,''),'(',''),')',''),'-',''),' ','') AS fone_customer
            FROM appointments a
            LEFT JOIN leads l ON l.id = a.lead_id
            LEFT JOIN customers c ON c.id = a.customer_id
            WHERE a.appointment_date BETWEEN ? AND ?
              AND YEAR(a.appointment_date) BETWEEN 2000 AND 2100
              AND (
                    REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(l.phone,''),'(',''),')',''),'-',''),' ','') IN ($in)
                 OR REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone,''),'(',''),')',''),'-',''),' ','') IN ($in)
              )";
    $params = array_merge([$de, $fim], $vals, $vals);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Limpa o nome que veio da agenda: a atendente escreve anotacao junto do nome
    // ("Wesley da Cruz Teodoro, 799, , para. Daniel", "Marina Firmino Bezerra, talvez").
    // Aqui sobra o nome da pessoa.
    $nomeBloco = static function (string $nome): string {
        $nome = trim($nome);
        // 1) valor em dinheiro (799, 570, 1.200...)
        $nome = preg_replace('/\bR?\$?\s*\d[\d.,]*\b/u', ' ', $nome) ?? $nome;
        // 2) anotacao da atendente: corta dali pra frente
        $nome = preg_replace('/\s+\b(talvez|vai mandar|nao sabe|n.o sabe|sei l.|s. pode de manh.|meio|mais ou menos|a confirmar|na hora|se poss.vel)\b.*$/iu', '', $nome) ?? $nome;
        // 3) primeiro separador forte (virgula, ponto-e-virgula, barra, travessao)
        $nome = preg_split('/\s*(?:[,;\/]|\s[-\x{2013}\x{2014}]\s)/u', $nome)[0] ?? $nome;
        // 4) sufixo solto "para Daniel" / "com o Daniel"
        $nome = preg_replace('/\s+\b(?:para|com o|para o|pra)\b\.?.*$/iu', '', $nome) ?? $nome;
        $nome = preg_replace('/[\s,.\-]+$/u', '', trim($nome)) ?? trim($nome);
        $nome = preg_replace('/\s{2,}/u', ' ', $nome) ?? $nome;
        return trim($nome);
    };

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        // Regra do "tatuou" (a mesma do funil_agenda_match/funil_appt_aconteceu):
        // 'cancelado' nunca conta; 'finalizado' conta sempre; qualquer outro status
        // com data+hora JA PASSADAS conta (a agenda do estudo deleta o evento quando
        // ha cancelamento/falta, entao "passou e continua la" = atendimento ocorreu).
        $aconteceu = funil_appt_aconteceu(
            $status,
            (string)($row['appointment_date'] ?? ''),
            (string)($row['start_time'] ?? '')
        );
        foreach (['fone_lead', 'fone_customer'] as $campo) {
            $fone = funil_so_digitos((string)($row[$campo] ?? ''));
            if ($fone === '') {
                continue;
            }
            // Acha o telefone do HIT que gerou essa variação (pode haver mais de
            // uma variação apontando para hits diferentes; marcamos todas).
            foreach (historico_telefone_variacoes($fone) as $v) {
                if (isset($variacoes[(string)$v])) {
                    $alvo = $variacoes[(string)$v];
                    $agendou[$alvo] = true;
                    if ($aconteceu) {
                        $tatuou[$alvo] = true;
                    }
                    // Primeiro nome que aparece manda (o COALESCE do SELECT ja
                    // prefere o nome do lead ao do customer).
                    $nomeLimpo = $nomeBloco((string)($row['nome'] ?? ''));
                    if ($nomeLimpo !== '' && !isset($nomes[$alvo])) {
                        $nomes[$alvo] = $nomeLimpo;
                    }
                }
            }
        }
    }

    return ['agendou' => $agendou, 'tatuou' => $tatuou, 'nomes' => $nomes];
}

/**
 * Nome do contato por telefone, direto do ARQUIVO da ponte (pushName do WhatsApp).
 *
 * Segunda fonte de nome da coluna Nome: quando o telefone nao tem lead/customer com
 * nome, mas apareceu numa conversa, o WhatsApp guarda o nome de perfil. Nomes de
 * ESTUDIO ("Estudio Cereja 2" e afins) sao descartados: nao sao pessoa.
 *
 * Devolve [fone_canonico => nome].
 */
function funil_nomes_ponte(): array
{
    $nomes = [];
    if (!function_exists('historico_percorrer_reverso') || !is_file(historico_conversas_path())) {
        return $nomes;
    }
    historico_percorrer_reverso(function (array $m) use (&$nomes): void {
        $fone = funil_so_digitos(historico_telefone_canonico($m));
        if ($fone === '' || isset($nomes[$fone])) {
            return;
        }
        $nome = trim((string)($m['name'] ?? ''));
        if ($nome === '') {
            return;
        }
        $chave = strtolower(function_exists('historico_sem_acento') ? historico_sem_acento($nome) : $nome);
        if (str_contains($chave, 'estudio cereja')) {
            return;
        }
        $nomes[$fone] = $nome;
    });
    return $nomes;
}

/**
 * Categorias de ORIGEM como o dono pediu (funil_match.origem_detectada).
 * 'indefinido' aparece EXPLICITAMENTE (não é "desconhecido": é "não deu
 * para saber a origem"), e por isso tem linha própria na tabela.
 */
function funil_origens_validas(): array
{
    return ['meta', 'google', 'instagram', 'indicacao', 'porta', 'cliente_antigo', 'indefinido'];
}

/** Rótulo humano de uma origem. */
function funil_origem_rotulo(string $o): string
{
    $rotulos = [
        'meta' => 'Meta (anúncio)',
        'google' => 'Google',
        'instagram' => 'Instagram (orgânico)',
        'indicacao' => 'Indicação',
        'porta' => 'Porta / presencial',
        'cliente_antigo' => 'Cliente antigo',
        'indefinido' => 'Indefinido',
    ];
    return $rotulos[$o] ?? ucfirst($o);
}

/**
 * ORIGEM REAL de cada telefone, vinda do job de IA (funil_match).
 *
 * Precedência: funil_match.origem_detectada (origem real analisada pela IA).
 * Devolve ['por_fone' => [fone => origem], 'total' => int, 'analisadas' => int].
 * Se a tabela não existir (job nunca rodou), devolve vazio e a página cai para
 * o cruzamento por ads_origin_hits.
 */
function funil_origens_ia(PDO $pdo): array
{
    $porFone = [];
    try {
        $st = $pdo->query(
            "SELECT telefone, origem_detectada, origem_confianca
               FROM funil_match
              WHERE origem_detectada IS NOT NULL
              ORDER BY origem_confianca DESC, id DESC"
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fone = funil_so_digitos((string)($row['telefone'] ?? ''));
            if ($fone === '') {
                continue;
            }
            $o = strtolower(trim((string)($row['origem_detectada'] ?? '')));
            if (!in_array($o, funil_origens_validas(), true)) {
                $o = 'indefinido';
            }
            // Uma linha por telefone: a primeira (mais confiável) manda.
            if (!isset($porFone[$fone])) {
                $porFone[$fone] = $o;
            }
        }
    } catch (Throwable $e) {
        return ['por_fone' => [], 'total' => 0, 'analisadas' => 0];
    }
    return ['por_fone' => $porFone, 'total' => count($porFone), 'analisadas' => count($porFone)];
}

/**
 * Progresso do job de IA: quantas conversas já foram analisadas e quantas faltam.
 * O universo é o número de telefones distintos com conversa no período (o mesmo
 * que a etapa "Abriu conversa" usa), para o número bater com o que o dono vê.
 */
function funil_progresso_ia(PDO $pdo, int $universoConversas): array
{
    $analisadas = 0;
    try {
        $st = $pdo->query('SELECT COUNT(DISTINCT telefone) AS c FROM funil_match');
        $analisadas = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return ['analisadas' => 0, 'total' => 0, 'faltam' => 0, 'pct' => null, 'disponivel' => false];
    }
    $total = max($universoConversas, $analisadas);
    return [
        'analisadas' => $analisadas,
        'total' => $total,
        'faltam' => max(0, $total - $analisadas),
        'pct' => $total > 0 ? ($analisadas / $total) * 100 : null,
        'disponivel' => true,
    ];
}

/**
 * Agenda a partir do funil_match (a ponte que o job de IA construiu):
 *   agendou = agendou=1 OU appointment_id vinculado
 *   tatuou  = desses, o appointment vinculado tem status 'finalizado'
 * Devolve ['agendou'=>[fone=>true], 'tatuou'=>[fone=>true]].
 *
 * Regra do "tatuou" (acordada com o dono):
 *   - 'cancelado' NUNCA conta;
 *   - 'finalizado' conta sempre;
 *   - status NAO-cancelado com data+hora JA PASSADAS conta (a Hellen deleta
 *     quando cancela/falta, entao "passou e continua la" = aconteceu).
 */
function funil_agenda_match(PDO $pdo): array
{
    $agendou = [];
    $tatuou = [];
    try {
        $sql = "SELECT fm.telefone, fm.agendou, fm.appointment_id,
                       LOWER(COALESCE(a.status,'')) AS st,
                       a.appointment_date, a.start_time
                  FROM funil_match fm
                  LEFT JOIN appointments a ON a.id = fm.appointment_id
                 WHERE fm.agendou = 1 OR fm.appointment_id IS NOT NULL";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fone = funil_so_digitos((string)($row['telefone'] ?? ''));
            if ($fone === '') {
                continue;
            }
            $agendou[$fone] = true;
            if (funil_appt_aconteceu(
                (string)($row['st'] ?? ''),
                (string)($row['appointment_date'] ?? ''),
                (string)($row['start_time'] ?? '')
            )) {
                $tatuou[$fone] = true;
            }
        }
    } catch (Throwable $e) {
        return ['agendou' => [], 'tatuou' => []];
    }
    return ['agendou' => $agendou, 'tatuou' => array_intersect_key($tatuou, $agendou)];
}

/**
 * O atendimento ACONTECEU? (mesma regra do "tatuou" acima, reutilizável)
 *   cancelado -> nunca; finalizado -> sempre; senão, data+hora passadas -> sim.
 */
function funil_appt_aconteceu(string $status, string $data, string $hora, ?int $agora = null): bool
{
    $st = strtolower(trim($status));
    if ($st === 'cancelado' || $st === '') {
        return false;
    }
    if ($st === 'finalizado') {
        return true;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return false;
    }
    $h = preg_match('/^\d{1,2}:\d{2}/', trim($hora), $m) ? $m[0] : '23:59';
    if (strlen($h) === 4) {
        $h = '0' . $h;
    }
    $ts = strtotime($data . ' ' . $h . ':00');
    if ($ts === false) {
        return false;
    }
    return $ts <= ($agora ?? time());
}

/**
 * Calcula o funil inteiro (as 5 etapas + quebra por plataforma).
 * $escopo = ['por_plataforma'=>, 'todos'=>, 'brutos'=>] do funil_leads_ads().
 */
function funil_calcular(PDO $pdo, string $de, string $ate, array $escopo): array
{
    $ponte = funil_mensagens_ponte($de, $ate);

    // Universo da ponte (para saber se um lead apareceu em ALGUMA data, e não só no período).
    $pontePeriodo = $ponte['vistos_periodo'];
    $ponteGeral = $ponte['vistos_geral'];
    $recebidas = $ponte['recebidas'];

    $abriramPeriodo = funil_casar_telefones($escopo['todos'], $pontePeriodo);
    $abriramGeral = funil_casar_telefones($escopo['todos'], $ponteGeral);

    // Origem real (funil_match / IA). Quando existir, ela MANDA; o hit do Ads
    // fica como fallback (e segue aparecendo na seção "por plataforma").
    $ia = funil_origens_ia($pdo);
    $origemIA = $ia['por_fone'];

    // Interagiu: >= 3 mensagens recebidas no período.
    $interagiram = [];
    foreach ($abriramPeriodo as $foneLead => $fonePonte) {
        if (($recebidas[$fonePonte] ?? 0) >= 3) {
            $interagiram[(string)$foneLead] = $fonePonte;
        }
    }

    // Agenda: cruza com TODO lead do Ads do período (mesmo quem não apareceu no arquivo).
    // REGRA DO DONO (22/09/2026): "agendou" conta SO agendamento REAL - appointment
    // ligado por lead_id/customer_id/telefone. O palpite da IA (funil_match.agendou),
    // que marcava 29 sem lastro nenhum (so 3 tinham appointment), NAO entra mais:
    // inflava a etapa e virava numero que o dono nao conseguia conferir.
    $agenda = funil_agenda($pdo, $escopo['todos'], $de, $ate);

    $agendou = $agenda['agendou'];
    $tatuou = array_intersect_key($agenda['tatuou'], $agendou);
    // Nome de quem tem appointment (prioridade na coluna Nome).
    $nomesAgenda = $agenda['nomes'] ?? [];

    // ---------------------------------------------------------------------
    // Distribuição por ORIGEM REAL (meta/google/instagram/indicacao/porta/
    // cliente_antigo/indefinido). "Indefinido" aparece explicitamente.
    // Precedência: funil_match (IA) > ads_origin_hits.platform > indefinido.
    // ---------------------------------------------------------------------
    $origens = [];
    foreach (funil_origens_validas() as $o) {
        $origens[$o] = ['origem' => $o, 'rotulo' => funil_origem_rotulo($o), 'total' => 0, 'agendou' => 0, 'tatuou' => 0];
    }
    // Universo da distribuição por origem: telefones com conversa no período
    // (união dos leads do Ads com a ponte) — é o que melhor representa "de onde veio".
    $universoOrigem = [];
    foreach (array_keys($escopo['todos']) as $f) {
        $universoOrigem[(string)$f] = true;
    }
    foreach (array_keys($abriramPeriodo) as $f) {
        $universoOrigem[(string)$f] = true;
    }

    $plataformaParaOrigem = static function (string $plat): string {
        $p = strtolower(trim($plat));
        if ($p === '') {
            return 'indefinido';
        }
        if (str_contains($p, 'meta') || str_contains($p, 'facebook') || str_contains($p, 'face') || str_contains($p, 'insta')) {
            // Instagram pago é Meta Ads; só cai em "instagram" quando for orgânico,
            // e a IA já marca isso. No fallback, anúncio de insta continua Meta.
            return str_contains($p, 'meta') || str_contains($p, 'facebook') || str_contains($p, 'face') ? 'meta' : 'instagram';
        }
        if (str_contains($p, 'google')) {
            return 'google';
        }
        return 'indefinido';
    };

    $origemHits = [];
    foreach ($escopo['por_plataforma'] as $plat => $fones) {
        $o = $plataformaParaOrigem((string)$plat);
        foreach (array_keys($fones) as $f) {
            if (!isset($origemHits[(string)$f])) {
                $origemHits[(string)$f] = $o;
            }
        }
    }

    foreach (array_keys($universoOrigem) as $fone) {
        $o = $origemIA[$fone] ?? $origemHits[$fone] ?? 'indefinido';
        if (!isset($origens[$o])) {
            $o = 'indefinido';
        }
        $origens[$o]['total']++;
        if (isset($agendou[$fone])) {
            $origens[$o]['agendou']++;
        }
        if (isset($tatuou[$fone])) {
            $origens[$o]['tatuou']++;
        }
    }
    // Origem "indefinido" fica por último (é o resto, não uma origem de verdade).
    $origemLista = array_values($origens);
    usort($origemLista, static function (array $a, array $b): int {
        if ($a['origem'] === 'indefinido') return 1;
        if ($b['origem'] === 'indefinido') return -1;
        return $b['total'] <=> $a['total'];
    });

    $progressoIA = funil_progresso_ia($pdo, count($universoOrigem));

    $etapas = [
        ['chave' => 'lead_ads', 'nome' => 'Lead do Ads', 'desc' => 'Contatos que clicaram no anúncio', 'total' => count($escopo['todos'])],
        ['chave' => 'conversa', 'nome' => 'Abriu conversa', 'desc' => 'Telefone apareceu no WhatsApp', 'total' => count($abriramPeriodo)],
        ['chave' => 'interagiu', 'nome' => 'Interagiu', 'desc' => '3+ mensagens recebidas no período', 'total' => count($interagiram)],
        ['chave' => 'agendou', 'nome' => 'Agendou', 'desc' => 'Agendou na agenda ou no funil_match (IA)', 'total' => count($agendou)],
        ['chave' => 'tatuou', 'nome' => 'Tatuou', 'desc' => 'Appointment vinculado com status finalizado', 'total' => count($tatuou)],
    ];

    // Taxas: absoluta (sobre a etapa 1) e em cadeia (sobre a etapa anterior).
    $base = max(1, (int)$etapas[0]['total']);
    $anterior = null;
    foreach ($etapas as &$e) {
        $e['pct_base'] = ((int)$e['total'] / $base) * 100;
        $e['pct_anterior'] = ($anterior === null) ? null : ((int)$e['total'] / max(1, $anterior)) * 100;
        $e['perdidos'] = ($anterior === null) ? 0 : max(0, $anterior - (int)$e['total']);
        $anterior = (int)$e['total'];
    }
    unset($e);

    // Quebra por plataforma: recalcula a cadeia inteira dentro de cada plataforma.
    $porPlataforma = [];
    $ordem = [];
    foreach ($escopo['por_plataforma'] as $plat => $fones) {
        $ordem[$plat] = true;
    }
    // Plataformas que aparecem na ponte/agenda mas não em hit do período ficam de fora
    // (a base do funil é o hit do Ads — sem hit, não há lead do Ads).
    arsort($ordem);
    $plataformas = array_keys($ordem);
    sort($plataformas);

    foreach ($plataformas as $plat) {
        $fonesPlat = $escopo['por_plataforma'][$plat];

        $abriuPlat = 0;
        foreach ($fonesPlat as $f => $_) {
            if (isset($abriramPeriodo[(string)$f])) {
                $abriuPlat++;
            }
        }
        $interPlat = 0;
        foreach ($fonesPlat as $f => $_) {
            if (isset($interagiram[(string)$f])) {
                $interPlat++;
            }
        }
        $agPlat = 0;
        $tatPlat = 0;
        foreach ($fonesPlat as $f => $_) {
            if (isset($agendou[(string)$f])) {
                $agPlat++;
            }
            if (isset($tatuou[(string)$f])) {
                $tatPlat++;
            }
        }
        $total = count($fonesPlat);
        $porPlataforma[] = [
            'plataforma' => $plat,
            'lead_ads' => $total,
            'conversa' => $abriuPlat,
            'interagiu' => $interPlat,
            'agendou' => $agPlat,
            'tatuou' => $tatPlat,
            'pct_conversa' => $total > 0 ? ($abriuPlat / $total) * 100 : 0.0,
            'pct_interagiu' => $total > 0 ? ($interPlat / $total) * 100 : 0.0,
            'pct_agendou' => $total > 0 ? ($agPlat / $total) * 100 : 0.0,
            'pct_tatuou' => $total > 0 ? ($tatPlat / $total) * 100 : 0.0,
        ];
    }

    // ---------------------------------------------------------------------
    // Listas de telefones por etapa, para o drill-down (clicar no número do
    // card e ver QUEM são os leads). Mesmas chaves das etapas.
    // ---------------------------------------------------------------------
    $listas = [
        'lead_ads' => array_keys($escopo['todos']),
        'conversa' => array_keys($abriramPeriodo),
        'interagiu' => array_keys($interagiram),
        'agendou' => array_keys($agendou),
        'tatuou' => array_keys($tatuou),
    ];

    // Nome do lead/cliente por telefone.
    // Precedencia: nome da AGENDA (quem tem appointment - e o que a atendente
    // escreveu) > cadastro do CRM (leads/customers) > perfil do WhatsApp no
    // arquivo da ponte. Telefone sem nenhuma das tres fica "(sem nome)".
    $nomes = [];
    try {
        $sqlNome = "SELECT phone, name FROM leads WHERE phone IS NOT NULL AND phone <> ''
                     UNION ALL
                     SELECT phone, name FROM customers WHERE phone IS NOT NULL AND phone <> ''";
        foreach ($pdo->query($sqlNome)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $f = funil_so_digitos((string)($row['phone'] ?? ''));
            $nm = trim((string)($row['name'] ?? ''));
            if ($f !== '' && $nm !== '' && !isset($nomes[$f])) {
                $nomes[$f] = $nm;
            }
        }
    } catch (Throwable $e) {
        $nomes = [];
    }
    // Perfil do WhatsApp (arquivo da ponte) entra por baixo do cadastro.
    foreach (funil_nomes_ponte() as $f => $nm) {
        if (!isset($nomes[$f]) && $nm !== '') {
            $nomes[$f] = $nm;
        }
    }
    // Agenda entra por cima de tudo (nome mais fiel).
    foreach ($nomesAgenda as $f => $nm) {
        if ($nm !== '') {
            $nomes[$f] = $nm;
        }
    }
    // Monta os itens de cada etapa (telefone + nome + origem + agendou/tatuou).
    $detalhes = [];
    foreach ($listas as $chave => $fones) {
        $itens = [];
        foreach ($fones as $fone) {
            $fone = (string)$fone;
            $itens[] = [
                'telefone' => $fone,
                'nome' => $nomes[$fone] ?? '',
                'origem' => funil_origem_rotulo($origemIA[$fone] ?? $origemHits[$fone] ?? 'indefinido'),
                'agendou' => isset($agendou[$fone]),
                'tatuou' => isset($tatuou[$fone]),
            ];
        }
        // Agendou/Tatuou primeiro, depois por nome/telefone, para o que importa ficar no topo.
        usort($itens, static function (array $a, array $b): int {
            if ($a['tatuou'] !== $b['tatuou']) return $a['tatuou'] ? -1 : 1;
            if ($a['agendou'] !== $b['agendou']) return $a['agendou'] ? -1 : 1;
            return strcmp((string)($a['nome'] !== '' ? $a['nome'] : $a['telefone']), (string)($b['nome'] !== '' ? $b['nome'] : $b['telefone']));
        });
        $detalhes[$chave] = $itens;
    }

    return [
        'etapas' => $etapas,
        'detalhes' => $detalhes,
        'por_plataforma' => $porPlataforma,
        'origens' => $origemLista,
        'progresso_ia' => $progressoIA,
        'brutos' => (int)($escopo['brutos'] ?? 0),
        'ponte_online' => count($ponteGeral) > 0,
        // Quantos leads do Ads apareceram no arquivo da ponte em QUALQUER data
        // (útil para explicar por que a etapa 2 pode ser baixa num período curto).
        'com_mensagem_geral' => count(funil_casar_telefones($escopo['todos'], $ponteGeral)),
        'arquivo_existe' => is_file(historico_conversas_path()),
    ];
}

/** Formata número inteiro no padrão brasileiro. */
function funil_num(int $n): string
{
    return number_format($n, 0, ',', '.');
}

/** Formata percentual com 1 casa. */
function funil_pct(?float $p): string
{
    if ($p === null) {
        return '—';
    }
    return number_format($p, 1, ',', '.') . '%';
}

/**
 * Renderiza a página do funil (echo de HTML).
 * Chamada pela rota em index.php dentro do render_studio_shell().
 */
function render_funil(array $studio): void
{
    $dbStatus = studio_db_status_for($studio);
    if (!$dbStatus['ok']) {
        render_studio_db_missing($studio, $dbStatus['error']);
        return;
    }

    $pdo = studio_db($studio);
    $periodo = funil_periodo();
    $de = (string)$periodo['de'];
    $ate = (string)$periodo['ate'];

    // Leitura do arquivo da ponte ANTES de medir tempo (is_file/whatsapp_conversations
    // é a parte lenta quando o NDJSON cresce).

    $escopo = funil_leads_ads($pdo, $de, $ate);
    // Complementa o cruzamento com whatsapp_conversations (conversas abertas pelo CRM).
    $convPdo = [];
    try {
        $st = $pdo->query('SELECT phone FROM whatsapp_conversations WHERE phone IS NOT NULL AND phone <> ""');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $f = funil_so_digitos((string)$row['phone']);
            if ($f !== '') {
                $convPdo[$f] = true;
            }
        }
    } catch (Throwable $e) {
        $convPdo = [];
    }

    // Botão liga/desliga do agendador do job de IA (funil_ia.js).
    // O POST altera o arquivo de flag que o job consulta antes de rodar.
    $agendadorMsg = '';
    $agendadorErro = false;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['funil_ia_agendador'])) {
        $querAtivo = ((string)($_POST['funil_ia_agendador'] ?? '')) === 'on';
        if (funil_ia_agendador_gravar($querAtivo, 'painel')) {
            $agendadorMsg = $querAtivo
                ? 'Agendador LIGADO — o job volta a rodar a cada 30 min.'
                : 'Agendador DESLIGADO — o job sai sem processar até você religar.';
        } else {
            $agendadorErro = true;
            $agendadorMsg = 'Não foi possível gravar a flag do agendador (arquivo/pasta sem permissão).';
        }
    }
    $agendador = funil_ia_agendador_ler();

    $funil = funil_calcular($pdo, $de, $ate, $escopo);

    // "Abriu conversa" também vale quando o telefone existe em whatsapp_conversations
    // (conversa criada pelo CRM/API oficial), mesmo sem linha no NDJSON da ponte.
    $extraConv = 0;
    foreach (array_keys($escopo['todos']) as $foneLead) {
        foreach (historico_telefone_variacoes((string)$foneLead) as $v) {
            if (isset($convPdo[(string)$v])) {
                $extraConv++;
                break;
            }
        }
    }

    $formatoData = static function (string $iso): string {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $iso);
        return $d ? $d->format('d/m/Y') : $iso;
    };

    $queryPreset = static function (array $extra): string {
        return app_url('studio_funil', $extra);
    };
    ?>
    <style>
     .funil-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:16px}
     .funil-card{background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:12px 13px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
     .funil-card .lbl{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#667085;font-weight:800}
     .funil-card .val{font-size:22px;font-weight:800;color:#101828;margin-top:5px;line-height:1.15}
     .funil-card .sub{font-size:10px;color:#98a2b3;margin-top:4px;line-height:1.35}
     .funil-filtros{background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:13px;margin-bottom:14px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
     .funil-filtros form{display:flex;flex-wrap:wrap;gap:11px;align-items:end}
     .funil-filtros label{display:flex;flex-direction:column;gap:4px;font-size:10px;color:#475467;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
     .funil-bar{height:10px;border-radius:6px;background:#eef2f6;overflow:hidden;min-width:60px}
     .funil-bar span{display:block;height:100%;background:linear-gradient(90deg,#4c6ef5,#22b8cf)}
     .funil-tabela .nome{font-weight:700;color:#101828}
     .funil-tabela .desc{display:block;font-size:11px;color:#98a2b3;font-weight:500}
     .funil-chip{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#3b4cca}
     .funil-val-link{display:inline-block;text-decoration:none;color:#3b4cca;border-bottom:2px dashed #c7d2fe;cursor:pointer;transition:color .12s,border-color .12s}
     .funil-val-link:hover{color:#1d4ed8;border-bottom-color:#1d4ed8}
     .funil-ver{color:#3b4cca;font-weight:700;text-decoration:none;border-bottom:1px dashed #c7d2fe}
     .funil-ver:hover{color:#1d4ed8;border-bottom-color:#1d4ed8}
     .funil-detalhe{background:#fff;border:1px solid #e6e8ee;border-radius:14px;padding:12px 14px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
     .funil-detalhe[open]{border-color:#c7d2fe;box-shadow:0 2px 10px rgba(59,76,202,.10)}
     .funil-detalhe>summary{font-size:13px;color:#101828;list-style:none}
     .funil-detalhe>summary::-webkit-details-marker{display:none}
     .funil-detalhe>summary::before{content:'▸ ';color:#3b4cca;font-weight:800}
     .funil-detalhe[open]>summary::before{content:'▾ '}
     .funil-detalhe>summary .desc{font-size:11px;color:#98a2b3;margin-left:4px}
     @media(min-width:768px){.funil-cards{grid-template-columns:repeat(5,minmax(0,1fr))}}
    </style>

    <script>
      // Clicar no número (ou em "ver leads") do card abre o bloco de detalhe
      // correspondente e rola até ele.
      document.addEventListener('click', function (ev) {
        var alvo = ev.target.closest('.funil-val-link, .funil-ver');
        if (!alvo) return;
        var chave = alvo.getAttribute('data-etapa');
        if (!chave) return;
        var box = document.getElementById('leads-' + chave);
        if (!box) return;
        ev.preventDefault();
        box.open = true;
        box.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    </script>

    <section class="panel">
      <div class="actions" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h2 style="margin:0"><i class="fa-solid fa-filter"></i> Funil de Vendas</h2>
        <span class="funil-chip"><?= h($formatoData($de)) ?> a <?= h($formatoData($ate)) ?></span>
      </div>
      <p class="muted" style="margin:6px 0 12px">
        Do anúncio até a tatuagem feita. Cada etapa é um subconjunto da anterior;
        só entra aqui quem chegou por anúncio.
      </p>

      <div class="funil-filtros">
        <form method="get" action="<?= h(app_url('studio_funil')) ?>">
          <input type="hidden" name="page" value="studio_funil">
          <label>De
            <input type="date" name="f_de" value="<?= h($de) ?>">
          </label>
          <label>Até
            <input type="date" name="f_ate" value="<?= h($ate) ?>">
          </label>
          <button type="submit" class="btn primary"><i class="fa-solid fa-magnifying-glass"></i> Aplicar</button>
        </form>
        <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
          <?php
            $atalhos = [
                ['atalho' => 'hoje', 'rotulo' => 'Hoje'],
                ['atalho' => 'ontem', 'rotulo' => 'Ontem'],
                ['dias' => 7, 'rotulo' => '7 dias'],
                ['dias' => 30, 'rotulo' => '30 dias'],
                ['dias' => 90, 'rotulo' => '90 dias'],
                ['dias' => 180, 'rotulo' => '180 dias'],
            ];
            $atalhoAtual = (string)($periodo['atalho'] ?? '');
            foreach ($atalhos as $a):
                $ativo = $atalhoAtual === ($a['atalho'] ?? '');
                $extra = isset($a['atalho']) ? ['f_atalho' => $a['atalho']] : ['f_dias' => $a['dias']];
          ?>
            <a class="btn <?= $ativo ? 'primary' : 'secondary' ?>" href="<?= h($queryPreset($extra)) ?>"><?= h($a['rotulo']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (!$funil['arquivo_existe']): ?>
        <div class="flash error" style="margin-bottom:12px">
          Arquivo de conversas da ponte não encontrado — as etapas "Abriu conversa" e "Interagiu" ficam zeradas.
        </div>
      <?php endif; ?>

      <?php $prog = $funil['progresso_ia'] ?? null; ?>
      <?php if ($prog && $prog['disponivel']): ?>
        <div class="funil-card" style="margin-bottom:14px">
          <div class="lbl">Progresso da análise por IA</div>
          <div class="val"><?= h(funil_num((int)$prog['analisadas'])) ?> <span style="font-size:13px;font-weight:600;color:#667085">de <?= h(funil_num((int)$prog['total'])) ?> conversas</span></div>
          <div class="sub"><?= h(funil_num((int)$prog['faltam'])) ?> ainda não analisadas <?php if ($prog['pct'] !== null): ?>· <?= h(funil_pct((float)$prog['pct'])) ?> concluído<?php endif; ?></div>
          <div class="funil-bar" style="margin-top:8px"><span style="width:<?= h(number_format((float)($prog['pct'] ?? 0), 1, '.', '')) ?>%"></span></div>
        </div>
      <?php else: ?>
        <div class="flash error" style="margin-bottom:12px">
          Tabela <code>funil_match</code> indisponível — a origem real (IA) e o vínculo de agendamento não estão preenchidos.
        </div>
      <?php endif; ?>

      <?php /* Liga/desliga do agendador do job de IA (funil_ia.js, tarefa FunilIA_30min). */ ?>
      <?php if ($agendadorMsg !== ''): ?>
        <div class="flash <?= $agendadorErro ? 'error' : 'success' ?>" style="margin-bottom:12px">
          <?= h($agendadorMsg) ?>
        </div>
      <?php endif; ?>
      <div class="funil-card" style="margin-bottom:14px">
        <div class="lbl">Atualização automática (IA)</div>
        <div class="val" style="font-size:20px">
          <?php if ($agendador['ativo']): ?>
            <span style="color:#067647">● Ativo</span>
          <?php else: ?>
            <span style="color:#b42318">■ Inativo</span>
          <?php endif; ?>
        </div>
        <div class="sub">
          Roda a cada 30 min no Windows (tarefa <code>FunilIA_30min</code>).
          <?php if ($agendador['atualizado_em'] !== ''): ?>
            Última mudança: <?= h($agendador['atualizado_em']) ?><?= $agendador['por'] !== '' ? ' (por ' . h($agendador['por']) . ')' : '' ?>.
          <?php endif; ?>
        </div>
        <form method="post" action="<?= h(app_url('studio_funil')) ?>" style="margin-top:10px">
          <input type="hidden" name="page" value="studio_funil">
          <input type="hidden" name="f_de" value="<?= h($de) ?>">
          <input type="hidden" name="f_ate" value="<?= h($ate) ?>">
          <input type="hidden" name="funil_ia_agendador" value="<?= $agendador['ativo'] ? 'off' : 'on' ?>">
          <button type="submit" class="btn <?= $agendador['ativo'] ? 'secondary' : 'primary' ?>">
            <i class="fa-solid fa-power-off"></i>
            <?= $agendador['ativo'] ? 'Desligar agendador' : 'Ligar agendador' ?>
          </button>
        </form>
      </div>

      <div class="funil-cards">
        <?php foreach ($funil['etapas'] as $e): ?>
          <?php $qtd = (int)$e['total']; ?>
          <div class="funil-card">
            <div class="lbl"><?= h($e['nome']) ?></div>
            <?php if ($qtd > 0): ?>
              <a class="val funil-val-link" href="#leads-<?= h((string)$e['chave']) ?>" data-etapa="<?= h((string)$e['chave']) ?>" title="Clique para ver os <?= h(funil_num($qtd)) ?> leads desta etapa">
                <?= h(funil_num($qtd)) ?>
              </a>
            <?php else: ?>
              <div class="val"><?= h(funil_num(0)) ?></div>
            <?php endif; ?>
            <div class="sub">
              <?= h(funil_pct($e['pct_base'])) ?> dos leads
              <?php if ($e['pct_anterior'] !== null): ?>
                · <?= h(funil_pct($e['pct_anterior'])) ?> da etapa anterior
              <?php endif; ?>
              <?php if ($qtd > 0): ?>
                · <a class="funil-ver" href="#leads-<?= h((string)$e['chave']) ?>" data-etapa="<?= h((string)$e['chave']) ?>">ver leads</a>
              <?php endif; ?>
            </div>
            <div class="funil-bar" style="margin-top:8px"><span style="width:<?= h(number_format((float)$e['pct_base'], 1, '.', '')) ?>%"></span></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php /* Drill-down: um bloco por etapa com a lista real de leads. */ ?>
      <?php foreach ($funil['etapas'] as $e): ?>
        <?php $itens = $funil['detalhes'][$e['chave']] ?? []; ?>
        <?php if (!$itens): continue; endif; ?>
        <details class="funil-detalhe" id="leads-<?= h((string)$e['chave']) ?>" style="margin:0 0 14px">
          <summary style="cursor:pointer;font-weight:700">
            <i class="fa-solid fa-users"></i>
            <?= h($e['nome']) ?> — <?= h(funil_num(count($itens))) ?> lead(s)
            <span class="desc" style="font-weight:500">clique para ver quem são</span>
          </summary>
          <div style="overflow-x:auto;margin-top:10px">
            <table class="table" style="width:100%;border-collapse:collapse">
              <thead>
                <tr>
                  <th style="text-align:left">Nome</th>
                  <th style="text-align:left">Telefone</th>
                  <th style="text-align:left">Origem</th>
                  <th style="text-align:center">Agendou</th>
                  <th style="text-align:center">Tatuou</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($itens as $it): ?>
                  <tr>
                    <td style="font-weight:600;min-width:180px"><?= $it['nome'] !== '' ? h((string)$it['nome']) : '<span class="muted">(sem nome)</span>' ?></td>
                    <td style="font-family:monospace;white-space:nowrap"><?= h((string)$it['telefone']) ?></td>
                    <td><?= h((string)$it['origem']) ?></td>
                    <td style="text-align:center"><?= $it['agendou'] ? '<span style="color:#067647;font-weight:700">✓</span>' : '<span class="muted">—</span>' ?></td>
                    <td style="text-align:center"><?= $it['tatuou'] ? '<span style="color:#067647;font-weight:700">✓</span>' : '<span class="muted">—</span>' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endforeach; ?>

      <div style="overflow-x:auto">
        <table class="table funil-tabela" style="width:100%;border-collapse:collapse">
          <thead>
            <tr>
              <th style="text-align:left">Etapa</th>
              <th style="text-align:right">Total</th>
              <th style="text-align:right">% do topo</th>
              <th style="text-align:right">% da anterior</th>
              <th style="text-align:right">Perdidos</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($funil['etapas'] as $e): ?>
              <tr>
                <td>
                  <span class="nome"><?= h($e['nome']) ?></span>
                  <span class="desc"><?= h($e['desc']) ?></span>
                </td>
                <td style="text-align:right;font-weight:700"><?= h(funil_num((int)$e['total'])) ?></td>
                <td style="text-align:right"><?= h(funil_pct($e['pct_base'])) ?></td>
                <td style="text-align:right"><?= h(funil_pct($e['pct_anterior'])) ?></td>
                <td style="text-align:right;color:#b42318"><?= $e['pct_anterior'] === null ? '—' : h(funil_num((int)$e['perdidos'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <h3 style="margin:22px 0 8px">Origem real do cliente</h3>
      <p class="muted" style="margin:0 0 8px">
        Classificação por IA das conversas (<code>funil_match</code>). Quando a IA ainda
        não analisou a conversa e não há hit de anúncio, a origem entra como
        <strong>Indefinido</strong> — não como zero.
      </p>
      <div style="overflow-x:auto">
        <table class="table" style="width:100%;border-collapse:collapse">
          <thead>
            <tr>
              <th style="text-align:left">Origem</th>
              <th style="text-align:right">Conversas</th>
              <th style="text-align:right">Agendou</th>
              <th style="text-align:right">Tatuou</th>
              <th style="text-align:right">% do total</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $totalOrigem = 0;
              foreach ($funil['origens'] as $o) { $totalOrigem += (int)$o['total']; }
            ?>
            <?php foreach ($funil['origens'] as $o): ?>
              <tr<?= $o['origem'] === 'indefinido' ? ' style="background:#f9fafb"' : '' ?>>
                <td style="font-weight:700"><?= h((string)$o['rotulo']) ?></td>
                <td style="text-align:right"><?= h(funil_num((int)$o['total'])) ?></td>
                <td style="text-align:right"><?= h(funil_num((int)$o['agendou'])) ?></td>
                <td style="text-align:right"><?= h(funil_num((int)$o['tatuou'])) ?></td>
                <td style="text-align:right"><?= h(funil_pct($totalOrigem > 0 ? ((int)$o['total'] / $totalOrigem) * 100 : null)) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($totalOrigem === 0): ?>
              <tr><td colspan="5" class="muted">Nenhuma conversa no período.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <h3 style="margin:22px 0 8px">Por plataforma do anúncio (secundário)</h3>
      <div style="overflow-x:auto">
        <table class="table" style="width:100%;border-collapse:collapse">
          <thead>
            <tr>
              <th style="text-align:left">Plataforma</th>
              <th style="text-align:right">Lead do Ads</th>
              <th style="text-align:right">Abriu conversa</th>
              <th style="text-align:right">Interagiu</th>
              <th style="text-align:right">Agendou</th>
              <th style="text-align:right">Tatuou</th>
              <th style="text-align:right">Conversão final</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$funil['por_plataforma']): ?>
              <tr><td colspan="7" class="muted">Nenhum lead de anúncio no período.</td></tr>
            <?php else: ?>
              <?php foreach ($funil['por_plataforma'] as $p): ?>
                <tr>
                  <td style="font-weight:700;text-transform:capitalize"><?= h((string)$p['plataforma']) ?></td>
                  <td style="text-align:right"><?= h(funil_num((int)$p['lead_ads'])) ?></td>
                  <td style="text-align:right"><?= h(funil_num((int)$p['conversa'])) ?> <span class="desc"><?= h(funil_pct((float)$p['pct_conversa'])) ?></span></td>
                  <td style="text-align:right"><?= h(funil_num((int)$p['interagiu'])) ?> <span class="desc"><?= h(funil_pct((float)$p['pct_interagiu'])) ?></span></td>
                  <td style="text-align:right"><?= h(funil_num((int)$p['agendou'])) ?> <span class="desc"><?= h(funil_pct((float)$p['pct_agendou'])) ?></span></td>
                  <td style="text-align:right"><?= h(funil_num((int)$p['tatuou'])) ?> <span class="desc"><?= h(funil_pct((float)$p['pct_tatuou'])) ?></span></td>
                  <td style="text-align:right;font-weight:800"><?= h(funil_pct((float)$p['pct_tatuou'])) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <details style="margin-top:16px">
        <summary style="cursor:pointer;font-weight:700">Como cada etapa é calculada</summary>
        <ul style="margin:10px 0 0 18px;line-height:1.7">
          <li><strong>Lead do Ads:</strong> <?= h(funil_num((int)$funil['etapas'][0]['total'])) ?> telefones distintos com hit em <code>ads_origin_hits</code> no período (<?= h(funil_num((int)$funil['brutos'])) ?> linhas brutas; a mesma pessoa pode gerar mais de um hit).</li>
          <li><strong>Abriu conversa:</strong> telefone do lead encontrado no arquivo de conversas da ponte dentro do período (casando com/sem DDI 55 e com/sem o 9). Também confere <code>whatsapp_conversations</code>: <?= h(funil_num($extraConv)) ?> lead(s) do período existem só por lá.</li>
          <li><strong>Interagiu:</strong> telefone com 3 ou mais mensagens <strong>recebidas</strong> (do cliente) no período.</li>
          <li><strong>Agendou:</strong> telefone com <code>agendou=1</code> ou <code>appointment_id</code> vinculado em <code>funil_match</code> (ponte feita pela IA), OU agendamento do CRM ligado ao lead/cliente daquele telefone (data entre o início do período e 180 dias à frente; datas fora de 2000–2100 são descartadas).</li>
          <li><strong>Tatuou:</strong> dos que agendaram, o <code>appointment_id</code> vinculado tem status <code>finalizado</code> — a data foi atingida e o cliente foi atendido. <code>cancelado</code> não conta.</li>
          <li><strong>Origem real:</strong> <code>funil_match.origem_detectada</code> (IA) quando existe; senão a plataforma do hit do anúncio; senão <strong>Indefinido</strong>.</li>
        </ul>
      </details>
    </section>
    <?php
}
