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
    $bateram = 0;
    foreach ($tokensAlvo as $token) {
        if (isset($palavras[$token])) {
            $bateram++;
        }
    }
    return $bateram >= 2;
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
    foreach (crm_telefone_indice_baileys() as $c) {
        if (crm_telefone_nome_casa($tokens, (string)$c['name'])) {
            $achados[$c['phone']] = true;
        }
    }
    // Ambiguidade (dois telefones diferentes para o mesmo nome): nao adivinha.
    if (count($achados) === 1) {
        return (string)array_key_first($achados);
    }
    return '';
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
/** URL do chat do Baileys ja aberto na conversa deste telefone. */
function crm_telefone_url_baileys(string $fone): string
{
    $d = preg_replace('/\D+/', '', $fone);
    if ($d === '') {
        return '';
    }
    return app_url('studio_whatsapp_baileys', ['fone' => $d]);
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
