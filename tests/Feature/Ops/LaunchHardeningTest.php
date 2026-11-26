<?php

declare(strict_types=1);

use App\Domains\Engagement\Models\BrowsingHistory;
use App\Domains\Identity\Actions\PruneRetention;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSession;
use App\Http\Middleware\ThrottleWrites;
use App\Shared\Audit\AuditLog;
use App\Shared\Ops\HealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

it('sends CSP with a script nonce, frame and sniffing protection on site pages, and a looser policy only in the admin', function (): void {
    $response = $this->get('/en')->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("script-src 'self' 'nonce-")
        ->not->toContain('unsafe-eval')
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()');

    // Every script tag Vite printed carries the same nonce.
    preg_match("/'nonce-([^']+)'/", $csp, $m);
    expect(substr_count((string) $response->getContent(), 'nonce="'.$m[1].'"'))->toBeGreaterThan(0);

    $admin = $this->get('/admin/login');
    expect((string) $admin->headers->get('Content-Security-Policy'))->toContain("'unsafe-eval'")->toContain("frame-ancestors 'none'");
});

it('can send the CSP as Report-Only to trial changes', function (): void {
    config(['x2key.security.csp_report_only' => true]);

    $response = $this->get('/en');
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toContain('nonce-');
});

it('throttles account-recovery endpoints per IP and every other write per user', function (): void {
    foreach (range(1, ThrottleWrites::SENSITIVE_PER_MINUTE) as $_) {
        $this->post('/forgot-password', ['email' => 'x@example.com']);
    }
    $this->post('/forgot-password', ['email' => 'x@example.com'])->assertStatus(429);

    $user = User::factory()->create();
    $this->actingAs($user);
    foreach (range(1, ThrottleWrites::PER_MINUTE) as $_) {
        $this->post('/announcements/read-all');
    }
    $this->post('/announcements/read-all')->assertStatus(429);
    $this->get('/announcements')->assertOk(); // reads are not limited here
});

it('counts each route numeric limit separately', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    // favorites allows 60/min; 59 favorites must not use up the 30/min of another route.
    foreach (range(1, 59) as $_) {
        $this->post('/favorites', ['type' => 'offer', 'id' => 'x']);
    }
    $this->post('/coupons/code', ['store' => 'nope', 'code' => 'NOPE'])->assertStatus(302);
});

it('keeps a rate limit on the routes the spec names (checkout, reveal, chat, coupons, tickets, webhooks, login)', function (): void {
    foreach (['checkout.store', 'buy.orders.reveal', 'chat.send', 'coupons.claim', 'webhooks.payments'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull()
            ->and(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle')))->toBeTrue("{$name} has no throttle");
    }
    expect(collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => str_starts_with($r->uri(), 'tickets') && in_array('POST', $r->methods(), true) && collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle'))))->toBeTrue();
});

it('exports the user\'s own data as JSON after a recent password, without secrets, and audits it', function (): void {
    $fx = CheckoutFixture::build();
    $buyer = $fx['buyer'];
    $this->actingAs($buyer);
    CheckoutFixture::deliveredOrder($fx);
    Ledger::fundCredits($buyer, 300);

    $this->get('/settings/account/export')->assertRedirect(); // asks for the password first

    $response = $this->withSession(['auth.password_confirmed_at' => time()])->get('/settings/account/export')->assertOk();
    $json = json_decode((string) $response->streamedContent(), true);

    expect($response->headers->get('Content-Disposition'))->toContain('x2key-data-')
        ->and($json['profile']['email'])->toBe($buyer->email)
        ->and($json['orders'])->toHaveCount(1)
        ->and(collect($json['money'])->pluck('type'))->toContain('PROMO_CREDIT')
        ->and((string) $response->streamedContent())->not->toContain('two_factor_secret')->not->toContain($buyer->password)
        ->and(AuditLog::query()->where('action', 'privacy.data_exported')->where('subject_id', $buyer->id)->exists())->toBeTrue();
});

it('prunes data past its retention and keeps recent rows', function (): void {
    $user = User::factory()->create();
    BrowsingHistory::query()->create(['user_id' => $user->id, 'viewed_at' => now()->subDays(200)]);
    BrowsingHistory::query()->create(['user_id' => $user->id, 'viewed_at' => now()->subDay()]);
    UserSession::query()->create(['user_id' => $user->id, 'session_id' => 'old', 'ip' => '1.1.1.1', 'user_agent' => 'x', 'last_active_at' => now()->subDays(100), 'revoked_at' => now()->subDays(100)]);
    UserSession::query()->create(['user_id' => $user->id, 'session_id' => 'live', 'ip' => '1.1.1.1', 'user_agent' => 'x', 'last_active_at' => now()]);

    $result = app(PruneRetention::class)->handle();

    expect($result['browsing_history'])->toBe(1)
        ->and($result['user_sessions_revoked'])->toBe(1)
        ->and(BrowsingHistory::query()->count())->toBe(1)
        ->and(UserSession::query()->pluck('session_id')->all())->toBe(['live']);
    $this->artisan('privacy:prune')->assertSuccessful();
});

it('reports health: 503 without a scheduler heartbeat, 200 once the scheduler and ledger check ran; details need the token', function (): void {
    config(['x2key.ops.health_token' => 'secret-token']);

    $this->get('/health')->assertStatus(503)->assertExactJson(['status' => 'fail']);

    $this->artisan('ops:heartbeat')->assertSuccessful();
    $this->artisan('ledger:verify')->assertSuccessful();

    $this->get('/health')->assertOk()->assertExactJson(['status' => 'ok']);
    $this->get('/health?token=secret-token')->assertOk()->assertJsonPath('checks.scheduler.ok', true)->assertJsonPath('checks.ledger.ok', true);
    $this->get('/health?token=wrong')->assertJsonMissingPath('checks');

    $this->travel(10)->minutes();
    expect(HealthCheck::healthy(app(HealthCheck::class)->run()))->toBeFalse();
    $this->artisan('ops:check')->assertFailed();
    expect(Cache::has(HealthCheck::HEARTBEAT_KEY))->toBeTrue();
});

it('serves robots.txt with private areas disallowed and the sitemap', function (): void {
    $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin', false)->assertSee('Disallow: /checkout', false)->assertSee('Sitemap: '.url('/sitemap.xml'), false);
});

it('marks session cookies secure in production by default', function (): void {
    expect(require base_path('config/session.php'))->toHaveKey('secure');
});

it('lets the browser reach Reverb at its public address, not the internal one', function (): void {
    config([
        'reverb.apps.apps.0.options.host' => 'reverb',
        'reverb.apps.apps.0.options.port' => 8080,
        'x2key.realtime.public_host' => 'ws.example.test',
        'x2key.realtime.public_port' => 443,
    ]);

    $csp = (string) $this->get('/en')->headers->get('Content-Security-Policy');

    expect($csp)->toContain('wss://ws.example.test:443')->not->toContain('reverb:8080');
});
