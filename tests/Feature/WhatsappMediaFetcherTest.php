<?php

namespace Tests\Feature;

use App\Exceptions\WhatsappOperationException;
use App\Support\HostResolver;
use App\Support\WhatsappMediaFetcher;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WhatsappMediaFetcherTest extends TestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n0000IHDR-fake-image";

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.wuzapi.media_max_bytes.image' => 1024,
            'services.wuzapi.media_max_bytes.document' => 2048,
            // The MCP flag must never relax media validation.
            'services.mcp.allow_private_hosts' => true,
        ]);

        $this->fakeResolver([
            'cdn.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
            'internal.example.com' => ['10.0.0.5'],
            'mixed.example.com' => ['93.184.216.34', '192.168.1.10'],
            'mapped.example.com' => ['::ffff:127.0.0.1'],
            'cgnat.example.com' => ['100.64.0.1'],
        ]);

        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, list<string>>  $hosts
     */
    private function fakeResolver(array $hosts): void
    {
        $this->app->instance(HostResolver::class, new class($hosts) extends HostResolver
        {
            /**
             * @param  array<string, list<string>>  $hosts
             */
            public function __construct(private readonly array $hosts) {}

            public function resolve(string $host): array
            {
                if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
                    return [trim($host, '[]')];
                }

                return $this->hosts[$host] ?? [];
            }
        });
    }

    private function fetcher(): WhatsappMediaFetcher
    {
        return app(WhatsappMediaFetcher::class);
    }

    private function assertMediaInvalid(Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Expected whatsapp_media_invalid.');
        } catch (WhatsappOperationException $exception) {
            $this->assertSame(WhatsappOperationException::MEDIA_INVALID, $exception->reason);
            $this->assertStringStartsWith('whatsapp_media_invalid:', $exception->getMessage());
        }
    }

    public function test_public_https_url_within_limit_returns_data_uri(): void
    {
        Http::fake([
            'https://cdn.example.com/x.png' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png; charset=binary']),
        ]);

        $media = $this->fetcher()->fetch('https://cdn.example.com/x.png', 'image');

        $this->assertSame('image/png', $media['mimetype']);
        $this->assertSame(strlen(self::PNG), $media['bytes']);
        $this->assertSame('data:image/png;base64,'.base64_encode(self::PNG), $media['data_uri']);
        Http::assertSentCount(1);
    }

    public function test_document_accepts_any_type_and_sniffs_octet_stream(): void
    {
        Http::fake([
            'https://cdn.example.com/doc' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $media = $this->fetcher()->fetch('https://cdn.example.com/doc', 'document');

        $this->assertSame('application/pdf', $media['mimetype']);
        $this->assertStringStartsWith('data:application/pdf;base64,', $media['data_uri']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedUrls(): array
    {
        return [
            'http scheme' => ['http://cdn.example.com/x.png'],
            'ftp scheme' => ['ftp://cdn.example.com/x.png'],
            'loopback literal' => ['https://127.0.0.1/x.png'],
            'ipv6 loopback' => ['https://[::1]/x.png'],
            'localhost' => ['https://localhost/x.png'],
            'link-local metadata' => ['https://169.254.169.254/latest'],
            'host resolving to private' => ['https://internal.example.com/x.png'],
            'any resolved address private' => ['https://mixed.example.com/x.png'],
            'ipv4-mapped ipv6' => ['https://mapped.example.com/x.png'],
            'carrier-grade nat' => ['https://cgnat.example.com/x.png'],
            'unresolvable host' => ['https://nowhere.example.com/x.png'],
            'userinfo' => ['https://user:pass@cdn.example.com/x.png'],
            'not a url' => ['not a url'],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function test_rejects_url_before_any_request(string $url): void
    {
        Http::fake();

        $this->assertMediaInvalid(fn () => $this->fetcher()->assertAcceptable($url, 'image'));
        $this->assertMediaInvalid(fn () => $this->fetcher()->fetch($url, 'image'));

        Http::assertNothingSent();
    }

    public function test_rejects_unknown_kind(): void
    {
        $this->assertMediaInvalid(fn () => $this->fetcher()->assertAcceptable('https://cdn.example.com/x.png', 'sticker'));
    }

    public function test_does_not_follow_redirects(): void
    {
        Http::fake([
            'https://cdn.example.com/x.png' => Http::response('', 302, ['Location' => 'https://127.0.0.1/x.png']),
        ]);

        $this->assertMediaInvalid(fn () => $this->fetcher()->fetch('https://cdn.example.com/x.png', 'image'));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://cdn.example.com/x.png');
    }

    public function test_rejects_body_above_the_type_limit(): void
    {
        Http::fake([
            'https://cdn.example.com/big.png' => Http::response(str_repeat('a', 1025), 200, ['Content-Type' => 'image/png']),
            'https://cdn.example.com/declared.png' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png', 'Content-Length' => '5000']),
        ]);

        $this->assertMediaInvalid(fn () => $this->fetcher()->fetch('https://cdn.example.com/big.png', 'image'));
        $this->assertMediaInvalid(fn () => $this->fetcher()->fetch('https://cdn.example.com/declared.png', 'image'));
    }

    public function test_rejects_error_status_connection_failure_and_type_mismatch(): void
    {
        Http::fake([
            'https://cdn.example.com/missing.png' => Http::response('', 404),
            'https://cdn.example.com/down.png' => fn () => throw new ConnectionException('timed out'),
            'https://cdn.example.com/page.png' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertMediaInvalid(fn () => $this->fetcher()->fetch('https://cdn.example.com/missing.png', 'image'));
        $this->assertMediaInvalid(fn () => $this->fetcher()->fetch('https://cdn.example.com/down.png', 'image'));
        $this->assertMediaInvalid(fn () => $this->fetcher()->fetch('https://cdn.example.com/page.png', 'image'));
    }
}
