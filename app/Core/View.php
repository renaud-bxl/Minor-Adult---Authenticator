<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Moteur de vues PHP : un gabarit est rendu dans une portée isolée, puis inséré dans un layout
 * (variable $content). Les données partagées (langue, jeton CSRF…) sont visibles de tous les gabarits.
 * L'échappement est explicite dans les gabarits via e().
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $directory)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $data = [...$this->shared, ...$data];
        $content = $this->renderFile($template, $data);

        return $layout === null ? $content : $this->renderFile($layout, [...$data, 'content' => $content]);
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        if (preg_match('#^[a-z0-9_]+(?:/[a-z0-9_.]+)*$#', $template) !== 1 || str_contains($template, '..')) {
            throw new \InvalidArgumentException('Nom de gabarit invalide.');
        }
        $file = $this->directory . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Gabarit introuvable : ' . $template);
        }

        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
