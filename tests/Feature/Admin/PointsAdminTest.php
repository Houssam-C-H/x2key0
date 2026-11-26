<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Points\Actions\PointsAdjustments;
use App\Domains\Points\Models\PointsAdjustment;
use App\Domains\Points\Models\PointsRule;
use App\Domains\Points\Support\Points;
use App\Filament\Resources\Points\Pages\EditPointsRule;
use App\Filament\Resources\Points\Pages\ListPointsAdjustments;
use App\Filament\Resources\Points\PointsRuleResource;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Shared\Audit\AuditLog;
use Database\Seeders\PointsRuleSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * Points admin (docs/ADMIN_PLAN.md item 7): rules, a user's history, adjustments with dual approval.
 */

it('posts small adjustments at once through the points ledger and shows them in the user history', function (): void {
    $finance = User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create();
    $user = User::factory()->create();

    $adj = app(PointsAdjustments::class)->request($finance, $user, 500, 'Goodwill for ticket 1234');
    expect($adj->status)->toBe(PointsAdjustment::POSTED)->and(Points::balance($user->id))->toBe(500)
        ->and(Points::history($user->id)[0])->toMatchArray(['type' => 'adjust', 'points' => 500]);

    $this->actingAs($finance);
    Livewire::test(ViewUser::class, ['record' => $user->getRouteKey()])->assertSee('X2K Score history')->assertSee('Goodwill for ticket 1234');
});

it('holds large adjustments for another approver and never overdraws', function (): void {
    $alice = User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create();
    $bob = User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create();
    $user = User::factory()->create();
    $act = app(PointsAdjustments::class);

    $big = $act->request($alice, $user, 20000, 'Campaign winner prize points');
    expect($big->status)->toBe(PointsAdjustment::PENDING)->and(Points::balance($user->id))->toBe(0)
        ->and(fn () => $act->approve($big, $alice))->toThrow(DomainException::class);

    $this->actingAs($bob);
    Livewire::test(ListPointsAdjustments::class)->callAction(TestAction::make('approve')->table($big));
    expect($big->refresh()->status)->toBe(PointsAdjustment::POSTED)->and(Points::balance($user->id))->toBe(20000);

    expect(fn () => $act->request($alice, User::factory()->create(), -500, 'Fraudulent order points reversed'))->toThrow(DomainException::class)
        ->and(fn () => $act->request($alice, $alice, 10, 'My own points please'))->toThrow(DomainException::class)
        ->and(fn () => $act->request(User::factory()->staff(StaffRole::Support)->create(), $user, 10, 'Support cannot do this'))->toThrow(DomainException::class)
        ->and(Points::balance($user->id))->toBe(20000)
        ->and(AuditLog::query()->where('action', 'points.adjustment_posted')->count())->toBe(1);
});

it('requests an adjustment from the screen', function (): void {
    $user = User::factory()->create(['username' => 'points_target']);
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());

    Livewire::test(ListPointsAdjustments::class)
        ->callAction('adjust', ['user_id' => $user->id, 'points' => '250', 'reason' => 'Compensation for a late order'])
        ->assertHasNoActionErrors();

    expect(Points::balance($user->id))->toBe(250);
});

it('edits a points rule (audited) and keeps support out', function (): void {
    (new PointsRuleSeeder)->run();
    $rule = PointsRule::query()->firstOrFail();
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());

    Livewire::test(EditPointsRule::class, ['record' => $rule->getRouteKey()])
        ->fillForm(['is_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($rule->refresh()->is_enabled)->toBeFalse()
        ->and(AuditLog::query()->where('subject_type', $rule->getMorphClass())->where('subject_id', $rule->id)->exists())->toBeTrue();

    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create());
    expect(PointsRuleResource::canAccess())->toBeFalse();
});
