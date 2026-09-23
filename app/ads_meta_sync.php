<?php
/**
 * Sync automático do painel Retorno dos Anúncios (ads_roi) com a Meta Ads API.
 *
 * Puxa o GASTO REAL diário da conta de anúncios e grava em ads_daily (canal 'meta'),
 * preenchendo o painel sem depender de lançamento manual.
 *
 * Regras:
 * - Só escreve dias com gasto > 0 (sem ruído de dias zerados).
 * - Faz UPSERT por (campaign_date, channel) com campaign_name nulo — o schema tem
 *   chave única (campaign_date, channel, campaign_name); como aqui nunca passamos
 *   campaign_name, o par (data, 'meta', NULL) é estável e não duplica.
 * - Nunca sobrescreve leads_direct/notes lançados manualmente (só atualiza spend).
 * - Respeita a janela configurada (default 30 dias) para não explodir o volume.
 */
declare(strict_types=1);

require_once __DIR__ . '/ads_daily.php';

/**
 * Busca a série diária de gasto da conta Meta e grava em ads_daily.
 *
 * @return array{ok:bool, error?:string, dias_sincronizados:int, gasto_total:float, dias_com_gasto:int}
 */
function ads_meta_sync_daily_spend(array $studio, int $days = 30): array
{
    $settings = studio_settings($studio);
    $token = trim((string)($settings['meta_ads_access_token'] ?? ''));
    $accountId = preg_replace('/^act_/', '', trim((string)($settings['meta_ads_ad_account_id'] ?? '')));
    $version = trim((string)($settings['meta_ads_api_version'] ?? 'v22.0')) ?: 'v22.0';

    if ($token === '' || $accountId === '') {
        return ['ok' => false, 'error' => 'Meta Ads não configurada (token ou ad_account ausente).'];
    }

    $days = max(1, min(120, $days));
    $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $until = date('Y-m-d');

    // time_increment=1 => série diária até hoje (limite 37 dias na API; aqui limitamos a 120,
    // mas a Meta corta em ~37 -> se o usuário escolher 60/90, buscamos em janelas de 30 e concatenamos).
    $rows = [];
    $cursorSince = $since;
    while (strtotime($cursorSince) <= strtotime($until)) {
        $chunkEnd = date('Y-m-d', min(strtotime($until), strtotime($cursorSince . ' +29 days')));
        $response = studio_meta_ads_request($version, '/act_' . $accountId . '/insights', $token, [
            'fields' => 'date_start,spend',
            'time_increment' => 1,
            'since' => $cursorSince,
            'until' => $chunkEnd,
            'level' => 'account',
            'limit' => 500,
        ]);
        if (!$response['ok']) {
            return [
                'ok' => false,
                'error' => (string)($response['error'] ?? 'Falha ao consultar insights diários da Meta.'),
                'status' => $response['status'] ?? null,
            ];
        }
        $items = is_array($response['json']['data'] ?? null) ? $response['json']['data'] : [];
        foreach ($items as $item) {
            $d = (string)($item['date_start'] ?? '');
            if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $rows[$d] = (float)($item['spend'] ?? 0);
            }
        }
        // A API encerra a série no último dia com dado; evita loop infinito.
        if ($chunkEnd === $until || empty($items)) {
            break;
        }
        $cursorSince = date('Y-m-d', strtotime($chunkEnd . ' +1 day'));
        if ($cursorSince > $until) {
            break;
        }
    }

    // Persistir em ads_daily (canal 'meta'), só dias com gasto > 0.
    $pdo = studio_db($studio);
    studio_ads_daily_ensure_schema($pdo);

    $insertStmt = $pdo->prepare(
        'INSERT INTO ads_daily (campaign_date, channel, campaign_name, spend, leads_direct, notes)
         VALUES (?, "meta", "[SYNC META]", ?, 0, NULL)
         ON DUPLICATE KEY UPDATE spend = VALUES(spend)'
    );

    $diasComGasto = 0;
    $gastoTotal = 0.0;
    $gastos = array_filter($rows, static fn(float $v): bool => $v > 0);
    ksort($gastos);
    foreach ($gastos as $d => $spend) {
        $insertStmt->execute([$d, $spend]);
        $diasComGasto++;
        $gastoTotal += $spend;
    }

    return [
        'ok' => true,
        'dias_sincronizados' => count($gastos),
        'gasto_total' => round($gastoTotal, 2),
        'dias_com_gasto' => $diasComGasto,
    ];
}