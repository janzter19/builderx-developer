<?php
declare(strict_types=1);

function builderxTemplateAssetHtml(string $manifestPath, string $assetsBase): string
{
    if (!is_file($manifestPath)) {
        return '';
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        return '';
    }

    $entry = $manifest['index.html'] ?? null;
    if (!is_array($entry) || empty($entry['css']) || !is_array($entry['css'])) {
        return '';
    }

    $html = [];
    foreach ($entry['css'] as $css) {
        $href = rtrim($assetsBase, '/') . '/' . ltrim((string) $css, '/');
        $html[] = '<link rel="stylesheet" data-builderx-shared-template="frontend" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
    }

    return implode("\n    ", $html);
}
