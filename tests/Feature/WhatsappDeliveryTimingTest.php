<?php

namespace Tests\Feature;

use App\Jobs\DeliverWhatsappOutbound;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSession;
use App\Services\WhatsappOutboundService;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappDeliveryTimingTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private const int MIN_INTERVAL = 8;

    private const int JITTER_MIN = 2;

    private const int JITTER_MAX = 7;

    private const int TYPING_MIN_MS = 1500;

    private const int TYPING_MAX_MS = 6000;

    private WhatsappSession $session;

    /**
     * Upstream calls in order, with the (fake) clock at each one.
     *
     * @var list<array{call: string, at: CarbonImmutable}>
     */
    private array $timeline = [];

    private int $sends = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->fakeWuzapi([
            'POST /chat/presence' => function (Request $request) {
                $this->timeline[] = ['call' => 'presence:'.$request['State'], 'at' => CarbonImmutable::now()];

                return Http::response(['code' => 200, 'data' => ['Details' => 'ok'], 'success' => true]);
            },
            'POST /chat/send/text' => function () {
                $this->sends++;
                $this->timeline[] = ['call' => 'send', 'at' => CarbonImmutable::now()];

                return Http::response(['code' => 200, 'data' => ['Id' => 'UPSTREAM'.$this->sends], 'success' => true]);
            },
        ]);

        Queue::fake();
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);

        $settings = app(Settings::class);
        $settings->set('whatsapp.min_send_interval_seconds', self::MIN_INTERVAL);
        $settings->set('whatsapp.jitter_min_seconds', self::JITTER_MIN);
        $settings->set('whatsapp.jitter_max_seconds', self::JITTER_MAX);
        $settings->set('whatsapp.typing_min_ms', self::TYPING_MIN_MS);
        $settings->set('whatsapp.typing_max_ms', self::TYPING_MAX_MS);

        $this->session = WhatsappSession::factory()->connected()->withConsent()->create();

        // Locks live on the Redis lock connection, which `Cache::flush()` does not
        // clear, and session ids repeat across runs after `migrate:fresh`.
        (new UniqueLock(Cache::store()))->release(new DeliverWhatsappOutbound($this->session->id));
        Cache::lock(WhatsappOutboundService::deliveryLockKey($this->session->id))->forceRelease();
    }

    protected function tearDown(): void
    {
        Queue::releaseUniqueJobLocks();

        parent::tearDown();
    }

    private function service(): WhatsappOutboundService
    {
        return app(WhatsappOutboundService::class);
    }

    private function enqueue(string $text, string $toJid = '5511988887777@s.whatsapp.net'): int
    {
        return $this->service()->enqueue($this->session->user, 'text', $toJid, ['text' => $text])['outbound_id'];
    }

    /**
     * Run pushed delivery jobs in order, moving the clock to each job's delay
     * as the queue would.
     */
    private function runDeliveries(): void
    {
        $processed = 0;

        while (($job = Queue::pushed(DeliverWhatsappOutbound::class)->values()->get($processed)) !== null) {
            $processed++;

            if ($job->delay instanceof CarbonImmutable && $job->delay->isFuture()) {
                $this->travelTo($job->delay);
            }

            (new UniqueLock(Cache::store()))->release($job);

            $job->handle($this->service());
        }
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function times(string $call): array
    {
        return array_values(array_map(
            fn (array $entry): CarbonImmutable => $entry['at'],
            array_filter($this->timeline, fn (array $entry): bool => $entry['call'] === $call),
        ));
    }

    public function test_each_send_is_wrapped_by_composing_and_paused_within_the_typing_range(): void
    {
        $short = $this->enqueue('Oi');
        $medium = $this->enqueue(str_repeat('a', 60), '5511977776666@s.whatsapp.net');
        $long = $this->enqueue(str_repeat('b', 400), '5511966665555@s.whatsapp.net');

        $this->runDeliveries();

        $this->assertSame(
            array_merge(...array_fill(0, 3, ['presence:composing', 'send', 'presence:paused'])),
            array_column($this->timeline, 'call'),
        );

        $composing = $this->times('presence:composing');
        $sends = $this->times('send');
        $typing = array_map(fn (int $n): float => $composing[$n]->diffInMilliseconds($sends[$n]), [0, 1, 2]);

        foreach ($typing as $milliseconds) {
            $this->assertGreaterThanOrEqual(self::TYPING_MIN_MS, $milliseconds);
            $this->assertLessThanOrEqual(self::TYPING_MAX_MS, $milliseconds);
        }

        $this->assertEqualsWithDelta(self::TYPING_MIN_MS, $typing[0], 1);
        $this->assertEqualsWithDelta(60 * WhatsappOutboundService::TYPING_MS_PER_CHARACTER, $typing[1], 1);
        $this->assertEqualsWithDelta(self::TYPING_MAX_MS, $typing[2], 1);

        foreach ([$short, $medium, $long] as $id) {
            $this->assertSame(WhatsappOutboundStatus::SENT, WhatsappOutboundMessage::query()->with('status')->findOrFail($id)->statusSlug());
        }
    }

    public function test_three_queued_sends_respect_interval_plus_jitter_between_send_end_and_next_presence(): void
    {
        $this->enqueue('Primeira');
        $this->enqueue('Segunda', '5511977776666@s.whatsapp.net');
        $this->enqueue('Terceira', '5511966665555@s.whatsapp.net');

        $this->runDeliveries();

        $sends = $this->times('send');
        $composing = $this->times('presence:composing');

        $this->assertCount(3, $sends);
        $this->assertCount(3, $composing);

        foreach ([0, 1] as $n) {
            $gap = $sends[$n]->diffInMilliseconds($composing[$n + 1]);

            $this->assertGreaterThanOrEqual((self::MIN_INTERVAL + self::JITTER_MIN) * 1000, $gap);
            $this->assertLessThanOrEqual((self::MIN_INTERVAL + self::JITTER_MAX + 1) * 1000, $gap);
        }
    }

    public function test_deliveries_never_overlap_in_a_session(): void
    {
        foreach (range(1, 3) as $n) {
            $this->enqueue("Mensagem {$n}", '55119'.str_pad((string) $n, 8, '0', STR_PAD_LEFT).'@s.whatsapp.net');
        }

        $this->runDeliveries();

        $calls = array_column($this->timeline, 'call');
        $inFlight = 0;

        foreach ($calls as $call) {
            $inFlight += match ($call) {
                'presence:composing' => 1,
                'presence:paused' => -1,
                default => 0,
            };

            $this->assertLessThanOrEqual(1, $inFlight);
        }

        $this->assertSame(0, $inFlight);
        $this->assertSame(3, $this->sends);
    }

    public function test_job_waiting_for_the_interval_is_re_dispatched_with_delay_instead_of_released(): void
    {
        $this->enqueue('Primeira');
        $this->enqueue('Segunda', '5511977776666@s.whatsapp.net');

        $first = Queue::pushed(DeliverWhatsappOutbound::class)->first();
        (new UniqueLock(Cache::store()))->release($first);
        $first->handle($this->service());

        $this->assertSame(1, $this->sends);

        $pushed = Queue::pushed(DeliverWhatsappOutbound::class)->values();
        $this->assertCount(2, $pushed);

        $nextDeliveryAt = $this->session->fresh()->next_delivery_at;
        $this->assertTrue($nextDeliveryAt->isFuture());
        $this->assertEquals($nextDeliveryAt->toImmutable(), $pushed[1]->delay);

        // Running it early (e.g. another dispatch) delivers nothing and waits again.
        (new UniqueLock(Cache::store()))->release($pushed[1]);
        $pushed[1]->handle($this->service());

        $this->assertSame(1, $this->sends);
        $this->assertCount(3, Queue::pushed(DeliverWhatsappOutbound::class));
    }
}
