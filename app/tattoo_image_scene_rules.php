<?php

declare(strict_types=1);

function studio_tattoo_scene_has_many_parts(string $text): bool
{
    $t = mb_strtolower($text, 'UTF-8');
    $hits = 0;
    foreach (['jesus','cristo','cordeiro','ovelha','lobo','leao','leão','tigre','homem','mulher','crianca','criança','animal'] as $word) {
        if (str_contains($t, $word)) $hits++;
    }
    return $hits >= 2;
}

function studio_tattoo_scene_prompt_guard(string $text): string
{
    if (!studio_tattoo_scene_has_many_parts($text)) {
        return 'Preserve the exact main subject requested by the user. Do not reinterpret or replace it.';
    }
    return 'This is a scene with multiple required elements. Every important element mentioned by the user must appear clearly in the image. Do not focus on only one subject. Preserve the action, relationship, hierarchy, and visual meaning described by the user. If the user describes protection, show protector, protected subject, and threat clearly.';
}

function studio_tattoo_scene_negative_guard(string $text): string
{
    if (!studio_tattoo_scene_has_many_parts($text)) {
        return 'wrong subject, unrelated subject';
    }
    return 'missing required element, single subject only, wrong scene, unrelated subject';
}
