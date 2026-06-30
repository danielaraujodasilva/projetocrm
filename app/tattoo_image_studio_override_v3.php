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
        ? ['width' => 640, 'height' => 896, 'steps' => 8, 'txt_cfg' => 4.0, 'distilled_guidance' => 2.8, 'expected_seconds' => 110, 'denoise' => 0.36]
        : ['width' => 832, 'height' => 1216, 'steps' => 30, 'txt_cfg' => 5.0, 'distilled_guidance' => 3.5, 'expected_seconds' => 380, 'denoise' => 0.28];
}

function studio_tattoo_image_norm(string $text): string
{
    return strtr(mb_strtolower($text, 'UTF-8'), ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
}

function studio_tattoo_image_subject_guard(string $request): array
{
    $t = studio_tattoo_image_norm($request);
    $animals = ['leao'=>'lion','lion'=>'lion','tigre'=>'tiger','tiger'=>'tiger','lobo'=>'wolf','wolf'=>'wolf','onca'=>'jaguar','jaguar'=>'jaguar','aguia'=>'eagle','eagle'=>'eagle','coruja'=>'owl','owl'=>'owl','cachorro'=>'dog','dog'=>'dog','gato'=>'cat','cat'=>'cat','cobra'=>'snake','snake'=>'snake'];
    foreach ($animals as $needle => $animal) {
        if (preg_match('/\b' . preg_quote($needle, '/') . 's?\b/u', $t)) {
            $details = [];
            if (str_contains($t, 'frontal') || str_contains($t, 'de frente')) $details[] = 'front view';
            if (str_contains($t, 'cabeca completa')) $details[] = 'complete head fully visible';
            if (str_contains($t, 'rugindo') || str_contains($t, 'roaring')) $details[] = 'roaring expression';
            if (str_contains($t, 'coroa')) $details[] = 'royal crown accessory on the animal';
            return [
                'lock' => 'SUBJECT FIDELITY: main subject is exactly a real ' . $animal . '. Preserve the animal species, anatomy, pose, angle, expression and requested accessories. ' . implode(', ', $details) . '.',
                'negative' => 'wrong subject, different animal, unrelated character, statue, costume, mannequin, text, watermark'
            ];
        }
    }
    return [
        'lock' => 'SUBJECT FIDELITY: preserve the exact requested main subject, anatomy, identity, pose, angle, expression and requested accessories.',
        'negative' => 'wrong subject, unrelated character, text, watermark'
    ];
}

function studio_tattoo_image_style_prompt(string $style): string
{
    return match ($style) {
        'stencil' => 'professional tattoo stencil blueprint, pure black ink on white background, clean contour lines, dashed shadow guides, anatomical hatching, no gray wash',
        'blackwork' => 'blackwork tattoo concept, strong silhouettes, balanced negative space, readable tattoo composition',
        'chicano' => 'chicano tattoo reference, dramatic black and grey mood, smooth contrast, premium tattoo composition',
        'fineline' => 'fine line tattoo concept, delicate clean linework, elegant minimal detail',
        'oldschool' => 'old school tattoo flash reference, bold linework, iconic simplified forms',
        'reference' => 'clean visual reference for tattoo design, clear subject, easy to redraw',
        default => 'photorealistic tattoo reference, cinematic lighting, sharp subject, rich tonal range, ultra detailed',
    };
}

function studio_tattoo_image_translate_basic(string $text): string
{
    $r = $text;
    $pairs = [
        'cabeça completa' => 'complete head', 'cabeca completa' => 'complete head', 'frontal' => 'front view', 'de frente' => 'front view', 'rugindo' => 'roaring',
        'leão' => 'lion', 'leao' => 'lion', 'tigre' => 'tiger', 'lobo' => 'wolf', 'onça' => 'jaguar', 'onca' => 'jaguar', 'águia' => 'eagle', 'aguia' => 'eagle',
        'coroa de rei' => 'royal king crown', 'coroa' => 'crown', 'realista' => 'realistic', 'preto e branco' => 'black and grey'
    ];
    foreach ($pairs as $from => $to) {
        $r = str_ireplace($from, $to, $r);
    }
    return $r;
}

function studio_tattoo_image_dimensions_for_format(array $config, string $format): array
{
    if ($format === 'square') return [768, 768];
    if ($format === 'wide') return [(int)$config['height'], (int)$config['width']];
    return [(int)$config['width'], (int)$config['height']];
}

function studio_tattoo_image_absolute_from_relative(string $relative): string
{
    $relative = ltrim(trim($relative), '/\\');
    if ($relative === '' || str_contains($relative, '..')) return '';
    $path = APP_BASE_PATH . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    return is_file($path) ? $path : '';
}

function studio_tattoo_image_build_prompt(array $data): string
{
    $request = trim((string)($data['prompt'] ?? ''));
    if (mb_strlen($request, 'UTF-8') < 4) throw new RuntimeException('Descreva um pouco melhor a imagem que você quer criar.');
    $style = studio_tattoo_image_choice((string)($data['style'] ?? 'realistic'), ['realistic','stencil','blackwork','chicano','fineline','oldschool','reference'], 'realistic');
    $format = studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical','square','wide'], 'vertical');
    $reference = trim((string)($data['reference_notes'] ?? ''));
    $guard = studio_tattoo_image_subject_guard($request . ' ' . $reference);
    $translated = studio_tattoo_image_translate_basic($request . ($reference !== '' ? '. Style notes: ' . $reference : ''));
    $formatHint = match ($format) { 'square' => 'balanced square composition', 'wide' => 'wide horizontal composition', default => 'strong vertical composition' };
    return studio_tattoo_image_style_prompt($style) . ', ' . $formatHint . '. ' . $guard['lock'] . ' No caption, no watermark, no logo, no frame. User concept: ' . $translated;
}

function studio_tattoo_image_body(array $data, string $mode): array
{
    $format = studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical','square','wide'], 'vertical');
    $config = studio_tattoo_image_mode_config($mode);
    [$width, $height] = studio_tattoo_image_dimensions_for_format($config, $format);
    $guard = studio_tattoo_image_subject_guard((string)($data['prompt'] ?? '') . ' ' . (string)($data['reference_notes'] ?? ''));
    $negative = 'low quality, blurry, malformed anatomy, duplicated subject, signature, user interface, ' . $guard['negative'];
    $extraNegative = trim((string)($data['negative_prompt'] ?? ''));
    if ($extraNegative !== '') $negative .= ', ' . $extraNegative;
    $body = [
        'prompt' => studio_tattoo_image_build_prompt($data),
        'negative_prompt' => $negative,
        'clip_skip' => -1,
        'width' => $width,
        'height' => $height,
        'seed' => -1,
        'batch_count' => 1,
        'sample_params' => ['scheduler' => 'karras', 'sample_method' => 'dpm++2m', 'sample_steps' => (int)$config['steps'], 'guidance' => ['txt_cfg' => (float)$config['txt_cfg'], 'distilled_guidance' => (float)$config['distilled_guidance']]],
        'vae_tiling_params' => ['enabled' => true, 'tile_size_x' => 512, 'tile_size_y' => 512, 'target_overlap' => 0.25],
        'output_format' => 'jpeg',
        'output_compression' => 94,
    ];
    $sourcePath = studio_tattoo_image_absolute_from_relative((string)($data['source_image_path'] ?? ''));
    if ($sourcePath !== '') {
        $sourceBinary = @file_get_contents($sourcePath);
        if (is_string($sourceBinary) && $sourceBinary !== '') {
            $b64 = base64_encode($sourceBinary);
            $body['init_image'] = $b64;
            $body['image'] = $b64;
            $body['source_image'] = $b64;
            $body['strength'] = (float)$config['denoise'];
            $body['denoising_strength'] = (float)$config['denoise'];
        }
    }
    return $body;
}

function studio_tattoo_image_upscale_jpeg(string $sourcePath, int $factor = 2): string
{
    $factor = max(2, min(4, $factor));
    if (!function_exists('imagecreatefromjpeg') || !is_file($sourcePath)) return '';
    $src = @imagecreatefromjpeg($sourcePath);
    if (!$src) return '';
    $width = imagesx($src); $height = imagesy($src);
    $dst = imagecreatetruecolor($width * $factor, $height * $factor);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $width * $factor, $height * $factor, $width, $height);
    $target = preg_replace('/\.jpe?g$/i', '_' . $factor . 'x.jpg', $sourcePath) ?: ($sourcePath . '_' . $factor . 'x.jpg');
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
    $_SESSION['studio_tattoo_image_history'] = array_slice($items, 0, 12);
}

function studio_tattoo_image_history_find(string $id): ?array
{
    foreach (studio_tattoo_image_history() as $item) if ((string)($item['history_id'] ?? '') === $id) return $item;
    return null;
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
    $mode = studio_tattoo_image_choice((string)($data['mode'] ?? 'final'), ['fast','final'], 'final');
    $localStatus = studio_local_image_ai_status();
    if (empty($localStatus['ok'])) throw new RuntimeException('A IA local ainda está iniciando.');
    $result = studio_local_image_ai_request('POST', '/sdcpp/v1/img_gen', studio_tattoo_image_body($data, $mode), 120);
    if (empty($result['ok'])) throw new RuntimeException((string)($result['error'] ?? 'Não foi possível iniciar a geração local.'));
    $jobId = trim((string)($result['json']['id'] ?? ''));
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $jobId)) throw new RuntimeException('A IA local não devolveu um identificador válido.');
    $config = studio_tattoo_image_mode_config($mode);
    return ['id'=>$jobId,'prompt'=>trim((string)($data['prompt'] ?? '')),'style'=>studio_tattoo_image_choice((string)($data['style'] ?? 'realistic'), ['realistic','stencil','blackwork','chicano','fineline','oldschool','reference'], 'realistic'),'mode'=>$mode,'format'=>studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical','square','wide'], 'vertical'),'reference_notes'=>trim((string)($data['reference_notes'] ?? '')),'negative_prompt'=>trim((string)($data['negative_prompt'] ?? '')),'source_image_path'=>trim((string)($data['source_image_path'] ?? '')),'upscale'=>!empty($data['upscale']),'upscale_factor'=>max(2, min(4, (int)($data['upscale_factor'] ?? 2))),'started_at'=>date('Y-m-d H:i:s'),'expected_seconds'=>(int)$config['expected_seconds'],'model'=>'RealVisXL 5.0 local'];
}

function studio_tattoo_image_poll(array $studio, array $job): array
{
    $jobId = trim((string)($job['id'] ?? ''));
    if ($jobId === '') return ['status'=>'failed','error'=>'Job inválido.'];
    $result = studio_local_image_ai_request('GET', '/sdcpp/v1/jobs/' . rawurlencode($jobId), null, 10);
    if (empty($result['ok'])) return ['status'=>'waiting', 'expected_seconds'=>(int)($job['expected_seconds'] ?? 300)];
    $json = (array)$result['json'];
    $status = (string)($json['status'] ?? 'waiting');
    if ($status !== 'completed') return ['status'=>in_array($status, ['failed','cancelled'], true) ? 'failed' : $status,'queue_position'=>(int)($json['queue_position'] ?? 0),'expected_seconds'=>(int)($job['expected_seconds'] ?? 300)];
    $base64 = trim((string)($json['result']['images'][0]['b64_json'] ?? ''));
    $binary = $base64 !== '' ? base64_decode($base64, true) : false;
    if ($binary === false || $binary === '') return ['status'=>'failed','error'=>'A imagem foi gerada, mas não pôde ser lida.'];
    $safeStudio = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($studio['slug'] ?? 'studio')) ?: 'studio';
    $folder = APP_BASE_PATH . '/storage/tattoo-images/' . $safeStudio;
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) return ['status'=>'failed','error'=>'Não foi possível preparar a pasta.'];
    $fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.jpg';
    $absolutePath = $folder . '/' . $fileName;
    file_put_contents($absolutePath, $binary);
    $upscaledFile = !empty($job['upscale']) ? studio_tattoo_image_upscale_jpeg($absolutePath, (int)($job['upscale_factor'] ?? 2)) : '';
    $payload = ['history_id'=>bin2hex(random_bytes(8)),'prompt'=>(string)($job['prompt'] ?? ''),'image_path'=>'storage/tattoo-images/' . $safeStudio . '/' . $fileName,'file_name'=>$fileName,'upscaled_image_path'=>$upscaledFile !== '' ? 'storage/tattoo-images/' . $safeStudio . '/' . $upscaledFile : '','upscaled_file_name'=>$upscaledFile,'generated_at'=>date('Y-m-d H:i:s'),'mode'=>(string)($job['mode'] ?? 'final'),'style'=>(string)($job['style'] ?? 'realistic'),'format'=>(string)($job['format'] ?? 'vertical'),'reference_notes'=>(string)($job['reference_notes'] ?? ''),'negative_prompt'=>(string)($job['negative_prompt'] ?? ''),'source_image_path'=>(string)($job['source_image_path'] ?? ''),'model'=>'RealVisXL 5.0 local'];
    studio_tattoo_image_history_add($payload);
    return ['status'=>'completed','result'=>$payload];
}

function studio_tattoo_image_handle_request(): void
{
    $page = (string)($_GET['page'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if ($page === 'studio_tattoo_images' && (isset($_GET['reset']) || $action === 'cancel_tattoo_reference')) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_verify();
        studio_tattoo_image_clear_job();
        flash_set('success', 'Geração travada cancelada.');
        redirect_to('studio_tattoo_images');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generate_tattoo_reference') {
        $studio = require_studio(); csrf_verify();
        try {
            $_SESSION['studio_tattoo_image_job'] = studio_tattoo_image_start($studio, $_POST);
            unset($_SESSION['studio_tattoo_image_result'], $_SESSION['studio_tattoo_image_prompt']);
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
        if (($poll['status'] ?? '') === 'completed' && is_array($poll['result'] ?? null)) { $_SESSION['studio_tattoo_image_result'] = $poll['result']; studio_tattoo_image_clear_job(); }
        elseif (($poll['status'] ?? '') === 'failed') { studio_tattoo_image_clear_job(); flash_set('error', (string)($poll['error'] ?? 'A IA local não conseguiu concluir a imagem.')); }
        echo json_encode($poll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
    }
    if ($page !== 'studio_tattoo_images') return;
    $studio = require_studio();
    render_studio_shell('Criar imagem', 'Prévia rápida, finalização e reset de travamento.', 'tattoo_images', function () use ($studio) {
        $localAi = studio_local_image_ai_status();
        $job = studio_tattoo_image_current_job();
        $isGenerating = is_array($job);
        $result = $_SESSION['studio_tattoo_image_result'] ?? null;
        $prompt = trim((string)($_SESSION['studio_tattoo_image_prompt'] ?? $job['prompt'] ?? $result['prompt'] ?? ''));
        unset($_SESSION['studio_tattoo_image_prompt']);
        echo '<style>.ai-card{background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:22px;padding:18px;box-shadow:0 16px 50px rgba(15,23,42,.08);margin-bottom:16px}.ai-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.ai-option{padding:12px;border:1px solid rgba(15,23,42,.12);border-radius:14px}.ai-option select,.ai-card textarea,.ai-card input[type=text]{width:100%}.ai-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.result-img{width:100%;border-radius:18px}.danger{background:#b91c1c!important;color:#fff!important}</style>';
        echo '<section class="ai-card"><h2>Criar imagem</h2><p class="muted">Sem Ollama no meio: prompt direto, mais rápido e menos chance de travar nesse limbo digital ridículo.</p>';
        if ($isGenerating) {
            echo '<div class="panel soft"><strong>Existe uma geração em andamento.</strong><p class="muted">Se travou, cancele aqui.</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="cancel_tattoo_reference"><button class="btn danger" type="submit">Cancelar / zerar geração</button></form></div>';
        }
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="generate_tattoo_reference"><div class="field"><label>Ideia da arte</label><textarea name="prompt" rows="6" maxlength="4000" required ' . ($isGenerating ? 'disabled' : '') . ' placeholder="Ex: cabeça completa frontal de um leão rugindo usando uma coroa de rei...">' . h($prompt) . '</textarea></div><div class="ai-grid"><div class="ai-option"><label>Modo</label><select name="mode"><option value="fast">Visualização rápida</option><option value="final">Final qualidade</option></select></div><div class="ai-option"><label>Estilo</label><select name="style"><option value="realistic">Realista</option><option value="stencil">Stencil blueprint</option><option value="blackwork">Blackwork</option><option value="chicano">Chicano</option><option value="fineline">Fine line</option><option value="oldschool">Old school</option><option value="reference">Referência limpa</option></select></div><div class="ai-option"><label>Formato</label><select name="format"><option value="vertical">Vertical</option><option value="square">Quadrado</option><option value="wide">Horizontal</option></select></div><div class="ai-option"><label>Upscale</label><label><input type="checkbox" name="upscale" value="1"> gerar 4x</label><input type="hidden" name="upscale_factor" value="4"></div></div><div class="field"><label>Referência / direção artística</label><input type="text" name="reference_notes" maxlength="600"></div><div class="field"><label>Evitar</label><input type="text" name="negative_prompt" maxlength="600"></div><button class="btn" type="submit" ' . (empty($localAi['ok']) || $isGenerating ? 'disabled' : '') . '>' . ($isGenerating ? 'Criando...' : 'Gerar imagem') . '</button></form></section>';
        echo '<section class="ai-card">';
        if (is_array($result) && !empty($result['image_path'])) { $url = app_asset_url((string)$result['image_path']); echo '<img class="result-img" src="' . h($url) . '"><h3>Imagem selecionada</h3><p>' . h((string)$result['prompt']) . '</p><a class="btn secondary" href="' . h($url) . '" download>Baixar</a>'; }
        else echo '<p class="muted">Sua imagem aparece aqui.</p>';
        echo '</section><script>(function(){const generating=' . ($isGenerating ? 'true' : 'false') . ';if(!generating)return;const statusUrl=' . json_encode(app_url('studio_tattoo_image_status'), JSON_UNESCAPED_SLASHES) . ';setTimeout(function poll(){fetch(statusUrl,{credentials:"same-origin",cache:"no-store"}).then(r=>r.json()).then(d=>{if(["completed","failed","idle"].includes(d.status)){location.reload();return;}setTimeout(poll,3000);}).catch(()=>setTimeout(poll,5000));},1200);})();</script>';
    }, flash_get());
    exit;
}

studio_tattoo_image_handle_request();
