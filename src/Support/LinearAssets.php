<?php

declare(strict_types=1);

namespace Dniccum\Linear\Support;

use Illuminate\Support\HtmlString;

/**
 * The `<link>`/`<script>` tags for the configuration page's front-end bundle.
 *
 * In production they come from the Vite manifest the bundle was built with. In
 * development (`linear.vite_dev_url`) they point at the running Vite dev
 * server. When neither is available a comment explains why nothing loaded.
 */
class LinearAssets
{
    /**
     * The Vite entry the page boots from.
     */
    public const string ENTRY = 'resources/js/main.ts';

    /**
     * Where Vite files the stylesheet when CSS code splitting is off.
     */
    public const string STYLESHEET = 'style.css';

    public function __construct(
        private readonly string $manifestPath,
    ) {}

    public function render(): HtmlString
    {
        $devUrl = Json::nullableString(config('linear.vite_dev_url'));

        return new HtmlString($devUrl !== null ? $this->development(rtrim($devUrl, '/')) : $this->production());
    }

    private function development(string $url): string
    {
        return implode("\n", [
            '<script type="module" src="'.e($url).'/@vite/client"></script>',
            '<script type="module" src="'.e($url.'/'.self::ENTRY).'"></script>',
        ]);
    }

    private function production(): string
    {
        $manifest = $this->manifest();
        $entry = Json::map($manifest[self::ENTRY] ?? null);
        $file = Json::nullableString($entry['file'] ?? null);

        if ($file === null) {
            return '<!-- Linear assets are not built: run "npm run build" in the package, or publish them with "php artisan vendor:publish --tag=linear-assets". -->';
        }

        $stylesheets = [];
        $imports = [];
        $this->collect($manifest, self::ENTRY, $stylesheets, $imports);

        $stylesheetFile = Json::nullableString(Json::map($manifest[self::STYLESHEET] ?? null)['file'] ?? null);

        if ($stylesheetFile !== null) {
            $stylesheets[] = $stylesheetFile;
        }

        $tags = [];

        foreach (array_unique($stylesheets) as $stylesheet) {
            $tags[] = '<link rel="stylesheet" href="'.e($this->url($stylesheet)).'">';
        }

        foreach (array_unique($imports) as $import) {
            $tags[] = '<link rel="modulepreload" href="'.e($this->url($import)).'">';
        }

        $tags[] = '<script type="module" src="'.e($this->url($file)).'"></script>';

        return implode("\n", $tags);
    }

    /**
     * Gather the stylesheets of a chunk and of everything it imports, and the
     * imported chunks themselves.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $stylesheets
     * @param  list<string>  $imports
     */
    private function collect(array $manifest, string $key, array &$stylesheets, array &$imports): void
    {
        $chunk = Json::map($manifest[$key] ?? null);

        array_push($stylesheets, ...Json::strings($chunk['css'] ?? null));

        foreach (Json::strings($chunk['imports'] ?? null) as $import) {
            $file = Json::nullableString(Json::map($manifest[$import] ?? null)['file'] ?? null);

            if ($file !== null && ! in_array($file, $imports, true)) {
                $imports[] = $file;
                $this->collect($manifest, $import, $stylesheets, $imports);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $contents = is_file($this->manifestPath) ? file_get_contents($this->manifestPath) : false;

        return Json::map($contents === false ? null : json_decode($contents, true));
    }

    private function url(string $file): string
    {
        return asset(trim(config()->string('linear.assets_path', 'vendor/linear'), '/').'/'.ltrim($file, '/'));
    }
}
