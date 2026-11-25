<?php
declare(strict_types=1);
/**
 * Endpoint do chat interno da Irene nos portais dos tatuadores.
 *
 * A sessao do portal define de quem e o historico (theo, fernando). O cliente
 * nunca manda o "dono" da conversa: isso vem da sessao, entao nao tem como um
 * login ler ou gravar no historico do outro.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/portal_irene_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
ini_set('display_errors', '0');
set_time_limit(120);

$GLOBALS['portal_irene_respondeu'] = false;
register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['portal_irene_respondeu'])) {
        return;
    }
    $erro = error_get_last();
    if (!$erro || !in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'A Irene tropecou aqui no servidor. Tenta de novo?'], JSON_UNESCAPED_UNICODE);
});

function portal_irene_json(int $status, array $dados): void
{
    http_response_code($status);
    $GLOBALS['portal_irene_respondeu'] = true;
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    portal_irene_json(405, ['ok' => false, 'erro' => 'Use POST para falar com a Irene.']);
}

/* O dono do historico vem da sessao do portal, nunca do corpo da requisicao. */
$portais = [
    'theo' => ['sessao' => 'theo_portal_acesso', 'nome' => 'Theo'],
    'fernando' => ['sessao' => 'fernando_portal_acesso', 'nome' => 'Fernando'],
];
$portal = null;
foreach ($portais as $slug => $info) {
    if (is_array($_SESSION[$info['sessao']] ?? null)) {
        $portal = ['slug' => $slug, 'nome' => $info['nome']];
        break;
    }
}
if ($portal === null) {
    portal_irene_json(401, ['ok' => false, 'erro' => 'Sessão expirada. Entra de novo no portal.']);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = [];
}
$token = (string)($in['csrf'] ?? ($_SERVER['HTTP_X_PORTAL_CSRF'] ?? ''));
$esperado = (string)($_SESSION['portal_irene_csrf'] ?? '');
if ($esperado === '' || $token === '' || !hash_equals($esperado, $token)) {
    portal_irene_json(403, ['ok' => false, 'erro' => 'Recarrega a página e tenta de novo.']);
}

$texto = trim((string)($in['mensagem'] ?? ''));
if ($texto === '') {
    portal_irene_json(400, ['ok' => false, 'erro' => 'Escreve uma mensagem primeiro.']);
}
if (mb_strlen($texto) > 2000) {
    portal_irene_json(400, ['ok' => false, 'erro' => 'Mensagem muito longa (máximo de 2000 caracteres).']);
}

/* Banco do estudio ativo (o mesmo que a agenda do portal usa). */
$studio = null;
try {
    foreach (db()->query('SELECT * FROM studios ORDER BY id ASC') as $row) {
        if (($row['status'] ?? '') === 'active') {
            $studio = $row;
            break;
        }
    }
} catch (Throwable) {
    $studio = null;
}
if (!is_array($studio)) {
    portal_irene_json(503, ['ok' => false, 'erro' => 'Não achei o estúdio agora.']);
}

$pdo = null;
try {
    $pdo = studio_db($studio);
} catch (Throwable $e) {
    portal_irene_log('conexao: ' . $e->getMessage());
    $pdo = null;
}
if (!$pdo instanceof PDO) {
    portal_irene_json(503, ['ok' => false, 'erro' => 'O banco do estúdio não respondeu.']);
}

try {
    portal_irene_garantir_tabela($pdo);
    if (portal_irene_contar_ultima_hora($pdo, $portal['slug']) >= PORTAL_IRENE_MAX_HORA) {
        portal_irene_json(429, ['ok' => false, 'erro' => 'Calma, muitas mensagens em uma hora. Tenta daqui a pouco.']);
    }
    $historico = portal_irene_historico($pdo, $portal['slug'], 6);
} catch (Throwable $e) {
    portal_irene_log('historico: ' . $e->getMessage());
    portal_irene_json(500, ['ok' => false, 'erro' => 'Não consegui abrir o histórico agora.']);
}

/* O turno atual entra no contexto, mas so e gravado se a Irene responder. */
$historico[] = ['papel' => 'user', 'texto' => $texto];
$contexto = portal_irene_contexto($pdo, $portal['nome'], $texto);
$turno = portal_irene_turno($portal['nome'], $contexto, $texto);
$historicoModelo = $historico;
$historicoModelo[count($historicoModelo) - 1]['texto'] = $turno;
$sistema = portal_irene_system($portal['nome']);
$resposta = portal_irene_responder($sistema, $historicoModelo);

if (empty($resposta['ok'])) {
    portal_irene_log('modelo: ' . (string)($resposta['erro'] ?? 'falhou') . ' portal=' . $portal['slug']);
    portal_irene_json(502, ['ok' => false, 'erro' => 'A Irene não conseguiu responder agora. Tenta de novo em instantes.']);
}

$idIrene = 0;
$aviso = '';
try {
    $pdo->beginTransaction();
    portal_irene_gravar($pdo, $portal['slug'], 'user', $texto, null);
    $idIrene = portal_irene_gravar(
        $pdo,
        $portal['slug'],
        'irene',
        (string)$resposta['texto'],
        (string)($resposta['modelo'] ?? '')
    );
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    portal_irene_log('gravar: ' . $e->getMessage());
    $aviso = 'A resposta veio, mas o histórico não salvou agora.';
}

portal_irene_json(200, [
    'ok' => true,
    'resposta' => (string)$resposta['texto'],
    'id' => $idIrene,
    'aviso' => $aviso,
]);
