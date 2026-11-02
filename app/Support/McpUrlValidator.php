<?php

namespace App\Support;

use App\Exceptions\InvalidMcpUrlException;

/**
 * Validates MCP connector URLs (US-9.1/9.2): only `http(s)://` endpoints, and
 * no private, loopback or link-local hosts unless `MCP_ALLOW_PRIVATE_HOSTS`
 * is enabled (local development).
 */
class McpUrlValidator
{
    /**
     * The validation error for the URL, or null when it is acceptable.
     */
    public function errorFor(?string $url): ?string
    {
        if (blank($url)) {
            return 'A URL do conector é obrigatória.';
        }

        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || blank($parts['host'] ?? null)) {
            return 'A URL do conector deve usar http:// ou https:// (stdio não é suportado).';
        }

        if (! $this->allowsPrivateHosts() && $this->isPrivateHost((string) $parts['host'])) {
            return 'Hosts privados ou locais não são permitidos para conectores MCP.';
        }

        return null;
    }

    /**
     * @throws InvalidMcpUrlException
     */
    public function assertValid(?string $url): void
    {
        $error = $this->errorFor($url);

        if ($error !== null) {
            throw new InvalidMcpUrlException($error);
        }
    }

    public function isPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function allowsPrivateHosts(): bool
    {
        return (bool) config('services.mcp.allow_private_hosts', false);
    }
}
