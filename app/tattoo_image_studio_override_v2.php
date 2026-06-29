<?php

declare(strict_types=1);

function studio_tattoo_image_choice(string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function studio_tattoo_image_norm(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    return strtr($text, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
}

function studio_tattoo_image_mode_config(string $mode): array
{
    return $mode === 'fast'
        ? ['width' => 640, 'height' => 896, 'steps' => 8, 'txt_cfg' => 4.0, 'distilled_guidance' => 2.8, 'expected_seconds' => 110, 'denoise' => 0.36]
        : ['width' => 832, 'height' => 1216, 'steps' => 30, 'txt_cfg' => 5.0, 'distilled_guidance' => 3.5, 'expected_seconds' => 380, 'denoise' => 0.28];
}

function studio_tattoo_image_style_prompt(string $style): string
{
    return match ($style) {
        'stencil' => 'professional tattoo stencil blueprint, pure black ink on white background, solid contour lines, dashed shadow transitions, anatomical hatching, no gray wash, readable thermal-transfer design',
        'blackwork' => 'blackwork tattoo concept, strong silhouettes, balanced negative space, clean readable composition',
        'chicano' => 'chicano tattoo reference, dramatic black and grey mood, smooth tonal contrast, premium tattoo composition',
        'fineline' => 'fine line tattoo concept, delicate clean linework, elegant minimal detail, readable on skin',
        'oldschool' => 'old school tattoo flash reference, bold linework, iconic simplified forms, classic tattoo composition',
        'reference' => 'clean visual reference for tattoo design, clear subject, useful composition, easy to redraw',
        default => 'RAW professional photograph, photorealistic, ultra-detailed, cinematic lighting, sharp subject, rich tonal range',
    };
}

function studio_tattoo_image_subject_guard(string $request): array
{
    $t = studio_tattoo_image_norm($request);
    $animals = ['leao'=>'lion','lion'=>'lion','tigre'=>'tiger','tiger'=>'tiger','lobo'=>'wolf','wolf'=>'wolf','onca'=>'jaguar','jaguar'=>'jaguar','aguia'=>'eagle','eagle'=>'eagle','coruja'=>'owl','owl'=>'owl','cachorro'=>'dog','dog'=>'dog','gato'=>'cat','cat'=>'cat','cobra'=>'snake','snake'=>'snake'];
    foreach ($animals as $needle => $subject) {
        if (preg_match('/\b' . preg_quote($needle, '/') . 's?\b/u', $t)) {
            $details = [];
            if (str_contains($t, 'frontal') || str_contains($t, 'de frente')) $details[] = 'front view';
            if (str_contains($t, 'cabeca completa')) $details[] = 'complete head fully visible';
            if (str_contains($t, 'rugindo') || str_contains($t, 'roaring')) $details[] = 'roaring expression';
            if (str_contains($t, 'coroa')) $details[] = 'royal crown on the animal';
            return [
                'lock' => 'SUBJECT FIDELITY: the main subject must remain exactly a real ' . $subject . '. Preserve species, anatomy, pose, angle, expression and requested accessories. Do not reinterpret the subject as anything else. ' . implode(', ', $details),
                'negative' => 'wrong subject, different species, costume, statue, mannequin, unrelated character'
            ];
        }
    }
    return [
        'lock' => 'SUBJECT FIDELITY: preserve the exact requested main subject, anatomy, identity, count, pose, angle, expression and requested accessories. Do not reinterpret the subject.',
        'negative' => 'wrong subject, different subject, unrelated character'
    ];
}

function studio_tattoo_image_translate_strict(string $request, string $reference = ''): string
{
    $request = trim($request);
    if ($request === '') return $request;
    $guard = studio_tattoo_image_subject_guard($request . ' ' . $reference);
    $body = [
        'model' => trim((string)(getenv('LOCAL_IMAGE_PROMPT_MODEL') ?: 'llama3.2:3b')),
        'stream' => false,
        'think' => false,
        'messages' => [
            ['role' => 'system', 'content' => 'Translate the tattoo image request to concise English. Do not invent. Do not change the main subject, species, count, pose, camera angle, expression, or requested accessory. Return only the final prompt.'],
            ['role' => 'user', 'content' => $guard['lock'] . "\nRequest: " . $request . ($reference !== '' ? "\nStyle notes: " . $reference : '')],
        ],
        'options' => ['temperature' => 0.05, 'num_predict' => 140, 'num_gpu' => 0],
    ];
    $ch = curl_init('http://127.0.0.1:11434/api/chat');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 40]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    $translated = is_array($json) ? trim((string)($json['message']['content'] ?? '')) : '';
    $translated = trim((string)preg_replace('/<think>.*?<\/think>/is', '', $translated));
    return ($status >= 200 && $status < 300 && $translated !== '') ? $translated : $request;
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
    $translated = studio_tattoo_image_translate_strict($request, $reference);
    $formatHint = match ($format) { 'square' => 'balanced square composition', 'wide' => 'horizontal composition', default => 'strong vertical composition' };
    return studio_tattoo_image_style_prompt($style) . ', ' . $formatHint . '. ' . $guard['lock'] . ' Standalone tattoo design reference. No text, no watermark, no logo, no frame. User concept: ' . $translated;
}

function studio_tattoo_image_body(array $data, string $mode): array
{
    $format = studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical','square','wide'], 'vertical');
    $config = studio_tattoo_image_mode_config($mode);
    [$width, $height] = studio_tattoo_image_dimensions_for_format($config, $format);
    $guard = studio_tattoo_image_subject_guard((string)($data['prompt'] ?? '') . ' ' . (string)($data['reference_notes'] ?? ''));
    $negative = 'low quality, blurry, bad anatomy, duplicated subject, watermark, signature, frame, text, logo, user interface, ' . $guard['negative'];
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
    $result = studio_local_image_ai_request('GET', '/sdcpp/v1/jobs/' . rawurlencode($jobId), null, 10);
    if (empty($result['ok'])) return ['status'=>'waiting'];
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

function studio_tattoo_image_start_from_history(array $studio, array $source, bool $upscale): array
{
    return studio_tattoo_image_start($studio, ['prompt'=>(string)($source['prompt'] ?? ''),'style'=>(string)($source['style'] ?? 'realistic'),'format'=>(string)($source['format'] ?? 'vertical'),'reference_notes'=>(string)($source['reference_notes'] ?? ''),'negative_prompt'=>(string)($source['negative_prompt'] ?? ''),'source_image_path'=>(string)($source['image_path'] ?? ''),'source_hint'=>'selected-preview','mode'=>'final','upscale'=>$upscale ? '1' : '','upscale_factor'=>$upscale ? 4 : 2]);
}

function studio_tattoo_image_upscale_history_item(array $source, int $factor = 4): array
{
    $path = studio_tattoo_image_absolute_from_relative((string)($source['image_path'] ?? ''));
    if ($path === '') throw new RuntimeException('Não encontrei o arquivo original para ampliar.');
    $file = studio_tattoo_image_upscale_jpeg($path, $factor);
    if ($file === '') throw new RuntimeException('Não consegui ampliar essa imagem. Verifique se a extensão GD do PHP está ativa.');
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['generate_tattoo_reference','finalize_tattoo_reference','upscale_tattoo_reference'], true)) {
        $studio = require_studio(); csrf_verify();
        try {
            if ($action === 'generate_tattoo_reference') {
                $_SESSION['studio_tattoo_image_job'] = studio_tattoo_image_start($studio, $_POST);
                unset($_SESSION['studio_tattoo_image_result'], $_SESSION['studio_tattoo_image_prompt']);
            } elseif ($action === 'finalize_tattoo_reference') {
                $source = studio_tattoo_image_history_find((string)($_POST['history_id'] ?? ''));
                if (!$source) throw new RuntimeException('Não encontrei essa prévia no histórico.');
                $_SESSION['studio_tattoo_image_job'] = studio_tattoo_image_start_from_history($studio, $source, !empty($_POST['upscale']));
            } else {
                $source = studio_tattoo_image_history_find((string)($_POST['history_id'] ?? ''));
                if (!$source) throw new RuntimeException('Não encontrei essa imagem no histórico.');
                $updated = studio_tattoo_image_upscale_history_item($source, (int)($_POST['upscale_factor'] ?? 4));
                studio_tattoo_image_history_add($updated);
                $_SESSION['studio_tattoo_image_result'] = $updated;
            }
        } catch (Throwable $error) {
            $_SESSION['studio_tattoo_image_prompt'] = trim((string)($_POST['prompt'] ?? ''));
            flash_set('error', $error->getMessage());
        }
        redirect_to('studio_tattoo_images');
    }
    if ($page === 'studio_tattoo_image_status') {
        $studio = require_studio(); header('Content-Type: application/json; charset=utf-8');
        $job = $_SESSION['studio_tattoo_image_job'] ?? null;
        if (!is_array($job)) { echo json_encode(['status'=>'idle'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
        $poll = studio_tattoo_image_poll($studio, $job);
        if (($poll['status'] ?? '') === 'completed' && is_array($poll['result'] ?? null)) { $_SESSION['studio_tattoo_image_result'] = $poll['result']; unset($_SESSION['studio_tattoo_image_job']); }
        elseif (($poll['status'] ?? '') === 'failed') { unset($_SESSION['studio_tattoo_image_job']); flash_set('error', (string)($poll['error'] ?? 'A IA local não conseguiu concluir a imagem.')); }
        echo json_encode($poll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
    }
    if ($page !== 'studio_tattoo_images') return;
    $studio = require_studio();
    render_studio_shell('Criar imagem', 'Prévia rápida, finalização e upscale para decalque.', 'tattoo_images', function () use ($studio) {
        $localAi = studio_local_image_ai_status(); $job = $_SESSION['studio_tattoo_image_job'] ?? null; $isGenerating = is_array($job) && !empty($job['id']); $result = $_SESSION['studio_tattoo_image_result'] ?? null; $history = studio_tattoo_image_history(); $prompt = trim((string)($_SESSION['studio_tattoo_image_prompt'] ?? $job['prompt'] ?? $result['prompt'] ?? '')); unset($_SESSION['studio_tattoo_image_prompt']);
        echo '<style>.ai-studio{display:grid;grid-template-columns:minmax(340px,520px) 1fr;gap:18px}.ai-card{background:linear-gradient(145deg,#fff,#f8fafc);border:1px solid rgba(15,23,42,.10);border-radius:24px;box-shadow:0 20px 60px rgba(15,23,42,.08);padding:18px}.ai-hero{background:linear-gradient(135deg,#07111f,#102338);color:#fff;border-radius:28px;padding:24px;margin-bottom:18px}.ai-hero h2{color:#fff}.ai-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.ai-option{padding:12px;border:1px solid rgba(15,23,42,.12);border-radius:16px;background:#fff}.ai-option label{font-weight:800;display:block}.ai-option select,.ai-card textarea,.ai-card input[type=text]{width:100%}.ai-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.result-img{width:100%;border-radius:22px}.history-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}.history-card{border:1px solid rgba(15,23,42,.10);border-radius:18px;background:#fff;padding:10px}.history-card img{width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:14px}.pill{display:inline-block;border-radius:999px;padding:4px 8px;background:#eef2ff;font-size:11px;font-weight:800;margin:4px}@media(max-width:980px){.ai-studio{grid-template-columns:1fr}}</style>';
        echo '<div class="ai-hero"><h2>Prévia, final e decalque.</h2><p>Agora com fidelidade reforçada: a IA pode criar livremente, mas sem trocar o sujeito principal como se estivesse bêbada de dataset.</p></div>';
        echo '<div class="ai-studio"><section class="ai-card"><form method="post" id="tattooImageForm">' . csrf_field() . '<input type="hidden" name="action" value="generate_tattoo_reference"><div class="field"><label>Ideia da arte</label><textarea name="prompt" rows="6" maxlength="4000" required ' . ($isGenerating ? 'disabled' : '') . ' placeholder="Ex: cabeça completa frontal de um leão rugindo usando uma coroa de rei...">' . h($prompt) . '</textarea></div><div class="ai-grid"><div class="ai-option"><label>Modo</label><select name="mode"><option value="fast">Visualização rápida</option><option value="final">Final qualidade</option></select></div><div class="ai-option"><label>Estilo</label><select name="style"><option value="realistic">Realista</option><option value="stencil">Stencil blueprint</option><option value="blackwork">Blackwork</option><option value="chicano">Chicano</option><option value="fineline">Fine line</option><option value="oldschool">Old school</option><option value="reference">Referência limpa</option></select></div><div class="ai-option"><label>Formato</label><select name="format"><option value="vertical">Vertical</option><option value="square">Quadrado</option><option value="wide">Horizontal</option></select></div><div class="ai-option"><label>Upscale</label><label><input type="checkbox" name="upscale" value="1"> gerar 4x</label><input type="hidden" name="upscale_factor" value="4"></div></div><div class="field"><label>Referência / direção artística</label><input type="text" name="reference_notes" maxlength="600"></div><div class="field"><label>Evitar</label><input type="text" name="negative_prompt" maxlength="600"></div><button class="btn" type="submit" ' . (empty($localAi['ok']) || $isGenerating ? 'disabled' : '') . '>' . ($isGenerating ? 'Criando...' : 'Gerar imagem') . '</button></form></section><section class="ai-card">';
        if (is_array($result) && !empty($result['image_path'])) { $url = app_asset_url((string)$result['image_path']); echo '<img class="result-img" src="' . h($url) . '"><h3>Imagem selecionada</h3><p>' . h((string)$result['prompt']) . '</p><a class="btn secondary" href="' . h($url) . '" download>Baixar</a>'; }
        else echo '<p class="muted">Sua imagem aparece aqui.</p>';
        echo '</section></div><section class="ai-card" style="margin-top:18px"><h2>Últimas gerações</h2><div class="history-grid">';
        foreach ($history as $item) { $thumb = !empty($item['image_path']) ? app_asset_url((string)$item['image_path']) : ''; echo '<article class="history-card"><img src="' . h($thumb) . '"><p>' . h((string)($item['prompt'] ?? '')) . '</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="finalize_tattoo_reference"><input type="hidden" name="history_id" value="' . h((string)($item['history_id'] ?? '')) . '"><input type="hidden" name="upscale" value="1"><button class="btn tiny" type="submit">Final + 4x</button></form></article>'; }
        echo '</div></section><script>(function(){const statusUrl=' . json_encode(app_url('studio_tattoo_image_status'), JSON_UNESCAPED_SLASHES) . ';';
        if ($isGenerating) echo 'setTimeout(function poll(){fetch(statusUrl,{credentials:"same-origin",cache:"no-store"}).then(r=>r.json()).then(d=>{if(["completed","failed","idle"].includes(d.status)){location.reload();return;}setTimeout(poll,3000);}).catch(()=>setTimeout(poll,3000));},1200);';
        echo '})();</script>';
    }, flash_get()); exit;
}

studio_tattoo_image_handle_request();
