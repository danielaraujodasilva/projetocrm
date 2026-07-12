<?php

declare(strict_types=1);

function studio_general_image_openai_config(array $studio): array
{
    $settings = studio_settings($studio);
    $apiKey = studio_setting_secret($settings, 'openai_api_key', 'OPENAI_API_KEY');
    $baseUrl = trim((string)($settings['ai_api_base_url'] ?? ''));
    if ($baseUrl === '' || preg_match('#(localhost|127\.0\.0\.1|::1):11434#i', $baseUrl)) {
        $baseUrl = 'https://api.openai.com/v1';
    }

    return [
        'enabled' => $apiKey !== '' && $apiKey !== 'ollama',
        'api_key' => $apiKey,
        'base_url' => rtrim($baseUrl, '/'),
        'model' => trim((string)(getenv('OPENAI_IMAGE_MODEL') ?: 'gpt-image-2')),
    ];
}

function studio_general_image_provider(array $studio): array
{
    $openAi = studio_general_image_openai_config($studio);
    if (!empty($openAi['enabled'])) {
        return [
            'ok' => true,
            'type' => 'openai',
            'model' => (string)$openAi['model'],
            'label' => 'GPT Image 2',
            'config' => $openAi,
        ];
    }

    $local = studio_local_image_ai_request('GET', '/v1/models', null, 4);
    return [
        'ok' => !empty($local['ok']),
        'type' => 'local',
        'model' => 'Stable Diffusion 3.5 Medium Turbo local',
        'label' => 'SD 3.5 Medium Turbo',
        'error' => (string)($local['error'] ?? ''),
    ];
}

function studio_general_image_restart_local_service(): void
{
    $script = APP_BASE_PATH . '/scripts/start-local-image-ai.ps1';
    if (!is_file($script)) {
        return;
    }

    $command = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ' . escapeshellarg($script);
    @pclose(@popen($command, 'r'));
}

function studio_general_image_style(string $style): string
{
    return match ($style) {
        'auto' => 'Use the visual style requested by the user. If no style is specified, render it as a sharp photorealistic image.',
        'realistic' => 'Hyperrealistic photography, natural light, crisp focus, high detail, realistic materials and realistic anatomy or texture when relevant.',
        'cartoon' => 'Cartoon style.',
        'illustration' => 'Polished digital illustration.',
        'cinematic' => 'Cinematic photorealistic image, dramatic but natural lighting, sharp detail.',
        'anime' => 'Anime style.',
        'stencil' => 'Black and white stencil style.',
        'blackwork' => 'Blackwork illustration style.',
        'chicano' => 'Chicano black and grey illustration style.',
        'fineline' => 'Fine line illustration style.',
        'oldschool' => 'Old school tattoo flash style.',
        'reference' => 'Clean reference image on a simple background.',
        default => '',
    };
}

function studio_general_image_prompt(array $data): string
{
    $prompt = trim((string)($data['prompt'] ?? ''));
    if (mb_strlen($prompt, 'UTF-8') < 2) {
        throw new RuntimeException('Diga o que voce quer ver na imagem.');
    }
    if (mb_strlen($prompt, 'UTF-8') > 4000) {
        throw new RuntimeException('O prompt deve ter no maximo 4.000 caracteres.');
    }

    $style = studio_tattoo_image_choice(
        (string)($data['style'] ?? 'realistic'),
        studio_tattoo_image_allowed_styles(),
        'realistic'
    );
    $styleText = studio_general_image_style($style);
    $direction = trim((string)($data['reference_notes'] ?? ''));

    $parts = [
        'Follow the user request literally. The main subject must match the prompt exactly.',
        $prompt,
    ];
    if ($styleText !== '') {
        $parts[] = $styleText;
    }
    if ($direction !== '') {
        $parts[] = $direction;
    }
    return implode("\n", $parts);
}

function studio_general_image_translate_for_local(string $prompt): string
{
    $instruction = 'Translate the image prompt below to English. Preserve every subject, action, attribute, quantity, color, relationship and exclusion exactly. Do not add, remove, explain or improve anything. Return only the translated prompt.';
    $payload = json_encode([
        'model' => 'llama3.2:3b',
        'prompt' => $instruction . "\n\n" . $prompt,
        'stream' => false,
        'keep_alive' => 0,
        'options' => [
            'temperature' => 0,
            'num_predict' => 700,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return $prompt;
    }

    $ch = curl_init('http://127.0.0.1:11434/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    $translated = is_array($json) ? trim((string)($json['response'] ?? '')) : '';

    if ($status < 200 || $status >= 300 || $translated === '' || mb_strlen($translated, 'UTF-8') > 5000) {
        return $prompt;
    }
    return trim($translated, " \t\n\r\0\x0B\"");
}

function studio_general_image_json_from_text(string $text): ?array
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $json = json_decode($text, true);
    if (is_array($json)) {
        return $json;
    }

    if (preg_match('/\{.*\}/s', $text, $match)) {
        $json = json_decode($match[0], true);
        return is_array($json) ? $json : null;
    }
    return null;
}

function studio_general_image_compile_for_local(string $prompt, string $negativePrompt): array
{
    $instruction = <<<'TXT'
You are a literal prompt compiler for a local text-to-image model.
Return only valid compact JSON with these fields: "prompt" string, "negative" string, "main_subject_is_human" boolean.
Translate to English if needed.
Preserve the user's requested subject, action, quantity, colors, style, mood and exclusions exactly.
Do not add tattoo context, glamour models, portraits, people, women, children, fashion, nudity, fantasy armor, robots, or unrelated studio scenes unless the user explicitly requested them.
Make the main subject concrete and unmistakable. If the prompt asks for a non-human animal, object, food, vehicle, place, character, logo, symbol or creature, state that exact subject first and add negative words that prevent replacing it with a human/person/woman/child/model.
If the prompt asks for a human/person, preserve that exactly and set main_subject_is_human true.
If the main subject is not a human/person, set main_subject_is_human false.
Human-like actions do not change the subject category. A chimpanzee smoking, a monkey drinking, an alligator wearing glasses, a car dancing, or a cup holding an object are still non-human main subjects.
Examples:
User asks "um chimpanzé fumando e bebendo cerveja" => main_subject_is_human false, prompt starts with "A non-human animal chimpanzee..."
User asks "um pirata fumando e bebendo cerveja" => main_subject_is_human true, prompt starts with "A pirate..."
User asks "um jacaré de terno" => main_subject_is_human false, prompt starts with "A non-human animal alligator..."
Do not moralize, refuse, explain or summarize.
TXT;

    $payload = json_encode([
        'model' => 'llama3.2:3b',
        'prompt' => $instruction . "\n\nPOSITIVE PROMPT:\n" . $prompt . "\n\nUSER NEGATIVE PROMPT:\n" . $negativePrompt,
        'stream' => false,
        'keep_alive' => 0,
        'format' => 'json',
        'options' => [
            'temperature' => 0,
            'num_predict' => 900,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return ['prompt' => studio_general_image_translate_for_local($prompt), 'negative' => $negativePrompt];
    }

    $ch = curl_init('http://127.0.0.1:11434/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    $response = is_array($json) ? (string)($json['response'] ?? '') : '';
    $compiled = studio_general_image_json_from_text($response);

    $compiledPrompt = is_array($compiled) ? trim((string)($compiled['prompt'] ?? '')) : '';
    $compiledNegative = is_array($compiled) ? trim((string)($compiled['negative'] ?? '')) : '';
    $mainSubjectIsHuman = is_array($compiled) ? filter_var($compiled['main_subject_is_human'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
    if ($status < 200 || $status >= 300 || $compiledPrompt === '' || mb_strlen($compiledPrompt, 'UTF-8') > 6000) {
        return [
            'prompt' => studio_general_image_translate_for_local($prompt),
            'negative' => $negativePrompt !== '' ? studio_general_image_translate_for_local($negativePrompt) : '',
            'main_subject_is_human' => null,
        ];
    }

    if ($mainSubjectIsHuman === false) {
        $compiledPrompt = 'Non-human main subject. ' . $compiledPrompt;
        $compiledNegative = trim(implode(', ', array_filter([
            $compiledNegative,
            'human, person, woman, man, child, model',
        ], static fn ($part) => trim((string)$part) !== '')));
    }

    return [
        'prompt' => trim($compiledPrompt, " \t\n\r\0\x0B\""),
        'negative' => trim($compiledNegative, " \t\n\r\0\x0B\""),
        'main_subject_is_human' => $mainSubjectIsHuman,
    ];
}

function studio_general_image_size(string $format, string $mode, bool $openAi): string|array
{
    $format = studio_tattoo_image_choice($format, ['vertical', 'square', 'wide'], 'vertical');
    if ($openAi) {
        return match ($format) {
            'square' => '1024x1024',
            'wide' => '1536x1024',
            default => '1024x1536',
        };
    }

    $final = $mode === 'final';
    return match ($format) {
        'square' => [512, 512],
        'wide' => [768, 512],
        default => [512, 768],
    };
}

function studio_general_image_storage(array $studio, string $extension): array
{
    $safeStudio = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($studio['slug'] ?? 'studio')) ?: 'studio';
    $folder = APP_BASE_PATH . '/storage/tattoo-images/' . $safeStudio;
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
        throw new RuntimeException('Nao foi possivel preparar a pasta das imagens.');
    }
    $extension = in_array($extension, ['png', 'jpg', 'webp'], true) ? $extension : 'png';
    $fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    return [$folder . '/' . $fileName, 'storage/tattoo-images/' . $safeStudio . '/' . $fileName, $fileName];
}

function studio_general_image_sharpen_jpeg(string $path): void
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imageconvolution') || !is_file($path)) {
        return;
    }

    $binary = @file_get_contents($path);
    $image = is_string($binary) ? @imagecreatefromstring($binary) : false;
    if (!$image) {
        return;
    }

    imageconvolution($image, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);
    imagejpeg($image, $path, 98);
    imagedestroy($image);
}

function studio_general_image_openai_request(array $config, string $path, array $fields, bool $multipart = false): array
{
    $ch = curl_init(rtrim((string)$config['base_url'], '/') . '/' . ltrim($path, '/'));
    $headers = ['Authorization: Bearer ' . (string)$config['api_key']];
    if (!$multipart) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $multipart ? $fields : json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 360,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;
    if ($raw === false || $curlError !== '') {
        throw new RuntimeException('Nao foi possivel conectar ao gerador de imagens: ' . $curlError);
    }
    if ($status < 200 || $status >= 300 || !is_array($json)) {
        $message = is_array($json) ? trim((string)($json['error']['message'] ?? '')) : '';
        throw new RuntimeException($message !== '' ? $message : 'O gerador de imagens respondeu com erro HTTP ' . $status . '.');
    }
    return $json;
}

function studio_general_image_result(array $studio, array $data, string $binary, string $model, string $extension = 'png'): array
{
    if ($binary === '') {
        throw new RuntimeException('A IA nao devolveu uma imagem valida.');
    }
    [$absolutePath, $relativePath, $fileName] = studio_general_image_storage($studio, $extension);
    if (file_put_contents($absolutePath, $binary) === false) {
        throw new RuntimeException('Nao foi possivel salvar a imagem gerada.');
    }
    if (in_array(strtolower($extension), ['jpg', 'jpeg'], true)) {
        studio_general_image_sharpen_jpeg($absolutePath);
    }

    return [
        'history_id' => bin2hex(random_bytes(8)),
        'prompt' => trim((string)($data['prompt'] ?? '')),
        'image_path' => $relativePath,
        'file_name' => $fileName,
        'upscaled_image_path' => '',
        'upscaled_file_name' => '',
        'generated_at' => date('Y-m-d H:i:s'),
        'mode' => studio_tattoo_image_choice((string)($data['mode'] ?? 'fast'), ['fast', 'final'], 'fast'),
        'style' => studio_tattoo_image_choice((string)($data['style'] ?? 'realistic'), studio_tattoo_image_allowed_styles(), 'realistic'),
        'format' => studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical', 'square', 'wide'], 'vertical'),
        'reference_notes' => trim((string)($data['reference_notes'] ?? '')),
        'negative_prompt' => trim((string)($data['negative_prompt'] ?? '')),
        'source_image_path' => trim((string)($data['source_image_path'] ?? '')),
        'model' => $model,
    ];
}

function studio_general_image_generate_openai(array $studio, array $data, array $provider): array
{
    $config = (array)$provider['config'];
    $prompt = studio_general_image_prompt($data);
    $format = studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical', 'square', 'wide'], 'vertical');
    $mode = studio_tattoo_image_choice((string)($data['mode'] ?? 'fast'), ['fast', 'final'], 'fast');
    $size = (string)studio_general_image_size($format, $mode, true);
    $sourcePath = studio_tattoo_image_absolute_from_relative((string)($data['source_image_path'] ?? ''));

    if ($sourcePath !== '') {
        $fields = [
            'model' => (string)$config['model'],
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $mode === 'final' ? 'high' : 'medium',
            'image' => new CURLFile($sourcePath, mime_content_type($sourcePath) ?: 'image/jpeg', basename($sourcePath)),
        ];
        $json = studio_general_image_openai_request($config, '/images/edits', $fields, true);
    } else {
        $json = studio_general_image_openai_request($config, '/images/generations', [
            'model' => (string)$config['model'],
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $mode === 'final' ? 'high' : 'medium',
            'n' => 1,
        ]);
    }

    $base64 = trim((string)($json['data'][0]['b64_json'] ?? ''));
    $binary = $base64 !== '' ? base64_decode($base64, true) : false;
    if ($binary === false && !empty($json['data'][0]['url'])) {
        $binary = @file_get_contents((string)$json['data'][0]['url']);
    }
    return studio_general_image_result($studio, $data, is_string($binary) ? $binary : '', (string)$provider['label'], 'png');
}

function studio_general_image_local_body(array $data, string $mode): array
{
    $format = studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical', 'square', 'wide'], 'vertical');
    [$width, $height] = studio_general_image_size($format, $mode, false);
    $style = studio_tattoo_image_choice((string)($data['style'] ?? 'realistic'), studio_tattoo_image_allowed_styles(), 'realistic');
    $negativePrompt = trim((string)($data['negative_prompt'] ?? ''));
    $qualityNegative = 'blurry, low resolution, text, watermark, logo, deformed, duplicate';
    $sampleSteps = $mode === 'final' ? 5 : 4;
    $txtCfg = in_array($style, ['realistic', 'cinematic', 'auto'], true) ? 1.55 : 1.2;
    $compiled = studio_general_image_compile_for_local(studio_general_image_prompt($data), $negativePrompt);
    $compiledNegative = trim((string)($compiled['negative'] ?? ''));
    $negative = trim(implode(', ', array_filter([$compiledNegative, $qualityNegative], static fn ($part) => trim((string)$part) !== '')));
    $body = [
        'prompt' => (string)($compiled['prompt'] ?? studio_general_image_translate_for_local(studio_general_image_prompt($data))),
        'negative_prompt' => $negative !== '' ? $negative : $qualityNegative,
        'clip_skip' => -1,
        'width' => $width,
        'height' => $height,
        'seed' => -1,
        'batch_count' => 1,
        'sample_params' => [
            'scheduler' => 'beta',
            'sample_method' => 'euler',
            'sample_steps' => $sampleSteps,
            'guidance' => ['txt_cfg' => $txtCfg, 'distilled_guidance' => 0.0],
        ],
        'vae_tiling_params' => ['enabled' => true, 'tile_size_x' => 512, 'tile_size_y' => 512, 'target_overlap' => 0.25],
        'output_format' => 'jpeg',
        'output_compression' => 98,
    ];

    $sourcePath = studio_tattoo_image_absolute_from_relative((string)($data['source_image_path'] ?? ''));
    if ($sourcePath !== '') {
        $sourceBinary = @file_get_contents($sourcePath);
        if (is_string($sourceBinary) && $sourceBinary !== '') {
            $body['init_image'] = base64_encode($sourceBinary);
            $body['strength'] = $mode === 'final' ? 0.52 : 0.62;
        }
    }
    return $body;
}

function studio_general_image_start(array $studio, array $data): array
{
    studio_general_image_prompt($data);
    $provider = studio_general_image_provider($studio);
    if (empty($provider['ok'])) {
        throw new RuntimeException('O novo motor local de imagens ainda esta iniciando.');
    }

    if ($provider['type'] === 'openai') {
        return [
            'status' => 'completed',
            'result' => studio_general_image_generate_openai($studio, $data, $provider),
        ];
    }

    $mode = studio_tattoo_image_choice((string)($data['mode'] ?? 'fast'), ['fast', 'final'], 'fast');
    $response = studio_local_image_ai_request('POST', '/sdcpp/v1/img_gen', studio_general_image_local_body($data, $mode), 120);
    if (empty($response['ok'])) {
        throw new RuntimeException((string)($response['error'] ?? 'Nao foi possivel iniciar a geracao local.'));
    }
    $jobId = trim((string)($response['json']['id'] ?? ''));
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $jobId)) {
        throw new RuntimeException('O motor local nao devolveu um identificador valido.');
    }
    return [
        'status' => 'queued',
        'id' => $jobId,
        'data' => [
            'prompt' => trim((string)($data['prompt'] ?? '')),
            'mode' => $mode,
            'style' => studio_tattoo_image_choice((string)($data['style'] ?? 'realistic'), studio_tattoo_image_allowed_styles(), 'realistic'),
            'format' => studio_tattoo_image_choice((string)($data['format'] ?? 'vertical'), ['vertical', 'square', 'wide'], 'vertical'),
            'reference_notes' => trim((string)($data['reference_notes'] ?? '')),
            'negative_prompt' => trim((string)($data['negative_prompt'] ?? '')),
            'source_image_path' => trim((string)($data['source_image_path'] ?? '')),
        ],
        'prompt' => trim((string)($data['prompt'] ?? '')),
        'mode' => $mode,
        'started_at' => date('Y-m-d H:i:s'),
        'expected_seconds' => $mode === 'final' ? 180 : 120,
        'model' => (string)$provider['label'],
    ];
}

function studio_general_image_poll(array $studio, array $job): array
{
    $jobId = trim((string)($job['id'] ?? ''));
    if ($jobId === '') {
        return ['status' => 'failed', 'error' => 'Geracao invalida.'];
    }
    $response = studio_local_image_ai_request('GET', '/sdcpp/v1/jobs/' . rawurlencode($jobId), null, 10);
    if (empty($response['ok'])) {
        $health = studio_local_image_ai_request('GET', '/v1/models', null, 3);
        if (empty($health['ok'])) {
            studio_general_image_restart_local_service();
            return [
                'status' => 'failed',
                'error' => 'O motor local de imagens caiu durante a geracao. Reiniciei o servico; tente gerar novamente.',
            ];
        }
        return ['status' => 'waiting', 'expected_seconds' => (int)($job['expected_seconds'] ?? 180)];
    }
    $json = (array)$response['json'];
    $status = (string)($json['status'] ?? 'waiting');
    if ($status !== 'completed') {
        $error = trim((string)($json['error']['message'] ?? ''));
        return [
            'status' => in_array($status, ['failed', 'cancelled'], true) ? 'failed' : $status,
            'queue_position' => (int)($json['queue_position'] ?? 0),
            'expected_seconds' => (int)($job['expected_seconds'] ?? 180),
            'error' => $error,
        ];
    }

    $base64 = trim((string)($json['result']['images'][0]['b64_json'] ?? ''));
    $binary = $base64 !== '' ? base64_decode($base64, true) : false;
    try {
        $result = studio_general_image_result(
            $studio,
            (array)($job['data'] ?? $job),
            is_string($binary) ? $binary : '',
            (string)($job['model'] ?? 'SD 3.5 Medium Turbo'),
            'jpg'
        );
    } catch (Throwable $error) {
        return ['status' => 'failed', 'error' => $error->getMessage()];
    }
    return ['status' => 'completed', 'result' => $result];
}
