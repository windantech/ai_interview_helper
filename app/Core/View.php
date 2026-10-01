<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Renders PHP templates from /views with extracted variables.
 */
final class View
{
    public static function render(string $template, array $data = []): void
    {
        $file = dirname(__DIR__, 2) . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        extract($data, EXTR_SKIP);
        require $file;
    }

    /** Render a page inside the standard layout (header + sidebar/nav + footer). */
    public static function page(string $template, array $data = []): void
    {
        $data['pageTemplate'] = $template;
        self::render('layouts/app', $data);
    }

    public static function guest(string $template, array $data = []): void
    {
        $data['pageTemplate'] = $template;
        self::render('layouts/guest', $data);
    }
}
