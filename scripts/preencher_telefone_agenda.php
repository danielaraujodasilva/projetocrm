<?php
/**
 * Preenche o telefone que ficou faltando nos agendamentos importados do Google
 * Agenda.
 *
 * Por que existe: o evento do Google Agenda traz o NOME do cliente no titulo, mas
 * nao traz o telefone. Sem telefone, o agendamento nao consegue ser ligado a
 * conversa do WhatsApp - e o funil de anuncios nao consegue dizer de qual canal o
 * cliente veio. Aqui o telefone e resolvido pelo nome (cadastro do CRM e conversas
 * do WhatsApp), com a mesma regra conservadora da agenda: exige nome + sobrenome
 * batendo e descarta resultado ambiguo.
 *
 * Uso:
 *   php scripts/preencher_telefone_agenda.php                     (simulacao)
 *   php scripts/preencher_telefone_agenda.php --aplicar           (grava)
 *   php scripts/preencher_telefone_agenda.php --de=2026-08-01 --ate=2026-12-31
 *   php scripts/preencher_telefone_agenda.php --sem-primeiro-nome   (so nome completo)
 *
 * Duas tentativas de casamento, nesta ordem:
 *   1. NOME COMPLETO (seguro) - nome + sobrenome batendo.
 *   2. PRIMEIRO NOME (arriscado) - so quando a conversa tem exatamente esse
 *      primeiro nome e a conversa teve mensagem perto da data do agendamento
 *      (ver crm_telefone_por_primeiro_nome). Desligue com --sem-primeiro-nome.
 *
 * Nao altera nome, valor, data nem status: grava SOMENTE o telefone, e apenas no
 * lead que pertence ao agendamento, e apenas quando ele esta vazio. Todo telefone
 * gravado vai para o log (id do lead + telefone), o que permite reverter.
 */
declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = 'CLI';
require dirname(__DIR__) . '/app/bootstrap.php';

$args = $argv ?? [];
$aplicar = in_array('--aplicar', $args, true);
// Casamento por PRIMEIRO nome e' mais arriscado (homonimo): ligado por padrao,
// mas pode ser desligado com --sem-primeiro-nome.
$primeiroNome = !in_array('--sem-primeiro-nome', $args, true);
$de = '2026-08-01';
$ate = date('Y-m-d', strtotime('+180 days'));
foreach ($args as $arg) {
    if (preg_match('/^--de=(\d{4}-\d{2}-\d{2})$/', (string)$arg, $m)) {
        $de = $m[1];
    }
    if (preg_match('/^--ate=(\d{4}-\d{2}-\d{2})$/', (string)$arg, $m)) {
        $ate = $m[1];
    }
}

$studio = get_studio(1);
if (!$studio) {
    fwrite(STDERR, "Estudio nao encontrado.\n");
    exit(1);
}
$pdo = studio_db($studio);

$sql = "SELECT a.id AS appointment_id, a.lead_id, a.customer_id, a.appointment_date,
               COALESCE(NULLIF(TRIM(l.name),''), NULLIF(TRIM(c.name),''), a.title) AS nome,
               COALESCE(l.phone, '') AS lead_phone,
               COALESCE(c.phone, '') AS customer_phone
          FROM appointments a
          LEFT JOIN leads l ON l.id = a.lead_id
          LEFT JOIN customers c ON c.id = a.customer_id
         WHERE a.appointment_date BETWEEN ? AND ?
           AND a.import_source = 'google_calendar'
           AND COALESCE(a.lead_id, 0) > 0
           AND COALESCE(l.phone, '') = ''
           AND COALESCE(c.phone, '') = ''
         ORDER BY a.appointment_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$de, $ate]);
$linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo ($aplicar ? 'MODO GRAVACAO' : 'SIMULACAO (nada sera gravado)') . "\n";
echo "Periodo: {$de} a {$ate}\n";
echo "Agendamentos importados do Google Agenda sem telefone: " . count($linhas) . "\n\n";

$resolvidos = [];
$sem = 0;
foreach ($linhas as $row) {
    $nome = (string)$row['nome'];
    $data = (string)$row['appointment_date'];
    // 1a tentativa: nome + sobrenome (seguro). 2a: so o primeiro nome, com as
    // travas de unicidade e de data (ver crm_telefone_por_primeiro_nome).
    $metodo = 'nome completo';
    $fone = function_exists('crm_telefone_por_nome') ? crm_telefone_por_nome($nome) : '';
    if ($fone === '' && $primeiroNome && function_exists('crm_telefone_por_primeiro_nome')) {
        $fone = crm_telefone_por_primeiro_nome($nome, $data);
        $metodo = 'primeiro nome';
    }
    if ($fone === '') {
        $sem++;
        continue;
    }
    $resolvidos[] = ['lead_id' => (int)$row['lead_id'], 'appointment_id' => (int)$row['appointment_id'], 'nome' => $nome, 'phone' => $fone, 'data' => $data, 'metodo' => $metodo];
}

foreach ($resolvidos as $r) {
    printf("  %s  %-40s -> %-14s [%s]\n", $r['data'], mb_substr($r['nome'], 0, 40), $r['phone'], $r['metodo']);
    if (!$aplicar) {
        continue;
    }
    $up = $pdo->prepare("UPDATE leads SET phone = ?, updated_at = NOW() WHERE id = ? AND COALESCE(phone, '') = ''");
    $up->execute([$r['phone'], $r['lead_id']]);
}

echo "\nResumo: " . count($resolvidos) . " com telefone resolvido, {$sem} sem casamento seguro.\n";

if ($aplicar && $resolvidos) {
    $dir = APP_BASE_PATH . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $log = $dir . '/preencher_telefone_agenda_' . date('Ymd-His') . '.log';
    $linhasLog = [];
    foreach ($resolvidos as $r) {
        $linhasLog[] = $r['lead_id'] . ';' . $r['appointment_id'] . ';' . $r['phone'] . ';' . $r['metodo'] . ';' . $r['nome'];
    }
    file_put_contents($log, implode("\n", $linhasLog) . "\n");
    echo "Log para reverter: {$log}\n";
}