<?php

namespace App\Support;

use App\Exceptions\WhatsappOperationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Outbound WhatsApp media (RF-44, D11): only `https` URLs whose host resolves
 * exclusively to public addresses (the `McpUrlValidator` rule, always applied
 * here — `MCP_ALLOW_PRIVATE_HOSTS` is MCP-only), fetched without redirects
 * and capped per type by `services.wuzapi.media_max_bytes`. Any failure is
 * `whatsapp_media_invalid`.
 */
class WhatsappMediaFetcher
{
    /**
     * @var list<string>
     */
    public const array KINDS = ['image', 'video', 'audio', 'document'];

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly McpUrlValidator $urlValidator,
        private readonly Settings $settings,
    ) {}

    /**
     * Check the URL without downloading it.
     *
     * @throws WhatsappOperationException
     */
    public function assertAcceptable(string $url, string $kind): void
    {
        $this->resolvePublicAddresses($url, $kind);
    }

    /**
     * Download the media and return it as a data URI for wuzapi.
     *
     * @return array{mimetype: string, bytes: int, data_uri: string}
     *
     * @throws WhatsappOperationException
     */
    public function fetch(string $url, string $kind): array
    {
        [$host, $port, $addresses] = $this->resolvePublicAddresses($url, $kind);
        $maxBytes = $this->maxBytes($kind);

        try {
            $response = Http::withoutRedirecting()
                ->timeout($this->timeoutSeconds())
                ->connectTimeout($this->timeoutSeconds())
                // Pin the connection to the validated addresses (DNS rebinding).
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".$this->curlAddresses($addresses)]]])
                ->get($url);
        } catch (ConnectionException) {
            throw $this->invalid();
        }

        if ($response->status() !== 200) {
            throw $this->invalid();
        }

        $declaredLength = $response->header('Content-Length');

        if ($declaredLength !== '' && (int) $declaredLength > $maxBytes) {
            throw $this->invalid();
        }

        $body = $response->body();
        $bytes = strlen($body);

        if ($bytes === 0 || $bytes > $maxBytes) {
            throw $this->invalid();
        }

        $mimetype = $this->mimetypeFor((string) $response->header('Content-Type'), $body);

        if ($kind !== 'document' && ! str_starts_with($mimetype, $kind.'/')) {
            throw $this->invalid();
        }

        return [
            'mimetype' => $mimetype,
            'bytes' => $bytes,
            'data_uri' => "data:{$mimetype};base64,".base64_encode($body),
        ];
    }

    public function maxBytes(string $kind): int
    {
        return (int) config("services.wuzapi.media_max_bytes.{$kind}", 0);
    }

    /**
     * @return array{string, int, list<string>} host, port and every resolved address
     *
     * @throws WhatsappOperationException
     */
    private function resolvePublicAddresses(string $url, string $kind): array
    {
        if (! in_array($kind, self::KINDS, true) || $this->maxBytes($kind) <= 0) {
            throw $this->invalid();
        }

        $parts = parse_url(trim($url));

        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || blank($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw $this->invalid();
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));

        if ($this->urlValidator->isPrivateHost($host)) {
            throw $this->invalid();
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw $this->invalid();
        }

        foreach ($addresses as $address) {
            if ($this->isPrivateAddress($address)) {
                throw $this->invalid();
            }
        }

        return [$host, (int) ($parts['port'] ?? 443), $addresses];
    }

    private function isPrivateAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        // IPv4-mapped IPv6 (`::ffff:10.0.0.5`) hides the IPv4 range from the IPv6 flags.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $matches) === 1) {
            return $this->urlValidator->isPrivateHost($matches[1]);
        }

        return $this->urlValidator->isPrivateHost($address)
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false;
    }

    /**
     * @param  list<string>  $addresses
     */
    private function curlAddresses(array $addresses): string
    {
        return implode(',', array_map(
            fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
            $addresses,
        ));
    }

    private function mimetypeFor(string $contentType, string $body): string
    {
        $mimetype = strtolower(trim(explode(';', $contentType)[0]));

        if ($mimetype === '' || $mimetype === 'application/octet-stream' || $mimetype === 'binary/octet-stream') {
            $mimetype = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        }

        return $mimetype !== '' ? $mimetype : 'application/octet-stream';
    }

    private function timeoutSeconds(): int
    {
        return max(1, min((int) config('services.wuzapi.timeout_seconds', 15), $this->settings->toolTimeoutSeconds()));
    }

    private function invalid(): WhatsappOperationException
    {
        return new WhatsappOperationException(WhatsappOperationException::MEDIA_INVALID);
    }
}
