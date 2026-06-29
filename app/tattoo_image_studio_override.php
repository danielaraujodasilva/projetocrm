<?php

declare(strict_types=1);

function studio_tattoo_image_choice(string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function studio_tattoo_image_mode_config(string $mode): array
{
    return $mode === 'fast'
        ? ['label' => 'Visualização rápida', 'width' => 640, 'height' => 896, 'steps' => 8, 'txt_cfg' => 4.0, 'distilled_guidance' => 2.8, 'expected_seconds' => 110, 'denoise' => 0.36]
        : ['label' => 'Final qualidade', 'width' => 832, 'height' => 1216, 'steps' => 30, 'txt_cfg' => 5.0, 'distilled_guidance' => 3.5, 'expected_seconds' => 380, 'denoise' => 0.28];
}

function studio_tattoo_image_style_prompt(string $style): string
{
    return match ($style) {
        'stencil' => 'professional tattoo stencil blueprint, pure black ink on white background, clean solid contour lines, dashed shadow transitions, anatomical hatching, readable thermal-transfer design, no gray wash, no solid black masses',
        'blackwork' => 'blackwork tattoo concept, strong silhouettes, balanced negative space, ornamental black shapes, clean readable composition for skin',
        'chicano' => 'chicano tattoo reference, dramatic black and grey mood, smooth gradients translated into tattoo-friendly contrast, elegant composition, premium studio style',
        'fineline' => 'fine line tattoo concept, delicate clean linework, elegant minimal detail, soft realistic structure, readable on skin',
        'oldschool' => 'old school tattoo flash reference, bold linework, iconic simplified forms, strong readable shapes, classic tattoo composition',
        'reference' => 'clean visual reference for tattoo design, clear subject, neutral useful composition, easy to redraw and adapt into tattoo',
        default => 'RAW professional photograph, award-winning photorealism, ultra-detailed natural textures, cinematic lighting, dimensional depth, sharp subject, rich tonal range',
    };
}

function studio_tattoo_image_build_prompt(array $data): string
{
    $request = trim((string)($data['prompt'] ?? ''));
    if (mb_strlen($request, 'UTF-8') < 4) {
        throw new RuntimeException('Descreva um pouco melhor a imagem que você quer criar.');
    }
    if (mb_strlen($request, 'UTF-8') > 4000) {
        throw new RuntimeException('A descrição ficou muito longa. Resuma em até 4.000 caracteres.');
    }

    $style = studio_tattoo_image_choice((string)($data['style'] ?? 'realistic'), ['realistic', 'stencil', 'blackwork', 'chicano', 'fineline', 'oldschool', 'reference'], 'realistic');
    $reference = trim((string)($data['reference_notes'] ?? ''));
    $format = studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical', 'square', 'wide'], 'vertical');
    $sourceHint = trim((string)($data['source_hint'] ?? ''));
    $translated = studio_translate_tattoo_image_prompt($request . ($reference !== '' ? "\nStyle/reference notes: " . $reference : ''));

    $formatHint = match ($format) {
        'square' => 'balanced square composition, central readable subject',
        'wide' => 'horizontal cinematic composition, useful for extended tattoo layout',
        default => 'strong vertical composition, main subject centered and readable',
    };

    return studio_tattoo_image_style_prompt($style) . ', ' . $formatHint . '. '
        . 'Create a standalone tattoo design reference. Do not add interface, caption, watermark, logo, frame or random typography. '
        . 'Respect the requested identity, age, expression, anatomy, pose, mood and composition when provided by the user. '
        . ($sourceHint !== '' ? 'Use the selected preview as composition lock: preserve the same main subject, framing, pose, silhouette, light direction and visual idea while increasing quality. ' : '')
        . 'User concept: ' . $translated;
}

function studio_tattoo_image_dimensions_for_format(array $config, string $format): array
{
    if ($format === 'square') {
        $side = min((int)$config['height'], max(640, (int)$config['width']));
        return [$side, $side];
    }
    if ($format === 'wide') {
        return [(int)$config['height'], (int)$config['width']];
    }
    return [(int)$config['width'], (int)$config['height']];
}

function studio_tattoo_image_absolute_from_relative(string $relative): string
{
    $relative = ltrim(trim($relative), '/\\');
    if ($relative === '' || str_contains($relative, '..')) {
        return '';
    }
    $path = APP_BASE_PATH . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    return is_file($path) ? $path : '';
}

function studio_tattoo_image_body(array $data, string $mode): array
{
    $format = studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical', 'square', 'wide'], 'vertical');
    $config = studio_tattoo_image_mode_config($mode);
    [$width, $height] = studio_tattoo_image_dimensions_for_format($config, $format);
    $negative = 'low quality, worst quality, blurry, flat lighting, oversaturated, malformed anatomy, deformed hands, extra fingers, bad eyes, duplicated subject, watermark, signature, border, frame, user interface';
    $extraNegative = trim((string)($data['negative_prompt'] ?? ''));
    if ($extraNegative !== '') {
        $negative .= ', ' . $extraNegative;
    }

    $body = [
        'prompt' => studio_tattoo_image_build_prompt($data),
        'negative_prompt' => $negative,
        'clip_skip' => -1,
        'width' => $width,
        'height' => $height,
        'seed' => -1,
        'batch_count' => 1,
        'sample_params' => [
            'scheduler' => 'karras',
            'sample_method' => 'dpm++2m',
            'sample_steps' => (int)$config['steps'],
            'guidance' => ['txt_cfg' => (float)$config['txt_cfg'], 'distilled_guidance' => (float)$config['distilled_guidance']],
        ],
        'vae_tiling_params' => ['enabled' => true, 'tile_size_x' => 512, 'tile_size_y' => 512, 'target_overlap' => 0.25],
        'output_format' => 'jpeg',
        'output_compression' => 94,
    ];

    $sourcePath = studio_tattoo_image_absolute_from_relative((string)($data['source_image_path'] ?? ''));
    if ($sourcePath !== '') {
        $sourceBinary = @file_get_contents($sourcePath);
        if (is_string($sourceBinary) && $sourceBinary !== '') {
            $sourceBase64 = base64_encode($sourceBinary);
            $body['init_image'] = $sourceBase64;
            $body['image'] = $sourceBase64;
            $body['source_image'] = $sourceBase64;
            $body['strength'] = (float)$config['denoise'];
            $body['denoising_strength'] = (float)$config['denoise'];
        }
    }

    return $body;
}

function studio_tattoo_image_start(array $studio, array $data): array
{
    $mode = studio_tattoo_image_choice((string)($data['mode'] ?? 'final'), ['fast', 'final'], 'final');
    $localStatus = studio_local_image_ai_status();
    if (empty($localStatus['ok'])) {
        throw new RuntimeException('A IA local de imagens ainda está iniciando. Aguarde um pouco e tente novamente.');
    }

    $body = studio_tattoo_image_body($data, $mode);
    $result = studio_local_image_ai_request('POST', '/sdcpp/v1/img_gen', $body, 120);
    if (empty($result['ok'])) {
        throw new RuntimeException((string)($result['error'] ?? 'Não foi possível iniciar a geração local.'));
    }

    $jobId = trim((string)($result['json']['id'] ?? ''));
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $jobId)) {
        throw new RuntimeException('A IA local não devolveu um identificador válido para a imagem.');
    }

    $config = studio_tattoo_image_mode_config($mode);
    return [
        'id' => $jobId,
        'prompt' => trim((string)($data['prompt'] ?? '')),
        'style' => studio_tattoo_image_choice((string)($data['style'] ?? 'realistic'), ['realistic', 'stencil', 'blackwork', 'chicano', 'fineline', 'oldschool', 'reference'], 'realistic'),
        'mode' => $mode,
        'format' => studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical', 'square', 'wide'], 'vertical'),
        'reference_notes' => trim((string)($data['reference_notes'] ?? '')),
        'negative_prompt' => trim((string)($data['negative_prompt'] ?? '')),
        'source_image_path' => trim((string)($data['source_image_path'] ?? '')),
        'upscale' => !empty($data['upscale']),
        'upscale_factor' => max(2, min(4, (int)($data['upscale_factor'] ?? 2))),
        'status' => (string)($result['json']['status'] ?? 'queued'),
        'started_at' => date('Y-m-d H:i:s'),
        'expected_seconds' => (int)$config['expected_seconds'],
        'model' => 'RealVisXL 5.0 local',
    ];
}

function studio_tattoo_image_upscale_jpeg(string $sourcePath, int $factor = 2): string
{
    $factor = max(2, min(4, $factor));
    if (!function_exists('imagecreatefromjpeg') || !is_file($sourcePath)) {
        return '';
    }
    $src = @imagecreatefromjpeg($sourcePath);
    if (!$src) {
        return '';
    }
    $width = imagesx($src);
    $height = imagesy($src);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($src);
        return '';
    }
    $dst = imagecreatetruecolor($width * $factor, $height * $factor);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $width * $factor, $height * $factor, $width, $height);
    $target = preg_replace('/\.jpe?g$/i', '_' . $factor . 'x.jpg', $sourcePath) ?: ($sourcePath . '_' . $factor . 'x.jpg');
    imagejpeg($dst, $target, 95);
    imagedestroy($src);
    imagedestroy($dst);
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
    $_SESSION['studio_tattoo_image_history'] = array_slice($items, 0, 12);
}

function studio_tattoo_image_history_find(string $id): ?array
{
    foreach (studio_tattoo_image_history() as $item) {
        if ((string)($item['history_id'] ?? '') === $id) {
            return $item;
        }
    }
    return null;
}

function studio_tattoo_image_poll(array $studio, array $job): array
{
    $jobId = trim((string)($job['id'] ?? ''));
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $jobId)) {
        return ['status' => 'failed', 'error' => 'Geração local inválida.'];
    }

    $result = studio_local_image_ai_request('GET', '/sdcpp/v1/jobs/' . rawurlencode($jobId), null, 10);
    if (empty($result['ok'])) {
        return ['status' => 'waiting', 'error' => (string)($result['error'] ?? '')];
    }

    $json = (array)$result['json'];
    $status = (string)($json['status'] ?? 'waiting');
    if ($status !== 'completed') {
        return ['status' => in_array($status, ['failed', 'cancelled'], true) ? 'failed' : $status, 'queue_position' => (int)($json['queue_position'] ?? 0), 'expected_seconds' => (int)($job['expected_seconds'] ?? 300), 'started_at' => (string)($job['started_at'] ?? ''), 'mode' => (string)($job['mode'] ?? 'final')];
    }

    $base64 = trim((string)($json['result']['images'][0]['b64_json'] ?? ''));
    $binary = $base64 !== '' ? base64_decode($base64, true) : false;
    if ($binary === false || $binary === '') {
        return ['status' => 'failed', 'error' => 'A imagem foi gerada, mas não pôde ser lida.'];
    }

    $safeStudio = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($studio['slug'] ?? 'studio')) ?: 'studio';
    $folder = APP_BASE_PATH . '/storage/tattoo-images/' . $safeStudio;
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
        return ['status' => 'failed', 'error' => 'Não foi possível preparar a pasta das imagens.'];
    }

    $fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.jpg';
    $absolutePath = $folder . '/' . $fileName;
    if (file_put_contents($absolutePath, $binary) === false) {
        return ['status' => 'failed', 'error' => 'Não foi possível salvar a imagem gerada.'];
    }

    $upscaledFile = !empty($job['upscale']) ? studio_tattoo_image_upscale_jpeg($absolutePath, (int)($job['upscale_factor'] ?? 2)) : '';
    $resultPayload = [
        'history_id' => bin2hex(random_bytes(8)),
        'prompt' => (string)($job['prompt'] ?? ''),
        'image_path' => 'storage/tattoo-images/' . $safeStudio . '/' . $fileName,
        'file_name' => $fileName,
        'upscaled_image_path' => $upscaledFile !== '' ? 'storage/tattoo-images/' . $safeStudio . '/' . $upscaledFile : '',
        'upscaled_file_name' => $upscaledFile,
        'generated_at' => date('Y-m-d H:i:s'),
        'mode' => (string)($job['mode'] ?? 'final'),
        'style' => (string)($job['style'] ?? 'realistic'),
        'format' => (string)($job['format'] ?? 'vertical'),
        'reference_notes' => (string)($job['reference_notes'] ?? ''),
        'negative_prompt' => (string)($job['negative_prompt'] ?? ''),
        'source_image_path' => (string)($job['source_image_path'] ?? ''),
        'model' => 'RealVisXL 5.0 local',
    ];
    studio_tattoo_image_history_add($resultPayload);
    return ['status' => 'completed', 'result' => $resultPayload];
}

function studio_tattoo_image_start_from_history(array $studio, array $source, bool $upscale): array
{
    $data = [
        'prompt' => (string)($source['prompt'] ?? ''),
        'style' => (string)($source['style'] ?? 'realistic'),
        'format' => (string)($source['format'] ?? 'vertical'),
        'reference_notes' => (string)($source['reference_notes'] ?? ''),
        'negative_prompt' => (string)($source['negative_prompt'] ?? ''),
        'source_image_path' => (string)($source['image_path'] ?? ''),
        'source_hint' => 'selected-preview',
        'mode' => 'final',
        'upscale' => $upscale ? '1' : '',
        'upscale_factor' => $upscale ? 4 : 2,
    ];
    return studio_tattoo_image_start($studio, $data);
}

function studio_tattoo_image_upscale_history_item(array $source, int $factor = 4): array
{
    $path = studio_tattoo_image_absolute_from_relative((string)($source['image_path'] ?? ''));
    if ($path === '') {
        throw new RuntimeException('Não encontrei o arquivo original para ampliar.');
    }
    $file = studio_tattoo_image_upscale_jpeg($path, $factor);
    if ($file === '') {
        throw new RuntimeException('Não consegui ampliar essa imagem. Verifique se a extensão GD do PHP está ativa.');
    }
    $dir = trim(dirname((string)$source['image_path']), '.');
    $source['upscaled_image_path'] = trim($dir, '/\\') . '/' . $file;
    $source['upscaled_file_name'] = $file;
    $source['generated_at'] = date('Y-m-d H:i:s');
    return $source;
}

function studio_tattoo_image_handle_request(): void
{
    $page = (string)($_GET['page'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['generate_tattoo_reference', 'finalize_tattoo_reference', 'upscale_tattoo_reference'], true)) {
        $studio = require_studio();
        csrf_verify();
        try {
            if ($action === 'generate_tattoo_reference') {
                $_SESSION['studio_tattoo_image_job'] = studio_tattoo_image_start($studio, $_POST);
                unset($_SESSION['studio_tattoo_image_result'], $_SESSION['studio_tattoo_image_prompt']);
                flash_set('success', 'A IA local começou a criar sua imagem.');
            } elseif ($action === 'finalize_tattoo_reference') {
                $source = studio_tattoo_image_history_find((string)($_POST['history_id'] ?? ''));
                if (!$source) throw new RuntimeException('Não encontrei essa prévia no histórico.');
                $_SESSION['studio_tattoo_image_job'] = studio_tattoo_image_start_from_history($studio, $source, !empty($_POST['upscale']));
                flash_set('success', 'Convertendo a prévia selecionada em versão final.');
            } else {
                $source = studio_tattoo_image_history_find((string)($_POST['history_id'] ?? ''));
                if (!$source) throw new RuntimeException('Não encontrei essa imagem no histórico.');
                $updated = studio_tattoo_image_upscale_history_item($source, (int)($_POST['upscale_factor'] ?? 4));
                studio_tattoo_image_history_add($updated);
                $_SESSION['studio_tattoo_image_result'] = $updated;
                flash_set('success', 'Upscale criado para decalque.');
            }
        } catch (Throwable $error) {
            $_SESSION['studio_tattoo_image_prompt'] = trim((string)($_POST['prompt'] ?? ''));
            flash_set('error', $error->getMessage());
        }
        redirect_to('studio_tattoo_images');
    }

    if ($page === 'studio_tattoo_image_status') {
        $studio = require_studio();
        header('Content-Type: application/json; charset=utf-8');
        $job = $_SESSION['studio_tattoo_image_job'] ?? null;
        if (!is_array($job)) {
            echo json_encode(['status' => 'idle'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $poll = studio_tattoo_image_poll($studio, $job);
        if (($poll['status'] ?? '') === 'completed' && is_array($poll['result'] ?? null)) {
            $_SESSION['studio_tattoo_image_result'] = $poll['result'];
            unset($_SESSION['studio_tattoo_image_job']);
        } elseif (($poll['status'] ?? '') === 'failed') {
            unset($_SESSION['studio_tattoo_image_job']);
            flash_set('error', (string)($poll['error'] ?? 'A IA local não conseguiu concluir a imagem.'));
        }
        echo json_encode($poll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($page !== 'studio_tattoo_images') return;

    $studio = require_studio();
    render_studio_shell('Criar imagem', 'Prévia rápida, finalização e upscale para decalque.', 'tattoo_images', function () use ($studio) {
        $localAi = studio_local_image_ai_status();
        $job = $_SESSION['studio_tattoo_image_job'] ?? null;
        $isGenerating = is_array($job) && !empty($job['id']);
        $result = $_SESSION['studio_tattoo_image_result'] ?? null;
        $history = studio_tattoo_image_history();
        $prompt = trim((string)($_SESSION['studio_tattoo_image_prompt'] ?? $job['prompt'] ?? $result['prompt'] ?? ''));
        unset($_SESSION['studio_tattoo_image_prompt']);

        echo '<style>.ai-studio{display:grid;grid-template-columns:minmax(340px,520px) 1fr;gap:18px}.ai-card{background:linear-gradient(145deg,#fff,#f8fafc);border:1px solid rgba(15,23,42,.10);border-radius:24px;box-shadow:0 20px 60px rgba(15,23,42,.08);padding:18px}.ai-hero{background:radial-gradient(circle at top right,rgba(31,111,120,.25),transparent 40%),linear-gradient(135deg,#07111f,#102338);color:#fff;border-radius:28px;padding:24px;margin-bottom:18px;overflow:hidden}.ai-hero span{color:#9be7ef;font-weight:800;font-size:12px;letter-spacing:.14em}.ai-hero h2{margin:8px 0;font-size:clamp(28px,4vw,48px);line-height:.95;color:#fff}.ai-hero p{max-width:68ch;color:#dbeafe}.ai-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.ai-option{padding:12px;border:1px solid rgba(15,23,42,.12);border-radius:16px;background:#fff}.ai-option label{font-weight:800;display:block;margin-bottom:6px}.ai-option select,.ai-option input[type=text],.ai-card textarea,.ai-card input[type=text]{width:100%}.ai-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.ai-status{display:inline-flex;gap:8px;align-items:center;padding:8px 10px;border-radius:999px;background:#e6fffb;color:#155e63;font-weight:800}.ai-dot{width:9px;height:9px;border-radius:50%;background:#14b8a6;box-shadow:0 0 0 6px rgba(20,184,166,.14)}.progress{margin-top:14px}.bar{height:14px;background:rgba(15,23,42,.10);border-radius:999px;overflow:hidden}.fill{height:100%;width:0;background:linear-gradient(90deg,#1f6f78,#0f172a);transition:width .35s}.meta{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:8px}.result-img{width:100%;border-radius:22px;box-shadow:0 18px 45px rgba(15,23,42,.18)}.history-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}.history-card{border:1px solid rgba(15,23,42,.10);border-radius:18px;background:#fff;padding:10px}.history-card img{width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:14px;background:#e5e7eb}.history-card p{font-size:12px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.pill{display:inline-block;border-radius:999px;padding:4px 8px;background:#eef2ff;font-size:11px;font-weight:800;margin:4px 4px 4px 0}@media(max-width:980px){.ai-studio{grid-template-columns:1fr}}</style>';
        echo '<div class="ai-hero"><span>IA LOCAL PARA TATUAGEM</span><h2>Prévia, final e decalque.</h2><p>Gere ideias rápidas com o cliente do lado, escolha a melhor e converta em final com upscale para virar decalque sem ficar encarando uma tela morta igual ritual satânico de escritório.</p></div>';
        echo '<div class="ai-studio"><section class="ai-card"><div class="ai-actions" style="justify-content:space-between;margin-bottom:12px"><div class="ai-status"><i class="ai-dot"></i>' . (empty($localAi['ok']) ? 'IA iniciando' : 'RealVisXL local ativo') . '</div><span class="pill">histórico: ' . h((string)count($history)) . '</span></div>';
        echo '<form method="post" id="tattooImageForm">' . csrf_field() . '<input type="hidden" name="action" value="generate_tattoo_reference">';
        echo '<div class="field"><label>Ideia da arte</label><textarea name="prompt" rows="6" maxlength="4000" required ' . ($isGenerating ? 'disabled' : '') . ' placeholder="Ex: retrato realista do Neymar como guerreiro samurai, expressão séria, luz dramática, composição para antebraço...">' . h($prompt) . '</textarea></div>';
        echo '<div class="ai-grid"><div class="ai-option"><label>Modo</label><select name="mode" ' . ($isGenerating ? 'disabled' : '') . '><option value="fast">Visualização rápida</option><option value="final">Final qualidade</option></select></div><div class="ai-option"><label>Estilo</label><select name="style" ' . ($isGenerating ? 'disabled' : '') . '><option value="realistic">Realista</option><option value="stencil">Stencil blueprint</option><option value="blackwork">Blackwork</option><option value="chicano">Chicano</option><option value="fineline">Fine line</option><option value="oldschool">Old school</option><option value="reference">Referência limpa</option></select></div><div class="ai-option"><label>Formato</label><select name="format" ' . ($isGenerating ? 'disabled' : '') . '><option value="vertical">Vertical</option><option value="square">Quadrado</option><option value="wide">Horizontal</option></select></div><div class="ai-option"><label>Upscale</label><label style="display:flex;gap:8px;align-items:center;font-weight:500"><input type="checkbox" name="upscale" value="1" ' . ($isGenerating ? 'disabled' : '') . '> gerar 2x/4x</label><select name="upscale_factor"><option value="2">2x</option><option value="4">4x decalque</option></select></div></div>';
        echo '<div class="field"><label>Referência / direção artística</label><input type="text" name="reference_notes" maxlength="600" ' . ($isGenerating ? 'disabled' : '') . ' placeholder="Ex: manter clima chicano, rosto limpo, fundo simples..."></div><div class="field"><label>Evitar</label><input type="text" name="negative_prompt" maxlength="600" ' . ($isGenerating ? 'disabled' : '') . ' placeholder="Ex: sem texto, sem fundo poluído, sem mãos..."></div>';
        echo '<div class="ai-actions"><button class="btn" type="submit" ' . (empty($localAi['ok']) || $isGenerating ? 'disabled' : '') . '>' . ($isGenerating ? 'Criando...' : 'Gerar imagem') . '</button><span id="tattooImageWait" class="muted" ' . ($isGenerating ? '' : 'hidden') . '>A IA local está trabalhando...</span></div></form>';
        if ($isGenerating) echo '<div class="progress"><div class="bar"><div id="tattooProgressFill" class="fill"></div></div><div class="meta"><strong id="tattooProgressLabel">Preparando...</strong><span id="tattooProgressPercent">0%</span><span id="tattooProgressTime">0s</span></div></div>';
        echo '</section><section class="ai-card">';
        if (is_array($result) && !empty($result['image_path'])) {
            $imageUrl = app_asset_url((string)$result['image_path']);
            $upscaledUrl = !empty($result['upscaled_image_path']) ? app_asset_url((string)$result['upscaled_image_path']) : '';
            echo '<img class="result-img" src="' . h($imageUrl) . '" alt="Imagem gerada"><h3>Imagem selecionada</h3><p class="muted">' . h((string)$result['prompt']) . '</p><span class="pill">' . h((string)($result['mode'] ?? '')) . '</span><span class="pill">' . h((string)($result['style'] ?? '')) . '</span><div class="ai-actions" style="margin-top:12px"><a class="btn secondary" href="' . h($imageUrl) . '" download="' . h((string)($result['file_name'] ?? 'referencia.jpg')) . '">Baixar</a>';
            if ($upscaledUrl !== '') echo '<a class="btn secondary" href="' . h($upscaledUrl) . '" download="' . h((string)($result['upscaled_file_name'] ?? 'referencia-4x.jpg')) . '">Baixar upscale</a>';
            if (!empty($result['history_id'])) {
                echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="finalize_tattoo_reference"><input type="hidden" name="history_id" value="' . h((string)$result['history_id']) . '"><input type="hidden" name="upscale" value="1"><button class="btn" type="submit">Converter em final + upscale</button></form>';
            }
            echo '</div>';
        } else {
            echo '<div style="min-height:420px;display:grid;place-items:center;text-align:center"><div><div class="tattoo-image-orb"></div><h3>Sua imagem aparece aqui</h3><p class="muted">Gere uma prévia rápida, escolha uma e finalize para decalque.</p></div></div>';
        }
        echo '</section></div>';

        echo '<section class="ai-card" style="margin-top:18px"><div class="ai-actions" style="justify-content:space-between"><div><h2 style="margin:0">Últimas gerações</h2><p class="muted" style="margin:4px 0 0">Escolha uma prévia e transforme em final, ou faça upscale direto para decalque.</p></div><span class="pill">sessão atual</span></div><div class="history-grid" style="margin-top:14px">';
        if (!$history) echo '<p class="muted">Nada gerado ainda.</p>';
        foreach ($history as $item) {
            $thumb = !empty($item['image_path']) ? app_asset_url((string)$item['image_path']) : '';
            $up = !empty($item['upscaled_image_path']) ? app_asset_url((string)$item['upscaled_image_path']) : '';
            echo '<article class="history-card"><img src="' . h($thumb) . '" alt="Prévia"><span class="pill">' . h((string)($item['mode'] ?? '')) . '</span><span class="pill">' . h((string)($item['style'] ?? '')) . '</span><p>' . h((string)($item['prompt'] ?? '')) . '</p><div class="ai-actions"><a class="btn tiny secondary" href="' . h($thumb) . '" download>Baixar</a>';
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="finalize_tattoo_reference"><input type="hidden" name="history_id" value="' . h((string)($item['history_id'] ?? '')) . '"><input type="hidden" name="upscale" value="1"><button class="btn tiny" type="submit">Final + 4x</button></form>';
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="upscale_tattoo_reference"><input type="hidden" name="history_id" value="' . h((string)($item['history_id'] ?? '')) . '"><input type="hidden" name="upscale_factor" value="4"><button class="btn tiny secondary" type="submit">Upscale 4x</button></form>';
            if ($up !== '') echo '<a class="btn tiny secondary" href="' . h($up) . '" download>Baixar 4x</a>';
            echo '</div></article>';
        }
        echo '</div></section>';

        echo '<script>(function(){const form=document.getElementById("tattooImageForm");const wait=document.getElementById("tattooImageWait");if(form){form.addEventListener("submit",()=>{const b=form.querySelector("button[type=submit]");if(b){b.disabled=true;b.textContent="Preparando...";}if(wait){wait.hidden=false;wait.textContent="Preparando a IA local...";}});}';
        if ($isGenerating) {
            echo 'const statusUrl=' . json_encode(app_url('studio_tattoo_image_status'), JSON_UNESCAPED_SLASHES) . ';const startedAt=Date.now();let failures=0;const fill=document.getElementById("tattooProgressFill"),label=document.getElementById("tattooProgressLabel"),percent=document.getElementById("tattooProgressPercent"),time=document.getElementById("tattooProgressTime");function fmt(s){s=Math.max(0,Math.floor(s));const m=Math.floor(s/60),r=s%60;return m?`${m}min ${r}s`:`${r}s`;}function prog(data){const elapsed=(Date.now()-startedAt)/1000;const expected=Number(data.expected_seconds||' . (int)($job['expected_seconds'] ?? 300) . ');let pct=Math.min(95,Math.max(7,Math.floor((elapsed/expected)*92)));let msg="Renderizando...";if(data.status==="queued"){pct=Math.min(pct,18);msg=data.queue_position>0?`Na fila · posição ${data.queue_position}`:"Na fila da IA";}else if(elapsed<20)msg="Preparando prompt e modelo...";else if(pct>80)msg="Finalizando e salvando...";if(fill)fill.style.width=pct+"%";if(percent)percent.textContent=pct+"%";if(label)label.textContent=msg;if(time)time.textContent="Tempo: "+fmt(elapsed);if(wait)wait.textContent=msg+" · "+pct+"%";}const poll=async()=>{try{const r=await fetch(statusUrl,{credentials:"same-origin",cache:"no-store"});const data=await r.json();failures=0;if(["completed","failed","idle"].includes(data.status)){if(fill)fill.style.width="100%";if(percent)percent.textContent="100%";location.reload();return;}prog(data);}catch(e){failures++;if(wait&&failures>2)wait.textContent="A geração continua localmente. Tentando reconectar...";}setTimeout(poll,3000);};prog({status:"queued"});setTimeout(poll,1200);';
        }
        echo '})();</script>';
    }, flash_get());
    exit;
}

studio_tattoo_image_handle_request();
