<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditAction;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * SPA login (US-1.1): session cookie via Sanctum, generic failure message, inactive users blocked, every attempt audited.
 */
class LoginController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        $credentials = $request->credentials();

        if (! $guard->validate($credentials)) {
            $this->auditLogger->log(AuditAction::LOGIN_FAILED, null, null, [
                'email' => $credentials['email'],
                'reason' => 'invalid_credentials',
            ]);

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();

        if (! $user->is_active) {
            $this->auditLogger->log(AuditAction::LOGIN_FAILED, null, $user, [
                'email' => $credentials['email'],
                'reason' => 'inactive',
            ]);

            abort(403, __('Your account is deactivated.'));
        }

        $guard->login($user, $request->boolean('remember'));

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $this->auditLogger->log(AuditAction::LOGIN_SUCCEEDED, $user, $user);

        return UserResource::make($user)->response()->setStatusCode(200);
    }
}
