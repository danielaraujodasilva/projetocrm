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
        ? ['width' => 512, 'height' => 704, 'steps' => 6, 'txt_cfg' => 4.4, 'distilled_guidance' => 2.8, 'expected_seconds' => 70, 'denoise' => 0.62]
        : ['width' => 832, 'height' => 1216, 'steps' => 30, 'txt_cfg' => 5.4, 'distilled_guidance' => 3.7, 'expected_seconds' => 380, 'denoise' => 0.58];
}

function studio_tattoo_image_allowed_styles(): array
{
    return ['auto','realistic','cartoon','illustration','cinematic','anime','stencil','blackwork','chicano','fineline','oldschool','reference'];
}

function studio_tattoo_image_norm(string $text): string
{
    return strtr(mb_strtolower($text, 'UTF-8'), ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
}

function studio_tattoo_image_non_human_negative(): string
{
    return 'human body, human face, person, portrait, woman, man, girl, boy, nude, naked, breast, buttocks, torso, skin, tattooed skin, tattooed person, model photo, body photo, fashion photo, glamour photo, boudoir photo, shoulder, arm, leg, back, nsfw';
}

function studio_tattoo_image_mentions_human_or_mockup(string $request): bool
{
    $t = studio_tattoo_image_norm($request);
    return (bool)preg_match('/\b(retrato|portrait|rosto|face|pessoa|person|homem|man|mulher|woman|menino|boy|menina|girl|corpo|body|modelo|model|pele|skin|braco|bra[cç]o|arm|perna|leg|costas|back|ombro|shoulder|nua|nu|nude|naked)\b/u', $t);
}

function studio_tattoo_image_translate_request(string $text): string
{
    $text = trim($text);
    if ($text === '') return '';
    $basic = studio_tattoo_image_translate_basic($text);
    if (mb_strlen($text, 'UTF-8') <= 40 && $basic !== $text) return $basic;
    if (!function_exists('curl_init')) return $basic;

    $body = [
        'model' => trim((string)(getenv('LOCAL_IMAGE_PROMPT_MODEL') ?: 'llama3.2:3b')),
        'stream' => false,
        'think' => false,
        'keep_alive' => 0,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Translate the user request into concise literal English for an image generator. Preserve the exact requested subject. Do not add people, bodies, nudity, tattoos on skin, scenery, props, or style details unless the user explicitly requested them. Return only the English translation.',
            ],
            ['role' => 'user', 'content' => $basic],
        ],
        'options' => ['temperature' => 0.0, 'num_predict' => 80, 'num_gpu' => 0],
    ];
    $ch = curl_init('http://127.0.0.1:11434/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    $translated = is_array($json) ? trim((string)($json['message']['content'] ?? '')) : '';
    $translated = trim((string)preg_replace('/<think>.*?<\/think>/is', '', $translated));
    return ($status >= 200 && $status < 300 && $translated !== '') ? studio_tattoo_image_translate_basic($translated) : $basic;
}

function studio_tattoo_image_subject_guard(string $request): array
{
    $t = studio_tattoo_image_norm($request);
    $animals = ['leao'=>'lion','lion'=>'lion','tigre'=>'tiger','tiger'=>'tiger','lobo'=>'wolf','wolf'=>'wolf','onca'=>'jaguar','jaguar'=>'jaguar','aguia'=>'eagle','eagle'=>'eagle','coruja'=>'owl','owl'=>'owl','cachorro'=>'dog','dog'=>'dog','gato'=>'cat','cat'=>'cat','cobra'=>'snake','snake'=>'snake','jacare'=>'crocodile','crocodilo'=>'crocodile','alligator'=>'alligator','macaco'=>'monkey','monkey'=>'monkey','mico'=>'small monkey','chimpanze'=>'chimpanzee','chipanze'=>'chimpanzee','chimpanzee'=>'chimpanzee','gorila'=>'gorilla','gorilla'=>'gorilla','orangotango'=>'orangutan','orangutan'=>'orangutan','bonobo'=>'bonobo'];
    foreach ($animals as $needle => $animal) {
        if (preg_match('/\b' . preg_quote($needle, '/') . 's?\b/u', $t)) {
            $details = [];
            if ($animal === 'crocodile' || $animal === 'alligator') {
                $details[] = 'real crocodile anatomy';
                $details[] = 'long snout fully visible';
                $details[] = 'reptile body proportions';
            }
            if (str_contains($t, 'frontal') || str_contains($t, 'de frente')) $details[] = 'front view';
            if (str_contains($t, 'cabeca completa')) $details[] = 'complete head fully visible';
            if (str_contains($t, 'rugindo') || str_contains($t, 'roaring')) $details[] = 'roaring expression';
            if (str_contains($t, 'coroa')) $details[] = 'royal crown accessory on the animal';
            return [
                'lock' => 'STRICT SUBJECT LOCK: the only main subject is exactly one real ' . $animal . '. Do not replace it with a human, tattooed model, body, costume, statue, robot or unrelated character. Use natural animal anatomy and natural animal posture, not a humanoid body. Preserve the animal species, anatomy, pose, angle, expression and requested accessories' . ($details ? ': ' . implode(', ', $details) : '') . '.',
                'negative' => 'wrong subject, different animal, unrelated character, anthropomorphic, humanoid, human anatomy, muscular human torso, human chest, abs, costume, mannequin, statue, robot, text, watermark, ' . studio_tattoo_image_non_human_negative()
            ];
        }
    }
    return [
        'lock' => 'STRICT SUBJECT LOCK: preserve the exact requested main subject, anatomy, identity, pose, angle, expression and requested accessories. Do not invent a different subject.',
        'negative' => 'wrong subject, unrelated character, text, watermark'
    ];
}

function studio_tattoo_image_literal_subject_hint(string $request): string
{
    $t = studio_tattoo_image_norm($request);
    $map = [
        '/\b(macaco|monkey|mico)\b/u' => 'Main subject must be a monkey.',
        '/\b(chimpanze|chipanze|chimpanz[eé]|chipanz[eé]|chimpanzee)\b/u' => 'Main subject must be a chimpanzee.',
        '/\b(gorila|gorilla)\b/u' => 'Main subject must be a gorilla.',
        '/\b(jacare|jacaré|crocodilo|alligator)\b/u' => 'Main subject must be a crocodile or alligator.',
        '/\b(pirata|pirate)\b/u' => 'Main subject must be a recognizable classic pirate: pirate hat or bandana, pirate coat or worn nautical clothing, adventurous seafarer look, period pirate details.',
        '/\b(saci(?:-?perer[eê])?)\b/u' => 'Main subject must be the Brazilian folklore character Saci-Perere.',
        '/\bmula sem cab[eê]ca\b/u' => 'Main subject must be the Brazilian folklore headless mule.',
        '/\bcurupira\b/u' => 'Main subject must be the Brazilian folklore character Curupira.',
    ];
    foreach ($map as $pattern => $hint) {
        if (preg_match($pattern, $t)) return $hint;
    }
    return '';
}

function studio_tattoo_image_allows_tattoo_or_model_bias(string $request): bool
{
    $t = studio_tattoo_image_norm($request);
    return (bool)preg_match('/\b(tattoo|tatuagem|tatuado|tatuada|pele|skin|modelo|model|ensaio|foto de corpo|body photo|shirtless|sem camisa|nu|nude|nua|naked)\b/u', $t);
}

function studio_tattoo_image_subject_hint(string $request): string
{
    $t = studio_tattoo_image_norm($request);
    $patterns = [
        '/\bsaci(?:-?perer[eê])?\b/u' => 'Brazilian folklore character Saci-Pererê, one-legged mischievous figure, red cap, smoking pipe, dark curly hair, recognizable folkloric silhouette, playful but eerie expression',
        '/\bmula sem cab[eê]ca\b/u' => 'Brazilian folklore headless mule, horse body without a head, flames or smoke from the neck, dramatic folklore silhouette, no human face',
        '/\bcurupira\b/u' => 'Brazilian folklore Curupira, red hair, backward feet, forest guardian, mischievous posture, unmistakable folkloric icon',
    ];
    foreach ($patterns as $pattern => $hint) {
        if (preg_match($pattern, $t)) {
            return $hint;
        }
    }
    return '';
}

function studio_tattoo_image_subject_brief(string $request): array
{
    $t = studio_tattoo_image_norm($request);
    $request = trim($request);
    $view = [];
    if (preg_match('/\b(frontal|de frente|front view|face front)\b/u', $t)) {
        $view[] = 'front view';
    }
    if (preg_match('/\b(perfil|lateral|de lado|side view)\b/u', $t)) {
        $view[] = 'side view';
    }
    if (preg_match('/\b(3\/4|tr[êe]s quartos|three-quarter)\b/u', $t)) {
        $view[] = 'three-quarter view';
    }
    if (preg_match('/\b(corpo inteiro|full body|inteiro)\b/u', $t)) {
        $view[] = 'full body visible';
    }

    $accessories = [];
    if (preg_match('/\b(com|with)\s+rosas?\b/u', $t) || preg_match('/\brosas?\b/u', $t)) {
        $accessories[] = 'roses';
    }
    if (preg_match('/\b(com|with)\s+fogo\b/u', $t) || preg_match('/\bfogo\b/u', $t)) {
        $accessories[] = 'fire';
    }
    if (preg_match('/\b(com|with)\s+coroa\b/u', $t) || preg_match('/\bcoroa\b/u', $t)) {
        $accessories[] = 'crown';
    }
    if (preg_match('/\b(com|with)\s+fuma[çc]a\b/u', $t) || preg_match('/\bfuma[çc]a\b/u', $t)) {
        $accessories[] = 'smoke';
    }
    if (preg_match('/\b(com|with)\s+corrente\b/u', $t) || preg_match('/\bcorrente\b/u', $t)) {
        $accessories[] = 'chain';
    }

    $humanNegative = studio_tattoo_image_non_human_negative() . ', clothing';
    $genericNegative = 'wrong subject, unrelated character, random person, text, watermark, logo';

    $folklore = [
        '/\bsaci(?:-?perer[eê])?\b/u' => [
            'kind' => 'folklore',
            'prompt' => 'full-body Brazilian folklore character Saci-Pererê, one-legged mischievous figure, red cap, smoking pipe, dark curly hair, recognizable folkloric silhouette, playful but eerie expression, centered tattoo reference, neutral background',
            'negative' => $humanNegative . ', extra legs, generic man, generic woman, modern clothing',
        ],
        '/\bmula sem cab[eê]ca\b/u' => [
            'kind' => 'folklore',
            'prompt' => 'Brazilian folklore headless mule, horse body without a head, flames or smoke coming from the neck, dramatic silhouette, fully visible, centered tattoo reference, neutral background',
            'negative' => $humanNegative . ', human face, human torso, rider, extra heads',
        ],
        '/\bcurupira\b/u' => [
            'kind' => 'folklore',
            'prompt' => 'Brazilian folklore Curupira, red hair, backward feet, forest guardian, mischievous posture, unmistakable folkloric icon, centered tattoo reference, neutral background',
            'negative' => $humanNegative . ', random man, random woman, normal feet, extra limbs',
        ],
    ];
    foreach ($folklore as $pattern => $brief) {
        if (preg_match($pattern, $t)) {
            return $brief;
        }
    }

    $animals = [
        '/\b(le[aã]o|lion)\b/u' => ['lion', 'single real lion, full body visible, strong mane, centered composition, clear tattoo reference, neutral background'],
        '/\b(tigre|tiger)\b/u' => ['tiger', 'single real tiger, full body visible, sharp stripes, centered composition, clear tattoo reference, neutral background'],
        '/\b(lobo|wolf)\b/u' => ['wolf', 'single real wolf, full body visible, intense eyes, centered composition, clear tattoo reference, neutral background'],
        '/\b(on[cç]a|jaguar)\b/u' => ['jaguar', 'single real jaguar, full body visible, spotted coat, centered composition, clear tattoo reference, neutral background'],
        '/\b(aguia|águia|eagle)\b/u' => ['eagle', 'single real eagle, wings visible if requested, powerful beak, centered composition, clear tattoo reference, neutral background'],
        '/\b(coruja|owl)\b/u' => ['owl', 'single real owl, round eyes, feathers clearly visible, centered composition, clear tattoo reference, neutral background'],
        '/\b(cachorro|dog)\b/u' => ['dog', 'single real dog, faithful anatomy, full body visible, centered composition, clear tattoo reference, neutral background'],
        '/\b(gato|cat)\b/u' => ['cat', 'single real cat, clean silhouette, full body visible, centered composition, clear tattoo reference, neutral background'],
        '/\b(cobra|snake)\b/u' => ['snake', 'single real snake, coiled or stretched body, clear scales, centered composition, clear tattoo reference, neutral background'],
        '/\b(jacare|jacaré|crocodilo|alligator)\b/u' => ['crocodile', 'single real crocodile, full body visible, long snout, reptile scales, sharp teeth, centered composition, clear tattoo reference, neutral background'],
        '/\b(macaco|monkey|mico)\b/u' => ['monkey', 'single real monkey, natural primate anatomy, natural animal posture, expressive face, visible fur, centered composition, clear tattoo reference, neutral background'],
        '/\b(chimpanze|chipanze|chimpanz[eé]|chipanz[eé]|chimpanzee)\b/u' => ['chimpanzee', 'single real chimpanzee, natural primate anatomy, natural seated or knuckle-walking posture, dark fur, expressive chimpanzee face, long arms, no human torso, centered composition, clear tattoo reference, neutral background'],
        '/\b(gorila|gorilla)\b/u' => ['gorilla', 'single real gorilla, natural primate anatomy, natural animal posture, powerful shoulders, dark fur, expressive face, centered composition, clear tattoo reference, neutral background'],
        '/\b(orangotango|orangutan)\b/u' => ['orangutan', 'single real orangutan, natural primate anatomy, natural animal posture, orange fur, expressive face, long arms, centered composition, clear tattoo reference, neutral background'],
        '/\b(bonobo)\b/u' => ['bonobo', 'single real bonobo, natural primate anatomy, natural animal posture, dark fur, expressive face, centered composition, clear tattoo reference, neutral background'],
    ];
    foreach ($animals as $pattern => [$label, $prompt]) {
        if (preg_match($pattern, $t)) {
            $prompt .= $view ? ', ' . implode(', ', $view) : '';
            if ($accessories) {
                $prompt .= ', ' . implode(', ', $accessories);
            }
            return [
                'kind' => 'animal',
                'prompt' => $prompt,
                'negative' => $humanNegative . ', anthropomorphic, humanoid, human anatomy, muscular human torso, human chest, abs, costume, mannequin, extra limbs, extra heads, text, watermark',
            ];
        }
    }

    $objects = [
        '/\b(rel[oó]gio de bolso|pocket watch)\b/u' => 'single antique pocket watch with chain, fully visible, centered, detailed metal casing, clean contour lines, clear tattoo reference, neutral background',
        '/\b(rel[oó]gio|clock|watch)\b/u' => 'single antique clock, fully visible, centered, detailed metal casing, clean contour lines, clear tattoo reference, neutral background',
        '/\b(caveira|skull)\b/u' => 'single human skull, fully visible, centered, high-contrast tattoo reference, clean contour lines, neutral background',
        '/\b(b[uú]ssola|compass)\b/u' => 'single antique compass, fully visible, centered, precise engraved details, clean contour lines, clear tattoo reference, neutral background',
        '/\b(adaga|dagger)\b/u' => 'single dagger, fully visible, centered, sharp blade, clean contour lines, clear tattoo reference, neutral background',
        '/\b(espada|sword)\b/u' => 'single sword, fully visible, centered, clean contour lines, clear tattoo reference, neutral background',
        '/\b(coroa|crown)\b/u' => 'single crown, fully visible, centered, clean contour lines, clear tattoo reference, neutral background',
        '/\b(cruz|cross)\b/u' => 'single cross, fully visible, centered, clean contour lines, clear tattoo reference, neutral background',
        '/\b(lua|moon)\b/u' => 'single crescent moon, fully visible, centered, clean contour lines, clear tattoo reference, neutral background',
        '/\b(sol|sun)\b/u' => 'single sun symbol, fully visible, centered, clean contour lines, clear tattoo reference, neutral background',
        '/\b(cora[cç][aã]o|heart)\b/u' => 'single heart, fully visible, centered, clean contour lines, clear tattoo reference, neutral background',
        '/\b(pena|feather)\b/u' => 'single feather, fully visible, centered, fine contour lines, clear tattoo reference, neutral background',
        '/\b(borboleta|butterfly)\b/u' => 'single butterfly, fully visible, centered, clean wings, clear tattoo reference, neutral background',
        '/\b(corvo|raven)\b/u' => 'single raven, fully visible, centered, sharp silhouette, clear tattoo reference, neutral background',
    ];
    foreach ($objects as $pattern => $prompt) {
        if (preg_match($pattern, $t)) {
            if (preg_match('/\bcaveira\b/u', $t) && preg_match('/\brosa|rosas\b/u', $t)) {
                $prompt = 'single human skull with roses, fully visible, centered, high-contrast tattoo reference, clean contour lines, neutral background';
            } elseif ($accessories) {
                $prompt .= ', ' . implode(', ', $accessories);
            }
            $prompt .= $view ? ', ' . implode(', ', $view) : '';
            return [
                'kind' => 'object',
                'prompt' => $prompt,
                'negative' => $humanNegative . ', body mockup, skin, arm, leg, torso, face close-up, person, portrait, clothing, text, watermark',
            ];
        }
    }

    if (preg_match('/\b(retrato|portrait|rosto|face|homem|mulher|menino|menina|personagem|guerreiro|samurai|cowboy|pirata|bruxa|mago|anjo|demonio|dem[ôo]nio)\b/u', $t)) {
        return [
            'kind' => 'character',
            'prompt' => 'single human character tattoo reference, fully visible, centered, readable facial expression, clean contour lines, neutral background' . ($view ? ', ' . implode(', ', $view) : ''),
            'negative' => 'random animal, extra limbs, extra heads, blurry face, duplicated face, text, watermark, logo',
        ];
    }

    $translatedRequest = studio_tattoo_image_translate_request($request);
    return [
        'kind' => 'generic',
        'prompt' => 'single isolated tattoo subject matching exactly this request: ' . $translatedRequest . ', fully visible, centered, clear silhouette, easy to redraw, neutral background' . ($view ? ', ' . implode(', ', $view) : '') . ($accessories ? ', ' . implode(', ', $accessories) : ''),
        'negative' => $genericNegative . (studio_tattoo_image_mentions_human_or_mockup($request) ? '' : ', ' . studio_tattoo_image_non_human_negative()),
        'translated' => $translatedRequest,
    ];
}

function studio_tattoo_image_style_prompt(string $style): string
{
    return match ($style) {
        'realistic' => 'realistic high quality image',
        'cartoon' => 'cartoon style, expressive shapes, clear readable character design',
        'illustration' => 'polished digital illustration, clean composition, rich visual detail',
        'cinematic' => 'cinematic image, dramatic lighting, high detail, strong atmosphere',
        'anime' => 'anime style illustration, clean lines, expressive design',
        'stencil' => 'black and white stencil style, clean contour lines, high contrast',
        'blackwork' => 'blackwork style, strong silhouettes, balanced negative space',
        'chicano' => 'chicano inspired black and grey style, dramatic contrast',
        'fineline' => 'fine line style, delicate clean linework, elegant minimal detail',
        'oldschool' => 'old school flash style, bold linework, iconic simplified forms',
        'reference' => 'clean visual reference, clear subject, readable details',
        default => 'natural image generation, follow the user prompt literally',
    };
}

function studio_tattoo_image_translate_basic(string $text): string
{
    $r = $text;
    $pairs = [
        'cabeça completa' => 'complete head', 'cabeca completa' => 'complete head', 'frontal' => 'front view', 'de frente' => 'front view', 'rugindo' => 'roaring',
        'leão' => 'lion', 'leao' => 'lion', 'tigre' => 'tiger', 'lobo' => 'wolf', 'onça' => 'jaguar', 'onca' => 'jaguar', 'águia' => 'eagle', 'aguia' => 'eagle',
        'coroa de rei' => 'royal king crown', 'coroa' => 'crown', 'realista' => 'realistic', 'preto e branco' => 'black and grey',
        'jacaré' => 'crocodile', 'jacare' => 'crocodile', 'crocodilo' => 'crocodile', 'alligator' => 'alligator',
        'chimpanzeee' => 'chimpanzee', 'chimpanzé' => 'chimpanzee', 'chimpanze' => 'chimpanzee', 'chipanzé' => 'chimpanzee', 'chipanze' => 'chimpanzee',
        'macaco' => 'monkey', 'mico' => 'small monkey', 'gorila' => 'gorilla', 'orangotango' => 'orangutan', 'bonobo' => 'bonobo',
        'caveira' => 'skull', 'crânio' => 'skull', 'cranio' => 'skull', 'rosa' => 'rose', 'rosas' => 'roses', 'flor' => 'flower', 'flores' => 'flowers',
        'borboleta' => 'butterfly', 'corvo' => 'raven', 'pena' => 'feather', 'adaga' => 'dagger', 'espada' => 'sword', 'relógio' => 'clock', 'relogio' => 'clock',
        'bússola' => 'compass', 'bussola' => 'compass', 'lua' => 'moon', 'sol' => 'sun', 'coração' => 'heart', 'coracao' => 'heart',
        'pirata' => 'pirate', 'cartunesco' => 'cartoon', 'desenho animado' => 'cartoon', 'cartoonizado' => 'cartoon',
        'alterar' => 'change', 'mudar' => 'change', 'transformar' => 'transform', 'estilo' => 'style', 'mais ' => 'more ',
        'pequena' => 'small', 'pequeno' => 'small', 'grande' => 'large', 'gigante' => 'giant', 'corpo inteiro' => 'full body', 'inteiro' => 'full body',
        'para um ' => 'to a ', 'para uma ' => 'to a ', 'para o ' => 'to the ', 'para a ' => 'to the ', 'para ' => 'to ',
        'com ' => 'with ', 'sem ' => 'without ', ' o ' => ' the ', ' a ' => ' the ', 'um ' => 'a ', 'uma ' => 'a '
    ];
    foreach ($pairs as $from => $to) {
        $r = str_ireplace($from, $to, $r);
    }
    $r = str_ireplace('chimpanzeee', 'chimpanzee', $r);
    return $r;
}

function studio_tattoo_image_dimensions_for_format(array $config, string $format): array
{
    if ($format === 'square') {
        $size = min((int)$config['width'], (int)$config['height']);
        return [$size, $size];
    }
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
    return studio_general_image_prompt($data);
}

function studio_tattoo_image_body(array $data, string $mode): array
{
    return studio_general_image_local_body($data, $mode);
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
                flash_set('success', 'Superresolucao 4x criada.');
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
        if (($poll['status'] ?? '') === 'completed' && is_array($poll['result'] ?? null)) { $_SESSION['studio_tattoo_image_result'] = $poll['result']; studio_tattoo_image_clear_job(); }
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
        $selectedMode = studio_tattoo_image_choice((string)($formState['mode'] ?? $job['mode'] ?? 'fast'), ['fast','final'], 'fast');
        $selectedStyle = studio_tattoo_image_choice((string)($formState['style'] ?? $job['style'] ?? $result['style'] ?? 'auto'), studio_tattoo_image_allowed_styles(), 'auto');
        $selectedFormat = studio_tattoo_image_choice((string)($formState['format'] ?? $job['format'] ?? $result['format'] ?? 'vertical'), ['vertical','square','wide'], 'vertical');
        $referenceNotes = trim((string)($formState['reference_notes'] ?? $job['reference_notes'] ?? $result['reference_notes'] ?? ''));
        $negativePrompt = trim((string)($formState['negative_prompt'] ?? $job['negative_prompt'] ?? $result['negative_prompt'] ?? ''));
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
            echo '<div class="field"><label>Modo</label><select name="mode"><option value="fast"' . ($selectedMode === 'fast' ? ' selected' : '') . '>Rapido</option><option value="final"' . ($selectedMode === 'final' ? ' selected' : '') . '>Mais detalhado</option></select></div>';
            echo '<div class="field"><label>Estilo</label><select name="style">';
            foreach (['auto'=>'Livre pelo prompt','realistic'=>'Realista','cartoon'=>'Cartoon','illustration'=>'Ilustracao','cinematic'=>'Cinematico','anime'=>'Anime','reference'=>'Referencia limpa','stencil'=>'Stencil','blackwork'=>'Blackwork','chicano'=>'Chicano','fineline'=>'Fine line','oldschool'=>'Old school'] as $value => $label) {
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
            echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="upscale_tattoo_image"><input type="hidden" name="history_id" value="' . h((string)($result['history_id'] ?? '')) . '"><button class="btn ti-mini-btn" type="submit">Super 4x</button></form>';
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
                echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="upscale_tattoo_image"><input type="hidden" name="history_id" value="' . h((string)($item['history_id'] ?? '')) . '"><button class="btn secondary" type="submit">4x</button></form>';
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
