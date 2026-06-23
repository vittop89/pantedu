<?php

namespace App\Core;

final class View
{
    public function __construct(private string $viewsPath)
    {
    }

    /**
     * Renders a PHP template with the given data. Template receives
     * $this (the View) and the data keys as local variables. The
     * built-in `e()` helper is available for escaping.
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->viewsPath . '/' . ltrim($template, '/') . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("view_not_found:$template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }

    public static function default(): self
    {
        $path = Config::get('app.paths.views')
              ?? dirname(__DIR__, 2) . '/views';
        return new self($path);
    }
}
