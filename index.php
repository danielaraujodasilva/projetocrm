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
$page = (string)($_GET['page'] ?? 'dashboard');

if ($page === 'lead_public_update') {
    $studio = require_studio();
    $dbStatus = studio_db_status_for($studio);
    if (!$dbStatus['ok']) {
        render_studio_db_missing($studio, $dbStatus['error']);
        exit;
    }
    $leadId = (int)($_GET['lead'] ?? 0);
    $token = trim((string)($_GET['token'] ?? ''));
    $lead = $leadId > 0 ? studio_find_lead($studio, $leadId) : null;
    if (!$lead || $token === '' || trim((string)($lead['public_update_token'] ?? '')) !== $token) {
        http_response_code(404);
        echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link invalido</title><link rel="stylesheet" href="' . h(app_asset_url('assets/app.css')) . '"></head><body><main class="container" style="padding:40px 16px"><section class="panel"><h1>Link invalido</h1><p class="muted">Esse link nao existe ou expirou.</p></section></main></body></html>';
        exit;
    }
    $customer = !empty($lead['customer_id']) ? studio_find_customer($studio, (int)$lead['customer_id']) : null;
    $customerSeed = is_array($customer) ? $customer : [];
    $missingFields = [];
    foreach ([
        'name' => 'Nome',
        'phone' => 'Telefone',
        'interest' => 'Interesse',
        'birth_date' => 'Data de nascimento',
        'allergies' => 'Alergias',
        'health_conditions' => 'Condições de saúde',
        'data_processing_consent' => 'Consentimento LGPD',
    ] as $field => $label) {
        $value = $field === 'interest' ? (string)($lead[$field] ?? '') : (string)($customerSeed[$field] ?? $lead[$field] ?? '');
        if (trim($value) === '') {
            $missingFields[$field] = $label;
        }
    }
    render_public_page('Atualizar cadastro', 'Complete seus dados para agilizar o atendimento.', function () use ($lead, $leadId, $token, $customerSeed) {
        $checked = static fn(string $field): string => !empty($customerSeed[$field]) ? ' checked' : '';
        $value = static fn(string $field, string $fallback = ''): string => (string)($customerSeed[$field] ?? $fallback);
        echo '<section class="panel" style="max-width:980px;margin:0 auto"><h1>Atualize seu cadastro</h1><p class="muted">Preencha sua ficha completa. Isso ajuda a transformar seu lead em cliente e deixa os próximos atendimentos mais seguros e rápidos.</p>';
        echo '<form class="form" method="post">';
        echo '<input type="hidden" name="action" value="public_lead_update"><input type="hidden" name="lead_id" value="' . h((string)$leadId) . '"><input type="hidden" name="token" value="' . h($token) . '">';
        echo '<div class="panel soft" style="margin-top:12px"><h2>Dados básicos</h2><div class="grid cols-2"><div class="field"><label>Nome</label><input name="name" value="' . h($value('name', (string)($lead['name'] ?? ''))) . '" placeholder="Seu nome completo"></div><div class="field"><label>Telefone</label><input name="phone" value="' . h($value('phone', (string)($lead['phone'] ?? ''))) . '" placeholder="Seu telefone"></div></div><div class="grid cols-2"><div class="field"><label>Email</label><input name="email" type="email" value="' . h($value('email')) . '"></div><div class="field"><label>Instagram</label><input name="instagram" value="' . h($value('instagram')) . '"></div></div><div class="grid cols-3"><div class="field"><label>Data de nascimento</label><input type="date" name="birth_date" value="' . h($value('birth_date')) . '"></div><div class="field"><label>Documento</label><input name="document_number" value="' . h($value('document_number')) . '"></div><div class="field"><label>Profissão</label><input name="occupation" value="' . h($value('occupation')) . '"></div></div><div class="field"><label>Interesse</label><input name="interest" value="' . h((string)($lead['interest'] ?? '')) . '" placeholder="O que você quer fazer?"></div></div>';
        echo '<div class="panel soft" style="margin-top:12px"><h2>Endereço</h2><div class="grid cols-3"><div class="field"><label>CEP</label><input name="address_zip" value="' . h($value('address_zip')) . '"></div><div class="field"><label>Estado</label><input name="address_state" value="' . h($value('address_state')) . '"></div><div class="field"><label>Cidade</label><input name="address_city" value="' . h($value('address_city')) . '"></div></div><div class="field"><label>Rua</label><input name="address_street" value="' . h($value('address_street')) . '"></div><div class="grid cols-3"><div class="field"><label>Número</label><input name="address_number" value="' . h($value('address_number')) . '"></div><div class="field"><label>Complemento</label><input name="address_complement" value="' . h($value('address_complement')) . '"></div><div class="field"><label>Bairro</label><input name="address_neighborhood" value="' . h($value('address_neighborhood')) . '"></div></div><div class="field"><label>Referência</label><input name="address_reference" value="' . h($value('address_reference')) . '"></div></div>';
        echo '<div class="panel soft" style="margin-top:12px"><h2>Anamnese</h2><div class="grid cols-2"><div class="field"><label>Nome de emergência</label><input name="emergency_contact_name" value="' . h($value('emergency_contact_name')) . '"></div><div class="field"><label>Telefone de emergência</label><input name="emergency_contact_phone" value="' . h($value('emergency_contact_phone')) . '"></div></div><div class="grid cols-2"><div class="field"><label>Região do corpo</label><input name="body_area" value="' . h($value('body_area')) . '"></div><div class="field"><label>Estilo de referência</label><input name="reference_style" value="' . h($value('reference_style')) . '"></div></div><div class="grid cols-2"><div class="field"><label>Já possui tatuagens?</label><textarea name="previous_tattoos">' . h($value('previous_tattoos')) . '</textarea></div><div class="field"><label>Resistência à dor</label><select name="pain_tolerance"><option value="">Selecionar</option><option value="baixa"' . (($value('pain_tolerance') === 'baixa') ? ' selected' : '') . '>Baixa</option><option value="media"' . (($value('pain_tolerance') === 'media') ? ' selected' : '') . '>Média</option><option value="alta"' . (($value('pain_tolerance') === 'alta') ? ' selected' : '') . '>Alta</option></select></div></div><div class="grid cols-2"><div class="field"><label>Alergias</label><textarea name="allergies">' . h($value('allergies')) . '</textarea></div><div class="field"><label>Medicamentos</label><textarea name="medications">' . h($value('medications')) . '</textarea></div></div><div class="grid cols-2"><div class="field"><label>Condições de saúde</label><textarea name="health_conditions">' . h($value('health_conditions')) . '</textarea></div><div class="field"><label>Condições de pele</label><textarea name="skin_conditions">' . h($value('skin_conditions')) . '</textarea></div></div><div class="grid cols-2"><div class="field"><label>Histórico de queloide</label><input name="keloid_history" value="' . h($value('keloid_history')) . '"></div><div class="field"><label>Uso de anticoagulantes</label><input name="anticoagulants" value="' . h($value('anticoagulants')) . '"></div></div><div class="grid cols-2"><div class="field"><label>Diabetes</label><input name="diabetes" value="' . h($value('diabetes')) . '"></div><div class="field"><label>Problemas de cicatrização</label><input name="healing_issues" value="' . h($value('healing_issues')) . '"></div></div></div>';
        echo '<div class="panel soft" style="margin-top:12px"><h2>Consentimentos e comunicação</h2><div class="grid cols-2"><label class="checkline"><input type="checkbox" name="data_processing_consent" value="1"' . $checked('data_processing_consent') . '> Autorizo o tratamento dos meus dados para atendimento</label><label class="checkline"><input type="checkbox" name="marketing_opt_in" value="1"' . $checked('marketing_opt_in') . '> Quero receber marketing</label><label class="checkline"><input type="checkbox" name="whatsapp_opt_in" value="1"' . $checked('whatsapp_opt_in') . '> Autorizo mensagens por WhatsApp</label><label class="checkline"><input type="checkbox" name="sms_opt_in" value="1"' . $checked('sms_opt_in') . '> Autorizo mensagens por SMS</label><label class="checkline"><input type="checkbox" name="email_opt_in" value="1"' . $checked('email_opt_in') . '> Autorizo mensagens por email</label><label class="checkline"><input type="checkbox" name="push_opt_in" value="1"' . $checked('push_opt_in') . '> Autorizo notificações push no futuro</label><label class="checkline"><input type="checkbox" name="social_network_opt_in" value="1"' . $checked('social_network_opt_in') . '> Aceito marcação nas redes sociais</label><label class="checkline"><input type="checkbox" name="share_before_after_opt_in" value="1"' . $checked('share_before_after_opt_in') . '> Autorizo uso de fotos antes/depois</label></div><div class="field"><label>Canais preferidos</label><input name="marketing_channels" value="' . h($value('marketing_channels')) . '" placeholder="WhatsApp, email, push..."></div><div class="field"><label>Redes sociais</label><input name="social_networks" value="' . h($value('social_networks')) . '" placeholder="@perfil no Instagram, TikTok, etc."></div></div>';
        echo '<div class="panel soft" style="margin-top:12px"><h2>Observações finais</h2><div class="field"><label>Observações</label><textarea name="notes">' . h($value('notes')) . '</textarea></div></div>';
        echo '<button class="btn" type="submit">Salvar cadastro completo</button>';
        if ($missingFields) {
            echo '<p class="muted" style="margin-top:12px">Campos ainda em branco: ' . h(implode(', ', array_values($missingFields))) . '</p>';
        }
        echo '</form></section>';
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
            if (login_admin((string)$_POST['email'], (string)$_POST['password'])) {
                flash_set('success', 'Login realizado.');
                redirect_to('dashboard');
            }
            flash_set('error', 'Email ou senha invalidos.');
            redirect_to('login');
        }

        if ($action === 'studio_login') {
            if (login_studio_user((string)$_POST['email'], (string)$_POST['password'])) {
                flash_set('success', 'Login do estudio realizado.');
                redirect_to('studio_home');
            }
            flash_set('error', 'Email ou senha invalidos para o estudio.');
            redirect_to('studio_login');
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
            $customerId = studio_save_customer($studio, $_POST);
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
            $leadId = studio_save_lead($studio, $_POST);
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
            flash_set('success', 'Lead movido no funil.');
            if (!empty($_POST['return_to_detail'])) {
                redirect_to('studio_lead', ['id' => $leadId]);
            }
            redirect_to('studio_leads');
        }

        if ($action === 'save_appointment') {
            $studio = require_studio();
            studio_save_appointment($studio, $_POST);
            flash_set('success', 'Agenda salva.');
            if (!empty($_POST['return_to_conversation'])) {
                redirect_to('studio_whatsapp_conversation', ['id' => (int)$_POST['return_to_conversation']]);
            }
            if (!empty($_POST['return_to_lead'])) {
                redirect_to('studio_lead', ['id' => (int)$_POST['return_to_lead']]);
            }
            if (!empty($_POST['return_to_customer'])) {
                redirect_to('studio_customer', ['id' => (int)$_POST['return_to_customer']]);
            }
            redirect_to('studio_agenda');
        }

        if ($action === 'mark_appointment_status') {
            $studio = require_studio();
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $newStatus = trim((string)($_POST['status'] ?? 'falta'));
            studio_update_appointment_status($studio, $appointmentId, $newStatus);
            flash_set('success', 'Status do agendamento atualizado.');
            $redirectDate = trim((string)($_POST['appointment_date'] ?? ''));
            if ($redirectDate !== '') {
                redirect_to('studio_agenda', ['date' => $redirectDate, 'appointment_id' => $appointmentId]);
            }
            redirect_to('studio_agenda');
        }

        if ($action === 'delete_appointment') {
            $studio = require_studio();
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            studio_delete_appointment($studio, $appointmentId);
            flash_set('success', 'Agendamento excluido.');
            $redirectDate = trim((string)($_POST['appointment_date'] ?? ''));
            if ($redirectDate !== '') {
                redirect_to('studio_agenda', ['date' => $redirectDate]);
            }
            redirect_to('studio_agenda');
        }

        if ($action === 'public_lead_update') {
            $studio = require_studio();
            $leadId = (int)($_POST['lead_id'] ?? 0);
            $token = trim((string)($_POST['token'] ?? ''));
            if ($leadId <= 0 || $token === '') {
                throw new RuntimeException('Link invalido.');
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
            foreach ($candidates as $candidate) {
                $uid = (string)($candidate['uid'] ?? '');
                if ($uid === '') {
                    continue;
                }
                $item = $_POST['items'][$uid] ?? null;
                if (!is_array($item) || empty($item['selected'])) {
                    continue;
                }
                $conflicts = $candidate['conflicts'] ?? [];
                $allowConflict = !empty($item['allow_conflict']);
                if ($conflicts && !$allowConflict) {
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
                    'raw_title' => (string)($candidate['raw_title'] ?? ''),
                    'description_original' => (string)($candidate['description_original'] ?? ''),
                    'notes' => trim((string)($candidate['notes'] ?? '')),
                    'interest' => trim((string)($item['interest'] ?? $candidate['interest'] ?? '')),
                    'phone' => normalize_phone((string)($item['phone'] ?? $candidate['phone'] ?? '')),
                    'name' => $name,
                    'value' => (float)str_replace(',', '.', (string)($item['value'] ?? $candidate['value'] ?? 0)),
                    'date' => $startDate,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'appointment_status' => (string)($item['appointment_status'] ?? $candidate['appointment_status'] ?? 'confirmado'),
                    'status' => (string)($item['status'] ?? $candidate['status'] ?? 'agendado'),
                    'pipeline_stage' => (string)($item['pipeline_stage'] ?? $candidate['pipeline_stage'] ?? 'agendado'),
                    'lead_score' => (int)($item['lead_score'] ?? $candidate['lead_score'] ?? 6),
                    'allow_conflict' => $allowConflict,
                ];
            }
            $result = studio_import_calendar_events($studio, $selectedItems);
            $_SESSION['calendar_import_last_batch'] = [
                'studio_id' => (int)$studio['id'],
                'uids' => array_values(array_map(static fn(array $item): string => (string)$item['uid'], $selectedItems)),
                'created_at' => time(),
            ];
            unset($_SESSION['calendar_import_preview'][$token]);
            flash_set('success', 'Importacao revisada concluida: ' . (int)$result['appointments_created'] . ' agendamentos criados e ' . (int)$result['duplicates_skipped'] . ' duplicados ignorados.');
            redirect_to('studio_agenda');
        }

        if ($action === 'undo_calendar_import') {
            $studio = require_studio();
            $batch = $_SESSION['calendar_import_last_batch'] ?? null;
            if (!is_array($batch) || (int)($batch['studio_id'] ?? 0) !== (int)$studio['id']) {
                throw new RuntimeException('Nao existe uma importacao recente para desfazer.');
            }
            $result = studio_revert_import_calendar_events($studio, $batch['uids'] ?? []);
            unset($_SESSION['calendar_import_last_batch']);
            flash_set('success', 'Importacao desfeita: ' . (int)($result['appointments_deleted'] ?? 0) . ' agendamentos removidos.');
            redirect_to('studio_agenda');
        }

        if ($action === 'save_artist') {
            $studio = require_studio();
            studio_save_artist($studio, $_POST);
            flash_set('success', 'Tatuador salvo.');
            redirect_to('studio_agenda');
        }

        if ($action === 'save_expense') {
            $studio = require_studio();
            studio_save_expense($studio, $_POST);
            flash_set('success', 'Despesa salva.');
            redirect_to('studio_finance');
        }

        if ($action === 'save_quick_reply') {
            $studio = require_studio();
            studio_save_quick_reply($studio, $_POST);
            flash_set('success', 'Resposta rapida salva.');
            if (!empty($_POST['return_to_settings'])) {
                redirect_to('studio_settings', ['tab' => (string)($_POST['settings_tab'] ?? 'quick_replies')]);
            }
            redirect_to('studio_quick_replies');
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

        if ($action === 'send_whatsapp_message') {
            $studio = require_studio();
            studio_send_whatsapp_message($studio, $_POST);
            flash_set('success', 'Mensagem enviada pelo WhatsApp.');
            if (!empty($_POST['conversation_id'])) {
                redirect_to('studio_whatsapp_conversation', ['id' => (int)$_POST['conversation_id']]);
            }
            redirect_to('studio_whatsapp');
        }

        if ($action === 'update_whatsapp_conversation') {
            $studio = require_studio();
            studio_update_whatsapp_conversation($studio, $_POST);
            flash_set('success', 'Conversa atualizada.');
            redirect_to('studio_whatsapp_conversation', ['id' => (int)($_POST['conversation_id'] ?? 0)]);
        }

        if ($action === 'update_whatsapp_profile') {
            $studio = require_studio();
            studio_update_whatsapp_profile($studio, $_POST);
            flash_set('success', 'Cadastro, lead e conversa atualizados.');
            redirect_to('studio_whatsapp_conversation', ['id' => (int)($_POST['conversation_id'] ?? 0)]);
        }

        if ($action === 'ask_studio_data_assistant') {
            $studio = require_studio();
            $_SESSION['studio_data_assistant_result'] = studio_data_assistant_answer($studio, (string)($_POST['question'] ?? ''));
            redirect_to('studio_data_assistant');
        }

        if ($action === 'save_studio_settings') {
            $studio = require_studio();
            studio_save_settings($studio, $_POST);
            flash_set('success', 'Configuracoes salvas.');
            redirect_to('studio_settings', ['tab' => (string)($_POST['settings_tab'] ?? 'studio')]);
        }
    } catch (Throwable $e) {
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
    logout_studio_user();
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
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="stylesheet" href="' . h(app_asset_url('assets/app.css')) . '"></head><body>';
    echo '<input type="text" readonly class="app-build-badge-input" data-build-version="' . h(app_build_version() . '-ui') . '" value="' . h(app_build_version() . '-ui') . '" title="Clique para selecionar a versao">';
}

function render_public_head(string $title, string $description): void
{
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="description" content="' . h($description) . '">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="stylesheet" href="' . h(app_asset_url('assets/app.css')) . '">';
    echo '</head><body class="public-page">';
    echo '<input type="text" readonly class="app-build-badge-input" data-build-version="' . h(app_build_version() . '-ui') . '" value="' . h(app_build_version() . '-ui') . '" title="Clique para selecionar a versao">';
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
        badge.select();
        badge.setSelectionRange(0, version.length);
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
}

function render_auth_page(string $title, string $subtitle, callable $content, ?array $flash): void
{
    render_head($title);
    echo '<div class="auth-page"><main class="auth-card">';
    echo '<h1>' . h($title) . '</h1><p>' . h($subtitle) . '</p>';
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
    echo '<div class="shell">';
    echo '<aside class="sidebar">';
    echo '<div class="brand"><span class="brand-mark">CRM</span><span>Projeto CRM</span></div>';
    echo '<nav class="nav">';
    echo '<a class="' . ($active === 'dashboard' ? 'active' : '') . '" href="' . h(app_url('dashboard')) . '">Painel</a>';
    echo '<a class="' . ($active === 'studios' ? 'active' : '') . '" href="' . h(app_url('studios')) . '">Estudios</a>';
    echo '<a class="' . ($active === 'plans' ? 'active' : '') . '" href="' . h(app_url('plans')) . '">Planos</a>';
    echo '<a class="' . ($active === 'new_studio' ? 'active' : '') . '" href="' . h(app_url('new_studio')) . '">Novo estudio</a>';
    echo '<a href="' . h(app_url('logout')) . '">Sair</a>';
    echo '</nav></aside>';
    echo '<main class="main">';
    echo '<div class="topbar"><div><h1>' . h($title) . '</h1><p>' . h($subtitle) . '</p></div>';
    echo '<span class="badge">' . h($admin['name'] ?? 'Gerente') . '</span></div>';
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
    echo '<div class="shell">';
    echo '<aside class="sidebar">';
    echo '<div class="brand"><span class="brand-mark">CRM</span><span>' . h($user['studio_name'] ?? 'Estudio') . '</span></div>';
    echo '<nav class="nav">';
    echo '<a class="' . ($active === 'home' ? 'active' : '') . '" href="' . h(app_url('studio_home')) . '">Inicio</a>';
    echo '<a class="' . ($active === 'people' ? 'active' : '') . '" href="' . h(app_url('studio_people')) . '">Pessoas</a>';
    echo '<a class="' . ($active === 'agenda' ? 'active' : '') . '" href="' . h(app_url('studio_agenda')) . '">Agenda</a>';
    echo '<a class="' . ($active === 'whatsapp' ? 'active' : '') . '" href="' . h(app_url('studio_whatsapp')) . '">WhatsApp</a>';
    echo '<a class="' . ($active === 'finance' ? 'active' : '') . '" href="' . h(app_url('studio_finance')) . '">Financeiro</a>';
    echo '<a class="' . ($active === 'settings' ? 'active' : '') . '" href="' . h(app_url('studio_settings')) . '">Configuracoes</a>';
    echo '<a class="' . ($active === 'reports' ? 'active' : '') . '" href="' . h(app_url('studio_reports')) . '">Relatorios</a>';
    echo '<a class="' . ($active === 'assistant' ? 'active' : '') . '" href="' . h(app_url('studio_data_assistant')) . '">Assistente IA</a>';
    echo '<a href="' . h(app_url('studio_logout')) . '">Sair</a>';
    echo '</nav></aside>';
    echo '<main class="main">';
    echo '<div class="topbar"><div><h1>' . h($title) . '</h1><p>' . h($subtitle) . '</p></div>';
    echo '<span class="badge">' . h($user['name'] ?? 'Usuario') . '</span></div>';
    render_flash($flash);
    $content();
    echo '</main></div>';
    render_scripts();
    echo '</body></html>';
}

function render_public_page(string $title, string $subtitle, callable $content): void
{
    render_public_head($title, $subtitle);
    echo '<div class="public-page-wrap">';
    $content();
    echo '</div>';
    render_scripts();
    echo '</body></html>';
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
            ['label' => 'Clientes/leads', 'key' => 'lead_limit'],
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
    render_auth_page('Entrar como gerente', 'Acesso administrativo da plataforma.', function () {
        echo '<form class="form" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="login">';
        echo '<div class="field"><label>Email</label><input name="email" type="text" inputmode="email" required autocomplete="email"></div>';
        echo '<div class="field"><label>Senha</label><input name="password" type="password" required autocomplete="current-password"></div>';
        echo '<button class="btn" type="submit">Entrar no painel</button>';
        echo '</form>';
    }, $flash);
    exit;
}

if ($page === 'studio_login') {
    render_auth_page('Entrar no CRM do estudio', 'Acesso operacional do estudio cadastrado.', function () {
        echo '<form class="form" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="studio_login">';
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

$studioPages = ['studio_home', 'studio_people', 'studio_leads', 'studio_lead', 'studio_customers', 'studio_customer', 'studio_agenda', 'studio_whatsapp', 'studio_whatsapp_conversation', 'studio_finance', 'studio_quick_replies', 'studio_reports', 'studio_data_assistant', 'studio_settings'];
if (in_array($page, $studioPages, true) && !current_studio_user()) {
    redirect_to('studio_login');
}

if ($page === 'studio_home') {
    $user = current_studio_user();
    $studio = require_studio();
    render_studio_shell('Inicio do CRM', 'Resumo operacional da instancia ' . (string)$user['studio_name'] . '.', 'home', function () use ($studio, $user) {
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
        $metaCampaignSummary = count($metaCampaignItems) . ' leads/conversas identificados pela frase inicial configurada hoje.';
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
                'max_clients' => ['label' => 'clientes/leads', 'count' => studio_lead_count($studio)],
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
            'scheduled_month' => [
                'title' => 'Agendado de hoje ate o fim do mes',
                'summary' => 'Total projetado a partir de hoje: ' . format_money($scheduledToEndOfMonth),
                'type' => 'scheduled_month',
                'items' => $scheduledMonthItems,
                'filters' => [
                    '7d' => 'Próximos 7 dias',
                    '15d' => 'Próximos 15 dias',
                    'month' => 'Este mês',
                    'next_month' => 'Mês que vem',
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
                'summary' => 'Horario, cliente, status, valor e sinal do dia corrente.',
                'type' => 'appointments',
                'kind' => 'appointment',
                'items' => $todayAppointments,
            ],
            'meta_campaign' => [
                'title' => 'Leads do Meta',
                'summary' => $metaCampaignSummary,
                'type' => 'meta_campaign',
                'default_range' => 'today',
                'tracking_hint' => implode(' | ', studio_meta_campaign_phrases($studio)),
                'items' => $metaCampaignItems,
                'filters' => array_map(static fn(array $range): string => (string)$range['label'], $metaCampaignRanges),
                'rangeMap' => $metaCampaignRangeMap,
                'all_items' => $metaCampaignAllItems,
                'today_iso' => $current->format('Y-m-d'),
            ],
            'whatsapp_conversations' => [
                'title' => 'Conversas do WhatsApp que precisam de resposta',
                'summary' => plan_allows('whatsapp')
                    ? ('Aguardando resposta: ' . count($pendingWhatsappConversations) . ' | Pediram humano: ' . count($needsHumanConversations))
                    : 'WhatsApp não liberado no plano atual.',
                'type' => 'whatsapp',
                'items' => array_slice(array_values($whatsappConversationItems), 0, 10),
                'filterLabel' => 'Filtrar conversas',
            ],
            'free_windows' => [
                'title' => 'Proximos horarios livres',
                'summary' => 'Primeiras janelas livres reais encontradas na agenda.',
                'type' => 'availability',
                'items' => array_slice($nextAvailableSlots, 0, 12),
                'filters' => [
                    '3d' => '3 dias',
                    '7d' => '7 dias',
                    '15d' => '15 dias',
                    'month' => 'Este mês',
                    'next_month' => 'Mês que vem',
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
                'summary' => 'Dias uteis restantes: ' . $remainingWorkDays . ' | slots por dia: ' . $slotCount . ' | vagas livres estimadas: ' . $availableSlots,
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
                                'month' => 'Este mês',
                                'next_month' => 'Mês que vem',
                            ][$rangeKey] ?? $rangeKey,
                            'items' => studio_schedule_available_slots($studio, $days, $rangeInfo['start'] ?? null),
                        ];
                    }
                    return $result;
                })($studio),
            ],
            'month_result' => [
                'title' => 'Resultado simples do mes',
                'summary' => 'Agenda no mes: ' . format_money($stats['month_revenue']) . ' | Despesas: ' . format_money($stats['month_expenses']) . ' | Saldo: ' . format_money($stats['month_revenue'] - $stats['month_expenses']),
                'type' => 'finance',
                'items' => [
                    ['label' => 'Agenda no mes', 'value' => format_money($stats['month_revenue'])],
                    ['label' => 'Despesas no mes', 'value' => format_money($stats['month_expenses'])],
                    ['label' => 'Saldo simples', 'value' => format_money($stats['month_revenue'] - $stats['month_expenses'])],
                ],
            ],
            'open_value' => [
                'title' => 'Valor em oportunidades abertas',
                'summary' => 'Soma estimada dos leads ainda nao perdidos ou fechados: ' . format_money($stats['open_value']),
                'type' => 'finance',
                'items' => [
                    ['label' => 'Oportunidades abertas', 'value' => format_money($stats['open_value']), 'detail' => 'Leads em aberto e em conversa que ainda podem virar agendamento.'],
                    ['label' => 'Leads no funil', 'value' => (string)$stats['leads'], 'detail' => 'Quantidade atual de leads ativos no sistema.'],
                    ['label' => 'Clientes cadastrados', 'value' => (string)$stats['customers'], 'detail' => 'Base total de clientes no estúdio.'],
                ],
            ],
            'appointments' => [
                'title' => 'Proximos atendimentos',
                'summary' => (string)$stats['appointments'] . ' atendimentos futuros ativos.',
                'kind' => 'appointment',
                'type' => 'appointments',
                'items' => array_slice(array_map($decorateAppointmentCard, $appointments), 0, 8),
                'filters' => [
                    '7d' => 'Próximos 7 dias',
                    '15d' => 'Próximos 15 dias',
                    'month' => 'Este mês',
                    'next_month' => 'Mês que vem',
                ],
                'rangeMap' => [
                    '7d' => array_map($decorateAppointmentCard, studio_upcoming_appointments($studio, 7)),
                    '15d' => array_map($decorateAppointmentCard, studio_upcoming_appointments($studio, 15)),
                    'month' => $appointmentsMonthItems,
                    'next_month' => $nextMonthItems,
                ],
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
                'today_iso' => $current->format('Y-m-d'),
            ],
        ];

        echo '<section class="panel dashboard-hero" style="margin-bottom:16px">';
        echo '<div class="dashboard-hero-copy">';
        echo '<p class="muted" style="margin:0 0 6px">Hoje, ' . h($current->format('d/m/Y')) . '</p>';
        echo '<div class="dashboard-hero-title"><h2 style="margin:0">' . h($studio['name'] ?? 'Estudio') . '</h2><span class="badge ' . h($dbStatus['ok'] ? 'ok' : 'danger') . '">' . h(current_studio_plan_name()) . '</span><span class="badge ' . h(plan_allows('whatsapp') ? 'ok' : 'warn') . '">' . h($whatsappState) . '</span></div>';
        echo '<p class="muted" style="margin:8px 0 0">Painel operacional do estúdio com agenda, leads, WhatsApp, financeiro e alertas do dia.</p>';
        echo '</div>';
        echo '</section>';

        echo '<section class="grid cols-4 dashboard-kpis dashboard-home-blocks">';
        foreach ([
            ['label' => 'Funil', 'focus' => 'attention_leads', 'value' => (string)$stats['open_leads']],
            ['label' => 'Agenda de hoje', 'focus' => 'today_agenda', 'value' => (string)$todayAppointmentsCount],
            ['label' => 'Leads do Meta', 'focus' => 'meta_campaign', 'value' => (string)count($metaCampaignItems)],
        ] as $action) {
            $focus = $action['focus'] ?? null;
            $detail = $focus ? ('Ver ' . h($action['value'] ?? '0') . ' entradas') : 'Abrir agora';
            echo '<button type="button" class="panel dashboard-stat dashboard-stat-button home-tile home-drill-card home-action-card"' . ($focus ? ' data-home-focus="' . h((string)$focus) . '" onclick="return window.openHomeDrilldown && window.openHomeDrilldown(\'' . h((string)$focus) . '\')"' : ' onclick="window.location.href=\'' . h($action['href']) . '\'"') . '><p class="home-drill-card-title">' . h($action['label']) . '</p><strong class="metric">' . h($detail) . '</strong><span class="muted">' . ($focus ? 'Abrir detalhes' : 'Abrir tela') . '</span></button>';
        }
        foreach ([
            ['value' => (string)$stats['appointments'], 'label' => 'Agendamentos futuros', 'focus' => 'appointments'],
            ['value' => format_money($scheduledToEndOfMonth), 'label' => 'Valor previsto no mês', 'focus' => 'scheduled_month'],
            ['value' => format_money($stats['month_signals']), 'label' => 'Sinais recebidos no mês', 'focus' => 'month_result'],
            ['value' => format_money($financeSummary['expenses_month'] ?? $stats['month_expenses']), 'label' => 'Despesas do mês', 'focus' => 'month_result'],
            ['value' => format_money(($financeSummary['appointments_month'] ?? $stats['month_revenue']) - ($financeSummary['expenses_month'] ?? $stats['month_expenses'])), 'label' => 'Resultado estimado do mês', 'focus' => 'month_result'],
        ] as $stat) {
            echo '<button type="button" class="panel dashboard-stat dashboard-stat-button home-tile home-drill-card" onclick="return window.openHomeDrilldown && window.openHomeDrilldown(\'' . h($stat['focus']) . '\')" data-home-focus="' . h($stat['focus']) . '"><p class="home-drill-card-title">' . h($stat['label']) . '</p><strong class="metric">' . h($stat['value']) . '</strong><span class="muted">Abrir detalhes</span></button>';
        }
        echo '</section>';
        echo '<div id="homeDrilldownModal" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1100px)"><div class="crm-panel-header"><div><h3 id="homeDrilldownTitle" class="crm-panel-title">Detalhe rapido</h3><p id="homeDrilldownSummary" class="muted" style="margin:4px 0 0"></p></div><button type="button" id="closeHomeDrilldown" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div id="homeDrilldownBody" class="p-4"></div></div></div>';
        echo '<script>window.homeDrilldowns = ' . json_encode($homeDrilldowns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<script src="' . h(app_asset_url('assets/home_drilldown.js')) . '"></script>';
        echo '<script src="' . h(app_asset_url('assets/home_drilldown.js')) . '"></script>';
        echo '<section class="panel" style="margin-top:16px"><div class="actions" style="justify-content:space-between"><div><h2>Alertas operacionais</h2><p class="muted">Situações que pedem ação agora.</p></div><a class="btn secondary" href="' . h(app_url('studio_reports')) . '">Abrir relatórios</a></div>';
        if (!$alerts) {
            echo '<p class="muted">Sem alertas importantes no momento.</p>';
        } else {
            echo '<ul class="alert-list">';
            foreach ($alerts as $alert) {
                $tone = (string)($alert['tone'] ?? 'warn');
                echo '<li class="alert-list-item"><span class="badge ' . h($tone === 'danger' ? 'danger' : ($tone === 'ok' ? 'ok' : 'warn')) . '">' . h($alert['title'] ?? 'Alerta') . '</span><span class="alert-list-text">' . h($alert['description'] ?? '') . '</span>' . (!empty($alert['href']) ? '<a class="btn tiny secondary" href="' . h((string)$alert['href']) . '">Abrir área</a>' : '') . '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
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

        echo '<section class="lead-detail-head">';
        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><div><h2>' . h($customer['name'] ?: 'Cliente sem nome') . '</h2><p class="muted">' . h(($customer['phone'] ?: 'Sem telefone') . ' | ' . ($customer['instagram'] ?: 'sem Instagram')) . '</p></div><a class="btn secondary" href="' . h(app_url('studio_customers')) . '">Voltar</a></div>';
        echo '<div class="grid cols-2" style="margin-top:12px"><div class="panel soft"><strong>' . h($customer['email'] ?: 'Sem email') . '</strong><p class="muted" style="margin:4px 0 0">Email</p></div><div class="panel soft"><strong>' . h($customer['birth_date'] ?: 'Sem data de nascimento') . '</strong><p class="muted" style="margin:4px 0 0">Nascimento</p></div></div>';
        echo '<div class="grid cols-2" style="margin-top:12px"><div class="panel soft"><strong>' . h($customer['body_area'] ?: 'Sem região do corpo') . '</strong><p class="muted" style="margin:4px 0 0">Área</p></div><div class="panel soft"><strong>' . h($customer['reference_style'] ?: 'Sem referência') . '</strong><p class="muted" style="margin:4px 0 0">Estilo</p></div></div>';
        echo '<div class="mini-metrics"><span><strong>' . h((string)count($activity['leads'])) . '</strong><small>Leads</small></span><span><strong>' . h((string)count($activity['appointments'])) . '</strong><small>Agendamentos</small></span><span><strong>' . h((string)count($activity['conversations'])) . '</strong><small>Conversas</small></span></div>';
        echo '</div>';

        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_customer"><input type="hidden" name="id" value="' . h((string)$customerId) . '"><input type="hidden" name="return_to_detail" value="1">';
        echo '<h2>Editar ficha</h2>';
        echo '<div class="grid cols-2"><div class="field"><label>Nome</label><input name="name" required value="' . h($customer['name'] ?? '') . '"></div><div class="field"><label>Telefone</label><input name="phone" value="' . h($customer['phone'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Email</label><input type="email" inputmode="email" name="email" value="' . h($customer['email'] ?? '') . '"></div><div class="field"><label>Instagram</label><input name="instagram" value="' . h($customer['instagram'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Data de nascimento</label><input type="date" name="birth_date" value="' . h($customer['birth_date'] ?? '') . '"></div><div class="field"><label>Documento</label><input name="document_number" value="' . h($customer['document_number'] ?? '') . '"></div><div class="field"><label>Profissão</label><input name="occupation" value="' . h($customer['occupation'] ?? '') . '"></div></div>';
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
    render_studio_shell('Funil de Leads', 'Acompanhe oportunidades, orçamentos e agendamentos do estúdio.', 'leads', function () use ($studio) {
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
                default => true,
            };
        };
        $matchesStage = static function (array $lead) use ($stageFilter): bool {
            return $stageFilter === '' || (string)($lead['pipeline_stage'] ?? '') === $stageFilter;
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

        $openLeads = array_values(array_filter($allLeads, static fn(array $lead): bool => !in_array((string)($lead['status'] ?? ''), ['perdido', 'fechado'], true)));
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

        echo '<section class="panel dashboard-hero" style="margin-bottom:16px">';
        echo '<div class="dashboard-hero-copy">';
        echo '<p class="muted" style="margin:0 0 6px">Funil comercial do estúdio</p>';
        echo '<div class="dashboard-hero-title"><h2 style="margin:0">Funil de Leads</h2><span class="badge ok">' . h(current_studio_plan_name()) . '</span></div>';
        echo '<p class="muted" style="margin:8px 0 0">Acompanhe oportunidades, orçamentos e agendamentos do estúdio.</p>';
        echo '</div>';
        echo '<div class="dashboard-hero-actions">';
        foreach ($leadLinks as $action) {
            echo '<a class="quick-action-card" href="' . h($action['href']) . '"><strong>' . h($action['label']) . '</strong><span class="muted">Abrir agora</span></a>';
        }
        echo '</div>';
        echo '</section>';

        echo '<form class="filter-bar panel" method="get" style="margin-bottom:16px">';
        echo '<input type="hidden" name="page" value="studio_leads">';
        echo '<input name="q" placeholder="Buscar por nome, telefone, interesse ou origem..." value="' . h($filters['q']) . '">';
        echo '<select name="status"><option value="">Todos os status</option>';
        foreach (lead_status_options() as $key => $label) {
            echo '<option value="' . h($key) . '" ' . ($filters['status'] === $key ? 'selected' : '') . '>' . h($label) . '</option>';
        }
        echo '</select>';
        echo '<select name="source"><option value="">Todas as origens</option>';
        foreach ($sources as $source) {
            echo '<option value="' . h($source) . '" ' . ($filters['source'] === $source ? 'selected' : '') . '>' . h($source) . '</option>';
        }
        echo '</select>';
        echo '<select name="min_score">';
        foreach ([0 => 'Qualquer nota', 4 => 'Nota mínima 4', 7 => 'Nota mínima 7', 8 => 'Quentes (8+)'] as $key => $label) {
            echo '<option value="' . h((string)$key) . '" ' . ($filters['min_score'] === $key ? 'selected' : '') . '>' . h($label) . '</option>';
        }
        echo '</select>';
        echo '<select name="focus"><option value="">Todos os leads</option>';
        foreach ([
            'hot' => 'Quentes',
            'stale' => 'Parados',
            'today' => 'Hoje',
            'pre_agendado' => 'Pré-agendados',
            'agendado' => 'Agendados',
        ] as $key => $label) {
            echo '<option value="' . h($key) . '" ' . ($focus === $key ? 'selected' : '') . '>' . h($label) . '</option>';
        }
        echo '</select>';
        echo '<button class="btn secondary" type="submit">Filtrar</button><a class="btn secondary" href="' . h(app_url('studio_leads')) . '">Limpar</a>';
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
        echo '<div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2>Funil de Leads</h2><p class="muted">Etapas ordenadas, total por coluna e cartões com ação comercial.</p></div><span class="badge">' . h((string)count($openLeads)) . ' leads abertos</span></div>';
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
        echo '<script>(function(){const modal=document.getElementById("pipelineLeadModal");const title=document.getElementById("pipelineLeadModalTitle");const summary=document.getElementById("pipelineLeadModalSummary");const body=document.getElementById("pipelineLeadModalBody");const closeBtn=document.getElementById("closePipelineLeadModal");const index=window.pipelineLeadIndex||{};const token=window.pipelineLeadMoveToken||"";if(!modal||!title||!summary||!body)return;const esc=(value)=>String(value??"").replace(/[&<>"\x27]/g,(ch)=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#39;"}[ch]||ch));const money=(value)=>new Intl.NumberFormat("pt-BR",{style:"currency",currency:"BRL"}).format(Number(value)||0);const formatDate=(value)=>{if(!value)return"-";try{return new Intl.DateTimeFormat("pt-BR",{dateStyle:"short",timeStyle:"short"}).format(new Date(value.replace(" ","T")));}catch(e){return value;}};const statusTone=(status, stale)=>{if(["agendado","pre_agendado"].includes(status)) return "warn"; if(status==="fechado") return "ok"; if(["perdido","cancelado"].includes(status)) return "danger"; return stale ? "warn" : "neutral";};const postMove=async(leadId, stage, status)=>{const formData=new FormData();formData.append("csrf_token",token);formData.append("action","move_lead");formData.append("lead_id",leadId);formData.append("pipeline_stage",stage);formData.append("status",status||"");const response=await fetch(window.location.pathname+window.location.search,{method:"POST",body:formData});if(!response.ok) throw new Error("Nao foi possivel mover o lead."); location.reload();};const close=()=>modal.classList.add("hidden");const open=(leadId)=>{const lead=index[String(leadId)]||null;if(!lead)return;const status=String(lead.status||"");const score=Number(lead.lead_score||0);const stale=lead.updated_at&&lead.updated_at!==""?(()=>{try{return new Date(lead.updated_at) < new Date(Date.now()-24*60*60*1000);}catch(e){return false;}})():false;const badges=[];badges.push(`<span class="drilldown-badge ${statusTone(status, stale)}">${esc(status || "sem status")}</span>`);badges.push(`<span class="drilldown-badge neutral">${esc(String(score))}/10</span>`);if(score>=8) badges.push(`<span class="drilldown-badge ok">Quente</span>`);if((lead.estimated_value||0)>=1000) badges.push(`<span class="drilldown-badge neutral">Alto valor</span>`);if(stale) badges.push(`<span class="drilldown-badge warn">Parado 24h+</span>`);if(lead.artist_name) badges.push(`<span class="drilldown-badge neutral">${esc(lead.artist_name)}</span>`);title.textContent=lead.name || "Lead sem nome";summary.textContent=[lead.phone?`Telefone: ${lead.phone}`:"",lead.source?`Origem: ${lead.source}`:"",lead.pipeline_stage?`Etapa: ${lead.pipeline_stage}`:""].filter(Boolean).join(" · ");const currentIndex=Array.isArray(window.pipelineStageNames)?window.pipelineStageNames.indexOf(String(lead.pipeline_stage||"")):-1;const prevStage=currentIndex>0?window.pipelineStageNames[currentIndex-1]:"";const nextStage=currentIndex>=0&&currentIndex<window.pipelineStageNames.length-1?window.pipelineStageNames[currentIndex+1]:"";body.innerHTML=`<div class="drilldown-panel-grid"><div class="drilldown-panel-summary"><div class="drilldown-kpi"><strong>${esc(money(lead.estimated_value || 0))}</strong><span>Valor estimado</span><small>${esc(lead.interest || "Sem interesse descrito.")}</small></div><div class="drilldown-kpi"><strong>${esc(String(score))}/10</strong><span>Nota</span><small>Criado ${esc(formatDate(lead.created_at))}</small></div><div class="drilldown-kpi highlight"><strong>${esc(formatDate(lead.updated_at || lead.created_at))}</strong><span>Última atualização</span><small>${esc(lead.customer_name || lead.email || lead.notes || "Sem dados adicionais.")}</small></div></div><div class="drilldown-card compact"><div class="lead-card-badges">${badges.join("")}</div><div class="lead-card-submeta"><span class="muted">${esc(lead.phone || "Sem telefone")}</span><span class="muted">Cliente: ${esc(lead.customer_name || "-")}</span><span class="muted">Contato recente: ${esc(lead.last_message_preview || "-")}</span></div><div class="lead-card-actions lead-card-actions-quick">${lead.id ? `<a class="btn tiny secondary" href="index.php?page=studio_lead&id=${encodeURIComponent(lead.id)}">Ver lead</a>` : ""}${lead.phone ? `<a class="btn tiny secondary" href="https://wa.me/${String(lead.phone).replace(/\\D+/g,"")}" target="_blank" rel="noopener">WhatsApp</a>` : ""}${lead.id ? `<a class="btn tiny secondary" href="index.php?page=studio_lead&id=${encodeURIComponent(lead.id)}#lead-schedule-form">Agendar</a>` : ""}</div><div class="lead-card-actions">${prevStage?`<button type="button" class="btn tiny secondary" data-modal-move-stage="${esc(prevStage)}" data-modal-lead-id="${esc(String(lead.id||""))}" data-modal-status="${esc(status)}">Voltar</button>`:""}${nextStage?`<button type="button" class="btn tiny secondary" data-modal-move-stage="${esc(nextStage)}" data-modal-lead-id="${esc(String(lead.id||""))}" data-modal-status="${esc(status)}">Avancar</button>`:""}</div></div></div>`;modal.classList.remove("hidden");};document.querySelectorAll("[data-lead-open]").forEach((btn)=>{btn.addEventListener("click",(event)=>{event.preventDefault();event.stopPropagation();open(btn.getAttribute("data-lead-open"));});});document.querySelectorAll("[data-move-stage]").forEach((btn)=>{btn.addEventListener("click",async(event)=>{event.preventDefault();event.stopPropagation();try{await postMove(btn.getAttribute("data-lead-id")||"0", btn.getAttribute("data-move-stage")||"", btn.getAttribute("data-current-status")||"");}catch(err){alert(err.message||"Erro ao mover lead");}});});document.querySelectorAll("[data-modal-move-stage]").forEach((btn)=>{btn.addEventListener("click",async(event)=>{event.preventDefault();event.stopPropagation();try{await postMove(btn.getAttribute("data-modal-lead-id")||"0", btn.getAttribute("data-modal-move-stage")||"", btn.getAttribute("data-modal-status")||"");}catch(err){alert(err.message||"Erro ao mover lead");}});});let dragLeadId="";document.querySelectorAll(".pipeline-column").forEach((column)=>{column.addEventListener("dragover",(event)=>{event.preventDefault();column.classList.add("drag-over");});column.addEventListener("dragleave",()=>column.classList.remove("drag-over"));column.addEventListener("drop",async(event)=>{event.preventDefault();column.classList.remove("drag-over");const leadId=dragLeadId||event.dataTransfer.getData("text/plain");const stage=column.getAttribute("data-stage")||"";if(!leadId||!stage)return;const lead=index[String(leadId)];if(!lead)return;try{await postMove(leadId, stage, lead.status || "");}catch(err){alert(err.message||"Erro ao mover lead");}});});document.querySelectorAll(".lead-card[draggable=\"true\"]").forEach((card)=>{card.addEventListener("dragstart",(event)=>{dragLeadId=card.getAttribute("data-lead-id")||"";event.dataTransfer.effectAllowed="move";event.dataTransfer.setData("text/plain",dragLeadId);card.classList.add("dragging");});card.addEventListener("dragend",()=>{dragLeadId="";card.classList.remove("dragging");});card.addEventListener("click",(event)=>{if(event.target.closest("a,button")) return; const id=card.getAttribute("data-lead-id"); if(id) open(id);});});if(closeBtn) closeBtn.addEventListener("click",close);modal.addEventListener("click",(event)=>{if(event.target===modal) close();});document.addEventListener("keydown",(event)=>{if(event.key==="Escape") close();});})();</script>';
        echo '</section>';

        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><h2>Leads que pedem atenção</h2><a class="btn secondary" href="' . h(app_url('studio_reports')) . '">Ver alertas</a></div>';
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

        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><h2>Filtro rápido de etapas</h2><span class="badge">Status comercial</span></div>';
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
            echo '</select></div><div class="field"><label>Etapa</label><select name="pipeline_stage">';
            foreach ($stages as $stage) {
                echo '<option value="' . h($stage['name']) . '">' . h($stage['name']) . '</option>';
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
        echo '<div class="mini-metrics"><span><strong>' . h(format_money($lead['estimated_value'] ?? 0)) . '</strong><small>Valor estimado</small></span><span><strong>' . h($lead['status']) . '</strong><small>Status</small></span><span><strong>' . h($lead['pipeline_stage'] ?: '-') . '</strong><small>Etapa</small></span></div>';
        echo '<div class="actions" style="margin-top:14px;gap:8px;flex-wrap:wrap"><a class="btn secondary" href="' . h($publicUpdateUrl) . '" target="_blank" rel="noopener">Link para atualizar cadastro</a><button type="button" class="btn secondary" data-copy-link="' . h($publicUpdateUrl) . '">Copiar link</button></div>';
        echo '</div>';
        echo '<form class="form panel" method="post" id="lead-move-form">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="move_lead"><input type="hidden" name="lead_id" value="' . h((string)$leadId) . '"><input type="hidden" name="return_to_detail" value="1">';
        echo '<div class="actions" style="justify-content:space-between"><h2>Mover no funil</h2><span class="badge">Fluxo</span></div>';
        echo '<div class="field"><label>Etapa</label><select name="pipeline_stage">';
        foreach ($stages as $stage) {
            echo '<option value="' . h($stage['name']) . '" ' . ((string)$stage['name'] === (string)$lead['pipeline_stage'] ? 'selected' : '') . '>' . h($stage['name']) . '</option>';
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
        echo '</select></div><div class="field"><label>Etapa</label><select name="pipeline_stage">';
        foreach ($stages as $stage) {
            echo '<option value="' . h($stage['name']) . '" ' . ((string)$stage['name'] === (string)$lead['pipeline_stage'] ? 'selected' : '') . '>' . h($stage['name']) . '</option>';
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
        $nextAvailableSlots = studio_schedule_available_slots($studio, 14, $current);
        $preScheduledNoSignalCount = (int)studio_db($studio)->query("SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND status = 'pre_agendado' AND COALESCE(deposit_value, 0) <= 0")->fetchColumn();
        $missingArtistCount = (int)studio_db($studio)->query("SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND COALESCE(artist_id, 0) = 0 AND status NOT IN ('cancelado', 'perdido', 'concluido', 'atendido', 'finalizado')")->fetchColumn();
        $missingContactCount = (int)studio_db($studio)->query("SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND COALESCE(customer_id, 0) = 0 AND COALESCE(lead_id, 0) = 0 AND status NOT IN ('cancelado', 'perdido', 'concluido', 'atendido', 'finalizado')")->fetchColumn();
        $selectedAppointmentId = (int)($_GET['appointment_id'] ?? 0);
        $selectedAppointment = $selectedAppointmentId > 0 ? studio_find_appointment($studio, $selectedAppointmentId) : null;
        $importPreviewToken = trim((string)($_GET['ics_preview'] ?? ''));
        $importPreview = $importPreviewToken !== '' ? ($_SESSION['calendar_import_preview'][$importPreviewToken] ?? null) : null;

        echo '<section class="panel"><div class="actions calendar-toolbar">';
        echo '<h2>Calendario</h2>';
        echo '<form class="inline-form" method="post" enctype="multipart/form-data">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="import_calendar_ics">';
        echo '<input type="file" name="ics_file" accept=".ics,text/calendar" required>';
        echo '<button class="btn secondary" type="submit">Importar ICS</button>';
        echo '</form>';
        foreach (['month' => 'Mes', 'week' => 'Semana', 'day' => 'Dia', 'list' => 'Blocos'] as $key => $label) {
            echo '<a class="btn ' . ($view === $key ? '' : 'secondary') . '" href="' . h(app_url('studio_agenda', ['cal_view' => $key, 'date' => $focus->format('Y-m-d')])) . '">' . h($label) . '</a>';
        }
        $prev = calendar_shift_date($view, $focus, -1);
        $next = calendar_shift_date($view, $focus, 1);
        echo '<span class="calendar-spacer"></span>';
        echo '<a class="btn secondary" href="' . h(app_url('studio_agenda', ['cal_view' => $view, 'date' => $prev->format('Y-m-d')])) . '">Anterior</a>';
        echo '<a class="btn secondary" href="' . h(app_url('studio_agenda', ['cal_view' => $view, 'date' => date('Y-m-d')])) . '">Hoje</a>';
        echo '<a class="btn secondary" href="' . h(app_url('studio_agenda', ['cal_view' => $view, 'date' => $next->format('Y-m-d')])) . '">Proximo</a>';
        echo '<button type="button" class="btn secondary" id="openFreeSlotsButton">Próximos horários livres</button>';
        echo '</div>';
        if (is_array($importPreview)) {
            $analysis = $importPreview['analysis'] ?? [];
            $candidates = $analysis['candidates'] ?? [];
            $skipped = $analysis['skipped'] ?? [];
            echo '<section class="panel" style="margin-top:16px;background:linear-gradient(180deg,rgba(48, 91, 255, 0.08),rgba(48, 91, 255, 0.02))">';
            echo '<div class="actions" style="justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">';
            echo '<div><h2>Revisar importacao Google Agenda</h2><p class="muted">Escolha o que importar, ajuste os campos e confirme apenas o que realmente faz sentido para o estúdio.</p></div>';
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
            echo '<button class="btn secondary" type="button" data-import-toggle="all">Selecionar tudo</button>';
            echo '<button class="btn secondary" type="button" data-import-toggle="none">Desmarcar tudo</button>';
            echo '</div>';
            echo '</div>';
            echo '<form method="post" class="import-preview-form" style="margin-top:16px">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="import_calendar_ics_confirm">';
            echo '<input type="hidden" name="import_token" value="' . h($importPreviewToken) . '">';
            echo '<div class="alert-grid" style="margin-bottom:16px">';
            echo '<article class="alert-card"><span class="badge ok">' . h((string)count($candidates)) . '</span><p><strong>Candidatos</strong></p><p class="muted">Eventos prontos para revisar.</p></article>';
            echo '<article class="alert-card"><span class="badge warn">' . h((string)($analysis['duplicates'] ?? 0)) . '</span><p><strong>Possiveis duplicados</strong></p><p class="muted">Eventos ja importados ou muito parecidos com importações anteriores.</p></article>';
            echo '<article class="alert-card"><span class="badge">' . h((string)count($skipped)) . '</span><p><strong>Ignorados</strong></p><p class="muted">Entradas sem sinal claro de atendimento.</p></article>';
            echo '<article class="alert-card"><span class="badge">' . h((string)($analysis['events_total'] ?? 0)) . '</span><p><strong>Total no ICS</strong></p><p class="muted">' . h((string)$importPreview['file_name']) . '</p></article>';
            echo '</div>';
            echo '<div class="stack" style="gap:12px">';
            foreach ($candidates as $candidate) {
                $uid = (string)($candidate['uid'] ?? '');
                $title = (string)($candidate['name'] ?? $candidate['raw_title'] ?? '');
                $rawTitle = (string)($candidate['raw_title'] ?? '');
                $description = (string)($candidate['description_original'] ?? '');
                $notes = (string)($candidate['notes'] ?? '');
                $conflicts = $candidate['conflicts'] ?? [];
                echo '<article class="panel" style="padding:16px;border:1px solid rgba(0,0,0,0.08);box-shadow:none">';
                echo '<div class="actions" style="justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">';
                echo '<label class="form-check" style="display:flex;align-items:flex-start;gap:10px;margin:0;flex:1">';
                echo '<input class="form-check-input import-select" type="checkbox" name="items[' . h($uid) . '][selected]" value="1" checked data-import-row="' . h($uid) . '">';
                echo '<span><strong>' . h($title) . '</strong><br><span class="muted">' . h($rawTitle) . '</span></span>';
                echo '</label>';
                echo '<span class="badge ' . ($conflicts ? 'warn' : '') . '">' . h($conflicts ? 'conflito' : (string)($candidate['reason'] ?? 'candidato')) . '</span>';
                echo '</div>';
                if ($conflicts) {
                    echo '<div class="panel soft" style="margin-top:12px;padding:12px;border:1px solid rgba(255,165,0,0.35);background:rgba(255,165,0,0.06)">';
                    echo '<strong>Conflito com agenda atual</strong>';
                    echo '<div class="stack" style="margin-top:8px;gap:8px">';
                    foreach ($conflicts as $conflict) {
                        $conflictName = (string)($conflict['customer_name'] ?? $conflict['title'] ?? 'Agendamento');
                        $conflictArtist = (string)($conflict['artist_name'] ?? 'sem tatuador');
                        $conflictStart = substr((string)($conflict['start_time'] ?? ''), 0, 5);
                        $conflictEnd = substr((string)($conflict['end_time'] ?? $conflict['start_time'] ?? ''), 0, 5);
                        echo '<div class="panel" style="padding:10px;background:#fff;border:1px solid rgba(0,0,0,0.06)">';
                        echo '<strong>' . h(format_date_pt((string)$conflict['appointment_date']) . ' ' . $conflictStart . ($conflictEnd !== '' ? ' - ' . $conflictEnd : '')) . '</strong>';
                        echo '<div class="muted">' . h($conflictName) . ' · ' . h($conflictArtist) . ' · ' . h((string)($conflict['status'] ?? '')) . '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                    echo '<label class="form-check" style="margin-top:10px;display:flex;gap:10px;align-items:flex-start">';
                    echo '<input class="form-check-input" type="checkbox" name="items[' . h($uid) . '][allow_conflict]" value="1">';
                    echo '<span>Importar mesmo assim e manter este item, mesmo com conflito.</span>';
                    echo '</label>';
                    echo '</div>';
                }
                echo '<div class="grid" style="grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:12px">';
                echo '<label>Nome<input type="text" name="items[' . h($uid) . '][name]" value="' . h((string)$title) . '"></label>';
                echo '<label>Data<input type="date" name="items[' . h($uid) . '][date]" value="' . h((string)($candidate['date'] ?? '')) . '"></label>';
                echo '<label>Início<input type="time" name="items[' . h($uid) . '][start_time]" value="' . h(substr((string)($candidate['start_time'] ?? ''), 0, 5)) . '"></label>';
                echo '<label>Fim<input type="time" name="items[' . h($uid) . '][end_time]" value="' . h(substr((string)($candidate['end_time'] ?? ''), 0, 5)) . '"></label>';
                echo '<label>Telefone<input type="text" name="items[' . h($uid) . '][phone]" value="' . h((string)($candidate['phone'] ?? '')) . '"></label>';
                echo '<label>Valor<input type="text" name="items[' . h($uid) . '][value]" value="' . h((string)($candidate['value'] ?? 0)) . '"></label>';
                echo '<label>Status<input type="text" name="items[' . h($uid) . '][appointment_status]" value="' . h((string)($candidate['appointment_status'] ?? 'confirmado')) . '"></label>';
                echo '<label>Lead<input type="text" name="items[' . h($uid) . '][status]" value="' . h((string)($candidate['status'] ?? 'agendado')) . '"></label>';
                echo '</div>';
                echo '<label style="display:block;margin-top:12px">Interesse/observações<textarea name="items[' . h($uid) . '][interest]" rows="2">' . h(trim($notes !== '' ? $notes . "\n" : '') . trim($description)) . '</textarea></label>';
                echo '<input type="hidden" name="items[' . h($uid) . '][pipeline_stage]" value="' . h((string)($candidate['pipeline_stage'] ?? 'agendado')) . '">';
                echo '<input type="hidden" name="items[' . h($uid) . '][lead_score]" value="' . h((string)($candidate['lead_score'] ?? 6)) . '">';
                echo '</article>';
            }
            if (!$candidates) {
                echo '<div class="alert-card"><p><strong>Nenhum candidato encontrado</strong></p><p class="muted">Esse arquivo não gerou eventos aptos para importação.</p></div>';
            }
            echo '</div>';
            echo '<div class="actions" style="justify-content:space-between;margin-top:16px;gap:12px;flex-wrap:wrap">';
            echo '<p class="muted">Itens com conflito ficam destacados. Por padrão eles não são importados, a menos que você marque a opção de manter mesmo assim.</p>';
            echo '<button class="btn" type="submit">Importar selecionados</button>';
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
                    document.querySelectorAll("[data-import-toggle]").forEach((button) => {
                        button.addEventListener("click", () => {
                            const checked = button.getAttribute("data-import-toggle") === "all";
                            form.querySelectorAll(".import-select").forEach((input) => { input.checked = checked; });
                        });
                    });
                })();
            </script>';
        }
        echo '<div class="alert-grid" style="margin-top:14px">';
        echo '<article class="alert-card"><span class="badge warn">' . h((string)$preScheduledNoSignalCount) . '</span><p><strong>Pré-agendamentos sem sinal</strong></p><p class="muted">Há pré-agendamentos aguardando confirmação financeira.</p></article>';
        echo '<article class="alert-card"><span class="badge ok">' . h((string)count($todayAppointments)) . '</span><p><strong>Agendamentos de hoje</strong></p><p class="muted">Confira a ocupação do dia atual sem sair da agenda.</p></article>';
        echo '<article class="alert-card"><span class="badge danger">' . h((string)$missingArtistCount) . '</span><p><strong>Sem tatuador definido</strong></p><p class="muted">Agendamentos sem tatuador precisam de revisão.</p></article>';
        echo '<article class="alert-card"><span class="badge warn">' . h((string)$missingContactCount) . '</span><p><strong>Sem cliente/lead vinculado</strong></p><p class="muted">Esses agendamentos merecem vínculo para evitar perda de contexto.</p></article>';
        echo '</div>';
        echo '<div id="freeSlotsModal" class="crm-modal hidden"><div class="crm-modal-panel" style="max-width:min(96vw,1100px)"><div class="crm-panel-header"><div><h3 class="crm-panel-title">Próximos horários livres</h3><p class="muted" style="margin:4px 0 0">Primeiras janelas livres encontradas na agenda.</p></div><button type="button" id="closeFreeSlotsModal" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div><div class="p-4"><div class="stack-list">';
        if (!$nextAvailableSlots) {
            echo '<p class="muted">Não foi possível calcular horários livres neste recorte.</p>';
        } else {
            foreach (array_slice($nextAvailableSlots, 0, 12) as $slot) {
                $href = app_url('studio_agenda', ['date' => (string)$slot['date']]) . '#appointment-form';
                echo '<a class="activity-card" href="' . h($href) . '"><strong>' . h((string)$slot['label']) . '</strong><span class="muted">' . h(implode(' · ', array_slice($slot['free_slots'] ?? [], 0, 4))) . '</span><span>' . h((string)count($slot['free_slots'] ?? [])) . ' horários livres</span></a>';
            }
        }
        echo '</div></div></div></div>';
        echo '<script>(function(){const openBtn=document.getElementById("openFreeSlotsButton");const modal=document.getElementById("freeSlotsModal");const closeBtn=document.getElementById("closeFreeSlotsModal");if(!openBtn||!modal)return;openBtn.addEventListener("click",()=>modal.classList.remove("hidden"));if(closeBtn) closeBtn.addEventListener("click",()=>modal.classList.add("hidden"));modal.addEventListener("click",(event)=>{if(event.target===modal) modal.classList.add("hidden");});document.addEventListener("keydown",(event)=>{if(event.key==="Escape") modal.classList.add("hidden");});})();</script>';
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
            echo '<section class="panel" style="margin-top:16px"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2>Detalhes do agendamento</h2><p class="muted">Clique num item da agenda para revisar, editar ou excluir sem perder o contexto.</p></div><a class="btn secondary" href="' . h(app_url('studio_agenda', ['date' => $selectedDate])) . '">Limpar selecao</a></div>';
            echo '<div class="grid cols-2">';
            echo '<div class="panel soft"><p class="muted">Quando</p><h3 style="margin-top:0">' . h(format_date_pt($selectedDate) . ' ' . substr((string)$selectedAppointment['start_time'], 0, 5) . ($selectedAppointment['end_time'] ? ' - ' . substr((string)$selectedAppointment['end_time'], 0, 5) : '')) . '</h3><p class="muted">' . h($selectedAppointment['status']) . '</p></div>';
            echo '<div class="panel soft"><p class="muted">Cliente / Lead</p><h3 style="margin-top:0">' . h($selectedAppointment['customer_name'] ?: $selectedAppointment['lead_name'] ?: $selectedAppointment['title']) . '</h3><p class="muted">' . h($selectedAppointment['artist_name'] ?: 'Sem tatuador') . '</p></div>';
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
            if (!empty($selectedAppointment['description'])) {
                echo '<div class="field"><label>Descricao</label><div class="info-box">' . h($selectedAppointment['description']) . '</div></div>';
            }
            if (!empty($selectedAppointment['reference_image_path'])) {
                $refUrl = app_url((string)$selectedAppointment['reference_image_path']);
                echo '<div class="field"><label>Referencia</label><a class="btn secondary" href="' . h($refUrl) . '" target="_blank" rel="noopener">Abrir imagem de referencia</a></div>';
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
            echo '<a class="btn" href="' . h(app_url('studio_agenda', ['date' => $selectedDate])) . '&appointment_id=' . h((string)(int)$selectedAppointment['id']) . '#appointment-form">Editar este agendamento</a>';
            echo '<form method="post" onsubmit="return confirm(\'Excluir este agendamento?\')" class="inline-form">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="delete_appointment">';
            echo '<input type="hidden" name="appointment_id" value="' . h((string)(int)$selectedAppointment['id']) . '">';
            echo '<input type="hidden" name="appointment_date" value="' . h($selectedDate) . '">';
            echo '<button class="btn secondary" type="submit">Excluir</button>';
            echo '</form>';
            echo '</div></section>';
        }

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
        render_customer_options($customers);
        echo '</select></div><div class="field"><label>Lead</label><select name="lead_id"><option value="">Sem lead</option>';
        render_lead_options($leads);
        echo '</select></div><div class="field"><label>Tatuador</label><select name="artist_id">';
        render_artist_options($artists, default_artist_id($studio) ?? 0);
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
        echo '<section class="panel" style="margin-top:16px"><h2>Agenda cadastrada</h2>';
        render_appointments_table($appointments);
        echo '</section>';
    }, $flash);
    exit;
}

if ($page === 'studio_whatsapp') {
    $studio = require_studio();
    render_studio_shell('WhatsApp', 'Central de conversas e sessao Baileys deste estudio.', 'whatsapp', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $settings = studio_settings($studio);
        $sessionKey = studio_session_key($studio);
        $serviceStatus = studio_whatsapp_service_status($studio);
        $summary = studio_whatsapp_summary($studio);
        $filters = [
            'q' => (string)($_GET['q'] ?? ''),
            'mode' => (string)($_GET['mode'] ?? ''),
            'needs_human' => !empty($_GET['needs_human']),
            'min_score' => (int)($_GET['min_score'] ?? 0),
            'filter' => (string)($_GET['filter'] ?? 'all'),
        ];
        $conversations = studio_list_whatsapp_conversations($studio, $filters);
        $serviceState = (string)($serviceStatus['status'] ?? 'offline');
        $serviceStateLabel = $serviceState === 'connected' ? 'Conectado' : ($serviceState === 'waiting_qr' ? 'Aguardando codigo' : ($serviceState === 'starting' ? 'Iniciando' : 'Nao conectado'));
        $firstConversationHref = !empty($conversations[0]['id']) ? app_url('studio_whatsapp_conversation', ['id' => (int)$conversations[0]['id']]) : app_url('studio_whatsapp');
        echo '<section class="quick-actions-grid whatsapp-quick-links">';
        echo '<a class="panel quick-action-card" href="' . h($firstConversationHref) . '"><strong>' . h($summary['total']) . '</strong><span>Conversas</span><small>Abrir a primeira conversa ativa</small></a>';
        echo '<a class="panel quick-action-card" href="' . h(app_url('studio_whatsapp', ['mode' => 'bot'])) . '"><strong>' . h($summary['bot']) . '</strong><span>Em modo IA</span><small>Ver conversas automáticas</small></a>';
        echo '<a class="panel quick-action-card" href="' . h(app_url('studio_whatsapp', ['needs_human' => 1])) . '"><strong>' . h($summary['needs_human']) . '</strong><span>Pedindo humano</span><small>Atendimentos que pedem atenção</small></a>';
        echo '<a class="panel quick-action-card" href="#wa-session-panel"><strong>' . h($serviceStateLabel) . '</strong><span>Sessao WhatsApp</span><small>Ver conexão e pareamento</small></a>';
        echo '</section>';
        echo '<section class="grid cols-2 whatsapp-overview" style="margin-top:16px">';
        echo '<div class="panel" id="wa-session-panel"><div class="actions" style="justify-content:space-between"><h2>Sessao do WhatsApp</h2>';
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
            echo '<p class="muted">O servico Node ainda nao respondeu. Inicie com <code>npm install</code> e <code>node server.js</code> em <code>services/whatsapp</code>.</p>';
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
        echo '<div class="actions whatsapp-session-actions">';
        echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="start_whatsapp_session"><button class="btn" type="submit">Iniciar pareamento</button></form>';
        echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="disconnect_whatsapp_session"><button class="btn secondary" type="submit">Desconectar</button></form>';
        echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="reset_whatsapp_session"><button class="btn secondary" type="submit">Limpar sessao</button></form>';
        echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="restart_whatsapp_service"><button class="btn secondary" type="submit">Reiniciar servico</button></form>';
        echo '</div>';
        echo '<form method="post" class="inline-form whatsapp-session-actions" style="margin-top:12px;gap:8px;align-items:flex-end;flex-wrap:wrap">' . csrf_field();
        echo '<input type="hidden" name="action" value="request_whatsapp_pairing_code">';
        echo '<div class="field" style="margin:0;min-width:220px"><label>Codigo por telefone</label><input name="pairing_phone" placeholder="5521999999999"></div>';
        echo '<button class="btn secondary" type="submit">Gerar codigo</button>';
        echo '</form>';
        echo '</div>';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_studio_settings">';
        echo '<h2>Configuracao WhatsApp</h2>';
        echo '<div class="field"><label>URL do servico Baileys</label><input name="whatsapp_service_url" value="' . h($settings['whatsapp_service_url'] ?? 'http://localhost:3010') . '"></div>';
        echo '<div class="field"><label>Padrao para conversas novas</label><select name="whatsapp_default_mode">';
        render_options(['human' => 'Humano atende primeiro', 'bot' => 'IA atende primeiro'], (string)($settings['whatsapp_default_mode'] ?? 'human'));
        echo '</select></div>';
        echo '<input type="hidden" name="studio_name" value="' . h($settings['studio_name'] ?? $studio['name']) . '">';
        echo '<input type="hidden" name="ai_model" value="' . h($settings['ai_model'] ?? $studio['ai_model'] ?? 'llama3:8b') . '">';
        echo '<input type="hidden" name="business_rules" value="' . h($settings['business_rules'] ?? $studio['business_rules'] ?? '') . '">';
        if (!empty($settings['ai_enabled'])) {
            echo '<input type="hidden" name="ai_enabled" value="1">';
        }
        echo '<label class="checkline"><input type="checkbox" name="whatsapp_enabled" value="1" ' . (!empty($settings['whatsapp_enabled']) ? 'checked' : '') . '> WhatsApp habilitado neste estudio</label>';
        echo '<button class="btn" type="submit">Salvar WhatsApp</button>';
        echo '</form>';
        echo '</section>';
        echo '<script>
(function(){
  const stateBox = document.getElementById("waSessionState");
  const statusBadge = document.getElementById("waStatusBadge");
  const liveUrl = "index.php?page=studio_whatsapp_live";
  const esc = (value) => String(value ?? "").replace(/[&<>"\']/g, (char) => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#039;"}[char]));
  function renderState(status) {
    if (!stateBox) return;
    if (!status || !status.ok) {
      stateBox.innerHTML = `<p class="muted">Servico Node sem resposta.</p><p class="muted">${esc(status?.error || "")}</p>`;
      return;
    }
    if (status.pairingCode) {
      stateBox.innerHTML = `<p class="metric" style="letter-spacing:2px">${esc(String(status.pairingCode).replace(/-/g, ""))}</p><p class="muted">Codigo para parear o numero ${esc(status.pairingPhone || "")}.</p><p class="muted">Digite exatamente como exibido, sem espacos nem caracteres extras.</p>`;
      return;
    }
    if (status.phone) {
      stateBox.innerHTML = `<p>Numero conectado: <strong>${esc(status.phone)}</strong></p>`;
      return;
    }
    if (status.lastError) {
      stateBox.innerHTML = `<p class="muted">Ultimo erro do servico: ${esc(status.lastError)}</p>`;
      return;
    }
    stateBox.innerHTML = `<p class="muted">${esc(status.status || "Aguardando acao do servico.")}</p>`;
  }
  function updateBadge(status) {
    if (!statusBadge) return;
    const value = status?.status || (status?.ok ? "online" : "offline");
    statusBadge.textContent = value === "connected" ? "conectado" : (value === "waiting_qr" ? "aguardando codigo" : value);
    statusBadge.classList.remove("ok", "warn", "danger");
    statusBadge.classList.add(value === "connected" ? "ok" : (value === "waiting_qr" || value === "starting" ? "warn" : "danger"));
  }
  async function refreshLive() {
    try {
      const response = await fetch(`${liveUrl}&_=${Date.now()}`, { headers: { "Accept": "application/json" } });
      const text = await response.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch (parseError) {
        throw new Error(`Resposta inesperada do servidor (${response.status}).`);
      }
      updateBadge(data.status);
      renderState(data.status);
    } catch (error) {
      if (stateBox) stateBox.innerHTML = `<p class="muted">Falha ao atualizar status ao vivo: ${esc(error.message)}</p>`;
    }
  }
  refreshLive();
  setInterval(refreshLive, 2000);
})();
</script>';
        echo '</section>';
        echo '<section class="grid cols-2 whatsapp-lower-panels" style="margin-top:16px">';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="send_whatsapp_message">';
        echo '<h2>Enviar mensagem manual</h2>';
        echo '<div class="field"><label>Telefone</label><input name="phone" placeholder="5511999999999"></div>';
        echo '<div class="field"><label>Mensagem</label><textarea name="message" placeholder="Escreva uma mensagem curta para o cliente"></textarea></div>';
        echo '<button class="btn" type="submit">Enviar WhatsApp</button>';
        echo '</form>';
        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><h2>Leitura rapida</h2><span class="badge">Fluxo atual</span></div>';
        echo '<div class="mini-metrics">';
        echo '<span><strong>' . h($summary['human']) . '</strong><small>Em humano</small></span>';
        echo '<span><strong>' . h($summary['analyzed']) . '</strong><small>Com IA</small></span>';
        echo '<span><strong>' . h($summary['avg_score'] ?: '-') . '</strong><small>Nota media</small></span>';
        echo '</div>';
        echo '<p class="muted">As mensagens recebidas pelo Baileys entram aqui e criam lead automaticamente quando o telefone ainda nao existir.</p>';
        echo '</div></section>';
        echo '<section class="panel whatsapp-list-panel" style="margin-top:16px"><div class="actions" style="justify-content:space-between"><h2>Conversas importadas</h2><span class="badge">Baileys multi-estudio</span></div>';
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
            ], static fn($value) => $value !== null && $value !== ''));
            $active = ($filters['filter'] ?: 'all') === $filterKey ? ' active' : '';
            echo '<a class="filter-pill' . h($active) . '" href="' . h($href) . '">' . h($label) . '</a>';
        }
        echo '</div>';
        echo '<form class="filter-bar" method="get"><input type="hidden" name="page" value="studio_whatsapp">';
        echo '<input type="hidden" name="filter" value="' . h($filters['filter'] ?: 'all') . '">';
        echo '<input name="q" placeholder="Buscar contato, telefone ou mensagem..." value="' . h($filters['q']) . '">';
        echo '<select name="mode"><option value="">Todos os modos</option>';
        render_options(['human' => 'Humano', 'bot' => 'IA'], $filters['mode']);
        echo '</select>';
        echo '<select name="min_score"><option value="0">Qualquer nota</option>';
        foreach ([5, 7, 9] as $score) {
            echo '<option value="' . h((string)$score) . '" ' . ((int)$filters['min_score'] === $score ? 'selected' : '') . '>Nota ' . h((string)$score) . '+</option>';
        }
        echo '</select>';
        echo '<label class="checkline compact"><input type="checkbox" name="needs_human" value="1" ' . ($filters['needs_human'] ? 'checked' : '') . '> Quer humano</label>';
        echo '<button class="btn secondary" type="submit">Filtrar</button><a class="btn secondary" href="' . h(app_url('studio_whatsapp')) . '">Limpar</a></form>';
        render_whatsapp_table($conversations);
        echo '</section>';
    }, $flash);
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
        echo '<div class="actions" style="justify-content:space-between"><div><h2>' . h($displayName) . '</h2><p class="muted">' . h($conversation['phone']) . '</p></div><div class="actions"><button class="btn secondary" type="button" id="openConversationToolsButton">Ferramentas</button><a class="btn secondary" href="' . h(app_url('studio_whatsapp')) . '">Voltar</a></div></div>';
        render_chat_messages($messages);
        echo '<form class="form send-box" method="post" enctype="multipart/form-data" id="chatComposer">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="send_whatsapp_message"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '"><input type="hidden" name="phone" value="' . h($conversation['phone']) . '">';
        echo '<div class="field"><label>Responder</label><textarea id="reply-message" name="message" placeholder="Digite a resposta para o cliente"></textarea></div>';
        echo '<div class="emoji-strip" aria-label="Emojis rapidos">';
        foreach (['😀','👍','🙏','❤️','😂','🔥','🎯','✅'] as $emoji) {
            echo '<button type="button" class="btn tiny secondary quick-reply-copy" data-reply="' . h($emoji) . '">' . h($emoji) . '</button>';
        }
        echo '</div>';
        echo '<div class="chat-attach-row">';
        echo '<input id="chatAttachment" type="file" name="media_file" accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.txt,.zip" hidden>';
        echo '<button class="btn secondary" type="button" id="chatAttachmentButton">Anexar</button>';
        echo '<button class="btn secondary" type="button" id="chatRecordButton">Gravar audio</button>';
        echo '<span id="chatRecordingState" class="muted"></span>';
        echo '</div>';
        echo '<div id="chatAttachmentPreview" class="chat-attachment-preview hidden"></div>';
        echo '<button class="btn" type="submit">Enviar mensagem</button>';
        echo '</form></div>';

        echo '<div id="conversationToolsOverlay" class="crm-modal hidden">';
        echo '<div class="crm-modal-panel conversation-tools-panel" style="max-width:min(96vw,860px)">';
        echo '<div class="crm-panel-header"><div><h3 class="crm-panel-title">Ferramentas da conversa</h3><p class="muted" style="margin:4px 0 0">Cadastro, IA e respostas rápidas em um só lugar.</p></div><button type="button" id="closeConversationToolsOverlay" class="crm-button crm-icon-button"><i class="fa-solid fa-xmark"></i></button></div>';
        echo '<div class="panel conversation-tools-body">';
        echo '<div class="conversation-tools-actions">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center">';
        echo '<div class="actions">';
        echo '<span class="score-pill small">' . h((string)($conversation['lead_score'] ?? 0)) . '/10</span>';
        echo '<span class="badge ' . h($aiStateTone) . '" data-ai-state-badge>' . h($aiStateLabel) . '</span>';
        echo '<span class="badge" data-conversation-confidence>' . h((string)$assistantConfidence) . '% leitura</span>';
        echo '</div>';
        echo '<div class="actions">';
        echo '<button class="btn secondary" type="button" data-mode-toggle="bot">Bot</button>';
        echo '<button class="btn secondary" type="button" data-mode-toggle="human">Humano</button>';
        echo '<button class="btn secondary" type="button" data-status-set="novo">Novo</button>';
        echo '<button class="btn secondary" type="button" id="openAppointmentModalButton">Agendar</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="mini-metrics conversation-metrics"><span><strong data-message-count>' . h((string)count($messages)) . '</strong><small>Mensagens exibidas</small></span><span><strong data-wa-attendance>' . h($conversation['attendance_mode']) . '</strong><small>Atendimento</small></span><span><strong data-wa-needs-human>' . h(!empty($conversation['needs_human']) ? 'sim' : 'nao') . '</strong><small>Quer humano</small></span><span><strong data-wa-lead-status>' . h(($conversation['lead_status'] ?: 'em_conversa') . ' / ' . ($conversation['lead_pipeline_stage'] ?: 'em_conversa')) . '</strong><small>Funil</small></span><span class="ai-state-chip" data-ai-state data-ai-state-label="' . h($aiStateLabel) . '" data-ai-state-tone="' . h($aiStateTone) . '">' . h($conversation['ai_last_status'] ?: (($conversation['attendance_mode'] === 'bot') ? 'IA pronta' : 'IA inativa')) . '</span></div>';
        echo '<form class="form" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="update_whatsapp_profile"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '">';
        $nameFieldValue = $conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: ''));
        if (($nameFieldValue === '' || in_array(function_exists('mb_strtolower') ? mb_strtolower($nameFieldValue, 'UTF-8') : strtolower($nameFieldValue), ['cliente whatsapp', 'contato whatsapp', 'sem nome'], true)) && !empty($assistantInsights['suggested_name'])) {
            $nameFieldValue = (string)$assistantInsights['suggested_name'];
        }
        echo '<div class="grid cols-2"><div class="field"><label>Nome</label><input name="name" value="' . h($nameFieldValue !== '' ? $nameFieldValue : $displayName) . '"></div><div class="field"><label>Telefone</label><input name="phone" value="' . h($conversation['phone']) . '"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Email</label><input type="text" inputmode="email" name="email" value="' . h($conversation['customer_email'] ?? '') . '"></div><div class="field"><label>Instagram</label><input name="instagram" value="' . h($conversation['customer_instagram'] ?? '') . '"></div></div>';
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
        echo '<div class="grid cols-2"><div class="field"><label>Status</label><select name="status">';
        render_options(lead_status_options(), (string)($conversation['lead_status'] ?: 'em_conversa'));
        echo '</select></div><div class="field"><label>Etapa</label><select name="pipeline_stage">';
        foreach (studio_list_pipeline_stages($studio) as $stage) {
            echo '<option value="' . h($stage['name']) . '" ' . ((string)$stage['name'] === (string)($conversation['lead_pipeline_stage'] ?: 'em_conversa') ? 'selected' : '') . '>' . h($stage['name']) . '</option>';
        }
        echo '</select></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Valor estimado</label><input name="estimated_value" value="' . h((string)($conversation['lead_estimated_value'] ?? '0')) . '"></div><div class="field"><label>Origem</label><input name="source" value="WhatsApp"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Modo de atendimento</label><select name="attendance_mode">';
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

        echo '<details class="panel side-tool-panel" ' . (!empty($assistantInsights['suggested_name']) || !empty($assistantInsights['suggested_interest']) || !empty($assistantInsights['schedule_reason']) ? 'open' : '') . '>';
        echo '<summary>Sugestões da IA</summary>';
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
                echo '<div class="drilldown-card compact"><strong>Sugestão de agendamento</strong><div class="muted">' . h((string)$assistantInsights['schedule_reason']) . '</div><div class="mini-metrics side-suggestion-metrics"><span><strong>' . h((string)($scheduleSuggestion['date'] ?? '')) . '</strong><small>Data</small></span><span><strong>' . h((string)($scheduleSuggestion['time'] ?? '')) . '</strong><small>Hora</small></span><span><strong>' . h((string)($scheduleSuggestion['end_time'] ?? '')) . '</strong><small>Fim</small></span></div></div>';
            }
            echo '<p class="muted">Quando o assistente encontra nome, interesse ou uma janela boa de agenda, ele preenche os campos acima para agilizar o atendimento.</p>';
        } else {
            echo '<p class="muted">Nenhuma sugestão clara detectada ainda.</p>';
        }
        echo '</details>';

        echo '<details class="panel side-tool-panel" open>';
        echo '<summary>Respostas rapidas</summary>';
        if ($quickReplies) {
            echo '<div class="quick-reply-list side-reply-list">';
            foreach (array_slice($quickReplies, 0, 12) as $reply) {
                echo '<button class="btn tiny secondary quick-reply-copy" type="button" data-reply="' . h($reply['body']) . '">' . h($reply['title']) . '</button>';
            }
            echo '</div>';
        } else {
            echo '<p class="muted">Nenhuma resposta rapida ativa.</p>';
        }
        echo '</details>';

        echo '<details class="panel side-tool-panel">';
        echo '<summary>Sugestao de agendamento</summary>';
        if ($scheduleSuggestion) {
            echo '<p class="muted">' . h($scheduleSuggestion['reason']) . '</p>';
            echo '<div class="mini-metrics side-suggestion-metrics">';
            echo '<span><strong>' . h($scheduleSuggestion['title']) . '</strong><small>Titulo</small></span>';
            echo '<span><strong>' . h($scheduleSuggestion['date']) . '</strong><small>Data</small></span>';
            echo '<span><strong>' . h($scheduleSuggestion['time']) . '</strong><small>Hora</small></span>';
            echo '<span><strong>' . h($scheduleSuggestion['end_time'] ?? '') . '</strong><small>Fim</small></span>';
            echo '</div>';
            echo '<button class="btn secondary" type="button" id="applyScheduleSuggestionButton" style="margin-top:10px">Usar sugestao</button>';
        } else {
            echo '<p class="muted">Ainda sem sugestao para esta conversa.</p>';
        }
        echo '</details>';
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
        echo '<div class="grid cols-2"><div class="field"><label>Titulo</label><input name="title" required value="' . h($conversation['lead_interest'] ?: 'Atendimento') . '"></div><div class="field"><label>Imagem de referencia</label><input id="appointmentReferenceInput" type="file" name="reference_image" accept="image/*" hidden><button class="btn secondary" type="button" id="appointmentReferenceButton">Anexar referencia</button></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Quantidade de pomadas</label><input type="number" min="0" step="1" name="pomadas_quantity" value="0" placeholder="0"><small class="muted">Quantas pomadas o cliente vai levar/fechar junto com o atendimento.</small></div><div class="field"><label>&nbsp;</label><div class="muted" style="padding-top:10px">Esse valor vai junto no agendamento.</div></div></div>';
        echo '<div id="appointmentReferencePreview" class="chat-attachment-preview hidden"></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Tatuador</label><select name="artist_id">';
        render_artist_options($artists, default_artist_id($studio) ?? 0);
        echo '</select></div><div class="field"><label>Status</label><select name="status">';
        render_options(appointment_status_options(), 'pre_agendado');
        echo '</select></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Data</label><input type="date" name="appointment_date" required value="' . h(date('Y-m-d')) . '"></div><div class="field"><label>Inicio</label><input type="time" name="start_time" required value="10:00"></div><div class="field"><label>Fim</label><input type="time" name="end_time" readonly></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Valor</label><input name="value" value="' . h((string)($conversation['lead_estimated_value'] ?? '')) . '"></div><div class="field"><label>Sinal</label><input name="deposit_value"></div></div>';
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
        echo 'function inferMediaType(mime, mediaUrl, type){ const normalizedMime = String(mime || "").toLowerCase().trim(); const normalizedType = String(type || "").toLowerCase().trim(); const rawUrl = String(mediaUrl || ""); const ext = (rawUrl.split("?")[0].split("#")[0].split(".").pop() || "").toLowerCase(); if (normalizedMime) { if (normalizedMime.startsWith("image/")) return "image"; if (normalizedMime.startsWith("video/")) return "video"; if (normalizedMime.startsWith("audio/")) return "audio"; } if (["jpg","jpeg","png","gif","webp","bmp","svg"].includes(ext) || normalizedType === "image") return "image"; if (["mp4","webm","mov","m4v","avi","mkv"].includes(ext) || normalizedType === "video") return "video"; if (["mp3","wav","ogg","oga","opus","webm","m4a","aac"].includes(ext) || normalizedType === "audio") return "audio"; return normalizedType || "document"; }';
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
        echo 'async function postConversationUpdate(payload, errorMessage){ const body = new URLSearchParams({ csrf_token: csrfToken, conversation_id: String(conversationId), ...payload }); const response = await fetch(window.location.pathname + window.location.search, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json, text/plain, */*" }, body }); const text = await response.text(); if (!response.ok) { throw new Error(text.trim() || errorMessage); } return text; }';
        echo 'document.querySelectorAll("[data-mode-toggle]").forEach(button => button.addEventListener("click", async () => { try { const isBot = button.dataset.modeToggle === "bot"; const payload = { action: "update_whatsapp_profile", attendance_mode: isBot ? "bot" : "human", needs_human: isBot ? 0 : 1, ai_last_status: isBot ? "IA pronta" : "IA inativa" }; await postConversationUpdate(payload, "Nao foi possivel atualizar o atendimento."); syncConversationUI(payload); } catch (error) { alert(error.message || "Nao foi possivel atualizar o atendimento."); } }));';
        echo 'document.querySelectorAll("[data-status-set]").forEach(button => button.addEventListener("click", async () => { try { const payload = { action: "update_whatsapp_profile", status: button.dataset.statusSet || "novo", create_lead: 1 }; await postConversationUpdate(payload, "Nao foi possivel atualizar o status."); syncConversationUI(payload); } catch (error) { alert(error.message || "Nao foi possivel atualizar o status."); } }));';
        echo 'async function toggleRecording(){ if (recorder && recorder.state === "recording") { recorder.stop(); return; } if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) { alert("Seu navegador nao liberou gravacao de audio aqui."); return; } try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); const preferredMime = MediaRecorder.isTypeSupported("audio/ogg;codecs=opus") ? "audio/ogg;codecs=opus" : (MediaRecorder.isTypeSupported("audio/webm;codecs=opus") ? "audio/webm;codecs=opus" : ""); const options = preferredMime ? { mimeType: preferredMime } : {}; recorder = new MediaRecorder(stream, options); chunks = []; startedAt = Date.now(); recordBtn.textContent = "Parar"; recordState.textContent = "Gravando..."; recordingTimer = setInterval(() => { const elapsed = Math.floor((Date.now() - startedAt) / 1000); recordState.textContent = `Gravando ${String(Math.floor(elapsed / 60)).padStart(2, "0")}:${String(elapsed % 60).padStart(2, "0")}`; }, 500); recorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); }; recorder.onstop = () => { if (recordingTimer) { clearInterval(recordingTimer); recordingTimer = null; } if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } const mime = recorder.mimeType || preferredMime || "audio/webm"; const ext = mime.includes("ogg") || mime.includes("opus") ? "ogg" : "webm"; const blob = new Blob(chunks, { type: mime }); recordedFile = new File([blob], `audio_${Date.now()}.${ext}`, { type: mime }); const dt = new DataTransfer(); dt.items.add(recordedFile); input.files = dt.files; renderPreview(); recordBtn.textContent = "Gravar audio"; recordState.textContent = "Audio pronto para envio"; }; recorder.start(); } catch (error) { alert("Nao foi possivel iniciar a gravacao."); } }';
        echo 'recordBtn.addEventListener("click", toggleRecording);';
        echo 'if (form) form.addEventListener("submit", async (event) => { event.preventDefault(); event.stopPropagation(); const hasText = !!textarea.value.trim(); const hasFile = !!(input.files && input.files.length); if (!hasText && !hasFile) return; const formData = new FormData(form); try { const response = await fetch(window.location.pathname + window.location.search, { method: "POST", body: formData }); if (!response.ok) throw new Error("Nao foi possivel enviar a mensagem."); textarea.value = ""; clearAttachment(); location.reload(); } catch (error) { alert(error.message || "Erro ao enviar mensagem"); } });';
        echo 'const pollConversation = async () => { try { const response = await fetch(`api_chat.php?id=${encodeURIComponent(conversationId)}&_=${Date.now()}`, { cache: "no-store", headers: { "Accept": "application/json" } }); const data = await response.json().catch(() => null); if (!data?.ok) return; syncConversationUI(data.conversation || {}); const messages = Array.isArray(data.mensagens) ? data.mensagens : []; const count = messages.length; if (count !== lastMessageCount) { lastMessageCount = count; updateConversationMeta(count); renderChatThread(messages); return; } if (messages.length > 0) { const latestSignature = `${messages[messages.length - 1]?.message_id || ""}|${messages[messages.length - 1]?.sent_at || ""}|${messages[messages.length - 1]?.transcricao || ""}|${messages[messages.length - 1]?.transcript || ""}`; if (pollConversation._lastSignature !== latestSignature) { pollConversation._lastSignature = latestSignature; renderChatThread(messages); } } } catch (error) {} };';
        echo 'pollConversation._lastSignature = "";';
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
        $summary = studio_finance_summary($studio);
        $expenses = studio_list_expenses($studio);
        echo '<section class="grid cols-3">';
        echo '<a class="panel dashboard-stat" href="' . h(app_url('studio_agenda')) . '"><p class="metric">' . h(format_money($summary['appointments_month'])) . '</p><p class="muted">Agenda no mes</p><span class="muted">Ver agendamentos</span></a>';
        echo '<a class="panel dashboard-stat" href="#nova-despesa"><p class="metric">' . h(format_money($summary['expenses_month'])) . '</p><p class="muted">Despesas no mes</p><span class="muted">Lancar despesa</span></a>';
        echo '<a class="panel dashboard-stat" href="#despesas-recentes"><p class="metric">' . h(format_money($summary['balance_month'])) . '</p><p class="muted">Resultado simples</p><span class="muted">Abrir detalhes</span></a>';
        echo '</section>';
        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<form class="form panel" method="post" id="nova-despesa">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_expense">';
        echo '<div class="actions" style="justify-content:space-between"><h2>Nova despesa</h2><span class="badge">Controle rapido</span></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Categoria</label><input name="category" value="Geral"></div><div class="field"><label>Data</label><input type="date" name="expense_date" value="' . h(date('Y-m-d')) . '" required></div></div>';
        echo '<div class="field"><label>Descricao</label><input name="description" required placeholder="Material, aluguel, trafego, insumo..."></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Valor</label><input name="amount" required placeholder="120,00"></div><div class="field"><label>Pagamento</label><input name="payment_method" placeholder="Pix, cartao, dinheiro..."></div></div>';
        echo '<div class="field"><label>Observacoes</label><textarea name="notes"></textarea></div>';
        echo '<button class="btn" type="submit">Salvar despesa</button>';
        echo '</form>';
        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><h2>Despesas por categoria</h2><a class="btn secondary" href="#despesas-recentes">Ver recentes</a></div>';
        render_category_totals($summary['by_category']);
        echo '</div></section>';
        echo '<section class="panel" style="margin-top:16px" id="despesas-recentes"><div class="actions" style="justify-content:space-between"><h2>Despesas recentes</h2><span class="muted">Lista completa do mes</span></div>';
        render_expenses_table($expenses);
        echo '</section>';
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
        echo '<section class="panel"><div class="actions" style="justify-content:space-between"><div><h2>Pessoas</h2><p class="muted">Clientes e leads num unico lugar.</p></div><span class="badge">' . h((string)($totalCustomers + $totalLeads)) . ' registros</span></div>';
        echo '<form class="filter-bar" method="get"><input type="hidden" name="page" value="studio_people">';
        echo '<input name="q" placeholder="Buscar por nome, telefone, email ou interesse..." value="' . h($q) . '">';
        echo '<select name="view">';
        foreach (['all' => 'Tudo', 'leads' => 'Leads', 'customers' => 'Clientes'] as $key => $label) {
            echo '<option value="' . h($key) . '" ' . ($view === $key ? 'selected' : '') . '>' . h($label) . '</option>';
        }
        echo '</select>';
        echo '<button class="btn secondary" type="submit">Filtrar</button><a class="btn secondary" href="' . h(app_url('studio_people')) . '">Limpar</a></form>';
        echo '</section>';
        echo '<section class="grid cols-3" style="margin-top:16px">';
        echo '<a class="panel dashboard-stat" href="' . h(app_url('studio_customers')) . '"><p class="metric">' . h((string)$totalCustomers) . '</p><p class="muted">Clientes</p><span class="muted">Abrir cadastros</span></a>';
        echo '<a class="panel dashboard-stat" href="' . h(app_url('studio_leads')) . '"><p class="metric">' . h((string)$totalLeads) . '</p><p class="muted">Leads</p><span class="muted">Abrir funil</span></a>';
        echo '<a class="panel dashboard-stat" href="' . h(app_url('studio_whatsapp')) . '"><p class="metric">' . h((string)studio_whatsapp_summary($studio)['total']) . '</p><p class="muted">Conversas WhatsApp</p><span class="muted">Ver integrações</span></a>';
        echo '</section>';
        echo '<section class="grid cols-2" style="margin-top:16px">';
        if ($view !== 'customers') {
            echo '<div class="panel"><div class="actions" style="justify-content:space-between"><h2>Leads recentes</h2><a class="btn secondary" href="' . h(app_url('studio_leads')) . '">Abrir funil</a></div>';
            render_leads_table(array_slice($leads, 0, 12));
            echo '</div>';
        }
        if ($view !== 'leads') {
            echo '<div class="panel"><div class="actions" style="justify-content:space-between"><h2>Clientes recentes</h2><a class="btn secondary" href="' . h(app_url('studio_customers')) . '">Abrir clientes</a></div>';
            render_customers_table(array_slice($customers, 0, 12));
            echo '</div>';
        }
        echo '</section>';
    }, $flash);
    exit;
}

if ($page === 'studio_quick_replies') {
    redirect_to('studio_settings');
    exit;
}

if ($page === 'studio_reports') {
    $studio = require_studio();
    render_studio_shell('Relatorios', 'Leitura gerencial do funil, agenda e financeiro.', 'reports', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $pdo = studio_db($studio);
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
            "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.status, a.value, a.deposit_value,
                    COALESCE(c.name, a.title) AS customer_name, ta.name AS artist_name
             FROM appointments a
             LEFT JOIN customers c ON c.id = a.customer_id
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
        if ($alerts) {
            echo '<section class="panel" style="margin-bottom:16px"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2>Alertas operacionais</h2><p class="muted">Sinais rápidos do que precisa de ação agora.</p></div><span class="badge">Operação</span></div>';
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
            echo '</div></section>';
        }
        echo '<section class="panel"><div class="actions" style="justify-content:space-between"><h2>Resumo gerencial</h2><span class="badge">Painel de leitura</span></div><div class="mini-metrics">';
        echo '<span><strong>' . h((string)array_sum(array_map(static fn($row) => (int)($row['qtd'] ?? 0), $reports['leads_by_status'] ?? []))) . '</strong><small>Leads totais</small></span>';
        echo '<span><strong>' . h((string)array_sum(array_map(static fn($row) => (int)($row['qtd'] ?? 0), $reports['appointments_by_status'] ?? []))) . '</strong><small>Agendamentos</small></span>';
        echo '<span><strong>' . h((string)count($reports['expenses_by_category'] ?? [])) . '</strong><small>Grupos de despesa</small></span>';
        echo '</div></section>';
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
        if (plan_allows('advanced_reports')) {
            $pivotConfig = $pivotDataSets[$pivotSource];
            echo '<section class="panel" style="margin-top:16px">';
            echo '<div class="actions" style="justify-content:space-between;align-items:flex-start;gap:12px"><div><h2>Tabela dinâmica</h2><p class="muted">Monte cruzamentos por arrastar campos entre linhas, colunas, medidas e filtros.</p></div><span class="badge">Análise</span></div>';
            echo '<div class="wdr-shell">';
            echo '<div class="wdr-source-bar">';
            foreach ($pivotDataSets as $key => $def) {
                echo '<button type="button" class="wdr-source-button' . ($key === $pivotSource ? ' active' : '') . '" data-pivot-source="' . h($key) . '"><strong>' . h($def['label']) . '</strong><span>' . h($def['subtitle']) . '</span></button>';
            }
            echo '</div>';
            echo '<div id="reportsPivot" class="wdr-frame"></div>';
            echo '</div>';
            echo '<div class="reports-pivot-note muted">Use a barra superior e a lista de campos para reorganizar a leitura. Se quiser, troque a base entre Leads, Agenda e Despesas.</div>';
            echo '</section>';
            echo '<link rel="stylesheet" href="' . h(app_asset_url('assets/vendor/webdatarocks/theme/teal/webdatarocks.min.css')) . '">';
            echo '<script src="' . h(app_asset_url('assets/vendor/webdatarocks/webdatarocks.js')) . '"></script>';
            echo '<script src="' . h(app_asset_url('assets/vendor/webdatarocks/webdatarocks.toolbar.min.js')) . '"></script>';
            echo '<script>window.reportsPivotData = ' . json_encode($pivotDataSets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; window.reportsPivotSource = ' . json_encode($pivotSource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
            echo '<script src="' . h(app_asset_url('assets/reports_pivot.js')) . '"></script>';
            echo '</section>';
        } else {
            echo '<section class="panel" style="margin-top:16px"><div class="actions" style="justify-content:space-between;align-items:flex-start;gap:12px"><div><h2>Tabela dinâmica</h2><p class="muted">Este bloco avançado fica disponível a partir do plano Profissional ou Avançado.</p></div><span class="badge warn">Bloqueado</span></div>';
            echo '<p class="muted">O restante dos relatórios continua disponível normalmente. Para liberar a pivot, considere alterar para um plano superior.</p>';
            echo '</section>';
        }
        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel"><h2>Leads por status</h2>';
        render_report_table($reports['leads_by_status'], 'status');
        echo '</div><div class="panel"><h2>Leads por origem</h2>';
        render_report_table($reports['leads_by_source'], 'source');
        echo '</div><div class="panel"><h2>Agenda por status</h2>';
        render_report_table($reports['appointments_by_status'], 'status');
        echo '</div><div class="panel"><h2>Agenda por mês</h2>';
        render_report_table($reports['appointments_by_month'], 'month');
        echo '</div><div class="panel"><h2>Despesas por categoria</h2>';
        render_report_table($reports['expenses_by_category'], 'category');
        echo '</div></section>';
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
        if (!plan_allows('ai_data_assistant')) {
            echo '<section class="panel"><div class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2>Assistente IA de dados</h2><p class="muted">Os recursos de IA estão disponíveis no plano Avançado.</p></div><span class="badge warn">Bloqueado</span></div><p class="muted">Esse assistente continua somente leitura e não altera dados. Para usar as respostas por IA, altere para um plano superior.</p></section>';
            return;
        }
        $result = $_SESSION['studio_data_assistant_result'] ?? null;
        unset($_SESSION['studio_data_assistant_result']);
        $suggestions = studio_data_assistant_suggestions();
        echo '<section class="grid cols-2">';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="ask_studio_data_assistant">';
        echo '<h2>Perguntar aos dados</h2>';
        echo '<div class="field"><label>Pergunta</label><textarea name="question" required placeholder="Ex: Quais leads merecem prioridade hoje?">' . h($result['question'] ?? '') . '</textarea></div>';
        echo '<button class="btn" type="submit">Perguntar</button>';
        echo '<p class="muted">Este assistente e somente leitura: ele consulta resumos do banco isolado do estudio e nao altera dados.</p>';
        echo '</form>';
        echo '<div class="panel"><h2>Sugestoes por assunto</h2>';
        foreach ($suggestions as $group => $items) {
            echo '<details class="suggestion-group"><summary>' . h($group) . '</summary><div class="suggestion-list">';
            foreach ($items as $item) {
                echo '<form method="post" class="inline-form">';
                echo csrf_field();
                echo '<input type="hidden" name="action" value="ask_studio_data_assistant">';
                echo '<input type="hidden" name="question" value="' . h($item) . '">';
                echo '<button class="btn secondary" type="submit">' . h($item) . '</button>';
                echo '</form>';
            }
            echo '</div></details>';
        }
        echo '</div></section>';
        echo '<section class="panel" style="margin-top:16px"><div class="actions" style="justify-content:space-between"><h2>Resposta</h2>';
        if ($result) {
            echo '<span class="badge">Gerado em ' . h($result['generated_at']) . '</span>';
        }
        echo '</div>';
        if (!$result) {
            echo '<p class="muted">Faca uma pergunta ou use uma sugestao para gerar uma leitura do negocio.</p>';
        } else {
            if (!empty($result['source'])) {
                $sourceLabel = $result['source'] === 'ai' ? 'Resposta por IA' : 'Leitura direta dos dados';
                echo '<p class="muted" style="margin:0 0 8px">' . h($sourceLabel) . '</p>';
            }
            echo '<pre class="answer-box">' . h($result['answer']) . '</pre>';
        }
        echo '</section>';
    }, $flash);
    exit;
}

if ($page === 'studio_settings') {
    $studio = require_studio();
    $activeSettingsTab = (string)($_GET['tab'] ?? 'studio');
    if (!in_array($activeSettingsTab, ['studio', 'agenda', 'whatsapp', 'ia', 'quick_replies', 'rules'], true)) {
        $activeSettingsTab = 'studio';
    }
    render_studio_shell('Configuracoes do estudio', 'Regras comerciais e preparacao dos modulos de IA/WhatsApp.', 'settings', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $settings = studio_settings($studio);
        $artists = studio_list_artists($studio);
        $pomadaUnitPrice = (float)($settings['pomada_unit_price'] ?? 100);
        $activeTab = (string)($_GET['tab'] ?? 'studio');
        if (!in_array($activeTab, ['studio', 'agenda', 'whatsapp', 'ia', 'quick_replies', 'rules'], true)) {
            $activeTab = 'studio';
        }
        echo '<form class="form panel" method="post" id="studioSettingsForm">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_studio_settings">';
        echo '<input type="hidden" name="settings_tab" value="' . h($activeTab) . '">';
        echo '<div id="topo-configuracoes" class="actions" style="justify-content:space-between;align-items:flex-start"><div><h2>Configuracoes do estudio</h2><p class="muted">Separadas por assunto para ficar mais simples de mexer no dia a dia.</p></div><span class="badge">Operacao</span></div>';
        echo '<div class="settings-tabs" role="tablist" aria-label="Configuracoes">';
        $tabs = [
            'studio' => 'Estúdio',
            'agenda' => 'Agenda',
            'whatsapp' => 'WhatsApp',
            'ia' => 'IA',
            'quick_replies' => 'Respostas rápidas',
            'rules' => 'Regras comerciais',
        ];
        $tabAnchors = [
            'studio' => 'settings-studio',
            'agenda' => 'settings-agenda',
            'whatsapp' => 'settings-whatsapp',
            'ia' => 'settings-ia',
            'quick_replies' => 'settings-quick-replies',
            'rules' => 'settings-rules',
        ];
        foreach ($tabs as $key => $label) {
            $activeClass = $activeTab === $key ? ' active' : '';
            echo '<a class="settings-tab' . h($activeClass) . '" data-settings-tab="' . h($key) . '" aria-selected="' . ($activeTab === $key ? 'true' : 'false') . '" href="' . h(app_url('studio_settings', ['tab' => $key])) . '#' . h($tabAnchors[$key] ?? 'settings-studio') . '">' . h($label) . '</a>';
        }
        echo '</div>';

        echo '<div class="settings-panel" id="settings-studio" data-settings-panel="studio">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Estúdio</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Nome do estudio</label><input name="studio_name" value="' . h($settings['studio_name'] ?? $studio['name']) . '" required></div>';
        echo '<div class="field"><label>WhatsApp habilitado neste estudio</label><label class="checkline"><input type="checkbox" name="whatsapp_enabled" value="1" ' . (!empty($settings['whatsapp_enabled']) ? 'checked' : '') . '> Ativar/Desativar integração</label></div>';
        echo '</div>';
        echo '</div>';

        $workDaysRaw = trim((string)($settings['appointment_work_days'] ?? '1,2,3,4,5'));
        $selectedWorkDays = array_values(array_filter(array_map('trim', explode(',', $workDaysRaw)), static fn($value) => $value !== ''));
        $dayOptions = ['1' => 'Segunda', '2' => 'Terça', '3' => 'Quarta', '4' => 'Quinta', '5' => 'Sexta', '6' => 'Sábado', '7' => 'Domingo'];
        $durationMinutes = max(0, (int)($settings['appointment_duration_minutes'] ?? '300'));
        $durationHours = intdiv($durationMinutes, 60);
        $durationMins = $durationMinutes % 60;
        echo '<div class="settings-panel" id="settings-agenda" data-settings-panel="agenda">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Agenda</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="panel soft">';
        echo '<h3 style="margin-top:0">Regras de agenda</h3>';
        echo '<div class="grid cols-3">';
        echo '<div class="field"><label>Dias da semana disponíveis</label><div class="weekday-picker">';
        foreach ($dayOptions as $dayValue => $dayLabel) {
            $checked = in_array($dayValue, $selectedWorkDays, true) || ($selectedWorkDays === [] && in_array($dayValue, ['1','2','3','4','5'], true));
            echo '<label class="weekday-pill' . ($checked ? ' is-active' : '') . '">';
            echo '<input type="checkbox" name="appointment_work_days[]" value="' . h($dayValue) . '" ' . ($checked ? 'checked' : '') . '>';
            echo '<span>' . h($dayLabel) . '</span>';
            echo '</label>';
        }
        echo '</div><small class="muted">Selecione os dias em que o estudio atende. O padrão vem de segunda a sexta.</small></div>';
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
        echo '</div>';

        echo '<div class="settings-panel" id="settings-whatsapp" data-settings-panel="whatsapp">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">WhatsApp</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Padrao das novas conversas WhatsApp</label><select name="whatsapp_default_mode">';
        render_options(['human' => 'Humano atende primeiro', 'bot' => 'IA atende primeiro'], (string)($settings['whatsapp_default_mode'] ?? 'human'));
        echo '</select></div>';
        echo '<div class="field"><label>URL do servico Baileys</label><input name="whatsapp_service_url" value="' . h($settings['whatsapp_service_url'] ?? 'http://localhost:3010') . '"></div>';
        echo '</div>';
        echo '<div class="field"><label>Frases iniciais da campanha META</label><textarea name="meta_campaign_phrases" placeholder="Tenho interesse no fechamento!&#10;Quero fechar minha tattoo!">' . h($settings['meta_campaign_phrases'] ?? "Tenho interesse no fechamento!") . '</textarea><small class="muted">Use uma frase por linha. O card da home vai contar conversas/leads cuja primeira mensagem recebida bater com uma dessas frases.</small></div>';
        echo '<p class="muted">Controle a porta de entrada e o modo inicial das conversas do WhatsApp.</p>';
        echo '</div>';

        echo '<div class="settings-panel" id="settings-ia" data-settings-panel="ia">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">IA</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Modelo IA</label><input name="ai_model" value="' . h($settings['ai_model'] ?? $studio['ai_model'] ?? 'llama3:8b') . '"></div>';
        echo '<div class="field"><label>Fornecedor da IA</label><select name="ai_provider"><option value="ollama"' . ((string)($settings['ai_provider'] ?? 'ollama') === 'ollama' ? ' selected' : '') . '>Ollama local</option><option value="openai"' . ((string)($settings['ai_provider'] ?? 'ollama') === 'openai' ? ' selected' : '') . '>OpenAI</option></select></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>URL da IA</label><input name="ai_api_base_url" value="' . h($settings['ai_api_base_url'] ?? 'http://localhost:11434/v1') . '" placeholder="http://localhost:11434/v1"><small class="muted">Use a URL do servidor local ou da API escolhida.</small></div>';
        echo '<div class="field"><label>Chave da OpenAI</label><input name="openai_api_key" type="password" value="' . h($settings['openai_api_key'] ?? '') . '" placeholder="sk-..."><small class="muted">Preencha só se usar OpenAI.</small></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Modelo da IA no WhatsApp</label><input name="openai_model" value="' . h($settings['openai_model'] ?? 'qwen3:4b') . '" placeholder="qwen3:4b"><small class="muted">No Ollama, esse campo também define o modelo local.</small></div>';
        echo '<div class="settings-switch-grid"><label class="checkline"><input type="checkbox" name="ai_enabled" value="1" ' . (!empty($settings['ai_enabled']) ? 'checked' : '') . '> IA pode responder conversas marcadas como IA</label><label class="checkline"><input type="checkbox" name="assistant_autofill_enabled" value="1" ' . (!empty($settings['assistant_autofill_enabled']) ? 'checked' : '') . '> Assistente preencher sugestões automaticamente nas conversas</label><label class="checkline"><input type="checkbox" name="whatsapp_enabled" value="1" ' . (!empty($settings['whatsapp_enabled']) ? 'checked' : '') . '> WhatsApp/Baileys ativo neste estudio</label></div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="settings-panel" id="settings-rules" data-settings-panel="rules">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Regras comerciais</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="field"><label>Regras e informações para IA</label><textarea name="business_rules" placeholder="Exemplo: Estúdio aberto de terça a sábado. Dois tatuadores. Responder sempre em português do Brasil. Quando o cliente pedir agendamento, considerar sinal obrigatório. Não inventar preço. Se faltar informação, perguntar só uma coisa por vez. Priorize datas, horários e referências reais do estúdio.">' . h($settings['business_rules'] ?? $studio['business_rules'] ?? '') . '</textarea><small class="muted">Esse texto entra no contexto da IA. Aqui vale escrever regras reais do estúdio, tom de atendimento, limites e informações que a IA deve respeitar sempre.</small></div>';
        echo '<div class="field"><label>Texto-base da IA para WhatsApp</label><textarea name="ai_whatsapp_prompt" placeholder="Você é o assistente do estúdio...">' . h($settings['ai_whatsapp_prompt'] ?? '') . '</textarea><small class="muted">Se vazio, o sistema usa um texto-base em português já pronto.</small></div>';
        echo '</div>';

        echo '<div class="actions" style="justify-content:space-between;align-items:center;margin-top:12px"><span class="muted">Salvar continua aplicando as regras no banco do estudio.</span><button class="btn" type="submit" form="studioSettingsForm">Salvar configurações</button></div>';
        echo '</form>';

        echo '<div class="settings-panel" id="settings-tattooers" data-settings-panel="tattooers">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Tatuadores</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="panel soft">';
        echo '<p class="muted">Cadastre e gerencie os tatuadores que aparecem na agenda e nos agendamentos do estúdio.</p>';
        render_artists_table($artists);
        echo '<form class="form" method="post" style="margin-top:14px">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_artist">';
        echo '<div class="grid cols-2"><div class="field"><label>Nome</label><input name="name" placeholder="Nome do tatuador" required></div><div class="field"><label>Cor</label><input type="color" name="color" value="#1f6f78"></div></div>';
        echo '<div class="field"><label>Especialidade</label><input name="specialty" placeholder="Fine line, blackwork, realismo..."></div>';
        echo '<label class="checkline"><input type="checkbox" name="is_active" value="1" checked> Tatuador ativo</label>';
        echo '<button class="btn secondary" type="submit">Adicionar tatuador</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div class="settings-panel" id="settings-quick-replies" data-settings-panel="quick_replies">';
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h3 style="margin:0">Respostas rápidas</h3><a class="btn tiny secondary" href="#topo-configuracoes">Voltar ao topo</a></div>';
        echo '<div class="actions" style="justify-content:space-between"><div><p class="muted">Esses textos prontos ficam disponíveis no atendimento e continuam editáveis por aqui.</p></div><a class="btn secondary" href="' . h(app_url('studio_quick_replies')) . '">Abrir biblioteca</a></div>';
        $replies = studio_list_quick_replies($studio);
        echo '<div class="grid cols-2">';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_quick_reply">';
        echo '<input type="hidden" name="return_to_settings" value="1">';
        echo '<input type="hidden" name="settings_tab" value="quick_replies">';
        echo '<div class="field"><label>Titulo</label><input name="title" required></div>';
        echo '<div class="field"><label>Atalho</label><input name="shortcut" placeholder="/atalho"></div>';
        echo '<div class="field"><label>Categoria</label><input name="category" value="Geral"></div>';
        echo '<div class="field"><label>Texto</label><textarea name="body" required placeholder="Mensagem pronta para usar no atendimento..."></textarea></div>';
        echo '<label class="checkline"><input type="checkbox" name="is_active" value="1" checked> Resposta ativa</label>';
        echo '<button class="btn" type="submit">Salvar resposta</button>';
        echo '</form>';
        echo '<div class="panel"><h3 style="margin-top:0">Biblioteca atual</h3>';
        render_quick_replies_table(array_slice($replies, 0, 12));
        echo '</div></div>';
        echo '</div>';
        echo '<script>(function(){ const activeTab = ' . json_encode($activeTab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; const tabs = document.querySelectorAll("[data-settings-tab]"); const hiddenTab = document.querySelector("#studioSettingsForm [name=settings_tab]"); const targetMap = { studio: "settings-studio", agenda: "settings-agenda", whatsapp: "settings-whatsapp", ia: "settings-ia", quick_replies: "settings-quick-replies", rules: "settings-rules" }; tabs.forEach(btn => { const selected = btn.dataset.settingsTab === activeTab; btn.classList.toggle("active", selected); btn.setAttribute("aria-selected", selected ? "true" : "false"); const key = btn.dataset.settingsTab || "studio"; const target = targetMap[key] || "settings-studio"; btn.setAttribute("href", "index.php?page=studio_settings&tab=" + encodeURIComponent(key) + "#" + target); }); if (hiddenTab) hiddenTab.value = activeTab; if (window.location.hash) { const target = document.querySelector(window.location.hash); if (target) { setTimeout(() => target.scrollIntoView({ behavior: "smooth", block: "start" }), 80); } } })();</script>';
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
                    'estudios: %s | usuarios: %s | tatuadores: %s | leads: %s | WhatsApp: %s',
                    $plan['studio_limit'] === null ? 'ilimitado' : (string)$plan['studio_limit'],
                    $plan['user_limit'] === null ? 'ilimitado' : (string)$plan['user_limit'],
                    $plan['tattoo_artist_limit'] === null ? 'ilimitado' : (string)$plan['tattoo_artist_limit'],
                    $plan['lead_limit'] === null ? 'ilimitado' : (string)$plan['lead_limit'],
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
        echo '<section class="panel" style="margin-top:16px"><h2>Acesso do estudio</h2>';
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
        echo '<section class="panel" style="margin-top:16px"><h2>Eventos recentes</h2>';
        $events = studio_events((int)$studio['id']);
        if (!$events) {
            echo '<p class="muted">Sem eventos ainda.</p>';
        } else {
            echo '<table class="table"><tbody>';
            foreach ($events as $event) {
                echo '<tr><td>' . h($event['created_at']) . '</td><td><strong>' . h($event['type']) . '</strong><br><span class="muted">' . h($event['message']) . '</span></td></tr>';
            }
            echo '</tbody></table>';
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
    echo '<div class="field"><label>Limite de clientes/leads</label><input type="number" min="0" name="lead_limit" value="' . h($plan['lead_limit'] ?? '') . '" placeholder="0 = ilimitado"></div>';
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
        'orcamento' => 'Orcamento',
        'pre_agendado' => 'Pre-agendado',
        'agendado' => 'Agendado',
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
        echo '<div class="calendar-cell' . h($outside) . '"><div class="calendar-date"><a href="' . h($dayHref) . '"><strong>' . h($cursor->format('d')) . '</strong></a><span class="badge ' . h($dayTone) . '">' . h((string)$dayCount) . '</span></div>';
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
        $dayAppointments = $byDay[$date] ?? [];
        $dayValue = array_reduce($dayAppointments, static fn(float $sum, array $appointment): float => $sum + appointment_effective_value($appointment, $pomadaUnit), 0.0);
        $dayHref = app_url('studio_agenda', ['cal_view' => 'day', 'date' => $date]);
        echo '<div class="calendar-cell"><div class="calendar-date"><a href="' . h($dayHref) . '"><strong>' . h($day->format('d/m')) . '</strong></a><br><span class="muted">' . h(['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'][$i]) . '</span></div>';
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
    echo '<h3 class="calendar-title">' . h($focus->format('d/m/Y')) . '</h3>';
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

function render_calendar_event(array $appointment): void
{
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($appointment['artist_color'] ?? '')) ? $appointment['artist_color'] : '#1f6f78';
    $name = $appointment['customer_name'] ?: ($appointment['lead_name'] ?: $appointment['title']);
    $href = app_url('studio_agenda', ['date' => (string)$appointment['appointment_date'], 'appointment_id' => (int)$appointment['id']]) . '#appointment-form';
    $status = (string)($appointment['status'] ?? '');
    $status_class = appointment_status_class($status);
    $status_bg = appointment_status_background($status);
    $status_border = appointment_status_border($status);
    echo '<a class="calendar-event ' . h($status_class) . '" href="' . h($href) . '" style="border-left-color:' . h($color) . '; background-color:' . h($status_bg) . '; border-color:' . h($status_border) . '"><strong>' . h(substr((string)$appointment['start_time'], 0, 5)) . '</strong> ' . h($name) . '<span class="badge ' . h(appointment_status_tone($status)) . '">' . h($status ?: 'sem status') . '</span></a>';
}

function render_calendar_block(array $appointment): void
{
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($appointment['artist_color'] ?? '')) ? $appointment['artist_color'] : '#1f6f78';
    $name = $appointment['customer_name'] ?: ($appointment['lead_name'] ?: $appointment['title']);
    $href = app_url('studio_agenda', ['date' => (string)$appointment['appointment_date'], 'appointment_id' => (int)$appointment['id']]) . '#appointment-form';
    $value = appointment_display_amount($appointment['value'] ?? 0);
    $deposit = appointment_display_amount($appointment['deposit_value'] ?? 0);
    $status = (string)($appointment['status'] ?? '');
    $status_class = appointment_status_class($status);
    $status_bg = appointment_status_background($status);
    $status_border = appointment_status_border($status);
    echo '<a class="appointment-block ' . h($status_class) . '" href="' . h($href) . '" style="border-left-color:' . h($color) . '; background-color:' . h($status_bg) . '; border-color:' . h($status_border) . '">';
    echo '<strong>' . h(format_date_pt((string)$appointment['appointment_date']) . ' ' . substr((string)$appointment['start_time'], 0, 5) . ($appointment['end_time'] ? ' - ' . substr((string)$appointment['end_time'], 0, 5) : '')) . '</strong>';
    echo '<span>' . h($name . ' - ' . $appointment['title']) . '</span>';
    echo '<span class="muted">' . h(($appointment['artist_name'] ?: 'Sem tatuador') . ' | ' . format_money($value) . ' | sinal ' . format_money($deposit)) . '</span>';
    echo '<span class="badge ' . h(appointment_status_tone($status)) . '">' . h($status ?: 'sem status') . '</span>';
    echo '</a>';
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
        echo '<tr>';
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
        echo '<tr>';
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
        echo '<div class="pipeline-column" style="--stage-color:' . h($color) . '" data-stage="' . h($stageName) . '">';
        echo '<div class="pipeline-column-head">';
        echo '<div><strong>' . h($stageName) . '</strong><span class="muted">Etapa do funil</span></div>';
        echo '<span class="badge">' . h((string)$stageCount) . ' leads</span>';
        echo '</div>';
        echo '<div class="pipeline-column-summary"><span><strong>' . h((string)$stageCount) . '</strong><small>Leads</small></span><span><strong>' . h(format_money($stageTotalValue)) . '</strong><small>Valor total</small></span><span><strong>' . h((string)$share) . '%</strong><small>Do funil</small></span></div>';
        if (!$leads) {
            echo '<p class="muted pipeline-empty">Nenhum lead nesta etapa.</p>';
        }
        foreach ($leads as $lead) {
            render_pipeline_card($lead, $stageNames);
        }
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
    $leadId = (int)$lead['id'];
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

    echo '<article class="lead-card' . ($isStale ? ' stale' : '') . '" draggable="true" data-lead-id="' . h((string)$leadId) . '" data-stage-name="' . h($currentStage) . '">';
    echo '<button type="button" class="lead-card-title-button" data-lead-open="' . h((string)$leadId) . '"><strong class="lead-card-title">' . h($lead['name'] ?: 'Lead sem nome') . '</strong></button>';
    echo '<div class="lead-card-submeta compact">';
    echo '<span class="badge">' . h($status !== '' ? $status : 'sem status') . '</span>';
    echo '<span class="badge">' . h((string)$score) . '/10</span>';
    if ($phoneLink !== '') {
        echo '<span class="badge">WhatsApp</span>';
    }
    $statusTone = in_array($status, ['agendado', 'pre_agendado'], true) ? 'warn' : (in_array($status, ['fechado'], true) ? 'ok' : (in_array($status, ['perdido'], true) ? 'danger' : ($isStale ? 'warn' : 'neutral')));
    echo '</div>';
    echo '<div class="lead-card-badges">';
    if ($isNew) {
        echo '<span class="badge ok">Novo</span>';
    }
    if ($isHot) {
        echo '<span class="badge ok">Quente</span>';
    }
    if ($isHighValue) {
        echo '<span class="badge">Alto valor</span>';
    }
    if ($isScheduled) {
        echo '<span class="badge warn">' . h($status === 'agendado' ? 'Agendado' : 'Pré-agendado') . '</span>';
    }
    if ($artistName !== '') {
        echo '<span class="badge">' . h($artistName) . '</span>';
    }
    if ($isStale) {
        echo '<span class="badge warn">parado há mais de 24h</span>';
    }
    echo '</div>';
    echo '<p class="lead-card-interest">' . h($lead['interest'] ?: 'Sem interesse descrito.') . '</p>';
    echo '<div class="lead-card-submeta">';
    echo '<span class="muted">' . h($lead['phone'] ?: 'Sem telefone') . '</span>';
    echo '<span class="muted">Origem: ' . h($lead['source'] ?: 'Sem origem') . '</span>';
    echo '<span class="muted">Atualizado ' . h($createdOrUpdated !== '' ? $createdOrUpdated : '-') . '</span>';
    echo '</div>';
    echo '<div class="lead-card-actions lead-card-actions-quick">';
    echo '<a class="btn tiny secondary" href="' . h(app_url('studio_lead', ['id' => $leadId])) . '">Ver</a>';
    echo '<button type="button" class="btn tiny secondary" data-lead-open="' . h((string)$leadId) . '">Detalhes</button>';
    echo '</div>';
    echo '<div class="lead-card-actions">';
    foreach ([['label' => 'Voltar', 'stage' => $prevStage], ['label' => 'Avancar', 'stage' => $nextStage]] as $move) {
        if ($move['stage'] === '') {
            continue;
        }
        echo '<button type="button" class="btn tiny secondary" data-move-stage="' . h($move['stage']) . '" data-lead-id="' . h((string)$leadId) . '" data-current-status="' . h($lead['status']) . '">' . h($move['label']) . '</button>';
    }
    echo '</div></article>';
}

function render_leads_table(array $leads): void
{
    if (!$leads) {
        echo '<p class="muted">Nenhum lead cadastrado ainda.</p>';
        return;
    }
    echo '<table class="table"><thead><tr><th>Lead</th><th>Funil</th><th>Valor</th><th>Nota</th><th>Acoes</th></tr></thead><tbody>';
    foreach ($leads as $lead) {
        $href = app_url('studio_lead', ['id' => (int)$lead['id']]);
        echo '<tr>';
        echo '<td><a href="' . h($href) . '"><strong>' . h($lead['name'] ?: 'Sem nome') . '</strong></a><br><span class="muted">' . h($lead['phone'] ?: $lead['interest']) . '</span></td>';
        echo '<td><span class="badge">' . h($lead['status']) . '</span><br><span class="muted">' . h($lead['pipeline_stage'] ?: '-') . '</span></td>';
        echo '<td>' . h(format_money($lead['estimated_value'] ?? 0)) . '<br><span class="muted">' . h($lead['source'] ?: '-') . '</span></td>';
        echo '<td><strong>' . h((string)($lead['lead_score'] ?? '-')) . '/10</strong></td>';
        echo '<td><a class="btn tiny secondary" href="' . h($href) . '">Abrir</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
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
    echo '<table class="table"><thead><tr><th>Quando</th><th>Atendimento</th><th>Tatuador</th><th>Valor</th><th>Status</th></tr></thead><tbody>';
    foreach ($appointments as $appointment) {
        $date = format_date_pt((string)$appointment['appointment_date']);
        $href = app_url('studio_agenda', ['date' => (string)$appointment['appointment_date'], 'appointment_id' => (int)$appointment['id']]) . '#appointment-form';
        echo '<tr>';
        echo '<td><strong>' . h($date) . '</strong><br><span class="muted">' . h(substr((string)$appointment['start_time'], 0, 5)) . ($appointment['end_time'] ? ' - ' . h(substr((string)$appointment['end_time'], 0, 5)) : '') . '</span></td>';
        echo '<td><strong>' . h($appointment['customer_name'] ?: $appointment['lead_name'] ?: $appointment['title']) . '</strong><br><span class="muted">' . h($appointment['description'] ?: $appointment['title']) . '</span></td>';
        echo '<td>' . h($appointment['artist_name'] ?: '-') . '</td>';
$appointmentValue = appointment_display_amount($appointment['value'] ?? 0);
$appointmentDeposit = appointment_display_amount($appointment['deposit_value'] ?? 0);
echo '<td>' . h(format_money($appointmentValue)) . '<br><span class="muted">Sinal ' . h(format_money($appointmentDeposit)) . '</span></td>';
        echo '<td><span class="badge">' . h($appointment['status']) . '</span><br><a class="btn tiny secondary" href="' . h($href) . '">Abrir</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function render_expenses_table(array $expenses): void
{
    if (!$expenses) {
        echo '<p class="muted">Nenhuma despesa cadastrada ainda.</p>';
        return;
    }
    echo '<table class="table"><thead><tr><th>Data</th><th>Despesa</th><th>Categoria</th><th>Valor</th></tr></thead><tbody>';
    foreach ($expenses as $expense) {
        $date = format_date_pt((string)$expense['expense_date']);
        echo '<tr>';
        echo '<td><strong>' . h($date) . '</strong><br><span class="muted">' . h($expense['payment_method'] ?: '-') . '</span></td>';
        echo '<td><strong>' . h($expense['description']) . '</strong><br><span class="muted">' . h($expense['notes'] ?: '-') . '</span></td>';
        echo '<td><span class="badge">' . h($expense['category']) . '</span></td>';
        echo '<td><strong>' . h(format_money($expense['amount'])) . '</strong></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
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
    echo '<table class="table"><thead><tr><th>Resposta</th><th>Categoria</th><th>Status</th></tr></thead><tbody>';
    foreach ($replies as $reply) {
        echo '<tr>';
        echo '<td><strong>' . h($reply['title']) . '</strong><br><span class="muted">' . h($reply['shortcut'] ?: '-') . '</span><br>' . h($reply['body']) . '</td>';
        echo '<td><span class="badge">' . h($reply['category']) . '</span></td>';
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
        $isUnreplied = false;
        $lastIncoming = trim((string)($conversation['last_incoming_at'] ?? ''));
        $lastOutgoing = trim((string)($conversation['last_outgoing_at'] ?? ''));
        if ($lastIncoming !== '' && ($lastOutgoing === '' || strtotime($lastIncoming) > strtotime($lastOutgoing))) {
            $isUnreplied = true;
        }
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
        echo '<td><strong>' . h($lastMessage !== '' ? $lastMessage : '-') . '</strong><br><span class="muted">' . h(studio_relative_time_label($messageMoment)) . '</span></td>';
        echo '<td>' . implode('<br>', $statusBadges) . '</td>';
        echo '<td><span class="badge ' . h($linkBadgeClass) . '">' . h($linkedLabel) . '</span></td>';
        echo '<td><strong>' . h((string)($conversation['lead_score'] ?? '-')) . '/10</strong><br><span class="muted">' . h($conversation['ai_last_status'] ?: '-') . '</span></td>';
        echo '<td><div class="actions"><a class="btn tiny" href="' . h($href) . '">Abrir</a>';
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
    $inferMediaType = static function (string $mime, string $mediaUrl, string $type): string {
        $mime = strtolower(trim($mime));
        $ext = strtolower(pathinfo((string)(parse_url($mediaUrl, PHP_URL_PATH) ?: $mediaUrl), PATHINFO_EXTENSION));
        if ($mime !== '') {
            if (str_starts_with($mime, 'image/')) return 'image';
            if (str_starts_with($mime, 'video/')) return 'video';
            if (str_starts_with($mime, 'audio/')) return 'audio';
        }
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        $videoExts = ['mp4', 'webm', 'mov', 'm4v', 'avi', 'mkv'];
        $audioExts = ['mp3', 'wav', 'ogg', 'oga', 'opus', 'webm', 'm4a', 'aac'];
        if (in_array($ext, $imageExts, true) || $type === 'image') return 'image';
        if (in_array($ext, $videoExts, true) || $type === 'video') return 'video';
        if (in_array($ext, $audioExts, true) || $type === 'audio') return 'audio';
        return $type ?: 'document';
    };

    echo '<div class="chat-thread">';
    if (!$messages) {
        echo '<p class="muted">Ainda nao ha mensagens registradas nesta conversa.</p>';
    }
    foreach ($messages as $message) {
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
        echo '<div class="chat-bubble">';
        if ($mediaUrl !== '') {
            if ($kind === 'image') {
                echo '<button type="button" class="chat-media-thumb" onclick="window.openMediaOverlay && window.openMediaOverlay(this.dataset.mediaSrc, this.dataset.mediaTitle, this.dataset.mediaKind)" data-media-src="' . h($mediaUrl) . '" data-media-title="' . h($mediaName ?: 'mídia') . '" data-media-kind="image" aria-label="Abrir imagem em tamanho grande"><img src="' . h($mediaUrl) . '" alt="' . h($mediaName ?: 'mídia') . '" style="max-width:260px;max-height:220px;border-radius:8px"></button>';
            } elseif ($kind === 'video') {
                echo '<video src="' . h($mediaUrl) . '" controls style="max-width:280px;max-height:220px;border-radius:8px"></video>';
            } elseif ($kind === 'audio') {
                echo '<audio src="' . h($mediaUrl) . '" controls style="width:280px;max-width:100%"></audio>';
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
        echo '<span>' . h(($message['sender_type'] ?? '-') . ' | ' . format_datetime_pt((string)($message['sent_at'] ?? '')) . (($message['status'] ?? '') ? ' | ' . $message['status'] : '')) . '</span>';
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
        echo '<tr>';
        echo '<td>' . h(($row[$labelKey] ?? '') ?: 'sem_informacao') . '</td>';
        echo '<td>' . h($row['qtd'] ?? 0) . '</td>';
        echo '<td><strong>' . h(format_money($row['total'] ?? 0)) . '</strong></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
