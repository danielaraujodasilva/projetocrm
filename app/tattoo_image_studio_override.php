<?php

declare(strict_types=1);

const STUDIO_TATTOO_STENCIL_PROMPT = <<<'PROMPT'
Transform this image into a professional tattoo stencil blueprint optimized for tattoo transfer and black-and-grey planning. The result must preserve the exact anatomy, likeness, proportions, expression, and perspective of the original image. Create a technical stencil system using different line behaviors to communicate depth and shadow information: solid clean lines for main contours and definitive structural edges; dashed or broken lines for soft shadows and secondary transitions; fine directional hatching to describe volume and surface curvature; denser cross-hatching for deeper shadow regions; lighter sparse hatching for soft tonal variation. Line flow must follow facial anatomy and form direction. Preserve skin breaks and negative space for tattoo readability. Shadows must not become solid black masses. Gradients must be translated into intelligent line density with breathable spacing. Keep the stencil clean and readable after thermal transfer onto skin. Visual style: technical tattoo stencil, engraving illustration, anatomical line mapping, black and grey tattoo planning sheet, professional tattoo draft. Pure black ink on white background, no gray wash, no painterly shading, no sketch chaos, no comic style, no vector clipart appearance. The final image must look like a master tattoo artist's construction stencil, where contour lines, dashed shadow guides, and hatching density clearly explain form, depth, and lighting before execution.
PROMPT;

function studio_tattoo_image_choice(string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function studio_tattoo_image_norm(string $text): string
{
    return strtr(mb_strtolower($text, 'UTF-8'), [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
        'é' => 'e', 'ê' => 'e', 'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ç' => 'c',
    ]);
}

function studio_tattoo_image_mode_config(string $mode): array
{
    return $mode === 'fast'
        ? ['width' => 640, 'height' => 896, 'steps' => 8, 'cfg' => 4.0, 'guidance' => 2.8, 'expected' => 120]
        : ['width' => 832, 'height' => 1216, 'steps' => 30, 'cfg' => 5.0, 'guidance' => 3.5, 'expected' => 390];
}

function studio_tattoo_image_style_prompt(string $style): string
{
    return match ($style) {
        'stencil' => 'professional technical tattoo stencil, pure black lines on white, anatomical line mapping, engraving hatching',
        'blackwork' => 'premium blackwork tattoo concept, strong silhouettes, balanced negative space, readable composition',
        'chicano' => 'premium chicano tattoo reference, dramatic black and grey mood, smooth tonal contrast',
        'fineline' => 'fine line tattoo concept, delicate clean linework, elegant minimal detail, readable on skin',
        'oldschool' => 'old school tattoo flash, bold linework, iconic simplified forms, classic composition',
        'reference' => 'clean tattoo design reference, clear subject, useful readable composition',
        default => 'RAW professional photograph, photorealistic, ultra-detailed natural textures, cinematic lighting, sharp focus, rich tonal range',
    };
}

function studio_tattoo_image_subject_guard(string $request): array
{
    $text = studio_tattoo_image_norm($request);
    $subjectMap = [
        'mulher' => 'woman', 'woman' => 'woman', 'garota' => 'woman', 'girl' => 'woman',
        'homem' => 'man', 'man' => 'man', 'rapaz' => 'man',
        'jesus' => 'Jesus', 'cristo' => 'Jesus',
        'caveira' => 'skull', 'skull' => 'skull',
        'anjo' => 'angel', 'angel' => 'angel', 'dragao' => 'dragon', 'dragon' => 'dragon',
        'rosa' => 'rose', 'rose' => 'rose', 'flor' => 'flower', 'flower' => 'flower',
        'relogio' => 'clock', 'clock' => 'clock', 'bussola' => 'compass', 'compass' => 'compass',
        'olho' => 'eye', 'eye' => 'eye',
        'leao' => 'lion', 'lion' => 'lion', 'tigre' => 'tiger', 'tiger' => 'tiger',
        'lobo' => 'wolf', 'wolf' => 'wolf', 'onca' => 'jaguar', 'jaguar' => 'jaguar',
        'aguia' => 'eagle', 'eagle' => 'eagle', 'coruja' => 'owl', 'owl' => 'owl',
        'cobra' => 'snake', 'snake' => 'snake', 'cachorro' => 'dog', 'dog' => 'dog',
    ];
    $detected = [];
    foreach ($subjectMap as $needle => $subject) {
        if (preg_match('/\b' . preg_quote($needle, '/') . 's?\b/u', $text, $match, PREG_OFFSET_CAPTURE)) {
            $detected[$subject] = (int)$match[0][1];
        }
    }
    asort($detected);
    $subjects = array_keys($detected);
    $isSplit = preg_match('/\b(metade|meio|dividid[oa]?|cortad[oa]?|rasgad[oa]?|hibrid[oa]?|half|split|torn|hybrid)\b/u', $text) === 1;
    if ($isSplit && count($subjects) >= 2) {
        $first = $subjects[0];
        $second = $subjects[1];
        $vertical = str_contains($text, 'vertical') ? ' The division must be perfectly vertical.' : '';
        $torn = preg_match('/rasgad|torn|papel/u', $text) === 1
            ? ' Add a narrow irregular white fibrous torn edge only along the central seam, like a photographic collage reveal. Both face halves must remain photorealistic, not drawn on paper.'
            : '';
        return [
            'lock' => '((ONE single front-facing split face:1.35)), ((exactly 50% ' . $first . ' face and 50% ' . $second . ' face:1.5)). '
                . 'Both halves are equally visible and share aligned eyes, nose/muzzle and mouth across one central seam. '
                . 'Not two portraits and not a complete ' . $first . ' or ' . $second . '.'
                . $vertical . $torn
                . ' Tight face portrait only, no full body.',
            'negative' => 'complete ' . $first . ', full ' . $first . ' face, only ' . $first . ', missing ' . $second
                . ', complete ' . $second . ', full ' . $second . ' face, only ' . $second . ', missing ' . $first
                . ', two separate faces, two portraits, side-by-side subjects, uneven split, missing half, clean straight divider, smooth center seam, simple split screen, sketch, drawing, engraving, parchment, paper background',
        ];
    }
    if (count($subjects) >= 2) {
        $required = implode(', ', array_slice($subjects, 0, 4));
        return [
            'lock' => 'CRITICAL MULTI-SUBJECT FIDELITY: include every requested subject with none omitted or replaced: ' . $required
                . '. Preserve the exact spatial relationship, hierarchy, count and interaction stated by the user. '
                . 'Do not turn this into a single-subject image.',
            'negative' => implode(', ', array_map(static fn(string $subject): string => 'missing ' . $subject, array_slice($subjects, 0, 4)))
                . ', omitted subject, replaced subject, single-subject composition',
        ];
    }
    $subjects = [
        'leao' => 'lion', 'lion' => 'lion', 'tigre' => 'tiger', 'tiger' => 'tiger',
        'lobo' => 'wolf', 'wolf' => 'wolf', 'onca' => 'jaguar', 'jaguar' => 'jaguar',
        'aguia' => 'eagle', 'eagle' => 'eagle', 'coruja' => 'owl', 'owl' => 'owl',
        'cobra' => 'snake', 'snake' => 'snake', 'cachorro' => 'dog', 'dog' => 'dog',
    ];
    foreach ($subjects as $needle => $subject) {
        if (preg_match('/\b' . preg_quote($needle, '/') . 's?\b/u', $text)) {
            return [
                'lock' => 'The main subject must remain exactly a real ' . $subject . '. Preserve species, anatomy, count, pose, angle, expression and accessories.',
                'negative' => 'wrong subject, different species, unrelated character',
            ];
        }
    }
    return [
        'lock' => 'Preserve the exact requested subject, identity, anatomy, count, pose, camera angle, expression and accessories.',
        'negative' => 'wrong subject, different subject, unrelated character',
    ];
}

function studio_tattoo_image_translate_strict(string $request): string
{
    $request = trim($request);
    if ($request === '') {
        return '';
    }
    $body = [
        'model' => trim((string)(getenv('LOCAL_IMAGE_PROMPT_MODEL') ?: 'llama3:8b')),
        'stream' => false,
        'think' => false,
        'keep_alive' => 0,
        'messages' => [
            ['role' => 'system', 'content' => 'Translate literally into a concise English image prompt. Preserve every subject, count, spatial relationship, split, side, camera angle and visual metaphor. Do not summarize, explain, add or remove anything. Output only the translated prompt.'],
            ['role' => 'user', 'content' => $request],
        ],
        'options' => ['temperature' => 0, 'num_predict' => 220, 'num_gpu' => 0],
    ];
    $ch = curl_init('http://127.0.0.1:11434/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    $translated = is_array($json) ? trim((string)($json['message']['content'] ?? '')) : '';
    $translated = trim((string)preg_replace('/<think>.*?<\/think>/is', '', $translated), " \n\r\t\"'");
    if (preg_match('/\b(could not|couldn.t|cannot|can.t|unable|sorry)\b/i', $translated)) {
        $translated = '';
    }
    return $status >= 200 && $status < 300 && $translated !== '' ? $translated : $request;
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

function studio_tattoo_image_dimensions(string $mode, string $format): array
{
    $config = studio_tattoo_image_mode_config($mode);
    if ($format === 'square') {
        return $mode === 'fast' ? [640, 640] : [832, 832];
    }
    if ($format === 'wide') {
        return [(int)$config['height'], (int)$config['width']];
    }
    return [(int)$config['width'], (int)$config['height']];
}

function studio_tattoo_image_build_prompt(array $data): string
{
    $prompt = trim((string)($data['prompt'] ?? ''));
    if (mb_strlen($prompt, 'UTF-8') < 4) {
        throw new RuntimeException('Descreva um pouco melhor a imagem que você quer criar.');
    }
    if (mb_strlen($prompt, 'UTF-8') > 4000) {
        throw new RuntimeException('Resuma a descrição em até 4.000 caracteres.');
    }
    $style = studio_tattoo_image_choice((string)($data['style'] ?? 'realistic'), ['realistic', 'stencil', 'blackwork', 'chicano', 'fineline', 'oldschool', 'reference'], 'realistic');
    $format = studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical', 'square', 'wide'], 'vertical');
    $operation = studio_tattoo_image_choice((string)($data['operation'] ?? 'create'), ['create', 'edit', 'stencil', 'variation', 'final'], 'create');
    $guard = studio_tattoo_image_subject_guard($prompt);
    $translated = studio_tattoo_image_translate_strict($prompt);
    $formatHint = match ($format) {
        'square' => 'balanced square composition',
        'wide' => 'horizontal extended composition',
        default => 'strong vertical composition',
    };

    $operationPrompt = match ($operation) {
        'edit' => 'IMAGE-TO-IMAGE LOCAL EDIT. Preserve the exact original composition, identity, face, anatomy, proportions, pose, framing, lighting and perspective. Change only this requested detail: ' . studio_tattoo_image_translate_strict((string)($data['edit_prompt'] ?? '')),
        'stencil' => STUDIO_TATTOO_STENCIL_PROMPT,
        'variation' => 'Create a closely related variation of the source image. Keep the same subject, composition, pose, framing, proportions, light direction, perspective, style and visual idea. Introduce only subtle natural differences.',
        'final' => 'FINALIZATION PASS. Preserve the exact source pose, face, identity, anatomy, composition, silhouette, lighting and perspective. Do not redesign it. Improve realism, coherent detail, texture and print readiness only.',
        default => 'Create a standalone tattoo design reference.',
    };

    return $guard['lock'] . ' CORE REQUEST: ' . $translated . '. '
        . $operationPrompt . ' '
        . studio_tattoo_image_style_prompt($style) . ', ' . $formatHint . '. '
        . 'No user interface, caption, watermark, logo, frame or random typography. '
        . 'Follow the core request literally; do not replace a composite subject with only one of its halves.';
}

function studio_tattoo_image_body(array $data, string $mode): array
{
    $format = studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical', 'square', 'wide'], 'vertical');
    [$width, $height] = studio_tattoo_image_dimensions($mode, $format);
    $config = studio_tattoo_image_mode_config($mode);
    $guard = studio_tattoo_image_subject_guard((string)($data['prompt'] ?? ''));
    $isComposite = str_starts_with((string)$guard['lock'], '((ONE single front-facing split face:1.35))');
    if ($isComposite && $mode === 'fast') {
        $config['steps'] = 14;
        $config['cfg'] = 6.0;
    }
    $negative = 'low quality, blurry, malformed anatomy, duplicated subject, altered identity, watermark, signature, text, logo, border, frame, user interface, ' . $guard['negative'];
    if (trim((string)($data['negative_prompt'] ?? '')) !== '') {
        $negative .= ', ' . trim((string)$data['negative_prompt']);
    }
    $body = [
        'prompt' => studio_tattoo_image_build_prompt($data),
        'negative_prompt' => $negative,
        'clip_skip' => -1,
        'width' => $width,
        'height' => $height,
        'seed' => -1,
        'batch_count' => 1,
        'auto_resize_ref_image' => true,
        'sample_params' => [
            'scheduler' => 'karras',
            'sample_method' => 'dpm++2m',
            'sample_steps' => (int)$config['steps'],
            'guidance' => ['txt_cfg' => (float)$config['cfg'], 'distilled_guidance' => (float)$config['guidance']],
        ],
        'vae_tiling_params' => ['enabled' => true, 'tile_size_x' => 512, 'tile_size_y' => 512, 'target_overlap' => 0.25],
        'output_format' => 'jpeg',
        'output_compression' => 94,
    ];

    $sourcePath = studio_tattoo_image_absolute_from_relative((string)($data['source_image_path'] ?? ''));
    if ($sourcePath !== '') {
        $binary = @file_get_contents($sourcePath);
        if (is_string($binary) && $binary !== '') {
            $base64 = base64_encode($binary);
            /*
             * stable-diffusion.cpp usa init_image no contrato nativo atual.
             * image/source_image e denoising_strength ficam como aliases de
             * compatibilidade; versões que não os conhecem simplesmente ignoram.
             */
            $body['init_image'] = $base64;
            $body['image'] = $base64;
            $body['source_image'] = $base64;
            $strength = max(0.08, min(0.75, (float)($data['strength'] ?? 0.28)));
            $body['strength'] = $strength;
            $body['denoising_strength'] = $strength;
        }
    }
    return $body;
}

function studio_tattoo_image_history_normalize(array $item): array
{
    $historyId = trim((string)($item['history_id'] ?? '')) ?: bin2hex(random_bytes(8));
    $mode = studio_tattoo_image_choice((string)($item['mode'] ?? 'fast'), ['fast', 'final'], 'fast');
    $type = studio_tattoo_image_choice((string)($item['image_type'] ?? ($mode === 'final' ? 'final' : 'preview')), ['preview', 'final', 'edit', 'stencil', 'upscale', 'variation'], 'preview');
    return array_merge($item, [
        'history_id' => $historyId,
        'parent_id' => trim((string)($item['parent_id'] ?? '')),
        'project_id' => trim((string)($item['project_id'] ?? '')),
        'image_type' => $type,
        'prompt' => trim((string)($item['prompt'] ?? '')),
        'edit_prompt' => trim((string)($item['edit_prompt'] ?? '')),
        'style' => trim((string)($item['style'] ?? 'realistic')) ?: 'realistic',
        'mode' => $mode,
        'format' => trim((string)($item['format'] ?? 'vertical')) ?: 'vertical',
        'source_image_path' => trim((string)($item['source_image_path'] ?? '')),
        'image_path' => trim((string)($item['image_path'] ?? '')),
        'upscaled_image_path' => trim((string)($item['upscaled_image_path'] ?? '')),
        'created_at' => (string)($item['created_at'] ?? $item['generated_at'] ?? date('Y-m-d H:i:s')),
        'favorite' => !empty($item['favorite']),
    ]);
}

function studio_tattoo_image_history(): array
{
    $raw = $_SESSION['studio_tattoo_image_history'] ?? [];
    $items = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    $normalized = array_map('studio_tattoo_image_history_normalize', $items);
    $byPath = [];
    foreach ($normalized as $item) {
        if ($item['image_path'] !== '') {
            $byPath[$item['image_path']] = $item['history_id'];
        }
    }
    $byId = [];
    foreach ($normalized as &$item) {
        if ($item['parent_id'] === '' && $item['source_image_path'] !== '' && isset($byPath[$item['source_image_path']])) {
            $item['parent_id'] = $byPath[$item['source_image_path']];
        }
        $byId[$item['history_id']] = $item;
    }
    unset($item);
    for ($pass = 0; $pass < 3; $pass++) {
        foreach ($normalized as &$item) {
            if ($item['project_id'] === '') {
                $parent = $byId[$item['parent_id']] ?? null;
                if (is_array($parent) && !empty($parent['project_id'])) {
                    $item['project_id'] = (string)$parent['project_id'];
                } elseif ($item['parent_id'] === '' || !isset($byId[$item['parent_id']])) {
                    $item['project_id'] = 'project_' . $item['history_id'];
                }
                $byId[$item['history_id']] = $item;
            }
        }
        unset($item);
    }
    foreach ($normalized as &$item) {
        if ($item['project_id'] === '') {
            $item['project_id'] = 'project_' . $item['history_id'];
        }
    }
    unset($item);
    $_SESSION['studio_tattoo_image_history'] = $normalized;
    return $normalized;
}

function studio_tattoo_image_history_save(array $items): void
{
    $_SESSION['studio_tattoo_image_history'] = array_slice(array_values($items), 0, 80);
}

function studio_tattoo_image_history_add(array $result): array
{
    $result = studio_tattoo_image_history_normalize($result);
    $items = array_values(array_filter(studio_tattoo_image_history(), static fn(array $item): bool => $item['history_id'] !== $result['history_id']));
    array_unshift($items, $result);
    studio_tattoo_image_history_save($items);
    return $result;
}

function studio_tattoo_image_history_find(string $id): ?array
{
    foreach (studio_tattoo_image_history() as $item) {
        if (hash_equals((string)$item['history_id'], $id)) {
            return $item;
        }
    }
    return null;
}

function studio_tattoo_image_history_toggle_favorite(string $id): array
{
    $items = studio_tattoo_image_history();
    $selected = [];
    foreach ($items as &$item) {
        if (hash_equals((string)$item['history_id'], $id)) {
            $item['favorite'] = empty($item['favorite']);
            $selected = $item;
        }
    }
    unset($item);
    if (!$selected) {
        throw new RuntimeException('Não encontrei essa imagem.');
    }
    studio_tattoo_image_history_save($items);
    return $selected;
}

function studio_tattoo_image_upscale_jpeg(string $sourcePath, int $factor = 4): string
{
    $factor = max(2, min(4, $factor));
    if (!function_exists('imagecreatefromjpeg') || !is_file($sourcePath)) {
        return '';
    }
    $source = @imagecreatefromjpeg($sourcePath);
    if (!$source) {
        return '';
    }
    $width = imagesx($source);
    $height = imagesy($source);
    $target = imagecreatetruecolor($width * $factor, $height * $factor);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $width * $factor, $height * $factor, $width, $height);
    $targetPath = preg_replace('/\.jpe?g$/i', '_' . $factor . 'x.jpg', $sourcePath) ?: $sourcePath . '_' . $factor . 'x.jpg';
    imagejpeg($target, $targetPath, 95);
    imagedestroy($source);
    imagedestroy($target);
    return is_file($targetPath) ? basename($targetPath) : '';
}

function studio_tattoo_image_apply_torn_seam(string $sourcePath): bool
{
    if (!function_exists('imagecreatefromjpeg') || !is_file($sourcePath)) {
        return false;
    }
    $image = @imagecreatefromjpeg($sourcePath);
    if (!$image) {
        return false;
    }
    $width = imagesx($image);
    $height = imagesy($image);
    if ($width < 200 || $height < 200) {
        imagedestroy($image);
        return false;
    }

    $center = (int)round($width / 2);
    $halfBand = max(5, (int)round($width * 0.012));
    $step = max(12, (int)round($height / 70));
    $left = [];
    $right = [];
    for ($y = -$step; $y <= $height + $step; $y += $step) {
        $noiseA = (int)round(sin($y * 0.071) * $halfBand * 0.65 + sin($y * 0.019) * $halfBand * 0.45);
        $noiseB = (int)round(cos($y * 0.059) * $halfBand * 0.6 + sin($y * 0.031) * $halfBand * 0.4);
        $left[] = [$center - $halfBand + $noiseA, $y];
        $right[] = [$center + $halfBand + $noiseB, $y];
    }
    $polygon = [];
    foreach ($left as $point) {
        $polygon[] = $point[0];
        $polygon[] = $point[1];
    }
    foreach (array_reverse($right) as $point) {
        $polygon[] = $point[0];
        $polygon[] = $point[1];
    }

    $paper = imagecolorallocate($image, 248, 246, 239);
    $paperLight = imagecolorallocate($image, 255, 254, 250);
    $edgeShadow = imagecolorallocate($image, 112, 104, 98);
    imagefilledpolygon($image, $polygon, count($polygon) / 2, $paper);
    for ($i = 1, $count = count($left); $i < $count; $i++) {
        imageline($image, $left[$i - 1][0] - 2, $left[$i - 1][1], $left[$i][0] - 2, $left[$i][1], $edgeShadow);
        imageline($image, $right[$i - 1][0] + 2, $right[$i - 1][1], $right[$i][0] + 2, $right[$i][1], $edgeShadow);
        imageline($image, $left[$i - 1][0], $left[$i - 1][1], $left[$i][0], $left[$i][1], $paperLight);
        imageline($image, $right[$i - 1][0], $right[$i - 1][1], $right[$i][0], $right[$i][1], $paperLight);
        if ($i % 3 === 0) {
            $fiber = max(3, (int)round($halfBand * (0.7 + (($i % 5) * 0.13))));
            imageline($image, $left[$i][0], $left[$i][1], $left[$i][0] - $fiber, $left[$i][1] + ($i % 2 ? 3 : -3), $paperLight);
            imageline($image, $right[$i][0], $right[$i][1], $right[$i][0] + $fiber, $right[$i][1] + ($i % 2 ? -3 : 3), $paperLight);
        }
    }
    $saved = imagejpeg($image, $sourcePath, 95);
    imagedestroy($image);
    return $saved;
}

function studio_tattoo_image_start(array $studio, array $data): array
{
    if (!empty($_SESSION['studio_tattoo_image_job'])) {
        throw new RuntimeException('Já existe uma imagem sendo criada. Aguarde a conclusão.');
    }
    if (empty(studio_local_image_ai_status()['ok'])) {
        throw new RuntimeException('A IA local ainda está iniciando. Aguarde um pouco.');
    }
    $mode = studio_tattoo_image_choice((string)($data['mode'] ?? 'fast'), ['fast', 'final'], 'fast');
    $operation = studio_tattoo_image_choice((string)($data['operation'] ?? 'create'), ['create', 'edit', 'stencil', 'variation', 'final'], 'create');
    $result = studio_local_image_ai_request('POST', '/sdcpp/v1/img_gen', studio_tattoo_image_body($data, $mode), 120);
    if (empty($result['ok'])) {
        throw new RuntimeException((string)($result['error'] ?? 'Não foi possível iniciar a geração local.'));
    }
    $jobId = trim((string)($result['json']['id'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $jobId)) {
        throw new RuntimeException('A IA local não devolveu um identificador válido.');
    }
    $config = studio_tattoo_image_mode_config($mode);
    $normalizedPrompt = studio_tattoo_image_norm((string)($data['prompt'] ?? ''));
    $compositeGuard = studio_tattoo_image_subject_guard((string)($data['prompt'] ?? ''));
    $isComposite = str_starts_with((string)$compositeGuard['lock'], '((ONE single front-facing split face:1.35))');
    if ($mode === 'fast' && $isComposite) {
        $config['expected'] = 210;
    }
    return [
        'id' => $jobId,
        'history_id' => bin2hex(random_bytes(8)),
        'parent_id' => trim((string)($data['parent_id'] ?? '')),
        'project_id' => trim((string)($data['project_id'] ?? '')) ?: 'project_' . bin2hex(random_bytes(8)),
        'image_type' => studio_tattoo_image_choice((string)($data['image_type'] ?? ($mode === 'final' ? 'final' : 'preview')), ['preview', 'final', 'edit', 'stencil', 'variation'], 'preview'),
        'prompt' => trim((string)($data['prompt'] ?? '')),
        'edit_prompt' => trim((string)($data['edit_prompt'] ?? '')),
        'style' => studio_tattoo_image_choice((string)($data['style'] ?? 'realistic'), ['realistic', 'stencil', 'blackwork', 'chicano', 'fineline', 'oldschool', 'reference'], 'realistic'),
        'mode' => $mode,
        'format' => studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical', 'square', 'wide'], 'vertical'),
        'source_image_path' => trim((string)($data['source_image_path'] ?? '')),
        'negative_prompt' => trim((string)($data['negative_prompt'] ?? '')),
        'operation' => $operation,
        'upscale' => !empty($data['upscale']),
        'upscale_factor' => max(2, min(4, (int)($data['upscale_factor'] ?? 4))),
        'favorite' => false,
        'apply_torn_seam' => $isComposite && preg_match('/rasgad|papel|torn/u', $normalizedPrompt) === 1,
        'started_at' => date('Y-m-d H:i:s'),
        'expected_seconds' => (int)$config['expected'],
        'model' => 'RealVisXL 5.0 local',
    ];
}

function studio_tattoo_image_poll(array $studio, array $job): array
{
    $jobId = trim((string)($job['id'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $jobId)) {
        return ['status' => 'failed', 'error' => 'Geração local inválida.'];
    }
    $response = studio_local_image_ai_request('GET', '/sdcpp/v1/jobs/' . rawurlencode($jobId), null, 10);
    if (empty($response['ok'])) {
        return ['status' => 'waiting', 'expected_seconds' => (int)($job['expected_seconds'] ?? 300)];
    }
    $json = (array)$response['json'];
    $status = (string)($json['status'] ?? 'waiting');
    if ($status !== 'completed') {
        $error = is_array($json['error'] ?? null) ? trim((string)($json['error']['message'] ?? '')) : '';
        return [
            'status' => in_array($status, ['failed', 'cancelled'], true) ? 'failed' : $status,
            'queue_position' => (int)($json['queue_position'] ?? 0),
            'expected_seconds' => (int)($job['expected_seconds'] ?? 300),
            'error' => $error,
        ];
    }
    $base64 = trim((string)($json['result']['images'][0]['b64_json'] ?? ''));
    $binary = $base64 !== '' ? base64_decode($base64, true) : false;
    if (!is_string($binary) || $binary === '') {
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
        return ['status' => 'failed', 'error' => 'Não foi possível salvar a imagem.'];
    }
    if (!empty($job['apply_torn_seam'])) {
        studio_tattoo_image_apply_torn_seam($absolutePath);
    }
    $upscaledFile = !empty($job['upscale']) ? studio_tattoo_image_upscale_jpeg($absolutePath, (int)($job['upscale_factor'] ?? 4)) : '';
    $payload = studio_tattoo_image_history_add([
        'history_id' => (string)($job['history_id'] ?? bin2hex(random_bytes(8))),
        'parent_id' => (string)($job['parent_id'] ?? ''),
        'project_id' => (string)($job['project_id'] ?? ''),
        'image_type' => (string)($job['image_type'] ?? 'preview'),
        'prompt' => (string)($job['prompt'] ?? ''),
        'edit_prompt' => (string)($job['edit_prompt'] ?? ''),
        'style' => (string)($job['style'] ?? 'realistic'),
        'mode' => (string)($job['mode'] ?? 'fast'),
        'format' => (string)($job['format'] ?? 'vertical'),
        'source_image_path' => (string)($job['source_image_path'] ?? ''),
        'image_path' => 'storage/tattoo-images/' . $safeStudio . '/' . $fileName,
        'upscaled_image_path' => $upscaledFile !== '' ? 'storage/tattoo-images/' . $safeStudio . '/' . $upscaledFile : '',
        'file_name' => $fileName,
        'upscaled_file_name' => $upscaledFile,
        'created_at' => date('Y-m-d H:i:s'),
        'favorite' => !empty($job['favorite']),
        'model' => 'RealVisXL 5.0 local',
    ]);
    return ['status' => 'completed', 'result' => $payload];
}

function studio_tattoo_image_derived_data(array $source, string $operation, array $post = []): array
{
    $editPrompt = trim((string)($post['edit_prompt'] ?? ''));
    $strength = match ($operation) {
        'final' => 0.18,
        'variation' => 0.32,
        'stencil' => 0.55,
        'edit' => match ((string)($post['intensity'] ?? 'medium')) {
            'low' => 0.18, 'high' => 0.48, default => 0.32,
        },
        default => 0.28,
    };
    if ($operation === 'edit' && str_starts_with(studio_tattoo_image_subject_guard($editPrompt)['lock'], '((ONE single front-facing split face:1.35))')) {
        // Corrigir um sujeito inteiro para uma composição 50/50 exige liberdade
        // estrutural maior que uma edição localizada comum.
        $strength = max($strength, 0.58);
    }
    return [
        'parent_id' => (string)$source['history_id'],
        'project_id' => (string)$source['project_id'],
        'prompt' => (string)$source['prompt'],
        'edit_prompt' => $editPrompt,
        'style' => $operation === 'stencil' ? 'stencil' : (string)$source['style'],
        'mode' => $operation === 'variation' ? 'fast' : 'final',
        'format' => (string)$source['format'],
        'source_image_path' => (string)$source['image_path'],
        'negative_prompt' => (string)($source['negative_prompt'] ?? ''),
        'operation' => $operation,
        'image_type' => $operation,
        'strength' => $strength,
        'upscale' => in_array($operation, ['final', 'stencil'], true),
        'upscale_factor' => 4,
    ];
}

function studio_tattoo_image_create_upscale_version(array $source): array
{
    $path = studio_tattoo_image_absolute_from_relative((string)$source['image_path']);
    if ($path === '') {
        throw new RuntimeException('Não encontrei o arquivo original para ampliar.');
    }
    $file = studio_tattoo_image_upscale_jpeg($path, 4);
    if ($file === '') {
        throw new RuntimeException('Não consegui ampliar essa imagem. Verifique a extensão GD.');
    }
    $directory = trim(dirname((string)$source['image_path']), '/\\.');
    return studio_tattoo_image_history_add([
        'history_id' => bin2hex(random_bytes(8)),
        'parent_id' => (string)$source['history_id'],
        'project_id' => (string)$source['project_id'],
        'image_type' => 'upscale',
        'prompt' => (string)$source['prompt'],
        'edit_prompt' => '',
        'style' => (string)$source['style'],
        'mode' => 'final',
        'format' => (string)$source['format'],
        'source_image_path' => (string)$source['image_path'],
        'image_path' => $directory . '/' . $file,
        'upscaled_image_path' => $directory . '/' . $file,
        'file_name' => $file,
        'upscaled_file_name' => $file,
        'created_at' => date('Y-m-d H:i:s'),
        'favorite' => false,
        'model' => 'Upscale local 4x',
    ]);
}

function studio_tattoo_image_render_actions(array $item, bool $busy): void
{
    $id = h((string)$item['history_id']);
    $disabled = $busy ? ' disabled' : '';
    $action = static function (string $name, string $label, string $class = 'secondary') use ($id, $disabled): void {
        echo '<form method="post" class="ti-inline">' . csrf_field()
            . '<input type="hidden" name="action" value="' . h($name) . '">'
            . '<input type="hidden" name="history_id" value="' . $id . '">'
            . '<button class="btn tiny ' . h($class) . '" type="submit"' . $disabled . '>' . h($label) . '</button></form>';
    };
    echo '<div class="ti-card-actions">';
    $action('finalize_tattoo_reference', 'Final + upscale', '');
    $action('upscale_tattoo_reference', 'Upscale 4x');
    $action('stencil_tattoo_reference', 'Transformar em decalque');
    echo '<button class="btn tiny secondary ti-edit-open" type="button" data-history-id="' . $id . '"' . $disabled . '>Editar detalhe</button>';
    $action('variation_tattoo_reference', 'Criar parecida');
    $action('favorite_tattoo_reference', !empty($item['favorite']) ? '★ Favorita' : '☆ Favoritar');
    echo '</div>';
}

function studio_tattoo_image_render_card(array $item, array $byId, string $selectedId, bool $busy, int $depth): void
{
    $imageUrl = app_asset_url((string)$item['image_path']);
    $type = (string)$item['image_type'];
    $parent = $byId[$item['parent_id']] ?? null;
    $selected = $selectedId === (string)$item['history_id'];
    echo '<article class="ti-version-card ti-type-' . h($type) . ($selected ? ' is-selected' : '') . (!empty($item['favorite']) ? ' is-favorite' : '') . '" style="--depth:' . min(5, $depth) . '">';
    echo '<a class="ti-thumb" href="' . h(app_url('studio_tattoo_images', ['selected' => (string)$item['history_id']])) . '"><img src="' . h($imageUrl) . '" alt="Versão ' . h($type) . '">';
    if (!empty($item['favorite'])) {
        echo '<span class="ti-favorite-star">★</span>';
    }
    echo '</a><div class="ti-version-body"><div class="ti-version-head"><span class="ti-badge ti-badge-' . h($type) . '">' . h($type) . '</span><time>' . h(date('d/m H:i', strtotime((string)$item['created_at']) ?: time())) . '</time></div>';
    if (is_array($parent)) {
        echo '<div class="ti-parent">↳ veio de <strong>' . h((string)$parent['image_type']) . '</strong> #' . h(substr((string)$parent['history_id'], 0, 6)) . '</div>';
    } else {
        echo '<div class="ti-parent">Raiz do projeto #' . h(substr((string)$item['project_id'], -6)) . '</div>';
    }
    echo '<p>' . h($item['edit_prompt'] !== '' ? $item['edit_prompt'] : (string)$item['prompt']) . '</p>';
    studio_tattoo_image_render_actions($item, $busy);
    echo '</div></article>';
}

function studio_tattoo_image_handle_request(): void
{
    $page = (string)($_GET['page'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $actions = [
        'generate_tattoo_reference', 'finalize_tattoo_reference', 'upscale_tattoo_reference',
        'stencil_tattoo_reference', 'edit_tattoo_reference', 'variation_tattoo_reference',
        'favorite_tattoo_reference',
    ];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($action, $actions, true)) {
        $studio = require_studio();
        csrf_verify();
        try {
            if ($action === 'generate_tattoo_reference') {
                $data = $_POST;
                $data['operation'] = 'create';
                $data['image_type'] = (string)($data['mode'] ?? 'fast') === 'final' ? 'final' : 'preview';
                $_SESSION['studio_tattoo_image_job'] = studio_tattoo_image_start($studio, $data);
                flash_set('success', 'A primeira versão do projeto entrou na fila.');
            } else {
                $source = studio_tattoo_image_history_find((string)($_POST['history_id'] ?? ''));
                if (!$source) {
                    throw new RuntimeException('Não encontrei essa versão no histórico.');
                }
                if ($action === 'favorite_tattoo_reference') {
                    $selected = studio_tattoo_image_history_toggle_favorite((string)$source['history_id']);
                    $_SESSION['studio_tattoo_image_result'] = $selected;
                    flash_set('success', !empty($selected['favorite']) ? 'Versão favoritada.' : 'Versão removida dos favoritos.');
                } elseif ($action === 'upscale_tattoo_reference') {
                    $selected = studio_tattoo_image_create_upscale_version($source);
                    $_SESSION['studio_tattoo_image_result'] = $selected;
                    flash_set('success', 'Nova versão 4x criada.');
                } else {
                    $operation = match ($action) {
                        'finalize_tattoo_reference' => 'final',
                        'stencil_tattoo_reference' => 'stencil',
                        'edit_tattoo_reference' => 'edit',
                        default => 'variation',
                    };
                    if ($operation === 'edit' && mb_strlen(trim((string)($_POST['edit_prompt'] ?? '')), 'UTF-8') < 4) {
                        throw new RuntimeException('Explique o detalhe que deseja alterar.');
                    }
                    $_SESSION['studio_tattoo_image_job'] = studio_tattoo_image_start($studio, studio_tattoo_image_derived_data($source, $operation, $_POST));
                    flash_set('success', match ($operation) {
                        'final' => 'Finalização e upscale iniciados.',
                        'stencil' => 'Transformação em decalque iniciada.',
                        'edit' => 'Edição localizada iniciada.',
                        default => 'Variação parecida iniciada.',
                    });
                }
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

    if ($page !== 'studio_tattoo_images') {
        return;
    }

    $studio = require_studio();
    render_studio_shell('Editor de projetos', 'Crie, derive, finalize e prepare o decalque de cada arte.', 'tattoo_images', function () use ($studio): void {
        $localAi = studio_local_image_ai_status();
        $job = $_SESSION['studio_tattoo_image_job'] ?? null;
        $busy = is_array($job) && !empty($job['id']);
        $history = studio_tattoo_image_history();
        $byId = [];
        foreach ($history as $item) {
            $byId[(string)$item['history_id']] = $item;
        }
        $requestedSelected = trim((string)($_GET['selected'] ?? ''));
        if ($requestedSelected !== '' && isset($byId[$requestedSelected])) {
            $_SESSION['studio_tattoo_selected_id'] = $requestedSelected;
            $_SESSION['studio_tattoo_image_result'] = $byId[$requestedSelected];
        }
        $selectedId = (string)($_SESSION['studio_tattoo_selected_id'] ?? '');
        $selected = $byId[$selectedId] ?? ($_SESSION['studio_tattoo_image_result'] ?? ($history[0] ?? null));
        if (is_array($selected)) {
            $selectedId = (string)($selected['history_id'] ?? '');
            $_SESSION['studio_tattoo_selected_id'] = $selectedId;
        }
        $prompt = trim((string)($_SESSION['studio_tattoo_image_prompt'] ?? $job['prompt'] ?? $selected['prompt'] ?? ''));
        unset($_SESSION['studio_tattoo_image_prompt']);

        echo <<<'CSS'
<style>
.ti-editor{--ink:#111827;--muted:#667085;--line:#e4e7ec;--accent:#b4235a;--accent2:#7f1d4d}.ti-hero{align-items:center;background:linear-gradient(125deg,#130d15,#2a1121 58%,#5c183b);border-radius:24px;color:#fff;display:flex;justify-content:space-between;margin-bottom:18px;overflow:hidden;padding:20px 24px;position:relative}.ti-hero:after{background:radial-gradient(circle,rgba(255,255,255,.2),transparent 66%);content:"";height:220px;position:absolute;right:-50px;top:-105px;width:220px}.ti-hero h2{color:#fff;font-size:clamp(25px,3vw,38px);line-height:1;margin:4px 0 6px}.ti-hero p{color:#f4dce8;margin:0}.ti-kicker{color:#f8a9ca;font-size:11px;font-weight:900;letter-spacing:.14em}.ti-live{align-items:center;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:999px;display:flex;font-size:12px;font-weight:800;gap:8px;padding:8px 12px;white-space:nowrap;z-index:1}.ti-live i{background:#52d273;border-radius:50%;box-shadow:0 0 0 5px rgba(82,210,115,.15);height:8px;width:8px}.ti-workspace{display:grid;gap:18px;grid-template-columns:minmax(310px,430px) minmax(0,1fr)}.ti-panel{background:#fff;border:1px solid var(--line);border-radius:22px;box-shadow:0 15px 45px rgba(16,24,40,.07);padding:18px}.ti-panel h3{margin:0 0 4px}.ti-form textarea,.ti-form input[type=text],.ti-form select{width:100%}.ti-options{display:grid;gap:10px;grid-template-columns:repeat(3,1fr)}.ti-option{background:#f9fafb;border:1px solid var(--line);border-radius:14px;padding:10px}.ti-option label{display:block;font-size:11px;font-weight:900;margin-bottom:5px;text-transform:uppercase}.ti-generate{align-items:center;display:flex;gap:10px;justify-content:space-between;margin-top:14px}.ti-stage{background:linear-gradient(145deg,#161219,#08070a);border-radius:18px;display:grid;min-height:520px;overflow:hidden;place-items:center;position:relative}.ti-stage img{height:100%;max-height:680px;object-fit:contain;width:100%}.ti-stage-empty{color:#cbd5e1;max-width:310px;text-align:center}.ti-stage-meta{background:linear-gradient(transparent,rgba(0,0,0,.88));bottom:0;color:#fff;left:0;padding:55px 18px 16px;position:absolute;right:0}.ti-stage-meta p{color:#e5e7eb;margin:7px 0}.ti-badge{border-radius:999px;display:inline-flex;font-size:10px;font-weight:900;letter-spacing:.06em;padding:5px 8px;text-transform:uppercase}.ti-badge-preview{background:#e0f2fe;color:#075985}.ti-badge-final{background:#dcfce7;color:#166534}.ti-badge-edit{background:#fef3c7;color:#92400e}.ti-badge-stencil{background:#111827;color:#fff}.ti-badge-upscale{background:#ede9fe;color:#5b21b6}.ti-badge-variation{background:#fce7f3;color:#9d174d}.ti-progress{background:#fff;border:1px solid #f0d3df;border-radius:18px;margin-top:14px;padding:14px}.ti-progress-track{background:#f1e7ec;border-radius:999px;height:12px;overflow:hidden}.ti-progress-fill{background:linear-gradient(90deg,var(--accent),#ef7da9);height:100%;transition:width .5s;width:5%}.ti-progress-meta{display:flex;font-size:12px;gap:10px;justify-content:space-between;margin-top:8px}.ti-history{margin-top:18px}.ti-history-head{align-items:end;display:flex;justify-content:space-between;margin-bottom:14px}.ti-history-head h2{margin:0}.ti-project{border-left:2px solid #ead4de;margin:0 0 22px;padding-left:16px}.ti-project-title{align-items:center;display:flex;gap:10px;margin:0 0 12px}.ti-project-title strong{font-size:14px}.ti-tree{display:grid;gap:12px}.ti-tree-node{margin-left:calc(min(var(--depth),3) * 26px);position:relative}.ti-tree-node:before{border-bottom:2px solid #ead4de;border-left:2px solid #ead4de;border-radius:0 0 0 10px;content:"";height:26px;left:-18px;position:absolute;top:-9px;width:14px}.ti-version-card{background:#fff;border:1px solid var(--line);border-radius:18px;display:grid;grid-template-columns:minmax(155px,240px) 1fr;overflow:hidden;transition:.2s}.ti-version-card:hover{box-shadow:0 14px 35px rgba(16,24,40,.09);transform:translateY(-1px)}.ti-version-card.is-selected{border-color:var(--accent);box-shadow:0 0 0 3px rgba(180,35,90,.1)}.ti-version-card.is-favorite{background:linear-gradient(120deg,#fff,#fff9e8)}.ti-thumb{background:#111;min-height:210px;position:relative}.ti-thumb img{height:100%;object-fit:cover;position:absolute;width:100%}.ti-favorite-star{background:#fff3c4;border-radius:999px;color:#b7791f;font-size:18px;position:absolute;right:8px;top:8px;padding:3px 8px}.ti-version-body{padding:14px}.ti-version-head{align-items:center;display:flex;justify-content:space-between}.ti-version-head time,.ti-parent{color:var(--muted);font-size:11px}.ti-parent{margin-top:8px}.ti-version-body p{display:-webkit-box;font-size:13px;line-height:1.4;margin:9px 0;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2}.ti-card-actions{display:flex;flex-wrap:wrap;gap:6px}.ti-inline{display:inline}.ti-card-actions .btn{margin:0}.ti-empty-history{border:1px dashed #d0d5dd;border-radius:18px;color:var(--muted);padding:32px;text-align:center}.ti-dialog{border:0;border-radius:22px;box-shadow:0 28px 90px rgba(0,0,0,.28);max-width:520px;padding:0;width:calc(100% - 28px)}.ti-dialog::backdrop{background:rgba(10,7,10,.68);backdrop-filter:blur(4px)}.ti-dialog-body{padding:22px}.ti-dialog-actions{display:flex;gap:10px;justify-content:flex-end}.ti-dialog textarea,.ti-dialog select{width:100%}@media(max-width:1000px){.ti-workspace{grid-template-columns:1fr}.ti-stage{min-height:430px}}@media(max-width:680px){.ti-hero{align-items:flex-start;flex-direction:column;gap:14px;padding:18px}.ti-options{grid-template-columns:1fr}.ti-panel{padding:13px}.ti-stage{min-height:390px}.ti-history-head{align-items:flex-start;flex-direction:column;gap:5px}.ti-project{padding-left:9px}.ti-tree-node{margin-left:calc(min(var(--depth),2) * 10px)}.ti-tree-node:before{display:none}.ti-version-card{display:block}.ti-thumb{display:block;min-height:310px}.ti-thumb img{position:absolute}.ti-version-body{padding:12px}.ti-card-actions{display:grid;grid-template-columns:1fr 1fr}.ti-card-actions .btn,.ti-inline{width:100%}.ti-card-actions .btn{font-size:11px;padding:8px 6px}.ti-generate{align-items:stretch;flex-direction:column}.ti-generate .btn{width:100%}}
</style>
CSS;

        echo '<div class="ti-editor"><header class="ti-hero"><div><span class="ti-kicker">EDITOR DE PROJETOS COM IA</span><h2>Da ideia ao decalque.</h2><p>Crie uma versão, refine detalhes e preserve cada etapa do projeto.</p></div><div class="ti-live"><i></i>' . (empty($localAi['ok']) ? 'IA local iniciando' : 'RealVisXL local ativo') . '</div></header>';
        echo '<div class="ti-workspace"><section class="ti-panel"><h3>Novo projeto</h3><p class="muted">Comece com uma prévia rápida. Depois evolua a imagem escolhida.</p>';
        echo '<form class="ti-form" id="tattooImageForm" method="post">' . csrf_field() . '<input type="hidden" name="action" value="generate_tattoo_reference">';
        echo '<div class="field"><label>Descreva a arte</label><textarea name="prompt" rows="7" maxlength="4000" required' . ($busy ? ' disabled' : '') . ' placeholder="Ex.: um leão frontal emergindo da floresta, olhar intenso, luz lateral dramática...">' . h($prompt) . '</textarea></div>';
        echo '<div class="ti-options"><div class="ti-option"><label>Modo</label><select name="mode"><option value="fast">Prévia rápida</option><option value="final">Final direto</option></select></div><div class="ti-option"><label>Estilo</label><select name="style"><option value="realistic">Realista</option><option value="blackwork">Blackwork</option><option value="chicano">Chicano</option><option value="fineline">Fine line</option><option value="oldschool">Old school</option><option value="reference">Referência limpa</option></select></div><div class="ti-option"><label>Formato</label><select name="format"><option value="vertical">Vertical</option><option value="square">Quadrado</option><option value="wide">Horizontal</option></select></div></div>';
        echo '<div class="field"><label>Evitar (opcional)</label><input type="text" name="negative_prompt" maxlength="600" placeholder="Ex.: sem texto, sem fundo poluído"></div>';
        echo '<div class="ti-generate"><span class="muted" id="tattooImageWait"' . ($busy ? '' : ' hidden') . '>' . ($busy ? 'A IA está trabalhando neste projeto…' : '') . '</span><button class="btn" type="submit"' . (empty($localAi['ok']) || $busy ? ' disabled' : '') . '>' . ($busy ? 'Criando…' : 'Gerar primeira versão') . '</button></div></form>';
        if ($busy) {
            echo '<div class="ti-progress"><div class="ti-progress-track"><div id="tattooProgressFill" class="ti-progress-fill"></div></div><div class="ti-progress-meta"><strong id="tattooProgressLabel">Preparando…</strong><span id="tattooProgressPercent">5%</span><span id="tattooProgressTime">0s</span></div></div>';
        }
        echo '</section><section class="ti-panel"><h3>Imagem selecionada</h3><p class="muted">As ações abaixo sempre criam uma nova versão.</p><div class="ti-stage">';
        if (is_array($selected) && !empty($selected['image_path'])) {
            $selectedUrl = app_asset_url((string)$selected['image_path']);
            echo '<img src="' . h($selectedUrl) . '" alt="Imagem selecionada"><div class="ti-stage-meta"><span class="ti-badge ti-badge-' . h((string)$selected['image_type']) . '">' . h((string)$selected['image_type']) . '</span><p>' . h((string)$selected['prompt']) . '</p>';
            studio_tattoo_image_render_actions($selected, $busy);
            echo '</div>';
        } else {
            echo '<div class="ti-stage-empty"><div class="tattoo-image-orb"></div><h3>Nenhuma versão ainda</h3><p>Descreva a ideia e gere a primeira prévia do projeto.</p></div>';
        }
        echo '</div></section></div>';

        echo '<section class="ti-panel ti-history"><div class="ti-history-head"><div><h2>Árvore de versões</h2><p class="muted">Cada ramificação mantém a origem e os ajustes do projeto.</p></div><strong>' . h((string)count($history)) . ' versões</strong></div>';
        if (!$history) {
            echo '<div class="ti-empty-history">Seu primeiro projeto aparecerá aqui.</div>';
        } else {
            $projects = [];
            foreach (array_reverse($history) as $item) {
                $projects[(string)$item['project_id']][] = $item;
            }
            foreach (array_reverse($projects, true) as $projectId => $projectItems) {
                $projectById = [];
                $children = [];
                foreach ($projectItems as $item) {
                    $projectById[(string)$item['history_id']] = $item;
                    $children[(string)$item['parent_id']][] = $item;
                }
                echo '<div class="ti-project"><div class="ti-project-title"><span class="ti-badge ti-badge-preview">projeto</span><strong>#' . h(substr($projectId, -8)) . '</strong><span class="muted">' . h((string)count($projectItems)) . ' versões</span></div><div class="ti-tree">';
                $renderBranch = function (array $item, int $depth) use (&$renderBranch, $children, $byId, $selectedId, $busy): void {
                    echo '<div class="ti-tree-node" style="--depth:' . min(5, $depth) . '">';
                    studio_tattoo_image_render_card($item, $byId, $selectedId, $busy, $depth);
                    echo '</div>';
                    foreach ($children[(string)$item['history_id']] ?? [] as $child) {
                        $renderBranch($child, $depth + 1);
                    }
                };
                $roots = $children[''] ?? [];
                foreach ($projectItems as $candidate) {
                    if ($candidate['parent_id'] !== '' && !isset($projectById[$candidate['parent_id']])) {
                        $roots[] = $candidate;
                    }
                }
                $seen = [];
                foreach ($roots as $root) {
                    if (!isset($seen[$root['history_id']])) {
                        $seen[$root['history_id']] = true;
                        $renderBranch($root, 0);
                    }
                }
                echo '</div></div>';
            }
        }
        echo '</section>';

        echo '<dialog class="ti-dialog" id="tattooEditDialog"><form method="post"><div class="ti-dialog-body">' . csrf_field() . '<input type="hidden" name="action" value="edit_tattoo_reference"><input type="hidden" name="history_id" id="tattooEditHistoryId"><h2>Editar detalhe</h2><p class="muted">A composição, anatomia, rosto, proporção, luz e perspectiva serão preservados.</p><div class="field"><label>O que alterar nesta imagem?</label><textarea name="edit_prompt" rows="5" required placeholder="Ex.: acrescente uma coroa de espinhos nesse Jesus"></textarea></div><div class="field"><label>Intensidade</label><select name="intensity"><option value="low">Baixa — alteração discreta</option><option value="medium" selected>Média — equilibrada</option><option value="high">Alta — mudança mais evidente</option></select></div><div class="ti-dialog-actions"><button class="btn secondary" id="tattooEditCancel" type="button">Cancelar</button><button class="btn" type="submit">Criar nova versão</button></div></div></form></dialog>';

        echo '<script>(function(){const form=document.getElementById("tattooImageForm"),wait=document.getElementById("tattooImageWait"),dialog=document.getElementById("tattooEditDialog"),editId=document.getElementById("tattooEditHistoryId");document.querySelectorAll(".ti-edit-open").forEach(button=>button.addEventListener("click",()=>{editId.value=button.dataset.historyId||"";dialog.showModal();}));document.getElementById("tattooEditCancel")?.addEventListener("click",()=>dialog.close());if(form)form.addEventListener("submit",()=>{const button=form.querySelector("button[type=submit]");if(button){button.disabled=true;button.textContent="Preparando…";}if(wait){wait.hidden=false;wait.textContent="Traduzindo e preparando a IA local…";}});';
        if ($busy) {
            $startedTimestamp = strtotime((string)($job['started_at'] ?? '')) ?: time();
            echo 'const statusUrl=' . json_encode(app_url('studio_tattoo_image_status'), JSON_UNESCAPED_SLASHES)
                . ',startedAt=' . ((int)$startedTimestamp * 1000) . ',expected=' . (int)($job['expected_seconds'] ?? 300) . ';'
                . 'const fill=document.getElementById("tattooProgressFill"),label=document.getElementById("tattooProgressLabel"),pctEl=document.getElementById("tattooProgressPercent"),timeEl=document.getElementById("tattooProgressTime");'
                . 'function elapsed(){return Math.max(0,(Date.now()-startedAt)/1000)}function fmt(s){s=Math.floor(s);const m=Math.floor(s/60),r=s%60;return m?m+"min "+r+"s":r+"s"}'
                . 'function paint(data){const e=elapsed();let pct=Math.min(96,Math.max(5,Math.floor(8+(e/expected)*87))),text="Renderizando detalhes…";if(data.status==="queued"){pct=Math.min(18,pct);text=data.queue_position>0?"Na fila · posição "+data.queue_position:"Na fila da IA local";}else if(e<25){text="Preparando modelo e composição…";}else if(pct>82){text="Finalizando textura e arquivo…";}fill.style.width=pct+"%";pctEl.textContent=pct+"%";label.textContent=text;timeEl.textContent=fmt(e);if(wait)wait.textContent=text;}'
                . 'async function poll(){try{const response=await fetch(statusUrl,{credentials:"same-origin",cache:"no-store"}),data=await response.json();if(["completed","failed","idle"].includes(data.status)){fill.style.width="100%";pctEl.textContent="100%";location.reload();return;}paint(data);}catch(error){if(wait)wait.textContent="A geração continua. Tentando reconectar…";}setTimeout(poll,3000)}paint({status:"queued"});setTimeout(poll,1000);';
        }
        echo '})();</script></div>';
    }, flash_get());
    exit;
}

studio_tattoo_image_handle_request();
