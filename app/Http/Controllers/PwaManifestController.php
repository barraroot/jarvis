<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * GET /manifest.webmanifest — Web App Manifest that makes the SPA installable (US-2.5).
 */
class PwaManifestController extends Controller
{
    public const THEME_COLOR = '#050a12';

    public function __invoke(): JsonResponse
    {
        return response()
            ->json($this->manifest(), options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     short_name: string,
     *     description: string,
     *     lang: string,
     *     start_url: string,
     *     scope: string,
     *     display: string,
     *     orientation: string,
     *     theme_color: string,
     *     background_color: string,
     *     icons: list<array{src: string, sizes: string, type: string, purpose: string}>
     * }
     */
    private function manifest(): array
    {
        return [
            'id' => '/',
            'name' => 'J.A.R.V.I.S.',
            'short_name' => 'JARVIS',
            'description' => 'Assistente pessoal J.A.R.V.I.S.',
            'lang' => 'pt-BR',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'theme_color' => self::THEME_COLOR,
            'background_color' => self::THEME_COLOR,
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
    }
}
