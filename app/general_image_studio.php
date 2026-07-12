<?php

declare(strict_types=1);

function studio_tattoo_image_choice(string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function studio_tattoo_image_allowed_styles(): array
{
    return ['auto','realistic','cartoon','illustration','cinematic','anime','stencil','blackwork','chicano','fineline','oldschool','reference'];
}

function studio_tattoo_image_absolute_from_relative(string $relative): string
{
    $relative = trim(str_replace('\\', '/', $relative), '/ ');
    if ($relative === '' || str_contains($relative, '..')) return '';
    $path = APP_BASE_PATH . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    return is_file($path) ? $path : '';
}

function studio_tattoo_image_realesrgan_root(): string
{
    $envRoot = trim((string)(getenv('REALESRGAN_NCNN_ROOT') ?: ''));
    return $envRoot !== '' ? $envRoot : 'C:\\AI\\realesrgan-ncnn-vulkan';
}

function studio_tattoo_image_realesrgan_upscale(string $sourcePath, int $factor = 4): string
{
    $factor = max(2, min(4, $factor));
    $root = studio_tattoo_image_realesrgan_root();
    $exe = $root . DIRECTORY_SEPARATOR . 'realesrgan-ncnn-vulkan.exe';
    if (!is_file($exe) || !is_file($sourcePath)) {
        return '';
    }

    $target = preg_replace('/\.(?:jpe?g|png|webp)$/i', '_' . $factor . 'x_ai.jpg', $sourcePath) ?: ($sourcePath . '_' . $factor . 'x_ai.jpg');
    $command = implode(' ', [
        escapeshellarg($exe),
        '-i', escapeshellarg($sourcePath),
        '-o', escapeshellarg($target),
        '-s', (string)$factor,
        '-n', 'realesrgan-x4plus',
        '-g', '0',
        '-t', '256',
        '-j', '1:2:2',
        '-f', 'jpg',
    ]);

    $previousCwd = getcwd();
    if (is_dir($root)) {
        chdir($root);
    }
    $output = [];
    $exitCode = 1;
    @exec($command . ' 2>&1', $output, $exitCode);
    if (is_string($previousCwd) && $previousCwd !== '') {
        chdir($previousCwd);
    }

    if ($exitCode !== 0 || !is_file($target) || filesize($target) < 1024) {
        @unlink($target);
        return '';
    }
    return basename($target);
}

function studio_tattoo_image_upscale_jpeg(string $sourcePath, int $factor = 2): string
{
    $factor = max(2, min(4, $factor));
    $aiFile = studio_tattoo_image_realesrgan_upscale($sourcePath, $factor);
    if ($aiFile !== '') {
        return $aiFile;
    }

    if (!function_exists('imagecreatefromstring') || !is_file($sourcePath)) return '';
    $binary = @file_get_contents($sourcePath);
    $src = is_string($binary) ? @imagecreatefromstring($binary) : false;
    if (!$src) return '';
    $width = imagesx($src); $height = imagesy($src);
    $dst = imagecreatetruecolor($width * $factor, $height * $factor);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $width * $factor, $height * $factor, $width, $height);
    if (function_exists('imageconvolution')) {
        imageconvolution($dst, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);
    }
    $target = preg_replace('/\.(?:jpe?g|png|webp)$/i', '_' . $factor . 'x.jpg', $sourcePath) ?: ($sourcePath . '_' . $factor . 'x.jpg');
    imagejpeg($dst, $target, 95);
    imagedestroy($src); imagedestroy($dst);
    return is_file($target) ? basename($target) : '';
}

function studio_tattoo_image_history(): array
{
    $items = $_SESSION['studio_tattoo_image_history'] ?? [];
    return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
}

function studio_tattoo_image_history_add(array $result): void
{
    $items = studio_tattoo_image_history();
    $result['history_id'] = $result['history_id'] ?? bin2hex(random_bytes(8));
    array_unshift($items, $result);
    $_SESSION['studio_tattoo_image_history'] = array_slice($items, 0, 10);
}

function studio_tattoo_image_history_find(string $id): ?array
{
    foreach (studio_tattoo_image_history() as $item) if ((string)($item['history_id'] ?? '') === $id) return $item;
    return null;
}

function studio_tattoo_image_history_update(array $result): void
{
    $id = (string)($result['history_id'] ?? '');
    if ($id === '') {
        return;
    }

    $items = studio_tattoo_image_history();
    foreach ($items as $index => $item) {
        if ((string)($item['history_id'] ?? '') === $id) {
            $items[$index] = array_merge($item, $result);
            $_SESSION['studio_tattoo_image_history'] = array_slice($items, 0, 10);
            return;
        }
    }
}

function studio_tattoo_image_make_super_resolution(array $result, int $factor = 4): array
{
    $imagePath = studio_tattoo_image_absolute_from_relative((string)($result['image_path'] ?? ''));
    if ($imagePath === '') {
        throw new RuntimeException('Imagem original indisponivel para superresolucao.');
    }

    $file = studio_tattoo_image_upscale_jpeg($imagePath, $factor);
    if ($file === '') {
        throw new RuntimeException('Nao foi possivel gerar a superresolucao desta imagem.');
    }

    $folder = trim(str_replace('\\', '/', dirname((string)($result['image_path'] ?? ''))), '. /');
    $result['upscaled_image_path'] = $folder !== '' ? $folder . '/' . $file : $file;
    $result['upscaled_file_name'] = $file;
    $result['upscaled_at'] = date('Y-m-d H:i:s');
    return $result;
}

function studio_tattoo_image_clear_job(): void
{
    unset($_SESSION['studio_tattoo_image_job'], $_SESSION['studio_tattoo_image_prompt']);
}

function studio_tattoo_image_current_job(): ?array
{
    $job = $_SESSION['studio_tattoo_image_job'] ?? null;
    if (!is_array($job)) return null;
    $started = strtotime((string)($job['started_at'] ?? '')) ?: 0;
    if ($started > 0 && time() - $started > 1800) {
        studio_tattoo_image_clear_job();
        return null;
    }
    return $job;
}

function studio_tattoo_image_start(array $studio, array $data): array
{
    return studio_general_image_start($studio, $data);
}

function studio_tattoo_image_poll(array $studio, array $job): array
{
    return studio_general_image_poll($studio, $job);
}

function studio_tattoo_image_handle_request(): void
{
    $page = (string)($_GET['page'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? '');
    if ($page === 'studio_tattoo_images' && (isset($_GET['reset']) || $action === 'cancel_tattoo_reference')) {
        if ($requestMethod === 'POST') csrf_verify();
        if ($action === 'cancel_tattoo_reference') {
            $health = studio_local_image_ai_request('GET', '/v1/models', null, 3);
            if (empty($health['ok'])) {
                studio_general_image_restart_local_service();
            }
        }
        studio_tattoo_image_clear_job();
        flash_set('success', 'Geração travada cancelada.');
        redirect_to('studio_tattoo_images');
    }
    if ($requestMethod === 'POST' && in_array($action, ['select_tattoo_history', 'edit_tattoo_history', 'upscale_tattoo_image'], true)) {
        csrf_verify();
        $historyId = trim((string)($_POST['history_id'] ?? ''));
        $item = $historyId !== '' ? studio_tattoo_image_history_find($historyId) : null;
        if (!is_array($item) && is_array($_SESSION['studio_tattoo_image_result'] ?? null)) {
            $item = $_SESSION['studio_tattoo_image_result'];
        }

        try {
            if (!is_array($item)) {
                throw new RuntimeException('Imagem do historico nao encontrada.');
            }

            if ($action === 'upscale_tattoo_image') {
                $item = studio_tattoo_image_make_super_resolution($item, 4);
                studio_tattoo_image_history_update($item);
                $_SESSION['studio_tattoo_image_result'] = $item;
                flash_set('success', 'Superresolucao IA 4x criada.');
            } elseif ($action === 'edit_tattoo_history') {
                $_SESSION['studio_tattoo_image_result'] = $item;
                $_SESSION['studio_tattoo_image_form'] = [
                    'prompt' => (string)($item['prompt'] ?? ''),
                    'mode' => 'fast',
                    'style' => (string)($item['style'] ?? 'auto'),
                    'format' => (string)($item['format'] ?? 'vertical'),
                    'reference_notes' => (string)($item['reference_notes'] ?? ''),
                    'negative_prompt' => (string)($item['negative_prompt'] ?? ''),
                    'source_image_path' => (string)($item['image_path'] ?? ''),
                ];
                flash_set('success', 'Imagem carregada como base para alteracao.');
            } else {
                $_SESSION['studio_tattoo_image_result'] = $item;
            }
        } catch (Throwable $error) {
            flash_set('error', $error->getMessage());
        }
        redirect_to('studio_tattoo_images');
    }
    if ($requestMethod === 'POST' && $action === 'generate_tattoo_reference') {
        $studio = require_studio(); csrf_verify();
        try {
            $generation = studio_tattoo_image_start($studio, $_POST);
            if (($generation['status'] ?? '') === 'completed' && is_array($generation['result'] ?? null)) {
                $_SESSION['studio_tattoo_image_result'] = $generation['result'];
                studio_tattoo_image_history_add($generation['result']);
                studio_tattoo_image_clear_job();
            } else {
                $_SESSION['studio_tattoo_image_job'] = $generation;
                unset($_SESSION['studio_tattoo_image_result']);
            }
            unset($_SESSION['studio_tattoo_image_prompt']);
        } catch (Throwable $error) {
            $_SESSION['studio_tattoo_image_prompt'] = trim((string)($_POST['prompt'] ?? ''));
            flash_set('error', $error->getMessage());
        }
        redirect_to('studio_tattoo_images');
    }
    if ($page === 'studio_tattoo_image_status') {
        $studio = require_studio(); header('Content-Type: application/json; charset=utf-8');
        $job = studio_tattoo_image_current_job();
        if (!$job) { echo json_encode(['status'=>'idle'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
        $poll = studio_tattoo_image_poll($studio, $job);
        if (($poll['status'] ?? '') === 'completed' && is_array($poll['result'] ?? null)) {
            $_SESSION['studio_tattoo_image_result'] = $poll['result'];
            studio_tattoo_image_history_add($poll['result']);
            studio_tattoo_image_clear_job();
        }
        elseif (($poll['status'] ?? '') === 'failed') { studio_tattoo_image_clear_job(); flash_set('error', (string)($poll['error'] ?? 'A IA local não conseguiu concluir a imagem.')); }
        echo json_encode($poll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
    }
    if ($page !== 'studio_tattoo_images') return;
    $studio = require_studio();
    render_studio_shell('Criar imagem', 'Geração livre: diga o que quer ver e a IA tenta seguir literalmente.', 'tattoo_images', function () use ($studio) {
        $localAi = studio_general_image_provider($studio);
        $job = studio_tattoo_image_current_job();
        $isGenerating = is_array($job);
        $result = $_SESSION['studio_tattoo_image_result'] ?? null;
        $formState = is_array($_SESSION['studio_tattoo_image_form'] ?? null) ? $_SESSION['studio_tattoo_image_form'] : [];
        $prompt = trim((string)($formState['prompt'] ?? $_SESSION['studio_tattoo_image_prompt'] ?? $job['prompt'] ?? $result['prompt'] ?? ''));
        $sourceImagePath = trim((string)($formState['source_image_path'] ?? ''));
        $selectedMode = studio_tattoo_image_choice((string)($formState['mode'] ?? $job['mode'] ?? 'final'), ['fast','final'], 'final');
        $selectedStyle = studio_tattoo_image_choice((string)($formState['style'] ?? $job['style'] ?? 'realistic'), studio_tattoo_image_allowed_styles(), 'realistic');
        $selectedFormat = studio_tattoo_image_choice((string)($formState['format'] ?? $job['format'] ?? 'vertical'), ['vertical','square','wide'], 'vertical');
        $referenceNotes = trim((string)($formState['reference_notes'] ?? $job['reference_notes'] ?? ''));
        $negativePrompt = trim((string)($formState['negative_prompt'] ?? $job['negative_prompt'] ?? ''));
        $history = studio_tattoo_image_history();
        unset($_SESSION['studio_tattoo_image_prompt']);
        unset($_SESSION['studio_tattoo_image_form']);
        echo '<style>.ti-gen{display:grid;grid-template-columns:minmax(320px,430px) minmax(0,1fr);gap:18px}.ti-box{background:#fff;border:1px solid rgba(15,23,42,.10);border-radius:8px;padding:16px;box-shadow:0 12px 34px rgba(15,23,42,.06)}.ti-prompt textarea{min-height:188px;resize:vertical;width:100%;font-size:16px;line-height:1.45}.ti-main-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}.ti-options{border:1px solid rgba(15,23,42,.12);border-radius:8px;margin-top:12px;padding:0}.ti-options summary{cursor:pointer;font-weight:800;padding:11px 12px}.ti-options-body{border-top:1px solid rgba(15,23,42,.08);display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr));padding:12px}.ti-options .field{margin:0}.ti-options select,.ti-options input[type=text]{width:100%}.ti-result{min-height:520px;display:flex;flex-direction:column}.ti-result-frame{align-items:center;background:#f6f7f4;border:1px dashed rgba(15,23,42,.14);border-radius:8px;display:flex;flex:1;justify-content:center;min-height:420px;overflow:hidden}.ti-result-frame img{display:block;max-height:72vh;max-width:100%;object-fit:contain;width:auto}.ti-placeholder{color:#667085;text-align:center}.ti-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px}.ti-history{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));margin-top:12px}.ti-history-item{border:1px solid rgba(15,23,42,.10);border-radius:8px;background:#fff;padding:8px}.ti-history-item img{aspect-ratio:3/4;border-radius:6px;display:block;object-fit:cover;width:100%;background:#f2f4f7}.ti-history-actions{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:7px}.ti-history-actions .btn,.ti-mini-btn{font-size:12px;padding:7px 8px}.ti-chip{align-items:center;background:#ecfdf3;border:1px solid #abefc6;border-radius:999px;color:#067647;display:inline-flex;font-size:12px;font-weight:800;gap:6px;padding:6px 10px}.danger{background:#b91c1c!important;color:#fff!important}@media(max-width:980px){.ti-gen{grid-template-columns:1fr}.ti-result{min-height:unset}.ti-options-body{grid-template-columns:1fr}}</style>';
        echo '<div class="ti-gen">';
        echo '<section class="ti-box ti-prompt"><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="generate_tattoo_reference"><input type="hidden" name="source_image_path" value="' . h($sourceImagePath) . '">';
        echo '<div class="field"><label>Prompt</label><textarea name="prompt" maxlength="4000" required ' . ($isGenerating ? 'disabled' : '') . ' placeholder="Descreva a imagem que quer gerar...">' . h($prompt) . '</textarea></div>';
        if ($sourceImagePath !== '') {
            echo '<span class="ti-chip">Alterando imagem do historico</span>';
        }
        if ($isGenerating) {
            echo '<p class="muted" id="aiWaitText">' . h((string)($job['model'] ?? 'A IA')) . ' esta criando sua imagem...</p>';
        } elseif (empty($localAi['ok'])) {
            echo '<p class="muted">Gerador indisponivel agora: ' . h((string)($localAi['error'] ?? 'servico nao respondeu')) . '</p>';
        } else {
            echo '<span class="ti-chip">Motor: ' . h((string)($localAi['label'] ?? 'IA de imagem')) . '</span>';
        }
        echo '<div class="ti-main-actions"><button class="btn" type="submit" ' . (empty($localAi['ok']) || $isGenerating ? 'disabled' : '') . '>' . ($isGenerating ? 'Gerando...' : 'Gerar imagem') . '</button>';
        if ($isGenerating) {
            echo '</form><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="cancel_tattoo_reference"><button class="btn danger" type="submit">Cancelar</button></form><form method="get"><input type="hidden" name="page" value="studio_tattoo_images"><button class="btn secondary" type="submit">Atualizar</button></form>';
        } else {
            echo '<a class="btn secondary" href="' . h(app_url('studio_tattoo_images', ['reset' => 1])) . '">Limpar</a>';
        }
        echo '</div>';
        if (!$isGenerating) {
            echo '<details class="ti-options"><summary>Opcoes</summary><div class="ti-options-body">';
            echo '<div class="field"><label>Modo</label><select name="mode"><option value="final"' . ($selectedMode === 'final' ? ' selected' : '') . '>Alta definicao</option><option value="fast"' . ($selectedMode === 'fast' ? ' selected' : '') . '>Previa rapida</option></select></div>';
            echo '<div class="field"><label>Estilo</label><select name="style">';
            foreach (['realistic'=>'Realista / hiperealista','auto'=>'Livre pelo prompt','cartoon'=>'Cartoon','illustration'=>'Ilustracao','cinematic'=>'Cinematico','anime'=>'Anime','reference'=>'Referencia limpa','stencil'=>'Stencil','blackwork'=>'Blackwork','chicano'=>'Chicano','fineline'=>'Fine line','oldschool'=>'Old school'] as $value => $label) {
                echo '<option value="' . h($value) . '"' . ($selectedStyle === $value ? ' selected' : '') . '>' . h($label) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="field"><label>Formato</label><select name="format"><option value="vertical"' . ($selectedFormat === 'vertical' ? ' selected' : '') . '>Vertical</option><option value="square"' . ($selectedFormat === 'square' ? ' selected' : '') . '>Quadrado</option><option value="wide"' . ($selectedFormat === 'wide' ? ' selected' : '') . '>Horizontal</option></select></div>';
            echo '<div class="field"><label>Direcao artistica</label><input type="text" name="reference_notes" maxlength="600" value="' . h($referenceNotes) . '"></div>';
            echo '<div class="field" style="grid-column:1/-1"><label>Evitar</label><input type="text" name="negative_prompt" maxlength="600" value="' . h($negativePrompt) . '"></div>';
            echo '</div></details></form>';
        }
        echo '</section>';

        echo '<section class="ti-box ti-result"><div class="ti-result-frame">';
        if (is_array($result) && !empty($result['image_path'])) {
            $url = app_asset_url((string)$result['image_path']);
            echo '<img src="' . h($url) . '" alt="Imagem gerada">';
        } else {
            echo '<div class="ti-placeholder">A imagem gerada aparece aqui.</div>';
        }
        echo '</div>';
        if (is_array($result) && !empty($result['image_path'])) {
            $url = app_asset_url((string)$result['image_path']);
            echo '<div class="ti-meta"><div><strong>' . h((string)($result['prompt'] ?? 'Imagem selecionada')) . '</strong><div class="muted">' . h((string)($result['generated_at'] ?? '')) . '</div></div><div class="actions">';
            echo '<a class="btn secondary ti-mini-btn" href="' . h($url) . '" download>Baixar</a>';
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="upscale_tattoo_image"><input type="hidden" name="history_id" value="' . h((string)($result['history_id'] ?? '')) . '"><button class="btn ti-mini-btn" type="submit">IA 4x</button></form>';
            if (!empty($result['upscaled_image_path'])) {
                echo '<a class="btn secondary ti-mini-btn" href="' . h(app_asset_url((string)$result['upscaled_image_path'])) . '" download>Baixar 4x</a>';
            }
            echo '</div></div>';
        }
        echo '</section></div>';

        echo '<section class="ti-box" style="margin-top:18px"><div class="actions" style="justify-content:space-between"><h2 style="margin:0">Historico</h2><span class="muted">' . h((string)count($history)) . '/10</span></div>';
        if (!$history) {
            echo '<p class="muted">As ultimas 10 imagens geradas aparecem aqui.</p>';
        } else {
            echo '<div class="ti-history">';
            foreach ($history as $item) {
                $thumb = !empty($item['image_path']) ? app_asset_url((string)$item['image_path']) : '';
                echo '<div class="ti-history-item">';
                if ($thumb !== '') {
                    echo '<img src="' . h($thumb) . '" alt="">';
                }
                echo '<small>' . h(mb_strimwidth((string)($item['prompt'] ?? ''), 0, 46, '...', 'UTF-8')) . '</small>';
                echo '<div class="ti-history-actions">';
                echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="select_tattoo_history"><input type="hidden" name="history_id" value="' . h((string)($item['history_id'] ?? '')) . '"><button class="btn secondary" type="submit">Ver</button></form>';
                echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="edit_tattoo_history"><input type="hidden" name="history_id" value="' . h((string)($item['history_id'] ?? '')) . '"><button class="btn secondary" type="submit">Alterar</button></form>';
                echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="upscale_tattoo_image"><input type="hidden" name="history_id" value="' . h((string)($item['history_id'] ?? '')) . '"><button class="btn secondary" type="submit">IA 4x</button></form>';
                if (!empty($item['upscaled_image_path'])) {
                    echo '<a class="btn secondary" href="' . h(app_asset_url((string)$item['upscaled_image_path'])) . '" download>Baixar 4x</a>';
                } else {
                    echo '<a class="btn secondary" href="' . h($thumb) . '" download>Baixar</a>';
                }
                echo '</div></div>';
            }
            echo '</div>';
        }
        echo '</section><script>(function(){const generating=' . ($isGenerating ? 'true' : 'false') . ';if(!generating)return;const wait=document.getElementById("aiWaitText");const statusUrl=' . json_encode(app_url('studio_tattoo_image_status'), JSON_UNESCAPED_SLASHES) . ';let failures=0;setTimeout(function poll(){fetch(statusUrl,{credentials:"same-origin",cache:"no-store"}).then(r=>r.json()).then(d=>{failures=0;if(["completed","failed","idle"].includes(d.status)){location.reload();return;}if(wait){wait.textContent=d.status==="queued"?"Sua imagem esta na fila...":"A IA esta criando sua imagem...";}setTimeout(poll,3000);}).catch(()=>{failures++;if(wait&&failures>2)wait.textContent="A geracao continua. Tentando reconectar...";setTimeout(poll,5000);});},1200);})();</script>';
    }, flash_get());
    exit;
}

studio_tattoo_image_handle_request();
