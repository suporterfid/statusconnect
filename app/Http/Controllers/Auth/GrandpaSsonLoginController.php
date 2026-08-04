<?php

namespace App\Http\Controllers\Auth;

use App\Application\GrandpaSson\GrandpaSsonIdentityProvider;
use App\Application\GrandpaSson\GrandpaSsonTenantMappingService;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GrandpaSsonLoginController extends Controller
{
    public function __construct(
        private readonly GrandpaSsonIdentityProvider $identityProvider,
        private readonly GrandpaSsonTenantMappingService $tenantMappings,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->requireInboundEnabled();

        $state = Str::random(64);
        $request->session()->put('grandpasson.oauth_state', $state);

        return redirect()->away($this->identityProvider->loginUrl($provider, $state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->requireInboundEnabled();

        $expectedState = $request->session()->pull('grandpasson.oauth_state');
        $state = $request->query('state');
        $code = $request->query('code');

        if (! is_string($expectedState) || ! is_string($state) || ! hash_equals($expectedState, $state)) {
            abort(403);
        }

        if (! is_string($code) || $code === '') {
            abort(401);
        }

        try {
            $identity = $this->identityProvider->exchange($code);
        } catch (\Throwable) {
            abort(401);
        }

        if ($identity === null) {
            abort(401);
        }

        $access = $this->tenantMappings->resolve($identity->brokerTenantId, $identity->brokerRole, $identity->groups);
        if ($access === null) {
            abort(403);
        }

        $user = User::query()->firstOrCreate(
            ['email' => $identity->email],
            ['name' => $identity->name, 'password' => Hash::make(Str::random(64))],
        );
        if ($user->name !== $identity->name) {
            $user->update(['name' => $identity->name]);
        }

        TenantMembership::query()->updateOrCreate(
            ['tenant_id' => $access->tenant->id, 'user_id' => $user->id],
            ['role' => $access->role->value],
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }

    private function requireInboundEnabled(): void
    {
        if (! (bool) config('grandpasson.inbound_enabled', false)) {
            abort(404);
        }
    }
}
