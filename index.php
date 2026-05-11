<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

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

$flash = flash_get();

function render_head(string $title): void
{
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="stylesheet" href="assets/app.css"></head><body>';
}

function render_flash(?array $flash): void
{
    if (!$flash) {
        return;
    }
    echo '<div class="flash ' . h($flash['type'] ?? '') . '">' . h($flash['message'] ?? '') . '</div>';
}

function render_auth_page(string $title, string $subtitle, callable $content, ?array $flash): void
{
    render_head($title);
    echo '<div class="auth-page"><main class="auth-card">';
    echo '<h1>' . h($title) . '</h1><p>' . h($subtitle) . '</p>';
    render_flash($flash);
    $content();
    echo '</main></div></body></html>';
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
    echo '</main></div></body></html>';
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
    echo '<a class="' . ($active === 'leads' ? 'active' : '') . '" href="' . h(app_url('studio_home')) . '#leads">Leads</a>';
    echo '<a class="' . ($active === 'agenda' ? 'active' : '') . '" href="' . h(app_url('studio_home')) . '#agenda">Agenda</a>';
    echo '<a class="' . ($active === 'settings' ? 'active' : '') . '" href="' . h(app_url('studio_settings')) . '">Configuracoes</a>';
    echo '<a href="' . h(app_url('studio_logout')) . '">Sair</a>';
    echo '</nav></aside>';
    echo '<main class="main">';
    echo '<div class="topbar"><div><h1>' . h($title) . '</h1><p>' . h($subtitle) . '</p></div>';
    echo '<span class="badge">' . h($user['name'] ?? 'Usuario') . '</span></div>';
    render_flash($flash);
    $content();
    echo '</main></div></body></html>';
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
        echo '<div class="field"><label>Email</label><input name="email" type="email" required autocomplete="email"></div>';
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
        echo '<div class="field"><label>Email</label><input name="email" type="email" required autocomplete="email"></div>';
        echo '<div class="field"><label>Senha</label><input name="password" type="password" required autocomplete="current-password"></div>';
        echo '<button class="btn" type="submit">Entrar no CRM</button>';
        echo '<a class="btn secondary" href="' . h(app_url('login')) . '">Painel gerente</a>';
        echo '</form>';
    }, $flash);
    exit;
}

if (in_array($page, ['studio_home', 'studio_settings'], true) && !current_studio_user()) {
    redirect_to('studio_login');
}

if ($page === 'studio_home') {
    $user = current_studio_user();
    render_studio_shell('Inicio do CRM', 'Primeira tela operacional da instancia ' . (string)$user['studio_name'] . '.', 'home', function () use ($user) {
        echo '<section class="grid cols-3">';
        echo '<div class="panel"><h2>Estudio</h2><p class="metric">' . h($user['studio_name']) . '</p><p class="muted">' . h($user['studio_slug']) . '</p></div>';
        echo '<div class="panel"><h2>Status</h2><span class="badge ' . ($user['studio_status'] === 'active' ? 'ok' : 'warn') . '">' . h($user['studio_status']) . '</span></div>';
        echo '<div class="panel"><h2>Banco</h2><p>' . h($user['database_name']) . '</p></div>';
        echo '</section>';
        echo '<section class="panel" style="margin-top:16px"><h2>Atalhos do CRM</h2><div class="module-list">';
        foreach ([
            ['Leads', 'Funil e contatos entram aqui na proxima etapa.'],
            ['WhatsApp', 'Conexao multi-sessao sera gerenciada por estudio.'],
            ['Agenda', 'Pre-agendamentos e horarios do estudio.'],
            ['IA', 'Regras e modelo configurados por instancia.'],
        ] as $module) {
            echo '<div class="module"><strong>' . h($module[0]) . '</strong><span class="muted">' . h($module[1]) . '</span></div>';
        }
        echo '</div></section>';
    }, $flash);
    exit;
}

if ($page === 'studio_settings') {
    $user = current_studio_user();
    render_studio_shell('Configuracoes do estudio', 'Visao inicial das configuracoes isoladas.', 'settings', function () use ($user) {
        echo '<section class="grid cols-2">';
        echo '<div class="panel"><h2>IA</h2><p>Modelo configurado: <strong>' . h($user['ai_model']) . '</strong></p><p class="muted">As regras por estudio serao editaveis aqui nas proximas etapas.</p></div>';
        echo '<div class="panel"><h2>WhatsApp</h2><p>Status: <span class="badge warn">' . h($user['whatsapp_status']) . '</span></p><p class="muted">A conexao via Baileys entrara como servico multi-sessao.</p></div>';
        echo '</section>';
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
        echo '<div class="field"><label>Email</label><input name="email" type="email" required autocomplete="email"></div>';
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
        echo '<section class="panel" style="margin-top:16px"><div class="actions"><a class="btn" href="' . h(app_url('studio_login')) . '">Acessar login do estudio</a><a class="btn secondary" href="' . h(app_url('studio_sql', ['id' => (int)$studio['id']])) . '">Ver SQL do banco do estudio</a><a class="btn secondary" href="' . h(app_url('edit_studio', ['id' => (int)$studio['id']])) . '">Editar cadastro</a></div></section>';
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
        echo '<div class="field"><label>Email de login</label><input type="email" name="access_email" value="' . h($studio['owner_email'] ?? '') . '" required></div>';
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
    echo '<div class="field"><label>Email do responsavel</label><input type="email" name="owner_email" value="' . h($studio['owner_email'] ?? '') . '"></div>';
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
