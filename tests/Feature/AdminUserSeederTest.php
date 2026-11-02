<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAdminEnvironment('Jarvis Admin', 'admin@jarvis.test', 'super-secret-password');
    }

    protected function tearDown(): void
    {
        foreach (['ADMIN_NAME', 'ADMIN_EMAIL', 'ADMIN_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    public function test_creates_admin_once_from_env(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::query()->count());

        $admin = User::query()->where('email', 'admin@jarvis.test')->firstOrFail();

        $this->assertSame('Jarvis Admin', $admin->name);
        $this->assertTrue((bool) $admin->is_admin);
        $this->assertTrue((bool) $admin->is_active);
        $this->assertTrue(Hash::check('super-secret-password', $admin->password));
    }

    public function test_does_not_replace_existing_user_with_same_email(): void
    {
        User::factory()->create(['email' => 'admin@jarvis.test', 'name' => 'Existing']);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::query()->count());
        $this->assertSame('Existing', User::query()->where('email', 'admin@jarvis.test')->value('name'));
    }

    public function test_rejects_password_shorter_than_twelve_characters(): void
    {
        $this->setAdminEnvironment('Jarvis Admin', 'admin@jarvis.test', 'short');

        $this->expectException(InvalidArgumentException::class);

        $this->seed(AdminUserSeeder::class);
    }

    public function test_requires_name_and_email(): void
    {
        $this->setAdminEnvironment('', '', 'super-secret-password');

        $this->expectException(InvalidArgumentException::class);

        $this->seed(AdminUserSeeder::class);
    }

    private function setAdminEnvironment(string $name, string $email, string $password): void
    {
        foreach (['ADMIN_NAME' => $name, 'ADMIN_EMAIL' => $email, 'ADMIN_PASSWORD' => $password] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
