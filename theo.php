<?php
declare(strict_types=1);
/**
 * Portal do Theo (/theo).
 *
 * Página privada, com login próprio, que lista os agendamentos do Theo.
 * Consulta o banco do estúdio a cada carregamento (sem cache), então qualquer
 * mudança feita na agenda aparece na hora em que a página é aberta.
 *
 * O Theo é identificado pelo nome no título/descrição do agendamento (é assim
 * que a agenda do estúdio registra hoje) e também por tattoo_artists, caso um
 * dia exista um profissional cadastrado com esse nome.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/portal_irene_lib.php';

const THEO_PORTAL_EMAIL = 'theo@danieltatuador.com';
const THEO_PORTAL_HASH = '$2y$10$Vetg3TO5f4bRRWZsY8/vjOInITRg5zTX9zVxVLoI5Eh20osB0buf.';
const THEO_PORTAL_KEY = 'theo_portal_acesso';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

function theo_h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function theo_url_atual(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/theo');
    return $uri !== '' ? $uri : '/theo';
}

$acao = (string)($_POST['acao'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($acao === 'sair') {
        unset($_SESSION[THEO_PORTAL_KEY]);
        header('Location: ' . theo_url_atual(), true, 303);
        exit;
    }
    if ($acao === 'entrar') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $senha = (string)($_POST['senha'] ?? '');
        if ($email === THEO_PORTAL_EMAIL && $senha !== '' && password_verify($senha, THEO_PORTAL_HASH)) {
            session_regenerate_id(true);
            $_SESSION[THEO_PORTAL_KEY] = ['email' => $email, 'em' => time()];
            header('Location: ' . theo_url_atual(), true, 303);
            exit;
        }
        usleep(400000);
        $erro = 'E-mail ou senha incorretos.';
    }
}

$logado = is_array($_SESSION[THEO_PORTAL_KEY] ?? null);

if (!$logado) {
    ?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Entrar · Agenda do Theo</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:#0f172a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#0f172a}
  .box{width:100%;max-width:380px;background:#fff;border-radius:20px;padding:28px 24px;box-shadow:0 24px 60px rgba(0,0,0,.35)}
  .tag{display:inline-block;font-size:11px;letter-spacing:.18em;color:#1f6f78;font-weight:700}
  h1{margin:8px 0 4px;font-size:24px}
  p.sub{margin:0 0 20px;color:#64748b;font-size:14px}
  label{display:block;font-size:12px;font-weight:700;color:#334155;margin:14px 0 6px}
  input{width:100%;padding:12px;border:1px solid #d0d5dd;border-radius:10px;font-size:15px;background:#fff;color:#0f172a}
  input:focus{outline:2px solid #1f6f78;outline-offset:1px}
  button{width:100%;margin-top:20px;padding:13px;border:0;border-radius:10px;background:#1f6f78;color:#fff;font-size:15px;font-weight:700;cursor:pointer}
  button:active{transform:translateY(1px)}
  .erro{margin-top:14px;padding:10px 12px;border-radius:10px;background:#fef3f2;color:#b42318;font-size:13px}
</style>
</head>
<body>
  <form class="box" method="post" autocomplete="on">
    <span class="tag">AGENDA</span>
    <h1>Theo</h1>
    <p class="sub">Entre para ver seus agendamentos.</p>
    <label for="email">E-mail</label>
    <input id="email" name="email" type="email" inputmode="email" autocomplete="username" required value="<?= theo_h((string)($_POST['email'] ?? '')) ?>">
    <label for="senha">Senha</label>
    <input id="senha" name="senha" type="password" autocomplete="current-password" required>
    <?php if (!empty($erro)) { echo '<div class="erro">' . theo_h((string)$erro) . '</div>'; } ?>
    <button type="submit" name="acao" value="entrar">Entrar</button>
  </form>
</body>
</html>
    <?php
    exit;
}

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

$agendamentos = [];
$artistId = 0;
$pdo = null;
if (is_array($studio)) {
    try {
        $pdo = studio_db($studio);
        $stmtArtista = $pdo->prepare('SELECT id FROM tattoo_artists WHERE name LIKE ? LIMIT 1');
        $stmtArtista->execute(['%theo%']);
        $artistId = (int)($stmtArtista->fetchColumn() ?: 0);
    } catch (Throwable) {
        $pdo = null;
    }
}

$statusRotulo = [
    'pre_agendado' => 'Pré-agendado',
    'agendado' => 'Agendado',
    'confirmado' => 'Confirmado',
    'finalizado' => 'Finalizado',
    'falta' => 'Faltou',
    'cancelado' => 'Cancelado',
];

$buscar = static function (PDO $pdo, string $de, string $ate, int $artistId): array {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.title, a.description, a.status, a.value,
                COALESCE(NULLIF(c.name, ''), NULLIF(l.name, '')) AS cliente,
                COALESCE(NULLIF(c.phone, ''), NULLIF(l.phone, '')) AS telefone
           FROM appointments a
           LEFT JOIN customers c ON c.id = a.customer_id
           LEFT JOIN leads l ON l.id = a.lead_id
          WHERE a.appointment_date BETWEEN ? AND ?
            AND (a.title LIKE ? OR a.raw_title LIKE ? OR a.description LIKE ? OR a.artist_id = ?)
          ORDER BY a.appointment_date ASC, a.start_time ASC, a.id ASC"
    );
    $stmt->execute([$de, $ate, '%theo%', '%theo%', '%theo%', $artistId]);
    return $stmt->fetchAll() ?: [];
};

$proximos = [];
$anteriores = [];
if ($pdo instanceof PDO) {
    try {
        $proximos = $buscar($pdo, date('Y-m-d'), date('Y-m-d', strtotime('+120 days')), $artistId);
    } catch (Throwable) {
        $proximos = [];
    }
    try {
        $anteriores = $buscar($pdo, date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('-1 day')), $artistId);
    } catch (Throwable) {
        $anteriores = [];
    }
}

$agrupar = static function (array $lista): array {
    $out = [];
    foreach ($lista as $item) {
        $out[(string)$item['appointment_date']][] = $item;
    }
    return $out;
};
$diasSemana = ['Sun' => 'dom', 'Mon' => 'seg', 'Tue' => 'ter', 'Wed' => 'qua', 'Thu' => 'qui', 'Fri' => 'sex', 'Sat' => 'sab'];
$rotuloDia = static function (string $data) use ($diasSemana): string {
    $ts = strtotime($data . ' 12:00:00');
    $abrev = $diasSemana[date('D', (int)$ts)] ?? '';
    $prefixo = $data === date('Y-m-d') ? 'HOJE · ' : ($data === date('Y-m-d', strtotime('+1 day')) ? 'AMANHÃ · ' : '');
    return $prefixo . $abrev . ' ' . date('d/m', (int)$ts);
};
$telefoneWa = static function (string $telefone): string {
    $digitos = preg_replace('/\D+/', '', $telefone) ?? '';
    if ($digitos === '') {
        return '';
    }
    if (strlen($digitos) <= 11) {
        $digitos = '55' . $digitos;
    }
    return 'https://wa.me/' . $digitos;
};

$renderDia = static function (string $titulo, array $lista, bool $passado = false) use ($statusRotulo, $rotuloDia, $telefoneWa): void {
    echo '<section class="dia' . ($passado ? ' passado' : '') . '">';
    echo '<div class="dia-head"><strong>' . theo_h(mb_strtoupper($titulo)) . '</strong><span>' . count($lista) . ' atendimento' . (count($lista) > 1 ? 's' : '') . '</span></div>';
    foreach ($lista as $item) {
        $status = (string)($item['status'] ?? '');
        $rotulo = $statusRotulo[$status] ?? ucfirst($status);
        $classe = $status === 'finalizado' ? 'done' : (in_array($status, ['cancelado', 'falta'], true) ? 'off' : 'ok');
        $cliente = trim((string)($item['cliente'] ?? ''));
        if ($cliente !== '') {
            $partesNome = [];
            foreach (explode(',', $cliente) as $parteNome) {
                $parteNome = trim($parteNome);
                if ($parteNome === '' || preg_match('/^th[eé]o$/iu', $parteNome) === 1) {
                    continue;
                }
                $partesNome[] = $parteNome;
            }
            if ($partesNome) {
                $cliente = implode(', ', $partesNome);
            }
        }
        $tituloCard = trim((string)($item['title'] ?? ''));
        $wa = $telefoneWa((string)($item['telefone'] ?? ''));
        $textoCard = (string)($item['title'] ?? '') . ' ' . (string)($item['raw_title'] ?? '') . ' ' . (string)($item['description'] ?? '');
        $ehCancelado = in_array($status, ['cancelado', 'falta'], true)
            || preg_match('/cancelad|faltou|n(ã|a)o vai|desmarcad/iu', $textoCard) === 1;
        $classeCard = 'card' . ($passado ? ' passado' : '') . ($ehCancelado ? ' cancelado' : '');
        echo '<article class="' . $classeCard . '">';
        echo '<div class="hora">' . theo_h(substr((string)($item['start_time'] ?? ''), 0, 5));
        if (!empty($item['end_time'])) {
            echo '<small>' . theo_h(substr((string)$item['end_time'], 0, 5)) . '</small>';
        }
        echo '</div>';
        echo '<div class="info"><strong>' . theo_h($cliente !== '' ? $cliente : $tituloCard) . '</strong>';
        $subTitulo = $tituloCard;
        if ($cliente !== '') {
            $subTitulo = (string)preg_replace('/' . preg_quote($cliente, '/') . '/iu', ' ', $subTitulo);
        }
        $subTitulo = (string)preg_replace('/\bth[eé]o\b/iu', ' ', $subTitulo);
        if ($cliente !== '') {
            foreach (array_filter(array_map('trim', explode(',', $cliente))) as $parteCliente) {
                if (mb_strlen($parteCliente) > 2) {
                    $subTitulo = (string)preg_replace('/' . preg_quote($parteCliente, '/') . '/iu', ' ', $subTitulo);
                }
            }
        }
        $subTitulo = trim((string)preg_replace('/[\s,;\-]+/u', ' ', $subTitulo));
        if (mb_strlen($subTitulo) > 3 && $subTitulo !== $cliente) {
            echo '<span class="sub">' . theo_h($subTitulo) . '</span>';
        }
        echo '</div>';
        echo '<div class="lado"><span class="tag ' . $classe . '">' . theo_h($rotulo) . '</span>';
        if ((float)($item['value'] ?? 0) > 0) {
            echo '<span class="valor">R$ ' . number_format((float)$item['value'], 0, ',', '.') . '</span>';
        }
        if ($wa !== '') {
            echo '<a class="wa" href="' . theo_h($wa) . '" target="_blank" rel="noopener">WhatsApp</a>';
        }
        echo '</div></article>';
    }
    echo '</section>';
};

$grupoProximos = $agrupar($proximos);
$grupoAnteriores = $agrupar($anteriores);
krsort($grupoAnteriores);
$totalHoje = 0;
foreach (($grupoProximos[date('Y-m-d')] ?? []) as $itemHoje) {
    $totalHoje++;
}
$totalProximos = count($proximos);

/* Chat com a Irene: historico preso a chave 'theo', sem misturar com outros logins. */
$ireneCsrf = '';
$ireneHistorico = [];
$ireneDisponivel = $pdo instanceof PDO;
$ireneAviso = '';
if ($ireneDisponivel) {
    try {
        portal_irene_garantir_tabela($pdo);
        $ireneHistorico = portal_irene_historico($pdo, 'theo', 60);
        if (empty($_SESSION['portal_irene_csrf'])) {
            $_SESSION['portal_irene_csrf'] = bin2hex(random_bytes(16));
        }
        $ireneCsrf = (string)$_SESSION['portal_irene_csrf'];
    } catch (Throwable $e) {
        $ireneDisponivel = false;
        $ireneAviso = 'O chat da Irene não carregou agora.';
    }
}
$ireneHora = static function (string $data): string {
    $ts = strtotime($data);
    if (!$ts) {
        return '';
    }
    return date('Y-m-d', $ts) === date('Y-m-d') ? date('H:i', $ts) : date('d/m H:i', $ts);
};
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Agenda do Theo</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#0f172a}
  .top{position:sticky;top:0;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;background:#0f172a;color:#fff}
  .brand{font-size:15px;letter-spacing:.02em;color:#e2e8f0}
  .brand strong{color:#fff;font-size:17px}
  .top-actions{display:flex;align-items:center;gap:8px}
  .stamp{font-size:11px;color:#94a3b8}
  .btn{padding:7px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.25);background:transparent;color:#e2e8f0;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none}
  .btn:active{transform:translateY(1px)}
  main{max-width:760px;margin:0 auto;padding:16px 14px 48px}
  .resumo{display:flex;gap:10px;margin:4px 0 18px}
  .resumo div{flex:1;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px}
  .resumo span{display:block;font-size:11px;color:#64748b;margin-bottom:4px}
  .resumo strong{font-size:20px}
  .dia{margin-bottom:20px}
  .dia-head{display:flex;justify-content:space-between;align-items:baseline;font-size:12px;letter-spacing:.06em;color:#475569;padding:0 4px 8px}
  .card{display:flex;gap:12px;align-items:center;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px;margin-bottom:8px}
  .hora{min-width:54px;font-size:17px;font-weight:700;line-height:1.1}
  .hora small{display:block;font-size:11px;font-weight:500;color:#94a3b8;margin-top:2px}
  .info{flex:1;min-width:0}
  .info strong{display:block;font-size:15px}
  .info .sub{display:block;font-size:12px;color:#64748b;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .lado{display:flex;flex-direction:column;align-items:flex-end;gap:4px;text-align:right}
  .tag{font-size:11px;font-weight:700;padding:3px 8px;border-radius:999px;white-space:nowrap}
  .tag.ok{background:#ecfdf3;color:#027a48}
  .tag.done{background:#eff8ff;color:#175cd3}
  .tag.off{background:#fef3f2;color:#b42318}
  .valor{font-size:12px;color:#475569}
  .wa{font-size:11px;font-weight:600;color:#1f6f78;text-decoration:none}
  .vazio{background:#fff;border:1px dashed #d0d5dd;border-radius:14px;padding:20px;text-align:center;color:#64748b;font-size:14px}
  h2.sec{margin:26px 0 10px;font-size:13px;letter-spacing:.08em;color:#64748b;text-transform:uppercase}
  .dia.passado .dia-head{color:#a3adba}
  .card.passado,.card.cancelado{opacity:.45;filter:grayscale(.9);background:#fafafa;border-color:#eef0f3}
  .card.passado .wa,.card.cancelado .wa{color:#94a3b8}
  .irene-card{margin-top:26px;background:#fff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden}
  .irene-head{display:flex;align-items:center;gap:10px;padding:12px 14px;background:#0f172a;color:#e2e8f0}
  .irene-avatar{width:34px;height:34px;flex:0 0 34px;border-radius:50%;background:#1f6f78;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;letter-spacing:.04em}
  .irene-head strong{display:block;font-size:14px;color:#fff}
  .irene-head small{display:block;font-size:11px;color:#94a3b8;margin-top:1px}
  .irene-msgs{max-height:360px;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#f8fafc}
  .irene-msgs .msg{display:flex;flex-direction:column;max-width:88%}
  .irene-msgs .msg.user{align-self:flex-end;align-items:flex-end}
  .irene-msgs .msg.irene{align-self:flex-start;align-items:flex-start}
  .irene-msgs .bolha{padding:9px 12px;border-radius:14px;font-size:14px;line-height:1.45;white-space:normal;overflow-wrap:anywhere}
  .irene-msgs .bolha strong{font-weight:700}
  .irene-msgs .bolha code{background:#f1f5f9;border-radius:4px;padding:1px 3px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
  .irene-msgs .user .bolha{background:#1f6f78;color:#fff;border-bottom-right-radius:4px}
  .irene-msgs .irene .bolha{background:#fff;border:1px solid #e5e7eb;color:#0f172a;border-bottom-left-radius:4px}
  .irene-msgs .msg small{font-size:10px;color:#94a3b8;margin-top:3px}
  .irene-chips{display:flex;gap:6px;flex-wrap:wrap;padding:0 14px 10px;background:#f8fafc}
  .irene-chips button{border:1px solid #d0d5dd;background:#fff;color:#334155;border-radius:999px;padding:5px 10px;font-size:11px;cursor:pointer}
  .irene-chips button:active{transform:translateY(1px)}
  .irene-form{display:flex;gap:8px;padding:12px 14px;border-top:1px solid #e5e7eb;background:#fff}
  .irene-form input{flex:1;min-width:0;border:1px solid #d0d5dd;border-radius:10px;padding:10px 12px;font-size:14px;color:#0f172a;background:#fff}
  .irene-form input:focus{outline:2px solid #1f6f78;outline-offset:1px}
  .irene-form button{border:0;border-radius:10px;background:#1f6f78;color:#fff;font-weight:700;padding:0 16px;font-size:14px;cursor:pointer}
  .irene-form button:disabled{opacity:.5;cursor:default}
  .irene-erro{margin:0;padding:0 14px 12px;color:#b42318;font-size:12px;background:#fff}
  .irene-vazio{padding:18px 14px;font-size:13px;color:#64748b;line-height:1.5}
  .digitando .bolha{display:flex;gap:4px;align-items:center;padding:12px}
  .digitando .bolha span{width:5px;height:5px;border-radius:50%;background:#94a3b8;animation:pisca 1s infinite}
  .digitando .bolha span:nth-child(2){animation-delay:.15s}
  .digitando .bolha span:nth-child(3){animation-delay:.3s}
  @keyframes pisca{0%,80%,100%{opacity:.25}40%{opacity:1}}
</style>
</head>
<body>
<header class="top">
  <div class="brand">Agenda · <strong>Theo</strong></div>
  <div class="top-actions">
    <span class="stamp"><?= theo_h(date('d/m H:i')) ?></span>
    <a class="btn" href="?">Atualizar</a>
    <form method="post" style="margin:0"><button class="btn" type="submit" name="acao" value="sair">Sair</button></form>
  </div>
</header>
<main>
  <div class="resumo">
    <div><span>Hoje</span><strong><?= $totalHoje ?></strong></div>
    <div><span>Próximos</span><strong><?= $totalProximos ?></strong></div>
  </div>

  <?php if (!$pdo instanceof PDO) { ?>
    <div class="vazio">Não consegui conectar no banco do estúdio agora.</div>
  <?php } elseif (!$grupoProximos) { ?>
    <div class="vazio">Nenhum agendamento marcado para frente.</div>
  <?php } else { ?>
    <?php foreach ($grupoProximos as $dia => $lista) { $renderDia($rotuloDia((string)$dia), $lista); } ?>
  <?php } ?>

  <?php if ($grupoAnteriores) { ?>
    <h2 class="sec">Últimos 60 dias</h2>
    <?php foreach ($grupoAnteriores as $dia => $lista) { $renderDia($rotuloDia((string)$dia), $lista, true); } ?>
  <?php } ?>

  <section class="irene-card" aria-labelledby="irene-titulo">
    <div class="irene-head">
      <div class="irene-avatar">IR</div>
      <div><strong id="irene-titulo">Irene</strong><small>assistente do estúdio · histórico só seu</small></div>
    </div>
    <?php if (!$ireneDisponivel) { ?>
      <div class="irene-vazio"><?= theo_h($ireneAviso !== '' ? $ireneAviso : 'Chat indisponível agora.') ?></div>
    <?php } else { ?>
      <div class="irene-msgs" id="irene-msgs" aria-live="polite">
        <?php if (!$ireneHistorico) { ?>
          <div class="msg irene"><div class="bolha">Oi, Theo! Sou a Irene, assistente do estúdio. Pode perguntar da sua agenda, dos valores e dos seus clientes — esse histórico fica só no seu portal.</div></div>
        <?php } else { ?>
          <?php foreach ($ireneHistorico as $msg) { $papel = ($msg['papel'] ?? '') === 'irene' ? 'irene' : 'user'; ?>
            <div class="msg <?= $papel ?>"><div class="bolha"><?= portal_irene_html((string)($msg['texto'] ?? '')) ?></div><small><?= theo_h($ireneHora((string)($msg['criado_em'] ?? ''))) ?></small></div>
          <?php } ?>
        <?php } ?>
      </div>
      <div class="irene-chips">
        <button type="button" data-frase="Como está minha agenda de amanhã?">Agenda de amanhã</button>
        <button type="button" data-frase="Quanto tenho a receber nos próximos agendamentos?">Quanto vou receber</button>
        <button type="button" data-frase="Resume meus últimos 60 dias.">Últimos 60 dias</button>
      </div>
      <form class="irene-form" id="irene-form" autocomplete="off">
        <input id="irene-texto" type="text" maxlength="4000" placeholder="Pergunte algo para a Irene..." aria-label="Mensagem para a Irene">
        <button type="submit" id="irene-enviar">Enviar</button>
      </form>
      <p class="irene-erro" id="irene-erro" hidden></p>
    <?php } ?>
  </section>
</main>
<?php if ($ireneDisponivel) { ?>
<script>
(function () {
  var form = document.getElementById('irene-form');
  if (!form) return;
  var campo = document.getElementById('irene-texto');
  var botao = document.getElementById('irene-enviar');
  var lista = document.getElementById('irene-msgs');
  var erro = document.getElementById('irene-erro');
  var csrf = <?= json_encode($ireneCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var ocupado = false;

  function rolar() { lista.scrollTop = lista.scrollHeight; }
  function hora() {
    var d = new Date();
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
  }
  function formatar(texto) {
    var escapado = document.createElement('div');
    escapado.textContent = String(texto).replace(/\r\n/g, '\n');
    var html = escapado.innerHTML;
    html = html.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');
    html = html.replace(/^#{1,6}\s*(.+)$/gm, '<strong>$1</strong>');
    return html.replace(/\n/g, '<br>');
  }
  function bolha(papel, texto) {
    var wrap = document.createElement('div');
    wrap.className = 'msg ' + papel;
    var b = document.createElement('div');
    b.className = 'bolha';
    b.innerHTML = formatar(texto);
    var s = document.createElement('small');
    s.textContent = hora();
    wrap.appendChild(b);
    wrap.appendChild(s);
    lista.appendChild(wrap);
    rolar();
    return wrap;
  }
  function digitando() {
    var wrap = document.createElement('div');
    wrap.className = 'msg irene digitando';
    var b = document.createElement('div');
    b.className = 'bolha';
    for (var i = 0; i < 3; i++) { b.appendChild(document.createElement('span')); }
    wrap.appendChild(b);
    lista.appendChild(wrap);
    rolar();
    return wrap;
  }
  function mostraErro(msg) {
    if (!msg) { erro.hidden = true; erro.textContent = ''; return; }
    erro.textContent = msg;
    erro.hidden = false;
  }
  function ocupar(v) {
    ocupado = v;
    botao.disabled = v;
    campo.disabled = v;
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var texto = (campo.value || '').trim();
    if (!texto || ocupado) return;
    mostraErro('');
    bolha('user', texto);
    campo.value = '';
    var espera = digitando();
    ocupar(true);

    fetch('/projetocrm/portal_irene.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Portal-Csrf': csrf },
      body: JSON.stringify({ mensagem: texto, csrf: csrf })
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, erro: 'Resposta inválida do servidor.' }; });
    }).then(function (d) {
      espera.remove();
      if (!d || !d.ok) throw new Error((d && d.erro) || 'A Irene não respondeu.');
      bolha('irene', d.resposta);
      if (d.aviso) mostraErro(d.aviso);
    }).catch(function (e) {
      espera.remove();
      mostraErro(e.message || 'Falha de rede.');
      campo.value = texto;
    }).then(function () {
      ocupar(false);
      campo.focus();
    });
  });

  Array.prototype.forEach.call(document.querySelectorAll('.irene-chips button'), function (b) {
    b.addEventListener('click', function () {
      campo.value = b.getAttribute('data-frase') || b.textContent;
      campo.focus();
    });
  });
})();
</script>
<?php } ?>
</body>
</html>
