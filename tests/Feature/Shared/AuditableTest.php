<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditLog;
use App\Shared\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('audit_probes', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('secret')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
    });
});

final class AuditProbe extends Model
{
    use Auditable;

    protected $table = 'audit_probes';

    protected $guarded = [];

    protected $hidden = ['secret'];
}

it('records create, update (diff only) and delete with the acting user', function (): void {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $probe = AuditProbe::query()->create(['name' => 'a']);
    $probe->update(['name' => 'b']);
    $probe->delete();

    $logs = AuditLog::query()->where('subject_type', $probe->getMorphClass())->orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe(['audit_probes.created', 'audit_probes.updated', 'audit_probes.deleted'])
        ->and($logs[1]->before)->toBe(['name' => 'a'])
        ->and($logs[1]->after)->toBe(['name' => 'b'])
        ->and($logs->pluck('actor_id')->unique()->all())->toBe([$actor->id]);
});

it('redacts hidden attributes and known secret keys', function (): void {
    AuditProbe::query()->create(['name' => 'x', 'secret' => 'top', 'password' => 'hunter2']);

    $after = AuditLog::query()->where('action', 'audit_probes.created')->sole()->after;

    expect($after['secret'])->toBe('[redacted]')
        ->and($after['password'])->toBe('[redacted]')
        ->and(json_encode($after))->not->toContain('hunter2')->not->toContain('top');
});

it('skips updates that only touch updated_at', function (): void {
    $probe = AuditProbe::query()->create(['name' => 'a']);
    $this->travel(1)->minute();
    $probe->touch();

    expect(AuditLog::query()->where('action', 'audit_probes.updated')->count())->toBe(0);
});

it('is append-only', function (): void {
    $log = app(AuditLogger::class)->log('test.event');

    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});
