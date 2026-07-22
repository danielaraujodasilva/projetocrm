<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$__app_build_cache = null;
function app_build_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }
    $root = __DIR__;
    $gitDir = $root . DIRECTORY_SEPARATOR . '.git';
    $gitBinary = 'git';
    $gitVersion = null;

    if (is_dir($gitDir)) {
        $command = $gitBinary . ' -C ' . escapeshellarg($root) . ' log -1 --date=format:%Y%m%d%H%M%S --format=%cd';
        $output = @shell_exec($command);
        $gitVersion = is_string($output) ? trim($output) : '';
    }

    if (is_string($gitVersion) && preg_match('/^\d{14}$/', $gitVersion) === 1) {
        $version = 'commitV' . $gitVersion;
        return $version;
    }

    $fallback = date('YmdHis', filemtime($root . DIRECTORY_SEPARATOR . 'index.php') ?: time());
    $version = 'commitV' . $fallback;
    return $version;
}

$dbStatus = db_status();
$schemaReady = $dbStatus['ok'] && schema_ready();
$page = (string)($_GET['page'] ?? 'home');

if ($page === 'lead_public_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $token = trim((string)($_POST['token'] ?? ''));
    if ($leadId <= 0 || $token === '') {
        http_response_code(404);
        exit('Link invalido.');
    }
    studio_ensure_public_lead_links_column();
    $link = studio_find_public_lead_link($leadId, $token);
    if (!is_array($link) || empty($link['studio_id'])) {
        http_response_code(404);
        exit('Link invalido.');
    }
    $studio = get_studio((int)$link['studio_id']);
    if (!$studio) {
        http_response_code(404);
        exit('Link invalido.');
    }
    $dbStatus = studio_db_status_for($studio);
    if (!$dbStatus['ok']) {
        http_response_code(503);
        exit('Banco do estúdio indisponível.');
    }
    $lead = studio_find_lead($studio, $leadId);
    if (!$lead || trim((string)($lead['public_update_token'] ?? '')) !== $token) {
        http_response_code(404);
        exit('Link invalido.');
    }
    $payload = [
        'name' => trim((string)($_POST['name'] ?? '')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
        'interest' => trim((string)($_POST['interest'] ?? '')),
        'body_area' => trim((string)($_POST['body_area'] ?? '')),
        'tattoo_size' => trim((string)($_POST['tattoo_size'] ?? '')),
        'reference_link' => trim((string)($_POST['reference_link'] ?? '')),
        'best_contact_time' => trim((string)($_POST['best_contact_time'] ?? '')),
        'allergies' => trim((string)($_POST['allergies'] ?? '')),
        'medications' => trim((string)($_POST['medications'] ?? '')),
        'health_conditions' => trim((string)($_POST['health_conditions'] ?? '')),
        'skin_conditions' => trim((string)($_POST['skin_conditions'] ?? '')),
        'keloid_history' => trim((string)($_POST['keloid_history'] ?? '')),
        'anticoagulants' => trim((string)($_POST['anticoagulants'] ?? '')),
        'diabetes' => trim((string)($_POST['diabetes'] ?? '')),
        'healing_issues' => trim((string)($_POST['healing_issues'] ?? '')),
        'data_processing_consent' => !empty($_POST['data_processing_consent']) ? '1' : '0',
        'health_data_consent' => !empty($_POST['health_data_consent']) ? '1' : '0',
        'truthfulness_confirmed' => !empty($_POST['truthfulness_confirmed']) ? '1' : '0',
        'marketing_opt_in' => !empty($_POST['marketing_opt_in']) ? '1' : '0',
        'share_before_after_opt_in' => !empty($_POST['share_before_after_opt_in']) ? '1' : '0',
        'social_network_opt_in' => !empty($_POST['social_network_opt_in']) ? '1' : '0',
        'notes' => trim((string)($_POST['notes'] ?? '')),
    ];
    $action = (string)($_POST['action'] ?? 'public_lead_update');
    if ($action === 'public_lead_autosave') {
        studio_save_public_lead_progress($studio, $leadId, $token, $payload, (string)($_POST['step'] ?? 'draft'), false);
        studio_log_public_lead_event($studio, $leadId, $token, (string)($_POST['step_event'] ?? 'started'), $payload);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($payload['data_processing_consent'] !== '1' || $payload['health_data_consent'] !== '1' || $payload['truthfulness_confirmed'] !== '1') {
        flash_set('error', 'Marque os consentimentos obrigatórios para enviar.');
        redirect_to('lead_public_update', ['lead' => $leadId, 'token' => $token]);
    }
    studio_save_public_lead_progress($studio, $leadId, $token, $payload, 'finished', true);
    studio_log_public_lead_event($studio, $leadId, $token, 'finished', $payload);
    $customerPayload = array_merge($payload, [
        'id' => !empty($lead['customer_id']) ? (int)$lead['customer_id'] : null,
        'email' => trim((string)($_POST['email'] ?? '')),
        'instagram' => trim((string)($_POST['instagram'] ?? '')),
        'birth_date' => trim((string)($_POST['birth_date'] ?? '')),
        'gender' => trim((string)($_POST['gender'] ?? '')),
        'document_number' => trim((string)($_POST['document_number'] ?? '')),
        'occupation' => trim((string)($_POST['occupation'] ?? '')),
        'address_zip' => trim((string)($_POST['address_zip'] ?? '')),
        'address_street' => trim((string)($_POST['address_street'] ?? '')),
        'address_number' => trim((string)($_POST['address_number'] ?? '')),
        'address_complement' => trim((string)($_POST['address_complement'] ?? '')),
        'address_neighborhood' => trim((string)($_POST['address_neighborhood'] ?? '')),
        'address_city' => trim((string)($_POST['address_city'] ?? '')),
        'address_state' => trim((string)($_POST['address_state'] ?? '')),
        'address_reference' => trim((string)($_POST['address_reference'] ?? '')),
        'emergency_contact_name' => trim((string)($_POST['emergency_contact_name'] ?? '')),
        'emergency_contact_phone' => trim((string)($_POST['emergency_contact_phone'] ?? '')),
        'previous_tattoos' => trim((string)($_POST['previous_tattoos'] ?? '')),
        'pain_tolerance' => trim((string)($_POST['pain_tolerance'] ?? '')),
        'social_networks' => trim((string)($_POST['social_networks'] ?? '')),
        'whatsapp_opt_in' => !empty($_POST['whatsapp_opt_in']) ? '1' : '0',
        'email_opt_in' => !empty($_POST['email_opt_in']) ? '1' : '0',
        'sms_opt_in' => !empty($_POST['sms_opt_in']) ? '1' : '0',
        'push_opt_in' => !empty($_POST['push_opt_in']) ? '1' : '0',
        'marketing_channels' => trim((string)($_POST['marketing_channels'] ?? '')),
        'reference_style' => trim((string)($_POST['reference_style'] ?? '')),
        'health_data_consent' => !empty($_POST['health_data_consent']) ? '1' : '0',
        'notes' => trim((string)($_POST['notes'] ?? '')),
    ]);
    $savedCustomerId = studio_save_customer($studio, $customerPayload);
    studio_db($studio)->prepare('UPDATE leads SET customer_id = ?, name = COALESCE(NULLIF(?, ""), name), phone = COALESCE(NULLIF(?, ""), phone), interest = COALESCE(NULLIF(?, ""), interest), updated_at = NOW() WHERE id = ?')
        ->execute([$savedCustomerId, $payload['name'], $payload['phone'], $payload['interest'], $leadId]);
    flash_set('success', 'Ficha enviada. Obrigado!');
    redirect_to('lead_public_update', ['lead' => $leadId, 'token' => $token, 'done' => 1]);
}

if ($page === 'lead_public_update') {
    $leadId = (int)($_GET['lead'] ?? 0);
    $token = trim((string)($_GET['token'] ?? ''));
    studio_ensure_public_lead_links_column();
    $link = null;
    if ($leadId > 0 && $token !== '') {
        $stmt = db()->prepare('SELECT * FROM public_lead_links WHERE lead_id = ? AND token = ? LIMIT 1');
        $stmt->execute([$leadId, $token]);
        $link = $stmt->fetch();
    }
    if (!is_array($link) || empty($link['studio_id'])) {
        http_response_code(404);
        echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link invalido</title><link rel="stylesheet" href="' . h(app_asset_url('assets/app.css')) . '"></head><body><main class="container" style="padding:40px 16px"><section class="panel"><h1>Link invalido</h1><p class="muted">Esse link nao existe ou expirou.</p></section></main></body></html>';
        exit;
    }
    $studio = get_studio((int)$link['studio_id']);
    if (!$studio) {
        http_response_code(404);
        echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link invalido</title><link rel="stylesheet" href="' . h(app_asset_url('assets/app.css')) . '"></head><body><main class="container" style="padding:40px 16px"><section class="panel"><h1>Link invalido</h1><p class="muted">Esse link nao existe ou expirou.</p></section></main></body></html>';
        exit;
    }
    $dbStatus = studio_db_status_for($studio);
    if (!$dbStatus['ok']) {
        render_studio_db_missing($studio, $dbStatus['error']);
        exit;
    }
    $lead = studio_find_lead($studio, $leadId);
    if (!$lead || trim((string)($lead['public_update_token'] ?? '')) !== $token) {
        http_response_code(404);
        echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link invalido</title><link rel="stylesheet" href="' . h(app_asset_url('assets/app.css')) . '"></head><body><main class="container" style="padding:40px 16px"><section class="panel"><h1>Link invalido</h1><p class="muted">Esse link nao existe ou expirou.</p></section></main></body></html>';
        exit;
    }
    if (!empty($_GET['done'])) {
        $studioPhone = preg_replace('/\D+/', '', (string)($studio['owner_phone'] ?? ''));
        $waUrl = $studioPhone !== '' ? 'https://wa.me/55' . $studioPhone : '';
        render_public_page('Cadastro enviado', 'Obrigado por completar seu cadastro.', function () use ($waUrl) {
            echo '<div class="public-page-wrap" style="padding:40px 16px"><section class="panel" style="max-width:720px;margin:0 auto;text-align:center"><h1>Cadastro enviado!</h1><p class="muted">Recebi suas informações. Agora posso seguir seu atendimento com mais segurança.</p>';
            if ($waUrl !== '') {
                echo '<p style="margin-top:20px"><a class="btn" href="' . h($waUrl) . '" target="_blank" rel="noopener">Voltar para o WhatsApp</a></p>';
            }
            echo '</section></div>';
        }, null);
        exit;
    }
    $customer = !empty($lead['customer_id']) ? studio_find_customer($studio, (int)$lead['customer_id']) : null;
    $customerSeed = is_array($customer) ? $customer : [];
    render_public_page('Atualizar cadastro', 'Complete seus dados para agilizar o atendimento.', function () use ($lead, $leadId, $token, $customerSeed) {
        $value = static fn(string $field, string $fallback = ''): string => (string)($customerSeed[$field] ?? $fallback);
        echo '<style>
            .public-lead-shell{max-width:860px;margin:0 auto;padding:0 0 24px}
            .public-lead-hero{position:relative;overflow:hidden;border:1px solid rgba(31,111,120,.14);background:linear-gradient(135deg,#0f172a,#1f6f78);color:#f8fafc;border-radius:20px;padding:26px;box-shadow:0 24px 60px rgba(15,23,42,.18)}
            .public-lead-hero:before{content:"";position:absolute;inset:auto -70px -120px auto;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.18),rgba(255,255,255,0) 70%);pointer-events:none}
            .public-lead-hero .muted{color:#dbeafe !important}
            .public-lead-hero h1{margin:10px 0 10px;font-size:clamp(30px,4vw,50px);line-height:1.02;letter-spacing:0;color:#fff}
            .public-lead-hero p{max-width:66ch;line-height:1.75;color:#e2e8f0}
            .public-lead-flow{display:grid;gap:14px;margin-top:18px}
            .public-lead-section{border:1px solid rgba(15,23,42,.08);border-radius:20px;background:#fff;padding:18px;box-shadow:0 10px 30px rgba(15,23,42,.05)} .public-step-extra{background:#f8fafc;border:1px solid rgba(148,163,184,.16);border-radius:14px;padding:12px;margin-top:10px} .public-choicebar{margin-top:8px}
            .public-step[data-step="3"] .grid{gap:12px}
            .public-step[data-step="3"] .grid > .field{padding:14px;border:1px solid rgba(148,163,184,.16);border-radius:16px;background:#f8fafc;box-shadow:0 1px 0 rgba(15,23,42,.03)}
            .public-step[data-step="3"] .grid > .field > label{display:block;margin-bottom:6px;color:#0f172a;font-weight:700}
            .public-step[data-step="3"] .grid > .field .public-choicebar{margin-top:10px}
            .public-step[data-step="3"] .grid > .field .public-step-extra{background:#fff}
            .public-lead-section h2{margin:0 0 10px;font-size:1.05rem;color:#0f172a}
            .public-lead-section .field label{font-size:.86rem;color:#334155}
            .public-lead-note{font-size:.92rem;color:#475569;line-height:1.6;margin:0 0 12px}
            .public-lead-consent-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
            .public-lead-consent-grid .checkline{margin:0;padding:14px;border:1px solid rgba(148,163,184,.18);border-radius:14px;background:#f8fafc;align-items:flex-start}
            .public-lead-consent-grid .consent-tag{display:inline-flex;align-items:center;justify-content:center;min-width:86px;padding:4px 10px;margin:0 0 8px 0;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.01em}
            .public-lead-consent-grid .consent-tag.required{background:#eef2ff;color:#4338ca}
            .public-lead-consent-grid .consent-tag.optional{background:#f1f5f9;color:#475569}
            .public-lead-submit{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding-top:6px}
            .public-lead-footerbar{position:sticky;bottom:10px;z-index:2;display:flex;justify-content:center;margin-top:18px}
            .public-lead-footerbar .inner{width:min(100%,860px);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:rgba(255,255,255,.94);backdrop-filter:blur(10px);border:1px solid rgba(148,163,184,.22);border-radius:16px;padding:12px 14px;box-shadow:0 12px 34px rgba(15,23,42,.08)}
            .public-lead-disclaimer{display:flex;align-items:flex-start;gap:10px;padding:14px 16px;border-radius:14px;background:#f8fafc;border:1px solid rgba(148,163,184,.22);color:#334155;line-height:1.55}
            .public-lead-disclaimer strong{display:block;color:#0f172a}
            .public-step{display:none}
            .public-step.is-active{display:block}
            .public-step-nav{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
            .public-progress{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:16px;background:#fff;border:1px solid rgba(148,163,184,.22);margin-top:14px}
            .public-progress .track{flex:1;height:10px;background:#e8eef1;border-radius:999px;overflow:hidden}
            .public-progress .track span{display:block;height:100%;width:25%;background:linear-gradient(90deg,#1f6f78,#38a3a5)}
            .public-choicebar{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
            .public-choice{border:1px solid rgba(148,163,184,.22);border-radius:14px;background:#fff;padding:12px 10px;font-weight:700;color:#0f172a;text-align:center;min-height:48px;cursor:pointer;touch-action:manipulation}
            .public-choice.active{background:#1f6f78;color:#fff;border-color:#1f6f78}
            @media (max-width: 900px){
                .public-lead-consent-grid,.public-choicebar{grid-template-columns:1fr}
                .public-lead-footerbar{position:static}
            }
        </style>';
        echo '<div class="public-lead-shell" id="public-lead-wizard">';
        echo '<section class="public-lead-hero">';
        echo '<span class="public-lead-kicker">Ficha &aacute;gil</span>';
        echo '<h1>Complete seu cadastro</h1>';
        echo '<p>Leva poucos minutos e ajuda a fazer seu atendimento com mais segurança.</p>';
        echo '<div class="public-lead-btn-row" style="margin-top:18px"><a class="btn" href="#lead-form">Come&ccedil;ar cadastro</a></div>';
        echo '<div class="muted" style="margin-top:10px;color:#dbeafe">Seu progresso &eacute; salvo automaticamente.</div>';
        echo '</section>';
        echo '<div class="public-lead-flow">';
        echo '<div class="public-lead-summary public-lead-summary-grid row row-cols-2 row-cols-md-4 g-2">';
        echo '<div class="public-lead-chip h-100"><strong>1</strong><span>Dados</span></div>';
        echo '<div class="public-lead-chip h-100"><strong>2</strong><span>Contato</span></div>';
        echo '<div class="public-lead-chip h-100"><strong>3</strong><span>Saúde</span></div>';
        echo '<div class="public-lead-chip h-100"><strong>4</strong><span>Enviar</span></div>';
        echo '</div>';
        echo '<form class="form" method="post" id="lead-form">';
        echo '<input type="hidden" name="action" value="public_lead_update"><input type="hidden" name="lead_id" value="' . h((string)$leadId) . '"><input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="wizard_step" id="wizard-step" value="1"><input type="hidden" name="step_event" id="step-event" value="opened">';
        echo '<section class="public-lead-section public-step is-active" data-step="1"><div class="actions" style="justify-content:space-between;align-items:flex-start"><h2 style="margin-top:0">Dados b&aacute;sicos</h2><span class="badge">1 de 4</span></div><p class="public-lead-note">Essas informa&ccedil;&otilde;es identificam seu cadastro.</p><div class="grid cols-2"><div class="field"><label>Nome completo</label><input name="name" value="' . h($value('name', (string)($lead['name'] ?? ''))) . '" placeholder="Seu nome completo"></div><div class="field"><label>Data de nascimento</label><input type="date" name="birth_date" value="' . h($value('birth_date')) . '"></div></div><div class="grid cols-2" style="margin-top:12px"><div class="field"><label>G&ecirc;nero</label><select name="gender"><option value="">Prefiro n&atilde;o informar</option><option value="Homem" ' . ($value('gender') === 'Homem' ? 'selected' : '') . '>Homem</option><option value="Mulher" ' . ($value('gender') === 'Mulher' ? 'selected' : '') . '>Mulher</option><option value="N&atilde;o bin&aacute;rio" ' . ($value('gender') === 'Não binário' || $value('gender') === 'Nao binario' ? 'selected' : '') . '>N&atilde;o bin&aacute;rio</option><option value="Outro" ' . ($value('gender') === 'Outro' ? 'selected' : '') . '>Outro</option></select></div><div class="field"><label>Profiss&atilde;o</label><input name="occupation" value="' . h($value('occupation')) . '" placeholder="Opcional"></div></div><div class="field" style="margin-top:12px"><label>Instagram</label><input name="instagram" value="' . h($value('instagram')) . '" placeholder="@seuusuario"></div></section>';
        echo '<section class="public-lead-section public-step" data-step="2" hidden><div class="actions" style="justify-content:space-between;align-items:flex-start"><h2 style="margin-top:0">Contato</h2><span class="badge">2 de 4</span></div><p class="public-lead-note">Usaremos esses dados para atendimento e organiza&ccedil;&atilde;o.</p><div class="grid cols-2"><div class="field"><label>WhatsApp</label><input name="phone" value="' . h($value('phone', (string)($lead['phone'] ?? ''))) . '" placeholder="(00) 00000-0000"></div><div class="field"><label>Email</label><input name="email" type="email" value="' . h($value('email')) . '" placeholder="Opcional"></div></div></section>';
        echo '<section class="public-lead-section public-step" data-step="3" hidden><div class="actions" style="justify-content:space-between;align-items:flex-start"><h2 style="margin-top:0">Anamnese r&aacute;pida</h2><span class="badge">3 de 4</span></div><p class="public-lead-note">Responda o que souber. Isso ajuda na seguran&ccedil;a do procedimento.</p><div class="grid cols-2"><div class="field"><label>Tem alergia?</label><div class="public-choicebar" data-group="allergies"><button type="button" class="public-choice" data-value="Não">Não</button><button type="button" class="public-choice" data-value="Sim">Sim</button><button type="button" class="public-choice" data-value="Não sei">Não sei</button></div><input type="hidden" name="allergies" value=""><div class="field public-step-extra" style="display:none;margin-top:10px"><label>Descreva rapidamente.</label><textarea name="allergies_details" rows="3"></textarea></div></div><div class="field"><label>Usa medicamento contínuo ou importante?</label><div class="public-choicebar" data-group="medications"><button type="button" class="public-choice" data-value="Não">Não</button><button type="button" class="public-choice" data-value="Sim">Sim</button><button type="button" class="public-choice" data-value="Não sei">Não sei</button></div><input type="hidden" name="medications" value=""><div class="field public-step-extra" style="display:none;margin-top:10px"><label>Descreva rapidamente.</label><textarea name="medications_details" rows="3"></textarea></div></div></div><div class="grid cols-2" style="margin-top:12px"><div class="field"><label>Usa anticoagulante?</label><div class="public-choicebar" data-group="anticoagulants"><button type="button" class="public-choice" data-value="Não">Não</button><button type="button" class="public-choice" data-value="Sim">Sim</button><button type="button" class="public-choice" data-value="Não sei">Não sei</button></div><input type="hidden" name="anticoagulants" value=""><div class="field public-step-extra" style="display:none;margin-top:10px"><label>Descreva rapidamente.</label><textarea name="anticoagulants_details" rows="3"></textarea></div></div><div class="field"><label>Tem diabetes?</label><div class="public-choicebar" data-group="diabetes"><button type="button" class="public-choice" data-value="Não">Não</button><button type="button" class="public-choice" data-value="Sim">Sim</button><button type="button" class="public-choice" data-value="Não sei">Não sei</button></div><input type="hidden" name="diabetes" value=""><div class="field public-step-extra" style="display:none;margin-top:10px"><label>Descreva rapidamente.</label><textarea name="diabetes_details" rows="3"></textarea></div></div></div><div class="grid cols-2" style="margin-top:12px"><div class="field"><label>Tem pressão alta?</label><div class="public-choicebar" data-group="health_conditions"><button type="button" class="public-choice" data-value="Não">Não</button><button type="button" class="public-choice" data-value="Sim">Sim</button><button type="button" class="public-choice" data-value="Não sei">Não sei</button></div><input type="hidden" name="health_conditions" value=""><div class="field public-step-extra" style="display:none;margin-top:10px"><label>Descreva rapidamente.</label><textarea name="health_conditions_details" rows="3"></textarea></div></div><div class="field"><label>Tem alguma condi&ccedil;&atilde;o de pele importante?</label><div class="public-choicebar" data-group="skin_conditions"><button type="button" class="public-choice" data-value="Não">Não</button><button type="button" class="public-choice" data-value="Sim">Sim</button><button type="button" class="public-choice" data-value="Não sei">Não sei</button></div><input type="hidden" name="skin_conditions" value=""><div class="field public-step-extra" style="display:none;margin-top:10px"><label>Descreva rapidamente.</label><textarea name="skin_conditions_details" rows="3"></textarea></div></div></div><div class="grid cols-2" style="margin-top:12px"><div class="field"><label>Tem hist&oacute;rico de queloide?</label><div class="public-choicebar" data-group="keloid_history"><button type="button" class="public-choice" data-value="Não">Não</button><button type="button" class="public-choice" data-value="Sim">Sim</button><button type="button" class="public-choice" data-value="Não sei">Não sei</button></div><input type="hidden" name="keloid_history" value=""><div class="field public-step-extra" style="display:none;margin-top:10px"><label>Descreva rapidamente.</label><textarea name="keloid_history_details" rows="3"></textarea></div></div><div class="field"><label>Tem problemas de cicatriza&ccedil;&atilde;o?</label><div class="public-choicebar" data-group="healing_issues"><button type="button" class="public-choice" data-value="Não">Não</button><button type="button" class="public-choice" data-value="Sim">Sim</button><button type="button" class="public-choice" data-value="Não sei">Não sei</button></div><input type="hidden" name="healing_issues" value=""><div class="field public-step-extra" style="display:none;margin-top:10px"><label>Descreva rapidamente.</label><textarea name="healing_issues_details" rows="3"></textarea></div></div></div><div class="grid cols-1" style="margin-top:12px"><div class="field"><label>Est&aacute; gr&aacute;vida ou amamentando?</label><div class="public-choicebar" data-group="pregnant_or_breastfeeding"><button type="button" class="public-choice" data-value="Não">Não</button><button type="button" class="public-choice" data-value="Sim">Sim</button><button type="button" class="public-choice" data-value="Não sei">Não sei</button></div><input type="hidden" name="pregnant_or_breastfeeding" value=""><div class="field public-step-extra" style="display:none;margin-top:10px"><label>Descreva rapidamente.</label><textarea name="pregnant_or_breastfeeding_details" rows="3"></textarea></div></div></div></section>';
        echo '<section class="public-lead-section public-step" data-step="4" hidden><div class="actions" style="justify-content:space-between;align-items:flex-start"><h2 style="margin-top:0">Consentimentos</h2><span class="badge">4 de 4</span></div><p class="public-lead-note">Obrigat&oacute;rios: cadastro, sa&uacute;de e veracidade. Os demais s&atilde;o opcionais.</p><div class="public-lead-consent-grid"><label class="checkline"><span class="consent-tag required">Obrigat&oacute;rio</span><input type="checkbox" name="data_processing_consent" value="1"> Autorizo o uso dos meus dados para cadastro, atendimento e organiza&ccedil;&atilde;o do procedimento</label><label class="checkline"><span class="consent-tag required">Obrigat&oacute;rio</span><input type="checkbox" name="health_data_consent" value="1"> Autorizo o uso das informa&ccedil;&otilde;es de sa&uacute;de para avalia&ccedil;&atilde;o de seguran&ccedil;a do procedimento</label><label class="checkline"><span class="consent-tag required">Obrigat&oacute;rio</span><input type="checkbox" name="truthfulness_confirmed" value="1"> Declaro que as informa&ccedil;&otilde;es preenchidas s&atilde;o verdadeiras</label><label class="checkline"><span class="consent-tag optional">Opcional</span><input type="checkbox" name="marketing_opt_in" value="1"> Aceito receber promo&ccedil;&otilde;es pelo WhatsApp</label><label class="checkline"><span class="consent-tag optional">Opcional</span><input type="checkbox" name="email_opt_in" value="1"> Aceito receber comunica&ccedil;&otilde;es por email</label><label class="checkline"><span class="consent-tag optional">Opcional</span><input type="checkbox" name="share_before_after_opt_in" value="1"> Autorizo uso de fotos e v&iacute;deos em portf&oacute;lio</label><label class="checkline"><span class="consent-tag optional">Opcional</span><input type="checkbox" name="social_network_opt_in" value="1"> Autorizo marca&ccedil;&atilde;o em redes sociais</label><label class="checkline"><span class="consent-tag optional">Opcional</span><input type="checkbox" name="whatsapp_opt_in" value="1"> Autorizo contato por WhatsApp</label></div><p class="muted" style="margin-top:14px">Seus dados ser&atilde;o usados apenas para cadastro, atendimento e seguran&ccedil;a do procedimento.</p></section>';
        echo '<div class="public-progress"><div><strong style="display:block;color:#0f172a">Etapa</strong><span class="muted" id="wizard-label">1 de 4 · Dados básicos</span></div><div class="track"><span id="wizard-bar"></span></div></div>';
        echo '<div class="public-lead-footerbar"><div class="inner"><div class="muted">Salvo automaticamente.</div><div class="public-step-nav"><button type="button" class="btn secondary" id="wizard-prev">Voltar</button><button type="button" class="btn" id="wizard-next">Continuar</button></div></div></div>'; 
        echo '</form>';
        echo '<script>
            (function(){
                const form = document.getElementById("lead-form");
                if (!form) return;
                const steps = Array.from(form.querySelectorAll(".public-step"));
                const prev = document.getElementById("wizard-prev");
                const next = document.getElementById("wizard-next");
                const label = document.getElementById("wizard-label");
                const bar = document.getElementById("wizard-bar");
                const stepInput = document.getElementById("wizard-step");
                const stepNames = ["Dados básicos","Contato","Anamnese","Consentimentos"];
                let step = 1;
                const show = (n) => {
                    step = Math.max(1, Math.min(4, n));
                    steps.forEach((el) => {
                        const active = Number(el.dataset.step) === step;
                        el.hidden = !active;
                        el.classList.toggle("is-active", active);
                    });
                    if (stepInput) stepInput.value = String(step);
                    if (label) label.textContent = `${step} de 4 · ${stepNames[step - 1] || ""}`;
                    if (bar) bar.style.width = ((step / 4) * 100) + "%";
                    if (prev) prev.disabled = step === 1;
                    if (next) { next.hidden = false; next.textContent = step === 4 ? \'Enviar e agilizar meu atendimento\' : \'Continuar\'; }
                };
                const autosave = async (eventName) => {
                    const fd = new FormData(form);
                    fd.set("action", "public_lead_autosave");
                    fd.set("step", String(step));
                    fd.set("step_event", eventName || "started");
                    try { await fetch(window.location.href, { method: "POST", body: fd }); } catch (e) {}
                };
                if (prev) prev.addEventListener("click", async () => { if (step > 1) { await autosave("step_" + step + "_completed"); show(step - 1); } });
                if (next) next.addEventListener("click", async () => { if (step < 4) { const ev = step === 1 ? "step_contact_completed" : step === 2 ? "step_project_completed" : "step_health_completed"; await autosave(ev); show(step + 1); return; } const required = ["data_processing_consent","health_data_consent","truthfulness_confirmed"]; const missing = required.filter((name) => { const el = form.querySelector(`input[name="${name}"]`); return !el || !el.checked; }); if (missing.length) { alert("Marque os consentimentos obrigatórios: cadastro, saúde e veracidade."); return; } form.submit(); });
                const groups = ["allergies","medications","diabetes","health_conditions","anticoagulants","keloid_history","healing_issues","skin_conditions","pregnant_or_breastfeeding"];
                groups.forEach((name) => {
                    const barEl = form.querySelector("[data-group=\"" + name + "\"]");
                    const hidden = form.querySelector("input[name=\"" + name + "\"]");
                    const extra = barEl ? barEl.parentElement.querySelector(".public-step-extra") : null;
                    if (!barEl || !hidden) return;
                    const selectChoice = (value) => {
                        barEl.querySelectorAll(".public-choice").forEach((other) => other.classList.remove("active"));
                        const match = Array.from(barEl.querySelectorAll(".public-choice")).find((btn) => btn.dataset.value === value);
                        if (match) match.classList.add("active");
                        hidden.value = value || "";
                        if (extra) {
                            extra.style.display = value === "Sim" ? "block" : "none";
                            if (value !== "Sim") {
                                const detail = extra.querySelector("textarea");
                                if (detail) detail.value = "";
                            }
                        }
                    };
                    barEl.querySelectorAll("input[type=\"radio\"]").forEach((input) => {
                        input.addEventListener("change", () => selectChoice(input.value));
                    });
                    barEl.querySelectorAll(".public-choice").forEach((btn) => {
                        ["pointerup", "click"].forEach((evt) => btn.addEventListener(evt, (event) => { event.preventDefault(); const input = btn.querySelector("input[type=\"radio\"]"); if (input) { input.checked = true; input.dispatchEvent(new Event("change", { bubbles: true })); } else { selectChoice(btn.dataset.value || ""); } }, { passive: false }));
                    });
                });
                form.addEventListener("submit", async (ev) => {
                    if (step < 4) {
                        ev.preventDefault();
                        await autosave(step === 1 ? "step_contact_completed" : step === 2 ? "step_project_completed" : "step_health_completed");
                        show(step + 1);
                    } else {
                        const consent1 = form.querySelector("input[name=\"data_processing_consent\"]");
                        const consent2 = form.querySelector("input[name=\"health_data_consent\"]");
                        const consent3 = form.querySelector("input[name=\"truthfulness_confirmed\"]");
                        if (!consent1?.checked || !consent2?.checked || !consent3?.checked) {
                            ev.preventDefault();
                            alert("Marque os consentimentos obrigatórios: cadastro, saúde e veracidade.");
                        }
                    }
                });
                autosave("opened");
                show(1);
            })();
        </script>';
        echo '</div>';
    }, null);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page !== 'public_plans' && $page !== 'lead_public_update') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'install_admin') {
            if (!$schemaReady) {
                throw new RuntimeException('Banco central ainda nao esta pronto.');
            }
            if (admin_count() > 0) {
                throw new RuntimeException('O gerente inicial ja foi criado.');
            }
            if (strlen((string)($_POST['password'] ?? '')) < 8) {
                throw new RuntimeException('Use uma senha com pelo menos 8 caracteres.');
            }
            install_admin((string)$_POST['name'], (string)$_POST['email'], (string)$_POST['password']);
            flash_set('success', 'Gerente criado. Faca login para continuar.');
            redirect_to('login');
        }

        if ($action === 'login') {
            $email = (string)$_POST['email'];
            $password = (string)$_POST['password'];
            $loginContext = trim((string)($_POST['login_context'] ?? 'auto'));
            if (!in_array($loginContext, ['studio', 'admin', 'auto'], true)) {
                $loginContext = 'auto';
            }
            $returnTo = safe_local_return_url((string)($_POST['return_to'] ?? $_SESSION['admin_return_to'] ?? ''));
            $isStudioReturn = $returnTo !== '' && str_contains($returnTo, 'page=studio_');
            unset($_SESSION['admin_return_to']);
            if ($loginContext === 'admin') {
                if (login_admin($email, $password)) {
                    flash_set('success', 'Login administrativo realizado.');
                    if ($returnTo !== '' && !$isStudioReturn) {
                        redirect_to_url($returnTo);
                    }
                    redirect_to('dashboard');
                }
                flash_set('error', 'Email ou senha invalidos para o painel gerente.');
                redirect_to('login');
            }
            if ($loginContext === 'studio') {
                if (login_studio_user($email, $password)) {
                    flash_set('success', 'Login do estudio realizado.');
                    if ($returnTo !== '' && $isStudioReturn && str_contains($returnTo, 'page=studio_whatsapp_mobile')) {
                        redirect_to_url($returnTo);
                    }
                    redirect_to('studio_home');
                }
                flash_set('error', 'Email ou senha invalidos para o estudio.');
                redirect_to('login');
            }
            $identity = auth_identity_by_email($email);
            if ($identity['type'] === 'admin') {
                if (login_admin($email, $password)) {
                    flash_set('success', 'Login administrativo realizado.');
                    if ($returnTo !== '') {
                        redirect_to_url($returnTo);
                    }
                    redirect_to('dashboard');
                }
                flash_set('error', 'Email ou senha invalidos para o painel administrativo.');
                redirect_to('login');
            }
            if ($identity['type'] === 'studio') {
                if (login_studio_user($email, $password)) {
                    $user = current_studio_user();
                    $studioId = (int)($user['studio_id'] ?? 0);
                    if ($studioId > 0) {
                        flash_set('success', 'Login do estudio realizado.');
                        if ($returnTo !== '' && str_contains($returnTo, 'page=studio_whatsapp_mobile')) {
                            redirect_to_url($returnTo);
                        }
                        redirect_to('studio_home');
                    }
                }
                flash_set('error', 'Email ou senha invalidos para o estudio.');
                redirect_to('login');
            }
            if (login_admin($email, $password)) {
                flash_set('success', 'Login administrativo realizado.');
                if ($returnTo !== '') {
                    redirect_to_url($returnTo);
                }
                redirect_to('dashboard');
            }
            if (login_studio_user($email, $password)) {
                flash_set('success', 'Login do estudio realizado.');
                if ($returnTo !== '' && str_contains($returnTo, 'page=studio_whatsapp_mobile')) {
                    redirect_to_url($returnTo);
                }
                redirect_to('studio_home');
            }
            flash_set('error', 'Email ou senha invalidos ou sem acesso cadastrado.');
            redirect_to('login');
        }

if ($action === 'studio_login') {
            $returnTo = safe_local_return_url((string)($_POST['return_to'] ?? $_SESSION['studio_return_to'] ?? ''));
            if ($returnTo !== '') {
                $_SESSION['studio_return_to'] = $returnTo;
            }
            if (login_studio_user((string)$_POST['email'], (string)$_POST['password'])) {
                flash_set('success', 'Login do estudio realizado.');
                unset($_SESSION['studio_return_to']);
                if ($returnTo !== '' && str_contains($returnTo, 'page=studio_whatsapp_mobile')) {
                    redirect_to_url($returnTo);
                }
                redirect_to('studio_home');
            }
            flash_set('error', 'Email ou senha invalidos para o estudio.');
            redirect_to('studio_login');
        }

        if ($action === 'studio_mobile_login') {
            if (login_studio_user((string)$_POST['email'], (string)$_POST['password'])) {
                flash_set('success', 'Login do estudio realizado.');
                redirect_to('studio_whatsapp_mobile');
            }
            flash_set('error', 'Email ou senha invalidos para o estudio.');
            redirect_to('studio_whatsapp_mobile');
        }

        if ($action === 'create_studio') {
            $admin = require_admin();
            if (trim((string)($_POST['name'] ?? '')) === '') {
                throw new RuntimeException('Informe o nome do estudio.');
            }
            $studioId = create_studio($_POST, (int)$admin['id']);
            flash_set('success', 'Estudio cadastrado na plataforma alpha.');
            redirect_to('studio', ['id' => $studioId]);
        }

        if ($action === 'update_studio') {
            require_admin();
            $studio = get_studio((int)($_POST['id'] ?? 0));
            if (!$studio) {
                throw new RuntimeException('Estudio nao encontrado.');
            }
            update_studio($studio, $_POST);
            flash_set('success', 'Estudio atualizado.');
            redirect_to('studio', ['id' => (int)$studio['id']]);
        }

        if ($action === 'save_commercial_plan') {
            require_admin();
            $planId = save_commercial_plan($_POST);
            flash_set('success', 'Plano comercial salvo.');
            redirect_to('edit_plan', ['id' => $planId]);
        }

        if ($action === 'delete_commercial_plan') {
            require_admin();
            delete_commercial_plan((int)($_POST['id'] ?? 0));
            flash_set('success', 'Plano comercial removido.');
            redirect_to('plans');
        }

        if ($action === 'save_studio_access') {
            require_admin();
            $studio = get_studio((int)($_POST['studio_id'] ?? 0));
            if (!$studio) {
                throw new RuntimeException('Estudio nao encontrado.');
            }
            create_or_update_studio_owner_user(
                $studio,
                (string)$_POST['access_name'],
                (string)$_POST['access_email'],
                (string)$_POST['access_password']
            );
            flash_set('success', 'Acesso do estudio salvo.');
            redirect_to('studio', ['id' => (int)$studio['id']]);
        }

        if ($action === 'save_studio_attendant') {
            $studio = require_studio();
            $currentAdmin = current_admin();
            $currentStudioUser = current_studio_user();
            $canManage = $currentAdmin || (
                is_array($currentStudioUser)
                && (string)($currentStudioUser['role'] ?? '') === 'owner'
                && (int)($currentStudioUser['studio_id'] ?? 0) === (int)$studio['id']
            );
            if (!$canManage) {
                throw new RuntimeException('Acesso negado.');
            }
            $savedId = studio_save_attendant_user($studio, $_POST);
            flash_set('success', $savedId > 0 ? 'Atendente salvo.' : 'Atendente atualizado.');
            redirect_to('studio_attendants', ['studio_id' => (int)$studio['id']]);
        }

        if ($action === 'delete_studio_attendant') {
            $studio = require_studio();
            $currentAdmin = current_admin();
            $currentStudioUser = current_studio_user();
            $canManage = $currentAdmin || (
                is_array($currentStudioUser)
                && (string)($currentStudioUser['role'] ?? '') === 'owner'
                && (int)($currentStudioUser['studio_id'] ?? 0) === (int)$studio['id']
            );
            if (!$canManage) {
                throw new RuntimeException('Acesso negado.');
            }
            $userId = (int)($_POST['id'] ?? 0);
            if ($currentAdmin && (int)($currentAdmin['id'] ?? 0) === $userId) {
                throw new RuntimeException('Nao e permitido excluir seu proprio acesso.');
            }
            if ($currentStudioUser && (int)($currentStudioUser['id'] ?? 0) === $userId) {
                throw new RuntimeException('Nao e permitido excluir seu proprio acesso.');
            }
            studio_delete_attendant_user($studio, $userId);
            flash_set('success', 'Atendente excluido.');
            redirect_to('studio_attendants', ['studio_id' => (int)$studio['id']]);
        }

        if ($action === 'install_studio_database') {
            require_admin();
            $studio = get_studio((int)($_POST['studio_id'] ?? 0));
            if (!$studio) {
                throw new RuntimeException('Estudio nao encontrado.');
            }
            studio_install_database($studio);
            flash_set('success', 'Banco do estudio instalado/atualizado com sucesso.');
            redirect_to('studio', ['id' => (int)$studio['id']]);
        }

        if ($action === 'save_customer') {
            $studio = require_studio();
            $wasUpdate = (int)($_POST['id'] ?? 0) > 0;
            $customerId = studio_save_customer($studio, $_POST);
            studio_event((int)$studio['id'], $wasUpdate ? 'customer_updated' : 'customer_created', ($wasUpdate ? 'Cliente atualizado: ' : 'Cliente criado: ') . trim((string)($_POST['name'] ?? 'Cliente')), [
                'category' => 'people',
                'target_type' => 'customer',
                'target_id' => $customerId,
                'context' => ['phone' => (string)($_POST['phone'] ?? ''), 'email' => (string)($_POST['email'] ?? '')],
            ]);
            flash_set('success', 'Cliente salvo.');
            if (!empty($_POST['return_to_detail'])) {
                redirect_to('studio_customer', ['id' => $customerId]);
            }
            redirect_to('studio_customers');
        }

        if ($action === 'save_lead') {
            $studio = require_studio();
            if (trim((string)($_POST['name'] ?? '')) === '' && trim((string)($_POST['phone'] ?? '')) === '') {
                throw new RuntimeException('Informe pelo menos nome ou telefone do lead.');
            }
            $wasUpdate = (int)($_POST['id'] ?? 0) > 0;
            $leadId = studio_save_lead($studio, $_POST);
            studio_event((int)$studio['id'], $wasUpdate ? 'lead_updated' : 'lead_created', ($wasUpdate ? 'Lead atualizado: ' : 'Lead criado: ') . (trim((string)($_POST['name'] ?? '')) ?: trim((string)($_POST['phone'] ?? 'Lead'))), [
                'category' => 'people',
                'target_type' => 'lead',
                'target_id' => $leadId,
                'context' => [
                    'pipeline_stage' => (string)($_POST['pipeline_stage'] ?? ''),
                    'status' => (string)($_POST['status'] ?? ''),
                    'source' => (string)($_POST['source'] ?? ''),
                    'estimated_value' => (string)($_POST['estimated_value'] ?? ''),
                ],
            ]);
            flash_set('success', 'Lead salvo.');
            if (!empty($_POST['return_to_detail'])) {
                redirect_to('studio_lead', ['id' => $leadId]);
            }
            redirect_to('studio_leads');
        }

        if ($action === 'move_lead') {
            $studio = require_studio();
            $leadId = (int)($_POST['lead_id'] ?? 0);
            studio_update_lead_stage($studio, $leadId, (string)($_POST['pipeline_stage'] ?? ''), (string)($_POST['status'] ?? ''));
            studio_event((int)$studio['id'], 'lead_moved', 'Lead movido no funil.', [
                'category' => 'people',
                'target_type' => 'lead',
                'target_id' => $leadId,
                'context' => ['pipeline_stage' => (string)($_POST['pipeline_stage'] ?? ''), 'status' => (string)($_POST['status'] ?? '')],
            ]);
            flash_set('success', 'Lead movido no funil.');
            if (!empty($_POST['return_to_detail'])) {
                redirect_to('studio_lead', ['id' => $leadId]);
            }
            redirect_to('studio_leads');
        }

        if ($action === 'save_appointment') {
            $studio = require_studio();
            $wasUpdate = (int)($_POST['id'] ?? 0) > 0;
            $appointmentId = studio_save_appointment($studio, $_POST);
            $outboundAttempted = function_exists('google_calendar_outbound_enabled')
                && google_calendar_outbound_enabled($studio);
            $outboundOk = $outboundAttempted && function_exists('google_calendar_try_push_appointment')
                ? google_calendar_try_push_appointment($studio, $appointmentId)
                : false;
            studio_event((int)$studio['id'], $wasUpdate ? 'appointment_updated' : 'appointment_created', ($wasUpdate ? 'Agendamento atualizado: ' : 'Agendamento criado: ') . trim((string)($_POST['title'] ?? 'Atendimento')), [
                'category' => 'agenda',
                'target_type' => 'appointment',
                'target_id' => $appointmentId,
                'context' => [
                    'date' => (string)($_POST['appointment_date'] ?? ''),
                    'start_time' => (string)($_POST['start_time'] ?? ''),
                    'status' => (string)($_POST['status'] ?? ''),
                    'import_source' => (string)($_POST['import_source'] ?? 'manual'),
                    'google_outbound_attempted' => $outboundAttempted,
                    'google_outbound_ok' => $outboundOk,
                ],
            ]);
            flash_set(
                'success',
                $outboundAttempted && !$outboundOk
                    ? 'Agenda salva. O envio ao Google ficou pendente; veja Opções da agenda.'
                    : 'Agenda salva.'
            );
            if (!empty($_POST['return_to_mobile2'])) {
                redirect_to('studio_whatsapp_mobile', array_filter([
                    'id' => (int)($_POST['conversation_id'] ?? $_POST['return_to_conversation'] ?? 0) ?: null,
                ], static fn($value) => $value !== null && $value !== ''));
            }
            if (!empty($_POST['return_to_mobile'])) {
                redirect_to('studio_whatsapp_mobile', array_filter([
                    'id' => (int)($_POST['conversation_id'] ?? 0) ?: null,
                    'visibility' => 'all',
                ], static fn($value) => $value !== null && $value !== ''));
            }
            if (!empty($_POST['return_to_workspace'])) {
                redirect_to('studio_whatsapp_mobile', array_filter([
                    'id' => (int)($_POST['conversation_id'] ?? $_POST['return_to_conversation'] ?? 0) ?: null,
                ], static fn($value) => $value !== null && $value !== ''));
            }
            if (!empty($_POST['return_to_conversation'])) {
                redirect_to('studio_whatsapp_conversation', ['id' => (int)$_POST['return_to_conversation']]);
            }
            if (!empty($_POST['return_to_lead'])) {
                redirect_to('studio_lead', ['id' => (int)$_POST['return_to_lead']]);
            }
            if (!empty($_POST['return_to_customer'])) {
                redirect_to('studio_customer', ['id' => (int)$_POST['return_to_customer']]);
            }
            $redirectDate = trim((string)($_POST['appointment_date'] ?? ''));
            if ($redirectDate !== '') {
                redirect_to('studio_agenda', ['date' => $redirectDate, 'appointment_id' => $appointmentId]);
            }
            redirect_to('studio_agenda', ['appointment_id' => $appointmentId]);
        }

        if ($action === 'mark_appointment_status') {
            $studio = require_studio();
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $newStatus = trim((string)($_POST['status'] ?? 'falta'));
            studio_update_appointment_status($studio, $appointmentId, $newStatus);
            $outboundAttempted = function_exists('google_calendar_outbound_enabled')
                && google_calendar_outbound_enabled($studio);
            $outboundOk = $outboundAttempted && function_exists('google_calendar_try_push_appointment')
                ? google_calendar_try_push_appointment($studio, $appointmentId)
                : false;
            studio_event((int)$studio['id'], 'appointment_status_updated', 'Status do agendamento alterado para ' . $newStatus . '.', [
                'category' => 'agenda',
                'target_type' => 'appointment',
                'target_id' => $appointmentId,
                'context' => ['status' => $newStatus, 'google_outbound_attempted' => $outboundAttempted, 'google_outbound_ok' => $outboundOk],
            ]);
            flash_set(
                'success',
                $outboundAttempted && !$outboundOk
                    ? 'Status atualizado. O envio ao Google ficou pendente; veja Opções da agenda.'
                    : 'Status do agendamento atualizado.'
            );
            $redirectDate = trim((string)($_POST['appointment_date'] ?? ''));
            if ($redirectDate !== '') {
                redirect_to('studio_agenda', ['date' => $redirectDate, 'appointment_id' => $appointmentId]);
            }
            redirect_to('studio_agenda');
        }

        if ($action === 'delete_appointment') {
            $studio = require_studio();
            $appointmentId = (int)($_POST['appointment_id'] ?? $_POST['id'] ?? 0);
            $appointment = studio_find_appointment($studio, $appointmentId);
            if (!$appointment) {
                throw new RuntimeException('Agendamento não encontrado para excluir.');
            }
            $outboundAttempted = function_exists('google_calendar_outbound_enabled')
                && google_calendar_outbound_enabled($studio);
            $outboundOk = $outboundAttempted && function_exists('google_calendar_try_push_appointment')
                ? google_calendar_try_push_appointment($studio, $appointmentId, true)
                : false;
            studio_delete_appointment($studio, $appointmentId);
            studio_event((int)$studio['id'], 'appointment_deleted', 'Agendamento excluído: ' . trim((string)($appointment['title'] ?? $appointment['customer_name'] ?? 'Atendimento')), [
                'category' => 'agenda',
                'target_type' => 'appointment',
                'target_id' => $appointmentId,
                'context' => [
                    'date' => (string)($appointment['appointment_date'] ?? ''),
                    'start_time' => (string)($appointment['start_time'] ?? ''),
                    'google_outbound_attempted' => $outboundAttempted,
                    'google_outbound_ok' => $outboundOk,
                ],
            ]);
            flash_set(
                'success',
                $outboundAttempted && !$outboundOk
                    ? 'Agendamento excluido. O envio ao Google ficou pendente; veja Opções da agenda.'
                    : 'Agendamento excluido.'
            );
            $redirectDate = trim((string)($_POST['appointment_date'] ?? ''));
            if ($redirectDate !== '') {
                redirect_to('studio_agenda', ['date' => $redirectDate]);
            }
            redirect_to('studio_agenda');
        }

        if ($action === 'public_lead_update') {
            $leadId = (int)($_POST['lead_id'] ?? 0);
            $token = trim((string)($_POST['token'] ?? ''));
            if ($leadId <= 0 || $token === '') {
                throw new RuntimeException('Link invalido.');
            }
            studio_ensure_public_lead_links_column();
            $link = null;
            $stmt = db()->prepare('SELECT * FROM public_lead_links WHERE lead_id = ? AND token = ? LIMIT 1');
            $stmt->execute([$leadId, $token]);
            $link = $stmt->fetch();
            if (!is_array($link) || empty($link['studio_id'])) {
                throw new RuntimeException('Link expirado ou invalido.');
            }
            $studio = get_studio((int)$link['studio_id']);
            if (!$studio) {
                throw new RuntimeException('Link expirado ou invalido.');
            }
            $dbStatus = studio_db_status_for($studio);
            if (!$dbStatus['ok']) {
                throw new RuntimeException('Banco do estúdio indisponível.');
            }
            $lead = studio_find_lead($studio, $leadId);
            if (!$lead || trim((string)($lead['public_update_token'] ?? '')) !== $token) {
                throw new RuntimeException('Link expirado ou invalido.');
            }
            $customerId = (int)($lead['customer_id'] ?? 0);
            $customerPayload = array_merge($_POST, [
                'id' => $customerId > 0 ? $customerId : null,
                'name' => trim((string)($_POST['name'] ?? $lead['name'] ?? '')),
                'phone' => trim((string)($_POST['phone'] ?? $lead['phone'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')),
                'instagram' => trim((string)($_POST['instagram'] ?? '')),
                'notes' => trim((string)($_POST['notes'] ?? '')),
            ]);
            if (trim((string)($customerPayload['name'] ?? '')) === '' && trim((string)($customerPayload['phone'] ?? '')) === '') {
                throw new RuntimeException('Preencha pelo menos um campo de contato.');
            }
            $savedCustomerId = studio_save_customer($studio, $customerPayload);
            $leadStmt = studio_db($studio)->prepare('UPDATE leads SET customer_id = ?, name = COALESCE(NULLIF(?, ""), name), phone = COALESCE(NULLIF(?, ""), phone), interest = COALESCE(NULLIF(?, ""), interest), updated_at = NOW() WHERE id = ?');
            $leadStmt->execute([
                $savedCustomerId,
                trim((string)($_POST['name'] ?? $lead['name'] ?? '')),
                trim((string)($_POST['phone'] ?? $lead['phone'] ?? '')),
                trim((string)($_POST['interest'] ?? $lead['interest'] ?? '')),
                $leadId,
            ]);
            flash_set('success', 'Cadastro atualizado. Obrigado!');
            redirect_to('lead_public_update', ['lead' => $leadId, 'token' => $token]);
        }

        if ($action === 'import_calendar_ics') {
            $studio = require_studio();
            if (empty($_FILES['ics_file']['tmp_name'])) {
                throw new RuntimeException('Envie um arquivo .ics valido.');
            }
            $analysis = studio_analyze_calendar_ics($studio, (string)$_FILES['ics_file']['tmp_name']);
            $token = bin2hex(random_bytes(12));
            $_SESSION['calendar_import_preview'] ??= [];
            $_SESSION['calendar_import_preview'][$token] = [
                'studio_id' => (int)$studio['id'],
                'file_name' => (string)($_FILES['ics_file']['name'] ?? 'agenda.ics'),
                'created_at' => time(),
                'analysis' => $analysis,
            ];
            redirect_to('studio_agenda', ['ics_preview' => $token]);
        }

        if ($action === 'import_calendar_ics_confirm') {
            $studio = require_studio();
            $token = trim((string)($_POST['import_token'] ?? ''));
            $preview = $_SESSION['calendar_import_preview'][$token] ?? null;
            if (!is_array($preview) || (int)($preview['studio_id'] ?? 0) !== (int)$studio['id']) {
                throw new RuntimeException('Previa de importacao expirada. Envie o arquivo novamente.');
            }
            $analysis = $preview['analysis'] ?? [];
            $candidates = $analysis['candidates'] ?? [];
            $selectedItems = [];
            $conflictsSkipped = 0;
            $selectedUidList = json_decode((string)($_POST['selected_uids_json'] ?? ''), true);
            $allowedConflictList = json_decode((string)($_POST['allow_conflicts_json'] ?? ''), true);
            $itemOverrides = json_decode((string)($_POST['item_overrides_json'] ?? ''), true);
            $usesJsonPayload = is_array($selectedUidList);
            $selectedUidSet = $usesJsonPayload
                ? array_fill_keys(array_filter(array_map('strval', $selectedUidList)), true)
                : [];
            $allowedConflictSet = is_array($allowedConflictList)
                ? array_fill_keys(array_filter(array_map('strval', $allowedConflictList)), true)
                : [];
            $itemOverrides = is_array($itemOverrides) ? $itemOverrides : [];
            foreach ($candidates as $candidate) {
                $uid = (string)($candidate['uid'] ?? '');
                if ($uid === '') {
                    continue;
                }
                $item = $usesJsonPayload
                    ? (is_array($itemOverrides[$uid] ?? null) ? $itemOverrides[$uid] : [])
                    : ($_POST['items'][$uid] ?? null);
                $selected = $usesJsonPayload ? isset($selectedUidSet[$uid]) : (is_array($item) && !empty($item['selected']));
                if (!$selected) {
                    continue;
                }
                $item = is_array($item) ? $item : [];
                $conflicts = $candidate['conflicts'] ?? [];
                $allowConflict = $usesJsonPayload ? isset($allowedConflictSet[$uid]) : !empty($item['allow_conflict']);
                if ($conflicts && !$allowConflict) {
                    $conflictsSkipped++;
                    continue;
                }
                $startDate = trim((string)($item['date'] ?? $candidate['date'] ?? ''));
                $startTime = trim((string)($item['start_time'] ?? $candidate['start_time'] ?? ''));
                $endTime = trim((string)($item['end_time'] ?? $candidate['end_time'] ?? ''));
                $name = trim((string)($item['name'] ?? $candidate['name'] ?? ''));
                if ($startDate === '' || $startTime === '' || $name === '') {
                    continue;
                }
                $selectedItems[] = [
                    'uid' => $uid,
                    'google_uid' => (string)($candidate['google_uid'] ?? ''),
                    'google_event_id' => (string)($candidate['google_event_id'] ?? ''),
                    'google_calendar_id' => (string)($candidate['google_calendar_id'] ?? ''),
                    'raw_title' => (string)($candidate['raw_title'] ?? ''),
                    'description_original' => (string)($candidate['description_original'] ?? ''),
                    'notes' => trim((string)($candidate['notes'] ?? '')),
                    'interest' => trim((string)($item['interest'] ?? $candidate['interest'] ?? '')),
                    'phone' => normalize_phone((string)($item['phone'] ?? $candidate['phone'] ?? '')),
                    'name' => $name,
                    'value' => money_to_float((string)($item['value'] ?? $candidate['value'] ?? 0)),
                    'date' => $startDate,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'appointment_status' => (string)($item['appointment_status'] ?? $candidate['appointment_status'] ?? 'confirmado'),
                    'status' => (string)($item['status'] ?? $candidate['status'] ?? 'agendado'),
                    'pipeline_stage' => (string)($item['pipeline_stage'] ?? $candidate['pipeline_stage'] ?? 'agendado'),
                    'lead_score' => (int)($item['lead_score'] ?? $candidate['lead_score'] ?? 6),
                    'deposit_value' => (float)($candidate['deposit_value'] ?? 0),
                    'ai_title_parse' => is_array($candidate['ai_title_parse'] ?? null) ? $candidate['ai_title_parse'] : [],
                    'ai_review_required' => (int)($candidate['ai_review_required'] ?? 0),
                    'ai_parse_confidence' => $candidate['ai_parse_confidence'] ?? null,
                    'ai_parse_summary' => (string)($candidate['ai_parse_summary'] ?? ''),
                    'ai_parse_payload' => (string)($candidate['ai_parse_payload'] ?? ''),
                    'allow_conflict' => $allowConflict,
                ];
            }
            if (!$selectedItems) {
                $message = $conflictsSkipped > 0
                    ? 'Nenhum evento foi importado porque todos os itens selecionados têm conflito. Marque "Importar mesmo assim" nos eventos que deseja manter.'
                    : 'Selecione pelo menos um evento válido para importar.';
                flash_set('error', $message);
                redirect_to('studio_agenda', ['ics_preview' => $token]);
            }
            $result = studio_import_calendar_events($studio, $selectedItems);
            if (!empty($result['created_uids'])) {
                $_SESSION['calendar_import_last_batch'] = [
                    'studio_id' => (int)$studio['id'],
                    'uids' => array_values(array_map('strval', $result['created_uids'])),
                    'created_at' => time(),
                ];
            } else {
                unset($_SESSION['calendar_import_last_batch']);
            }
            unset($_SESSION['calendar_import_preview'][$token]);
            $message = 'Sincronização concluída: '
                . (int)$result['appointments_created']
                . ' criados, '
                . (int)($result['appointments_updated'] ?? 0)
                . ' atualizados e '
                . (int)$result['duplicates_skipped']
                . ' já estavam iguais.';
            if ($conflictsSkipped > 0) {
                $message .= ' ' . $conflictsSkipped . ' conflito(s) não foram importados.';
            }
            studio_event((int)$studio['id'], 'calendar_ics_imported', $message, [
                'category' => 'agenda',
                'context' => [
                    'appointments_created' => (int)$result['appointments_created'],
                    'appointments_updated' => (int)($result['appointments_updated'] ?? 0),
                    'duplicates_skipped' => (int)$result['duplicates_skipped'],
                    'conflicts_skipped' => $conflictsSkipped,
                    'selected_count' => count($selectedItems),
                ],
            ]);
            flash_set('success', $message);
            redirect_to('studio_agenda', [
                'cal_view' => 'month',
                'date' => (string)($selectedItems[0]['date'] ?? date('Y-m-d')),
            ]);
        }

        if ($action === 'undo_calendar_import') {
            $studio = require_studio();
            $batch = $_SESSION['calendar_import_last_batch'] ?? null;
            if (!is_array($batch) || (int)($batch['studio_id'] ?? 0) !== (int)$studio['id']) {
                throw new RuntimeException('Nao existe uma importacao recente para desfazer.');
            }
            $result = studio_revert_import_calendar_events($studio, $batch['uids'] ?? []);
            unset($_SESSION['calendar_import_last_batch']);
            flash_set(
                'success',
                'Importação desfeita: '
                . (int)($result['appointments_deleted'] ?? 0)
                . ' agendamentos, '
                . (int)($result['leads_deleted'] ?? 0)
                . ' leads e '
                . (int)($result['customers_deleted'] ?? 0)
                . ' clientes de teste removidos.'
            );
            studio_event((int)$studio['id'], 'calendar_import_undone', 'Importação de calendário desfeita.', [
                'category' => 'agenda',
                'context' => $result,
            ]);
            redirect_to('studio_agenda');
        }

        if ($action === 'google_calendar_sync_now') {
            $studio = require_studio();
            $result = google_calendar_sync_studio($studio);
            studio_event((int)$studio['id'], 'google_calendar_synced', 'Google Agenda sincronizado: ' . (string)($result['message'] ?? ''), [
                'category' => 'agenda',
                'context' => $result,
            ]);
            flash_set('success', 'Google Agenda sincronizado: ' . $result['message']);
            redirect_to('studio_agenda');
        }

        if ($action === 'google_calendar_toggle') {
            $studio = require_studio();
            $enabled = !empty($_POST['enabled']);
            google_calendar_set_enabled($studio, $enabled);
            studio_event((int)$studio['id'], 'google_calendar_toggled', $enabled ? 'Sincronização automática do Google Agenda ativada.' : 'Sincronização automática do Google Agenda pausada.', [
                'category' => 'agenda',
                'context' => ['enabled' => $enabled],
            ]);
            flash_set('success', $enabled ? 'Sincronização automática ativada.' : 'Sincronização automática pausada.');
            redirect_to('studio_agenda');
        }

        if ($action === 'google_calendar_outbound_toggle') {
            $studio = require_studio();
            $enabled = !empty($_POST['outbound_enabled']);
            google_calendar_set_outbound_enabled($studio, $enabled);
            studio_event((int)$studio['id'], 'google_calendar_outbound_toggled', $enabled ? 'Envio CRM -> Google ativado.' : 'Envio CRM -> Google desativado.', [
                'category' => 'agenda',
                'context' => ['outbound_enabled' => $enabled],
            ]);
            flash_set(
                'success',
                $enabled
                    ? 'Envio CRM -> Google ativado. Para gravar no Google, reconecte a conta com permissões de escrita.'
                    : 'Envio CRM -> Google desativado.'
            );
            redirect_to('studio_agenda');
        }

        if ($action === 'google_calendar_select') {
            $studio = require_studio();
            google_calendar_select($studio, trim((string)($_POST['calendar_id'] ?? '')));
            google_calendar_set_enabled($studio, true);
            $result = google_calendar_sync_studio($studio, true);
            studio_event((int)$studio['id'], 'google_calendar_selected', 'Calendário Google alterado e sincronizado: ' . (string)($result['message'] ?? ''), [
                'category' => 'agenda',
                'context' => ['calendar_id' => (string)($_POST['calendar_id'] ?? ''), 'sync' => $result],
            ]);
            flash_set('success', 'Calendário alterado e sincronizado: ' . $result['message']);
            redirect_to('studio_agenda');
        }

        if ($action === 'google_calendar_disconnect') {
            $studio = require_studio();
            google_calendar_disconnect($studio);
            studio_event((int)$studio['id'], 'google_calendar_disconnected', 'Conta Google desconectada. Agendamentos importados foram preservados.', [
                'category' => 'agenda',
            ]);
            flash_set('success', 'Conta Google desconectada. Os agendamentos já importados foram preservados.');
            redirect_to('studio_agenda');
        }

        if ($action === 'save_artist') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem gerenciar tatuadores.');
            }
            $artistId = studio_save_artist($studio, $_POST);
            studio_event((int)$studio['id'], 'artist_saved', 'Tatuador salvo: ' . trim((string)($_POST['name'] ?? 'Tatuador')), [
                'category' => 'agenda',
                'target_type' => 'artist',
                'target_id' => $artistId,
                'context' => ['active' => !empty($_POST['is_active'])],
            ]);
            flash_set('success', 'Tatuador salvo.');
            redirect_to('studio_artists', ['artist_id' => $artistId]);
        }

        if ($action === 'save_expense') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem gerenciar o financeiro.');
            }
            $expenseId = studio_save_expense($studio, $_POST);
            studio_event((int)$studio['id'], 'expense_saved', 'Despesa salva: ' . trim((string)($_POST['description'] ?? 'Despesa')), [
                'category' => 'finance',
                'target_type' => 'expense',
                'target_id' => $expenseId,
                'context' => ['date' => (string)($_POST['expense_date'] ?? ''), 'amount' => (string)($_POST['amount'] ?? '')],
            ]);
            flash_set('success', 'Despesa salva.');
            redirect_to('studio_finance');
        }

        if ($action === 'delete_expense') {
            $studio = require_studio();
            studio_delete_expense($studio, (int)($_POST['id'] ?? 0));
            flash_set('success', 'Despesa excluída.');
            redirect_to('studio_finance');
        }

        if ($action === 'save_quick_reply') {
            $studio = require_studio();
            $expectsJson = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
                || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
            $id = studio_save_quick_reply($studio, $_POST);
            if ($expectsJson) {
                $user = current_studio_user();
                $replies = studio_list_quick_replies($studio, (int)($user['id'] ?? 0));
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'id' => $id, 'quick_replies' => studio_quick_replies_payload($replies)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            flash_set('success', 'Resposta rapida salva.');
            if (!empty($_POST['return_to_settings'])) {
                redirect_to('studio_settings', ['tab' => (string)($_POST['settings_tab'] ?? 'quick_replies')]);
            }
            redirect_to('studio_quick_replies');
        }

        if ($action === 'delete_quick_reply') {
            $studio = require_studio();
            csrf_verify();
            $expectsJson = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
                || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
            studio_delete_quick_reply($studio, (int)($_POST['id'] ?? 0));
            if ($expectsJson) {
                $user = current_studio_user();
                $replies = studio_list_quick_replies($studio, (int)($user['id'] ?? 0));
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'quick_replies' => studio_quick_replies_payload($replies)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            flash_set('success', 'Resposta rapida excluida.');
            redirect_to('studio_quick_replies');
        }

        if ($action === 'save_whatsapp_tag') {
            $studio = require_studio();
            studio_save_whatsapp_tag($studio, $_POST);
            flash_set('success', 'Tag salva.');
            redirect_to('studio_whatsapp_tags');
        }

        if ($action === 'delete_whatsapp_tag') {
            $studio = require_studio();
            studio_delete_whatsapp_tag($studio, (int)($_POST['id'] ?? 0));
            flash_set('success', 'Tag excluída.');
            redirect_to('studio_whatsapp_tags');
        }

        if ($action === 'toggle_whatsapp_conversation_tag') {
            $studio = require_studio();
            $conversationId = (int)($_POST['conversation_id'] ?? 0);
            studio_toggle_whatsapp_conversation_tag($studio, $conversationId, (int)($_POST['tag_id'] ?? 0));
            redirect_to(!empty($_POST['return_to_workspace']) ? 'studio_whatsapp_mobile' : 'studio_whatsapp_conversation', $conversationId > 0 ? ['id' => $conversationId] : []);
        }

        if ($action === 'start_whatsapp_session') {
            $studio = require_studio();
            $result = studio_start_whatsapp_session($studio);
            if (empty($result['ok'])) {
                $error = (string)($result['error'] ?? 'Nao foi possivel iniciar o WhatsApp.');
                if (!empty($result['health_error'])) {
                    $error .= ' Health: ' . (string)$result['health_error'];
                }
                if (!empty($result['current_version']) || !empty($result['expected_version'])) {
                    $error .= ' Versao atual: ' . (string)($result['current_version'] ?? '') . ' Esperada: ' . (string)($result['expected_version'] ?? '');
                }
                if (!empty($result['log_tail'])) {
                    $error .= ' Log: ' . mb_substr((string)$result['log_tail'], -500);
                }
                if (!empty($result['auto_start']['error'])) {
                    $error .= ' Tentativa automatica: ' . (string)$result['auto_start']['error'];
                    if (!empty($result['auto_start']['health_error'])) {
                        $error .= ' Health: ' . (string)$result['auto_start']['health_error'];
                    }
                    if (!empty($result['auto_start']['install_output'])) {
                        $error .= ' Install: ' . mb_substr((string)$result['auto_start']['install_output'], 0, 250);
                    }
                    if (!empty($result['auto_start']['log_tail'])) {
                        $error .= ' Log: ' . mb_substr((string)$result['auto_start']['log_tail'], -500);
                    }
                }
                throw new RuntimeException($error);
            }
            flash_set('success', 'Pedido enviado. O WhatsApp vai mostrar o codigo quando o servico responder.');
            redirect_to('studio_whatsapp');
        }

        if ($action === 'disconnect_whatsapp_session') {
            $studio = require_studio();
            $result = studio_disconnect_whatsapp_session($studio);
            if (empty($result['ok'])) {
                throw new RuntimeException((string)($result['error'] ?? 'Nao foi possivel desconectar o WhatsApp.'));
            }
            flash_set('success', 'Desconexao do WhatsApp solicitada.');
            redirect_to('studio_whatsapp');
        }

        if ($action === 'restart_whatsapp_service') {
            $studio = require_studio();
            $result = studio_restart_whatsapp_service($studio);
            if (empty($result['ok'])) {
                $error = (string)($result['error'] ?? 'Nao foi possivel reiniciar o servico WhatsApp.');
                if (!empty($result['current_version']) || !empty($result['expected_version'])) {
                    $error .= ' Versao atual: ' . (string)($result['current_version'] ?? '') . ' Esperada: ' . (string)($result['expected_version'] ?? '');
                }
                if (!empty($result['health_error'])) {
                    $error .= ' Health: ' . (string)$result['health_error'];
                }
                if (!empty($result['log_tail'])) {
                    $error .= ' Log: ' . mb_substr((string)$result['log_tail'], -500);
                }
                throw new RuntimeException($error);
            }
            flash_set('success', 'Reinicio do servico WhatsApp solicitado.');
            redirect_to('studio_whatsapp');
        }

        if ($action === 'request_whatsapp_pairing_code') {
            $studio = require_studio();
            $result = studio_request_whatsapp_pairing_code($studio, (string)($_POST['pairing_phone'] ?? ''));
            if (empty($result['ok'])) {
                $error = (string)($result['error'] ?? 'Nao foi possivel gerar o codigo de pareamento.');
                if (!empty($result['health_error'])) {
                    $error .= ' Health: ' . (string)$result['health_error'];
                }
                if (!empty($result['current_version']) || !empty($result['expected_version'])) {
                    $error .= ' Versao atual: ' . (string)($result['current_version'] ?? '') . ' Esperada: ' . (string)($result['expected_version'] ?? '');
                }
                if (!empty($result['log_tail'])) {
                    $error .= ' Log: ' . mb_substr((string)$result['log_tail'], -500);
                }
                throw new RuntimeException($error);
            }
            flash_set('success', 'Codigo solicitado. Aguarde a resposta do servico e o codigo vai aparecer na tela.');
            redirect_to('studio_whatsapp');
        }

        if ($action === 'reset_whatsapp_session') {
            $studio = require_studio();
            $result = studio_reset_whatsapp_session($studio);
            if (empty($result['ok'])) {
                $error = (string)($result['error'] ?? 'Nao foi possivel limpar a sessao do WhatsApp.');
                if (!empty($result['local_reset']['error'])) {
                    $error .= ' Limpeza local: ' . (string)$result['local_reset']['error'];
                }
                if (!empty($result['service_error'])) {
                    $error .= ' Servico: ' . (string)$result['service_error'];
                }
                throw new RuntimeException($error);
            }
            flash_set('success', 'Limpeza da sessao WhatsApp solicitada. Acompanhe pelo log ao vivo.');
            redirect_to('studio_whatsapp');
        }

        if ($action === 'send_whatsapp_template') {
            $studio = require_studio();
            if (function_exists('crm_whatsapp_official_apply_defaults')) {
                crm_whatsapp_official_apply_defaults($studio);
            }
            $conversationId = (int)($_POST['conversation_id'] ?? $_GET['id'] ?? 0);
            $templatePost = $_POST;
            $templatePost['enforce_assignment'] = true;
            $result = studio_send_whatsapp_official_template_message($studio, $templatePost);
            if (empty($result['ok'])) {
                $error = studio_whatsapp_send_error_message($result);
                flash_set('error', $error);
            } else {
                $messageId = (string)($result['messageId'] ?? '');
                flash_set('success', 'Template enviado pela API oficial do WhatsApp.' . ($messageId !== '' ? ' ID: ' . $messageId : ''));
            }
            if (!empty($_POST['return_to_workspace'])) {
                redirect_to('studio_whatsapp_mobile', $conversationId > 0 ? ['id' => $conversationId] : []);
            }
            if ($conversationId > 0) {
                redirect_to('studio_whatsapp_conversation', ['id' => $conversationId]);
            }
            redirect_to('studio_whatsapp');
        }

        if ($action === 'send_whatsapp_interactive') {
            $studio = require_studio();
            $conversationId = (int)($_POST['conversation_id'] ?? 0);
            $payload = $_POST;
            $payload['enforce_assignment'] = true;
            $result = studio_send_whatsapp_official_message($studio, $payload);
            if (empty($result['ok'])) {
                flash_set('error', studio_whatsapp_send_error_message($result));
            } else {
                flash_set('success', 'Mensagem interativa enviada pela API oficial.');
            }
            redirect_to(!empty($_POST['return_to_workspace']) ? 'studio_whatsapp_mobile' : 'studio_whatsapp_conversation', $conversationId > 0 ? ['id' => $conversationId] : []);
        }

        if ($action === 'save_whatsapp_sticker') {
            $studio = require_studio();
            $user = current_studio_user();
            header('Content-Type: application/json; charset=utf-8');
            if (!$user) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => 'Faça login para salvar figurinhas.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $result = studio_save_whatsapp_sticker_from_message($studio, (int)$user['id'], (int)($_POST['message_id'] ?? 0));
            if (empty($result['ok'])) {
                http_response_code(400);
            }
            $result['stickers'] = array_map('studio_whatsapp_sticker_payload', studio_list_whatsapp_stickers($studio, (int)$user['id']));
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'send_whatsapp_sticker') {
            $studio = require_studio();
            $user = current_studio_user();
            header('Content-Type: application/json; charset=utf-8');
            if (!$user) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => 'Faça login para enviar figurinhas.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            if (function_exists('crm_whatsapp_official_apply_defaults')) {
                crm_whatsapp_official_apply_defaults($studio);
            }
            $conversationId = (int)($_POST['conversation_id'] ?? $_GET['id'] ?? 0);
            $conversation = $conversationId > 0 ? studio_find_whatsapp_conversation($studio, $conversationId) : null;
            if (!$conversation) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Conversa não encontrada.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            if (!studio_can_send_whatsapp_conversation($studio, $conversation, $user)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Você precisa assumir a conversa para enviar figurinhas.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $result = studio_send_whatsapp_saved_sticker($studio, $conversation, (int)$user['id'], (int)($_POST['sticker_id'] ?? 0));
            if (empty($result['ok'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => studio_whatsapp_send_error_message($result), 'stickers' => array_map('studio_whatsapp_sticker_payload', studio_list_whatsapp_stickers($studio, (int)$user['id']))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            echo json_encode(['ok' => true, 'message_id' => (string)($result['messageId'] ?? ''), 'conversation_id' => (int)($result['conversation_id'] ?? $conversationId), 'stickers' => array_map('studio_whatsapp_sticker_payload', studio_list_whatsapp_stickers($studio, (int)$user['id']))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'send_whatsapp_message') {
            $studio = require_studio();
            $wantsWhatsappJson = !empty($_POST['return_to_mobile'])
                || !empty($_POST['return_to_mobile2'])
                || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

            if (function_exists('crm_whatsapp_official_apply_defaults')) {
                crm_whatsapp_official_apply_defaults($studio);
            }

            $settings = studio_settings($studio);
            $provider = (string)($settings['whatsapp_provider'] ?? 'official');

            if ($provider === 'official') {
                $conversationId = (int)($_POST['conversation_id'] ?? $_GET['id'] ?? 0);
                $officialPost = $_POST;
                $officialPost['enforce_assignment'] = true;
                $result = studio_send_whatsapp_official_message($studio, $officialPost);
                if (empty($result['ok'])) {
                    $error = studio_whatsapp_send_error_message($result);
                    flash_set('error', $error);
                    if ($wantsWhatsappJson) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        exit;
                    }
                    if (!empty($_POST['return_to_mobile']) || !empty($_POST['return_to_mobile2'])) {
                        redirect_to('studio_whatsapp_mobile', $conversationId > 0 ? ['id' => $conversationId] : []);
                    }
                    if (!empty($_POST['return_to_workspace'])) {
                        redirect_to('studio_whatsapp_mobile', $conversationId > 0 ? ['id' => $conversationId] : []);
                    }
                    redirect_to($conversationId > 0 ? 'studio_whatsapp_conversation' : 'studio_whatsapp', $conversationId > 0 ? ['id' => $conversationId] : []);
                }

                $messageId = (string)($result['messageId'] ?? '');
                flash_set('success', 'Mensagem enviada pela API oficial do WhatsApp.' . ($messageId !== '' ? ' ID: ' . $messageId : ''));
                if ($wantsWhatsappJson) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'message_id' => $messageId, 'conversation_id' => (int)($result['conversation_id'] ?? $conversationId)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }
                if (!empty($_POST['return_to_mobile']) || !empty($_POST['return_to_mobile2'])) {
                    redirect_to('studio_whatsapp_mobile', $conversationId > 0 ? ['id' => $conversationId] : []);
                }
                if (!empty($_POST['return_to_workspace'])) {
                    redirect_to('studio_whatsapp_mobile', $conversationId > 0 ? ['id' => $conversationId] : []);
                }
                redirect_to($conversationId > 0 ? 'studio_whatsapp_conversation' : 'studio_whatsapp', $conversationId > 0 ? ['id' => $conversationId] : []);

                $conversationId = (int)($_POST['conversation_id'] ?? $_GET['id'] ?? 0);
                $conversation = $conversationId > 0 ? studio_find_whatsapp_conversation($studio, $conversationId) : null;

                $phone = normalize_phone((string)(
                    $_POST['to_phone']
                    ?? $_POST['phone']
                    ?? $_POST['numero']
                    ?? $_POST['recipient_number']
                    ?? ''
                ));

                if ($phone === '' && is_array($conversation)) {
                    $phone = normalize_phone((string)($conversation['phone'] ?? ''));
                }

                $message = trim((string)(
                    $_POST['message']
                    ?? $_POST['mensagem']
                    ?? $_POST['body']
                    ?? $_POST['text']
                    ?? $_POST['message_text']
                    ?? ''
                ));
                $upload = studio_prepare_whatsapp_attachment($studio, $_POST, $_FILES ?? [], $conversationId);

                if ($phone === '' || ($message === '' && empty($upload['base64']))) {
                    $details = 'conversation_id=' . $conversationId
                        . ' | phone=' . $phone
                        . ' | message_length=' . strlen($message)
                        . ' | has_upload=' . (!empty($upload['base64']) ? '1' : '0')
                        . ' | post_keys=' . implode(',', array_keys($_POST));

                    flash_set('error', 'Faltou telefone, mensagem ou anexo para enviar pela API oficial. ' . $details);

                    if ($wantsWhatsappJson) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['ok' => false, 'error' => 'missing_phone_message_or_attachment'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        exit;
                    }
                    if ($conversationId > 0) {
                        redirect_to('studio_whatsapp_mobile', ['id' => $conversationId]);
                    }

                    redirect_to('studio_whatsapp');
                }

                $result = !empty($upload['base64'])
                    ? studio_whatsapp_official_send_media($studio, $phone, $upload, $message)
                    : studio_whatsapp_official_send_text($studio, $phone, $message);

                if (empty($result['ok'])) {
                    $error = (string)($result['error'] ?? 'Nao foi possível enviar pela API oficial.');

                    if (!empty($result['status'])) {
                        $error .= ' | HTTP ' . (string)$result['status'];
                    }

                    if (!empty($result['json']['error']['message'])) {
                        $error .= ' | ' . (string)$result['json']['error']['message'];
                    }

                    if (!empty($result['json']['error']['error_data']['details'])) {
                        $error .= ' | ' . (string)$result['json']['error']['error_data']['details'];
                    }

                    if (!empty($result['diagnostic']) && is_array($result['diagnostic'])) {
                        $diag = $result['diagnostic'];
                        $error .= ' | source: ' . (string)($diag['source'] ?? '');
                        $error .= ' | phone_number_id: ' . (string)($diag['zap_local_config']['phone_number_id'] ?? $diag['crm']['phone_number_id'] ?? '');
                        $error .= ' | to_phone: ' . (string)($diag['send']['to_phone'] ?? '');
                        $error .= ' | message_length: ' . (string)($diag['send']['message_length'] ?? '');
                    }

                    flash_set('error', $error);

                    if ($wantsWhatsappJson) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        exit;
                    }
                    if ($conversationId > 0) {
                        redirect_to('studio_whatsapp_mobile', ['id' => $conversationId]);
                    }

                    redirect_to('studio_whatsapp');
                }

                $json = is_array($result['json'] ?? null) ? $result['json'] : [];
                $messageId = (string)($json['messages'][0]['id'] ?? '');

                studio_record_whatsapp_message($studio, [
                    'numero' => $phone,
                    'mensagem' => $message,
                    'fromMe' => true,
                    'senderType' => 'human',
                    'messageId' => $messageId,
                    'remoteJid' => $phone,
                    'timestamp' => time(),
                    'tipoMensagem' => !empty($upload['kind']) ? $upload['kind'] : 'texto',
                    'mediaUrl' => $upload['relativePath'] ?? '',
                    'mediaMime' => $upload['mime'] ?? '',
                    'mediaFileName' => $upload['fileName'] ?? '',
                ]);

                flash_set('success', 'Mensagem enviada pela API oficial do WhatsApp.' . ($messageId !== '' ? ' ID: ' . $messageId : ''));

                if ($wantsWhatsappJson) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'message_id' => $messageId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }
                if ($conversationId > 0) {
                    redirect_to('studio_whatsapp_mobile', ['id' => $conversationId]);
                }

                redirect_to('studio_whatsapp');
            }

            studio_send_whatsapp_message($studio, $_POST);
            flash_set('success', 'Mensagem enviada pelo WhatsApp.');
            if ($wantsWhatsappJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'conversation_id' => !empty($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            if (!empty($_POST['return_to_workspace'])) {
                redirect_to('studio_whatsapp_mobile', !empty($_POST['conversation_id']) ? ['id' => (int)$_POST['conversation_id']] : []);
            }
            if (!empty($_POST['conversation_id'])) {
                redirect_to('studio_whatsapp_conversation', ['id' => (int)$_POST['conversation_id']]);
            }
            redirect_to('studio_whatsapp');
        }

        if ($action === 'whatsapp_ai_suggestions') {
            $studio = require_studio();
            csrf_verify();
            $conversationId = (int)($_POST['conversation_id'] ?? 0);
            $conversation = $conversationId > 0 ? studio_find_whatsapp_conversation($studio, $conversationId) : null;
            $currentUser = current_studio_user();
            if (!$conversation || !$currentUser || !studio_can_send_whatsapp_conversation($studio, $conversation, $currentUser)) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Conversa indisponivel para seu usuario.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $messages = studio_whatsapp_messages($studio, $conversationId, 120, $conversation);
            $payload = studio_whatsapp_ai_suggestions($studio, $conversation, $messages);

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'mark_whatsapp_read' || $action === 'mark_whatsapp_unread') {
            $studio = require_studio();
            $conversationId = (int)($_POST['conversation_id'] ?? 0);
            if ($action === 'mark_whatsapp_read') {
                studio_whatsapp_mark_read($studio, $conversationId);
                flash_set('success', 'Conversa marcada como lida.');
            } else {
                studio_whatsapp_mark_unread($studio, $conversationId);
                flash_set('success', 'Conversa marcada como nao lida.');
            }
            redirect_to('studio_whatsapp', array_filter([
                'filter' => (string)($_POST['filter'] ?? $_GET['filter'] ?? 'all'),
                'q' => (string)($_POST['q'] ?? $_GET['q'] ?? ''),
                'mode' => (string)($_POST['mode'] ?? $_GET['mode'] ?? ''),
                'needs_human' => !empty($_POST['needs_human'] ?? $_GET['needs_human']) ? 1 : null,
                'min_score' => (int)($_POST['min_score'] ?? $_GET['min_score'] ?? 0) ?: null,
            ], static fn($value) => $value !== null && $value !== ''));
        }

        if ($action === 'mobile_mark_whatsapp_read' || $action === 'mobile_mark_whatsapp_unread') {
            $studio = require_studio();
            $conversationId = (int)($_POST['conversation_id'] ?? 0);
            if ($action === 'mobile_mark_whatsapp_read') {
                studio_whatsapp_mark_read($studio, $conversationId);
                flash_set('success', 'Conversa marcada como lida.');
            } else {
                studio_whatsapp_mark_unread($studio, $conversationId);
                flash_set('success', 'Conversa marcada como nao lida.');
            }
            if (!empty($_POST['return_to_mobile2'])) {
                redirect_to('studio_whatsapp_mobile', array_filter([
                    'id' => $conversationId > 0 ? $conversationId : null,
                ], static fn($value) => $value !== null && $value !== ''));
            }
            redirect_to('studio_whatsapp_mobile', array_filter([
                'id' => $conversationId > 0 ? $conversationId : null,
                'q' => (string)($_POST['q'] ?? $_GET['q'] ?? ''),
                'filter' => (string)($_POST['filter'] ?? $_GET['filter'] ?? ''),
                'visibility' => 'all',
            ], static fn($value) => $value !== null && $value !== ''));
        }

        if ($action === 'mobile_delete_whatsapp_conversations') {
            csrf_verify();
            $studio = require_studio();
            $currentUser = current_studio_user();
            if (!$currentUser || !studio_current_user_is_admin()) {
                flash_set('error', 'Apenas administradores podem excluir conversas.');
                redirect_to('studio_whatsapp_mobile');
            }

            $conversationIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['conversation_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
            $result = studio_delete_whatsapp_conversations($studio, $conversationIds, $currentUser);
            if (empty($result['ok'])) {
                flash_set('error', (string)($result['error'] ?? 'Nao foi possivel excluir as conversas.'));
            } else {
                $successParts = [(int)($result['deleted_conversations'] ?? 0) . ' conversas excluidas'];
                if ((int)($result['deleted_leads'] ?? 0) > 0) {
                    $successParts[] = (int)$result['deleted_leads'] . ' leads apagados';
                }
                if ((int)($result['detached_appointments'] ?? 0) > 0) {
                    $successParts[] = (int)$result['detached_appointments'] . ' agendamentos preservados e desvinculados do lead';
                }
                flash_set('success', implode(', ', $successParts) . '.');
            }

            redirect_to('studio_whatsapp_mobile', array_filter([
                'filter' => (string)($_POST['filter'] ?? $_GET['filter'] ?? 'all'),
                'q' => (string)($_POST['q'] ?? $_GET['q'] ?? ''),
                'visibility' => (string)($_POST['visibility'] ?? $_GET['visibility'] ?? 'all'),
                'date_filter' => (string)($_POST['date_filter'] ?? $_GET['date_filter'] ?? ''),
                'date_from' => (string)($_POST['date_from'] ?? $_GET['date_from'] ?? ''),
                'date_to' => (string)($_POST['date_to'] ?? $_GET['date_to'] ?? ''),
            ], static fn($value) => $value !== null && $value !== ''));
        }

        if ($action === 'update_whatsapp_conversation') {
            $studio = require_studio();
            studio_update_whatsapp_conversation($studio, $_POST);
            flash_set('success', 'Conversa atualizada.');
            if (!empty($_POST['return_to_workspace'])) {
                redirect_to('studio_whatsapp_mobile', ['id' => (int)($_POST['conversation_id'] ?? 0)]);
            }
            redirect_to('studio_whatsapp_conversation', ['id' => (int)($_POST['conversation_id'] ?? 0)]);
        }

        if ($action === 'toggle_whatsapp_ai_mode') {
            $studio = require_studio();
            csrf_verify();
            $conversationId = (int)($_POST['conversation_id'] ?? 0);
            $conversation = $conversationId > 0 ? studio_find_whatsapp_conversation($studio, $conversationId) : null;
            $user = current_studio_user();
            $isAdmin = studio_current_user_is_admin();
            $expectsJson = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
                || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
            if (!$conversation || !$user) {
                $error = 'Conversa invalida.';
            } elseif (!$isAdmin && !studio_can_send_whatsapp_conversation($studio, $conversation, $user)) {
                $error = 'Conversa indisponivel para seu usuario.';
            } else {
                $currentMode = (string)($conversation['attendance_mode'] ?? 'human');
                $nextMode = (string)($_POST['attendance_mode'] ?? $_POST['mode'] ?? '');
                if (!in_array($nextMode, ['human', 'bot'], true)) {
                    $nextMode = $currentMode === 'bot' ? 'human' : 'bot';
                }
                $status = $nextMode === 'bot' ? 'IA pronta para responder' : 'IA desativada para esta conversa';
                studio_update_whatsapp_conversation($studio, [
                    'conversation_id' => $conversationId,
                    'attendance_mode' => $nextMode,
                    'needs_human' => $nextMode === 'bot' ? 0 : 1,
                    'ai_last_status' => $status,
                    'ai_last_at' => date('Y-m-d H:i:s'),
                ]);
                if ($expectsJson) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'ok' => true,
                        'conversation_id' => $conversationId,
                        'attendance_mode' => $nextMode,
                        'ai_last_status' => $status,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }
                flash_set('success', $status . '.');
                redirect_to('studio_whatsapp_conversation', ['id' => $conversationId]);
            }
            if ($expectsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            flash_set('error', $error);
            redirect_to('studio_whatsapp_conversation', ['id' => $conversationId]);
        }

        if ($action === 'update_whatsapp_profile') {
            $studio = require_studio();
            $result = studio_update_whatsapp_profile($studio, $_POST);
            flash_set('success', 'Cadastro, lead e conversa atualizados.');
            $expectsJson = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
                || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
            if ($expectsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            if (!empty($_POST['return_to_mobile2'])) {
                redirect_to('studio_whatsapp_mobile', array_filter([
                    'id' => (int)($_POST['conversation_id'] ?? 0) ?: null,
                ], static fn($value) => $value !== null && $value !== ''));
            }
            if (!empty($_POST['return_to_workspace'])) {
                redirect_to('studio_whatsapp_mobile', ['id' => (int)($_POST['conversation_id'] ?? 0)]);
            }
            redirect_to('studio_whatsapp_conversation', ['id' => (int)($_POST['conversation_id'] ?? 0)]);
        }

        if ($action === 'ask_studio_data_assistant') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem usar o assistente de dados.');
            }
            $expectsJson = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
                || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
            try {
                $result = studio_data_assistant_answer($studio, (string)($_POST['question'] ?? ''));
            } catch (Throwable $e) {
                if ($expectsJson) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }
                throw $e;
            }
            unset($result['context']);
            if ($expectsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $_SESSION['studio_data_assistant_result'] = $result;
            redirect_to('studio_data_assistant');
        }

        if ($action === 'generate_tattoo_reference') {
            $studio = require_studio();
            $_SESSION['studio_tattoo_image_form'] = [
                'prompt' => trim((string)($_POST['prompt'] ?? '')),
                'style' => trim((string)($_POST['style'] ?? 'realistic')),
                'format' => trim((string)($_POST['format'] ?? 'vertical')),
                'mode' => trim((string)($_POST['mode'] ?? 'final')),
                'composition' => trim((string)($_POST['composition'] ?? 'reference')),
                'reference_notes' => trim((string)($_POST['reference_notes'] ?? '')),
                'negative_prompt' => trim((string)($_POST['negative_prompt'] ?? '')),
                'upscale' => !empty($_POST['upscale']) ? '1' : '',
                'upscale_factor' => trim((string)($_POST['upscale_factor'] ?? '4')),
            ];
            $_SESSION['studio_tattoo_image_job'] = studio_start_tattoo_reference_generation($studio, $_POST);
            flash_set('success', 'A IA local começou a criar sua imagem.');
            redirect_to('studio_tattoo_images');
        }

        if ($action === 'save_studio_settings') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem alterar configurações.');
            }
            studio_save_settings($studio, $_POST);
            $settingsTab = (string)($_POST['settings_tab'] ?? 'studio');
            studio_event((int)$studio['id'], 'studio_settings_updated', 'Configurações salvas no painel "' . $settingsTab . '".', [
                'category' => 'settings',
                'target_type' => 'studio_settings',
                'target_id' => 1,
                'context' => [
                    'settings_tab' => $settingsTab,
                    'changed_fields' => array_values(array_filter(array_keys($_POST), static fn($key): bool => !in_array((string)$key, ['csrf_token', 'action'], true))),
                ],
            ]);
            flash_set('success', $settingsTab === 'rules'
                ? 'Treinamento salvo. A nova base já vale para as próximas respostas da IA.'
                : 'Configuracoes salvas.');
            redirect_to('studio_settings', ['tab' => $settingsTab]);
        }

        if ($action === 'save_whatsapp_service_flow') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem alterar o roteiro de atendimento.');
            }
            $definition = json_decode((string)($_POST['flow_definition'] ?? ''), true);
            if (!is_array($definition)) {
                throw new RuntimeException('O navegador não enviou um fluxograma válido. Recarregue a página e tente novamente.');
            }
            $user = studio_current_user();
            studio_whatsapp_service_flow_save($studio, $definition, (int)($user['id'] ?? 0));
            studio_event((int)$studio['id'], 'whatsapp_service_flow_updated', 'Roteiro rígido do WhatsApp atualizado.', [
                'category' => 'settings',
                'target_type' => 'whatsapp_ai_flow',
                'target_id' => 1,
                'context' => ['steps' => count((array)($definition['steps'] ?? [])), 'enabled' => !empty($definition['enabled'])],
            ]);
            flash_set('success', 'Fluxograma salvo. As próximas respostas já seguirão este roteiro.');
            redirect_to('studio_whatsapp_flow');
        }

        if ($action === 'reset_whatsapp_service_flow') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem restaurar o roteiro de atendimento.');
            }
            $user = studio_current_user();
            studio_whatsapp_service_flow_reset($studio, (int)($user['id'] ?? 0));
            studio_event((int)$studio['id'], 'whatsapp_service_flow_reset', 'Roteiro rígido do WhatsApp restaurado para o modelo recomendado.', [
                'category' => 'settings',
                'target_type' => 'whatsapp_ai_flow',
                'target_id' => 1,
            ]);
            flash_set('success', 'Roteiro recomendado restaurado.');
            redirect_to('studio_whatsapp_flow');
        }

        if ($action === 'generate_ai_team_playbook') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem gerar playbook da IA.');
            }
            csrf_verify();
            $result = studio_generate_ai_team_playbook($studio);
            if (empty($result['ok'])) {
                flash_set('error', (string)($result['error'] ?? 'Nao foi possivel gerar os playbooks da equipe.'));
            } else {
                flash_set('success', 'Playbooks da equipe atualizados com ' . (int)($result['examples'] ?? 0) . ' exemplos reais de atendimento.');
            }
            redirect_to('studio_settings', ['tab' => 'ia']);
        }

        if ($action === 'start_whatsapp_learning_job') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem importar conversas para aprendizado.');
            }
            csrf_verify();
            header('Content-Type: application/json; charset=utf-8');
            $user = studio_current_user();
            $result = studio_whatsapp_learning_create_job(
                $studio,
                trim((string)($_POST['file_name'] ?? 'export-whatsapp.zip')),
                max(0, (int)($_POST['file_size'] ?? 0)),
                (int)($user['id'] ?? 0)
            );
            echo json_encode(['ok' => true, 'job' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'whatsapp_learning_job_status') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem acompanhar esta importação.');
            }
            csrf_verify();
            header('Content-Type: application/json; charset=utf-8');
            $job = studio_whatsapp_learning_job($studio, trim((string)($_POST['job_id'] ?? '')));
            if (!$job) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Processamento ainda não localizado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['ok' => true, 'job' => $job], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'delete_whatsapp_learning_import') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem excluir aprendizados importados.');
            }
            csrf_verify();
            header('Content-Type: application/json; charset=utf-8');
            $importId = max(0, (int)($_POST['import_id'] ?? 0));
            if (!studio_whatsapp_learning_delete_import($studio, $importId)) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Aprendizado não encontrado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            studio_event((int)$studio['id'], 'ai_learning_import_deleted', 'Aprendizado importado removido da IA.', [
                'category' => 'settings',
                'target_type' => 'whatsapp_ai_learning_import',
                'target_id' => $importId,
            ]);
            echo json_encode(['ok' => true, 'import_id' => $importId], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'import_whatsapp_learning_zip') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem importar conversas para aprendizado.');
            }
            csrf_verify();
            @set_time_limit(900);
            header('Content-Type: application/json; charset=utf-8');
            $user = studio_current_user();
            $jobId = trim((string)($_POST['learning_job_id'] ?? ''));
            if (!studio_whatsapp_learning_job($studio, $jobId)) {
                $createdJob = studio_whatsapp_learning_create_job(
                    $studio,
                    (string)(($_FILES['learning_zip']['name'] ?? '') ?: 'export-whatsapp.zip'),
                    (int)($_FILES['learning_zip']['size'] ?? 0),
                    (int)($user['id'] ?? 0)
                );
                $jobId = (string)($createdJob['job_id'] ?? '');
            }
            studio_whatsapp_learning_update_job($studio, $jobId, 'processing', 'validating', 10, 'Upload recebido pelo servidor. Iniciando validação.', 20);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            try {
                $result = studio_import_whatsapp_learning_zip(
                    $studio,
                    $_FILES['learning_zip'] ?? [],
                    trim((string)($_POST['attendant_names'] ?? '')),
                    static function (array $progress) use ($studio, $jobId): void {
                        studio_whatsapp_learning_update_job(
                            $studio,
                            $jobId,
                            'processing',
                            (string)($progress['stage'] ?? 'processing'),
                            (int)($progress['progress'] ?? 10),
                            (string)($progress['detail'] ?? 'Processando conversa.'),
                            isset($progress['eta_seconds']) ? (int)$progress['eta_seconds'] : null,
                            (array)($progress['counters'] ?? [])
                        );
                    },
                    (int)($user['id'] ?? 0)
                );
                studio_whatsapp_learning_update_job(
                    $studio,
                    $jobId,
                    'completed',
                    'completed',
                    100,
                    (string)($result['summary'] ?? 'Aprendizado concluído.'),
                    0,
                    [
                        'messages_found' => (int)($result['message_count'] ?? 0),
                        'participants_found' => (int)($result['participant_count'] ?? 0),
                        'audio_found' => (int)($result['audio_count'] ?? 0),
                        'audio_completed' => (int)($result['audio_transcribed'] ?? 0),
                        'media_found' => (int)($result['media_count'] ?? 0),
                        'media_completed' => (int)($result['media_analyzed'] ?? 0),
                    ],
                    (int)($result['import_id'] ?? 0)
                );
                studio_event((int)$studio['id'], 'ai_learning_imported', 'Aprendizado da IA atualizado por export do WhatsApp.', [
                    'category' => 'settings',
                    'target_type' => 'whatsapp_ai_learning_import',
                    'target_id' => (int)($result['import_id'] ?? 0),
                    'context' => [
                        'message_count' => (int)($result['message_count'] ?? 0),
                        'participant_count' => (int)($result['participant_count'] ?? 0),
                        'audio_transcribed' => (int)($result['audio_transcribed'] ?? 0),
                        'media_analyzed' => (int)($result['media_analyzed'] ?? 0),
                    ],
                ]);
                $result['job_id'] = $jobId;
                echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (Throwable $exception) {
                $job = studio_whatsapp_learning_job($studio, $jobId);
                studio_whatsapp_learning_update_job(
                    $studio,
                    $jobId,
                    'failed',
                    'failed',
                    (int)($job['progress'] ?? 10),
                    'Processamento interrompido: ' . $exception->getMessage(),
                    null,
                    (array)($job['counters'] ?? []),
                    null,
                    $exception->getMessage()
                );
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => $exception->getMessage(), 'job_id' => $jobId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            exit;
        }

        if ($action === 'upload_voice_sample') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem enviar amostras de voz.');
            }
            csrf_verify();
            header('Content-Type: application/json; charset=utf-8');
            $result = studio_store_voice_sample_upload($studio, $_FILES['voice_sample'] ?? []);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'test_ai_voice_reply') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem testar voz da IA.');
            }
            csrf_verify();
            $text = trim((string)($_POST['voice_test_text'] ?? ''));
            if ($text === '') {
                $text = 'Oi! Tenho terça-feira, dia 14, às 10 horas. A chave Pix fica escrita na mensagem para você copiar.';
            }
            $voiceOverrides = [
                'ai_voice_reply_engine' => strtolower(trim((string)($_POST['ai_voice_reply_engine'] ?? ''))),
                'ai_voice_reply_xtts_sample_path' => trim((string)($_POST['ai_voice_reply_xtts_sample_path'] ?? '')),
                'ai_voice_reply_xtts_language' => strtolower(trim((string)($_POST['ai_voice_reply_xtts_language'] ?? ''))),
                'ai_voice_reply_voice' => trim((string)($_POST['ai_voice_reply_voice'] ?? '')),
                'ai_voice_reply_rate' => max(-10, min(10, (int)($_POST['ai_voice_reply_rate'] ?? 2))),
                'ai_voice_reply_volume' => max(0, min(100, (int)($_POST['ai_voice_reply_volume'] ?? 100))),
            ];
            header('Content-Type: application/json; charset=utf-8');
            $result = studio_whatsapp_ai_voice_generate($studio, 0, $text, $voiceOverrides);
            if (!empty($result['ok']) && is_array($result['upload'] ?? null)) {
                $result['audio_url'] = (string)($result['upload']['relativePath'] ?? '');
                unset($result['upload']['base64']);
            }
            $result['spoken_text'] = studio_whatsapp_ai_voice_spoken_text($text);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'assign_whatsapp_conversation' || $action === 'release_whatsapp_conversation' || $action === 'transfer_whatsapp_conversation') {
            csrf_verify();
            $studio = require_studio();
            $conversationId = (int)($_POST['conversation_id'] ?? $_GET['id'] ?? 0);
            $conversation = $conversationId > 0 ? studio_find_whatsapp_conversation($studio, $conversationId) : null;
            $user = current_studio_user();
            $admin = current_admin();
            $isAdmin = studio_current_user_is_admin();
            $actorId = (int)($admin['id'] ?? ($user['id'] ?? 0));
            if (!$conversation || !$user) {
                flash_set('error', 'Conversa invalida.');
            } elseif ($action === 'assign_whatsapp_conversation') {
                if (!$isAdmin && !empty($conversation['assigned_user_id']) && (int)$conversation['assigned_user_id'] !== (int)$user['id']) {
                    flash_set('error', 'Conversa atribuida a outro atendente.');
                } else {
                    studio_assign_whatsapp_conversation($studio, $conversationId, (int)$user['id'], $actorId);
                    studio_update_whatsapp_conversation($studio, [
                        'conversation_id' => $conversationId,
                        'attendance_mode' => 'human',
                        'needs_human' => 0,
                        'ai_last_status' => 'Atendente assumiu a conversa',
                        'ai_last_at' => date('Y-m-d H:i:s'),
                    ]);
                    flash_set('success', 'Conversa assumida.');
                }
            } elseif ($action === 'release_whatsapp_conversation') {
                if (!$isAdmin && (int)($conversation['assigned_user_id'] ?? 0) !== (int)$user['id']) {
                    flash_set('error', 'Voce nao pode liberar esta conversa.');
                } else {
                    studio_release_whatsapp_conversation($studio, $conversationId, $actorId);
                    flash_set('success', 'Conversa liberada.');
                }
            } elseif ($action === 'transfer_whatsapp_conversation') {
                if (!$isAdmin) {
                    flash_set('error', 'Apenas administradores podem transferir conversas.');
                } else {
                    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
                    if ($targetUserId <= 0) {
                        flash_set('error', 'Selecione um atendente de destino.');
                    } else {
                        studio_transfer_whatsapp_conversation($studio, $conversationId, $targetUserId, $actorId);
                        flash_set('success', 'Conversa transferida.');
                    }
                }
            }
            if (!empty($_POST['return_to_mobile'])) {
                redirect_to('studio_whatsapp_mobile', array_filter([
                    'id' => $conversationId > 0 ? $conversationId : null,
                    'visibility' => 'all',
                ], static fn($value) => $value !== null && $value !== ''));
            }
            if (!empty($_POST['return_to_mobile2'])) {
                redirect_to('studio_whatsapp_mobile', array_filter([
                    'id' => $conversationId > 0 ? $conversationId : null,
                ], static fn($value) => $value !== null && $value !== ''));
            }
            redirect_to('studio_whatsapp_mobile', $conversationId > 0 ? ['id' => $conversationId] : []);
        }

        if ($action === 'test_whatsapp_official') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem testar integrações.');
            }
            $_SESSION['studio_whatsapp_official_test_result'] = studio_whatsapp_official_test_connection($studio);
            studio_event((int)$studio['id'], 'whatsapp_official_connection_tested', 'Teste de conexão do WhatsApp oficial executado.', [
                'category' => 'whatsapp',
                'context' => $_SESSION['studio_whatsapp_official_test_result'],
            ]);
            flash_set('success', 'Teste do WhatsApp oficial executado.');
            redirect_to('studio_settings', ['tab' => 'whatsapp']);
        }

        if ($action === 'send_whatsapp_official_test_message') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem testar integrações.');
            }

            if (function_exists('crm_whatsapp_official_apply_defaults')) {
                crm_whatsapp_official_apply_defaults($studio);
            }

            $toPhone = (string)($_POST['to_phone'] ?? $_POST['phone'] ?? $_POST['numero'] ?? '');
            $message = (string)($_POST['message'] ?? $_POST['mensagem'] ?? '');

            $result = studio_whatsapp_official_send_text(
                $studio,
                $toPhone,
                $message
            );

            $_SESSION['studio_whatsapp_official_send_result'] = $result;
            studio_event((int)$studio['id'], empty($result['ok']) ? 'official_send_failed' : 'official_send_ok', empty($result['ok']) ? 'Teste de mensagem WhatsApp falhou.' : 'Teste de mensagem WhatsApp enviado.', [
                'category' => 'whatsapp',
                'context' => [
                    'to_phone' => preg_replace('/\D+/', '', $toPhone),
                    'message_length' => strlen($message),
                    'result' => $result,
                ],
            ]);

            if (empty($result['ok'])) {
                $errorLines = [];
                $errorLines[] = (string)($result['error'] ?? 'Falha ao enviar pela API oficial.');

                if (!empty($result['status'])) {
                    $errorLines[] = 'HTTP ' . (string)$result['status'];
                }

                if (!empty($result['json']['error']['message'])) {
                    $errorLines[] = (string)$result['json']['error']['message'];
                }

                if (!empty($result['json']['error']['error_data']['details'])) {
                    $errorLines[] = (string)$result['json']['error']['error_data']['details'];
                }

                $diagnostic = (array)($result['diagnostic'] ?? []);
                $crmDiag = (array)($diagnostic['crm'] ?? []);
                $zapDiag = (array)($diagnostic['zap_local_config'] ?? []);
                $sendDiag = (array)($diagnostic['send'] ?? []);
                $errorLines[] = 'api_version: ' . (string)($crmDiag['api_version'] ?? '');
                $errorLines[] = 'phone_number_id: ' . (string)($crmDiag['phone_number_id'] ?? '');
                $errorLines[] = 'token_preview: ' . (string)($crmDiag['token_preview'] ?? '');
                $errorLines[] = 'token_length: ' . (string)($crmDiag['token_length'] ?? '');
                $errorLines[] = 'zap_local_config_exists: ' . (!empty($zapDiag) && !empty($zapDiag['exists']) ? 'SIM' : 'NAO');
                $errorLines[] = 'zap_token_preview: ' . (string)($zapDiag['token_preview'] ?? 'vazio');
                $errorLines[] = 'zap_token_length: ' . (string)($zapDiag['token_length'] ?? '0');
                $errorLines[] = 'same_token_as_crm: ' . (!empty($zapDiag) && !empty($zapDiag['same_token_as_crm']) ? 'SIM' : 'NAO');
                $errorLines[] = 'same_phone_number_id_as_crm: ' . (!empty($zapDiag) && !empty($zapDiag['same_phone_number_id_as_crm']) ? 'SIM' : 'NAO');
                $errorLines[] = 'same_api_version_as_crm: ' . (!empty($zapDiag) && !empty($zapDiag['same_api_version_as_crm']) ? 'SIM' : 'NAO');
                if (isset($sendDiag['to_phone'])) {
                    $errorLines[] = 'to_phone: ' . (string)$sendDiag['to_phone'];
                }
                if (isset($sendDiag['message_length'])) {
                    $errorLines[] = 'message_length: ' . (string)$sendDiag['message_length'];
                }

                flash_set('error', implode(' | ', $errorLines));
            } else {
                $messageId = (string)($result['json']['messages'][0]['id'] ?? '');
                flash_set('success', 'Mensagem enviada pela API oficial.' . ($messageId !== '' ? ' ID: ' . $messageId : ''));
            }

            redirect_to('studio_settings', ['tab' => 'whatsapp']);
        }

        if ($action === 'test_meta_ads_connection') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem testar Meta Ads.');
            }
            $_SESSION['meta_ads_test_result'] = studio_meta_ads_test_connection($studio);
            flash_set('success', 'Teste da Meta Ads executado.');
            redirect_to('studio_meta_ads');
        }

        if ($action === 'sync_meta_ads_leads') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem sincronizar Meta Ads.');
            }
            $_SESSION['meta_ads_sync_result'] = studio_meta_ads_sync_leads($studio);
            flash_set('success', 'Sincronizacao da Meta Ads executada.');
            redirect_to('studio_meta_ads');
        }

        if ($action === 'connect_meta_ads') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem conectar Meta Ads.');
            }
            $settings = studio_settings($studio);
            $appId = trim((string)($settings['meta_ads_app_id'] ?? ''));
            $redirectUri = 'https://danieltatuador.com/projetocrm/meta_oauth_callback.php';
            if ($appId === '') {
                flash_set('error', 'Configure o App ID antes de conectar com a Meta.');
                redirect_to('studio_meta_ads');
            }
            $_SESSION['meta_ads_oauth_state'] = bin2hex(random_bytes(24));
            $authUrl = 'https://www.facebook.com/v22.0/dialog/oauth?'
                . 'client_id=' . rawurlencode($appId)
                . '&redirect_uri=' . rawurlencode($redirectUri)
                . '&scope=' . rawurlencode('ads_read,business_management,pages_show_list,pages_read_engagement,leads_retrieval')
                . '&response_type=' . rawurlencode('code')
                . '&state=' . rawurlencode($_SESSION['meta_ads_oauth_state']);
            header('Location: ' . $authUrl);
            exit;
        }

        if ($action === 'select_meta_ads_account') {
            $studio = require_studio();
            if (!studio_current_user_is_admin()) {
                throw new RuntimeException('Apenas administradores podem selecionar conta Meta Ads.');
            }
            $accountId = preg_replace('/^act_/', '', trim((string)($_POST['meta_ads_selected_account'] ?? '')));
            if ($accountId === '') {
                flash_set('error', 'Selecione uma conta de anúncio.');
                redirect_to('studio_meta_ads');
            }
            $settings = studio_settings($studio);
            $payload = [];
            foreach ($settings as $key => $value) {
                $payload[$key] = $value;
            }
            $payload['meta_ads_ad_account_id'] = $accountId;
            studio_save_settings($studio, $payload);
            flash_set('success', 'Conta de anúncio salva.');
            redirect_to('studio_meta_ads');
        }
    } catch (Throwable $e) {
        if (!empty($_POST['return_to_mobile']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        flash_set('error', $e->getMessage());
        redirect_to($page);
    }
}

if ($page === 'logout') {
    logout_admin();
    flash_set('success', 'Voce saiu da plataforma.');
    redirect_to('login');
}

if ($page === 'studio_logout') {
    $returnTo = safe_local_return_url((string)($_GET['return_to'] ?? ''));
    if ($returnTo === '') {
        $returnTo = safe_local_return_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
    }
    logout_studio_user();
    if ($returnTo !== '') {
        $_SESSION['studio_return_to'] = $returnTo;
    }
    flash_set('success', 'Voce saiu do CRM do estudio.');
    redirect_to('studio_login');
}

if ($page === 'studio_whatsapp_live') {
    header('Content-Type: application/json; charset=utf-8');
    $studio = current_studio();
    if (!$studio) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'Sessao do estudio expirou. Faça login novamente.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        echo json_encode([
            'ok' => true,
            'status' => studio_whatsapp_service_status($studio, 1),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$flash = flash_get();

function render_head(string $title): void
{
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
    $viewport = in_array($title, ['Atendimento Mobile', 'WhatsApp Mobile 2'], true)
        ? 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover'
        : 'width=device-width, initial-scale=1';
    $bodyClass = in_array($title, ['Atendimento Mobile', 'WhatsApp Mobile 2'], true) ? ' class="mobile-workspace"' : '';
    echo '<meta name="viewport" content="' . h($viewport) . '">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhj6hW+ALEwIH" crossorigin="anonymous">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">';
    echo '<link rel="stylesheet" href="' . h(app_asset_url('assets/app.css')) . '?v=' . h((string)(@filemtime(__DIR__ . '/assets/app.css') ?: app_build_version())) . '">';
    if (in_array($title, ['Atendimento Mobile', 'WhatsApp Mobile 2'], true)) {
        echo '<link rel="stylesheet" href="' . h(app_asset_url('assets/studio_whatsapp_mobile2.css')) . '?v=' . h(app_build_version()) . '">';
    }
    echo '</head><body' . $bodyClass . '>';
    echo '<input type="text" readonly class="app-build-badge-input" data-build-version="' . h(app_build_version()) . '" value="' . h(app_build_version()) . '" title="Clique para selecionar a versao">';
}

function render_public_head(string $title, string $description, string $bodyClass = ''): void
{
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="description" content="' . h($description) . '">';
    echo '<title>' . h($title) . '</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISV5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhj6hW+ALEwIH" crossorigin="anonymous">';
    echo '<link rel="stylesheet" href="' . h(app_asset_url('assets/app.css')) . '?v=' . h((string)(@filemtime(__DIR__ . '/assets/app.css') ?: app_build_version())) . '">';
    $bodyClasses = trim('public-page ' . $bodyClass);
    echo '</head><body class="' . h($bodyClasses) . '">';
    echo '<input type="text" readonly class="app-build-badge-input" data-build-version="' . h(app_build_version()) . '" value="' . h(app_build_version()) . '" title="Clique para selecionar a versao">';
}

function render_flash(?array $flash): void
{
    if (!$flash) {
        return;
    }
    echo '<div class="flash ' . h($flash['type'] ?? '') . '">' . h($flash['message'] ?? '') . '</div>';
}

function render_scripts(): void
{
    echo '<script>
function crmParseOverlayDate(value) {
    if (!value) return null;
    const raw = String(value).trim();
    if (!raw) return null;
    const normalized = raw.includes("T") ? raw : raw.replace(" ", "T");
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? null : date;
}

function crmEnhanceOverlay(modal) {
    if (!modal) return;
    const panel = modal.querySelector(".crm-modal-panel");
    const header = modal.querySelector(".crm-panel-header");
    const body = modal.querySelector(".p-4");
    if (!panel || !header || !body) return;
    const candidates = Array.from(body.querySelectorAll([
        "tbody tr[data-overlay-item]"
    ].join(",")));

    if (!candidates.length) {
        const existingToolbar = panel.querySelector(".overlay-toolbar");
        if (existingToolbar) {
            existingToolbar.remove();
            delete modal.dataset.crmOverlayToolbarKey;
        }
        return;
    }

    const allowDateFilters = candidates.some((item) => Boolean(item.dataset.overlayDate));
    const allowTimeFilters = candidates.some((item) => Boolean(item.dataset.overlayTime));
    const toolbarKey = [
        "search",
        allowDateFilters ? "date" : "",
        allowTimeFilters ? "time" : ""
    ].filter(Boolean).join("-");
    const existingToolbar = panel.querySelector(".overlay-toolbar");
    if (existingToolbar && modal.dataset.crmOverlayToolbarKey === toolbarKey) {
        return;
    }
    if (existingToolbar) {
        existingToolbar.remove();
    }

    const toolbar = document.createElement("div");
    toolbar.className = "overlay-toolbar";
    toolbar.innerHTML = `
        <div class="overlay-toolbar-grid">
            <input type="search" class="form-control overlay-search" placeholder="Buscar nesta lista">
            ${allowDateFilters ? "<input type=\"date\" class=\"form-control overlay-date-from\" aria-label=\"Data inicial\">" : ""}
            ${allowDateFilters ? "<input type=\"date\" class=\"form-control overlay-date-to\" aria-label=\"Data final\">" : ""}
            ${allowTimeFilters ? "<input type=\"time\" class=\"form-control overlay-time-from\" aria-label=\"Hora inicial\">" : ""}
            ${allowTimeFilters ? "<input type=\"time\" class=\"form-control overlay-time-to\" aria-label=\"Hora final\">" : ""}
            <button type="button" class="btn secondary overlay-clear">Limpar filtros</button>
        </div>
    `;
    panel.insertBefore(toolbar, body);
    modal.dataset.crmOverlayEnhanced = "1";
    modal.dataset.crmOverlayToolbarKey = toolbarKey;

    const searchInput = toolbar.querySelector(".overlay-search");
    const dateFromInput = toolbar.querySelector(".overlay-date-from");
    const dateToInput = toolbar.querySelector(".overlay-date-to");
    const timeFromInput = toolbar.querySelector(".overlay-time-from");
    const timeToInput = toolbar.querySelector(".overlay-time-to");
    const clearButton = toolbar.querySelector(".overlay-clear");

    const normaliseTime = (value) => {
        const raw = String(value || "").trim();
        if (!raw) return "";
        const match = raw.match(/(\d{1,2}):(\d{2})/);
        if (!match) return "";
        return `${String(match[1]).padStart(2, "0")}:${match[2]}`;
    };

    const refresh = () => {
        const needle = String(searchInput?.value || "").trim().toLowerCase();
        const fromDate = crmParseOverlayDate(dateFromInput?.value || "");
        const toDate = crmParseOverlayDate(dateToInput?.value || "");
        const fromTime = normaliseTime(timeFromInput?.value || "");
        const toTime = normaliseTime(timeToInput?.value || "");

        candidates.forEach((item) => {
            const text = String(item.dataset.overlayText || item.textContent || "").toLowerCase();
            const dateValue = item.dataset.overlayDate || "";
            const timeValue = item.dataset.overlayTime || "";
            const itemDate = crmParseOverlayDate(dateValue);
            const itemTime = normaliseTime(timeValue);
            let visible = needle === "" || text.includes(needle);
            if (visible && (fromDate || toDate) && itemDate) {
                if (fromDate && itemDate < fromDate) visible = false;
                if (toDate && itemDate > new Date(toDate.getTime() + 86399999)) visible = false;
            }
            if (visible && (fromTime || toTime) && itemTime) {
                if (fromTime && itemTime < fromTime) visible = false;
                if (toTime && itemTime > toTime) visible = false;
            }
            item.style.display = visible ? "" : "none";
        });
    };

    [searchInput, dateFromInput, dateToInput, timeFromInput, timeToInput].forEach((input) => input && input.addEventListener("input", refresh));
    clearButton.addEventListener("click", () => {
        [searchInput, dateFromInput, dateToInput, timeFromInput, timeToInput].forEach((input) => { if (input) input.value = ""; });
        refresh();
    });

    refresh();
}

setInterval(function () {
    document.querySelectorAll(".crm-modal:not(.hidden)").forEach(crmEnhanceOverlay);
}, 350);

document.addEventListener("click", function (event) {
    var button = event.target.closest(".quick-reply-copy");
    if (!button) return;
    var textarea = document.getElementById("reply-message");
    if (!textarea) return;
    textarea.value = button.getAttribute("data-reply") || "";
    textarea.focus();
});

document.addEventListener("click", async function (event) {
    var badge = event.target.closest(".app-build-badge-input");
    if (!badge) return;
    var version = badge.getAttribute("data-build-version") || badge.textContent || "";
    try {
        if (typeof badge.select === "function") {
            badge.select();
            if (typeof badge.setSelectionRange === "function") {
                badge.setSelectionRange(0, version.length);
            }
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(version).catch(function () {});
        }
        setTimeout(function () {
            badge.blur();
        }, 200);
    } catch (error) {
        badge.focus();
    }
});

document.addEventListener("click", async function (event) {
    var button = event.target.closest("[data-copy-link]");
    if (!button) return;
    var text = button.getAttribute("data-copy-link") || "";
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
        }
        button.textContent = "Link copiado";
        setTimeout(function () {
            button.textContent = "Copiar link";
        }, 1500);
    } catch (error) {
        button.textContent = "Copiar link";
    }
});
</script>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>';
}

function render_auth_page(string $title, string $subtitle, callable $content, ?array $flash): void
{
    render_head($title);
    echo '<div class="auth-page container-fluid d-flex align-items-center justify-content-center py-3 py-md-5">';
    echo '<main class="auth-card card shadow-lg border-0 mx-auto w-100 rounded-4">';
    echo '<div class="auth-card-kicker">Acesso seguro</div>';
    echo '<h1 class="display-6 fw-bold">' . h($title) . '</h1><p class="lead mb-4">' . h($subtitle) . '</p>';
    render_flash($flash);
    $content();
    echo '</main></div>';
    render_scripts();
    echo '</body></html>';
}

function render_app_shell(string $title, string $subtitle, string $active, callable $content, ?array $flash): void
{
    $admin = current_admin();
    render_head($title);
    echo '<div class="shell d-flex flex-column flex-lg-row min-vh-100">';
    echo '<aside class="sidebar d-flex flex-column flex-shrink-0 rounded-end-4">';
    echo '<div class="brand"><span class="brand-mark">CRM</span><span>Projeto CRM</span></div>';
    echo '<div class="sidebar-meta shadow-sm"><span class="sidebar-meta-label">Área administrativa</span><strong>' . h($admin['name'] ?? 'Gerente') . '</strong></div>';
    echo '<nav class="nav nav-pills flex-row flex-lg-column flex-wrap gap-2">';
    echo '<a class="' . ($active === 'dashboard' ? 'active' : '') . '" href="' . h(app_url('dashboard')) . '">Painel</a>';
    echo '<a class="' . ($active === 'studios' ? 'active' : '') . '" href="' . h(app_url('studios')) . '">Estudios</a>';
    echo '<a class="' . ($active === 'plans' ? 'active' : '') . '" href="' . h(app_url('plans')) . '">Planos</a>';
    echo '<a class="' . ($active === 'new_studio' ? 'active' : '') . '" href="' . h(app_url('new_studio')) . '">Novo estúdio</a>';
    echo '<a href="' . h(app_url('logout')) . '">Sair</a>';
    echo '</nav></aside>';
    echo '<main class="main flex-grow-1 container-fluid py-3 py-lg-4">';
    echo '<div class="topbar d-flex justify-content-between align-items-start gap-3 flex-wrap pb-3 mb-4 border-bottom"><div><div class="topbar-kicker">Painel gerente</div><h1 class="h2 mb-1">' . h($title) . '</h1><p class="mb-0">' . h($subtitle) . '</p></div>';
    echo '<span class="badge text-bg-secondary rounded-pill px-3 py-2">' . h($admin['name'] ?? 'Gerente') . '</span></div>';
    render_flash($flash);
    $content();
    echo '</main></div>';
    render_scripts();
    echo '</body></html>';
}

function render_studio_shell(string $title, string $subtitle, string $active, callable $content, ?array $flash): void
{
    $user = current_studio_user();
    render_head($title);
    $navGroups = [
        'Operação' => [
            ['home', 'fa-house', 'Início', 'studio_home'],
            ['people', 'fa-user-group', 'Pessoas', 'studio_people'],
            ['agenda', 'fa-calendar-days', 'Agenda', 'studio_agenda'],
        ],
        'Atendimento' => [
            ['whatsapp', 'fa-comments', 'WhatsApp', 'studio_whatsapp'],
        ],
        'Marketing' => [
            ['meta_ads', 'fa-chart-line', 'Meta Ads', 'studio_meta_ads'],
            ['tattoo_images', 'fa-wand-magic-sparkles', 'Criar imagens', 'studio_tattoo_images'],
        ],
        'Gestão' => [
            ['artists', 'fa-pen-nib', 'Tatuadores', 'studio_artists'],
            ['finance', 'fa-wallet', 'Financeiro', 'studio_finance'],
            ['reports', 'fa-chart-pie', 'Relatórios', 'studio_reports'],
            ['assistant', 'fa-robot', 'Assistente IA', 'studio_data_assistant'],
        ],
        'Configurações' => [
            ['settings', 'fa-sliders', 'Configurações', 'studio_settings'],
        ],
    ];
    $renderStudioNav = static function (array $groups, string $current): void {
        $adminOnlyRoutes = ['studio_artists', 'studio_finance', 'studio_reports', 'studio_data_assistant', 'studio_settings', 'studio_meta_ads'];
        $isAdmin = studio_current_user_is_admin();
        foreach ($groups as $groupLabel => $items) {
            $visibleItems = [];
            foreach ($items as $item) {
                if (in_array((string)($item[3] ?? ''), $adminOnlyRoutes, true) && !$isAdmin) {
                    continue;
                }
                $visibleItems[] = $item;
            }
            if (!$visibleItems) {
                continue;
            }
            echo '<div class="nav-group"><span class="nav-group-label">' . h($groupLabel) . '</span>';
            foreach ($visibleItems as [$key, $icon, $label, $route]) {
                echo '<a class="' . ($current === $key ? 'active' : '') . '" href="' . h(app_url($route)) . '"><i class="fa-solid ' . h($icon) . '" aria-hidden="true"></i><span>' . h($label) . '</span></a>';
            }
            echo '</div>';
        }
    };
    echo '<div class="shell studio-shell d-flex flex-column flex-lg-row min-vh-100">';
    echo '<aside class="sidebar studio-sidebar d-flex flex-column flex-shrink-0">';
    echo '<div class="brand"><span class="brand-mark">C</span><span><strong>' . h($user['studio_name'] ?? 'Estúdio') . '</strong><small>Workspace operacional</small></span></div>';
    echo '<div class="sidebar-meta"><span class="sidebar-avatar">' . h(mb_strtoupper(mb_substr((string)($user['name'] ?? 'U'), 0, 1))) . '</span><span><span class="sidebar-meta-label">Conectado como</span><strong>' . h($user['name'] ?? 'Usuário') . '</strong></span></div>';
    echo '<nav class="nav studio-nav desktop-nav">';
    $renderStudioNav($navGroups, $active);
    echo '<div class="nav-group nav-account"><a href="' . h(app_url('studio_logout')) . '"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Sair</span></a></div>';
    echo '</nav>';
    echo '<details class="mobile-menu"><summary><i class="fa-solid fa-bars"></i><span>Navegação</span><i class="fa-solid fa-chevron-down"></i></summary><nav class="nav studio-nav">';
    $renderStudioNav($navGroups, $active);
    echo '<a href="' . h(app_url('studio_logout')) . '"><i class="fa-solid fa-arrow-right-from-bracket"></i><span>Sair</span></a></nav></details>';
    echo '</aside>';
    echo '<main class="main flex-grow-1 container-fluid py-3 py-lg-4">';
    echo '<div class="topbar studio-topbar d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><div class="topbar-kicker">Workspace / ' . h($title) . '</div><h1 class="h2 mb-1">' . h($title) . '</h1><p class="mb-0">' . h($subtitle) . '</p></div>';
    echo '<div class="topbar-presence"><span></span><strong>Operação online</strong></div></div>';
    render_flash($flash);
    $content();
    echo '</main>';
    echo '<nav class="mobile-bottom-nav" aria-label="Navegação principal">';
    $bottomItems = [
        ['home', 'fa-house', 'Início', 'studio_home'],
        ['leads', 'fa-bolt', 'Leads', 'studio_leads'],
        ['agenda', 'fa-calendar-days', 'Agenda', 'studio_agenda'],
        ['whatsapp', 'fa-comments', 'WhatsApp', 'studio_whatsapp'],
        studio_current_user_is_admin()
            ? ['meta_ads', 'fa-chart-line', 'Meta', 'studio_meta_ads']
            : ['tattoo_images', 'fa-wand-magic-sparkles', 'Imagens', 'studio_tattoo_images'],
    ];
    foreach ($bottomItems as [$key, $icon, $label, $route]) {
        echo '<a class="' . ($active === $key ? 'active' : '') . '" href="' . h(app_url($route)) . '"><i class="fa-solid ' . h($icon) . '"></i><span>' . h($label) . '</span></a>';
    }
    echo '</nav></div>';
    render_scripts();
    echo '</body></html>';
}

function render_public_page(string $title, string $subtitle, callable $content, string $bodyClass = ''): void
{
    render_public_head($title, $subtitle, $bodyClass);
    echo '<div class="public-page-wrap container-xl px-3 px-md-4 py-3 py-md-4">';
    $content();
    echo '</div>';
    render_scripts();
    echo '</body></html>';
}

function render_public_home_page(): void
{
    render_public_page('projetocrm · CRM para estúdios e atendimentos', 'projetocrm é um CRM para organizar clientes, leads, agenda, atendimentos, metas e integrações, incluindo sincronização de eventos do Google Calendar com a agenda interna.', function () {
        echo '<nav class="public-home-nav" aria-label="Navegação pública">';
        echo '<a class="public-home-brand" href="' . h(app_base_path() . '/') . '"><span>crm</span><strong>projetocrm</strong></a>';
        echo '<div class="public-home-links">';
        echo '<a href="' . h(app_url('privacy')) . '">Privacidade</a>';
        echo '<a href="' . h(app_url('terms')) . '">Termos</a>';
        echo '<a href="' . h(app_url('support')) . '">Suporte</a>';
        echo '<a class="btn secondary" href="' . h(app_url('login')) . '">Entrar no sistema</a>';
        echo '</div></nav>';

        echo '<section class="public-home-hero">';
        echo '<div class="public-home-copy">';
        echo '<span class="public-kicker">CRM operacional para estúdios</span>';
        echo '<h1>projetocrm organiza clientes, agenda e atendimentos em um só lugar.</h1>';
        echo '<p class="public-lead">O projetocrm é um sistema de CRM criado para centralizar leads, clientes, agenda, conversas, metas, financeiro e integrações usadas na rotina de atendimento.</p>';
        echo '<p class="public-copy">A área interna exige login para proteger os dados dos estúdios. Esta página pública explica a finalidade do app, seus recursos principais e como a integração com Google Calendar funciona.</p>';
        echo '<div class="actions public-actions">';
        echo '<a class="btn" href="' . h(app_url('login')) . '">Entrar no sistema</a>';
        echo '<a class="btn secondary" href="' . h(app_url('support')) . '">Falar com suporte</a>';
        echo '</div></div>';
        echo '<aside class="public-home-card" aria-label="Resumo do aplicativo">';
        echo '<strong>Para que serve?</strong>';
        echo '<p>Para ajudar equipes de atendimento a acompanhar pessoas, compromissos, oportunidades e tarefas sem espalhar informações entre planilhas, WhatsApp e calendários.</p>';
        echo '<div class="public-home-pill-grid">';
        foreach (['Clientes', 'Agenda', 'Leads', 'Metas', 'Financeiro', 'Integrações'] as $item) {
            echo '<span>' . h($item) . '</span>';
        }
        echo '</div>';
        echo '</aside></section>';

        echo '<section class="public-section public-home-section">';
        echo '<div class="section-head"><h2>Recursos principais</h2><p>O sistema foi desenhado para dar visão prática da operação e reduzir perda de informações durante o atendimento.</p></div>';
        echo '<div class="public-home-feature-grid">';
        $features = [
            ['Clientes e histórico', 'Cadastro de clientes, leads, interesses, observações e histórico de contato.'],
            ['Agenda interna', 'Visualização e organização dos compromissos do estúdio dentro do CRM.'],
            ['Atendimentos e WhatsApp', 'Apoio ao fluxo de atendimento, conversas, respostas rápidas e acompanhamento de oportunidades.'],
            ['Metas e relatórios', 'Indicadores para acompanhar performance, metas comerciais e evolução da operação.'],
            ['Meta Ads', 'Consulta de informações de campanhas e anúncios quando a conta de anúncios está conectada.'],
            ['Google Calendar', 'Sincronização dos eventos do calendário Google com a agenda interna do projetocrm.'],
        ];
        foreach ($features as [$title, $text]) {
            echo '<article class="public-home-feature"><strong>' . h($title) . '</strong><p>' . h($text) . '</p></article>';
        }
        echo '</div></section>';

        echo '<section class="public-section public-home-google">';
        echo '<div class="section-head"><h2>Integração com Google Calendar</h2><p>Quando autorizada pelo usuário, a integração lê calendários e eventos para manter a agenda interna do CRM alinhada.</p></div>';
        echo '<div class="public-home-split">';
        echo '<div class="public-home-panel"><h3>O que a integração faz</h3><ul>';
        echo '<li>Lista calendários disponíveis para o usuário conectado.</li>';
        echo '<li>Importa e atualiza eventos do Google Calendar na agenda interna.</li>';
        echo '<li>Usa sincronização incremental para buscar alterações recentes sem duplicar compromissos.</li>';
        echo '</ul></div>';
        echo '<div class="public-home-panel"><h3>O que ela não faz</h3><ul>';
        echo '<li>Não publica dados sensíveis nesta página pública.</li>';
        echo '<li>Não permite acesso à agenda sem login no CRM.</li>';
        echo '<li>Não altera eventos do Google Calendar; a sincronização atual é de leitura para o CRM.</li>';
        echo '</ul></div>';
        echo '</div></section>';

        echo '<footer class="public-home-footer">';
        echo '<span>projetocrm</span>';
        echo '<a href="' . h(app_url('privacy')) . '">Política de Privacidade</a>';
        echo '<a href="' . h(app_url('terms')) . '">Termos de Uso</a>';
        echo '<a href="' . h(app_url('support')) . '">Suporte/Contato</a>';
        echo '<a href="' . h(app_url('login')) . '">Login</a>';
        echo '</footer>';
    }, 'public-page-app');
}

function render_public_policy_page(string $page): void
{
    $isPrivacy = $page === 'privacy';
    $isTerms = $page === 'terms';
    $title = $isPrivacy ? 'Política de Privacidade do projetocrm' : ($isTerms ? 'Termos de Uso do projetocrm' : 'Suporte do projetocrm');
    $description = $isPrivacy
        ? 'Política de Privacidade pública do projetocrm, incluindo uso de dados e integração com Google Calendar.'
        : ($isTerms ? 'Termos públicos de uso do projetocrm.' : 'Página pública de suporte e contato do projetocrm.');

    render_public_page($title, $description, function () use ($page, $title, $isPrivacy, $isTerms) {
        echo '<nav class="public-home-nav" aria-label="Navegação pública">';
        echo '<a class="public-home-brand" href="' . h(app_base_path() . '/') . '"><span>crm</span><strong>projetocrm</strong></a>';
        echo '<div class="public-home-links">';
        echo '<a href="' . h(app_url('privacy')) . '">Privacidade</a>';
        echo '<a href="' . h(app_url('terms')) . '">Termos</a>';
        echo '<a href="' . h(app_url('support')) . '">Suporte</a>';
        echo '<a class="btn secondary" href="' . h(app_url('login')) . '">Entrar no sistema</a>';
        echo '</div></nav>';
        echo '<section class="public-home-document">';
        echo '<span class="public-kicker">Informação pública</span>';
        echo '<h1>' . h($title) . '</h1>';

        if ($isPrivacy) {
            echo '<p>O projetocrm coleta e processa dados necessários para organizar atendimento, clientes, leads, agenda, histórico de contato, relatórios e integrações autorizadas pelo usuário.</p>';
            echo '<h2>Dados usados</h2><p>Podem ser armazenados dados cadastrais, dados de atendimento, registros de agenda, mensagens operacionais, preferências do estúdio e identificadores técnicos necessários para autenticação e segurança.</p>';
            echo '<h2>Google Calendar</h2><p>Quando o usuário conecta sua conta Google, o projetocrm usa permissões de leitura para listar calendários e sincronizar eventos com a agenda interna. Esses dados são usados somente para exibir e atualizar compromissos dentro do CRM.</p>';
            echo '<h2>Proteção</h2><p>As áreas com dados internos exigem autenticação. Tokens de integração são armazenados de forma protegida no ambiente do servidor e não são exibidos publicamente.</p>';
            echo '<h2>Contato</h2><p>Para solicitações de suporte, remoção de acesso ou dúvidas sobre dados, use a página pública de suporte.</p>';
        } elseif ($isTerms) {
            echo '<p>Ao usar o projetocrm, o usuário concorda em acessar o sistema somente com credenciais autorizadas e manter a confidencialidade das informações de clientes e atendimentos.</p>';
            echo '<h2>Uso permitido</h2><p>O sistema deve ser usado para gestão operacional de estúdios, atendimentos, agenda, leads, clientes, metas e integrações relacionadas.</p>';
            echo '<h2>Responsabilidades</h2><p>Cada usuário é responsável pelas informações inseridas, pela autorização das integrações conectadas e pelo uso adequado dos dados exibidos no CRM.</p>';
            echo '<h2>Disponibilidade</h2><p>O projetocrm pode receber ajustes, melhorias e manutenções para preservar segurança, estabilidade e qualidade da operação.</p>';
        } else {
            echo '<p>Use esta página para encontrar os canais públicos de suporte do projetocrm.</p>';
            echo '<h2>Ajuda para acesso</h2><p>Se você já possui credenciais, acesse o botão de login. Se não conseguir entrar, solicite suporte ao responsável pela implantação do CRM.</p>';
            echo '<h2>Suporte sobre integrações</h2><p>Para dúvidas sobre Google Calendar, Meta Ads ou permissões de acesso, informe qual conta foi conectada, qual tela apresentou erro e o horário aproximado do teste.</p>';
            echo '<div class="actions public-actions">';
            echo '<a class="btn" href="' . h(app_url('login')) . '">Entrar no sistema</a>';
            echo '<a class="btn secondary" href="' . h(public_sales_whatsapp_url()) . '" target="_blank" rel="noopener">Contato via WhatsApp</a>';
            echo '</div>';
        }

        echo '<p class="muted public-home-updated">Última atualização: 09/07/2026.</p>';
        echo '</section>';
    }, 'public-page-app');
}

function render_public_agent_page(): void
{
    $version = app_build_version();
    $status = [
        'aplicacao' => 'online',
        'banco_central' => $GLOBALS['dbStatus']['ok'] ?? false ? 'ok' : 'indisponivel',
        'schema' => $GLOBALS['schemaReady'] ?? false ? 'ok' : 'pendente',
        'versao' => $version,
        'gerado_em' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'),
    ];

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>projetocrm · Página pública para agentes</title>';
    echo '<meta name="description" content="Página pública de verificação do CRM do estúdio, acessível sem login para agentes e validadores.">';
    echo '<style>
        :root{color-scheme:light;--bg:#f3f5f7;--surface:#fff;--line:#dbe2ea;--text:#17202a;--muted:#657386;--brand:#1f6f78;}
        *{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:var(--text)}
        main{max-width:920px;margin:0 auto;padding:32px 18px 48px}
        .hero,.panel{background:var(--surface);border:1px solid var(--line);border-radius:14px}
        .hero{padding:28px;margin-bottom:18px}.hero h1{margin:0 0 8px;font-size:32px}.hero p{margin:0;color:var(--muted);line-height:1.5}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:18px 0}
        .card{padding:16px;border:1px solid var(--line);border-radius:12px;background:#f9fbfc}.card strong{display:block;font-size:14px;margin-bottom:6px}.card span{color:var(--muted)}
        .panel{padding:22px}.panel h2{margin:0 0 10px;font-size:20px}.panel p,.panel li{color:var(--muted);line-height:1.55}
        .actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;border:1px solid var(--line);text-decoration:none;color:var(--text);background:#fff;font-weight:700}
        .btn.primary{background:var(--brand);border-color:var(--brand);color:#fff}pre{margin:0;padding:14px;border-radius:12px;background:#101820;color:#d7f7ea;overflow:auto}
    </style></head><body><main>';
    echo '<section class="hero"><h1>projetocrm</h1><p>Esta é uma página pública de verificação para agentes, navegadores automatizados e validadores. As áreas operacionais do CRM exigem autenticação, mas esta rota foi criada justamente para ser acessível sem login.</p>';
    echo '<div class="actions"><a class="btn primary" href="' . h(app_url('studio_login')) . '">Login do estúdio</a><a class="btn" href="' . h(app_url('login')) . '">Painel gerente</a></div></section>';
    echo '<div class="grid">';
    foreach ($status as $label => $value) {
        echo '<div class="card"><strong>' . h(ucwords(str_replace('_', ' ', $label))) . '</strong><span>' . h((string)$value) . '</span></div>';
    }
    echo '</div>';
    echo '<section class="panel"><h2>O que um agente consegue fazer aqui</h2><ul>';
    echo '<li>Confirmar que o domínio e a aplicação estão online.</li>';
    echo '<li>Ler informações públicas básicas sem esbarrar na tela de login.</li>';
    echo '<li>Descobrir as rotas corretas de autenticação do CRM.</li>';
    echo '</ul></section>';
    echo '<section class="panel" style="margin-top:18px"><h2>Resumo técnico</h2><pre>' . h(json_encode([
        'app' => 'projetocrm',
        'public_agent_page' => true,
        'login_url' => app_url('studio_login'),
        'manager_login_url' => app_url('login'),
        'version' => $version,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></section>';
    echo '</main></body></html>';
}

if ($page === 'home') {
    render_public_home_page();
    exit;
}

if (in_array($page, ['privacy', 'terms', 'support'], true)) {
    render_public_policy_page($page);
    exit;
}

if (!$dbStatus['ok'] || !$schemaReady) {
    render_auth_page('Preparar banco central', 'Rode o SQL inicial no phpMyAdmin para habilitar a alpha.', function () use ($dbStatus) {
        echo '<div class="panel">';
        echo '<h2>Status do banco</h2>';
        if ($dbStatus['ok']) {
            echo '<p><span class="badge warn">Conectado, schema pendente</span></p>';
        } else {
            echo '<p><span class="badge danger">Sem conexao</span></p>';
            echo '<p class="muted">' . h($dbStatus['error']) . '</p>';
        }
        echo '<p>Abra o phpMyAdmin e execute o arquivo abaixo:</p>';
        echo '<p><strong>projetocrm/database/platform_alpha.sql</strong></p>';
        echo '<p class="muted">Configuracao padrao: banco <code>projetocrm_platform</code>, usuario <code>root</code>, senha vazia. Se precisar trocar, crie <code>config/database.local.php</code>.</p>';
        echo '</div>';
    }, $flash);
    exit;
}

if ($page === 'public_plans') {
    $publicPlans = list_commercial_plans(true);
    render_public_page('Planos do CRM para Estúdios de Tatuagem', 'CRM para tatuadores com leads, agenda, WhatsApp, financeiro, relatórios e IA.', function () use ($publicPlans) {
        $heroCta = public_sales_whatsapp_url();
        $heroPlanCta = !empty($publicPlans[0]['name']) ? public_sales_whatsapp_url((string)$publicPlans[0]['name']) : $heroCta;
        echo '<section class="public-hero">';
        echo '<div class="public-hero-copy">';
        echo '<span class="public-kicker">Venda mais. Responda melhor. Organize sua operação.</span>';
        echo '<h1>CRM para estúdios de tatuagem</h1>';
        echo '<p class="public-lead">Organize leads, clientes, agenda, WhatsApp e financeiro em um só lugar.</p>';
        echo '<p class="public-copy">Feito para tatuadores que precisam vender mais, responder melhor e parar de perder cliente no WhatsApp.</p>';
        echo '<div class="actions public-actions">';
        echo '<a class="btn" href="#planos">Ver planos</a>';
        echo '<a class="btn secondary" href="' . h($heroCta) . '" target="_blank" rel="noopener">Falar no WhatsApp</a>';
        echo '</div>';
        echo '</div>';
        echo '<div class="public-hero-aside">';
        echo '<div class="public-hero-card">';
        echo '<p class="muted">Pensado para vender para estúdios reais, com preço, limite e recurso editáveis pelo painel administrativo.</p>';
        echo '<div class="hero-stats">';
        foreach ([
            ['label' => 'Planos ativos', 'value' => count($publicPlans)],
            ['label' => 'WhatsApp de vendas', 'value' => public_sales_whatsapp_number()],
            ['label' => 'Acesso', 'value' => 'Sem login'],
        ] as $item) {
            echo '<div class="hero-stat"><strong>' . h((string)$item['value']) . '</strong><span>' . h($item['label']) . '</span></div>';
        }
        echo '</div>';
        echo '<a class="btn secondary hero-side-cta" href="' . h($heroPlanCta) . '" target="_blank" rel="noopener">Tenho interesse no plano destaque</a>';
        echo '</div>';
        echo '</div>';
        echo '</section>';

        if (!$publicPlans) {
            echo '<section class="public-section"><div class="public-empty">Nenhum plano disponível no momento.</div></section>';
            return;
        }

        echo '<section class="public-section" id="planos">';
        echo '<div class="section-head"><h2>Escolha o plano certo para o seu estúdio</h2><p>Os valores, limites e recursos abaixo vêm do banco e acompanham as alterações do painel administrativo.</p></div>';
        echo '<div class="public-plan-grid">';
        foreach ($publicPlans as $plan) {
            $planName = (string)($plan['name'] ?? '');
            $recommended = !empty($plan['recommended']);
            $monthly = (float)($plan['monthly_price'] ?? 0);
            $annual = (float)($plan['annual_price'] ?? 0);
            $savings = $annual > 0 ? max(0.0, ($monthly * 12) - $annual) : 0.0;
            $shortDescription = trim((string)($plan['short_description'] ?? $plan['description'] ?? ''));
            $features = commercial_plan_public_features($plan);
            $limits = commercial_plan_public_limits($plan);
            echo '<article class="public-plan-card' . ($recommended ? ' recommended' : '') . '">';
            echo '<div class="public-plan-top">';
            echo '<div>';
            echo '<div class="public-plan-name-row"><h3>' . h($planName) . '</h3>' . ($recommended ? '<span class="badge ok">Recomendado</span>' : '') . '</div>';
            if ($shortDescription !== '') {
                echo '<p class="public-plan-subtitle">' . h($shortDescription) . '</p>';
            }
            echo '</div>';
            echo '<div class="public-plan-price">';
            echo '<strong>' . h(format_money($monthly)) . '</strong>';
            echo '<span>/mês</span>';
            echo '</div>';
            echo '</div>';
            echo '<div class="public-plan-annual">';
            echo '<span>' . h(format_money($annual)) . '/ano</span>';
            if ($savings > 0) {
                echo '<small>Economize ' . h(format_money($savings)) . ' no anual</small>';
            }
            echo '</div>';
            if ($features) {
                echo '<div class="public-plan-block"><strong>Recursos</strong><ul class="public-feature-list">';
                foreach ($features as $feature) {
                    echo '<li>' . h($feature) . '</li>';
                }
                echo '</ul></div>';
            }
            echo '<div class="public-plan-block"><strong>Limites</strong><div class="public-limits-grid">';
            foreach ($limits as $limit) {
                echo '<div class="public-limit-pill"><span>' . h($limit['label']) . '</span><strong>' . h($limit['value']) . '</strong></div>';
            }
            echo '</div></div>';
            if (trim((string)($plan['description'] ?? '')) !== '') {
                echo '<p class="public-plan-description">' . h((string)$plan['description']) . '</p>';
            }
            echo '<div class="actions public-plan-actions">';
            echo '<a class="btn" href="' . h(public_sales_whatsapp_url($planName)) . '" target="_blank" rel="noopener">Tenho interesse</a>';
            echo '</div>';
            echo '</article>';
        }
        echo '</div>';
        echo '</section>';

        echo '<section class="public-section public-compare">';
        echo '<div class="section-head"><h2>Compare os planos</h2><p>Uma leitura simples para bater o olho e entender onde cada plano faz sentido.</p></div>';
        echo '<div class="table-wrap"><table class="table public-compare-table"><thead><tr><th>Recurso</th>';
        foreach ($publicPlans as $plan) {
            echo '<th>' . h((string)($plan['name'] ?? 'Plano')) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $compareRows = [
            ['label' => 'Usuários', 'key' => 'user_limit'],
            ['label' => 'Tatuadores', 'key' => 'tattoo_artist_limit'],
            ['label' => 'WhatsApp', 'key' => 'allow_whatsapp'],
            ['label' => 'IA', 'key' => 'allow_ai'],
            ['label' => 'Automações', 'key' => 'allow_automations'],
            ['label' => 'Relatórios avançados', 'key' => 'allow_advanced_reports'],
            ['label' => 'Multi-estúdio', 'key' => 'allow_multi_studio'],
        ];
        foreach ($compareRows as $row) {
            echo '<tr><td><strong>' . h($row['label']) . '</strong></td>';
            foreach ($publicPlans as $plan) {
                $key = (string)$row['key'];
                $value = $plan[$key] ?? null;
                if (str_starts_with($key, 'allow_')) {
                    echo '<td>' . (!empty($value) ? '<span class="badge ok">Sim</span>' : '<span class="badge warn">Não</span>') . '</td>';
                } else {
                    echo '<td>' . h($value === null || $value === '' ? 'Ilimitado' : (string)$value) . '</td>';
                }
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '</section>';

        echo '<section class="public-section public-audience">';
        echo '<div class="section-head"><h2>Para quem é</h2><p>Os textos abaixo podem ser refinados depois pelo painel, mas já ajudam o visitante a se enxergar em cada plano.</p></div>';
        echo '<div class="public-audience-grid">';
        foreach ($publicPlans as $plan) {
            echo '<article class="public-audience-card">';
            echo '<h3>' . h((string)($plan['name'] ?? 'Plano')) . '</h3>';
            echo '<p>' . h(trim((string)($plan['description'] ?? $plan['short_description'] ?? '')) ?: 'Plano comercial do CRM.') . '</p>';
            echo '</article>';
        }
        echo '</div>';
        echo '</section>';

        echo '<section class="public-section public-benefits">';
        echo '<div class="section-head"><h2>Benefícios do CRM</h2><p>Um resumo simples do que o sistema resolve no dia a dia do estúdio.</p></div>';
        echo '<div class="public-benefits-grid">';
        foreach ([
            'Pare de perder orçamento no WhatsApp.',
            'Veja quais leads estão quentes.',
            'Controle agenda e sinais.',
            'Organize clientes e histórico.',
            'Acompanhe financeiro do estúdio.',
            'Use respostas rápidas e IA para acelerar atendimento.',
            'Tenha relatórios para tomar decisões melhores.',
        ] as $benefit) {
            echo '<div class="public-benefit">' . h($benefit) . '</div>';
        }
        echo '</div>';
        echo '</section>';

        echo '<section class="public-section public-final-cta">';
        echo '<div class="public-final-card">';
        echo '<div><h2>Quer testar no seu estúdio?</h2><p>Fale comigo e veja qual plano faz sentido para sua operação.</p></div>';
        echo '<a class="btn" href="' . h(public_sales_whatsapp_url()) . '" target="_blank" rel="noopener">Chamar no WhatsApp</a>';
        echo '</div>';
        echo '</section>';
    });
    exit;
}

if (admin_count() === 0) {
    render_auth_page('Criar gerente', 'Primeiro acesso da plataforma. Crie o usuario dono.', function () {
        echo '<form class="form" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="install_admin">';
        echo '<div class="field"><label>Nome</label><input name="name" required autocomplete="name"></div>';
        echo '<div class="field"><label>Email</label><input name="email" type="text" inputmode="email" required autocomplete="email"></div>';
        echo '<div class="field"><label>Senha</label><input name="password" type="password" minlength="8" required autocomplete="new-password"></div>';
        echo '<button class="btn" type="submit">Criar gerente</button>';
        echo '</form>';
    }, $flash);
    exit;
}

if ($page === 'login') {
    if (current_studio_user()) {
        redirect_to('studio_home');
    }
    if (current_admin()) {
        redirect_to('dashboard');
    }
    render_auth_page('Entrar na plataforma', 'Escolha se este acesso deve abrir o CRM do estúdio ou o painel gerente.', function () {
        $returnTo = trim((string)($_SESSION['admin_return_to'] ?? ''));
        $selectedContext = (string)($_GET['mode'] ?? 'studio');
        if (!in_array($selectedContext, ['studio', 'admin', 'auto'], true)) {
            $selectedContext = 'studio';
        }
        echo '<div class="auth-welcome">';
        echo '<span>projetocrm</span>';
        echo '<strong>Bem-vindo ao projetocrm!</strong>';
        echo '<p>Organize seus atendimentos, clientes e agenda em um só lugar.</p>';
        echo '</div>';
        echo '<form class="form" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="login">';
        if ($returnTo !== '') {
            echo '<input type="hidden" name="return_to" value="' . h($returnTo) . '">';
        }
        echo '<div class="field"><label>Entrar como</label><select name="login_context" required>';
        echo '<option value="studio"' . ($selectedContext === 'studio' ? ' selected' : '') . '>Estúdio - agenda e operação</option>';
        echo '<option value="admin"' . ($selectedContext === 'admin' ? ' selected' : '') . '>Gerente - painel administrativo</option>';
        echo '<option value="auto"' . ($selectedContext === 'auto' ? ' selected' : '') . '>Automático - detectar pelo email</option>';
        echo '</select><small class="muted">Use Estúdio para gravar a integração com Google Agenda; use Gerente para configurar planos, estúdios e usuários.</small></div>';
        echo '<div class="field"><label>Email</label><input name="email" type="text" inputmode="email" required autocomplete="email" placeholder="admin@... ou acesso do estúdio"></div>';
        echo '<div class="field"><label>Senha</label><input name="password" type="password" required autocomplete="current-password"></div>';
        echo '<div class="actions" style="justify-content:space-between;gap:10px;align-items:center">';
        echo '<button class="btn" type="submit">Entrar</button>';
        echo '<span class="badge neutral">Escolha manual</span>';
        echo '</div>';
        echo '<p class="muted" style="margin-top:10px">Se o mesmo email existir nos dois lugares, a escolha acima decide para onde o login vai.</p>';
        echo '</form>';
    }, $flash);
    exit;
}

if ($page === 'studio_login') {
    if (current_studio_user()) {
        $returnTo = safe_local_return_url((string)($_GET['return_to'] ?? $_SESSION['studio_return_to'] ?? ''));
        if ($returnTo !== '') {
            unset($_SESSION['studio_return_to']);
            redirect_to_url($returnTo);
        }
        redirect_to('studio_home');
    }
    render_auth_page('Entrar no CRM do estudio', 'Acesso operacional do estudio cadastrado.', function () {
        $returnTo = safe_local_return_url((string)($_GET['return_to'] ?? $_SESSION['studio_return_to'] ?? ''));
        echo '<form class="form" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="studio_login">';
        if ($returnTo !== '') {
            echo '<input type="hidden" name="return_to" value="' . h($returnTo) . '">';
        }
        echo '<div class="field"><label>Email</label><input name="email" type="text" inputmode="email" required autocomplete="email"></div>';
        echo '<div class="field"><label>Senha</label><input name="password" type="password" required autocomplete="current-password"></div>';
        echo '<button class="btn" type="submit">Entrar no CRM</button>';
        echo '<a class="btn secondary" href="' . h(app_url('login')) . '">Painel gerente</a>';
        echo '</form>';
    }, $flash);
    exit;
}

if ($page === 'public_agent') {
    render_public_agent_page();
    exit;
}

$studioPages = ['studio_home', 'studio_people', 'studio_leads', 'studio_lead', 'studio_customers', 'studio_customer', 'studio_agenda', 'studio_artists', 'studio_whatsapp', 'studio_whatsapp_workspace', 'studio_whatsapp_conversation', 'studio_whatsapp_tags', 'studio_whatsapp_flow', 'studio_finance', 'studio_quick_replies', 'studio_reports', 'studio_data_assistant', 'studio_tattoo_images', 'studio_tattoo_image_status', 'studio_settings', 'studio_meta_ads'];
if (in_array($page, $studioPages, true) && !current_studio_user()) {
    $_SESSION['studio_return_to'] = safe_local_return_url((string)($_SERVER['REQUEST_URI'] ?? ''));
    redirect_to('studio_login');
}

$studioAdminOnlyPages = ['studio_artists', 'studio_whatsapp_flow', 'studio_finance', 'studio_reports', 'studio_data_assistant', 'studio_settings', 'studio_meta_ads'];
if (in_array($page, $studioAdminOnlyPages, true) && current_studio_user() && !studio_current_user_is_admin()) {
    flash_set('error', 'Apenas administradores podem acessar esta área.');
    redirect_to('studio_home');
}

if (in_array($page, ['studio_whatsapp_workspace', 'studio_whatsapp_conversation'], true)) {
    $mobileParams = [];
    foreach (['id', 'filter', 'date_from', 'date_to'] as $key) {
        if (isset($_GET[$key]) && is_scalar($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $mobileParams[$key] = trim((string)$_GET[$key]);
        }
    }
    redirect_to('studio_whatsapp_mobile', $mobileParams);
}

if ($page === 'studio_whatsapp_mobile' || $page === 'studio_whatsapp_mobile2') {
    $mobileRoute = 'studio_whatsapp_mobile';
    $currentUser = current_studio_user();
    render_head('Atendimento Mobile');

    if (!$currentUser) {
        $mobileReturnTo = safe_local_return_url((string)($_SERVER['REQUEST_URI'] ?? app_url($mobileRoute)));
        echo '<main class="m2-login"><section class="m2-login-card"><h1>Entrar no atendimento</h1><form method="post" class="form" action="' . h(app_url($mobileRoute)) . '">' . csrf_field() . '<input type="hidden" name="action" value="studio_login"><input type="hidden" name="return_to" value="' . h($mobileReturnTo !== '' ? $mobileReturnTo : app_url($mobileRoute)) . '"><div class="field"><label>Email</label><input name="email" type="text" inputmode="email" required autocomplete="email"></div><div class="field"><label>Senha</label><input name="password" type="password" required autocomplete="current-password"></div><button class="m2-send" type="submit">Entrar</button></form></section></main></body></html>';
        exit;
    }

    $studio = get_studio((int)($currentUser['studio_id'] ?? 0));
    if (!$studio) {
        echo '<main class="m2-login"><section class="m2-login-card"><h1>Estudio nao encontrado</h1><a class="m2-send" href="' . h(app_url('studio_login')) . '">Recarregar login</a></section></main></body></html>';
        exit;
    }

    $filters = [
        'q' => (string)($_GET['q'] ?? ''),
        'filter' => (string)($_GET['filter'] ?? 'all'),
        'visibility' => (string)($_GET['visibility'] ?? 'all'),
        'date_filter' => (string)($_GET['date_filter'] ?? ''),
        'date_from' => (string)($_GET['date_from'] ?? ''),
        'date_to' => (string)($_GET['date_to'] ?? ''),
        'offset' => 0,
    ];
    $conversations = studio_list_whatsapp_conversations($studio, $filters, 80);
    $conversationId = (int)($_GET['id'] ?? 0);
    $conversation = $conversationId > 0 ? studio_find_whatsapp_conversation($studio, $conversationId) : null;
    $messages = $conversation ? studio_whatsapp_messages($studio, $conversationId, 100, $conversation) : [];
    $isAdmin = studio_current_user_is_admin();
    $currentUserId = (int)($currentUser['id'] ?? 0);
    $assignedUserId = (int)($conversation['assigned_user_id'] ?? 0);
    $assignedUserName = $assignedUserId > 0 ? studio_user_label_by_id($assignedUserId) : '';
    $canSend = $conversation && ($isAdmin || $assignedUserId === $currentUserId);
    $assistantInsights = $conversation ? studio_whatsapp_assistant_insights($studio, $conversation, $messages) : [];
    $customers = $conversation ? studio_list_customers($studio) : [];
    $leads = $conversation ? studio_list_leads($studio) : [];
    $artists = $conversation ? studio_list_artists($studio) : [];
    $quickReplies = $conversation ? array_values(array_filter(studio_list_quick_replies($studio), static fn(array $reply): bool => !empty($reply['is_active']))) : [];
    $stickers = $conversation ? studio_list_whatsapp_stickers($studio, $currentUserId) : [];
    $savedStickerUrls = array_fill_keys(array_map(static fn(array $sticker): string => (string)($sticker['media_url'] ?? ''), $stickers), true);
    $assistantAutofillEnabled = !empty(studio_settings($studio)['assistant_autofill_enabled']);
    $assistantConfidence = max(0, min(100, (int)round(((int)($assistantInsights['confidence'] ?? 0)) * 10)));
    if ($assistantAutofillEnabled && $assistantConfidence === 0 && count($messages) > 0) {
        $assistantConfidence = 35;
    }
    $scheduleSuggestion = $conversation ? studio_whatsapp_schedule_suggestion($conversation, $messages, $artists) : [];
    if ($conversation && !empty($assistantInsights['suggested_date']) && !empty($assistantInsights['suggested_time'])) {
        $scheduleSuggestion['date'] = (string)$assistantInsights['suggested_date'];
        $scheduleSuggestion['time'] = (string)$assistantInsights['suggested_time'];
    }
    if ($conversation && !empty($assistantInsights['schedule_reason'])) {
        $scheduleSuggestion['reason'] = (string)$assistantInsights['schedule_reason'];
    }
    if ($conversation && !empty($assistantInsights['suggested_interest']) && trim((string)($conversation['lead_interest'] ?? '')) === '') {
        $scheduleSuggestion['title'] = (string)$assistantInsights['suggested_interest'];
    }
    $publicUpdateUrl = '';
    if ($conversation && !empty($conversation['lead_id'])) {
        $publicToken = studio_ensure_lead_public_update_token($studio, (int)$conversation['lead_id']);
        $publicUpdateUrl = app_url('lead_public_update', ['lead' => (int)$conversation['lead_id'], 'token' => $publicToken]);
    }
    $sharePhone = $conversation ? preg_replace('/\D+/', '', (string)($conversation['phone'] ?? '')) : '';
    $whatsAppShareUrl = ($sharePhone !== '' && $publicUpdateUrl !== '') ? ('https://wa.me/' . $sharePhone . '?text=' . rawurlencode('Atualize seu cadastro por aqui: ' . $publicUpdateUrl)) : '';
    $pendingAudioCount = 0;
    foreach ($messages as $message) {
        $pendingMime = (string)($message['media_mime'] ?? '');
        $pendingType = (string)($message['message_type'] ?? '');
        $pendingHasTranscript = trim((string)($message['transcricao'] ?? $message['transcript'] ?? '')) !== '';
        if ((str_starts_with($pendingMime, 'audio/') || $pendingType === 'audio') && !$pendingHasTranscript) {
            $pendingAudioCount++;
        }
    }
    $nameFieldValue = $conversation ? (string)($conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: ''))) : '';
    if ($nameFieldValue === '' && !empty($assistantInsights['suggested_name'])) {
        $nameFieldValue = (string)$assistantInsights['suggested_name'];
    }
    $interestFieldValue = $conversation ? (string)($conversation['lead_interest'] ?: ($conversation['last_message_preview'] ?? '')) : '';
    if ($interestFieldValue === '' && !empty($assistantInsights['suggested_interest'])) {
        $interestFieldValue = (string)$assistantInsights['suggested_interest'];
    }
    $notesFieldValue = $conversation ? (string)($conversation['customer_notes'] ?? '') : '';
    if ($notesFieldValue === '' && !empty($assistantInsights['suggested_notes'])) {
        $notesFieldValue = (string)$assistantInsights['suggested_notes'];
    }

    $labelForConversation = static function (array $row): string {
        $name = trim((string)($row['customer_name'] ?? ''));
        if ($name === '') $name = trim((string)($row['lead_name'] ?? ''));
        if ($name === '') $name = trim((string)($row['name'] ?? ''));
        $phone = trim((string)($row['phone'] ?? ''));
        if ($name === '' || in_array($name, ['Cliente WhatsApp', 'Contato WhatsApp'], true)) {
            return $phone !== '' ? $phone : 'Contato WhatsApp';
        }
        return $name;
    };
    $mobileAiStateFor = static function (array $row, int $confidence = 0): array {
        $mode = (string)($row['attendance_mode'] ?? 'human');
        $needsHuman = !empty($row['needs_human']);
        $rawStatus = trim((string)($row['ai_last_status'] ?? ''));
        $status = $rawStatus !== '' ? $rawStatus : ($mode === 'bot' ? 'IA pronta para responder' : 'IA inativa');
        $lower = mb_strtolower($status);
        $tone = 'neutral';
        $label = $mode === 'bot' ? 'IA pronta' : ($needsHuman ? 'Aguardando atendente' : 'Com atendente');
        $progress = $mode === 'bot' ? 72 : ($needsHuman ? 35 : 18);
        if ($mode === 'bot') {
            $tone = 'ok';
            $progress = max($progress, 72);
        } elseif ($needsHuman) {
            $tone = 'warn';
        }
        if (str_contains($lower, 'analis')) {
            $tone = 'warn';
            $label = 'Analisando';
            $progress = 46;
        } elseif (str_contains($lower, 'erro') || str_contains($lower, 'falha') || str_contains($lower, 'sem resposta') || str_contains($lower, 'indispon')) {
            $tone = 'danger';
            $label = 'Atenção IA';
            $progress = 28;
        } elseif (str_contains($lower, 'respond') || str_contains($lower, 'enviad')) {
            $tone = 'ok';
            $label = 'IA respondeu';
            $progress = 100;
        } elseif (str_contains($lower, 'pronta') || str_contains($lower, 'ativad')) {
            $tone = 'ok';
            $label = 'IA pronta';
            $progress = 82;
        } elseif (str_contains($lower, 'inativa') || str_contains($lower, 'desativ')) {
            $tone = 'neutral';
            $label = 'IA inativa';
            $progress = 12;
        }
        if ($confidence > 0 && $tone !== 'danger') {
            $progress = max($progress, min(100, $confidence));
        }
        return ['label' => $label, 'status' => $status, 'tone' => $tone, 'progress' => max(0, min(100, $progress))];
    };
    $inferMediaType = static function (string $mime, string $mediaUrl, string $type): string {
        $mime = strtolower(trim($mime));
        $type = strtolower(trim($type));
        $ext = strtolower(pathinfo((string)(parse_url($mediaUrl, PHP_URL_PATH) ?: $mediaUrl), PATHINFO_EXTENSION));
        if ($type === 'sticker') return 'sticker';
        if (str_starts_with($mime, 'image/') || $type === 'image') return 'image';
        if (str_starts_with($mime, 'audio/') || $type === 'audio') return 'audio';
        if (str_starts_with($mime, 'video/') || $type === 'video') return 'video';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return 'image';
        if (in_array($ext, ['mp3', 'wav', 'ogg', 'oga', 'opus', 'webm', 'm4a', 'aac'], true)) return 'audio';
        if (in_array($ext, ['mp4', 'mov', 'm4v', 'avi', 'mkv'], true)) return 'video';
        return 'file';
    };

    echo '<main class="m2-shell' . ($conversation ? ' has-chat' : '') . '" data-conversation-id="' . h((string)$conversationId) . '">';
    echo '<aside class="m2-list" id="m2ListPanel">';
    echo '<header class="m2-top"><strong>WhatsApp</strong>' . ($isAdmin ? '<span class="badge ok">ADM</span>' : '') . '<span class="m2-top-actions"><button class="m2-icon m2-filter-button" type="button" id="m2FiltersButton" aria-label="Filtros" title="Filtros"><i class="fa-solid fa-sliders"></i></button>' . ($isAdmin ? '<button class="m2-icon m2-manage-toggle" type="button" id="m2ManageToggle" aria-label="Gerenciar" title="Gerenciar"><i class="fa-solid fa-user-gear"></i></button>' : '') . '</span></header>';
    echo '<div class="m2-search"><i class="fa-solid fa-magnifying-glass"></i><input id="m2Search" type="search" placeholder="Buscar conversa"></div>';
    $mobileFilterHref = static function (array $patch = []) use ($mobileRoute, $filters): string {
        $params = [
            'visibility' => $filters['visibility'] !== '' ? $filters['visibility'] : null,
            'filter' => $filters['filter'] !== '' ? $filters['filter'] : null,
            'date_filter' => $filters['date_filter'] !== '' ? $filters['date_filter'] : null,
            'date_from' => $filters['date_from'] !== '' ? $filters['date_from'] : null,
            'date_to' => $filters['date_to'] !== '' ? $filters['date_to'] : null,
            'q' => $filters['q'] !== '' ? $filters['q'] : null,
        ];
        foreach ($patch as $key => $value) {
            $params[$key] = $value;
        }
        return app_url($mobileRoute, array_filter($params, static fn($value) => $value !== null && $value !== ''));
    };
    $mobileFilterActive = ($filters['visibility'] !== '' && $filters['visibility'] !== 'all')
        || ($filters['filter'] !== '' && $filters['filter'] !== 'all')
        || $filters['date_filter'] !== ''
        || $filters['date_from'] !== ''
        || $filters['date_to'] !== ''
        || $filters['q'] !== '';
    echo '<details class="m2-filter-menu"' . ($mobileFilterActive ? ' open' : '') . '><summary class="m2-filter-toggle"><i class="fa-solid fa-sliders"></i><span>Filtros</span>' . ($mobileFilterActive ? '<b>Ativo</b>' : '') . '</summary><div class="m2-filter-panel">';
    $visibilityPills = $isAdmin ? ['all' => 'Todas', 'mine' => 'Minhas', 'free' => 'Livres'] : ['mine' => 'Minhas'];
    echo '<div class="m2-filter-group"><strong>Visibilidade</strong><div class="m2-filter-chip-row">';
    foreach ($visibilityPills as $visibilityKey => $label) {
        $href = $mobileFilterHref([
            'visibility' => $filters['visibility'] === $visibilityKey ? null : $visibilityKey,
        ]);
        echo '<a class="m2-filter-chip' . h($filters['visibility'] === $visibilityKey ? ' active' : '') . '" href="' . h($href) . '">' . h($label) . '</a>';
    }
    echo '</div></div>';
    echo '<div class="m2-filter-group"><strong>Status</strong><div class="m2-filter-chip-row">';
    foreach (['all' => 'Tudo', 'unreplied' => 'Nao lidas', 'needs_human' => 'Humano', 'bot' => 'IA'] as $filterKey => $label) {
        $href = $mobileFilterHref([
            'filter' => $filters['filter'] === $filterKey ? null : $filterKey,
        ]);
        echo '<a class="m2-filter-chip' . h($filters['filter'] === $filterKey ? ' active' : '') . '" href="' . h($href) . '">' . h($label) . '</a>';
    }
    echo '</div></div>';
    echo '<div class="m2-filter-group"><strong>Periodo exato</strong>';
    echo '<form class="m2-range-form" method="get">';
    echo '<input type="hidden" name="page" value="' . h($mobileRoute) . '">';
    echo '<input type="hidden" name="visibility" value="' . h($filters['visibility']) . '">';
    echo '<input type="hidden" name="filter" value="' . h($filters['filter']) . '">';
    echo '<input type="hidden" name="q" value="' . h($filters['q']) . '">';
    echo '<div class="m2-range-grid"><label><span>De</span><input type="date" name="date_from" value="' . h($filters['date_from']) . '"></label><label><span>Ate</span><input type="date" name="date_to" value="' . h($filters['date_to']) . '"></label></div>';
    echo '<small class="m2-range-help">Escolha as duas datas para limitar as conversas ao intervalo desejado.</small>';
    echo '<input type="hidden" name="date_filter" value="range">';
    echo '<div class="m2-range-actions"><button class="m2-range-apply" type="submit">Aplicar periodo</button><a class="m2-range-clear" href="' . h($mobileFilterHref(['date_filter' => null, 'date_from' => null, 'date_to' => null])) . '">Limpar</a></div>';
    echo '</form></div>';
    echo '</div></details>';
    if ($isAdmin) {
        echo '<form id="m2BulkDeleteForm" class="m2-bulk-form" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="mobile_delete_whatsapp_conversations">';
        echo '<input type="hidden" name="filter" value="' . h($filters['filter']) . '">';
        echo '<input type="hidden" name="visibility" value="' . h($filters['visibility']) . '">';
        echo '<input type="hidden" name="date_filter" value="' . h($filters['date_filter']) . '">';
        echo '<input type="hidden" name="date_from" value="' . h($filters['date_from']) . '">';
        echo '<input type="hidden" name="date_to" value="' . h($filters['date_to']) . '">';
        echo '<input type="hidden" name="q" value="' . h($filters['q']) . '">';
        echo '<div class="m2-bulk-bar"><label class="m2-select-all"><input type="checkbox" id="m2SelectAllConversations"><span>Selecionar tudo</span></label><span class="m2-bulk-count" id="m2BulkCount">0 selecionadas</span><button class="m2-bulk-delete" type="submit" id="m2DeleteSelectedConversations" disabled>Excluir selecionadas</button></div>';
    }
    echo '<nav class="m2-items' . ($isAdmin ? ' m2-items-admin' : '') . '" id="m2Items">';
    foreach ($conversations as $row) {
        $rowId = (int)($row['id'] ?? 0);
        $rowName = $labelForConversation($row);
        $rowAssignedUserId = (int)($row['assigned_user_id'] ?? 0);
        $rowPreview = trim((string)($row['latest_message_preview'] ?? $row['last_message_preview'] ?? ''));
        $href = app_url($mobileRoute, ['id' => $rowId]);
        $active = $rowId === $conversationId ? ' active' : '';
        $assignmentLabel = $rowAssignedUserId <= 0 ? 'Livre' : ($rowAssignedUserId === $currentUserId ? 'Minha' : studio_user_label_by_id($rowAssignedUserId));
        $rowUnreadCount = studio_whatsapp_unread_count($row, $studio);
        $rowAiState = $mobileAiStateFor($row);
        $searchText = strtolower($rowName . ' ' . ($row['phone'] ?? '') . ' ' . $rowPreview);
        $rowInitial = mb_strtoupper(mb_substr(trim($rowName) !== '' ? $rowName : 'W', 0, 1));
        if ($isAdmin) {
            echo '<div class="m2-item m2-item-admin' . h($active) . '" data-conversation-id="' . h((string)$rowId) . '" data-search="' . h($searchText) . '"><label class="m2-select-row"><input class="m2-conversation-checkbox" type="checkbox" name="conversation_ids[]" value="' . h((string)$rowId) . '"><span></span></label><a class="m2-item-link" href="' . h($href) . '"><span class="m2-avatar">' . h($rowInitial) . '</span><span><strong>' . h($rowName) . '</strong><small>' . h($rowPreview !== '' ? $rowPreview : 'Sem mensagem ainda') . '</small><small class="m2-item-badges"><b class="' . h($rowAiState['tone']) . '">' . h($rowAiState['label']) . '</b>' . ($rowUnreadCount > 0 ? '<b class="warn">' . h((string)$rowUnreadCount) . '</b>' : '') . '</small><span class="m2-ai-mini-bar ' . h($rowAiState['tone']) . '"><i style="width:' . h((string)$rowAiState['progress']) . '%"></i></span></span><em>' . h($assignmentLabel) . '</em></a></div>';
        } else {
            echo '<a class="m2-item' . h($active) . '" href="' . h($href) . '" data-search="' . h($searchText) . '"><span class="m2-avatar">' . h($rowInitial) . '</span><span><strong>' . h($rowName) . '</strong><small>' . h($rowPreview !== '' ? $rowPreview : 'Sem mensagem ainda') . '</small><small class="m2-item-badges"><b class="' . h($rowAiState['tone']) . '">' . h($rowAiState['label']) . '</b>' . ($rowUnreadCount > 0 ? '<b class="warn">' . h((string)$rowUnreadCount) . '</b>' : '') . '</small><span class="m2-ai-mini-bar ' . h($rowAiState['tone']) . '"><i style="width:' . h((string)$rowAiState['progress']) . '%"></i></span></span><em>' . h($assignmentLabel) . '</em></a>';
        }
    }
    echo '</nav>';
    if ($isAdmin) {
        echo '</form>';
    }
    echo '</aside>';

    echo '<section class="m2-chat' . (!$conversation ? ' empty' : '') . '" id="m2ChatPanel">';
    if (!$conversation) {
        echo '<div class="m2-empty"><i class="fa-solid fa-comments"></i><strong>Escolha uma conversa</strong></div>';
    } else {
        $displayName = $labelForConversation($conversation);
        $displayInitial = mb_strtoupper(mb_substr(trim($displayName) !== '' ? $displayName : 'W', 0, 1));
        echo '<header class="m2-chat-head"><a class="m2-icon m2-back" href="' . h(app_url($mobileRoute)) . '" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a><span class="m2-avatar">' . h($displayInitial) . '</span><span class="m2-title"><strong>' . h($displayName) . '</strong><small>' . h($assignedUserId <= 0 ? 'Livre' : ('Com ' . ($assignedUserId === $currentUserId ? 'voce' : $assignedUserName))) . '</small></span><button class="m2-icon" type="button" id="m2MenuButton" aria-label="Acoes"><i class="fa-solid fa-ellipsis-vertical"></i></button></header>';
        $mobileAiState = $mobileAiStateFor($conversation, $assistantConfidence);
        echo '<div class="m2-breadcrumb"><a href="' . h(app_url('studio_home')) . '">CRM</a><i class="fa-solid fa-angle-right"></i><a href="' . h(app_url('studio_whatsapp_mobile', ['id' => $conversationId])) . '">Conversas</a><i class="fa-solid fa-angle-right"></i><span>' . h($displayName) . '</span></div>';
        echo '<section class="m2-ai-status ' . h($mobileAiState['tone']) . '" aria-label="Status da IA"><div><strong>' . h($mobileAiState['label']) . '</strong><small>' . h($mobileAiState['status']) . '</small></div><span>' . h((string)$mobileAiState['progress']) . '%</span><b><i style="width:' . h((string)$mobileAiState['progress']) . '%"></i></b></section>';
        $mobileAiModeActive = ((string)($conversation['attendance_mode'] ?? 'human') === 'bot');
        echo '<div class="m2-action-row"><button type="button" id="m2OpenAppointment" title="Agendar" aria-label="Agendar"><i class="fa-regular fa-calendar"></i></button><button type="button" id="m2OpenTools" title="Painel" aria-label="Painel"><i class="fa-solid fa-sliders"></i></button><button type="button" id="m2AiButton" title="Sugestoes da IA" aria-label="Sugestoes da IA"><i class="fa-solid fa-wand-magic-sparkles"></i></button><button type="button" id="m2AiModeButton" class="' . ($mobileAiModeActive ? 'is-active' : '') . '" title="' . h($mobileAiModeActive ? 'IA ligada nesta conversa' : 'IA desligada nesta conversa') . '" aria-label="' . h($mobileAiModeActive ? 'IA ligada' : 'IA desligada') . '" data-next-mode="' . h($mobileAiModeActive ? 'human' : 'bot') . '"><i class="fa-solid ' . h($mobileAiModeActive ? 'fa-toggle-on' : 'fa-toggle-off') . '"></i></button>';
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="mobile_mark_whatsapp_read"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="return_to_mobile2" value="1"><button type="submit" title="Marcar lida" aria-label="Marcar lida"><i class="fa-regular fa-envelope-open"></i></button></form>';
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="mobile_mark_whatsapp_unread"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="return_to_mobile2" value="1"><button type="submit" title="Marcar nao lida" aria-label="Marcar nao lida"><i class="fa-regular fa-envelope"></i></button></form>';
        if (!empty($conversation['customer_id'])) echo '<a href="' . h(app_url('studio_customer', ['id' => (int)$conversation['customer_id']])) . '" target="_blank" rel="noopener" title="Cliente" aria-label="Cliente"><i class="fa-solid fa-user"></i></a>';
        if (!empty($conversation['lead_id'])) echo '<a href="' . h(app_url('studio_lead', ['id' => (int)$conversation['lead_id']])) . '" target="_blank" rel="noopener" title="Lead" aria-label="Lead"><i class="fa-solid fa-seedling"></i></a>';
        if ($publicUpdateUrl !== '') echo '<a href="' . h($publicUpdateUrl) . '" target="_blank" rel="noopener" title="Cadastro publico" aria-label="Cadastro publico"><i class="fa-regular fa-address-card"></i></a>';
        echo '</div>';
        echo '<div class="m2-menu hidden" id="m2Menu">';
        if ($assignedUserId <= 0 || $isAdmin || $assignedUserId === $currentUserId) {
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="assign_whatsapp_conversation"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="return_to_mobile2" value="1"><button type="submit"><i class="fa-solid fa-hand-pointer"></i>Assumir</button></form>';
        }
        if (($assignedUserId === $currentUserId || $isAdmin) && $assignedUserId > 0) {
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="release_whatsapp_conversation"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="return_to_mobile2" value="1"><button type="submit"><i class="fa-solid fa-lock-open"></i>Liberar</button></form>';
        }
        if ($isAdmin) {
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="transfer_whatsapp_conversation"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="return_to_mobile2" value="1"><select name="target_user_id" required><option value="">Transferir para...</option>';
            foreach (studio_list_users($studio) as $studioUser) echo '<option value="' . h((string)$studioUser['id']) . '">' . h((string)$studioUser['name']) . '</option>';
            echo '</select><button type="submit"><i class="fa-solid fa-right-left"></i>Transferir</button></form>';
        }
        echo '</div>';
        echo '<div class="m2-messages" id="m2Messages">';
        foreach ($messages as $message) {
            $direction = (string)($message['direction'] ?? 'in');
            $class = $direction === 'out' ? ' out' : ' in';
            $body = (string)($message['body'] ?? '');
            $type = (string)($message['message_type'] ?? 'texto');
            $mime = (string)($message['media_mime'] ?? '');
            $mediaUrl = (string)($message['media_url'] ?? '');
            $mediaName = (string)($message['media_file_name'] ?? '');
            $kind = $inferMediaType($mime, $mediaUrl, $type);
            if ($mediaName === '' && $mediaUrl !== '') $mediaName = basename(parse_url($mediaUrl, PHP_URL_PATH) ?: $mediaUrl);
            $messageKey = (string)($message['id'] ?? $message['message_id'] ?? $message['sent_at'] ?? '');
            $messageIdLocal = (string)($message['id'] ?? '');
            $messageWamid = (string)($message['message_id'] ?? '');
            $messagePreview = trim($body);
            if ($messagePreview === '') {
                $messagePreview = $mediaName !== '' ? $mediaName : '[' . ($type !== '' ? $type : 'mensagem') . ']';
            }
            $messagePreview = mb_substr($messagePreview, 0, 220, 'UTF-8');
            $contextPreview = trim((string)($message['context_preview'] ?? ''));
            $bubbleClass = 'm2-bubble' . ($kind === 'audio' ? ' m2-audio-bubble' : '');
            echo '<article class="m2-msg' . h($class) . '" data-message-key="' . h($messageKey) . '" data-message-id="' . h($messageIdLocal) . '" data-wamid="' . h($messageWamid) . '" data-message-preview="' . h($messagePreview) . '" data-message-sender="' . h($direction === 'out' ? 'Você' : $displayName) . '"><div class="' . h($bubbleClass) . '">';
            if ($contextPreview !== '') echo '<div class="m2-quoted"><span>Respondendo</span><p>' . h(mb_substr($contextPreview, 0, 220, 'UTF-8')) . '</p></div>';
            if ($mediaUrl !== '') {
                if ($kind === 'sticker') {
                    echo '<div class="m2-sticker-wrap"><img class="m2-sticker-media" src="' . h($mediaUrl) . '" alt="' . h($mediaName ?: 'Figurinha') . '"></div>';
                    if (empty($savedStickerUrls[$mediaUrl])) echo '<button class="m2-save-sticker" type="button" data-save-sticker="' . h($messageIdLocal) . '"><i class="fa-regular fa-bookmark"></i>Salvar figurinha</button>';
                } elseif ($kind === 'image') echo '<img class="m2-media" src="' . h($mediaUrl) . '" alt="' . h($mediaName ?: 'Midia') . '">';
                elseif ($kind === 'video') echo '<video class="m2-media" src="' . h($mediaUrl) . '" controls></video>';
                elseif ($kind === 'audio') {
                    echo '<div class="m2-audio-widget" data-audio-src="' . h($mediaUrl) . '"><button class="m2-audio-toggle" type="button" aria-label="Reproduzir audio"><i class="fa-solid fa-play"></i></button><span class="m2-audio-time">0:00</span><div class="m2-audio-track" role="slider" aria-label="Progresso do audio"><span></span></div><audio class="m2-audio-native" src="' . h($mediaUrl) . '" preload="metadata" style="display:none!important"></audio></div>';
                    if (empty($message['transcricao']) && empty($message['transcript'])) echo '<button class="m2-transcribe" type="button" data-transcribe-audio="' . h($message['message_id'] ?? '') . '" data-media-url="' . h($mediaUrl) . '"><i class="fa-solid fa-wave-square"></i>Transcrever</button>';
                } else echo '<a class="m2-file" href="' . h($mediaUrl) . '" target="_blank" rel="noopener"><i class="fa-solid fa-paperclip"></i>' . h($mediaName !== '' ? $mediaName : 'Abrir anexo') . '</a>';
            }
            $syntheticAudioBody = $kind === 'audio' && preg_match('/^\[(audio|áudio)\]$/iu', trim($body)) === 1;
            if ($body !== '' && !$syntheticAudioBody) echo '<p>' . nl2br(h($body)) . '</p>';
            $transcribedText = trim((string)($message['transcricao'] ?? $message['transcript'] ?? ''));
            if ($transcribedText !== '') echo '<div class="m2-transcript">' . h($transcribedText) . '</div>';
            echo '<div class="m2-bubble-foot"><time>' . h(format_datetime_pt((string)($message['sent_at'] ?? ''), false)) . '</time><button class="m2-reply-action" type="button" data-reply-message aria-label="Responder mensagem"><i class="fa-solid fa-reply"></i></button></div></div></article>';
        }
        echo '</div>';
        echo '<div class="m2-emoji hidden" id="m2EmojiPanel" aria-label="Emojis">';
        foreach (['😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😍','😘','😗','😙','😚','😋','😛','😜','🤪','😎','🥳','😏','😒','😞','😔','😢','😭','😤','😡','🤯','🥺','😬','😮‍💨','😴','🤔','🫠','🤝','👍','👎','👌','🤌','👏','🙌','🙏','💪','🤘','✌️','👀','🫶','❤️','🧡','💛','💚','💙','💜','🖤','🤍','💔','💕','💞','💘','🔥','✨','⭐','💫','💥','💯','✅','☑️','❌','⚠️','🙏','📅','⏰','📍','💰','💳','📸','🎨','🖊️','🖤','🌹','🐍','🦁','🐺','🦋','🌙','☀️','⚡','👑','💀','👻','🍒','🍺','☕','🎯','🚀','📲','🤖'] as $emoji) echo '<button type="button" data-emoji="' . h($emoji) . '">' . h($emoji) . '</button>';
        echo '</div>';
        echo '<div class="m2-sticker-panel hidden" id="m2StickerPanel" aria-label="Figurinhas"></div>';
        echo '<div class="m2-attachment hidden" id="m2AttachmentPreview"></div>';
        $mobileWindow = studio_whatsapp_customer_service_window($studio, $conversationId);
        $mobileAiSnapshot = studio_whatsapp_ai_suggestions_snapshot($studio, $conversation, $assistantInsights, $messages);
        if (!empty($mobileWindow['applies']) && empty($mobileWindow['open'])) {
            echo '<div class="m2-card"><strong>Janela oficial encerrada</strong><small>Use um template aprovado para reabrir esta conversa fora das 24h.</small></div>';
        }
        echo '<div class="m2-reply-preview hidden" id="m2ReplyPreview"><div><span>Responder</span><strong id="m2ReplyPreviewSender">Mensagem</strong><p id="m2ReplyPreviewText"></p></div><button type="button" id="m2CancelReply" aria-label="Cancelar resposta"><i class="fa-solid fa-xmark"></i></button></div>';
        echo '<form class="m2-composer" id="m2Composer" method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="send_whatsapp_message"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="phone" value="' . h((string)($conversation['phone'] ?? '')) . '"><input type="hidden" name="return_to_mobile" value="1"><input type="hidden" name="return_to_mobile2" value="1"><input type="hidden" name="context_message_id" id="m2ContextMessageId" value=""><input type="hidden" name="context_local_message_id" id="m2ContextLocalMessageId" value=""><input type="hidden" name="context_preview" id="m2ContextPreview" value=""><input id="m2AttachmentInput" class="m2-file-input" type="file" name="media_file" accept="image/*,audio/*,video/*,.webp,.pdf,.doc,.docx,.txt,.zip"><button type="button" id="m2EmojiButton" aria-label="Emoji"><i class="fa-regular fa-face-smile"></i></button><button type="button" id="m2StickerButton" aria-label="Figurinhas"><i class="fa-regular fa-note-sticky"></i></button><button type="button" id="m2AttachButton" aria-label="Anexar"><i class="fa-solid fa-paperclip"></i></button><textarea id="m2Message" name="message" placeholder="Mensagem" rows="1" ' . (!$canSend ? 'disabled' : '') . '></textarea><button type="button" id="m2RecordButton" aria-label="Audio"><i class="fa-solid fa-microphone"></i></button><button type="submit" aria-label="Enviar" ' . (!$canSend ? 'disabled' : '') . '><i class="fa-solid fa-paper-plane"></i></button></form>';
        if (!$canSend) {
            echo '<div class="m2-notice">Voce pode visualizar, mas precisa assumir a conversa para responder.</div>';
        }
        echo '<div class="m2-drawer hidden" id="m2ToolsPanel"><div class="m2-drawer-head"><strong>Painel da conversa</strong><button type="button" data-close-drawer="m2ToolsPanel" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div><div class="m2-drawer-body">';
        echo '<section class="m2-card"><div class="m2-card-head"><h3>Radar</h3><span>' . h(((string)($conversation['attendance_mode'] ?? 'human')) === 'bot' ? 'IA ativa' : 'Humano') . '</span></div><div class="m2-metrics"><span><strong>' . h((string)count($messages)) . '</strong><small>Msgs</small></span><span><strong>' . h((string)$assistantConfidence) . '%</strong><small>IA</small></span><span><strong>' . h((string)$pendingAudioCount) . '</strong><small>Audios</small></span></div><div class="m2-tool-grid"><button type="button" data-mode-toggle="bot">Bot</button><button type="button" data-mode-toggle="human">Humano</button><button type="button" data-status-set="novo">Novo</button><button type="button" id="m2TranscribePending">Transcrever</button></div></section>';
        echo '<section class="m2-card"><div class="m2-card-head"><h3>Cadastro e funil</h3><span>' . h((string)($conversation['lead_score'] ?? 0)) . '/10</span></div><form class="m2-form" method="post">' . csrf_field() . '<input type="hidden" name="action" value="update_whatsapp_profile"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="return_to_mobile2" value="1"><label>Nome<input name="name" value="' . h($nameFieldValue !== '' ? $nameFieldValue : $displayName) . '"></label><label>Telefone<input name="phone" value="' . h((string)($conversation['phone'] ?? '')) . '"></label><label>Email<input type="text" inputmode="email" name="email" value="' . h((string)($conversation['customer_email'] ?? '')) . '"></label><label>Instagram<input name="instagram" value="' . h((string)($conversation['customer_instagram'] ?? '')) . '"></label><label>Lead score<input type="number" name="lead_score" min="0" max="10" value="' . h((string)($conversation['lead_score'] ?? 0)) . '"></label><label>Cliente<select name="customer_id"><option value="">Criar/sem cliente</option>';
        render_customer_options($customers, (int)($conversation['customer_id'] ?? 0));
        echo '</select></label><label>Lead<select name="lead_id"><option value="">Criar/sem lead</option>';
        render_lead_options($leads, (int)($conversation['lead_id'] ?? 0));
        echo '</select></label><label>Interesse<input name="interest" value="' . h($interestFieldValue) . '"></label><label>Status<select name="status">';
        render_options(lead_status_options(), (string)($conversation['lead_status'] ?: 'em_conversa'));
        echo '</select></label><label>Etapa<select name="pipeline_stage">';
        foreach (studio_list_pipeline_stages($studio) as $stage) {
            echo '<option value="' . h((string)$stage['name']) . '" ' . ((string)$stage['name'] === (string)($conversation['lead_pipeline_stage'] ?: 'em_conversa') ? 'selected' : '') . '>' . h(studio_pipeline_stage_display_name((string)$stage['name'])) . '</option>';
        }
        echo '</select></label><label class="wide">Observacoes<textarea name="notes">' . h($notesFieldValue) . '</textarea></label><label class="check"><input type="checkbox" name="needs_human" value="1" ' . (!empty($conversation['needs_human']) ? 'checked' : '') . '> Cliente pediu humano</label><label class="check"><input type="checkbox" name="create_customer" value="1" ' . (empty($conversation['customer_id']) ? 'checked' : '') . '> Criar/atualizar cliente</label><label class="check"><input type="checkbox" name="create_lead" value="1" ' . (empty($conversation['lead_id']) ? 'checked' : '') . '> Criar/atualizar lead</label><button type="submit">Salvar dados</button></form></section>';
        echo '<section class="m2-card"><div class="m2-card-head"><h3>Link de cadastro</h3><span>' . h($publicUpdateUrl !== '' ? 'Pronto' : 'Pendente') . '</span></div>';
        if ($publicUpdateUrl !== '') {
            echo '<label class="m2-copy-field">Link publico<input type="text" id="m2PublicUpdateUrl" readonly value="' . h($publicUpdateUrl) . '"></label><div class="m2-tool-grid"><a href="' . h($publicUpdateUrl) . '" target="_blank" rel="noopener">Abrir</a><button type="button" id="m2CopyPublicUrl">Copiar</button>' . ($whatsAppShareUrl !== '' ? '<a href="' . h($whatsAppShareUrl) . '" target="_blank" rel="noopener">WhatsApp</a>' : '') . '<button type="button" id="m2OpenAppointmentFromTools">Agendar</button></div>';
        } else {
            echo '<p>Vincule ou crie um lead para liberar o link publico de cadastro.</p>';
        }
        echo '</section><section class="m2-card"><div class="m2-card-head"><h3>Respostas rapidas</h3><span>' . h((string)count($quickReplies)) . '</span></div>';
        if ($quickReplies) {
            echo '<div class="m2-quick-list">';
            foreach (array_slice($quickReplies, 0, 16) as $reply) {
                echo '<button type="button" data-reply="' . h((string)$reply['body']) . '">' . h((string)$reply['title']) . '</button>';
            }
            echo '</div>';
        } else {
            echo '<p>Nenhuma resposta rapida ativa.</p>';
        }
        echo '</section></div></div>';
        echo '<div class="m2-drawer hidden" id="m2AppointmentPanel"><div class="m2-drawer-head"><strong>Agendar atendimento</strong><button type="button" data-close-drawer="m2AppointmentPanel" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div><div class="m2-drawer-body"><form class="m2-form" method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="save_appointment"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="customer_id" value="' . h((string)($conversation['customer_id'] ?? 0)) . '"><input type="hidden" name="lead_id" value="' . h((string)($conversation['lead_id'] ?? 0)) . '"><input type="hidden" name="import_source" value="whatsapp"><input type="hidden" name="return_to_conversation" value="' . h((string)$conversationId) . '"><input type="hidden" name="return_to_mobile2" value="1"><label>Titulo<input name="title" required value="' . h((string)($scheduleSuggestion['title'] ?? ($conversation['lead_interest'] ?: 'Atendimento'))) . '"></label><label>Tatuador<select name="artist_id">';
        render_artist_options($artists, default_artist_id($studio) ?? 0);
        echo '</select></label><label>Data<input type="date" name="appointment_date" value="' . h((string)($scheduleSuggestion['date'] ?? date('Y-m-d'))) . '" required></label><label>Inicio<input type="time" name="start_time" value="' . h((string)($scheduleSuggestion['time'] ?? '09:00')) . '" required></label><label>Fim<input type="time" name="end_time" value="' . h((string)($scheduleSuggestion['end_time'] ?? '10:00')) . '" required></label><label>Status<select name="status">';
        render_options(appointment_status_options(), 'pre_agendado');
        echo '</select></label><label>Valor<input name="price" placeholder="0,00"></label><label>Sinal<input name="deposit_amount" placeholder="0,00"></label><label class="wide">Descricao<textarea name="description">' . h((string)($scheduleSuggestion['description'] ?? $scheduleSuggestion['reason'] ?? '')) . '</textarea></label><button type="submit">Salvar agendamento</button></form></div></div>';
        echo '<div class="crm-modal hidden" id="m2AiOverlay"><div class="crm-modal-panel ai-modal-panel" style="max-width:min(96vw,760px);background:linear-gradient(180deg,#15232a 0%,#111b21 100%);color:#e9edef;border:1px solid rgba(134,150,160,.22);box-shadow:0 30px 90px rgba(0,0,0,.55)"><div class="crm-panel-header" style="background:linear-gradient(180deg,rgba(19,32,39,.98) 0%,rgba(15,25,31,.98) 100%);border-bottom:1px solid rgba(0,168,132,.16);color:#e9edef"><div><h3 class="crm-panel-title" style="color:#f3f6f7">Sugestoes da IA</h3><p class="muted" style="margin:4px 0 0;color:#9aa7af">Copiloto silencioso para leitura, resumo e apoio ao atendimento.</p></div><button type="button" id="closeM2AiOverlay" class="crm-button crm-icon-button" style="color:#e9edef;border-color:rgba(255,255,255,.1);background:rgba(255,255,255,.04)"><i class="fa-solid fa-xmark"></i></button></div><div id="m2AiOverlayBody" class="p-4" style="padding:20px;background:linear-gradient(180deg,rgba(17,27,33,.98) 0%,rgba(12,19,24,.99) 100%);color:#e9edef"></div></div></div>';
        echo '<script type="application/json" id="m2AiInitialData">' . json_encode($mobileAiSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
        echo '<script type="application/json" id="m2QuickRepliesData">' . json_encode(studio_quick_replies_payload($quickReplies), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
        echo '<script type="application/json" id="m2StickersData">' . json_encode(array_map('studio_whatsapp_sticker_payload', $stickers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }
    echo '</section></main>';
    echo '<script src="' . h(app_asset_url('assets/studio_whatsapp_mobile2.js')) . '?v=' . h(app_build_version()) . '"></script>';
    echo '<script src="' . h(app_asset_url('assets/studio_whatsapp_quick_replies.js')) . '?v=' . h(app_build_version()) . '"></script>';
    echo '<script src="' . h(app_asset_url('assets/studio_whatsapp_ai_overlay.js')) . '?v=' . h(app_build_version()) . '"></script>';
    echo '</body></html>';
    exit;
}

if ($page === 'studio_home') {
    $user = current_studio_user();
    $studio = require_studio();
    render_studio_shell('Início do CRM', 'Resumo operacional da instância ' . (string)$user['studio_name'] . '.', 'home', function () use ($studio, $user) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $stats = studio_stats($studio);
        $financeSummary = studio_finance_summary($studio);
        $pdo = studio_db($studio);
        $pomadaUnitPrice = (float)(studio_settings($studio)['pomada_unit_price'] ?? (app_config('app')['pomada_unit_price'] ?? 100));
        $paidAppointmentsMonth = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE DATE_FORMAT(appointment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND status NOT IN ('cancelado') AND COALESCE(deposit_value, 0) > 0")->fetchColumn();
        $recentLeads = studio_recent_leads($studio, 6);
        $appointments = studio_upcoming_appointments($studio, 6);
        $monthStart = new DateTimeImmutable('first day of this month', new DateTimeZone('America/Sao_Paulo'));
        $monthEnd = new DateTimeImmutable('last day of this month 23:59:59', new DateTimeZone('America/Sao_Paulo'));
        $nextMonthStart = new DateTimeImmutable('first day of next month', new DateTimeZone('America/Sao_Paulo'));
        $nextMonthEnd = new DateTimeImmutable('last day of next month 23:59:59', new DateTimeZone('America/Sao_Paulo'));
        $settings = studio_settings($studio);
        $metaInsights = null;
        $metaMessagingInsights = null;
        if (!empty($settings['meta_ads_access_token']) && !empty($settings['meta_ads_ad_account_id'])) {
            try {
                $metaInsights = studio_meta_ads_insights_summary($studio, 30);
                $metaMessagingInsights = studio_meta_ads_messaging_conversations_summary($studio, 1);
            } catch (Throwable) {
                $metaInsights = null;
                $metaMessagingInsights = null;
            }
        }
        $allowedDays = studio_schedule_days($studio);
        $allowedSlots = studio_schedule_slots($studio);
        $allowedDaySet = array_fill_keys(array_map('strval', $allowedDays), true);
        $current = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
        $todayIso = $current->format('Y-m-d');
        $remainingWorkDays = 0;
        for ($day = $current; $day <= $monthEnd; $day = $day->modify('+1 day')) {
            if (isset($allowedDaySet[$day->format('N')])) {
                $remainingWorkDays++;
            }
        }
        $slotCount = max(1, count($allowedSlots));
        $scheduledToEndOfMonth = (float)($pdo->query("SELECT COALESCE(SUM(value), 0) FROM appointments WHERE appointment_date BETWEEN '" . $current->format('Y-m-d') . "' AND '" . $monthEnd->format('Y-m-d') . "' AND status NOT IN ('cancelado')")->fetchColumn() ?: 0);
        $bookedSlots = (int)($pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN '" . $current->format('Y-m-d') . "' AND '" . $monthEnd->format('Y-m-d') . "' AND status NOT IN ('cancelado')")->fetchColumn());
        $availableSlots = max(0, ($remainingWorkDays * $slotCount) - $bookedSlots);
        $todayAppointments = array_values(array_filter(studio_calendar_appointments($studio, $current->format('Y-m-d'), $current->format('Y-m-d')), static fn(array $appointment): bool => (string)($appointment['status'] ?? '') !== 'cancelado'));
        $todayAppointmentsCount = count($todayAppointments);
        $newLeadsTodayStmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE DATE(created_at) = ?");
        $newLeadsTodayStmt->execute([$todayIso]);
        $newLeadsToday = (int)$newLeadsTodayStmt->fetchColumn();
        $attentionLeads = array_values(array_filter(studio_list_leads($studio, [], 120), static function (array $lead) use ($current): bool {
            $score = (int)($lead['lead_score'] ?? 0);
            $updatedAt = (string)($lead['updated_at'] ?? $lead['created_at'] ?? '');
            $isStale = false;
            if ($updatedAt !== '') {
                try {
                    $updatedMoment = new DateTimeImmutable($updatedAt, new DateTimeZone('America/Sao_Paulo'));
                    $isStale = $updatedMoment < $current->modify('-24 hours');
                } catch (Throwable) {
                    $isStale = false;
                }
            }
            return $score >= 7 || $isStale || in_array((string)($lead['status'] ?? ''), ['novo', 'em_conversa'], true);
        }));
        usort($attentionLeads, static function (array $left, array $right): int {
            $leftScore = (int)($left['lead_score'] ?? 0);
            $rightScore = (int)($right['lead_score'] ?? 0);
            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }
            return strcmp((string)($right['updated_at'] ?? ''), (string)($left['updated_at'] ?? ''));
        });
        $attentionLeads = array_slice($attentionLeads, 0, 8);
        $attentionLeadsTotal = count($attentionLeads);
        $staleAttentionLeadsCount = count(array_filter($attentionLeads, static function (array $lead) use ($current): bool {
            $updatedAt = (string)($lead['updated_at'] ?? $lead['created_at'] ?? '');
            if ($updatedAt === '') {
                return false;
            }
            try {
                return new DateTimeImmutable($updatedAt, new DateTimeZone('America/Sao_Paulo')) < $current->modify('-24 hours');
            } catch (Throwable) {
                return false;
            }
        }));
        $nextAvailableSlots = studio_schedule_available_slots($studio, 14, $current);
        $financeSummary = studio_finance_summary($studio);
        $whatsappSummary = plan_allows('whatsapp') ? studio_whatsapp_summary($studio) : ['total' => 0, 'bot' => 0, 'human' => 0, 'analyzed' => 0, 'needs_human' => 0, 'avg_score' => 0];
        $whatsappStatusData = null;
        if (plan_allows('whatsapp')) {
            try {
                $whatsappStatusData = studio_whatsapp_service_status($studio, 1);
            } catch (Throwable) {
                $whatsappStatusData = null;
            }
        }
        $whatsappState = 'desconectado';
        if (is_array($whatsappStatusData) && !empty($whatsappStatusData['ok'])) {
            $rawState = (string)($whatsappStatusData['status'] ?? '');
            $whatsappState = match ($rawState) {
                'connected' => 'conectado',
                'waiting_qr' => 'aguardando QR',
                'starting' => 'iniciando',
                'disconnected' => 'desconectado',
                default => 'erro',
            };
            if (!empty($whatsappStatusData['phone'])) {
                $whatsappState .= ' · ' . preg_replace('/\D+/', '', (string)$whatsappStatusData['phone']);
            }
        }
        $pendingWhatsappConversations = plan_allows('whatsapp') ? studio_list_whatsapp_conversations($studio, ['filter' => 'unreplied'], 10) : [];
        $needsHumanConversations = plan_allows('whatsapp') ? studio_list_whatsapp_conversations($studio, ['filter' => 'needs_human'], 10) : [];
        $whatsappConversationItems = [];
        foreach (array_merge($pendingWhatsappConversations, $needsHumanConversations) as $conversation) {
            $conversationId = (int)($conversation['id'] ?? 0);
            if ($conversationId > 0 && !isset($whatsappConversationItems[$conversationId])) {
                $whatsappConversationItems[$conversationId] = $conversation;
            }
        }
        $metaCampaignRanges = [
            'today' => [
                'label' => 'Hoje',
                'start' => $current->setTime(0, 0, 0),
                'end' => $current->setTime(23, 59, 59),
            ],
            '7d' => [
                'label' => 'Últimos 7 dias',
                'start' => $current->modify('-6 days')->setTime(0, 0, 0),
                'end' => $current->setTime(23, 59, 59),
            ],
            '15d' => [
                'label' => 'Últimos 15 dias',
                'start' => $current->modify('-14 days')->setTime(0, 0, 0),
                'end' => $current->setTime(23, 59, 59),
            ],
            '30d' => [
                'label' => 'Últimos 30 dias',
                'start' => $current->modify('-29 days')->setTime(0, 0, 0),
                'end' => $current->setTime(23, 59, 59),
            ],
            'month' => [
                'label' => 'Este mês',
                'start' => $monthStart->setTime(0, 0, 0),
                'end' => $monthEnd,
            ],
        ];
        $metaCampaignAllItems = studio_meta_campaign_entries(
            $studio,
            $current->modify('-180 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
            $current->setTime(23, 59, 59)->format('Y-m-d H:i:s')
        );
        $metaCampaignRangeMap = [];
        foreach ($metaCampaignRanges as $rangeKey => $rangeConfig) {
            $metaCampaignRangeMap[$rangeKey] = studio_meta_campaign_entries(
                $studio,
                $rangeConfig['start']->format('Y-m-d H:i:s'),
                $rangeConfig['end']->format('Y-m-d H:i:s')
            );
        }
        $metaCampaignItems = $metaCampaignRangeMap['today'] ?? [];
        $metaCampaignInternalCount = count($metaCampaignItems);
        $metaCampaignMetaCount = (int)($metaMessagingInsights['reported_conversations'] ?? 0);
        $metaCampaignDelta = $metaCampaignMetaCount - $metaCampaignInternalCount;
        $metaCampaignSummary = $metaCampaignInternalCount . ' contatos internos pela frase inicial hoje';
        if (($metaMessagingInsights['ok'] ?? false) && $metaCampaignMetaCount > 0) {
            $metaCampaignSummary .= ' · Meta reportou ' . $metaCampaignMetaCount . ' conversas';
        }
        if (($metaMessagingInsights['ok'] ?? false) && $metaCampaignMetaCount !== $metaCampaignInternalCount) {
            $metaCampaignSummary .= ' · diferença ' . ($metaCampaignDelta > 0 ? '+' : '') . $metaCampaignDelta;
        }
        $metaSpendLabel = 'Meta Ads pronta';
        if (is_array($metaInsights) && !empty($metaInsights['ok'])) {
            $metaSpendLabel = format_money((float)($metaInsights['spend'] ?? 0)) . ' gasto · ' . (int)($metaInsights['clicks'] ?? 0) . ' cliques';
        }
        $decorateAppointmentCard = static function (array $appointment) use ($pomadaUnitPrice): array {
            $rawValue = appointment_display_amount($appointment['value'] ?? 0);
            $rawDeposit = appointment_display_amount($appointment['deposit_value'] ?? 0);
            $pomadas = max(0, (int)($appointment['pomadas_quantity'] ?? 0));
            $effective = max(0.0, $rawValue + ($pomadas * $pomadaUnitPrice) - $rawDeposit);
            $appointment['value_label'] = format_money($rawValue);
            $appointment['deposit_label'] = format_money($rawDeposit);
            $appointment['effective_value_label'] = format_money($effective);
            $appointment['start_time_label'] = substr((string)($appointment['start_time'] ?? ''), 0, 5);
            $appointment['end_time_label'] = substr((string)($appointment['end_time'] ?? ''), 0, 5);
            return $appointment;
        };
        $scheduledMonthItems = array_map($decorateAppointmentCard, $pdo->query("SELECT a.*, COALESCE(c.name, a.title) AS customer_name, ta.name AS artist_name FROM appointments a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id WHERE a.appointment_date BETWEEN '" . $current->format('Y-m-d') . "' AND '" . $monthEnd->format('Y-m-d') . "' AND a.status NOT IN ('cancelado') ORDER BY a.appointment_date ASC, a.start_time ASC LIMIT 12")->fetchAll() ?: []);
        $appointmentsMonthItems = array_map($decorateAppointmentCard, $pdo->query("SELECT a.*, COALESCE(c.name, a.title) AS customer_name, ta.name AS artist_name FROM appointments a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id WHERE a.appointment_date BETWEEN '" . $current->format('Y-m-d') . "' AND '" . $monthEnd->format('Y-m-d') . "' AND a.status NOT IN ('cancelado') ORDER BY a.appointment_date ASC, a.start_time ASC LIMIT 40")->fetchAll() ?: []);
        $nextMonthItems = array_map($decorateAppointmentCard, $pdo->query("SELECT a.*, COALESCE(c.name, a.title) AS customer_name, ta.name AS artist_name FROM appointments a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id WHERE a.appointment_date BETWEEN '" . (new DateTimeImmutable('first day of next month', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d') . "' AND '" . (new DateTimeImmutable('last day of next month 23:59:59', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d') . "' AND a.status NOT IN ('cancelado') ORDER BY a.appointment_date ASC, a.start_time ASC LIMIT 40")->fetchAll() ?: []);
        $alerts = [];

        try {
            $metaBalanceAlert = studio_meta_balance_alert_process($studio);

            if (!empty($metaBalanceAlert['ok']) && !empty($metaBalanceAlert['low'])) {
                $description = 'Saldo disponível reportado pela Meta: '
                    . format_money((float)$metaBalanceAlert['balance'])
                    . '. Recarregue a conta para evitar interrupção das campanhas.';

                if (!empty($metaBalanceAlert['notification_sent']) && empty($metaBalanceAlert['notification_ok'])) {
                    $description .= ' O aviso no WhatsApp falhou; confira a janela de atendimento ou a configuração da API oficial.';
                }

                $alerts[] = [
                    'title' => 'Saldo Meta baixo',
                    'description' => $description,
                    'href' => app_url('studio_meta_ads'),
                    'tone' => 'danger',
                ];
            }
        } catch (Throwable $e) {
            // O monitor nunca deve derrubar a Home do CRM.
        }

        if ($staleAttentionLeadsCount > 0) {
            $alerts[] = [
                'title' => 'Leads sem atualização há mais de 24h',
                'description' => 'Você tem ' . $staleAttentionLeadsCount . ' leads parados ou frios que merecem retorno.',
                'href' => app_url('studio_leads'),
                'tone' => 'warn',
            ];
        }
        $preScheduledNoSignalCount = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date >= '" . $current->format('Y-m-d') . "' AND status = 'pre_agendado' AND COALESCE(deposit_value, 0) <= 0")->fetchColumn();
        if ($preScheduledNoSignalCount > 0) {
            $alerts[] = [
                'title' => 'Pré-agendamentos sem sinal',
                'description' => 'Há ' . $preScheduledNoSignalCount . ' pré-agendamentos aguardando sinal.',
                'href' => app_url('studio_agenda'),
                'tone' => 'warn',
            ];
        }
        $preScheduledOverlapCount = (int)$pdo->query("SELECT COUNT(DISTINCT a.id) FROM appointments a INNER JOIN appointments b ON b.appointment_date = a.appointment_date AND COALESCE(b.artist_id, 0) = COALESCE(a.artist_id, 0) AND b.id <> a.id AND b.status = 'pre_agendado' AND a.status = 'pre_agendado' AND NOT (COALESCE(b.end_time, b.start_time) <= a.start_time OR b.start_time >= a.end_time) WHERE a.status = 'pre_agendado' AND a.appointment_date >= CURDATE()")->fetchColumn();
        if ($preScheduledOverlapCount > 0) {
            $alerts[] = [
                'title' => 'Pré-agendamentos duplicados',
                'description' => 'Há ' . $preScheduledOverlapCount . ' pré-agendamentos no mesmo dia, horário e tatuador. Isso é permitido, mas vale revisar.',
                'href' => app_url('studio_agenda'),
                'tone' => 'warn',
            ];
        }
        if (count($needsHumanConversations) > 0) {
            $alerts[] = [
                'title' => 'Pedido por humano no WhatsApp',
                'description' => 'Há ' . count($needsHumanConversations) . ' conversas pedindo atendimento humano. O alerta some quando alguém responder manualmente na conversa.',
                'href' => plan_allows('whatsapp') ? app_url('studio_whatsapp') : app_url('studio_settings'),
                'tone' => 'warn',
            ];
        }
        if (plan_allows('whatsapp')) {
            if (($whatsappStatusData['ok'] ?? false) && in_array((string)($whatsappStatusData['status'] ?? ''), ['disconnected', 'error'], true)) {
                $alerts[] = [
                    'title' => 'WhatsApp desconectado',
                    'description' => 'Verifique a sessão do WhatsApp para continuar recebendo e respondendo conversas.',
                    'href' => app_url('studio_whatsapp'),
                    'tone' => 'danger',
                ];
            }
            $pendingWhatsappCount = count($pendingWhatsappConversations);
            if ($pendingWhatsappCount > 0) {
                $alerts[] = [
                    'title' => 'Conversas esperando resposta',
                    'description' => 'Há ' . $pendingWhatsappCount . ' conversas que ainda aguardam retorno.',
                    'href' => app_url('studio_whatsapp'),
                    'tone' => 'warn',
                ];
            }
        } else {
            $alerts[] = [
                'title' => 'WhatsApp indisponível no plano atual',
                'description' => 'A integração com WhatsApp aparece a partir do plano Profissional.',
                'href' => app_url('studio_settings'),
                'tone' => 'warn',
            ];
        }
        $plan = current_studio_plan();
        if (is_array($plan)) {
            $planLimits = [
                'max_users' => ['label' => 'usuários', 'count' => studio_user_count((int)$studio['id'])],
                'max_tattooers' => ['label' => 'tatuadores', 'count' => studio_artist_count($studio)],
                'max_whatsapp_sessions' => ['label' => 'sessões WhatsApp', 'count' => studio_whatsapp_session_count($studio)],
            ];
            foreach ($planLimits as $limitKey => $info) {
                $limitValue = plan_limit($limitKey);
                if ($limitValue > 0 && $info['count'] >= (int)ceil($limitValue * 0.8) && $info['count'] < $limitValue) {
                    $alerts[] = [
                        'title' => 'Limite de ' . $info['label'] . ' próximo',
                        'description' => 'Seu plano está próximo do limite de ' . $info['label'] . '. Considere alterar para um plano superior.',
                        'href' => app_url('studio_settings'),
                        'tone' => 'warn',
                    ];
                }
            }
        }
        $focus = (string)($_GET['focus'] ?? '');
        $homeDrilldowns = [
            'attention_leads' => [
                'title' => 'Funil em aten&ccedil;&atilde;o',
                'summary' => $attentionLeadsTotal . ' leads quentes, parados ou com retorno pendente.',
                'type' => 'leads',
                'items' => array_map(static function (array $lead): array {
                    return [
                        'id' => $lead['id'] ?? null,
                        'name' => $lead['name'] ?? ($lead['phone'] ?? 'Lead'),
                        'phone' => $lead['phone'] ?? '',
                        'pipeline_stage' => $lead['pipeline_stage'] ?? '',
                        'status' => $lead['status'] ?? '',
                        'source' => $lead['source'] ?? '',
                        'lead_score' => $lead['lead_score'] ?? 0,
                        'interest' => $lead['interest'] ?? '',
                        'description' => $lead['description'] ?? '',
                        'updated_at' => $lead['updated_at'] ?? $lead['created_at'] ?? '',
                        'estimated_value' => $lead['estimated_value'] ?? 0,
                    ];
                }, $attentionLeads),
            ],
            'scheduled_month' => [
                'title' => 'Agendado de hoje at&eacute; o fim do m&ecirc;s',
                'summary' => 'Total projetado a partir de hoje: ' . format_money($scheduledToEndOfMonth),
                'type' => 'scheduled_month',
                'items' => $scheduledMonthItems,
                'filters' => [
                    '7d' => 'Pr&oacute;ximos 7 dias',
                    '15d' => 'Pr&oacute;ximos 15 dias',
                    'month' => 'Este m&ecirc;s',
                    'next_month' => 'M&ecirc;s que vem',
                ],
                'rangeMap' => [
                    '7d' => array_map($decorateAppointmentCard, studio_upcoming_appointments($studio, 7)),
                    '15d' => array_map($decorateAppointmentCard, studio_upcoming_appointments($studio, 15)),
                    'month' => $appointmentsMonthItems,
                    'next_month' => $nextMonthItems,
                ],
            ],
            'today_agenda' => [
                'title' => 'Agenda de hoje',
                'summary' => 'Hor&aacute;rio, cliente, status, valor e sinal do dia corrente.',
                'type' => 'appointments',
                'kind' => 'appointment',
                'items' => $todayAppointments,
            ],
            'meta_campaign' => [
                'title' => 'Leads da campanha META',
                'summary' => $metaCampaignSummary,
                'type' => 'meta_campaign',
                'default_range' => 'today',
                'tracking_hint' => implode(' | ', studio_meta_campaign_phrases($studio)),
                'items' => $metaCampaignItems,
                'filters' => array_map(static fn(array $range): string => (string)$range['label'], $metaCampaignRanges),
                'rangeMap' => $metaCampaignRangeMap,
                'all_items' => $metaCampaignAllItems,
                'comparison' => [
                    'internal_count' => $metaCampaignInternalCount,
                    'meta_count' => $metaCampaignMetaCount,
                    'delta' => $metaCampaignDelta,
                    'days' => 1,
                    'meta_ok' => (bool)($metaMessagingInsights['ok'] ?? false),
                ],
                'today_iso' => $current->format('Y-m-d'),
            ],
            'whatsapp_conversations' => [
                'title' => 'Conversas do WhatsApp que precisam de resposta',
                'summary' => plan_allows('whatsapp')
                    ? ('Aguardando resposta: ' . count($pendingWhatsappConversations) . ' | Pediram humano: ' . count($needsHumanConversations))
                    : 'WhatsApp n&atilde;o liberado no plano atual.',
                'type' => 'whatsapp',
                'items' => array_slice(array_values($whatsappConversationItems), 0, 10),
                'filterLabel' => 'Filtrar conversas',
            ],
            'free_windows' => [
                'title' => 'Pr&oacute;ximos hor&aacute;rios livres',
                'summary' => 'Primeiras janelas livres reais encontradas na agenda.',
                'type' => 'availability',
                'items' => array_slice($nextAvailableSlots, 0, 12),
                'filters' => [
                    '3d' => '3 dias',
                    '7d' => '7 dias',
                    '15d' => '15 dias',
                    'month' => 'Este m&ecirc;s',
                    'next_month' => 'M&ecirc;s que vem',
                ],
                'rangeMap' => [
                    '3d' => studio_schedule_available_slots($studio, 3, $current),
                    '7d' => studio_schedule_available_slots($studio, 7, $current),
                    '15d' => studio_schedule_available_slots($studio, 15, $current),
                    'month' => studio_schedule_available_slots($studio, max(1, (int)$monthEnd->diff($current)->days + 1), $current),
                    'next_month' => studio_schedule_available_slots($studio, (int)(new DateTimeImmutable('first day of next month', new DateTimeZone('America/Sao_Paulo')))->format('t'), new DateTimeImmutable('first day of next month', new DateTimeZone('America/Sao_Paulo'))),
                ],
            ],
            'available_slots' => [
                'title' => 'Vagas livres na agenda',
                'summary' => 'Dias &uacute;teis restantes: ' . $remainingWorkDays . ' | slots por dia: ' . $slotCount . ' | vagas livres estimadas: ' . $availableSlots,
                'type' => 'availability',
                'default_range' => '7d',
                'ranges' => (static function (array $studio): array {
                    $today = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
                    $monthEnd = new DateTimeImmutable('last day of this month 23:59:59', new DateTimeZone('America/Sao_Paulo'));
                    $nextMonthStart = new DateTimeImmutable('first day of next month', new DateTimeZone('America/Sao_Paulo'));
                    $rangeMap = [
                        '3d' => ['days' => 3, 'start' => $today],
                        '7d' => ['days' => 7, 'start' => $today],
                        '15d' => ['days' => 15, 'start' => $today],
                        'month' => ['days' => max(1, (int)$monthEnd->diff($today)->days + 1), 'start' => $today],
                        'next_month' => ['days' => max(1, (int)$nextMonthStart->format('t')), 'start' => $nextMonthStart],
                    ];
                    $result = [];
                    foreach ($rangeMap as $rangeKey => $rangeInfo) {
                        $days = (int)($rangeInfo['days'] ?? 7);
                        $result[$rangeKey] = [
                            'key' => $rangeKey,
                            'label' => [
                                '3d' => '3 dias',
                                '7d' => '7 dias',
                                '15d' => '15 dias',
                                'month' => 'Este m&ecirc;s',
                                'next_month' => 'M&ecirc;s que vem',
                            ][$rangeKey] ?? $rangeKey,
                            'items' => studio_schedule_available_slots($studio, $days, $rangeInfo['start'] ?? null),
                        ];
                    }
                    return $result;
                })($studio),
            ],
            'month_result' => [
                'title' => 'Resultado simples do mês',
                'summary' => 'Agenda no mês: ' . format_money($stats['month_revenue']) . ' | Despesas: ' . format_money($stats['month_expenses']) . ' | Saldo: ' . format_money($stats['month_revenue'] - $stats['month_expenses']),
                'type' => 'finance',
                'items' => [
                    ['label' => 'Agenda no mês', 'value' => format_money($stats['month_revenue'])],
                    ['label' => 'Despesas no mês', 'value' => format_money($stats['month_expenses'])],
                    ['label' => 'Saldo simples', 'value' => format_money($stats['month_revenue'] - $stats['month_expenses'])],
                ],
            ],
            'open_value' => [
                'title' => 'Valor em oportunidades abertas',
                'summary' => 'Soma estimada dos leads ainda n&atilde;o perdidos ou fechados: ' . format_money($stats['open_value']),
                'type' => 'finance',
                'items' => [
                    ['label' => 'Oportunidades abertas', 'value' => format_money($stats['open_value']), 'detail' => 'Leads em aberto e em conversa que ainda podem virar agendamento.'],
                    ['label' => 'Leads no funil', 'value' => (string)$stats['leads'], 'detail' => 'Quantidade atual de leads ativos no sistema.'],
                    ['label' => 'Clientes cadastrados', 'value' => (string)$stats['customers'], 'detail' => 'Base total de clientes no est&uacute;dio.'],
                ],
            ],
            'appointments' => [
                'title' => 'Pr&oacute;ximos atendimentos',
                'summary' => (string)$stats['appointments'] . ' atendimentos futuros ativos.',
                'kind' => 'appointment',
                'type' => 'appointments',
                'items' => array_slice(array_map($decorateAppointmentCard, $appointments), 0, 8),
                'filters' => [
                    '7d' => 'Pr&oacute;ximos 7 dias',
                    '15d' => 'Pr&oacute;ximos 15 dias',
                    'month' => 'Este m&ecirc;s',
                    'next_month' => 'M&ecirc;s que vem',
                ],
                'rangeMap' => [
                    '7d' => array_map($decorateAppointmentCard, studio_upcoming_appointments($studio, 7)),
                    '15d' => array_map($decorateAppointmentCard, studio_upcoming_appointments($studio, 15)),
                    'month' => $appointmentsMonthItems,
                    'next_month' => $nextMonthItems,
                ],
            ],
        ];
        echo '<div class="home-command-center">';
        echo '<section class="home-kpi-section"><div class="section-heading"><div><span class="section-eyebrow">Pulso do estúdio</span><h2>Hoje, em uma leitura</h2></div><span class="section-date">' . h(format_date_pt($todayIso)) . '</span></div>';
        echo '<div class="home-kpi-grid">';
        $homeKpis = [
            ['Leads hoje', (string)$newLeadsToday, 'Novas oportunidades', 'fa-bolt', 'attention_leads'],
            ['Conversas abertas', (string)count($pendingWhatsappConversations), 'Aguardando resposta', 'fa-comments', 'whatsapp_conversations'],
            ['Agenda de hoje', (string)$todayAppointmentsCount, 'Atendimentos ativos', 'fa-calendar-check', 'today_agenda'],
            ['Faturamento previsto', format_money($scheduledToEndOfMonth), 'Até o fim do mês', 'fa-arrow-trend-up', 'scheduled_month'],
            ['Meta Ads', is_array($metaInsights) && !empty($metaInsights['ok']) ? format_money((float)($metaInsights['spend'] ?? 0)) : '—', 'Gasto nos últimos 30 dias', 'fa-chart-line', 'meta_ads'],
        ];
        foreach ($homeKpis as [$label, $value, $hint, $icon, $target]) {
            if ($target === 'meta_ads') {
                echo '<a class="home-kpi-card" href="' . h(app_url('studio_meta_ads')) . '">';
            } else {
                echo '<button class="home-kpi-card" type="button" data-home-focus="' . h($target) . '">';
            }
            echo '<span class="home-kpi-icon"><i class="fa-solid ' . h($icon) . '"></i></span><span class="home-kpi-label">' . h($label) . '</span><strong>' . h($value) . '</strong><small>' . h($hint) . '</small>';
            echo $target === 'meta_ads' ? '</a>' : '</button>';
        }
        echo '</div></section>';
        echo '<section class="panel attention-panel"><div class="section-heading"><div><span class="section-eyebrow">Prioridades</span><h2>O que precisa de atenção agora</h2><p class="muted mb-0">Ordenado para você agir primeiro no que impacta a operação.</p></div><a class="btn secondary" href="' . h(app_url('studio_reports')) . '">Ver todos os alertas</a></div>';
        if (!$alerts) {
            echo '<div class="attention-empty"><i class="fa-solid fa-circle-check"></i><div><strong>Tudo sob controle</strong><span>Nenhum alerta importante no momento.</span></div></div>';
        } else {
            echo '<ul class="alert-list">';
            foreach ($alerts as $alert) {
                $tone = (string)($alert['tone'] ?? 'warn');
                echo '<li class="alert-list-item ' . h($tone) . '"><span class="alert-priority-dot"></span><span class="alert-list-text"><strong>' . h($alert['title'] ?? 'Alerta') . '</strong><small>' . h($alert['description'] ?? '') . '</small></span>' . (!empty($alert['href']) ? '<a class="btn tiny secondary" href="' . h((string)$alert['href']) . '">Resolver</a>' : '') . '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';

        echo '<section class="home-actions-section"><div class="section-heading"><div><span class="section-eyebrow">Próximos passos</span><h2>Atalhos inteligentes</h2><p class="muted mb-0">Acesse as rotinas mais usadas sem procurar no menu.</p></div></div>';
        echo '<div class="settings-overview-grid dashboard-home-blocks row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mt-3">';
        $homeCards = [
            ['label' => 'Responder WhatsApp', 'summary' => count($pendingWhatsappConversations) . ' conversas aguardando retorno.', 'focus' => 'whatsapp_conversations', 'icon' => 'fa-comments'],
            ['label' => 'Ver leads quentes', 'summary' => $attentionLeadsTotal . ' oportunidades pedem atenção.', 'focus' => 'attention_leads', 'icon' => 'fa-bolt'],
            ['label' => 'Abrir agenda', 'summary' => $todayAppointmentsCount . ' atendimentos previstos hoje.', 'focus' => 'today_agenda', 'icon' => 'fa-calendar-days'],
            ['label' => 'Acompanhar Meta Ads', 'summary' => $metaSpendLabel, 'focus' => 'meta_ads', 'icon' => 'fa-chart-line'],
            ['label' => 'Próximos horários livres', 'summary' => 'Encontre uma janela para agendar.', 'focus' => 'free_windows', 'icon' => 'fa-clock'],
            ['label' => 'Resultado do mês', 'summary' => 'Receita, despesas e saldo simplificado.', 'focus' => 'month_result', 'icon' => 'fa-wallet'],
        ];
        foreach ($homeCards as $card) {
            $isMetaAds = (string)($card['focus'] ?? '') === 'meta_ads';
            if ($isMetaAds) {
                echo '<a class="home-smart-action" href="' . h(app_url('studio_meta_ads')) . '"><span class="home-action-icon"><i class="fa-solid ' . h((string)$card['icon']) . '"></i></span><span><strong>' . h((string)$card['label']) . '</strong><small>' . h((string)$card['summary']) . '</small></span><i class="fa-solid fa-arrow-right"></i></a>';
                continue;
            }
            echo '<button type="button" class="home-smart-action" data-home-focus="' . h((string)$card['focus']) . '"><span class="home-action-icon"><i class="fa-solid ' . h((string)$card['icon']) . '"></i></span><span><strong>' . h((string)$card['label']) . '</strong><small>' . h((string)$card['summary']) . '</small></span><i class="fa-solid fa-arrow-right"></i></button>';
        }
        echo '</div></section></div>';
        echo '<div id="homeDrilldownModal" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1120px)"><div class="crm-panel-header"><div><h3 id="homeDrilldownTitle" class="crm-panel-title">Detalhe rápido</h3><p id="homeDrilldownSummary" class="muted" style="margin:4px 0 0"></p></div><button type="button" class="crm-button crm-icon-button" onclick="document.getElementById(\'homeDrilldownModal\').classList.add(\'hidden\')"><i class="fa-solid fa-xmark"></i></button></div><div id="homeDrilldownBody" class="p-4"></div></div></div>';
        echo '<div id="homeTodayAgendaModal" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1180px)"><div class="crm-panel-header"><div><h3 class="crm-panel-title">Agenda de hoje</h3><p class="muted" style="margin:4px 0 0">Leitura rápida dos agendamentos do dia.</p></div><button type="button" class="crm-button crm-icon-button" id="closeHomeTodayAgendaModal"><i class="fa-solid fa-xmark"></i></button></div><div id="homeTodayAgendaBody" class="p-4"></div></div></div>';
        echo '<script>window.homeDrilldowns = ' . json_encode(normalize_display_value($homeDrilldowns), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';window.homeTodayAgenda = ' . json_encode(normalize_display_value(['items' => $todayAppointments, 'date' => format_date_pt($todayIso)]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<script src="' . h(app_asset_url('assets/home_drilldown.js')) . '?v=' . h(app_build_version()) . '"></script>';
    }, $flash);
    exit;
}

if ($page === 'studio_customers') {
    redirect_to('studio_people', ['view' => 'customers']);
    exit;
}

if ($page === 'studio_customer') {
    $studio = require_studio();
    render_studio_shell('Ficha do cliente', 'Historico completo de lead, WhatsApp e agenda.', 'customers', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $customerId = (int)($_GET['id'] ?? 0);
        $customer = studio_find_customer($studio, $customerId);
        if (!$customer) {
            echo '<section class="panel"><h2>Cliente nao encontrado</h2><p class="muted">Volte para a lista e escolha outro cliente.</p><a class="btn" href="' . h(app_url('studio_customers')) . '">Abrir clientes</a></section>';
            return;
        }
        $activity = studio_customer_activity($studio, $customerId);
        $leads = studio_list_leads($studio);
        $artists = studio_list_artists($studio);
        $customerValue = static function (string $key, string $fallback = '') use ($customer): string {
            return (string)($customer[$key] ?? $fallback);
        };
        $linkedLead = $activity['leads'][0] ?? null;
        $publicUpdateUrl = '';
        if (is_array($linkedLead) && !empty($linkedLead['id'])) {
            $publicUpdateToken = studio_ensure_lead_public_update_token($studio, (int)$linkedLead['id']);
            if ($publicUpdateToken !== '') {
                $publicUpdateUrl = app_url('lead_public_update', ['lead' => (int)$linkedLead['id'], 'token' => $publicUpdateToken]);
            }
        }

        echo '<section class="lead-detail-head">';
        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><div><h2>' . h($customer['name'] ?: 'Cliente sem nome') . '</h2><p class="muted">' . h(($customer['phone'] ?: 'Sem telefone') . ' | ' . ($customer['instagram'] ?: 'sem Instagram')) . '</p></div><a class="btn secondary" href="' . h(app_url('studio_customers')) . '">Voltar</a></div>';
        if ($publicUpdateUrl !== '') {
            $publicShareMessage = 'Oi! Segue o link para atualizar seu cadastro: ' . $publicUpdateUrl;
            echo '<div class="actions" style="margin-top:14px;gap:8px;flex-wrap:wrap"><a class="btn secondary" href="' . h($publicUpdateUrl) . '" target="_blank" rel="noopener">Abrir cadastro público</a><button type="button" class="btn secondary" data-copy-link="' . h($publicUpdateUrl) . '">Copiar link</button><a class="btn secondary" href="https://wa.me/?text=' . h(rawurlencode($publicShareMessage)) . '" target="_blank" rel="noopener">Enviar no WhatsApp</a></div>';
        } else {
            echo '<p class="muted" style="margin-top:14px">Este cliente ainda não tem um lead vinculado para gerar o link público de atualização.</p>';
        }
        echo '<div class="grid cols-2" style="margin-top:12px"><div class="panel soft"><strong>' . h($customerValue('email', 'Sem email')) . '</strong><p class="muted" style="margin:4px 0 0">Email</p></div><div class="panel soft"><strong>' . h($customerValue('birth_date', 'Sem data de nascimento')) . '</strong><p class="muted" style="margin:4px 0 0">Nascimento</p></div></div>';
        echo '<div class="grid cols-2" style="margin-top:12px"><div class="panel soft"><strong>' . h($customerValue('body_area', 'Sem região do corpo')) . '</strong><p class="muted" style="margin:4px 0 0">rea</p></div><div class="panel soft"><strong>' . h($customerValue('reference_style', 'Sem referência')) . '</strong><p class="muted" style="margin:4px 0 0">Estilo</p></div></div>';
        echo '<div class="mini-metrics"><span><strong>' . h((string)count($activity['leads'])) . '</strong><small>Leads</small></span><span><strong>' . h((string)count($activity['appointments'])) . '</strong><small>Agendamentos</small></span><span><strong>' . h((string)count($activity['conversations'])) . '</strong><small>Conversas</small></span></div>';
        echo '</div>';

        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_customer"><input type="hidden" name="id" value="' . h((string)$customerId) . '"><input type="hidden" name="return_to_detail" value="1">';
        echo '<h2>Editar ficha</h2>';
        echo '<div class="grid cols-2"><div class="field"><label>Nome</label><input name="name" required value="' . h($customer['name'] ?? '') . '"></div><div class="field"><label>Telefone</label><input name="phone" value="' . h($customer['phone'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Email</label><input type="email" inputmode="email" name="email" value="' . h($customer['email'] ?? '') . '"></div><div class="field"><label>Instagram</label><input name="instagram" value="' . h($customer['instagram'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Data de nascimento</label><input type="date" name="birth_date" value="' . h($customer['birth_date'] ?? '') . '"></div><div class="field"><label>Documento</label><input name="document_number" value="' . h($customer['document_number'] ?? '') . '"></div><div class="field"><label>G&ecirc;nero</label><select name="gender"><option value="">Prefiro n&atilde;o informar</option><option value="Homem" ' . (($customer['gender'] ?? '') === 'Homem' ? 'selected' : '') . '>Homem</option><option value="Mulher" ' . (($customer['gender'] ?? '') === 'Mulher' ? 'selected' : '') . '>Mulher</option><option value="N&atilde;o bin&aacute;rio" ' . (in_array((string)($customer['gender'] ?? ''), ['N&atilde;o bin&aacute;rio', 'Nao binario', 'Não binário'], true) ? 'selected' : '') . '>N&atilde;o bin&aacute;rio</option><option value="Outro" ' . (($customer['gender'] ?? '') === 'Outro' ? 'selected' : '') . '>Outro</option></select></div></div><div class="grid cols-2"><div class="field"><label>Profiss&atilde;o</label><input name="occupation" value="' . h($customer['occupation'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>CEP</label><input name="address_zip" value="' . h($customer['address_zip'] ?? '') . '"></div><div class="field"><label>Rua</label><input name="address_street" value="' . h($customer['address_street'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Número</label><input name="address_number" value="' . h($customer['address_number'] ?? '') . '"></div><div class="field"><label>Complemento</label><input name="address_complement" value="' . h($customer['address_complement'] ?? '') . '"></div><div class="field"><label>Bairro</label><input name="address_neighborhood" value="' . h($customer['address_neighborhood'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Cidade</label><input name="address_city" value="' . h($customer['address_city'] ?? '') . '"></div><div class="field"><label>Estado</label><input name="address_state" value="' . h($customer['address_state'] ?? '') . '"></div><div class="field"><label>Referência</label><input name="address_reference" value="' . h($customer['address_reference'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Contato de emergência</label><input name="emergency_contact_name" value="' . h($customer['emergency_contact_name'] ?? '') . '"></div><div class="field"><label>Telefone de emergência</label><input name="emergency_contact_phone" value="' . h($customer['emergency_contact_phone'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Região do corpo</label><input name="body_area" value="' . h($customer['body_area'] ?? '') . '"></div><div class="field"><label>Estilo de referência</label><input name="reference_style" value="' . h($customer['reference_style'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Já possui tatuagens?</label><textarea name="previous_tattoos">' . h($customer['previous_tattoos'] ?? '') . '</textarea></div><div class="field"><label>Resistência à dor</label><select name="pain_tolerance">';
        render_options(['' => 'Selecionar', 'baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'], (string)($customer['pain_tolerance'] ?? ''));
        echo '</select></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Alergias</label><textarea name="allergies">' . h($customer['allergies'] ?? '') . '</textarea></div><div class="field"><label>Medicamentos</label><textarea name="medications">' . h($customer['medications'] ?? '') . '</textarea></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Condições de saúde</label><textarea name="health_conditions">' . h($customer['health_conditions'] ?? '') . '</textarea></div><div class="field"><label>Condições de pele</label><textarea name="skin_conditions">' . h($customer['skin_conditions'] ?? '') . '</textarea></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Histórico de queloide</label><input name="keloid_history" value="' . h($customer['keloid_history'] ?? '') . '"></div><div class="field"><label>Uso de anticoagulantes</label><input name="anticoagulants" value="' . h($customer['anticoagulants'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Diabetes</label><input name="diabetes" value="' . h($customer['diabetes'] ?? '') . '"></div><div class="field"><label>Problemas de cicatrização</label><input name="healing_issues" value="' . h($customer['healing_issues'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-2"><label class="checkline"><input type="checkbox" name="data_processing_consent" value="1"' . (!empty($customer['data_processing_consent']) ? ' checked' : '') . '> Consentimento LGPD</label><label class="checkline"><input type="checkbox" name="marketing_opt_in" value="1"' . (!empty($customer['marketing_opt_in']) ? ' checked' : '') . '> Quer receber marketing</label><label class="checkline"><input type="checkbox" name="whatsapp_opt_in" value="1"' . (!empty($customer['whatsapp_opt_in']) ? ' checked' : '') . '> WhatsApp</label><label class="checkline"><input type="checkbox" name="sms_opt_in" value="1"' . (!empty($customer['sms_opt_in']) ? ' checked' : '') . '> SMS</label><label class="checkline"><input type="checkbox" name="email_opt_in" value="1"' . (!empty($customer['email_opt_in']) ? ' checked' : '') . '> Email</label><label class="checkline"><input type="checkbox" name="push_opt_in" value="1"' . (!empty($customer['push_opt_in']) ? ' checked' : '') . '> Push futuro</label><label class="checkline"><input type="checkbox" name="social_network_opt_in" value="1"' . (!empty($customer['social_network_opt_in']) ? ' checked' : '') . '> Marcação em redes sociais</label><label class="checkline"><input type="checkbox" name="share_before_after_opt_in" value="1"' . (!empty($customer['share_before_after_opt_in']) ? ' checked' : '') . '> Antes/depois</label></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Canais preferidos</label><input name="marketing_channels" value="' . h($customer['marketing_channels'] ?? '') . '"></div><div class="field"><label>Redes sociais</label><input name="social_networks" value="' . h($customer['social_networks'] ?? '') . '"></div></div>';
        echo '<div class="field"><label>Observacoes</label><textarea name="notes">' . h($customer['notes'] ?? '') . '</textarea></div>';
        echo '<button class="btn" type="submit">Salvar ficha</button>';
        echo '</form></section>';

        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel"><h2>Leads deste cliente</h2>';
        render_leads_table($activity['leads']);
        echo '</div>';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_appointment"><input type="hidden" name="customer_id" value="' . h((string)$customerId) . '"><input type="hidden" name="import_source" value="customer"><input type="hidden" name="return_to_customer" value="' . h((string)$customerId) . '">';
        echo '<h2>Novo agendamento</h2>';
        echo '<div class="field"><label>Titulo</label><input name="title" required value="Atendimento"></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Lead</label><select name="lead_id"><option value="">Sem lead</option>';
        render_lead_options($leads);
        echo '</select></div><div class="field"><label>Tatuador</label><select name="artist_id">';
        render_artist_options($artists, default_artist_id($studio) ?? 0);
        echo '</select></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Data</label><input type="date" name="appointment_date" required value="' . h(date('Y-m-d')) . '"></div><div class="field"><label>Inicio</label><input type="time" name="start_time" required value="10:00"></div><div class="field"><label>Fim</label><input type="time" name="end_time" readonly></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Status</label><select name="status">';
        render_options(appointment_status_options(), 'pre_agendado');
        echo '</select></div><div class="field"><label>Valor</label><input name="value"></div><div class="field"><label>Sinal</label><input name="deposit_value"></div></div>';
        echo '<div class="field"><label>Descricao</label><textarea name="description" placeholder="Detalhes do atendimento..."></textarea></div>';
        echo '<button class="btn" type="submit">Agendar cliente</button>';
        echo '</form></section>';

        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel"><h2>Conversas WhatsApp</h2>';
        render_lead_conversations($activity['conversations']);
        echo '</div><div class="panel"><h2>Agendamentos</h2>';
        render_appointments_table($activity['appointments']);
        echo '</div></section>';
    }, $flash);
    exit;
}

if ($page === 'studio_leads') {
    $studio = require_studio();
    render_studio_shell('Funil de Leads', 'Acompanhe oportunidades, conversas e agendamentos do estúdio.', 'leads', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }

        $current = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
            'source' => trim((string)($_GET['source'] ?? '')),
            'min_score' => (int)($_GET['min_score'] ?? 0),
        ];
        $focus = strtolower(trim((string)($_GET['focus'] ?? '')));
        $stageFilter = trim((string)($_GET['stage'] ?? ''));
        $stages = studio_list_pipeline_stages($studio);
        $board = studio_pipeline_board($studio, $filters);
        $pipelineLeadIndex = [];
        $allLeads = [];
        $stageNames = [];
        foreach ($board as $stageName => $column) {
            $stageNames[] = (string)$stageName;
            foreach (($column['leads'] ?? []) as $lead) {
                $allLeads[] = $lead;
                $leadId = (int)($lead['id'] ?? 0);
                if ($leadId > 0) {
                    $pipelineLeadIndex[$leadId] = [
                        'id' => $leadId,
                        'name' => (string)($lead['name'] ?? ''),
                        'phone' => (string)($lead['phone'] ?? ''),
                        'interest' => (string)($lead['interest'] ?? ''),
                        'status' => (string)($lead['status'] ?? ''),
                        'pipeline_stage' => (string)($lead['pipeline_stage'] ?? ''),
                        'source' => (string)($lead['source'] ?? ''),
                        'lead_score' => (int)($lead['lead_score'] ?? 0),
                        'estimated_value' => (float)($lead['estimated_value'] ?? 0),
                        'created_at' => (string)($lead['created_at'] ?? ''),
                        'updated_at' => (string)($lead['updated_at'] ?? ''),
                        'customer_name' => (string)($lead['customer_name'] ?? ''),
                        'customer_id' => (int)($lead['customer_id'] ?? 0),
                        'artist_name' => (string)($lead['artist_name'] ?? $lead['tattoo_artist_name'] ?? $lead['responsible_name'] ?? ''),
                        'email' => (string)($lead['email'] ?? ''),
                        'notes' => (string)($lead['notes'] ?? ''),
                        'last_message_preview' => (string)($lead['last_message_preview'] ?? ''),
                        'description' => (string)($lead['description'] ?? ''),
                    ];
                }
            }
        }
        $initialLeadCount = count($allLeads);

        $isStaleLead = static function (array $lead) use ($current): bool {
            $updatedAt = (string)($lead['updated_at'] ?? $lead['created_at'] ?? '');
            if ($updatedAt === '') {
                return false;
            }
            try {
                return new DateTimeImmutable($updatedAt, new DateTimeZone('America/Sao_Paulo')) < $current->modify('-24 hours');
            } catch (Throwable) {
                return false;
            }
        };
        $isNewToday = static function (array $lead) use ($current): bool {
            $createdAt = (string)($lead['created_at'] ?? '');
            if ($createdAt === '') {
                return false;
            }
            try {
                return (new DateTimeImmutable($createdAt, new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d') === $current->format('Y-m-d');
            } catch (Throwable) {
                return false;
            }
        };
        $matchesFocus = static function (array $lead) use ($focus, $isStaleLead, $isNewToday): bool {
            return match ($focus) {
                'hot' => (int)($lead['lead_score'] ?? 0) >= 8,
                'stale' => $isStaleLead($lead),
                'today' => $isNewToday($lead),
                'pre_agendado', 'agendado' => (string)($lead['status'] ?? '') === $focus,
                'finalizado' => (string)($lead['pipeline_stage'] ?? '') === 'finalizado' || (string)($lead['status'] ?? '') === 'finalizado' || !empty($lead['finalized_from_appointment']),
                default => true,
            };
        };
        $matchesStage = static function (array $lead) use ($stageFilter): bool {
            return $stageFilter === '' || studio_normalize_pipeline_stage((string)($lead['pipeline_stage'] ?? '')) === studio_normalize_pipeline_stage($stageFilter);
        };

        if ($focus !== '' || $stageFilter !== '') {
            foreach ($board as $stageName => $column) {
                $filtered = array_values(array_filter($column['leads'] ?? [], static function (array $lead) use ($matchesFocus, $matchesStage): bool {
                    return $matchesFocus($lead) && $matchesStage($lead);
                }));
                $board[$stageName]['leads'] = $filtered;
                $board[$stageName]['total_value'] = array_reduce($filtered, static fn(float $sum, array $lead): float => $sum + (float)($lead['estimated_value'] ?? 0), 0.0);
                $board[$stageName]['total_count'] = count($filtered);
            }
            unset($stageName, $column);
            $allLeads = array_values(array_filter($allLeads, static function (array $lead) use ($matchesFocus, $matchesStage): bool {
                return $matchesFocus($lead) && $matchesStage($lead);
            }));
        }

        $openLeads = array_values(array_filter($allLeads, static fn(array $lead): bool => !in_array((string)($lead['status'] ?? ''), ['perdido', 'fechado', 'finalizado', 'atendido'], true) && (string)($lead['pipeline_stage'] ?? '') !== 'finalizado'));
        $openValue = array_reduce($openLeads, static fn(float $sum, array $lead): float => $sum + (float)($lead['estimated_value'] ?? 0), 0.0);
        $newLeadsToday = count(array_filter($openLeads, $isNewToday));
        $staleLeads = array_values(array_filter($openLeads, $isStaleLead));
        $hotLeads = array_values(array_filter($openLeads, static fn(array $lead): bool => (int)($lead['lead_score'] ?? 0) >= 8));
        $preScheduledLeads = array_values(array_filter($openLeads, static fn(array $lead): bool => (string)($lead['status'] ?? '') === 'pre_agendado'));
        $scheduledLeads = array_values(array_filter($openLeads, static fn(array $lead): bool => (string)($lead['status'] ?? '') === 'agendado'));

        $sources = [];
        foreach ($allLeads as $lead) {
            $source = trim((string)($lead['source'] ?? ''));
            if ($source !== '') {
                $sources[$source] = $source;
            }
        }
        asort($sources, SORT_NATURAL | SORT_FLAG_CASE);

        $leadLinks = [
            ['label' => 'Novo lead', 'href' => app_url('studio_lead', ['id' => 0]), 'safe' => false],
            ['label' => 'Ver todos', 'href' => app_url('studio_people', ['view' => 'leads']), 'safe' => true],
            ['label' => 'Abrir agenda', 'href' => app_url('studio_agenda'), 'safe' => true],
        ];
        if (function_exists('studio_lead_stage_export_url')) {
            $leadLinks[] = ['label' => 'Exportar', 'href' => studio_lead_stage_export_url($studio), 'safe' => true];
        }

        echo '<section class="panel dashboard-hero shadow-sm border-0" style="margin-bottom:16px">';
        echo '<div class="dashboard-hero-copy">';
        echo '<p class="muted" style="margin:0 0 6px">Funil comercial do estúdio</p>';
        echo '<div class="dashboard-hero-title"><h2 style="margin:0">Funil de Leads</h2><span class="badge ok">' . h(current_studio_plan_name()) . '</span></div>';
        echo '<p class="muted" style="margin:8px 0 0">Acompanhe oportunidades, conversas e agendamentos do estúdio.</p>';
        echo '</div>';
        echo '<div class="dashboard-hero-actions row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3">';
        foreach ($leadLinks as $action) {
            echo '<a class="quick-action-card h-100 text-start" href="' . h($action['href']) . '"><strong>' . h($action['label']) . '</strong><span class="muted">Abrir seção</span></a>';
        }
        echo '</div>';
        echo '</section>';

        echo '<form class="filter-bar panel row row-cols-1 row-cols-md-2 row-cols-xl-6 g-2 align-items-end" method="get" style="margin-bottom:16px">';
        echo '<input type="hidden" name="page" value="studio_leads">';
        echo '<div class="col"><input name="q" placeholder="Buscar por nome, telefone, interesse ou origem..." value="' . h($filters['q']) . '"></div>';
        echo '<div class="col"><select name="status"><option value="">Todos os status</option>';
        foreach (lead_status_options() as $key => $label) {
            echo '<option value="' . h($key) . '" ' . ($filters['status'] === $key ? 'selected' : '') . '>' . h($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col"><select name="source"><option value="">Todas as origens</option>';
        foreach ($sources as $source) {
            echo '<option value="' . h($source) . '" ' . ($filters['source'] === $source ? 'selected' : '') . '>' . h($source) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col"><select name="min_score">';
        foreach ([0 => 'Qualquer nota', 4 => 'Nota mínima 4', 7 => 'Nota mínima 7', 8 => 'Quentes (8+)'] as $key => $label) {
            echo '<option value="' . h((string)$key) . '" ' . ($filters['min_score'] === $key ? 'selected' : '') . '>' . h($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col"><select name="focus"><option value="">Todos os leads</option>';
        foreach ([
            'hot' => 'Quentes',
            'stale' => 'Parados',
            'today' => 'Hoje',
            'pre_agendado' => 'Pré-agendados',
            'agendado' => 'Agendados',
            'finalizado' => 'Finalizados',
        ] as $key => $label) {
            echo '<option value="' . h($key) . '" ' . ($focus === $key ? 'selected' : '') . '>' . h($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col d-flex gap-2 flex-wrap"><button class="btn secondary" type="submit">Filtrar</button><a class="btn secondary" href="' . h(app_url('studio_leads')) . '">Limpar</a></div>';
        echo '</form>';

        echo '<section class="grid cols-4 dashboard-kpis">';
        foreach ([
            ['value' => (string)count($openLeads), 'label' => 'Leads abertos'],
            ['value' => format_money($openValue), 'label' => 'Valor estimado total aberto'],
            ['value' => (string)$newLeadsToday, 'label' => 'Leads novos hoje'],
            ['value' => (string)count($staleLeads), 'label' => 'Leads parados 24h+'],
            ['value' => (string)count($hotLeads), 'label' => 'Leads quentes'],
            ['value' => (string)count($preScheduledLeads), 'label' => 'Pré-agendados'],
            ['value' => (string)count($scheduledLeads), 'label' => 'Agendados'],
        ] as $stat) {
            echo '<div class="panel dashboard-stat"><strong class="metric">' . h($stat['value']) . '</strong><p class="muted" style="margin:0">' . h($stat['label']) . '</p></div>';
        }
        echo '</section>';

        echo '<section class="panel" style="margin-top:16px">';
        echo '<div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2>Funil de Leads</h2><p class="muted">Etapas ordenadas, paginação por coluna e cartões compactos para ação rápida.</p></div><span class="badge">' . h((string)count($openLeads)) . ' leads abertos</span></div>';
        if (!$allLeads) {
            if ($initialLeadCount === 0) {
                echo '<div class="drilldown-empty"><strong>Nenhum lead cadastrado ainda.</strong><div class="muted">Crie o primeiro lead para começar a operar o funil.</div><a class="btn" href="' . h(app_url('studio_lead', ['id' => 0])) . '">Criar primeiro lead</a></div>';
            } else {
                echo '<div class="drilldown-empty"><strong>Nenhum lead encontrado para este filtro.</strong><div class="muted">Tente limpar os filtros ou buscar outra combinação.</div><a class="btn" href="' . h(app_url('studio_leads')) . '">Limpar filtros</a></div>';
            }
        }
        render_pipeline_board($board, $stages);
        echo '<div id="pipelineLeadModal" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,860px)"><div class="crm-panel-header"><div><h3 id="pipelineLeadModalTitle" class="crm-panel-title">Detalhe do lead</h3><p id="pipelineLeadModalSummary" class="muted" style="margin:4px 0 0"></p></div><button type="button" id="closePipelineLeadModal" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="pipelineLeadModalBody" class="p-4"></div></div></div>';
        echo '<script>window.pipelineLeadIndex = ' . json_encode($pipelineLeadIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; window.pipelineLeadMoveToken = ' . json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; window.pipelineStageNames = ' . json_encode($stageNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<script>(function(){const modal=document.getElementById("pipelineLeadModal");const title=document.getElementById("pipelineLeadModalTitle");const summary=document.getElementById("pipelineLeadModalSummary");const body=document.getElementById("pipelineLeadModalBody");const closeBtn=document.getElementById("closePipelineLeadModal");const index=window.pipelineLeadIndex||{};const token=window.pipelineLeadMoveToken||"";if(!modal||!title||!summary||!body)return;const esc=(value)=>String(value??"").replace(/[&<>"\x27]/g,(ch)=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#39;"}[ch]||ch));const money=(value)=>new Intl.NumberFormat("pt-BR",{style:"currency",currency:"BRL"}).format(Number(value)||0);const formatDate=(value)=>{if(!value)return"-";try{return new Intl.DateTimeFormat("pt-BR",{dateStyle:"short",timeStyle:"short"}).format(new Date(value.replace(" ","T")));}catch(e){return value;}};const statusTone=(status, stale)=>{if(["agendado","pre_agendado"].includes(status)) return "warn"; if(status==="fechado") return "ok"; if(["perdido","cancelado"].includes(status)) return "danger"; return stale ? "warn" : "neutral";};const postMove=async(leadId, stage, status)=>{const formData=new FormData();formData.append("csrf_token",token);formData.append("action","move_lead");formData.append("lead_id",leadId);formData.append("pipeline_stage",stage);formData.append("status",status||"");const response=await fetch(window.location.pathname+window.location.search,{method:"POST",body:formData});if(!response.ok) throw new Error("Nao foi possivel mover o lead."); location.reload();};const close=()=>modal.classList.add("hidden");const open=(leadId)=>{const lead=index[String(leadId)]||null;if(!lead)return;const status=String(lead.status||"");const score=Number(lead.lead_score||0);const stale=lead.updated_at&&lead.updated_at!==""?(()=>{try{return new Date(lead.updated_at) < new Date(Date.now()-24*60*60*1000);}catch(e){return false;}})():false;const badges=[];badges.push(`<span class="drilldown-badge ${statusTone(status, stale)}">${esc(status || "sem status")}</span>`);badges.push(`<span class="drilldown-badge neutral">${esc(String(score))}/10</span>`);if(score>=8) badges.push(`<span class="drilldown-badge ok">Quente</span>`);if((lead.estimated_value||0)>=1000) badges.push(`<span class="drilldown-badge neutral">Alto valor</span>`);if(stale) badges.push(`<span class="drilldown-badge warn">Parado 24h+</span>`);if(lead.artist_name) badges.push(`<span class="drilldown-badge neutral">${esc(lead.artist_name)}</span>`);title.textContent=lead.name || "Lead sem nome";summary.textContent=[lead.phone?`Telefone: ${lead.phone}`:"",lead.source?`Origem: ${lead.source}`:"",lead.pipeline_stage?`Etapa : ${lead.pipeline_stage}`:""].filter(Boolean).join(" · ");const currentIndex=Array.isArray(window.pipelineStageNames)?window.pipelineStageNames.indexOf(String(lead.pipeline_stage||"")):-1;const prevStage=currentIndex>0?window.pipelineStageNames[currentIndex-1]:"";const nextStage=currentIndex>=0&&currentIndex<window.pipelineStageNames.length-1?window.pipelineStageNames[currentIndex+1]:"";body.innerHTML=`<div class="drilldown-panel-grid"><div class="drilldown-panel-summary"><div class="drilldown-kpi"><strong>${esc(money(lead.estimated_value || 0))}</strong><span>Valor estimado</span><small>${esc(lead.interest || "Sem interesse descrito.")}</small></div><div class="drilldown-kpi"><strong>${esc(String(score))}/10</strong><span>Nota</span><small>Criado ${esc(formatDate(lead.created_at))}</small></div><div class="drilldown-kpi highlight"><strong>${esc(formatDate(lead.updated_at || lead.created_at))}</strong><span>Última atualização</span><small>${esc(lead.customer_name || lead.email || lead.notes || "Sem dados adicionais.")}</small></div></div><div class="drilldown-card compact"><div class="lead-card-badges">${badges.join("")}</div><div class="lead-card-submeta"><span class="muted">${esc(lead.phone || "Sem telefone")}</span><span class="muted">Cliente: ${esc(lead.customer_name || "-")}</span><span class="muted">Contato recente: ${esc(lead.last_message_preview || "-")}</span></div><div class="lead-card-actions lead-card-actions-quick">${lead.id ? `<a class="btn tiny secondary" href="index.php?page=studio_lead&id=${encodeURIComponent(lead.id)}">Ver lead</a>` : ""}${lead.phone ? `<a class="btn tiny secondary" href="https://wa.me/${String(lead.phone).replace(/\\D+/g,"")}" target="_blank" rel="noopener">WhatsApp</a>` : ""}${lead.id ? `<a class="btn tiny secondary" href="index.php?page=studio_lead&id=${encodeURIComponent(lead.id)}#lead-schedule-form">Agendar</a>` : ""}</div><div class="lead-card-actions">${prevStage?`<button type="button" class="btn tiny secondary" data-modal-move-stage="${esc(prevStage)}" data-modal-lead-id="${esc(String(lead.id||""))}" data-modal-status="${esc(status)}">Voltar</button>`:""}${nextStage?`<button type="button" class="btn tiny secondary" data-modal-move-stage="${esc(nextStage)}" data-modal-lead-id="${esc(String(lead.id||""))}" data-modal-status="${esc(status)}">Avancar</button>`:""}</div></div></div>`;modal.classList.remove("hidden");};document.querySelectorAll("[data-lead-open]").forEach((btn)=>{btn.addEventListener("click",(event)=>{event.preventDefault();event.stopPropagation();open(btn.getAttribute("data-lead-open"));});});document.querySelectorAll("[data-move-stage]").forEach((btn)=>{btn.addEventListener("click",async(event)=>{event.preventDefault();event.stopPropagation();try{await postMove(btn.getAttribute("data-lead-id")||"0", btn.getAttribute("data-move-stage")||"", btn.getAttribute("data-current-status")||"");}catch(err){alert(err.message||"Erro ao mover lead");}});});document.querySelectorAll("[data-modal-move-stage]").forEach((btn)=>{btn.addEventListener("click",async(event)=>{event.preventDefault();event.stopPropagation();try{await postMove(btn.getAttribute("data-modal-lead-id")||"0", btn.getAttribute("data-modal-move-stage")||"", btn.getAttribute("data-modal-status")||"");}catch(err){alert(err.message||"Erro ao mover lead");}});});let dragLeadId="";document.querySelectorAll(".pipeline-column").forEach((column)=>{column.addEventListener("dragover",(event)=>{event.preventDefault();column.classList.add("drag-over");});column.addEventListener("dragleave",()=>column.classList.remove("drag-over"));column.addEventListener("drop",async(event)=>{event.preventDefault();column.classList.remove("drag-over");const leadId=dragLeadId||event.dataTransfer.getData("text/plain");const stage=column.getAttribute("data-stage")||"";if(!leadId||!stage)return;const lead=index[String(leadId)];if(!lead)return;try{await postMove(leadId, stage, lead.status || "");}catch(err){alert(err.message||"Erro ao mover lead");}});});document.querySelectorAll(".lead-card[draggable=\"true\"]").forEach((card)=>{card.addEventListener("dragstart",(event)=>{dragLeadId=card.getAttribute("data-lead-id")||"";event.dataTransfer.effectAllowed="move";event.dataTransfer.setData("text/plain",dragLeadId);card.classList.add("dragging");});card.addEventListener("dragend",()=>{dragLeadId="";card.classList.remove("dragging");});card.addEventListener("click",(event)=>{if(event.target.closest("a,button")) return; const id=card.getAttribute("data-lead-id"); if(id) open(id);});});if(closeBtn) closeBtn.addEventListener("click",close);modal.addEventListener("click",(event)=>{if(event.target===modal) close();});document.addEventListener("keydown",(event)=>{if(event.key==="Escape") close();});})();</script>';
        echo '</section>';

        echo '<script>(function(){const sortOrder=["updated_desc","score_desc","value_desc","name_asc"];const sortLabel={updated_desc:"Recentes",score_desc:"Nota maior",value_desc:"Valor maior",name_asc:"Nome A-Z"};const parseDate=(value)=>{if(!value)return 0;const time=Date.parse(String(value).replace(" ","T"));return Number.isFinite(time)?time:0;};const compare=(mode,a,b)=>{if(mode==="score_desc")return (Number(b.dataset.leadScore||0)-Number(a.dataset.leadScore||0))||parseDate(b.dataset.leadUpdated)-parseDate(a.dataset.leadUpdated);if(mode==="value_desc")return (Number(b.dataset.leadValue||0)-Number(a.dataset.leadValue||0))||parseDate(b.dataset.leadUpdated)-parseDate(a.dataset.leadUpdated);if(mode==="name_asc")return String(a.dataset.leadName||"").localeCompare(String(b.dataset.leadName||""),"pt-BR",{sensitivity:"base"});return parseDate(b.dataset.leadUpdated)-parseDate(a.dataset.leadUpdated);};document.querySelectorAll(".pipeline-column").forEach((column)=>{const list=column.querySelector("[data-pipeline-list]");if(!list)return;const filter=column.querySelector("[data-pipeline-filter]");const select=column.querySelector("[data-pipeline-sort]");const title=column.querySelector("[data-pipeline-sort-toggle]");const prev=column.querySelector("[data-pipeline-prev]");const next=column.querySelector("[data-pipeline-next]");const label=column.querySelector("[data-pipeline-page-label]");const pager=column.querySelector("[data-pipeline-pagination]");const pageSize=Math.max(1,Number(column.dataset.pageSize||12));let page=1;let mode=select?.value||"updated_desc";const apply=()=>{const query=String(filter?.value||"").trim().toLocaleLowerCase("pt-BR");const cards=Array.from(list.querySelectorAll(".lead-card"));const matches=cards.filter((card)=>!query||String(card.dataset.leadSearch||"").toLocaleLowerCase("pt-BR").includes(query));matches.sort((a,b)=>compare(mode,a,b));matches.forEach((card)=>list.appendChild(card));const pages=Math.max(1,Math.ceil(matches.length/pageSize));page=Math.min(Math.max(1,page),pages);cards.forEach((card)=>{card.hidden=true;});matches.forEach((card,index)=>{card.hidden=index<((page-1)*pageSize)||index>=(page*pageSize);});if(label){const start=matches.length?((page-1)*pageSize)+1:0;const end=Math.min(page*pageSize,matches.length);label.textContent=`${start}-${end} de ${matches.length}`;}if(prev)prev.disabled=page<=1;if(next)next.disabled=page>=pages;if(pager)pager.hidden=matches.length<=pageSize&&!query;column.dataset.sortMode=mode;if(title){title.dataset.sortLabel=sortLabel[mode]||"Ordenar";title.setAttribute("aria-label",`Classificar por ${sortLabel[mode]||mode}`);}};filter?.addEventListener("input",()=>{page=1;apply();});select?.addEventListener("change",()=>{mode=select.value;page=1;apply();});title?.addEventListener("click",()=>{const current=sortOrder.indexOf(mode);mode=sortOrder[(current+1+sortOrder.length)%sortOrder.length];if(select)select.value=mode;page=1;apply();});prev?.addEventListener("click",()=>{page--;apply();});next?.addEventListener("click",()=>{page++;apply();});apply();});})();</script>';

        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel shadow-sm border-0"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h2>Leads que pedem atenção</h2><p class="muted">Os contatos mais urgentes para responder ou avançar hoje.</p></div><a class="btn secondary" href="' . h(app_url('studio_reports')) . '">Ver alertas</a></div>';
        if (!$hotLeads && !$staleLeads) {
            echo '<p class="muted">Sem leads pendentes no momento.</p>';
        } else {
            $attentionCards = [];
            foreach (array_merge($hotLeads, $staleLeads) as $lead) {
                $leadId = (int)($lead['id'] ?? 0);
                if ($leadId <= 0 || isset($attentionCards[$leadId])) {
                    continue;
                }
                $attentionCards[$leadId] = $lead;
            }
            $attentionCards = array_slice(array_values($attentionCards), 0, 8);
            $resolveConversationHref = static function (array $lead) use ($studio): string {
                $leadId = (int)($lead['id'] ?? 0);
                $customerId = (int)($lead['customer_id'] ?? 0);
                $phone = normalize_phone((string)($lead['phone'] ?? ''));
                $pdo = studio_db($studio);

                if ($leadId > 0) {
                    $stmt = $pdo->prepare('SELECT id FROM whatsapp_conversations WHERE lead_id = ? ORDER BY COALESCE(last_message_at, updated_at) DESC, id DESC LIMIT 1');
                    $stmt->execute([$leadId]);
                    $conversationId = (int)($stmt->fetchColumn() ?: 0);
                    if ($conversationId > 0) {
                        return app_url('studio_whatsapp_conversation', ['id' => $conversationId]);
                    }
                }

                if ($customerId > 0) {
                    $stmt = $pdo->prepare('SELECT id FROM whatsapp_conversations WHERE customer_id = ? ORDER BY COALESCE(last_message_at, updated_at) DESC, id DESC LIMIT 1');
                    $stmt->execute([$customerId]);
                    $conversationId = (int)($stmt->fetchColumn() ?: 0);
                    if ($conversationId > 0) {
                        return app_url('studio_whatsapp_conversation', ['id' => $conversationId]);
                    }
                }

                if ($phone !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM whatsapp_conversations WHERE phone = ? ORDER BY COALESCE(last_message_at, updated_at) DESC, id DESC LIMIT 1');
                    $stmt->execute([$phone]);
                    $conversationId = (int)($stmt->fetchColumn() ?: 0);
                    if ($conversationId > 0) {
                        return app_url('studio_whatsapp_conversation', ['id' => $conversationId]);
                    }
                }

                return '';
            };
            echo '<div class="stack-list">';
            foreach ($attentionCards as $lead) {
                $href = app_url('studio_lead', ['id' => (int)$lead['id']]);
                $phone = normalize_phone((string)($lead['phone'] ?? ''));
                $phoneLink = $phone !== '' ? 'https://wa.me/' . $phone : '';
                $conversationHref = $resolveConversationHref($lead);
                echo '<div class="activity-card">';
                echo '<strong><a href="' . h($href) . '">' . h($lead['name'] ?: 'Sem nome') . '</a></strong>';
                echo '<span class="muted">' . h(($lead['status'] ?: '-') . ' · ' . ($lead['pipeline_stage'] ?: '-') . ' · ' . ($lead['source'] ?: 'Sem origem')) . '</span>';
                echo '<span>' . h(($lead['interest'] ?: 'Sem interesse descrito.') . ' · ' . format_money($lead['estimated_value'] ?? 0)) . '</span>';
                echo '<div class="lead-card-actions lead-card-actions-quick">';
                echo '<span class="badge">' . h((string)($lead['lead_score'] ?? 0)) . '/10</span>';
                if ((int)($lead['lead_score'] ?? 0) >= 8) {
                    echo '<span class="badge ok">Quente</span>';
                }
                if ($phoneLink !== '') {
                    echo '<span class="badge">WhatsApp</span>';
                }
                echo '</div>';
                echo '<div class="lead-card-actions lead-card-actions-quick">';
                if ($conversationHref !== '') {
                    echo '<a class="btn tiny secondary" href="' . h($conversationHref) . '">Ver</a>';
                    echo '<a class="btn tiny secondary" href="' . h($conversationHref) . '">Abrir conversa</a>';
                } else {
                    echo '<span class="btn tiny secondary" aria-disabled="true" title="Sem conversa vinculada">Ver</span>';
                }
                if ($phoneLink !== '') {
                    echo '<a class="btn tiny secondary" href="' . h($phoneLink) . '" target="_blank" rel="noopener">WhatsApp</a>';
                }
                echo '<a class="btn tiny secondary" href="' . h($href . '#lead-schedule-form') . '">Agendar</a>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';

        echo '<div class="panel shadow-sm border-0"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h2>Filtro rápido de etapas</h2><p class="muted">Clique numa etapa para focar o trabalho comercial.</p></div><span class="badge">Status comercial</span></div>';
        echo '<div class="stack-list">';
        foreach ($board as $stageName => $column) {
            $count = count($column['leads'] ?? []);
            $value = (float)($column['total_value'] ?? 0);
            $href = app_url('studio_leads', [
                'q' => $filters['q'],
                'status' => $filters['status'],
                'source' => $filters['source'],
                'min_score' => $filters['min_score'] > 0 ? (string)$filters['min_score'] : '',
                'focus' => $focus,
                'stage' => $stageName,
            ]);
            echo '<a class="activity-card" href="' . h($href) . '"><strong>' . h($stageName) . '</strong><span class="muted">' . h($count . ' leads · ' . format_money($value)) . '</span><span>Clique para focar nesta etapa.</span></a>';
        }
        echo '</div></div>';
        echo '</section>';
    }, $flash);
    exit;
}

if ($page === 'studio_lead') {
    $studio = require_studio();
    render_studio_shell('Detalhe do lead', 'Historico, funil e proximas acoes.', 'leads', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $leadId = (int)($_GET['id'] ?? 0);
        if ($leadId <= 0) {
            $customers = studio_list_customers($studio);
            $stages = studio_list_pipeline_stages($studio);
            $artists = studio_list_artists($studio);
            echo '<section class="lead-detail-head">';
            echo '<form class="form panel" method="post" id="lead-new-form">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="save_lead"><input type="hidden" name="return_to_detail" value="1">';
            echo '<div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2>Novo lead</h2><p class="muted">Crie uma oportunidade nova para o funil do estúdio.</p></div><span class="badge">Cadastro</span></div>';
            echo '<div class="grid cols-2"><div class="field"><label>Nome</label><input name="name" placeholder="Nome do lead"></div><div class="field"><label>Telefone</label><input name="phone" placeholder="(11) 99999-9999"></div></div>';
            echo '<div class="field"><label>Cliente vinculado</label><select name="customer_id"><option value="">Sem vinculo</option>';
            render_customer_options($customers, 0);
            echo '</select></div>';
            echo '<div class="field"><label>Interesse</label><input name="interest" placeholder="Ex.: tatuagem fina no antebraço"></div>';
            echo '<div class="grid cols-3"><div class="field"><label>Status</label><select name="status">';
            render_options(lead_status_options(), 'novo');
            echo '</select></div><div class="field"><label>Etapa </label><select name="pipeline_stage">';
            foreach ($stages as $stage) {
                echo '<option value="' . h($stage['name']) . '">' . h(studio_pipeline_stage_display_name((string)$stage['name'])) . '</option>';
            }
            echo '</select></div><div class="field"><label>Nota 0-10</label><input type="number" name="lead_score" min="0" max="10" value="0"></div></div>';
            echo '<div class="grid cols-2"><div class="field"><label>Valor estimado</label><input name="estimated_value" value="0"></div><div class="field"><label>Origem</label><input name="source" placeholder="Instagram, WhatsApp, indicação..."></div></div>';
            echo '<div class="field"><label>Tatuador / responsável</label><select name="artist_id">';
            render_artist_options($artists, default_artist_id($studio) ?? 0);
            echo '</select></div>';
            echo '<button class="btn" type="submit">Salvar lead</button>';
            echo '</form>';
            echo '<div class="panel soft"><p class="muted">Dica</p><h3 style="margin-top:0">Depois de salvo, o lead já entra no funil e pode ser movido entre etapas com os botões do card.</h3></div>';
            echo '</section>';
            return;
        }
        $lead = studio_find_lead($studio, $leadId);
        if (!$lead) {
            echo '<section class="panel"><h2>Lead nao encontrado</h2><p class="muted">Volte para o funil e escolha outro lead.</p><a class="btn" href="' . h(app_url('studio_leads')) . '">Abrir funil</a></section>';
            return;
        }
        $customers = studio_list_customers($studio);
        $stages = studio_list_pipeline_stages($studio);
        $artists = studio_list_artists($studio);
        $activity = studio_lead_activity($studio, $leadId);
        $publicUpdateToken = studio_ensure_lead_public_update_token($studio, $leadId);
        $publicUpdateUrl = app_url('lead_public_update', ['lead' => $leadId, 'token' => $publicUpdateToken]);

        echo '<section class="lead-detail-head">';
        echo '<div class="panel"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2>' . h($lead['name'] ?: 'Lead sem nome') . '</h2><p class="muted">' . h(($lead['phone'] ?: 'Sem telefone') . ' | ' . ($lead['source'] ?: 'sem origem')) . '</p></div><strong class="score-pill">' . h((string)($lead['lead_score'] ?? 0)) . '/10</strong></div>';
        echo '<p>' . h($lead['interest'] ?: 'Sem interesse descrito.') . '</p>';
        echo '<div class="mini-metrics"><span><strong>' . h(format_money($lead['estimated_value'] ?? 0)) . '</strong><small>Valor estimado</small></span><span><strong>' . h($lead['status']) . '</strong><small>Status</small></span><span><strong>' . h($lead['pipeline_stage'] ?: '-') . '</strong><small>Etapa </small></span></div>';
        $shareMessage = 'Oi! Segue o link para atualizar seu cadastro: ' . $publicUpdateUrl;
        echo '<div class="actions" style="margin-top:14px;gap:8px;flex-wrap:wrap"><a class="btn secondary" href="' . h($publicUpdateUrl) . '" target="_blank" rel="noopener">Abrir formulário</a><button type="button" class="btn secondary" data-copy-link="' . h($publicUpdateUrl) . '">Copiar link</button><a class="btn secondary" href="https://wa.me/?text=' . h(rawurlencode($shareMessage)) . '" target="_blank" rel="noopener">Enviar no WhatsApp</a></div>';
        echo '</div>';
        echo '<form class="form panel" method="post" id="lead-move-form">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="move_lead"><input type="hidden" name="lead_id" value="' . h((string)$leadId) . '"><input type="hidden" name="return_to_detail" value="1">';
        echo '<div class="actions" style="justify-content:space-between"><h2>Mover no funil</h2><span class="badge">Fluxo</span></div>';
        echo '<div class="field"><label>Etapa </label><select name="pipeline_stage">';
        foreach ($stages as $stage) {
            echo '<option value="' . h($stage['name']) . '" ' . ((string)$stage['name'] === (string)$lead['pipeline_stage'] ? 'selected' : '') . '>' . h(studio_pipeline_stage_display_name((string)$stage['name'])) . '</option>';
        }
        echo '</select></div><div class="field"><label>Status</label><select name="status">';
        render_options(lead_status_options(), (string)$lead['status']);
        echo '</select></div><button class="btn" type="submit">Atualizar etapa</button>';
        echo '</form></section>';

        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<form class="form panel" method="post" id="lead-edit-form">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_lead"><input type="hidden" name="id" value="' . h((string)$leadId) . '"><input type="hidden" name="return_to_detail" value="1">';
        echo '<div class="actions" style="justify-content:space-between"><h2>Editar lead</h2><span class="badge">Dados</span></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Nome</label><input name="name" value="' . h($lead['name'] ?? '') . '"></div><div class="field"><label>Telefone</label><input name="phone" value="' . h($lead['phone'] ?? '') . '"></div></div>';
        echo '<div class="field"><label>Cliente vinculado</label><select name="customer_id"><option value="">Sem vinculo</option>';
        render_customer_options($customers, (int)($lead['customer_id'] ?? 0));
        echo '</select></div>';
        echo '<div class="field"><label>Interesse</label><input name="interest" value="' . h($lead['interest'] ?? '') . '"></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Status</label><select name="status">';
        render_options(lead_status_options(), (string)$lead['status']);
        echo '</select></div><div class="field"><label>Etapa </label><select name="pipeline_stage">';
        foreach ($stages as $stage) {
            echo '<option value="' . h($stage['name']) . '" ' . ((string)$stage['name'] === (string)$lead['pipeline_stage'] ? 'selected' : '') . '>' . h(studio_pipeline_stage_display_name((string)$stage['name'])) . '</option>';
        }
        echo '</select></div><div class="field"><label>Nota 0-10</label><input type="number" name="lead_score" min="0" max="10" value="' . h((string)($lead['lead_score'] ?? 0)) . '"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Valor estimado</label><input name="estimated_value" value="' . h((string)($lead['estimated_value'] ?? '0')) . '"></div><div class="field"><label>Origem</label><input name="source" value="' . h($lead['source'] ?? '') . '"></div></div>';
        echo '<button class="btn" type="submit">Salvar alteracoes</button>';
        echo '</form>';

        echo '<form class="form panel" method="post" id="lead-schedule-form">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_appointment"><input type="hidden" name="lead_id" value="' . h((string)$leadId) . '"><input type="hidden" name="customer_id" value="' . h((string)($lead['customer_id'] ?? 0)) . '"><input type="hidden" name="import_source" value="lead"><input type="hidden" name="return_to_lead" value="' . h((string)$leadId) . '">';
        echo '<div class="actions" style="justify-content:space-between"><h2>Agendar este lead</h2><span class="badge">Proximo passo</span></div>';
        echo '<div class="field"><label>Titulo</label><input name="title" required value="' . h($lead['interest'] ?: 'Atendimento') . '"></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Tatuador</label><select name="artist_id">';
        render_artist_options($artists, (int)($selectedAppointment['artist_id'] ?? default_artist_id($studio) ?? 0));
        echo '</select></div><div class="field"><label>Status</label><select name="status">';
        render_options(appointment_status_options(), 'pre_agendado');
        echo '</select></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Data</label><input type="date" name="appointment_date" required value="' . h(date('Y-m-d')) . '"></div><div class="field"><label>Inicio</label><input type="time" name="start_time" required value="10:00"></div><div class="field"><label>Fim</label><input type="time" name="end_time" readonly></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Valor</label><input name="value" value="' . h((string)($lead['estimated_value'] ?? '')) . '"></div><div class="field"><label>Sinal</label><input name="deposit_value"></div></div>';
        echo '<div class="field"><label>Descricao</label><textarea name="description" placeholder="Detalhes combinados com o cliente...">' . h($lead['interest'] ?? '') . '</textarea></div>';
        echo '<button class="btn" type="submit">Criar agendamento</button>';
        echo '</form></section>';

        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><h2>Historico rapido</h2><span class="badge">Resumo</span></div>';
        echo '<h3>Conversas WhatsApp</h3>';
        render_lead_conversations($activity['conversations']);
        echo '<h3>Agendamentos</h3>';
        render_appointments_table($activity['appointments']);
        echo '</div></section>';
    }, $flash);
    exit;
}

if ($page === 'studio_agenda') {
    $studio = require_studio();
    render_studio_shell('Agenda', 'Calendario, tatuadores e proximos atendimentos.', 'agenda', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $customers = studio_list_customers($studio);
        $leads = studio_list_leads($studio);
        $artists = studio_list_artists($studio);
        $appointments = studio_list_appointments($studio);
        $view = (string)($_GET['cal_view'] ?? 'month');
        if (!in_array($view, ['month', 'week', 'day', 'list'], true)) {
            $view = 'month';
        }
        $focus = parse_calendar_date((string)($_GET['date'] ?? date('Y-m-d')));
        [$startDate, $endDate] = calendar_range_for($view, $focus);
        $calendarAppointments = studio_calendar_appointments($studio, $startDate, $endDate);
        studio_apply_appointment_auto_status_rules($studio);
        $pomadaUnitPrice = (float)(studio_settings($studio)['pomada_unit_price'] ?? 100);
        $todayDate = date('Y-m-d');
        $todayAppointments = studio_calendar_appointments($studio, $todayDate, $todayDate);
        $nextAvailableSlots = array_values(array_filter(
            studio_schedule_available_slots($studio, 60, $focus),
            static fn(array $slot): bool => !empty($slot['allowed']) && !empty($slot['free_slots'])
        ));
        $preScheduledNoSignalCount = (int)studio_db($studio)->query("SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND status = 'pre_agendado' AND COALESCE(deposit_value, 0) <= 0")->fetchColumn();
        $missingArtistCount = (int)studio_db($studio)->query("SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND COALESCE(artist_id, 0) = 0 AND status NOT IN ('cancelado', 'perdido', 'concluido', 'atendido', 'finalizado')")->fetchColumn();
        $missingContactCount = (int)studio_db($studio)->query("SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND COALESCE(customer_id, 0) = 0 AND COALESCE(lead_id, 0) = 0 AND status NOT IN ('cancelado', 'perdido', 'concluido', 'atendido', 'finalizado')")->fetchColumn();
        $selectedAppointmentId = (int)($_GET['appointment_id'] ?? 0);
        $selectedAppointment = $selectedAppointmentId > 0 ? studio_find_appointment($studio, $selectedAppointmentId) : null;
        $googleCalendarConfigured = google_calendar_configured();
        $googleCalendarIntegration = google_calendar_integration($studio);
        $googleCalendarConnected = google_calendar_is_connected($googleCalendarIntegration);
        $importPreviewToken = trim((string)($_GET['ics_preview'] ?? ''));
        $importPreview = $importPreviewToken !== '' ? ($_SESSION['calendar_import_preview'][$importPreviewToken] ?? null) : null;
        $appointmentsPage = max(1, (int)($_GET['appointments_page'] ?? 1));
        $appointmentsPerPage = 12;
        $appointmentsTotal = count($appointments);
        $appointmentsTotalPages = max(1, (int)ceil($appointmentsTotal / $appointmentsPerPage));
        $appointmentsPage = min($appointmentsPage, $appointmentsTotalPages);
        $appointmentsOffset = ($appointmentsPage - 1) * $appointmentsPerPage;
        $appointmentsPageRows = array_slice($appointments, $appointmentsOffset, $appointmentsPerPage);

        echo '<section class="panel agenda-shell"><div class="agenda-focus-toolbar calendar-toolbar">';
        echo '<div class="agenda-toolbar-copy"><span class="badge ok">Agenda viva</span><h2 class="mb-0">Calendário</h2><p class="muted mb-0">O calendário fica no centro. Importação, Google e alertas ficam em opções.</p></div>';
        echo '<div class="calendar-toolbar-actions agenda-primary-actions">';
        foreach (['month' => 'Mes', 'week' => 'Semana', 'day' => 'Dia', 'list' => 'Blocos'] as $key => $label) {
            echo '<a class="btn ' . ($view === $key ? '' : 'secondary') . '" href="' . h(app_url('studio_agenda', ['cal_view' => $key, 'date' => $focus->format('Y-m-d')])) . '">' . h($label) . '</a>';
        }
        $prev = calendar_shift_date($view, $focus, -1);
        $next = calendar_shift_date($view, $focus, 1);
        echo '<a class="btn secondary" href="' . h(app_url('studio_agenda', ['cal_view' => $view, 'date' => $prev->format('Y-m-d')])) . '">Anterior</a>';
        echo '<a class="btn secondary" href="' . h(app_url('studio_agenda', ['cal_view' => $view, 'date' => date('Y-m-d')])) . '">Hoje</a>';
        echo '<a class="btn secondary" href="' . h(app_url('studio_agenda', ['cal_view' => $view, 'date' => $next->format('Y-m-d')])) . '">Proximo</a>';
        echo '<button type="button" class="btn secondary" id="openAgendaToolsButton"><i class="fa-solid fa-ellipsis"></i> Opções</button>';
        echo '</div>';
        echo '</div>';
        echo '<div id="agendaToolsModal" class="crm-modal hidden"><div class="crm-modal-panel agenda-tools-modal"><div class="crm-panel-header"><div><h3 class="crm-panel-title">Opções da agenda</h3><p class="muted" style="margin:4px 0 0">Todas as funções continuam aqui, sem poluir o calendário principal.</p></div><button type="button" id="closeAgendaToolsModal" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div class="p-4"><div class="agenda-tools-grid">';
        echo '<section class="panel soft agenda-tool-card"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h3 class="mt-0 mb-1">Importar ICS</h3><p class="muted mb-0">Envie um arquivo do Google Agenda para revisar antes de gravar.</p></div><span class="badge">arquivo</span></div>';
        echo '<form class="inline-form d-flex flex-wrap gap-2 align-items-end agenda-import-form" method="post" enctype="multipart/form-data">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="import_calendar_ics">';
        echo '<input type="file" name="ics_file" accept=".ics,text/calendar" required>';
        echo '<button class="btn secondary" type="submit">Importar ICS</button>';
        echo '</form></section>';
        echo '<section class="panel soft agenda-tool-card"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h3 class="mt-0 mb-1">Horários livres</h3><p class="muted mb-0">Veja rapidamente as próximas janelas disponíveis.</p></div><span class="badge ok">' . h((string)count($nextAvailableSlots)) . '</span></div><button type="button" class="btn secondary" id="openFreeSlotsButton">Próximos horários livres</button></section>';
        echo '<section class="google-calendar-sync-card ' . ($googleCalendarConnected ? 'is-connected' : '') . '">';
        echo '<div class="google-calendar-sync-hero">';
        echo '<span class="google-calendar-mark"><i class="fa-brands fa-google"></i></span>';
        echo '<div class="google-calendar-copy">';
        echo '<div class="google-calendar-title-row"><span class="section-eyebrow">Integração</span><h3>Google Agenda</h3>';
        if ($googleCalendarConnected) {
            $syncStatus = (string)($googleCalendarIntegration['last_sync_status'] ?? 'connected');
            $statusClass = $syncStatus === 'error' ? 'warn' : (!empty($googleCalendarIntegration['enabled']) ? 'ok' : '');
            $statusLabel = $syncStatus === 'error' ? 'atenção' : (!empty($googleCalendarIntegration['enabled']) ? 'automática' : 'pausada');
            echo '<span class="badge ' . h($statusClass) . '">' . h($statusLabel) . '</span>';
        } else {
            echo '<span class="badge">não conectada</span>';
        }
        echo '</div>';
        if (!$googleCalendarConfigured) {
            echo '<p class="muted">As credenciais OAuth ainda não estão instaladas no servidor.</p>';
        } elseif (!$googleCalendarConnected) {
            echo '<p class="muted">Conecte sua conta para buscar somente as alterações do Google a cada cinco minutos.</p>';
        } else {
            $calendarLabel = (string)($googleCalendarIntegration['calendar_name'] ?? $googleCalendarIntegration['calendar_id'] ?? 'Agenda principal');
            $accountLabel = (string)($googleCalendarIntegration['account_email'] ?? '');
            $lastSyncAt = trim((string)($googleCalendarIntegration['last_sync_at'] ?? ''));
            $outboundEnabled = !empty($googleCalendarIntegration['outbound_enabled']);
            echo '<p class="muted">Agenda conectada ao CRM. Você pode sincronizar agora, pausar a rotina automática ou escolher outro calendário sem sair desta tela.</p>';
        }
        echo '</div></div>';
        if ($googleCalendarConnected) {
            echo '<div class="google-calendar-meta-grid">';
            echo '<span class="google-calendar-meta-card"><small>Conta</small><strong>' . h($accountLabel !== '' ? $accountLabel : 'Conta Google') . '</strong></span>';
            echo '<span class="google-calendar-meta-card"><small>Calendário</small><strong>' . h($calendarLabel) . '</strong></span>';
            echo '<span class="google-calendar-meta-card"><small>Última sincronização</small><strong>' . h($lastSyncAt !== '' ? format_datetime_pt($lastSyncAt, false) : 'Pendente') . '</strong></span>';
            echo '<span class="google-calendar-meta-card"><small>CRM para Google</small><strong>' . ($outboundEnabled ? 'Ativado' : 'Desativado') . '</strong></span>';
            if (!empty($googleCalendarIntegration['last_sync_message'])) {
                echo '<p class="google-calendar-last-message"><i class="fa-solid fa-circle-info"></i> ' . h((string)$googleCalendarIntegration['last_sync_message']) . '</p>';
            }
            if (!empty($googleCalendarIntegration['outbound_last_message'])) {
                $outboundStatus = (string)($googleCalendarIntegration['outbound_last_status'] ?? '');
                $outboundClass = $outboundStatus === 'error' ? ' warn' : '';
                echo '<p class="google-calendar-last-message' . h($outboundClass) . '"><i class="fa-solid fa-arrow-up-right-from-square"></i> Último envio CRM -&gt; Google: ' . h((string)$googleCalendarIntegration['outbound_last_message']) . '</p>';
            }
            if ($outboundEnabled) {
                echo '<p class="google-calendar-last-message"><i class="fa-solid fa-key"></i> Se aparecer erro ao enviar, reconecte a conta Google com permissão para criar e editar eventos.</p>';
            }
            echo '</div>';
        }
        if ($googleCalendarConnected || $googleCalendarConfigured) {
            echo '<div class="google-calendar-sync-actions">';
        }
        if ($googleCalendarConfigured && !$googleCalendarConnected) {
            echo '<a class="btn" href="' . h(app_asset_url('google_calendar_oauth_start.php')) . '"><i class="fa-brands fa-google"></i> Conectar Google</a>';
        }
        if ($googleCalendarConnected) {
            $calendars = json_decode((string)($googleCalendarIntegration['calendars_json'] ?? '[]'), true);
            if (is_array($calendars) && count($calendars) > 1) {
                echo '<form method="post" class="google-calendar-select">';
                echo csrf_field() . '<input type="hidden" name="action" value="google_calendar_select">';
                echo '<label>Calendário<select name="calendar_id" onchange="this.form.submit()">';
                foreach ($calendars as $calendar) {
                    if (!is_array($calendar) || empty($calendar['id'])) {
                        continue;
                    }
                    $selected = (string)$calendar['id'] === (string)($googleCalendarIntegration['calendar_id'] ?? '') ? ' selected' : '';
                    echo '<option value="' . h((string)$calendar['id']) . '"' . $selected . '>' . h((string)($calendar['name'] ?? $calendar['id'])) . '</option>';
                }
                echo '</select></label></form>';
            }
            if (!empty($googleCalendarIntegration['enabled'])) {
                echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="google_calendar_sync_now"><button class="btn" type="submit">Sincronizar agora</button></form>';
            }
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="google_calendar_toggle"><input type="hidden" name="enabled" value="' . (!empty($googleCalendarIntegration['enabled']) ? '0' : '1') . '"><button class="btn secondary" type="submit">' . (!empty($googleCalendarIntegration['enabled']) ? 'Pausar automática' : 'Ativar automática') . '</button></form>';
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="google_calendar_outbound_toggle"><input type="hidden" name="outbound_enabled" value="' . (!empty($googleCalendarIntegration['outbound_enabled']) ? '0' : '1') . '"><button class="btn secondary" type="submit">' . (!empty($googleCalendarIntegration['outbound_enabled']) ? 'Desativar CRM -> Google' : 'Ativar CRM -> Google') . '</button></form>';
            echo '<form method="post" onsubmit="return confirm(\'Desconectar a conta Google? Os agendamentos já importados serão preservados.\')">' . csrf_field() . '<input type="hidden" name="action" value="google_calendar_disconnect"><button class="btn secondary danger-outline" type="submit">Desconectar</button></form>';
        }
        if ($googleCalendarConnected || $googleCalendarConfigured) {
            echo '</div>';
        }
        if ($googleCalendarConnected) {
            echo '<div class="google-calendar-sync-stats">';
            echo '<span><strong>' . h((string)($googleCalendarIntegration['last_sync_created'] ?? 0)) . '</strong><small>criados</small></span>';
            echo '<span><strong>' . h((string)($googleCalendarIntegration['last_sync_updated'] ?? 0)) . '</strong><small>atualizados</small></span>';
            echo '<span><strong>' . h((string)($googleCalendarIntegration['last_sync_unchanged'] ?? 0)) . '</strong><small>iguais</small></span>';
            echo '<span><strong>' . h((string)($googleCalendarIntegration['last_sync_cancelled'] ?? 0)) . '</strong><small>cancelados</small></span>';
            echo '</div>';
        }
        echo '</section>';
        echo '<section class="panel soft agenda-tool-card agenda-alerts-card"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h3 class="mt-0 mb-1">Alertas rápidos</h3><p class="muted mb-0">Sinais de atenção sem ocupar o topo do calendário.</p></div><span class="badge warn">status</span></div>';
        echo '<div class="alert-grid row row-cols-1 row-cols-md-2 g-3 mt-3">';
        echo '<article class="alert-card"><span class="badge warn">' . h((string)$preScheduledNoSignalCount) . '</span><p><strong>Pré-agendamentos sem sinal</strong></p><p class="muted">Há pré-agendamentos aguardando confirmação financeira.</p></article>';
        echo '<article class="alert-card"><span class="badge ok">' . h((string)count($todayAppointments)) . '</span><p><strong>Agendamentos de hoje</strong></p><p class="muted">Confira a ocupação do dia atual sem sair da agenda.</p></article>';
        echo '<article class="alert-card"><span class="badge danger">' . h((string)$missingArtistCount) . '</span><p><strong>Sem tatuador definido</strong></p><p class="muted">Agendamentos sem tatuador precisam de revisão.</p></article>';
        echo '<article class="alert-card"><span class="badge warn">' . h((string)$missingContactCount) . '</span><p><strong>Sem cliente/lead vinculado</strong></p><p class="muted">Esses agendamentos merecem vínculo para evitar perda de contexto.</p></article>';
        echo '</div></section>';
        echo '</div></div></div></div>';
        if (is_array($importPreview)) {
            $analysis = $importPreview['analysis'] ?? [];
            $candidates = $analysis['candidates'] ?? [];
            $skipped = $analysis['skipped'] ?? [];
            echo '<section class="panel import-preview-shell">';
            echo '<div class="import-preview-head">';
            echo '<div><span class="section-eyebrow">Google Agenda</span><h2 class="mb-1">Revisar sincronização</h2><p class="muted mb-0">Confira a lista compacta. Abra um evento somente quando precisar editar os detalhes.</p></div>';
            echo '<div class="import-preview-actions">';
            echo '<button class="btn secondary" type="button" data-import-toggle="all">Selecionar tudo</button>';
            echo '<button class="btn secondary" type="button" data-import-toggle="none">Desmarcar tudo</button>';
            echo '</div>';
            echo '</div>';
            echo '<form method="post" class="import-preview-form">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="import_calendar_ics_confirm">';
            echo '<input type="hidden" name="import_token" value="' . h($importPreviewToken) . '">';
            echo '<input type="hidden" name="selected_uids_json" value="">';
            echo '<input type="hidden" name="allow_conflicts_json" value="">';
            echo '<input type="hidden" name="item_overrides_json" value="">';
            echo '<div class="import-summary-strip">';
            echo '<span><strong>' . h((string)count($candidates)) . '</strong> candidatos</span>';
            echo '<span><strong>' . h((string)($analysis['duplicates'] ?? 0)) . '</strong> já importados</span>';
            echo '<span><strong>' . h((string)count($skipped)) . '</strong> ignorados</span>';
            echo '<span><strong>' . h((string)($analysis['events_total'] ?? 0)) . '</strong> no arquivo</span>';
            echo '</div>';
            echo '<div class="import-list-toolbar">';
            echo '<label class="import-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Buscar por nome, data ou horário" data-import-search></label>';
            echo '<span class="import-selected-count"><strong data-import-selected-count>' . h((string)count($candidates)) . '</strong> selecionados</span>';
            echo '<div class="import-pagination"><button class="btn tiny secondary" type="button" data-import-page="prev">Anterior</button><span data-import-page-label>Página 1</span><button class="btn tiny secondary" type="button" data-import-page="next">Próxima</button></div>';
            echo '</div>';
            echo '<div class="import-candidate-list" data-import-list>';
            foreach ($candidates as $candidate) {
                $uid = (string)($candidate['uid'] ?? '');
                $title = (string)($candidate['name'] ?? $candidate['raw_title'] ?? '');
                $rawTitle = (string)($candidate['raw_title'] ?? '');
                $description = (string)($candidate['description_original'] ?? '');
                $notes = (string)($candidate['notes'] ?? '');
                $conflicts = $candidate['conflicts'] ?? [];
                $alreadyImported = !empty($candidate['already_imported']);
                $dateLabel = format_date_pt((string)($candidate['date'] ?? ''), false);
                $startLabel = substr((string)($candidate['start_time'] ?? ''), 0, 5);
                $endLabel = substr((string)($candidate['end_time'] ?? ''), 0, 5);
                $searchText = studio_calendar_lower_text($title . ' ' . $rawTitle . ' ' . $dateLabel . ' ' . $startLabel);
                echo '<details class="import-candidate-row" data-import-candidate data-import-uid="' . h($uid) . '" data-import-search-text="' . h($searchText) . '">';
                echo '<summary class="import-candidate-summary">';
                echo '<label class="import-candidate-check" onclick="event.stopPropagation()"><input class="form-check-input import-select" type="checkbox" checked data-import-row="' . h($uid) . '"><span></span></label>';
                echo '<span class="import-candidate-when"><strong>' . h($dateLabel) . '</strong><small>' . h($startLabel . ($endLabel !== '' ? ' – ' . $endLabel : '')) . '</small></span>';
                echo '<span class="import-candidate-title"><strong>' . h($title) . '</strong><small>' . h($rawTitle !== $title ? $rawTitle : '') . '</small></span>';
                echo '<span class="import-candidate-badges">';
                if ($alreadyImported) {
                    echo '<span class="badge ok">sincronizar</span>';
                }
                if ($conflicts) {
                    echo '<span class="badge warn">conflito</span>';
                }
                if ((float)($candidate['value'] ?? 0) > 0) {
                    echo '<span class="badge">' . h(format_money((float)$candidate['value'])) . '</span>';
                }
                if (!empty($candidate['ai_review_required'])) {
                    echo '<span class="badge warn">revisar IA</span>';
                }
                echo '</span><i class="fa-solid fa-chevron-down import-candidate-chevron"></i>';
                echo '</summary>';
                echo '<div class="import-candidate-details">';
                if ($conflicts) {
                    echo '<div class="import-conflict-box"><strong>Conflito com a agenda atual</strong><div>';
                    foreach (array_slice($conflicts, 0, 3) as $conflict) {
                        $conflictName = (string)($conflict['customer_name'] ?? $conflict['title'] ?? 'Agendamento');
                        $conflictArtist = (string)($conflict['artist_name'] ?? 'sem tatuador');
                        $conflictStart = substr((string)($conflict['start_time'] ?? ''), 0, 5);
                        $conflictEnd = substr((string)($conflict['end_time'] ?? $conflict['start_time'] ?? ''), 0, 5);
                        echo '<span><strong>' . h(format_date_pt((string)$conflict['appointment_date']) . ' ' . $conflictStart . ($conflictEnd !== '' ? ' - ' . $conflictEnd : '')) . '</strong> ' . h($conflictName . ' · ' . $conflictArtist) . '</span>';
                    }
                    echo '</div><label class="import-allow-conflict"><input class="form-check-input" type="checkbox" data-import-allow-conflict><span>Sincronizar mesmo com este conflito</span></label>';
                    echo '</div>';
                }
                if (!empty($candidate['ai_parse_summary'])) {
                    echo '<div class="import-conflict-box" style="border-color:rgba(16,185,129,.24);background:rgba(16,185,129,.06)"><strong>Leitura inteligente do titulo</strong><div><span>' . h((string)$candidate['ai_parse_summary']) . '</span></div></div>';
                }
                echo '<div class="import-edit-grid">';
                foreach ([
                    ['name', 'Nome', 'text', $title],
                    ['date', 'Data', 'date', (string)($candidate['date'] ?? '')],
                    ['start_time', 'Início', 'time', $startLabel],
                    ['end_time', 'Fim', 'time', $endLabel],
                    ['phone', 'Telefone', 'text', (string)($candidate['phone'] ?? '')],
                    ['value', 'Valor', 'text', (string)($candidate['value'] ?? 0)],
                    ['appointment_status', 'Status', 'text', (string)($candidate['appointment_status'] ?? 'confirmado')],
                    ['status', 'Lead', 'text', (string)($candidate['status'] ?? 'agendado')],
                ] as [$field, $label, $type, $fieldValue]) {
                    echo '<label>' . h($label) . '<input type="' . h($type) . '" value="' . h($fieldValue) . '" data-import-field="' . h($field) . '" data-original="' . h($fieldValue) . '"></label>';
                }
                echo '</div>';
                $interestValue = trim($notes !== '' ? $notes . "\n" : '') . trim($description);
                echo '<label class="import-interest">Interesse/observações<textarea rows="2" data-import-field="interest" data-original="' . h($interestValue) . '">' . h($interestValue) . '</textarea></label>';
                echo '</div></details>';
            }
            if (!$candidates) {
                echo '<div class="alert-card"><p><strong>Nenhum candidato encontrado</strong></p><p class="muted">Esse arquivo não gerou eventos aptos para importação.</p></div>';
            }
            echo '</div>';
            echo '<div class="import-submit-bar">';
            echo '<p class="muted mb-0">Eventos já existentes serão atualizados quando data, horário ou conteúdo tiver mudado. Conflitos reais continuam exigindo confirmação.</p>';
            echo '<button class="btn" type="submit">Sincronizar selecionados</button>';
            echo '</div>';
            echo '</form>';
            if (!empty($_SESSION['calendar_import_last_batch'])) {
                echo '<form method="post" class="inline-form" style="margin-top:10px">';
                echo csrf_field();
                echo '<input type="hidden" name="action" value="undo_calendar_import">';
                echo '<button class="btn secondary" type="submit">Desfazer ultima importacao</button>';
                echo '</form>';
            }
            if ($skipped) {
                echo '<details style="margin-top:16px"><summary class="btn secondary" style="display:inline-flex;cursor:pointer">Ver eventos ignorados</summary>';
                echo '<div class="stack" style="margin-top:12px;gap:8px">';
                foreach (array_slice($skipped, 0, 12) as $item) {
                    echo '<div class="panel" style="padding:12px"><strong>' . h((string)($item['raw_title'] ?? '-')) . '</strong><div class="muted">' . h((string)($item['reason'] ?? 'ignorado')) . '</div></div>';
                }
                echo '</div></details>';
            }
            echo '</section>';
            echo '<script>
                (function () {
                    const form = document.querySelector(".import-preview-form");
                    if (!form) return;
                    const rows = Array.from(form.querySelectorAll("[data-import-candidate]"));
                    const search = form.querySelector("[data-import-search]");
                    const selectedCount = form.querySelector("[data-import-selected-count]");
                    const pageLabel = form.querySelector("[data-import-page-label]");
                    const previous = form.querySelector("[data-import-page=\"prev\"]");
                    const next = form.querySelector("[data-import-page=\"next\"]");
                    const pageSize = 30;
                    let currentPage = 1;
                    let filteredRows = rows;

                    const updateSelectedCount = () => {
                        if (selectedCount) {
                            selectedCount.textContent = String(form.querySelectorAll(".import-select:checked").length);
                        }
                    };

                    const renderPage = () => {
                        const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
                        currentPage = Math.min(Math.max(1, currentPage), totalPages);
                        const visible = new Set(filteredRows.slice((currentPage - 1) * pageSize, currentPage * pageSize));
                        rows.forEach((row) => { row.hidden = !visible.has(row); });
                        if (pageLabel) pageLabel.textContent = "Página " + currentPage + " de " + totalPages;
                        if (previous) previous.disabled = currentPage <= 1;
                        if (next) next.disabled = currentPage >= totalPages;
                    };

                    document.querySelectorAll("[data-import-toggle]").forEach((button) => {
                        button.addEventListener("click", () => {
                            const checked = button.getAttribute("data-import-toggle") === "all";
                            form.querySelectorAll(".import-select").forEach((input) => { input.checked = checked; });
                            updateSelectedCount();
                        });
                    });
                    form.querySelectorAll(".import-select").forEach((input) => input.addEventListener("change", updateSelectedCount));
                    if (search) {
                        search.addEventListener("input", () => {
                            const term = search.value.trim().toLocaleLowerCase("pt-BR");
                            filteredRows = term === ""
                                ? rows
                                : rows.filter((row) => (row.dataset.importSearchText || "").includes(term));
                            currentPage = 1;
                            renderPage();
                        });
                    }
                    if (previous) previous.addEventListener("click", () => { currentPage--; renderPage(); });
                    if (next) next.addEventListener("click", () => { currentPage++; renderPage(); });

                    form.addEventListener("submit", (event) => {
                        const selected = [];
                        const allowedConflicts = [];
                        const overrides = {};
                        rows.forEach((row) => {
                            const uid = row.dataset.importUid || "";
                            const checkbox = row.querySelector(".import-select");
                            if (uid === "" || !checkbox || !checkbox.checked) return;
                            selected.push(uid);
                            if (row.querySelector("[data-import-allow-conflict]:checked")) {
                                allowedConflicts.push(uid);
                            }
                            const changed = {};
                            row.querySelectorAll("[data-import-field]").forEach((field) => {
                                if (field.value !== (field.dataset.original || "")) {
                                    changed[field.dataset.importField] = field.value;
                                }
                            });
                            if (Object.keys(changed).length > 0) overrides[uid] = changed;
                        });
                        if (selected.length === 0) {
                            event.preventDefault();
                            window.alert("Selecione pelo menos um evento para sincronizar.");
                            return;
                        }
                        form.querySelector("[name=selected_uids_json]").value = JSON.stringify(selected);
                        form.querySelector("[name=allow_conflicts_json]").value = JSON.stringify(allowedConflicts);
                        form.querySelector("[name=item_overrides_json]").value = JSON.stringify(overrides);
                    });
                    updateSelectedCount();
                    renderPage();
                })();
            </script>';
        }
        echo '<div id="freeSlotsModal" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1100px)"><div class="crm-panel-header"><div><h3 class="crm-panel-title">Próximos horários livres</h3><p class="muted" style="margin:4px 0 0">Primeiras janelas livres encontradas na agenda.</p></div><button type="button" id="closeFreeSlotsModal" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div class="p-4"><div class="stack-list d-grid gap-2">';
        if (!$nextAvailableSlots) {
            echo '<p class="muted">Não foi possível encontrar vagas livres nas próximas semanas.</p>';
        } else {
            foreach (array_slice($nextAvailableSlots, 0, 12) as $slot) {
                $href = app_url('studio_agenda', ['date' => (string)$slot['date']]) . '#appointment-form';
                echo '<a class="activity-card" href="' . h($href) . '"><strong>' . h((string)$slot['label']) . '</strong><span class="muted">' . h(implode(' · ', array_slice($slot['free_slots'] ?? [], 0, 4))) . '</span><span>' . h((string)count($slot['free_slots'] ?? [])) . ' horários livres</span></a>';
            }
        }
        echo '</div></div></div></div>';
        echo '<div id="appointmentDetailModal" class="crm-modal hidden"><div class="crm-modal-panel appointment-detail-modal"><div class="crm-panel-header"><div><h3 id="appointmentDetailTitle" class="crm-panel-title">Agendamento</h3><p id="appointmentDetailSummary" class="muted" style="margin:4px 0 0"></p></div><button type="button" id="closeAppointmentDetailModal" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div class="p-4" id="appointmentDetailBody"></div></div></div>';
        echo '<script>(function(){const toolsBtn=document.getElementById("openAgendaToolsButton");const toolsModal=document.getElementById("agendaToolsModal");const closeTools=document.getElementById("closeAgendaToolsModal");const freeBtn=document.getElementById("openFreeSlotsButton");const freeModal=document.getElementById("freeSlotsModal");const closeFree=document.getElementById("closeFreeSlotsModal");const detailModal=document.getElementById("appointmentDetailModal");const closeDetail=document.getElementById("closeAppointmentDetailModal");const detailTitle=document.getElementById("appointmentDetailTitle");const detailSummary=document.getElementById("appointmentDetailSummary");const detailBody=document.getElementById("appointmentDetailBody");const csrfHtml=' . json_encode(csrf_field(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';const open=(modal)=>{if(modal)modal.classList.remove("hidden");};const close=(modal)=>{if(modal)modal.classList.add("hidden");};const esc=(value)=>String(value??"").replace(/[&<>"\x27]/g,(ch)=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#39;"}[ch]||ch));if(toolsBtn&&toolsModal)toolsBtn.addEventListener("click",()=>open(toolsModal));if(closeTools)closeTools.addEventListener("click",()=>close(toolsModal));if(freeBtn&&freeModal)freeBtn.addEventListener("click",()=>{close(toolsModal);open(freeModal);});if(closeFree)closeFree.addEventListener("click",()=>close(freeModal));if(closeDetail)closeDetail.addEventListener("click",()=>close(detailModal));document.addEventListener("click",(event)=>{const trigger=event.target instanceof Element?event.target.closest("[data-appointment-detail]"):null;if(!trigger||!detailModal||!detailTitle||!detailBody)return;let item={};try{item=JSON.parse(trigger.getAttribute("data-appointment-detail")||"{}");}catch(error){item={};}detailTitle.textContent=item.name||item.title||"Agendamento";detailSummary.textContent=[item.date_label,item.time_label,item.status].filter(Boolean).join(" · ");const alerts=Array.isArray(item.health_alerts)?item.health_alerts:[];const referenceHtml=item.reference_url?`<div class="appointment-reference-inline"><a href="${esc(item.reference_url)}" target="_blank" rel="noopener"><img src="${esc(item.reference_url)}" alt="Referência do agendamento"></a><div><strong>Referência principal</strong><p>${esc(item.reference_name||"Imagem enviada no WhatsApp")}</p></div></div>`:"";detailBody.innerHTML=`<div class="appointment-detail-grid"><div class="appointment-detail-kpi"><span>Quando</span><strong>${esc(item.date_label||"-")}</strong><small>${esc(item.time_label||"-")}</small></div><div class="appointment-detail-kpi"><span>Status</span><strong>${esc(item.status||"-")}</strong><small>${esc(item.origin_label||"Manual")}</small></div><div class="appointment-detail-kpi"><span>Tatuador</span><strong>${esc(item.artist||"-")}</strong><small>${esc(item.google_calendar_id?"Google Agenda":"CRM")}</small></div><div class="appointment-detail-kpi"><span>Valores</span><strong>${esc(item.value_label||"R$ 0,00")}</strong><small>Sinal ${esc(item.deposit_label||"R$ 0,00")}</small></div></div>${referenceHtml}<div class="panel soft appointment-detail-notes"><strong>Título original</strong><p>${esc(item.title||item.name||"-")}</p>${item.description?`<strong>Descrição</strong><p>${esc(item.description)}</p>`:""}${item.raw_title?`<strong>Origem/importação</strong><p>${esc(item.raw_title)}</p>`:""}${alerts.length?`<strong>Alertas de saúde</strong><div class="appointment-health-list">${alerts.map((alert)=>`<span class="badge warn">${esc(alert.label)}: ${esc(alert.detail)}</span>`).join("")}</div>`:""}</div><div class="actions appointment-detail-actions"><a class="btn" href="${esc(item.edit_url||"#")}">Editar agendamento</a><form method="post" class="inline-form" onsubmit="return confirm(\'Excluir este agendamento?\')">${csrfHtml}<input type="hidden" name="action" value="delete_appointment"><input type="hidden" name="appointment_id" value="${esc(item.id||"")}"><input type="hidden" name="appointment_date" value="${esc(item.date||"")}"><button type="submit" class="btn secondary">Excluir</button></form><button type="button" class="btn secondary" data-close-appointment-detail>Fechar</button></div>`;open(detailModal);});document.addEventListener("click",(event)=>{if(event.target instanceof Element&&event.target.closest("[data-close-appointment-detail]"))close(detailModal);});[toolsModal,freeModal,detailModal].forEach((modal)=>{if(!modal)return;modal.addEventListener("click",(event)=>{if(event.target===modal)close(modal);});});document.addEventListener("keydown",(event)=>{if(event.key==="Escape"){close(toolsModal);close(freeModal);close(detailModal);}});})();</script>';
        if ($view === 'month') {
            render_calendar_month($calendarAppointments, $focus, $pomadaUnitPrice);
        } elseif ($view === 'week') {
            render_calendar_week($calendarAppointments, $focus, $pomadaUnitPrice);
        } elseif ($view === 'day') {
            render_calendar_day($calendarAppointments, $focus, $pomadaUnitPrice);
        } else {
            render_calendar_list($calendarAppointments);
        }
        echo '</section>';

        if ($selectedAppointment) {
            $selectedDate = (string)($selectedAppointment['appointment_date'] ?? date('Y-m-d'));
            $selectedReferences = studio_list_appointment_references($studio, (int)($selectedAppointment['id'] ?? 0));
            if (!$selectedReferences && !empty($selectedAppointment['reference_image_path'])) {
                $selectedReferences = [[
                    'reference_image_path' => (string)$selectedAppointment['reference_image_path'],
                    'reference_image_name' => (string)($selectedAppointment['reference_image_name'] ?? ''),
                    'reference_image_mime' => (string)($selectedAppointment['reference_image_mime'] ?? ''),
                    'summary' => 'Referência principal do agendamento.',
                    'source' => (string)($selectedAppointment['import_source'] ?? 'manual'),
                ]];
            }
            echo '<section class="panel mt-3"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h2 class="mb-1">Detalhes do agendamento</h2><p class="muted mb-0">Clique num item da agenda para revisar, editar ou excluir sem perder o contexto.</p></div><a class="btn secondary" href="' . h(app_url('studio_agenda', ['date' => $selectedDate])) . '">Limpar selecao</a></div>';
            echo '<div class="grid cols-2 mt-3">';
            echo '<div class="panel soft"><p class="muted mb-1">Quando</p><h3 class="mt-0">' . h(format_date_pt($selectedDate) . ' ' . substr((string)$selectedAppointment['start_time'], 0, 5) . ($selectedAppointment['end_time'] ? ' - ' . substr((string)$selectedAppointment['end_time'], 0, 5) : '')) . '</h3><p class="muted mb-0">' . h($selectedAppointment['status']) . '</p></div>';
            echo '<div class="panel soft"><p class="muted mb-1">Cliente / Lead</p><h3 class="mt-0">' . h($selectedAppointment['customer_name'] ?: $selectedAppointment['lead_name'] ?: $selectedAppointment['title']) . '</h3><p class="muted mb-0">' . h($selectedAppointment['artist_name'] ?: 'Sem tatuador') . '</p></div>';
            $selectedValue = appointment_display_amount($selectedAppointment['value'] ?? 0);
            $selectedDeposit = appointment_display_amount($selectedAppointment['deposit_value'] ?? 0);
            $selectedPomadaUnit = isset($selectedAppointment['pomada_unit_price']) && $selectedAppointment['pomada_unit_price'] !== null && $selectedAppointment['pomada_unit_price'] !== ''
                ? appointment_display_amount($selectedAppointment['pomada_unit_price'])
                : $pomadaUnitPrice;
            $selectedEffective = max(0.0, $selectedValue + (max(0, (int)($selectedAppointment['pomadas_quantity'] ?? 0)) * $selectedPomadaUnit) - $selectedDeposit);
            echo '<div class="panel soft"><p class="muted">Valor</p><h3 style="margin-top:0">' . h(format_money($selectedValue)) . '</h3><p class="muted">Sinal ' . h(format_money($selectedDeposit)) . '</p><p class="muted">Total efetivo ' . h(format_money($selectedEffective)) . '</p></div>';
            echo '<div class="panel soft"><p class="muted">Pomadas</p><h3 style="margin-top:0">' . h((string)($selectedAppointment['pomadas_quantity'] ?? 0)) . '</h3><p class="muted">Quantidade vinculada ao agendamento</p></div>';
            echo '<div class="panel soft"><p class="muted">Origem</p><h3 style="margin-top:0">' . h(appointment_origin_label((string)($selectedAppointment['import_source'] ?? 'manual'))) . '</h3><p class="muted">' . h((string)($selectedAppointment['raw_title'] ?? '')) . '</p></div>';
            echo '</div>';
            $healthAlerts = studio_appointment_health_alerts_from_row($selectedAppointment);
            if ($healthAlerts) {
                echo '<div class="panel" style="margin-top:12px;border-left:4px solid #eab308;background:#fffbeb"><strong>Alertas de saúde</strong><div class="stack-list" style="margin-top:10px">';
                foreach ($healthAlerts as $alert) {
                    echo '<div class="activity-card"><strong>' . h((string)$alert['label']) . '</strong><span>' . h((string)$alert['detail']) . '</span></div>';
                }
                echo '</div></div>';
            }
            if (!empty($selectedAppointment['description'])) {
                echo '<div class="field"><label>Descricao</label><div class="info-box">' . h($selectedAppointment['description']) . '</div></div>';
            }
            if ($selectedReferences) {
                echo '<div class="field"><label>Referências da tatuagem</label><div class="appointment-reference-gallery">';
                foreach ($selectedReferences as $index => $reference) {
                    $referencePath = trim((string)($reference['reference_image_path'] ?? ''));
                    if ($referencePath === '') {
                        continue;
                    }
                    $refUrl = preg_match('/^https?:\/\//i', $referencePath) ? $referencePath : app_url($referencePath);
                    $refMime = strtolower(trim((string)($reference['reference_image_mime'] ?? '')));
                    $refName = trim((string)($reference['reference_image_name'] ?? ''));
                    $refSummary = trim((string)($reference['summary'] ?? ''));
                    echo '<a class="appointment-reference-card" href="' . h($refUrl) . '" target="_blank" rel="noopener">';
                    if ($refMime === '' || str_starts_with($refMime, 'image/')) {
                        echo '<img src="' . h($refUrl) . '" alt="' . h($refName !== '' ? $refName : 'Referência ' . ($index + 1)) . '">';
                    } else {
                        echo '<div class="appointment-reference-file"><i class="fa-regular fa-file"></i></div>';
                    }
                    echo '<span class="badge">Ref. ' . h((string)($index + 1)) . '</span>';
                    echo '<strong>' . h($refName !== '' ? $refName : 'Imagem de referência') . '</strong>';
                    if ($refSummary !== '') {
                        echo '<small>' . h($refSummary) . '</small>';
                    }
                    echo '</a>';
                }
                echo '</div></div>';
            }
            echo '<div class="actions" style="margin-top:14px">';
            echo '<form method="post" class="inline-form">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="mark_appointment_status">';
            echo '<input type="hidden" name="appointment_id" value="' . h((string)(int)$selectedAppointment['id']) . '">';
            echo '<input type="hidden" name="appointment_date" value="' . h($selectedDate) . '">';
            echo '<input type="hidden" name="status" value="falta">';
            echo '<button class="btn secondary" type="submit" onclick="return confirm(\'Marcar este agendamento como falta?\')">Marcar falta</button>';
            echo '</form>';
            echo '<button class="btn" type="button" id="openSelectedAppointmentEdit">Editar este agendamento</button>';
            echo '<form method="post" onsubmit="return confirm(\'Excluir este agendamento?\')" class="inline-form">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="delete_appointment">';
            echo '<input type="hidden" name="appointment_id" value="' . h((string)(int)$selectedAppointment['id']) . '">';
            echo '<input type="hidden" name="appointment_date" value="' . h($selectedDate) . '">';
            echo '<button class="btn secondary" type="submit">Excluir</button>';
            echo '</form>';
            echo '</div></section>';
        }

        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<button type="button" class="panel dashboard-stat" id="openNewSlotOverlay"><p class="metric">' . h($selectedAppointment ? 'Editar selecionado' : 'Novo horário') . '</p><p class="muted">' . h($selectedAppointment ? 'Abrir formulário do agendamento selecionado' : 'Abrir formulário em overlay') . '</p></button>';
        echo '<button type="button" class="panel dashboard-stat" id="openAgendaTableOverlay"><p class="metric">Agenda cadastrada</p><p class="muted">Ver lista paginada em overlay</p></button>';
        echo '</section>';
        echo '<div id="newSlotOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,980px)"><div class="crm-panel-header"><div><h3 class="crm-panel-title">' . h($selectedAppointment ? 'Editar horario' : 'Novo horario') . '</h3><p class="muted" style="margin:4px 0 0">Cadastre ou ajuste um atendimento sem sair da agenda.</p></div><button type="button" id="closeNewSlotOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="newSlotOverlayBody" class="p-4"></div></div></div>';
        echo '<div id="agendaTableOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1200px)"><div class="crm-panel-header"><div><h3 class="crm-panel-title">Agenda cadastrada</h3><p class="muted" style="margin:4px 0 0">Lista paginada de agendamentos.</p></div><button type="button" id="closeAgendaTableOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="agendaTableOverlayBody" class="p-4"></div></div></div>';
        echo '<div id="appointmentFormSource" hidden>';
        echo '<section class="grid cols-2" id="appointment-form">';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_appointment">';
        echo '<input type="hidden" name="id" value="' . h((string)($selectedAppointment['id'] ?? 0)) . '">';
        echo '<h2>' . h($selectedAppointment ? 'Editar horario' : 'Novo horario') . '</h2>';
        echo '<div class="field"><label>Titulo</label><input name="title" required value="' . h($selectedAppointment['title'] ?? 'Atendimento') . '"></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Origem</label><select name="import_source">';
        render_options(appointment_origin_options(), (string)($selectedAppointment['import_source'] ?? 'manual'));
        echo '</select></div><div class="field"><label>Origem bruta</label><input name="raw_title" value="' . h($selectedAppointment['raw_title'] ?? '') . '" placeholder="Titulo original importado, se houver"></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Cliente</label><select name="customer_id"><option value="">Sem cliente</option>';
        render_customer_options($customers, (int)($selectedAppointment['customer_id'] ?? 0));
        echo '</select></div><div class="field"><label>Lead</label><select name="lead_id"><option value="">Sem lead</option>';
        render_lead_options($leads, (int)($selectedAppointment['lead_id'] ?? 0));
        echo '</select></div><div class="field"><label>Tatuador</label><select name="artist_id">';
        render_artist_options($artists, (int)($selectedAppointment['artist_id'] ?? default_artist_id($studio) ?? 0));
        echo '</select></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Data</label><input type="date" name="appointment_date" required value="' . h($selectedAppointment['appointment_date'] ?? date('Y-m-d')) . '"></div><div class="field"><label>Inicio</label><input type="time" name="start_time" required value="' . h(substr((string)($selectedAppointment['start_time'] ?? '10:00'), 0, 5)) . '"></div><div class="field"><label>Fim</label><input type="time" name="end_time" readonly value="' . h(substr((string)($selectedAppointment['end_time'] ?? ''), 0, 5)) . '"></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Status</label><select name="status">';
        render_options(appointment_status_options(), (string)($selectedAppointment['status'] ?? 'pre_agendado'));
        echo '</select></div><div class="field"><label>Valor</label><input name="value" placeholder="600,00" value="' . h((string)($selectedAppointment['value'] ?? '')) . '"></div><div class="field"><label>Sinal</label><input name="deposit_value" placeholder="100,00" value="' . h((string)($selectedAppointment['deposit_value'] ?? '')) . '"></div></div>';
        echo '<div class="field"><label>Quantidade de pomadas</label><input type="number" min="0" step="1" name="pomadas_quantity" value="' . h((string)($selectedAppointment['pomadas_quantity'] ?? 0)) . '"></div>';
        echo '<div class="field"><label>Descricao</label><textarea name="description" placeholder="Detalhes do atendimento, local do corpo, referencia, observacoes...">' . h($selectedAppointment['description'] ?? '') . '</textarea></div>';
        echo '<button class="btn" type="submit">' . h($selectedAppointment ? 'Salvar alteracoes' : 'Salvar horario') . '</button>';
        echo '</form>';
        echo '</section>';
        echo '</div>';
        echo '<div id="agendaTableSource" hidden><section class="panel" style="margin-top:16px"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2>Agenda cadastrada</h2><p class="muted">Página ' . h((string)$appointmentsPage) . ' de ' . h((string)$appointmentsTotalPages) . '</p></div>';
        if ($appointmentsTotalPages > 1) {
            echo '<div class="actions" style="gap:8px;flex-wrap:wrap">';
            if ($appointmentsPage > 1) {
                echo '<a class="btn secondary" href="' . h(app_url('studio_agenda', ['cal_view' => $view, 'date' => $focus->format('Y-m-d'), 'appointments_page' => $appointmentsPage - 1])) . '">Anterior</a>';
            }
            if ($appointmentsPage < $appointmentsTotalPages) {
                echo '<a class="btn secondary" href="' . h(app_url('studio_agenda', ['cal_view' => $view, 'date' => $focus->format('Y-m-d'), 'appointments_page' => $appointmentsPage + 1])) . '">Proxima</a>';
            }
            echo '</div>';
        }
        echo '</div>';
        render_appointments_table($appointmentsPageRows);
        echo '</section></div>';
        echo '<script>(function(){const openNew=document.getElementById("openNewSlotOverlay");const openSelectedEdit=document.getElementById("openSelectedAppointmentEdit");const openTable=document.getElementById("openAgendaTableOverlay");const newModal=document.getElementById("newSlotOverlay");const tableModal=document.getElementById("agendaTableOverlay");const newBody=document.getElementById("newSlotOverlayBody");const tableBody=document.getElementById("agendaTableOverlayBody");const newSource=document.getElementById("appointmentFormSource");const tableSource=document.getElementById("agendaTableSource");const closeNew=document.getElementById("closeNewSlotOverlay");const closeTable=document.getElementById("closeAgendaTableOverlay");const openAppointmentForm=()=>{if(!newModal||!newBody||!newSource)return;newBody.innerHTML=newSource.innerHTML;newModal.classList.remove("hidden");};if(openNew)openNew.addEventListener("click",openAppointmentForm);if(openSelectedEdit)openSelectedEdit.addEventListener("click",openAppointmentForm);if(openTable&&tableModal&&tableBody&&tableSource){openTable.addEventListener("click",()=>{tableBody.innerHTML=tableSource.innerHTML;tableModal.classList.remove("hidden");});}if(location.hash==="#appointment-form"&&openSelectedEdit){setTimeout(openAppointmentForm,60);}if(closeNew) closeNew.addEventListener("click",()=>newModal.classList.add("hidden"));if(closeTable) closeTable.addEventListener("click",()=>tableModal.classList.add("hidden"));[newModal,tableModal].forEach((modal)=>{if(!modal)return;modal.addEventListener("click",(event)=>{if(event.target===modal) modal.classList.add("hidden");});});document.addEventListener("keydown",(event)=>{if(event.key==="Escape"){if(newModal) newModal.classList.add("hidden");if(tableModal) tableModal.classList.add("hidden");}});})();</script>';
    }, $flash);
    exit;
}

if ($page === 'studio_whatsapp') {
    $studio = require_studio();
    render_studio_shell('WhatsApp', 'Central de conversas, API oficial e respostas do estúdio.', 'whatsapp', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $settings = studio_settings($studio);
        $isOfficialWhatsApp = true;
        $sessionKey = studio_session_key($studio);
        $serviceStatus = studio_whatsapp_service_status($studio);
        $summary = studio_whatsapp_summary($studio);
        $filters = [
            'q' => (string)($_GET['q'] ?? ''),
            'mode' => (string)($_GET['mode'] ?? ''),
            'needs_human' => !empty($_GET['needs_human']),
            'min_score' => (int)($_GET['min_score'] ?? 0),
            'filter' => (string)($_GET['filter'] ?? ''),
            'visibility' => (string)($_GET['visibility'] ?? 'all'),
            'date_filter' => (string)($_GET['date_filter'] ?? ''),
            'date_from' => (string)($_GET['date_from'] ?? ''),
            'date_to' => (string)($_GET['date_to'] ?? ''),
            'tag_id' => (int)($_GET['tag_id'] ?? 0),
            'offset' => max(0, (int)($_GET['conv_offset'] ?? 0)),
        ];
        if ($filters['visibility'] === '') {
            $filters['visibility'] = 'all';
        }
        if ($filters['visibility'] === '') {
            $filters['visibility'] = 'all';
        }
        $conversationPageSize = 30;
        $conversations = studio_list_whatsapp_conversations($studio, $filters, $conversationPageSize);
        $nextConversationOffset = (int)$filters['offset'] + $conversationPageSize;
        $hasMoreConversations = count(studio_list_whatsapp_conversations($studio, array_merge($filters, ['offset' => $nextConversationOffset]), $conversationPageSize)) > 0;
        $conversationPageSize = 30;
        $serviceState = (string)($serviceStatus['status'] ?? 'offline');
        if ($isOfficialWhatsApp) {
            $serviceStateLabel = !empty($serviceStatus['ready']) ? 'API oficial ativa' : 'Configuracao incompleta';
        } else {
            $serviceStateLabel = $serviceState === 'connected' ? 'Conectado' : ($serviceState === 'waiting_qr' ? 'Aguardando codigo' : ($serviceState === 'starting' ? 'Iniciando' : 'Nao conectado'));
        }
        $firstConversationHref = !empty($conversations[0]['id']) ? app_url('studio_whatsapp_conversation', ['id' => (int)$conversations[0]['id']]) : app_url('studio_whatsapp');
        $conversationsPage = max(1, (int)($_GET['wa_page'] ?? 1));
        $conversationsPerPage = 12;
        $conversationsTotal = count($conversations);
        $conversationsTotalPages = max(1, (int)ceil($conversationsTotal / $conversationsPerPage));
        $conversationsPage = min($conversationsPage, $conversationsTotalPages);
        $conversationsOffset = ($conversationsPage - 1) * $conversationsPerPage;
        $conversationsPageRows = array_slice($conversations, $conversationsOffset, $conversationsPerPage);
        $statusBadgeClass = !empty($serviceStatus['ready']) ? 'ok' : 'danger';
        $statusSummary = !empty($serviceStatus['ready']) ? 'API oficial pronta' : 'Configuração incompleta';
        $statusDetail = !empty($serviceStatus['ready'])
            ? 'Webhook e envios estão configurados para a Meta Cloud API.'
            : 'Revise credenciais, phone number ID, webhook e token em Configurações.';
        echo '<section class="panel whatsapp-hero">';
        echo '<div class="whatsapp-hero-copy"><div class="topbar-kicker">WhatsApp do estúdio</div><h2>Atendimento oficial</h2><p class="muted">Esta tela agora é só uma entrada limpa para o atendimento. As conversas ficam oficialmente no app mobile/PWA.</p><div class="actions whatsapp-hero-actions"><a class="btn" href="' . h(app_url('studio_whatsapp_mobile')) . '"><i class="fa-solid fa-mobile-screen-button"></i> Abrir atendimento</a><a class="btn secondary" href="' . h(app_url('studio_settings', ['tab' => 'whatsapp'])) . '#settings-whatsapp"><i class="fa-solid fa-gear"></i> Configurações</a></div></div>';
        echo '<div class="whatsapp-hero-sidebar"><div class="whatsapp-session-summary-card"><span class="badge ' . h($statusBadgeClass) . '">' . h($statusSummary) . '</span><strong>' . h($statusDetail) . '</strong><span class="muted">Último webhook: ' . h((string)($serviceStatus['last_webhook_at'] ?? '') !== '' ? (string)$serviceStatus['last_webhook_at'] : 'sem registro') . '</span></div>';
        echo '<div class="whatsapp-hero-stats"><div class="whatsapp-hero-stat"><strong>' . h((string)$summary['total']) . '</strong><span>Conversas</span></div><div class="whatsapp-hero-stat"><strong>' . h((string)$summary['human']) . '</strong><span>Humano</span></div><div class="whatsapp-hero-stat"><strong>' . h((string)$summary['analyzed']) . '</strong><span>IA</span></div><div class="whatsapp-hero-stat"><strong>' . h((string)($summary['avg_score'] ?: '-')) . '</strong><span>Nota média</span></div></div></div>';
        echo '</section>';
        echo '<section class="panel whatsapp-list-panel"><div class="actions" style="justify-content:space-between;align-items:center"><div><h2>Conversas recentes</h2><p class="muted">Atalhos rápidos para continuar no atendimento oficial.</p></div><a class="btn tiny secondary" href="' . h(app_url('studio_whatsapp_mobile')) . '">Ver todas</a></div>';
        if (!$conversationsPageRows) {
            echo '<p class="muted">Nenhuma conversa registrada ainda.</p>';
        } else {
            echo '<div class="drilldown-grid">';
            foreach (array_slice($conversationsPageRows, 0, 8) as $row) {
                $rowId = (int)($row['id'] ?? 0);
                $rowName = trim((string)($row['customer_name'] ?? ''));
                if ($rowName === '') { $rowName = trim((string)($row['lead_name'] ?? '')); }
                if ($rowName === '') { $rowName = trim((string)($row['name'] ?? '')); }
                if ($rowName === '' || $rowName === 'Cliente WhatsApp' || $rowName === 'Contato WhatsApp') {
                    $rowName = trim((string)($row['phone'] ?? 'Contato WhatsApp'));
                }
                $preview = trim((string)($row['latest_message_preview'] ?? $row['last_message_preview'] ?? 'Sem prévia'));
                $mode = (string)($row['attendance_mode'] ?? 'bot') === 'human' ? 'Humano' : 'IA';
                $needsHuman = !empty($row['needs_human']);
                echo '<a class="drilldown-card compact" href="' . h(app_url('studio_whatsapp_mobile', ['id' => $rowId])) . '"><span class="badge ' . h($needsHuman ? 'warn' : 'ok') . '">' . h($needsHuman ? 'Atenção' : $mode) . '</span><strong>' . h($rowName) . '</strong><div class="muted">' . h(mb_substr($preview, 0, 120)) . '</div><small class="muted">' . h(format_datetime_pt((string)($row['message_last_at'] ?? $row['updated_at'] ?? ''), false)) . '</small></a>';
            }
            echo '</div>';
        }
        echo '</section>';
        return;
        echo '<section class="panel whatsapp-hero">';
        echo '<div class="whatsapp-hero-copy"><div class="topbar-kicker">WhatsApp do estudio</div><h2>Central de conversas e API oficial</h2><p class="muted">Acompanhe a saude da Meta Cloud API, webhooks, envios e conversas do CRM em um unico lugar.</p><div class="actions whatsapp-hero-actions"><a class="btn" href="' . h(app_url('studio_whatsapp_workspace')) . '">Abrir workspace</a><button type="button" class="btn secondary" id="openWhatsAppStatusOverlay">Ver status</button><button type="button" class="btn secondary" id="openManualMessageOverlay">Mensagem manual</button></div></div>';
        echo '<div class="whatsapp-hero-sidebar">';
        if ($isOfficialWhatsApp) {
            $statusBadgeClass = !empty($serviceStatus['ready']) ? 'ok' : 'danger';
            $statusSummary = !empty($serviceStatus['ready']) ? 'Meta Cloud API pronta para envio e webhook' : 'Complete as credenciais da API oficial';
            $statusHint = 'Ultimo webhook: ' . ((string)($serviceStatus['last_webhook_at'] ?? '') !== '' ? (string)$serviceStatus['last_webhook_at'] : 'ainda sem registro') . '. FFMPEG: ' . (!empty($serviceStatus['service_health']['ffmpeg']) ? 'encontrado' : 'nao encontrado');
        } else {
            $statusBadgeClass = $serviceState === 'connected' ? 'ok' : ($serviceState === 'waiting_qr' ? 'warn' : 'danger');
            $statusSummary = $serviceState === 'connected' ? ('Conectado no numero ' . preg_replace('/\D+/', '', (string)($serviceStatus['phone'] ?? ''))) : ($serviceState === 'waiting_qr' ? 'Codigo pronto para parear' : ($serviceState === 'starting' ? 'Solicitando o codigo de pareamento' : ($serviceState === 'disconnected' ? 'Sessao desconectada' : 'Nao conectado')));
            $statusHint = !empty($serviceStatus['pairingCode']) ? 'Use o codigo abaixo para parear o WhatsApp.' : ($serviceState === 'connected' ? 'A sessao esta pronta para receber e responder mensagens.' : 'Use as acoes abaixo para iniciar ou recuperar a sessao.');
        }
        echo '<div class="whatsapp-session-summary-card"><span class="badge ' . h($statusBadgeClass) . '">' . h($serviceStateLabel) . '</span><strong>' . h($statusSummary) . '</strong><span class="muted">' . h($statusHint) . '</span></div>';
        if (!empty($serviceStatus['pairingCode'])) {
            echo '<div class="wa-pairing-code-inline">' . h((string)$serviceStatus['pairingCode']) . '</div>';
        }
        if (!empty($serviceStatus['lastError'])) {
            echo '<div class="whatsapp-hero-error"><strong>Último erro</strong><span class="muted">' . h((string)$serviceStatus['lastError']) . '</span></div>';
        }
        echo '<div class="whatsapp-hero-stats"><div class="whatsapp-hero-stat"><strong>' . h((string)$summary['total']) . '</strong><span>Total de conversas</span></div><div class="whatsapp-hero-stat"><strong>' . h((string)$summary['human']) . '</strong><span>Em humano</span></div><div class="whatsapp-hero-stat"><strong>' . h((string)$summary['analyzed']) . '</strong><span>Com IA</span></div><div class="whatsapp-hero-stat"><strong>' . h((string)($summary['avg_score'] ?: '-')) . '</strong><span>Nota média</span></div></div>';
        echo '</div></section>';
        echo '<section class="quick-actions-grid whatsapp-quick-links row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3">';
        echo '<a class="panel quick-action-card h-100 text-start" href="' . h(app_url('studio_whatsapp_workspace')) . '"><strong>Abrir workspace</strong><span>' . h((string)$summary['human']) . ' em humano</span><small>Visual tipo WhatsApp Web com CRM embutido</small></a>';
        echo '<button type="button" class="panel quick-action-card h-100 text-start" id="openWhatsAppStatusOverlay"><strong>' . h($serviceStateLabel) . '</strong><span>' . h($isOfficialWhatsApp ? 'Saude da API oficial' : 'Status e pareamento') . '</span><small>' . h($isOfficialWhatsApp ? 'Ver webhook, envios, erros e credenciais' : 'Ver conexao, pareamento e acoes') . '</small></button>';
        echo '<button type="button" class="panel quick-action-card h-100 text-start" id="openWhatsAppReadingOverlay"><strong>Leitura rápida</strong><span>' . h((string)$summary['analyzed']) . ' analisadas</span><small>Resumo do fluxo atual</small></button>';
        echo '<button type="button" class="panel quick-action-card h-100 text-start" id="openWhatsAppConversationsOverlay"><strong>Conversas importadas</strong><span>' . h((string)$conversationsTotal) . ' registros</span><small>Ver lista paginada</small></button>';
        echo '</section>';
        echo '<div id="whatsappStatusOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,980px)"><div class="crm-panel-header"><div><h3 class="crm-panel-title">Status do WhatsApp</h3><p class="muted" style="margin:4px 0 0">Saude da API oficial, webhook, envios e falhas.</p></div><button type="button" id="closeWhatsAppStatusOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="whatsappStatusOverlayBody" class="p-4"></div></div></div>';
        echo '<div id="whatsappStatusSource" hidden>';
        if ($isOfficialWhatsApp) {
            echo '<div class="panel" id="wa-session-panel"><div class="actions" style="justify-content:space-between"><h2>API oficial do WhatsApp</h2><span id="waStatusBadge" class="badge ' . h(!empty($serviceStatus['ready']) ? 'ok' : 'danger') . '">' . h($serviceStateLabel) . '</span></div>';
            echo '<div class="mini-metrics"><span><strong>' . h((string)($serviceStatus['score'] ?? 0)) . '/' . h((string)($serviceStatus['total'] ?? 0)) . '</strong><small>Checks</small></span><span><strong>' . h((string)($serviceStatus['last_webhook_at'] ?? '-') ?: '-') . '</strong><small>Ultimo webhook</small></span><span><strong>' . h((string)($serviceStatus['last_outbound_at'] ?? '-') ?: '-') . '</strong><small>Ultimo envio</small></span></div>';
            if (!empty($serviceStatus['lastError'])) {
                echo '<div class="whatsapp-hero-error"><strong>Ultimo erro</strong><span class="muted">' . h((string)$serviceStatus['lastError']) . '</span></div>';
            }
            echo '<div class="grid cols-2">';
            foreach (($serviceStatus['checks'] ?? []) as $check) {
                if (!is_array($check)) {
                    continue;
                }
                echo '<div class="drilldown-card compact"><span class="badge ' . h(!empty($check['ok']) ? 'ok' : 'danger') . '">' . h(!empty($check['ok']) ? 'OK' : 'Pendente') . '</span><strong>' . h((string)($check['label'] ?? 'Check')) . '</strong><div class="muted">' . h((string)($check['value'] ?? '')) . '</div></div>';
            }
            echo '</div>';
            echo '<div class="panel soft" style="margin-top:14px"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h3 style="margin:0">Eventos recentes</h3><p class="muted" style="margin:4px 0 0">Envios, webhooks, status da Meta e falhas ficam registrados aqui.</p></div><a class="btn tiny secondary" href="' . h(app_url('studio_settings', ['tab' => 'whatsapp'])) . '#settings-whatsapp">Configurar API</a></div>';
            $recentEvents = is_array($serviceStatus['recent_events'] ?? null) ? $serviceStatus['recent_events'] : [];
            if (!$recentEvents) {
                echo '<p class="muted">Ainda nao ha eventos oficiais registrados nesta tabela.</p>';
            } else {
                echo '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Quando</th><th>Tipo</th><th>Direcao</th><th>Status</th><th>Erro</th></tr></thead><tbody>';
                foreach (array_slice($recentEvents, 0, 10) as $event) {
                    echo '<tr><td>' . h((string)($event['created_at'] ?? '')) . '</td><td>' . h((string)($event['event_type'] ?? '')) . '</td><td>' . h((string)($event['direction'] ?? '')) . '</td><td>' . h((string)($event['status'] ?? '')) . '</td><td>' . h(mb_substr((string)($event['error'] ?? ''), 0, 120)) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div></div>';
        } else {
        echo '<div class="panel" id="wa-session-panel"><div class="actions" style="justify-content:space-between"><h2>Sessão do WhatsApp</h2>';
        $badgeClass = $serviceState === 'connected' ? 'ok' : ($serviceState === 'waiting_qr' ? 'warn' : 'danger');
        echo '<span id="waStatusBadge" class="badge ' . h($badgeClass) . '">' . h($serviceStateLabel) . '</span></div>';
        $sessionSummary = 'Nao conectado';
        $connectedPhone = preg_replace('/\D+/', '', (string)($serviceStatus['phone'] ?? ''));
        if ($connectedPhone !== '') {
            $sessionSummary = 'Conectado no numero ' . $connectedPhone;
        } elseif (!empty($serviceStatus['pairingCode'])) {
            $sessionSummary = 'Codigo pronto para parear';
        } elseif ($serviceState === 'waiting_qr') {
            $sessionSummary = 'Aguardando o codigo de pareamento';
        } elseif ($serviceState === 'starting') {
            $sessionSummary = 'Solicitando o codigo de pareamento';
        } elseif ($serviceState === 'disconnected') {
            $sessionSummary = 'Sessao desconectada';
        } elseif ($serviceState === 'error') {
            $sessionSummary = 'Nao foi possivel conectar';
        }
        echo '<div class="wa-session-summary"><strong>' . h($sessionSummary) . '</strong>';
        if ($connectedPhone !== '') {
            echo '<span class="muted">WhatsApp conectado e pronto para receber mensagens.</span>';
        } elseif (!empty($serviceStatus['pairingCode'])) {
            echo '<span class="muted">Use o codigo abaixo no WhatsApp do celular.</span>';
        } else {
            echo '<span class="muted">Clique em iniciar pareamento ou gerar codigo por telefone.</span>';
        }
        echo '</div>';
        if (!empty($serviceStatus['pairingCode'])) {
            echo '<div class="wa-pairing-code-inline">' . h((string)$serviceStatus['pairingCode']) . '</div>';
        }
        echo '<div id="waSessionState">';
        if (empty($serviceStatus['ok'])) {
            echo '<p class="muted">A integração local foi removida. Configure a API oficial da Meta nas configurações acima.</p>';
            echo '<p class="muted">' . h($serviceStatus['error'] ?? '') . '</p>';
        } elseif (!empty($serviceStatus['pairingCode'])) {
            echo '<p class="muted">Parear o numero ' . h((string)($serviceStatus['pairingPhone'] ?? '')) . ' agora.</p>';
        } elseif ($connectedPhone !== '') {
            echo '<p>Numero conectado: <strong>' . h($connectedPhone) . '</strong></p>';
        } elseif ($serviceState === 'starting') {
            echo '<p class="muted">Gerando codigo de pareamento. Se demorar mais de alguns segundos, clique em <strong>Gerar codigo</strong>.</p>';
        } elseif ($serviceState === 'waiting_qr') {
            echo '<p class="muted">Aguardando o retorno do servico para mostrar o codigo.</p>';
        } elseif (!empty($serviceStatus['lastError'])) {
            echo '<p class="muted">Ultimo erro do servico: ' . h((string)$serviceStatus['lastError']) . '</p>';
        }
        echo '</div>';
        echo '<div class="actions whatsapp-session-actions d-flex flex-wrap gap-2">';
        echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="start_whatsapp_session"><button class="btn" type="submit">Iniciar pareamento</button></form>';
        echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="disconnect_whatsapp_session"><button class="btn secondary" type="submit">Desconectar</button></form>';
        echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="reset_whatsapp_session"><button class="btn secondary" type="submit">Limpar sessão</button></form>';
        echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="restart_whatsapp_service"><button class="btn secondary" type="submit">Reiniciar serviço</button></form>';
        echo '</div>';
        echo '<form method="post" class="inline-form whatsapp-session-actions d-flex flex-wrap gap-2 align-items-end" style="margin-top:12px">' . csrf_field();
        echo '<input type="hidden" name="action" value="request_whatsapp_pairing_code">';
        echo '<div class="field pairing-phone-field" style="margin:0;min-width:220px;flex:1 1 220px"><label>C?digo por telefone</label><input name="pairing_phone" placeholder="5521999999999"></div>';
        echo '<button class="btn secondary" type="submit">Gerar c?digo</button>';
        echo '</form>';
        echo '</div>';
        }
        echo '</div>';
        echo '<div id="whatsappManualOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,860px)"><div class="crm-panel-header"><div><h3 class="crm-panel-title">Enviar mensagem manual</h3><p class="muted" style="margin:4px 0 0">Envio direto sem poluir a tela principal.</p></div><button type="button" id="closeManualMessageOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="manualMessageOverlayBody" class="p-4"></div></div></div>';
        echo '<div id="manualMessageSource" hidden><form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="send_whatsapp_message">';
        echo '<h2>Enviar mensagem manual</h2>';
        echo '<div class="field"><label>Telefone</label><input name="phone" placeholder="5511999999999"></div>';
        echo '<div class="field"><label>Mensagem</label><textarea name="message" placeholder="Escreva uma mensagem curta para o cliente"></textarea></div>';
        echo '<button class="btn" type="submit">Enviar WhatsApp</button>';
        echo '</form>';
        if ($isOfficialWhatsApp) {
            echo '<form class="form panel" method="post" style="margin-top:14px">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="send_whatsapp_template">';
            echo '<h2>Enviar template aprovado</h2>';
            echo '<p class="muted">Use quando a conversa estiver fora da janela de 24h da API oficial.</p>';
            echo '<div class="grid cols-2"><div class="field"><label>Telefone</label><input name="phone" placeholder="5511999999999"></div><div class="field"><label>Nome do template</label><input name="template_name" placeholder="confirmacao_agendamento"></div></div>';
            echo '<div class="grid cols-2"><div class="field"><label>Idioma</label><input name="template_language" value="pt_BR"></div><div class="field"><label>Parametros</label><textarea name="template_parameters" placeholder="Um parametro por linha ou separado por virgula"></textarea></div></div>';
            echo '<button class="btn secondary" type="submit">Enviar template</button>';
            echo '</form>';
        }
        echo '</div>';
        echo '<div id="whatsappReadingOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,760px)"><div class="crm-panel-header"><div><h3 class="crm-panel-title">Leitura rápida</h3><p class="muted" style="margin:4px 0 0">Resumo do fluxo atual.</p></div><button type="button" id="closeWhatsAppReadingOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="whatsappReadingOverlayBody" class="p-4"></div></div></div>';
        echo '<div id="whatsappReadingSource" hidden><div class="panel"><div class="mini-metrics"><span><strong>' . h($summary['human']) . '</strong><small>Em humano</small></span><span><strong>' . h($summary['analyzed']) . '</strong><small>Com IA</small></span><span><strong>' . h($summary['avg_score'] ?: '-') . '</strong><small>Nota media</small></span></div><p class="muted">As mensagens recebidas pela API oficial entram aqui e criam lead automaticamente quando o telefone ainda nao existir.</p></div></div>';
        echo '<div id="whatsappConversationsOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1200px)"><div class="crm-panel-header"><div><h3 class="crm-panel-title">Conversas importadas</h3><p class="muted" style="margin:4px 0 0">Página ' . h((string)$conversationsPage) . ' de ' . h((string)$conversationsTotalPages) . '.</p></div><button type="button" id="closeWhatsAppConversationsOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="whatsappConversationsOverlayBody" class="p-4"></div></div></div>';
        echo '<div id="whatsappConversationsSource" hidden><section class="panel whatsapp-list-panel" style="margin:0"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2>Conversas importadas</h2><p class="muted">Filtre por urgencia, IA, humano e vinculos.</p></div><span class="badge">API oficial</span></div>';
        echo '<div class="whatsapp-filter-tabs">';
        $baseWhatsappUrl = app_url('studio_whatsapp');
        $filterTabs = [
            'all' => 'Todas',
            'unreplied' => 'Não respondidas',
            'needs_human' => 'Pediram humano',
            'bot' => 'Em IA/Bot',
            'human' => 'Em humano',
            'no_link' => 'Sem lead vinculado',
        ];
        foreach ($filterTabs as $filterKey => $label) {
            $href = app_url('studio_whatsapp', array_filter([
                'filter' => $filterKey !== 'all' ? $filterKey : null,
                'q' => $filters['q'] !== '' ? $filters['q'] : null,
                'mode' => $filters['mode'] !== '' ? $filters['mode'] : null,
                'needs_human' => $filters['needs_human'] ? 1 : null,
                'min_score' => $filters['min_score'] > 0 ? $filters['min_score'] : null,
                'wa_page' => $conversationsPage,
            ], static fn($value) => $value !== null && $value !== ''));
            $active = ($filters['filter'] ?: 'all') === $filterKey ? ' active' : '';
            echo '<a class="filter-pill' . h($active) . '" href="' . h($href) . '">' . h($label) . '</a>';
        }
        echo '</div>';
        echo '<form class="filter-bar row row-cols-1 row-cols-md-2 row-cols-xl-6 g-2 align-items-end" method="get"><input type="hidden" name="page" value="studio_whatsapp">';
        echo '<input type="hidden" name="filter" value="' . h($filters['filter'] ?: 'all') . '">';
        echo '<div class="col"><input name="q" placeholder="Buscar contato, telefone ou mensagem..." value="' . h($filters['q']) . '"></div>';
        echo '<div class="col"><select name="mode"><option value="">Todos os modos</option>';
        render_options(['human' => 'Humano', 'bot' => 'IA'], $filters['mode']);
        echo '</select></div>';
        echo '<div class="col"><select name="min_score"><option value="0">Qualquer nota</option>';
        foreach ([5, 7, 9] as $score) {
            echo '<option value="' . h((string)$score) . '" ' . ((int)$filters['min_score'] === $score ? 'selected' : '') . '>Nota ' . h((string)$score) . '+</option>';
        }
        echo '</select></div>';
        echo '<div class="col"><label class="checkline compact"><input type="checkbox" name="needs_human" value="1" ' . ($filters['needs_human'] ? 'checked' : '') . '> Quer humano</label></div>';
        echo '<div class="col d-flex gap-2 flex-wrap"><button class="btn secondary" type="submit">Filtrar</button><a class="btn secondary" href="' . h(app_url('studio_whatsapp')) . '">Limpar</a></div></form>';
        render_whatsapp_table($conversationsPageRows);
        if ($conversationsTotalPages > 1) {
            echo '<div class="actions" style="justify-content:space-between;margin-top:12px;flex-wrap:wrap">';
            if ($conversationsPage > 1) {
                echo '<a class="btn secondary" href="' . h(app_url('studio_whatsapp', ['filter' => $filters['filter'] ?: 'all', 'q' => $filters['q'] !== '' ? $filters['q'] : null, 'mode' => $filters['mode'] !== '' ? $filters['mode'] : null, 'needs_human' => $filters['needs_human'] ? 1 : null, 'min_score' => $filters['min_score'] > 0 ? $filters['min_score'] : null, 'wa_page' => $conversationsPage - 1])) . '">Anterior</a>';
            }
            if ($conversationsPage < $conversationsTotalPages) {
                echo '<a class="btn secondary" href="' . h(app_url('studio_whatsapp', ['filter' => $filters['filter'] ?: 'all', 'q' => $filters['q'] !== '' ? $filters['q'] : null, 'mode' => $filters['mode'] !== '' ? $filters['mode'] : null, 'needs_human' => $filters['needs_human'] ? 1 : null, 'min_score' => $filters['min_score'] > 0 ? $filters['min_score'] : null, 'wa_page' => $conversationsPage + 1])) . '">Próxima</a>';
            }
            echo '</div>';
        }
        echo '</section></div>';
        echo '<script>(function(){const modal=document.getElementById("whatsappStatusOverlay");const body=document.getElementById("whatsappStatusOverlayBody");const source=document.getElementById("whatsappStatusSource");const closeBtn=document.getElementById("closeWhatsAppStatusOverlay");const openBtn=document.getElementById("openWhatsAppStatusOverlay");if(openBtn&&modal&&body&&source){openBtn.addEventListener("click",()=>{body.innerHTML=source.innerHTML;modal.classList.remove("hidden");});}if(closeBtn) closeBtn.addEventListener("click",()=>modal.classList.add("hidden"));if(modal) modal.addEventListener("click",(event)=>{if(event.target===modal) modal.classList.add("hidden");});document.addEventListener("keydown",(event)=>{if(event.key==="Escape"&&modal) modal.classList.add("hidden");});})();</script>';
        echo '<script>(function(){const modal=document.getElementById("whatsappManualOverlay");const body=document.getElementById("manualMessageOverlayBody");const source=document.getElementById("manualMessageSource");const openBtn=document.getElementById("openManualMessageOverlay");const closeBtn=document.getElementById("closeManualMessageOverlay");if(openBtn&&modal&&body&&source){openBtn.addEventListener("click",()=>{body.innerHTML=source.innerHTML;modal.classList.remove("hidden");});}if(closeBtn) closeBtn.addEventListener("click",()=>modal.classList.add("hidden"));if(modal) modal.addEventListener("click",(event)=>{if(event.target===modal) modal.classList.add("hidden");});document.addEventListener("keydown",(event)=>{if(event.key==="Escape"&&modal) modal.classList.add("hidden");});})();</script>';
        echo '<script>(function(){const modal=document.getElementById("whatsappReadingOverlay");const body=document.getElementById("whatsappReadingOverlayBody");const source=document.getElementById("whatsappReadingSource");const openBtn=document.getElementById("openWhatsAppReadingOverlay");const closeBtn=document.getElementById("closeWhatsAppReadingOverlay");if(openBtn&&modal&&body&&source){openBtn.addEventListener("click",()=>{body.innerHTML=source.innerHTML;modal.classList.remove("hidden");});}if(closeBtn) closeBtn.addEventListener("click",()=>modal.classList.add("hidden"));if(modal) modal.addEventListener("click",(event)=>{if(event.target===modal) modal.classList.add("hidden");});document.addEventListener("keydown",(event)=>{if(event.key==="Escape"&&modal) modal.classList.add("hidden");});})();</script>';
        echo '<script>(function(){const modal=document.getElementById("whatsappConversationsOverlay");const body=document.getElementById("whatsappConversationsOverlayBody");const source=document.getElementById("whatsappConversationsSource");const openBtn=document.getElementById("openWhatsAppConversationsOverlay");const closeBtn=document.getElementById("closeWhatsAppConversationsOverlay");if(openBtn&&modal&&body&&source){openBtn.addEventListener("click",()=>{body.innerHTML=source.innerHTML;modal.classList.remove("hidden");});}if(closeBtn) closeBtn.addEventListener("click",()=>modal.classList.add("hidden"));if(modal) modal.addEventListener("click",(event)=>{if(event.target===modal) modal.classList.add("hidden");});document.addEventListener("keydown",(event)=>{if(event.key==="Escape"&&modal) modal.classList.add("hidden");});})();</script>';
    }, $flash);
    exit;
}

if ($page === 'studio_whatsapp_debug') {
    $studio = require_studio();
    render_studio_shell('Debug WhatsApp', 'Eventos do webhook oficial e diagnostico seguro.', 'whatsapp', function () use ($studio) {
        $logPath = APP_BASE_PATH . '/storage/whatsapp_webhook_events.log';
        $events = [];
        if (is_file($logPath)) {
            $lines = array_slice(array_reverse(array_filter(array_map('trim', file($logPath, FILE_IGNORE_NEW_LINES) ?: []))), 0, 50);
            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $events[] = $decoded;
                }
            }
        }
        echo '<div class="panel"><h2>Debug WhatsApp</h2><p class="muted">Ultimos eventos do webhook oficial.</p>';
        echo '<pre style="white-space:pre-wrap;max-height:70vh;overflow:auto">' . h(json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
    });
    exit;
}

if ($page === 'studio_whatsapp_workspace') {
    $params = $_GET;
    unset($params['page']);
    redirect_to('studio_whatsapp_mobile', array_filter($params, static fn($value): bool => $value !== null && $value !== ''));
    exit;

    $studio = require_studio();
    render_studio_shell('Workspace WhatsApp', 'Leitura tipo WhatsApp Web com ferramentas do CRM.', 'whatsapp', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }

        $filters = [
            'q' => (string)($_GET['q'] ?? ''),
            'mode' => (string)($_GET['mode'] ?? ''),
            'needs_human' => !empty($_GET['needs_human']),
            'min_score' => (int)($_GET['min_score'] ?? 0),
            'filter' => (string)($_GET['filter'] ?? ''),
            'visibility' => (string)($_GET['visibility'] ?? 'all'),
            'date_filter' => (string)($_GET['date_filter'] ?? ''),
            'date_from' => (string)($_GET['date_from'] ?? ''),
            'date_to' => (string)($_GET['date_to'] ?? ''),
            'tag_id' => (int)($_GET['tag_id'] ?? 0),
            'offset' => max(0, (int)($_GET['conv_offset'] ?? 0)),
        ];
        $conversationPageSize = 30;
        $conversations = studio_list_whatsapp_conversations($studio, $filters, $conversationPageSize);
        $nextConversationOffset = (int)$filters['offset'] + $conversationPageSize;
        $hasMoreConversations = count(studio_list_whatsapp_conversations($studio, array_merge($filters, ['offset' => $nextConversationOffset]), $conversationPageSize)) > 0;
        $conversationSearchIndex = studio_list_whatsapp_conversations($studio, array_merge($filters, ['offset' => 0]), 250);
        $conversationId = (int)($_GET['id'] ?? 0);
        if ($conversationId <= 0 && !empty($conversations[0]['id'])) {
            $conversationId = (int)$conversations[0]['id'];
        }
        $conversation = $conversationId > 0 ? studio_find_whatsapp_conversation($studio, $conversationId) : null;
        $currentUser = current_studio_user();
        $isAdmin = current_admin() !== null;
        $workspaceConversationId = 0;
        if ($currentUser) {
            foreach ($conversations as $row) {
                if (!is_array($row) || empty($row['id'])) {
                    continue;
                }
                if (studio_can_view_whatsapp_conversation($studio, $row, $currentUser)) {
                    $workspaceConversationId = (int)$row['id'];
                    break;
                }
            }
        } elseif (!empty($conversations[0]['id'])) {
            $workspaceConversationId = (int)$conversations[0]['id'];
        }
        $messages = $conversation ? studio_whatsapp_messages($studio, $conversationId, 80, $conversation) : [];
        $assistantInsights = $conversation ? studio_whatsapp_assistant_insights($studio, $conversation, $messages) : [];
        $displayName = $conversation ? ($conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: 'Contato WhatsApp'))) : 'Selecione uma conversa';
        if ($conversation && ($displayName === 'Cliente WhatsApp' || $displayName === 'Contato WhatsApp' || $displayName === '') && !empty($assistantInsights['suggested_name'])) {
            $displayName = (string)$assistantInsights['suggested_name'];
        }
        $customers = $conversation ? studio_list_customers($studio) : [];
        $leads = $conversation ? studio_list_leads($studio) : [];
        $artists = $conversation ? studio_list_artists($studio) : [];
        $quickReplies = $conversation ? array_values(array_filter(studio_list_quick_replies($studio), static fn(array $reply): bool => !empty($reply['is_active']))) : [];
        $availableTags = studio_list_whatsapp_tags($studio);
        $conversationTags = $conversation ? studio_whatsapp_conversation_tags($studio, $conversationId) : [];
        $assistantAutofillEnabled = !empty(studio_settings($studio)['assistant_autofill_enabled']);
        $assistantConfidence = max(0, min(100, (int)round(((int)($assistantInsights['confidence'] ?? 0)) * 10)));
        $assignedUserId = (int)($conversation['assigned_user_id'] ?? 0);
        $canSendHere = $conversation && $currentUser && studio_can_send_whatsapp_conversation($studio, $conversation, $currentUser);
        $assignedUserName = (string)($conversation['assigned_user_name'] ?? '');
        if ($assistantAutofillEnabled && $assistantConfidence === 0 && count($messages) > 0) {
            $assistantConfidence = 35;
        }
        $scheduleSuggestion = $conversation ? studio_whatsapp_schedule_suggestion($conversation, $messages, $artists) : [];
        if ($conversation && !empty($assistantInsights['suggested_date']) && !empty($assistantInsights['suggested_time'])) {
            $scheduleSuggestion['date'] = (string)$assistantInsights['suggested_date'];
            $scheduleSuggestion['time'] = (string)$assistantInsights['suggested_time'];
        }
        if ($conversation && !empty($assistantInsights['schedule_reason'])) {
            $scheduleSuggestion['reason'] = (string)$assistantInsights['schedule_reason'];
        }
        if ($conversation && !empty($assistantInsights['suggested_interest']) && trim((string)($conversation['lead_interest'] ?? '')) === '') {
            $scheduleSuggestion['title'] = (string)$assistantInsights['suggested_interest'];
        }

        $publicUpdateUrl = '';
        if ($conversation && !empty($conversation['lead_id'])) {
            $publicToken = studio_ensure_lead_public_update_token($studio, (int)$conversation['lead_id']);
            $publicUpdateUrl = app_url('lead_public_update', ['lead' => (int)$conversation['lead_id'], 'token' => $publicToken]);
        }
        $sharePhone = $conversation ? preg_replace('/\D+/', '', (string)($conversation['phone'] ?? '')) : '';
        $whatsAppShareUrl = ($sharePhone !== '' && $publicUpdateUrl !== '') ? ('https://wa.me/' . $sharePhone . '?text=' . rawurlencode('Atualize seu cadastro por aqui: ' . $publicUpdateUrl)) : '';
        $pendingAudioCount = 0;
        foreach ($messages as $message) {
            $mime = (string)($message['media_mime'] ?? '');
            $type = (string)($message['message_type'] ?? '');
            $looksAudio = str_starts_with($mime, 'audio/') || $type === 'audio';
            $hasTranscript = trim((string)($message['transcricao'] ?? $message['transcript'] ?? '')) !== '';
            if ($looksAudio && !$hasTranscript) {
                $pendingAudioCount++;
            }
        }

        echo '<section class="wa-web-shell" style="display:grid;grid-template-columns:minmax(320px,360px) minmax(0,1fr);gap:16px;align-items:start;">';
        echo '<aside class="wa-web-sidebar">';
        echo '<div class="wa-web-sidebar-top">';
        echo '<div class="wa-web-brand">';
        echo '<div class="wa-web-brand-title"><h2>WhatsApp</h2><span class="wa-web-brand-dot"></span></div>';
        echo '<p class="muted">Histórico, triagem e resposta do estúdio</p>';
        echo '</div>';
        echo '<div class="wa-web-top-actions"><button class="wa-web-icon-btn" type="button" aria-label="Nova conversa"><i class="fa-solid fa-comment-medical"></i></button><button class="wa-web-icon-btn" type="button" aria-label="Menu"><i class="fa-solid fa-ellipsis-vertical"></i></button></div>';
        echo '</div>';
        echo '<form class="wa-web-search" method="get" id="waWorkspaceSearchForm" autocomplete="off">';
        echo '<input type="hidden" name="page" value="studio_whatsapp_workspace">';
        echo '<input type="hidden" name="filter" value="' . h($filters['filter']) . '">';
        echo '<input type="hidden" name="visibility" value="' . h($filters['visibility']) . '">';
        echo '<input type="hidden" name="date_filter" value="' . h($filters['date_filter']) . '">';
        echo '<input type="hidden" name="date_from" value="' . h($filters['date_from']) . '">';
        echo '<input type="hidden" name="date_to" value="' . h($filters['date_to']) . '">';
        echo '<input type="text" name="q" id="waWorkspaceSearchInput" placeholder="Pesquisar texto, áudio transcrito ou contato" value="' . h($filters['q']) . '">';
        echo '<select name="tag_id" aria-label="Filtrar por tag" onchange="this.form.submit()"><option value="">Todas as tags</option>';
        foreach ($availableTags as $tag) {
            echo '<option value="' . h((string)$tag['id']) . '"' . ((int)$filters['tag_id'] === (int)$tag['id'] ? ' selected' : '') . '>' . h((string)$tag['name']) . '</option>';
        }
        echo '</select>';
        echo '<div class="wa-web-search-suggestions hidden" id="waWorkspaceSearchSuggestions"></div>';
        echo '</form>';
        echo '<div class="wa-web-filter-row">';
        $visibilityPills = $isAdmin ? ['all' => 'Todas', 'mine' => 'Minhas', 'free' => 'Livres'] : ['mine' => 'Minhas'];
        foreach ($visibilityPills as $visibilityKey => $label) {
            $nextVisibility = ($filters['visibility'] === $visibilityKey) ? '' : $visibilityKey;
            $href = app_url('studio_whatsapp_workspace', array_filter([
                'id' => $conversationId > 0 ? $conversationId : null,
                'visibility' => $nextVisibility !== '' ? $nextVisibility : null,
                'date_filter' => $filters['date_filter'] !== '' ? $filters['date_filter'] : null,
                'date_from' => $filters['date_from'] !== '' ? $filters['date_from'] : null,
                'date_to' => $filters['date_to'] !== '' ? $filters['date_to'] : null,
                'filter' => $filters['filter'] !== '' ? $filters['filter'] : null,
                'q' => $filters['q'] !== '' ? $filters['q'] : null,
                'conv_offset' => null,
            ], static fn($value) => $value !== null && $value !== ''));
            $active = $filters['visibility'] === $visibilityKey ? ' active' : '';
            echo '<a class="wa-web-filter-pill' . h($active) . '" href="' . h($href) . '">' . h($label) . '</a>';
        }
        foreach (['all' => 'Tudo', 'unreplied' => 'Não lidas', 'needs_human' => 'Humano', 'bot' => 'IA'] as $filterKey => $label) {
            $nextFilter = ($filters['filter'] === $filterKey) ? '' : $filterKey;
            $href = app_url('studio_whatsapp_workspace', array_filter([
                'id' => $conversationId > 0 ? $conversationId : null,
                'visibility' => $filters['visibility'] !== '' ? $filters['visibility'] : null,
                'date_filter' => $filters['date_filter'] !== '' ? $filters['date_filter'] : null,
                'date_from' => $filters['date_from'] !== '' ? $filters['date_from'] : null,
                'date_to' => $filters['date_to'] !== '' ? $filters['date_to'] : null,
                'filter' => $nextFilter !== '' ? $nextFilter : null,
                'q' => $filters['q'] !== '' ? $filters['q'] : null,
                'conv_offset' => null,
            ], static fn($value) => $value !== null && $value !== ''));
            $active = $filters['filter'] === $filterKey ? ' active' : '';
            echo '<a class="wa-web-filter-pill' . h($active) . '" href="' . h($href) . '">' . h($label) . '</a>';
        }
        $todayActive = $filters['date_filter'] === 'today';
        $todayHref = app_url('studio_whatsapp_workspace', array_filter(['id' => $conversationId > 0 ? $conversationId : null, 'visibility' => $filters['visibility'] !== '' ? $filters['visibility'] : null, 'date_filter' => $todayActive ? null : 'today', 'date_from' => $filters['date_from'] !== '' ? $filters['date_from'] : null, 'date_to' => $filters['date_to'] !== '' ? $filters['date_to'] : null, 'filter' => $filters['filter'] !== '' ? $filters['filter'] : null, 'q' => $filters['q'] !== '' ? $filters['q'] : null], static fn($value) => $value !== null && $value !== ''));
        $rangeActive = $filters['date_filter'] === 'range';
        $rangeHref = app_url('studio_whatsapp_workspace', array_filter(['id' => $conversationId > 0 ? $conversationId : null, 'visibility' => $filters['visibility'] !== '' ? $filters['visibility'] : null, 'date_filter' => $rangeActive ? null : 'range', 'date_from' => $rangeActive ? null : date('Y-m-01'), 'date_to' => $rangeActive ? null : date('Y-m-d'), 'filter' => $filters['filter'] !== '' ? $filters['filter'] : null, 'q' => $filters['q'] !== '' ? $filters['q'] : null], static fn($value) => $value !== null && $value !== ''));
        echo '<a class="wa-web-filter-pill' . ($todayActive ? ' active' : '') . '" href="' . h($todayHref) . '">Hoje</a>';
        echo '<a class="wa-web-filter-pill' . ($rangeActive ? ' active' : '') . '" href="' . h($rangeHref) . '">Período</a>';
        echo '</div>';
        echo '<div class="wa-web-archive-row"><span class="wa-web-archive-icon"><i class="fa-solid fa-box-archive"></i></span><strong>Arquivadas</strong><span class="wa-web-archive-count">2</span></div>';
        echo '<div class="wa-web-chat-list">';
        if ($conversations) {
            foreach ($conversations as $row) {
                $rowId = (int)($row['id'] ?? 0);
                $rowName = (string)($row['customer_name'] ?: ($row['lead_name'] ?: ($row['name'] ?: 'Contato WhatsApp')));
                $rowPreview = trim((string)($row['latest_message_preview'] ?? $row['last_message_preview'] ?? ''));
                $rowHref = app_url('studio_whatsapp_workspace', array_filter([
                    'id' => $rowId,
                    'filter' => $filters['filter'] !== 'all' ? $filters['filter'] : null,
                    'q' => $filters['q'] !== '' ? $filters['q'] : null,
                    'tag_id' => $filters['tag_id'] > 0 ? $filters['tag_id'] : null,
                ], static fn($value) => $value !== null && $value !== ''));
                $rowActive = $rowId === $conversationId ? ' active' : '';
                echo '<a class="wa-web-chat-item' . h($rowActive) . '" href="' . h($rowHref) . '" data-search-name="' . h($rowName) . '" data-search-phone="' . h((string)($row['phone'] ?? '')) . '" data-search-preview="' . h($rowPreview) . '">';
                echo '<div class="wa-web-chat-avatar">' . h(mb_strtoupper(mb_substr(trim($rowName) !== '' ? $rowName : 'W', 0, 1))) . '</div>';
                echo '<div class="wa-web-chat-meta"><div class="wa-web-chat-head"><strong>' . h($rowName) . '</strong><span>' . h(format_datetime_pt((string)($row['message_last_at'] ?? $row['updated_at'] ?? ''), false)) . '</span></div>';
                echo '<p>' . h($rowPreview !== '' ? $rowPreview : 'Sem mensagem ainda') . '</p>';
                echo '<div class="wa-web-chat-badges">';
                if (!empty($row['needs_human'])) {
                    echo '<span class="badge warn">Aguardando atendente</span>';
                } else {
                    echo '<span class="badge ' . (($row['attendance_mode'] ?? 'human') === 'bot' ? 'ok' : '') . '">' . h(($row['attendance_mode'] ?? 'human') === 'bot' ? 'IA ativa' : 'Com atendente') . '</span>';
                }
                $rowUnreadCount = studio_whatsapp_unread_count($row, $studio);
                if ($rowUnreadCount > 0) {
                    echo '<span class="wa-web-unread-badge">' . h((string)$rowUnreadCount) . '</span>';
                }
                if (empty($row['lead_id']) && empty($row['customer_id'])) {
                    echo '<span class="badge">Sem cadastro</span>';
                }
                echo '</div></div></a>';
            }
        } else {
            echo '<div class="panel"><p class="muted">Nenhuma conversa encontrada com esse filtro.</p></div>';
        }
        if ($hasMoreConversations) {
            $loadMoreHref = app_url('studio_whatsapp_workspace', array_filter([
                'id' => $conversationId > 0 ? $conversationId : null,
                'filter' => $filters['filter'] !== 'all' ? $filters['filter'] : null,
                'q' => $filters['q'] !== '' ? $filters['q'] : null,
                'tag_id' => $filters['tag_id'] > 0 ? $filters['tag_id'] : null,
                'conv_offset' => $nextConversationOffset,
            ], static fn($value) => $value !== null && $value !== ''));
            echo '<a class="wa-web-load-more" id="waLoadMoreLink" data-next-offset="' . h((string)$nextConversationOffset) . '" href="' . h($loadMoreHref) . '">Carregar mais conversas</a>';
        }
        echo '</div>';
        echo '</aside>';

        echo '<section class="wa-web-main">';
        if (!$conversation) {
            echo '<div class="wa-web-empty">';
            echo '<div class="wa-web-empty-illustration"><div class="wa-web-empty-bubble"></div><div class="wa-web-empty-badge"><i class="fa-solid fa-lock"></i></div></div>';
            echo '<h2>WhatsApp Business Web</h2>';
            echo '<p class="muted">Selecione uma conversa para ver mensagens, cadastro e próximos passos.</p>';
            echo '<small class="wa-web-empty-lock"><i class="fa-solid fa-lock"></i> Suas mensagens pessoais são protegidas com criptografia de ponta a ponta.</small>';
        echo '</div>';
        } else {
        echo '<div class="wa-web-main-header">';
        $leadStatusKey = (string)($conversation['lead_status'] ?: 'em_conversa');
        $leadStatusLabel = [
            'novo' => 'Novo',
            'em_conversa' => 'Em conversa',
            'qualificado' => 'Qualificado',
            'negociando' => 'Negociando',
            'agendado' => 'Agendado',
            'finalizado' => 'Finalizado',
            'perdido' => 'Perdido',
        ][$leadStatusKey] ?? ucfirst(str_replace('_', ' ', $leadStatusKey));
        echo '<div class="wa-web-main-contact"><div class="wa-web-chat-avatar large">' . h(mb_strtoupper(mb_substr(trim($displayName) !== '' ? $displayName : 'W', 0, 1))) . '</div><div class="wa-web-main-contact-text"><h2>' . h($displayName) . '</h2><p>' . h((string)($conversation['phone'] ?? '')) . '</p><div class="wa-web-main-submeta"><span class="wa-web-status-dot"></span><span>' . h(((string)($conversation['attendance_mode'] ?? 'human')) === 'bot' ? 'IA ativa' : 'Com atendente') . '</span><span>•</span><span>' . h($leadStatusLabel) . '</span>';
        foreach ($conversationTags as $tag) {
            echo '<span class="badge" style="border-color:' . h((string)$tag['color']) . ';color:' . h((string)$tag['color']) . '">' . h((string)$tag['name']) . '</span>';
        }
        echo '</div></div></div>';
        echo '<div class="wa-web-assignment-bar">';
        if ($assignedUserId > 0) {
            echo '<span class="badge warn">Conversa assumida por: ' . h($assignedUserName !== '' ? $assignedUserName : ('Atendente #' . $assignedUserId)) . '</span>';
        } else {
            echo '<span class="badge">Conversa livre</span>';
        }
        echo '<div class="d-flex flex-wrap gap-2">';
        $conversationAssignedUserId = (int)($conversation['assigned_user_id'] ?? 0);
        $currentUserId = (int)($currentUser['id'] ?? 0);
        if ($conversationAssignedUserId <= 0) {
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="assign_whatsapp_conversation"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><button class="btn secondary" type="submit">Assumir conversa</button></form>';
        }
        if ($conversationAssignedUserId === $currentUserId && $conversationAssignedUserId > 0) {
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="release_whatsapp_conversation"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><button class="btn secondary" type="submit">Liberar conversa</button></form>';
        }
        if ($isAdmin) {
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="transfer_whatsapp_conversation"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><select name="target_user_id"><option value="">Transferir para...</option>';
            foreach (studio_list_users($studio) as $studioUser) { echo '<option value="' . h((string)$studioUser['id']) . '">' . h((string)$studioUser['name']) . '</option>'; }
            echo '</select><button class="btn secondary" type="submit">Transferir</button></form>';
            if ($conversationAssignedUserId > 0) {
                echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="release_whatsapp_conversation"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><button class="btn secondary" type="submit">Desatribuir</button></form>';
            }
        }
        echo '</div></div>';
        $workspaceAiModeActive = ((string)($conversation['attendance_mode'] ?? 'human') === 'bot');
        echo '<div class="wa-web-main-actions"><button class="wa-web-action-pill" type="button" id="openAppointmentModalButton" aria-label="Agendar"><i class="fa-regular fa-calendar"></i><span>Agendar</span></button><button class="wa-web-ai-trigger" type="button" id="openWorkspaceAiButton" aria-label="Sugestoes da IA" title="Sugestoes da IA"><i class="fa-solid fa-wand-magic-sparkles"></i></button><button class="wa-web-ai-toggle' . ($workspaceAiModeActive ? ' is-active' : '') . '" type="button" id="workspaceAiModeButton" aria-label="' . h($workspaceAiModeActive ? 'IA ligada' : 'IA desligada') . '" title="' . h($workspaceAiModeActive ? 'IA ligada nesta conversa' : 'IA desligada nesta conversa') . '" data-next-mode="' . h($workspaceAiModeActive ? 'human' : 'bot') . '"><i class="fa-solid ' . h($workspaceAiModeActive ? 'fa-toggle-on' : 'fa-toggle-off') . '"></i><span>' . h($workspaceAiModeActive ? 'IA on' : 'IA off') . '</span></button>';
            if ($publicUpdateUrl !== '') {
                echo '<a class="wa-web-action-pill" href="' . h($publicUpdateUrl) . '" target="_blank" rel="noopener" aria-label="Cadastro"><i class="fa-regular fa-address-card"></i><span>Cadastro</span></a>';
            }
            echo '<a class="wa-web-action-pill" href="' . h(app_url('studio_whatsapp_mobile')) . '" target="_blank" rel="noopener" aria-label="Abrir no celular"><i class="fa-solid fa-mobile-screen-button"></i><span>Celular</span></a>';
            echo '<button class="wa-web-tools-toggle" type="button" id="openWorkspaceToolsButton" aria-label="Ferramentas"><i class="fa-solid fa-sliders"></i><span>Painel</span></button></div></div>';
            render_chat_messages($messages);
            $workspaceWindow = studio_whatsapp_customer_service_window($studio, $conversationId);
            if (!empty($workspaceWindow['applies']) && empty($workspaceWindow['open'])) {
                echo '<div class="panel soft" style="margin:10px 0"><strong>Janela oficial de 24h encerrada.</strong><p class="muted" style="margin:4px 0 0">Use um template aprovado da Meta para reabrir a conversa. O formulario de template fica em WhatsApp > Mensagem manual.</p></div>';
            }
            echo '<form class="form wa-web-composer" method="post" enctype="multipart/form-data" id="chatComposer">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="send_whatsapp_message"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="phone" value="' . h((string)($conversation['phone'] ?? '')) . '"><input type="hidden" name="return_to_workspace" value="1">';
            echo '<div class="wa-web-composer-shell">';
            echo '<button class="wa-web-composer-action" type="button" id="chatEmojiButton" aria-label="Emoji"><i class="fa-regular fa-face-smile"></i><span>Emoji</span></button>';
            echo '<button class="wa-web-composer-action" type="button" id="chatAttachmentButton" aria-label="Anexar"><i class="fa-solid fa-plus"></i><span>Anexar</span></button>';
            echo '<div class="wa-web-composer-input"><textarea id="reply-message" name="message" placeholder="Digite uma mensagem" rows="1" ' . (!$canSendHere ? 'disabled' : '') . '></textarea></div>';
            echo '<input id="chatAttachment" type="file" name="media_file" accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.txt,.zip" hidden>';
            echo '<span id="chatRecordingState" class="muted wa-web-recording-state"></span>';
            echo '<button class="wa-web-composer-action" type="button" id="chatRecordButton" aria-label="Gravar audio"><i class="fa-solid fa-microphone"></i><span>Audio</span></button>';
            echo '<button class="wa-web-send-btn" type="submit" aria-label="Enviar" ' . (!$canSendHere ? 'disabled' : '') . '><i class="fa-solid fa-paper-plane"></i><span>Enviar</span></button>';
            if ($conversation && !$canSendHere) { echo '<div class="muted" style="margin-top:8px">Voce pode visualizar, mas nao interagir com esta conversa.</div>'; }
            echo '</div>';
            echo '<div class="wa-web-emoji-panel hidden" id="chatEmojiPanel">';
            foreach (['😀','😂','😍','🔥','👏','🙏','👍','👀','✅','❤️','🎯','📅'] as $emoji) {
                echo '<button class="btn tiny secondary quick-reply-copy" type="button" data-reply="' . h($emoji) . '">' . h($emoji) . '</button>';
            }
            echo '</div>';
            echo '<div id="chatAttachmentPreview" class="chat-attachment-preview hidden"></div>';
            echo '</form>';
        }
        echo '</section>';

        if ($conversation) {
            $nameFieldValue = $conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: ''));
            if (($nameFieldValue === '' || in_array(function_exists('mb_strtolower') ? mb_strtolower($nameFieldValue, 'UTF-8') : strtolower($nameFieldValue), ['cliente whatsapp', 'contato whatsapp', 'sem nome'], true)) && !empty($assistantInsights['suggested_name'])) {
                $nameFieldValue = (string)$assistantInsights['suggested_name'];
            }
            $interestFieldValue = $conversation['lead_interest'] ?: $conversation['last_message_preview'] ?: '';
            if ($interestFieldValue === '' && !empty($assistantInsights['suggested_interest'])) {
                $interestFieldValue = (string)$assistantInsights['suggested_interest'];
            }
            $notesFieldValue = (string)($conversation['customer_notes'] ?? '');
            if ($notesFieldValue === '' && !empty($assistantInsights['suggested_notes'])) {
                $notesFieldValue = (string)$assistantInsights['suggested_notes'];
            }
            $workspaceAiSnapshot = studio_whatsapp_ai_suggestions_snapshot($studio, $conversation, $assistantInsights, $messages);

            ob_start();
            echo '<div class="wa-web-tools-card">';
            echo '<div class="wa-web-tools-head"><h3>Radar do atendimento</h3><span class="badge ' . (((string)($conversation['attendance_mode'] ?? 'human')) === 'bot' ? 'ok' : 'warn') . '">' . h(((string)($conversation['attendance_mode'] ?? 'human')) === 'bot' ? 'IA ativa' : 'Humano') . '</span></div>';
            echo '<div class="mini-metrics"><span><strong>' . h((string)count($messages)) . '</strong><small>Mensagens</small></span><span><strong>' . h((string)$assistantConfidence) . '%</strong><small>Leitura IA</small></span><span><strong>' . h((string)$pendingAudioCount) . '</strong><small>Áudios sem transcrição</small></span></div>';
            echo '<div class="wa-web-action-grid">';
            echo '<button class="btn secondary" type="button" data-mode-toggle="bot">Bot</button>';
            echo '<button class="btn secondary" type="button" data-mode-toggle="human">Humano</button>';
            echo '<button class="btn secondary" type="button" data-status-set="novo">Novo</button>';
            echo '<button class="btn secondary" type="button" id="transcribePendingAudioButton">Transcrever audios</button>';
            echo '</div></div>';

            echo '<div class="wa-web-tools-card">';
            echo '<div class="wa-web-tools-head"><h3>Cadastro e funil</h3><span class="badge">' . h((string)($conversation['lead_score'] ?? 0)) . '/10</span></div>';
            echo '<p class="muted" style="margin-top:-2px">Atualize o cadastro só do que importa para avançar a conversa.</p>';
            echo '<form class="form" method="post">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="update_whatsapp_profile"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="return_to_workspace" value="1">';
            echo '<div class="field"><label>Nome</label><input name="name" value="' . h($nameFieldValue !== '' ? $nameFieldValue : $displayName) . '"></div>';
            echo '<div class="grid cols-2"><div class="field"><label>Telefone</label><input name="phone" value="' . h((string)($conversation['phone'] ?? '')) . '"></div><div class="field"><label>Email</label><input type="text" inputmode="email" name="email" value="' . h((string)($conversation['customer_email'] ?? '')) . '"></div></div>';
            echo '<div class="grid cols-2"><div class="field"><label>Instagram</label><input name="instagram" value="' . h((string)($conversation['customer_instagram'] ?? '')) . '"></div><div class="field"><label>Lead score</label><input type="number" name="lead_score" min="0" max="10" value="' . h((string)($conversation['lead_score'] ?? 0)) . '"></div></div>';
            echo '<div class="field"><label>Cliente vinculado</label><select name="customer_id"><option value="">Criar/sem cliente</option>';
            render_customer_options($customers, (int)($conversation['customer_id'] ?? 0));
            echo '</select></div>';
            echo '<div class="field"><label>Lead vinculado</label><select name="lead_id"><option value="">Criar/sem lead</option>';
            render_lead_options($leads, (int)($conversation['lead_id'] ?? 0));
            echo '</select></div>';
            echo '<div class="field"><label>Interesse</label><input name="interest" value="' . h($interestFieldValue) . '"></div>';
            echo '<div class="grid cols-2"><div class="field"><label>Status</label><select name="status">';
            render_options(lead_status_options(), (string)($conversation['lead_status'] ?: 'em_conversa'));
            echo '</select></div><div class="field"><label>Etapa</label><select name="pipeline_stage">';
            foreach (studio_list_pipeline_stages($studio) as $stage) {
                echo '<option value="' . h((string)$stage['name']) . '" ' . ((string)$stage['name'] === (string)($conversation['lead_pipeline_stage'] ?: 'em_conversa') ? 'selected' : '') . '>' . h(studio_pipeline_stage_display_name((string)$stage['name'])) . '</option>';
            }
            echo '</select></div></div>';
            echo '<div class="field"><label>Observacoes</label><textarea name="notes">' . h($notesFieldValue) . '</textarea></div>';
            echo '<label class="checkline"><input type="checkbox" name="needs_human" value="1" ' . (!empty($conversation['needs_human']) ? 'checked' : '') . '> Cliente pediu humano</label>';
            echo '<label class="checkline"><input type="checkbox" name="create_customer" value="1" ' . (empty($conversation['customer_id']) ? 'checked' : '') . '> Criar/atualizar cliente</label>';
            echo '<label class="checkline"><input type="checkbox" name="create_lead" value="1" ' . (empty($conversation['lead_id']) ? 'checked' : '') . '> Criar/atualizar lead</label>';
            echo '<button class="btn" type="submit">Salvar dados</button>';
            echo '</form></div>';

            echo '<div class="wa-web-tools-card">';
            echo '<div class="wa-web-tools-head"><h3>Link de cadastro</h3><span class="badge">' . h($publicUpdateUrl !== '' ? 'Pronto' : 'Pendente') . '</span></div>';
            if ($publicUpdateUrl !== '') {
                echo '<div class="field"><label>Link publico</label><input type="text" readonly value="' . h($publicUpdateUrl) . '" id="workspacePublicUpdateUrl"></div>';
                echo '<div class="wa-web-action-grid">';
                echo '<a class="btn secondary" href="' . h($publicUpdateUrl) . '" target="_blank" rel="noopener">Abrir</a>';
                echo '<button class="btn secondary" type="button" id="copyWorkspacePublicUpdateUrl">Copiar</button>';
                if ($whatsAppShareUrl !== '') {
                    echo '<a class="btn secondary" href="' . h($whatsAppShareUrl) . '" target="_blank" rel="noopener">Mandar no WhatsApp</a>';
                }
                echo '<button class="btn secondary" type="button" id="openAppointmentFromTools">Agendar</button>';
                echo '</div>';
            } else {
                echo '<p class="muted">Vincule ou crie um lead para liberar o link que transforma esse contato em cliente com ficha completa.</p>';
            }
            echo '</div>';

            echo '<div class="wa-web-tools-card">';
            echo '<div class="wa-web-tools-head"><h3>Respostas rapidas</h3><span class="badge">' . h((string)count($quickReplies)) . '</span></div>';
            if ($quickReplies) {
                echo '<div class="quick-reply-list">';
                foreach (array_slice($quickReplies, 0, 16) as $reply) {
                    echo '<button class="btn tiny secondary quick-reply-copy" type="button" data-reply="' . h((string)$reply['body']) . '">' . h((string)$reply['title']) . '</button>';
                }
                echo '</div>';
            } else {
                echo '<p class="muted">Nenhuma resposta rapida ativa.</p>';
            }
            echo '</div>';
            echo '<div class="wa-web-tools-card"><div class="wa-web-tools-head"><h3>Tags da conversa</h3><span class="badge">' . h((string)count($conversationTags)) . '</span></div>';
            if ($availableTags) {
                echo '<div class="quick-reply-list">';
                $activeTagIds = array_map(static fn(array $tag): int => (int)$tag['id'], $conversationTags);
                foreach ($availableTags as $tag) {
                    $active = in_array((int)$tag['id'], $activeTagIds, true);
                    echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="toggle_whatsapp_conversation_tag"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="tag_id" value="' . h((string)$tag['id']) . '"><input type="hidden" name="return_to_workspace" value="1"><button class="btn tiny ' . ($active ? '' : 'secondary') . '" type="submit" style="border-color:' . h((string)$tag['color']) . '">' . ($active ? '✓ ' : '+ ') . h((string)$tag['name']) . '</button></form>';
                }
                echo '</div>';
            } else {
                echo '<p class="muted">Crie tags em Configurações → Tags das conversas.</p>';
            }
            echo '</div>';
            echo '<div class="wa-web-tools-card"><div class="wa-web-tools-head"><h3>Recursos oficiais do WhatsApp</h3><span class="badge ok">Cloud API</span></div>';
            echo '<form class="form" method="post">' . csrf_field() . '<input type="hidden" name="action" value="send_whatsapp_interactive"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="phone" value="' . h((string)($conversation['phone'] ?? '')) . '"><input type="hidden" name="return_to_workspace" value="1">';
            echo '<div class="field"><label>Formato</label><select name="interactive_type"><option value="button">Até 3 botões</option><option value="list">Lista de opções</option><option value="flow">Formulário Meta Flow</option></select></div>';
            echo '<div class="field"><label>Mensagem</label><textarea name="message" required placeholder="Escolha uma opção para continuarmos:"></textarea></div>';
            echo '<div class="field"><label>Opções</label><textarea name="interactive_options" placeholder="Uma opção por linha&#10;Ex.: Quero agendar&#10;Quero orçamento&#10;Falar com atendente"></textarea><small class="muted">Botões: até 3. Lista: até 10. Para Flow, as opções são ignoradas.</small></div>';
            echo '<button class="btn" type="submit">Enviar interação</button></form></div>';
            $workspaceToolsMarkup = (string)ob_get_clean();
            echo '<template id="workspaceToolsMarkup">' . $workspaceToolsMarkup . '</template>';
            echo '<div id="workspaceToolsOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,900px)"><div class="crm-panel-header"><div><h3 class="crm-panel-title">Ferramentas da conversa</h3><p class="muted" style="margin:4px 0 0">Cadastro, IA e respostas rápidas em um overlay sem ocupar a lateral fixa.</p></div><button type="button" id="closeWorkspaceToolsOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div class="p-4" id="workspaceToolsOverlayBody"></div></div></div>';
            echo '<div id="workspaceAiOverlay" class="crm-modal hidden"><div class="crm-modal-panel ai-modal-panel" style="max-width:min(96vw,960px);background:linear-gradient(180deg,#15232a 0%,#111b21 100%);color:#e9edef;border:1px solid rgba(134,150,160,.22);box-shadow:0 30px 90px rgba(0,0,0,.55)"><div class="crm-panel-header" style="background:linear-gradient(180deg,rgba(19,32,39,.98) 0%,rgba(15,25,31,.98) 100%);border-bottom:1px solid rgba(0,168,132,.16);color:#e9edef"><div><h3 class="crm-panel-title" style="color:#f3f6f7">Sugestoes da IA</h3><p class="muted" style="margin:4px 0 0;color:#9aa7af">Copiloto silencioso para leitura, resumo e apoio ao atendimento.</p></div><button type="button" id="closeWorkspaceAiOverlay" class="crm-button crm-icon-button" style="color:#e9edef;border-color:rgba(255,255,255,.1);background:rgba(255,255,255,.04)"><i class="fa-solid fa-xmark"></i></button></div><div class="p-4" id="workspaceAiOverlayBody" style="padding:20px;background:linear-gradient(180deg,rgba(17,27,33,.98) 0%,rgba(12,19,24,.99) 100%);color:#e9edef"></div></div></div>';
            echo '<script type="application/json" id="workspaceAiInitialData">' . json_encode($workspaceAiSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
            echo '<script type="application/json" id="workspaceQuickRepliesData">' . json_encode(studio_quick_replies_payload($quickReplies), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';

            echo '<div id="appointmentModal" class="crm-modal hidden">';
            echo '<div class="crm-modal-panel" style="max-width:min(96vw,860px)">';
            echo '<div class="crm-panel-header"><div><h3 class="crm-panel-title">Agendar atendimento</h3></div><button type="button" id="closeAppointmentModal" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div>';
            echo '<form class="form action-card compact-action" method="post" enctype="multipart/form-data" style="padding:18px">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="save_appointment"><input type="hidden" name="customer_id" value="' . h((string)($conversation['customer_id'] ?? 0)) . '"><input type="hidden" name="lead_id" value="' . h((string)($conversation['lead_id'] ?? 0)) . '"><input type="hidden" name="import_source" value="whatsapp"><input type="hidden" name="return_to_conversation" value="' . h((string)$conversationId) . '"><input type="hidden" name="return_to_workspace" value="1">';
            echo '<div class="grid cols-2"><div class="field"><label>Titulo</label><input name="title" required value="' . h((string)($conversation['lead_interest'] ?: 'Atendimento')) . '"></div><div class="field"><label>Tatuador</label><select name="artist_id">';
            render_artist_options($artists, default_artist_id($studio) ?? 0);
            echo '</select></div></div>';
            echo '<div class="grid cols-2"><div class="field"><label>Data</label><input type="date" name="appointment_date" value="' . h((string)($scheduleSuggestion['date'] ?? date('Y-m-d'))) . '" required></div><div class="field"><label>Inicio</label><input type="time" name="start_time" value="' . h((string)($scheduleSuggestion['time'] ?? '09:00')) . '" required></div></div>';
            echo '<div class="grid cols-2"><div class="field"><label>Fim</label><input type="time" name="end_time" value="' . h((string)($scheduleSuggestion['end_time'] ?? '10:00')) . '" required></div><div class="field"><label>Status</label><select name="status">';
            render_options(appointment_status_options(), 'pre_agendado');
            echo '</select></div></div>';
            echo '<div class="grid cols-2"><div class="field"><label>Valor</label><input name="price" placeholder="0,00"></div><div class="field"><label>Sinal</label><input name="deposit_amount" placeholder="0,00"></div></div>';
            echo '<div class="field"><label>Descricao</label><textarea name="description">' . h((string)($scheduleSuggestion['description'] ?? '')) . '</textarea></div>';
            echo '<button class="btn" type="submit">Salvar agendamento</button>';
            echo '</form></div></div>';

            echo '<div id="mediaOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1100px)"><div class="crm-panel-header"><div><h3 id="mediaOverlayTitle" class="crm-panel-title">Midia</h3></div><button type="button" id="closeMediaOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="mediaOverlayBody" class="p-4 flex items-center justify-center"></div></div></div>';
        }

        echo '</section>';

        if ($conversation) {
            echo '<script>';
            echo '(function(){';
            echo 'const conversationId=' . (int)$conversationId . ';';
            echo 'const form=document.getElementById("chatComposer"); const textarea=document.getElementById("reply-message"); const input=document.getElementById("chatAttachment"); const emojiBtn=document.getElementById("chatEmojiButton"); const emojiPanel=document.getElementById("chatEmojiPanel"); const attachBtn=document.getElementById("chatAttachmentButton"); const preview=document.getElementById("chatAttachmentPreview"); const recordBtn=document.getElementById("chatRecordButton"); const recordState=document.getElementById("chatRecordingState"); const mediaOverlay=document.getElementById("mediaOverlay"); const mediaOverlayBody=document.getElementById("mediaOverlayBody"); const mediaOverlayTitle=document.getElementById("mediaOverlayTitle"); const closeMediaOverlay=document.getElementById("closeMediaOverlay"); const chatThread=document.querySelector(".chat-thread"); const appointmentModal=document.getElementById("appointmentModal"); const openAppointmentModalButton=document.getElementById("openAppointmentModalButton"); const openAppointmentFromTools=document.getElementById("openAppointmentFromTools"); const closeAppointmentModal=document.getElementById("closeAppointmentModal"); const toolsButton=document.getElementById("openWorkspaceToolsButton"); const toolsOverlay=document.getElementById("workspaceToolsOverlay"); const toolsOverlayBody=document.getElementById("workspaceToolsOverlayBody"); const toolsRail=document.getElementById("workspaceToolsRail"); const closeToolsOverlay=document.getElementById("closeWorkspaceToolsOverlay"); const csrfToken=document.querySelector(\'input[name="csrf_token"]\')?.value||""; const searchForm=document.getElementById("waWorkspaceSearchForm"); const searchInput=document.getElementById("waWorkspaceSearchInput"); const searchSuggestions=document.getElementById("waWorkspaceSearchSuggestions"); const searchConversationIndex=' . json_encode(array_map(static function (array $row) use ($filters): array { $rowId = (int)($row['id'] ?? 0); $rowName = (string)($row['customer_name'] ?: ($row['lead_name'] ?: ($row['name'] ?: 'Contato WhatsApp'))); $rowPreview = trim((string)($row['latest_message_preview'] ?? $row['last_message_preview'] ?? '')); return ['id' => $rowId, 'name' => $rowName, 'phone' => (string)($row['phone'] ?? ''), 'preview' => $rowPreview, 'href' => app_url('studio_whatsapp_workspace', array_filter(['id' => $rowId, 'filter' => $filters['filter'] !== 'all' ? $filters['filter'] : null], static fn($value) => $value !== null && $value !== ''))]; }, $conversationSearchIndex), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; let recordedFile=null; let recorder=null; let stream=null; let chunks=[]; let recordingTimer=null; let startedAt=0;';
            echo 'function escapeHtml(value){ return String(value ?? "").replace(/[&<>"\x27]/g, char => ({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#39;" }[char] || char)); }';
            echo 'function formatDateTimePt(value){if(!value)return "-"; const date=new Date(String(value).replace(" ","T")); if(Number.isNaN(date.getTime())) return String(value); return date.toLocaleString("pt-BR",{day:"2-digit",month:"2-digit",hour:"2-digit",minute:"2-digit"});}';
            echo 'function inferMediaType(mime,url,type){const m=String(mime||"").toLowerCase(); const u=String(url||"").toLowerCase(); const t=String(type||"").toLowerCase(); if(m.startsWith("image/")||t==="image"||/\\.(png|jpg|jpeg|gif|webp)$/i.test(u)) return "image"; if(m.startsWith("audio/")||t==="audio"||/\\.(ogg|oga|opus|mp3|wav|m4a|aac|webm)$/i.test(u)) return "audio"; if(m.startsWith("video/")||t==="video"||/\\.(mp4|mov|m4v|avi|mkv)$/i.test(u)) return "video"; return "file"; }';
            echo 'function closeSearchSuggestions(){ searchSuggestions?.classList.add("hidden"); if(searchSuggestions) searchSuggestions.innerHTML=""; }';
            echo 'function renderSearchSuggestions(query){ if(!searchInput||!searchSuggestions) return; const needle=String(query||"").trim().toLowerCase(); if(needle.length<2){ closeSearchSuggestions(); return; } const matches=searchConversationIndex.filter((item)=>{ return [item.name,item.phone,item.preview].join(" ").toLowerCase().includes(needle); }).slice(0,8); if(!matches.length){ closeSearchSuggestions(); return; } searchSuggestions.innerHTML=matches.map((item)=>`<a class="wa-web-search-suggestion" href="${escapeHtml(item.href)}"><strong>${escapeHtml(item.name||item.phone||"Contato")}</strong><span>${escapeHtml(item.phone||"")}</span><small>${escapeHtml(item.preview||"Sem mensagem ainda")}</small></a>`).join(""); searchSuggestions.classList.remove("hidden"); }';
            echo 'function renderChatMessage(message){ const direction=String(message?.direction||"in"); const className=direction==="out"?"out":"in"; const body=String(message?.body||""); const type=String(message?.message_type||"texto"); const mime=String(message?.media_mime||""); const mediaUrl=String(message?.media_url||""); let mediaName=String(message?.media_file_name||""); const kind=inferMediaType(mime,mediaUrl,type); if(!mediaName&&mediaUrl){ mediaName=decodeURIComponent(mediaUrl.split("/").pop().split("?")[0]||""); } let html=`<div class="chat-message ${className}"><div class="chat-bubble">`; if(mediaUrl){ if(kind==="image"){ html += `<button type="button" class="chat-media-thumb" data-media-src="${escapeHtml(mediaUrl)}" data-media-title="${escapeHtml(mediaName||"midia")}" data-media-kind="image"><img src="${escapeHtml(mediaUrl)}" alt="${escapeHtml(mediaName||"midia")}" style="max-width:260px;max-height:220px;border-radius:8px"></button>`; } else if(kind==="video"){ html += `<button type="button" class="chat-media-thumb" data-media-src="${escapeHtml(mediaUrl)}" data-media-title="${escapeHtml(mediaName||"midia")}" data-media-kind="video"><video src="${escapeHtml(mediaUrl)}" style="max-width:280px;max-height:220px;border-radius:8px"></video></button>`; } else if(kind==="audio"){ html += `<audio src="${escapeHtml(mediaUrl)}" controls style="width:280px;max-width:100%"></audio>`; if(!String(message?.transcricao||message?.transcript||"").trim()){ html += `<button class="btn tiny secondary" type="button" data-transcribe-audio="${escapeHtml(message?.message_id||"")}" data-media-url="${escapeHtml(mediaUrl)}">Transcrever audio</button>`; } } else { html += `<a class="muted" href="${escapeHtml(mediaUrl)}" target="_blank" rel="noopener">Abrir anexo${mediaName?`: ${escapeHtml(mediaName)}`:""}</a>`; } } if(body){ html += `<p>${escapeHtml(body).replace(/\\n/g,"<br>")}</p>`; } else if(type!=="texto"&&!mediaUrl){ html += `<p>[${escapeHtml(type)}]</p>`; } const transcribedText=String(message?.transcricao||message?.transcript||"").trim(); const transcribedError=String(message?.transcricao_erro||message?.transcript_error||"").trim(); if(transcribedText){ html += `<div class="chat-transcription-result">${escapeHtml(transcribedText)}</div>`; } if(transcribedError){ html += `<div class="chat-transcription-error">${escapeHtml(transcribedError)}</div>`; } html += `<span>${escapeHtml(String(message?.sender_type||"-"))} | ${escapeHtml(formatDateTimePt(message?.sent_at||"-"))}${String(message?.status||"")?` | ${escapeHtml(String(message.status))}`:""}</span></div></div>`; return html; }';
            echo 'function scrollChatToLatest(force){ if(!chatThread) return; const should=force || (chatThread.scrollHeight-chatThread.scrollTop-chatThread.clientHeight)<120; if(should){ chatThread.scrollTop=chatThread.scrollHeight; }}';
            echo 'async function refreshWorkspaceConversation(){ if(!chatThread || document.hidden) return; try{ const response=await fetch(`api/api_chat.php?id=${conversationId}`,{ credentials:"same-origin", headers:{ "Accept":"application/json" } }); if(!response.ok) return; const data=await response.json().catch(()=>null); if(!data?.ok || !Array.isArray(data.mensagens)) return; const latestId=String(data.mensagens[0]?.id||""); if(chatThread.dataset.latestMessageId===latestId) return; const shouldStick=(chatThread.scrollHeight-chatThread.scrollTop-chatThread.clientHeight)<180; const draft=textarea?.value||""; const selectionStart=textarea?.selectionStart ?? null; const selectionEnd=textarea?.selectionEnd ?? null; const focused=document.activeElement===textarea; chatThread.innerHTML=data.mensagens.map(renderChatMessage).join(""); chatThread.dataset.latestMessageId=latestId; if(shouldStick){ scrollChatToLatest(true); } if(textarea && focused){ textarea.value=draft; try{ if(selectionStart!==null&&selectionEnd!==null){ textarea.setSelectionRange(selectionStart, selectionEnd); } textarea.focus(); }catch(error){} } }catch(error){} }';
echo 'scrollChatToLatest(true);';
            echo 'async function refreshWorkspaceSidebar(){ const chatList=document.querySelector(".wa-web-chat-list"); if(!chatList || document.hidden) return; try{ const response=await fetch(window.location.pathname+window.location.search,{ credentials:"same-origin", headers:{ "X-Requested-With":"XMLHttpRequest", "Accept":"text/html" } }); if(!response.ok) return; const html=await response.text(); const doc=new DOMParser().parseFromString(html,"text/html"); const freshList=doc.querySelector(".wa-web-chat-list"); if(!freshList) return; const currentComposer=document.getElementById("chatComposer"); const currentDraft=textarea?.value||""; const currentSelectionStart=textarea?.selectionStart ?? null; const currentSelectionEnd=textarea?.selectionEnd ?? null; const currentFocused=document.activeElement===textarea; chatList.innerHTML=freshList.innerHTML; if(currentComposer && currentFocused){ textarea.value=currentDraft; try{ if(currentSelectionStart!==null&&currentSelectionEnd!==null){ textarea.setSelectionRange(currentSelectionStart, currentSelectionEnd); } textarea.focus(); }catch(error){} } }catch(error){} }';
            echo 'setInterval(()=>{ refreshWorkspaceConversation(); refreshWorkspaceSidebar(); }, 5000);';
            echo 'function clearAttachment(){ if(input) input.value=""; recordedFile=null; if(preview){ preview.classList.add("hidden"); preview.innerHTML=""; }}';
            echo 'function renderPreview(){ const file=recordedFile || (input.files&&input.files[0]); if(!file){ preview.classList.add("hidden"); preview.innerHTML=""; return;} const url=URL.createObjectURL(file); let content=`<div class="flex items-center gap-3 flex-wrap">`; if(file.type.startsWith("image/")){ content += `<img src="${url}" style="max-width:180px;max-height:140px;border-radius:8px">`; } else if(file.type.startsWith("audio/")){ content += `<audio src="${url}" controls style="width:280px;max-width:100%"></audio>`; } else if(file.type.startsWith("video/")){ content += `<video src="${url}" controls style="max-width:220px;max-height:160px"></video>`; } content += `<div><strong>${file.name}</strong><div class="muted text-sm">${file.type||"arquivo"}</div></div><button type="button" class="btn tiny secondary" id="clearAttachmentBtn">Remover</button></div>`; preview.classList.remove("hidden"); preview.innerHTML=content; document.getElementById("clearAttachmentBtn")?.addEventListener("click", clearAttachment); }';
            echo 'if(attachBtn&&input){ attachBtn.addEventListener("click",()=>input.click()); input.addEventListener("change",()=>{recordedFile=null;renderPreview();}); }';
            echo 'emojiBtn?.addEventListener("click",()=>{ emojiPanel?.classList.toggle("hidden"); textarea?.focus(); });';
            echo 'searchInput?.addEventListener("input",()=>renderSearchSuggestions(searchInput.value)); searchInput?.addEventListener("focus",()=>renderSearchSuggestions(searchInput.value)); searchInput?.addEventListener("keydown",(event)=>{ if(event.key==="Escape") closeSearchSuggestions(); }); document.addEventListener("click",(event)=>{ if(searchForm&&!searchForm.contains(event.target)) closeSearchSuggestions(); });';
            echo 'document.querySelectorAll(".quick-reply-copy").forEach(button=>button.addEventListener("click",()=>{ const reply=button.dataset.reply||""; if(textarea){ textarea.value=textarea.value ? textarea.value+"\\n"+reply : reply; textarea.focus(); }}));';
            echo 'function openMediaOverlay(src,title,kind){ if(!src||!mediaOverlay||!mediaOverlayBody||!mediaOverlayTitle) return; mediaOverlayTitle.textContent=title||"Midia"; if(kind==="video"){ mediaOverlayBody.innerHTML=`<video src="${src}" controls autoplay style="max-width:100%;max-height:82vh;border-radius:10px"></video>`; } else if(kind==="audio"){ mediaOverlayBody.innerHTML=`<audio src="${src}" controls autoplay style="width:min(680px,100%)"></audio>`; } else { mediaOverlayBody.innerHTML=`<div style="width:100%;display:flex;justify-content:center"><img src="${src}" alt="${title||"Midia"}" style="max-width:100%;max-height:82vh;object-fit:contain;border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.35)"></div>`; } mediaOverlay.classList.remove("hidden"); }';
            echo 'document.addEventListener("click",(event)=>{ const button=event.target.closest(".chat-media-thumb"); if(button){ openMediaOverlay(button.dataset.mediaSrc||"", button.dataset.mediaTitle||"Midia", button.dataset.mediaKind||"image"); return; } const transcribeBtn=event.target.closest("[data-transcribe-audio]"); if(transcribeBtn){ event.preventDefault(); if(transcribeBtn.dataset.busy==="1") return; transcribeBtn.dataset.busy="1"; const oldLabel=transcribeBtn.textContent; transcribeBtn.textContent="Transcrevendo..."; fetch("api/whatsapp_transcribe_audio_v2.php",{ method:"POST", headers:{ "Content-Type":"application/json","Accept":"application/json" }, body:JSON.stringify({ conversation_id: conversationId, message_id: transcribeBtn.dataset.transcribeAudio||"", media_url: transcribeBtn.dataset.mediaUrl||"" }) }).then(r=>r.json().catch(()=>null)).then(data=>{ if(!data?.ok) throw new Error(data?.error||"Nao foi possivel transcrever o audio"); const bubble=transcribeBtn.closest(".chat-bubble"); if(bubble){ let box=bubble.querySelector(".chat-transcription-result"); if(!box){ box=document.createElement("div"); box.className="chat-transcription-result"; box.style.cssText="margin-top:10px;padding:10px 12px;border-radius:8px;background:rgba(0,0,0,.2);font-size:.9rem"; bubble.appendChild(box); } box.textContent="Transcricao: "+data.text; } transcribeBtn.textContent="Transcrito"; }).catch(error=>{ alert(error.message||"Nao foi possivel transcrever o audio"); transcribeBtn.textContent=oldLabel; }).finally(()=>{ transcribeBtn.dataset.busy="0"; }); return; }});';
            echo 'closeMediaOverlay?.addEventListener("click",()=>mediaOverlay.classList.add("hidden")); mediaOverlay?.addEventListener("click",(event)=>{ if(event.target===mediaOverlay) mediaOverlay.classList.add("hidden"); });';
            echo 'function openAppointment(){ appointmentModal?.classList.remove("hidden"); } openAppointmentModalButton?.addEventListener("click",openAppointment); openAppointmentFromTools?.addEventListener("click",openAppointment); closeAppointmentModal?.addEventListener("click",()=>appointmentModal.classList.add("hidden")); appointmentModal?.addEventListener("click",(event)=>{ if(event.target===appointmentModal) appointmentModal.classList.add("hidden"); });';
            echo 'async function toggleWorkspaceAiMode(nextMode){ const body=new URLSearchParams({ csrf_token: csrfToken, action:"toggle_whatsapp_ai_mode", conversation_id:String(conversationId), attendance_mode:nextMode }); const response=await fetch(window.location.pathname+window.location.search,{ method:"POST", headers:{ "X-Requested-With":"XMLHttpRequest","Accept":"application/json, text/plain, */*" }, body }); const data=await response.json().catch(()=>null); if(!response.ok || !data?.ok){ throw new Error((data&&data.error)||"Nao foi possivel alternar a IA."); } location.reload(); }';
            echo 'document.getElementById("workspaceAiModeButton")?.addEventListener("click", async ()=>{ try{ const button=document.getElementById("workspaceAiModeButton"); const nextMode=(button?.dataset.nextMode||"bot"); if(nextMode==="bot" && !confirm("Ativar IA automatica para esta conversa? Ela podera responder novas mensagens.")){ return; } await toggleWorkspaceAiMode(nextMode); } catch(error){ alert(error.message||"Nao foi possivel alternar a IA."); }});';
        echo 'if(toolsButton&&toolsOverlay&&toolsOverlayBody){ toolsButton.addEventListener("click",()=>{ toolsOverlayBody.innerHTML=document.getElementById("workspaceToolsMarkup")?.innerHTML||""; toolsOverlay.classList.remove("hidden"); }); } closeToolsOverlay?.addEventListener("click",()=>toolsOverlay.classList.add("hidden")); toolsOverlay?.addEventListener("click",(event)=>{ if(event.target===toolsOverlay) toolsOverlay.classList.add("hidden"); });';
        echo 'const chatList=document.querySelector(".wa-web-chat-list"); let loadMoreBusy=false; async function loadMoreConversations(link){ if(loadMoreBusy||!link||!link.href||!chatList) return; loadMoreBusy=true; const href=link.href; link.textContent="Carregando..."; try{ const response=await fetch(href,{ credentials:"same-origin" }); const html=await response.text(); const doc=new DOMParser().parseFromString(html,"text/html"); const incomingItems=[...doc.querySelectorAll(".wa-web-chat-list .wa-web-chat-item")]; const currentIds=new Set([...chatList.querySelectorAll(".wa-web-chat-item")].map((item)=>item.getAttribute("href")||"")); incomingItems.forEach((item)=>{ const key=item.getAttribute("href")||""; if(!currentIds.has(key)){ chatList.insertBefore(item, link); currentIds.add(key); } }); const nextLink=doc.getElementById("waLoadMoreLink"); if(nextLink&&nextLink.getAttribute("href")){ link.href=nextLink.getAttribute("href"); link.dataset.nextOffset=nextLink.dataset.nextOffset||""; link.textContent="Carregar mais conversas"; } else { link.remove(); } } catch(error){ link.textContent="Tentar novamente"; } finally { loadMoreBusy=false; }} const waLoadMoreLink=document.getElementById("waLoadMoreLink"); if(waLoadMoreLink){ waLoadMoreLink.addEventListener("click",(event)=>{ event.preventDefault(); loadMoreConversations(waLoadMoreLink); }); const loadMoreObserver=new IntersectionObserver((entries)=>{ entries.forEach((entry)=>{ if(entry.isIntersecting){ loadMoreConversations(waLoadMoreLink); } }); }, { root:null, threshold:0.35 }); loadMoreObserver.observe(waLoadMoreLink); }';
            echo 'document.getElementById("copyWorkspacePublicUpdateUrl")?.addEventListener("click", async ()=>{ const input=document.getElementById("workspacePublicUpdateUrl"); if(!input) return; try{ await navigator.clipboard.writeText(input.value||""); }catch(error){ input.select(); document.execCommand("copy"); }});';
            echo 'document.getElementById("transcribePendingAudioButton")?.addEventListener("click",()=>{ const buttons=[...document.querySelectorAll("[data-transcribe-audio]")]; buttons.forEach((button,index)=>{ setTimeout(()=>button.click(), index*350); }); });';
            echo 'document.querySelectorAll("[data-mode-toggle]").forEach(button=>button.addEventListener("click", async ()=>{ try{ const isBot=button.dataset.modeToggle==="bot"; const body=new URLSearchParams({ csrf_token: csrfToken, action:"update_whatsapp_profile", conversation_id:String(conversationId), return_to_workspace:"1", attendance_mode:isBot?"bot":"human", needs_human:isBot?"0":"1", ai_last_status:isBot?"IA pronta":"IA inativa" }); const response=await fetch(window.location.pathname+window.location.search,{ method:"POST", headers:{ "X-Requested-With":"XMLHttpRequest","Accept":"application/json, text/plain, */*" }, body }); if(!response.ok){ throw new Error("Nao foi possivel atualizar o atendimento."); } location.reload(); }catch(error){ alert(error.message||"Nao foi possivel atualizar o atendimento."); }}));';
            echo 'document.querySelectorAll("[data-status-set]").forEach(button=>button.addEventListener("click", async ()=>{ try{ const body=new URLSearchParams({ csrf_token: csrfToken, action:"update_whatsapp_profile", conversation_id:String(conversationId), return_to_workspace:"1", status:button.dataset.statusSet||"novo", create_lead:"1" }); const response=await fetch(window.location.pathname+window.location.search,{ method:"POST", headers:{ "X-Requested-With":"XMLHttpRequest","Accept":"application/json, text/plain, */*" }, body }); if(!response.ok){ throw new Error("Nao foi possivel atualizar o status."); } location.reload(); }catch(error){ alert(error.message||"Nao foi possivel atualizar o status."); }}));';
            echo 'async function toggleRecording(){ if(recorder&&recorder.state==="recording"){ recorder.stop(); return; } if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){ alert("Seu navegador nao liberou gravacao de audio aqui."); return; } try{ stream=await navigator.mediaDevices.getUserMedia({ audio:true }); const preferredMime=MediaRecorder.isTypeSupported("audio/ogg;codecs=opus") ? "audio/ogg;codecs=opus" : (MediaRecorder.isTypeSupported("audio/webm;codecs=opus") ? "audio/webm;codecs=opus" : ""); const options=preferredMime ? { mimeType: preferredMime } : {}; recorder=new MediaRecorder(stream, options); chunks=[]; startedAt=Date.now(); if(recordBtn){ recordBtn.innerHTML="<i class=\"fa-solid fa-stop\"></i><span>Parar</span>"; recordBtn.classList.add("is-recording"); } if(recordState) recordState.textContent="Gravando..."; recordingTimer=setInterval(()=>{ const elapsed=Math.floor((Date.now()-startedAt)/1000); if(recordState) recordState.textContent=`Gravando ${String(Math.floor(elapsed/60)).padStart(2,"0")}:${String(elapsed%60).padStart(2,"0")}`; }, 500); recorder.ondataavailable=e=>{ if(e.data.size>0) chunks.push(e.data); }; recorder.onstop=()=>{ if(recordingTimer){ clearInterval(recordingTimer); recordingTimer=null; } if(stream){ stream.getTracks().forEach(t=>t.stop()); stream=null; } const mime=recorder.mimeType||preferredMime||"audio/webm"; const ext=mime.includes("ogg")||mime.includes("opus") ? "ogg" : "webm"; const blob=new Blob(chunks,{ type:mime }); recordedFile=new File([blob],`audio_${Date.now()}.${ext}`,{ type:mime }); const dt=new DataTransfer(); dt.items.add(recordedFile); if(input) input.files=dt.files; renderPreview(); if(recordBtn){ recordBtn.innerHTML="<i class=\"fa-solid fa-microphone\"></i><span>Audio</span>"; recordBtn.classList.remove("is-recording"); } if(recordState) recordState.textContent="Audio pronto para envio"; }; recorder.start(); }catch(error){ alert("Nao foi possivel iniciar a gravacao."); }}';
            echo 'recordBtn?.addEventListener("click",toggleRecording);';
            echo 'form?.addEventListener("submit", async (event)=>{ event.preventDefault(); event.stopPropagation(); const hasText=!!textarea?.value.trim(); const hasFile=!!(input?.files&&input.files.length); if(!hasText&&!hasFile) return; const formData=new FormData(form); try{ const response=await fetch(window.location.pathname+window.location.search,{ method:"POST", body:formData }); if(!response.ok) throw new Error("Nao foi possivel enviar a mensagem."); if(textarea) textarea.value=""; clearAttachment(); location.reload(); }catch(error){ alert(error.message||"Erro ao enviar mensagem"); }});';
            echo 'document.addEventListener("keydown",(event)=>{ if(event.key==="Escape"){ mediaOverlay?.classList.add("hidden"); appointmentModal?.classList.add("hidden"); toolsOverlay?.classList.add("hidden"); }});';
            echo '})();';
            echo '</script>';
            echo '<script src="' . h(app_asset_url('assets/studio_whatsapp_ai_overlay.js')) . '?v=' . h(app_build_version()) . '"></script>';
            echo '<script src="' . h(app_asset_url('assets/studio_whatsapp_quick_replies.js')) . '?v=' . h(app_build_version()) . '"></script>';
        }
        echo '<script>';
        echo '(function(){';
        echo 'const searchForm=document.getElementById("waWorkspaceSearchForm"); const searchInput=document.getElementById("waWorkspaceSearchInput"); const searchSuggestions=document.getElementById("waWorkspaceSearchSuggestions"); const searchConversationIndex=' . json_encode(array_map(static function (array $row) use ($filters): array { $rowId = (int)($row['id'] ?? 0); $rowName = (string)($row['customer_name'] ?: ($row['lead_name'] ?: ($row['name'] ?: 'Contato WhatsApp'))); $rowPreview = trim((string)($row['latest_message_preview'] ?? $row['last_message_preview'] ?? '')); return ['id' => $rowId, 'name' => $rowName, 'phone' => (string)($row['phone'] ?? ''), 'preview' => $rowPreview, 'href' => app_url('studio_whatsapp_workspace', array_filter(['id' => $rowId, 'filter' => $filters['filter'] !== 'all' ? $filters['filter'] : null], static fn($value) => $value !== null && $value !== ''))]; }, $conversationSearchIndex), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; const searchItems=[...document.querySelectorAll(".wa-web-chat-item")];';
        echo 'function escapeHtml(value){ return String(value ?? "").replace(/[&<>"\x27]/g, char => ({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#39;" }[char] || char)); }';
        echo 'function closeSearchSuggestions(){ searchSuggestions?.classList.add("hidden"); if(searchSuggestions) searchSuggestions.innerHTML=""; }';
        echo 'function filterVisibleConversations(query){ const needle=String(query||"").trim().toLowerCase(); searchItems.forEach((item)=>{ const haystack=[item.dataset.searchName,item.dataset.searchPhone,item.dataset.searchPreview].join(" ").toLowerCase(); item.classList.toggle("hidden", needle!=="" && !haystack.includes(needle)); }); }';
        echo 'function renderSearchSuggestions(query){ if(!searchInput||!searchSuggestions) return; const needle=String(query||"").trim().toLowerCase(); filterVisibleConversations(needle); if(needle.length<2){ closeSearchSuggestions(); return; } const matches=searchConversationIndex.filter((item)=>[item.name,item.phone,item.preview].join(" ").toLowerCase().includes(needle)).slice(0,8); if(!matches.length){ closeSearchSuggestions(); return; } searchSuggestions.innerHTML=matches.map((item)=>`<a class="wa-web-search-suggestion" href="${escapeHtml(item.href)}"><strong>${escapeHtml(item.name||item.phone||"Contato")}</strong><span>${escapeHtml(item.phone||"")}</span><small>${escapeHtml(item.preview||"Sem mensagem ainda")}</small></a>`).join(""); searchSuggestions.classList.remove("hidden"); }';
        echo 'searchInput?.addEventListener("input",()=>renderSearchSuggestions(searchInput.value)); searchInput?.addEventListener("focus",()=>renderSearchSuggestions(searchInput.value)); searchInput?.addEventListener("search",()=>renderSearchSuggestions(searchInput.value)); searchInput?.addEventListener("keydown",(event)=>{ if(event.key==="Escape"){ searchInput.value=""; filterVisibleConversations(""); closeSearchSuggestions(); } }); document.addEventListener("click",(event)=>{ if(searchForm && !searchForm.contains(event.target)) closeSearchSuggestions(); });';
        echo '})();';
        echo '</script>';
    }, $flash);
    exit;
}

if ($page === 'studio_whatsapp_mobile_api') {
    $currentUser = current_studio_user();
    if (!$currentUser) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $studio = get_studio((int)($currentUser['studio_id'] ?? 0));
    if (!$studio) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'studio_not_found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $action = (string)($_GET['action'] ?? '');
    $isAdmin = studio_current_user_is_admin();
    header('Content-Type: application/json; charset=utf-8');
    if ($action === 'list') {
        $visibility = (string)($_GET['visibility'] ?? 'all');
        $filters = [
            'q' => (string)($_GET['q'] ?? ''),
            'visibility' => $visibility === '' ? 'all' : $visibility,
        ];
        $rows = studio_list_whatsapp_conversations($studio, $filters, 40);
        $items = [];
        foreach ($rows as $row) {
            $assignedUserId = (int)($row['assigned_user_id'] ?? 0);
            if (!$isAdmin && $assignedUserId > 0 && $assignedUserId !== (int)$currentUser['id']) {
                continue;
            }
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => (function (array $row): string {
                    $name = trim((string)($row['customer_name'] ?? ''));
                    if ($name === '') { $name = trim((string)($row['lead_name'] ?? '')); }
                    if ($name === '') { $name = trim((string)($row['name'] ?? '')); }
                    $phone = trim((string)($row['phone'] ?? ''));
                    if ($name !== '' && $name !== 'Cliente WhatsApp' && $name !== 'Contato WhatsApp') {
                        return $name;
                    }
                    return $phone !== '' ? $phone : ($name !== '' ? $name : 'Contato WhatsApp');
                })($row),
                'phone' => (string)($row['phone'] ?? ''),
                'preview' => trim((string)($row['latest_message_preview'] ?? $row['last_message_preview'] ?? '')),
                'message_last_at' => (string)($row['message_last_at'] ?? $row['updated_at'] ?? ''),
                'assigned_user_id' => $assignedUserId,
                'assigned_user_name' => $assignedUserId > 0 ? studio_user_label_by_id($assignedUserId) : '',
                'unread_count' => studio_whatsapp_unread_count($row, $studio),
                'can_view' => true,
                'can_assume' => $assignedUserId <= 0 && !empty($currentUser),
                'can_reply' => $assignedUserId === (int)$currentUser['id'] || $isAdmin,
            ];
        }
        echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($action === 'messages') {
        $conversationId = (int)($_GET['id'] ?? 0);
        $conversation = $conversationId > 0 ? studio_find_whatsapp_conversation($studio, $conversationId) : null;
        if (!$conversation) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'conversation_not_found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $assignedUserId = (int)($conversation['assigned_user_id'] ?? 0);
        if (!$isAdmin && $assignedUserId > 0 && $assignedUserId !== (int)$currentUser['id']) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $messages = studio_whatsapp_messages($studio, $conversationId, 100, $conversation);
        echo json_encode([
            'ok' => true,
            'conversation' => [
                'id' => (int)$conversation['id'],
                'name' => (function (array $conversation): string {
                    $name = trim((string)($conversation['customer_name'] ?? ''));
                    if ($name === '') { $name = trim((string)($conversation['lead_name'] ?? '')); }
                    if ($name === '') { $name = trim((string)($conversation['name'] ?? '')); }
                    $phone = trim((string)($conversation['phone'] ?? ''));
                    if ($name !== '' && $name !== 'Cliente WhatsApp' && $name !== 'Contato WhatsApp') {
                        return $name;
                    }
                    return $phone !== '' ? $phone : ($name !== '' ? $name : 'Contato WhatsApp');
                })($conversation),
                'phone' => (string)($conversation['phone'] ?? ''),
                'assigned_user_id' => $assignedUserId,
                'assigned_user_name' => $assignedUserId > 0 ? studio_user_label_by_id($assignedUserId) : '',
                'unread_count' => studio_whatsapp_unread_count($conversation, $studio),
                'last_message_at' => (string)($conversation['last_message_at'] ?? $conversation['updated_at'] ?? ''),
                'last_message_direction' => (string)($conversation['last_message_direction'] ?? ''),
            ],
            'messages' => array_map(static function (array $message): array {
                return [
                    'id' => (int)($message['id'] ?? 0),
                    'direction' => (string)($message['direction'] ?? 'in'),
                    'sender_type' => (string)($message['sender_type'] ?? 'customer'),
                    'body' => (string)($message['body'] ?? ''),
                    'media_url' => (string)($message['media_url'] ?? ''),
                    'media_mime' => (string)($message['media_mime'] ?? ''),
                    'media_file_name' => (string)($message['media_file_name'] ?? ''),
                    'message_type' => (string)($message['message_type'] ?? 'texto'),
                    'message_id' => (string)($message['message_id'] ?? ''),
                    'context_message_id' => (string)($message['context_message_id'] ?? ''),
                    'context_local_message_id' => (int)($message['context_local_message_id'] ?? 0),
                    'context_preview' => (string)($message['context_preview'] ?? ''),
                    'sent_at' => (string)($message['sent_at'] ?? ''),
                    'status' => (string)($message['status'] ?? ''),
                    'transcricao' => (string)($message['transcricao'] ?? ''),
                    'transcript' => (string)($message['transcript'] ?? ''),
                    'transcricao_erro' => (string)($message['transcricao_erro'] ?? ''),
                ];
            }, $messages),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_action'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($page === 'studio_whatsapp_manifest') {
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode([
        'name' => 'CRM WhatsApp',
        'short_name' => 'Zap CRM',
        'start_url' => app_url('studio_whatsapp_mobile'),
        'scope' => app_base_path() . '/',
        'display' => 'standalone',
        'background_color' => '#0b141a',
        'theme_color' => '#075e54',
        'icons' => [
            ['src' => app_asset_url('assets/wa-icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($page === 'studio_whatsapp_conversation') {
    $studio = require_studio();
    render_studio_shell('Conversa WhatsApp', 'Historico, atendimento e envio direto.', 'whatsapp', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $conversationId = (int)($_GET['id'] ?? 0);
        $conversation = studio_find_whatsapp_conversation($studio, $conversationId);
        if (!$conversation) {
            echo '<section class="panel"><h2>Conversa nao encontrada</h2><p class="muted">Volte para a central e escolha outra conversa.</p><a class="btn" href="' . h(app_url('studio_whatsapp')) . '">Abrir WhatsApp</a></section>';
            return;
        }
        $messages = studio_whatsapp_messages($studio, $conversationId, 80, $conversation);
        $assistantInsights = studio_whatsapp_assistant_insights($studio, $conversation, $messages);
        $displayName = $conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: 'Contato WhatsApp'));
        if (($displayName === 'Cliente WhatsApp' || $displayName === 'Contato WhatsApp' || $displayName === '') && !empty($assistantInsights['suggested_name'])) {
            $displayName = (string)$assistantInsights['suggested_name'];
        }
        $customers = studio_list_customers($studio);
        $leads = studio_list_leads($studio);
        $artists = studio_list_artists($studio);
        $quickReplies = array_values(array_filter(studio_list_quick_replies($studio), static fn(array $reply): bool => !empty($reply['is_active'])));
        $availableTags = studio_list_whatsapp_tags($studio);
        $conversationTags = studio_whatsapp_conversation_tags($studio, $conversationId);
        $scheduleSuggestion = studio_whatsapp_schedule_suggestion($conversation, $messages, $artists);
        if (!empty($assistantInsights['suggested_date']) && !empty($assistantInsights['suggested_time'])) {
            $scheduleSuggestion['date'] = (string)$assistantInsights['suggested_date'];
            $scheduleSuggestion['time'] = (string)$assistantInsights['suggested_time'];
        }
        if (!empty($assistantInsights['schedule_reason'])) {
            $scheduleSuggestion['reason'] = (string)$assistantInsights['schedule_reason'];
        }
        if (!empty($assistantInsights['suggested_interest']) && trim((string)($conversation['lead_interest'] ?? '')) === '') {
            $scheduleSuggestion['title'] = (string)$assistantInsights['suggested_interest'];
        }
        $availabilityStart = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
        $monthEnd = new DateTimeImmutable('last day of this month 23:59:59', new DateTimeZone('America/Sao_Paulo'));
        $availabilityRanges = [
            '3d' => ['label' => '3 dias', 'days' => 3],
            '7d' => ['label' => '7 dias', 'days' => 7],
            '15d' => ['label' => '15 dias', 'days' => 15],
            'month' => ['label' => 'Este mês', 'days' => max(1, (int)$monthEnd->diff($availabilityStart)->days + 1)],
            'next_month' => ['label' => 'Mês que vem', 'start' => $availabilityStart->modify('first day of next month'), 'days' => (int)$availabilityStart->modify('first day of next month')->format('t')],
            'custom' => ['label' => 'Prazo livre', 'days' => 365],
        ];
        $availabilityCardsByRange = [];
        $allowedDays = studio_schedule_days($studio);
        $allowedSlots = studio_schedule_slots($studio);
        foreach ($availabilityRanges as $rangeKey => $rangeInfo) {
            $rangeStart = $rangeInfo['start'] ?? $availabilityStart;
            $rangeDays = max(1, (int)($rangeInfo['days'] ?? 7));
            $rangeEnd = $rangeStart->modify('+' . max(0, $rangeDays - 1) . ' days');
            $availabilityAppointments = studio_calendar_appointments($studio, $rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d'));
            $appointmentsByDay = [];
            foreach ($availabilityAppointments as $appointment) {
                $appointmentsByDay[(string)$appointment['appointment_date']][] = $appointment;
            }
            $availabilityCards = [];
            for ($offset = 0; $offset < $rangeDays; $offset++) {
                $day = $rangeStart->modify('+' . $offset . ' days');
                $dateKey = $day->format('Y-m-d');
                $busy = count($appointmentsByDay[$dateKey] ?? []);
                $suggestedSlot = '';
                $freeSlots = [];
                $bookedAppointments = [];
                $isAllowedDay = in_array((string)$day->format('N'), $allowedDays, true);
                foreach ($allowedSlots as $slot) {
                    $taken = false;
                    foreach ($appointmentsByDay[$dateKey] ?? [] as $appointment) {
                        $startTime = substr((string)$appointment['start_time'], 0, 5);
                        if ($startTime === $slot) {
                            $taken = true;
                            $bookedAppointments[] = [
                                'id' => (int)($appointment['id'] ?? 0),
                                'time' => $startTime,
                                'title' => (string)($appointment['title'] ?? ''),
                                'customer_name' => (string)($appointment['customer_name'] ?? ''),
                                'status' => (string)($appointment['status'] ?? ''),
                            ];
                            break;
                        }
                    }
                    if (!$taken) {
                        $freeSlots[] = $slot;
                        $suggestedSlot = $slot;
                    }
                }
                $availabilityCards[] = [
                    'date' => $day->format('Y-m-d'),
                    'label' => $day->format('D d/m'),
                    'allowed' => $isAllowedDay,
                    'busy' => $busy,
                    'free' => max(0, count($allowedSlots) - $busy),
                    'slot' => $suggestedSlot,
                    'free_slots' => $freeSlots,
                    'booked' => $bookedAppointments,
                ];
            }
            $availabilityCardsByRange[$rangeKey] = $availabilityCards;
        }
        $availabilityCards = $availabilityCardsByRange['7d'] ?? [];
        $assistantAutofillEnabled = !empty(studio_settings($studio)['assistant_autofill_enabled']);
        $aiRawStatus = trim((string)($conversation['ai_last_status'] ?? ''));
        $aiStateLabel = $assistantAutofillEnabled ? 'Analisando' : 'Inativa';
        $aiStateTone = $assistantAutofillEnabled ? 'warn' : 'warn';
        $assistantConfidence = max(0, min(100, (int)round(((int)($assistantInsights['confidence'] ?? 0)) * 10)));
        if ($assistantAutofillEnabled && $assistantConfidence === 0 && count($messages) > 0) {
            $assistantConfidence = 35;
        }
        if ($assistantAutofillEnabled) {
            $aiStateLabel = 'Analisando';
            $aiStateTone = 'warn';
        }
        if ((string)($conversation['attendance_mode'] ?? 'human') === 'bot') {
            $aiStateLabel = 'Ativa';
            $aiStateTone = 'ok';
        }
        if ($aiRawStatus !== '') {
            $normalizedAi = function_exists('mb_strtolower') ? mb_strtolower($aiRawStatus, 'UTF-8') : strtolower($aiRawStatus);
            if (str_contains($normalizedAi, 'analis')) {
                $aiStateLabel = 'Analisando';
                $aiStateTone = 'warn';
            } elseif (str_contains($normalizedAi, 'erro') || str_contains($normalizedAi, 'falha') || str_contains($normalizedAi, 'sem resposta')) {
                $aiStateLabel = 'Erro';
                $aiStateTone = 'danger';
            } elseif (str_contains($normalizedAi, 'inativa') || str_contains($normalizedAi, 'desativada')) {
                $aiStateLabel = 'Inativa';
                $aiStateTone = 'neutral';
            } elseif (str_contains($normalizedAi, 'pronta') || str_contains($normalizedAi, 'respond')) {
                $aiStateLabel = 'Ativa';
                $aiStateTone = 'ok';
            } else {
                $aiStateLabel = $aiRawStatus;
                $aiStateTone = 'neutral';
            }
        } elseif ($assistantAutofillEnabled && (int)count($messages) > 0 && (string)($conversation['attendance_mode'] ?? 'human') === 'human') {
            $aiStateLabel = 'Analisando';
            $aiStateTone = 'warn';
        }

        echo '<section class="conversation-layout" style="grid-template-columns:minmax(0,1fr)">';
        echo '<div class="panel conversation-main">';
        echo '<div class="conversation-header">';
        echo '<div class="conversation-header-main">';
        echo '<div class="conversation-avatar">' . h(strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$displayName) ?: 'W', 0, 1))) . '</div>';
        echo '<div class="conversation-header-text">';
        echo '<h2>' . h($displayName) . '</h2><p class="muted">' . h($conversation['phone']) . '</p><span class="conversation-header-sub">Conta comercial</span><div class="actions" style="gap:6px;margin-top:6px">';
        foreach ($conversationTags as $tag) {
            echo '<span class="badge" style="border-color:' . h((string)$tag['color']) . ';color:' . h((string)$tag['color']) . '">' . h((string)$tag['name']) . '</span>';
        }
        echo '</div></div></div>';
        echo '<div class="actions conversation-header-actions d-flex flex-wrap gap-2 justify-content-end"><button class="wa-web-icon-btn" type="button" id="openConversationToolsButton" aria-label="Etiqueta e ações"><i class="fa-regular fa-bookmark"></i></button><button class="wa-web-icon-btn" type="button" aria-label="Chamada"><i class="fa-solid fa-video"></i></button><button class="wa-web-icon-btn" type="button" aria-label="Pesquisar"><i class="fa-solid fa-magnifying-glass"></i></button><button class="wa-web-icon-btn" type="button" aria-label="Menu"><i class="fa-solid fa-ellipsis-vertical"></i></button></div>';
        echo '</div>';
        render_chat_messages($messages);
        echo '<form class="form send-box" method="post" enctype="multipart/form-data" id="chatComposer">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="send_whatsapp_message"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="phone" value="' . h($conversation['phone']) . '">';
        echo '<div class="wa-web-composer-input"><button class="wa-web-icon-btn" type="button" id="chatAttachmentButton" aria-label="Anexar"><i class="fa-regular fa-square-plus"></i></button><textarea id="reply-message" name="message" placeholder="Digite uma mensagem" rows="1"></textarea><button class="wa-web-icon-btn" type="button" id="chatRecordButton" aria-label="Gravar audio"><i class="fa-solid fa-microphone"></i></button></div>';
        echo '<div class="emoji-strip" aria-label="Emojis rapidos">';
        foreach (['😀','😂','🎯','✅'] as $emoji) {
            echo '<button type="button" class="btn tiny secondary quick-reply-copy" data-reply="' . h($emoji) . '">' . h($emoji) . '</button>';
        }
        echo '</div>';
        echo '<div class="chat-attach-row">';
        echo '<input id="chatAttachment" type="file" name="media_file" accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.txt,.zip" hidden>';
        echo '<span id="chatRecordingState" class="muted"></span>';
        echo '</div>';
        echo '<div id="chatAttachmentPreview" class="chat-attachment-preview hidden"></div>';
        echo '<button class="wa-web-send-btn" type="submit" aria-label="Enviar"><i class="fa-solid fa-paper-plane"></i></button>';
        echo '</form></div>';

        echo '<div id="conversationToolsOverlay" class="crm-modal hidden">';
        echo '<div class="crm-modal-panel conversation-tools-panel" style="max-width:min(96vw,860px)">';
        echo '<div class="crm-panel-header"><div><h3 class="crm-panel-title">Ferramentas da conversa</h3><p class="muted" style="margin:4px 0 0">Cadastro, IA e respostas rápidas em um só lugar.</p></div><button type="button" id="closeConversationToolsOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div>';
        echo '<div class="panel conversation-tools-body">';
        echo '<div class="conversation-tools-actions">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">';
        echo '<div class="actions" style="gap:8px;flex-wrap:wrap">';
        echo '<span class="score-pill small">' . h((string)($conversation['lead_score'] ?? 0)) . '/10</span>';
        echo '<span class="badge ' . h($aiStateTone) . '" data-ai-state-badge>' . h($aiStateLabel) . '</span>';
        echo '<span class="badge" data-conversation-confidence>' . h((string)$assistantConfidence) . '% leitura</span>';
        echo '</div>';
        echo '<div class="actions" style="gap:8px;flex-wrap:wrap">';
        echo '<button class="btn secondary" type="button" data-mode-toggle="bot">Bot</button>';
        echo '<button class="btn secondary" type="button" data-mode-toggle="human">Humano</button>';
        echo '<button class="btn secondary" type="button" data-status-set="novo">Novo</button>';
        echo '<button class="btn secondary" type="button" id="openAppointmentModalButton">Agendar</button>';
        echo '<button class="btn secondary" type="button" id="toggleUnreadButton">Marcar nao lida</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="mini-metrics conversation-metrics row row-cols-1 row-cols-md-2 row-cols-xl-5 g-2"><span><strong data-message-count>' . h((string)count($messages)) . '</strong><small>Mensagens exibidas</small></span><span><strong data-wa-attendance>' . h($conversation['attendance_mode']) . '</strong><small>Atendimento</small></span><span><strong data-wa-needs-human>' . h(!empty($conversation['needs_human']) ? 'sim' : 'nao') . '</strong><small>Quer humano</small></span><span><strong data-wa-lead-status>' . h(($conversation['lead_status'] ?: 'em_conversa') . ' / ' . ($conversation['lead_pipeline_stage'] ?: 'em_conversa')) . '</strong><small>Funil</small></span><span class="ai-state-chip" data-ai-state data-ai-state-label="' . h($aiStateLabel) . '" data-ai-state-tone="' . h($aiStateTone) . '">' . h($conversation['ai_last_status'] ?: (($conversation['attendance_mode'] === 'bot') ? 'IA pronta' : 'IA inativa')) . '</span></div>';
        echo '<div class="conversation-inline-tools row row-cols-1 row-cols-lg-2 g-3">';
        echo '<div class="conversation-inline-group">';
        echo '<strong>Respostas rápidas</strong>';
        echo '<div class="quick-reply-list side-reply-list">';
        foreach (array_slice($quickReplies, 0, 12) as $reply) {
            echo '<button class="btn tiny secondary quick-reply-copy" type="button" data-reply="' . h($reply['body']) . '">' . h($reply['title']) . '</button>';
        }
        echo '</div>';
        echo '</div>';
        echo '<div class="conversation-inline-group">';
        echo '<strong>Sugestões da IA</strong>';
        if (!empty($assistantInsights['suggested_name']) || !empty($assistantInsights['suggested_interest']) || !empty($assistantInsights['suggested_notes']) || !empty($assistantInsights['schedule_reason'])) {
            echo '<div class="stack-list">';
            if (!empty($assistantInsights['suggested_name'])) {
                echo '<div class="drilldown-card compact"><strong>Nome sugerido</strong><div class="muted">' . h((string)$assistantInsights['suggested_name']) . '</div></div>';
            }
            if (!empty($assistantInsights['suggested_interest'])) {
                echo '<div class="drilldown-card compact"><strong>Interesse sugerido</strong><div class="muted">' . h((string)$assistantInsights['suggested_interest']) . '</div></div>';
            }
            if (!empty($assistantInsights['suggested_notes'])) {
                echo '<div class="drilldown-card compact"><strong>Observação sugerida</strong><div class="muted">' . h((string)$assistantInsights['suggested_notes']) . '</div></div>';
            }
            if (!empty($assistantInsights['schedule_reason'])) {
                echo '<div class="drilldown-card compact"><strong>Sugestão de agendamento</strong><div class="muted">' . h((string)$assistantInsights['schedule_reason']) . '</div></div>';
            }
        } else {
            echo '<p class="muted">Nenhuma sugestão clara detectada ainda.</p>';
        }
        echo '</div>';
        echo '</div>';
        echo '<div class="conversation-inline-tools row row-cols-1 row-cols-lg-2 g-3">';
        echo '<div class="conversation-inline-group"><strong>Tags</strong><div class="quick-reply-list">';
        $activeTagIds = array_map(static fn(array $tag): int => (int)$tag['id'], $conversationTags);
        foreach ($availableTags as $tag) {
            $active = in_array((int)$tag['id'], $activeTagIds, true);
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="toggle_whatsapp_conversation_tag"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="tag_id" value="' . h((string)$tag['id']) . '"><button class="btn tiny ' . ($active ? '' : 'secondary') . '" type="submit">' . ($active ? '✓ ' : '+ ') . h((string)$tag['name']) . '</button></form>';
        }
        echo '</div></div>';
        echo '<div class="conversation-inline-group"><strong>Mensagem interativa</strong><form class="form" method="post">' . csrf_field() . '<input type="hidden" name="action" value="send_whatsapp_interactive"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="phone" value="' . h((string)$conversation['phone']) . '"><select name="interactive_type"><option value="button">Botões</option><option value="list">Lista</option><option value="flow">Formulário</option></select><textarea name="message" required placeholder="Escolha uma opção:"></textarea><textarea name="interactive_options" placeholder="Uma opção por linha"></textarea><button class="btn" type="submit">Enviar interação</button></form></div>';
        echo '</div>';
        echo '<form class="form" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="update_whatsapp_profile"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '">';
        $nameFieldValue = $conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: ''));
        if (($nameFieldValue === '' || in_array(function_exists('mb_strtolower') ? mb_strtolower($nameFieldValue, 'UTF-8') : strtolower($nameFieldValue), ['cliente whatsapp', 'contato whatsapp', 'sem nome'], true)) && !empty($assistantInsights['suggested_name'])) {
            $nameFieldValue = (string)$assistantInsights['suggested_name'];
        }
        echo '<div class="grid cols-1 row row-cols-1 row-cols-md-2 g-3"><div class="field"><label>Nome</label><input name="name" value="' . h($nameFieldValue !== '' ? $nameFieldValue : $displayName) . '"></div><div class="field"><label>Telefone</label><input name="phone" value="' . h($conversation['phone']) . '"></div></div>';
        echo '<div class="grid cols-1 row row-cols-1 row-cols-md-2 g-3"><div class="field"><label>Email</label><input type="text" inputmode="email" name="email" value="' . h($conversation['customer_email'] ?? '') . '"></div><div class="field"><label>Instagram</label><input name="instagram" value="' . h($conversation['customer_instagram'] ?? '') . '"></div></div>';
        echo '<div class="field"><label>Cliente vinculado</label><select name="customer_id"><option value="">Criar/sem cliente</option>';
        render_customer_options($customers, (int)($conversation['customer_id'] ?? 0));
        echo '</select></div>';
        echo '<div class="field"><label>Lead vinculado</label><select name="lead_id"><option value="">Criar/sem lead</option>';
        render_lead_options($leads, (int)($conversation['lead_id'] ?? 0));
        echo '</select></div>';
        $interestFieldValue = $conversation['lead_interest'] ?: $conversation['last_message_preview'] ?: '';
        if ($interestFieldValue === '' && !empty($assistantInsights['suggested_interest'])) {
            $interestFieldValue = (string)$assistantInsights['suggested_interest'];
        }
        echo '<div class="field"><label>Interesse</label><input name="interest" value="' . h($interestFieldValue) . '"></div>';
        echo '<div class="grid cols-1 row row-cols-1 row-cols-md-2 g-3"><div class="field"><label>Status</label><select name="status">';
        render_options(lead_status_options(), (string)($conversation['lead_status'] ?: 'em_conversa'));
        echo '</select></div><div class="field"><label>Etapa </label><select name="pipeline_stage">';
        foreach (studio_list_pipeline_stages($studio) as $stage) {
            echo '<option value="' . h($stage['name']) . '" ' . ((string)$stage['name'] === (string)($conversation['lead_pipeline_stage'] ?: 'em_conversa') ? 'selected' : '') . '>' . h(studio_pipeline_stage_display_name((string)$stage['name'])) . '</option>';
        }
        echo '</select></div></div>';
        echo '<div class="grid cols-1 row row-cols-1 row-cols-md-2 g-3"><div class="field"><label>Valor estimado</label><input name="estimated_value" value="' . h((string)($conversation['lead_estimated_value'] ?? '0')) . '"></div><div class="field"><label>Origem</label><input name="source" value="WhatsApp"></div></div>';
        echo '<div class="grid cols-1 row row-cols-1 row-cols-md-2 g-3"><div class="field"><label>Modo de atendimento</label><select name="attendance_mode">';
        render_options(['human' => 'Humano', 'bot' => 'IA'], (string)$conversation['attendance_mode']);
        echo '</select></div><div class="field"><label>Nota do lead</label><input type="number" name="lead_score" min="0" max="10" value="' . h((string)($conversation['lead_score'] ?? 0)) . '"></div></div>';
        echo '<div class="field"><label>Status da analise</label><input name="ai_last_status" value="' . h($conversation['ai_last_status'] ?? '') . '" placeholder="ex: precisa retorno"></div>';
        $notesFieldValue = (string)($conversation['customer_notes'] ?? '');
        if ($notesFieldValue === '' && !empty($assistantInsights['suggested_notes'])) {
            $notesFieldValue = (string)$assistantInsights['suggested_notes'];
        }
        echo '<div class="field"><label>Observacoes do cliente</label><textarea name="notes">' . h($notesFieldValue) . '</textarea></div>';
        echo '<label class="checkline"><input type="checkbox" name="needs_human" value="1" ' . (!empty($conversation['needs_human']) ? 'checked' : '') . '> Cliente pediu humano</label>';
        echo '<label class="checkline"><input type="checkbox" name="create_customer" value="1" ' . (empty($conversation['customer_id']) ? 'checked' : '') . '> Criar/atualizar ficha de cliente</label>';
        echo '<label class="checkline"><input type="checkbox" name="create_lead" value="1" ' . (empty($conversation['lead_id']) ? 'checked' : '') . '> Criar/atualizar lead</label>';
        echo '<button class="btn" type="submit">Salvar cadastro</button>';
        echo '</form>';
        echo '<div class="info-list">';
        echo '<p><strong>Cliente:</strong> ' . ($conversation['customer_id'] ? '<a href="' . h(app_url('studio_customer', ['id' => (int)$conversation['customer_id']])) . '">' . h($conversation['customer_name'] ?: 'Abrir cliente') . '</a>' : '<span class="muted">sem cliente vinculado</span>') . '</p>';
        echo '<p><strong>Lead:</strong> ' . ($conversation['lead_id'] ? '<a href="' . h(app_url('studio_lead', ['id' => (int)$conversation['lead_id']])) . '">' . h($conversation['lead_name'] ?: 'Abrir lead') . '</a>' : '<span class="muted">sem lead vinculado</span>') . '</p>';
        echo '<p><strong>Interesse:</strong> ' . h($conversation['lead_interest'] ?: '-') . '</p>';
        echo '<p><strong>Funil:</strong> ' . h(($conversation['lead_status'] ?: '-') . ' / ' . ($conversation['lead_pipeline_stage'] ?: '-')) . '</p>';
        echo '<p><strong>Ultima mensagem:</strong> ' . h($conversation['last_message_at'] ?: '-') . '</p>';
        echo '</div>';
        echo '</div></div>';

        echo '<div id="appointmentModal" class="crm-modal hidden">';
        echo '<div class="crm-modal-panel" style="max-width:min(96vw,860px)">';
        echo '<div class="crm-panel-header"><div><h3 class="crm-panel-title">Agendar atendimento</h3></div><button type="button" id="closeAppointmentModal" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div>';
        echo '<form class="form action-card compact-action" method="post" enctype="multipart/form-data" style="padding:18px">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_appointment"><input type="hidden" name="customer_id" value="' . h((string)($conversation['customer_id'] ?? 0)) . '"><input type="hidden" name="lead_id" value="' . h((string)($conversation['lead_id'] ?? 0)) . '"><input type="hidden" name="import_source" value="whatsapp"><input type="hidden" name="return_to_conversation" value="' . h((string)$conversationId) . '">';
        echo '<h3>Criar agendamento</h3>';
        echo '<div class="grid cols-1 row row-cols-1 row-cols-md-2 g-3"><div class="field"><label>Titulo</label><input name="title" required value="' . h($conversation['lead_interest'] ?: 'Atendimento') . '"></div><div class="field"><label>Imagem de referencia</label><input id="appointmentReferenceInput" type="file" name="reference_image" accept="image/*" hidden><button class="btn secondary" type="button" id="appointmentReferenceButton">Anexar referencia</button></div></div>';
        echo '<div class="grid cols-1 row row-cols-1 row-cols-md-2 g-3"><div class="field"><label>Quantidade de pomadas</label><input type="number" min="0" step="1" name="pomadas_quantity" value="0" placeholder="0"><small class="muted">Quantas pomadas o cliente vai levar/fechar junto com o atendimento.</small></div><div class="field"><label>&nbsp;</label><div class="muted" style="padding-top:10px">Esse valor vai junto no agendamento.</div></div></div>';
        echo '<div id="appointmentReferencePreview" class="chat-attachment-preview hidden"></div>';
        echo '<div class="grid cols-1 row row-cols-1 row-cols-md-2 g-3"><div class="field"><label>Tatuador</label><select name="artist_id">';
        render_artist_options($artists, default_artist_id($studio) ?? 0);
        echo '</select></div><div class="field"><label>Status</label><select name="status">';
        render_options(appointment_status_options(), 'pre_agendado');
        echo '</select></div></div>';
        echo '<div class="grid cols-1 row row-cols-1 row-cols-md-3 g-3"><div class="field"><label>Data</label><input type="date" name="appointment_date" required value="' . h(date('Y-m-d')) . '"></div><div class="field"><label>Inicio</label><input type="time" name="start_time" required value="10:00"></div><div class="field"><label>Fim</label><input type="time" name="end_time" readonly></div></div>';
        echo '<div class="grid cols-1 row row-cols-1 row-cols-md-2 g-3"><div class="field"><label>Valor</label><input name="value" value="' . h((string)($conversation['lead_estimated_value'] ?? '')) . '"></div><div class="field"><label>Sinal</label><input name="deposit_value"></div></div>';
        echo '<div class="field"><label>Descricao</label><textarea name="description">' . h($conversation['last_message_preview'] ?? '') . '</textarea></div>';
        echo '<div class="panel" style="padding:12px">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Disponibilidade rapida</h3><span class="muted">Clique num dia e horario</span></div>';
        echo '<div class="availability-strip">';
        foreach ($availabilityCards as $card) {
            echo '<button type="button" class="availability-card" data-appointment-date="' . h($card['date']) . '" data-appointment-time="' . h($card['slot'] ?: '10:00') . '">';
            echo '<strong>' . h($card['label']) . '</strong>';
            echo '<span>' . h($card['allowed'] ? (string)$card['busy'] . ' ocupados' : 'Fora dos dias permitidos') . '</span>';
            echo '<small>' . h($card['allowed'] ? ($card['slot'] ? 'Livre: ' . $card['slot'] . ' - ' . ($scheduleSuggestion['end_time'] ?? '') : 'Sem slot livre rapido') : 'Nao sugerir') . '</small>';
            echo '</button>';
        }
        echo '</div></div>';
        echo '<button class="btn secondary" type="submit">Criar horario</button>';
        echo '</form></div></div>';
        echo '</section>';
        echo '<div id="mediaOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1100px)"><div class="crm-panel-header"><div><h3 id="mediaOverlayTitle" class="crm-panel-title">Midia</h3></div><button type="button" id="closeMediaOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="mediaOverlayBody" class="p-4 flex items-center justify-center"></div></div></div>';
        echo '<script>';
        echo '(() => {';
        echo 'const form = document.getElementById("chatComposer");';
        echo 'const input = document.getElementById("chatAttachment");';
        echo 'const preview = document.getElementById("chatAttachmentPreview");';
        echo 'const attachBtn = document.getElementById("chatAttachmentButton");';
        echo 'const recordBtn = document.getElementById("chatRecordButton");';
        echo 'const recordState = document.getElementById("chatRecordingState");';
        echo 'const textarea = document.getElementById("reply-message");';
        echo 'const applyScheduleSuggestionButton = document.getElementById("applyScheduleSuggestionButton");';
        echo 'const mediaOverlay = document.getElementById("mediaOverlay");';
        echo 'const mediaOverlayTitle = document.getElementById("mediaOverlayTitle");';
        echo 'const mediaOverlayBody = document.getElementById("mediaOverlayBody");';
        echo 'const closeMediaOverlay = document.getElementById("closeMediaOverlay");';
        echo 'const conversationToolsOverlay = document.getElementById("conversationToolsOverlay");';
        echo 'const openConversationToolsButton = document.getElementById("openConversationToolsButton");';
        echo 'const closeConversationToolsOverlay = document.getElementById("closeConversationToolsOverlay");';
        echo 'const chatThread = document.querySelector(".chat-thread");';
        echo 'const messageCountLabel = document.querySelector("[data-message-count]");';
        echo 'const conversationId = ' . (int)$conversationId . ';';
        echo 'let lastMessageCount = ' . count($messages) . ';';
        echo 'let recorder = null; let stream = null; let chunks = []; let recordedFile = null; let recordingTimer = null; let startedAt = 0;';
        echo 'let chatStickToBottom = true;';
        echo 'function clearAttachment(){ input.value = ""; recordedFile = null; preview.classList.add("hidden"); preview.innerHTML = ""; recordState.textContent = ""; if (recordingTimer) { clearInterval(recordingTimer); recordingTimer = null; } if (recorder && recorder.state !== "inactive") recorder.stop(); if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } recordBtn.textContent = "Gravar audio"; }';
        echo 'function escapeHtml(value){ return String(value ?? "").replace(/[&<>"\x27]/g, char => ({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#39;" }[char] || char)); }';
        echo 'function formatDateTimePt(value){ const raw = String(value || "").trim(); if (!raw) return "-"; const normalized = raw.includes("T") ? raw : raw.replace(" ", "T"); const date = new Date(normalized); if (Number.isNaN(date.getTime())) return raw; const weekdays = ["dom", "seg", "ter", "qua", "qui", "sex", "sáb"]; const weekday = weekdays[date.getDay()] || ""; const dd = String(date.getDate()).padStart(2, "0"); const mm = String(date.getMonth() + 1).padStart(2, "0"); const yyyy = date.getFullYear(); const hh = String(date.getHours()).padStart(2, "0"); const mi = String(date.getMinutes()).padStart(2, "0"); return `${weekday.toUpperCase()} - ${dd}/${mm}/${yyyy} ${hh}:${mi}`.trim(); }';
        echo 'function inferMediaType(mime, mediaUrl, type){ const normalizedMime = String(mime || "").toLowerCase().trim(); const normalizedType = String(type || "").toLowerCase().trim(); const rawUrl = String(mediaUrl || ""); const ext = (rawUrl.split("?")[0].split("#")[0].split(".").pop() || "").toLowerCase(); if (normalizedMime) { if (normalizedMime.startsWith("image/")) return "image"; if (normalizedMime.startsWith("audio/")) return "audio"; if (normalizedMime.startsWith("video/")) return "video"; } if (["jpg","jpeg","png","gif","webp","bmp","svg"].includes(ext) || normalizedType === "image") return "image"; if (["mp3","wav","ogg","oga","opus","webm","m4a","aac"].includes(ext) || normalizedType === "audio") return "audio"; if (["mp4","mov","m4v","avi","mkv"].includes(ext) || normalizedType === "video") return "video"; return normalizedType || "document"; }';
        echo 'function renderChatMessage(message){ const direction = String(message?.direction || "in"); const className = direction === "out" ? "out" : "in"; const body = String(message?.body || ""); const type = String(message?.message_type || "texto"); const mime = String(message?.media_mime || ""); const mediaUrl = String(message?.media_url || ""); let mediaName = String(message?.media_file_name || ""); const kind = inferMediaType(mime, mediaUrl, type); if (!mediaName && mediaUrl) { mediaName = decodeURIComponent(mediaUrl.split("/").pop().split("?")[0] || ""); } let html = `<div class="chat-message ${className}"><div class="chat-bubble">`; if (mediaUrl) { if (kind === "image") { html += `<button type="button" class="chat-media-thumb" onclick="window.openMediaOverlay && window.openMediaOverlay(this.dataset.mediaSrc, this.dataset.mediaTitle, this.dataset.mediaKind)" data-media-src="${escapeHtml(mediaUrl)}" data-media-title="${escapeHtml(mediaName || "midia")}" data-media-kind="image"><img src="${escapeHtml(mediaUrl)}" alt="${escapeHtml(mediaName || "midia")}" style="max-width:260px;max-height:220px;border-radius:8px"></button>`; } else if (kind === "video") { html += `<button type="button" class="chat-media-thumb" onclick="window.openMediaOverlay && window.openMediaOverlay(this.dataset.mediaSrc, this.dataset.mediaTitle, this.dataset.mediaKind)" data-media-src="${escapeHtml(mediaUrl)}" data-media-title="${escapeHtml(mediaName || "midia")}" data-media-kind="video"><video src="${escapeHtml(mediaUrl)}" style="max-width:280px;max-height:220px;border-radius:8px"></video></button>`; } else if (kind === "audio") { html += `<audio src="${escapeHtml(mediaUrl)}" controls style="width:280px;max-width:100%"></audio>`; if (!String(message?.transcricao || message?.transcript || "").trim()) { html += `<button class="btn tiny secondary" type="button" data-transcribe-audio="${escapeHtml(message?.message_id || "")}" data-media-url="${escapeHtml(mediaUrl)}">Transcrever audio</button>`; } } else { html += `<a class="muted" href="${escapeHtml(mediaUrl)}" target="_blank" rel="noopener">Abrir anexo${mediaName ? `: ${escapeHtml(mediaName)}` : ""}</a>`; } } if (body) { html += `<p>${escapeHtml(body).replace(/\n/g, "<br>")}</p>`; } else if (type !== "texto" && !mediaUrl) { html += `<p>[${escapeHtml(type)}]</p>`; } const transcribedText = String(message?.transcricao || message?.transcript || "").trim(); const transcribedError = String(message?.transcricao_erro || message?.transcript_error || "").trim(); if (transcribedText) { html += `<div class="chat-transcription-result">${escapeHtml(transcribedText)}</div>`; } if (transcribedError) { html += `<div class="chat-transcription-error">${escapeHtml(transcribedError)}</div>`; } html += `<span>${escapeHtml(String(message?.sender_type || "-"))} | ${escapeHtml(formatDateTimePt(message?.sent_at || "-"))}${String(message?.status || "") ? ` | ${escapeHtml(String(message.status))}` : ""}</span>`; html += `</div></div>`; return html; }';
        echo 'function isChatNearBottom(){ if (!chatThread) return true; return (chatThread.scrollTop + chatThread.clientHeight) >= (chatThread.scrollHeight - 120); }';
        echo 'function scrollChatToLatest(force = false){ if (!chatThread) return; if (force || chatStickToBottom || isChatNearBottom()) { chatThread.scrollTop = chatThread.scrollHeight; chatStickToBottom = true; } }';
        echo 'if (chatThread) { chatThread.addEventListener("scroll", () => { chatStickToBottom = isChatNearBottom(); }); }';
        echo 'function renderChatThread(messages){ if (!chatThread) return; const shouldStick = chatStickToBottom || isChatNearBottom(); if (!Array.isArray(messages) || messages.length === 0) { chatThread.innerHTML = `<p class="muted">Ainda nao ha mensagens registradas nesta conversa.</p>`; scrollChatToLatest(true); return; } chatThread.innerHTML = messages.map(renderChatMessage).join(""); chatStickToBottom = shouldStick; scrollChatToLatest(shouldStick); }';
        echo 'scrollChatToLatest(true);';
        echo 'function updateConversationMeta(count){ if (messageCountLabel) messageCountLabel.textContent = String(count); }';
        echo 'function renderPreview(){ const file = recordedFile || (input.files && input.files[0]); if (!file) { preview.classList.add("hidden"); preview.innerHTML = ""; return; } const url = URL.createObjectURL(file); let content = `<div class=\"flex items-center gap-3 flex-wrap\">`; if (file.type.startsWith("image/")) { content += `<img src=\"${url}\" style=\"max-width:180px;max-height:140px;border-radius:8px\">`; } else if (file.type.startsWith("audio/")) { content += `<audio src=\"${url}\" controls style=\"width:280px;max-width:100%\"></audio>`; } else if (file.type.startsWith("video/")) { content += `<video src=\"${url}\" controls style=\"max-width:220px;max-height:160px\"></video>`; } content += `<div><strong>${file.name}</strong><div class=\"muted text-sm\">${file.type || "arquivo"}</div></div><button type=\"button\" class=\"btn tiny secondary\" id=\"clearAttachmentBtn\">Remover</button></div>`; preview.classList.remove("hidden"); preview.innerHTML = content; const clearBtn = document.getElementById("clearAttachmentBtn"); if (clearBtn) clearBtn.addEventListener("click", clearAttachment); }';
        echo 'attachBtn.addEventListener("click", () => input.click());';
        echo 'input.addEventListener("change", () => { recordedFile = null; renderPreview(); });';
        echo 'document.querySelectorAll(".quick-reply-copy").forEach(button => button.addEventListener("click", () => { const reply = button.dataset.reply || ""; textarea.value = textarea.value ? textarea.value + "\\n" + reply : reply; textarea.focus(); }));';
        echo 'function openMediaOverlay(src, title, kind){ if (!src || !mediaOverlay || !mediaOverlayBody || !mediaOverlayTitle) return; mediaOverlayTitle.textContent = title || "Midia"; if (kind === "video") { mediaOverlayBody.innerHTML = `<video src="${src}" controls autoplay style="max-width:100%;max-height:82vh;border-radius:10px"></video>`; } else if (kind === "audio") { mediaOverlayBody.innerHTML = `<audio src="${src}" controls autoplay style="width:min(680px,100%)"></audio>`; } else { mediaOverlayBody.innerHTML = `<div style="width:100%;display:flex;justify-content:center"><img src="${src}" alt="${title || "Midia"}" style="max-width:100%;max-height:82vh;object-fit:contain;border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.35)"></div>`; } mediaOverlay.classList.remove("hidden"); } window.openMediaOverlay = openMediaOverlay;';
        echo 'document.addEventListener("click", (event) => { const button = event.target.closest(".chat-media-thumb"); if (!button) return; const src = button.dataset.mediaSrc || ""; const title = button.dataset.mediaTitle || "Midia"; const kind = button.dataset.mediaKind || "image"; openMediaOverlay(src, title, kind); });';
        echo 'if (closeMediaOverlay && mediaOverlay) { closeMediaOverlay.addEventListener("click", () => mediaOverlay.classList.add("hidden")); mediaOverlay.addEventListener("click", event => { if (event.target === mediaOverlay) mediaOverlay.classList.add("hidden"); }); }';
        echo 'if (openConversationToolsButton && conversationToolsOverlay) { openConversationToolsButton.addEventListener("click", () => conversationToolsOverlay.classList.remove("hidden")); }';
        echo 'if (closeConversationToolsOverlay && conversationToolsOverlay) { closeConversationToolsOverlay.addEventListener("click", () => conversationToolsOverlay.classList.add("hidden")); conversationToolsOverlay.addEventListener("click", event => { if (event.target === conversationToolsOverlay) conversationToolsOverlay.classList.add("hidden"); }); }';
        echo 'document.addEventListener("keydown", event => { if (event.key === "Escape") { if (mediaOverlay) mediaOverlay.classList.add("hidden"); if (conversationToolsOverlay) conversationToolsOverlay.classList.add("hidden"); if (appointmentModal) appointmentModal.classList.add("hidden"); } });';
        echo 'if (applyScheduleSuggestionButton) { applyScheduleSuggestionButton.addEventListener("click", () => { const title = ' . json_encode($scheduleSuggestion['title'] ?? '') . '; const date = ' . json_encode($scheduleSuggestion['date'] ?? '') . '; const time = ' . json_encode($scheduleSuggestion['time'] ?? '') . '; const desc = ' . json_encode($scheduleSuggestion['description'] ?? '') . '; const artist = ' . json_encode($scheduleSuggestion['artist_id'] ?? '') . '; const titleInput = document.querySelector(\'[name="title"]\'); const dateInput = document.querySelector(\'[name="appointment_date"]\'); const startTimeInput = document.querySelector(\'[name="start_time"]\'); const descInput = document.querySelector(\'[name="description"]\'); const artistInput = document.querySelector(\'[name="artist_id"]\'); if (titleInput) titleInput.value = title; if (dateInput) dateInput.value = date; if (startTimeInput) startTimeInput.value = time; if (descInput) descInput.value = desc; if (artistInput && artist) artistInput.value = artist; syncAppointmentEndTime(); document.getElementById("scheduleButton").click(); }); }';
        echo 'const csrfToken = document.querySelector(\'input[name="csrf_token"]\')?.value || "";';
        echo 'const attendanceLabel = document.querySelector("[data-wa-attendance]"); const needsHumanLabel = document.querySelector("[data-wa-needs-human]"); const leadStatusLabel = document.querySelector("[data-wa-lead-status]"); const aiStateLabel = document.querySelector("[data-ai-state]"); const aiStateBadge = document.querySelector("[data-ai-state-badge]"); const attendanceSelect = document.querySelector(\'[name="attendance_mode"]\'); const statusSelect = document.querySelector(\'[name="status"]\'); const pipelineSelect = document.querySelector(\'[name="pipeline_stage"]\'); const needsHumanCheckbox = document.querySelector(\'[name="needs_human"]\'); const assistantAutofillEnabled = ' . (!empty(studio_settings($studio)['assistant_autofill_enabled']) ? 'true' : 'false') . ';';
        echo 'function aiStateToneFromStatus(status, mode, assistantOn){ const text = String(status || "").toLowerCase(); if (text.includes("erro") || text.includes("falha") || text.includes("sem resposta")) return "danger"; if (text.includes("analis")) return "warn"; if (text.includes("inativa") || text.includes("desativ")) return assistantOn ? "warn" : "neutral"; if (mode === "bot" || text.includes("pronta") || text.includes("respond")) return "ok"; return assistantOn ? "warn" : (mode === "bot" ? "ok" : "warn"); }';
        echo 'function aiStateLabelFromStatus(status, mode, assistantOn){ const text = String(status || "").trim(); const lower = text.toLowerCase(); if (lower.includes("erro") || lower.includes("falha") || lower.includes("sem resposta")) return "Erro"; if (lower.includes("analis")) return "Analisando"; if (lower.includes("inativa") || lower.includes("desativ")) return assistantOn ? "Analisando" : "Inativa"; if (mode === "bot" || lower.includes("pronta") || lower.includes("respond")) return "Ativa"; return assistantOn ? "Analisando" : (text || (mode === "bot" ? "Ativa" : "Inativa")); }';
        echo 'function syncConversationUI(data){ const mode = String(data?.attendance_mode || ""); const needsHuman = !!data?.needs_human; const leadStatus = String(data?.lead_status || ""); const leadStage = String(data?.lead_pipeline_stage || ""); const aiStatus = String(data?.ai_last_status || ""); if (attendanceLabel && mode) attendanceLabel.textContent = mode; if (needsHumanLabel) needsHumanLabel.textContent = needsHuman ? "pedindo humano" : "sem pedido humano"; if (leadStatusLabel) leadStatusLabel.textContent = `${leadStatus || "em_conversa"} / ${leadStage || "em_conversa"}`; const aiTone = aiStateToneFromStatus(aiStatus, mode, assistantAutofillEnabled); const aiLabel = aiStateLabelFromStatus(aiStatus, mode, assistantAutofillEnabled); if (aiStateLabel) aiStateLabel.textContent = aiStatus || (assistantAutofillEnabled ? "Analisando" : (mode === "bot" ? "IA pronta" : "IA inativa")); if (aiStateBadge) { aiStateBadge.textContent = aiLabel; aiStateBadge.className = `badge ${aiTone}`; } if (attendanceSelect && mode) attendanceSelect.value = mode; if (statusSelect && leadStatus) statusSelect.value = leadStatus; if (pipelineSelect && leadStage) pipelineSelect.value = leadStage; if (needsHumanCheckbox) needsHumanCheckbox.checked = needsHuman; }';
        echo 'const appointmentModal = document.getElementById("appointmentModal"); const openAppointmentModalButton = document.getElementById("openAppointmentModalButton"); const closeAppointmentModal = document.getElementById("closeAppointmentModal");';
        echo 'if (openAppointmentModalButton && appointmentModal) { openAppointmentModalButton.addEventListener("click", () => appointmentModal.classList.remove("hidden")); }';
        echo 'if (closeAppointmentModal && appointmentModal) { closeAppointmentModal.addEventListener("click", () => appointmentModal.classList.add("hidden")); appointmentModal.addEventListener("click", event => { if (event.target === appointmentModal) appointmentModal.classList.add("hidden"); }); }';
        echo 'const appointmentReferenceInput = document.getElementById("appointmentReferenceInput"); const appointmentReferenceButton = document.getElementById("appointmentReferenceButton"); const appointmentReferencePreview = document.getElementById("appointmentReferencePreview"); const appointmentDateInput = document.querySelector(\'[name="appointment_date"]\'); const appointmentStartTimeInput = document.querySelector(\'[name="start_time"]\'); const appointmentEndTimeInput = document.querySelector(\'[name="end_time"]\'); const appointmentDurationMinutes = ' . (int)studio_schedule_duration_minutes($studio) . ';';
        echo 'if (appointmentReferenceButton && appointmentReferenceInput) { appointmentReferenceButton.addEventListener("click", () => appointmentReferenceInput.click()); }';
        echo 'if (appointmentReferenceInput && appointmentReferencePreview) { appointmentReferenceInput.addEventListener("change", () => { const file = appointmentReferenceInput.files && appointmentReferenceInput.files[0]; if (!file) { appointmentReferencePreview.classList.add("hidden"); appointmentReferencePreview.innerHTML = ""; return; } const url = URL.createObjectURL(file); appointmentReferencePreview.classList.remove("hidden"); appointmentReferencePreview.innerHTML = `<div class="flex items-center gap-3 flex-wrap"><img src="${url}" style="max-width:160px;max-height:120px;border-radius:8px"><div><strong>${file.name}</strong><div class="muted text-sm">${file.type || "imagem"}</div></div><button type="button" class="btn tiny secondary" id="clearAppointmentReferenceBtn">Remover</button></div>`; const clearBtn = document.getElementById("clearAppointmentReferenceBtn"); if (clearBtn) clearBtn.addEventListener("click", () => { appointmentReferenceInput.value = ""; appointmentReferencePreview.classList.add("hidden"); appointmentReferencePreview.innerHTML = ""; }); }); }';
        echo 'function pad2(value){ return String(value).padStart(2, "0"); }';
        echo 'function calculateAppointmentEndTime(dateValue, startValue, minutes){ const date = String(dateValue || "").trim(); const start = String(startValue || "").trim().slice(0, 5); const totalMinutes = Math.max(15, Number(minutes) || 0); if (!date || !start) return ""; const base = new Date(`${date}T${start}:00`); if (Number.isNaN(base.getTime())) return ""; base.setMinutes(base.getMinutes() + totalMinutes); return `${pad2(base.getHours())}:${pad2(base.getMinutes())}`; }';
        echo 'function syncAppointmentEndTime(){ if (!appointmentEndTimeInput) return; const endTime = calculateAppointmentEndTime(appointmentDateInput?.value, appointmentStartTimeInput?.value, appointmentDurationMinutes); if (endTime) appointmentEndTimeInput.value = endTime; }';
        echo 'if (appointmentDateInput) appointmentDateInput.addEventListener("change", syncAppointmentEndTime); if (appointmentStartTimeInput) appointmentStartTimeInput.addEventListener("change", syncAppointmentEndTime); syncAppointmentEndTime();';
        echo 'document.querySelectorAll("[data-appointment-date]").forEach(button => button.addEventListener("click", () => { const dateInput = document.querySelector(\'[name="appointment_date"]\'); const timeInput = document.querySelector(\'[name="start_time"]\'); if (dateInput) dateInput.value = button.dataset.appointmentDate || dateInput.value; if (timeInput) timeInput.value = button.dataset.appointmentTime || timeInput.value; syncAppointmentEndTime(); }));';
        echo 'async function postConversationUpdate(payload, errorMessage){ const body = new URLSearchParams({ csrf_token: csrfToken, conversation_id: String(conversationId), ...payload }); const response = await fetch(window.location.pathname + window.location.search, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json, text/plain, */*" }, body }); const contentType = response.headers.get("content-type") || ""; const data = contentType.includes("application/json") ? await response.json().catch(() => null) : null; const text = data ? "" : await response.text(); if (!response.ok || data?.ok === false) { throw new Error((data && data.error) || text.trim() || errorMessage); } return data || text; }';
        echo 'let conversationMarkedUnread = false;';
        echo 'function refreshUnreadButtonLabel(){ const toggleUnreadButton = document.getElementById("toggleUnreadButton"); if (toggleUnreadButton) toggleUnreadButton.textContent = conversationMarkedUnread ? "Marcar lida" : "Marcar nao lida"; }';
        echo 'async function loadConversationReadState(){ try { const response = await fetch("api/whatsapp_read_state.php", { cache: "no-store", headers: { "Accept": "application/json" } }); const data = await response.json().catch(() => null); conversationMarkedUnread = !(data?.ok && data.read && data.read[String(conversationId)]); refreshUnreadButtonLabel(); } catch (error) {} }';
        echo 'async function setConversationReadState(mode = "read"){ try { await fetch("api/whatsapp_read_state.php", { method: "POST", headers: { "Content-Type": "application/json", "Accept": "application/json" }, body: JSON.stringify({ id: conversationId, mode }) }); conversationMarkedUnread = mode === "unread"; refreshUnreadButtonLabel(); } catch (error) {} }';
        echo 'document.querySelectorAll("[data-mode-toggle]").forEach(button => button.addEventListener("click", async () => { try { const isBot = button.dataset.modeToggle === "bot"; const payload = { action: "update_whatsapp_profile", attendance_mode: isBot ? "bot" : "human", needs_human: isBot ? 0 : 1, ai_last_status: isBot ? "IA pronta para responder" : "IA inativa" }; await postConversationUpdate(payload, "Nao foi possivel atualizar o atendimento."); syncConversationUI(payload); } catch (error) { alert(error.message || "Nao foi possivel atualizar o atendimento."); } }));';
        echo 'document.querySelectorAll("[data-status-set]").forEach(button => button.addEventListener("click", async () => { try { const payload = { action: "update_whatsapp_profile", status: button.dataset.statusSet || "novo", create_lead: 1 }; await postConversationUpdate(payload, "Nao foi possivel atualizar o status."); syncConversationUI(payload); } catch (error) { alert(error.message || "Nao foi possivel atualizar o status."); } }));';
        echo 'async function toggleMobileAiMode(nextMode){ const payload = { action: "toggle_whatsapp_ai_mode", attendance_mode: nextMode }; await postConversationUpdate(payload, "Nao foi possivel alternar a IA."); location.reload(); }';
        echo 'document.getElementById("m2AiModeButton")?.addEventListener("click", async () => { try { const button = document.getElementById("m2AiModeButton"); const nextMode = button?.dataset.nextMode || "bot"; if (nextMode === "bot" && !confirm("Ativar IA automatica para esta conversa? Ela podera responder novas mensagens.")) { return; } await toggleMobileAiMode(nextMode); } catch (error) { alert(error.message || "Nao foi possivel alternar a IA."); } });';
        echo 'async function toggleRecording(){ if (recorder && recorder.state === "recording") { recorder.stop(); return; } if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) { alert("Seu navegador nao liberou gravacao de audio aqui."); return; } try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); const preferredMime = MediaRecorder.isTypeSupported("audio/ogg;codecs=opus") ? "audio/ogg;codecs=opus" : (MediaRecorder.isTypeSupported("audio/webm;codecs=opus") ? "audio/webm;codecs=opus" : ""); const options = preferredMime ? { mimeType: preferredMime } : {}; recorder = new MediaRecorder(stream, options); chunks = []; startedAt = Date.now(); recordBtn.textContent = "Parar"; recordState.textContent = "Gravando..."; recordingTimer = setInterval(() => { const elapsed = Math.floor((Date.now() - startedAt) / 1000); recordState.textContent = `Gravando ${String(Math.floor(elapsed / 60)).padStart(2, "0")}:${String(elapsed % 60).padStart(2, "0")}`; }, 500); recorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); }; recorder.onstop = () => { if (recordingTimer) { clearInterval(recordingTimer); recordingTimer = null; } if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } const mime = recorder.mimeType || preferredMime || "audio/webm"; const ext = mime.includes("ogg") || mime.includes("opus") ? "ogg" : "webm"; const blob = new Blob(chunks, { type: mime }); recordedFile = new File([blob], `audio_${Date.now()}.${ext}`, { type: mime }); const dt = new DataTransfer(); dt.items.add(recordedFile); input.files = dt.files; renderPreview(); recordBtn.textContent = "Gravar audio"; recordState.textContent = "Audio pronto para envio"; }; recorder.start(); } catch (error) { alert("Nao foi possivel iniciar a gravacao."); } }';
        echo 'recordBtn.addEventListener("click", toggleRecording);';
        echo 'if (form) form.addEventListener("submit", async (event) => { event.preventDefault(); event.stopPropagation(); const hasText = !!textarea.value.trim(); const hasFile = !!(input.files && input.files.length); if (!hasText && !hasFile) return; const formData = new FormData(form); try { const response = await fetch(window.location.pathname + window.location.search, { method: "POST", body: formData }); if (!response.ok) throw new Error("Nao foi possivel enviar a mensagem."); textarea.value = ""; clearAttachment(); location.reload(); } catch (error) { alert(error.message || "Erro ao enviar mensagem"); } });';
        echo 'const pollConversation = async () => { try { const response = await fetch(`api_chat.php?id=${encodeURIComponent(conversationId)}&_=${Date.now()}`, { cache: "no-store", headers: { "Accept": "application/json" } }); const data = await response.json().catch(() => null); if (!data?.ok) return; syncConversationUI(data.conversation || {}); const messages = Array.isArray(data.mensagens) ? data.mensagens : []; const count = messages.length; if (count !== lastMessageCount) { lastMessageCount = count; updateConversationMeta(count); renderChatThread(messages); } else if (messages.length > 0) { const latestSignature = `${messages[messages.length - 1]?.message_id || ""}|${messages[messages.length - 1]?.sent_at || ""}|${messages[messages.length - 1]?.transcricao || ""}|${messages[messages.length - 1]?.transcript || ""}`; if (pollConversation._lastSignature !== latestSignature) { pollConversation._lastSignature = latestSignature; renderChatThread(messages); } } if (messages.some(msg => !msg.from_me && !msg.fromMe)) { setConversationReadState("read"); } } catch (error) {} };';
        echo 'pollConversation._lastSignature = "";';
        echo 'loadConversationReadState().then(() => { const toggleUnreadButton = document.getElementById("toggleUnreadButton"); if (toggleUnreadButton) toggleUnreadButton.addEventListener("click", () => setConversationReadState(conversationMarkedUnread ? "read" : "unread")); setConversationReadState("read"); });';
        echo 'setInterval(pollConversation, 3000);';
        echo 'document.addEventListener("click", async (event) => { const btn = event.target.closest("[data-transcribe-audio]"); if (!btn) return; event.preventDefault(); if (btn.dataset.busy === "1") return; btn.dataset.busy = "1"; const oldLabel = btn.textContent; btn.textContent = "Transcrevendo..."; try { const response = await fetch("api/whatsapp_transcribe_audio_v2.php", { method: "POST", headers: { "Content-Type": "application/json", "Accept": "application/json" }, body: JSON.stringify({ conversation_id: ' . (int)$conversationId . ', message_id: btn.dataset.transcribeAudio || "", media_url: btn.dataset.mediaUrl || "" }) }); const data = await response.json().catch(() => null); if (!data?.ok) throw new Error(data?.error || "Nao foi possivel transcrever o audio"); const bubble = btn.closest(".chat-bubble"); if (bubble) { let box = bubble.querySelector(".chat-transcription-result"); if (!box) { box = document.createElement("div"); box.className = "chat-transcription-result"; box.style.cssText = "margin-top:10px;padding:10px 12px;border-radius:8px;background:rgba(0,0,0,.2);font-size:.9rem"; bubble.appendChild(box); } box.textContent = "Transcricao: " + data.text; } btn.textContent = "Transcrito"; } catch (error) { alert(error.message); btn.textContent = oldLabel; } finally { btn.dataset.busy = "0"; } });';
        echo '})();';
        echo '</script>';
    }, $flash);
    exit;
}

if ($page === 'studio_finance') {
    $studio = require_studio();
    render_studio_shell('Financeiro', 'Despesas e leitura simples do resultado mensal.', 'finance', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $defaultStart = date('Y-m-01');
        $defaultEnd = date('Y-m-t');
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? (string)$_GET['date_from'] : $defaultStart;
        $dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? (string)$_GET['date_to'] : $defaultEnd;
        if ($dateTo < $dateFrom) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        $summary = studio_finance_summary($studio, $dateFrom, $dateTo);
        $expenses = studio_list_expenses($studio, $dateFrom, $dateTo);
        $periodLabel = format_date_pt($dateFrom, false) . ' até ' . format_date_pt($dateTo, false);
        echo '<section class="panel finance-hero">';
        echo '<div><span class="section-eyebrow">Financeiro</span><h2 style="margin:4px 0">Resultado do período</h2><p class="muted mb-0">' . h($periodLabel) . '</p></div>';
        echo '<form class="filter-bar finance-period-form" method="get"><input type="hidden" name="page" value="studio_finance">';
        echo '<label>De<input type="date" name="date_from" value="' . h($dateFrom) . '"></label>';
        echo '<label>Até<input type="date" name="date_to" value="' . h($dateTo) . '"></label>';
        echo '<button class="btn secondary" type="submit">Aplicar</button><a class="btn secondary" href="' . h(app_url('studio_finance')) . '">Este mês</a>';
        echo '</form></section>';
        echo '<section class="grid cols-3 finance-kpi-grid">';
        echo '<button type="button" class="panel dashboard-stat h-100 text-start finance-kpi-primary" data-finance-overlay="agenda"><p class="metric">' . h(format_money($summary['appointments_period'])) . '</p><p class="muted">Receita lançada no período</p><span class="muted">' . h((string)$summary['appointments_count']) . ' agendamentos · ticket médio ' . h(format_money($summary['average_ticket'])) . '</span></button>';
        echo '<button type="button" class="panel dashboard-stat h-100 text-start" data-finance-overlay="agenda"><p class="metric">' . h(format_money($summary['deposits_period'])) . '</p><p class="muted">Sinais recebidos</p><span class="muted">Valor já pago/abatido nos agendamentos</span></button>';
        echo '<button type="button" class="panel dashboard-stat h-100 text-start" data-finance-overlay="expense-form"><p class="metric">' . h(format_money($summary['expenses_period'])) . '</p><p class="muted">Despesas no período</p><span class="muted">Lançar despesa</span></button>';
        echo '<button type="button" class="panel dashboard-stat h-100 text-start" data-finance-overlay="recent"><p class="metric">' . h(format_money($summary['balance_period'])) . '</p><p class="muted">Resultado previsto</p><span class="muted">Receita lançada - despesas</span></button>';
        echo '<div class="panel dashboard-stat h-100 text-start"><p class="metric">' . h(format_money($summary['appointments_month'])) . '</p><p class="muted">Agenda deste mês</p><span class="muted">Comparativo rápido do mês atual</span></div>';
        echo '<div class="panel dashboard-stat h-100 text-start"><p class="metric">' . h(format_money($summary['balance_month'])) . '</p><p class="muted">Resultado deste mês</p><span class="muted">Mês calendário atual</span></div>';
        echo '</section>';
        echo '<div id="financeAgendaSource" hidden><div class="panel shadow-sm border-0" style="margin:0"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><h2>Agenda no período</h2><a class="btn secondary" href="' . h(app_url('studio_agenda')) . '">Abrir agenda</a></div><p class="muted">Receita lançada, sinais e status dos agendamentos em ' . h($periodLabel) . '.</p>';
        if (!empty($summary['appointments_by_status'])) {
            echo '<div class="table-responsive"><table class="data-table"><thead><tr><th>Status</th><th>Qtd</th><th>Receita</th><th>Sinal</th></tr></thead><tbody>';
            foreach ($summary['appointments_by_status'] as $row) {
                echo '<tr><td>' . h((string)($row['status'] ?? '-')) . '</td><td>' . h((string)($row['qtd'] ?? 0)) . '</td><td>' . h(format_money((float)($row['total'] ?? 0))) . '</td><td>' . h(format_money((float)($row['sinal'] ?? 0))) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p class="muted">Nenhum agendamento financeiro no período.</p>';
        }
        echo '</div></div>';
        echo '<div id="financeExpenseSource" hidden><form class="form panel" method="post" id="nova-despesa">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_expense">';
        echo '<input type="hidden" name="id" value="">';
        echo '<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><h2 data-expense-form-title>Nova despesa</h2><span class="badge">Controle rápido</span></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Categoria</label><input name="category" value="Geral"></div><div class="field"><label>Data</label><input type="date" name="expense_date" value="' . h(date('Y-m-d')) . '" required></div></div>';
        echo '<div class="field"><label>Descricao</label><input name="description" required placeholder="Material, aluguel, trafego, insumo..."></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Valor</label><input name="amount" required placeholder="120,00"></div><div class="field"><label>Pagamento</label><input name="payment_method" placeholder="Pix, cartao, dinheiro..."></div></div>';
        echo '<div class="field"><label>Observacoes</label><textarea name="notes"></textarea></div>';
        echo '<div class="actions"><button class="btn" type="submit">Salvar despesa</button><button class="btn secondary" type="button" data-expense-reset hidden>Cancelar edição</button></div>';
        echo '</form></div>';
        echo '<div id="financeRecentSource" hidden><div class="panel shadow-sm border-0" style="margin:0"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><h2>Despesas por categoria</h2><span class="muted">' . h($periodLabel) . '</span></div>';
        render_category_totals($summary['by_category']);
        echo '<div class="panel shadow-sm border-0" style="margin-top:16px"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><h2>Despesas recentes</h2><span class="muted">Lista completa do mês</span></div>';
        render_expenses_table($expenses);
        echo '</div></div></div>';
        echo '<div id="financeOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1100px)"><div class="crm-panel-header"><div><h3 id="financeOverlayTitle" class="crm-panel-title">Detalhe</h3><p class="muted" id="financeOverlaySummary" style="margin:4px 0 0"></p></div><button type="button" id="closeFinanceOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="financeOverlayBody" class="p-4"></div></div></div>';
        echo '<script>(function(){const modal=document.getElementById("financeOverlay");const title=document.getElementById("financeOverlayTitle");const summary=document.getElementById("financeOverlaySummary");const body=document.getElementById("financeOverlayBody");const closeBtn=document.getElementById("closeFinanceOverlay");const agenda=document.getElementById("financeAgendaSource");const expense=document.getElementById("financeExpenseSource");const recent=document.getElementById("financeRecentSource");if(!modal||!title||!summary||!body||!agenda||!expense||!recent)return;function resetExpenseForm(){const form=body.querySelector("#nova-despesa");if(!form)return;form.reset();form.querySelector("[name=id]").value="";form.querySelector("[name=category]").value="Geral";form.querySelector("[name=expense_date]").value=new Date().toISOString().slice(0,10);const heading=form.querySelector("[data-expense-form-title]");if(heading)heading.textContent="Nova despesa";const reset=form.querySelector("[data-expense-reset]");if(reset)reset.hidden=true;title.textContent="Nova despesa";summary.textContent="Lançamento rápido de despesa.";}function open(kind){if(kind==="agenda"){title.textContent="Agenda no período";summary.textContent="Visão rápida da agenda vinculada ao período.";body.innerHTML=agenda.innerHTML;}else if(kind==="expense-form"){title.textContent="Nova despesa";summary.textContent="Lançamento rápido de despesa.";body.innerHTML=expense.innerHTML;}else{title.textContent="Resultado e despesas";summary.textContent="Categorias e despesas recentes.";body.innerHTML=recent.innerHTML;}modal.classList.remove("hidden");}document.querySelectorAll("[data-finance-overlay]").forEach((btn)=>btn.addEventListener("click",()=>open(btn.getAttribute("data-finance-overlay")||"")));body.addEventListener("click",(event)=>{const edit=event.target.closest("[data-expense-edit]");if(edit){const expenseData=JSON.parse(edit.getAttribute("data-expense-edit")||"{}");body.innerHTML=expense.innerHTML;const form=body.querySelector("#nova-despesa");if(!form)return;Object.entries(expenseData).forEach(([name,value])=>{const field=form.elements.namedItem(name);if(field)field.value=value??"";});const heading=form.querySelector("[data-expense-form-title]");if(heading)heading.textContent="Editar despesa";const reset=form.querySelector("[data-expense-reset]");if(reset)reset.hidden=false;title.textContent="Editar despesa";summary.textContent="Revise os dados e salve sem criar outro lançamento.";return;}if(event.target.closest("[data-expense-reset]"))resetExpenseForm();});if(closeBtn) closeBtn.addEventListener("click",()=>modal.classList.add("hidden"));modal.addEventListener("click",(event)=>{if(event.target===modal) modal.classList.add("hidden");});document.addEventListener("keydown",(event)=>{if(event.key==="Escape") modal.classList.add("hidden");});})();</script>';
    }, $flash);
    exit;
}

if ($page === 'studio_people') {
    $studio = require_studio();
    render_studio_shell('Pessoas', 'Clientes e leads num unico lugar.', 'people', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $view = (string)($_GET['view'] ?? 'all');
        $q = trim((string)($_GET['q'] ?? ''));
        $customers = studio_list_customers($studio);
        $leads = studio_list_leads($studio);
        if ($q !== '') {
            $customers = array_values(array_filter($customers, static fn(array $row): bool => stripos((string)($row['name'] ?? ''), $q) !== false || stripos((string)($row['phone'] ?? ''), $q) !== false || stripos((string)($row['email'] ?? ''), $q) !== false || stripos((string)($row['instagram'] ?? ''), $q) !== false));
            $leads = array_values(array_filter($leads, static fn(array $row): bool => stripos((string)($row['name'] ?? ''), $q) !== false || stripos((string)($row['phone'] ?? ''), $q) !== false || stripos((string)($row['interest'] ?? ''), $q) !== false || stripos((string)($row['source'] ?? ''), $q) !== false));
        }
        $totalCustomers = count($customers);
        $totalLeads = count($leads);
        echo '<section class="panel shadow-sm border-0 people-hero"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><span class="section-eyebrow">Base do estúdio</span><h2>Pessoas</h2><p class="muted">Clientes, leads e conversas em uma leitura rápida.</p></div><span class="badge">' . h((string)($totalCustomers + $totalLeads)) . ' registros</span></div>';
        echo '<form class="filter-bar row row-cols-1 row-cols-md-2 row-cols-xl-4 g-2 align-items-end" method="get"><input type="hidden" name="page" value="studio_people">';
        echo '<div class="col"><input name="q" placeholder="Buscar por nome, telefone, email ou interesse..." value="' . h($q) . '"></div>';
        echo '<div class="col"><select name="view">';
        foreach (['all' => 'Tudo', 'leads' => 'Leads', 'customers' => 'Clientes'] as $key => $label) {
            echo '<option value="' . h($key) . '" ' . ($view === $key ? 'selected' : '') . '>' . h($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col d-flex gap-2 flex-wrap"><button class="btn secondary" type="submit">Filtrar</button><a class="btn secondary" href="' . h(app_url('studio_people')) . '">Limpar</a></div></form>';
        echo '</section>';
        echo '<section class="grid cols-3 people-kpi-grid" style="margin-top:16px">';
        echo '<button type="button" class="panel dashboard-stat dashboard-stat-button" data-people-overlay="customers"><p class="metric">' . h((string)$totalCustomers) . '</p><p class="muted">Clientes</p><span class="muted">Abrir cadastros</span></button>';
        echo '<a class="panel dashboard-stat" href="' . h(app_url('studio_leads')) . '"><p class="metric">' . h((string)$totalLeads) . '</p><p class="muted">Leads</p><span class="muted">Abrir funil</span></a>';
        echo '<a class="panel dashboard-stat" href="' . h(app_url('studio_whatsapp')) . '"><p class="metric">' . h((string)studio_whatsapp_summary($studio)['total']) . '</p><p class="muted">Conversas WhatsApp</p><span class="muted">Ver integrações</span></a>';
        echo '</section>';
        echo '<section class="grid cols-2 people-secondary-grid" style="margin-top:16px">';
        echo '<button type="button" class="panel dashboard-stat dashboard-stat-button" data-people-overlay="leads"><p class="metric">' . h((string)$totalLeads) . '</p><p class="muted">Leads recentes</p><span class="muted">Abrir lista em overlay</span></button>';
        echo '<button type="button" class="panel dashboard-stat dashboard-stat-button" data-people-overlay="customers"><p class="metric">' . h((string)$totalCustomers) . '</p><p class="muted">Clientes recentes</p><span class="muted">Abrir lista em overlay</span></button>';
        echo '</section>';
        echo '<div id="peopleLeadsSource" class="hidden" hidden><div class="panel" style="margin:0"><div class="actions" style="justify-content:space-between"><h2>Leads recentes</h2><a class="btn secondary" href="' . h(app_url('studio_leads')) . '">Abrir funil</a></div>';
        render_leads_table(array_slice($leads, 0, 12));
        echo '</div></div>';
        echo '<div id="peopleCustomersSource" class="hidden" hidden><div class="panel" style="margin:0"><div class="actions" style="justify-content:space-between"><h2>Clientes recentes</h2><a class="btn secondary" href="' . h(app_url('studio_customers')) . '">Abrir clientes</a></div>';
        render_customers_table(array_slice($customers, 0, 12));
        echo '</div></div>';
        echo '<div id="peopleOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1100px)"><div class="crm-panel-header"><div><h3 id="peopleOverlayTitle" class="crm-panel-title">Detalhe</h3><p class="muted" id="peopleOverlaySummary" style="margin:4px 0 0"></p></div><button type="button" id="closePeopleOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="peopleOverlayBody" class="p-4"></div></div></div>';
        echo '<script>(function(){const modal=document.getElementById("peopleOverlay");const title=document.getElementById("peopleOverlayTitle");const summary=document.getElementById("peopleOverlaySummary");const body=document.getElementById("peopleOverlayBody");const closeBtn=document.getElementById("closePeopleOverlay");const leadsSource=document.getElementById("peopleLeadsSource");const customersSource=document.getElementById("peopleCustomersSource");if(!modal||!title||!summary||!body||!leadsSource||!customersSource)return;function openOverlay(kind){if(kind==="leads"){title.textContent="Leads recentes";summary.textContent="' . h((string)$totalLeads) . ' registros mostrados em overlay.";body.innerHTML=leadsSource.innerHTML;}else{title.textContent="Clientes recentes";summary.textContent="' . h((string)$totalCustomers) . ' registros mostrados em overlay.";body.innerHTML=customersSource.innerHTML;}modal.classList.remove("hidden");}document.querySelectorAll("[data-people-overlay]").forEach((btn)=>{btn.addEventListener("click",()=>openOverlay(btn.getAttribute("data-people-overlay")||""));});if(closeBtn) closeBtn.addEventListener("click",()=>modal.classList.add("hidden"));modal.addEventListener("click",(event)=>{if(event.target===modal) modal.classList.add("hidden");});document.addEventListener("keydown",(event)=>{if(event.key==="Escape") modal.classList.add("hidden");});})();</script>';
    }, $flash);
    exit;
}

if ($page === 'studio_quick_replies') {
    $studio = require_studio();
    render_studio_shell('Respostas rápidas', 'Biblioteca compartilhada e pessoal para agilizar o atendimento.', 'whatsapp', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }

        $replies = studio_list_quick_replies($studio, null, true);
        $editId = max(0, (int)($_GET['edit'] ?? 0));
        $editing = null;
        foreach ($replies as $reply) {
            if ((int)$reply['id'] === $editId) {
                $editing = $reply;
                break;
            }
        }
        $isAdmin = studio_current_user_is_admin();
        echo '<section class="panel"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><span class="section-eyebrow">Atendimento</span><h2 style="margin:4px 0">' . ($editing ? 'Editar resposta' : 'Nova resposta rápida') . '</h2><p class="muted">Use atalhos como <strong>/valor</strong>. Respostas pessoais aparecem só para você; as do estúdio ficam disponíveis para toda a equipe.</p></div><a class="btn secondary" href="' . h(app_url('studio_settings', ['tab' => 'quick_replies'])) . '">Voltar às configurações</a></div>';
        echo '<form class="form" method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_quick_reply"><input type="hidden" name="id" value="' . h((string)($editing['id'] ?? '')) . '">';
        echo '<div class="grid cols-2"><div class="field"><label>Título</label><input name="title" required maxlength="140" value="' . h((string)($editing['title'] ?? '')) . '" placeholder="Ex.: Como funciona o orçamento"></div><div class="field"><label>Atalho</label><input name="shortcut" maxlength="80" value="' . h((string)($editing['shortcut'] ?? '')) . '" placeholder="/orcamento"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Categoria</label><input name="category" maxlength="80" value="' . h((string)($editing['category'] ?? 'Geral')) . '"></div><div class="field"><label>Visibilidade</label><select name="scope">';
        render_options($isAdmin ? ['personal' => 'Somente para mim', 'studio' => 'Todo o estúdio'] : ['personal' => 'Somente para mim'], (string)($editing['scope'] ?? 'personal'));
        echo '</select></div></div>';
        echo '<div class="field"><label>Texto da resposta</label><textarea name="body" rows="6" required placeholder="Escreva a mensagem que será inserida na conversa.">' . h((string)($editing['body'] ?? '')) . '</textarea></div>';
        echo '<label class="checkline"><input type="checkbox" name="is_active" value="1" ' . (!isset($editing['is_active']) || !empty($editing['is_active']) ? 'checked' : '') . '> Resposta ativa</label>';
        echo '<div class="actions" style="margin-top:14px"><button class="btn" type="submit">' . ($editing ? 'Salvar alterações' : 'Criar resposta') . '</button>' . ($editing ? '<a class="btn secondary" href="' . h(app_url('studio_quick_replies')) . '">Cancelar edição</a>' : '') . '</div></form></section>';

        echo '<section class="panel"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2 style="margin:0">Biblioteca</h2><p class="muted">' . h((string)count($replies)) . ' respostas visíveis para seu login.</p></div><span class="badge">Equipe</span></div>';
        if (!$replies) {
            echo '<p class="muted">Nenhuma resposta rápida cadastrada.</p>';
        } else {
            echo '<div class="table-responsive"><table class="table"><thead><tr><th>Resposta</th><th>Categoria</th><th>Escopo</th><th>Status</th><th>Ações</th></tr></thead><tbody>';
            foreach ($replies as $reply) {
                $editable = !empty($reply['studio_user_id']) || $isAdmin;
                echo '<tr><td><strong>' . h((string)$reply['title']) . '</strong><br><span class="muted">' . h((string)($reply['shortcut'] ?: 'sem atalho')) . '</span><br>' . nl2br(h((string)$reply['body'])) . '</td>';
                echo '<td><span class="badge">' . h((string)$reply['category']) . '</span></td><td><span class="badge">' . h((string)($reply['scope'] === 'personal' ? 'pessoal' : 'estúdio')) . '</span></td><td><span class="badge ' . (!empty($reply['is_active']) ? 'ok' : 'warn') . '">' . (!empty($reply['is_active']) ? 'ativa' : 'inativa') . '</span></td><td>';
                if ($editable) {
                    echo '<div class="actions"><a class="btn tiny secondary" href="' . h(app_url('studio_quick_replies', ['edit' => (int)$reply['id']])) . '">Editar</a><form method="post" onsubmit="return confirm(\'Excluir esta resposta rápida?\')">' . csrf_field() . '<input type="hidden" name="action" value="delete_quick_reply"><input type="hidden" name="id" value="' . h((string)$reply['id']) . '"><button class="btn tiny danger" type="submit">Excluir</button></form></div>';
                } else {
                    echo '<span class="muted">Somente leitura</span>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
    }, $flash);
    exit;
}

if ($page === 'studio_reports') {
    $studio = require_studio();
    render_studio_shell('Relatórios', 'Leitura gerencial do funil, agenda e financeiro.', 'reports', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $pdo = studio_db($studio);
        studio_ensure_appointment_reference_columns($studio);
        $today = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
        $monthStart = new DateTimeImmutable('first day of this month', new DateTimeZone('America/Sao_Paulo'));
        $monthEnd = new DateTimeImmutable('last day of this month 23:59:59', new DateTimeZone('America/Sao_Paulo'));
        $alerts = [];
        $staleLeads = $pdo->prepare(
            "SELECT l.id, l.name, l.phone, l.status, l.pipeline_stage, l.lead_score, l.updated_at, l.created_at
             FROM leads l
             WHERE l.status NOT IN ('perdido', 'fechado')
               AND COALESCE(l.updated_at, l.created_at) < ?
             ORDER BY COALESCE(l.updated_at, l.created_at) ASC
             LIMIT 8"
        );
        $staleLeads->execute([$today->modify('-24 hours')->format('Y-m-d H:i:s')]);
        $staleLeadsRows = $staleLeads->fetchAll() ?: [];
        if ($staleLeadsRows) {
            $alerts[] = [
                'title' => 'Leads abertos sem atualização há mais de 24h',
                'count' => count($staleLeadsRows),
                'tone' => 'warn',
                'items' => array_map(static function (array $lead): array {
                    return [
                        'label' => ($lead['name'] ?: $lead['phone'] ?: 'Lead sem nome'),
                        'detail' => ($lead['pipeline_stage'] ?: 'Sem etapa') . ' · nota ' . ((string)($lead['lead_score'] ?? 0)) . '/10',
                        'href' => app_url('studio_lead', ['id' => (int)$lead['id']]),
                    ];
                }, $staleLeadsRows),
            ];
        }
        $highScoreUnscheduled = $pdo->query(
            "SELECT l.id, l.name, l.phone, l.status, l.pipeline_stage, l.lead_score, l.estimated_value
             FROM leads l
             LEFT JOIN appointments a ON a.lead_id = l.id AND a.status NOT IN ('cancelado')
             WHERE COALESCE(l.lead_score, 0) >= 7
               AND a.id IS NULL
               AND l.status NOT IN ('perdido', 'fechado')
             ORDER BY COALESCE(l.lead_score, 0) DESC, COALESCE(l.updated_at, l.created_at) DESC
             LIMIT 8"
        )->fetchAll() ?: [];
        if ($highScoreUnscheduled) {
            $alerts[] = [
                'title' => 'Leads com score alto ainda não agendados',
                'count' => count($highScoreUnscheduled),
                'tone' => 'ok',
                'items' => array_map(static function (array $lead): array {
                    return [
                        'label' => ($lead['name'] ?: $lead['phone'] ?: 'Lead sem nome'),
                        'detail' => 'Score ' . ((string)($lead['lead_score'] ?? 0)) . '/10 · ' . format_money($lead['estimated_value'] ?? 0),
                        'href' => app_url('studio_lead', ['id' => (int)$lead['id']]),
                    ];
                }, $highScoreUnscheduled),
            ];
        }
        $preScheduledNoSignal = $pdo->query(
            "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.value, a.deposit_value,
                    COALESCE(c.name, a.title) AS customer_name, ta.name AS artist_name
             FROM appointments a
             LEFT JOIN customers c ON c.id = a.customer_id
             LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
             WHERE a.status = 'pre_agendado'
               AND COALESCE(a.deposit_value, 0) = 0
             ORDER BY a.appointment_date ASC, a.start_time ASC
             LIMIT 8"
        )->fetchAll() ?: [];
        if ($preScheduledNoSignal) {
            $alerts[] = [
                'title' => 'Pré-agendamentos sem sinal',
                'count' => count($preScheduledNoSignal),
                'tone' => 'warn',
                'items' => array_map(static function (array $appointment): array {
                    $href = app_url('studio_agenda', ['date' => (string)$appointment['appointment_date'], 'appointment_id' => (int)$appointment['id']]) . '#appointment-form';
                    return [
                        'label' => ($appointment['customer_name'] ?: 'Agendamento sem nome'),
'detail' => format_date_pt((string)$appointment['appointment_date']) . ' às ' . substr((string)$appointment['start_time'], 0, 5) . ' · ' . format_money(appointment_display_amount($appointment['value'] ?? 0)),
                        'href' => $href,
                    ];
                }, $preScheduledNoSignal),
            ];
        }
        $todayAppointments = $pdo->query(
            "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.status, a.value, a.deposit_value, a.customer_id, a.lead_id,
                    COALESCE(c.name, a.title) AS customer_name, ta.name AS artist_name,
                    c.allergies AS customer_allergies, c.medications AS customer_medications, c.health_conditions AS customer_health_conditions, c.skin_conditions AS customer_skin_conditions,
                    c.keloid_history AS customer_keloid_history, c.anticoagulants AS customer_anticoagulants, c.diabetes AS customer_diabetes, c.healing_issues AS customer_healing_issues, c.pregnant_or_breastfeeding AS customer_pregnant_or_breastfeeding
             FROM appointments a
             LEFT JOIN customers c ON c.id = a.customer_id
             LEFT JOIN leads l ON l.id = a.lead_id
             LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
             WHERE a.appointment_date = '" . $today->format('Y-m-d') . "'
               AND a.status NOT IN ('cancelado')
             ORDER BY a.start_time ASC
             LIMIT 12"
        )->fetchAll() ?: [];
        if ($todayAppointments) {
            $alerts[] = [
                'title' => 'Agendamentos de hoje',
                'count' => count($todayAppointments),
                'tone' => 'neutral',
                'items' => array_map(static function (array $appointment): array {
                    $href = app_url('studio_agenda', ['date' => (string)$appointment['appointment_date'], 'appointment_id' => (int)$appointment['id']]) . '#appointment-form';
                    return [
                        'label' => ($appointment['customer_name'] ?: 'Atendimento'),
                        'detail' => format_date_pt((string)$appointment['appointment_date']) . ' · ' . substr((string)$appointment['start_time'], 0, 5) . ' · ' . (string)($appointment['status'] ?? '-') . ' · ' . format_money(appointment_display_amount($appointment['value'] ?? 0)) . ' · sinal ' . format_money(appointment_display_amount($appointment['deposit_value'] ?? 0)),
                        'href' => $href,
                    ];
                }, $todayAppointments),
            ];
        }
        $todayHealthAlerts = array_values(array_filter($todayAppointments, static fn(array $appointment): bool => (bool)studio_appointment_health_alerts_from_row($appointment)));
        if ($todayHealthAlerts) {
            $alerts[] = [
                'title' => 'Alertas de saúde de hoje',
                'count' => count($todayHealthAlerts),
                'tone' => 'warn',
                'items' => array_map(static function (array $appointment): array {
                    $href = app_url('studio_agenda', ['date' => (string)$appointment['appointment_date'], 'appointment_id' => (int)$appointment['id']]) . '#appointment-form';
                    $healthAlerts = studio_appointment_health_alerts_from_row($appointment);
                    $labels = array_map(static fn(array $alert): string => (string)($alert['label'] ?? ''), $healthAlerts);
                    return [
                        'label' => ($appointment['customer_name'] ?: 'Atendimento'),
                        'detail' => format_date_pt((string)$appointment['appointment_date']) . ' · ' . substr((string)$appointment['start_time'], 0, 5) . (empty($labels) ? '' : ' · ' . implode(' · ', array_slice($labels, 0, 3))),
                        'href' => $href,
                    ];
                }, $todayHealthAlerts),
            ];
        }
        $confirmationAutomation = studio_schedule_appointment_confirmations($studio);
        if (!empty($confirmationAutomation['canceled'])) {
            $alerts[] = [
                'title' => 'Confirmações vencidas canceladas',
                'count' => (int)$confirmationAutomation['canceled'],
                'tone' => 'danger',
                'items' => array_map(static function (array $event): array {
                    return [
                        'label' => 'Agendamento cancelado por falta de confirmação',
                        'detail' => 'A janela de confirmação expirou sem resposta do cliente.',
                        'href' => app_url('studio_agenda'),
                    ];
                }, array_slice($confirmationAutomation['events'] ?? [], 0, 4)),
            ];
        }
        $monthExpenses = (float)($pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN '" . $monthStart->format('Y-m-d') . "' AND '" . $monthEnd->format('Y-m-d') . "'")->fetchColumn() ?: 0);

        $reports = studio_report_data($studio);
        $financeSummary = studio_finance_summary($studio);
        $whatsappSummary = plan_allows('whatsapp')
            ? studio_whatsapp_summary($studio)
            : ['total' => 0, 'bot' => 0, 'human' => 0, 'analyzed' => 0, 'needs_human' => 0, 'avg_score' => 0];
        $leadPulse = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status NOT IN ('perdido', 'fechado') THEN 1 ELSE 0 END) AS open_total,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_new,
                SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN 1 ELSE 0 END) AS month_new,
                SUM(CASE WHEN status NOT IN ('perdido', 'fechado') AND COALESCE(lead_score, 0) >= 7 THEN 1 ELSE 0 END) AS hot_open,
                COALESCE(SUM(CASE WHEN status NOT IN ('perdido', 'fechado') THEN estimated_value ELSE 0 END), 0) AS open_value
             FROM leads"
        )->fetch() ?: [];
        $appointmentPulse = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN appointment_date >= CURDATE() AND status NOT IN ('cancelado', 'perdido') THEN 1 ELSE 0 END) AS future_total,
                SUM(CASE WHEN appointment_date = CURDATE() AND status NOT IN ('cancelado', 'perdido') THEN 1 ELSE 0 END) AS today_total,
                SUM(CASE WHEN DATE_FORMAT(appointment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND status NOT IN ('cancelado', 'perdido') THEN 1 ELSE 0 END) AS month_total,
                COALESCE(SUM(CASE WHEN appointment_date >= CURDATE() AND status NOT IN ('cancelado', 'perdido') THEN value ELSE 0 END), 0) AS future_value,
                COALESCE(SUM(CASE WHEN appointment_date >= CURDATE() AND status NOT IN ('cancelado', 'perdido') THEN deposit_value ELSE 0 END), 0) AS future_deposits,
                SUM(CASE WHEN COALESCE(ai_review_required, 0) = 1 THEN 1 ELSE 0 END) AS import_review_total
             FROM appointments"
        )->fetch() ?: [];
        $customerPulse = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_new,
                SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN 1 ELSE 0 END) AS month_new
             FROM customers"
        )->fetch() ?: [];
        $upcomingSummaryItems = $pdo->query(
            "SELECT a.id, a.appointment_date, a.start_time, a.status, a.value, a.deposit_value,
                    COALESCE(c.name, a.title) AS customer_name, COALESCE(ta.name, 'Sem tatuador') AS artist_name
             FROM appointments a
             LEFT JOIN customers c ON c.id = a.customer_id
             LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
             WHERE a.appointment_date >= CURDATE()
               AND a.status NOT IN ('cancelado', 'perdido')
             ORDER BY a.appointment_date ASC, a.start_time ASC
             LIMIT 6"
        )->fetchAll() ?: [];
        $hotLeadSummaryItems = $pdo->query(
            "SELECT id, name, phone, pipeline_stage, lead_score, estimated_value, updated_at
             FROM leads
             WHERE status NOT IN ('perdido', 'fechado')
             ORDER BY COALESCE(lead_score, 0) DESC, COALESCE(estimated_value, 0) DESC, COALESCE(updated_at, created_at) DESC
             LIMIT 6"
        )->fetchAll() ?: [];
        $whatsappAttentionItems = plan_allows('whatsapp')
            ? ($pdo->query(
                "SELECT id, COALESCE(name, phone) AS name, phone, attendance_mode, needs_human, last_message_preview, last_message_at
                 FROM whatsapp_conversations
                 WHERE needs_human = 1 OR attendance_mode = 'human'
                 ORDER BY COALESCE(last_message_at, updated_at, created_at) DESC
                 LIMIT 6"
            )->fetchAll() ?: [])
            : [];
        $googleIntegration = [];
        try {
            if (function_exists('google_calendar_integration')) {
                $googleIntegration = google_calendar_integration($studio);
            }
        } catch (Throwable) {
            $googleIntegration = [];
        }
        $metaSummary = ['ok' => false];
        try {
            if (function_exists('studio_meta_ads_insights_summary')) {
                $metaSummary = studio_meta_ads_insights_summary($studio, 30);
            }
        } catch (Throwable $e) {
            $metaSummary = ['ok' => false, 'error' => $e->getMessage()];
        }
        $pivotSource = (string)($_GET['pivot_source'] ?? 'leads');
        $pivotSource = in_array($pivotSource, ['leads', 'appointments', 'expenses'], true) ? $pivotSource : 'leads';
        $pivotDataSets = [
            'leads' => [
                'label' => 'Leads',
                'subtitle' => 'Funil, origem, etapa e nota',
                'data' => $pdo->query(
                    "SELECT
                        COALESCE(name, 'Sem nome') AS nome,
                        COALESCE(phone, '') AS telefone,
                        COALESCE(status, 'Sem status') AS status,
                        COALESCE(source, 'Sem origem') AS origem,
                        COALESCE(pipeline_stage, 'Sem etapa') AS etapa,
                        COALESCE(lead_score, 0) AS nota,
                        COALESCE(estimated_value, 0) AS valor_estimado,
                        COALESCE(interest, '') AS interesse,
                        DATE(COALESCE(created_at, updated_at)) AS data_criacao,
                        DATE_FORMAT(COALESCE(created_at, updated_at), '%Y-%m') AS mes,
                        1 AS total
                     FROM leads
                     ORDER BY COALESCE(created_at, updated_at) DESC
                     LIMIT 2000"
                )->fetchAll() ?: [],
                'report' => [
                    'dataSource' => ['dataSourceType' => 'json'],
                    'slice' => [
                        'rows' => [['uniqueName' => 'status']],
                        'columns' => [['uniqueName' => 'Measures'], ['uniqueName' => 'origem']],
                        'measures' => [['uniqueName' => 'total', 'aggregation' => 'sum', 'format' => 'int']],
                    ],
                ],
            ],
            'appointments' => [
                'label' => 'Agenda',
                'subtitle' => 'Status, tatuador, valor e sinal',
                'data' => $pdo->query(
                    "SELECT
                        a.id,
                        COALESCE(c.name, a.title, 'Sem nome') AS cliente,
                        COALESCE(a.status, 'Sem status') AS status,
                        COALESCE(ta.name, 'Sem tatuador') AS tatuador,
                        COALESCE(a.title, '') AS titulo,
                        COALESCE(a.appointment_date, CURDATE()) AS data_agendamento,
                        DATE_FORMAT(a.appointment_date, '%Y-%m') AS mes,
                        COALESCE(a.start_time, '') AS horario,
                        COALESCE(a.end_time, '') AS horario_fim,
                        COALESCE(a.value, 0) AS valor,
                        COALESCE(a.deposit_value, 0) AS sinal,
                        1 AS total
                     FROM appointments a
                     LEFT JOIN customers c ON c.id = a.customer_id
                     LEFT JOIN tattoo_artists ta ON ta.id = a.artist_id
                     ORDER BY a.appointment_date DESC, a.start_time DESC
                     LIMIT 2000"
                )->fetchAll() ?: [],
                'report' => [
                    'dataSource' => ['dataSourceType' => 'json'],
                    'slice' => [
                        'rows' => [['uniqueName' => 'status']],
                        'columns' => [['uniqueName' => 'Measures'], ['uniqueName' => 'tatuador']],
                        'measures' => [['uniqueName' => 'total', 'aggregation' => 'sum', 'format' => 'int'], ['uniqueName' => 'valor', 'aggregation' => 'sum', 'format' => 'currency']],
                    ],
                ],
            ],
            'expenses' => [
                'label' => 'Despesas',
                'subtitle' => 'Categorias, meio, data e valor',
                'data' => $pdo->query(
                    "SELECT
                        COALESCE(category, 'Sem categoria') AS categoria,
                        COALESCE(payment_method, 'Sem pagamento') AS meio,
                        COALESCE(description, '') AS descricao,
                        COALESCE(notes, '') AS notas,
                        COALESCE(expense_date, CURDATE()) AS data_despesa,
                        DATE_FORMAT(expense_date, '%Y-%m') AS mes,
                        COALESCE(amount, 0) AS valor,
                        1 AS total
                     FROM expenses
                     ORDER BY expense_date DESC
                     LIMIT 2000"
                )->fetchAll() ?: [],
                'report' => [
                    'dataSource' => ['dataSourceType' => 'json'],
                    'slice' => [
                        'rows' => [['uniqueName' => 'categoria']],
                        'columns' => [['uniqueName' => 'Measures'], ['uniqueName' => 'meio']],
                        'measures' => [['uniqueName' => 'total', 'aggregation' => 'sum', 'format' => 'int'], ['uniqueName' => 'valor', 'aggregation' => 'sum', 'format' => 'currency']],
                    ],
                ],
            ],
        ];
        $alertsMarkup = '';
        if ($alerts) {
            ob_start();
            echo '<div class="alert-grid">';
            foreach ($alerts as $alert) {
                echo '<div class="alert-card">';
                echo '<div class="actions" style="justify-content:space-between;align-items:flex-start"><div><strong>' . h($alert['title']) . '</strong><p class="muted" style="margin:4px 0 0">' . h((string)$alert['count']) . ' itens</p></div><span class="badge ' . h((string)($alert['tone'] ?? 'neutral')) . '">Atenção</span></div>';
                echo '<div class="stack-list" style="margin-top:10px">';
                foreach (array_slice($alert['items'], 0, 4) as $item) {
                    echo '<a class="activity-card" href="' . h($item['href']) . '"><strong>' . h($item['label']) . '</strong><span>' . h($item['detail']) . '</span></a>';
                }
                echo '</div></div>';
            }
            echo '<div class="alert-card"><strong>Despesas do mês</strong><p class="metric" style="margin:8px 0 0">' . h(format_money($monthExpenses)) . '</p><p class="muted">Total de despesas registradas no período atual.</p><a class="btn tiny secondary" href="' . h(app_url('studio_finance')) . '">Abrir financeiro</a></div>';
            echo '</div>';
            $alertsMarkup = ob_get_clean();
        }

        ob_start();
        $balance = (float)($financeSummary['balance_period'] ?? 0);
        $openLeads = (int)($leadPulse['open_total'] ?? 0);
        $hotOpenLeads = (int)($leadPulse['hot_open'] ?? 0);
        $futureAppointments = (int)($appointmentPulse['future_total'] ?? 0);
        $aiReviewImports = (int)($appointmentPulse['import_review_total'] ?? 0);
        $needsHuman = (int)($whatsappSummary['needs_human'] ?? 0);
        $executiveNotes = [];
        $executiveNotes[] = $balance >= 0
            ? 'Financeiro do mês está positivo em ' . format_money($balance) . '.'
            : 'Financeiro do mês está negativo em ' . format_money(abs($balance)) . '; vale revisar despesas e agenda prevista.';
        if ($hotOpenLeads > 0) {
            $executiveNotes[] = $hotOpenLeads . ' lead(s) quente(s) ainda aberto(s), com potencial de virar agenda.';
        }
        if ($needsHuman > 0) {
            $executiveNotes[] = $needsHuman . ' conversa(s) do WhatsApp pedindo intervenção humana.';
        }
        if ($aiReviewImports > 0) {
            $executiveNotes[] = $aiReviewImports . ' agendamento(s) importado(s) precisam revisão da leitura automática.';
        }
        if ($futureAppointments <= 0) {
            $executiveNotes[] = 'Não há atendimentos futuros ativos cadastrados.';
        }

        echo '<div class="stack-list" style="gap:16px">';
        echo '<section class="panel soft" style="margin:0;background:linear-gradient(135deg,#f7fbf8 0%,#eef7f1 100%)"><div class="actions" style="justify-content:space-between;align-items:flex-start;gap:12px"><div><span class="section-eyebrow">Visão executiva</span><h3 style="margin:4px 0 6px">Resumo geral do estúdio</h3><p class="muted" style="margin:0">Consolida financeiro, agenda, leads, WhatsApp, IA, Google Agenda e Meta Ads quando disponível.</p></div><span class="badge ok">' . h(format_date_pt(date('Y-m-d'))) . '</span></div>';
        echo '<div class="mini-metrics" style="margin-top:14px">';
        echo '<span><strong>' . h(format_money((float)($financeSummary['appointments_period'] ?? 0))) . '</strong><small>Receita prevista no mês</small></span>';
        echo '<span><strong>' . h(format_money((float)($financeSummary['deposits_period'] ?? 0))) . '</strong><small>Sinais recebidos</small></span>';
        echo '<span><strong>' . h(format_money((float)($financeSummary['expenses_period'] ?? 0))) . '</strong><small>Despesas do mês</small></span>';
        echo '<span><strong>' . h(format_money($balance)) . '</strong><small>Saldo simples</small></span>';
        echo '<span><strong>' . h((string)$openLeads) . '</strong><small>Leads abertos</small></span>';
        echo '<span><strong>' . h((string)$futureAppointments) . '</strong><small>Agendamentos futuros</small></span>';
        echo '</div>';
        echo '<div class="alert-grid" style="margin-top:14px">';
        foreach ($executiveNotes as $note) {
            $tone = str_contains(studio_calendar_remove_accents(studio_calendar_lower_text($note)), 'negativo') || str_contains(studio_calendar_remove_accents(studio_calendar_lower_text($note)), 'revis') || str_contains(studio_calendar_remove_accents(studio_calendar_lower_text($note)), 'humana') ? 'warn' : 'ok';
            echo '<div class="alert-card" style="padding:14px"><span class="badge ' . h($tone) . '">' . h($tone === 'ok' ? 'ok' : 'atenção') . '</span><p style="margin:8px 0 0"><strong>' . h($note) . '</strong></p></div>';
        }
        echo '</div></section>';

        echo '<section class="grid cols-4 dashboard-kpis">';
        $summaryKpis = [
            ['Financeiro', format_money((float)($financeSummary['balance_period'] ?? 0)), 'Saldo simples do mês', 'studio_finance'],
            ['Ticket médio', format_money((float)($financeSummary['average_ticket'] ?? 0)), (string)($financeSummary['appointments_count'] ?? 0) . ' atendimento(s) no período', 'studio_finance'],
            ['Clientes', (string)($customerPulse['total'] ?? 0), '+' . (string)($customerPulse['month_new'] ?? 0) . ' este mês', 'studio_people'],
            ['WhatsApp', (string)($whatsappSummary['total'] ?? 0), (string)$needsHuman . ' pedindo humano', 'studio_whatsapp'],
            ['IA ativa', (string)($whatsappSummary['bot'] ?? 0), (string)($whatsappSummary['analyzed'] ?? 0) . ' conversas analisadas', 'studio_whatsapp'],
            ['Agenda hoje', (string)($appointmentPulse['today_total'] ?? 0), (string)($appointmentPulse['month_total'] ?? 0) . ' no mês', 'studio_agenda'],
            ['Leads quentes', (string)$hotOpenLeads, format_money((float)($leadPulse['open_value'] ?? 0)) . ' em aberto', 'studio_leads'],
            ['Meta Ads', !empty($metaSummary['ok']) ? format_money((float)($metaSummary['spend'] ?? 0)) : '—', !empty($metaSummary['ok']) ? ((string)($metaSummary['clicks'] ?? 0) . ' cliques / 30d') : 'sem leitura agora', 'studio_meta_ads'],
        ];
        foreach ($summaryKpis as [$label, $value, $hint, $pageTarget]) {
            echo '<a class="panel dashboard-stat" href="' . h(app_url((string)$pageTarget)) . '"><strong class="metric">' . h((string)$value) . '</strong><p class="muted" style="margin:0">' . h((string)$label) . '</p><span class="muted">' . h((string)$hint) . '</span></a>';
        }
        echo '</section>';

        echo '<div class="grid cols-2">';
        echo '<section class="panel soft"><div class="actions" style="justify-content:space-between"><h3 style="margin:0">Próximos atendimentos</h3><a class="btn tiny secondary" href="' . h(app_url('studio_agenda')) . '">Agenda</a></div><div class="stack-list" style="margin-top:10px">';
        if (!$upcomingSummaryItems) {
            echo '<p class="muted">Nenhum atendimento futuro ativo encontrado.</p>';
        }
        foreach ($upcomingSummaryItems as $appointment) {
            $href = app_url('studio_agenda', ['date' => (string)$appointment['appointment_date'], 'appointment_id' => (int)$appointment['id']]) . '#appointment-form';
            echo '<a class="activity-card" href="' . h($href) . '"><strong>' . h((string)($appointment['customer_name'] ?: 'Atendimento')) . '</strong><span>' . h(format_date_pt((string)$appointment['appointment_date']) . ' às ' . substr((string)$appointment['start_time'], 0, 5) . ' · ' . (string)$appointment['artist_name'] . ' · ' . format_money(appointment_display_amount($appointment['value'] ?? 0))) . '</span></a>';
        }
        echo '</div></section>';

        echo '<section class="panel soft"><div class="actions" style="justify-content:space-between"><h3 style="margin:0">Leads para priorizar</h3><a class="btn tiny secondary" href="' . h(app_url('studio_leads')) . '">Funil</a></div><div class="stack-list" style="margin-top:10px">';
        if (!$hotLeadSummaryItems) {
            echo '<p class="muted">Nenhum lead aberto encontrado.</p>';
        }
        foreach ($hotLeadSummaryItems as $lead) {
            echo '<a class="activity-card" href="' . h(app_url('studio_lead', ['id' => (int)$lead['id']])) . '"><strong>' . h((string)($lead['name'] ?: $lead['phone'] ?: 'Lead sem nome')) . '</strong><span>' . h((string)($lead['pipeline_stage'] ?: 'Sem etapa') . ' · score ' . (string)($lead['lead_score'] ?? 0) . '/10 · ' . format_money($lead['estimated_value'] ?? 0)) . '</span></a>';
        }
        echo '</div></section>';

        echo '<section class="panel soft"><div class="actions" style="justify-content:space-between"><h3 style="margin:0">WhatsApp e IA</h3><a class="btn tiny secondary" href="' . h(app_url('studio_whatsapp')) . '">Conversas</a></div>';
        echo '<div class="mini-metrics" style="margin-top:10px"><span><strong>' . h((string)($whatsappSummary['bot'] ?? 0)) . '</strong><small>em IA</small></span><span><strong>' . h((string)($whatsappSummary['human'] ?? 0)) . '</strong><small>humano</small></span><span><strong>' . h((string)($whatsappSummary['avg_score'] ?? 0)) . '</strong><small>score médio</small></span></div>';
        echo '<div class="stack-list" style="margin-top:10px">';
        if (!$whatsappAttentionItems) {
            echo '<p class="muted">Nenhuma conversa crítica no momento.</p>';
        }
        foreach ($whatsappAttentionItems as $conversation) {
            $conversationName = (string)($conversation['name'] ?: $conversation['phone'] ?: 'Conversa');
            echo '<a class="activity-card" href="' . h(app_url('studio_whatsapp_mobile', ['id' => (int)$conversation['id']])) . '"><strong>' . h($conversationName) . '</strong><span>' . h(((int)($conversation['needs_human'] ?? 0) === 1 ? 'Pediu humano' : (string)$conversation['attendance_mode']) . ' · ' . ((string)($conversation['last_message_preview'] ?? '') ?: 'sem prévia')) . '</span></a>';
        }
        echo '</div></section>';

        echo '<section class="panel soft"><div class="actions" style="justify-content:space-between"><h3 style="margin:0">Integrações e operação</h3><a class="btn tiny secondary" href="' . h(app_url('studio_settings')) . '">Configurações</a></div><div class="stack-list" style="margin-top:10px">';
        $googleStatus = !empty($googleIntegration['enabled']) ? 'Sincronização ativa' : 'Sincronização pausada ou não configurada';
        $googleDetail = trim((string)($googleIntegration['last_sync_message'] ?? '')) ?: 'Sem mensagem recente.';
        echo '<div class="activity-card"><strong>Google Agenda</strong><span>' . h($googleStatus . ' · ' . $googleDetail) . '</span></div>';
        if ($aiReviewImports > 0) {
            echo '<a class="activity-card" href="' . h(app_url('studio_agenda')) . '"><strong>Importações para revisar</strong><span>' . h((string)$aiReviewImports . ' agendamento(s) com leitura automática incerta.') . '</span></a>';
        }
        if (!empty($metaSummary['ok'])) {
            echo '<a class="activity-card" href="' . h(app_url('studio_meta_ads')) . '"><strong>Meta Ads 30 dias</strong><span>' . h(format_money((float)($metaSummary['spend'] ?? 0)) . ' investidos · ' . (string)($metaSummary['reach'] ?? 0) . ' alcance · CTR ' . number_format((float)($metaSummary['ctr'] ?? 0), 2, ',', '.') . '%') . '</span></a>';
        } else {
            echo '<div class="activity-card"><strong>Meta Ads</strong><span>' . h((string)($metaSummary['error'] ?? 'Sem leitura configurada agora.')) . '</span></div>';
        }
        if (!empty($financeSummary['by_category'][0])) {
            $topExpense = $financeSummary['by_category'][0];
            echo '<a class="activity-card" href="' . h(app_url('studio_finance')) . '"><strong>Maior categoria de despesa</strong><span>' . h((string)($topExpense['category'] ?? 'Sem categoria') . ' · ' . format_money($topExpense['total'] ?? 0)) . '</span></a>';
        }
        echo '</div></section>';
        echo '</div></div>';
        $summaryMarkup = ob_get_clean();

        ob_start();
        render_report_table($reports['leads_by_status'], 'status');
        $leadStatusTable = ob_get_clean();

        ob_start();
        render_report_table($reports['leads_by_source'], 'source');
        $leadSourceTable = ob_get_clean();

        ob_start();
        render_report_table($reports['appointments_by_status'], 'status');
        $appointmentsStatusTable = ob_get_clean();

        ob_start();
        render_report_table($reports['appointments_by_month'], 'month');
        $appointmentsMonthTable = ob_get_clean();

        ob_start();
        render_report_table($reports['expenses_by_category'], 'category');
        $expensesCategoryTable = ob_get_clean();

        echo '<section class="panel shadow-sm border-0 reports-launcher" style="margin-bottom:16px"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><span class="section-eyebrow">Central de leitura</span><h2>Relatórios</h2><p class="muted">Abra cada leitura em overlay sem sair da página.</p></div><span class="badge">Painel</span></div>';
        echo '<div class="settings-overview-grid row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 reports-launcher-grid">';
        echo '<button type="button" class="panel dashboard-stat h-100 text-start" data-reports-overlay="alerts"><i class="fa-solid fa-triangle-exclamation"></i><p class="metric">Alertas operacionais</p><p class="muted">Sinais rápidos do que pede ação</p><span class="muted">Abrir em overlay</span></button>';
        echo '<button type="button" class="panel dashboard-stat h-100 text-start" data-reports-overlay="summary"><i class="fa-solid fa-layer-group"></i><p class="metric">Resumo gerencial</p><p class="muted">Visão geral da operação</p><span class="muted">Abrir em overlay</span></button>';
        echo '<button type="button" class="panel dashboard-stat h-100 text-start" data-reports-overlay="lead_status"><i class="fa-solid fa-filter-circle-dollar"></i><p class="metric">Leads por status</p><p class="muted">Distribuição do funil</p><span class="muted">Abrir em overlay</span></button>';
        echo '<button type="button" class="panel dashboard-stat h-100 text-start" data-reports-overlay="lead_source"><i class="fa-solid fa-route"></i><p class="metric">Leads por origem</p><p class="muted">Canais de entrada</p><span class="muted">Abrir em overlay</span></button>';
        echo '<button type="button" class="panel dashboard-stat h-100 text-start" data-reports-overlay="appointments_status"><i class="fa-solid fa-calendar-check"></i><p class="metric">Agenda por status</p><p class="muted">Leitura do calendário</p><span class="muted">Abrir em overlay</span></button>';
        echo '<button type="button" class="panel dashboard-stat h-100 text-start" data-reports-overlay="appointments_month"><i class="fa-solid fa-calendar-days"></i><p class="metric">Agenda por mês</p><p class="muted">Comparativo mensal</p><span class="muted">Abrir em overlay</span></button>';
        echo '<button type="button" class="panel dashboard-stat h-100 text-start" data-reports-overlay="expenses_category"><i class="fa-solid fa-receipt"></i><p class="metric">Despesas por categoria</p><p class="muted">Centro de custo</p><span class="muted">Abrir em overlay</span></button>';
        if (plan_allows('advanced_reports')) {
            echo '<button type="button" class="panel dashboard-stat h-100 text-start" data-reports-overlay="pivot"><i class="fa-solid fa-table-cells-large"></i><p class="metric">Tabela dinâmica</p><p class="muted">Cruzamentos avançados</p><span class="muted">Abrir em overlay</span></button>';
        }
        echo '</div></section>';
        echo '<div id="reportsOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1180px)"><div class="crm-panel-header"><div><h3 id="reportsOverlayTitle" class="crm-panel-title">Relatórios</h3><p id="reportsOverlaySummary" class="muted" style="margin:4px 0 0"></p></div><button type="button" id="closeReportsOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="reportsOverlayBody" class="p-4"></div></div></div>';
        echo '<div id="reportsSourceAlerts" hidden><div class="stack-list">' . $alertsMarkup . '</div></div>';
        echo '<div id="reportsSourceSummary" hidden><div class="panel" style="margin:0"><div class="actions" style="justify-content:space-between"><h2>Resumo gerencial</h2><span class="badge">Painel de leitura</span></div>' . $summaryMarkup . '</div></div>';
        echo '<div id="reportsSourceLeadStatus" hidden><div class="panel" style="margin:0"><h2>Leads por status</h2>' . $leadStatusTable . '</div></div>';
        echo '<div id="reportsSourceLeadSource" hidden><div class="panel" style="margin:0"><h2>Leads por origem</h2>' . $leadSourceTable . '</div></div>';
        echo '<div id="reportsSourceAppointmentsStatus" hidden><div class="panel" style="margin:0"><h2>Agenda por status</h2>' . $appointmentsStatusTable . '</div></div>';
        echo '<div id="reportsSourceAppointmentsMonth" hidden><div class="panel" style="margin:0"><h2>Agenda por mês</h2>' . $appointmentsMonthTable . '</div></div>';
        echo '<div id="reportsSourceExpensesCategory" hidden><div class="panel" style="margin:0"><h2>Despesas por categoria</h2>' . $expensesCategoryTable . '</div></div>';
        if (plan_allows('advanced_reports')) {
            echo '<div id="reportsSourcePivot" hidden><section class="panel" style="margin:0"><div class="actions" style="justify-content:space-between;align-items:flex-start;gap:12px"><div><h2>Tabela dinâmica</h2><p class="muted">Monte cruzamentos por arrastar campos entre linhas, colunas, medidas e filtros.</p></div><span class="badge">Análise</span></div><div class="wdr-shell"><div class="wdr-source-bar">';
            foreach ($pivotDataSets as $key => $def) {
                echo '<button type="button" class="wdr-source-button' . ($key === $pivotSource ? ' active' : '') . '" data-pivot-source="' . h($key) . '"><strong>' . h($def['label']) . '</strong><span>' . h($def['subtitle']) . '</span></button>';
            }
            echo '</div><div id="reportsPivot" class="wdr-frame"></div></div><div class="reports-pivot-note muted">Use a barra superior e a lista de campos para reorganizar a leitura. Se quiser, troque a base entre Leads, Agenda e Despesas.</div></section></div>';
        }
        echo '<link rel="stylesheet" href="' . h(app_asset_url('assets/vendor/webdatarocks/webdatarocks.min.css')) . '?v=' . h(app_build_version()) . '">';
        echo '<script src="' . h(app_asset_url('assets/vendor/webdatarocks/webdatarocks.toolbar.min.js')) . '?v=' . h(app_build_version()) . '"></script>';
        echo '<script src="' . h(app_asset_url('assets/vendor/webdatarocks/webdatarocks.js')) . '?v=' . h(app_build_version()) . '"></script>';
        echo '<script>window.reportsPivotData = ' . json_encode($pivotDataSets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; window.reportsPivotSource = ' . json_encode($pivotSource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<script>(function(){const modal=document.getElementById("reportsOverlay");const body=document.getElementById("reportsOverlayBody");const title=document.getElementById("reportsOverlayTitle");const summary=document.getElementById("reportsOverlaySummary");const closeBtn=document.getElementById("closeReportsOverlay");const sourceMap={alerts:{title:"Alertas operacionais",summary:"Sinais rápidos do que precisa de ação agora",source:"reportsSourceAlerts"},summary:{title:"Resumo gerencial",summary:"Visão geral da operação",source:"reportsSourceSummary"},lead_status:{title:"Leads por status",summary:"Distribuição do funil",source:"reportsSourceLeadStatus"},lead_source:{title:"Leads por origem",summary:"Canais de entrada",source:"reportsSourceLeadSource"},appointments_status:{title:"Agenda por status",summary:"Leitura do calendário",source:"reportsSourceAppointmentsStatus"},appointments_month:{title:"Agenda por mês",summary:"Comparativo mensal",source:"reportsSourceAppointmentsMonth"},expenses_category:{title:"Despesas por categoria",summary:"Centro de custo",source:"reportsSourceExpensesCategory"},pivot:{title:"Tabela dinâmica",summary:"Cruzamentos avançados",source:"reportsSourcePivot"}};let pivotInstance=null;function disposePivot(){try{if(pivotInstance&&typeof pivotInstance.dispose==="function")pivotInstance.dispose();}catch(error){}pivotInstance=null;}function openOverlay(key){const config=sourceMap[key]||sourceMap.summary;const source=document.getElementById(config.source);if(!modal||!body||!title||!summary||!source)return;disposePivot();title.textContent=config.title;summary.textContent=config.summary;body.innerHTML=source.innerHTML;modal.classList.remove("hidden");if(key==="pivot"&&window.WebDataRocks&&window.reportsPivotData){const mount=body.querySelector("#reportsPivot");if(!mount)return;mount.id="reportsPivotOverlay";const sourceButtons=Array.from(body.querySelectorAll("[data-pivot-source]"));const renderPivot=(sourceKey)=>{const cfg=window.reportsPivotData[sourceKey]||window.reportsPivotData.leads;if(!cfg)return;sourceButtons.forEach((button)=>button.classList.toggle("active",button.getAttribute("data-pivot-source")===sourceKey));const report={dataSource:{dataSourceType:"json",data:cfg.data},slice:cfg.report.slice};if(pivotInstance){pivotInstance.setReport(report);return;}pivotInstance=new WebDataRocks({container:"#reportsPivotOverlay",height:640,width:"100%",toolbar:true,report:report});};sourceButtons.forEach((button)=>button.addEventListener("click",()=>renderPivot(button.getAttribute("data-pivot-source")||"leads")));setTimeout(()=>{try{renderPivot(window.reportsPivotSource||"leads");}catch(error){mount.innerHTML="<p class=\"muted\">Não foi possível montar a tabela dinâmica agora.</p>";}},50);}}document.querySelectorAll("[data-reports-overlay]").forEach((button)=>{button.addEventListener("click",()=>openOverlay(button.getAttribute("data-reports-overlay")||"summary"));});if(closeBtn)closeBtn.addEventListener("click",()=>{disposePivot();modal.classList.add("hidden");});if(modal)modal.addEventListener("click",(event)=>{if(event.target===modal){disposePivot();modal.classList.add("hidden");}});document.addEventListener("keydown",(event)=>{if(event.key==="Escape"&&modal){disposePivot();modal.classList.add("hidden");}});})();</script>';
    }, $flash);
    exit;
}

if ($page === 'studio_data_assistant') {
    $studio = require_studio();
    render_studio_shell('Assistente IA de dados', 'Perguntas internas sobre CRM, agenda, WhatsApp e financeiro.', 'assistant', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $result = $_SESSION['studio_data_assistant_result'] ?? null;
        unset($_SESSION['studio_data_assistant_result']);
        echo '<style>
            .data-assistant-wrap{max-width:980px;margin:0 auto}
            .data-ask-panel{padding:26px;border-radius:28px;background:linear-gradient(145deg,#ffffff 0%,#f6faf7 55%,#eef6f1 100%);border:1px solid rgba(13,64,46,.12);box-shadow:0 22px 70px rgba(15,44,34,.10)}
            .data-ask-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:18px}
            .data-ask-title h2{font-size:28px;margin:0 0 6px}
            .data-ask-title p{margin:0;color:#61736b}
            .data-safe-pill{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(15,118,87,.18);background:#f3fbf6;color:#0f6b4f;border-radius:999px;padding:9px 13px;font-weight:800;font-size:12px;white-space:nowrap}
            .data-safe-pill:before{content:"";width:8px;height:8px;border-radius:999px;background:#16a34a;box-shadow:0 0 0 5px rgba(22,163,74,.10)}
            .data-prompt-box{display:grid;gap:12px}
            .data-prompt-box textarea{width:100%;min-height:154px;border-radius:22px;border:1px solid rgba(15,44,34,.18);padding:18px 20px;font-size:18px;line-height:1.45;resize:vertical;background:rgba(255,255,255,.88);box-shadow:inset 0 1px 0 rgba(255,255,255,.8)}
            .data-prompt-actions{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
            .data-prompt-hint{color:#61736b;font-size:13px}
            .data-progress{margin-top:18px;border-radius:22px;border:1px solid rgba(15,44,34,.12);background:rgba(255,255,255,.74);padding:16px;display:none}
            .data-progress.active{display:block}
            .data-progress-top{display:flex;justify-content:space-between;gap:14px;font-size:13px;font-weight:800;color:#10251d}
            .data-progress-track{height:12px;background:#e4eee8;border-radius:999px;overflow:hidden;margin:12px 0 10px}
            .data-progress-bar{height:100%;width:0;background:linear-gradient(90deg,#0f7a5b,#33d39a);border-radius:999px;transition:width .35s ease}
            .data-progress-sub{display:flex;justify-content:space-between;gap:14px;color:#61736b;font-size:12px;flex-wrap:wrap}
            .data-answer-panel{margin-top:18px;padding:24px;border-radius:28px;background:#ffffff;border:1px solid rgba(15,44,34,.10);box-shadow:0 16px 50px rgba(15,44,34,.08)}
            .data-answer-panel.empty{border-style:dashed;background:rgba(255,255,255,.55)}
            .data-answer-title{display:flex;justify-content:space-between;gap:14px;align-items:center;margin-bottom:14px}
            .data-answer-title h2{margin:0}
            .data-answer-text{white-space:pre-wrap;font-size:17px;line-height:1.65;color:#10251d}
            .data-tech{margin-top:18px;border-top:1px solid rgba(15,44,34,.09);padding-top:12px}
            .data-tech summary{cursor:pointer;color:#61736b;font-weight:800}
            .data-tech pre{white-space:pre-wrap;background:#10251d;color:#dff8ea;border-radius:18px;padding:14px;overflow:auto;font-size:12px}
            @media(max-width:720px){.data-ask-panel,.data-answer-panel{border-radius:20px;padding:18px}.data-ask-head{display:block}.data-safe-pill{margin-top:12px}.data-prompt-box textarea{font-size:16px}}
        </style>';
        echo '<div class="data-assistant-wrap">';
        echo '<section class="data-ask-panel">';
        echo '<div class="data-ask-head"><div class="data-ask-title"><h2>Assistente de dados</h2><p>Pergunte qualquer coisa sobre clientes, agenda, WhatsApp, leads e financeiro. Ele consulta somente leitura e responde direto.</p></div><span class="data-safe-pill">Somente leitura</span></div>';
        echo '<form id="dataAssistantForm" class="data-prompt-box" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="ask_studio_data_assistant">';
        echo '<textarea id="dataAssistantQuestion" name="question" required placeholder="Ex: Quantos clientes houve mês passado? Quanto está previsto para ganhar semana que vem? Qual cliente mais gastou até hoje?">' . h($result['question'] ?? '') . '</textarea>';
        echo '<div class="data-prompt-actions"><span class="data-prompt-hint">Quanto mais específica for a pergunta, mais precisa fica a resposta.</span><button id="dataAssistantSubmit" class="btn" type="submit">Enviar pergunta</button></div>';
        echo '</form>';
        echo '<div id="dataAssistantProgress" class="data-progress" aria-live="polite">';
        echo '<div class="data-progress-top"><span id="dataAssistantPhase">Preparando consulta...</span><span id="dataAssistantPercent">0%</span></div>';
        echo '<div class="data-progress-track"><div id="dataAssistantBar" class="data-progress-bar"></div></div>';
        echo '<div class="data-progress-sub"><span id="dataAssistantElapsed">Tempo corrido: 0s</span><span id="dataAssistantEta">Estimativa: calculando...</span></div>';
        echo '</div>';
        echo '</section>';
        $initialAnswer = is_array($result) ? trim((string)($result['answer'] ?? '')) : '';
        echo '<section id="dataAssistantAnswerPanel" class="data-answer-panel' . ($initialAnswer === '' ? ' empty' : '') . '">';
        echo '<div class="data-answer-title"><h2>Resposta</h2><span id="dataAssistantGenerated" class="badge">' . ($initialAnswer !== '' ? h((string)($result['generated_at'] ?? 'gerado agora')) : 'Aguardando pergunta') . '</span></div>';
        echo '<div id="dataAssistantAnswer" class="data-answer-text">' . ($initialAnswer !== '' ? h($initialAnswer) : 'Faça uma pergunta acima para consultar os dados do estúdio.') . '</div>';
        echo '<details id="dataAssistantTech" class="data-tech"' . (!empty($result['queries']) ? '' : ' style="display:none"') . '><summary>Detalhes técnicos das consultas</summary><pre id="dataAssistantQueries">' . (!empty($result['queries']) ? h(json_encode($result['queries'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') : '') . '</pre></details>';
        echo '</section></div>';
        echo '<script>(function(){const form=document.getElementById("dataAssistantForm");const button=document.getElementById("dataAssistantSubmit");const progress=document.getElementById("dataAssistantProgress");const bar=document.getElementById("dataAssistantBar");const percent=document.getElementById("dataAssistantPercent");const phase=document.getElementById("dataAssistantPhase");const elapsed=document.getElementById("dataAssistantElapsed");const eta=document.getElementById("dataAssistantEta");const answerPanel=document.getElementById("dataAssistantAnswerPanel");const answer=document.getElementById("dataAssistantAnswer");const generated=document.getElementById("dataAssistantGenerated");const tech=document.getElementById("dataAssistantTech");const queries=document.getElementById("dataAssistantQueries");if(!form||!button||!progress||!bar||!percent||!phase||!elapsed||!eta||!answerPanel||!answer||!generated)return;const phases=["Entendendo sua pergunta...","Mapeando tabelas do sistema...","Montando consultas somente leitura...","Validando segurança das consultas...","Buscando os dados no banco...","Organizando a resposta..."];let timer=null;let started=0;let current=0;function setProgress(value,text){current=Math.max(current,Math.min(value,96));bar.style.width=current+"%";percent.textContent=Math.round(current)+"%";if(text)phase.textContent=text;}function startLoading(){started=Date.now();current=0;progress.classList.add("active");answerPanel.classList.remove("empty");button.disabled=true;button.textContent="Consultando...";generated.textContent="Consultando...";answer.textContent="Estou entendendo a pergunta e preparando uma consulta segura, somente leitura. Pode levar alguns segundos.";if(tech)tech.style.display="none";setProgress(6,phases[0]);timer=setInterval(()=>{const seconds=Math.max(1,Math.round((Date.now()-started)/1000));const phaseIndex=Math.min(phases.length-1,Math.floor(seconds/3));const target=Math.min(92,8+seconds*7);setProgress(target,phases[phaseIndex]);elapsed.textContent="Tempo corrido: "+seconds+"s";const remaining=Math.max(3,Math.round((100-current)/9));eta.textContent=current<90?"Estimativa: cerca de "+remaining+"s":"Estimativa: finalizando...";answer.textContent=phases[phaseIndex]+"\\nTempo corrido: "+seconds+"s. Ainda estou trabalhando na resposta.";},600);}function stopLoading(){if(timer)clearInterval(timer);timer=null;setProgress(100,"Pronto.");elapsed.textContent="Tempo corrido: "+Math.max(1,Math.round((Date.now()-started)/1000))+"s";eta.textContent="Concluído";setTimeout(()=>progress.classList.remove("active"),850);button.disabled=false;button.textContent="Enviar pergunta";}function showError(message){answerPanel.classList.remove("empty");generated.textContent="Erro";answer.textContent=message||"Não consegui responder agora.";if(tech)tech.style.display="none";}form.addEventListener("submit",async(event)=>{event.preventDefault();const data=new FormData(form);if(!String(data.get("question")||"").trim())return;startLoading();try{const response=await fetch(window.location.href,{method:"POST",body:data,headers:{"Accept":"application/json","X-Requested-With":"XMLHttpRequest"}});const json=await response.json();if(!response.ok||!json.ok)throw new Error(json.error||"Falha ao consultar os dados.");answer.textContent=json.answer||"Não encontrei uma resposta para essa pergunta.";generated.textContent=json.generated_at?("Gerado em "+json.generated_at):"Gerado agora";if(Array.isArray(json.queries)&&json.queries.length&&tech&&queries){tech.style.display="";queries.textContent=JSON.stringify(json.queries,null,2);}else if(tech&&queries){tech.style.display="none";queries.textContent="";}}catch(error){showError(error.message||"Não consegui consultar agora.");}finally{stopLoading();}});})();</script>';
    }, $flash);
    exit;
}

if ($page === 'studio_tattoo_image_status') {
    $studio = require_studio();
    header('Content-Type: application/json; charset=utf-8');
    $job = $_SESSION['studio_tattoo_image_job'] ?? null;
    if (!is_array($job)) {
        echo json_encode(['status' => 'idle'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $poll = studio_poll_tattoo_reference_generation($studio, $job);
    if (($poll['status'] ?? '') === 'completed' && is_array($poll['result'] ?? null)) {
        $_SESSION['studio_tattoo_image_result'] = $poll['result'];
        if (function_exists('studio_tattoo_image_history_add')) {
            studio_tattoo_image_history_add($poll['result']);
        }
        unset($_SESSION['studio_tattoo_image_job']);
    } elseif (($poll['status'] ?? '') === 'failed') {
        unset($_SESSION['studio_tattoo_image_job']);
        flash_set('error', (string)($poll['error'] ?? 'A IA local não conseguiu concluir a imagem.'));
    }
    echo json_encode($poll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($page === 'studio_tattoo_images') {
    $studio = require_studio();
    render_studio_shell('Criar imagem', 'Geração livre: descreva a imagem e deixe a IA obedecer ao pedido.', 'tattoo_images', function () use ($studio) {
        $localAi = studio_local_image_ai_status();
        $job = $_SESSION['studio_tattoo_image_job'] ?? null;
        $isGenerating = is_array($job) && !empty($job['id']);
        $result = $_SESSION['studio_tattoo_image_result'] ?? null;
        $history = function_exists('studio_tattoo_image_history') ? studio_tattoo_image_history() : [];
        $formState = is_array($_SESSION['studio_tattoo_image_form'] ?? null) ? $_SESSION['studio_tattoo_image_form'] : [];
        unset($_SESSION['studio_tattoo_image_form']);
        $prompt = trim((string)($job['prompt'] ?? $result['prompt'] ?? $formState['prompt'] ?? ''));
        $selectedStyle = (string)($job['style'] ?? $result['style'] ?? $formState['style'] ?? 'realistic');
        $selectedFormat = (string)($job['format'] ?? $result['format'] ?? $formState['format'] ?? 'vertical');
        $selectedMode = (string)($formState['mode'] ?? $job['mode'] ?? 'final');
        $selectedComposition = (string)($formState['composition'] ?? $job['composition'] ?? 'reference');
        $referenceNotes = trim((string)($job['reference_notes'] ?? $result['reference_notes'] ?? $formState['reference_notes'] ?? ''));
        $negativePrompt = trim((string)($job['negative_prompt'] ?? $result['negative_prompt'] ?? $formState['negative_prompt'] ?? ''));
        $upscale = !empty($job['upscale'] ?? $formState['upscale'] ?? false);
        $upscaleFactor = max(2, min(4, (int)($job['upscale_factor'] ?? $formState['upscale_factor'] ?? 4)));
        $styleLabels = [
            'realistic' => 'Realista',
            'stencil' => 'Stencil blueprint',
            'blackwork' => 'Blackwork',
            'chicano' => 'Chicano',
            'fineline' => 'Fine line',
            'oldschool' => 'Old school',
            'reference' => 'Referência limpa',
        ];
        $formatLabels = ['vertical' => 'Vertical', 'square' => 'Quadrado', 'wide' => 'Horizontal'];
        $modeLabels = ['fast' => 'Visualização rápida', 'final' => 'Final qualidade'];
        $compositionLabels = ['reference' => 'Referência limpa', 'mockup' => 'Aplicada na pele', 'flash' => 'Flash / cartela'];
        $presetPrompts = [
            'Leao frontal, cabeça completa, coroa de rei, olhar intenso, alto contraste',
            'Coruja em blackwork, asas abertas, composição vertical, sombreado limpo',
            'Mula sem cabeça, atmosfera sombria, silhueta fiel, sem virar retrato humano',
            'Stencil limpo de rosa e punhal, linhas fortes, pronto para decalque',
            'Retrato feminino tatuado, rosto bem centralizado, luz suave, composição equilibrada',
            'Caveira chicano com flores, sombras suaves, preto e cinza, leitura de tattoo',
        ];
        $selectedImagePath = is_array($result) ? trim((string)($result['upscaled_image_path'] ?? $result['image_path'] ?? '')) : '';
        $selectedDownloadName = is_array($result) ? trim((string)($result['upscaled_file_name'] ?? $result['file_name'] ?? 'referencia-tattoo.jpg')) : 'referencia-tattoo.jpg';
        $selectedPromptText = is_array($result) ? trim((string)($result['translated_prompt'] ?? $result['prompt'] ?? '')) : '';
        $localAiModel = (string)($localAi['model'] ?? 'RealVisXL 5.0 local');
        $localAiState = !empty($localAi['ok']) ? 'Operação online' : 'A IA local está iniciando';
        echo '<div class="tattoo-image-page">';
        echo '<section class="panel tattoo-image-hero">';
        echo '<div class="tattoo-image-hero-copy"><span class="section-eyebrow">IA de imagem</span><h2>Criador livre, simples e direto</h2><p>Escreva exatamente o que quer ver. As opções ficam como apoio: qualidade, estilo, formato e acabamento, sem prender o pedido ao tema tatuagem.</p><div class="tattoo-image-mini-badges"><span class="badge">' . h($localAiModel) . '</span><span class="badge">' . h($modeLabels[$selectedMode] ?? $selectedMode) . '</span><span class="badge">' . h($selectedStyle !== '' ? ($styleLabels[$selectedStyle] ?? $selectedStyle) : 'Realista') . '</span><span class="badge">' . h($formatLabels[$selectedFormat] ?? $selectedFormat) . '</span><span class="badge">' . h($compositionLabels[$selectedComposition] ?? $selectedComposition) . '</span></div></div>';
        echo '<div class="tattoo-image-hero-meta"><div class="tattoo-image-status-chip"><span class="tattoo-image-status-dot ' . (!empty($localAi['ok']) ? 'is-on' : 'is-off') . '"></span><div><strong>' . h($localAiState) . '</strong><small>' . h(!empty($localAi['ok']) ? 'Geração local sem API externa' : 'Aguarde o modelo subir') . '</small></div></div><div class="tattoo-image-hero-actions"><a class="btn secondary" href="#tattoo-history">Ver histórico</a><a class="btn secondary" href="#tattoo-guides">Guias rápidos</a></div></div>';
        echo '</section>';
        echo '<div class="tattoo-image-layout">';
        echo '<section class="panel tattoo-image-panel tattoo-image-compose" id="tattoo-compose">';
        echo '<div class="tattoo-image-panel-head"><div><h3>Gerar imagem</h3><p class="muted mb-0">Prompt primeiro. Ajustes finos ficam logo abaixo, sem poluir a criação.</p></div><span class="badge ' . (!empty($localAi['ok']) ? 'ok' : 'warn') . '">' . h($isGenerating ? 'Gerando' : 'Pronto') . '</span></div>';
        echo '<div class="tattoo-image-chip-row" aria-label="Sugestões rápidas">';
        foreach ($presetPrompts as $preset) {
            echo '<button type="button" class="tattoo-image-chip" data-tattoo-preset="' . h($preset) . '">' . h(mb_substr($preset, 0, 40)) . '</button>';
        }
        echo '</div>';
        if (empty($localAi['ok'])) {
            echo '<div class="tattoo-image-key-note"><strong>A IA local está iniciando.</strong><span>O modelo RealVisXL roda nesta máquina. Quando ficar online, o gerador usa seu briefing sem depender de API externa.</span></div>';
        } else {
            echo '<div class="tattoo-image-local-status"><i></i><span>' . h($localAiModel) . ' · rodando localmente</span></div>';
        }
        echo '<form method="post" id="tattooImageForm" class="tattoo-image-form" data-tattoo-image-form>';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="generate_tattoo_reference">';
        echo '<div class="field"><label>Ideia da arte</label><textarea data-tattoo-image-input name="prompt" rows="6" maxlength="4000" required ' . ($isGenerating ? 'disabled' : '') . ' placeholder="Ex: mula sem cabeça em cena sombria, silhueta clara, cabeça ausente, clima dramático, pronta para tattoo...">' . h($prompt) . '</textarea><small class="tattoo-image-muted-note">Descreva o sujeito principal, o que precisa aparecer e o que não pode mudar.</small></div>';
        echo '<div class="tattoo-image-control-grid">';
        echo '<div class="tattoo-image-control-card"><label>Modo</label><select data-tattoo-image-mode name="mode"><option value="fast"' . ($selectedMode === 'fast' ? ' selected' : '') . '>Visualização rápida</option><option value="final"' . ($selectedMode === 'final' ? ' selected' : '') . '>Final qualidade</option></select><small>Rápido para testar, final para fechar a arte.</small></div>';
        echo '<div class="tattoo-image-control-card"><label>Estilo</label><select data-tattoo-image-style name="style"><option value="realistic"' . ($selectedStyle === 'realistic' ? ' selected' : '') . '>Realista</option><option value="stencil"' . ($selectedStyle === 'stencil' ? ' selected' : '') . '>Stencil blueprint</option><option value="blackwork"' . ($selectedStyle === 'blackwork' ? ' selected' : '') . '>Blackwork</option><option value="chicano"' . ($selectedStyle === 'chicano' ? ' selected' : '') . '>Chicano</option><option value="fineline"' . ($selectedStyle === 'fineline' ? ' selected' : '') . '>Fine line</option><option value="oldschool"' . ($selectedStyle === 'oldschool' ? ' selected' : '') . '>Old school</option><option value="reference"' . ($selectedStyle === 'reference' ? ' selected' : '') . '>Referência limpa</option></select><small>Define o acabamento visual principal.</small></div>';
        echo '<div class="tattoo-image-control-card"><label>Formato</label><select data-tattoo-image-format name="format"><option value="vertical"' . ($selectedFormat === 'vertical' ? ' selected' : '') . '>Vertical</option><option value="square"' . ($selectedFormat === 'square' ? ' selected' : '') . '>Quadrado</option><option value="wide"' . ($selectedFormat === 'wide' ? ' selected' : '') . '>Horizontal</option></select><small>Ajuda a composição a encaixar melhor.</small></div>';
        echo '<div class="tattoo-image-control-card"><label>Upscale</label><label class="tattoo-image-check"><input data-tattoo-image-upscale type="checkbox" name="upscale" value="1"' . ($upscale ? ' checked' : '') . ($isGenerating ? ' disabled' : '') . '> gerar 4x</label><input type="hidden" name="upscale_factor" value="' . h((string)$upscaleFactor) . '"><small>Amplia a imagem final para uso mais limpo.</small></div>';
        echo '</div>';
        echo '<details class="tattoo-image-detail" open><summary><strong>Direção artística</strong><span class="muted">abrir para refinar a leitura da tatuagem</span></summary><div class="tattoo-image-detail-body">';
        echo '<div class="field"><label>Referência / direção artística</label><input data-tattoo-image-reference type="text" name="reference_notes" maxlength="600" value="' . h($referenceNotes) . '" placeholder="Ex.: sombra lateral suave, leitura premium, rosto centralizado, fundo escuro..."></div>';
        echo '<div class="field"><label>Aplicação</label><select data-tattoo-image-composition name="composition"><option value="reference"' . ($selectedComposition === 'reference' ? ' selected' : '') . '>Referência limpa</option><option value="mockup"' . ($selectedComposition === 'mockup' ? ' selected' : '') . '>Aplicada na pele</option><option value="flash"' . ($selectedComposition === 'flash' ? ' selected' : '') . '>Flash / cartela</option></select><small class="tattoo-image-muted-note">Para personagem, símbolo ou estudo, deixe em referência limpa. Use pele só se isso for intencional.</small></div>';
        echo '<div class="field"><label>Evitar</label><input data-tattoo-image-negative type="text" name="negative_prompt" maxlength="600" value="' . h($negativePrompt) . '" placeholder="Ex.: texto, moldura, fundo poluído, mãos extras, distorção..."></div>';
        echo '<p class="tattoo-image-muted-note mb-0">Use este bloco para travar o clima, a composição e os erros que você não quer ver.</p>';
        echo '</div></details>';
        echo '<div class="tattoo-image-prompt-preview" data-tattoo-image-preview><strong>Prévia do briefing</strong><div class="tattoo-image-preview-lines"><span><i class="fa-solid fa-bolt"></i> <b>Ideia:</b> <em>' . h($prompt !== '' ? $prompt : 'escreva sua ideia principal') . '</em></span><span><i class="fa-solid fa-compass-drafting"></i> <b>Estilo:</b> <em>' . h($styleLabels[$selectedStyle] ?? $selectedStyle) . '</em></span><span><i class="fa-solid fa-crop-simple"></i> <b>Formato:</b> <em>' . h($formatLabels[$selectedFormat] ?? $selectedFormat) . '</em></span><span><i class="fa-solid fa-person-dress-burst"></i> <b>Aplicação:</b> <em>' . h($compositionLabels[$selectedComposition] ?? $selectedComposition) . '</em></span><span><i class="fa-solid fa-shield-halved"></i> <b>Evitar:</b> <em>' . h($negativePrompt !== '' ? $negativePrompt : 'nada definido ainda') . '</em></span></div></div>';
        echo '<div class="tattoo-image-compose-footer"><span class="tattoo-image-submit-note" id="tattooImageWait" ' . ($isGenerating ? '' : 'hidden') . '>' . ($isGenerating ? 'A IA local está criando sua imagem... pode levar alguns minutos.' : 'Preparando a IA local...') . '</span><button class="btn tattoo-image-generate" type="submit" ' . (empty($localAi['ok']) || $isGenerating ? 'disabled' : '') . '>' . ($isGenerating ? 'Criando...' : 'Gerar imagem') . '</button></div>';
        echo '</form>';
        echo '</section>';
        echo '<section class="panel tattoo-image-panel tattoo-image-preview">';
        if (is_array($result) && $selectedImagePath !== '') {
            $imageUrl = app_asset_url($selectedImagePath);
            echo '<div class="tattoo-image-preview-figure"><img class="result-img" src="' . h($imageUrl) . '" alt="Imagem realista gerada para referência de tatuagem"></div>';
            echo '<div class="tattoo-image-preview-meta"><div class="tattoo-image-preview-title"><h3>Resultado mais recente</h3><div class="tattoo-image-mini-badges"><span class="badge">' . h($modeLabels[(string)($result['mode'] ?? $selectedMode)] ?? (string)($result['mode'] ?? $selectedMode)) . '</span><span class="badge">' . h($styleLabels[(string)($result['style'] ?? $selectedStyle)] ?? (string)($result['style'] ?? $selectedStyle)) . '</span><span class="badge">' . h($formatLabels[(string)($result['format'] ?? $selectedFormat)] ?? (string)($result['format'] ?? $selectedFormat)) . '</span><span class="badge">' . h($compositionLabels[(string)($result['composition'] ?? $selectedComposition)] ?? (string)($result['composition'] ?? $selectedComposition)) . '</span></div></div><p class="muted mb-0">' . h($selectedPromptText !== '' ? $selectedPromptText : (string)($result['prompt'] ?? '')) . '</p><div class="tattoo-image-result-actions"><a class="btn secondary" href="' . h($imageUrl) . '" download="' . h($selectedDownloadName) . '">Baixar imagem</a>' . (!empty($result['upscaled_image_path']) ? '<span class="badge ok">4x pronto</span>' : '') . '</div></div>';
        } else {
            echo '<div class="tattoo-image-empty"><div class="tattoo-image-orb"></div><div><strong>Sua imagem aparece aqui.</strong><p class="muted mb-0">Quando a geração terminar, esta área mostra o resultado e o caminho para baixar.</p></div></div>';
            echo '<div class="tattoo-image-status-stack">';
            echo '<div class="tattoo-image-status-row"><span class="badge ' . (!empty($localAi['ok']) ? 'ok' : 'warn') . '">' . h($localAiState) . '</span><span class="badge">' . h($localAiModel) . '</span></div>';
            echo '<p class="muted mb-0">Quanto mais clara for a ideia principal, melhor a IA obedece. Se o resultado vier torto, ajuste o briefing acima e tente de novo.</p>';
            echo '</div>';
        }
        echo '</section>';
        echo '</div>';
        echo '<details class="panel tattoo-image-detail" id="tattoo-guides">';
        echo '<summary><strong>Como a IA interpreta o pedido</strong><span class="muted">o que o modelo vai tentar segurar</span></summary><div class="tattoo-image-detail-body"><div class="grid cols-2"><div class="panel soft"><h3 style="margin-top:0">Mantém</h3><ul class="mb-0"><li>Sujeito principal, pose e acessórios informados.</li><li>Estilo, formato e direção artística escolhidos.</li><li>Direção de composição para não virar uma cena genérica.</li></ul></div><div class="panel soft"><h3 style="margin-top:0">Evita</h3><ul class="mb-0"><li>Texto, moldura, logotipo e interface.</li><li>Troca de sujeito por retrato aleatório.</li><li>Elementos que não foram pedidos no briefing.</li></ul></div></div></div></details>';
        echo '<details class="panel tattoo-image-detail" id="tattoo-history">';
        echo '<summary><strong>Histórico recente</strong><span class="badge">' . h((string)count($history)) . ' itens</span></summary><div class="tattoo-image-detail-body">';
        if (!$history) {
            echo '<p class="muted mb-0">Sem imagens geradas ainda.</p>';
        } else {
            echo '<div class="tattoo-image-history-grid">';
            foreach ($history as $item) {
                $thumb = !empty($item['upscaled_image_path'] ?? '') ? app_asset_url((string)$item['upscaled_image_path']) : (!empty($item['image_path'] ?? '') ? app_asset_url((string)$item['image_path']) : '');
                if ($thumb === '') {
                    continue;
                }
                echo '<article class="tattoo-image-history-card"><img src="' . h($thumb) . '" alt="Histórico de imagem gerada"><strong>' . h((string)($item['style'] ?? 'Referência')) . '</strong><p class="muted mb-2">' . h(mb_strimwidth((string)($item['prompt'] ?? ''), 0, 120, '...')) . '</p><div class="tattoo-image-history-actions"><a class="btn tiny secondary" href="' . h($thumb) . '" download="' . h((string)($item['upscaled_file_name'] ?? $item['file_name'] ?? 'referencia-tattoo.jpg')) . '">Baixar</a></div></article>';
            }
            echo '</div>';
        }
        echo '</div></details>';
        echo '<script>(function(){const form=document.getElementById("tattooImageForm");const wait=document.getElementById("tattooImageWait");const preview=document.querySelector("[data-tattoo-image-preview]");const promptInput=form?.querySelector("[data-tattoo-image-input]");const styleSelect=form?.querySelector("[data-tattoo-image-style]");const formatSelect=form?.querySelector("[data-tattoo-image-format]");const modeSelect=form?.querySelector("[data-tattoo-image-mode]");const compositionSelect=form?.querySelector("[data-tattoo-image-composition]");const referenceInput=form?.querySelector("[data-tattoo-image-reference]");const negativeInput=form?.querySelector("[data-tattoo-image-negative]");const upscaleInput=form?.querySelector("[data-tattoo-image-upscale]");const labels={style:' . json_encode($styleLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ',format:' . json_encode($formatLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ',mode:' . json_encode($modeLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ',composition:' . json_encode($compositionLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '};const escapeHtml=(value)=>String(value||"").replace(/[&<>"\']/g,(char)=>{if(char==="&")return"&amp;";if(char==="<")return"&lt;";if(char===">")return"&gt;";if(char===String.fromCharCode(34))return"&quot;";if(char===String.fromCharCode(39))return"&#39;";return char;});const syncPreview=()=>{if(!preview||!promptInput)return;const pieces=[`<span><i class="fa-solid fa-bolt"></i> <b>Ideia:</b> <em>${escapeHtml(promptInput.value.trim()||"escreva sua ideia principal")}</em></span>`,`<span><i class="fa-solid fa-compass-drafting"></i> <b>Estilo:</b> <em>${escapeHtml(labels.style[styleSelect?.value||"realistic"]||styleSelect?.value||"Realista")}</em></span>`,`<span><i class="fa-solid fa-crop-simple"></i> <b>Formato:</b> <em>${escapeHtml(labels.format[formatSelect?.value||"vertical"]||formatSelect?.value||"Vertical")}</em></span>`,`<span><i class="fa-solid fa-clock"></i> <b>Modo:</b> <em>${escapeHtml(labels.mode[modeSelect?.value||"final"]||modeSelect?.value||"Final qualidade")}</em></span>`,`<span><i class="fa-solid fa-person-dress-burst"></i> <b>Aplicação:</b> <em>${escapeHtml(labels.composition[compositionSelect?.value||"reference"]||compositionSelect?.value||"Referência limpa")}</em></span>`,`<span><i class="fa-solid fa-shield-halved"></i> <b>Evitar:</b> <em>${escapeHtml(negativeInput?.value.trim()||"nada definido ainda")}</em></span>`];preview.innerHTML=`<strong>Prévia do briefing</strong><div class="tattoo-image-preview-lines">${pieces.join("")}</div>`;};const presetButtons=document.querySelectorAll("[data-tattoo-preset]");presetButtons.forEach((button)=>{button.addEventListener("click",()=>{if(!promptInput)return;promptInput.value=button.dataset.tattooPreset||"";promptInput.dispatchEvent(new Event("input",{bubbles:true}));promptInput.focus();promptInput.setSelectionRange(promptInput.value.length,promptInput.value.length);syncPreview();});});["input","change"].forEach((eventName)=>{promptInput?.addEventListener(eventName,syncPreview);styleSelect?.addEventListener(eventName,syncPreview);formatSelect?.addEventListener(eventName,syncPreview);modeSelect?.addEventListener(eventName,syncPreview);compositionSelect?.addEventListener(eventName,syncPreview);referenceInput?.addEventListener(eventName,syncPreview);negativeInput?.addEventListener(eventName,syncPreview);upscaleInput?.addEventListener(eventName,syncPreview);});syncPreview();if(form){form.addEventListener("submit",()=>{const button=form.querySelector("button[type=submit]");if(button){button.disabled=true;button.textContent="Preparando...";}if(wait){wait.hidden=false;wait.textContent="Preparando a IA local...";}});}';
        if ($isGenerating) {
            echo 'const statusUrl=' . json_encode(app_url('studio_tattoo_image_status'), JSON_UNESCAPED_SLASHES) . ';let failures=0;const poll=async()=>{try{const response=await fetch(statusUrl,{credentials:"same-origin",cache:"no-store"});const data=await response.json();failures=0;if(data.status==="completed"||data.status==="failed"||data.status==="idle"){location.reload();return;}if(wait){wait.textContent=data.status==="queued"?"Sua imagem está na fila da IA local...":"A IA local está criando sua imagem... pode levar alguns minutos.";}}catch(error){failures++;if(wait&&failures>2)wait.textContent="A geração continua localmente. Tentando reconectar...";}setTimeout(poll,5000);};setTimeout(poll,1500);';
        }
        echo '})();</script>';
    }, $flash);
    exit;
}

if ($page === 'studio_whatsapp_tags') {
    $studio = require_studio();
    render_studio_shell('Tags das conversas', 'Organize o atendimento com tags oficiais do estúdio e tags pessoais de cada atendente.', 'settings', function () use ($studio) {
        $tags = studio_list_whatsapp_tags($studio);
        $user = current_studio_user();
        $userId = (int)($user['id'] ?? 0);
        $isAdmin = studio_current_user_is_admin();
        echo '<div class="grid cols-2">';
        echo '<section class="panel"><h2>Nova tag</h2><p class="muted">Tags oficiais aparecem para toda a equipe. Tags pessoais aparecem apenas para quem criou.</p>';
        echo '<form class="form" method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_whatsapp_tag">';
        echo '<div class="field"><label>Nome</label><input name="name" maxlength="80" required placeholder="Ex.: Orçamento enviado"></div>';
        echo '<div class="field"><label>Cor</label><input type="color" name="color" value="#6b7280"></div>';
        if ($isAdmin) {
            echo '<div class="field"><label>Visibilidade</label><select name="scope"><option value="studio">Oficial do estúdio — toda a equipe</option><option value="personal">Pessoal — somente eu</option></select></div>';
        } else {
            echo '<input type="hidden" name="scope" value="personal">';
        }
        echo '<button class="btn" type="submit">Criar tag</button></form></section>';
        echo '<section class="panel"><h2>Tags disponíveis</h2><div class="stack-list">';
        if (!$tags) {
            echo '<p class="muted">Nenhuma tag criada ainda.</p>';
        }
        foreach ($tags as $tag) {
            $isOwner = (int)($tag['studio_user_id'] ?? 0) === $userId;
            echo '<div class="drilldown-card compact"><div class="actions" style="justify-content:space-between;align-items:center"><span class="badge" style="border-color:' . h((string)$tag['color']) . ';color:' . h((string)$tag['color']) . '">' . h((string)$tag['name']) . '</span><span class="muted">' . ((string)$tag['scope'] === 'studio' ? 'Oficial' : 'Pessoal') . '</span></div>';
            if ($isAdmin || $isOwner) {
                echo '<form method="post" style="margin-top:8px">' . csrf_field() . '<input type="hidden" name="action" value="delete_whatsapp_tag"><input type="hidden" name="id" value="' . h((string)$tag['id']) . '"><button class="btn tiny danger" type="submit">Excluir</button></form>';
            }
            echo '</div>';
        }
        echo '</div></section></div><div class="actions"><a class="btn secondary" href="' . h(app_url('studio_settings')) . '">Voltar às configurações</a></div>';
    }, $flash);
    exit;
}

if ($page === 'studio_artists') {
    $studio = require_studio();
    if (!studio_current_user_is_admin()) {
        flash_set('error', 'Apenas administradores podem acessar a gestão de tatuadores.');
        redirect_to('studio_home');
    }
    render_studio_shell('Tatuadores', 'Equipe artística, agenda, clientes atendidos e desempenho por tatuador.', 'artists', function () use ($studio) {
        $pdo = studio_db($studio);
        $artists = studio_list_artists($studio, false);
        $artistIds = array_values(array_filter(array_map(static fn(array $artist): int => (int)($artist['id'] ?? 0), $artists), static fn(int $id): bool => $id > 0));
        $editingArtistId = (int)($_GET['artist_id'] ?? 0);
        $editingArtist = null;
        foreach ($artists as $artist) {
            if ((int)($artist['id'] ?? 0) === $editingArtistId) {
                $editingArtist = $artist;
                break;
            }
        }

        $statsByArtist = [];
        if ($artistIds) {
            $placeholders = implode(',', array_fill(0, count($artistIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT ta.id AS artist_id,
                        COUNT(a.id) AS total_appointments,
                        COUNT(DISTINCT NULLIF(a.customer_id, 0)) AS total_customers,
                        COALESCE(SUM(CASE WHEN LOWER(COALESCE(a.status, '')) NOT IN ('cancelado','cancelada','cancelled') THEN a.value ELSE 0 END), 0) AS total_revenue,
                        COALESCE(SUM(CASE WHEN LOWER(COALESCE(a.status, '')) NOT IN ('cancelado','cancelada','cancelled') THEN a.deposit_value ELSE 0 END), 0) AS total_deposits,
                        SUM(CASE WHEN a.appointment_date >= CURDATE() AND LOWER(COALESCE(a.status, '')) NOT IN ('cancelado','cancelada','cancelled') THEN 1 ELSE 0 END) AS future_appointments,
                        SUM(CASE WHEN DATE_FORMAT(a.appointment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND LOWER(COALESCE(a.status, '')) NOT IN ('cancelado','cancelada','cancelled') THEN 1 ELSE 0 END) AS month_appointments,
                        COALESCE(SUM(CASE WHEN DATE_FORMAT(a.appointment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND LOWER(COALESCE(a.status, '')) NOT IN ('cancelado','cancelada','cancelled') THEN a.value ELSE 0 END), 0) AS month_revenue,
                        SUM(CASE WHEN LOWER(COALESCE(a.status, '')) IN ('finalizado','concluido','concluído') THEN 1 ELSE 0 END) AS finished_appointments,
                        SUM(CASE WHEN LOWER(COALESCE(a.status, '')) IN ('cancelado','cancelada','cancelled') THEN 1 ELSE 0 END) AS cancelled_appointments,
                        MAX(a.appointment_date) AS last_appointment_date
                 FROM tattoo_artists ta
                 LEFT JOIN appointments a ON a.artist_id = ta.id
                 WHERE ta.id IN ($placeholders)
                 GROUP BY ta.id"
            );
            $stmt->execute($artistIds);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $statsByArtist[(int)$row['artist_id']] = $row;
            }
        }

        $recentCustomersByArtist = [];
        $upcomingByArtist = [];
        if ($artistIds) {
            $placeholders = implode(',', array_fill(0, count($artistIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT a.artist_id,
                        COALESCE(c.name, l.name, a.title, 'Cliente sem nome') AS customer_name,
                        COALESCE(c.phone, l.phone, '') AS phone,
                        MAX(a.appointment_date) AS last_date,
                        COUNT(*) AS appointment_count,
                        COALESCE(SUM(a.value), 0) AS total_value
                 FROM appointments a
                 LEFT JOIN customers c ON c.id = a.customer_id
                 LEFT JOIN leads l ON l.id = a.lead_id
                 WHERE a.artist_id IN ($placeholders)
                   AND LOWER(COALESCE(a.status, '')) NOT IN ('cancelado','cancelada','cancelled')
                 GROUP BY a.artist_id, customer_name, phone
                 ORDER BY last_date DESC, appointment_count DESC"
            );
            $stmt->execute($artistIds);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $artistId = (int)($row['artist_id'] ?? 0);
                if (count($recentCustomersByArtist[$artistId] ?? []) >= 6) {
                    continue;
                }
                $recentCustomersByArtist[$artistId][] = $row;
            }

            $stmt = $pdo->prepare(
                "SELECT a.*, COALESCE(c.name, l.name, a.title, 'Cliente sem nome') AS customer_name
                 FROM appointments a
                 LEFT JOIN customers c ON c.id = a.customer_id
                 LEFT JOIN leads l ON l.id = a.lead_id
                 WHERE a.artist_id IN ($placeholders)
                   AND a.appointment_date >= CURDATE()
                   AND LOWER(COALESCE(a.status, '')) NOT IN ('cancelado','cancelada','cancelled')
                 ORDER BY a.appointment_date ASC, a.start_time ASC, a.id ASC
                 LIMIT 160"
            );
            $stmt->execute($artistIds);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $artistId = (int)($row['artist_id'] ?? 0);
                if (count($upcomingByArtist[$artistId] ?? []) >= 5) {
                    continue;
                }
                $upcomingByArtist[$artistId][] = $row;
            }
        }

        $unassigned = $pdo->query(
            "SELECT COUNT(*) AS total, COALESCE(SUM(value), 0) AS revenue
             FROM appointments
             WHERE COALESCE(artist_id, 0) = 0
               AND LOWER(COALESCE(status, '')) NOT IN ('cancelado','cancelada','cancelled')"
        )->fetch() ?: ['total' => 0, 'revenue' => 0];
        $totalActive = count(array_filter($artists, static fn(array $artist): bool => !empty($artist['is_active'])));
        $totalRevenue = array_sum(array_map(static fn(array $row): float => (float)($row['total_revenue'] ?? 0), $statsByArtist));
        $monthRevenue = array_sum(array_map(static fn(array $row): float => (float)($row['month_revenue'] ?? 0), $statsByArtist));
        $futureTotal = array_sum(array_map(static fn(array $row): int => (int)($row['future_appointments'] ?? 0), $statsByArtist));
        $planLimit = plan_limit_for_studio($studio, 'max_tattooers');

        echo '<section class="artists-hero panel">';
        echo '<div><span class="section-eyebrow">Equipe artística</span><h2>Gestão de tatuadores</h2><p class="muted">Cadastro, disponibilidade operacional e leitura rápida de resultado por artista. Apenas administradores e donos do estúdio podem alterar estes dados.</p></div>';
        echo '<div class="artists-hero-actions"><a class="btn" href="' . h(app_url('studio_artists')) . '"><i class="fa-solid fa-plus"></i> Novo tatuador</a><a class="btn secondary" href="' . h(app_url('studio_agenda')) . '"><i class="fa-solid fa-calendar-days"></i> Abrir agenda</a></div>';
        echo '</section>';

        echo '<section class="artists-kpi-grid">';
        echo '<div class="drilldown-kpi"><span>Ativos</span><strong>' . h((string)$totalActive) . '</strong><small>' . h($planLimit > 0 ? 'limite do plano: ' . $planLimit : 'sem limite definido') . '</small></div>';
        echo '<div class="drilldown-kpi"><span>Próximos agendamentos</span><strong>' . h((string)$futureTotal) . '</strong><small>com tatuador definido</small></div>';
        echo '<div class="drilldown-kpi"><span>Receita total vinculada</span><strong>' . h(format_money($totalRevenue)) . '</strong><small>sem cancelados</small></div>';
        echo '<div class="drilldown-kpi"><span>Receita deste mês</span><strong>' . h(format_money($monthRevenue)) . '</strong><small>agenda do mês atual</small></div>';
        echo '</section>';

        echo '<div class="artists-layout">';
        echo '<section class="panel artists-list-panel"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2 style="margin:0">Tatuadores cadastrados</h2><p class="muted" style="margin:6px 0 0">Clique em editar para ajustar dados, cor e status.</p></div><span class="badge">' . h((string)count($artists)) . ' registros</span></div>';
        if (!$artists) {
            echo '<p class="muted">Nenhum tatuador cadastrado ainda.</p>';
        } else {
            echo '<div class="artists-grid">';
            foreach ($artists as $artist) {
                $artistId = (int)($artist['id'] ?? 0);
                $stats = $statsByArtist[$artistId] ?? [];
                $color = trim((string)($artist['color'] ?? '#1f6f78')) ?: '#1f6f78';
                $isActive = !empty($artist['is_active']);
                echo '<article class="artist-card">';
                echo '<div class="artist-card-head"><span class="artist-color" style="background:' . h($color) . '"></span><div><strong>' . h((string)$artist['name']) . '</strong><small>' . h((string)($artist['specialty'] ?: 'Especialidade não informada')) . '</small></div><span class="badge ' . h($isActive ? 'ok' : 'warn') . '">' . h($isActive ? 'ativo' : 'inativo') . '</span></div>';
                echo '<div class="artist-card-metrics">';
                echo '<span><strong>' . h((string)(int)($stats['total_appointments'] ?? 0)) . '</strong><small>agendamentos</small></span>';
                echo '<span><strong>' . h((string)(int)($stats['total_customers'] ?? 0)) . '</strong><small>clientes</small></span>';
                echo '<span><strong>' . h(format_money((float)($stats['total_revenue'] ?? 0))) . '</strong><small>receita</small></span>';
                echo '<span><strong>' . h((string)(int)($stats['future_appointments'] ?? 0)) . '</strong><small>próximos</small></span>';
                echo '</div>';
                echo '<div class="artist-card-split">';
                echo '<div><h3>Clientes recentes</h3>';
                $recentCustomers = $recentCustomersByArtist[$artistId] ?? [];
                if (!$recentCustomers) {
                    echo '<p class="muted">Sem clientes vinculados ainda.</p>';
                } else {
                    echo '<div class="artist-mini-list">';
                    foreach ($recentCustomers as $customer) {
                        echo '<span><strong>' . h((string)$customer['customer_name']) . '</strong><small>' . h((string)$customer['appointment_count']) . ' atendimento(s) · ' . h(format_money((float)$customer['total_value'])) . '</small></span>';
                    }
                    echo '</div>';
                }
                echo '</div><div><h3>Agenda futura</h3>';
                $upcoming = $upcomingByArtist[$artistId] ?? [];
                if (!$upcoming) {
                    echo '<p class="muted">Sem próximos horários.</p>';
                } else {
                    echo '<div class="artist-mini-list">';
                    foreach ($upcoming as $appointment) {
                        echo '<span><strong>' . h(format_date_pt((string)$appointment['appointment_date']) . ' às ' . substr((string)$appointment['start_time'], 0, 5)) . '</strong><small>' . h((string)$appointment['customer_name']) . ' · ' . h((string)$appointment['status']) . '</small></span>';
                    }
                    echo '</div>';
                }
                echo '</div></div>';
                echo '<div class="artist-card-actions"><a class="btn tiny secondary" href="' . h(app_url('studio_artists', ['artist_id' => $artistId])) . '">Editar</a><a class="btn tiny secondary" href="' . h(app_url('studio_agenda', ['artist_id' => $artistId])) . '">Ver agenda</a></div>';
                echo '</article>';
            }
            echo '</div>';
        }
        echo '</section>';

        echo '<aside class="artists-side">';
        echo '<section class="panel"><h2>' . h($editingArtist ? 'Editar tatuador' : 'Novo tatuador') . '</h2><p class="muted">Use uma cor forte para facilitar a leitura no calendário.</p>';
        echo '<form class="form" method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_artist">';
        if ($editingArtist) {
            echo '<input type="hidden" name="id" value="' . h((string)$editingArtist['id']) . '">';
        }
        echo '<div class="field"><label>Nome</label><input name="name" required value="' . h((string)($editingArtist['name'] ?? '')) . '" placeholder="Nome do tatuador"></div>';
        echo '<div class="field"><label>Especialidade</label><input name="specialty" value="' . h((string)($editingArtist['specialty'] ?? '')) . '" placeholder="Ex.: blackwork, realismo, fineline"></div>';
        echo '<div class="field"><label>Cor no calendário</label><input type="color" name="color" value="' . h((string)($editingArtist['color'] ?? '#1f6f78')) . '"></div>';
        $activeChecked = $editingArtist ? !empty($editingArtist['is_active']) : true;
        echo '<label class="checkline"><input type="checkbox" name="is_active" value="1" ' . ($activeChecked ? 'checked' : '') . '> Tatuador ativo para novos agendamentos</label>';
        echo '<button class="btn" type="submit">Salvar tatuador</button>';
        if ($editingArtist) {
            echo '<a class="btn secondary" href="' . h(app_url('studio_artists')) . '">Cancelar edição</a>';
        }
        echo '</form></section>';

        echo '<section class="panel soft"><h2>Sem tatuador definido</h2><p class="muted">Agendamentos sem artista atrapalham estatísticas e podem gerar conflito de agenda.</p>';
        echo '<div class="mini-metrics"><span><strong>' . h((string)(int)($unassigned['total'] ?? 0)) . '</strong><small>agendamentos</small></span><span><strong>' . h(format_money((float)($unassigned['revenue'] ?? 0))) . '</strong><small>valor</small></span></div>';
        echo '<a class="btn secondary" href="' . h(app_url('studio_agenda')) . '">Revisar agenda</a></section>';

        echo '<section class="panel soft"><h2>Permissões sugeridas</h2><div class="permission-map">';
        $permissionRows = [
            ['Somente ADM', 'Tatuadores, acessos, configurações, tokens/API, integrações, exclusões, tags oficiais e treinamento da IA.'],
            ['ADM ou financeiro', 'Financeiro, despesas, relatórios completos, Meta Ads e indicadores de resultado.'],
            ['Equipe operacional', 'WhatsApp, agenda diária, clientes/leads, respostas rápidas pessoais e consulta de informações.'],
            ['Somente sistema/IA', 'Logs técnicos, webhooks, leitura de mídia, automações e eventos internos.'],
        ];
        foreach ($permissionRows as [$label, $copy]) {
            echo '<div><strong>' . h($label) . '</strong><span>' . h($copy) . '</span></div>';
        }
        echo '</div></section>';
        echo '</aside></div>';
    }, $flash);
    exit;
}

if ($page === 'studio_whatsapp_flow') {
    $studio = require_studio();
    $flow = studio_whatsapp_service_flow($studio);
    $flowConfig = is_array($flow['config'] ?? null) ? $flow['config'] : [];
    $flowSteps = array_values((array)($flow['steps'] ?? []));
    render_studio_shell('Roteiro do atendimento', 'Um caminho fixo para coletar os dados e concluir o agendamento sem a IA improvisar etapas.', 'settings', function () use ($studio, $flowConfig, $flowSteps) {
        $clientSteps = array_map(static function (array $step): array {
            return [
                'step_key' => (string)($step['step_key'] ?? ''),
                'title' => (string)($step['title'] ?? ''),
                'step_type' => (string)($step['step_type'] ?? 'question'),
                'field_key' => (string)($step['field_key'] ?? 'custom_response'),
                'answer_type' => (string)($step['answer_type'] ?? 'text'),
                'question_text' => (string)($step['question_text'] ?? ''),
                'help_text' => (string)($step['help_text'] ?? ''),
                'options' => array_values((array)($step['options'] ?? [])),
                'next_step_key' => (string)($step['next_step_key'] ?? ''),
                'branch_map' => is_array($step['branch_map'] ?? null) ? $step['branch_map'] : [],
                'branch_map_json' => json_encode(is_array($step['branch_map'] ?? null) ? $step['branch_map'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_required' => !empty($step['is_required']),
                'is_active' => !empty($step['is_active']),
            ];
        }, $flowSteps);
        echo '<div class="flow-page-head">';
        echo '<div><span class="section-eyebrow">WhatsApp · somente ADM</span><h2>Fluxograma operacional</h2><p>Conecte cada bloco ao próximo passo. Em perguntas com opções, cada resposta pode seguir por um caminho diferente.</p></div>';
        echo '<div class="flow-head-actions"><a class="btn secondary" href="' . h(app_url('studio_settings')) . '"><i class="fa-solid fa-arrow-left"></i> Configurações</a><button class="btn" type="submit" form="whatsappFlowForm"><i class="fa-solid fa-floppy-disk"></i> Salvar e publicar</button></div>';
        echo '</div>';
        echo '<div class="flow-principles">';
        echo '<div><i class="fa-solid fa-lock"></i><span><strong>Ordem rígida</strong><small>Nenhuma etapa crítica é pulada.</small></span></div>';
        echo '<div><i class="fa-solid fa-wand-magic-sparkles"></i><span><strong>IA sob demanda</strong><small>Só responde intercorrências.</small></span></div>';
        echo '<div><i class="fa-solid fa-rotate-right"></i><span><strong>Retomada exata</strong><small>Volta à pergunta onde parou.</small></span></div>';
        echo '<div><i class="fa-solid fa-calendar-check"></i><span><strong>Final verificável</strong><small>Pix, agenda e humano protegidos.</small></span></div>';
        echo '</div>';
        echo '<form method="post" id="whatsappFlowForm" class="flow-editor-shell">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_whatsapp_service_flow">';
        echo '<input type="hidden" name="flow_definition" id="flowDefinitionInput">';
        echo '<section class="flow-canvas-panel">';
        echo '<div class="flow-config-strip">';
        echo '<label class="flow-enabled"><input type="checkbox" id="flowEnabled" ' . (!empty($flowConfig['enabled']) ? 'checked' : '') . '><span></span><strong>Roteiro ativo</strong></label>';
        echo '<label><span>Nome do roteiro</span><input id="flowName" value="' . h((string)($flowConfig['flow_name'] ?? 'Roteiro principal de agendamento')) . '"></label>';
        echo '</div>';
        echo '<label class="flow-intro-field"><span>Mensagem de abertura</span><textarea id="flowIntro" rows="2">' . h((string)($flowConfig['intro_text'] ?? '')) . '</textarea><small>É enviada uma única vez, antes da primeira pergunta do roteiro.</small></label>';
        echo '<div class="flow-canvas-toolbar"><div><strong data-flow-count>' . count($flowSteps) . ' blocos</strong><span> Clique em Editar para abrir as propriedades</span></div><button class="btn secondary tiny" type="button" data-flow-add><i class="fa-solid fa-plus"></i> Novo bloco</button></div>';
        echo '<div class="flow-canvas" data-flow-canvas></div>';
        echo '</section>';
        echo '<aside class="flow-inspector" data-flow-inspector hidden>';
        echo '<div class="flow-modal-backdrop" data-flow-close></div><div class="flow-inspector-dialog" role="dialog" aria-modal="true" aria-labelledby="flowEditorTitle">';
        echo '<div class="flow-inspector-empty" data-flow-empty><i class="fa-solid fa-arrow-pointer"></i><strong>Selecione um bloco</strong><p>As propriedades e a pergunta aparecerão aqui.</p></div>';
        echo '<div class="flow-inspector-form" data-flow-form hidden>';
        echo '<div class="flow-inspector-head"><div><span class="section-eyebrow">Editar bloco</span><h3 id="flowEditorTitle" data-flow-editor-title>Etapa</h3><small class="flow-editor-hint">As alterações ficam no roteiro e são publicadas ao salvar.</small></div><div class="flow-inspector-head-actions"><span class="flow-step-number" data-flow-editor-number>01</span><button class="flow-modal-close" type="button" data-flow-close aria-label="Fechar editor"><i class="fa-solid fa-xmark"></i></button></div></div>';
        echo '<div class="field"><label>Título interno</label><input data-flow-field="title" maxlength="160"></div>';
        echo '<div class="grid cols-2 flow-form-grid"><div class="field"><label>Tipo do bloco</label><select data-flow-field="step_type"><option value="question">Pergunta</option><option value="choice">Escolha / botões</option><option value="media">Arquivo ou mídia</option><option value="system">Ação do sistema</option></select></div>';
        echo '<div class="field"><label>Como validar</label><select data-flow-field="answer_type"><option value="text">Texto curto</option><option value="long_text">Descrição livre</option><option value="choice">Uma das opções</option><option value="body_area">Parte do corpo</option><option value="image_or_skip">Imagem, link ou sem referência</option><option value="schedule">Data e horário reais</option><option value="yes_no">Sim ou não</option><option value="system_quote">Calcular orçamento</option><option value="system_payment">Enviar Pix</option><option value="payment_proof">Comprovante imagem/PDF</option><option value="system_finalize">Criar agendamento</option></select></div></div>';
        echo '<div class="field"><label>Dado preenchido</label><select data-flow-field="field_key"><option value="customer_name">Nome do cliente</option><option value="tattoo_idea">Ideia/desenho</option><option value="reference_received">Referência</option><option value="body_area">Área do corpo</option><option value="body_details">Posição/lado</option><option value="size_coverage">Tamanho/cobertura</option><option value="style_preference">Estilo/cor</option><option value="quote">Orçamento</option><option value="selected_slot">Vaga da agenda</option><option value="slot_confirmed">Confirmação da vaga</option><option value="deposit_requested">Solicitação do Pix</option><option value="proof_received">Comprovante</option><option value="appointment_id">Agendamento criado</option><option value="custom_response">Resposta personalizada</option></select><small>Ações de sistema devem permanecer ligadas ao dado correspondente.</small></div>';
        echo '<div class="field flow-message-field"><div class="flow-field-head"><label>Mensagem ou pergunta fixa</label><button class="btn secondary tiny" type="button" data-flow-final-template><i class="fa-solid fa-calendar-check"></i> Inserir resumo final</button></div><div class="flow-format-toolbar" role="toolbar" aria-label="Formatação WhatsApp"><button type="button" data-flow-format="bold" title="Negrito"><strong>B</strong></button><button type="button" data-flow-format="italic" title="Itálico"><em>I</em></button><button type="button" data-flow-format="strike" title="Tachado"><s>S</s></button><button type="button" data-flow-format="mono" title="Monoespaçado"><code>M</code></button><span></span><button type="button" data-flow-format="quote" title="Citação">❝</button><button type="button" data-flow-format="bullet" title="Lista com marcadores">•</button><button type="button" data-flow-format="numbered" title="Lista numerada">1.</button></div><button type="button" data-flow-format-message hidden></button><textarea data-flow-field="question_text" rows="7" maxlength="2000"></textarea><small class="flow-format-help">Selecione um trecho e clique em um estilo. O WhatsApp usa *negrito*, _itálico_, ~tachado~, três acentos graves para monoespaçado, citações e listas.</small><div class="flow-message-preview" data-flow-message-preview><strong>Prévia WhatsApp</strong><span>Selecione ou escreva uma mensagem para visualizar.</span></div><small>Variáveis: {{customer_name}}, {{tattoo_idea}}, {{reference}}, {{project}}, {{body_area}}, {{body_details}}, {{size_coverage}}, {{style_preference}}, {{quote}}, {{quote_description}}, {{date}}, {{time}}, {{deposit}}, {{pix_key}}, {{pix_recipient}}, {{appointment_id}} e {{studio_address}}.</small></div>';
        echo '<div class="field"><label>Opções enviadas neste bloco</label><textarea data-flow-options rows="4" placeholder="Uma opção por linha"></textarea><small>Se este campo ficar vazio, o WhatsApp envia só a pergunta em texto, sem botões inventados. Até 3 opções viram botões; de 4 a 10 viram uma lista.</small></div>';
        echo '<div class="flow-connections-panel"><div class="flow-connections-head"><div><strong>Conexões do fluxograma</strong><small>Defina para onde a conversa segue depois deste bloco.</small></div><i class="fa-solid fa-route" aria-hidden="true"></i></div><div class="field"><label>Próximo bloco padrão</label><select data-flow-field="next_step_key" data-flow-next-step><option value="">Seguir a ordem abaixo</option></select><small>Usado quando nenhuma opção específica tiver um destino configurado.</small></div><div class="flow-branch-list" data-flow-branches><div class="flow-branch-empty">Adicione opções neste bloco para criar caminhos específicos.</div></div><input type="hidden" data-flow-field="branch_map_json" value="{}"></div>';
        echo '<div class="field"><label>Explicação para a equipe</label><textarea data-flow-field="help_text" rows="3" maxlength="600"></textarea></div>';
        echo '<div class="flow-checks"><label><input type="checkbox" data-flow-check="is_active"> Bloco ativo</label><label><input type="checkbox" data-flow-check="is_required"> Resposta obrigatória</label></div>';
        echo '<div class="flow-order-actions"><button type="button" class="btn secondary tiny" data-flow-up><i class="fa-solid fa-arrow-up"></i> Subir</button><button type="button" class="btn secondary tiny" data-flow-down><i class="fa-solid fa-arrow-down"></i> Descer</button><button type="button" class="btn danger tiny" data-flow-delete><i class="fa-solid fa-trash"></i> Excluir</button></div>';
         echo '</div></div></aside>';
         echo '<script>(function(){const inspector=document.querySelector("[data-flow-inspector]");const canvas=document.querySelector("[data-flow-canvas]");const field=document.querySelector("[data-flow-field=question_text]");if(!inspector||!canvas||!field)return;function openEditor(){inspector.hidden=false;inspector.classList.add("is-open");document.body.classList.add("flow-modal-open");window.setTimeout(function(){field.focus();},40);}function closeEditor(){inspector.classList.remove("is-open");inspector.hidden=true;document.body.classList.remove("flow-modal-open");}function decorateNodes(){canvas.querySelectorAll(".flow-node-wrap").forEach(function(wrap,index){if(wrap.querySelector("[data-flow-edit-index]"))return;const edit=document.createElement("button");edit.type="button";edit.className="flow-node-edit";edit.dataset.flowEditIndex=String(index);edit.textContent="Editar";edit.addEventListener("click",function(event){event.preventDefault();event.stopPropagation();const node=wrap.querySelector(".flow-node");if(node)node.click();window.setTimeout(openEditor,0);});wrap.append(edit);});}function applyFormat(kind){const value=String(field.value||"");const start=Number(field.selectionStart||0);const end=Number(field.selectionEnd||start);const selected=value.slice(start,end);const mono=String.fromCharCode(96).repeat(3);const markers={bold:["*","*"],italic:["_","_"],strike:["~","~"],mono:[mono,mono]};let replacement="";let nextStart=start;let nextEnd=start;if(markers[kind]){const pair=markers[kind];replacement=pair[0]+(selected||"texto")+pair[1];nextStart=start+(selected?replacement.length:pair[0].length);nextEnd=nextStart;}else{const source=selected||"texto";const lines=source.split("\\n");if(kind==="quote")replacement=lines.map(function(line){return line?"> "+line:">";}).join("\\n");if(kind==="bullet")replacement=lines.map(function(line){return line?"- "+line:"-";}).join("\\n");if(kind==="numbered")replacement=lines.map(function(line,lineIndex){return (lineIndex+1)+". "+line;}).join("\\n");nextStart=start+replacement.length;nextEnd=nextStart;}field.value=value.slice(0,start)+replacement+value.slice(end);field.focus();if(selected){field.setSelectionRange(nextStart,nextEnd);}else{const caret=start+(markers[kind]?markers[kind][0].length:replacement.length);field.setSelectionRange(caret,caret);}field.dispatchEvent(new Event("input",{bubbles:true}));}document.querySelectorAll("[data-flow-format]").forEach(function(button){button.addEventListener("click",function(){applyFormat(button.dataset.flowFormat);});});document.querySelectorAll("[data-flow-close]").forEach(function(button){button.addEventListener("click",closeEditor);});document.querySelector("[data-flow-add]")?.addEventListener("click",function(){window.setTimeout(openEditor,0);});document.addEventListener("keydown",function(event){if(event.key==="Escape"&&!inspector.hidden)closeEditor();});new MutationObserver(decorateNodes).observe(canvas,{childList:true});decorateNodes();})();</script>';
         echo '</form>';
         echo '<script src="' . h(app_asset_url('assets/studio_whatsapp_flow.js')) . '?v=' . h(app_build_version()) . '"></script>';
         echo '<div class="flow-footer-actions"><div><strong>As conversas em andamento preservam os dados já coletados.</strong><span> Se um bloco mudar, elas continuam no primeiro item ainda pendente.</span></div><div class="actions"><form method="post" onsubmit="return confirm(\'Restaurar todo o fluxograma recomendado? As personalizações serão substituídas.\')">' . csrf_field() . '<input type="hidden" name="action" value="reset_whatsapp_service_flow"><button class="btn secondary" type="submit"><i class="fa-solid fa-clock-rotate-left"></i> Restaurar recomendado</button></form><button class="btn" type="submit" form="whatsappFlowForm"><i class="fa-solid fa-floppy-disk"></i> Salvar e publicar</button></div></div>';
        echo '<script>(function(){const initial=' . json_encode($clientSteps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';let steps=Array.isArray(initial)?initial:[];let selected=steps.length?0:-1;const canvas=document.querySelector("[data-flow-canvas]");const inspector=document.querySelector("[data-flow-inspector]");const empty=inspector.querySelector("[data-flow-empty]");const form=inspector.querySelector("[data-flow-form]");const count=document.querySelector("[data-flow-count]");const icons={question:"fa-message",choice:"fa-list-check",media:"fa-image",system:"fa-gears"};const labels={question:"Pergunta",choice:"Escolha",media:"Mídia",system:"Sistema"};const finalTemplate="Beleza! Agendamento feito.\\n\\nResumo do agendamento:\\nCliente: {{customer_name}}\\nDia: {{date}}\\nHora: {{time}}\\nLocal da tattoo: {{body_area}}\\nIdeia: {{tattoo_idea}}\\nOrçamento: {{quote}}\\nEndereço: {{studio_address}}\\n\\nJá sinalizei a equipe para conferir os dados. Qualquer dúvida, é só chamar por aqui.";function escKey(value){return String(value||"step").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g,"").replace(/[^a-z0-9]+/g,"_").replace(/^_+|_+$/g,"")||"step";}function sync(){const payload={enabled:document.getElementById("flowEnabled").checked,flow_name:document.getElementById("flowName").value,intro_text:document.getElementById("flowIntro").value,steps};document.getElementById("flowDefinitionInput").value=JSON.stringify(payload);}function make(tag,cls,text){const el=document.createElement(tag);if(cls)el.className=cls;if(text!==undefined)el.textContent=text;return el;}function formatFlowMessage(text){let value=String(text||"").replace(/\\\\n/g,"\\n").replace(/\\r\\n?/g,"\\n");value=value.replace(/[ \\t]+$/gm,"").replace(/\\n{3,}/g,"\\n\\n").trim();if(!value)return "";value=value.replace(/\\s+(Cliente:|Dia:|Hora:|Local da tattoo:|Ideia:|Orçamento:|Endereco:|Endereço:|Qualquer dúvida|Ja sinalizei|Já sinalizei)/g,"\\n$1");value=value.replace(/(Agendamento feito[.!]?|Agendamento conclu[ií]do[.!]?)(\\n)(Cliente:|Dia:|Resumo)/i,"$1\\n\\n$3");if(!/Resumo do agendamento:/i.test(value)&&/(Cliente:|Dia:|Hora:)/.test(value)){value=value.replace(/(Agendamento feito[.!]?|Agendamento conclu[ií]do[.!]?)/i,"$1\\n\\nResumo do agendamento:");}value=value.replace(/\\n(Qualquer dúvida|Ja sinalizei|Já sinalizei)/,"\\n\\n$1");return value.replace(/\\n{3,}/g,"\\n\\n").trim();}function updatePreview(text){const preview=form.querySelector("[data-flow-message-preview]");if(!preview)return;const span=preview.querySelector("span");if(span)span.textContent=String(text||"").trim()||"Selecione ou escreva uma mensagem para visualizar.";}function setQuestionText(value){if(selected<0)return;const field=form.querySelector("[data-flow-field=question_text]");if(field)field.value=value;steps[selected].question_text=value;updatePreview(value);render();}function render(){canvas.replaceChildren();steps.forEach((step,index)=>{const wrap=make("div","flow-node-wrap");const node=make("button","flow-node"+(index===selected?" is-selected":"")+(!step.is_active?" is-disabled":""));node.type="button";node.dataset.index=String(index);const indexEl=make("span","flow-node-index",String(index+1).padStart(2,"0"));const icon=make("span","flow-node-icon");const iconI=make("i","fa-solid "+(icons[step.step_type]||icons.question));icon.append(iconI);const copy=make("span","flow-node-copy");copy.append(make("strong","",step.title||"Bloco sem título"));copy.append(make("small","",step.question_text||"Sem mensagem"));const meta=make("span","flow-node-meta");meta.append(make("b","",labels[step.step_type]||"Pergunta"));meta.append(make("em","",step.field_key||"custom_response"));node.append(indexEl,icon,copy,meta);node.addEventListener("click",()=>{selected=index;render();load();});wrap.append(node);if(index<steps.length-1){const connector=make("span","flow-connector");connector.append(make("i","fa-solid fa-arrow-down"));wrap.append(connector);}canvas.append(wrap);});count.textContent=steps.length+" bloco"+(steps.length===1?"":"s");sync();}function load(){const step=steps[selected];empty.hidden=!!step;form.hidden=!step;if(!step)return;form.querySelector("[data-flow-editor-title]").textContent=step.title||"Etapa";form.querySelector("[data-flow-editor-number]").textContent=String(selected+1).padStart(2,"0");form.querySelectorAll("[data-flow-field]").forEach(el=>{el.value=step[el.dataset.flowField]??"";});form.querySelector("[data-flow-options]").value=(step.options||[]).join("\\n");form.querySelectorAll("[data-flow-check]").forEach(el=>{el.checked=!!step[el.dataset.flowCheck];});updatePreview(step.question_text||"");}form.querySelectorAll("[data-flow-field]").forEach(el=>el.addEventListener("input",()=>{if(selected<0)return;steps[selected][el.dataset.flowField]=el.value;if(el.dataset.flowField==="title"){form.querySelector("[data-flow-editor-title]").textContent=el.value||"Etapa";}if(el.dataset.flowField==="question_text"){updatePreview(el.value);}render();}));form.querySelector("[data-flow-format-message]").addEventListener("click",()=>{if(selected<0)return;const current=String(steps[selected].question_text||"");setQuestionText(formatFlowMessage(current));});form.querySelector("[data-flow-final-template]").addEventListener("click",()=>{if(selected<0)return;if(String(steps[selected].question_text||"").trim()!==""&&!confirm("Substituir a mensagem deste bloco pelo modelo de resumo final?"))return;setQuestionText(finalTemplate);});form.querySelector("[data-flow-options]").addEventListener("input",function(){if(selected<0)return;steps[selected].options=this.value.split(/\\r?\\n/).map(v=>v.trim()).filter(Boolean).slice(0,10);sync();});form.querySelectorAll("[data-flow-check]").forEach(el=>el.addEventListener("change",()=>{if(selected<0)return;steps[selected][el.dataset.flowCheck]=el.checked;render();}));function move(delta){if(selected<0)return;const target=selected+delta;if(target<0||target>=steps.length)return;[steps[selected],steps[target]]=[steps[target],steps[selected]];selected=target;render();load();}form.querySelector("[data-flow-up]").addEventListener("click",()=>move(-1));form.querySelector("[data-flow-down]").addEventListener("click",()=>move(1));form.querySelector("[data-flow-delete]").addEventListener("click",()=>{if(selected<0||!confirm("Excluir este bloco do roteiro? A alteração só será publicada ao salvar."))return;steps.splice(selected,1);selected=Math.min(selected,steps.length-1);render();load();});document.querySelector("[data-flow-add]").addEventListener("click",()=>{const number=steps.length+1;steps.push({step_key:"custom_"+Date.now(),title:"Nova pergunta",step_type:"question",field_key:"custom_response_"+number,answer_type:"text",question_text:"Escreva aqui a pergunta fixa para o cliente.",help_text:"",options:[],is_required:true,is_active:true});selected=steps.length-1;render();load();form.querySelector("[data-flow-field=title]")?.focus();});["flowEnabled","flowName","flowIntro"].forEach(id=>document.getElementById(id).addEventListener("input",sync));document.getElementById("whatsappFlowForm").addEventListener("submit",event=>{sync();if(!steps.length){event.preventDefault();alert("Adicione pelo menos um bloco ao roteiro.");}});render();load();})();</script>';
        echo '<script>(function(){const field=document.querySelector("[data-flow-field=question_text]");const formatButton=document.querySelector("[data-flow-format-message]");const finalButton=document.querySelector("[data-flow-final-template]");if(!field)return;const labels="Cliente|Dia|Hora|Horário|Local da tattoo|Local|Ideia|Referência|Orçamento|Endereço|Observações";function formatMessage(raw){let value=String(raw||"").replace(/\\\\n/g,"\\n").replace(/\\r\\n?/g,"\\n").trim();if(!value)return "";value=value.replace(new RegExp("\\\\s+(?=\\\\*?(?:"+labels+")\\\\*?\\\\s*:)","giu"),"\\n");const lines=[];value.split("\\n").forEach(function(rawLine){const line=rawLine.trim();if(!line){lines.push("");return;}if(/^\\*?Resumo do agendamento\\*?\\s*:?\\*?$/iu.test(line)){lines.push("*Resumo do agendamento*");return;}const match=line.match(new RegExp("^\\\\*?("+labels+")\\\\*?\\\\s*:\\\\*?\\\\s*(.*)$","iu"));if(match){lines.push("*"+match[1]+":*"+(match[2].trim()?" "+match[2].trim():""));return;}if(/^(?:Beleza!\\s*)?Agendamento\\s+(?:feito|concluído|concluido)\\.?$/iu.test(line)){lines.push("*Agendamento feito!*");return;}if(/^(?:Já sinalizei|Ja sinalizei)\\s+/iu.test(line)){lines.push("✅ "+line);return;}lines.push(line);});let output=lines.join("\\n").replace(/\\n?(\\*Resumo do agendamento\\*)/iu,"\\n\\n$1").replace(/(\\*Resumo do agendamento\\*)\\n(?!\\n)/iu,"$1\\n\\n").replace(/\\n?(✅\\s+(?:Já|Ja) sinalizei)/iu,"\\n\\n$1");return output.replace(/\\n{3,}/g,"\\n\\n").trim();}function sync(value){field.value=value;field.dispatchEvent(new Event("input",{bubbles:true}));}if(formatButton)formatButton.addEventListener("click",function(event){event.preventDefault();event.stopImmediatePropagation();sync(formatMessage(field.value));},true);if(finalButton)finalButton.addEventListener("click",function(event){event.preventDefault();event.stopImmediatePropagation();if(String(field.value||"").trim()!==""&&!window.confirm("Substituir a mensagem deste bloco pelo modelo de resumo final?"))return;sync("*Agendamento feito!*\\n\\n*Resumo do agendamento*\\n\\n*Cliente:* {{customer_name}}\\n*Dia:* {{date}}\\n*Horário:* {{time}}\\n*Local da tattoo:* {{body_area}}\\n*Ideia:* {{tattoo_idea}}\\n*Orçamento:* {{quote}}\\n*Endereço:* {{studio_address}}\\n\\n✅ Já sinalizei a equipe para conferir os dados.\\nSe precisar alterar alguma coisa, é só chamar por aqui.");},true);})();</script>';
    }, $flash);
    exit;
}

if ($page === 'studio_settings') {
    $studio = require_studio();
    $activeSettingsTab = (string)($_GET['tab'] ?? 'studio');
    if (!in_array($activeSettingsTab, ['studio', 'agenda', 'whatsapp', 'ia', 'meta_ads', 'alerts', 'quick_replies', 'rules'], true)) {
        $activeSettingsTab = 'studio';
    }
    render_studio_shell('Configurações do estúdio', 'Regras comerciais e preparação dos módulos de IA/WhatsApp.', 'settings', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $settings = studio_settings($studio);
        $officialTestResult = null;
        $officialSendResult = null;
        if (isset($_SESSION['studio_whatsapp_official_test_result']) && is_array($_SESSION['studio_whatsapp_official_test_result'])) {
            $officialTestResult = $_SESSION['studio_whatsapp_official_test_result'];
            unset($_SESSION['studio_whatsapp_official_test_result']);
        }
        if (isset($_SESSION['studio_whatsapp_official_send_result']) && is_array($_SESSION['studio_whatsapp_official_send_result'])) {
            $officialSendResult = $_SESSION['studio_whatsapp_official_send_result'];
            unset($_SESSION['studio_whatsapp_official_send_result']);
        }
        $whatsappOfficialStatus = studio_whatsapp_official_status($studio);
        $artists = studio_list_artists($studio);
        $pomadaUnitPrice = (float)($settings['pomada_unit_price'] ?? 100);
        $dayOptions = [
            '1' => 'Segunda',
            '2' => 'Terça',
            '3' => 'Quarta',
            '4' => 'Quinta',
            '5' => 'Sexta',
            '6' => 'Sábado',
            '7' => 'Domingo',
        ];
        $workDaysSetting = $settings['appointment_work_days'] ?? '1,2,3,4,5,6,7';
        $selectedWorkDays = is_array($workDaysSetting)
            ? array_values(array_filter(array_map('strval', $workDaysSetting), static fn(string $value): bool => $value !== ''))
            : array_values(array_filter(preg_split('/\s*,\s*/', trim((string)$workDaysSetting)) ?: [], static fn(string $value): bool => $value !== ''));
        $durationHours = max(0, (int)($settings['appointment_duration_hours'] ?? 5));
        $durationMins = max(0, min(45, (int)($settings['appointment_duration_minutes_part'] ?? 0)));
        $activeTab = (string)($_GET['tab'] ?? 'studio');
        if (!in_array($activeTab, ['studio', 'agenda', 'whatsapp', 'ia', 'meta_ads', 'alerts', 'quick_replies', 'rules'], true)) {
            $activeTab = 'studio';
        }
        echo '<div class="panel soft" style="margin-bottom:16px">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center;gap:12px">';
        echo '<div><strong>Atendentes e acessos do estúdio</strong><div class="muted">Gerencie os usuários do estúdio em uma tela separada, fora do workspace.</div></div>';
        echo '<a class="btn secondary" href="' . h(app_url('studio_attendants', ['studio_id' => (int)$studio['id']])) . '">Abrir atendentes do estúdio</a>';
        echo '</div></div>';
        echo '<div class="settings-overview-grid">';
        $aiSettingsReady = !empty($settings['nvidia_api_key'])
            || !empty($settings['openai_api_key'])
            || (string)($settings['ai_provider'] ?? 'nvidia') === 'ollama';
        $settingsCards = [
            'studio' => ['Estúdio', 'Identidade, dados base e integrações', 'fa-store', 'Dados essenciais'],
            'agenda' => ['Agenda', 'Horários, duração e disponibilidade', 'fa-calendar-days', count($selectedWorkDays) . ' dias ativos'],
            'whatsapp' => ['WhatsApp', 'Conexão e comportamento do atendimento', 'fa-comments', 'API oficial'],
            'ia' => ['Inteligência artificial', 'Modelo, chave e automações', 'fa-robot', $aiSettingsReady ? 'Configurada' : 'Pendente'],
            'meta_ads' => ['Meta Ads', 'Conta, token, Pixel e formulários', 'fa-chart-line', !empty($settings['meta_ads_enabled']) ? 'Ativa' : 'Desativada'],
            'alerts' => ['Alertas', 'Avisos de saldo e operação no celular', 'fa-bell', !empty($settings['meta_balance_alert_enabled']) || !array_key_exists('meta_balance_alert_enabled', $settings) ? 'Ativos' : 'Desativados'],
            'quick_replies' => ['Respostas rápidas', 'Biblioteca para agilizar o atendimento', 'fa-reply', 'Conteúdo'],
            'rules' => ['Treinamento da IA', 'Regras usadas em todas as conversas', 'fa-graduation-cap', 'Conteúdo'],
            'tags' => ['Tags das conversas', 'Organização oficial e pessoal', 'fa-tags', 'Organização'],
        ];
        foreach ($settingsCards as $key => [$title, $subtitle, $icon, $status]) {
            if ($key === 'tags') {
                echo '<a class="settings-category-card" href="' . h(app_url('studio_whatsapp_tags')) . '"><span class="settings-category-icon"><i class="fa-solid ' . h($icon) . '"></i></span><span class="settings-category-status">' . h($status) . '</span><strong>' . h($title) . '</strong><small>' . h($subtitle) . '</small><span class="settings-category-link">Gerenciar <i class="fa-solid fa-arrow-right"></i></span></a>';
                continue;
            }
            echo '<button type="button" class="settings-category-card" data-settings-overlay="' . h($key) . '"><span class="settings-category-icon"><i class="fa-solid ' . h($icon) . '"></i></span><span class="settings-category-status">' . h($status) . '</span><strong>' . h($title) . '</strong><small>' . h($subtitle) . '</small><span class="settings-category-link">Configurar <i class="fa-solid fa-arrow-right"></i></span></button>';
        }
        if (studio_current_user_is_admin()) {
            echo '<a class="settings-category-card settings-flow-card" href="' . h(app_url('studio_whatsapp_flow')) . '"><span class="settings-category-icon"><i class="fa-solid fa-diagram-project"></i></span><span class="settings-category-status">ADM</span><strong>Roteiro do atendimento</strong><small>Fluxograma rígido, perguntas e ordem do agendamento</small><span class="settings-category-link">Editar fluxo <i class="fa-solid fa-arrow-right"></i></span></a>';
        }
        echo '<a class="settings-category-card" href="' . h(app_url('studio_finance')) . '"><span class="settings-category-icon"><i class="fa-solid fa-wallet"></i></span><span class="settings-category-status">Gestão</span><strong>Financeiro</strong><small>Despesas e leitura do resultado mensal</small><span class="settings-category-link">Abrir módulo <i class="fa-solid fa-arrow-right"></i></span></a>';
        if (studio_current_user_is_admin()) {
            echo '<a class="settings-category-card" href="' . h(app_url('studio_artists')) . '"><span class="settings-category-icon"><i class="fa-solid fa-pen-nib"></i></span><span class="settings-category-status">ADM</span><strong>Tatuadores</strong><small>Equipe artística, clientes atendidos e estatísticas</small><span class="settings-category-link">Gerenciar <i class="fa-solid fa-arrow-right"></i></span></a>';
        }
        echo '<a class="settings-category-card" href="' . h(app_url('studio_attendants', ['studio_id' => (int)$studio['id']])) . '"><span class="settings-category-icon"><i class="fa-solid fa-shield-halved"></i></span><span class="settings-category-status">Segurança</span><strong>Acessos</strong><small>Atendentes, usuários e permissões do estúdio</small><span class="settings-category-link">Gerenciar <i class="fa-solid fa-arrow-right"></i></span></a>';
        echo '</div>';
        echo '<div id="settingsOverlay" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1180px)"><div class="crm-panel-header"><div><h3 id="settingsOverlayTitle" class="crm-panel-title">Configurações</h3><p id="settingsOverlaySummary" class="muted" style="margin:4px 0 0"></p></div><button type="button" id="closeSettingsOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="settingsOverlayBody" class="p-4"></div></div></div>';
        echo '<form class="form panel" method="post" id="studioSettingsForm">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_studio_settings">';
        echo '<input type="hidden" name="settings_tab" value="' . h($activeTab) . '">';
        echo '<div id="settingsSourceStudio" hidden><div class="settings-panel" id="settings-studio" data-settings-panel="studio">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Estúdio</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Nome do estudio</label><input name="studio_name" value="' . h($settings['studio_name'] ?? $studio['name']) . '" required></div>';
        echo '<div class="field"><label>WhatsApp habilitado neste estudio</label><label class="checkline"><input type="checkbox" name="whatsapp_enabled" value="1" ' . (!empty($settings['whatsapp_enabled']) ? 'checked' : '') . '> Ativar/Desativar integração</label></div>';
        echo '</div>';
        echo '<div class="field"><label>Endereço oficial do estúdio</label><input name="studio_address" value="' . h($settings['studio_address'] ?? '') . '" placeholder="Rua, número, bairro, cidade"><small class="muted">A IA usa exatamente este endereço e nunca inventa um local.</small></div>';
        echo '<div class="settings-save-row"><span class="muted">Salva somente os dados essenciais exibidos neste painel.</span><button class="btn" type="button" data-settings-submit>Salvar dados do estúdio</button></div>';
        echo '</div></div>';
        echo '<div id="settingsSourceAgenda" hidden><div class="settings-panel" id="settings-agenda" data-settings-panel="agenda">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Agenda</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="panel soft">';
        echo '<h3 style="margin-top:0">Regras de agenda</h3>';
        echo '<div class="grid cols-3">';
        echo '<div class="field"><label>Dias da semana disponíveis</label><div class="weekday-picker">';
        foreach ($dayOptions as $dayValue => $dayLabel) {
            $dayValue = (string)$dayValue;
            $checked = in_array($dayValue, $selectedWorkDays, true) || ($selectedWorkDays === [] && in_array($dayValue, ['1','2','3','4','5','6','7'], true));
            echo '<label class="weekday-pill' . ($checked ? ' is-active' : '') . '">';
            echo '<input type="checkbox" name="appointment_work_days[]" value="' . h($dayValue) . '" ' . ($checked ? 'checked' : '') . '>';
            echo '<span>' . h($dayLabel) . '</span>';
            echo '</label>';
        }
        echo '</div><small class="muted">Selecione os dias em que o estudio atende. O padrão considera todos os dias.</small></div>';
        echo '<div class="field"><label>Horários disponíveis</label><input name="appointment_time_slots" value="' . h($settings['appointment_time_slots'] ?? '10:00,15:00') . '" placeholder="10:00,15:00"><small class="muted">Separe por vírgula. Ex: 10:00,15:00</small></div>';
        echo '<div class="field"><label>Valor da pomada</label><input name="pomada_unit_price" value="' . h(number_format($pomadaUnitPrice, 2, ',', '.')) . '" placeholder="100,00"><small class="muted">Este valor vale só para novos agendamentos. Os antigos mantêm o preço salvo neles.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-3">';
        echo '<div class="field"><label>Duração do atendimento</label><div class="duration-picker">';
        echo '<label><span>Horas</span><select name="appointment_duration_hours">';
        for ($hours = 0; $hours <= 12; $hours++) {
            echo '<option value="' . $hours . '"' . ($hours === $durationHours ? ' selected' : '') . '>' . $hours . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>Minutos</span><select name="appointment_duration_minutes_part">';
        foreach ([0, 15, 30, 45] as $minutes) {
            echo '<option value="' . $minutes . '"' . ($minutes === $durationMins ? ' selected' : '') . '>' . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT) . '</option>';
        }
        echo '</select></label>';
        echo '</div><small class="muted">O fim será calculado automaticamente. Ex: 5 horas = 10:00 até 15:00.</small></div>';
        echo '</div>';
        echo '<div class="field"><label>Mensagem quando a vaga for tomada por um confirmado</label><textarea name="appointment_overwrite_message" placeholder="Oi {{name}}, sua vaga do dia {{date}} às {{start_time}} foi ocupada por outro agendamento confirmado com sinal pago. Escolha outro horário e envie o sinal para garantir a nova vaga.">' . h($settings['appointment_overwrite_message'] ?? 'Oi {{name}}, sua vaga do dia {{date}} às {{start_time}} foi ocupada por outro agendamento confirmado com sinal pago. Escolha outro horário e envie o sinal para garantir a nova vaga.') . '</textarea><small class="muted">Aceita variáveis: {{name}}, {{date}}, {{start_time}}, {{end_time}}, {{new_date}}, {{new_start_time}}, {{new_end_time}}, {{studio_name}}, {{reason}}</small></div>';
        echo '<div class="field"><label>Mensagem de confirmação do agendamento</label><textarea name="appointment_confirmation_message" placeholder="Oi {{name}}! Sua sessão está confirmada para {{date}} às {{start_time}}. Me responde com sim para confirmar, ou avisa se precisar cancelar/alterar.">' . h($settings['appointment_confirmation_message'] ?? 'Oi {{name}}! Sua sessão está confirmada para {{date}} às {{start_time}}. Me responde com sim para confirmar, ou avisa se precisar cancelar/alterar.') . '</textarea><small class="muted">Aceita variáveis: {{name}}, {{date}}, {{start_time}}, {{end_time}}, {{studio_name}}, {{reason}}</small></div>';
        echo '<div class="settings-save-row"><span class="muted">Os dias, horários, duração e mensagens deste painel serão preservados juntos.</span><button class="btn" type="button" data-settings-submit>Salvar agenda</button></div>';
        echo '</div></div></div>';
        echo '<div id="settingsSourceWhatsapp" hidden><div class="settings-panel" id="settings-whatsapp" data-settings-panel="whatsapp">';
        echo '<div class="settings-panel-head">';
        echo '<div><h3 style="margin:0">WhatsApp</h3><p class="muted" style="margin:6px 0 0">Entrada, provedor e configuração oficial em um fluxo só.</p></div>';
        echo '<a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a>';
        echo '</div>';
        echo '<div class="settings-panel-summary-grid">';
        echo '<div class="drilldown-card compact settings-summary-card"><span class="badge">Provedor</span><strong>API oficial da Meta</strong><div class="muted">O fluxo oficial está selecionado como motor principal.</div></div>';
        echo '<div class="drilldown-card compact settings-summary-card"><span class="badge">Ambiente</span><strong>' . h((string)($settings['whatsapp_official_mode'] ?? 'production') === 'sandbox' ? 'Sandbox / teste' : 'Produção') . '</strong><div class="muted">' . h((string)($settings['whatsapp_official_mode'] ?? 'production') === 'sandbox' ? 'Usando dados e número de teste.' : 'Usando credenciais de produção.') . '</div></div>';
        echo '<div class="drilldown-card compact settings-summary-card"><span class="badge ' . h($whatsappOfficialStatus['ready'] ? 'ok' : 'warn') . '">Diagnóstico</span><strong>' . h((string)$whatsappOfficialStatus['score']) . '/' . h((string)$whatsappOfficialStatus['total']) . ' pronto</strong><div class="muted">' . h($whatsappOfficialStatus['ready'] ? 'Bloco oficial apto para testes.' : 'Ainda faltam campos para ativação completa.') . '</div></div>';
        echo '</div>';
        echo '<div class="settings-two-column">';
        echo '<div class="panel soft settings-group">';
        echo '<h3 style="margin-top:0">Entrada do WhatsApp</h3>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Motor principal</label><div class="muted">A integração usa somente a API oficial da Meta.</div></div>';
        echo '<input type="hidden" name="whatsapp_provider" value="official">';
        echo '<div class="field"><label>Padrão das novas conversas</label><select name="whatsapp_default_mode">';
        render_options(['human' => 'Humano atende primeiro', 'bot' => 'IA atende primeiro'], (string)($settings['whatsapp_default_mode'] ?? 'human'));
        echo '</select><small class="muted">Define quem assume as conversas que entram agora.</small></div>';
        echo '<div class="field"><label>Integração WhatsApp</label><div class="muted">A integração usa somente a API oficial da Meta.</div></div>';
        echo '</div>';
        echo '<div class="field"><label>Frases iniciais da campanha META</label><textarea name="meta_campaign_phrases" placeholder="Tenho interesse no fechamento!&#10;Quero fechar minha tattoo!">' . h($settings['meta_campaign_phrases'] ?? "Tenho interesse no fechamento!") . '</textarea><small class="muted">Uma frase por linha. O sistema usa isso para identificar leads/campanhas.</small></div>';
        echo '</div>';
        echo '<div class="panel soft settings-group">';
        echo '<h3 style="margin-top:0">WhatsApp oficial da Meta</h3>';
        echo '<p class="muted">Use este bloco para preparar a API oficial sem misturar isso com as configurações básicas da operação.</p>';
        echo '<div class="settings-status-box">';
        echo '<strong>Status de preparação: ' . h((string)$whatsappOfficialStatus['score']) . '/' . h((string)$whatsappOfficialStatus['total']) . '</strong>';
        echo '<div class="muted" style="margin-top:6px">' . h($whatsappOfficialStatus['ready'] ? 'Tudo pronto para a próxima etapa de testes.' : 'Ainda faltam campos obrigatórios para concluir a ativação.') . '</div>';
        echo '</div>';
        echo '<div class="settings-check-grid">';
        foreach ($whatsappOfficialStatus['checks'] as $check) {
            $tone = !empty($check['ok']) ? 'ok' : 'warn';
            echo '<div class="drilldown-card compact settings-check-card"><span class="badge ' . h($tone) . '">' . h(!empty($check['ok']) ? 'OK' : 'Pendente') . '</span><strong>' . h((string)$check['label']) . '</strong><div class="muted">' . h((string)($check['value'] !== '' ? $check['value'] : 'Não preenchido')) . '</div></div>';
        }
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Modo oficial</label><select name="whatsapp_official_mode"><option value="production"' . (((string)($settings['whatsapp_official_mode'] ?? 'production') === 'production') ? ' selected' : '') . '>Produção</option><option value="sandbox"' . (((string)($settings['whatsapp_official_mode'] ?? 'production') === 'sandbox') ? ' selected' : '') . '>Sandbox / teste</option></select><small class="muted">Sandbox usa os dados de teste; produção usa seu número real.</small></div>';
        echo '<div class="field"><label>Versão da API</label><input name="whatsapp_official_api_version" value="' . h($settings['whatsapp_official_api_version'] ?? 'v22.0') . '" placeholder="v22.0"><small class="muted">Versão da Graph API para WhatsApp oficial.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>App ID</label><input name="whatsapp_official_app_id" value="' . h($settings['whatsapp_official_app_id'] ?? '') . '" placeholder="123456789012345"><small class="muted">ID do app no Meta Developers.</small></div>';
        echo '<div class="field"><label>App Secret</label><input name="whatsapp_official_app_secret" type="password" value="" placeholder="App Secret"><small class="muted">Atual: ' . h(studio_meta_ads_mask_secret((string)($settings['whatsapp_official_app_secret'] ?? ''))) . '</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>WABA ID</label><input name="whatsapp_official_business_account_id" value="' . h($settings['whatsapp_official_business_account_id'] ?? '') . '" placeholder="123456789012345"><small class="muted">ID da WhatsApp Business Account vinculada ao app.</small></div>';
        echo '<div class="field"><label>Phone Number ID</label><input name="whatsapp_official_phone_number_id" value="' . h($settings['whatsapp_official_phone_number_id'] ?? '') . '" placeholder="123456789012345"><small class="muted">ID do número que vai enviar e receber mensagens.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>WABA ID de teste</label><input name="whatsapp_official_test_business_account_id" value="' . h($settings['whatsapp_official_test_business_account_id'] ?? '120771777788528') . '" placeholder="120771777788528"><small class="muted">Conta de teste separada da produção.</small></div>';
        echo '<div class="field"><label>Phone Number ID de teste</label><input name="whatsapp_official_test_phone_number_id" value="' . h($settings['whatsapp_official_test_phone_number_id'] ?? '126382657222367') . '" placeholder="126382657222367"><small class="muted">Número sandbox que já está respondendo.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Access Token</label><input name="whatsapp_official_access_token" type="password" value="" placeholder="EAAB..."><small class="muted">Atual: ' . h(studio_meta_ads_mask_secret((string)($settings['whatsapp_official_access_token'] ?? ''))) . '</small></div>';
        echo '<div class="field"><label>Webhook Verify Token</label><input name="whatsapp_official_verify_token" value="' . h($settings['whatsapp_official_verify_token'] ?? 'Luna*123') . '" placeholder="token-privado-de-verificacao"><small class="muted">Valida o webhook com a Meta.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Callback URL</label><input name="whatsapp_official_callback_url" value="' . h($settings['whatsapp_official_callback_url'] ?? (app_url('') . 'api/whatsapp_webhook.php')) . '" placeholder="' . h(app_url('') . 'api/whatsapp_webhook.php') . '"><small class="muted">URL pública do webhook.</small></div>';
        echo '<div class="field"><label>Webhook Secret</label><input name="whatsapp_official_webhook_secret" type="password" value="" placeholder="Opcional, se usar assinatura de eventos"><small class="muted">Atual: ' . h(studio_meta_ads_mask_secret((string)($settings['whatsapp_official_webhook_secret'] ?? ''))) . '</small></div>';
        echo '</div>';
        echo '<div class="field"><label>Observações do WhatsApp oficial</label><textarea name="whatsapp_official_notes" placeholder="Ex.: número principal, horário de atendimento, observações do webhook, etc.">' . h($settings['whatsapp_official_notes'] ?? '') . '</textarea><small class="muted">Anotações importantes para a futura ativação da API oficial.</small></div>';
        echo '<div class="panel soft"><h3 style="margin-top:0">Formulário Meta Flow</h3><p class="muted">Cadastre aqui um Flow já criado e publicado no WhatsApp Manager. Depois ele poderá ser enviado diretamente nas conversas.</p><div class="grid cols-3">';
        echo '<div class="field"><label>Flow ID</label><input name="whatsapp_flow_id" value="' . h($settings['whatsapp_flow_id'] ?? '') . '" placeholder="1234567890"></div>';
        echo '<div class="field"><label>Texto do botão</label><input name="whatsapp_flow_cta" maxlength="20" value="' . h($settings['whatsapp_flow_cta'] ?? 'Preencher') . '" placeholder="Preencher"></div>';
        echo '<div class="field"><label>Tela inicial</label><input name="whatsapp_flow_screen" value="' . h($settings['whatsapp_flow_screen'] ?? 'FIRST_ENTRY_SCREEN') . '" placeholder="FIRST_ENTRY_SCREEN"></div>';
        echo '</div></div>';
        echo '<div class="settings-save-row"><div class="muted">Os campos acima são salvos junto com o botão do bloco e com o botão final da página.</div><button class="btn" type="submit" data-settings-submit="whatsapp">Salvar ajustes do WhatsApp</button></div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="panel soft settings-group">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Teste e validação</h3><span class="badge">API oficial</span></div>';
        echo '<div class="settings-test-grid">';
        echo '<div class="settings-inline-form">';
        echo '<button class="btn" type="submit" name="action" value="test_whatsapp_official" formnovalidate>Testar configuração oficial</button>';
        echo '</div>';
        echo '<div class="settings-inline-form">';
        echo '<input type="text" name="to_phone" placeholder="' . h((string)($settings['whatsapp_official_mode'] ?? 'production') === 'sandbox' ? '15551015039' : '5511999999999') . '">';
        echo '<input type="text" name="message" placeholder="Mensagem de teste">';
        echo '<button class="btn secondary" type="submit" name="action" value="send_whatsapp_official_test_message" formnovalidate>Enviar teste</button>';
        echo '</div>';
        echo '</div>';
        if ($officialTestResult) {
            echo '<div class="drilldown-card compact" style="margin-top:12px"><strong>Resultado do teste</strong><div class="muted" style="margin-top:8px">' . h((string)($officialTestResult['summary'] ?? ($officialTestResult['error'] ?? ''))) . '</div></div>';
        }
        if ($officialSendResult) {
            $renderWhatsappDiag = static function ($value) use (&$renderWhatsappDiag): string {
                if (is_array($value)) {
                    $parts = [];
                    foreach ($value as $key => $child) {
                        $parts[] = h((string)$key) . ': ' . $renderWhatsappDiag($child);
                    }
                    return implode('<br>', $parts);
                }
                if (is_bool($value)) {
                    return $value ? 'SIM' : 'NAO';
                }
                if ($value === null) {
                    return 'vazio';
                }
                return h((string)$value);
            };
            echo '<div class="drilldown-card compact" style="margin-top:12px"><strong>Resultado do envio</strong><div class="muted" style="margin-top:8px">' . h((string)($officialSendResult['ok'] ? 'Mensagem enviada com sucesso.' : ($officialSendResult['error'] ?? 'Falha no envio.'))) . '</div>';
            if (!empty($officialSendResult['diagnostic']) && is_array($officialSendResult['diagnostic'])) {
                echo '<div class="muted" style="margin-top:10px"><strong>Diagnostico seguro</strong><div style="margin-top:6px;line-height:1.6">' . $renderWhatsappDiag($officialSendResult['diagnostic']) . '</div></div>';
            }
            if (!empty($officialSendResult['json']) && is_array($officialSendResult['json'])) {
                echo '<div class="muted" style="margin-top:10px"><strong>Resposta da Meta</strong><pre style="white-space:pre-wrap;margin:6px 0 0">' . h(json_encode($officialSendResult['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) . '</pre></div>';
            }
            echo '</div>';
        }
        echo '<div class="settings-howto">';
        echo '<h3 style="margin-top:0">Como testar agora</h3>';
        echo '<ol class="muted" style="margin:0;padding-left:20px">';
        echo '<li>Salve as configurações do bloco WhatsApp oficial.</li>';
        echo '<li>Na Meta, valide o webhook com o callback URL e o verify token.</li>';
        echo '<li>Depois, confira se o número aparece em <strong>Contas do WhatsApp Business</strong> com a conta escolhida.</li>';
        echo '<li>Se o status acima estiver completo, seguimos para envio e recebimento via API oficial.</li>';
        echo '</ol>';
        echo '</div>';
        echo '<div class="settings-howto" style="margin-top:16px">';
        echo '<h3 style="margin-top:0">Atendentes e acessos</h3>';
        echo '<p class="muted" style="margin-top:0">O gerenciamento de usuários do estúdio fica na tela administrativa do estúdio, não no workspace. Use este atalho para abrir direto a seção de acessos.</p>';
        echo '<div class="actions"><a class="btn secondary" href="' . h(app_url('studio_attendants', ['studio_id' => (int)$studio['id']])) . '">Abrir atendentes do estúdio</a></div>';
        echo '</div>';
        echo '</div>';
        echo '</div></div>';
        $aiProviderCurrent = (string)($settings['ai_provider'] ?? 'nvidia');
        if (!in_array($aiProviderCurrent, ['nvidia', 'openai', 'ollama'], true)) {
            $aiProviderCurrent = 'nvidia';
        }
        $aiBaseUrlCurrent = trim((string)($settings['ai_api_base_url'] ?? ''));
        if ($aiBaseUrlCurrent === '') {
            $aiBaseUrlCurrent = $aiProviderCurrent === 'openai'
                ? 'https://api.openai.com/v1'
                : ($aiProviderCurrent === 'ollama' ? 'http://localhost:11434/v1' : 'https://integrate.api.nvidia.com/v1');
        }
        echo '<div id="settingsSourceIa" hidden><div class="settings-panel" id="settings-ia" data-settings-panel="ia">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">IA</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="panel soft">';
        echo '<h3 style="margin-top:0">Motor principal do chatbot</h3>';
        echo '<p class="muted">A NVIDIA fica como padrão para o WhatsApp. OpenAI e Ollama continuam guardados como alternativas, sem misturar as chaves.</p>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Fornecedor da IA</label><select name="ai_provider"><option value="nvidia"' . ($aiProviderCurrent === 'nvidia' ? ' selected' : '') . '>NVIDIA API - Llama 70B</option><option value="openai"' . ($aiProviderCurrent === 'openai' ? ' selected' : '') . '>OpenAI</option><option value="ollama"' . ($aiProviderCurrent === 'ollama' ? ' selected' : '') . '>Ollama local</option></select><small class="muted">Este é o provedor usado pelo chatbot do WhatsApp e pelas sugestões de resposta.</small></div>';
        echo '<div class="field"><label>URL da IA</label><input name="ai_api_base_url" value="' . h($aiBaseUrlCurrent) . '" placeholder="https://integrate.api.nvidia.com/v1"><small class="muted">NVIDIA: https://integrate.api.nvidia.com/v1 | OpenAI: https://api.openai.com/v1 | Ollama: http://localhost:11434/v1</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Chave da NVIDIA</label><input name="nvidia_api_key" type="password" value="" placeholder="nvapi-..."><small class="muted">Atual: ' . h(studio_meta_ads_mask_secret((string)($settings['nvidia_api_key'] ?? ''))) . '</small></div>';
        echo '<div class="field"><label>Modelo NVIDIA</label><input name="nvidia_model" value="' . h($settings['nvidia_model'] ?? 'qwen/qwen3-next-80b-a3b-instruct') . '" placeholder="qwen/qwen3-next-80b-a3b-instruct"><small class="muted">Padrão testado: Qwen3 Next 80B, usado tanto para compreender quanto para redigir exceções do roteiro. Foi mais rápido e direto que o Llama 3.1 70B nesta API.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Chave NVIDIA Vision</label><input name="nvidia_vision_api_key" type="password" value="" placeholder="nvapi-..."><small class="muted">Atual: ' . h(studio_meta_ads_mask_secret((string)($settings['nvidia_vision_api_key'] ?? ''))) . ' · se ficar vazia, usa a chave NVIDIA principal.</small></div>';
        echo '<div class="field"><label>Modelo NVIDIA Vision</label><input name="nvidia_vision_model" value="' . h($settings['nvidia_vision_model'] ?? 'meta/llama-3.2-11b-vision-instruct') . '" placeholder="meta/llama-3.2-11b-vision-instruct"><small class="muted">Usado para entender imagens recebidas pelo WhatsApp. Testado aqui: 11B responde em poucos segundos; 90B pode dar timeout. O sistema ainda tenta fallback automático.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Chave NVIDIA Document Parse</label><input name="nvidia_document_api_key" type="password" value="" placeholder="nvapi-..."><small class="muted">Atual: ' . h(studio_meta_ads_mask_secret((string)($settings['nvidia_document_api_key'] ?? ''))) . ' · se ficar vazia, usa a chave NVIDIA principal.</small></div>';
        echo '<div class="field"><label>Modelo NVIDIA Document Parse</label><input name="nvidia_document_model" value="' . h($settings['nvidia_document_model'] ?? 'nvidia/nemoretriever-parse') . '" placeholder="nvidia/nemoretriever-parse"><small class="muted">Usado para ler documentos, prints e primeira página de PDFs recebidos no WhatsApp.</small></div>';
        echo '</div>';
        echo '<div class="panel soft" style="margin-top:16px">';
        echo '<h3 style="margin-top:0">Comportamento do chatbot e reservas</h3>';
        echo '<p class="muted">Essas regras controlam a dinâmica fina da conversa: agrupamento de mensagens quebradas, pedido de atendente e reserva por sinal.</p>';
        echo '<div class="grid cols-3">';
        echo '<div class="field"><label>Esperar mensagens quebradas por</label><input type="number" min="2" max="30" name="whatsapp_ai_debounce_seconds" value="' . h((string)($settings['whatsapp_ai_debounce_seconds'] ?? 8)) . '"><small class="muted">Tempo em segundos antes da IA responder. Ajuda quando o cliente manda “oi / boa noite / orçamento” separado.</small></div>';
        echo '<div class="field"><label>Valor do sinal</label><input name="ai_booking_deposit_amount" value="' . h(number_format((float)($settings['ai_booking_deposit_amount'] ?? 50), 2, ',', '.')) . '" placeholder="50,00"><small class="muted">Usado nas respostas e na criação automática do agendamento.</small></div>';
        echo '<div class="field"><label>Criar agenda após comprovante</label><label class="checkline"><input type="checkbox" name="ai_auto_create_appointment_after_proof" value="1" ' . ((int)($settings['ai_auto_create_appointment_after_proof'] ?? 1) === 1 ? 'checked' : '') . '> Se comprovante bater, lançar horário automaticamente</label></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Chave Pix para sinal</label><input name="ai_booking_pix_key" value="' . h($settings['ai_booking_pix_key'] ?? '363.262.368-60') . '" placeholder="CPF, telefone, email ou aleatória"></div>';
        echo '<div class="field"><label>Favorecido do Pix</label><input name="ai_booking_pix_recipient" value="' . h($settings['ai_booking_pix_recipient'] ?? 'Daniel Araújo da Silva') . '" placeholder="Nome de quem recebe o sinal"></div>';
        echo '</div>';
        echo '<div class="settings-switch-grid"><label class="checkline"><input type="checkbox" name="ai_keep_active_until_human_reply" value="1" ' . ((int)($settings['ai_keep_active_until_human_reply'] ?? 1) === 1 ? 'checked' : '') . '> Manter a IA respondendo até um atendente assumir</label></div>';
        echo '<div class="field"><label>Mensagem quando o cliente pede atendente</label><textarea name="ai_handoff_keepalive_message" placeholder="Avisei a equipe que você quer falar com um atendente. Enquanto isso, sigo por aqui se quiser mais alguma informação.">' . h($settings['ai_handoff_keepalive_message'] ?? 'Avisei a equipe que você quer falar com um atendente. Enquanto isso, sigo por aqui se quiser mais alguma informação.') . '</textarea><small class="muted">Quando marcado, pedir atendente só acende o alerta no sistema. A IA continua ativa e só desliga quando alguém assume ou responde como humano.</small></div>';
        echo '</div>';
        echo '<div class="panel soft" style="margin-top:16px">';
        echo '<h3 style="margin-top:0">IAs multimodais do WhatsApp</h3>';
        echo '<p class="muted">Aqui você controla quais camadas extras entram na conversa. O texto continua no modelo principal; estas opções servem para quando o cliente manda imagem, documento ou vídeo.</p>';
        echo '<div class="settings-switch-grid"><label class="checkline"><input type="checkbox" name="nvidia_vision_enabled" value="1" ' . ((int)($settings['nvidia_vision_enabled'] ?? 1) === 1 ? 'checked' : '') . '> Entender imagens recebidas</label><label class="checkline"><input type="checkbox" name="nvidia_document_enabled" value="1" ' . ((int)($settings['nvidia_document_enabled'] ?? 1) === 1 ? 'checked' : '') . '> Ler documentos e primeira página de PDF</label><label class="checkline"><input type="checkbox" name="nvidia_video_enabled" value="1" ' . ((int)($settings['nvidia_video_enabled'] ?? 1) === 1 ? 'checked' : '') . '> Analisar vídeos por frames</label></div>';
        echo '<div class="grid cols-2" style="margin-top:12px">';
        echo '<div class="field"><label>Modelo NVIDIA para vídeo</label><input name="nvidia_video_model" value="' . h($settings['nvidia_video_model'] ?? 'meta/llama-3.2-90b-vision-instruct') . '" placeholder="meta/llama-3.2-90b-vision-instruct"><small class="muted">Por padrão usa o mesmo tipo de modelo Vision, analisando frames extraídos com FFmpeg.</small></div>';
        echo '<div class="field"><label>Frames analisados por vídeo</label><select name="nvidia_video_frame_count">';
        for ($frameOption = 1; $frameOption <= 6; $frameOption++) {
            $selectedFrameOption = (int)($settings['nvidia_video_frame_count'] ?? 3) === $frameOption ? ' selected' : '';
            echo '<option value="' . $frameOption . '"' . $selectedFrameOption . '>' . $frameOption . ($frameOption === 3 ? ' - recomendado' : '') . '</option>';
        }
        echo '</select><small class="muted">Mais frames entendem melhor o vídeo, mas deixam a resposta mais lenta. Três costuma ser o ponto bom.</small></div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="panel soft" style="margin-top:16px">';
        echo '<h3 style="margin-top:0">Resposta em áudio</h3>';
        echo '<p class="muted">Controle se a IA responde em áudio. Windows SAPI é leve e simples; Coqui XTTS v2 é offline, mais natural e consegue usar uma amostra autorizada da Fran.</p>';
        echo '<div class="settings-switch-grid"><label class="checkline"><input type="checkbox" name="ai_voice_reply_enabled" value="1" ' . (!empty($settings['ai_voice_reply_enabled']) ? 'checked' : '') . '> IA pode responder em áudio</label><label class="checkline"><input type="checkbox" name="ai_voice_reply_when_audio_only" value="1" ' . ((int)($settings['ai_voice_reply_when_audio_only'] ?? 1) === 1 ? 'checked' : '') . '> Só responder em áudio quando o cliente mandar áudio</label></div>';
        echo '<div class="grid cols-2" style="margin-top:12px">';
        $voiceEngineCurrent = (string)($settings['ai_voice_reply_engine'] ?? 'sapi');
        echo '<div class="field"><label>Motor de voz</label><select name="ai_voice_reply_engine"><option value="sapi"' . ($voiceEngineCurrent === 'sapi' ? ' selected' : '') . '>Windows SAPI local</option><option value="xtts"' . ($voiceEngineCurrent === 'xtts' ? ' selected' : '') . '>Coqui XTTS v2 - voz clonada local</option></select><small class="muted">Se o XTTS falhar ou estiver sem amostra, o sistema tenta SAPI como plano B.</small></div>';
        $voiceSamplePath = trim((string)($settings['ai_voice_reply_xtts_sample_path'] ?? ''));
        $voiceSampleResolved = studio_whatsapp_ai_voice_resolve_xtts_sample(studio_whatsapp_ai_voice_config($studio));
        $voiceSampleRelative = str_replace('\\', '/', $voiceSamplePath);
        $voiceSampleCanPreview = $voiceSampleRelative !== '' && str_starts_with($voiceSampleRelative, 'storage/');
        $voiceSampleAbsolute = $voiceSampleRelative !== '' && str_starts_with($voiceSampleRelative, 'storage/')
            ? APP_BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $voiceSampleRelative)
            : $voiceSampleResolved;
        $voiceSampleStatus = $voiceSampleResolved !== ''
            ? 'Amostra pronta: ' . ($voiceSamplePath !== '' ? $voiceSamplePath : basename($voiceSampleResolved))
            : 'Nenhuma amostra encontrada ainda.';
        $voiceSampleInventory = studio_whatsapp_ai_voice_sample_inventory($studio);
        $voiceSampleScore = (int)($voiceSampleInventory['score'] ?? 0);
        $voiceSampleScoreColor = $voiceSampleScore >= 80 ? '#16a34a' : ($voiceSampleScore >= 55 ? '#d97706' : '#dc2626');
        echo '<div class="field voice-sample-card"><label>Amostra autorizada da Fran</label><input name="ai_voice_reply_xtts_sample_path" data-voice-sample-path value="' . h($voiceSamplePath) . '" placeholder="storage/voice-samples/fran.wav"><small class="muted" data-voice-sample-status>' . h($voiceSampleStatus) . '</small></div>';
        echo '</div>';
        echo '<div class="panel soft voice-sample-card" style="margin-top:12px;border-style:dashed">';
        echo '<div class="actions" style="justify-content:space-between;align-items:flex-start;gap:16px"><div><h4 style="margin:0 0 8px">Amostras da voz da Fran</h4><p class="muted" style="margin:0"><strong>TIP:</strong> para clonagem de voz no XTTS, uma amostra de 10 a 30 segundos já funciona para teste, mas o melhor resultado costuma vir de 3 a 6 amostras limpas, somando 60 a 120 segundos. Grave frases diferentes, voz natural, sem música, sem eco e sempre com autorização clara. Mais áudio só ajuda se for limpo; áudio ruim, abafado ou com microfones diferentes pode piorar a semelhança. Essas amostras servem como referência local de timbre/ritmo na hora de gerar áudio e também registram a autorização prática, mas não substituem um combinado formal com ela.</p></div><div style="min-width:170px;text-align:right"><strong data-voice-score-label>' . h((string)$voiceSampleScore . '% - ' . (string)($voiceSampleInventory['label'] ?? 'fraco')) . '</strong><div style="height:10px;border-radius:999px;background:#e5e7eb;overflow:hidden;margin-top:8px"><span data-voice-score-bar style="display:block;height:100%;width:' . h((string)$voiceSampleScore) . '%;background:' . h($voiceSampleScoreColor) . ';border-radius:999px"></span></div><small class="muted" data-voice-score-meta>' . h((string)($voiceSampleInventory['count'] ?? 0) . ' amostra(s), ' . number_format((float)($voiceSampleInventory['total_duration'] ?? 0), 1, ',', '.') . 's úteis') . '</small></div></div>';
        echo '<div class="actions" style="gap:8px;align-items:center;margin-top:12px"><button type="button" class="btn secondary" data-voice-record-start><i class="fa-solid fa-microphone"></i> Gravar nova amostra</button><button type="button" class="btn secondary" data-voice-record-stop disabled><i class="fa-solid fa-stop"></i> Parar e salvar</button><label class="btn secondary" style="margin:0"><i class="fa-solid fa-upload"></i> Enviar áudio<input type="file" data-voice-sample-file accept="audio/*" hidden></label><span class="muted" data-voice-record-state>Pronto para gravar.</span></div>';
        echo '<div class="voice-sample-list" data-voice-sample-list style="display:grid;gap:8px;margin-top:12px">';
        foreach ((array)($voiceSampleInventory['samples'] ?? []) as $sample) {
            $duration = (float)($sample['duration'] ?? 0);
            $sampleRelative = (string)($sample['path'] ?? '');
            $sampleAbsolute = (string)($sample['absolute_path'] ?? '');
            echo '<div class="panel soft" style="padding:10px;margin:0;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center"><div><strong>' . h((string)($sample['file_name'] ?? basename($sampleRelative))) . (!empty($sample['is_primary']) ? ' <span class="badge ok">principal</span>' : '') . '</strong><br><small class="muted">' . h($duration > 0 ? number_format($duration, 1, ',', '.') . 's' : 'duração não medida') . ' · ' . h($sampleRelative) . '</small></div>';
            if ($sampleRelative !== '' && str_starts_with($sampleRelative, 'storage/') && is_file($sampleAbsolute)) {
                echo '<audio controls style="width:220px;max-width:100%" src="' . h($sampleRelative . '?v=' . (string)filemtime($sampleAbsolute)) . '"></audio>';
            } else {
                echo '<span class="muted">sem prévia</span>';
            }
            echo '</div>';
        }
        if (empty($voiceSampleInventory['samples'])) {
            echo '<div class="panel soft" style="padding:10px;margin:0"><span class="muted">Nenhuma amostra salva ainda.</span></div>';
        }
        echo '</div><ul class="muted" data-voice-tips style="margin:12px 0 0">';
        foreach ((array)($voiceSampleInventory['tips'] ?? []) as $tip) {
            echo '<li>' . h((string)$tip) . '</li>';
        }
        echo '</ul>';
        if ($voiceSampleCanPreview && is_file((string)$voiceSampleAbsolute)) {
            echo '<audio controls style="width:100%;margin-top:12px" data-voice-sample-audio src="' . h($voiceSampleRelative . '?v=' . (string)filemtime((string)$voiceSampleAbsolute)) . '"></audio>';
        } else {
            echo '<audio controls style="width:100%;margin-top:12px;display:none" data-voice-sample-audio></audio>';
        }
        echo '<small class="muted">Sugestão de texto para uma das gravações: “Oi, aqui é a Fran. Eu autorizo o uso desta amostra da minha voz para responder clientes do estúdio.” Nas outras, peça para ela falar naturalmente frases de atendimento, com pausas normais.</small>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Idioma do XTTS</label><select name="ai_voice_reply_xtts_language">';
        foreach (['pt' => 'Português', 'en' => 'Inglês', 'es' => 'Espanhol', 'fr' => 'Francês', 'de' => 'Alemão', 'it' => 'Italiano'] as $langValue => $langLabel) {
            echo '<option value="' . h($langValue) . '"' . ((string)($settings['ai_voice_reply_xtts_language'] ?? 'pt') === $langValue ? ' selected' : '') . '>' . h($langLabel) . '</option>';
        }
        echo '</select><small class="muted">Para atendimento em português, deixe Português.</small></div>';
        echo '<div class="field"><label>Nome exato da voz SAPI</label><input name="ai_voice_reply_voice" value="' . h($settings['ai_voice_reply_voice'] ?? '') . '" placeholder="Ex.: Microsoft Maria Desktop"><small class="muted">Usado pelo Windows SAPI ou como fallback quando o XTTS não conseguir gerar.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        $voiceRateCurrent = max(-10, min(10, (int)($settings['ai_voice_reply_rate'] ?? 2)));
        $voiceVolumeCurrent = max(0, min(100, (int)($settings['ai_voice_reply_volume'] ?? 100)));
        echo '<div class="field"><label>Velocidade da voz <strong data-voice-rate-label>' . h((string)$voiceRateCurrent) . '</strong></label><input type="range" min="-10" max="10" step="1" name="ai_voice_reply_rate" value="' . h((string)$voiceRateCurrent) . '" data-voice-rate><small class="muted">Sugestão: 2 ou 3 para a voz do Windows não ficar arrastada. Negativo deixa mais lento.</small></div>';
        echo '<div class="field"><label>Volume <strong data-voice-volume-label>' . h((string)$voiceVolumeCurrent) . '%</strong></label><input type="range" min="0" max="100" step="5" name="ai_voice_reply_volume" value="' . h((string)$voiceVolumeCurrent) . '" data-voice-volume><small class="muted">Volume do áudio gerado pelo SAPI/XTTS.</small></div>';
        echo '</div>';
        echo '<div class="panel soft" style="margin-top:12px;border-style:dashed"><h4 style="margin:0 0 8px">Testar voz</h4><p class="muted" style="margin:0 0 10px">Escreva uma frase e ouça exatamente como ela sairia no áudio. O teste respeita motor, velocidade, volume e também remove a chave Pix da fala.</p><div class="field"><label>Texto para teste</label><textarea rows="3" data-voice-test-text placeholder="Ex.: Para reservar TER - 14/07/2026 às 10:00, o sinal é R$ 50,00 via Pix 363.262.368-60 em nome de Daniel.">Para reservar TER - 14/07/2026 às 10:00, o sinal é R$ 50,00 via Pix 363.262.368-60 em nome de Daniel.</textarea></div><div class="actions" style="gap:10px;align-items:center"><button type="button" class="btn secondary" data-voice-test-button><i class="fa-solid fa-play"></i> Gerar teste</button><span class="muted" data-voice-test-status>Pronto para testar.</span></div><audio controls data-voice-test-audio style="display:none;width:100%;margin-top:12px"></audio><small class="muted" data-voice-test-spoken style="display:block;margin-top:8px"></small></div>';
        echo '</div>';
        echo '<script>(function(){function scopeOf(el){return (el&&el.closest&&el.closest(".settings-panel"))||document;}function csrf(scope){return (scope.querySelector("input[name=csrf_token]")||document.querySelector("input[name=csrf_token]"))?.value||"";}function updateVoiceLabels(scope){scope.querySelectorAll("[data-voice-rate]").forEach((input)=>{const label=scope.querySelector("[data-voice-rate-label]");if(label)label.textContent=input.value;});scope.querySelectorAll("[data-voice-volume]").forEach((input)=>{const label=scope.querySelector("[data-voice-volume-label]");if(label)label.textContent=input.value+"%";});}document.addEventListener("input",(event)=>{if(!(event.target instanceof Element))return;if(event.target.matches("[data-voice-rate],[data-voice-volume]"))updateVoiceLabels(scopeOf(event.target));});document.addEventListener("click",async(event)=>{const button=event.target instanceof Element?event.target.closest("[data-voice-test-button]"):null;if(!button)return;const scope=scopeOf(button);const status=scope.querySelector("[data-voice-test-status]");const audio=scope.querySelector("[data-voice-test-audio]");const spoken=scope.querySelector("[data-voice-test-spoken]");const text=scope.querySelector("[data-voice-test-text]")?.value||"";const formData=new FormData();formData.append("action","test_ai_voice_reply");formData.append("csrf_token",csrf(scope));formData.append("voice_test_text",text);["ai_voice_reply_engine","ai_voice_reply_xtts_sample_path","ai_voice_reply_xtts_language","ai_voice_reply_voice","ai_voice_reply_rate","ai_voice_reply_volume"].forEach((name)=>{const field=scope.querySelector(`[name="${name}"]`)||document.querySelector(`[name="${name}"]`);if(field)formData.append(name,field.value||"");});button.disabled=true;if(status)status.textContent="Gerando áudio de teste...";try{const response=await fetch(window.location.pathname+window.location.search,{method:"POST",headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"},body:formData});const data=await response.json().catch(()=>null);if(!response.ok||!data||!data.ok){throw new Error((data&&data.error)||"Não foi possível gerar o teste.");}if(audio&&data.audio_url){audio.src=String(data.audio_url)+"?v="+Date.now();audio.style.display="block";audio.load();audio.play().catch(()=>{});}if(spoken)spoken.textContent=data.spoken_text?("Texto falado: "+data.spoken_text):"";if(status)status.textContent="Teste pronto."; }catch(error){if(status)status.textContent=error.message||"Erro ao testar voz.";alert(error.message||"Erro ao testar voz.");}finally{button.disabled=false;}});document.addEventListener("DOMContentLoaded",()=>updateVoiceLabels(document));})();</script>';
        echo '<script>(function(){let recorder=null;let chunks=[];let activePanel=null;let activeStream=null;function closestPanel(el){return el&&el.closest?el.closest(".voice-sample-card"):null;}function findScope(el){return (el&&el.closest&&el.closest(".settings-panel"))||document;}function csrf(scope){return (scope.querySelector("input[name=csrf_token]")||document.querySelector("input[name=csrf_token]"))?.value||"";}function setState(panel,text){const scope=findScope(panel);scope.querySelectorAll("[data-voice-record-state]").forEach((node)=>node.textContent=text);}function setBusy(panel,busy){const scope=findScope(panel);scope.querySelectorAll("[data-voice-record-start],[data-voice-record-stop],[data-voice-sample-file]").forEach((node)=>{if(node.matches("[data-voice-record-stop]")){node.disabled=!busy;}else{node.disabled=busy;}});}function updateSampleUi(scope,data){scope=document;scope.querySelectorAll("[data-voice-sample-path]").forEach((input)=>input.value=data.path||"");scope.querySelectorAll("[data-voice-sample-status]").forEach((node)=>node.textContent=data.path?("Amostra pronta: "+data.path):"Amostra salva.");scope.querySelectorAll("select[name=ai_voice_reply_engine]").forEach((select)=>select.value="xtts");scope.querySelectorAll("[data-voice-sample-audio]").forEach((audio)=>{if(data.path&&String(data.path).startsWith("storage/")){audio.src=data.path+"?v="+Date.now();audio.style.display="block";audio.load();}})}async function uploadVoiceSample(file,panel){const scope=findScope(panel);const formData=new FormData();formData.append("action","upload_voice_sample");formData.append("csrf_token",csrf(scope));formData.append("voice_sample",file,file.name||"fran.webm");setState(panel,"Salvando amostra...");const response=await fetch(window.location.pathname+window.location.search,{method:"POST",headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"},body:formData});const data=await response.json().catch(()=>null);if(!response.ok||!data||!data.ok){throw new Error((data&&data.error)||"Não foi possível salvar a amostra.");}updateSampleUi(scope,data);setState(panel,"Amostra salva. Salve as configurações para manter os demais ajustes desta tela.");}document.addEventListener("click",async(event)=>{const start=event.target instanceof Element?event.target.closest("[data-voice-record-start]"):null;const stop=event.target instanceof Element?event.target.closest("[data-voice-record-stop]"):null;if(start){const panel=closestPanel(start);try{if(!navigator.mediaDevices||!window.MediaRecorder){throw new Error("Este navegador não liberou gravação de áudio.");}activePanel=panel;chunks=[];activeStream=await navigator.mediaDevices.getUserMedia({audio:true});recorder=new MediaRecorder(activeStream);recorder.ondataavailable=(ev)=>{if(ev.data&&ev.data.size>0)chunks.push(ev.data);};recorder.onstop=async()=>{try{const type=chunks[0]?.type||"audio/webm";const blob=new Blob(chunks,{type});const file=new File([blob],"fran.webm",{type});await uploadVoiceSample(file,activePanel);}catch(error){setState(activePanel,error.message||"Erro ao salvar amostra.");alert(error.message||"Erro ao salvar amostra.");}finally{activeStream&&activeStream.getTracks().forEach((track)=>track.stop());activeStream=null;recorder=null;chunks=[];setBusy(activePanel,false);}};recorder.start();setBusy(panel,true);setState(panel,"Gravando... fale por 10 a 30 segundos e clique em Parar e salvar.");}catch(error){setBusy(panel,false);setState(panel,error.message||"Não foi possível iniciar gravação.");alert(error.message||"Não foi possível iniciar gravação.");}}if(stop&&recorder){event.preventDefault();if(recorder.state!=="inactive")recorder.stop();}});document.addEventListener("change",async(event)=>{const input=event.target instanceof Element?event.target.closest("[data-voice-sample-file]"):null;if(!input||!input.files||!input.files[0])return;const panel=closestPanel(input);try{await uploadVoiceSample(input.files[0],panel);}catch(error){setState(panel,error.message||"Erro ao enviar arquivo.");alert(error.message||"Erro ao enviar arquivo.");}finally{input.value="";}});})();</script>';
        echo '</div>';
        echo '<div class="panel soft">';
        echo '<h3 style="margin-top:0">Alternativas opcionais</h3>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Chave da OpenAI</label><input name="openai_api_key" type="password" value="" placeholder="sk-..."><small class="muted">Atual: ' . h(studio_meta_ads_mask_secret((string)($settings['openai_api_key'] ?? ''))) . '</small></div>';
        echo '<div class="field"><label>Modelo OpenAI</label><input name="openai_model" value="' . h($settings['openai_model'] ?? 'gpt-4o-mini') . '" placeholder="gpt-4o-mini"><small class="muted">Usado apenas se você trocar o fornecedor para OpenAI.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Modelo Ollama/local</label><input name="ai_model" value="' . h($settings['ai_model'] ?? $studio['ai_model'] ?? 'llama3.2:3b') . '" placeholder="llama3.2:3b"><small class="muted">Usado apenas se você trocar o fornecedor para Ollama local.</small></div>';
        echo '<div class="field"><label>Modelo para compreender conversas</label><select name="ai_semantic_model">';
        $semanticModelCurrent = (string)($settings['ai_semantic_model'] ?? 'qwen/qwen3-next-80b-a3b-instruct');
        foreach (['qwen/qwen3-next-80b-a3b-instruct' => 'Qwen3 Next 80B - melhor compreensão (recomendado)', 'meta/llama-3.1-70b-instruct' => 'Llama 3.1 70B - modelo principal', 'meta/llama-3.1-8b-instruct' => 'Llama 3.1 8B - mais rápido'] as $semanticModelValue => $semanticModelLabel) {
            echo '<option value="' . h($semanticModelValue) . '"' . ($semanticModelCurrent === $semanticModelValue ? ' selected' : '') . '>' . h($semanticModelLabel) . '</option>';
        }
        echo '</select><small class="muted">Lê contexto, abreviações, correções e mensagens fragmentadas antes do modelo principal escrever a resposta.</small></div>';
        echo '</div><div class="grid cols-2">';
        echo '<div class="settings-switch-grid"><label class="checkline"><input type="checkbox" name="ai_enabled" value="1" ' . (!empty($settings['ai_enabled']) ? 'checked' : '') . '> IA pode responder conversas marcadas como IA</label><label class="checkline"><input type="checkbox" name="ai_semantic_interpreter_enabled" value="1" ' . ((int)($settings['ai_semantic_interpreter_enabled'] ?? 1) === 1 ? 'checked' : '') . '> Compreender a conversa inteira antes de responder</label><label class="checkline"><input type="checkbox" name="assistant_autofill_enabled" value="1" ' . (!empty($settings['assistant_autofill_enabled']) ? 'checked' : '') . '> Assistente preencher sugestões automaticamente nas conversas</label><label class="checkline"><input type="checkbox" name="ai_learn_from_attendants_enabled" value="1" ' . ((int)($settings['ai_learn_from_attendants_enabled'] ?? 1) === 1 ? 'checked' : '') . '> Aprender com respostas reais dos atendentes</label><label class="checkline"><input type="checkbox" name="ai_conversation_summary_enabled" value="1" ' . ((int)($settings['ai_conversation_summary_enabled'] ?? 1) === 1 ? 'checked' : '') . '> Gerar resumo vivo das conversas</label><label class="checkline"><input type="checkbox" name="whatsapp_enabled" value="1" ' . (!empty($settings['whatsapp_enabled']) ? 'checked' : '') . '> WhatsApp oficial ativo neste estudio</label></div>';
        echo '<p class="muted" style="margin:10px 0 0">A compreensão da conversa inteira usa um modelo especializado para identificar intenção, contexto, correções e perguntas paralelas. O modelo principal escreve a resposta; as regras do sistema apenas validam preços, horários, pagamentos e gravações reais.</p>';
        echo '</div>';
        echo '<div class="settings-howto" style="margin-top:12px"><strong>Como esse aprendizado funciona</strong><p class="muted" style="margin:6px 0 0">Quando ativado, a IA lê respostas humanas dos atendentes para aprender tom, ordem das perguntas e condução comercial. Ela não usa fatos de outro cliente como preço, data, comprovante ou endereço particular; esses dados continuam vindo da conversa atual, da agenda e das regras cadastradas.</p></div>';
        echo '<div class="panel soft" style="margin-top:12px;border-style:dashed"><div class="actions" style="justify-content:space-between;align-items:flex-start;gap:14px"><div><h3 style="margin:0">Aprendizado operacional da equipe</h3><p class="muted" style="margin:6px 0 0">Aqui ficam os playbooks que a IA usa para entender contexto, objeções, tipos de cliente e formas de contornar situações. Gere automaticamente pelas conversas reais, revise o texto e salve.</p></div><button class="btn secondary" type="submit" name="action" value="generate_ai_team_playbook"><i class="fa-solid fa-wand-magic-sparkles"></i> Gerar/atualizar playbooks</button></div>';
        echo '<div class="panel" data-learning-import style="margin-top:14px;padding:16px;background:linear-gradient(135deg,#f8fffc 0%,#f3f8ff 100%);border-color:#cfe5dc"><div class="actions" style="justify-content:space-between;align-items:flex-start;gap:18px"><div><span class="section-eyebrow">Aprendizado acelerado</span><h4 style="margin:4px 0 6px">Importar conversa exportada do WhatsApp</h4><p class="muted" style="margin:0;max-width:720px">Envie o ZIP completo criado pelo WhatsApp. A IA lê a conversa, transcreve áudios localmente, interpreta uma quantidade segura de imagens, PDFs e vídeos, incorpora apenas estratégias reutilizáveis ao playbook e apaga o ZIP e todas as mídias temporárias no final.</p></div><span class="pill success"><i class="fa-solid fa-shield-halved"></i> Arquivos efêmeros</span></div>';
        echo '<div class="grid cols-2" style="margin-top:14px"><div class="field"><label>Export do WhatsApp (.zip)</label><input type="file" accept=".zip,application/zip" data-learning-zip><small class="muted">Máximo de 38 MB. Use “Exportar conversa” com mídias no WhatsApp.</small></div><div class="field"><label>Nome(s) dos atendentes no export <span class="muted">(opcional)</span></label><input type="text" data-learning-attendants placeholder="Ex.: Daniel, Fran"><small class="muted">Ajuda a IA a distinguir equipe e cliente. Separe nomes por vírgula.</small></div></div>';
        echo '<div class="actions" style="gap:12px;align-items:center"><button type="button" class="btn" data-learning-submit><i class="fa-solid fa-file-zipper"></i> Ler e aprender com o ZIP</button><span class="muted" data-learning-status>O arquivo só existe durante o processamento.</span></div>';
        echo '<div data-learning-progress-wrap style="display:none;margin-top:14px"><div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:6px"><strong data-learning-stage>Preparando arquivo...</strong><strong data-learning-percent>0%</strong></div><div style="height:10px;border-radius:999px;background:#dce8e3;overflow:hidden"><span data-learning-bar style="display:block;height:100%;width:0;background:linear-gradient(90deg,#157f64,#31b78d);border-radius:999px;transition:width .35s ease"></span></div><p data-learning-detail style="margin:9px 0 0;font-weight:650">Validando o ZIP.</p><div class="grid cols-3" data-learning-counters style="margin-top:10px;gap:8px"><div class="settings-howto"><strong data-learning-files>0</strong><small class="muted">arquivos</small></div><div class="settings-howto"><strong data-learning-audio>0/0</strong><small class="muted">áudios</small></div><div class="settings-howto"><strong data-learning-media>0/0</strong><small class="muted">mídias</small></div></div><div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:10px"><small class="muted">Tempo decorrido: <strong data-learning-elapsed>0s</strong></small><small class="muted">Estimativa restante: <strong data-learning-eta>calculando...</strong></small><small class="muted">Última confirmação do servidor: <strong data-learning-heartbeat>agora</strong></small></div><small class="muted" style="display:block;margin-top:8px">O percentual é ponderado por etapas concluídas e só avança após confirmação do servidor. A estimativa pode mudar conforme a duração dos áudios e das análises.</small></div>';
        echo '<div class="settings-howto" data-learning-result style="margin-top:12px"><strong>Último processamento</strong><p class="muted" style="margin:6px 0 0" data-learning-last>' . h((string)($settings['ai_learning_import_last_summary'] ?? 'Nenhum export importado ainda.')) . '</p><small class="muted">Importações concluídas: ' . h((string)($settings['ai_learning_import_count'] ?? 0)) . '. Última: ' . h((string)($settings['ai_learning_import_last_at'] ?? 'nunca')) . '.</small></div></div>';
        $learningImports = studio_whatsapp_learning_imports($studio, 30);
        echo '<div class="panel soft" style="margin-top:14px" data-learning-library><div class="actions" style="justify-content:space-between;align-items:flex-start;gap:12px"><div><h4 style="margin:0">Aprendizados importados</h4><p class="muted" style="margin:5px 0 0">Abra cada conversa para conferir exatamente o que entrou na orientação da IA. Excluir remove somente aquele aprendizado.</p></div><span class="badge" data-learning-count>' . h((string)count($learningImports)) . '</span></div><div data-learning-list style="display:grid;gap:10px;margin-top:12px">';
        if (!$learningImports) {
            echo '<div class="settings-howto" data-learning-empty><span class="muted">Nenhuma conversa importada no novo histórico.</span></div>';
        }
        foreach ($learningImports as $learningImport) {
            $importId = (int)($learningImport['id'] ?? 0);
            $createdByLabel = (int)($learningImport['created_by_user_id'] ?? 0) > 0 ? studio_user_label_by_id((int)$learningImport['created_by_user_id']) : '';
            echo '<article class="panel" data-learning-card="' . h((string)$importId) . '" style="margin:0;padding:14px"><div class="actions" style="justify-content:space-between;align-items:flex-start;gap:12px"><div style="min-width:0"><strong style="display:block;overflow-wrap:anywhere">' . h((string)($learningImport['original_file_name'] ?? 'Export do WhatsApp')) . '</strong><small class="muted">Importado em ' . h((string)($learningImport['created_at'] ?? '')) . ($createdByLabel !== '' ? ' por ' . h($createdByLabel) : '') . '</small></div><button type="button" class="btn tiny danger" data-learning-delete="' . h((string)$importId) . '"><i class="fa-solid fa-trash"></i> Excluir</button></div>';
            echo '<div class="actions" style="gap:6px;margin-top:10px"><span class="badge">' . h((string)($learningImport['message_count'] ?? 0)) . ' mensagens</span><span class="badge">' . h((string)($learningImport['audio_transcribed'] ?? 0)) . '/' . h((string)($learningImport['audio_count'] ?? 0)) . ' áudios</span><span class="badge">' . h((string)($learningImport['media_analyzed'] ?? 0)) . '/' . h((string)($learningImport['media_count'] ?? 0)) . ' mídias</span><span class="badge">' . h((string)($learningImport['processing_seconds'] ?? 0)) . 's</span></div>';
            echo '<details style="margin-top:10px"><summary style="cursor:pointer;font-weight:750">Ver o que a IA aprendeu</summary><div class="settings-howto" style="margin-top:10px;white-space:pre-wrap;line-height:1.55" data-learning-text>' . h((string)($learningImport['learned_text'] ?? '')) . '</div></details></article>';
        }
        echo '</div></div>';
        echo '<div class="settings-switch-grid" style="margin-top:12px"><label class="checkline"><input type="checkbox" name="ai_team_playbook_enabled" value="1" ' . ((int)($settings['ai_team_playbook_enabled'] ?? 1) === 1 ? 'checked' : '') . '> Usar estes playbooks nas respostas da IA</label></div>';
        echo '<div class="field" style="margin-top:12px"><label>Playbooks aprendidos</label><textarea name="ai_team_playbook_text" rows="16" style="min-height:320px" placeholder="Clique em Gerar/atualizar playbooks para a IA analisar conversas reais e criar estratégias editáveis.">' . h($settings['ai_team_playbook_text'] ?? '') . '</textarea><small class="muted">Última atualização: ' . h((string)($settings['ai_team_playbook_updated_at'] ?? 'nunca')) . '. Edite livremente. Isso vale como estratégia; fatos oficiais continuam vindo das regras, agenda e conversa atual.</small></div></div>';
        echo '<script>(function(){function scopeOf(el){return (el&&el.closest&&el.closest(".settings-panel"))||document;}function csrf(scope){return (scope.querySelector("input[name=csrf_token]")||document.querySelector("input[name=csrf_token]"))?.value||"";}function duration(value){value=Math.max(0,Math.round(Number(value)||0));if(value<60)return value+"s";const minutes=Math.floor(value/60);const seconds=value%60;return minutes+"min"+(seconds?(" "+seconds+"s"):"");}function setText(panel,selector,value){const el=panel.querySelector(selector);if(el)el.textContent=value;}function setVisual(panel,value,stage,detail){const safe=Math.max(0,Math.min(100,Math.round(Number(value)||0)));const wrap=panel.querySelector("[data-learning-progress-wrap]");if(wrap)wrap.style.display="block";const bar=panel.querySelector("[data-learning-bar]");if(bar)bar.style.width=safe+"%";setText(panel,"[data-learning-percent]",safe+"%");if(stage)setText(panel,"[data-learning-stage]",stage);if(detail)setText(panel,"[data-learning-detail]",detail);}const labels={waiting_upload:"Aguardando upload",validating:"Validando ZIP",extracting:"Extraindo arquivos",parsing:"Lendo conversa",planning:"Planejando análises",loading_transcriber:"Carregando transcritor local",transcribing_audio:"Transcrevendo áudios",analyzing_media:"Interpretando mídias",consolidating:"Consolidando aprendizado",saving:"Salvando aprendizado",completed:"Aprendizado concluído",failed:"Importação interrompida"};function renderJob(panel,job){if(!job)return;const counters=job.counters||{};setVisual(panel,job.progress,labels[job.stage]||job.stage||"Processando",job.detail||"Processando conversa.");setText(panel,"[data-learning-files]",String(counters.files_found||0));setText(panel,"[data-learning-audio]",String(counters.audio_completed||0)+"/"+String(counters.audio_found||counters.audio_selected||0));setText(panel,"[data-learning-media]",String(counters.media_completed||0)+"/"+String(counters.media_found||counters.media_selected||0));setText(panel,"[data-learning-elapsed]",duration(job.elapsed_seconds||0));setText(panel,"[data-learning-eta]",job.status==="completed"?"concluído":job.eta_seconds===null||job.eta_seconds===undefined?"calculando...":"aprox. "+duration(job.eta_seconds));setText(panel,"[data-learning-heartbeat]",(job.seconds_since_update||0)>2?("há "+duration(job.seconds_since_update)):"agora");}async function post(scope,fields){const body=new FormData();Object.entries(fields).forEach(([key,value])=>body.append(key,String(value)));body.append("csrf_token",csrf(scope));const response=await fetch(window.location.pathname+window.location.search,{method:"POST",headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"},body});const data=await response.json().catch(()=>null);if(!response.ok||!data||!data.ok)throw new Error((data&&data.error)||"O servidor não concluiu a solicitação.");return data;}function appendImport(data){const list=document.querySelector("[data-learning-list]");if(!list||!data.import_id)return;document.querySelector("[data-learning-empty]")?.remove();const card=document.createElement("article");card.className="panel";card.dataset.learningCard=String(data.import_id);card.style.cssText="margin:0;padding:14px";const head=document.createElement("div");head.className="actions";head.style.cssText="justify-content:space-between;align-items:flex-start;gap:12px";const title=document.createElement("div");title.style.minWidth="0";const strong=document.createElement("strong");strong.style.cssText="display:block;overflow-wrap:anywhere";strong.textContent=data.original_file_name||"Export do WhatsApp";const small=document.createElement("small");small.className="muted";small.textContent="Importado agora";title.append(strong,small);const remove=document.createElement("button");remove.type="button";remove.className="btn tiny danger";remove.dataset.learningDelete=String(data.import_id);remove.textContent="Excluir";head.append(title,remove);const badges=document.createElement("div");badges.className="actions";badges.style.cssText="gap:6px;margin-top:10px";[[data.message_count||0," mensagens"],[(data.audio_transcribed||0)+"/"+(data.audio_count||0)," áudios"],[(data.media_analyzed||0)+"/"+(data.media_count||0)," mídias"],[(data.processing_seconds||0),"s"]].forEach(([value,label])=>{const span=document.createElement("span");span.className="badge";span.textContent=String(value)+label;badges.appendChild(span);});const details=document.createElement("details");details.style.marginTop="10px";const summary=document.createElement("summary");summary.style.cssText="cursor:pointer;font-weight:750";summary.textContent="Ver o que a IA aprendeu";const learned=document.createElement("div");learned.className="settings-howto";learned.dataset.learningText="";learned.style.cssText="margin-top:10px;white-space:pre-wrap;line-height:1.55";learned.textContent=data.learned_text||"";details.append(summary,learned);card.append(head,badges,details);list.prepend(card);const count=document.querySelector("[data-learning-count]");if(count)count.textContent=String(document.querySelectorAll("[data-learning-card]").length);}document.addEventListener("click",async function(event){const remove=event.target instanceof Element?event.target.closest("[data-learning-delete]"):null;if(remove){const card=remove.closest("[data-learning-card]");const importId=remove.getAttribute("data-learning-delete")||"0";if(!confirm("Excluir somente este aprendizado da memória operacional da IA?"))return;remove.disabled=true;try{await post(scopeOf(remove),{action:"delete_whatsapp_learning_import",import_id:importId});card?.remove();const cards=document.querySelectorAll("[data-learning-card]");const count=document.querySelector("[data-learning-count]");if(count)count.textContent=String(cards.length);if(!cards.length){const empty=document.createElement("div");empty.className="settings-howto";empty.dataset.learningEmpty="";empty.innerHTML="<span class=muted>Nenhuma conversa importada no novo histórico.</span>";document.querySelector("[data-learning-list]")?.appendChild(empty);}}catch(error){remove.disabled=false;alert(error.message||"Não foi possível excluir.");}return;}const button=event.target instanceof Element?event.target.closest("[data-learning-submit]"):null;if(!button)return;const panel=button.closest("[data-learning-import]");const scope=scopeOf(button);const input=panel?.querySelector("[data-learning-zip]");const file=input?.files?.[0];if(!panel||!file){alert("Selecione o ZIP exportado pelo WhatsApp.");return;}if(file.size>38*1024*1024){alert("O ZIP precisa ter no máximo 38 MB.");return;}button.disabled=true;let pollTimer=null;let jobId="";let uploadActive=true;const stopPoll=()=>{if(pollTimer){clearInterval(pollTimer);pollTimer=null;}};const poll=async()=>{if(!jobId)return;try{const data=await post(scope,{action:"whatsapp_learning_job_status",job_id:jobId});if(!uploadActive||data.job.status!=="waiting_upload")renderJob(panel,data.job);if(["completed","failed"].includes(data.job.status))stopPoll();}catch(error){}};try{const started=await post(scope,{action:"start_whatsapp_learning_job",file_name:file.name,file_size:file.size});jobId=started.job.job_id;setVisual(panel,0,"Enviando ZIP","Preparando upload real de "+(file.size/1024/1024).toFixed(1)+" MB.");setText(panel,"[data-learning-eta]","calculando velocidade...");const uploadStarted=Date.now();pollTimer=setInterval(poll,1200);const formData=new FormData();formData.append("action","import_whatsapp_learning_zip");formData.append("csrf_token",csrf(scope));formData.append("learning_job_id",jobId);formData.append("learning_zip",file,file.name);formData.append("attendant_names",panel.querySelector("[data-learning-attendants]")?.value||"");const xhr=new XMLHttpRequest();xhr.open("POST",window.location.pathname+window.location.search,true);xhr.setRequestHeader("X-Requested-With","XMLHttpRequest");xhr.setRequestHeader("Accept","application/json");xhr.upload.onprogress=function(ev){if(!ev.lengthComputable)return;const ratio=ev.loaded/ev.total;const elapsed=Math.max(.1,(Date.now()-uploadStarted)/1000);const rate=ev.loaded/elapsed;const eta=rate>0?(ev.total-ev.loaded)/rate:null;setVisual(panel,Math.max(1,Math.round(ratio*9)),"Enviando ZIP","Upload real: "+Math.round(ratio*100)+"% ("+(ev.loaded/1024/1024).toFixed(1)+" de "+(ev.total/1024/1024).toFixed(1)+" MB).");setText(panel,"[data-learning-elapsed]",duration(elapsed));setText(panel,"[data-learning-eta]",eta===null?"calculando...":"aprox. "+duration(eta));};xhr.upload.onload=function(){uploadActive=false;setVisual(panel,10,"Upload recebido","Aguardando o servidor validar o arquivo.");};xhr.onload=function(){uploadActive=false;stopPoll();let data=null;try{data=JSON.parse(xhr.responseText);}catch(error){}if(xhr.status<200||xhr.status>=300||!data||!data.ok){setVisual(panel,Number(panel.querySelector("[data-learning-percent]")?.textContent?.replace("%","")||10),"Importação interrompida",(data&&data.error)||"Não foi possível processar o ZIP.");button.disabled=false;alert((data&&data.error)||"Não foi possível processar o ZIP.");return;}setVisual(panel,100,"Aprendizado concluído",data.summary||"Aprendizado salvo.");setText(panel,"[data-learning-eta]","concluído");setText(panel,"[data-learning-status]","ZIP e mídias temporárias apagados.");setText(panel,"[data-learning-last]",data.summary||"Importação concluída.");document.querySelectorAll("input[name=ai_team_playbook_enabled]").forEach(input=>input.checked=true);appendImport(data);if(input)input.value="";button.disabled=false;};xhr.onerror=function(){uploadActive=false;setVisual(panel,10,"Conexão interrompida","Consultando o servidor para saber se o processamento continua.");button.disabled=false;poll();};xhr.send(formData);}catch(error){stopPoll();button.disabled=false;setVisual(panel,0,"Não foi possível iniciar",error.message||"Falha ao iniciar importação.");alert(error.message||"Falha ao iniciar importação.");}});})();</script>';
        echo '<script>(function(){const listSelector="[data-learning-list]";const cardSelector="[data-learning-card]";function refresh(){document.querySelectorAll("[data-learning-library]").forEach(function(library){const list=library.querySelector(listSelector);if(!list)return;const cards=list.querySelectorAll(":scope > "+cardSelector);library.querySelectorAll("[data-learning-count]").forEach(function(count){if(count.textContent!==String(cards.length))count.textContent=String(cards.length);});const empty=list.querySelector("[data-learning-empty]");if(cards.length&&empty)empty.remove();if(!cards.length&&!empty){const notice=document.createElement("div");notice.className="settings-howto";notice.dataset.learningEmpty="";notice.innerHTML="<span class=muted>Nenhuma conversa importada no novo histórico.</span>";list.appendChild(notice);}});}function cardsIn(node){if(!(node instanceof Element))return[];return[...(node.matches(cardSelector)?[node]:[]),...node.querySelectorAll(cardSelector)];}const observer=new MutationObserver(function(mutations){const added=[];const removedIds=new Set();mutations.forEach(function(mutation){mutation.addedNodes.forEach(function(node){added.push(...cardsIn(node));});if(mutation.target instanceof Element&&mutation.target.matches(listSelector)){mutation.removedNodes.forEach(function(node){cardsIn(node).forEach(function(card){if(card.dataset.learningCard)removedIds.add(card.dataset.learningCard);});});}});removedIds.forEach(function(id){document.querySelectorAll(cardSelector+"[data-learning-card=\""+id+"\"]").forEach(function(card){card.remove();});});added.forEach(function(card){const id=card.dataset.learningCard;if(!id)return;document.querySelectorAll(listSelector).forEach(function(list){if(!list.querySelector(cardSelector+"[data-learning-card=\""+id+"\"]"))list.prepend(card.cloneNode(true));});});refresh();});observer.observe(document.body,{childList:true,subtree:true});refresh();})();</script>';
        echo '</div>';
        echo '<div class="settings-save-row"><div class="muted">Salva provedor, chaves, modelos, visão, documentos, vídeo, automações e voz da IA.</div><button class="btn" type="button" data-settings-submit>Salvar inteligência artificial</button></div>';
        echo '</div></div>';
        echo '<div id="settingsSourceMetaAds" hidden><div class="settings-panel" id="settings-meta-ads" data-settings-panel="meta_ads">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Meta Ads</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="panel soft">';
        echo '<h3 style="margin-top:0">Integração com a API da Meta</h3>';
        echo '<p class="muted">Use este bloco para guardar o que o CRM precisa para falar com a Marketing API e organizar campanhas, públicos, anúncios e métricas.</p>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Integração Meta Ads ativa</label><label class="checkline"><input type="checkbox" name="meta_ads_enabled" value="1" ' . (!empty($settings['meta_ads_enabled']) ? 'checked' : '') . '> Liberar a página e os testes da API</label></div>';
        echo '<div class="field"><label>ID da conta de anúncio</label><input name="meta_ads_ad_account_id" value="' . h($settings['meta_ads_ad_account_id'] ?? '') . '" placeholder="act_1234567890"><small class="muted">Você pode colar com ou sem `act_`. O sistema normaliza depois.</small></div>';
        echo '</div></div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>ID do App Meta</label><input name="meta_ads_app_id" value="' . h($settings['meta_ads_app_id'] ?? '') . '" placeholder="123456789012345"><small class="muted">Aparece no painel do app em developers.facebook.com.</small></div>';
        echo '<div class="field"><label>Secret do App Meta</label><input name="meta_ads_app_secret" type="password" value="" placeholder="App Secret"><small class="muted">Guardado no banco, mas nunca exibido inteiro. Atual: ' . h(studio_meta_ads_mask_secret((string)($settings['meta_ads_app_secret'] ?? ''))) . '</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Access Token</label><input name="meta_ads_access_token" type="password" value="" placeholder="EAAB..."><small class="muted">Normalmente um token de System User com permissões de anúncios. Atual: ' . h(studio_meta_ads_mask_secret((string)($settings['meta_ads_access_token'] ?? ''))) . '</small></div>';
        echo '<div class="field"><label>ID do Business Manager</label><input name="meta_ads_business_id" value="' . h($settings['meta_ads_business_id'] ?? '') . '" placeholder="123456789012345"><small class="muted">Ajuda a conferir a origem dos ativos e as permissões corretas.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>ID do Pixel</label><input name="meta_ads_pixel_id" value="' . h($settings['meta_ads_pixel_id'] ?? '') . '" placeholder="123456789012345"><small class="muted">Opcional, mas muito útil para eventos e acompanhamento de conversões.</small></div>';
        echo '<div class="field"><label>ID do formulário de leads</label><input name="meta_ads_lead_form_id" value="' . h($settings['meta_ads_lead_form_id'] ?? '') . '" placeholder="123456789012345"><small class="muted">Opcional. Use só se você for rodar campanhas de Lead Ads com formulário nativo da Meta. Para campanhas de WhatsApp, pode deixar vazio.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Versão da API</label><input name="meta_ads_api_version" value="' . h($settings['meta_ads_api_version'] ?? 'v22.0') . '" placeholder="v22.0"><small class="muted">Use a versão que seu app estiver homologado para usar.</small></div>';
        echo '<div class="field"><label>URL de redirecionamento OAuth</label><input name="meta_ads_redirect_uri" value="https://danieltatuador.com/projetocrm/meta_oauth_callback.php" placeholder="https://danieltatuador.com/projetocrm/meta_oauth_callback.php"><small class="muted">Fixada para o callback limpo da Meta. O botão Conectar usa exatamente essa URL.</small></div>';
        echo '</div>';
        echo '<div class="field"><label>Observações operacionais</label><textarea name="meta_ads_notes" placeholder="Ex.: usamos essa conta para campanhas de fechamento, catálogo e remarketing. A conta fica em nome do business X.">' . h($settings['meta_ads_notes'] ?? '') . '</textarea><small class="muted">Isso não vai para a Meta. Fica só como documentação interna do estúdio.</small></div>';
        echo '<div class="actions" style="justify-content:flex-end;margin-top:12px"><button class="btn" type="button" data-settings-submit>Salvar configurações</button></div>';
        echo '</div>';
        echo '<div class="panel soft" style="margin-top:16px">';
        echo '<h3 style="margin-top:0">O que essa página vai mostrar</h3>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><strong>Campanhas</strong><p class="muted">Listagem, status, objetivo e orçamento das campanhas ativas.</p></div>';
        echo '<div class="field"><strong>Conjuntos e anúncios</strong><p class="muted">Visão por conjunto de anúncios, criativos e status de entrega.</p></div>';
        echo '<div class="field"><strong>Leads e formulários</strong><p class="muted">Opcional. Só entra em cena se você usar formulário nativo da Meta; para WhatsApp, pode ignorar.</p></div>';
        echo '<div class="field"><strong>Relatórios</strong><p class="muted">Métricas como impressões, cliques, CPC, CPM, CTR e gasto.</p></div>';
        echo '<div class="field"><strong>Públicos</strong><p class="muted">Reuso de públicos personalizados e lookalikes quando disponível.</p></div>';
        echo '<div class="field"><strong>Tokens e diagnóstico</strong><p class="muted">Checagem de token, permissões e ligação com a conta de anúncio.</p></div>';
        echo '</div>';
        echo '</div></div>';
        $metaBalanceAlertEnabled = array_key_exists('meta_balance_alert_enabled', $settings) ? !empty($settings['meta_balance_alert_enabled']) : true;
        $metaBalanceAlertThreshold = (float)money_to_float((string)($settings['meta_balance_alert_threshold'] ?? '20'));
        if ($metaBalanceAlertThreshold <= 0) {
            $metaBalanceAlertThreshold = 20.0;
        }
        $metaBalanceAlertMessage = trim((string)($settings['meta_balance_alert_message'] ?? ''));
        if ($metaBalanceAlertMessage === '') {
            $metaBalanceAlertMessage = studio_meta_balance_alert_default_message();
        }
        echo '<div id="settingsSourceAlerts" hidden><div class="settings-panel" id="settings-alerts" data-settings-panel="alerts">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><div><h3 style="margin:0">Alertas operacionais</h3><p class="muted" style="margin:4px 0 0">Configure avisos enviados pelo WhatsApp oficial para acompanhar o saldo da conta de anúncios.</p></div><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="panel soft" style="margin-top:14px;border-color:#cfe5dc"><div class="actions" style="justify-content:space-between;align-items:flex-start;gap:16px"><div><strong>Saldo disponível do Meta Ads</strong><p class="muted" style="margin:6px 0 0">O CRM usa o crédito identificado pela Meta como “Saldo disponível”. O gasto de hoje e o gasto acumulado não entram nesta comparação.</p></div><label class="switch"><input type="checkbox" name="meta_balance_alert_enabled" value="1" ' . ($metaBalanceAlertEnabled ? 'checked' : '') . '><span>Ativar alerta</span></label></div>';
        echo '<div class="grid cols-2" style="margin-top:14px">';
        echo '<div class="field"><label>Alertar quando o saldo for menor ou igual a</label><input name="meta_balance_alert_threshold" type="number" min="0.01" step="0.01" value="' . h(number_format($metaBalanceAlertThreshold, 2, '.', '')) . '"><small class="muted">Exemplo: R$ 20,00. O aviso dispara novamente depois que o saldo sair do limite e voltar a cair.</small></div>';
        echo '<div class="field"><label>Celular que recebe o aviso</label><input name="meta_balance_alert_phone" inputmode="tel" value="' . h((string)($settings['meta_balance_alert_phone'] ?? '5511947573311')) . '" placeholder="5511999999999"><small class="muted">Use o formato internacional, somente números. O número atual foi preservado como padrão.</small></div>';
        echo '</div>';
        echo '<div class="field"><label>Mensagem do alerta</label><textarea name="meta_balance_alert_message" rows="6" placeholder="Mensagem enviada quando o saldo entrar no limite">' . h($metaBalanceAlertMessage) . '</textarea><small class="muted">Variáveis disponíveis: <code>{{account_name}}</code>, <code>{{balance}}</code>, <code>{{threshold}}</code> e <code>{{currency}}</code>.</small></div>';
        echo '<div class="settings-howto" style="margin-top:12px"><strong>Como a regra funciona</strong><p class="muted" style="margin:6px 0 0">O alerta é enviado uma vez quando a conta entra no limite. Ao voltar acima dele, a regra é armada novamente para uma próxima queda. Se você desativar o alerta ou apagar o telefone, nada será enviado.</p></div>';
        echo '<div class="actions" style="justify-content:flex-end;margin-top:12px"><button class="btn" type="button" data-settings-submit>Salvar alertas</button></div>';
        echo '</div></div>';
        echo '<div id="settingsSourceRules" hidden><div class="settings-panel" id="settings-rules" data-settings-panel="rules">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><div><h3 style="margin:0">Treinamento da IA</h3><p class="muted" style="margin:4px 0 0">Ensine uma vez; o chatbot consulta este conteúdo em todas as conversas.</p></div><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="panel soft" style="margin-top:14px"><strong>O que colocar aqui</strong><p class="muted" style="margin:6px 0 0">Preços, promoções com validade, regras de sinal, estilos atendidos, endereço, formas de pagamento, políticas de retoque e exemplos de respostas corretas. Quando algo mudar, basta editar e salvar.</p></div>';
        echo '<div class="panel soft" style="margin-top:14px"><strong>Aprendizado com atendentes ativo</strong><p class="muted" style="margin:6px 0 0">A IA procura respostas humanas reais em casos parecidos e usa apenas o jeito de falar e conduzir. Mensagens de teste, links e conversas sem relação com atendimento são ignoradas; fatos como preço, endereço e agenda continuam vindo desta base e do banco.</p></div>';
        $pricingPageSynced = trim((string)($settings['ai_pricing_page_synced_at'] ?? ''));
        $pricingPageSummary = trim((string)($settings['ai_pricing_page_summary'] ?? ''));
        $pricingPageError = trim((string)($settings['ai_pricing_page_error'] ?? ''));
        echo '<div class="panel soft" style="margin-top:14px">';
        echo '<div class="actions" style="justify-content:space-between;align-items:flex-start"><div><strong>Fonte oficial de preços e promoções</strong><p class="muted" style="margin:6px 0 0">Cadastre aqui a página de orçamento deste estúdio. Cada login usa a sua própria URL, então outro estúdio não herda a página do Daniel.</p></div><label class="switch"><input type="checkbox" name="ai_pricing_page_enabled" value="1" ' . (!empty($settings['ai_pricing_page_enabled']) ? 'checked' : '') . '><span>Usar no WhatsApp</span></label></div>';
        echo '<div class="field" style="margin-top:12px"><label>URL da página de orçamento deste estúdio</label><input name="ai_pricing_page_url" value="' . h($settings['ai_pricing_page_url'] ?? '') . '" placeholder="https://seudominio.com/orcamento"><small class="muted">A IA lê essa página como fonte gerenciável de preços, promoções e regras comerciais. Se a página mudar, o sistema relê automaticamente em até algumas horas.</small></div>';
        if ($pricingPageSynced !== '' || $pricingPageSummary !== '' || $pricingPageError !== '') {
            echo '<div class="panel" style="margin-top:12px"><strong>Status da leitura</strong><p class="muted" style="margin:6px 0 0">Última leitura: ' . h($pricingPageSynced !== '' ? $pricingPageSynced : 'ainda não lida') . '</p>';
            if ($pricingPageError !== '') {
                echo '<p class="muted" style="margin:6px 0 0;color:#b45309">Aviso: ' . h($pricingPageError) . '</p>';
            }
            if ($pricingPageSummary !== '') {
                echo '<p class="muted" style="margin:8px 0 0">' . h(mb_substr($pricingPageSummary, 0, 520, 'UTF-8')) . (mb_strlen($pricingPageSummary, 'UTF-8') > 520 ? '...' : '') . '</p>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<div class="actions" style="margin:14px 0 8px"><button class="btn tiny secondary" type="button" data-ai-knowledge-template>Inserir modelo organizado</button><span class="muted" data-ai-rules-count>0 caracteres</span></div>';
        echo '<div class="field"><label>Base de conhecimento do estúdio</label><textarea name="business_rules" rows="20" style="min-height:420px" placeholder="Exemplo:\n\n[PREÇOS]\nO valor mínimo é R$ ...\n\n[PROMOÇÕES]\nAté 31/07, fechamento de costas ...\n\n[ATENDIMENTO]\n...\n\n[COMO RESPONDER]\nQuando o cliente enviar uma referência e informar o local, ...">' . h($settings['business_rules'] ?? $studio['business_rules'] ?? '') . '</textarea><small class="muted">Use frases diretas. Inclua datas nas promoções e escreva exatamente como espera que a IA responda. As informações da agenda em tempo real continuam vindo do banco de dados.</small></div>';
        echo '<details class="panel soft" style="margin-top:14px"><summary><strong>Personalidade avançada da IA</strong></summary><div class="field" style="margin-top:12px"><label>Texto-base do WhatsApp</label><textarea name="ai_whatsapp_prompt" placeholder="Você é o assistente do estúdio...">' . h($settings['ai_whatsapp_prompt'] ?? '') . '</textarea><small class="muted">Opcional. Se ficar vazio, o sistema usa a personalidade padrão em português. As informações comerciais devem ficar na base acima.</small></div></details>';
        echo '<div class="actions" style="justify-content:flex-end;margin-top:14px"><button class="btn" type="button" data-settings-submit>Salvar treinamento da IA</button></div>';
        echo '</div></div>';
        echo '<div id="settingsSourceQuickReplies" hidden><div class="settings-panel" id="settings-quick-replies" data-settings-panel="quick_replies">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Respostas rápidas</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="actions" style="justify-content:space-between"><div><p class="muted">As respostas rápidas continuam disponíveis na biblioteca dedicada. Nesta tela deixamos só o acesso e a visualização para não atrapalhar o salvamento principal.</p></div><a class="btn secondary" href="' . h(app_url('studio_quick_replies')) . '">Abrir biblioteca</a></div>';
        $replies = array_slice(studio_list_quick_replies($studio), 0, 8);
        echo '<div class="panel"><h3 style="margin-top:0">Biblioteca atual</h3>';
        if (!$replies) {
            echo '<p class="muted" style="margin:0">Nenhuma resposta rápida cadastrada ainda.</p>';
        } else {
            echo '<div class="stack-list">';
            foreach ($replies as $reply) {
                echo '<div class="stack-item"><div><strong>' . h((string)($reply['title'] ?? 'Resposta rápida')) . '</strong><small>' . h((string)($reply['shortcut'] ?? 'Sem atalho')) . ' · ' . h((string)($reply['category'] ?? 'Geral')) . '</small></div><span class="badge">' . h(!empty($reply['is_active']) ? 'Ativa' : 'Inativa') . '</span></div>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '</div></div>';
        echo '<div class="actions" style="justify-content:space-between;align-items:center;margin-top:12px"><span class="muted">Salvar continua aplicando as regras no banco do estudio.</span><button class="btn" type="submit">Salvar configurações</button></div>';
        echo '</form>';
        echo <<<'HTML'
<script>
(function(){
  const modal = document.getElementById("settingsOverlay");
  const body = document.getElementById("settingsOverlayBody");
  const title = document.getElementById("settingsOverlayTitle");
  const summary = document.getElementById("settingsOverlaySummary");
  const closeBtn = document.getElementById("closeSettingsOverlay");
  const baseForm = document.getElementById("studioSettingsForm");
  const knowledgeTemplate = `[SOBRE O ESTÚDIO]
Nome, endereço e região atendida:
Estilos e tipos de trabalho realizados:
O que não fazemos:

[PREÇOS E ORÇAMENTO]
Valor mínimo:
Como o orçamento é calculado:
Formas de pagamento:
Valor e regra do sinal:

[PROMOÇÕES]
Nome da promoção:
Regra e valor:
Data de início e fim:
Quem pode usar:

[AGENDA E ATENDIMENTO]
Dias e horários de atendimento:
Prazo médio para responder orçamento:
Quando encaminhar para uma pessoa:

[POLÍTICAS]
Retoque:
Cancelamento e remarcação:
Menores de idade:

[COMO A IA DEVE RESPONDER]
Tom de voz:
Informações que deve perguntar:
O que nunca deve dizer:
Exemplo de pergunta do cliente -> resposta correta:`;
  const sourceMap = {
    studio: {title:"Estúdio", summary:"Dados base e integração", source:"settingsSourceStudio"},
    agenda: {title:"Agenda", summary:"Regras de horário e duração", source:"settingsSourceAgenda"},
    whatsapp: {title:"WhatsApp", summary:"Entrada e comportamento", source:"settingsSourceWhatsapp"},
    ia: {title:"IA", summary:"Modelo, chave e automação", source:"settingsSourceIa"},
    meta_ads: {title:"Meta Ads", summary:"Credenciais, IDs e diagnóstico", source:"settingsSourceMetaAds"},
    alerts: {title:"Alertas", summary:"Saldo, limite, mensagem e telefone", source:"settingsSourceAlerts"},
    quick_replies: {title:"Respostas rápidas", summary:"Biblioteca do atendimento", source:"settingsSourceQuickReplies"},
    rules: {title:"Treinamento da IA", summary:"Base usada em todas as conversas", source:"settingsSourceRules"}
  };
  function updateRulesCount(scope=document) {
    const field = scope.querySelector('textarea[name="business_rules"]');
    const counter = scope.querySelector("[data-ai-rules-count]");
    if (field && counter) counter.textContent = `${field.value.length} caracteres`;
  }
  function wrapOverlayForm(panelKey) {
    if (!body || !baseForm) return;
    const csrfToken = baseForm.querySelector('input[name="csrf_token"]')?.value || "";
    const wrapper = document.createElement("form");
    wrapper.id = "settingsOverlayForm";
    wrapper.method = "post";
    wrapper.action = baseForm.getAttribute("action") || location.href;
    const current = body.innerHTML;
    body.innerHTML = "";
    wrapper.innerHTML = `<input type="hidden" name="action" value="save_studio_settings"><input type="hidden" name="settings_tab" value="${panelKey}"><input type="hidden" name="csrf_token" value="${csrfToken.replace(/"/g, "&quot;")}">${current}`;
    wrapper.querySelectorAll("[data-settings-submit]").forEach((button) => {
      button.type = "submit";
      button.removeAttribute("data-settings-submit");
    });
    body.appendChild(wrapper);
  }
  function openOverlay(key) {
    const config = sourceMap[key] || sourceMap.studio;
    const source = document.getElementById(config.source);
    if (!modal || !body || !title || !summary || !source) return;
    title.textContent = config.title;
    summary.textContent = config.summary;
    body.innerHTML = source.innerHTML;
    wrapOverlayForm(key);
    modal.classList.remove("hidden");
    updateRulesCount(body);
  }
  document.querySelectorAll("[data-settings-overlay]").forEach((button) => {
    button.addEventListener("click", () => openOverlay(button.getAttribute("data-settings-overlay") || "studio"));
  });
  document.addEventListener("input", (event) => {
    if (event.target instanceof Element && event.target.matches('textarea[name="business_rules"]')) {
      updateRulesCount(event.target.closest(".settings-panel") || document);
    }
  });
  document.addEventListener("click", (event) => {
    const templateButton = event.target instanceof Element ? event.target.closest("[data-ai-knowledge-template]") : null;
    if (!templateButton) return;
    event.preventDefault();
    const panel = templateButton.closest(".settings-panel") || document;
    const field = panel.querySelector('textarea[name="business_rules"]');
    if (field && (!field.value.trim() || window.confirm("Adicionar o modelo abaixo do conteúdo atual?"))) {
      field.value = field.value.trim() ? `${field.value.trim()}\n\n${knowledgeTemplate}` : knowledgeTemplate;
      field.focus();
      updateRulesCount(panel);
    }
  });
  if (closeBtn) closeBtn.addEventListener("click", () => modal.classList.add("hidden"));
  if (modal) modal.addEventListener("click", (event) => { if (event.target === modal) modal.classList.add("hidden"); });
  document.addEventListener("keydown", (event) => { if (event.key === "Escape" && modal) modal.classList.add("hidden"); });
})();
</script>
HTML;
        echo '<script>(function(){ const activeTab = ' . json_encode($activeTab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; const tabs = document.querySelectorAll("[data-settings-tab]"); const hiddenTab = document.querySelector("#studioSettingsForm [name=settings_tab]"); const targetMap = { studio: "settings-studio", agenda: "settings-agenda", whatsapp: "settings-whatsapp", ia: "settings-ia", meta_ads: "settings-meta-ads", alerts: "settings-alerts", quick_replies: "settings-quick-replies", rules: "settings-rules" }; tabs.forEach(btn => { const selected = btn.dataset.settingsTab === activeTab; btn.classList.toggle("active", selected); btn.setAttribute("aria-selected", selected ? "true" : "false"); const key = btn.dataset.settingsTab || "studio"; const target = targetMap[key] || "settings-studio"; btn.setAttribute("href", "index.php?page=studio_settings&tab=" + encodeURIComponent(key) + "#" + target); }); if (hiddenTab) hiddenTab.value = activeTab; if (window.location.hash) { const target = document.querySelector(window.location.hash); if (target) { setTimeout(() => target.scrollIntoView({ behavior: "smooth", block: "start" }), 80); } } })();</script>';
    }, $flash);
    exit;
}

if ($page === 'studio_meta_ads') {
    $studio = require_studio();
    render_studio_shell('Meta Ads', 'Uma visão limpa da conta, com camadas expansíveis para acessar tudo o que a API entrega.', 'meta_ads', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $settings = studio_settings($studio);
        $testResult = null;
        $syncResult = null;
        if (isset($_SESSION['meta_ads_test_result']) && is_array($_SESSION['meta_ads_test_result'])) {
            $testResult = $_SESSION['meta_ads_test_result'];
            unset($_SESSION['meta_ads_test_result']);
        }
        if (isset($_SESSION['meta_ads_sync_result']) && is_array($_SESSION['meta_ads_sync_result'])) {
            $syncResult = $_SESSION['meta_ads_sync_result'];
            unset($_SESSION['meta_ads_sync_result']);
        }
        $oauthResult = null;
        if (isset($_SESSION['meta_ads_oauth_result']) && is_array($_SESSION['meta_ads_oauth_result'])) {
            $oauthResult = $_SESSION['meta_ads_oauth_result'];
            unset($_SESSION['meta_ads_oauth_result']);
        }
        $oauthAccounts = [];
        if (isset($_SESSION['meta_ads_oauth_accounts']) && is_array($_SESSION['meta_ads_oauth_accounts'])) {
            $oauthAccounts = $_SESSION['meta_ads_oauth_accounts'];
        }
        $enabled = !empty($settings['meta_ads_enabled']);
        $apiVersion = trim((string)($settings['meta_ads_api_version'] ?? 'v22.0'));
        $accountId = trim((string)($settings['meta_ads_ad_account_id'] ?? ''));
        $accountId = preg_replace('/^act_/', '', $accountId);
        $leadFormId = preg_replace('/^act_/', '', trim((string)($settings['meta_ads_lead_form_id'] ?? '')));
        $metaAdsAccessToken = trim((string)($settings['meta_ads_access_token'] ?? ''));
        $metaAdsAuthReady = $metaAdsAccessToken !== '' && $accountId !== '';
        $baseGraphUrl = 'https://graph.facebook.com/' . rawurlencode($apiVersion);
        $accountRef = $accountId !== '' ? 'act_' . $accountId : '{ad_account_id}';
        $performanceEnd = trim((string)($_GET['meta_ads_until'] ?? date('Y-m-d')));
        $performanceStart = trim((string)($_GET['meta_ads_since'] ?? date('Y-m-d', strtotime('-29 days'))));
        $performanceStartDate = DateTime::createFromFormat('Y-m-d', $performanceStart) ?: new DateTime('-29 days');
        $performanceEndDate = DateTime::createFromFormat('Y-m-d', $performanceEnd) ?: new DateTime('today');
        if ($performanceStartDate > $performanceEndDate) {
            [$performanceStartDate, $performanceEndDate] = [$performanceEndDate, $performanceStartDate];
        }
        $performanceStart = $performanceStartDate->format('Y-m-d');
        $performanceEnd = $performanceEndDate->format('Y-m-d');
        $performanceTimeRange = json_encode([
            'since' => $performanceStart,
            'until' => $performanceEnd,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $performanceSummary = null;
        $todayPerformanceSummary = null;
        $accountOverview = null;
        $accountOverviewError = null;
        if ($metaAdsAuthReady) {
            try {
                $accountOverviewResponse = studio_meta_ads_request($apiVersion, '/act_' . $accountId, $metaAdsAccessToken, [
                    'fields' => 'id,name,currency,balance,amount_spent,spend_cap,account_status,funding_source_details',
                ]);
                if (!empty($accountOverviewResponse['ok'])) {
                    $accountOverview = is_array($accountOverviewResponse['json'] ?? null) ? $accountOverviewResponse['json'] : null;
                } else {
                    $accountOverviewError = (string)($accountOverviewResponse['error'] ?? 'Não foi possível consultar os dados financeiros da conta.');
                }
                $todayPerformanceResponse = studio_meta_ads_request($apiVersion, '/act_' . $accountId . '/insights', $metaAdsAccessToken, [
                    'fields' => 'spend',
                    'date_preset' => 'today',
                    'limit' => 1,
                ]);
                if (!empty($todayPerformanceResponse['ok'])) {
                    $todayPerformanceRows = is_array($todayPerformanceResponse['json']['data'] ?? null) ? $todayPerformanceResponse['json']['data'] : [];
                    $todayPerformanceRow = is_array($todayPerformanceRows[0] ?? null) ? $todayPerformanceRows[0] : [];
                    $todayPerformanceSummary = [
                        'ok' => true,
                        'spend' => (float)($todayPerformanceRow['spend'] ?? 0),
                    ];
                }
                $apiPerformanceResponse = studio_meta_ads_request($apiVersion, '/act_' . preg_replace('/^act_/', '', trim((string)($settings['meta_ads_ad_account_id'] ?? ''))) . '/insights', trim((string)$settings['meta_ads_access_token']), [
                    'fields' => 'spend,impressions,clicks,ctr,cpc,cpm,reach',
                    'time_range' => $performanceTimeRange,
                    'limit' => 1,
                ]);
                if (!empty($apiPerformanceResponse['ok'])) {
                    $apiPerformanceRows = is_array($apiPerformanceResponse['json']['data'] ?? null) ? $apiPerformanceResponse['json']['data'] : [];
                    $apiPerformanceRow = is_array($apiPerformanceRows[0] ?? null) ? $apiPerformanceRows[0] : [];
                    $performanceSummary = [
                        'ok' => true,
                        'period' => $performanceTimeRange,
                        'since' => $performanceStart,
                        'until' => $performanceEnd,
                        'spend' => (float)($apiPerformanceRow['spend'] ?? 0),
                        'impressions' => (int)($apiPerformanceRow['impressions'] ?? 0),
                        'clicks' => (int)($apiPerformanceRow['clicks'] ?? 0),
                        'ctr' => (float)($apiPerformanceRow['ctr'] ?? 0),
                        'cpc' => (float)($apiPerformanceRow['cpc'] ?? 0),
                        'cpm' => (float)($apiPerformanceRow['cpm'] ?? 0),
                        'reach' => (int)($apiPerformanceRow['reach'] ?? 0),
                    ];
                }
            } catch (Throwable) {
                $performanceSummary = null;
            }
        }
        $campaignsData = null;
        $adsetsData = null;
        $adsData = null;
        $adInsightsByAd = [];
        $audiencesData = null;
        $campaignMetrics = [];
        $leadsData = null;
        $campaignsError = null;
        $adsetsError = null;
        $adsError = null;
        $audiencesError = null;
        $leadsError = null;
        if ($enabled && !$metaAdsAuthReady) {
            $missingParts = [];
            if ($accountId === '') {
                $missingParts[] = 'ID da conta de anúncio';
            }
            if ($metaAdsAccessToken === '') {
                $missingParts[] = 'Access Token';
            }
            $campaignsError = 'Configure ' . implode(' e ', $missingParts) . ' para carregar campanhas desta conta.';
            $adsetsError = $campaignsError;
            $adsError = $campaignsError;
            $audiencesError = $campaignsError;
        } elseif ($metaAdsAuthReady) {
            $campaignsResponse = studio_meta_ads_request($apiVersion, '/act_' . $accountId . '/campaigns', $metaAdsAccessToken, [
                'fields' => 'id,name,status,objective,created_time,updated_time,buying_type,effective_status',
                'limit' => 12,
            ]);
            if (!empty($campaignsResponse['ok'])) {
                $campaignsData = is_array($campaignsResponse['json']['data'] ?? null) ? $campaignsResponse['json']['data'] : [];
                foreach ($campaignsData as $campaignRow) {
                    if (!is_array($campaignRow)) {
                        continue;
                    }
                    $campaignId = trim((string)($campaignRow['id'] ?? ''));
                    if ($campaignId === '') {
                        continue;
                    }
                    $campaignInsight = studio_meta_ads_request($apiVersion, '/' . $campaignId . '/insights', $metaAdsAccessToken, [
                        'fields' => 'spend,impressions,clicks,ctr,cpc,cpm,reach',
                        'time_range' => $performanceTimeRange,
                        'limit' => 1,
                    ]);
                    if (!empty($campaignInsight['ok'])) {
                        $items = is_array($campaignInsight['json']['data'] ?? null) ? $campaignInsight['json']['data'] : [];
                        $row = $items[0] ?? [];
                        $campaignMetrics[$campaignId] = [
                            'spend' => (float)($row['spend'] ?? 0),
                            'impressions' => (int)($row['impressions'] ?? 0),
                            'clicks' => (int)($row['clicks'] ?? 0),
                            'ctr' => (float)($row['ctr'] ?? 0),
                            'cpc' => (float)($row['cpc'] ?? 0),
                            'cpm' => (float)($row['cpm'] ?? 0),
                            'reach' => (int)($row['reach'] ?? 0),
                        ];
                    }
                }
            } else {
                $campaignsError = (string)($campaignsResponse['error'] ?? 'Erro ao carregar campanhas.');
            }
            $adsetsResponse = studio_meta_ads_request($apiVersion, '/act_' . $accountId . '/adsets', $metaAdsAccessToken, [
                'fields' => 'id,name,status,effective_status,campaign_id,optimization_goal,billing_event,created_time,updated_time',
                'limit' => 12,
            ]);
            if (!empty($adsetsResponse['ok'])) {
                $adsetsData = is_array($adsetsResponse['json']['data'] ?? null) ? $adsetsResponse['json']['data'] : [];
            } else {
                $adsetsError = (string)($adsetsResponse['error'] ?? 'Erro ao carregar conjuntos de anúncios.');
            }
            $adsResponse = studio_meta_ads_request($apiVersion, '/act_' . $accountId . '/ads', $metaAdsAccessToken, [
                'fields' => 'id,name,status,effective_status,adset_id,campaign_id,created_time,updated_time',
                'limit' => 12,
            ]);
            if (!empty($adsResponse['ok'])) {
                $adsData = is_array($adsResponse['json']['data'] ?? null) ? $adsResponse['json']['data'] : [];
                foreach ($adsData as $adRow) {
                    if (!is_array($adRow)) {
                        continue;
                    }
                    $adId = trim((string)($adRow['id'] ?? ''));
                    if ($adId === '') {
                        continue;
                    }
                    $adInsightsResponse = studio_meta_ads_request($apiVersion, '/' . $adId . '/insights', $metaAdsAccessToken, [
                        'fields' => 'spend,impressions,clicks,ctr,cpc,cpm,reach,frequency,inline_link_clicks,outbound_clicks,unique_clicks,unique_inline_link_clicks,unique_outbound_clicks,actions',
                        'time_range' => $performanceTimeRange,
                        'limit' => 1,
                    ]);
                    if (!empty($adInsightsResponse['ok'])) {
                        $adInsightRows = is_array($adInsightsResponse['json']['data'] ?? null) ? $adInsightsResponse['json']['data'] : [];
                        if (!empty($adInsightRows[0]) && is_array($adInsightRows[0])) {
                            $adInsightsByAd[$adId] = $adInsightRows[0];
                        }
                    }
                }
            } else {
                $adsError = (string)($adsResponse['error'] ?? 'Erro ao carregar anuncios.');
            }
            $audiencesResponse = studio_meta_ads_request($apiVersion, '/act_' . $accountId . '/customaudiences', $metaAdsAccessToken, [
                'fields' => 'id,name,description,operation_status,subtype,delivery_status,lookalike_spec',
                'limit' => 12,
            ]);
            if (!empty($audiencesResponse['ok'])) {
                $audiencesData = is_array($audiencesResponse['json']['data'] ?? null) ? $audiencesResponse['json']['data'] : [];
            } else {
                $audiencesError = (string)($audiencesResponse['error'] ?? 'Erro ao carregar públicos.');
            }
        }
        if ($metaAdsAccessToken !== '' && $leadFormId !== '') {
            $leadsResponse = studio_meta_ads_request($apiVersion, '/' . $leadFormId . '/leads', $metaAdsAccessToken, [
                'fields' => 'created_time,field_data,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name',
                'limit' => 12,
            ]);
            if (!empty($leadsResponse['ok'])) {
                $leadsData = is_array($leadsResponse['json']['data'] ?? null) ? $leadsResponse['json']['data'] : [];
            } else {
                $leadsError = (string)($leadsResponse['error'] ?? 'Erro ao carregar leads.');
            }
        }
        $examples = [
            ['title' => 'Campanhas', 'method' => 'GET', 'path' => '/' . $accountRef . '/campaigns', 'description' => 'Lista campanhas, status e objetivo.'],
            ['title' => 'Estrutura da conta', 'method' => 'GET', 'path' => '/' . $accountRef . '/adsets', 'description' => 'Mostra a estrutura vinculada à conta.'],
            ['title' => 'Anúncios', 'method' => 'GET', 'path' => '/' . $accountRef . '/ads', 'description' => 'Lista anúncios, criativos e status de entrega.'],
            ['title' => 'Insights', 'method' => 'GET', 'path' => '/' . $accountRef . '/insights?fields=impressions,clicks,spend,cpm,cpc,ctr,reach', 'description' => 'Retorna métricas para relatório e BI.'],
            ['title' => 'Lead Ads', 'method' => 'GET', 'path' => '/{lead_form_id}/leads', 'description' => 'Consome leads captados por formulário.'],
            ['title' => 'Públicos', 'method' => 'GET', 'path' => '/act_' . ($accountId !== '' ? $accountId : '{ad_account_id}') . '/customaudiences', 'description' => 'Consulta públicos personalizados disponíveis.'],
        ];
        $metaObjectiveLabel = static function (string $objective): string {
            $key = strtoupper(trim($objective));
            $labels = [
                'OUTCOME_LEADS' => 'Gerar leads',
                'LEAD_GENERATION' => 'Gerar leads',
                'OUTCOME_ENGAGEMENT' => 'Engajamento/mensagens',
                'MESSAGES' => 'Mensagens',
                'OUTCOME_TRAFFIC' => 'Levar pessoas ao site',
                'LINK_CLICKS' => 'Cliques no link',
                'OUTCOME_AWARENESS' => 'Alcance e reconhecimento',
                'BRAND_AWARENESS' => 'Reconhecimento de marca',
                'REACH' => 'Alcance',
                'OUTCOME_SALES' => 'Vendas/conversões',
                'CONVERSIONS' => 'Conversões',
                'POST_ENGAGEMENT' => 'Interações na publicação',
            ];
            return $labels[$key] ?? ($objective !== '' ? str_replace('_', ' ', $objective) : 'Objetivo não informado');
        };
        $metaStatusInfo = static function (string $status): array {
            $key = strtoupper(trim($status));
            $map = [
                'ACTIVE' => ['tone' => 'ok', 'label' => 'Rodando', 'hint' => 'A Meta pode entregar esse item agora.'],
                'PAUSED' => ['tone' => 'warn', 'label' => 'Pausado', 'hint' => 'Não está entregando até ser reativado.'],
                'DELETED' => ['tone' => 'danger', 'label' => 'Excluído', 'hint' => 'Mantido só para histórico.'],
                'ARCHIVED' => ['tone' => 'warn', 'label' => 'Arquivado', 'hint' => 'Fora da operação ativa.'],
                'IN_PROCESS' => ['tone' => 'warn', 'label' => 'Processando', 'hint' => 'Aguardando revisão/processamento.'],
                'WITH_ISSUES' => ['tone' => 'danger', 'label' => 'Com problema', 'hint' => 'Precisa revisão na Meta.'],
            ];
            return $map[$key] ?? ['tone' => 'warn', 'label' => ($status !== '' ? str_replace('_', ' ', $status) : 'Sem status'), 'hint' => 'Status retornado pela Meta.'];
        };
        $metaActionLabel = static function (string $actionType): string {
            $key = strtolower(trim($actionType));
            $labels = [
                'lead' => 'Lead',
                'onsite_conversion.lead_grouped' => 'Lead',
                'onsite_conversion.messaging_conversation_started_7d' => 'Conversas iniciadas',
                'messaging_conversation_started_7d' => 'Conversas iniciadas',
                'link_click' => 'Clique no link',
                'post_engagement' => 'Engajamento',
                'page_engagement' => 'Engajamento na página',
                'landing_page_view' => 'Visualização da página',
                'omni_purchase' => 'Compra',
                'purchase' => 'Compra',
                'video_view' => 'Visualização de vídeo',
            ];
            return $labels[$key] ?? str_replace(['onsite_conversion.', '_'], ['', ' '], $actionType);
        };
        $metaAdReading = static function (array $insights, string $status) use ($metaStatusInfo): array {
            $spend = (float)($insights['spend'] ?? 0);
            $clicks = (int)($insights['clicks'] ?? 0);
            $impressions = (int)($insights['impressions'] ?? 0);
            $reach = (int)($insights['reach'] ?? 0);
            $ctr = (float)($insights['ctr'] ?? 0);
            $cpc = (float)($insights['cpc'] ?? 0);
            $frequency = (float)($insights['frequency'] ?? 0);
            $statusInfo = $metaStatusInfo($status);
            $tone = (string)$statusInfo['tone'];
            $headline = (string)$statusInfo['label'];
            $detail = (string)$statusInfo['hint'];
            $tags = [];

            if (strtoupper($status) === 'ACTIVE' && $spend <= 0 && $impressions <= 0) {
                $tone = 'warn';
                $headline = 'Ativo, mas sem entrega';
                $detail = 'Está ligado, porém não teve impressões no período. Pode ser orçamento, público, aprovação ou data.';
                $tags[] = 'sem entrega';
            } elseif ($spend > 0 && $clicks <= 0) {
                $tone = 'danger';
                $headline = 'Gastou sem gerar clique';
                $detail = 'Teve investimento, mas não trouxe cliques. Vale revisar criativo, chamada e público.';
                $tags[] = 'revisar criativo';
            } elseif ($ctr >= 1.5 && $clicks > 0) {
                $tone = 'ok';
                $headline = 'Boa atração';
                $detail = 'O anúncio está chamando atenção melhor que o mínimo esperado.';
                $tags[] = 'boa taxa de clique';
            } elseif ($impressions >= 100 && $ctr > 0 && $ctr < 0.6) {
                $tone = 'warn';
                $headline = 'Pouca atração';
                $detail = 'Muita gente viu, mas pouca gente clicou. Pode precisar de promessa mais clara ou imagem melhor.';
                $tags[] = 'CTR baixo';
            } elseif ($reach > 0 || $impressions > 0) {
                $headline = 'Entregando';
                $detail = 'Está alcançando pessoas. Use cliques, mensagens e custo para decidir se mantém.';
                $tags[] = 'em entrega';
            }
            if ($frequency >= 3.5) {
                $tone = $tone === 'danger' ? 'danger' : 'warn';
                $tags[] = 'frequência alta';
                $detail .= ' Frequência alta pode indicar saturação do público.';
            }
            if ($cpc > 0) {
                $tags[] = 'CPC ' . format_money($cpc);
            }

            return [
                'tone' => $tone,
                'headline' => $headline,
                'detail' => $detail,
                'tags' => $tags,
            ];
        };
        echo '<div class="meta-ads-page">';
        $activeCampaigns = count(array_filter((array)$campaignsData, static fn($campaign): bool => is_array($campaign) && (string)($campaign['effective_status'] ?? $campaign['status'] ?? '') === 'ACTIVE'));
        $metaCurrency = (string)($accountOverview['currency'] ?? 'BRL');
        $metaMoney = static fn($value): string => $value === null || $value === '' ? '—' : format_money(((float)$value) / 100);
        $metaBalanceValue = $accountOverview['balance'] ?? null;
        $metaBalanceHint = 'Saldo disponível reportado pela Meta';
        $metaFundingDisplay = trim((string)($accountOverview['funding_source_details']['display_string'] ?? ''));
        if ($metaFundingDisplay !== '' && preg_match('/R\$\s*([0-9.]+(?:,[0-9]+)?)/iu', $metaFundingDisplay, $metaFundingMatch)) {
            $metaBalanceValue = money_to_float((string)($metaFundingMatch[1] ?? '')) * 100;
            $metaBalanceHint = 'Saldo disponível; gasto não é saldo';
        }
        $metaExecutiveRead = [];
        if (is_array($performanceSummary) && !empty($performanceSummary['ok'])) {
            $spend = (float)($performanceSummary['spend'] ?? 0);
            $clicks = (int)($performanceSummary['clicks'] ?? 0);
            $reach = (int)($performanceSummary['reach'] ?? 0);
            $ctr = (float)($performanceSummary['ctr'] ?? 0);
            $cpc = (float)($performanceSummary['cpc'] ?? 0);
            if ($spend <= 0) {
                $metaExecutiveRead[] = ['tone' => 'warn', 'title' => 'Sem investimento no período', 'text' => 'A conta não gastou nesse intervalo. Se era para vender/agendar, confira orçamento, datas e campanhas ativas.'];
            } else {
                $metaExecutiveRead[] = ['tone' => 'ok', 'title' => 'Investimento encontrado', 'text' => 'No período selecionado foram investidos ' . format_money($spend) . ', alcançando ' . number_format($reach, 0, ',', '.') . ' pessoas.'];
            }
            if ($spend > 0 && $clicks <= 0) {
                $metaExecutiveRead[] = ['tone' => 'danger', 'title' => 'Gasto sem clique', 'text' => 'Houve gasto, mas nenhum clique registrado. O próximo passo é revisar criativo, público e chamada.'];
            } elseif ($clicks > 0) {
                $metaExecutiveRead[] = ['tone' => $ctr >= 1.5 ? 'ok' : ($ctr < 0.6 ? 'warn' : 'neutral'), 'title' => 'Atração do anúncio', 'text' => 'A conta gerou ' . number_format($clicks, 0, ',', '.') . ' cliques com CTR de ' . number_format($ctr, 2, ',', '.') . '% e CPC médio de ' . format_money($cpc) . '.'];
            }
        } else {
            $metaExecutiveRead[] = ['tone' => 'warn', 'title' => 'Sem leitura de performance', 'text' => 'A conta ainda não retornou métricas do período. Revise conexão ou selecione outro intervalo.'];
        }
        $metaOpenHealth = is_array($testResult) || is_array($syncResult) || is_array($oauthResult) || !empty($oauthAccounts) || (bool)$accountOverviewError;
        $metaOpenPerformance = is_array($performanceSummary) && !empty($performanceSummary['ok']);
        $metaLayerCards = [
            [
                'title' => 'Conta e acesso',
                'lead' => 'Token, conta e permissões que liberam a API.',
                'badge' => $enabled ? 'Ativa' : 'A revisar',
                'items' => [
                    'Conta conectada e liberada no CRM.',
                    'Versão da API e token do System User.',
                    'Saldo, limite e status da conta.',
                ],
            ],
            [
                'title' => 'Estrutura de mídia',
                'lead' => 'Campanhas, conjuntos e anúncios do topo ao detalhe.',
                'badge' => 'Estrutura',
                'items' => [
                    'Campanhas ativas e pausadas.',
                    'Conjuntos e anúncios agrupados por campanha.',
                    'Resumo de gasto, cliques e CTR.',
                ],
            ],
            [
                'title' => 'Públicos e ativos',
                'lead' => 'Tudo que pode ser segmentado ou reutilizado.',
                'badge' => 'Públicos',
                'items' => [
                    'Públicos personalizados carregados na conta.',
                    'Status e tamanho aproximado.',
                    'Base para retargeting e exclusões.',
                ],
            ],
            [
                'title' => 'Leads e automações',
                'lead' => 'Captura, importação e acionamentos internos.',
                'badge' => 'Leads',
                'items' => [
                    'Formulários importados para o CRM.',
                    'Teste de conexão e OAuth.',
                    'Alertas quando a captura falha.',
                ],
            ],
            [
                'title' => 'Insights e diagnóstico',
                'lead' => 'Leitura executiva para saber se a conta está saudável.',
                'badge' => 'Insights',
                'items' => [
                    'Gasto por período e por campanha.',
                    'Sinal de erro e conexão da API.',
                    'Atalhos para período e configuração.',
                ],
            ],
            [
                'title' => 'Configuração e suporte',
                'lead' => 'Atalhos e rota técnica só quando precisar ajustar algo.',
                'badge' => 'Opcional',
                'items' => [
                    'Abrir configurações da Meta Ads.',
                    'Testar conexão e sincronização.',
                    'Consultar a referência técnica escondida.',
                ],
            ],
        ];
        echo '<nav class="meta-section-nav" aria-label="Seções do Meta Ads"><a href="#meta-overview">Visão geral</a><a href="#meta-health">Saúde</a><a href="#meta-performance">Performance</a><a href="#meta-audiences">Públicos</a><a href="#meta-campaigns">Campanhas</a><a href="#meta-endpoints">Endpoints</a><a href="#meta-config">Configuração</a></nav>';
        echo '<section class="panel meta-overview-panel" id="meta-overview">';
        echo '<div class="meta-hero">';
        echo '<div class="meta-hero-copy"><span class="section-eyebrow">Conta de anúncios</span><h2>' . h((string)($accountOverview['name'] ?? 'Visão geral')) . '</h2><p class="muted mb-0">Uma leitura limpa do Meta Ads, mostrando primeiro o que importa e escondendo o resto em camadas fáceis de abrir.</p></div>';
        echo '<div class="meta-hero-actions">';
        echo '<div class="meta-status-stack"><span class="badge ' . ($enabled ? 'ok' : 'warn') . '">' . ($enabled ? 'Integração ativa' : 'Integração desativada') . '</span><span class="badge">' . h($metaCurrency) . '</span><span class="badge">' . h($accountId !== '' ? 'act_' . $accountId : 'conta não definida') . '</span></div>';
        echo '<div class="meta-action-row"><button type="button" class="btn secondary" onclick="toggleMetaAdsSections(true)">Expandir tudo</button><button type="button" class="btn secondary" onclick="toggleMetaAdsSections(false)">Recolher tudo</button><button type="button" class="btn" onclick="var d=document.getElementById(\'metaAdsPerformanceDialog\'); if(d && d.showModal) d.showModal();">Alterar período</button></div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="meta-kpi-grid meta-kpi-grid--primary">';
        foreach ([
            ['Saldo atual', $accountOverview ? $metaMoney($metaBalanceValue) : '—', $metaBalanceHint, 'fa-wallet'],
            ['Gasto hoje', is_array($todayPerformanceSummary) && !empty($todayPerformanceSummary['ok']) ? format_money((float)($todayPerformanceSummary['spend'] ?? 0)) : '—', 'Investimento desde 00h', 'fa-clock'],
            ['Gasto no período', is_array($performanceSummary) && !empty($performanceSummary['ok']) ? format_money((float)($performanceSummary['spend'] ?? 0)) : '—', $performanceStart . ' a ' . $performanceEnd, 'fa-arrow-trend-up'],
            ['Campanhas ativas', (string)$activeCampaigns, count((array)$campaignsData) . ' campanhas carregadas', 'fa-bullhorn'],
        ] as [$label, $value, $hint, $icon]) {
            echo '<div class="meta-kpi-card"><span><i class="fa-solid ' . h($icon) . '"></i>' . h($label) . '</span><strong>' . h($value) . '</strong><small>' . h($hint) . '</small></div>';
        }
        echo '</div>';
        echo '<div class="meta-executive-grid">';
        foreach ($metaExecutiveRead as $readItem) {
            echo '<article class="meta-executive-card ' . h((string)($readItem['tone'] ?? 'neutral')) . '"><span>' . h((string)($readItem['title'] ?? 'Leitura')) . '</span><p>' . h((string)($readItem['text'] ?? '')) . '</p></article>';
        }
        echo '</div>';
        if ($accountOverviewError) {
            echo '<div class="meta-inline-alert"><i class="fa-solid fa-circle-info"></i><span><strong>Dados financeiros indisponíveis</strong>' . h($accountOverviewError) . '</span></div>';
        }
        echo '<div class="meta-layer-grid">';
        foreach ($metaLayerCards as $layer) {
            echo '<details class="panel soft meta-layer-card">';
            echo '<summary><div class="meta-summary-line"><strong>' . h((string)$layer['title']) . '</strong><span class="badge">' . h((string)$layer['badge']) . '</span></div><span class="meta-summary-lead">' . h((string)$layer['lead']) . '</span></summary>';
            echo '<div class="meta-layer-body"><ul class="mb-0">';
            foreach ((array)($layer['items'] ?? []) as $item) {
                echo '<li>' . h((string)$item) . '</li>';
            }
            echo '</ul></div>';
            echo '</details>';
        }
        echo '</div>';
        echo '</section>';
        echo '<section class="meta-detail-stack" aria-label="Detalhes da integração">';
        echo '<details class="panel soft meta-detail-card" id="meta-health"' . ($metaOpenHealth ? ' open' : '') . '>';
        echo '<summary><div class="meta-summary-line"><strong>Saúde da integração</strong><span class="badge">' . h(($metaOpenHealth ? 'há detalhes' : 'fechado')) . '</span></div><span class="meta-summary-lead">Mostra só o que importa para saber se a conta está viva e sincronizando.</span></summary>';
        echo '<div class="meta-detail-body">';
        echo '<div class="grid cols-3">';
        echo '<div class="panel soft"><h3 style="margin-top:0">Conexão</h3><ul class="mb-0"><li>' . h($enabled ? 'Meta Ads ativado no CRM.' : 'Meta Ads ainda desativado no CRM.') . '</li><li>' . h($metaAdsAuthReady ? 'Token e conta respondendo.' : 'Token ou conta faltando.') . '</li><li>' . h($accountOverviewError ? 'A conta respondeu com erro.' : 'A conta respondeu normalmente.') . '</li></ul></div>';
        echo '<div class="panel soft"><h3 style="margin-top:0">Sincronização</h3><ul class="mb-0"><li>' . h(is_array($syncResult) && !empty($syncResult['ok']) ? 'Leads importados com sucesso.' : 'Sincronização ainda não foi executada.') . '</li><li>' . h($leadFormId !== '' ? 'Formulário de leads configurado.' : 'Formulário de leads não informado.') . '</li><li>' . h(is_array($oauthResult) ? 'OAuth já foi concluído nesta sessão.' : 'OAuth ainda pode ser feito.') . '</li></ul></div>';
        echo '<div class="panel soft"><h3 style="margin-top:0">Próximo passo</h3><ul class="mb-0"><li>Confira a campanha principal no bloco abaixo.</li><li>Se faltar algo, abra Configuração.</li><li>Se algo quebrar, rode o teste da integração.</li></ul></div>';
        echo '</div>';
        if (is_array($testResult)) {
            $tone = !empty($testResult['ok']) ? 'ok' : 'danger';
            echo '<div class="panel soft" style="margin-top:16px"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h3 class="mb-1">Resultado do teste</h3><p class="muted mb-0">Verificação ao vivo da API da Meta.</p></div><span class="badge ' . h($tone) . '">' . h(!empty($testResult['ok']) ? 'Conectado' : 'Erro') . '</span></div>';
            if (!empty($testResult['ok'])) {
                echo '<div class="grid cols-2" style="margin-top:12px">';
                echo '<div class="field"><strong>Conta</strong><p class="muted mb-0">' . h((string)($testResult['account']['name'] ?? '')) . ' · ' . h((string)($testResult['account']['id'] ?? '')) . '</p></div>';
                echo '<div class="field"><strong>Status da conta</strong><p class="muted mb-0">' . h((string)($testResult['account']['account_status'] ?? '')) . '</p></div>';
                echo '<div class="field"><strong>Moeda / fuso</strong><p class="muted mb-0">' . h((string)($testResult['account']['currency'] ?? '')) . ' · ' . h((string)($testResult['account']['timezone_name'] ?? '')) . '</p></div>';
                echo '</div>';
                echo '<p class="muted mb-0" style="margin-top:12px">' . h(!empty($testResult['campaigns_ok']) ? 'Conexão validada com campanhas acessíveis.' : 'Conexão validada. As campanhas não vieram nesse teste, mas conta e token responderam.') . '</p>';
            } else {
                echo '<p class="mb-0" style="margin-top:12px"><strong>Erro:</strong> ' . h((string)($testResult['error'] ?? 'Erro desconhecido')) . '</p>';
            }
            echo '</div>';
        }
        if (is_array($syncResult)) {
            $syncTone = !empty($syncResult['ok']) ? 'ok' : 'danger';
            echo '<div class="panel soft" style="margin-top:16px"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h3 class="mb-1">Resultado da sincronização</h3><p class="muted mb-0">Importação dos leads do formulário para o CRM.</p></div><span class="badge ' . h($syncTone) . '">' . h(!empty($syncResult['ok']) ? 'Sincronizado' : 'Erro') . '</span></div>';
            if (!empty($syncResult['ok'])) {
                echo '<div class="grid cols-4" style="margin-top:12px">';
                echo '<div class="field"><strong>Total</strong><p class="muted mb-0">' . h((string)($syncResult['total'] ?? 0)) . '</p></div>';
                echo '<div class="field"><strong>Criados</strong><p class="muted mb-0">' . h((string)($syncResult['leads_created'] ?? 0)) . '</p></div>';
                echo '<div class="field"><strong>Atualizados</strong><p class="muted mb-0">' . h((string)($syncResult['leads_updated'] ?? 0)) . '</p></div>';
                echo '<div class="field"><strong>Novos clientes</strong><p class="muted mb-0">' . h((string)($syncResult['customers_created'] ?? 0)) . '</p></div>';
                echo '</div>';
            } else {
                echo '<p class="mb-0" style="margin-top:12px"><strong>Erro:</strong> ' . h((string)($syncResult['error'] ?? 'Erro desconhecido')) . '</p>';
            }
            echo '</div>';
        }
        if (is_array($oauthResult)) {
            echo '<div class="panel soft" style="margin-top:16px"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h3 class="mb-1">Conexão OAuth concluída</h3><p class="muted mb-0">Token salvo com segurança e contas de anúncio carregadas.</p></div><span class="badge ok">Pronto</span></div>';
            echo '<p class="mb-0 mt-2">Token salvo: ' . h((string)($oauthResult['access_token_tail'] ?? '')) . ' · Contas encontradas: ' . h((string)($oauthResult['accounts_count'] ?? 0)) . '</p>';
            echo '</div>';
        }
        if ($oauthAccounts) {
            $currentAccountSetting = preg_replace('/^act_/', '', trim((string)($settings['meta_ads_ad_account_id'] ?? '')));
            echo '<div class="panel soft" style="margin-top:16px"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h3 class="mb-1">Escolha a conta de anúncio</h3><p class="muted mb-0">Selecione a conta correta retornada pela Meta e salve no CRM.</p></div><span class="badge">' . h((string)count($oauthAccounts)) . ' contas</span></div>';
            echo '<form method="post" class="mt-3">' . csrf_field() . '<input type="hidden" name="action" value="select_meta_ads_account"><div class="grid cols-2"><div class="field"><label>Conta encontrada</label><select name="meta_ads_selected_account">';
            foreach ($oauthAccounts as $account) {
                $accountId = (string)($account['id'] ?? '');
                $accountName = trim((string)($account['name'] ?? $accountId));
                $selected = (preg_replace('/^act_/', '', $accountId) === '875946594343063' || preg_replace('/^act_/', '', $accountId) === $currentAccountSetting) ? ' selected' : '';
                echo '<option value="' . h($accountId) . '"' . $selected . '>' . h($accountName . ' · ' . $accountId) . '</option>';
            }
            echo '</select><small class="muted">A conta correta já vem pré-selecionada quando existe na lista.</small></div><div class="field"><label>Ação</label><button class="btn" type="submit">Salvar conta selecionada</button></div></div></form></div>';
        }
        echo '<div class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel soft"><h3 style="margin-top:0">Resumo técnico</h3><ul class="list-unstyled mb-0">';
        echo '<li class="mb-2"><strong>Base Graph:</strong> ' . h($baseGraphUrl) . '</li>';
        echo '<li class="mb-2"><strong>Conta:</strong> ' . h($accountId !== '' ? 'act_' . $accountId : 'não configurada') . '</li>';
        echo '<li class="mb-2"><strong>Ativação:</strong> ' . h($enabled ? 'Liberada' : 'Ainda não liberada') . '</li>';
        echo '<li class="mb-2"><strong>Fluxo sugerido:</strong> token de System User, conta de anúncio, pixel e, se usar leads, formulário.</li>';
        echo '</ul></div>';
        echo '<div class="panel soft"><h3 style="margin-top:0">Checklist de teste</h3><ol class="mb-0">';
        echo '<li>Preencher os campos de acesso em Configurações.</li>';
        echo '<li>Confirmar que o app Meta tem o produto Marketing API habilitado.</li>';
        echo '<li>Gerar um token válido com as permissões corretas.</li>';
        echo '<li>Validar a conta de anúncio e os ativos associados.</li>';
        echo '</ol></div>';
        echo '</div>';
        echo '</div></details>';
        echo '<details class="panel soft meta-detail-card" id="meta-performance"' . ($metaOpenPerformance ? ' open' : '') . '>';
        echo '<summary><div class="meta-summary-line"><strong>Performance e período</strong><span class="badge">' . h($performanceStart . ' → ' . $performanceEnd) . '</span></div><span class="meta-summary-lead">Resumo executivo do período selecionado e do que a conta entregou até aqui.</span></summary>';
        echo '<div class="meta-detail-body">';
        echo '<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h3 class="mb-1">Leitura executiva</h3><p class="muted mb-0">Resumo de ' . h((string)($performanceSummary['since'] ?? $performanceStart)) . ' até ' . h((string)($performanceSummary['until'] ?? $performanceEnd)) . ' da conta conectada.</p></div><span class="badge ok">Leitura executiva</span></div>';
        if (is_array($performanceSummary) && !empty($performanceSummary['ok'])) {
            echo '<div class="grid cols-4" style="margin-top:12px">';
            echo '<div class="field"><strong>Gasto</strong><p class="muted mb-0">' . h(format_money((float)($performanceSummary['spend'] ?? 0))) . '</p></div>';
            echo '<div class="field"><strong>Impressões</strong><p class="muted mb-0">' . h(number_format((int)($performanceSummary['impressions'] ?? 0), 0, ',', '.')) . '</p></div>';
            echo '<div class="field"><strong>Cliques</strong><p class="muted mb-0">' . h(number_format((int)($performanceSummary['clicks'] ?? 0), 0, ',', '.')) . '</p></div>';
            echo '<div class="field"><strong>CTR</strong><p class="muted mb-0">' . h(number_format((float)($performanceSummary['ctr'] ?? 0), 2, ',', '.')) . '%</p></div>';
            echo '<div class="field"><strong>CPC</strong><p class="muted mb-0">' . h(format_money((float)($performanceSummary['cpc'] ?? 0))) . '</p></div>';
            echo '<div class="field"><strong>CPM</strong><p class="muted mb-0">' . h(format_money((float)($performanceSummary['cpm'] ?? 0))) . '</p></div>';
            echo '<div class="field"><strong>Alcance</strong><p class="muted mb-0">' . h(number_format((int)($performanceSummary['reach'] ?? 0), 0, ',', '.')) . '</p></div>';
            echo '</div>';
        } else {
            echo '<p class="muted mb-0" style="margin-top:16px">Conecte a conta para ver as métricas aqui.</p>';
        }
        echo '</div></details>';
        echo '<details class="panel soft meta-detail-card" id="meta-endpoints">';
        echo '<summary><div class="meta-summary-line"><strong>Referência técnica opcional</strong><span class="badge">' . h((string)count($examples)) . ' rotas</span></div><span class="meta-summary-lead">Só para quando você quiser conferir a API por trás do painel. Fica recolhido por padrão.</span></summary>';
        echo '<div class="meta-detail-body">';
        echo '<div class="meta-endpoint-grid">';
        foreach ($examples as $example) {
            $fullUrl = $baseGraphUrl . (string)$example['path'];
            echo '<details class="meta-endpoint-card"><summary><div class="meta-summary-line"><strong>' . h((string)$example['title']) . '</strong><span class="badge">' . h((string)$example['method']) . '</span></div><span class="meta-summary-lead">' . h((string)$example['description']) . '</span></summary><div class="meta-endpoint-body"><code style="display:block;white-space:pre-wrap;word-break:break-word">' . h($fullUrl) . '</code></div></details>';
        }
        echo '</div>';
        echo '</div></details>';
        echo '<details class="panel soft meta-detail-card" id="meta-audiences">';
        echo '<summary><div class="meta-summary-line"><strong>Públicos personalizados</strong><span class="badge">' . h((string)count($audiencesData ?? [])) . ' públicos</span></div><span class="meta-summary-lead">Lista curta dos públicos que podem ser usados em segmentação e remarketing.</span></summary>';
        echo '<div class="meta-detail-body">';
        if ($audiencesError) {
            echo '<div class="panel soft"><p class="mb-0"><strong>Não foi possível carregar públicos:</strong> ' . h($audiencesError) . '</p></div>';
        } elseif ($audiencesData) {
            echo '<div class="meta-audience-grid">';
            foreach ($audiencesData as $audience) {
                $delivery = is_array($audience['delivery_status'] ?? null) ? $audience['delivery_status'] : [];
                $deliveryStatus = is_array($delivery) && isset($delivery['code']) ? (string)$delivery['code'] : (string)($audience['operation_status']['code'] ?? $audience['operation_status'] ?? '');
                $approxCount = $audience['approximate_count'] ?? ($audience['approximate_count_lower_bound'] ?? '');
                echo '<article class="meta-audience-card">';
                echo '<div class="meta-audience-top"><div><strong>' . h((string)($audience['name'] ?? '')) . '</strong><span>' . h((string)($audience['id'] ?? '')) . '</span></div><div class="meta-chip-row"><span class="badge">' . h((string)($audience['subtype'] ?? '')) . '</span><span class="badge ' . h($deliveryStatus === 'ACTIVE' ? 'ok' : 'warn') . '">' . h($deliveryStatus) . '</span></div></div>';
                echo '<div class="meta-audience-metrics"><span><small>Tamanho</small><strong>' . h((string)$approxCount) . '</strong></span><span><small>Descrição</small><strong>' . h((string)($audience['description'] ?? '—')) . '</strong></span></div>';
                echo '</article>';
            }
            echo '</div>';
        } else {
            echo '<p class="muted mb-0">Nenhum público retornado ainda.</p>';
        }
        echo '</div></details>';
        echo '</section>';
        echo '<script>(function(){const root=document.querySelector(".meta-ads-page");if(!root||window.toggleMetaAdsSections)return;window.toggleMetaAdsSections=function(open){root.querySelectorAll("details.meta-layer-card,details.meta-detail-card,details.meta-campaign-accordion").forEach((el)=>{el.open=!!open;});};})();</script>';
        $adsByAdset = [];
        $adsByCampaign = [];
        foreach (($adsData ?? []) as $ad) {
            if (!is_array($ad)) {
                continue;
            }
            $adsetKey = (string)($ad['adset_id'] ?? 'sem-adset');
            $campaignKey = (string)($ad['campaign_id'] ?? 'sem-campaign');
            $adsByAdset[$adsetKey][] = $ad;
            $adsByCampaign[$campaignKey][] = $ad;
        }
        $adsetsByCampaign = [];
        foreach (($adsetsData ?? []) as $adset) {
            if (!is_array($adset)) {
                continue;
            }
            $campaignKey = (string)($adset['campaign_id'] ?? 'sem-campaign');
            $adsetsByCampaign[$campaignKey][] = $adset;
        }
        if (!empty($performanceSummary) && !empty($performanceSummary['ok'])) {
            $campaignMetrics['__account__'] = [
                'spend' => (float)($performanceSummary['spend'] ?? 0),
                'impressions' => (int)($performanceSummary['impressions'] ?? 0),
                'clicks' => (int)($performanceSummary['clicks'] ?? 0),
                'ctr' => (float)($performanceSummary['ctr'] ?? 0),
                'cpc' => (float)($performanceSummary['cpc'] ?? 0),
                'cpm' => (float)($performanceSummary['cpm'] ?? 0),
                'reach' => (int)($performanceSummary['reach'] ?? 0),
            ];
        }
        echo '<details class="panel soft meta-detail-card" id="meta-campaigns">';
        echo '<summary><div class="meta-summary-line"><strong>Campanhas e anúncios</strong><span class="badge">' . h((string)count($campaignsData ?? [])) . ' campanhas</span></div><span class="meta-summary-lead">Leitura operacional da conta: campanha no topo, conjuntos no meio e anúncios em cartões compactos.</span></summary>';
        echo '<div class="meta-detail-body">';
        echo '<div class="meta-campaign-toolbar"><form method="get" class="meta-period-form"><input type="hidden" name="page" value="studio_meta_ads"><label>De<input type="date" name="meta_ads_since" value="' . h($performanceStart) . '"></label><label>Até<input type="date" name="meta_ads_until" value="' . h($performanceEnd) . '"></label><button class="btn" type="submit">Aplicar período</button></form></div>';
        if ($campaignsError) {
            echo '<div class="panel soft"><p class="mb-0"><strong>Não foi possível carregar a árvore:</strong> ' . h($campaignsError) . '</p></div>';
        } elseif ($campaignsData) {
            echo '<div class="meta-campaign-list">';
            $campaignIndex = 0;
            foreach ($campaignsData as $campaign) {
                if (!is_array($campaign)) {
                    continue;
                }
                $campaignId = (string)($campaign['id'] ?? '');
                $campaignName = (string)($campaign['name'] ?? 'Campanha');
                $campaignStatus = (string)($campaign['effective_status'] ?? $campaign['status'] ?? '');
                $campaignObjective = trim((string)($campaign['objective'] ?? ''));
                $campaignBuyingType = trim((string)($campaign['buying_type'] ?? ''));
                $campaignAdsets = $adsetsByCampaign[$campaignId] ?? [];
                $campaignAds = $adsByCampaign[$campaignId] ?? [];
                $campaignMetric = $campaignMetrics[$campaignId] ?? [];
                $campaignStatusInfo = $metaStatusInfo($campaignStatus);
                $campaignObjectiveLabel = $metaObjectiveLabel($campaignObjective);
                $campaignReading = $metaAdReading($campaignMetric, $campaignStatus);
                $activeCampaign = $campaignStatus === 'ACTIVE';
                $campaignOpen = $campaignIndex === 0;
                $campaignIndex++;
                echo '<details class="meta-campaign-card" ' . ($campaignOpen ? 'open ' : '') . 'style="border-left-color:' . h($activeCampaign ? '#16a34a' : '#64748b') . '">';
                echo '<summary class="meta-campaign-summary">';
                echo '<div class="meta-campaign-head"><div><strong>' . h($campaignName) . '</strong><span>' . h($campaignObjectiveLabel . ($campaignBuyingType !== '' ? ' · ' . $campaignBuyingType : '')) . '</span></div><div class="meta-chip-row"><span class="badge ' . h((string)$campaignStatusInfo['tone']) . '">' . h((string)$campaignStatusInfo['label']) . '</span><span class="badge">' . h((string)count($campaignAdsets)) . ' conjuntos</span><span class="badge">' . h((string)count($campaignAds)) . ' anúncios</span></div></div>';
                echo '<div class="meta-campaign-read ' . h((string)$campaignReading['tone']) . '"><strong>' . h((string)$campaignReading['headline']) . '</strong><span>' . h((string)$campaignReading['detail']) . '</span></div>';
                echo '<div class="meta-campaign-metrics">';
                echo '<span><small>Gasto</small><strong>' . h(isset($campaignMetric['spend']) ? format_money((float)$campaignMetric['spend']) : '—') . '</strong></span>';
                echo '<span><small>Cliques</small><strong>' . h(isset($campaignMetric['clicks']) ? number_format((int)$campaignMetric['clicks'], 0, ',', '.') : '—') . '</strong></span>';
                echo '<span><small>CTR</small><strong>' . h(isset($campaignMetric['ctr']) ? number_format((float)$campaignMetric['ctr'], 2, ',', '.') . '%' : '—') . '</strong></span>';
                echo '<span><small>Alcance</small><strong>' . h(isset($campaignMetric['reach']) ? number_format((int)$campaignMetric['reach'], 0, ',', '.') : '—') . '</strong></span>';
                echo '</div>';
                echo '</summary>';
                echo '<div class="meta-campaign-body">';
                if (!empty($campaignAdsets)) {
                    echo '<div class="meta-adset-list">';
                    foreach ($campaignAdsets as $adset) {
                        if (!is_array($adset)) {
                            continue;
                        }
                        $adsetId = (string)($adset['id'] ?? '');
                        $adsetName = (string)($adset['name'] ?? 'Conjunto');
                        $adsetStatus = (string)($adset['effective_status'] ?? $adset['status'] ?? '');
                        $adsetObjective = trim((string)($adset['optimization_goal'] ?? ''));
                        $adsetBilling = trim((string)($adset['billing_event'] ?? ''));
                        $adsetAds = $adsByAdset[$adsetId] ?? [];
                        $adsetStatusInfo = $metaStatusInfo($adsetStatus);
                        $adsetObjectiveLabel = $metaObjectiveLabel($adsetObjective);
                        echo '<details class="meta-adset-card">';
                        echo '<summary class="meta-adset-summary"><div><strong>' . h($adsetName) . '</strong><span>' . h($adsetObjectiveLabel) . '</span></div><div class="meta-chip-row"><span class="badge ' . h((string)$adsetStatusInfo['tone']) . '">' . h((string)$adsetStatusInfo['label']) . '</span><span class="badge">' . h((string)count($adsetAds)) . ' anúncios</span></div></summary>';
                        echo '<div class="meta-adset-body">';
                        if ($adsetBilling !== '') {
                            echo '<p class="meta-subline">Cobrança: ' . h($adsetBilling) . '</p>';
                        }
                        if (!empty($adsetAds)) {
                            echo '<div class="meta-ad-list">';
                            foreach ($adsetAds as $ad) {
                                if (!is_array($ad)) {
                                    continue;
                                }
                                $adName = (string)($ad['name'] ?? 'Anúncio');
                                $adId = (string)($ad['id'] ?? '');
                                $adStatus = (string)($ad['effective_status'] ?? $ad['status'] ?? '');
                                $adInsights = is_array($adInsightsByAd[$adId] ?? null) ? $adInsightsByAd[$adId] : [];
                                $adStatusInfo = $metaStatusInfo($adStatus);
                                $adReading = $metaAdReading($adInsights, $adStatus);
                                $adActions = is_array($adInsights['actions'] ?? null) ? $adInsights['actions'] : [];
                                $adActionSummary = [];
                                foreach ($adActions as $action) {
                                    if (!is_array($action)) {
                                        continue;
                                    }
                                    $actionType = trim((string)($action['action_type'] ?? ''));
                                    $actionValue = trim((string)($action['value'] ?? ''));
                                    if ($actionType !== '' && $actionValue !== '') {
                                        $adActionSummary[] = $metaActionLabel($actionType) . ': ' . $actionValue;
                                    }
                                    if (count($adActionSummary) >= 3) {
                                        break;
                                    }
                                }
                                echo '<article class="meta-ad-card ' . h((string)$adReading['tone']) . '">';
                                echo '<div class="meta-ad-head"><div><strong>' . h($adName) . '</strong><span>' . h((string)$adStatusInfo['hint']) . '</span></div><span class="badge ' . h((string)$adStatusInfo['tone']) . '">' . h((string)$adStatusInfo['label']) . '</span></div>';
                                echo '<div class="meta-ad-reading"><strong>' . h((string)$adReading['headline']) . '</strong><span>' . h((string)$adReading['detail']) . '</span></div>';
                                if (!empty($adReading['tags'])) {
                                    echo '<div class="meta-chip-row meta-chip-row-left">';
                                    foreach ((array)$adReading['tags'] as $tag) {
                                        echo '<span class="badge">' . h((string)$tag) . '</span>';
                                    }
                                    echo '</div>';
                                }
                                echo '<div class="meta-ad-metrics">';
                                echo '<span><small>Gasto</small><strong>' . h(format_money((float)($adInsights['spend'] ?? 0))) . '</strong></span>';
                                echo '<span><small>Cliques</small><strong>' . h(number_format((int)($adInsights['clicks'] ?? 0), 0, ',', '.')) . '</strong></span>';
                                echo '<span><small>CTR</small><strong>' . h(number_format((float)($adInsights['ctr'] ?? 0), 2, ',', '.') . '%') . '</strong></span>';
                                echo '<span><small>Alcance</small><strong>' . h(number_format((int)($adInsights['reach'] ?? 0), 0, ',', '.')) . '</strong></span>';
                                echo '</div>';
                                echo '<details class="meta-ad-more">';
                                echo '<summary>Mais detalhes</summary>';
                                echo '<div class="meta-ad-more-body">';
                                echo '<div class="meta-ad-secondary-grid">';
                                echo '<span><small>CPC</small><strong>' . h(format_money((float)($adInsights['cpc'] ?? 0))) . '</strong></span>';
                                echo '<span><small>CPM</small><strong>' . h(format_money((float)($adInsights['cpm'] ?? 0))) . '</strong></span>';
                                echo '<span><small>Frequência</small><strong>' . h(number_format((float)($adInsights['frequency'] ?? 0), 2, ',', '.')) . '</strong></span>';
                                echo '<span><small>Cliques no link</small><strong>' . h(number_format((int)($adInsights['inline_link_clicks'] ?? 0), 0, ',', '.')) . '</strong></span>';
                                echo '<span><small>Cliques externos</small><strong>' . h(number_format((int)($adInsights['outbound_clicks'] ?? 0), 0, ',', '.')) . '</strong></span>';
                                echo '<span><small>Última atualização</small><strong>' . h(format_date_pt((string)($ad['updated_time'] ?? $ad['created_time'] ?? ''))) . '</strong></span>';
                                echo '</div>';
                                if ($adActionSummary) {
                                    echo '<p class="meta-subline">Ações: ' . h(implode(' · ', $adActionSummary)) . '</p>';
                                }
                                echo '<p class="meta-subline">Conjunto: ' . h((string)($ad['adset_id'] ?? '')) . ' · Campanha: ' . h((string)($ad['campaign_id'] ?? '')) . '</p>';
                                echo '</div>';
                                echo '</details>';
                                echo '</article>';
                            }
                            echo '</div>';
                        } else {
                            echo '<p class="meta-subline">Sem anúncios retornados para este conjunto.</p>';
                        }
                        echo '</div>';
                        echo '</details>';
                    }
                    echo '</div>';
                } elseif (!empty($campaignAds)) {
                    echo '<div class="meta-ad-list">';
                    foreach ($campaignAds as $ad) {
                        if (!is_array($ad)) {
                            continue;
                        }
                        $adName = (string)($ad['name'] ?? 'Anúncio');
                        $adId = (string)($ad['id'] ?? '');
                        $adStatus = (string)($ad['effective_status'] ?? $ad['status'] ?? '');
                        $adInsights = is_array($adInsightsByAd[$adId] ?? null) ? $adInsightsByAd[$adId] : [];
                        $adStatusInfo = $metaStatusInfo($adStatus);
                        $adReading = $metaAdReading($adInsights, $adStatus);
                        echo '<article class="meta-ad-card ' . h((string)$adReading['tone']) . '">';
                        echo '<div class="meta-ad-head"><div><strong>' . h($adName) . '</strong><span>' . h((string)$adStatusInfo['hint']) . '</span></div><span class="badge ' . h((string)$adStatusInfo['tone']) . '">' . h((string)$adStatusInfo['label']) . '</span></div>';
                        echo '<div class="meta-ad-reading"><strong>' . h((string)$adReading['headline']) . '</strong><span>' . h((string)$adReading['detail']) . '</span></div>';
                        if (!empty($adReading['tags'])) {
                            echo '<div class="meta-chip-row meta-chip-row-left">';
                            foreach ((array)$adReading['tags'] as $tag) {
                                echo '<span class="badge">' . h((string)$tag) . '</span>';
                            }
                            echo '</div>';
                        }
                        echo '<div class="meta-ad-metrics">';
                        echo '<span><small>Gasto</small><strong>' . h(format_money((float)($adInsights['spend'] ?? 0))) . '</strong></span>';
                        echo '<span><small>Cliques</small><strong>' . h(number_format((int)($adInsights['clicks'] ?? 0), 0, ',', '.')) . '</strong></span>';
                        echo '<span><small>CTR</small><strong>' . h(number_format((float)($adInsights['ctr'] ?? 0), 2, ',', '.') . '%') . '</strong></span>';
                        echo '<span><small>Alcance</small><strong>' . h(number_format((int)($adInsights['reach'] ?? 0), 0, ',', '.')) . '</strong></span>';
                        echo '</div>';
                        echo '</article>';
                    }
                    echo '</div>';
                } else {
                    echo '<p class="meta-subline">Sem conjuntos ou anúncios retornados para esta campanha.</p>';
                }
                echo '</div>';
                echo '</details>';
            }
            echo '</div>';
        } else {
            echo '<p class="muted mb-0 mt-3">Nenhuma campanha retornada ainda.</p>';
        }
        echo '</div></details>';
        echo '<details class="panel soft meta-detail-card" id="meta-config"><summary><div class="meta-summary-line"><strong>Configuração e conexão</strong><span class="badge">Ajustes</span></div><span class="meta-summary-lead">Atalhos para revisar credenciais, conta, lead form e tudo que falta ligar.</span></summary><div class="meta-detail-body"><div class="actions" style="justify-content:space-between;align-items:center"><div><h3 class="mb-1">Ir para as configurações</h3><p class="muted mb-0">Se ainda não cadastrou os dados, abra o bloco Meta Ads nas configurações.</p></div><a class="btn" href="' . h(app_url('studio_settings', ['tab' => 'meta_ads'])) . '#settings-meta-ads">Abrir configurações</a></div></div></details>';
        echo '<dialog id="metaAdsPerformanceDialog" style="max-width:980px;width:min(980px,96vw);border:none;border-radius:20px;padding:0;overflow:hidden">';
        echo '<form method="get" style="margin:0;background:#fff">';
        echo '<input type="hidden" name="page" value="studio_meta_ads">';
        echo '<div style="padding:18px 20px;border-bottom:1px solid rgba(15,23,42,.08);display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">';
        echo '<div><h3 style="margin:0">Performance de mídia</h3><p class="muted mb-0">Escolha as datas para ver gasto, cliques e alcance.</p></div>';
        echo '<button type="button" class="btn btn-secondary" onclick="document.getElementById(\'metaAdsPerformanceDialog\').close();">Fechar</button>';
        echo '</div>';
        echo '<div style="padding:18px 20px">';
        echo '<div class="grid cols-2" style="margin-bottom:16px">';
        echo '<div class="field"><label>De</label><input type="date" name="meta_ads_since" value="' . h($performanceStart) . '"></div>';
        echo '<div class="field"><label>Até</label><input type="date" name="meta_ads_until" value="' . h($performanceEnd) . '"></div>';
        echo '</div>';
        echo '<div class="d-flex gap-2 flex-wrap">';
        echo '<button class="btn" type="submit">Atualizar período</button>';
        echo '<button type="button" class="btn btn-secondary" onclick="document.getElementById(\'metaAdsPerformanceDialog\').close();">Cancelar</button>';
        echo '</div>';
        if (is_array($performanceSummary) && !empty($performanceSummary['ok'])) {
            echo '<div class="grid cols-4" style="margin-top:16px">';
            echo '<div class="field"><strong>Gasto</strong><p class="muted mb-0">' . h(format_money((float)($performanceSummary['spend'] ?? 0))) . '</p></div>';
            echo '<div class="field"><strong>Impressões</strong><p class="muted mb-0">' . h(number_format((int)($performanceSummary['impressions'] ?? 0), 0, ',', '.')) . '</p></div>';
            echo '<div class="field"><strong>Cliques</strong><p class="muted mb-0">' . h(number_format((int)($performanceSummary['clicks'] ?? 0), 0, ',', '.')) . '</p></div>';
            echo '<div class="field"><strong>CTR</strong><p class="muted mb-0">' . h(number_format((float)($performanceSummary['ctr'] ?? 0), 2, ',', '.')) . '%</p></div>';
            echo '<div class="field"><strong>CPC</strong><p class="muted mb-0">' . h(format_money((float)($performanceSummary['cpc'] ?? 0))) . '</p></div>';
            echo '<div class="field"><strong>CPM</strong><p class="muted mb-0">' . h(format_money((float)($performanceSummary['cpm'] ?? 0))) . '</p></div>';
            echo '<div class="field"><strong>Alcance</strong><p class="muted mb-0">' . h(number_format((int)($performanceSummary['reach'] ?? 0), 0, ',', '.')) . '</p></div>';
            echo '</div>';
        } else {
            echo '<p class="muted mb-0" style="margin-top:16px">Conecte a conta para ver as métricas aqui.</p>';
        }
        echo '</div>';
        echo '</form>';
        echo '</dialog>';
        echo '</div>';
    }, $flash);
    exit;
}

if ($page === 'dashboard') {
    require_admin();
    $stats = stats();
    render_app_shell('Painel da plataforma', 'Visao geral da alpha multi-estudio.', 'dashboard', function () use ($stats) {
        echo '<section class="grid cols-3">';
        echo '<div class="panel"><p class="metric">' . h($stats['studios']) . '</p><p class="muted">Estudios cadastrados</p></div>';
        echo '<div class="panel"><p class="metric">' . h($stats['active']) . '</p><p class="muted">Ativos</p></div>';
        echo '<div class="panel"><p class="metric">' . h($stats['setup']) . '</p><p class="muted">Em configuracao</p></div>';
        echo '</section>';
        echo '<section class="panel" style="margin-top:16px"><h2>Proximos passos da alpha</h2>';
        echo '<div class="module-list">';
        foreach ([
            ['Gerente', 'Login central e cadastro de estudios.'],
            ['Banco isolado', 'SQL individual por estudio, pronto para phpMyAdmin.'],
            ['CRM minimo', 'Tela base por estudio para receber os modulos.'],
            ['WhatsApp/IA', 'Marcados para conectar nas proximas etapas.'],
        ] as $module) {
            echo '<div class="module"><strong>' . h($module[0]) . '</strong><span class="muted">' . h($module[1]) . '</span></div>';
        }
        echo '</div></section>';
    }, $flash);
    exit;
}

if ($page === 'studios') {
    require_admin();
    $studios = list_studios();
    render_app_shell('Estudios', 'Cadastros e isolamento de bancos.', 'studios', function () use ($studios) {
        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><h2>Estudios cadastrados</h2><a class="btn" href="' . h(app_url('new_studio')) . '">Novo estudio</a></div>';
        if (!$studios) {
            echo '<p class="muted">Nenhum estudio cadastrado ainda.</p>';
        } else {
            echo '<table class="table"><thead><tr><th>Estudio</th><th>Status</th><th>Banco</th><th>Dono</th><th></th></tr></thead><tbody>';
            foreach ($studios as $studio) {
                $dbOk = studio_database_exists($studio);
                $plan = resolve_studio_plan($studio);
                echo '<tr>';
                echo '<td><strong>' . h($studio['name']) . '</strong><br><span class="muted">' . h($studio['slug']) . ' · ' . h(commercial_plan_display_name($plan, (string)($studio['plan_name'] ?? ''))) . '</span></td>';
                echo '<td><span class="badge ' . ($studio['status'] === 'active' ? 'ok' : 'warn') . '">' . h($studio['status']) . '</span></td>';
                echo '<td>' . h($studio['database_name']) . '<br><span class="badge ' . ($dbOk ? 'ok' : 'warn') . '">' . ($dbOk ? 'encontrado' : 'pendente') . '</span></td>';
                echo '<td>' . h($studio['owner_name']) . '<br><span class="muted">' . h($studio['owner_email']) . '</span></td>';
                echo '<td><div class="actions"><a class="btn secondary" href="' . h(app_url('studio', ['id' => (int)$studio['id']])) . '">Gerenciar</a><a class="btn" href="' . h(app_url('studio_login')) . '">Login do estudio</a></div></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }, $flash);
    exit;
}

if ($page === 'plans') {
    require_admin();
    $plans = list_commercial_plans();
    render_app_shell('Planos comerciais', 'Precos, recursos e limites editaveis do CRM.', 'plans', function () use ($plans) {
        if (!commercial_plans_ready()) {
            echo '<section class="panel"><h2>Migration pendente</h2><p>Rode o arquivo <code>database/platform_alpha_003_commercial_plans.sql</code> no banco central para habilitar os planos comerciais.</p></section>';
            return;
        }

        echo '<section class="panel"><div class="actions" style="justify-content:space-between"><h2>Planos cadastrados</h2><a class="btn" href="' . h(app_url('new_plan')) . '">Novo plano</a></div>';
        if (!$plans) {
            echo '<p class="muted">Nenhum plano cadastrado ainda.</p>';
        } else {
            echo '<div class="grid cols-3">';
            foreach ($plans as $plan) {
                $recommended = !empty($plan['recommended']);
                echo '<article class="panel">';
                echo '<div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2>' . h($plan['name']) . '</h2><p class="muted">' . h($plan['slug']) . '</p></div><div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end"><span class="badge ' . ($recommended ? 'ok' : 'warn') . '">' . ($recommended ? 'recomendado' : 'padrao') . '</span><span class="badge ' . (!empty($plan['is_active']) ? 'ok' : 'warn') . '">' . (!empty($plan['is_active']) ? 'ativo' : 'inativo') . '</span></div></div>';
                if (trim((string)($plan['short_description'] ?? '')) !== '') {
                    echo '<p class="muted">' . h($plan['short_description']) . '</p>';
                }
                echo '<p><strong>Mensal:</strong> ' . h(format_money((float)$plan['monthly_price'])) . '</p>';
                echo '<p><strong>Anual:</strong> ' . h(format_money((float)$plan['annual_price'])) . '</p>';
                if (trim((string)($plan['description'] ?? '')) !== '') {
                    echo '<p class="muted">' . h($plan['description']) . '</p>';
                }
                echo '<div class="module-list" style="margin-top:10px">';
                foreach ([
                    'WhatsApp' => !empty($plan['allow_whatsapp']),
                    'IA' => !empty($plan['allow_ai']),
                    'Dados' => !empty($plan['allow_data_assistant']),
                    'Financeiro' => !empty($plan['allow_finance']),
                    'Relatorios' => !empty($plan['allow_advanced_reports']),
                    'Automações' => !empty($plan['allow_automations']),
                    'Multi-estudio' => !empty($plan['allow_multi_studio']),
                    'Integrações' => !empty($plan['allow_external_integrations']),
                ] as $label => $enabled) {
                    echo '<div class="module"><strong>' . h($label) . '</strong><span class="muted">' . ($enabled ? 'sim' : 'nao') . '</span></div>';
                }
                echo '</div>';
                echo '<p class="muted" style="margin-top:10px">Limites: ' . h(trim(sprintf(
                    'estudios: %s | usuarios: %s | tatuadores: %s | WhatsApp: %s',
                    $plan['studio_limit'] === null ? 'ilimitado' : (string)$plan['studio_limit'],
                    $plan['user_limit'] === null ? 'ilimitado' : (string)$plan['user_limit'],
                    $plan['tattoo_artist_limit'] === null ? 'ilimitado' : (string)$plan['tattoo_artist_limit'],
                    $plan['whatsapp_session_limit'] === null ? 'ilimitado' : (string)$plan['whatsapp_session_limit']
                ))) . '</p>';
                echo '<div class="actions"><a class="btn secondary" href="' . h(app_url('edit_plan', ['id' => (int)$plan['id']])) . '">Editar</a></div>';
                echo '</article>';
            }
            echo '</div>';
        }
        echo '</section>';
    }, $flash);
    exit;
}

if ($page === 'new_plan') {
    require_admin();
    render_app_shell('Novo plano', 'Cadastre um plano comercial editavel para a plataforma.', 'plans', function () {
        render_commercial_plan_form(null);
    }, $flash);
    exit;
}

if ($page === 'edit_plan') {
    require_admin();
    $plan = get_commercial_plan((int)($_GET['id'] ?? 0));
    if (!$plan) {
        flash_set('error', 'Plano comercial nao encontrado.');
        redirect_to('plans');
    }
    render_app_shell('Editar plano', 'Atualize preco, recursos e limites do plano.', 'plans', function () use ($plan) {
        render_commercial_plan_form($plan);
    }, $flash);
    exit;
}

if ($page === 'new_studio') {
    require_admin();
    render_app_shell('Novo estudio', 'Cadastre o primeiro cliente ou o seu proprio estudio.', 'new_studio', function () {
        render_studio_form(null);
    }, $flash);
    exit;
}

if ($page === 'studio') {
    require_admin();
    $studio = get_studio((int)($_GET['id'] ?? 0));
    if (!$studio) {
        flash_set('error', 'Estudio nao encontrado.');
        redirect_to('studios');
    }
    render_app_shell((string)$studio['name'], 'Instancia alpha do CRM deste estudio.', 'studios', function () use ($studio) {
        $dbOk = studio_database_exists($studio);
        $plan = resolve_studio_plan($studio);
        $attendantsUrl = app_url('studio_attendants', ['studio_id' => (int)$studio['id']]);
        echo '<section class="grid cols-3">';
        echo '<div class="panel"><h2>Status</h2><span class="badge ' . ($studio['status'] === 'active' ? 'ok' : 'warn') . '">' . h($studio['status']) . '</span></div>';
        echo '<div class="panel"><h2>Banco</h2><p>' . h($studio['database_name']) . '</p><span class="badge ' . ($dbOk ? 'ok' : 'warn') . '">' . ($dbOk ? 'encontrado' : 'pendente') . '</span></div>';
        echo '<div class="panel"><h2>Plano</h2><p>' . h(commercial_plan_display_name($plan, (string)($studio['plan_name'] ?? ''))) . '</p>';
        if ($plan) {
            echo '<span class="muted">' . h(format_money((float)$plan['monthly_price'])) . '/mes · ' . h(format_money((float)$plan['annual_price'])) . '/ano</span>';
        }
        echo '</div>';
        echo '</section>';
        echo '<section class="panel" style="margin-top:16px"><div class="actions">';
        echo '<a class="btn" href="' . h(app_url('studio_login')) . '">Acessar login do estudio</a>';
        echo '<form method="post" class="inline-form">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="install_studio_database">';
        echo '<input type="hidden" name="studio_id" value="' . h($studio['id']) . '">';
        echo '<button class="btn secondary" type="submit">' . ($dbOk ? 'Atualizar banco do estudio' : 'Instalar banco do estudio') . '</button>';
        echo '</form>';
        echo '<a class="btn secondary" href="' . h(app_url('studio_sql', ['id' => (int)$studio['id']])) . '">Ver SQL do banco do estudio</a>';
        echo '<a class="btn secondary" href="' . h(app_url('edit_studio', ['id' => (int)$studio['id']])) . '">Editar cadastro</a>';
        echo '</div></section>';
        echo '<section class="panel" id="acessos-estudio" style="margin-top:16px"><h2>Acesso do estudio</h2>';
        $users = studio_users((int)$studio['id']);
        if ($users) {
            echo '<table class="table"><thead><tr><th>Nome</th><th>Email</th><th>Papel</th><th>Ultimo login</th></tr></thead><tbody>';
            foreach ($users as $user) {
                echo '<tr><td>' . h($user['name']) . '</td><td>' . h($user['email']) . '</td><td>' . h($user['role']) . '</td><td>' . h($user['last_login_at'] ?? '-') . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="muted">Nenhum usuario operacional criado ainda.</p>';
        }
        echo '<form class="form" method="post" style="margin-top:14px">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_studio_access">';
        echo '<input type="hidden" name="studio_id" value="' . h($studio['id']) . '">';
        echo '<div class="grid cols-3">';
        echo '<div class="field"><label>Nome</label><input name="access_name" value="' . h($studio['owner_name'] ?? '') . '" required></div>';
        echo '<div class="field"><label>Email de login</label><input type="text" inputmode="email" name="access_email" value="' . h($studio['owner_email'] ?? '') . '" required></div>';
        echo '<div class="field"><label>Senha inicial</label><input type="password" name="access_password" minlength="8" required></div>';
        echo '</div><button class="btn" type="submit">Salvar acesso do estudio</button></form>';
        echo '<div class="actions" style="margin-top:12px"><a class="btn" href="' . h(app_url('studio_login')) . '">Ir para tela de login do estudio</a><span class="muted">Use o email e a senha cadastrados acima.</span></div>';
        echo '</section>';
        echo '<section class="panel" style="margin-top:16px"><h2>CRM alpha</h2><div class="module-list">';
        foreach ([
            ['Leads', 'Estrutura preparada para funil e contatos.'],
            ['WhatsApp', 'Sera ligado ao servico multi-sessao.'],
            ['IA', 'Regras por estudio e modelos por instancia.'],
            ['Agenda', 'Banco isolado para horarios e clientes.'],
        ] as $module) {
            echo '<div class="module"><strong>' . h($module[0]) . '</strong><span class="muted">' . h($module[1]) . '</span></div>';
        }
        echo '</div></section>';
        echo '<script>(function(){if(window.location.hash==="#acessos-estudio"){window.location.replace(' . json_encode($attendantsUrl) . ');}})();</script>';
        echo '<section class="panel studio-events-panel" style="margin-top:16px"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><span class="section-eyebrow">Auditoria</span><h2 style="margin:4px 0">Eventos recentes</h2><p class="muted" style="margin:0">Linha do tempo operacional do estúdio: agenda, WhatsApp, configurações, financeiro e acessos.</p></div><span class="badge">últimos 40</span></div>';
        $events = studio_events((int)$studio['id'], 40);
        if (!$events) {
            echo '<p class="muted">Sem eventos ainda.</p>';
        } else {
            $eventCategoryCounts = [];
            foreach ($events as $event) {
                $category = (string)($event['category'] ?? 'system');
                $eventCategoryCounts[$category] = ($eventCategoryCounts[$category] ?? 0) + 1;
            }
            echo '<div class="studio-events-summary">';
            foreach ($eventCategoryCounts as $category => $count) {
                $categoryLabel = [
                    'agenda' => 'Agenda',
                    'whatsapp' => 'WhatsApp',
                    'settings' => 'Configurações',
                    'finance' => 'Financeiro',
                    'people' => 'Pessoas',
                    'access' => 'Acessos',
                    'system' => 'Sistema',
                ][$category] ?? ucfirst($category);
                echo '<span><strong>' . h((string)$count) . '</strong><small>' . h($categoryLabel) . '</small></span>';
            }
            echo '</div><div class="studio-events-timeline">';
            foreach ($events as $event) {
                $category = (string)($event['category'] ?? 'system');
                $categoryLabel = [
                    'agenda' => 'Agenda',
                    'whatsapp' => 'WhatsApp',
                    'settings' => 'Configurações',
                    'finance' => 'Financeiro',
                    'people' => 'Pessoas',
                    'access' => 'Acessos',
                    'system' => 'Sistema',
                ][$category] ?? ucfirst($category);
                $tone = trim((string)($event['tone'] ?? ''));
                $context = is_array($event['context'] ?? null) ? $event['context'] : [];
                $actorName = trim((string)($event['actor_name'] ?? ''));
                $actorType = trim((string)($event['actor_type'] ?? ''));
                $actorLabel = $actorName !== '' ? $actorName : ($actorType !== '' ? $actorType : 'Sistema');
                $createdAt = (string)($event['created_at'] ?? '');
                $createdLabel = $createdAt !== '' ? format_datetime_pt($createdAt, false) : '';
                echo '<article class="studio-event-card ' . h($tone !== '' ? 'tone-' . $tone : '') . '">';
                echo '<span class="studio-event-icon"><i class="' . h((string)($event['icon'] ?? 'fa-solid fa-wave-square')) . '"></i></span>';
                echo '<div class="studio-event-main">';
                echo '<div class="studio-event-head"><div><span class="badge">' . h($categoryLabel) . '</span><strong>' . h((string)($event['label'] ?? $event['type'] ?? 'Evento')) . '</strong></div><time>' . h($createdLabel) . '</time></div>';
                echo '<p>' . h((string)($event['message'] ?? '')) . '</p>';
                echo '<div class="studio-event-meta"><span><i class="fa-solid fa-user"></i> ' . h($actorLabel) . '</span><span><i class="fa-solid fa-code"></i> ' . h((string)($event['type'] ?? 'event')) . '</span></div>';
                if ($context) {
                    echo '<details class="studio-event-details"><summary>Ver detalhes técnicos</summary><pre>' . h(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) . '</pre></details>';
                }
                echo '</div></article>';
            }
            echo '</div>';
        }
        echo '</section>';
    }, $flash);
    exit;
}

if ($page === 'edit_studio') {
    require_admin();
    $studio = get_studio((int)($_GET['id'] ?? 0));
    if (!$studio) {
        flash_set('error', 'Estudio nao encontrado.');
        redirect_to('studios');
    }
    render_app_shell('Editar estudio', 'Atualize configuracoes da instancia.', 'studios', function () use ($studio) {
        render_studio_form($studio);
    }, $flash);
    exit;
}

if ($page === 'studio_attendants') {
    $admin = current_admin();
    $studioUser = current_studio_user();
    $studios = list_studios();
    $studioId = (int)($_GET['studio_id'] ?? ($_POST['studio_id'] ?? 0));
    if ($studioId <= 0 && $studios) {
        $studioId = (int)$studios[0]['id'];
    }
    $studio = $studioId > 0 ? get_studio($studioId) : null;
    if (!$studio) {
        flash_set('error', 'Estudio nao encontrado.');
        redirect_to('studios');
    }
    $editingUser = null;
    $editUserId = (int)($_GET['user_id'] ?? 0);
    if ($editUserId > 0) {
        $candidate = studio_find_user($editUserId);
        if (is_array($candidate) && (int)($candidate['studio_id'] ?? 0) === (int)$studio['id']) {
            $editingUser = $candidate;
        }
    }
    $canManageAttendants = $admin || (
        is_array($studioUser)
        && (string)($studioUser['role'] ?? '') === 'owner'
        && (int)($studioUser['studio_id'] ?? 0) === (int)$studio['id']
    );
    $backUrl = $admin ? app_url('studios') : app_url('studio_home');
    $backLabel = $admin ? 'Voltar ao painel gerencial' : 'Voltar ao painel do estúdio';
    render_app_shell('Atendentes do estúdio', 'Gerencie os usuarios que podem acessar o estúdio.', 'studios', function () use ($studio, $studios, $admin, $studioUser, $canManageAttendants, $editingUser, $backUrl, $backLabel) {
        if (!$canManageAttendants) {
            $returnTo = app_url('studio_attendants', ['studio_id' => (int)$studio['id']]);
            $_SESSION['admin_return_to'] = $returnTo;
            echo '<section class="panel" style="margin-bottom:16px">';
            echo '<h2>Acesso administrativo necessário</h2>';
            echo '<p class="muted">Esta tela aceita admin da plataforma ou dono do estúdio. Entre com uma dessas contas para criar ou atualizar acessos.</p>';
            echo '<form class="form" method="post" action="' . h(app_url('login')) . '">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="login">';
            echo '<input type="hidden" name="return_to" value="' . h($returnTo) . '">';
            echo '<div class="grid cols-2">';
            echo '<div class="field"><label>Email</label><input name="email" type="text" inputmode="email" required autocomplete="email"></div>';
            echo '<div class="field"><label>Senha</label><input name="password" type="password" required autocomplete="current-password"></div>';
            echo '</div><button class="btn" type="submit">Entrar como admin</button></form>';
            echo '</section>';
        }
        echo '<section class="panel">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">';
        echo '<div><h2 style="margin-bottom:6px">Estúdio: ' . h($studio['name']) . '</h2><p class="muted" style="margin:0">Página direta para criar e atualizar acessos do estúdio.</p></div>';
        echo '<a class="btn secondary" href="' . h($backUrl) . '">' . h($backLabel) . '</a>';
        echo '</div>';
        echo '<form method="get" class="actions" style="margin-top:14px;gap:10px;flex-wrap:wrap">';
        echo '<input type="hidden" name="page" value="studio_attendants">';
        echo '<div class="field" style="min-width:280px;flex:1"><label>Selecionar estúdio</label><select name="studio_id">';
        foreach ($studios as $item) {
            echo '<option value="' . h((string)$item['id']) . '"' . ((int)$item['id'] === (int)$studio['id'] ? ' selected' : '') . '>' . h($item['name']) . '</option>';
        }
        echo '</select></div>';
        echo '<div style="align-self:end"><button class="btn secondary" type="submit">Trocar estúdio</button></div>';
        echo '</form>';
        echo '</section>';

        $users = studio_users((int)$studio['id']);
        echo '<section class="panel" style="margin-top:16px"><div class="actions" style="justify-content:space-between;align-items:center"><h2 style="margin:0">Acessos cadastrados</h2><span class="badge">' . h((string)count($users)) . ' usuários</span></div>';
        if ($users) {
            echo '<table class="table"><thead><tr><th>Nome</th><th>Email</th><th>Papel</th><th>Ativo</th><th>Último login</th><th></th></tr></thead><tbody>';
            foreach ($users as $user) {
                echo '<tr>';
                echo '<td>' . h($user['name']) . '</td><td>' . h($user['email']) . '</td><td>' . h($user['role']) . '</td><td>' . h(!empty($user['is_active']) ? 'sim' : 'nao') . '</td><td>' . h($user['last_login_at'] ?? '-') . '</td>';
                echo '<td><div class="actions" style="justify-content:flex-end">';
                echo '<a class="btn tiny secondary" href="' . h(app_url('studio_attendants', ['studio_id' => (int)$studio['id'], 'user_id' => (int)$user['id']])) . '">Editar</a>';
                echo '<form method="post" onsubmit="return confirm(\'Excluir este atendente?\');" style="margin:0">';
                echo csrf_field();
                echo '<input type="hidden" name="action" value="delete_studio_attendant">';
                echo '<input type="hidden" name="id" value="' . h((string)$user['id']) . '">';
                echo '<button class="btn tiny secondary" type="submit">Excluir</button>';
                echo '</form>';
                echo '</div></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="muted">Nenhum usuario operacional criado ainda.</p>';
        }
        echo '</section>';

        if ($canManageAttendants) {
            echo '<section class="panel" style="margin-top:16px"><h2>' . h($editingUser ? 'Editar atendente' : 'Adicionar atendente') . '</h2>';
            echo '<p class="muted">Use para criar, editar ou desativar acessos do estúdio.</p>';
            echo '<form class="form" method="post">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="save_studio_attendant">';
            echo '<input type="hidden" name="studio_id" value="' . h($studio['id']) . '">';
            if ($editingUser) {
                echo '<input type="hidden" name="id" value="' . h((string)$editingUser['id']) . '">';
            }
            echo '<div class="grid cols-3">';
            echo '<div class="field"><label>Nome</label><input name="name" value="' . h($editingUser['name'] ?? ($studio['owner_name'] ?? '')) . '" required></div>';
            echo '<div class="field"><label>Email de login</label><input type="text" inputmode="email" name="email" value="' . h($editingUser['email'] ?? ($studio['owner_email'] ?? '')) . '" required></div>';
            echo '<div class="field"><label>Senha ' . h($editingUser ? '(opcional para manter)' : '(obrigatória)') . '</label><input type="password" name="password" minlength="8" ' . ($editingUser ? '' : 'required') . '></div>';
            echo '</div>';
            echo '<div class="grid cols-3" style="margin-top:12px">';
            echo '<div class="field"><label>Papel</label><select name="role">';
            foreach (['attendant' => 'Atendente', 'admin' => 'Administrador do estúdio', 'owner' => 'Dono'] as $value => $label) {
                $selected = (string)($editingUser['role'] ?? 'attendant') === $value ? ' selected' : '';
                echo '<option value="' . h($value) . '"' . $selected . '>' . h($label) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="field"><label>Status</label><select name="is_active"><option value="1"' . (!isset($editingUser['is_active']) || !empty($editingUser['is_active']) ? ' selected' : '') . '>Ativo</option><option value="0"' . (isset($editingUser['is_active']) && empty($editingUser['is_active']) ? ' selected' : '') . '>Inativo</option></select></div>';
            echo '<div class="field"><label>Dica</label><div class="muted">Salvar sem senha mantém a senha atual do atendente editado.</div></div>';
            echo '</div>';
            echo '<button class="btn" type="submit">Salvar acesso do estúdio</button></form>';
            echo '</section>';
        }
    }, $flash);
    exit;
}

if ($page === 'studio_sql') {
    require_admin();
    $studio = get_studio((int)($_GET['id'] ?? 0));
    if (!$studio) {
        flash_set('error', 'Estudio nao encontrado.');
        redirect_to('studios');
    }
    render_app_shell('SQL do estudio', 'Rode este SQL no phpMyAdmin para criar o banco isolado.', 'studios', function () use ($studio) {
        echo '<section class="panel"><div class="actions" style="justify-content:space-between"><h2>' . h($studio['database_name']) . '</h2><a class="btn secondary" href="' . h(app_url('studio', ['id' => (int)$studio['id']])) . '">Voltar</a></div>';
        echo '<pre class="codebox">' . h(studio_sql($studio)) . '</pre></section>';
    }, $flash);
    exit;
}

http_response_code(404);
render_app_shell('Pagina nao encontrada', 'O caminho solicitado nao existe.', 'dashboard', function () {
    echo '<div class="panel"><p>Volte para o painel inicial.</p></div>';
}, $flash);

function render_studio_form(?array $studio): void
{
    $isEdit = is_array($studio);
    $action = $isEdit ? 'update_studio' : 'create_studio';
    $plans = list_commercial_plans(true);
    $selectedPlanId = (int)($studio['plan_id'] ?? 0);
    if ($selectedPlanId <= 0 && !empty($studio['plan_name'])) {
        $selectedPlan = get_commercial_plan_by_slug((string)$studio['plan_name']);
        $selectedPlanId = (int)($selectedPlan['id'] ?? 0);
    }
    echo '<form class="form panel" method="post">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="' . h($action) . '">';
    if ($isEdit) {
        echo '<input type="hidden" name="id" value="' . h($studio['id']) . '">';
    }
    echo '<div class="grid cols-2">';
    echo '<div class="field"><label>Nome do estudio</label><input name="name" required value="' . h($studio['name'] ?? '') . '"></div>';
    echo '<div class="field"><label>Slug</label><input name="slug" ' . ($isEdit ? 'readonly' : '') . ' value="' . h($studio['slug'] ?? '') . '" placeholder="meu-estudio"></div>';
    echo '<div class="field"><label>Status</label><select name="status">';
    foreach (['setup' => 'Em configuracao', 'active' => 'Ativo', 'paused' => 'Pausado', 'disabled' => 'Desativado'] as $value => $label) {
        $selected = ($studio['status'] ?? 'setup') === $value ? 'selected' : '';
        echo '<option value="' . h($value) . '" ' . $selected . '>' . h($label) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="field"><label>Plano</label>';
    if ($plans) {
        echo '<select name="plan_id">';
        echo '<option value="">Selecione um plano</option>';
        foreach ($plans as $plan) {
            $selected = (int)$plan['id'] === $selectedPlanId ? 'selected' : '';
            echo '<option value="' . h($plan['id']) . '" ' . $selected . '>' . h($plan['name'] . ' · ' . format_money((float)$plan['monthly_price']) . '/mes') . '</option>';
        }
        echo '</select>';
    } else {
        echo '<input name="plan_name" value="' . h($studio['plan_name'] ?? 'basico') . '">';
    }
    echo '<small class="muted">O plano pode ser trocado depois no painel administrativo.</small></div>';
    echo '<div class="field"><label>Responsavel</label><input name="owner_name" value="' . h($studio['owner_name'] ?? '') . '"></div>';
    echo '<div class="field"><label>Email do responsavel</label><input type="text" inputmode="email" name="owner_email" value="' . h($studio['owner_email'] ?? '') . '"></div>';
    echo '<div class="field"><label>Telefone</label><input name="owner_phone" value="' . h($studio['owner_phone'] ?? '') . '"></div>';
    echo '<div class="field"><label>Modelo IA</label><input name="ai_model" value="' . h($studio['ai_model'] ?? 'llama3:8b') . '"></div>';
    echo '<div class="field"><label>Banco do estudio</label><input name="database_name" value="' . h($studio['database_name'] ?? '') . '" placeholder="projetocrm_nome_do_estudio"></div>';
    echo '<div class="field"><label>Host do banco</label><input name="database_host" value="' . h($studio['database_host'] ?? 'localhost') . '"></div>';
    echo '<div class="field"><label>Usuario do banco</label><input name="database_user" value="' . h($studio['database_user'] ?? 'root') . '"></div>';
    echo '</div>';
    echo '<div class="field"><label>Regras base da IA deste estudio</label><textarea name="business_rules" placeholder="Endereco, horarios, sinal, politicas, limites da IA...">' . h($studio['business_rules'] ?? '') . '</textarea></div>';
    echo '<div class="actions"><button class="btn" type="submit">' . ($isEdit ? 'Salvar alteracoes' : 'Cadastrar estudio') . '</button><a class="btn secondary" href="' . h(app_url('studios')) . '">Cancelar</a></div>';
    echo '</form>';
}

function render_commercial_plan_form(?array $plan): void
{
    $isEdit = is_array($plan);
    echo '<form class="form panel" method="post">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="save_commercial_plan">';
    if ($isEdit) {
        echo '<input type="hidden" name="id" value="' . h($plan['id']) . '">';
    }
    echo '<div class="grid cols-2">';
    echo '<div class="field"><label>Nome do plano</label><input name="name" required value="' . h($plan['name'] ?? '') . '"></div>';
    echo '<div class="field"><label>Slug</label><input name="slug" value="' . h($plan['slug'] ?? '') . '" placeholder="basico"></div>';
    echo '<div class="field"><label>Nome curto</label><input name="short_description" value="' . h($plan['short_description'] ?? '') . '" placeholder="Resumo curto do plano"></div>';
    echo '<div class="field"><label>Ordem</label><input type="number" name="sort_order" value="' . h($plan['sort_order'] ?? 0) . '"></div>';
    echo '<div class="field"><label>Preco mensal (R$)</label><input type="text" inputmode="decimal" step="0.01" min="0" name="monthly_price" value="' . h(number_format((float)($plan['monthly_price'] ?? 0), 2, '.', '')) . '"></div>';
    echo '<div class="field"><label>Preco anual (R$)</label><input type="text" inputmode="decimal" step="0.01" min="0" name="annual_price" value="' . h(number_format((float)($plan['annual_price'] ?? 0), 2, '.', '')) . '"></div>';
    echo '<div class="field"><label>Status</label><select name="is_active"><option value="1" ' . (!isset($plan['is_active']) || !empty($plan['is_active']) ? 'selected' : '') . '>Ativo</option><option value="0" ' . (isset($plan['is_active']) && empty($plan['is_active']) ? 'selected' : '') . '>Inativo</option></select></div>';
    echo '<div class="field"><label>Destaque</label><select name="recommended"><option value="1" ' . (!empty($plan['recommended']) ? 'selected' : '') . '>Recomendado</option><option value="0" ' . (empty($plan['recommended']) ? 'selected' : '') . '>Padrao</option></select></div>';
    echo '<div class="field"><label>Limite de estúdios</label><input type="number" min="0" name="studio_limit" value="' . h($plan['studio_limit'] ?? '') . '" placeholder="0 = ilimitado"></div>';
    echo '<div class="field"><label>Limite de usuários</label><input type="number" min="0" name="user_limit" value="' . h($plan['user_limit'] ?? '') . '" placeholder="0 = ilimitado"></div>';
    echo '<div class="field"><label>Limite de tatuadores</label><input type="number" min="0" name="tattoo_artist_limit" value="' . h($plan['tattoo_artist_limit'] ?? '') . '" placeholder="0 = ilimitado"></div>';
    echo '<div class="field"><label>Limite de sessões WhatsApp</label><input type="number" min="0" name="whatsapp_session_limit" value="' . h($plan['whatsapp_session_limit'] ?? '') . '" placeholder="0 = sem WhatsApp"></div>';
    echo '</div>';
    echo '<div class="field"><label>Descricao completa</label><textarea name="description" placeholder="Resumo comercial do plano para o gerente.">' . h($plan['description'] ?? '') . '</textarea></div>';
    echo '<div class="grid cols-2">';
    echo '<div class="field"><label>Recursos inclusos</label><textarea name="features_text" placeholder="Um recurso por linha. Ex:&#10;WhatsApp com IA&#10;Relatorios avancados">' . h($plan['features_text'] ?? '') . '</textarea><small class="muted">Use uma linha por recurso ou modulo incluso.</small></div>';
    echo '<div class="field"><label>Limites do plano</label><textarea name="limits_text" placeholder="Um limite por linha. Ex:&#10;usuarios: 5&#10;tatuadores: 3">' . h($plan['limits_text'] ?? '') . '</textarea><small class="muted">Use texto simples para limites comerciais do plano.</small></div>';
    echo '</div>';
    echo '<div class="field"><label>Permissoes</label>';
    echo '<div class="module-list">';
    foreach ([
        'allow_whatsapp' => 'Permite WhatsApp',
        'allow_ai' => 'Permite IA',
        'allow_data_assistant' => 'Permite assistente de dados',
        'allow_finance' => 'Permite financeiro',
        'allow_advanced_reports' => 'Permite relatorios avancados',
        'allow_automations' => 'Permite automacoes/follow-up',
        'allow_multi_studio' => 'Permite multi-estudio',
        'allow_external_integrations' => 'Permite integracoes externas',
        'allow_advanced_customization' => 'Permite personalizacao avancada',
    ] as $field => $label) {
        $checked = !empty($plan[$field]) ? ' checked' : '';
        echo '<label class="module"><input type="checkbox" name="' . h($field) . '" value="1"' . $checked . '> <strong>' . h($label) . '</strong></label>';
    }
    echo '</div></div>';
    echo '<div class="actions"><button class="btn" type="submit">' . ($isEdit ? 'Salvar plano' : 'Cadastrar plano') . '</button><a class="btn secondary" href="' . h(app_url('plans')) . '">Cancelar</a></div>';
    echo '</form>';
    if ($isEdit) {
        echo '<form method="post" class="panel" style="margin-top:12px" onsubmit="return confirm(\'Remover este plano?\')">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="delete_commercial_plan">';
        echo '<input type="hidden" name="id" value="' . h($plan['id']) . '">';
        echo '<div class="actions"><button class="btn secondary" type="submit">Excluir plano</button></div>';
        echo '</form>';
    }
}

function render_studio_db_missing(array $studio, string $error): void
{
    echo '<section class="panel">';
    echo '<h2>Banco do estudio pendente</h2>';
    echo '<p>O CRM operacional deste estudio ainda precisa do banco isolado instalado ou atualizado.</p>';
    echo '<p class="muted">Banco configurado: <strong>' . h($studio['database_name'] ?? '') . '</strong></p>';
    if ($error !== '') {
        echo '<p class="muted">' . h($error) . '</p>';
    }
    echo '<p class="muted">Entre pelo painel gerente, abra este estudio e clique em <strong>Instalar banco do estudio</strong>.</p>';
    echo '</section>';
}

function lead_status_options(): array
{
    return [
        'novo' => 'Novo',
        'em_conversa' => 'Em conversa',
        'pre_agendado' => 'Pre-agendado',
        'agendado' => 'Agendado',
        'finalizado' => 'Finalizado',
        'fechado' => 'Fechado',
        'perdido' => 'Perdido',
    ];
}

function appointment_status_options(): array
{
    return [
        'pre_agendado' => 'Pre-agendado',
        'agendado' => 'Agendado',
        'confirmado' => 'Confirmado',
        'finalizado' => 'Finalizado',
        'falta' => 'Falta',
        'cancelado' => 'Cancelado',
    ];
}

function appointment_origin_options(): array
{
    return [
        'manual' => 'Manual',
        'google_ics' => 'Google Calendar',
        'whatsapp' => 'WhatsApp',
        'whatsapp_ai' => 'WhatsApp IA',
        'lead' => 'Lead',
        'customer' => 'Cliente',
    ];
}

function appointment_origin_label(string $origin): string
{
    return appointment_origin_options()[$origin] ?? ($origin !== '' ? ucfirst(str_replace('_', ' ', $origin)) : 'Manual');
}

function studio_data_assistant_suggestions(): array
{
    return [
        'Agenda' => [
            'Quais sao os proximos agendamentos e com qual tatuador?',
            'Quais dias da agenda parecem mais cheios?',
            'Quais agendamentos ainda estao pre-agendados?',
            'Qual tatuador tem mais horarios futuros?',
        ],
        'Leads e funil' => [
            'Quais leads merecem prioridade hoje?',
            'Resumo dos leads por status e origem.',
            'Quais leads tem maior nota e maior valor estimado?',
            'Onde o funil parece estar travando?',
        ],
        'WhatsApp' => [
            'Quais conversas do WhatsApp precisam de atencao?',
            'Quais conversas pediram atendimento humano?',
            'Compare conversas em IA e em humano.',
            'Quais conversas recentes parecem mais importantes?',
        ],
        'Financeiro' => [
            'Qual o resultado simples do mes?',
            'Compare faturamento da agenda com despesas.',
            'Quais categorias de despesa pesam mais?',
            'Qual leitura rapida do financeiro atual?',
        ],
    ];
}

function render_options(array $options, string $selected): void
{
    foreach ($options as $value => $label) {
        $isSelected = $value === $selected ? 'selected' : '';
        echo '<option value="' . h($value) . '" ' . $isSelected . '>' . h($label) . '</option>';
    }
}

function render_customer_options(array $customers, int $selectedId = 0): void
{
    foreach ($customers as $customer) {
        $selected = (int)$customer['id'] === $selectedId ? ' selected' : '';
        echo '<option value="' . h($customer['id']) . '"' . $selected . '>' . h(($customer['name'] ?: 'Sem nome') . ($customer['phone'] ? ' - ' . $customer['phone'] : '')) . '</option>';
    }
}

function render_lead_options(array $leads, int $selectedId = 0): void
{
    foreach ($leads as $lead) {
        $selected = (int)$lead['id'] === $selectedId ? ' selected' : '';
        echo '<option value="' . h($lead['id']) . '"' . $selected . '>' . h(($lead['name'] ?: 'Sem nome') . ($lead['interest'] ? ' - ' . $lead['interest'] : '')) . '</option>';
    }
}

function render_artist_options(array $artists, int $selectedId = 0): void
{
    echo '<option value=""' . ($selectedId <= 0 ? ' selected' : '') . '>Sem tatuador</option>';
    foreach ($artists as $artist) {
        $selected = (int)$artist['id'] === $selectedId ? ' selected' : '';
        echo '<option value="' . h($artist['id']) . '"' . $selected . '>' . h($artist['name'] . ($artist['specialty'] ? ' - ' . $artist['specialty'] : '')) . '</option>';
    }
}

function parse_calendar_date(string $date): DateTimeImmutable
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$parsed) {
        return new DateTimeImmutable(date('Y-m-d'));
    }

    return $parsed;
}

function calendar_range_for(string $view, DateTimeImmutable $focus): array
{
    if ($view === 'week') {
        $start = $focus->modify('monday this week');
        return [$start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')];
    }
    if ($view === 'day') {
        return [$focus->format('Y-m-d'), $focus->format('Y-m-d')];
    }
    if ($view === 'list') {
        return [date('Y-m-d'), (new DateTimeImmutable(date('Y-m-d')))->modify('+45 days')->format('Y-m-d')];
    }

    return [$focus->modify('first day of this month')->format('Y-m-d'), $focus->modify('last day of this month')->format('Y-m-d')];
}

function calendar_shift_date(string $view, DateTimeImmutable $focus, int $direction): DateTimeImmutable
{
    $operator = $direction >= 0 ? '+' : '-';
    return match ($view) {
        'week' => $focus->modify($operator . '1 week'),
        'day' => $focus->modify($operator . '1 day'),
        'list' => $focus->modify($operator . '45 days'),
        default => $focus->modify($operator . '1 month'),
    };
}

function appointments_by_day(array $appointments): array
{
    $grouped = [];
    foreach ($appointments as $appointment) {
        $day = (string)$appointment['appointment_date'];
        $grouped[$day][] = $appointment;
    }

    return $grouped;
}

function appointment_display_amount(float|int|string $value): float
{
    $amount = money_to_float((string)$value);
    if ($amount >= 10000 && fmod($amount, 100.0) === 0.0) {
        $amount /= 100.0;
    }
    return max(0.0, $amount);
}

function appointment_effective_value(array $appointment, ?float $pomadaUnit = null): float
{
    $value = appointment_display_amount($appointment['value'] ?? 0);
    $deposit = appointment_display_amount($appointment['deposit_value'] ?? 0);
    $pomadas = max(0, (int)($appointment['pomadas_quantity'] ?? 0));
    $unit = isset($appointment['pomada_unit_price']) && $appointment['pomada_unit_price'] !== null && $appointment['pomada_unit_price'] !== ''
        ? appointment_display_amount($appointment['pomada_unit_price'])
        : ($pomadaUnit ?? (float)(app_config('app')['pomada_unit_price'] ?? 100));
    $effective = $value + ($pomadas * $unit) - $deposit;

    return max(0.0, $effective);
}

function render_calendar_month(array $appointments, DateTimeImmutable $focus, ?float $pomadaUnit = null): void
{
    $byDay = appointments_by_day($appointments);
    $first = $focus->modify('first day of this month');
    $last = $focus->modify('last day of this month');
    $cursor = $first->modify('-' . ((int)$first->format('N') - 1) . ' days');
    $end = $last->modify('+' . (7 - (int)$last->format('N')) . ' days');
    echo '<h3 class="calendar-title">' . h($focus->format('m/Y')) . '</h3>';
    echo '<div class="calendar-grid month">';
    foreach (['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'] as $label) {
        echo '<div class="calendar-head">' . h($label) . '</div>';
    }
    while ($cursor <= $end) {
        $date = $cursor->format('Y-m-d');
        $outside = $cursor->format('m') !== $focus->format('m') ? ' muted-day' : '';
        $isToday = $date === date('Y-m-d');
        $todayClass = $isToday ? ' is-today' : '';
        $dayAppointments = $byDay[$date] ?? [];
        $dayCount = count($dayAppointments);
        $dayValue = array_reduce($dayAppointments, static fn(float $sum, array $appointment): float => $sum + appointment_effective_value($appointment, $pomadaUnit), 0.0);
        $dayTone = 'neutral';
        foreach ($dayAppointments as $appointment) {
            $tone = appointment_status_tone((string)($appointment['status'] ?? ''));
            if ($tone === 'danger') {
                $dayTone = 'danger';
                break;
            }
            if ($tone === 'warn') {
                $dayTone = 'warn';
            } elseif ($tone === 'ok' && $dayTone !== 'warn') {
                $dayTone = 'ok';
            }
        }
        $dayHref = app_url('studio_agenda', ['cal_view' => 'day', 'date' => $date]);
        echo '<div class="calendar-cell' . h($outside . $todayClass) . '"><div class="calendar-date"><a href="' . h($dayHref) . '"><strong>' . h($cursor->format('d')) . '</strong>' . ($isToday ? '<em>Hoje</em>' : '') . '</a><span class="badge ' . h($isToday ? 'ok' : $dayTone) . '">' . h((string)$dayCount) . '</span></div>';
        echo '<div class="calendar-day-summary"><small>' . h(format_money($dayValue)) . '</small><span class="muted">previsto no dia</span></div>';
        foreach (array_slice($dayAppointments, 0, 4) as $appointment) {
            render_calendar_event($appointment);
        }
        $extra = $dayCount - 4;
        if ($extra > 0) {
            echo '<span class="muted">+' . h($extra) . ' horarios</span>';
        }
        echo '</div>';
        $cursor = $cursor->modify('+1 day');
    }
    echo '</div>';
}

function render_calendar_week(array $appointments, DateTimeImmutable $focus, ?float $pomadaUnit = null): void
{
    $byDay = appointments_by_day($appointments);
    $start = $focus->modify('monday this week');
    echo '<div class="calendar-grid week">';
    for ($i = 0; $i < 7; $i++) {
        $day = $start->modify('+' . $i . ' days');
        $date = $day->format('Y-m-d');
        $isToday = $date === date('Y-m-d');
        $dayAppointments = $byDay[$date] ?? [];
        $dayValue = array_reduce($dayAppointments, static fn(float $sum, array $appointment): float => $sum + appointment_effective_value($appointment, $pomadaUnit), 0.0);
        $dayHref = app_url('studio_agenda', ['cal_view' => 'day', 'date' => $date]);
        echo '<div class="calendar-cell' . ($isToday ? ' is-today' : '') . '"><div class="calendar-date"><a href="' . h($dayHref) . '"><strong>' . h($day->format('d/m')) . '</strong>' . ($isToday ? '<em>Hoje</em>' : '') . '</a><br><span class="muted">' . h(['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'][$i]) . '</span></div>';
        echo '<div class="calendar-day-summary"><small>' . h(count($dayAppointments) . ' agendamentos · ' . format_money($dayValue)) . '</small></div>';
        foreach ($dayAppointments as $appointment) {
            render_calendar_event($appointment);
        }
        if (empty($dayAppointments)) {
            echo '<span class="muted">Livre</span>';
        }
        echo '</div>';
    }
    echo '</div>';
}

function render_calendar_day(array $appointments, DateTimeImmutable $focus, ?float $pomadaUnit = null): void
{
    $isToday = $focus->format('Y-m-d') === date('Y-m-d');
    echo '<h3 class="calendar-title' . ($isToday ? ' is-today-title' : '') . '">' . h($focus->format('d/m/Y')) . ($isToday ? ' <span class="badge ok">Hoje</span>' : '') . '</h3>';
    if (!$appointments) {
        echo '<p class="muted">Nenhum agendamento neste dia.</p>';
        return;
    }
    $dayTotal = array_reduce($appointments, static fn(float $sum, array $appointment): float => $sum + appointment_effective_value($appointment, $pomadaUnit), 0.0);
    echo '<div class="calendar-day-summary" style="margin-bottom:12px"><small>' . h(format_money($dayTotal)) . '</small><span class="muted">previsto no dia considerando pomadas e sinal</span></div>';
    echo '<div class="stack-list">';
    foreach ($appointments as $appointment) {
        render_calendar_block($appointment);
    }
    echo '</div>';
}

function render_calendar_list(array $appointments): void
{
    if (!$appointments) {
        echo '<p class="muted">Nenhum agendamento futuro nos proximos 45 dias.</p>';
        return;
    }
    echo '<div class="stack-list">';
    foreach ($appointments as $appointment) {
        render_calendar_block($appointment);
    }
    echo '</div>';
}

function appointment_calendar_detail_payload(array $appointment): array
{
    $name = (string)($appointment['customer_name'] ?: ($appointment['lead_name'] ?: $appointment['title']));
    $date = (string)($appointment['appointment_date'] ?? '');
    $start = substr((string)($appointment['start_time'] ?? ''), 0, 5);
    $end = substr((string)($appointment['end_time'] ?? ''), 0, 5);
    $value = appointment_display_amount($appointment['value'] ?? 0);
    $deposit = appointment_display_amount($appointment['deposit_value'] ?? 0);
    $status = (string)($appointment['status'] ?? '');
    $healthAlerts = studio_appointment_health_alerts_from_row($appointment);
    $href = app_url('studio_agenda', ['date' => $date, 'appointment_id' => (int)($appointment['id'] ?? 0)]) . '#appointment-form';
    $referencePath = trim((string)($appointment['reference_image_path'] ?? ''));
    $referenceUrl = $referencePath !== ''
        ? (preg_match('/^https?:\/\//i', $referencePath) ? $referencePath : app_url($referencePath))
        : '';

    return [
        'id' => (int)($appointment['id'] ?? 0),
        'name' => $name,
        'title' => (string)($appointment['title'] ?? ''),
        'description' => (string)($appointment['description'] ?? ''),
        'date' => $date,
        'date_label' => $date !== '' ? format_date_pt($date) : '-',
        'time_label' => trim($start . ($end !== '' ? ' - ' . $end : '')),
        'status' => $status !== '' ? $status : 'sem status',
        'artist' => (string)($appointment['artist_name'] ?: 'Sem tatuador'),
        'value_label' => format_money($value),
        'deposit_label' => format_money($deposit),
        'origin_label' => appointment_origin_label((string)($appointment['import_source'] ?? 'manual')),
        'raw_title' => (string)($appointment['raw_title'] ?? ''),
        'google_calendar_id' => (string)($appointment['google_calendar_id'] ?? ''),
        'google_event_id' => (string)($appointment['google_calendar_event_id'] ?? ''),
        'reference_url' => $referenceUrl,
        'reference_name' => (string)($appointment['reference_image_name'] ?? ''),
        'reference_mime' => (string)($appointment['reference_image_mime'] ?? ''),
        'health_alerts' => array_map(static fn(array $alert): array => [
            'label' => (string)($alert['label'] ?? ''),
            'detail' => (string)($alert['detail'] ?? ''),
        ], $healthAlerts),
        'edit_url' => $href,
    ];
}

function appointment_calendar_detail_attr(array $appointment): string
{
    return h(json_encode(appointment_calendar_detail_payload($appointment), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function render_calendar_event(array $appointment): void
{
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($appointment['artist_color'] ?? '')) ? $appointment['artist_color'] : '#1f6f78';
    $name = $appointment['customer_name'] ?: ($appointment['lead_name'] ?: $appointment['title']);
    $status = (string)($appointment['status'] ?? '');
    $status_class = appointment_status_class($status);
    $status_bg = appointment_status_background($status);
    $status_border = appointment_status_border($status);
    $healthAlerts = studio_appointment_health_alerts_from_row($appointment);
    echo '<button type="button" class="calendar-event ' . h($status_class) . '" data-appointment-detail="' . appointment_calendar_detail_attr($appointment) . '" style="border-left-color:' . h($color) . '; background-color:' . h($status_bg) . '; border-color:' . h($status_border) . '"><strong>' . h(substr((string)$appointment['start_time'], 0, 5)) . '</strong><span class="calendar-event-title">' . h($name) . '</span><span class="badge ' . h(appointment_status_tone($status)) . '">' . h($status ?: 'sem status') . '</span>' . ($healthAlerts ? '<span class="badge warn">saúde</span>' : '') . '</button>';
}

function render_calendar_block(array $appointment): void
{
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($appointment['artist_color'] ?? '')) ? $appointment['artist_color'] : '#1f6f78';
    $name = $appointment['customer_name'] ?: ($appointment['lead_name'] ?: $appointment['title']);
    $value = appointment_display_amount($appointment['value'] ?? 0);
    $deposit = appointment_display_amount($appointment['deposit_value'] ?? 0);
    $status = (string)($appointment['status'] ?? '');
    $status_class = appointment_status_class($status);
    $status_bg = appointment_status_background($status);
    $status_border = appointment_status_border($status);
    echo '<button type="button" class="appointment-block appointment-block-compact ' . h($status_class) . '" data-appointment-detail="' . appointment_calendar_detail_attr($appointment) . '" style="border-left-color:' . h($color) . '; background-color:' . h($status_bg) . '; border-color:' . h($status_border) . '">';
    echo '<strong>' . h(format_date_pt((string)$appointment['appointment_date']) . ' ' . substr((string)$appointment['start_time'], 0, 5) . ($appointment['end_time'] ? ' - ' . substr((string)$appointment['end_time'], 0, 5) : '')) . '</strong>';
    echo '<span class="appointment-block-title">' . h($name . ' - ' . $appointment['title']) . '</span>';
    echo '<span class="muted appointment-block-meta">' . h(($appointment['artist_name'] ?: 'Sem tatuador') . ' | ' . format_money($value) . ' | sinal ' . format_money($deposit)) . '</span>';
    echo '<span class="badge ' . h(appointment_status_tone($status)) . '">' . h($status ?: 'sem status') . '</span>';
    if (studio_appointment_health_alerts_from_row($appointment)) {
        echo '<span class="badge warn">saúde</span>';
    }
    echo '</button>';
}

function render_customers_table(array $customers): void
{
    if (!$customers) {
        echo '<p class="muted">Nenhum cliente cadastrado ainda.</p>';
        return;
    }
    echo '<table class="table"><thead><tr><th>Cliente</th><th>Contato</th><th>Observacoes</th><th>Acoes</th></tr></thead><tbody>';
    foreach ($customers as $customer) {
        $href = app_url('studio_customer', ['id' => (int)$customer['id']]);
        $lastAppointmentDate = trim((string)($customer['last_appointment_date'] ?? ''));
        $lastMessageAt = trim((string)($customer['last_message_at'] ?? ''));
        echo '<tr data-overlay-item data-overlay-date="' . h($lastAppointmentDate !== '' ? $lastAppointmentDate : $lastMessageAt) . '" data-overlay-time="' . h(substr($lastMessageAt, 11, 5)) . '">';
        echo '<td><a href="' . h($href) . '"><strong>' . h($customer['name'] ?: 'Sem nome') . '</strong></a><br><span class="muted">' . h($customer['instagram'] ?: '-') . '</span></td>';
        echo '<td>' . h($customer['phone'] ?: '-') . '<br><span class="muted">' . h($customer['email'] ?: '-') . '</span></td>';
        echo '<td>' . h($customer['notes'] ?: '-') . '</td>';
        echo '<td><a class="btn tiny secondary" href="' . h($href) . '">Abrir</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function render_artists_table(array $artists): void
{
    if (!$artists) {
        echo '<p class="muted">Nenhum tatuador cadastrado ainda.</p>';
        return;
    }
    echo '<table class="table"><thead><tr><th>Tatuador</th><th>Especialidade</th><th>Status</th></tr></thead><tbody>';
    foreach ($artists as $artist) {
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($artist['color'] ?? '')) ? $artist['color'] : '#1f6f78';
        echo '<tr data-overlay-item data-overlay-date="' . h((string)($expense['expense_date'] ?? '')) . '">';
        echo '<td><span class="color-dot" style="background:' . h($color) . '"></span><strong>' . h($artist['name']) . '</strong></td>';
        echo '<td>' . h($artist['specialty'] ?: '-') . '</td>';
        echo '<td><span class="badge ' . (!empty($artist['is_active']) ? 'ok' : 'warn') . '">' . (!empty($artist['is_active']) ? 'ativo' : 'inativo') . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function render_pipeline_board(array $board, array $stages): void
{
    if (!$board) {
        echo '<p class="muted">Nenhuma etapa de funil configurada.</p>';
        return;
    }

    $stageNames = array_values(array_map(static fn(array $stage): string => (string)$stage['name'], $stages));
    $totalLeads = 0;
    $totalValue = 0.0;
    foreach ($board as $column) {
        $totalLeads += count($column['leads'] ?? []);
        $totalValue += (float)($column['total_value'] ?? 0);
    }
    echo '<div class="pipeline-board">';
    foreach ($board as $stageName => $column) {
        $stage = $column['stage'];
        $leads = $column['leads'];
        $stageCount = count($leads);
        $stageTotalValue = (float)($column['total_value'] ?? 0);
        $share = $totalLeads > 0 ? (int)round(($stageCount / $totalLeads) * 100) : 0;
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($stage['color'] ?? '')) ? $stage['color'] : '#667085';
        $isFinalizedColumn = studio_normalize_pipeline_stage((string)$stageName) === 'finalizado';
        $stageLabel = studio_pipeline_stage_display_name((string)$stageName);
        echo '<div class="pipeline-column" style="--stage-color:' . h($color) . '" data-stage="' . h($stageName) . '" data-page-size="12">';
        echo '<div class="pipeline-column-head">';
        echo '<button type="button" class="pipeline-column-title" data-pipeline-sort-toggle title="Clique para alternar a classificação"><strong>' . h($stageLabel) . '</strong><span class="muted">' . h($isFinalizedColumn ? 'Atendimentos concluídos' : 'Etapa do funil') . '</span></button>';
        echo '<span class="badge">' . h((string)$stageCount) . ' ' . h($isFinalizedColumn ? 'itens' : 'leads') . '</span>';
        echo '</div>';
        echo '<div class="pipeline-column-summary"><span><strong>' . h((string)$stageCount) . '</strong><small>' . h($isFinalizedColumn ? 'Atendidos' : 'Leads') . '</small></span><span><strong>' . h(format_money($stageTotalValue)) . '</strong><small>Valor</small></span><span><strong>' . h((string)$share) . '%</strong><small>Funil</small></span></div>';
        echo '<div class="pipeline-column-tools"><input type="search" data-pipeline-filter placeholder="Filtrar coluna..."><select data-pipeline-sort aria-label="Classificar coluna"><option value="updated_desc">Recentes</option><option value="score_desc">Nota maior</option><option value="value_desc">Valor maior</option><option value="name_asc">Nome A-Z</option></select></div>';
        if (!$leads) {
            echo '<p class="muted pipeline-empty">' . h($isFinalizedColumn ? 'Nenhum atendimento finalizado automaticamente.' : 'Nenhum lead nesta etapa.') . '</p>';
        }
        echo '<div class="pipeline-card-list" data-pipeline-list>';
        foreach ($leads as $lead) {
            render_pipeline_card($lead, $stageNames);
        }
        echo '</div>';
        echo '<div class="pipeline-pagination" data-pipeline-pagination><button type="button" class="btn tiny secondary" data-pipeline-prev>Anterior</button><span data-pipeline-page-label>1/1</span><button type="button" class="btn tiny secondary" data-pipeline-next>Próxima</button></div>';
        echo '</div>';
    }
    echo '</div>';
}

function render_pipeline_card(array $lead, array $stageNames): void
{
    $currentStage = (string)($lead['pipeline_stage'] ?? '');
    $currentIndex = array_search($currentStage, $stageNames, true);
    $prevStage = $currentIndex !== false && $currentIndex > 0 ? $stageNames[$currentIndex - 1] : '';
    $nextStage = $currentIndex !== false && $currentIndex < count($stageNames) - 1 ? $stageNames[$currentIndex + 1] : '';
    $leadId = (int)($lead['id'] ?? 0);
    $appointmentId = (int)($lead['appointment_id'] ?? 0);
    $isAppointmentCard = !empty($lead['finalized_from_appointment']);
    $updatedAt = (string)($lead['updated_at'] ?? $lead['created_at'] ?? '');
    $isStale = false;
    if ($updatedAt !== '') {
        try {
            $staleThreshold = new DateTimeImmutable('-24 hours', new DateTimeZone('America/Sao_Paulo'));
            $updatedMoment = new DateTimeImmutable($updatedAt, new DateTimeZone('America/Sao_Paulo'));
            $isStale = $updatedMoment < $staleThreshold;
        } catch (Throwable) {
            $isStale = false;
        }
    }
    $phone = normalize_phone((string)($lead['phone'] ?? ''));
    $phoneLink = $phone !== '' ? 'https://wa.me/' . $phone : '';
    $createdAt = (string)($lead['created_at'] ?? '');
    $createdOrUpdated = $updatedAt !== '' ? (function_exists('studio_relative_time_label') ? studio_relative_time_label($updatedAt) : $updatedAt) : '-';
    $createdLabel = $createdAt !== '' ? (function_exists('studio_relative_time_label') ? studio_relative_time_label($createdAt) : $createdAt) : '-';
    $isNew = false;
    if ($createdAt !== '') {
        try {
            $isNew = new DateTimeImmutable($createdAt, new DateTimeZone('America/Sao_Paulo')) >= new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
        } catch (Throwable) {
            $isNew = false;
        }
    }
    $score = (int)($lead['lead_score'] ?? 0);
    $isHot = $score >= 8;
    $isHighValue = (float)($lead['estimated_value'] ?? 0) >= 1000;
    $status = strtolower((string)($lead['status'] ?? ''));
    $artistName = trim((string)($lead['artist_name'] ?? $lead['tattoo_artist_name'] ?? $lead['responsible_name'] ?? ''));
    $isScheduled = in_array($status, ['agendado', 'pre_agendado'], true);
    $appointmentDate = (string)($lead['appointment_date'] ?? '');
    $appointmentTime = substr((string)($lead['start_time'] ?? ''), 0, 5);
    $appointmentHref = $appointmentDate !== '' ? app_url('studio_agenda', ['date' => $appointmentDate, 'appointment_id' => $appointmentId]) . '#appointment-form' : app_url('studio_agenda');
    $cardDomId = $leadId > 0 ? (string)$leadId : 'appointment-' . $appointmentId;
    $draggable = $leadId > 0 && !$isAppointmentCard ? 'true' : 'false';
    $leadName = trim((string)($lead['name'] ?? '')) ?: ($isAppointmentCard ? 'Agendamento finalizado' : 'Lead sem nome');
    $interest = trim((string)($lead['interest'] ?? '')) ?: 'Sem interesse descrito.';
    $value = (float)($lead['estimated_value'] ?? 0);
    $searchText = trim(implode(' ', array_filter([
        $leadName,
        $interest,
        (string)($lead['phone'] ?? ''),
        (string)($lead['source'] ?? ''),
        $artistName,
        $status,
    ])));
    $contextParts = [];
    if ($phone !== '') {
        $contextParts[] = $phone;
    }
    if ($artistName !== '') {
        $contextParts[] = $artistName;
    }
    if ($isAppointmentCard && $appointmentDate !== '') {
        $contextParts[] = format_date_pt($appointmentDate) . ($appointmentTime !== '' ? ' ' . $appointmentTime : '');
    } elseif ($createdOrUpdated !== '') {
        $contextParts[] = $createdOrUpdated;
    }
    $context = trim(implode(' · ', $contextParts));

    echo '<article class="lead-card card shadow-sm border-0' . ($isStale && !$isAppointmentCard ? ' stale' : '') . ($isAppointmentCard ? ' lead-card-finalized' : '') . '" draggable="' . h($draggable) . '" data-lead-id="' . h($cardDomId) . '" data-stage-name="' . h($currentStage) . '" data-lead-name="' . h(mb_strtolower($leadName, 'UTF-8')) . '" data-lead-score="' . h((string)$score) . '" data-lead-value="' . h((string)$value) . '" data-lead-updated="' . h($updatedAt !== '' ? $updatedAt : $createdAt) . '" data-lead-search="' . h(mb_strtolower($searchText, 'UTF-8')) . '">';
    if ($leadId > 0) {
        echo '<button type="button" class="lead-card-title-button" data-lead-open="' . h((string)$leadId) . '"><strong class="lead-card-title">' . h($leadName) . '</strong></button>';
    } else {
        echo '<a class="lead-card-title-button" href="' . h($appointmentHref) . '"><strong class="lead-card-title">' . h($leadName) . '</strong></a>';
    }
    echo '<div class="lead-card-strip">';
    echo '<span class="badge">' . h($isAppointmentCard ? 'finalizado' : ($status !== '' ? $status : 'sem status')) . '</span>';
    echo '<span class="badge">' . h((string)$score) . '/10</span>';
    if ($isHot) {
        echo '<span class="badge ok">Quente</span>';
    }
    if ($isScheduled) {
        echo '<span class="badge warn">' . h($status === 'agendado' ? 'Agendado' : 'Pré-agendado') . '</span>';
    }
    if ($isAppointmentCard) {
        echo '<span class="badge ok">Atendido</span>';
    }
    if ($isStale && !$isAppointmentCard) {
        echo '<span class="badge warn">24h+</span>';
    }
    if ($value > 0) {
        echo '<span class="lead-card-value">' . h(format_money($value)) . '</span>';
    }
    echo '</div>';
    echo '<p class="lead-card-context" title="' . h($interest) . '">' . h($context !== '' ? $context : $interest) . '</p>';
    echo '<div class="lead-card-actions lead-card-actions-quick">';
    if ($leadId > 0) {
        echo '<a class="btn tiny secondary" href="' . h(app_url('studio_lead', ['id' => $leadId])) . '">Ver</a>';
        echo '<button type="button" class="btn tiny secondary" data-lead-open="' . h((string)$leadId) . '">Detalhes</button>';
    }
    if ($isAppointmentCard) {
        echo '<a class="btn tiny secondary" href="' . h($appointmentHref) . '">Agenda</a>';
    }
    if (!$isAppointmentCard) {
        foreach ([['label' => 'Voltar', 'stage' => $prevStage], ['label' => 'Avancar', 'stage' => $nextStage]] as $move) {
            if ($move['stage'] === '') {
                continue;
            }
            echo '<button type="button" class="btn tiny secondary" data-move-stage="' . h($move['stage']) . '" data-lead-id="' . h((string)$leadId) . '" data-current-status="' . h($lead['status']) . '">' . h($move['label'] === 'Avancar' ? '→' : '←') . '</button>';
        }
    }
    echo '</div>';
    echo '</article>';
}

function render_leads_table(array $leads): void
{
    if (!$leads) {
        echo '<p class="muted">Nenhum lead cadastrado ainda.</p>';
        return;
    }
    echo '<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Lead</th><th>Funil</th><th>Valor</th><th>Nota</th><th>Acoes</th></tr></thead><tbody>';
    foreach ($leads as $lead) {
        $href = app_url('studio_lead', ['id' => (int)$lead['id']]);
        echo '<tr data-overlay-item>';
        echo '<td><a href="' . h($href) . '"><strong>' . h($lead['name'] ?: 'Sem nome') . '</strong></a><br><span class="muted">' . h($lead['phone'] ?: $lead['interest']) . '</span></td>';
        echo '<td><span class="badge">' . h($lead['status']) . '</span><br><span class="muted">' . h($lead['pipeline_stage'] ?: '-') . '</span></td>';
        echo '<td>' . h(format_money($lead['estimated_value'] ?? 0)) . '<br><span class="muted">' . h($lead['source'] ?: '-') . '</span></td>';
        echo '<td><strong>' . h((string)($lead['lead_score'] ?? '-')) . '/10</strong></td>';
        echo '<td><a class="btn tiny secondary" href="' . h($href) . '">Abrir</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function render_lead_conversations(array $conversations): void
{
    if (!$conversations) {
        echo '<p class="muted">Nenhuma conversa vinculada a este lead.</p>';
        return;
    }

    echo '<div class="stack-list">';
    foreach ($conversations as $conversation) {
        $name = $conversation['name'] ?: $conversation['phone'];
        echo '<a class="activity-card" href="' . h(app_url('studio_whatsapp_conversation', ['id' => (int)$conversation['id']])) . '">';
        echo '<strong>' . h($name) . '</strong>';
        echo '<span class="muted">' . h(($conversation['message_count'] ?? 0) . ' mensagens | ' . ($conversation['message_last_at'] ?: '-')) . '</span>';
        $lastMessage = trim((string)($conversation['last_message_preview'] ?? $conversation['latest_message_preview'] ?? ''));
        echo '<span>' . h($lastMessage !== '' ? $lastMessage : '-') . '</span>';
        echo '</a>';
    }
    echo '</div>';
}

function render_appointments_table(array $appointments): void
{
    if (!$appointments) {
        echo '<p class="muted">Nenhum horario cadastrado ainda.</p>';
        return;
    }
    echo '<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Quando</th><th>Atendimento</th><th>Tatuador</th><th>Valor</th><th>Status</th></tr></thead><tbody>';
    foreach ($appointments as $appointment) {
        $date = format_date_pt((string)$appointment['appointment_date']);
        $href = app_url('studio_agenda', ['date' => (string)$appointment['appointment_date'], 'appointment_id' => (int)$appointment['id']]) . '#appointment-form';
        echo '<tr data-overlay-item data-overlay-date="' . h((string)($appointment['appointment_date'] ?? '')) . '" data-overlay-time="' . h(substr((string)($appointment['start_time'] ?? ''), 0, 5)) . '">';
        echo '<td><strong>' . h($date) . '</strong><br><span class="muted">' . h(substr((string)$appointment['start_time'], 0, 5)) . ($appointment['end_time'] ? ' - ' . h(substr((string)$appointment['end_time'], 0, 5)) : '') . '</span></td>';
        echo '<td><strong>' . h($appointment['customer_name'] ?: $appointment['lead_name'] ?: $appointment['title']) . '</strong><br><span class="muted">' . h($appointment['description'] ?: $appointment['title']) . '</span></td>';
        echo '<td>' . h($appointment['artist_name'] ?: '-') . '</td>';
$appointmentValue = appointment_display_amount($appointment['value'] ?? 0);
$appointmentDeposit = appointment_display_amount($appointment['deposit_value'] ?? 0);
echo '<td>' . h(format_money($appointmentValue)) . '<br><span class="muted">Sinal ' . h(format_money($appointmentDeposit)) . '</span></td>';
        echo '<td><span class="badge">' . h($appointment['status']) . '</span>' . (studio_appointment_health_alerts_from_row($appointment) ? '<br><span class="badge warn">saúde</span>' : '') . '<br><a class="btn tiny secondary" href="' . h($href) . '">Abrir</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function render_expenses_table(array $expenses): void
{
    if (!$expenses) {
        echo '<p class="muted">Nenhuma despesa cadastrada ainda.</p>';
        return;
    }
    echo '<div class="table-responsive"><table class="table"><thead><tr><th>Data</th><th>Despesa</th><th>Categoria</th><th>Valor</th><th>Ações</th></tr></thead><tbody>';
    foreach ($expenses as $expense) {
        $date = format_date_pt((string)$expense['expense_date']);
        $editPayload = json_encode([
            'id' => (int)$expense['id'],
            'category' => (string)$expense['category'],
            'description' => (string)$expense['description'],
            'amount' => number_format((float)$expense['amount'], 2, ',', ''),
            'expense_date' => (string)$expense['expense_date'],
            'payment_method' => (string)($expense['payment_method'] ?? ''),
            'notes' => (string)($expense['notes'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '<tr data-overlay-item data-overlay-date="' . h((string)($expense['expense_date'] ?? '')) . '">';
        echo '<td><strong>' . h($date) . '</strong><br><span class="muted">' . h($expense['payment_method'] ?: '-') . '</span></td>';
        echo '<td><strong>' . h($expense['description']) . '</strong><br><span class="muted">' . h($expense['notes'] ?: '-') . '</span></td>';
        echo '<td><span class="badge">' . h($expense['category']) . '</span></td>';
        echo '<td><strong>' . h(format_money($expense['amount'])) . '</strong></td>';
        echo '<td><div class="actions"><button class="btn tiny secondary" type="button" data-expense-edit="' . h($editPayload ?: '{}') . '">Editar</button>';
        echo '<form method="post" onsubmit="return confirm(\'Excluir esta despesa?\')">' . csrf_field() . '<input type="hidden" name="action" value="delete_expense"><input type="hidden" name="id" value="' . h((string)$expense['id']) . '"><button class="btn tiny danger" type="submit">Excluir</button></form></div></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function render_category_totals(array $rows): void
{
    if (!$rows) {
        echo '<p class="muted">Sem despesas para agrupar.</p>';
        return;
    }
    echo '<table class="table"><thead><tr><th>Categoria</th><th>Qtd</th><th>Total</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . h(($row['category'] ?? '') ?: 'Geral') . '</td><td>' . h($row['qtd'] ?? 0) . '</td><td><strong>' . h(format_money($row['total'] ?? 0)) . '</strong></td></tr>';
    }
    echo '</tbody></table>';
}

function render_quick_replies_table(array $replies): void
{
    if (!$replies) {
        echo '<p class="muted">Nenhuma resposta rapida cadastrada.</p>';
        return;
    }
    echo '<table class="table"><thead><tr><th>Resposta</th><th>Categoria</th><th>Escopo</th><th>Status</th></tr></thead><tbody>';
    foreach ($replies as $reply) {
        echo '<tr>';
        echo '<td><strong>' . h($reply['title']) . '</strong><br><span class="muted">' . h($reply['shortcut'] ?: '-') . '</span><br>' . h($reply['body']) . '</td>';
        echo '<td><span class="badge">' . h($reply['category']) . '</span></td>';
        echo '<td><span class="badge">' . h(((string)($reply['scope'] ?? '') === 'personal' || !empty($reply['studio_user_id'])) ? 'pessoal' : 'estudio') . '</span></td>';
        echo '<td><span class="badge ' . (!empty($reply['is_active']) ? 'ok' : 'warn') . '">' . (!empty($reply['is_active']) ? 'ativa' : 'inativa') . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function appointment_status_tone(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'pre_agendado' => 'warn',
        'agendado', 'confirmado' => 'ok',
        'atendido', 'finalizado' => 'neutral',
        'cancelado', 'perdido', 'falta' => 'danger',
        'pendente' => 'warn',
        default => 'neutral',
    };
}

function appointment_status_class(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'cancelado', 'perdido', 'falta' => 'status-cancelado',
        'pre_agendado' => 'status-pre-agendado',
        'agendado' => 'status-agendado',
        'confirmado' => 'status-confirmado',
        'atendido', 'finalizado' => 'status-finalizado',
        'pendente' => 'status-pendente',
        default => 'status-neutro',
    };
}

function appointment_status_background(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'cancelado', 'perdido' => '#f3f4f6',
        'falta' => '#fdecec',
        'pre_agendado' => '#fff8db',
        'agendado' => '#e9f8ea',
        'confirmado' => '#d5f0d8',
        'atendido', 'finalizado' => '#e8f0fb',
        'pendente' => '#fff1dd',
        default => '#f8fafc',
    };
}

function appointment_status_border(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'cancelado', 'perdido' => '#d1d5db',
        'falta' => '#f1b5b5',
        'pre_agendado' => '#f3d36b',
        'agendado' => '#8fd39a',
        'confirmado' => '#4fba6a',
        'atendido', 'finalizado' => '#89a9d8',
        'pendente' => '#e8b86d',
        default => '#cbd5e1',
    };
}

function studio_relative_time_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }
    try {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $moment = new DateTimeImmutable($value, $tz);
        $now = new DateTimeImmutable('now', $tz);
        $diff = $now->diff($moment);
        $past = $moment <= $now;
        $units = [
            ['days', 365, 'ano', 'anos'],
            ['days', 30, 'mês', 'meses'],
            ['days', 7, 'semana', 'semanas'],
            ['h', 1, 'hora', 'horas'],
            ['i', 1, 'minuto', 'minutos'],
            ['s', 1, 'segundo', 'segundos'],
        ];
        foreach ($units as [$prop, $threshold, $singular, $plural]) {
            $amount = (int)($diff->$prop ?? 0);
            if ($prop === 'days') {
                $days = (int)$diff->days;
                if ($days >= $threshold) {
                    $amount = (int)floor($days / $threshold);
                } else {
                    continue;
                }
            } elseif ($amount < $threshold) {
                continue;
            }
            if ($amount <= 0) {
                continue;
            }
            $label = $amount === 1 ? $singular : $plural;
            return $past ? 'há ' . $amount . ' ' . $label : 'em ' . $amount . ' ' . $label;
        }
        return $past ? 'há instantes' : 'agora';
    } catch (Throwable) {
        return $value;
    }
}

function render_whatsapp_table(array $conversations): void
{
    if (!$conversations) {
        echo '<p class="muted">Nenhuma conversa importada ainda. Inicie a sessao do WhatsApp e envie uma mensagem para este numero aparecer aqui.</p>';
        return;
    }
    echo '<table class="table whatsapp-conversations-table"><thead><tr><th>Contato</th><th>Última mensagem</th><th>Modo</th><th>Vínculo</th><th>Situação</th><th>Ações</th></tr></thead><tbody>';
    foreach ($conversations as $conversation) {
        $name = $conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: 'Sem nome'));
        $needsHuman = !empty($conversation['needs_human']);
        $href = app_url('studio_whatsapp_conversation', ['id' => (int)$conversation['id']]);
        $unreadCount = studio_whatsapp_unread_count($conversation, current_studio() ?: []);
        $isUnreplied = $unreadCount > 0;
        $linkedLabel = !empty($conversation['customer_id']) ? 'Cliente vinculado' : (!empty($conversation['lead_id']) ? 'Lead vinculado' : 'Sem vínculo');
        $linkBadgeClass = $linkedLabel === 'Sem vínculo' ? 'warn' : '';
        $statusBadges = [];
        $statusBadges[] = '<span class="badge ' . ($conversation['attendance_mode'] === 'bot' ? 'ok' : '') . '">' . h($conversation['attendance_mode'] === 'bot' ? 'IA' : 'Humano') . '</span>';
        if ($needsHuman) {
            $statusBadges[] = '<span class="badge warn">pediu humano</span>';
        }
        if ($isUnreplied) {
            $statusBadges[] = '<span class="badge danger">sem resposta</span>';
        }
        echo '<tr>';
        echo '<td><a href="' . h($href) . '"><strong>' . h($name) . '</strong></a><br><span class="muted">' . h($conversation['phone']) . '</span><br><span class="muted">' . h((string)($conversation['message_count'] ?? 0)) . ' mensagens</span></td>';
        $messageMoment = (string)($conversation['message_last_at'] ?? $conversation['last_message_at'] ?? '');
        $lastMessage = trim((string)($conversation['last_message_preview'] ?? $conversation['latest_message_preview'] ?? ''));
        echo '<td><strong>' . h($lastMessage !== '' ? $lastMessage : '-') . '</strong><br><span class="muted">' . h(studio_relative_time_label($messageMoment)) . '</span>' . ($isUnreplied ? '<br><span class="badge danger">Nao lida</span>' : '') . '</td>';
        echo '<td>' . implode('<br>', $statusBadges) . '</td>';
        echo '<td><span class="badge ' . h($linkBadgeClass) . '">' . h($linkedLabel) . '</span></td>';
        echo '<td><strong>' . h((string)($conversation['lead_score'] ?? '-')) . '/10</strong><br><span class="muted">' . h($conversation['ai_last_status'] ?: '-') . '</span></td>';
        echo '<td><div class="actions"><a class="btn tiny" href="' . h($href) . '">Abrir</a>';
        if ($isUnreplied) {
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="mark_whatsapp_read"><input type="hidden" name="conversation_id" value="' . h((string)$conversation['id']) . '"><button class="btn tiny secondary" type="submit">Marcar lida</button></form>';
        } else {
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="mark_whatsapp_unread"><input type="hidden" name="conversation_id" value="' . h((string)$conversation['id']) . '"><button class="btn tiny secondary" type="submit">Marcar nao lida</button></form>';
        }
        if (!empty($conversation['lead_id'])) {
            echo '<a class="btn tiny secondary" href="' . h(app_url('studio_lead', ['id' => (int)$conversation['lead_id']])) . '">Lead</a>';
        }
        echo '</div></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function render_chat_messages(array $messages): void
{
    $senderLabels = [
        'human' => 'Atendente',
        'bot' => 'IA',
        'customer' => 'Cliente',
        'system' => 'Sistema',
    ];
    $messageStatusLabels = [
        'read' => 'Lida',
        'delivered' => 'Entregue',
        'sent' => 'Enviada',
        'failed' => 'Falhou',
        'received' => 'Recebida',
        'pending' => 'Pendente',
    ];
    $inferMediaType = static function (string $mime, string $mediaUrl, string $type): string {
        $mime = strtolower(trim($mime));
        $type = strtolower(trim($type));
        $ext = strtolower(pathinfo((string)(parse_url($mediaUrl, PHP_URL_PATH) ?: $mediaUrl), PATHINFO_EXTENSION));
        if ($mime !== '') {
            if (str_starts_with($mime, 'image/')) return 'image';
            if (str_starts_with($mime, 'audio/')) return 'audio';
            if (str_starts_with($mime, 'video/')) return 'video';
        }
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        $videoExts = ['mp4', 'mov', 'm4v', 'avi', 'mkv'];
        $audioExts = ['mp3', 'wav', 'ogg', 'oga', 'opus', 'webm', 'm4a', 'aac'];
        if (in_array($ext, $imageExts, true) || $type === 'image') return 'image';
        if (in_array($ext, $audioExts, true) || $type === 'audio') return 'audio';
        if (in_array($ext, $videoExts, true) || $type === 'video') return 'video';
        return $type ?: 'document';
    };

    echo '<div class="chat-thread d-flex flex-column gap-3">';
    if (!$messages) {
        echo '<div class="alert alert-light border mb-0">Ainda nao ha mensagens registradas nesta conversa.</div>';
    }
    $lastDate = '';
    foreach ($messages as $message) {
        $sentAt = (string)($message['sent_at'] ?? '');
        $dayKey = '';
        if ($sentAt !== '') {
            try {
                $dayKey = (new DateTimeImmutable($sentAt, new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
            } catch (Throwable) {
                $dayKey = substr($sentAt, 0, 10);
            }
        }
        if ($dayKey !== '' && $dayKey !== $lastDate) {
            $lastDate = $dayKey;
            echo '<div class="chat-day-separator"><span>' . h(format_date_pt($dayKey, true)) . '</span></div>';
        }
        $direction = (string)($message['direction'] ?? 'in');
        $class = $direction === 'out' ? 'out' : 'in';
        $body = (string)($message['body'] ?? '');
        $type = (string)($message['message_type'] ?? 'texto');
        $mime = (string)($message['media_mime'] ?? '');
        $mediaUrl = (string)($message['media_url'] ?? '');
        $mediaName = (string)($message['media_file_name'] ?? '');
        $kind = $inferMediaType($mime, $mediaUrl, $type);
        if ($mediaName === '' && $mediaUrl !== '') {
            $mediaName = basename(parse_url($mediaUrl, PHP_URL_PATH) ?: $mediaUrl);
        }
        echo '<div class="chat-message ' . h($class) . '">';
        echo '<div class="chat-bubble card shadow-sm border-0">';
        if ($mediaUrl !== '') {
            if ($kind === 'image') {
                echo '<button type="button" class="chat-media-thumb" onclick="window.openMediaOverlay && window.openMediaOverlay(this.dataset.mediaSrc, this.dataset.mediaTitle, this.dataset.mediaKind)" data-media-src="' . h($mediaUrl) . '" data-media-title="' . h($mediaName ?: 'mídia') . '" data-media-kind="image" aria-label="Abrir imagem em tamanho grande"><img class="img-fluid rounded-3" src="' . h($mediaUrl) . '" alt="' . h($mediaName ?: 'mídia') . '"></button>';
            } elseif ($kind === 'video') {
                echo '<video class="img-fluid rounded-3" src="' . h($mediaUrl) . '" controls></video>';
            } elseif ($kind === 'audio') {
                echo '<audio class="w-100" src="' . h($mediaUrl) . '" controls></audio>';
                if (empty($message['transcricao']) && empty($message['transcript'])) {
                    echo '<button class="btn tiny secondary" type="button" data-transcribe-audio="' . h($message['message_id'] ?? '') . '" data-media-url="' . h($mediaUrl) . '">Transcrever audio</button>';
                }
            } else {
                echo '<a class="muted" href="' . h($mediaUrl) . '" target="_blank" rel="noopener">Abrir anexo' . ($mediaName !== '' ? ': ' . h($mediaName) : '') . '</a>';
            }
        }
        if ($body !== '') {
            echo '<p>' . nl2br(h($body)) . '</p>';
        } elseif ($type !== 'texto' && $mediaUrl === '') {
            echo '<p>' . h('[' . $type . ']') . '</p>';
        }
        $transcribedText = (string)($message['transcricao'] ?? $message['transcript'] ?? '');
        $transcribedError = (string)($message['transcricao_erro'] ?? $message['transcript_error'] ?? '');
        if ($transcribedText !== '') {
            echo '<div class="chat-transcription-result">' . h($transcribedText) . '</div>';
        }
        if ($transcribedError !== '') {
            echo '<div class="chat-transcription-error">' . h($transcribedError) . '</div>';
        }
        $senderKey = strtolower(trim((string)($message['sender_type'] ?? '')));
        $senderLabel = $senderLabels[$senderKey] ?? ($direction === 'out' ? 'Atendente' : 'Cliente');
        $messageStatusKey = strtolower(trim((string)($message['status'] ?? '')));
        $messageMeta = [$senderLabel, format_datetime_pt((string)($message['sent_at'] ?? ''))];
        if ($messageStatusKey !== '') {
            $messageMeta[] = $messageStatusLabels[$messageStatusKey] ?? ucfirst(str_replace('_', ' ', $messageStatusKey));
        }
        echo '<span class="text-muted small d-block mt-2">' . h(implode(' • ', $messageMeta)) . '</span>';
        echo '</div></div>';
    }
    echo '</div>';
}

function render_report_table(array $rows, string $labelKey): void
{
    if (!$rows) {
        echo '<p class="muted">Sem dados para este relatorio.</p>';
        return;
    }
    echo '<table class="table"><thead><tr><th>Grupo</th><th>Qtd</th><th>Total</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $overlayText = trim((string)(($row[$labelKey] ?? '') . ' ' . ($row['qtd'] ?? '') . ' ' . ($row['total'] ?? '')));
        echo '<tr data-overlay-item data-overlay-text="' . h($overlayText) . '">';
        echo '<td>' . h(($row[$labelKey] ?? '') ?: 'sem_informacao') . '</td>';
        echo '<td>' . h($row['qtd'] ?? 0) . '</td>';
        echo '<td><strong>' . h(format_money($row['total'] ?? 0)) . '</strong></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
