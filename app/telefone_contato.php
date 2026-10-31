<?php
/**
 * Resolve o telefone de um agendamento/lead para exibir e linkar no CRM.
 *
 * Ordem de tentativa (a primeira que der telefone utilizavel vence):
 *   1. customer.phone  (cadastro do cliente)
 *   2. lead.phone      (cadastro do lead)
 *   3. casamento por NOME nas conversas da ponte do Baileys
 *
 * O casamento por nome existe porque a importacao do Google Agenda quase nunca
 * traz telefone (o evento nao tem o numero), mas o titulo SEMPRE comeca com o
 * nome completo do cliente - e a ponte do Baileys guarda o nome que o cliente
 * escreveu na conversa.
 *
 * CUIDADO: o titulo costuma ter RABO depois do nome (valor, regiao, observacao):
 * "Denise Araujo, fechamento costas 799, para Théo". Casar so o NOME e ignorar o
 * rabo. Regra para nao confundir pessoas diferentes:
 *   - compara apenas os PRIMEIROS tokens do nome (nome + sobrenome);
 *   - exige bater pelo menos 2 tokens (nome E sobrenome), nunca 1;
 *   - o token do nome do agendamento precisa aparecer como PALAVRA inteira no
 *     nome da conversa (evita "Ana" casar com "Mariana").
 */

/** Palavras que nunca fazem parte do nome do cliente (cauda do titulo). */
function crm_telefone_palavras_ruido(): array
{
    return [
        'nao', 'levar', 'reuniao', 'estorno', 'chegar', 'lembrar', 'limpeza', 'niver',
        'casamento', 'pericia', 'inss', 'cognizant', 'consultar', 'ligar', 'comprar',
        'buscar', 'pagar', 'ver', 'avisar', 'mandar', 'enviar', 'falar', 'retorno',
        'para', 'com', 'sem', 'dia', 'hora', 'h', 'as', 'ate', 'de', 'da', 'do',
        'costas', 'peito', 'braco', 'perna', 'coxa', 'peitoral', 'fechamento',
        'feminina', 'masculina', 'tattoo', 'tatuagem', 'pomada', 'pomadas', 'sinal',
        'pago', 'pix', 'talvez', 'talves', 'retocar', 'retoque', 'cobertura',
        'orcar', 'orcamento', 'fazer', 'feito', 'sessao', 'area', 'local', 'estudio',
        'cereja', 'daniel', 'danilo', 'tatuador', 'inicio', 'finalizar', 'final',
        'pela', 'pelo', 'mim', 'meu', 'minha', 'ele', 'ela', 'nos', 'vcs', 'vc',
        'mais', 'menos', 'depois', 'antes', 'hoje', 'amanha', 'ontem',
        // formas verbais e palavras soltas que aparecem no RABO do titulo
        // ("Anderson feijao nao mandou", "Priscila Soares so sinal"): nao fazem
        // parte do nome e inflavam a contagem de tokens.
        'mandou', 'avisou', 'ligou', 'falou', 'pagou', 'pediu', 'marcou', 'confirmou',
        'remarcou', 'reagendou', 'enviou', 'trouxe', 'levou', 'chegou', 'voltou',
        'quer', 'queria', 'precisa', 'preciso', 'sabe', 'tem', 'ter', 'esta', 'estava',
        'ficou', 'deixou', 'vai', 'vou', 'foi', 'so', 'das', 'dos', 'duas', 'dois',
        'meio', 'meia', 'desenho', 'desenhos', 'gratis', 'gratuito', 'segunda',
    ];
}

/** Normaliza texto de nome: minusculo, sem acento, so letras e espaco. */
function crm_telefone_normalizar(string $valor): string
{
    $valor = function_exists('historico_sem_acento')
        ? historico_sem_acento($valor)
        : mb_strtolower($valor, 'UTF-8');
    $valor = preg_replace('/[^a-z ]/', ' ', $valor) ?? $valor;
    $valor = preg_replace('/\s+/', ' ', $valor) ?? $valor;
    return trim($valor);
}

/**
 * Reduz um titulo/nome a SÓ a parte que parece nome de pessoa.
 * Corta na primeira virgula e descarta palavras de ruido e numeros.
 * Devolve no maximo 3 tokens (nome + 1 ou 2 sobrenomes).
 */
function crm_telefone_nome_essencial(string $texto): array
{
    $parte = explode(',', (string)$texto)[0];
    $parte = crm_telefone_normalizar($parte);
    if ($parte === '') {
        return [];
    }
    $ruido = crm_telefone_palavras_ruido();
    $tokens = [];
    foreach (explode(' ', $parte) as $palavra) {
        if ($palavra === '' || mb_strlen($palavra) < 2) {
            continue;
        }
        if (in_array($palavra, $ruido, true)) {
            continue;
        }
        $tokens[] = $palavra;
        if (count($tokens) >= 3) {
            break;
        }
    }
    return $tokens;
}

/**
 * Um conjunto de tokens de nome é compatível com um nome de conversa?
 * Exige ao menos 2 tokens batendo como PALAVRA INTEIRA (nome + sobrenome).
 */
function crm_telefone_nome_casa(array $tokensAlvo, string $nomeConversa): bool
{
    $tokensAlvo = array_values(array_filter($tokensAlvo, static fn($t): bool => $t !== ''));
    if (count($tokensAlvo) < 2) {
        return false; // 1 token só casa gente demais ("Ana", "Daniel").
    }
    $normal = crm_telefone_normalizar($nomeConversa);
    if ($normal === '') {
        return false;
    }
    $palavras = array_flip(explode(' ', $normal));
    // O PRIMEIRO e o SEGUNDO token do nome do agendamento precisam bater. Exigir
    // "dois tokens quaisquer" casava pessoas diferentes que so dividem sobrenome:
    // "Guilherme Araujo Silva" casava com "Daniel Araujo da Silva" (araujo+silva)
    // e "Jose Messias da Silva" com "Jose Lailson Teodoro da Silva" (jose+silva).
    if (!isset($palavras[$tokensAlvo[0]]) || !isset($palavras[$tokensAlvo[1]])) {
        return false;
    }
    $bateram = 0;
    foreach ($tokensAlvo as $token) {
        if (isset($palavras[$token])) {
            $bateram++;
        }
    }
    // Exige que TODOS os tokens do lado MAIS CURTO batam. Sem isso, nomes com
    // primeiro nome composto comum casavam por engano: "Pedro Henrique das Neves
    // Felix" (3 tokens) batia com "Pedro Henrique Fernandes Vieira" so por
    // "pedro"+"henrique" - pessoas diferentes.
    $tokensNome = crm_telefone_nome_essencial($nomeConversa);
    $exigidos = min(count($tokensAlvo), count($tokensNome));
    return $bateram >= 2 && $bateram >= $exigidos;
}

/**
 * Indice nome->telefone das conversas do Baileys (arquivo da ponte).
 * Cacheado em memoria durante a requisicao: a pagina faz varias consultas.
 */
function crm_telefone_indice_baileys(): array
{
    static $indice = null;
    if (is_array($indice)) {
        return $indice;
    }
    $indice = [];
    if (!function_exists('historico_conversas_agrupadas')) {
        return $indice;
    }
    try {
        // limite alto: precisa do nome de TODAS as conversas para casar.
        $bloco = historico_conversas_agrupadas([], 5000, 0, 'recentes');
    } catch (Throwable) {
        return $indice;
    }
    foreach (($bloco['conversas'] ?? []) as $c) {
        $fone = preg_replace('/\D+/', '', (string)($c['phone'] ?? ''));
        $nome = trim((string)($c['name'] ?? ''));
        if ($fone === '' || $nome === '') {
            continue;
        }
        if (function_exists('historico_telefone_utilizavel') && !historico_telefone_utilizavel($fone)) {
            continue;
        }
        $indice[] = ['phone' => $fone, 'name' => $nome];
    }
    return $indice;
}

/**
 * Indice nome->telefone do banco do CRM (leads, clientes e conversas do WhatsApp).
 *
 * O arquivo da ponte sozinho nao basta: o telefone do cliente muitas vezes ja esta
 * cadastrado no CRM (lead do anuncio, cliente, conversa). Este indice amplia o
 * casamento por nome sem mudar a regra de seguranca (nome + sobrenome).
 */
function crm_telefone_indice_crm(): array
{
    static $indice = null;
    if (is_array($indice)) {
        return $indice;
    }
    $indice = [];
    try {
        $studio = null;
        if (function_exists('current_studio_user') && function_exists('require_studio') && current_studio_user()) {
            $studio = require_studio();
        } elseif (function_exists('get_studio')) {
            $studio = get_studio(1);
        }
        if (!$studio || !function_exists('studio_db')) {
            return $indice;
        }
        $pdo = studio_db($studio);
        $sql = 'SELECT name, phone FROM leads WHERE COALESCE(phone, \'\') <> \'\'
                 UNION ALL
                SELECT name, phone FROM customers WHERE COALESCE(phone, \'\') <> \'\'
                 UNION ALL
                SELECT name, phone FROM whatsapp_conversations WHERE COALESCE(phone, \'\') <> \'\'';
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fone = preg_replace('/\D+/', '', (string)($row['phone'] ?? ''));
            $nome = trim((string)($row['name'] ?? ''));
            if ($fone === '' || $nome === '') {
                continue;
            }
            if (function_exists('historico_telefone_utilizavel') && !historico_telefone_utilizavel($fone)) {
                continue;
            }
            $indice[] = ['phone' => $fone, 'name' => $nome];
        }
    } catch (Throwable) {
        $indice = [];
    }
    return $indice;
}

/**
 * Procura o telefone de um nome (titulo do agendamento ou nome do lead)
 * nas conversas do Baileys. Devolve '' quando nao achar com seguranca.
 */
function crm_telefone_por_nome(string $texto): string
{
    $tokens = crm_telefone_nome_essencial($texto);
    if (count($tokens) < 2) {
        return '';
    }
    $achados = [];
    // Duas fontes: arquivo da ponte do WhatsApp e cadastro do CRM. Sempre com a
    // mesma regra (nome + sobrenome) e sem aceitar resultado ambiguo.
    foreach ([crm_telefone_indice_baileys(), crm_telefone_indice_crm()] as $fonte) {
        foreach ($fonte as $c) {
            if (crm_telefone_nome_casa($tokens, (string)$c['name'])) {
                $achados[$c['phone']] = true;
            }
        }
    }
    // Ambiguidade (dois telefones diferentes para o mesmo nome): nao adivinha.
    if (count($achados) === 1) {
        return (string)array_key_first($achados);
    }
    return '';
}

/**
 * Ultima mensagem conhecida de um telefone (ponte do WhatsApp + CRM).
 * Serve de trava de TEMPO no casamento por primeiro nome. Cacheado na requisicao.
 */
function crm_telefone_ultima_mensagem(string $fone): string
{
    static $mapa = null;
    if (!is_array($mapa)) {
        $mapa = [];
        if (function_exists('historico_conversas_agrupadas')) {
            try {
                $bloco = historico_conversas_agrupadas([], 5000, 0, 'recentes');
                foreach (($bloco['conversas'] ?? []) as $c) {
                    $f = preg_replace('/\D+/', '', (string)($c['phone'] ?? ''));
                    $quando = (string)($c['ultima_em'] ?? '');
                    if ($f !== '' && $quando !== '' && (!isset($mapa[$f]) || $quando > $mapa[$f])) {
                        $mapa[$f] = $quando;
                    }
                }
            } catch (Throwable) {
                // sem a ponte, segue so com o CRM
            }
        }
        try {
            $studio = null;
            if (function_exists('current_studio_user') && function_exists('require_studio') && current_studio_user()) {
                $studio = require_studio();
            } elseif (function_exists('get_studio')) {
                $studio = get_studio(1);
            }
            if ($studio && function_exists('studio_db')) {
                $sql = 'SELECT phone, last_message_at FROM whatsapp_conversations
                         WHERE COALESCE(last_message_at, \'\') <> \'\'';
                foreach (studio_db($studio)->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $f = preg_replace('/\D+/', '', (string)($row['phone'] ?? ''));
                    $quando = (string)($row['last_message_at'] ?? '');
                    if ($f !== '' && $quando !== '' && (!isset($mapa[$f]) || $quando > $mapa[$f])) {
                        $mapa[$f] = $quando;
                    }
                }
            }
        } catch (Throwable) {
            // sem o CRM, segue so com a ponte
        }
    }
    $variacoes = function_exists('historico_telefone_variacoes')
        ? historico_telefone_variacoes($fone)
        : [$fone];
    foreach ($variacoes as $v) {
        if (isset($mapa[(string)$v])) {
            return (string)$mapa[(string)$v];
        }
    }
    return '';
}

/**
 * Casamento pelo PRIMEIRO nome.
 *
 * Existe porque muita conversa do WhatsApp so tem o primeiro nome ("Edson") e o
 * titulo da agenda tem o nome completo ("Edson Liandro da paz, 600, cotovelo").
 * Isso e' RISCO DE HOMONIMO, entao so devolve telefone com as tres travas juntas:
 *
 *   1. o titulo tem nome + sobrenome e o primeiro nome tem 4+ letras;
 *   2. existe UM UNICO telefone cujo nome de conversa e' exatamente esse primeiro
 *      nome (dois "Bruno" diferentes = ambiguidade = nao adivinha);
 *   3. a conversa teve mensagem na janela [dataRef - 120d, dataRef + 30d]. Sem esse
 *      dateRef nao casa (nao ha como provar que e' a mesma pessoa).
 */
function crm_telefone_por_primeiro_nome(string $texto, string $dataRef = ''): string
{
    if ($dataRef === '') {
        return '';
    }
    $tokens = crm_telefone_nome_essencial($texto);
    if (count($tokens) < 2) {
        return '';
    }
    $primeiro = (string)$tokens[0];
    if (mb_strlen($primeiro) < 4) {
        return '';
    }
    $achados = [];
    foreach ([crm_telefone_indice_baileys(), crm_telefone_indice_crm()] as $fonte) {
        foreach ($fonte as $c) {
            // O nome da conversa tem que ser EXATAMENTE esse primeiro nome (uma
            // palavra so). Nome com inicial ("Carlos A V") indica outra pessoa.
            if (crm_telefone_normalizar((string)$c['name']) === $primeiro) {
                $achados[(string)$c['phone']] = true;
            }
        }
    }
    if (count($achados) !== 1) {
        return '';
    }
    $fone = (string)array_key_first($achados);
    $ultima = crm_telefone_ultima_mensagem($fone);
    if ($ultima === '') {
        return '';
    }
    $dias = (int)floor((strtotime(substr($ultima, 0, 10)) - strtotime($dataRef)) / 86400);
    if ($dias < -120 || $dias > 30) {
        return '';
    }
    return $fone;
}

/**
 * Telefone final de um agendamento, na ordem cadastro -> cadastro -> Baileys.
 * Devolve string de digitos ('' quando nao ha).
 */
function crm_telefone_do_agendamento(array $appointment): string
{
    foreach (['customer_phone', 'lead_phone', 'phone'] as $campo) {
        $fone = normalize_phone((string)($appointment[$campo] ?? ''));
        if ($fone !== '') {
            return $fone;
        }
    }
    foreach ([
        (string)($appointment['customer_name'] ?? ''),
        (string)($appointment['lead_name'] ?? ''),
        (string)($appointment['name'] ?? ''),
        (string)($appointment['title'] ?? ''),
        (string)($appointment['raw_title'] ?? ''),
    ] as $candidato) {
        $achado = crm_telefone_por_nome($candidato);
        if ($achado !== '') {
            return $achado;
        }
    }
    return '';
}

/**
 * Formata telefone brasileiro para leitura.
 *
 * Aceita com ou sem DDI 55. Normaliza para: (DD) XXXXX-XXXX ou (DD) XXXX-XXXX.
 * Regra do DDI: so remove o "55" quando o que sobra tem 10 ou 11 digitos (tamanho
 * de numero BR). Assim "5511975019175" (13) vira "(11) 97501-9175" e um numero
 * local de 11 digitos NAO perde o DDD.
 */
function crm_telefone_formatar(string $fone): string
{
    $d = preg_replace('/\D+/', '', $fone);
    if ($d === '') {
        return '';
    }
    if (str_starts_with($d, '55') && in_array(strlen($d) - 2, [10, 11], true)) {
        $d = substr($d, 2);
    }
    $len = strlen($d);
    if ($len === 11) {
        return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7);
    }
    if ($len === 10) {
        return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6);
    }
    return $d;
}
/** URL do chat unico ja aberto na conversa deste telefone. */
function crm_telefone_url_baileys(string $fone): string
{
    $d = preg_replace('/\D+/', '', $fone);
    if ($d === '') {
        return '';
    }
    return app_url('studio_whatsapp', ['fone' => $d]);
}

/**
 * Bloco HTML pronto do telefone clicavel (link para o chat do Baileys).
 * Devolve '' quando nao ha telefone - quem chama decide o fallback.
 */
function crm_telefone_link_html(string $fone, string $classe = ''): string
{
    $d = preg_replace('/\D+/', '', $fone);
    if ($d === '') {
        return '';
    }
    $url = crm_telefone_url_baileys($d);
    if ($url === '') {
        return '';
    }
    $rotulo = crm_telefone_formatar($d);
    if ($rotulo === '') {
        $rotulo = $d;
    }
    $classeAttr = trim($classe) !== '' ? ' class="' . h($classe) . '"' : '';
    return '<a' . $classeAttr . ' href="' . h($url) . '" target="_blank" rel="noopener" title="Abrir conversa no WhatsApp">'
        . '<i class="fa-brands fa-whatsapp"></i> ' . h($rotulo) . '</a>';
}
