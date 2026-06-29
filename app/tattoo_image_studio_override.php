<?php

declare(strict_types=1);

function studio_tattoo_image_choice(string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function studio_tattoo_image_mode_config(string $mode): array
{
    if ($mode === 'fast') {
        return [
            'label' => 'Visualizacao rapida',
            'width' => 640,
            'height' => 896,
            'steps' => 8,
            'txt_cfg' => 4.0,
            'distilled_guidance' => 2.8,
            'expected_seconds' => 110,
        ];
    }

    return [
        'label' => 'Final qualidade',
        'width' => 832,
        'height' => 1216,
        'steps' => 30,
        'txt_cfg' => 5.0,
        'distilled_guidance' => 3.5,
        'expected_seconds' => 380,
    ];
}

function studio_tattoo_image_style_prompt(string $style): string
{
    return match ($style) {
        'stencil' => 'professional tattoo stencil blueprint, clean black ink on white background, solid contour lines, dashed shadow transitions, anatomical hatching, readable thermal-transfer design, no gray wash, no solid black masses',
        'blackwork' => 'blackwork tattoo concept, strong silhouettes, balanced negative space, ornamental black shapes, clean readable composition for skin',
        'chicano' => 'chicano tattoo reference, dramatic black and grey mood, smooth gradients translated into tattoo-friendly contrast, elegant composition, premium studio style',
        'fineline' => 'fine line tattoo concept, delicate clean linework, elegant minimal detail, soft realistic structure, readable on skin',
        'oldschool' => 'old school tattoo flash reference, bold linework, iconic simplified forms, strong readable shapes, classic tattoo composition',
        'reference' => 'visual reference for tattoo design, clear subject, clean composition, useful for drawing and planning a tattoo',
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
    $translated = studio_translate_tattoo_image_prompt($request . ($reference !== '' ? "\nStyle/reference notes: " . $reference : ''));

    $formatHint = match ($format) {
        'square' => 'balanced square composition, central readable subject',
        'wide' => 'horizontal cinematic composition, useful for extended tattoo layout',
        default => 'strong vertical composition, main subject centered and readable',
    };

    return studio_tattoo_image_style_prompt($style) . ', ' . $formatHint . '. '
        . 'Create a standalone tattoo design reference. Do not add interface, caption, watermark, logo, frame or random typography. '
        . 'Respect the requested identity, age, expression, anatomy, pose, mood and composition when provided by the user. '
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

function studio_tattoo_image_start(array $studio, array $data): array
{
    $mode = studio_tattoo_image_choice((string)($data['mode'] ?? 'final'), ['fast', 'final'], 'final');
    $format = studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical', 'square', 'wide'], 'vertical');
    $config = studio_tattoo_image_mode_config($mode);
    [$width, $height] = studio_tattoo_image_dimensions_for_format($config, $format);

    $localStatus = studio_local_image_ai_status();
    if (empty($localStatus['ok'])) {
        throw new RuntimeException('A IA local de imagens ainda está iniciando. Aguarde um pouco e tente novamente.');
    }

    $prompt = studio_tattoo_image_build_prompt($data);
    $negative = 'low quality, worst quality, blurry, flat lighting, oversaturated, malformed anatomy, deformed hands, extra fingers, bad eyes, duplicated subject, watermark, signature, border, frame, user interface';
    $extraNegative = trim((string)($data['negative_prompt'] ?? ''));
    if ($extraNegative !== '') {
        $negative .= ', ' . $extraNegative;
    }

    $body = [
        'prompt' => $prompt,
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
            'guidance' => [
                'txt_cfg' => (float)$config['txt_cfg'],
                'distilled_guidance' => (float)$config['distilled_guidance'],
            ],
        ],
        'vae_tiling_params' => [
            'enabled' => true,
            'tile_size_x' => 512,
            'tile_size_y' => 512,
            'target_overlap' => 0.25,
        ],
        'output_format' => 'jpeg',
        'output_compression' => 94,
    ];

    $result = studio_local_image_ai_request('POST', '/sdcpp/v1/img_gen', $body, 120);
    if (empty($result['ok'])) {
        throw new RuntimeException((string)($result['error'] ?? 'Não foi possível iniciar a geração local.'));
    }

    $jobId = trim((string)($result['json']['id'] ?? ''));
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $jobId)) {
        throw new RuntimeException('A IA local não devolveu um identificador válido para a imagem.');
    }

    return [
        'id' => $jobId,
        'prompt' => trim((string)($data['prompt'] ?? '')),
        'style' => studio_tattoo_image_choice((string)($data['style'] ?? 'realistic'), ['realistic', 'stencil', 'blackwork', 'chicano', 'fineline', 'oldschool', 'reference'], 'realistic'),
        'mode' => $mode,
        'format' => $format,
        'upscale' => !empty($data['upscale']),
        'status' => (string)($result['json']['status'] ?? 'queued'),
        'started_at' => date('Y-m-d H:i:s'),
        'expected_seconds' => (int)$config['expected_seconds'],
        'model' => 'RealVisXL 5.0 local',
    ];
}

function studio_tattoo_image_upscale_jpeg(string $sourcePath): string
{
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
    $dst = imagecreatetruecolor($width * 2, $height * 2);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $width * 2, $height * 2, $width, $height);
    $target = preg_replace('/\.jpe?g$/i', '_2x.jpg', $sourcePath) ?: ($sourcePath . '_2x.jpg');
    imagejpeg($dst, $target, 94);
    imagedestroy($src);
    imagedestroy($dst);
    return is_file($target) ? basename($target) : '';
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
        return [
            'status' => in_array($status, ['failed', 'cancelled'], true) ? 'failed' : $status,
            'queue_position' => (int)($json['queue_position'] ?? 0),
            'expected_seconds' => (int)($job['expected_seconds'] ?? 300),
            'started_at' => (string)($job['started_at'] ?? ''),
            'mode' => (string)($job['mode'] ?? 'final'),
        ];
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

    $upscaledFile = !empty($job['upscale']) ? studio_tattoo_image_upscale_jpeg($absolutePath) : '';

    return [
        'status' => 'completed',
        'result' => [
            'prompt' => (string)($job['prompt'] ?? ''),
            'image_path' => 'storage/tattoo-images/' . $safeStudio . '/' . $fileName,
            'file_name' => $fileName,
            'upscaled_image_path' => $upscaledFile !== '' ? 'storage/tattoo-images/' . $safeStudio . '/' . $upscaledFile : '',
            'upscaled_file_name' => $upscaledFile,
            'generated_at' => date('Y-m-d H:i:s'),
            'mode' => (string)($job['mode'] ?? 'final'),
            'style' => (string)($job['style'] ?? 'realistic'),
            'model' => 'RealVisXL 5.0 local',
        ],
    ];
}

function studio_tattoo_image_handle_request(): void
{
    $page = (string)($_GET['page'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generate_tattoo_reference') {
        $studio = require_studio();
        csrf_verify();
        try {
            $_SESSION['studio_tattoo_image_job'] = studio_tattoo_image_start($studio, $_POST);
            unset($_SESSION['studio_tattoo_image_result'], $_SESSION['studio_tattoo_image_prompt']);
            flash_set('success', 'A IA local começou a criar sua imagem.');
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

    if ($page !== 'studio_tattoo_images') {
        return;
    }

    $studio = require_studio();
    render_studio_shell('Criar imagem', 'Prévia rápida, imagem final e upscale para referência de tatuagem.', 'tattoo_images', function () use ($studio) {
        $localAi = studio_local_image_ai_status();
        $job = $_SESSION['studio_tattoo_image_job'] ?? null;
        $isGenerating = is_array($job) && !empty($job['id']);
        $result = $_SESSION['studio_tattoo_image_result'] ?? null;
        $prompt = trim((string)($_SESSION['studio_tattoo_image_prompt'] ?? $job['prompt'] ?? $result['prompt'] ?? ''));
        unset($_SESSION['studio_tattoo_image_prompt']);

        echo '<style>.tattoo-progress-card{margin-top:16px;padding:16px;border-radius:18px;background:rgba(15,23,42,.04);border:1px solid rgba(15,23,42,.10)}.tattoo-progress-track{height:14px;background:rgba(15,23,42,.10);border-radius:999px;overflow:hidden}.tattoo-progress-fill{height:100%;width:0;background:linear-gradient(90deg,#1f6f78,#0f172a);border-radius:999px;transition:width .35s ease}.tattoo-progress-meta{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:8px}.tattoo-options-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:12px 0}.tattoo-option{border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:10px;background:#fff}.tattoo-option label{font-weight:700;display:block;margin-bottom:6px}.tattoo-image-form select,.tattoo-image-form input[type=text]{width:100%}</style>';
        echo '<div class="tattoo-image-studio">';
        echo '<section class="tattoo-image-compose">';
        echo '<div class="tattoo-image-intro"><span>IA LOCAL PARA TATUAGEM</span><h2>O que você quer criar?</h2><p>Use prévia rápida com cliente do lado, final em qualidade maior quando a ideia estiver aprovada e upscale 2x quando precisar imprimir ou redesenhar.</p></div>';
        if (empty($localAi['ok'])) {
            echo '<div class="tattoo-image-key-note"><strong>A IA local está iniciando.</strong><span>O modelo RealVisXL roda nesta máquina. Atualize em alguns instantes.</span></div>';
        } else {
            echo '<div class="tattoo-image-local-status"><i></i><span>RealVisXL 5.0 · rodando localmente</span></div>';
        }
        echo '<form method="post" id="tattooImageForm" class="tattoo-image-form">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="generate_tattoo_reference">';
        echo '<textarea name="prompt" rows="5" maxlength="4000" required ' . ($isGenerating ? 'disabled' : '') . ' placeholder="Ex: retrato realista do Neymar como guerreiro samurai, expressão séria, iluminação dramática, composição para fechar antebraço...">' . h($prompt) . '</textarea>';
        echo '<div class="tattoo-options-grid">';
        echo '<div class="tattoo-option"><label>Velocidade</label><select name="mode" ' . ($isGenerating ? 'disabled' : '') . '><option value="fast">Visualização rápida</option><option value="final" selected>Final qualidade</option></select><small class="muted">Rápido para aprovar ideia, final para imagem bonita.</small></div>';
        echo '<div class="tattoo-option"><label>Estilo</label><select name="style" ' . ($isGenerating ? 'disabled' : '') . '><option value="realistic">Realista</option><option value="stencil">Stencil blueprint</option><option value="blackwork">Blackwork</option><option value="chicano">Chicano</option><option value="fineline">Fine line</option><option value="oldschool">Old school</option><option value="reference">Referência limpa</option></select></div>';
        echo '<div class="tattoo-option"><label>Formato</label><select name="format" ' . ($isGenerating ? 'disabled' : '') . '><option value="vertical">Vertical</option><option value="square">Quadrado</option><option value="wide">Horizontal</option></select></div>';
        echo '<div class="tattoo-option"><label>Upscale</label><label style="display:flex;gap:8px;align-items:center;font-weight:500"><input type="checkbox" name="upscale" value="1" ' . ($isGenerating ? 'disabled' : '') . '> gerar versão 2x</label><small class="muted">Upscale por redimensionamento limpo no servidor.</small></div>';
        echo '</div>';
        echo '<div class="field"><label>Referência de estilo / direção artística</label><input type="text" name="reference_notes" maxlength="600" ' . ($isGenerating ? 'disabled' : '') . ' placeholder="Ex: clima de capa de álbum dos anos 90, luz lateral, fundo sem roubar atenção..."></div>';
        echo '<div class="field"><label>Evitar na imagem</label><input type="text" name="negative_prompt" maxlength="600" ' . ($isGenerating ? 'disabled' : '') . ' placeholder="Ex: sem texto, sem fundo poluído, sem mãos, sem cor..."></div>';
        echo '<div class="tattoo-image-submit-row"><span id="tattooImageWait" ' . ($isGenerating ? '' : 'hidden') . '>' . ($isGenerating ? 'A IA local está criando sua imagem…' : 'Preparando a IA local…') . '</span><button class="btn tattoo-image-generate" type="submit" ' . (empty($localAi['ok']) || $isGenerating ? 'disabled' : '') . '>' . ($isGenerating ? 'Criando…' : 'Gerar imagem') . '</button></div>';
        echo '</form>';
        if ($isGenerating) {
            echo '<div class="tattoo-progress-card"><div class="tattoo-progress-track"><div id="tattooProgressFill" class="tattoo-progress-fill"></div></div><div class="tattoo-progress-meta"><strong id="tattooProgressLabel">Preparando...</strong><span id="tattooProgressPercent">0%</span><span id="tattooProgressTime">0s</span></div></div>';
        }
        echo '</section>';

        if (is_array($result) && !empty($result['image_path'])) {
            $imageUrl = app_asset_url((string)$result['image_path']);
            $upscaledUrl = !empty($result['upscaled_image_path']) ? app_asset_url((string)$result['upscaled_image_path']) : '';
            echo '<section class="tattoo-image-result">';
            echo '<img src="' . h($imageUrl) . '" alt="Imagem gerada para referência de tatuagem">';
            echo '<div><p>' . h((string)$result['prompt']) . '</p><p class="muted">' . h((string)($result['mode'] ?? '')) . ' · ' . h((string)($result['style'] ?? '')) . ' · ' . h((string)($result['model'] ?? '')) . '</p><div class="actions"><a class="btn secondary" href="' . h($imageUrl) . '" download="' . h((string)($result['file_name'] ?? 'referencia-tattoo.jpg')) . '">Baixar imagem</a>';
            if ($upscaledUrl !== '') {
                echo '<a class="btn secondary" href="' . h($upscaledUrl) . '" download="' . h((string)($result['upscaled_file_name'] ?? 'referencia-tattoo-2x.jpg')) . '">Baixar 2x</a>';
            }
            echo '</div></div></section>';
        } else {
            echo '<section class="tattoo-image-empty"><div class="tattoo-image-orb"></div><p>Sua imagem vai aparecer aqui.</p></section>';
        }
        echo '</div>';
        echo '<script>(function(){const form=document.getElementById("tattooImageForm");const wait=document.getElementById("tattooImageWait");if(form){form.addEventListener("submit",()=>{const button=form.querySelector("button[type=submit]");if(button){button.disabled=true;button.textContent="Preparando…";}if(wait){wait.hidden=false;wait.textContent="Preparando a IA local…";}});}';
        if ($isGenerating) {
            echo 'const statusUrl=' . json_encode(app_url('studio_tattoo_image_status'), JSON_UNESCAPED_SLASHES) . ';const startedAt=Date.now();let failures=0;const fill=document.getElementById("tattooProgressFill");const label=document.getElementById("tattooProgressLabel");const percent=document.getElementById("tattooProgressPercent");const time=document.getElementById("tattooProgressTime");function fmt(s){s=Math.max(0,Math.floor(s));const m=Math.floor(s/60);const r=s%60;return m?`${m}min ${r}s`:`${r}s`;}function fakeProgress(data){const elapsed=(Date.now()-startedAt)/1000;const expected=Number(data.expected_seconds||' . (int)($job['expected_seconds'] ?? 300) . ');let pct=Math.min(95,Math.max(7,Math.floor((elapsed/expected)*92)));let msg="Gerando imagem...";if(data.status==="queued"){pct=Math.min(pct,18);msg=data.queue_position>0?`Na fila · posição ${data.queue_position}`:"Na fila da IA local";}else if(elapsed<20){msg="Preparando prompt e modelo...";}else if(pct<80){msg="Renderizando a imagem...";}else{msg="Finalizando e salvando...";}if(fill)fill.style.width=pct+"%";if(percent)percent.textContent=pct+"%";if(label)label.textContent=msg;if(time)time.textContent="Tempo: "+fmt(elapsed);if(wait)wait.textContent=msg+" · "+pct+"%";}const poll=async()=>{try{const response=await fetch(statusUrl,{credentials:"same-origin",cache:"no-store"});const data=await response.json();failures=0;if(data.status==="completed"||data.status==="failed"||data.status==="idle"){if(fill)fill.style.width="100%";if(percent)percent.textContent="100%";location.reload();return;}fakeProgress(data);}catch(error){failures++;if(wait&&failures>2)wait.textContent="A geração continua localmente. Tentando reconectar…";}setTimeout(poll,3000);};fakeProgress({status:"queued"});setTimeout(poll,1200);';
        }
        echo '})();</script>';
    }, flash_get());
    exit;
}

studio_tattoo_image_handle_request();
