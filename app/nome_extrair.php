<?php
/**
 * EXTRATOR DE NOME COMPLETO nas conversas do Baileys.
 *
 * PADRAO REAL (confirmado nos dados, nao suposto):
 *   A) O estudio pede o nome COMPLETO ("Nome completo por favor", "me manda o
 *      nome completo") e o cliente responde com o nome completo.
 *      Obs.: NAO usar "Qual seu nome?" - ali o cliente passa so o primeiro nome.
 *   B) O proprio ESTUDIO escreve o nome completo (confirma antes de agendar),
 *      normalmente na reta final da conversa.
 *
 * Nome valido = 2 a 5 palavras, cada uma com 3+ letras, so letras (com acento)
 * e espaco, sem digito/link/emoji/pontuacao, e nenhuma palavra pode ser palavra
 * comum de conversa (fechou, sim, obrigado, valor, pix, costas...).
 *
 * Uma palavra so NAO basta: e primeiro nome ou palavra generica. O dono quer o
 * nome COMPLETO, que e o que identifica a pessoa sem ambiguidade.
 */

/** Texto sem acento e minusculo. */
function ex_sem_acento(string $v): string
{
    return strtr(mb_strtolower(trim($v), 'UTF-8'), [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
    ]);
}

/** Palavras que NUNCA sao nome de pessoa. */
function ex_palavras_comuns(): array
{
    return [
        // saudacoes/cortesia
        'ola','oi','oie','bom','boa','dia','tarde','noite','obrigado','obrigada','valeu',
        'vlw','gratidao','tudo','bem','prazer','favor','atencao','desculpa','desculpe',
        // confirmacoes
        'sim','nao','ok','blz','beleza','fechou','fechado','combinado','certo','isso',
        'perfeito','show','legal','otimo','maravilha','top','tranquilo','calma','entendi',
        'entendido','tendeu','tendi','claro','aham','uhum','perfeita','boas','nessa','simm',
        // conversa generica
        'deixa','espera','acho','vou','vamos','quero','queria','gostaria','posso','pode',
        'esta','te','me','mim','sou','eu','voce','vcs','vc','meu','minha','seu','sua','que',
        'qual','quando','onde','como','porque','para','com','sobre','mais','menos','muito',
        'pouco','agora','depois','antes','hoje','amanha','ontem','semana','mes','ano','dia',
        'horas','hora','tem','tenho','tinha','vai','foi','era','sera','seria','fica','fico',
        'ficou','tava','estava','estou','ja','ainda','tambem','ate','ta','esta',
        // dinheiro/negocio
        'valor','preco','pago','pix','sinal','entrada','parcela','parcelado','cartao',
        'dinheiro','reais','desconto','promocao','orcamento','comprovante','transferencia',
        'deposito','pagamento','credito','debito','vezes','simulacao',
        // tatuagem/estudio
        'tattoo','tatuagem','tatuar','desenho','arte','traco','fechamento','costas','braco',
        'perna','peito','peitoral','coxa','antebraco','panturrilha','pomada','sessao',
        'retorno','retoque','cobertura','estudio','tatuador','agenda','agendar','agendamento',
        'horario','reserva','marca','marcar','marcado','confirmar','confirmado','chegar',
        'chegando','perto','longe','instagram','whatsapp','facebook','link','endereco',
        'rua','avenida','jardim','zona','leste','oeste','norte','sul','capital','sao','paulo',
        // acoes
        'manda','mandar','mandei','pega','pegar','faz','fazer','feito','vi','ver','olha',
        'olhar','consegui','consegue','conseguiu','tirar','tira','comeca','comecar','vou',
        // afins
        'kkk','kkkk','haha','risos','amiga','amigo','amor','gente','pessoal','moca','rapaz',
        'senhor','senhora','dona','qualquer','alguma','algum','nada','cada','esse','essa',
        'aquele','aquela','nome','apelido','sobrenome','chamo','chama','chamado','apresenta',
        'luna','theo','roqueira','vizinho','vizinha','faladora','gatona','manno','mano',
        'mozão','mozao','cafe','pizza','mussarela','contador','cliente','opcoes','detalhes',
        'loba','acordou','acorda','gostoso','geral','livre','antiga','negociacao','fiacao',
        'roubaram','relaxa','bolinha','possivel','perfeitamente','independente','semelhante',
        'apenas','duvidas','cedo','primeiro','segundo','terceiro','varias','alguns',
    ];
}

/**
 * A mensagem e um NOME COMPLETO? (2-5 palavras que parecem nome de pessoa)
 * Uma palavra so retorna false de proposito: e primeiro nome ou palavra solta.
 */
function ex_parece_nome_completo(string $texto): bool
{
    $t = trim($texto);
    if ($t === '' || mb_strlen($t) > 55) {
        return false;
    }
    // Rejeita digito, link, arroba, cifrao, emoji, pontuacao forte.
    if (preg_match('/\d|https?|www\.|@|R\$|[?!…,;:|()\[\]{}"\'\/\\\+*_=<>~`]/iu', $t)) {
        return false;
    }
    // Somente letras (com acento) e espaco simples.
    if (preg_match('/^[\p{L}][\p{L} ]*$/u', $t) !== 1) {
        return false;
    }
    $palavras = preg_split('/\s+/u', $t);
    $n = count($palavras);
    if ($n < 2 || $n > 5) {
        return false;
    }
    $comuns = ex_palavras_comuns();
    $informativas = 0;
    foreach ($palavras as $p) {
        if (mb_strlen($p) < 3) {
            return false;
        }
        if (in_array(ex_sem_acento($p), $comuns, true)) {
            return false;
        }
        $informativas++;
    }
    return $informativas >= 2;
}

/** O estudio pediu o nome COMPLETO nesta mensagem? */
function ex_pediu_nome_completo(string $texto): bool
{
    $t = ex_sem_acento($texto);
    if ($t === '') {
        return false;
    }
    // "Qual seu nome" NAO entra: ali vem so o primeiro nome.
    $gatilhos = [
        'nome completo', 'nome e sobrenome', 'nome e o sobrenome',
        'manda o nome', 'me manda o nome', 'passa o nome', 'me passa o nome',
        'preciso do nome', 'vou precisar do nome', 'qual o nome completo',
    ];
    foreach ($gatilhos as $g) {
        if (str_contains($t, $g)) {
            return true;
        }
    }
    return false;
}

/**
 * Extrai o nome completo de uma conversa (mensagens em ordem cronologica).
 *
 * Devolve ['nome'=>string,'confianca'=>'alta|media','origem'=>string,'pos'=>int].
 *   alta  = resposta direta a pedido de nome completo do estudio.
 *   media = nome escrito pelo estudio, ou apareceu no fim da conversa.
 */
function ex_extrair_nome_completo(array $msgs): array
{
    $vazio = ['nome' => '', 'confianca' => '', 'origem' => '', 'pos' => 0];
    $total = count($msgs);
    if ($total === 0) {
        return $vazio;
    }

    // --- A) Estudio pede o nome completo; cliente responde --- (confianca alta)
    foreach ($msgs as $i => $m) {
        if (empty($m['from_me'])) {
            continue;
        }
        if (!ex_pediu_nome_completo((string)($m['text'] ?? ''))) {
            continue;
        }
        // Procura a proxima resposta do cliente (ate 4 mensagens a frente).
        for ($j = $i + 1; $j < min($i + 5, $total); $j++) {
            if (!empty($msgs[$j]['from_me'])) {
                continue;
            }
            $resp = trim((string)($msgs[$j]['text'] ?? ''));
            if ($resp === '') {
                continue;
            }
            if (ex_parece_nome_completo($resp)) {
                return ['nome' => $resp, 'confianca' => 'alta', 'origem' => 'pedido-nome-completo', 'pos' => $j + 1];
            }
        }
    }

    // --- B) O ESTUDIO escreve o nome completo --- (confianca media)
    // Preferir o mais proximo do FIM da conversa (confirmacao antes de agendar).
    for ($i = $total - 1; $i >= 0; $i--) {
        if (empty($msgs[$i]['from_me'])) {
            continue;
        }
        $t = trim((string)($msgs[$i]['text'] ?? ''));
        if (ex_parece_nome_completo($t)) {
            return ['nome' => $t, 'confianca' => 'media', 'origem' => 'estudio-escreveu', 'pos' => $i + 1];
        }
    }

    // --- C) Cliente manda o nome completo na reta final --- (confianca media)
    $limite = (int)floor($total * 0.6);
    for ($i = $total - 1; $i >= $limite; $i--) {
        if (!empty($msgs[$i]['from_me'])) {
            continue;
        }
        $t = trim((string)($msgs[$i]['text'] ?? ''));
        if (ex_parece_nome_completo($t)) {
            return ['nome' => $t, 'confianca' => 'media', 'origem' => 'cliente-final', 'pos' => $i + 1];
        }
    }

    return $vazio;
}
