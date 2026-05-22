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
    if (!is_dir($gitDir)) {
        $version = 'dev';
        return $version;
    }
    $head = @file_get_contents($gitDir . DIRECTORY_SEPARATOR . 'HEAD');
    if ($head === false) {
        $version = 'dev';
        return $version;
    }
    $head = trim($head);
    if (str_starts_with($head, 'ref: ')) {
        $ref = trim(substr($head, 5));
        $refFile = $gitDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);
        if (is_file($refFile)) {
            $hash = trim((string)@file_get_contents($refFile));
            if ($hash !== '') {
                $version = substr($hash, 0, 7);
                return $version;
            }
        }
        $packed = $gitDir . DIRECTORY_SEPARATOR . 'packed-refs';
        if (is_file($packed)) {
            foreach (file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line[0] === '#' || $line[0] === '^') {
                    continue;
                }
                [$hash, $path] = array_pad(preg_split('/\s+/', trim($line), 2), 2, null);
                if ($path === $ref && is_string($hash) && $hash !== '') {
                    $version = substr($hash, 0, 7);
                    return $version;
                }
            }
        }
    } elseif ($head !== '') {
        $version = substr($head, 0, 7);
        return $version;
    }
    $version = 'dev';
    return $version;
}

$dbStatus = db_status();
$schemaReady = $dbStatus['ok'] && schema_ready();
$page = (string)($_GET['page'] ?? 'dashboard');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        if ($action === 'import_calendar_ics') {
            $studio = require_studio();
            if (empty($_FILES['ics_file']['tmp_name'])) {
                throw new RuntimeException('Envie um arquivo .ics valido.');
            }
            $result = studio_import_calendar_ics($studio, (string)$_FILES['ics_file']['tmp_name']);
            flash_set('success', 'ICS importado: ' . (int)$result['inserted'] . ' novos, ' . (int)$result['updated'] . ' atualizados, ' . (int)$result['skipped'] . ' ignorados.');
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
            redirect_to('studio_settings');
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
    echo '<link rel="stylesheet" href="assets/app.css"></head><body>';
    echo '<input type="text" readonly class="app-build-badge-input" data-build-version="' . h(app_build_version()) . '" value="v' . h(app_build_version()) . '" title="Clique para selecionar a versao">';
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
    echo '<a class="' . ($active === 'leads' ? 'active' : '') . '" href="' . h(app_url('studio_leads')) . '">Leads</a>';
    echo '<a class="' . ($active === 'customers' ? 'active' : '') . '" href="' . h(app_url('studio_customers')) . '">Clientes</a>';
    echo '<a class="' . ($active === 'agenda' ? 'active' : '') . '" href="' . h(app_url('studio_agenda')) . '">Agenda</a>';
    echo '<a class="' . ($active === 'whatsapp' ? 'active' : '') . '" href="' . h(app_url('studio_whatsapp')) . '">WhatsApp</a>';
    echo '<a class="' . ($active === 'finance' ? 'active' : '') . '" href="' . h(app_url('studio_finance')) . '">Financeiro</a>';
    echo '<a class="' . ($active === 'quick_replies' ? 'active' : '') . '" href="' . h(app_url('studio_quick_replies')) . '">Respostas</a>';
    echo '<a class="' . ($active === 'reports' ? 'active' : '') . '" href="' . h(app_url('studio_reports')) . '">Relatorios</a>';
    echo '<a class="' . ($active === 'assistant' ? 'active' : '') . '" href="' . h(app_url('studio_data_assistant')) . '">Assistente IA</a>';
    echo '<a class="' . ($active === 'settings' ? 'active' : '') . '" href="' . h(app_url('studio_settings')) . '">Configuracoes</a>';
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

$studioPages = ['studio_home', 'studio_leads', 'studio_lead', 'studio_customers', 'studio_customer', 'studio_agenda', 'studio_whatsapp', 'studio_whatsapp_conversation', 'studio_finance', 'studio_quick_replies', 'studio_reports', 'studio_data_assistant', 'studio_settings'];
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
        $recentLeads = studio_recent_leads($studio, 6);
        $appointments = studio_upcoming_appointments($studio, 6);

        echo '<section class="grid cols-3">';
        echo '<div class="panel"><p class="metric">' . h($stats['leads']) . '</p><p class="muted">Leads no funil</p></div>';
        echo '<div class="panel"><p class="metric">' . h($stats['customers']) . '</p><p class="muted">Clientes cadastrados</p></div>';
        echo '<div class="panel"><p class="metric">' . h($stats['appointments']) . '</p><p class="muted">Proximos atendimentos</p></div>';
        echo '<div class="panel"><p class="metric">' . h(format_money($stats['month_revenue'])) . '</p><p class="muted">Agenda no mes</p></div>';
        echo '<div class="panel"><p class="metric">' . h(format_money($stats['month_expenses'])) . '</p><p class="muted">Despesas no mes</p></div>';
        echo '<div class="panel"><p class="metric">' . h($stats['whatsapp_conversations']) . '</p><p class="muted">Conversas WhatsApp</p></div>';
        echo '</section>';
        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><h2>Leads recentes</h2><a class="btn secondary" href="' . h(app_url('studio_leads')) . '">Abrir funil</a></div>';
        if (!$recentLeads) {
            echo '<p class="muted">Nenhum lead cadastrado ainda.</p>';
        } else {
            echo '<table class="table"><thead><tr><th>Lead</th><th>Status</th><th>Nota</th></tr></thead><tbody>';
            foreach ($recentLeads as $lead) {
                echo '<tr><td><strong>' . h($lead['name'] ?: 'Sem nome') . '</strong><br><span class="muted">' . h($lead['phone'] ?: $lead['interest']) . '</span></td><td><span class="badge">' . h($lead['status']) . '</span></td><td>' . h((string)($lead['lead_score'] ?? '-')) . '/10</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><h2>Agenda proxima</h2><a class="btn secondary" href="' . h(app_url('studio_agenda')) . '">Abrir agenda</a></div>';
        if (!$appointments) {
            echo '<p class="muted">Nenhum horario futuro cadastrado.</p>';
        } else {
            echo '<table class="table"><thead><tr><th>Data</th><th>Cliente</th><th>Status</th></tr></thead><tbody>';
            foreach ($appointments as $appointment) {
                echo '<tr><td><strong>' . h(date('d/m/Y', strtotime((string)$appointment['appointment_date']))) . '</strong><br><span class="muted">' . h(substr((string)$appointment['start_time'], 0, 5)) . '</span></td><td>' . h($appointment['customer_name']) . '<br><span class="muted">' . h($appointment['title']) . '</span></td><td><span class="badge">' . h($appointment['status']) . '</span></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div></section>';
        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel"><h2>Valor em oportunidades abertas</h2><p class="metric">' . h(format_money($stats['open_value'])) . '</p><p class="muted">Soma estimada dos leads ainda nao perdidos ou fechados.</p></div>';
        echo '<div class="panel"><h2>Resultado simples do mes</h2><p class="metric">' . h(format_money($stats['month_revenue'] - $stats['month_expenses'])) . '</p><p class="muted">Agenda do mes menos despesas cadastradas.</p></div>';
        echo '</section>';
    }, $flash);
    exit;
}

if ($page === 'studio_customers') {
    $studio = require_studio();
    render_studio_shell('Clientes', 'Fichas de clientes, historico e proximos passos.', 'customers', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $customers = studio_list_customers($studio);
        echo '<section class="grid cols-2">';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_customer">';
        echo '<h2>Novo cliente</h2>';
        echo '<div class="field"><label>Nome</label><input name="name" required></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Telefone</label><input name="phone"></div><div class="field"><label>Email</label><input type="text" inputmode="email" name="email"></div></div>';
        echo '<div class="field"><label>Instagram</label><input name="instagram" placeholder="@cliente"></div>';
        echo '<div class="field"><label>Observacoes</label><textarea name="notes" placeholder="Preferencias, historico, restricoes, ideias de tatuagem..."></textarea></div>';
        echo '<button class="btn" type="submit">Salvar cliente</button>';
        echo '</form>';
        echo '<div class="panel"><h2>Clientes recentes</h2>';
        render_customers_table($customers);
        echo '</div></section>';
    }, $flash);
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
        echo '<p>' . h($customer['notes'] ?: 'Sem observacoes cadastradas.') . '</p>';
        echo '<div class="mini-metrics"><span><strong>' . h((string)count($activity['leads'])) . '</strong><small>Leads</small></span><span><strong>' . h((string)count($activity['appointments'])) . '</strong><small>Agendamentos</small></span><span><strong>' . h((string)count($activity['conversations'])) . '</strong><small>Conversas</small></span></div>';
        echo '</div>';

        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_customer"><input type="hidden" name="id" value="' . h((string)$customerId) . '"><input type="hidden" name="return_to_detail" value="1">';
        echo '<h2>Editar ficha</h2>';
        echo '<div class="grid cols-2"><div class="field"><label>Nome</label><input name="name" required value="' . h($customer['name'] ?? '') . '"></div><div class="field"><label>Telefone</label><input name="phone" value="' . h($customer['phone'] ?? '') . '"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Email</label><input type="text" inputmode="email" name="email" value="' . h($customer['email'] ?? '') . '"></div><div class="field"><label>Instagram</label><input name="instagram" value="' . h($customer['instagram'] ?? '') . '"></div></div>';
        echo '<div class="field"><label>Observacoes</label><textarea name="notes">' . h($customer['notes'] ?? '') . '</textarea></div>';
        echo '<button class="btn" type="submit">Salvar ficha</button>';
        echo '</form></section>';

        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel"><h2>Leads deste cliente</h2>';
        render_leads_table($activity['leads']);
        echo '</div>';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_appointment"><input type="hidden" name="customer_id" value="' . h((string)$customerId) . '"><input type="hidden" name="return_to_customer" value="' . h((string)$customerId) . '">';
        echo '<h2>Novo agendamento</h2>';
        echo '<div class="field"><label>Titulo</label><input name="title" required value="Atendimento"></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Lead</label><select name="lead_id"><option value="">Sem lead</option>';
        render_lead_options($leads);
        echo '</select></div><div class="field"><label>Tatuador</label><select name="artist_id"><option value="">Sem tatuador</option>';
        render_artist_options($artists);
        echo '</select></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Data</label><input type="date" name="appointment_date" required value="' . h(date('Y-m-d')) . '"></div><div class="field"><label>Inicio</label><input type="time" name="start_time" required value="10:00"></div><div class="field"><label>Fim</label><input type="time" name="end_time"></div></div>';
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
    render_studio_shell('Leads', 'Funil comercial visual, prioridades e oportunidades do estudio.', 'leads', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $customers = studio_list_customers($studio);
        $stages = studio_list_pipeline_stages($studio);
        $filters = [
            'q' => (string)($_GET['q'] ?? ''),
            'status' => (string)($_GET['status'] ?? ''),
            'source' => (string)($_GET['source'] ?? ''),
            'min_score' => (int)($_GET['min_score'] ?? 0),
        ];
        $leads = studio_list_leads($studio, $filters);
        $board = studio_pipeline_board($studio, $filters);
        echo '<section class="panel"><div class="actions" style="justify-content:space-between"><div><h2>Funil visual</h2><p class="muted">Acompanhe as oportunidades por etapa e mova o lead conforme a conversa evolui.</p></div><span class="badge">' . h((string)count($leads)) . ' leads</span></div>';
        echo '<form class="filter-bar" method="get"><input type="hidden" name="page" value="studio_leads">';
        echo '<input name="q" placeholder="Buscar nome, telefone, interesse..." value="' . h($filters['q']) . '">';
        echo '<select name="status"><option value="">Todos os status</option>';
        render_options(lead_status_options(), $filters['status']);
        echo '</select>';
        echo '<input name="source" placeholder="Origem" value="' . h($filters['source']) . '">';
        echo '<select name="min_score"><option value="0">Qualquer nota</option>';
        foreach ([5, 7, 9] as $score) {
            echo '<option value="' . h((string)$score) . '" ' . ((int)$filters['min_score'] === $score ? 'selected' : '') . '>Nota ' . h((string)$score) . '+</option>';
        }
        echo '</select><button class="btn secondary" type="submit">Filtrar</button><a class="btn secondary" href="' . h(app_url('studio_leads')) . '">Limpar</a></form>';
        render_pipeline_board($board, $stages);
        echo '</section>';

        echo '<section class="grid cols-2">';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_lead">';
        echo '<h2>Novo lead</h2>';
        echo '<div class="grid cols-2"><div class="field"><label>Nome</label><input name="name"></div><div class="field"><label>Telefone</label><input name="phone"></div></div>';
        echo '<div class="field"><label>Cliente vinculado</label><select name="customer_id"><option value="">Sem vinculo</option>';
        render_customer_options($customers);
        echo '</select></div>';
        echo '<div class="field"><label>Interesse</label><input name="interest" placeholder="Fechamento de braco, piercing, cobertura..."></div>';
        echo '<div class="grid cols-3">';
        echo '<div class="field"><label>Status</label><select name="status">';
        render_options(lead_status_options(), 'novo');
        echo '</select></div>';
        echo '<div class="field"><label>Etapa</label><select name="pipeline_stage">';
        foreach ($stages as $stage) {
            echo '<option value="' . h($stage['name']) . '">' . h($stage['name']) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="field"><label>Nota 0-10</label><input type="number" name="lead_score" min="0" max="10" value="5"></div>';
        echo '</div>';
        echo '<div class="grid cols-2"><div class="field"><label>Valor estimado</label><input name="estimated_value" placeholder="450,00"></div><div class="field"><label>Origem</label><input name="source" value="manual"></div></div>';
        echo '<button class="btn" type="submit">Salvar lead</button>';
        echo '</form>';
        echo '<div class="panel"><h2>Leads cadastrados</h2>';
        render_leads_table($leads);
        echo '</div></section>';
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
        $lead = studio_find_lead($studio, $leadId);
        if (!$lead) {
            echo '<section class="panel"><h2>Lead nao encontrado</h2><p class="muted">Volte para o funil e escolha outro lead.</p><a class="btn" href="' . h(app_url('studio_leads')) . '">Abrir funil</a></section>';
            return;
        }
        $customers = studio_list_customers($studio);
        $stages = studio_list_pipeline_stages($studio);
        $artists = studio_list_artists($studio);
        $activity = studio_lead_activity($studio, $leadId);

        echo '<section class="lead-detail-head">';
        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><div><h2>' . h($lead['name'] ?: 'Lead sem nome') . '</h2><p class="muted">' . h(($lead['phone'] ?: 'Sem telefone') . ' | ' . ($lead['source'] ?: 'sem origem')) . '</p></div><strong class="score-pill">' . h((string)($lead['lead_score'] ?? 0)) . '/10</strong></div>';
        echo '<p>' . h($lead['interest'] ?: 'Sem interesse descrito.') . '</p>';
        echo '<div class="mini-metrics"><span><strong>' . h(format_money($lead['estimated_value'] ?? 0)) . '</strong><small>Valor estimado</small></span><span><strong>' . h($lead['status']) . '</strong><small>Status</small></span><span><strong>' . h($lead['pipeline_stage'] ?: '-') . '</strong><small>Etapa</small></span></div>';
        echo '</div>';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="move_lead"><input type="hidden" name="lead_id" value="' . h((string)$leadId) . '"><input type="hidden" name="return_to_detail" value="1">';
        echo '<h2>Mover no funil</h2>';
        echo '<div class="field"><label>Etapa</label><select name="pipeline_stage">';
        foreach ($stages as $stage) {
            echo '<option value="' . h($stage['name']) . '" ' . ((string)$stage['name'] === (string)$lead['pipeline_stage'] ? 'selected' : '') . '>' . h($stage['name']) . '</option>';
        }
        echo '</select></div><div class="field"><label>Status</label><select name="status">';
        render_options(lead_status_options(), (string)$lead['status']);
        echo '</select></div><button class="btn" type="submit">Atualizar etapa</button>';
        echo '</form></section>';

        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_lead"><input type="hidden" name="id" value="' . h((string)$leadId) . '"><input type="hidden" name="return_to_detail" value="1">';
        echo '<h2>Editar lead</h2>';
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

        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_appointment"><input type="hidden" name="lead_id" value="' . h((string)$leadId) . '"><input type="hidden" name="customer_id" value="' . h((string)($lead['customer_id'] ?? 0)) . '"><input type="hidden" name="return_to_lead" value="' . h((string)$leadId) . '">';
        echo '<h2>Agendar este lead</h2>';
        echo '<div class="field"><label>Titulo</label><input name="title" required value="' . h($lead['interest'] ?: 'Atendimento') . '"></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Tatuador</label><select name="artist_id"><option value="">Sem tatuador</option>';
        render_artist_options($artists);
        echo '</select></div><div class="field"><label>Status</label><select name="status">';
        render_options(appointment_status_options(), 'pre_agendado');
        echo '</select></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Data</label><input type="date" name="appointment_date" required value="' . h(date('Y-m-d')) . '"></div><div class="field"><label>Inicio</label><input type="time" name="start_time" required value="10:00"></div><div class="field"><label>Fim</label><input type="time" name="end_time"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Valor</label><input name="value" value="' . h((string)($lead['estimated_value'] ?? '')) . '"></div><div class="field"><label>Sinal</label><input name="deposit_value"></div></div>';
        echo '<div class="field"><label>Descricao</label><textarea name="description" placeholder="Detalhes combinados com o cliente...">' . h($lead['interest'] ?? '') . '</textarea></div>';
        echo '<button class="btn" type="submit">Criar agendamento</button>';
        echo '</form></section>';

        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel"><h2>Historico rapido</h2>';
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
        echo '</div>';
        if ($view === 'month') {
            render_calendar_month($calendarAppointments, $focus);
        } elseif ($view === 'week') {
            render_calendar_week($calendarAppointments, $focus);
        } elseif ($view === 'day') {
            render_calendar_day($calendarAppointments, $focus);
        } else {
            render_calendar_list($calendarAppointments);
        }
        echo '</section>';

        echo '<section class="grid cols-2">';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_appointment">';
        echo '<h2>Novo horario</h2>';
        echo '<div class="field"><label>Titulo</label><input name="title" required value="Atendimento"></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Cliente</label><select name="customer_id"><option value="">Sem cliente</option>';
        render_customer_options($customers);
        echo '</select></div><div class="field"><label>Lead</label><select name="lead_id"><option value="">Sem lead</option>';
        render_lead_options($leads);
        echo '</select></div><div class="field"><label>Tatuador</label><select name="artist_id"><option value="">Sem tatuador</option>';
        render_artist_options($artists);
        echo '</select></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Data</label><input type="date" name="appointment_date" required value="' . h(date('Y-m-d')) . '"></div><div class="field"><label>Inicio</label><input type="time" name="start_time" required value="10:00"></div><div class="field"><label>Fim</label><input type="time" name="end_time"></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Status</label><select name="status">';
        render_options(appointment_status_options(), 'pre_agendado');
        echo '</select></div><div class="field"><label>Valor</label><input name="value" placeholder="600,00"></div><div class="field"><label>Sinal</label><input name="deposit_value" placeholder="100,00"></div></div>';
        echo '<div class="field"><label>Descricao</label><textarea name="description" placeholder="Detalhes do atendimento, local do corpo, referencia, observacoes..."></textarea></div>';
        echo '<button class="btn" type="submit">Salvar horario</button>';
        echo '</form>';
        echo '<div class="panel"><h2>Tatuadores</h2>';
        render_artists_table($artists);
        echo '<form class="form" method="post" style="margin-top:14px">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_artist">';
        echo '<div class="grid cols-2"><div class="field"><label>Nome</label><input name="name" placeholder="Nome do tatuador" required></div><div class="field"><label>Cor</label><input type="color" name="color" value="#1f6f78"></div></div>';
        echo '<div class="field"><label>Especialidade</label><input name="specialty" placeholder="Fine line, blackwork, realismo..."></div>';
        echo '<label class="checkline"><input type="checkbox" name="is_active" value="1" checked> Tatuador ativo</label>';
        echo '<button class="btn secondary" type="submit">Adicionar tatuador</button>';
        echo '</form></div></section>';
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
        ];
        $conversations = studio_list_whatsapp_conversations($studio, $filters);
        echo '<section class="grid cols-3">';
        echo '<div class="panel"><p class="metric">' . h($summary['total']) . '</p><p class="muted">Conversas</p></div>';
        echo '<div class="panel"><p class="metric">' . h($summary['bot']) . '</p><p class="muted">Em modo IA</p></div>';
        echo '<div class="panel"><p class="metric">' . h($summary['needs_human']) . '</p><p class="muted">Pedindo humano</p></div>';
        echo '</section>';
        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<div class="panel"><div class="actions" style="justify-content:space-between"><h2>Sessao do WhatsApp</h2>';
        $status = (string)($serviceStatus['status'] ?? 'offline');
        $statusLabel = $status === 'connected' ? 'Conectado' : ($status === 'waiting_qr' ? 'Aguardando codigo' : ($status === 'starting' ? 'Iniciando' : 'Nao conectado'));
        $badgeClass = $status === 'connected' ? 'ok' : ($status === 'waiting_qr' ? 'warn' : 'danger');
        echo '<span id="waStatusBadge" class="badge ' . h($badgeClass) . '">' . h($statusLabel) . '</span></div>';
        $sessionSummary = 'Nao conectado';
        $connectedPhone = preg_replace('/\D+/', '', (string)($serviceStatus['phone'] ?? ''));
        if ($connectedPhone !== '') {
            $sessionSummary = 'Conectado no numero ' . $connectedPhone;
        } elseif (!empty($serviceStatus['pairingCode'])) {
            $sessionSummary = 'Codigo pronto para parear';
        } elseif ($status === 'waiting_qr') {
            $sessionSummary = 'Aguardando o codigo de pareamento';
        } elseif ($status === 'starting') {
            $sessionSummary = 'Solicitando o codigo de pareamento';
        } elseif ($status === 'disconnected') {
            $sessionSummary = 'Sessao desconectada';
        } elseif ($status === 'error') {
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
        echo '<div id="waSessionState">';
        if (empty($serviceStatus['ok'])) {
            echo '<p class="muted">O servico Node ainda nao respondeu. Inicie com <code>npm install</code> e <code>npm start</code> em <code>services/whatsapp</code>.</p>';
            echo '<p class="muted">' . h($serviceStatus['error'] ?? '') . '</p>';
        } elseif (!empty($serviceStatus['pairingCode'])) {
            echo '<div class="wa-pairing-code">' . h((string)$serviceStatus['pairingCode']) . '</div>';
            echo '<p class="muted">Codigo para parear o numero ' . h((string)($serviceStatus['pairingPhone'] ?? '')) . '.</p>';
        } elseif ($connectedPhone !== '') {
            echo '<p>Numero conectado: <strong>' . h($connectedPhone) . '</strong></p>';
        } elseif ($status === 'starting') {
            echo '<p class="muted">Gerando codigo de pareamento. Se demorar mais de alguns segundos, clique em <strong>Gerar codigo</strong>.</p>';
        } elseif ($status === 'waiting_qr') {
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
        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="send_whatsapp_message">';
        echo '<h2>Enviar mensagem manual</h2>';
        echo '<div class="field"><label>Telefone</label><input name="phone" placeholder="5511999999999"></div>';
        echo '<div class="field"><label>Mensagem</label><textarea name="message" placeholder="Escreva uma mensagem curta para o cliente"></textarea></div>';
        echo '<button class="btn" type="submit">Enviar WhatsApp</button>';
        echo '</form>';
        echo '<div class="panel"><h2>Leitura rapida</h2>';
        echo '<p><strong>' . h($summary['human']) . '</strong> conversas em humano.</p>';
        echo '<p><strong>' . h($summary['analyzed']) . '</strong> conversas com alguma analise de IA.</p>';
        echo '<p><strong>' . h($summary['avg_score'] ?: '-') . '</strong> nota media dos leads importados.</p>';
        echo '<p class="muted">As mensagens recebidas pelo Baileys entram aqui e criam lead automaticamente quando o telefone ainda nao existir.</p>';
        echo '</div></section>';
        echo '<section class="panel" style="margin-top:16px"><div class="actions" style="justify-content:space-between"><h2>Conversas importadas</h2><span class="badge">Baileys multi-estudio</span></div>';
        echo '<form class="filter-bar" method="get"><input type="hidden" name="page" value="studio_whatsapp">';
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
        $messages = studio_whatsapp_messages($studio, $conversationId);
        $displayName = $conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: 'Contato WhatsApp'));
        $customers = studio_list_customers($studio);
        $leads = studio_list_leads($studio);
        $artists = studio_list_artists($studio);
        $quickReplies = array_values(array_filter(studio_list_quick_replies($studio), static fn(array $reply): bool => !empty($reply['is_active'])));
        $scheduleSuggestion = studio_whatsapp_schedule_suggestion($conversation, $messages, $artists);

        echo '<section class="conversation-layout">';
        echo '<div class="panel conversation-main">';
        echo '<div class="actions" style="justify-content:space-between"><div><h2>' . h($displayName) . '</h2><p class="muted">' . h($conversation['phone']) . '</p></div><div class="actions"><span class="score-pill small">' . h((string)($conversation['lead_score'] ?? 0)) . '/10</span><button class="btn secondary" type="button" data-mode-toggle="bot">Bot</button><button class="btn secondary" type="button" data-mode-toggle="human">Humano</button><button class="btn secondary" type="button" data-status-set="novo">Novo</button><button class="btn secondary" type="button" data-status-set="agendado">Agendado</button><a class="btn secondary" href="' . h(app_url('studio_whatsapp')) . '">Voltar</a></div></div>';
        echo '<div class="mini-metrics conversation-metrics"><span><strong data-message-count>' . h((string)count($messages)) . '</strong><small>Mensagens exibidas</small></span><span><strong>' . h($conversation['attendance_mode']) . '</strong><small>Atendimento</small></span><span><strong>' . h(!empty($conversation['needs_human']) ? 'sim' : 'nao') . '</strong><small>Quer humano</small></span></div>';
        echo '<div class="wa-session-summary conversation-status-summary"><strong data-wa-attendance>' . h($conversation['attendance_mode']) . '</strong><span data-wa-needs-human>' . h(!empty($conversation['needs_human']) ? 'pedindo humano' : 'sem pedido humano') . '</span><span data-wa-lead-status>' . h(($conversation['lead_status'] ?: 'em_conversa') . ' / ' . ($conversation['lead_pipeline_stage'] ?: 'em_conversa')) . '</span></div>';
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

        echo '<aside class="panel conversation-side">';
        echo '<h2>Cadastro e lead</h2>';
        echo '<form class="form" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="update_whatsapp_profile"><input type="hidden" name="conversation_id" value="' . h((string)$conversationId) . '">';
        echo '<div class="grid cols-2"><div class="field"><label>Nome</label><input name="name" value="' . h($displayName) . '"></div><div class="field"><label>Telefone</label><input name="phone" value="' . h($conversation['phone']) . '"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Email</label><input type="text" inputmode="email" name="email" value="' . h($conversation['customer_email'] ?? '') . '"></div><div class="field"><label>Instagram</label><input name="instagram" value="' . h($conversation['customer_instagram'] ?? '') . '"></div></div>';
        echo '<div class="field"><label>Cliente vinculado</label><select name="customer_id"><option value="">Criar/sem cliente</option>';
        render_customer_options($customers, (int)($conversation['customer_id'] ?? 0));
        echo '</select></div>';
        echo '<div class="field"><label>Lead vinculado</label><select name="lead_id"><option value="">Criar/sem lead</option>';
        render_lead_options($leads, (int)($conversation['lead_id'] ?? 0));
        echo '</select></div>';
        echo '<div class="field"><label>Interesse</label><input name="interest" value="' . h($conversation['lead_interest'] ?: $conversation['last_message_preview'] ?: '') . '"></div>';
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
        echo '<div class="field"><label>Observacoes do cliente</label><textarea name="notes">' . h($conversation['customer_notes'] ?? '') . '</textarea></div>';
        echo '<label class="checkline"><input type="checkbox" name="needs_human" value="1" ' . (!empty($conversation['needs_human']) ? 'checked' : '') . '> Cliente pediu humano</label>';
        echo '<label class="checkline"><input type="checkbox" name="create_customer" value="1" ' . (empty($conversation['customer_id']) ? 'checked' : '') . '> Criar/atualizar ficha de cliente</label>';
        echo '<label class="checkline"><input type="checkbox" name="create_lead" value="1" ' . (empty($conversation['lead_id']) ? 'checked' : '') . '> Criar/atualizar lead</label>';
        echo '<button class="btn" type="submit">Salvar cadastro</button>';
        echo '</form>';

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
            echo '</div>';
            echo '<button class="btn secondary" type="button" id="applyScheduleSuggestionButton" style="margin-top:10px">Usar sugestao</button>';
        } else {
            echo '<p class="muted">Ainda sem sugestao para esta conversa.</p>';
        }
        echo '</details>';

        echo '<form class="form action-card compact-action" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_appointment"><input type="hidden" name="customer_id" value="' . h((string)($conversation['customer_id'] ?? 0)) . '"><input type="hidden" name="lead_id" value="' . h((string)($conversation['lead_id'] ?? 0)) . '"><input type="hidden" name="return_to_conversation" value="' . h((string)$conversationId) . '">';
        echo '<h3>Criar agendamento</h3>';
        echo '<div class="field"><label>Titulo</label><input name="title" required value="' . h($conversation['lead_interest'] ?: 'Atendimento') . '"></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Tatuador</label><select name="artist_id"><option value="">Sem tatuador</option>';
        render_artist_options($artists);
        echo '</select></div><div class="field"><label>Status</label><select name="status">';
        render_options(appointment_status_options(), 'pre_agendado');
        echo '</select></div></div>';
        echo '<div class="grid cols-3"><div class="field"><label>Data</label><input type="date" name="appointment_date" required value="' . h(date('Y-m-d')) . '"></div><div class="field"><label>Inicio</label><input type="time" name="start_time" required value="10:00"></div><div class="field"><label>Fim</label><input type="time" name="end_time"></div></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Valor</label><input name="value" value="' . h((string)($conversation['lead_estimated_value'] ?? '')) . '"></div><div class="field"><label>Sinal</label><input name="deposit_value"></div></div>';
        echo '<div class="field"><label>Descricao</label><textarea name="description">' . h($conversation['last_message_preview'] ?? '') . '</textarea></div>';
        echo '<button class="btn secondary" type="submit">Criar horario</button>';
        echo '</form>';

        echo '<div class="info-list">';
        echo '<p><strong>Cliente:</strong> ' . ($conversation['customer_id'] ? '<a href="' . h(app_url('studio_customer', ['id' => (int)$conversation['customer_id']])) . '">' . h($conversation['customer_name'] ?: 'Abrir cliente') . '</a>' : '<span class="muted">sem cliente vinculado</span>') . '</p>';
        echo '<p><strong>Lead:</strong> ' . ($conversation['lead_id'] ? '<a href="' . h(app_url('studio_lead', ['id' => (int)$conversation['lead_id']])) . '">' . h($conversation['lead_name'] ?: 'Abrir lead') . '</a>' : '<span class="muted">sem lead vinculado</span>') . '</p>';
        echo '<p><strong>Interesse:</strong> ' . h($conversation['lead_interest'] ?: '-') . '</p>';
        echo '<p><strong>Funil:</strong> ' . h(($conversation['lead_status'] ?: '-') . ' / ' . ($conversation['lead_pipeline_stage'] ?: '-')) . '</p>';
        echo '<p><strong>Ultima mensagem:</strong> ' . h($conversation['last_message_at'] ?: '-') . '</p>';
        echo '</div></aside></section>';
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
        echo 'const chatThread = document.querySelector(".chat-thread");';
        echo 'const messageCountLabel = document.querySelector("[data-message-count]");';
        echo 'const conversationId = ' . (int)$conversationId . ';';
        echo 'let lastMessageCount = ' . count($messages) . ';';
        echo 'let recorder = null; let stream = null; let chunks = []; let recordedFile = null; let recordingTimer = null; let startedAt = 0;';
        echo 'function clearAttachment(){ input.value = ""; recordedFile = null; preview.classList.add("hidden"); preview.innerHTML = ""; recordState.textContent = ""; if (recordingTimer) { clearInterval(recordingTimer); recordingTimer = null; } if (recorder && recorder.state !== "inactive") recorder.stop(); if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } recordBtn.textContent = "Gravar audio"; }';
        echo 'function escapeHtml(value){ return String(value ?? "").replace(/[&<>"\x27]/g, char => ({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#39;" }[char] || char)); }';
        echo 'function inferMediaType(mime, mediaUrl, type){ const normalizedMime = String(mime || "").toLowerCase().trim(); const normalizedType = String(type || "").toLowerCase().trim(); const rawUrl = String(mediaUrl || ""); const ext = (rawUrl.split("?")[0].split("#")[0].split(".").pop() || "").toLowerCase(); if (normalizedMime) { if (normalizedMime.startsWith("image/")) return "image"; if (normalizedMime.startsWith("video/")) return "video"; if (normalizedMime.startsWith("audio/")) return "audio"; } if (["jpg","jpeg","png","gif","webp","bmp","svg"].includes(ext) || normalizedType === "image") return "image"; if (["mp4","webm","mov","m4v","avi","mkv"].includes(ext) || normalizedType === "video") return "video"; if (["mp3","wav","ogg","oga","opus","webm","m4a","aac"].includes(ext) || normalizedType === "audio") return "audio"; return normalizedType || "document"; }';
        echo 'function renderChatMessage(message){ const direction = String(message?.direction || "in"); const className = direction === "out" ? "out" : "in"; const body = String(message?.body || ""); const type = String(message?.message_type || "texto"); const mime = String(message?.media_mime || ""); const mediaUrl = String(message?.media_url || ""); let mediaName = String(message?.media_file_name || ""); const kind = inferMediaType(mime, mediaUrl, type); if (!mediaName && mediaUrl) { mediaName = decodeURIComponent(mediaUrl.split("/").pop().split("?")[0] || ""); } let html = `<div class="chat-message ${className}"><div class="chat-bubble">`; if (mediaUrl) { if (kind === "image") { html += `<button type="button" class="chat-media-thumb" onclick="window.openMediaOverlay && window.openMediaOverlay(this.dataset.mediaSrc, this.dataset.mediaTitle, this.dataset.mediaKind)" data-media-src="${escapeHtml(mediaUrl)}" data-media-title="${escapeHtml(mediaName || "mídia")}" data-media-kind="image"><img src="${escapeHtml(mediaUrl)}" alt="${escapeHtml(mediaName || "mídia")}" style="max-width:260px;max-height:220px;border-radius:8px"></button>`; } else if (kind === "video") { html += `<button type="button" class="chat-media-thumb" onclick="window.openMediaOverlay && window.openMediaOverlay(this.dataset.mediaSrc, this.dataset.mediaTitle, this.dataset.mediaKind)" data-media-src="${escapeHtml(mediaUrl)}" data-media-title="${escapeHtml(mediaName || "mídia")}" data-media-kind="video"><video src="${escapeHtml(mediaUrl)}" style="max-width:280px;max-height:220px;border-radius:8px"></video></button>`; } else if (kind === "audio") { html += `<audio src="${escapeHtml(mediaUrl)}" controls style="width:280px;max-width:100%"></audio>`; if (!String(message?.transcricao || message?.transcript || "").trim()) { html += `<button class="btn tiny secondary" type="button" data-transcribe-audio="${escapeHtml(message?.message_id || "")}" data-media-url="${escapeHtml(mediaUrl)}">Transcrever audio</button>`; } } else { html += `<a class="muted" href="${escapeHtml(mediaUrl)}" target="_blank" rel="noopener">Abrir anexo${mediaName ? `: ${escapeHtml(mediaName)}` : ""}</a>`; } } if (body) { html += `<p>${escapeHtml(body).replace(/\n/g, "<br>")}</p>`; } else if (type !== "texto" && !mediaUrl) { html += `<p>[${escapeHtml(type)}]</p>`; } const transcribedText = String(message?.transcricao || message?.transcript || "").trim(); const transcribedError = String(message?.transcricao_erro || message?.transcript_error || "").trim(); if (transcribedText) { html += `<div class="chat-transcription-result">${escapeHtml(transcribedText)}</div>`; } if (transcribedError) { html += `<div class="chat-transcription-error">${escapeHtml(transcribedError)}</div>`; } html += `<span>${escapeHtml(String(message?.sender_type || "-"))} | ${escapeHtml(String(message?.sent_at || "-"))}${String(message?.status || "") ? ` | ${escapeHtml(String(message.status))}` : ""}</span>`; html += `</div></div>`; return html; }';
        echo 'function renderChatThread(messages){ if (!chatThread) return; if (!Array.isArray(messages) || messages.length === 0) { chatThread.innerHTML = `<p class="muted">Ainda nao ha mensagens registradas nesta conversa.</p>`; return; } chatThread.innerHTML = messages.map(renderChatMessage).join(""); }';
        echo 'function updateConversationMeta(count){ if (messageCountLabel) messageCountLabel.textContent = String(count); }';
        echo 'function renderPreview(){ const file = recordedFile || (input.files && input.files[0]); if (!file) { preview.classList.add("hidden"); preview.innerHTML = ""; return; } const url = URL.createObjectURL(file); let content = `<div class=\"flex items-center gap-3 flex-wrap\">`; if (file.type.startsWith("image/")) { content += `<img src=\"${url}\" style=\"max-width:180px;max-height:140px;border-radius:8px\">`; } else if (file.type.startsWith("audio/")) { content += `<audio src=\"${url}\" controls style=\"width:280px;max-width:100%\"></audio>`; } else if (file.type.startsWith("video/")) { content += `<video src=\"${url}\" controls style=\"max-width:220px;max-height:160px\"></video>`; } content += `<div><strong>${file.name}</strong><div class=\"muted text-sm\">${file.type || "arquivo"}</div></div><button type=\"button\" class=\"btn tiny secondary\" id=\"clearAttachmentBtn\">Remover</button></div>`; preview.classList.remove("hidden"); preview.innerHTML = content; const clearBtn = document.getElementById("clearAttachmentBtn"); if (clearBtn) clearBtn.addEventListener("click", clearAttachment); }';
        echo 'attachBtn.addEventListener("click", () => input.click());';
        echo 'input.addEventListener("change", () => { recordedFile = null; renderPreview(); });';
        echo 'document.querySelectorAll(".quick-reply-copy").forEach(button => button.addEventListener("click", () => { const reply = button.dataset.reply || ""; textarea.value = textarea.value ? textarea.value + "\\n" + reply : reply; textarea.focus(); }));';
        echo 'function openMediaOverlay(src, title, kind){ if (!src || !mediaOverlay || !mediaOverlayBody || !mediaOverlayTitle) return; mediaOverlayTitle.textContent = title || "Midia"; if (kind === "video") { mediaOverlayBody.innerHTML = `<video src="${src}" controls autoplay style="max-width:100%;max-height:82vh;border-radius:10px"></video>`; } else if (kind === "audio") { mediaOverlayBody.innerHTML = `<audio src="${src}" controls autoplay style="width:min(680px,100%)"></audio>`; } else { mediaOverlayBody.innerHTML = `<div style="width:100%;display:flex;justify-content:center"><img src="${src}" alt="${title || "Midia"}" style="max-width:100%;max-height:82vh;object-fit:contain;border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.35)"></div>`; } mediaOverlay.classList.remove("hidden"); } window.openMediaOverlay = openMediaOverlay;';
        echo 'document.addEventListener("click", (event) => { const button = event.target.closest(".chat-media-thumb"); if (!button) return; const src = button.dataset.mediaSrc || ""; const title = button.dataset.mediaTitle || "Midia"; const kind = button.dataset.mediaKind || "image"; openMediaOverlay(src, title, kind); });';
        echo 'if (closeMediaOverlay && mediaOverlay) { closeMediaOverlay.addEventListener("click", () => mediaOverlay.classList.add("hidden")); mediaOverlay.addEventListener("click", event => { if (event.target === mediaOverlay) mediaOverlay.classList.add("hidden"); }); document.addEventListener("keydown", event => { if (event.key === "Escape") mediaOverlay.classList.add("hidden"); }); }';
        echo 'if (applyScheduleSuggestionButton) { applyScheduleSuggestionButton.addEventListener("click", () => { const title = ' . json_encode($scheduleSuggestion['title'] ?? '') . '; const date = ' . json_encode($scheduleSuggestion['date'] ?? '') . '; const time = ' . json_encode($scheduleSuggestion['time'] ?? '') . '; const desc = ' . json_encode($scheduleSuggestion['description'] ?? '') . '; const artist = ' . json_encode($scheduleSuggestion['artist_id'] ?? '') . '; const titleInput = document.querySelector(\'[name="title"]\'); const dateInput = document.querySelector(\'[name="appointment_date"]\'); const startTimeInput = document.querySelector(\'[name="start_time"]\'); const descInput = document.querySelector(\'[name="description"]\'); const artistInput = document.querySelector(\'[name="artist_id"]\'); if (titleInput) titleInput.value = title; if (dateInput) dateInput.value = date; if (startTimeInput) startTimeInput.value = time; if (descInput) descInput.value = desc; if (artistInput && artist) artistInput.value = artist; document.getElementById("scheduleButton").click(); }); }';
        echo 'const csrfToken = document.querySelector(\'input[name="csrf_token"]\')?.value || "";';
        echo 'const attendanceLabel = document.querySelector("[data-wa-attendance]"); const needsHumanLabel = document.querySelector("[data-wa-needs-human]"); const leadStatusLabel = document.querySelector("[data-wa-lead-status]"); const attendanceSelect = document.querySelector(\'[name="attendance_mode"]\'); const statusSelect = document.querySelector(\'[name="status"]\'); const pipelineSelect = document.querySelector(\'[name="pipeline_stage"]\'); const needsHumanCheckbox = document.querySelector(\'[name="needs_human"]\');';
        echo 'function syncConversationUI(data){ const mode = String(data?.attendance_mode || ""); const needsHuman = !!data?.needs_human; const leadStatus = String(data?.lead_status || ""); const leadStage = String(data?.lead_pipeline_stage || ""); if (attendanceLabel && mode) attendanceLabel.textContent = mode; if (needsHumanLabel) needsHumanLabel.textContent = needsHuman ? "pedindo humano" : "sem pedido humano"; if (leadStatusLabel) leadStatusLabel.textContent = `${leadStatus || "em_conversa"} / ${leadStage || "em_conversa"}`; if (attendanceSelect && mode) attendanceSelect.value = mode; if (statusSelect && leadStatus) statusSelect.value = leadStatus; if (pipelineSelect && leadStage) pipelineSelect.value = leadStage; if (needsHumanCheckbox) needsHumanCheckbox.checked = needsHuman; }';
        echo 'async function postConversationUpdate(payload, errorMessage){ const body = new URLSearchParams({ csrf_token: csrfToken, conversation_id: String(conversationId), ...payload }); const response = await fetch(window.location.pathname + window.location.search, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json, text/plain, */*" }, body }); const text = await response.text(); if (!response.ok) { throw new Error(text.trim() || errorMessage); } return text; }';
        echo 'document.querySelectorAll("[data-mode-toggle]").forEach(button => button.addEventListener("click", async () => { try { const payload = { action: "update_whatsapp_profile", attendance_mode: button.dataset.modeToggle === "bot" ? "bot" : "human", needs_human: button.dataset.modeToggle === "bot" ? 0 : 1 }; await postConversationUpdate(payload, "Nao foi possivel atualizar o atendimento."); syncConversationUI(payload); } catch (error) { alert(error.message || "Nao foi possivel atualizar o atendimento."); } }));';
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
        echo '<div class="panel"><p class="metric">' . h(format_money($summary['appointments_month'])) . '</p><p class="muted">Agenda no mes</p></div>';
        echo '<div class="panel"><p class="metric">' . h(format_money($summary['expenses_month'])) . '</p><p class="muted">Despesas no mes</p></div>';
        echo '<div class="panel"><p class="metric">' . h(format_money($summary['balance_month'])) . '</p><p class="muted">Resultado simples</p></div>';
        echo '</section>';
        echo '<section class="grid cols-2" style="margin-top:16px">';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_expense">';
        echo '<h2>Nova despesa</h2>';
        echo '<div class="grid cols-2"><div class="field"><label>Categoria</label><input name="category" value="Geral"></div><div class="field"><label>Data</label><input type="date" name="expense_date" value="' . h(date('Y-m-d')) . '" required></div></div>';
        echo '<div class="field"><label>Descricao</label><input name="description" required placeholder="Material, aluguel, trafego, insumo..."></div>';
        echo '<div class="grid cols-2"><div class="field"><label>Valor</label><input name="amount" required placeholder="120,00"></div><div class="field"><label>Pagamento</label><input name="payment_method" placeholder="Pix, cartao, dinheiro..."></div></div>';
        echo '<div class="field"><label>Observacoes</label><textarea name="notes"></textarea></div>';
        echo '<button class="btn" type="submit">Salvar despesa</button>';
        echo '</form>';
        echo '<div class="panel"><h2>Despesas por categoria</h2>';
        render_category_totals($summary['by_category']);
        echo '</div></section>';
        echo '<section class="panel" style="margin-top:16px"><h2>Despesas recentes</h2>';
        render_expenses_table($expenses);
        echo '</section>';
    }, $flash);
    exit;
}

if ($page === 'studio_quick_replies') {
    $studio = require_studio();
    render_studio_shell('Respostas rapidas', 'Textos prontos para atendimento e futura IA do WhatsApp.', 'quick_replies', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $replies = studio_list_quick_replies($studio);
        echo '<section class="grid cols-2">';
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_quick_reply">';
        echo '<h2>Nova resposta</h2>';
        echo '<div class="grid cols-2"><div class="field"><label>Titulo</label><input name="title" required></div><div class="field"><label>Atalho</label><input name="shortcut" placeholder="/atalho"></div></div>';
        echo '<div class="field"><label>Categoria</label><input name="category" value="Geral"></div>';
        echo '<div class="field"><label>Texto</label><textarea name="body" required placeholder="Mensagem pronta para usar no atendimento..."></textarea></div>';
        echo '<label class="checkline"><input type="checkbox" name="is_active" value="1" checked> Resposta ativa</label>';
        echo '<button class="btn" type="submit">Salvar resposta</button>';
        echo '</form>';
        echo '<div class="panel"><h2>Biblioteca</h2>';
        render_quick_replies_table($replies);
        echo '</div></section>';
    }, $flash);
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
        $reports = studio_report_data($studio);
        echo '<section class="grid cols-2">';
        echo '<div class="panel"><h2>Leads por status</h2>';
        render_report_table($reports['leads_by_status'], 'status');
        echo '</div><div class="panel"><h2>Leads por origem</h2>';
        render_report_table($reports['leads_by_source'], 'source');
        echo '</div><div class="panel"><h2>Agenda por status</h2>';
        render_report_table($reports['appointments_by_status'], 'status');
        echo '</div><div class="panel"><h2>Agenda por mes</h2>';
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
            echo '<pre class="answer-box">' . h($result['answer']) . '</pre>';
        }
        echo '</section>';
    }, $flash);
    exit;
}

if ($page === 'studio_settings') {
    $studio = require_studio();
    render_studio_shell('Configuracoes do estudio', 'Regras comerciais e preparacao dos modulos de IA/WhatsApp.', 'settings', function () use ($studio) {
        $dbStatus = studio_db_status_for($studio);
        if (!$dbStatus['ok']) {
            render_studio_db_missing($studio, $dbStatus['error']);
            return;
        }
        $settings = studio_settings($studio);
        echo '<form class="form panel" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="save_studio_settings">';
        echo '<h2>Base do estudio</h2>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Nome do estudio</label><input name="studio_name" value="' . h($settings['studio_name'] ?? $studio['name']) . '" required></div>';
        echo '<div class="field"><label>Modelo IA</label><input name="ai_model" value="' . h($settings['ai_model'] ?? $studio['ai_model'] ?? 'llama3:8b') . '"></div>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<label class="checkline"><input type="checkbox" name="ai_enabled" value="1" ' . (!empty($settings['ai_enabled']) ? 'checked' : '') . '> Permitir IA responder clientes quando a conversa estiver em modo IA</label>';
        echo '<label class="checkline"><input type="checkbox" name="whatsapp_enabled" value="1" ' . (!empty($settings['whatsapp_enabled']) ? 'checked' : '') . '> Permitir conexao WhatsApp/Baileys neste estudio</label>';
        echo '</div>';
        echo '<div class="grid cols-2">';
        echo '<div class="field"><label>Padrao das novas conversas WhatsApp</label><select name="whatsapp_default_mode">';
        render_options(['human' => 'Humano atende primeiro', 'bot' => 'IA atende primeiro'], (string)($settings['whatsapp_default_mode'] ?? 'human'));
        echo '</select></div>';
        echo '<div class="field"><label>URL do servico Baileys</label><input name="whatsapp_service_url" value="' . h($settings['whatsapp_service_url'] ?? 'http://localhost:3010') . '"></div>';
        echo '</div>';
        echo '<div class="field"><label>Regras e informacoes para IA</label><textarea name="business_rules" placeholder="Endereco, horarios, politicas, estilos, preco minimo, sinal, o que a IA pode prometer e o que precisa confirmar...">' . h($settings['business_rules'] ?? $studio['business_rules'] ?? '') . '</textarea></div>';
        echo '<p class="muted">Resumo: a primeira opcao libera a IA para responder somente conversas marcadas como IA. A segunda libera a conexao do numero WhatsApp deste estudio. O padrao das novas conversas define se um novo cliente entra com humano ou IA primeiro.</p>';
        echo '<div class="actions"><button class="btn" type="submit">Salvar configuracoes</button><span class="muted">Essas regras ficam no banco isolado do estudio.</span></div>';
        echo '</form>';
    }, $flash);
    exit;
}

if (!current_admin() && $page !== 'login') {
    redirect_to('login');
}

if ($page === 'login') {
    render_auth_page('Entrar', 'Acesse o painel de gerenciamento dos estudios.', function () {
        echo '<form class="form" method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="login">';
        echo '<div class="field"><label>Email</label><input name="email" type="text" inputmode="email" required autocomplete="email"></div>';
        echo '<div class="field"><label>Senha</label><input name="password" type="password" required autocomplete="current-password"></div>';
        echo '<button class="btn" type="submit">Entrar</button>';
        echo '</form>';
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
                echo '<tr>';
                echo '<td><strong>' . h($studio['name']) . '</strong><br><span class="muted">' . h($studio['slug']) . '</span></td>';
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
        echo '<section class="grid cols-3">';
        echo '<div class="panel"><h2>Status</h2><span class="badge ' . ($studio['status'] === 'active' ? 'ok' : 'warn') . '">' . h($studio['status']) . '</span></div>';
        echo '<div class="panel"><h2>Banco</h2><p>' . h($studio['database_name']) . '</p><span class="badge ' . ($dbOk ? 'ok' : 'warn') . '">' . ($dbOk ? 'encontrado' : 'pendente') . '</span></div>';
        echo '<div class="panel"><h2>Plano</h2><p>' . h($studio['plan_name']) . '</p></div>';
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
    echo '<div class="field"><label>Plano</label><input name="plan_name" value="' . h($studio['plan_name'] ?? 'alpha') . '"></div>';
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
        'confirmado' => 'Confirmado',
        'em_atendimento' => 'Em atendimento',
        'concluido' => 'Concluido',
        'cancelado' => 'Cancelado',
    ];
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

function render_calendar_month(array $appointments, DateTimeImmutable $focus): void
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
        echo '<div class="calendar-cell' . h($outside) . '"><div class="calendar-date">' . h($cursor->format('d')) . '</div>';
        foreach (array_slice($byDay[$date] ?? [], 0, 4) as $appointment) {
            render_calendar_event($appointment);
        }
        $extra = count($byDay[$date] ?? []) - 4;
        if ($extra > 0) {
            echo '<span class="muted">+' . h($extra) . ' horarios</span>';
        }
        echo '</div>';
        $cursor = $cursor->modify('+1 day');
    }
    echo '</div>';
}

function render_calendar_week(array $appointments, DateTimeImmutable $focus): void
{
    $byDay = appointments_by_day($appointments);
    $start = $focus->modify('monday this week');
    echo '<div class="calendar-grid week">';
    for ($i = 0; $i < 7; $i++) {
        $day = $start->modify('+' . $i . ' days');
        $date = $day->format('Y-m-d');
        echo '<div class="calendar-cell"><div class="calendar-date"><strong>' . h($day->format('d/m')) . '</strong><br><span class="muted">' . h(['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'][$i]) . '</span></div>';
        foreach ($byDay[$date] ?? [] as $appointment) {
            render_calendar_event($appointment);
        }
        if (empty($byDay[$date])) {
            echo '<span class="muted">Livre</span>';
        }
        echo '</div>';
    }
    echo '</div>';
}

function render_calendar_day(array $appointments, DateTimeImmutable $focus): void
{
    echo '<h3 class="calendar-title">' . h($focus->format('d/m/Y')) . '</h3>';
    if (!$appointments) {
        echo '<p class="muted">Nenhum agendamento neste dia.</p>';
        return;
    }
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
    echo '<div class="calendar-event" style="border-left-color:' . h($color) . '"><strong>' . h(substr((string)$appointment['start_time'], 0, 5)) . '</strong> ' . h($name) . '</div>';
}

function render_calendar_block(array $appointment): void
{
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($appointment['artist_color'] ?? '')) ? $appointment['artist_color'] : '#1f6f78';
    $name = $appointment['customer_name'] ?: ($appointment['lead_name'] ?: $appointment['title']);
    echo '<div class="appointment-block" style="border-left-color:' . h($color) . '">';
    echo '<strong>' . h(date('d/m/Y', strtotime((string)$appointment['appointment_date'])) . ' ' . substr((string)$appointment['start_time'], 0, 5)) . '</strong>';
    echo '<span>' . h($name) . ' - ' . h($appointment['title']) . '</span>';
    echo '<span class="muted">' . h(($appointment['artist_name'] ?: 'Sem tatuador') . ' | ' . $appointment['status'] . ' | ' . format_money($appointment['value'] ?? 0)) . '</span>';
    echo '</div>';
}

function render_customers_table(array $customers): void
{
    if (!$customers) {
        echo '<p class="muted">Nenhum cliente cadastrado ainda.</p>';
        return;
    }
    echo '<table class="table"><thead><tr><th>Cliente</th><th>Contato</th><th>Observacoes</th></tr></thead><tbody>';
    foreach ($customers as $customer) {
        echo '<tr>';
        echo '<td><a href="' . h(app_url('studio_customer', ['id' => (int)$customer['id']])) . '"><strong>' . h($customer['name'] ?: 'Sem nome') . '</strong></a><br><span class="muted">' . h($customer['instagram'] ?: '-') . '</span></td>';
        echo '<td>' . h($customer['phone'] ?: '-') . '<br><span class="muted">' . h($customer['email'] ?: '-') . '</span></td>';
        echo '<td>' . h($customer['notes'] ?: '-') . '</td>';
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
    echo '<div class="pipeline-board">';
    foreach ($board as $stageName => $column) {
        $stage = $column['stage'];
        $leads = $column['leads'];
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($stage['color'] ?? '')) ? $stage['color'] : '#667085';
        echo '<div class="pipeline-column" style="--stage-color:' . h($color) . '">';
        echo '<div class="pipeline-column-head"><strong>' . h($stageName) . '</strong><span class="badge">' . h((string)count($leads)) . '</span></div>';
        echo '<p class="muted">' . h(format_money($column['total_value'] ?? 0)) . '</p>';
        if (!$leads) {
            echo '<p class="muted">Sem leads nesta etapa.</p>';
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

    echo '<article class="lead-card">';
    echo '<a class="lead-card-title" href="' . h(app_url('studio_lead', ['id' => $leadId])) . '">' . h($lead['name'] ?: 'Lead sem nome') . '</a>';
    echo '<p class="muted">' . h($lead['phone'] ?: ($lead['interest'] ?: 'Sem telefone')) . '</p>';
    echo '<p>' . h($lead['interest'] ?: 'Sem interesse descrito.') . '</p>';
    echo '<div class="lead-card-meta"><span>' . h(format_money($lead['estimated_value'] ?? 0)) . '</span><strong>' . h((string)($lead['lead_score'] ?? 0)) . '/10</strong></div>';
    echo '<div class="lead-card-actions">';
    foreach ([['label' => 'Voltar', 'stage' => $prevStage], ['label' => 'Avancar', 'stage' => $nextStage]] as $move) {
        if ($move['stage'] === '') {
            continue;
        }
        echo '<form method="post" class="inline-form">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="move_lead"><input type="hidden" name="lead_id" value="' . h((string)$leadId) . '"><input type="hidden" name="pipeline_stage" value="' . h($move['stage']) . '"><input type="hidden" name="status" value="' . h($lead['status']) . '">';
        echo '<button class="btn tiny secondary" type="submit">' . h($move['label']) . '</button>';
        echo '</form>';
    }
    echo '</div></article>';
}

function render_leads_table(array $leads): void
{
    if (!$leads) {
        echo '<p class="muted">Nenhum lead cadastrado ainda.</p>';
        return;
    }
    echo '<table class="table"><thead><tr><th>Lead</th><th>Funil</th><th>Valor</th><th>Nota</th></tr></thead><tbody>';
    foreach ($leads as $lead) {
        echo '<tr>';
        echo '<td><a href="' . h(app_url('studio_lead', ['id' => (int)$lead['id']])) . '"><strong>' . h($lead['name'] ?: 'Sem nome') . '</strong></a><br><span class="muted">' . h($lead['phone'] ?: $lead['interest']) . '</span></td>';
        echo '<td><span class="badge">' . h($lead['status']) . '</span><br><span class="muted">' . h($lead['pipeline_stage'] ?: '-') . '</span></td>';
        echo '<td>' . h(format_money($lead['estimated_value'] ?? 0)) . '<br><span class="muted">' . h($lead['source'] ?: '-') . '</span></td>';
        echo '<td><strong>' . h((string)($lead['lead_score'] ?? '-')) . '/10</strong></td>';
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
        echo '<span>' . h($conversation['last_message_preview'] ?: '-') . '</span>';
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
        $date = date('d/m/Y', strtotime((string)$appointment['appointment_date']));
        echo '<tr>';
        echo '<td><strong>' . h($date) . '</strong><br><span class="muted">' . h(substr((string)$appointment['start_time'], 0, 5)) . ($appointment['end_time'] ? ' - ' . h(substr((string)$appointment['end_time'], 0, 5)) : '') . '</span></td>';
        echo '<td><strong>' . h($appointment['customer_name'] ?: $appointment['lead_name'] ?: $appointment['title']) . '</strong><br><span class="muted">' . h($appointment['description'] ?: $appointment['title']) . '</span></td>';
        echo '<td>' . h($appointment['artist_name'] ?: '-') . '</td>';
        echo '<td>' . h(format_money($appointment['value'] ?? 0)) . '<br><span class="muted">Sinal ' . h(format_money($appointment['deposit_value'] ?? 0)) . '</span></td>';
        echo '<td><span class="badge">' . h($appointment['status']) . '</span></td>';
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
        $date = date('d/m/Y', strtotime((string)$expense['expense_date']));
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

function render_whatsapp_table(array $conversations): void
{
    if (!$conversations) {
        echo '<p class="muted">Nenhuma conversa importada ainda. Inicie a sessao do WhatsApp e envie uma mensagem para este numero aparecer aqui.</p>';
        return;
    }
    echo '<table class="table"><thead><tr><th>Contato</th><th>Modo</th><th>Lead</th><th>Ultima mensagem</th><th>Acoes</th></tr></thead><tbody>';
    foreach ($conversations as $conversation) {
        $name = $conversation['customer_name'] ?: ($conversation['lead_name'] ?: ($conversation['name'] ?: 'Sem nome'));
        $needsHuman = !empty($conversation['needs_human']);
        echo '<tr>';
        echo '<td><a href="' . h(app_url('studio_whatsapp_conversation', ['id' => (int)$conversation['id']])) . '"><strong>' . h($name) . '</strong></a><br><span class="muted">' . h($conversation['phone']) . '</span></td>';
        echo '<td><span class="badge ' . ($conversation['attendance_mode'] === 'bot' ? 'ok' : '') . '">' . h($conversation['attendance_mode']) . '</span><br>' . ($needsHuman ? '<span class="badge warn">quer humano</span>' : '<span class="muted">sem alerta</span>') . '</td>';
        echo '<td><strong>' . h((string)($conversation['lead_score'] ?? '-')) . '/10</strong><br><span class="muted">' . h($conversation['ai_last_status'] ?: '-') . '</span></td>';
        echo '<td>' . h($conversation['last_message_preview'] ?: '-') . '<br><span class="muted">' . h(($conversation['message_count'] ?? 0) . ' mensagens - ' . ($conversation['message_last_at'] ?: '-')) . '</span></td>';
        echo '<td><div class="actions"><a class="btn tiny" href="' . h(app_url('studio_whatsapp_conversation', ['id' => (int)$conversation['id']])) . '">Abrir</a>';
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
        echo '<span>' . h(($message['sender_type'] ?? '-') . ' | ' . ($message['sent_at'] ?? '-') . (($message['status'] ?? '') ? ' | ' . $message['status'] : '')) . '</span>';
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
