<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

class AdminUserSeeder extends Seeder
{
    /**
     * Minimum length accepted for the initial administrator password.
     */
    public const int MINIMUM_PASSWORD_LENGTH = 12;

    /**
     * Create the first administrator from ADMIN_NAME, ADMIN_EMAIL and ADMIN_PASSWORD when it does not exist yet.
     */
    public function run(): void
    {
        $name = trim((string) env('ADMIN_NAME', ''));
        $email = trim((string) env('ADMIN_EMAIL', ''));
        $password = (string) env('ADMIN_PASSWORD', '');

        if ($name === '' || $email === '') {
            throw new InvalidArgumentException('ADMIN_NAME and ADMIN_EMAIL must be set to seed the initial administrator.');
        }

        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            throw new InvalidArgumentException(sprintf('ADMIN_PASSWORD must have at least %d characters.', self::MINIMUM_PASSWORD_LENGTH));
        }

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        User::query()->forceCreate([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
