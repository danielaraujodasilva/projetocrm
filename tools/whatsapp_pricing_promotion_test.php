<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$studio = db()->query('SELECT * FROM studios ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$studio) {
    fwrite(STDERR, "Nenhum estudio encontrado.\n");
    exit(1);
}

$settings = studio_settings($studio);
$context = studio_ai_pricing_page_context($studio, $settings, studio_openai_config($studio));
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(str_contains($context, 'Promocoes ativas:'), 'O contexto nao contem as promocoes do orcamento.');

$cases = [
    ['fechamento de costas', 'promo_fechamento_costas'],
    ['fechamento completo de costas', 'promo_fechamento_costas'],
    ['fechamento de braço por fora esquerdo', 'promo_fechamento_braco_externo_esquerdo'],
    ['fechamento de perna por fora esquerda', 'promo_fechamento_perna_frontal_esquerda'],
    ['fechamento de perna por dentro direita', 'promo_fechamento_perna_posterior_direita'],
];

foreach ($cases as [$input, $expectedKey]) {
    $quote = studio_whatsapp_ai_pricing_area_quote($context, $input);
    $expect(is_array($quote), "Nenhum preco promocional encontrado para: {$input}");
    if (is_array($quote)) {
        $expect((string)($quote['key'] ?? '') === $expectedKey, "Promocao incorreta para: {$input}");
        $expect((float)($quote['amount'] ?? 0) > 0, "Valor invalido para: {$input}");
    }
}

// Sem lado, o sistema deve pedir o detalhe faltante em vez de escolher um lado.
$expect(
    studio_whatsapp_ai_pricing_area_quote($context, 'perna inteira por fora') === null,
    'O sistema escolheu uma perna sem o cliente informar o lado.'
);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FALHA: {$failure}\n");
    }
    exit(1);
}

echo "Promocoes de fechamento reconhecidas com dados oficiais.\n";
