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
 * Traduz a REDE do clique pago do Google (segments.ad_network_type vindo do
 * click_view, da API do Google Ads) para o rotulo humano mostrado na arvore.
 * Rede desconhecida nao e escondida: volta entre parenteses.
 */
function funil_google_rede_rotulo(string $rede): string
{
    $r = strtoupper(trim($rede));
    if ($r === '') {
        return 'Google Ads (clique pago)';
    }
    $mapa = [
        'YOUTUBE' => 'YouTube',
        'YOUTUBE_SEARCH' => 'YouTube (busca)',
        'YOUTUBE_WATCH' => 'YouTube (assistindo)',
        'SEARCH' => 'Pesquisa (Google)',
        'SEARCH_PARTNERS' => 'Parceiros de pesquisa',
        'CONTENT' => 'Display (sites)',
        'DISCOVER' => 'Discover',
        'MIXED' => 'Google (misto)',
        'UNKNOWN' => 'Google (nao informado)',
    ];
    return $mapa[$r] ?? ('Google (' . ucfirst(strtolower($r)) . ')');
}

/** Codigo curto do canal da arvore para a rede do Google (ex.: google_youtube). */
function funil_google_rede_canal(string $rede): string
{
    $r = trim(strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '_', trim($rede))), '_');
    return $r === '' ? 'google_ads' : 'google_' . $r;
}

/**
 * Deriva a REDE (grupo do painel) e o CANAL de um hit de anuncio.
 *
 * A ponte grava platform/origin e, quando existe, o source_url do anuncio.
 * Rede = grupo exibido (Meta/Google/...); canal = de onde dentro daquela rede
 * o cliente veio (Facebook, Instagram, anuncio no Status do WhatsApp...).
 *
 * $redeGoogle = segments.ad_network_type do clique (YOUTUBE, SEARCH...),
 * resolvido pelo gclid e guardado em ads_click_info. Vazio = ainda nao
 * resolvido, e o Google cai no balde unico de clique pago.
 *
 * Devolve ['rede'=>string,'canal'=>string,'rotulo'=>string].
 */
function funil_canal_do_hit(string $platform, string $origin, string $sourceUrl, string $redeGoogle = ''): array
{
    $plat = strtolower(trim($platform));
    $ori  = strtolower(trim($origin));
    $url  = strtolower(trim($sourceUrl));

    // Google: clique pago. A ponte traz o click_id (gclid), sem source_url —
    // por isso a rede vem do cache resolvido na API, nao da URL.
    if ($plat === 'google' || $ori === 'google') {
        return [
            'rede' => 'google',
            'canal' => funil_google_rede_canal($redeGoogle),
            'rotulo' => funil_google_rede_rotulo($redeGoogle),
        ];
    }

    $base = $plat !== '' ? $plat : $ori;

    // Anuncio no Status do WhatsApp: source_url wa.me/wamo/status.
    if (str_contains($url, 'wa.me') || str_contains($base, 'whatsapp')) {
        return ['rede' => 'meta', 'canal' => 'whatsapp_status', 'rotulo' => 'WhatsApp (Status / anúncio)'];
    }
    if (str_contains($url, 'instagram') || str_contains($base, 'instagram') || str_contains($base, 'insta')) {
        return ['rede' => 'meta', 'canal' => 'instagram', 'rotulo' => 'Instagram'];
    }
    if (str_contains($url, 'fb.me') || str_contains($url, 'facebook') || str_contains($base, 'facebook') || str_contains($base, 'face')) {
        return ['rede' => 'meta', 'canal' => 'facebook', 'rotulo' => 'Facebook'];
    }
    if (str_contains($base, 'meta')) {
        return ['rede' => 'meta', 'canal' => 'meta_outros', 'rotulo' => 'Meta (outros)'];
    }
    if ($base === '') {
        return ['rede' => 'outros', 'canal' => 'sem_plataforma', 'rotulo' => 'Sem plataforma'];
    }
    return ['rede' => 'outros', 'canal' => $base, 'rotulo' => ucfirst($base)];
}

/**
 * Telefones de lead do Ads no período, com a plataforma e o CANAL de cada um.
 *
 * Uma linha por telefone (o primeiro hit do período manda na plataforma), porque
 * a mesma pessoa pode gerar mais de um hit; contar linha a linha inflaria a
 * etapa 1 e quebraria a lógica de "subconjunto" das etapas seguintes.
 *
 * Devolve ['por_plataforma'=>[plat=>[fone=>true]], 'todos'=>[fone=>true], 'brutos'=>int].
 */
function funil_leads_ads(PDO $pdo, string $de, string $ate): array
{
    // COALESCE(platform, origin): a ponte as vezes grava so "origin" (ex.: "meta");
    // sem esse fallback o lead caia em "sem plataforma" e virava Indefinido.
    $stmt = $pdo->prepare(
        'SELECT phone, platform, origin, source_url, click_id, created_at FROM ads_origin_hits
         WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([$de . ' 00:00:00', $ate]);

    // Rede de cada clique pago do Google (YouTube, Pesquisa, Display...), ja
    // resolvida pelo gclid e cacheada em ads_click_info. A tabela pode nao
    // existir ainda: sem ela o funil continua funcionando, so com o balde unico.
    $redeGoogleDoClique = [];
    try {
        foreach ($pdo->query('SELECT gclid, ad_network_type FROM ads_click_info')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $gclid = trim((string)($c['gclid'] ?? ''));
            if ($gclid !== '') {
                $redeGoogleDoClique[$gclid] = (string)($c['ad_network_type'] ?? '');
            }
        }
    } catch (Throwable $e) {
        $redeGoogleDoClique = [];
    }

    $porPlataforma = [];
    $porCanal = [];      // rede => canal => [fone => true]
    $canalRotulo = [];   // "rede|canal" => rotulo humano do canal
    $redeRotulo = ['meta' => 'Meta (anúncios)', 'google' => 'Google', 'outros' => 'Outros'];
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
            $plat = strtolower(trim((string)($row['origin'] ?? '')));
        }
        if ($plat === '') {
            $plat = 'sem plataforma';
        }
        if (!isset($todos[$fone])) {
            $todos[$fone] = true;
            $porPlataforma[$plat][$fone] = true;
            // Canal granular (Facebook/Instagram/Status do WhatsApp/Google...): o
            // PRIMEIRO hit do periodo manda, igual a plataforma, para nao inflar
            // a contagem quando o mesmo telefone gera mais de um hit.
            $clickId = trim((string)($row['click_id'] ?? ''));
            $canal = funil_canal_do_hit(
                (string)($row['platform'] ?? ''),
                (string)($row['origin'] ?? ''),
                (string)($row['source_url'] ?? ''),
                $clickId !== '' ? (string)($redeGoogleDoClique[$clickId] ?? '') : ''
            );
            $porCanal[$canal['rede']][$canal['canal']][$fone] = true;
            $canalRotulo[$canal['rede'] . '|' . $canal['canal']] = $canal['rotulo'];
        }
    }
    return [
        'por_plataforma' => $porPlataforma,
        'por_canal' => $porCanal,
        'canal_rotulo' => $canalRotulo,
        'rede_rotulo' => $redeRotulo,
        'todos' => $todos,
        'brutos' => $brutos,
    ];
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
 *                Vale tambem o vinculo que a IA amarrou em funil_match
 *                (appointment_id), desde que o agendamento seja unico daquele
 *                telefone - a agenda importada nao guarda telefone, entao o
 *                casamento por telefone sozinho quase nao acha nada.
 *   - tatuou   = esse appointment tem status 'finalizado' (a data foi atingida
 *                e o cliente foi atendido). 'cancelado' NÃO conta.
 *
 * Datas: "agendou" usa a data em que o cliente MARCOU (google_created_at / created_at),
 * não a data do atendimento - quem marcou ontem para hoje não é agendamento de hoje.
 * "tatuou" usa a data do ATENDIMENTO (appointment_date), dentro do período.
 * Datas absurdas são descartadas (há lixo tipo 2087-04-23).
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

    // Limites do período com hora, para comparar com a data de fechamento.
    $deHora = $de . ' 00:00:00';
    $ateHora = $ate . ' 23:59:59';
    $vals = array_keys($variacoes);
    $in = implode(',', array_fill(0, count($vals), '?'));

    // Junta o telefone do agendamento com o do lead e o do customer (o CRM liga
    // appointment a lead_id e customer_id; nem sempre o telefone está no lead).
    $sql = "SELECT a.status, a.appointment_date, a.start_time,
                   COALESCE(a.google_created_at, a.created_at) AS fechado_em,
                   COALESCE(NULLIF(TRIM(l.name),''), NULLIF(TRIM(c.name),''), NULLIF(TRIM(a.title),'')) AS nome,
                   REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(l.phone,''),'(',''),')',''),'-',''),' ','') AS fone_lead,
                   REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone,''),'(',''),')',''),'-',''),' ','') AS fone_customer
            FROM appointments a
            LEFT JOIN leads l ON l.id = a.lead_id
            LEFT JOIN customers c ON c.id = a.customer_id
            WHERE ( a.appointment_date BETWEEN ? AND ?
                 OR COALESCE(a.google_created_at, a.created_at) BETWEEN ? AND ? )
              AND (a.import_source IS NULL OR a.import_source = '' OR a.google_created_at IS NOT NULL)
              AND YEAR(a.appointment_date) BETWEEN 2000 AND 2100
              AND (
                    REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(l.phone,''),'(',''),')',''),'-',''),' ','') IN ($in)
                 OR REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone,''),'(',''),')',''),'-',''),' ','') IN ($in)
              )";
    $params = array_merge([$de, $ate, $deHora, $ateHora], $vals, $vals);

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
        $diaAppt = substr((string)($row['appointment_date'] ?? ''), 0, 10);
        // Regra do "tatuou" (a mesma do funil_agenda_match/funil_appt_aconteceu):
        // 'cancelado' nunca conta; 'finalizado' conta sempre; qualquer outro status
        // com data+hora JA PASSADAS conta (a agenda do estudo deleta o evento quando
        // ha cancelamento/falta, entao "passou e continua la" = atendimento ocorreu).
        $aconteceu = funil_appt_aconteceu(
            $status,
            $diaAppt,
            (string)($row['start_time'] ?? '')
        );
        // AGENDOU = marcou no período; TATUOU = foi atendido no período. São datas
        // diferentes: quem marcou ontem para hoje só conta como "agendou" ONTEM.
        $marcou = funil_data_entre((string)($row['fechado_em'] ?? ''), $de, $ate);
        $atendeu = $aconteceu && $diaAppt !== '' && $diaAppt >= $de && $diaAppt <= $ate;
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
                    if ($marcou) {
                        $agendou[$alvo] = true;
                    }
                    if ($atendeu) {
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

    // Vinculo pelo appointment que a IA AMARROU (funil_match.appointment_id).
    // A agenda importada do Google NAO guarda telefone, entao o casamento acima
    // (por telefone) quase nunca acha nada. O job funil_ia.js le a conversa e
    // amarra conversa -> appointment por NOME + data; quando esse vinculo
    // existe, ele aponta para um agendamento REAL (nao e o palpite
    // "funil_match.agendou", que continua fora). Guarda: o agendamento so vale
    // se UM telefone o reivindicar - se dois telefones apontam para o mesmo
    // appointment, e ambiguidade e fica de fora (era assim que "Theo", nome do
    // tatuador, ligava o telefone da Marina ao agendamento do Eduardo).
    $porAppt = [];
    try {
        $stIA = $pdo->query('SELECT telefone, appointment_id FROM funil_match WHERE appointment_id IS NOT NULL');
        foreach ($stIA->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $apptId = (int)($row['appointment_id'] ?? 0);
            if ($apptId <= 0) {
                continue;
            }
            $alvo = null;
            foreach (historico_telefone_variacoes(funil_so_digitos((string)($row['telefone'] ?? ''))) as $v) {
                if (isset($variacoes[(string)$v])) {
                    $alvo = $variacoes[(string)$v];
                    break;
                }
            }
            if ($alvo !== null) {
                $porAppt[$apptId][$alvo] = true;
            }
        }
    } catch (Throwable $e) {
        $porAppt = [];
    }

    $apptSemDuplicidade = [];
    foreach ($porAppt as $apptId => $alvos) {
        if (count($alvos) === 1) {
            $apptSemDuplicidade[] = (int)$apptId;
        }
    }
    if ($apptSemDuplicidade) {
        $inIds = implode(',', array_fill(0, count($apptSemDuplicidade), '?'));
        $stAppt = $pdo->prepare(
            "SELECT id, status, appointment_date, start_time,
                    COALESCE(google_created_at, created_at) AS fechado_em,
                    COALESCE(NULLIF(TRIM(title),''),'') AS titulo
               FROM appointments
              WHERE id IN ($inIds)
                AND ( appointment_date BETWEEN ? AND ?
                   OR COALESCE(google_created_at, created_at) BETWEEN ? AND ? )
                AND (import_source IS NULL OR import_source = '' OR google_created_at IS NOT NULL)
                AND YEAR(appointment_date) BETWEEN 2000 AND 2100"
        );
        $stAppt->execute(array_merge($apptSemDuplicidade, [$de, $ate, $deHora, $ateHora]));
        foreach ($stAppt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $diaAppt = substr((string)($row['appointment_date'] ?? ''), 0, 10);
            $aconteceu = funil_appt_aconteceu(
                (string)($row['status'] ?? ''),
                $diaAppt,
                (string)($row['start_time'] ?? '')
            );
            $marcou = funil_data_entre((string)($row['fechado_em'] ?? ''), $de, $ate);
            $atendeu = $aconteceu && $diaAppt !== '' && $diaAppt >= $de && $diaAppt <= $ate;
            foreach (array_keys($porAppt[(int)$row['id']] ?? []) as $alvo) {
                if ($marcou) {
                    $agendou[$alvo] = true;
                }
                if ($atendeu) {
                    $tatuou[$alvo] = true;
                }
                $nomeLimpo = $nomeBloco((string)($row['titulo'] ?? ''));
                if ($nomeLimpo !== '' && !isset($nomes[$alvo])) {
                    $nomes[$alvo] = $nomeLimpo;
                }
            }
        }
    }

    return ['agendou' => $agendou, 'tatuou' => $tatuou, 'nomes' => $nomes];
}

/**
 * Telefones que a IA marcou como REAGENDAMENTO (funil_match.reagendamento).
 *
 * Reagendamento = o cliente JA tinha atendimento marcado e so mudou a data.
 * Isso NAO e conversao nova do funil: o cartao "Agendou" contava esses casos e
 * o dono via numero inflado (conversa de quem so remarcou aparecia como venda).
 * Devolve [telefone => motivo da IA].
 */
function funil_reagendamentos(PDO $pdo): array
{
    $mapa = [];
    try {
        $st = $pdo->query('SELECT telefone, reagendamento_motivo FROM funil_match WHERE reagendamento = 1');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fone = funil_so_digitos((string)($row['telefone'] ?? ''));
            if ($fone !== '') {
                $mapa[$fone] = trim((string)($row['reagendamento_motivo'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $mapa;
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
    return ['meta', 'google', 'instagram', 'indicacao', 'porta', 'cliente_antigo', 'whatsapp', 'indefinido'];
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
        'whatsapp' => 'WhatsApp (direto)',
        'indefinido' => 'Indefinido',
    ];
    return $rotulos[$o] ?? ucfirst($o);
}

/**
 * Universo do funil escolhido na tela (?f_universo=ads|todos).
 *
 * 'ads'   = so quem clicou em anuncio (comportamento antigo).
 * 'todos' = inclui indicacao, porta, cliente que voltou e os agendamentos
 *           lancados direto na agenda. Padrao 'todos': o dono pediu para
 *           nenhum agendamento sumir da tela.
 */
function funil_universo(): string
{
    return strtolower(trim((string)($_GET['f_universo'] ?? ''))) === 'ads' ? 'ads' : 'todos';
}

/**
 * Traduz um codigo do catalogo de origem (ads_origem_*) para os codigos do funil.
 * 'agenda'/'manual'/'outro' sao forma de CADASTRO, nao origem de marketing:
 * entram como indefinido e aparecem com o nome real no bloco "Fora do Ads".
 */
function funil_origem_do_catalogo(string $codigo): string
{
    $mapa = [
        'meta' => 'meta',
        'instagram' => 'instagram',
        'google' => 'google',
        'indicacao' => 'indicacao',
        'porta' => 'porta',
        'reincidente' => 'cliente_antigo',
        'organico_ig' => 'instagram',
        'whatsapp' => 'whatsapp',
        'tiktok' => 'indefinido',
        'agenda' => 'indefinido',
        'manual' => 'indefinido',
        'outro' => 'indefinido',
    ];

    return $mapa[strtolower(trim($codigo))] ?? 'indefinido';
}

/**
 * Origem DECLARADA no CRM (leads.source) dos leads criados no periodo.
 *
 * O funil so olhava clique pago + IA da conversa, entao quem entrou por
 * indicacao, passou na porta ou foi lancado direto na agenda ficava sem
 * origem nenhuma. Aqui o que o dono/atendente classificou ganha voz.
 * Devolve ['por_fone' => [fone => codigo do funil]].
 */
function funil_origens_crm(PDO $pdo, string $de, string $ate): array
{
    $out = ['por_fone' => []];
    try {
        $stmt = $pdo->prepare(
            'SELECT id, phone, source FROM leads WHERE DATE(created_at) BETWEEN ? AND ?'
        );
        $stmt->execute([$de, $ate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $codigo = function_exists('ads_origem_normalizar')
                ? ads_origem_normalizar((string)($row['source'] ?? ''))
                : '';
            $origem = funil_origem_do_catalogo($codigo);
            $fone = funil_so_digitos((string)($row['phone'] ?? ''));
            if ($fone !== '' && !isset($out['por_fone'][$fone])) {
                $out['por_fone'][$fone] = $origem;
            }
        }
    } catch (Throwable $e) {
        // tabela/coluna indisponivel: o funil continua funcionando como antes
    }

    return $out;
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
 * A data (YYYY-MM-DD, ignorando a hora) cai dentro do período [de, ate]?
 * Usada para separar "marcou no período" de "foi atendido no período".
 */
function funil_data_entre(string $dataHora, string $de, string $ate): bool
{
    $d = substr(trim($dataHora), 0, 10);
    return $d !== '' && $d >= $de && $d <= $ate;
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

    // Origem real (funil_match / IA). O hit de anuncio MANDA (clique pago
    // comprovado); a IA so decide quem nao tem hit (ver $origemDe mais abaixo).
    $ia = funil_origens_ia($pdo);
    $origemIA = $ia['por_fone'];

    // Universo da tela: 'ads' = so clique pago; 'todos' = inclui indicacao,
    // porta, cliente que voltou e quem foi lancado direto na agenda.
    $universo = funil_universo();
    $crmOrigens = funil_origens_crm($pdo, $de, $ate);
    $baseFones = $escopo['todos'];
    if ($universo === 'todos') {
        foreach (array_keys($pontePeriodo) as $f) {
            $baseFones[(string)$f] = true;
        }
        foreach (array_keys($crmOrigens['por_fone']) as $f) {
            $baseFones[(string)$f] = true;
        }
    }

    $abriramPeriodo = funil_casar_telefones($baseFones, $pontePeriodo);
    $abriramGeral = funil_casar_telefones($baseFones, $ponteGeral);

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
    // NÃO cruzar com "agendou": quem foi atendido hoje pode ter marcado antes do
    // período (o cartão "Tatuou" é sobre o atendimento, não sobre o fechamento).
    $tatuou = $agenda['tatuou'];
    // Nome de quem tem appointment (prioridade na coluna Nome).
    $nomesAgenda = $agenda['nomes'] ?? [];

    // Universo "Todos": soma tambem os agendamentos de quem nao veio de anuncio,
    // senao o cartao "Agendou" ficaria 0 com a agenda cheia.
    $agendouBase = $agendou;
    $tatuouBase = $tatuou;
    if ($universo === 'todos') {
        $agendaBase = funil_agenda($pdo, $baseFones, $de, $ate);
        $agendouBase += $agendaBase['agendou'];
        $tatuouBase += $agendaBase['tatuou'];
    }

    // REAGENDAMENTO nao e agendamento novo: o cliente ja tinha atendimento e so
    // trocou a data. Sai do cartao "Agendou" (senao infla a conversao); os itens
    // ficam visiveis em "ver leads" marcados, para o dono conferir.
    $reagSet = [];
    foreach (funil_reagendamentos($pdo) as $foneReag => $motivoReag) {
        foreach (historico_telefone_variacoes((string)$foneReag) as $v) {
            $reagSet[(string)$v] = $motivoReag;
        }
    }
    $reagendouBase = [];
    if ($reagSet) {
        foreach (array_keys($agendouBase) as $foneBase) {
            $motivoReag = $reagSet[(string)$foneBase] ?? null;
            if ($motivoReag === null) {
                foreach (historico_telefone_variacoes((string)$foneBase) as $v) {
                    if (isset($reagSet[(string)$v])) {
                        $motivoReag = $reagSet[(string)$v];
                        break;
                    }
                }
            }
            if ($motivoReag !== null) {
                $reagendouBase[(string)$foneBase] = (string)$motivoReag;
                unset($agendouBase[$foneBase], $tatuouBase[$foneBase]);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Distribuição por ORIGEM REAL (meta/google/instagram/indicacao/porta/
    // cliente_antigo/indefinido). "Indefinido" aparece explicitamente.
    // Precedência: ads_origin_hits.platform (clique pago COMPROVADO) >
    // funil_match (IA) > indefinido.
    // ---------------------------------------------------------------------
    $origens = [];
    foreach (funil_origens_validas() as $o) {
        $origens[$o] = ['origem' => $o, 'rotulo' => funil_origem_rotulo($o), 'total' => 0, 'agendou' => 0, 'tatuou' => 0];
    }
    // Universo da distribuicao por origem: a MESMA base do funil acima, para a
    // tabela e os cartoes nunca contarem coisas diferentes.
    $universoOrigem = $baseFones;

    $plataformaParaOrigem = static function (string $plat): string {
        $p = strtolower(trim($plat));
        if ($p === '') {
            return 'indefinido';
        }
        // "organico_ig" e o unico rotulo de Instagram que NAO e anuncio pago.
        if (str_contains($p, 'organico') || str_contains($p, 'organic')) {
            return 'instagram';
        }
        // Anuncio de Instagram, Facebook ou Meta e SEMPRE Meta Ads. O Instagram
        // organico de verdade vem da IA (funil_match), nao da plataforma do hit.
        if (str_contains($p, 'meta') || str_contains($p, 'facebook') || str_contains($p, 'face') || str_contains($p, 'insta')) {
            return 'meta';
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

    // Precedencia: hit de anuncio > IA > indefinido.
    // O hit e prova dura: a ponte so grava quando o WhatsApp traz o ctwa_clid
    // (clique pago de verdade), e o periodo do hit e o do proprio clique. A IA
    // le a conversa e erra para "instagram/indicacao" quem veio de anuncio -
    // era assim que anuncio pago virava "Instagram (organico)".
    // "indefinido" da IA quer dizer "nao achei sinal na conversa": nesse caso
    // vale a plataforma do hit; sem isso Meta/Google viravam Indefinido.
    $origemDe = static function (string $fone) use ($origemIA, $origemHits, $crmOrigens): string {
        $hit = $origemHits[$fone] ?? '';
        if ($hit !== '' && $hit !== 'indefinido') {
            return $hit;
        }
        // Classificacao manual do CRM manda sobre o palpite da IA: quem respondeu
        // "veio por indicacao / passou na porta" sabe mais que a leitura da conversa.
        $crm = $crmOrigens['por_fone'][$fone] ?? '';
        if ($crm !== '' && $crm !== 'indefinido') {
            return $crm;
        }
        $o = $origemIA[$fone] ?? '';
        return $o !== '' ? $o : 'indefinido';
    };

    foreach (array_keys($universoOrigem) as $fone) {
        $o = $origemDe((string)$fone);
        if (!isset($origens[$o])) {
            $o = 'indefinido';
        }
        $origens[$o]['total']++;
        if (isset($agendouBase[$fone])) {
            $origens[$o]['agendou']++;
        }
        if (isset($tatuouBase[$fone])) {
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
        $universo === 'ads'
            ? ['chave' => 'lead_ads', 'nome' => 'Lead do Ads', 'desc' => 'Contatos que clicaram no anúncio', 'total' => count($escopo['todos'])]
            : ['chave' => 'lead_ads', 'nome' => 'Contatos no período', 'desc' => 'Anúncio, indicação, porta e agenda', 'total' => count($baseFones)],
        ['chave' => 'conversa', 'nome' => 'Abriu conversa', 'desc' => 'Telefone apareceu no WhatsApp', 'total' => count($abriramPeriodo)],
        ['chave' => 'interagiu', 'nome' => 'Interagiu', 'desc' => '3+ mensagens recebidas no período', 'total' => count($interagiram)],
        ['chave' => 'agendou', 'nome' => 'Agendou', 'desc' => 'Agendamento real marcado no período (sem reagendamento)', 'total' => count($agendouBase), 'reagendamentos' => count($reagendouBase)],
        ['chave' => 'tatuou', 'nome' => 'Tatuou', 'desc' => 'Atendimento que aconteceu no período', 'total' => count($tatuouBase)],
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

    // Quebra por REDE (Meta/Google...) e, dentro dela, por CANAL (Facebook,
    // Instagram, Status do WhatsApp...): recalcula a cadeia inteira em cada
    // recorte, com a mesma regra das etapas (o primeiro hit do periodo manda).
    $metricas = static function (array $fones) use ($abriramPeriodo, $interagiram, $agendou, $tatuou): array {
        $abriu = 0;
        $inter = 0;
        $ag = 0;
        $tat = 0;
        foreach ($fones as $f => $_) {
            $f = (string)$f;
            if (isset($abriramPeriodo[$f])) { $abriu++; }
            if (isset($interagiram[$f]))   { $inter++; }
            if (isset($agendou[$f]))       { $ag++; }
            if (isset($tatuou[$f]))        { $tat++; }
        }
        $total = count($fones);
        return [
            'lead_ads' => $total,
            'conversa' => $abriu,
            'interagiu' => $inter,
            'agendou' => $ag,
            'tatuou' => $tat,
            'pct_conversa' => $total > 0 ? ($abriu / $total) * 100 : 0.0,
            'pct_interagiu' => $total > 0 ? ($inter / $total) * 100 : 0.0,
            'pct_agendou' => $total > 0 ? ($ag / $total) * 100 : 0.0,
            'pct_tatuou' => $total > 0 ? ($tat / $total) * 100 : 0.0,
        ];
    };

    $porPlataforma = [];
    foreach (($escopo['por_canal'] ?? []) as $rede => $canais) {
        $fonesRede = [];
        $linhas = [];
        foreach ($canais as $canal => $fones) {
            $linha = $metricas($fones);
            $linha['canal'] = (string)$canal;
            $linha['rotulo'] = (string)(($escopo['canal_rotulo'] ?? [])[$rede . '|' . $canal] ?? ucfirst((string)$canal));
            $linhas[] = $linha;
            foreach (array_keys($fones) as $f) {
                $fonesRede[(string)$f] = true;
            }
        }
        usort($linhas, static fn(array $a, array $b): int => $b['lead_ads'] <=> $a['lead_ads']);
        $resumo = $metricas($fonesRede);
        $resumo['rede'] = (string)$rede;
        $resumo['rotulo'] = (string)(($escopo['rede_rotulo'] ?? [])[$rede] ?? ucfirst((string)$rede));
        $resumo['canais'] = $linhas;
        $porPlataforma[] = $resumo;
    }
    usort($porPlataforma, static fn(array $a, array $b): int => $b['lead_ads'] <=> $a['lead_ads']);

    // ---------------------------------------------------------------------
    // Listas de telefones por etapa, para o drill-down (clicar no número do
    // card e ver QUEM são os leads). Mesmas chaves das etapas.
    // ---------------------------------------------------------------------
    $listas = [
        'lead_ads' => array_keys($baseFones),
        'conversa' => array_keys($abriramPeriodo),
        'interagiu' => array_keys($interagiram),
        'agendou' => array_keys($agendouBase),
        'tatuou' => array_keys($tatuouBase),
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
                'origem' => funil_origem_rotulo($origemDe($fone)),
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

    // Reagendamentos: nao contam no cartao "Agendou", mas aparecem no drill-down
    // marcados (quem so remarcou nao e conversao nova).
    $detalhes['reagendamento'] = [];
    foreach ($reagendouBase as $foneReag => $motivoReag) {
        $foneReag = (string)$foneReag;
        $detalhes['reagendamento'][] = [
            'telefone' => $foneReag,
            'nome' => $nomes[$foneReag] ?? '',
            'origem' => funil_origem_rotulo($origemDe($foneReag)),
            'motivo' => (string)$motivoReag,
        ];
    }

    // Bloco "Fora do Ads": agendamentos do periodo sem nenhum clique pago.
    // E aqui que aparecem os clientes de indicacao/porta, que nao passam pelo WhatsApp.
    // No universo "So Ads" o bloco nao faz sentido, entao nem consulta.
    $foraAds = $universo === 'todos' ? funil_fora_ads($pdo, $escopo['todos'], $de, $ate) : [];

    return [
        'etapas' => $etapas,
        'detalhes' => $detalhes,
        'por_plataforma' => $porPlataforma,
        'origens' => $origemLista,
        'fora_ads' => $foraAds,
        'universo' => $universo,
        'reagendamentos' => count($reagendouBase),
        'progresso_ia' => $progressoIA,
        'brutos' => (int)($escopo['brutos'] ?? 0),
        'ponte_online' => count($ponteGeral) > 0,
        // Quantos leads do Ads apareceram no arquivo da ponte em QUALQUER data
        // (útil para explicar por que a etapa 2 pode ser baixa num período curto).
        'com_mensagem_geral' => count(funil_casar_telefones($escopo['todos'], $ponteGeral)),
        'arquivo_existe' => is_file(historico_conversas_path()),
    ];
}

/**
 * Agendamentos do periodo que NAO tem clique pago nenhum.
 *
 * O funil era ancorado no hit do anuncio, entao o cliente que chegou por
 * indicacao ou passou na porta simplesmente nao existia na tela. Aqui ele
 * aparece com a origem declarada no CRM (leads.source).
 */
function funil_fora_ads(PDO $pdo, array $leadsAds, string $de, string $ate): array
{
    $variacoes = [];
    foreach (array_keys($leadsAds) as $fone) {
        foreach (historico_telefone_variacoes((string)$fone) as $v) {
            $variacoes[(string)$v] = true;
        }
    }

    $fim = (new DateTimeImmutable($ate))->modify('+180 days')->format('Y-m-d');
    $sql = "SELECT a.id, a.title, a.appointment_date, a.start_time, a.status, a.value,
                   COALESCE(NULLIF(TRIM(l.name),''), NULLIF(TRIM(c.name),''), NULLIF(TRIM(a.title),'')) AS nome,
                   COALESCE(l.source, '') AS origem_crm,
                   REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(l.phone,''),'(',''),')',''),'-',''),' ','') AS fone_lead,
                   REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone,''),'(',''),')',''),'-',''),' ','') AS fone_customer
              FROM appointments a
              LEFT JOIN leads l ON l.id = a.lead_id
              LEFT JOIN customers c ON c.id = a.customer_id
             WHERE a.appointment_date BETWEEN ? AND ?
               AND YEAR(a.appointment_date) BETWEEN 2000 AND 2100
               AND a.status NOT IN ('cancelado', 'perdido')
             ORDER BY a.appointment_date ASC, a.start_time ASC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$de, $fim]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    // Agendamento que a IA amarrou a um telefone de anuncio tambem e do Ads.
    $apptAds = [];
    try {
        $stIA = $pdo->query('SELECT telefone, appointment_id FROM funil_match WHERE appointment_id IS NOT NULL');
        foreach ($stIA->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $apptId = (int)($row['appointment_id'] ?? 0);
            if ($apptId <= 0) {
                continue;
            }
            foreach (historico_telefone_variacoes(funil_so_digitos((string)($row['telefone'] ?? ''))) as $v) {
                if (isset($variacoes[(string)$v])) {
                    $apptAds[$apptId] = true;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        $apptAds = [];
    }

    $fora = [];
    foreach ($rows as $row) {
        if (isset($apptAds[(int)$row['id']])) {
            continue;
        }
        $veioDeAnuncio = false;
        foreach (['fone_lead', 'fone_customer'] as $campo) {
            $fone = funil_so_digitos((string)($row[$campo] ?? ''));
            if ($fone === '') {
                continue;
            }
            foreach (historico_telefone_variacoes($fone) as $v) {
                if (isset($variacoes[(string)$v])) {
                    $veioDeAnuncio = true;
                    break 2;
                }
            }
        }
        if ($veioDeAnuncio) {
            continue;
        }

        $codigo = function_exists('ads_origem_normalizar')
            ? ads_origem_normalizar((string)($row['origem_crm'] ?? ''))
            : '';
        $rotulo = $codigo === ''
            ? 'Sem origem'
            : (function_exists('ads_origem_rotulo') ? ads_origem_rotulo($codigo) : ucfirst($codigo));

        $foneChave = funil_so_digitos((string)($row['fone_lead'] ?? ''));
        if ($foneChave === '') {
            $foneChave = funil_so_digitos((string)($row['fone_customer'] ?? ''));
        }
        $nomeChave = strtolower(preg_replace('/\s+/u', ' ', trim((string)($row['nome'] ?? ''))) ?? '');

        $fora[] = [
            'id' => (int)($row['id'] ?? 0),
            'data' => (string)($row['appointment_date'] ?? ''),
            'hora' => substr((string)($row['start_time'] ?? ''), 0, 5),
            'nome' => trim((string)($row['nome'] ?? '')),
            'origem' => $rotulo,
            'origem_codigo' => $codigo !== '' ? $codigo : 'sem_origem',
            'status' => (string)($row['status'] ?? ''),
            'valor' => (float)($row['value'] ?? 0),
            'chave' => $foneChave !== '' ? 'fone:' . $foneChave : 'nome:' . $nomeChave,
        ];
    }

    // O mesmo cliente aparece 2+ vezes quando o atendimento foi REMARCADO: a
    // agenda cria um evento novo no Google e o antigo continua como "confirmado".
    // Agrupa por cliente (telefone quando existe, senao o nome) para remarcacao
    // nao contar como agendamento novo. Data/status/valor exibidos = o ultimo.
    $grupos = [];
    foreach ($fora as $row) {
        $chave = (string)$row['chave'];
        if (!isset($grupos[$chave])) {
            $row['datas'] = [];
            $row['qtd'] = 0;
            $grupos[$chave] = $row;
        }
        $grupos[$chave]['qtd']++;
        $grupos[$chave]['datas'][] = $row['data'] . ($row['hora'] !== '' ? ' ' . $row['hora'] : '');
        if ($row['data'] >= $grupos[$chave]['data']) {
            $grupos[$chave]['id'] = $row['id'];
            $grupos[$chave]['data'] = $row['data'];
            $grupos[$chave]['hora'] = $row['hora'];
            $grupos[$chave]['status'] = $row['status'];
            $grupos[$chave]['valor'] = $row['valor'];
            $grupos[$chave]['origem'] = $row['origem'];
            $grupos[$chave]['origem_codigo'] = $row['origem_codigo'];
        }
    }

    return array_values($grupos);
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

    // Periodo atual, para os botoes que so trocam o universo sem perder o filtro.
    $paramsPeriodo = (string)($periodo['atalho'] ?? '') !== ''
        ? ['f_atalho' => (string)$periodo['atalho']]
        : ['f_de' => $de, 'f_ate' => $ate];
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
        Do anúncio até a tatuagem feita. As <strong>etapas</strong> contam só quem clicou
        no anúncio; o filtro <strong>Universo</strong> e a tabela de origens também
        mostram quem chegou por indicação, porta ou foi lançado direto na agenda.
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
        <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
          <span class="funil-chip">Universo</span>
          <?php $universoAtual = (string)($funil['universo'] ?? 'todos'); ?>
          <a class="btn <?= $universoAtual === 'todos' ? 'primary' : 'secondary' ?>" href="<?= h($queryPreset($paramsPeriodo + ['f_universo' => 'todos'])) ?>">Todos</a>
          <a class="btn <?= $universoAtual === 'ads' ? 'primary' : 'secondary' ?>" href="<?= h($queryPreset($paramsPeriodo + ['f_universo' => 'ads'])) ?>">Só Ads</a>
          <span class="desc" style="font-size:11px;color:#98a2b3">em "Todos" entram indicação, porta e agenda</span>
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
            <?php if (!empty($e['reagendamentos'])): ?>
              <div class="sub" style="color:#b54708;margin-top:4px">
                ↺ <?= h(funil_num((int)$e['reagendamentos'])) ?> reagendamento(s) fora da conta
              </div>
            <?php endif; ?>
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

      <?php $reagItens = $funil['detalhes']['reagendamento'] ?? []; ?>
      <?php if ($reagItens): ?>
        <details class="funil-detalhe" id="leads-reagendamento" style="margin:0 0 14px">
          <summary style="cursor:pointer;font-weight:700">
            <i class="fa-solid fa-rotate-left"></i>
            Reagendamentos — <?= h(funil_num(count($reagItens))) ?> contato(s)
            <span class="desc" style="font-weight:500">já tinham atendimento e só trocaram a data (não contam como Agendou)</span>
          </summary>
          <div style="overflow-x:auto;margin-top:10px">
            <table class="table" style="width:100%;border-collapse:collapse">
              <thead>
                <tr>
                  <th style="text-align:left">Nome</th>
                  <th style="text-align:left">Telefone</th>
                  <th style="text-align:left">Origem</th>
                  <th style="text-align:left">Por que é reagendamento</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($reagItens as $it): ?>
                  <tr>
                    <td style="font-weight:600;min-width:180px"><?= $it['nome'] !== '' ? h((string)$it['nome']) : '<span class="muted">(sem nome)</span>' ?></td>
                    <td style="font-family:monospace;white-space:nowrap"><?= h((string)$it['telefone']) ?></td>
                    <td><?= h((string)$it['origem']) ?></td>
                    <td class="muted"><?= h((string)$it['motivo']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endif; ?>

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
        Clique pago comprovado (<code>ads_origin_hits</code>) manda; sem ele vale a
        origem declarada no CRM (<code>leads.source</code>: indicação, porta, cliente que
        voltou) e, por último, a IA da conversa (<code>funil_match</code>). Sem nenhum
        dos três, a origem entra como <strong>Indefinido</strong> — não como zero.
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

      <details class="funil-detalhe" style="margin-top:22px" open>
        <summary style="font-size:14px;padding:4px 0">
          <strong>Fora do Ads (indicação, porta, agenda)</strong>
          <span class="desc"><?= h(funil_num(count($funil['fora_ads']))) ?> cliente(s) sem clique pago no período</span>
        </summary>
        <div style="padding-top:8px">
      <?php if (($funil['universo'] ?? 'todos') === 'ads'): ?>
      <p class="muted" style="margin:0 0 8px">Filtro em <strong>Só Ads</strong> — troque para "Todos" para ver os agendamentos de outras origens.</p>
      <?php else: ?>
      <?php
        $foraDatas = 0;
        $foraMulti = 0;
        foreach ($funil['fora_ads'] as $fx) {
            $q = (int)($fx['qtd'] ?? 1);
            $foraDatas += $q;
            if ($q > 1) { $foraMulti++; }
        }
      ?>
      <p class="muted" style="margin:0 0 8px">
        <strong><?= h(funil_num(count($funil['fora_ads']))) ?> cliente(s)</strong> sem clique pago
        (<?= h(funil_num($foraDatas)) ?> agendamento(s)<?php if ($foraMulti > 0): ?>, sendo <?= h(funil_num($foraMulti)) ?> com 2+ datas —
        reagendamento ou recorrência<?php endif; ?>). É aqui que o cliente de indicação ou de porta existe
        na tela — sem depender de clique pago nem de conversa no WhatsApp. Quando o mesmo cliente
        tem mais de uma data, é remarcação: a agenda cria um evento novo no Google e o antigo
        continua como "confirmado". A data mostrada é a última; passe o mouse nela para ver todas.
        Esta lista é a <strong>agenda</strong> de quem não veio de anúncio (inclui agendamentos futuros, até 180 dias);
        o cartão "Agendou" acima conta só quem fechou o agendamento dentro do período.
      </p>
      <?php endif; ?>
      <?php if (($funil['universo'] ?? 'todos') !== 'ads'): ?>
      <div style="overflow-x:auto">
        <table class="table funil-tabela" style="width:100%;border-collapse:collapse">
          <thead>
            <tr>
              <th style="text-align:left">Data</th>
              <th style="text-align:left">Cliente</th>
              <th style="text-align:left">Origem</th>
              <th style="text-align:left">Status</th>
              <th style="text-align:right">Valor</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$funil['fora_ads']): ?>
              <tr><td colspan="5" class="muted">Nenhum agendamento fora do anúncio no período.</td></tr>
            <?php else: ?>
              <?php foreach ($funil['fora_ads'] as $f): ?>
                <tr>
                  <td style="white-space:nowrap">
                    <?= h($formatoData((string)$f['data'])) ?><?= $f['hora'] !== '' ? ' ' . h((string)$f['hora']) : '' ?>
                    <?php if ((int)($f['qtd'] ?? 1) > 1): ?>
                      <span class="desc" title="<?= h(implode(' · ', (array)($f['datas'] ?? []))) ?>"><?= h((string)$f['qtd']) ?> datas</span>
                    <?php endif; ?>
                  </td>
                  <td class="nome"><?= h((string)$f['nome']) ?></td>
                  <td><?= h((string)$f['origem']) ?></td>
                  <td><?= h((string)$f['status']) ?></td>
                  <td style="text-align:right">R$ <?= h(number_format((float)$f['valor'], 2, ',', '.')) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
        </div>
      </details>

      <h3 style="margin:22px 0 8px">Por plataforma do anúncio (secundário)</h3>
      <?php if (!$funil['por_plataforma']): ?>
        <p class="muted">Nenhum lead de anúncio no período.</p>
      <?php else: ?>
        <p class="muted" style="margin:0 0 8px">Clique na plataforma para ver de onde o cliente veio (Facebook, Instagram, Status do WhatsApp, Google...).</p>
        <?php foreach ($funil['por_plataforma'] as $p): ?>
          <details class="funil-detalhe" style="margin:0 0 12px">
            <summary style="cursor:pointer;font-weight:700;font-size:14px;padding:2px 0">
              <?= h((string)$p['rotulo']) ?>
              <span class="desc"><?= h(funil_num((int)$p['lead_ads'])) ?> lead(s) · <?= h(funil_num((int)$p['agendou'])) ?> agendou · <?= h(funil_num((int)$p['tatuou'])) ?> tatuou</span>
            </summary>
            <div style="overflow-x:auto;margin-top:10px">
              <table class="table" style="width:100%;border-collapse:collapse">
                <thead>
                  <tr>
                    <th style="text-align:left">Canal</th>
                    <th style="text-align:right">Lead do Ads</th>
                    <th style="text-align:right">Abriu conversa</th>
                    <th style="text-align:right">Interagiu</th>
                    <th style="text-align:right">Agendou</th>
                    <th style="text-align:right">Tatuou</th>
                    <th style="text-align:right">Conversão final</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($p['canais'] as $c): ?>
                    <tr>
                      <td style="font-weight:600"><?= h((string)$c['rotulo']) ?></td>
                      <td style="text-align:right"><?= h(funil_num((int)$c['lead_ads'])) ?></td>
                      <td style="text-align:right"><?= h(funil_num((int)$c['conversa'])) ?> <span class="desc"><?= h(funil_pct((float)$c['pct_conversa'])) ?></span></td>
                      <td style="text-align:right"><?= h(funil_num((int)$c['interagiu'])) ?> <span class="desc"><?= h(funil_pct((float)$c['pct_interagiu'])) ?></span></td>
                      <td style="text-align:right"><?= h(funil_num((int)$c['agendou'])) ?> <span class="desc"><?= h(funil_pct((float)$c['pct_agendou'])) ?></span></td>
                      <td style="text-align:right"><?= h(funil_num((int)$c['tatuou'])) ?> <span class="desc"><?= h(funil_pct((float)$c['pct_tatuou'])) ?></span></td>
                      <td style="text-align:right;font-weight:800"><?= h(funil_pct((float)$c['pct_tatuou'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (count($p['canais']) > 1): ?>
                    <tr style="border-top:2px solid #e6e8ee;font-weight:700">
                      <td>Total <?= h((string)$p['rotulo']) ?></td>
                      <td style="text-align:right"><?= h(funil_num((int)$p['lead_ads'])) ?></td>
                      <td style="text-align:right"><?= h(funil_num((int)$p['conversa'])) ?> <span class="desc"><?= h(funil_pct((float)$p['pct_conversa'])) ?></span></td>
                      <td style="text-align:right"><?= h(funil_num((int)$p['interagiu'])) ?> <span class="desc"><?= h(funil_pct((float)$p['pct_interagiu'])) ?></span></td>
                      <td style="text-align:right"><?= h(funil_num((int)$p['agendou'])) ?> <span class="desc"><?= h(funil_pct((float)$p['pct_agendou'])) ?></span></td>
                      <td style="text-align:right"><?= h(funil_num((int)$p['tatuou'])) ?> <span class="desc"><?= h(funil_pct((float)$p['pct_tatuou'])) ?></span></td>
                      <td style="text-align:right;font-weight:800"><?= h(funil_pct((float)$p['pct_tatuou'])) ?></td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </details>
        <?php endforeach; ?>
      <?php endif; ?>

      <details style="margin-top:16px">
        <summary style="cursor:pointer;font-weight:700">Como cada etapa é calculada</summary>
        <ul style="margin:10px 0 0 18px;line-height:1.7">
          <li><strong>Contatos do funil:</strong> em "Só Ads" são os <?= h(funil_num((int)$funil['brutos'])) ?> hits brutos de <code>ads_origin_hits</code> no período virados em telefones distintos. Em "Todos" a base soma também quem tem conversa na ponte ou origem declarada no CRM (indicação, porta, agenda).</li>
          <li><strong>Abriu conversa:</strong> telefone do lead encontrado no arquivo de conversas da ponte dentro do período (casando com/sem DDI 55 e com/sem o 9). Também confere <code>whatsapp_conversations</code>: <?= h(funil_num($extraConv)) ?> lead(s) do período existem só por lá.</li>
          <li><strong>Interagiu:</strong> telefone com 3 ou mais mensagens <strong>recebidas</strong> (do cliente) no período.</li>
          <li><strong>Agendou:</strong> agendamento REAL ligado ao contato — (a) o agendamento do CRM cujo lead/cliente tem o telefone do hit, ou (b) o agendamento que a IA amarrou à conversa em <code>funil_match.appointment_id</code>, contando só quando esse agendamento é exclusivo daquele telefone. O palpite <code>funil_match.agendou</code> NÃO conta. O que conta é a data em que o agendamento foi FECHADO (<code>google_created_at</code>; sem ela, <code>created_at</code>) cair dentro do período — quem marcou ontem para hoje conta como "agendou" ontem. Eventos importados em massa (sem <code>google_created_at</code>) ficam de fora.</li>
          <li><strong>Tatuou:</strong> o atendimento vinculado ao contato aconteceu com a <strong>data do atendimento</strong> dentro do período. <code>finalizado</code> conta sempre; outros status contam quando a data e a hora já passaram (a agenda apaga o evento quando há cancelamento ou falta). <code>cancelado</code> não conta. É independente de "Agendou": quem marcou antes do período e foi atendido nele aparece aqui.</li>
          <li><strong>Origem real:</strong> a plataforma do hit do anúncio (clique pago comprovado) manda; sem hit vale a origem declarada no CRM (<code>leads.source</code>); sem ela, <code>funil_match.origem_detectada</code> (IA); sem nada, <strong>Indefinido</strong>.</li>
          <li><strong>Canal do anúncio:</strong> dentro de cada plataforma, "de onde veio" sai do <code>source_url</code> do hit: <code>fb.me</code> = Facebook, <code>instagram.com</code> = Instagram, <code>wa.me/wamo/status</code> = anúncio no Status do WhatsApp. No Google a ponte só traz o <code>click_id</code> (sem URL): por isso a rede (YouTube, Pesquisa, Display, Discover...) vem da API do Google Ads pelo <code>gclid</code> e fica em <code>ads_click_info</code>. Clique ainda não resolvido aparece como "Google Ads (clique pago)".</li>
          <li><strong>Fora do Ads:</strong> agendamentos do período sem nenhum clique pago (indicação, porta, Google Agenda). É o bloco que faz o cliente que não veio de anúncio existir na tela. Fica de fora só o que está <code>cancelado</code>/<code>perdido</code>.</li>
        </ul>
      </details>
    </section>
    <?php
}
