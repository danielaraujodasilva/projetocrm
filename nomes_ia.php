<?php
/**
 * PAGINA de acompanhamento da varredura de nomes via IA.
 * Consome api/nomes_ia_progresso.php e mostra o progresso ao vivo.
 *
 * Acesso: /projetocrm/nomes_ia.php
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Varredura de nomes via IA</title>
<style>
  :root { --bg:#0f1115; --card:#181b22; --bd:#2a2f3a; --tx:#e6e9ef; --mut:#9aa3b2;
          --ok:#22c55e; --err:#ef4444; --ac:#6366f1; }
  * { box-sizing:border-box; }
  body { margin:0; padding:24px; background:var(--bg); color:var(--tx);
         font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  h1 { margin:0 0 4px; font-size:20px; }
  .sub { color:var(--mut); margin-bottom:20px; font-size:13px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px; }
  .card { background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:14px 16px; }
  .card .lbl { color:var(--mut); font-size:11px; text-transform:uppercase; letter-spacing:.5px; }
  .card .val { font-size:26px; font-weight:700; margin-top:4px; }
  .bar { height:18px; background:#22262f; border-radius:9px; overflow:hidden; margin:6px 0 4px; }
  .bar > div { height:100%; background:linear-gradient(90deg,var(--ac),#8b5cf6); width:0; transition:width .4s; }
  .pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; }
  .pill.on { background:rgba(34,197,94,.15); color:var(--ok); }
  .pill.off { background:rgba(154,163,178,.15); color:var(--mut); }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th,td { text-align:left; padding:7px 10px; border-bottom:1px solid var(--bd); }
  th { color:var(--mut); font-weight:600; font-size:11px; text-transform:uppercase; }
  .col { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  @media (max-width:820px) { .col { grid-template-columns:1fr; } }
  .box { background:var(--card); border:1px solid var(--bd); border-radius:10px; padding:14px; max-height:420px; overflow:auto; }
  .box h2 { margin:0 0 10px; font-size:13px; text-transform:uppercase; color:var(--mut); letter-spacing:.5px; }
  pre { margin:0; font:12px/1.5 ui-monospace,Consolas,monospace; color:var(--mut); white-space:pre-wrap; }
  .conf { font-variant-numeric:tabular-nums; }
  .c100 { color:var(--ok); } .c70 { color:#eab308; } .c50 { color:var(--err); }
</style>
</head>
<body>
<h1>Varredura de nomes via IA local</h1>
<div class="sub">
  Extração de nome completo das conversas da ponte ·
  motor <code id="motor">qwen/qwen3-8b</code> ·
  <span id="estado" class="pill off">verificando…</span>
</div>

<div class="grid">
  <div class="card"><div class="lbl">Progresso</div><div class="val"><span id="pct">0</span>%</div></div>
  <div class="card"><div class="lbl">Processadas</div><div class="val"><span id="proc">0</span><span style="font-size:14px;color:var(--mut)"> / <span id="tot">0</span></span></div></div>
  <div class="card"><div class="lbl">Nomes achados</div><div class="val" style="color:var(--ok)"><span id="ach">0</span></div></div>
  <div class="card"><div class="lbl">Falhas</div><div class="val" style="color:var(--err)"><span id="fal">0</span></div></div>
  <div class="card"><div class="lbl">Restantes</div><div class="val"><span id="res">0</span></div></div>
</div>

<div class="bar"><div id="barra"></div></div>
<div class="sub" style="margin-top:2px">Checkpoint salvo: <span id="mtime">—</span></div>

<div class="col" style="margin-top:20px">
  <div class="box">
    <h2>Nomes encontrados (<span id="achN">0</span>)</h2>
    <table>
      <thead><tr><th>Telefone</th><th>Nome</th><th>Conf.</th></tr></thead>
      <tbody id="tbNomes"></tbody>
    </table>
  </div>
  <div class="box">
    <h2>Log recente</h2>
    <pre id="log">aguardando…</pre>
  </div>
</div>

<script>
const $ = id => document.getElementById(id);
let erroSeguidas = 0;

async function atualizar() {
  try {
    const r = await fetch('api/nomes_ia_progresso.php?_=' + Date.now(), {cache:'no-store'});
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const j = await r.json();
    erroSeguidas = 0;

    $('pct').textContent  = j.percentual;
    $('proc').textContent = j.processadas;
    $('tot').textContent  = j.total;
    $('ach').textContent  = j.achados;
    $('fal').textContent  = j.falhas;
    $('res').textContent  = j.restantes;
    $('mtime').textContent = j.checkpoint_mtime || '—';
    $('barra').style.width = j.percentual + '%';

    const est = $('estado');
    est.textContent = j.rodando ? 'rodando' : 'parado';
    est.className = 'pill ' + (j.rodando ? 'on' : 'off');

    $('achN').textContent = j.nomes.length;
    const tb = $('tbNomes');
    tb.innerHTML = '';
    j.nomes.slice().reverse().forEach(n => {
      const cls = n.conf >= 90 ? 'c100' : (n.conf >= 70 ? 'c70' : 'c50');
      const tr = document.createElement('tr');
      tr.innerHTML = '<td>' + n.fone + '</td><td>' + esc(n.nome) + '</td>' +
                     '<td class="conf ' + cls + '">' + n.conf + '</td>';
      tb.appendChild(tr);
    });

    $('log').textContent = (j.log || []).join('\n') || '—';
  } catch (e) {
    erroSeguidas++;
    $('estado').textContent = 'erro (' + erroSeguidas + ')';
    $('estado').className = 'pill off';
  }
}
function esc(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
atualizar();
setInterval(atualizar, 3000);
</script>
</body>
</html>
