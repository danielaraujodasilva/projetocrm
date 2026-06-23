<?php

declare(strict_types=1);

function crm_whatsapp_official_defaults(): array
{
    return [
        'whatsapp_provider' => 'official',
        'whatsapp_official_mode' => 'production',
        'whatsapp_official_api_version' => 'v23.0',
        'whatsapp_official_app_id' => '2034781270582768',
        'whatsapp_official_business_account_id' => '1908414750031996',
        'whatsapp_official_phone_number_id' => '1186818641175044',
        'whatsapp_official_verify_token' => 'zap_crm_daniel_2026',
        'whatsapp_official_callback_url' => 'https://danieltatuador.com/projetocrm/api/whatsapp_webhook.php',
        'whatsapp_official_display_number' => '+55 11 95786-7798',
        'whatsapp_official_test_business_account_id' => '120771777788528',
        'whatsapp_official_test_phone_number_id' => '126382657222367',
    ];
}

function crm_whatsapp_official_column_sql(string $column): ?string
{
    return match ($column) {
        'whatsapp_provider' => '`whatsapp_provider` VARCHAR(30) NOT NULL DEFAULT "official"',
        'whatsapp_official_mode' => '`whatsapp_official_mode` VARCHAR(30) NOT NULL DEFAULT "production"',
        'whatsapp_official_api_version' => '`whatsapp_official_api_version` VARCHAR(30) NULL',
        'whatsapp_official_app_id' => '`whatsapp_official_app_id` VARCHAR(120) NULL',
        'whatsapp_official_business_account_id' => '`whatsapp_official_business_account_id` VARCHAR(120) NULL',
        'whatsapp_official_phone_number_id' => '`whatsapp_official_phone_number_id` VARCHAR(120) NULL',
        'whatsapp_official_verify_token' => '`whatsapp_official_verify_token` VARCHAR(160) NULL',
        'whatsapp_official_callback_url' => '`whatsapp_official_callback_url` VARCHAR(500) NULL',
        'whatsapp_official_display_number' => '`whatsapp_official_display_number` VARCHAR(60) NULL',
        'whatsapp_official_access_token' => '`whatsapp_official_access_token` TEXT NULL',
        'whatsapp_official_test_business_account_id' => '`whatsapp_official_test_business_account_id` VARCHAR(120) NULL',
        'whatsapp_official_test_phone_number_id' => '`whatsapp_official_test_phone_number_id` VARCHAR(120) NULL',
        default => null,
    };
}

function crm_whatsapp_official_ensure_schema(array $studio): void
{
    try {
        $pdo = studio_db($studio);
        $pdo->exec('CREATE TABLE IF NOT EXISTS `studio_settings` (`id` TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1, `created_at` DATETIME NULL, `updated_at` DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('INSERT IGNORE INTO `studio_settings` (`id`, `created_at`, `updated_at`) VALUES (1, NOW(), NOW())');

        foreach (array_keys(crm_whatsapp_official_defaults()) as $column) {
            $definition = crm_whatsapp_official_column_sql($column);
            if ($definition === null) {
                continue;
            }
            try {
                $pdo->exec('ALTER TABLE `studio_settings` ADD COLUMN IF NOT EXISTS ' . $definition);
            } catch (Throwable) {
                try {
                    $pdo->exec('ALTER TABLE `studio_settings` ADD COLUMN ' . $definition);
                } catch (Throwable) {
                }
            }
        }
        try {
            $pdo->exec('ALTER TABLE `studio_settings` ADD COLUMN IF NOT EXISTS ' . crm_whatsapp_official_column_sql('whatsapp_official_access_token'));
        } catch (Throwable) {
            try {
                $pdo->exec('ALTER TABLE `studio_settings` ADD COLUMN ' . crm_whatsapp_official_column_sql('whatsapp_official_access_token'));
            } catch (Throwable) {
            }
        }
    } catch (Throwable) {
    }
}

function crm_whatsapp_official_prepare_post_settings(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if ((string)($_POST['action'] ?? '') !== 'save_studio_settings') {
        return;
    }

    foreach (crm_whatsapp_official_defaults() as $key => $value) {
        $_POST[$key] = $value;
    }
    $_POST['settings_tab'] = 'whatsapp';
}

function crm_whatsapp_official_current_studio(): ?array
{
    if (!function_exists('current_studio')) {
        return null;
    }
    try {
        $studio = current_studio();
        return is_array($studio) ? $studio : null;
    } catch (Throwable) {
        return null;
    }
}

function crm_whatsapp_official_should_autoconfigure(): bool
{
    $page = (string)($_GET['page'] ?? '');
    $tab = (string)($_GET['tab'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    return $page === 'studio_settings'
        || $page === 'studio_whatsapp'
        || $page === 'studio_whatsapp_workspace'
        || $tab === 'whatsapp'
        || in_array($action, ['save_studio_settings', 'send_whatsapp_message', 'test_whatsapp_official', 'send_whatsapp_official_test_message'], true);
}

function crm_whatsapp_official_send_from_crm(array $studio, array $data): array
{
    crm_whatsapp_official_apply_defaults($studio);

    $phone = normalize_phone((string)($data['phone'] ?? $data['numero'] ?? ''));
    $conversationId = (int)($data['conversation_id'] ?? 0);
    $conversation = null;
    if ($phone === '' && $conversationId > 0) {
        $conversation = studio_find_whatsapp_conversation($studio, $conversationId);
        $phone = normalize_phone((string)($conversation['phone'] ?? ''));
    }
    if (!$conversation && $conversationId > 0) {
        $conversation = studio_find_whatsapp_conversation($studio, $conversationId);
    }

    $message = trim((string)($data['message'] ?? $data['mensagem'] ?? ''));
    if ($phone === '' || $message === '') {
        return ['ok' => false, 'error' => 'Informe telefone e mensagem. A API oficial ainda não envia anexos por esta tela.'];
    }

    $result = studio_whatsapp_official_send_text($studio, $phone, $message);
    if (empty($result['ok'])) {
        return $result;
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
        'tipoMensagem' => 'texto',
    ]);

    return $result + ['messageId' => $messageId];
}

function crm_whatsapp_official_intercept_send(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if ((string)($_POST['action'] ?? '') !== 'send_whatsapp_message') {
        return;
    }

    csrf_verify();
    $studio = require_studio();
    crm_whatsapp_official_apply_defaults($studio);
    $settings = studio_settings($studio);
    if ((string)($settings['whatsapp_provider'] ?? 'official') !== 'official') {
        return;
    }

    $result = crm_whatsapp_official_send_from_crm($studio, $_POST);
    if (empty($result['ok'])) {
        throw new RuntimeException((string)($result['error'] ?? 'Não foi possível enviar pela API oficial.'));
    }

    flash_set('success', 'Mensagem enviada pela API oficial do WhatsApp.');
    if (!empty($_POST['return_to_workspace'])) {
        redirect_to('studio_whatsapp_workspace', ['id' => (int)($_POST['conversation_id'] ?? 0)]);
    }
    if (!empty($_POST['conversation_id'])) {
        redirect_to('studio_whatsapp_conversation', ['id' => (int)$_POST['conversation_id']]);
    }
    redirect_to('studio_whatsapp');
}

function crm_whatsapp_official_output_filter(string $html): string
{
    $needle = '</body>';
    if (!str_contains($html, $needle)) {
        return $html;
    }

    $script = <<<'HTML'
<style>
.crm-official-runtime-note{border:1px solid #bbf7d0;background:#ecfdf5;color:#14532d;border-radius:14px;padding:12px 14px;margin:12px 0;font-size:14px}.crm-hide-baileys{display:none!important}
</style>
<script>
(function(){
  var page = new URLSearchParams(location.search).get('page') || '';
  var tab = new URLSearchParams(location.search).get('tab') || '';
  if (page !== 'studio_settings' && tab !== 'whatsapp') return;
  var provider = document.querySelector('[name="whatsapp_provider"]');
  if (provider) {
    provider.value = 'official';
    Array.from(provider.options || []).forEach(function(opt){ if ((opt.value || '').toLowerCase() === 'baileys') opt.hidden = true; });
    var wrap = provider.closest('.field,.form-group,.mb-3,.col,.row') || provider.parentElement;
    if (wrap && !document.querySelector('.crm-official-runtime-note')) {
      var note = document.createElement('div');
      note.className = 'crm-official-runtime-note';
      note.innerHTML = '<strong>WhatsApp oficial ativado.</strong><br>Baileys ficou preservado no código, mas escondido nesta tela. Preencha apenas o Access Token da Meta e salve.';
      wrap.parentNode.insertBefore(note, wrap);
    }
  }
  var values = {
    whatsapp_provider:'official',
    whatsapp_official_mode:'production',
    whatsapp_official_api_version:'v23.0',
    whatsapp_official_app_id:'2034781270582768',
    whatsapp_official_business_account_id:'1908414750031996',
    whatsapp_official_phone_number_id:'1186818641175044',
    whatsapp_official_verify_token:'zap_crm_daniel_2026',
    whatsapp_official_callback_url:'https://danieltatuador.com/projetocrm/api/whatsapp_webhook.php',
    whatsapp_official_display_number:'+55 11 95786-7798'
  };
  Object.keys(values).forEach(function(name){
    var el = document.querySelector('[name="'+name+'"]');
    if (!el) return;
    el.value = values[name];
    if (name !== 'whatsapp_official_access_token') el.readOnly = true;
  });
  document.querySelectorAll('button,input,a,summary,label,legend,h2,h3,h4,p,small,span,div').forEach(function(el){
    var text = (el.textContent || '').toLowerCase();
    if (text.includes('baileys') || text.includes('qr code') || text.includes('pareamento')) {
      var card = el.closest('.card,.panel,.accordion-item,.settings-card,section,form');
      if (card && !card.querySelector('[name="whatsapp_official_access_token"]')) card.classList.add('crm-hide-baileys');
    }
  });
})();
</script>
HTML;

    return str_replace($needle, $script . $needle, $html);
}

crm_whatsapp_official_prepare_post_settings();

$__crm_whatsapp_studio = crm_whatsapp_official_current_studio();
if ($__crm_whatsapp_studio && crm_whatsapp_official_should_autoconfigure()) {
    crm_whatsapp_official_apply_defaults($__crm_whatsapp_studio);
}
unset($__crm_whatsapp_studio);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['action'] ?? '') === 'send_whatsapp_message') {
    $studio = crm_whatsapp_official_current_studio();
    if ($studio && (string)(studio_settings($studio)['whatsapp_provider'] ?? 'official') === 'official') {
        csrf_verify();
        if (function_exists('crm_whatsapp_official_apply_defaults')) {
            crm_whatsapp_official_apply_defaults($studio);
        }

        $conversationId = (int)($_POST['conversation_id'] ?? $_GET['id'] ?? 0);
        $conversation = $conversationId > 0 ? studio_find_whatsapp_conversation($studio, $conversationId) : null;
        $user = studio_current_user();
        if (!$user) {
            flash_set('error', 'Voce precisa estar autenticado para enviar mensagem.');
            redirect_to('studio_whatsapp');
        }
        if (!$conversation || !studio_can_send_whatsapp_conversation($studio, $conversation, $user)) {
            flash_set('error', 'Esta conversa esta atribuida a outro atendente.');
            redirect_to('studio_whatsapp_workspace', ['id' => $conversationId]);
        }

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
        if ($phone === '' || $message === '') {
            flash_set('error', 'Faltou telefone ou mensagem para enviar pela API oficial.');
            redirect_to($conversationId > 0 ? 'studio_whatsapp_workspace' : 'studio_whatsapp', $conversationId > 0 ? ['id' => $conversationId] : []);
        }

        $result = studio_whatsapp_official_send_text($studio, $phone, $message);
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
            }
            flash_set('error', $error);
            redirect_to($conversationId > 0 ? 'studio_whatsapp_workspace' : 'studio_whatsapp', $conversationId > 0 ? ['id' => $conversationId] : []);
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
            'tipoMensagem' => 'texto',
        ]);
        flash_set('success', 'Mensagem enviada pela API oficial do WhatsApp.' . ($messageId !== '' ? ' ID: ' . $messageId : ''));
        redirect_to($conversationId > 0 ? 'studio_whatsapp_workspace' : 'studio_whatsapp', $conversationId > 0 ? ['id' => $conversationId] : []);
    }
    crm_whatsapp_official_intercept_send();
}

ob_start('crm_whatsapp_official_output_filter');
