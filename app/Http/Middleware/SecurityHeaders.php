<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers (project-description §Segurança): every response gets
 * `nosniff` and a strict `Referrer-Policy`; the SPA shell and the backend
 * pages are never framed (`X-Frame-Options: DENY`); the SPA shell also gets
 * a `Content-Security-Policy` allowing only itself, the Reverb WebSocket host
 * and Bunny Fonts (plus the Vite dev server while it runs hot).
 */
class SecurityHeaders
{
    public const string BUNNY_FONTS = 'https://fonts.bunny.net';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');

        if ($this->isSpaShell($request)) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        return $response;
    }

    /**
     * The Vue shell is served by the `spa` route and the catch-all fallback.
     */
    private function isSpaShell(Request $request): bool
    {
        $route = $request->route();

        return $route !== null && ($route->named('spa') || $route->isFallback);
    }

    public function contentSecurityPolicy(): string
    {
        $devServer = $this->viteDevServer();

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'none'"],
            'form-action' => ["'self'"],
            'script-src' => ["'self'", ...$devServer],
            'style-src' => ["'self'", "'unsafe-inline'", self::BUNNY_FONTS, ...$devServer],
            'font-src' => ["'self'", 'data:', self::BUNNY_FONTS, ...$devServer],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'media-src' => ["'self'", 'blob:'],
            'connect-src' => ["'self'", ...$this->reverbOrigins(), ...$this->devServerSockets($devServer)],
            'worker-src' => ["'self'"],
            'manifest-src' => ["'self'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', array_unique($sources)))
            ->implode('; ');
    }

    /**
     * WebSocket origin(s) of the Reverb server the SPA connects to: the
     * browser-facing address (`services.reverb.public`) when set, otherwise
     * the broadcasting host the backend publishes to.
     *
     * @return list<string>
     */
    private function reverbOrigins(): array
    {
        $public = config('services.reverb.public', []);
        $options = filled($public['host'] ?? null)
            ? $public
            : config('broadcasting.connections.reverb.options', []);
        $host = $options['host'] ?? null;

        if (blank($host)) {
            return [];
        }

        $secure = ($options['scheme'] ?? 'https') === 'https';
        $port = (int) ($options['port'] ?? ($secure ? 443 : 80));
        $defaultPort = $secure ? 443 : 80;
        $authority = $port === $defaultPort ? $host : $host.':'.$port;

        return [($secure ? 'wss://' : 'ws://').$authority];
    }

    /**
     * @return list<string>
     */
    private function viteDevServer(): array
    {
        if (! Vite::isRunningHot()) {
            return [];
        }

        $url = trim((string) file_get_contents(Vite::hotFile()));

        return filled($url) ? [$url] : [];
    }

    /**
     * @param  list<string>  $devServer
     * @return list<string>
     */
    private function devServerSockets(array $devServer): array
    {
        return array_merge($devServer, array_map(
            fn (string $url): string => preg_replace('/^http/', 'ws', $url),
            $devServer,
        ));
    }
}
