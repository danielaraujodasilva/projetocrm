<?php
/**
 * Origens de cliente — APENAS os codigos (para agrupar/contar).
 *
 * Esta separado do catalogo de rotulos de proposito: quem so precisa CONTAR
 * nao carrega os rotulos (que sao do painel). Mantem o comportamento antigo
 * sem depender do arquivo de rotulos.
 */
declare(strict_types=1);

/**
 * Codigos de origem que CONTAM como anuncio pago (grupo "anuncio" do catalogo).
 * Usado por: contagem de "Leads de anuncio" e pelo ROI.
 */
function ads_origem_codigos_anuncio(): array
{
    return ['meta', 'instagram', 'google', 'tiktok'];
}
