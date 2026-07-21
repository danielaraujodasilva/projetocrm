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
        'All requested props, actions, clothing, objects, body placement, colors and style words are mandatory. Do not omit secondary objects.',
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

    $looksLikeRefusal = (bool)preg_match(
        '/\b(i\s+(?:can(?:not|\'t)|won\'t)|unable to|cannot fulfill|can\'t fulfill|sorry|as an ai|nao posso|não posso|nao consigo|não consigo)\b/i',
        $translated
    );
    if ($status < 200 || $status >= 300 || $translated === '' || $looksLikeRefusal || mb_strlen($translated, 'UTF-8') > 5000) {
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

function studio_general_image_prompt_lock(string $prompt): array
{
    $norm = mb_strtolower($prompt, 'UTF-8');
    $norm = strtr($norm, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ]);

    $humanWords = '(humano|humana|pessoa|homem|mulher|garoto|garota|menino|menina|cliente|modelo|retrato|rosto|face|corpo humano|human|person|man|woman|boy|girl|model|portrait|face)';
    $explicitHuman = (bool)preg_match('/^\s*' . $humanWords . '\b/u', $norm)
        || (bool)preg_match('/\b(com|incluindo|mostrando|junto com|ao lado de|retrato de|foto de|imagem de)\s+(um|uma|o|a|de|do|da)?\s*' . $humanWords . '\b/u', $norm);
    if ($explicitHuman) {
        return ['prefix' => '', 'negative' => ''];
    }

    $tattooPlacement = '';
    if (preg_match('/\b(tattoo|tatuagem|tattooed|tatuado|tatuada)\b/u', $norm)) {
        $placementAreas = [
            'costas|back' => 'full adult human back, rear view',
            'antebraco|forearm' => 'adult human forearm',
            'braco|arm' => 'adult human arm',
            'perna|leg' => 'adult human leg',
            'coxa|thigh' => 'adult human thigh',
            'panturrilha|calf' => 'adult human calf',
            'peito|chest' => 'adult human chest',
            'ombro|shoulder' => 'adult human shoulder',
            'pescoco|neck' => 'adult human neck',
            'mao|hand' => 'adult human hand',
            'pulso|wrist' => 'adult human wrist',
            'tornozelo|ankle' => 'adult human ankle',
        ];
        foreach ($placementAreas as $pattern => $label) {
            if (preg_match('/\b(?:' . $pattern . ')\b/u', $norm)) {
                $tattooPlacement = $label;
                break;
            }
        }
    }

    $subjects = [
        'chimpanze' => 'a non-human chimpanzee',
        'chimpanzee' => 'a non-human chimpanzee',
        'macaco' => 'a non-human monkey',
        'gorila' => 'a non-human gorilla',
        'jacare' => 'a non-human alligator',
        'crocodilo' => 'a non-human crocodile',
        'leao' => 'a non-human lion',
        'lion' => 'a non-human lion',
        'lobo' => 'a non-human wolf',
        'wolf' => 'a non-human wolf',
        'tigre' => 'a non-human tiger',
        'tiger' => 'a non-human tiger',
        'pantera' => 'a non-human panther',
        'onca' => 'a non-human jaguar',
        'jaguar' => 'a non-human jaguar',
        'aguia' => 'a non-human eagle',
        'eagle' => 'a non-human eagle',
        'coruja' => 'a non-human owl',
        'owl' => 'a non-human owl',
        'cobra' => 'a non-human snake',
        'snake' => 'a non-human snake',
        'dragao' => 'a non-human dragon',
        'dragon' => 'a non-human dragon',
        'urso' => 'a non-human bear',
        'bear' => 'a non-human bear',
        'cavalo' => 'a non-human horse',
        'horse' => 'a non-human horse',
        'cachorro' => 'a non-human dog',
        'dog' => 'a non-human dog',
        'gato' => 'a non-human cat',
        'cat' => 'a non-human cat',
        'tubarao' => 'a non-human shark',
        'shark' => 'a non-human shark',
        'peixe' => 'a non-human fish',
        'fish' => 'a non-human fish',
        'borboleta' => 'a non-human butterfly',
        'butterfly' => 'a non-human butterfly',
        'aranha' => 'a non-human spider',
        'spider' => 'a non-human spider',
        'escorpiao' => 'a non-human scorpion',
        'scorpion' => 'a non-human scorpion',
    ];

    foreach ($subjects as $needle => $label) {
        if (preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $norm)) {
            if ($tattooPlacement !== '') {
                $tattooSubject = preg_replace('/^a non-human\s+/i', 'a ', $label) ?: $label;
                return [
                    'prefix' => 'TATTOO MOCKUP on ' . $tattooPlacement . '. The tattoo design depicts ' . $tattooSubject . '.',
                    'negative' => 'tattoo on the wrong body area, extra tattoos, text, watermark',
                ];
            }
            return [
                'prefix' => 'MAIN SUBJECT: ' . $label . '. Never replace it with a human or person.',
                'negative' => 'human, person, woman, man, child, fashion model, human face',
            ];
        }
    }

    return ['prefix' => '', 'negative' => ''];
}

function studio_general_image_compact_local_text(string $text, int $maxWords, int $maxChars): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return '';
    }
    $words = preg_split('/\s+/u', $text) ?: [];
    if (count($words) > $maxWords) {
        $text = implode(' ', array_slice($words, 0, $maxWords));
    }
    if (mb_strlen($text, 'UTF-8') > $maxChars) {
        $text = rtrim(mb_substr($text, 0, $maxChars, 'UTF-8'));
        $text = preg_replace('/\s+\S*$/u', '', $text) ?: $text;
    }
    return trim($text, " \t\n\r\0\x0B,;.");
}

function studio_general_image_prepare_local_data(array $studio, array $data): array
{
    $prompt = trim((string)($data['prompt'] ?? ''));
    $negativePrompt = trim((string)($data['negative_prompt'] ?? ''));
    if ($prompt === '' || !function_exists('studio_openai_config') || !function_exists('studio_openai_text')) {
        return $data;
    }

    $config = studio_openai_config($studio);
    if (trim((string)($config['api_key'] ?? '')) === '') {
        return $data;
    }

    $schema = [
        'type' => 'object',
        'properties' => [
            'prompt' => ['type' => 'string'],
            'negative' => ['type' => 'string'],
        ],
        'required' => ['prompt', 'negative'],
        'additionalProperties' => false,
    ];
    $systemPrompt = <<<'TXT'
You compile literal prompts for a local text-to-image model.
Return only compact JSON. Translate the request to concise English.
Preserve the exact main subject, quantity, action, appearance, objects, setting, composition and requested style.
Never add tattoo context, people, glamour, nudity, armor, robots or unrelated elements.
If the request explicitly asks for a tattoo placed on a body area, preserve the word tattoo and state the exact adult human body area and viewing angle.
Put the unmistakable main subject first. Keep prompt under 32 words and negative under 18 words.
TXT;
    $request = "USER REQUEST:\n" . $prompt;
    if ($negativePrompt !== '') {
        $request .= "\n\nUSER EXCLUSIONS:\n" . $negativePrompt;
    }
    $response = studio_openai_text(
        (string)$config['api_key'],
        (string)$config['model'],
        $systemPrompt,
        $request,
        (string)$config['base_url'],
        20,
        false,
        $schema,
        '{"prompt":"short literal English image prompt","negative":"short English exclusions"}'
    );
    $compiled = !empty($response['ok']) && is_array($response['raw_json'] ?? null)
        ? (array)$response['raw_json']
        : [];
    if (trim((string)($compiled['prompt'] ?? '')) === ''
        && stripos((string)($config['base_url'] ?? ''), 'integrate.api.nvidia.com') !== false) {
        $retryModel = strcasecmp((string)$config['model'], 'meta/llama-3.3-70b-instruct') === 0
            ? 'meta/llama-3.1-70b-instruct'
            : 'meta/llama-3.3-70b-instruct';
        $retry = studio_openai_text(
            (string)$config['api_key'],
            $retryModel,
            $systemPrompt,
            $request,
            (string)$config['base_url'],
            12,
            false,
            $schema,
            '{"prompt":"short literal English image prompt","negative":"short English exclusions"}'
        );
        $compiled = !empty($retry['ok']) && is_array($retry['raw_json'] ?? null)
            ? (array)$retry['raw_json']
            : [];
    }
    $compiledPrompt = studio_general_image_compact_local_text((string)($compiled['prompt'] ?? ''), 32, 240);
    $data['_local_compiled_prompt'] = $compiledPrompt !== '' ? $compiledPrompt : $prompt;
    $compiledNegative = studio_general_image_compact_local_text((string)($compiled['negative'] ?? ''), 18, 140);
    $data['_local_compiled_negative'] = $compiledNegative !== '' ? $compiledNegative : $negativePrompt;
    return $data;
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
        $fallbackPrompt = studio_general_image_translate_for_local($prompt);
        $fallbackNegative = $negativePrompt !== '' ? studio_general_image_translate_for_local($negativePrompt) : '';
        $lock = studio_general_image_prompt_lock($prompt . "\n" . $fallbackPrompt);
        if (trim((string)$lock['prefix']) !== '') {
            $fallbackPrompt = trim((string)$lock['prefix'] . "\n" . $fallbackPrompt);
            $fallbackNegative = trim(implode(', ', array_filter([
                $fallbackNegative,
                (string)$lock['negative'],
            ], static fn ($part) => trim((string)$part) !== '')));
        }
        return [
            'prompt' => $fallbackPrompt,
            'negative' => $fallbackNegative,
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
    $lock = studio_general_image_prompt_lock($prompt . "\n" . $compiledPrompt);
    if (trim((string)$lock['prefix']) !== '') {
        $compiledPrompt = trim((string)$lock['prefix'] . "\n" . $compiledPrompt);
        $compiledNegative = trim(implode(', ', array_filter([
            $compiledNegative,
            (string)$lock['negative'],
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

function studio_general_image_prepare_local_init_image(string $sourcePath, int $targetWidth, int $targetHeight): string
{
    if (!is_file($sourcePath)) {
        return '';
    }
    $binary = @file_get_contents($sourcePath);
    if (!is_string($binary) || $binary === '') {
        return '';
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
        return $binary;
    }

    try {
        $source = @imagecreatefromstring($binary);
    } catch (Throwable) {
        return $binary;
    }
    if (!$source) {
        return $binary;
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    if ($sourceWidth <= 0 || $sourceHeight <= 0 || $targetWidth <= 0 || $targetHeight <= 0) {
        imagedestroy($source);
        return $binary;
    }

    $sourceRatio = $sourceWidth / $sourceHeight;
    $targetRatio = $targetWidth / $targetHeight;
    if ($sourceRatio > $targetRatio) {
        $cropHeight = $sourceHeight;
        $cropWidth = (int)round($sourceHeight * $targetRatio);
        $cropX = (int)max(0, floor(($sourceWidth - $cropWidth) / 2));
        $cropY = 0;
    } else {
        $cropWidth = $sourceWidth;
        $cropHeight = (int)round($sourceWidth / $targetRatio);
        $cropX = 0;
        $cropY = (int)max(0, floor(($sourceHeight - $cropHeight) / 2));
    }

    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    imagecopyresampled($target, $source, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
    ob_start();
    imagejpeg($target, null, 92);
    $prepared = (string)ob_get_clean();
    imagedestroy($source);
    imagedestroy($target);

    return $prepared !== '' ? $prepared : $binary;
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
    $sampleSteps = $mode === 'final' ? 6 : 4;
    $txtCfg = in_array($style, ['realistic', 'cinematic', 'auto'], true) ? 1.8 : 1.35;
    $userPrompt = trim((string)($data['prompt'] ?? ''));
    $translatedPrompt = trim((string)($data['_local_compiled_prompt'] ?? ''));
    if ($translatedPrompt === '') {
        $translatedPrompt = studio_general_image_translate_for_local($userPrompt);
    }
    $lock = studio_general_image_prompt_lock($userPrompt . "\n" . $translatedPrompt);
    $compactStyle = match ($style) {
        'realistic' => 'photorealistic, sharp detail, natural light',
        'cinematic' => 'cinematic photorealism, dramatic natural light',
        'cartoon' => 'polished cartoon style',
        'illustration' => 'polished digital illustration',
        'anime' => 'anime illustration',
        'stencil' => 'clean black and white stencil',
        'blackwork' => 'high contrast blackwork illustration',
        'chicano' => 'Chicano black and grey illustration',
        'fineline' => 'delicate fine line illustration',
        'oldschool' => 'classic old school tattoo flash',
        'reference' => 'clean reference image, simple background',
        default => '',
    };
    $compiledPrompt = studio_general_image_compact_local_text(
        implode(', ', array_filter([(string)($lock['prefix'] ?? ''), $translatedPrompt, $compactStyle])),
        38,
        280
    );
    $translatedNegative = trim((string)($data['_local_compiled_negative'] ?? ''));
    if ($translatedNegative === '' && $negativePrompt !== '') {
        $translatedNegative = studio_general_image_translate_for_local($negativePrompt);
    }
    $compiledNegative = studio_general_image_compact_local_text(
        implode(', ', array_filter([$translatedNegative, (string)($lock['negative'] ?? '')])),
        24,
        180
    );
    $negative = trim(implode(', ', array_filter([$compiledNegative, $qualityNegative], static fn ($part) => trim((string)$part) !== '')));
    $body = [
        'prompt' => $compiledPrompt,
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
        $sourceBinary = studio_general_image_prepare_local_init_image($sourcePath, (int)$width, (int)$height);
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
    $localData = studio_general_image_prepare_local_data($studio, $data);
    $response = studio_local_image_ai_request('POST', '/sdcpp/v1/img_gen', studio_general_image_local_body($localData, $mode), 120);
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
        if ((int)($response['status'] ?? 0) === 404) {
            return [
                'status' => 'failed',
                'error' => 'O motor local perdeu o job de imagem antes de concluir. Reinicie a geração.',
            ];
        }
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
