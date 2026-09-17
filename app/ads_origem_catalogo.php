<?php
/**
 * Origens de cliente: catalogo unico.
 *
 * POR QUE EXISTE
 * Antes, `leads.source` era texto livre: cada tela escrevia o que quisesse
 * ("meta", "Meta Ads", "facebook", "insta"...). Isso quebra qualquer contagem
 * por canal. Aqui fica a lista canonica, usada por:
 *   - seletor de origem na edicao do lead
 *   - leitura/gravacao da ponte de anuncio (api/origin_bridge.php)
 *   - contagem por origem no painel de ROI
 *
 * REGRA: gravar SEMPRE o codigo (a chave), nunca o rotulo. O rotulo e so exibicao.
 */
declare(strict_types=1);

/**
 * Catalogo: codigo => [rotulo, grupo, cor].
 * O grupo separa o que e pago (anuncio) do que e organico.
 */
function ads_origem_catalogo(): array
{
    return [
        'meta'        => ['Meta (Facebook)', 'anuncio', '#3538cd'],
        'instagram'   => ['Instagram', 'anuncio', '#c11574'],
        'google'      => ['Google Ads', 'anuncio', '#027a48'],
        'tiktok'      => ['TikTok Ads', 'anuncio', '#101828'],
        'indicacao'   => ['Indicação', 'organico', '#b54708'],
        'porta'       => ['Passou na porta', 'organico', '#6941c6'],
        'reincidente' => ['Cliente que voltou', 'organico', '#0e7090'],
        'organico_ig' => ['Instagram orgânico', 'organico', '#c11574'],
        'whatsapp'    => ['WhatsApp (direto)', 'organico', '#079455'],
        'agenda'      => ['Google Agenda', 'sistema', '#667085'],
        'manual'      => ['Cadastro manual', 'sistema', '#667085'],
        'outro'       => ['Outro', 'outro', '#98a2b3'],
    ];
}

/** Rotulo legivel de um codigo de origem (aceita valores antigos em texto livre). */
function ads_origem_rotulo(string $codigo): string
{
    $codigo = strtolower(trim($codigo));
    if ($codigo === '') {
        return 'Sem origem';
    }
    $cat = ads_origem_catalogo();
    if (isset($cat[$codigo])) {
        return (string)$cat[$codigo][0];
    }

    // Compatibilidade com valores antigos em texto livre.
    $aliases = [
        'meta ads' => 'meta', 'facebook' => 'meta', 'fb' => 'meta', 'messenger' => 'meta',
        'insta' => 'instagram', 'ig' => 'instagram',
        'google ads' => 'google', 'google agenda' => 'agenda',
        'indicacao' => 'indicacao', 'indicação' => 'indicacao',
        'whatsapp' => 'whatsapp', 'manual' => 'manual',
    ];
    if (isset($aliases[$codigo])) {
        return (string)$cat[$aliases[$codigo]][0];
    }

    return ucfirst($codigo);
}

/** Normaliza um valor livre para o codigo canonico do catalogo. */
function ads_origem_normalizar(string $valor): string
{
    $v = strtolower(trim($valor));
    if ($v === '') {
        return '';
    }
    $cat = ads_origem_catalogo();
    if (isset($cat[$v])) {
        return $v;
    }
    $aliases = [
        'meta ads' => 'meta', 'facebook' => 'meta', 'fb' => 'meta', 'messenger' => 'meta',
        'meta_ads' => 'meta', 'ctwa_facebook' => 'meta',
        'insta' => 'instagram', 'ig' => 'instagram', 'ctwa_instagram' => 'instagram',
        'google ads' => 'google', 'googleads' => 'google', 'adwords' => 'google',
        'google agenda' => 'agenda', 'google_calendar' => 'agenda',
        'indicacao' => 'indicacao', 'indicação' => 'indicacao', 'indicado' => 'indicacao',
        'passou na porta' => 'porta', 'porta' => 'porta',
        'cliente que voltou' => 'reincidente', 'reincidente' => 'reincidente',
        'whatsapp' => 'whatsapp', 'manual' => 'manual',
    ];
    if (isset($aliases[$v])) {
        return $aliases[$v];
    }
    return 'outro';
}

/** A origem conta como anuncio pago? (usado no ROI) */
function ads_origem_e_anuncio(string $codigo): bool
{
    $cat = ads_origem_catalogo();
    $codigo = ads_origem_normalizar($codigo);
    return isset($cat[$codigo]) && $cat[$codigo][1] === 'anuncio';
}

/** Cor da etiqueta da origem. */
function ads_origem_cor(string $codigo): string
{
    $cat = ads_origem_catalogo();
    $codigo = ads_origem_normalizar($codigo);
    return $cat[$codigo][2] ?? '#98a2b3';
}

/**
 * Opcoes para <select>, agrupadas. Retorna [grupo => [codigo => rotulo]].
 */
function ads_origem_opcoes_agrupadas(): array
{
    $grupos = [
        'anuncio' => 'Anúncio (pago)',
        'organico' => 'Orgânico / espontâneo',
        'sistema' => 'Sistema / importação',
        'outro' => 'Outro',
    ];
    $out = [];
    foreach (ads_origem_catalogo() as $codigo => $info) {
        $grupo = (string)$info[1];
        if (!isset($out[$grupo])) {
            $out[$grupo] = ['label' => $grupos[$grupo] ?? ucfirst($grupo), 'itens' => []];
        }
        $out[$grupo]['itens'][$codigo] = (string)$info[0];
    }
    return $out;
}

/**
 * Renderiza um <select> de origem ja selecionado.
 * $comVazio inclui a opcao "sem origem".
 */
function ads_origem_render_select(string $name, string $selecionado, bool $comVazio = true, string $id = ''): string
{
    $selecionado = ads_origem_normalizar($selecionado);
    $html = '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"'
        . ($id !== '' ? ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
    if ($comVazio) {
        $html .= '<option value="">— sem origem —</option>';
    }
    foreach (ads_origem_opcoes_agrupadas() as $g) {
        $html .= '<optgroup label="' . htmlspecialchars((string)$g['label'], ENT_QUOTES, 'UTF-8') . '">';
        foreach ($g['itens'] as $codigo => $rotulo) {
            $sel = ((string)$codigo === $selecionado) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars((string)$codigo, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                . htmlspecialchars((string)$rotulo, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</optgroup>';
    }
    $html .= '</select>';
    return $html;
}

/** Etiqueta colorida da origem (para listas). */
function ads_origem_badge(string $codigo): string
{
    $codigo = strtolower(trim($codigo));
    if ($codigo === '') {
        return '<span class="badge" style="background:#f2f4f7;color:#98a2b3">sem origem</span>';
    }
    $rotulo = ads_origem_rotulo($codigo);
    $cor = ads_origem_cor($codigo);
    return '<span class="badge" style="background:' . htmlspecialchars($cor, ENT_QUOTES, 'UTF-8') . ';color:#fff">'
        . htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8') . '</span>';
}
