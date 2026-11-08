<?php

namespace Tests\Feature;

use App\Services\WhatsappGroupService;
use App\Services\WhatsappOutboundService;
use App\Services\WuzapiClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Concerns\FakesWuzapi;
use Tests\TestCase;

/**
 * RNF-04: every call to a wuzapi send or group mutation endpoint goes
 * through the brakes. The paths only exist inside `WuzapiClient`, and its
 * mutating methods are only called by `WhatsappOutboundService` (`send*`)
 * and `WhatsappGroupService` (`group*`). RNF-08: every WhatsApp/wuzapi test
 * fakes wuzapi, so the suite never calls the real service.
 */
class WhatsappSingleExitPathTest extends TestCase
{
    use FakesWuzapi;

    /**
     * Send and group mutation paths of wuzapi (CT-20).
     *
     * @var list<string>
     */
    private const array MUTATING_PATHS = [
        '/chat/send/',
        '/group/create',
        '/group/updateparticipants',
        '/group/name',
        '/group/topic',
        '/group/photo',
        '/group/announce',
        '/group/locked',
        '/group/leave',
    ];

    /**
     * `group*` methods of the client that only read. Any other `group*`
     * method is treated as a mutation, so a new one is guarded by default.
     *
     * @var list<string>
     */
    private const array READ_GROUP_METHODS = ['groupList', 'groupInfo', 'groupInviteLink'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWuzapi();
    }

    public function test_send_and_group_mutation_paths_only_appear_in_the_wuzapi_client(): void
    {
        $client = (string) file_get_contents(app_path('Services/WuzapiClient.php'));

        foreach (self::MUTATING_PATHS as $path) {
            $this->assertStringContainsString($path, $client, "WuzapiClient no longer calls [{$path}]; update the guarded path list.");
        }

        $this->assertSame([], $this->appViolations(), 'A wuzapi send/group mutation escapes the brakes.');
    }

    public function test_mutating_client_methods_are_only_called_by_their_service(): void
    {
        $methods = $this->mutatingMethods();

        $this->assertEqualsCanonicalizing(
            ['sendText', 'sendImage', 'sendVideo', 'sendAudio', 'sendDocument', 'sendLocation'],
            array_keys(array_filter($methods, fn (string $owner): bool => $owner === WhatsappOutboundService::class)),
        );
        $this->assertEqualsCanonicalizing(
            ['groupCreate', 'groupPhoto', 'groupLeave', 'groupName', 'groupTopic', 'groupAnnounce', 'groupLocked', 'groupUpdateParticipants'],
            array_keys(array_filter($methods, fn (string $owner): bool => $owner === WhatsappGroupService::class)),
        );

        // Both services really are the exit path (the scan is not vacuous).
        $this->assertStringContainsString('->sendText(', (string) file_get_contents(app_path('Services/WhatsappOutboundService.php')));
        $this->assertStringContainsString('->groupCreate(', (string) file_get_contents(app_path('Services/WhatsappGroupService.php')));

        $this->assertSame([], $this->appViolations());
    }

    public function test_scanner_flags_a_send_or_group_mutation_outside_its_service(): void
    {
        $rogueCall = '<?php $client->sendText($to, "oi");';
        $rogueGroup = '<?php $client->groupLeave($jid);';
        $rogueCallable = '<?php app()->call([$client, \'sendImage\']);';
        $roguePath = '<?php Http::post($url."/chat/send/text", []);';
        $rogueGroupPath = '<?php Http::post("{$url}/group/updateparticipants", []);';
        $comment = "<?php\n// Calls POST /group/updateparticipants and ->sendText() in the service.\n\$x = 1;";

        $this->assertNotEmpty($this->violationsIn('app/Tools/Internal/RogueTool.php', $rogueCall));
        $this->assertNotEmpty($this->violationsIn('app/Services/WhatsappGroupService.php', $rogueCall), 'send* belongs to the outbound service only.');
        $this->assertNotEmpty($this->violationsIn('app/Services/WhatsappOutboundService.php', $rogueGroup), 'group* belongs to the group service only.');
        $this->assertNotEmpty($this->violationsIn('app/Jobs/Rogue.php', $rogueCallable));
        $this->assertNotEmpty($this->violationsIn('app/Jobs/Rogue.php', $roguePath));
        $this->assertNotEmpty($this->violationsIn('app/Jobs/Rogue.php', $rogueGroupPath));

        $this->assertSame([], $this->violationsIn('app/Services/WhatsappOutboundService.php', $rogueCall));
        $this->assertSame([], $this->violationsIn('app/Services/WhatsappGroupService.php', $rogueGroup));
        $this->assertSame([], $this->violationsIn('app/Services/WuzapiClient.php', $roguePath));
        $this->assertSame([], $this->violationsIn('app/Jobs/Rogue.php', $comment), 'Comments are not calls.');
    }

    /**
     * RNF-08: the wuzapi fake (`Http::fake` + `preventStrayRequests`) is
     * part of every WhatsApp/wuzapi test class.
     */
    public function test_whatsapp_and_wuzapi_tests_use_the_wuzapi_fake(): void
    {
        $files = collect([...File::allFiles(base_path('tests/Feature')), ...File::allFiles(base_path('tests/Unit'))])
            ->filter(fn (SplFileInfo $file): bool => preg_match('/^(Whatsapp|Wuzapi)\w*Test\.php$/', $file->getFilename()) === 1);

        $this->assertGreaterThanOrEqual(25, $files->count());

        foreach ($files as $file) {
            $class = 'Tests\\'.Str::of($file->getRealPath())
                ->after(base_path('tests').DIRECTORY_SEPARATOR)
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '\\');

            $this->assertTrue(class_exists((string) $class), "{$class} is not autoloadable.");
            $this->assertContains(FakesWuzapi::class, class_uses_recursive((string) $class), "{$class} must use FakesWuzapi (RNF-08).");

            $source = $file->getContents();
            $this->assertTrue(
                str_contains($source, '$this->fakeWuzapi(') || str_contains($source, 'Http::preventStrayRequests()'),
                "{$class} must fake wuzapi and prevent stray requests (RNF-08).",
            );
        }

        $this->assertSame($this->wuzapiUrl, config('services.wuzapi.url'));
    }

    /**
     * Mutating client method → the only class allowed to call it.
     *
     * @return array<string, class-string>
     */
    private function mutatingMethods(): array
    {
        $methods = [];

        foreach ((new ReflectionClass(WuzapiClient::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if (str_starts_with($name, 'send')) {
                $methods[$name] = WhatsappOutboundService::class;
            } elseif (str_starts_with($name, 'group') && ! in_array($name, self::READ_GROUP_METHODS, true)) {
                $methods[$name] = WhatsappGroupService::class;
            }
        }

        return $methods;
    }

    /**
     * @return list<string>
     */
    private function appViolations(): array
    {
        $violations = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'app/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());

            array_push($violations, ...$this->violationsIn($relative, $file->getContents()));
        }

        return $violations;
    }

    /**
     * Code tokens (identifiers and string literals, never comments) of the
     * file that name a mutating path or a mutating client method it may
     * not use.
     *
     * @return list<string>
     */
    private function violationsIn(string $relativePath, string $source): array
    {
        $ownerFiles = [
            WhatsappOutboundService::class => 'app/Services/WhatsappOutboundService.php',
            WhatsappGroupService::class => 'app/Services/WhatsappGroupService.php',
        ];

        if ($relativePath === 'app/Services/WuzapiClient.php') {
            return [];
        }

        $methods = $this->mutatingMethods();
        $violations = [];

        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$type, $text, $line] = $token;

            if (in_array($type, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                foreach (self::MUTATING_PATHS as $path) {
                    if (str_contains($text, $path)) {
                        $violations[] = "{$relativePath}:{$line} names wuzapi path [{$path}]";
                    }
                }
            }

            if (! in_array($type, [T_STRING, T_CONSTANT_ENCAPSED_STRING], true)) {
                continue;
            }

            $name = trim($text, '\'"');

            if (isset($methods[$name]) && $ownerFiles[$methods[$name]] !== $relativePath) {
                $violations[] = "{$relativePath}:{$line} uses WuzapiClient::{$name}() outside ".class_basename($methods[$name]);
            }
        }

        return $violations;
    }
}
