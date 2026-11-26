<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Messaging\Actions\StaffChatAccess;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Messaging\Models\Message;
use App\Filament\Pages\ChatModeration;
use App\Filament\Pages\ChatTranscript;
use App\Shared\Audit\AuditLog;
use Database\Seeders\PointsRuleSeeder;
use Database\Seeders\UserLevelSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\CheckoutFixture;

/*
 * Chat moderation (docs/ADMIN_PLAN.md item 6): read-only transcripts, flagged queue, audited openings.
 */

beforeEach(function (): void {
    (new UserLevelSeeder)->run();
    (new PointsRuleSeeder)->run();
    $this->fx = CheckoutFixture::build();
    $this->order = CheckoutFixture::deliveredOrder($this->fx);
    $this->conversation = Conversation::query()->where('order_id', $this->order->id)->firstOrFail();
    app(SendMessage::class)->handle($this->conversation, $this->fx['buyer'], 'Hello, all good');
    $this->flagged = app(SendMessage::class)->handle($this->conversation, $this->fx['buyer'], 'Write me at buyer.contact@example.com');
});

it('shows the transcript read-only, with what the other party saw, and audits the opening', function (): void {
    $support = User::factory()->staff(StaffRole::Support)->withTwoFactor()->create();
    $this->actingAs($support);

    Livewire::withQueryParams(['conversation' => $this->conversation->public_id, 'context' => 'dispute', 'ref' => 'D-1'])
        ->test(ChatTranscript::class)
        ->assertOk()
        ->assertSee('Hello, all good')
        ->assertSee('buyer.contact@example.com')
        ->assertSee('The other party saw')
        ->assertSee('dispute D-1');

    $log = AuditLog::query()->where('action', 'chat.transcript_opened')->sole();
    expect($log->actor_id)->toBe($support->id)
        ->and($log->subject_id)->toBe($this->conversation->id)
        ->and($log->after)->toMatchArray(['context' => 'dispute', 'context_id' => 'D-1']);
});

it('lists flagged messages until they are marked reviewed', function (): void {
    expect($this->flagged->body_redacted)->not->toBeNull();
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->withTwoFactor()->create());

    Livewire::test(ChatModeration::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->flagged])
        ->callAction(TestAction::make('review')->table($this->flagged))
        ->assertCanNotSeeTableRecords([$this->flagged]);

    expect($this->flagged->refresh()->reviewed_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'chat.message_reviewed')->count())->toBe(1)
        ->and(Message::query()->whereNotNull('body_redacted')->whereNull('reviewed_at')->count())->toBe(0);
});

it('keeps finance and non-staff out', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());
    expect(ChatTranscript::canAccess())->toBeFalse()->and(ChatModeration::canAccess())->toBeFalse()
        ->and(ChatTranscript::urlForOrder($this->order->id, 'order', 'x'))->toBeNull()
        ->and(fn () => app(StaffChatAccess::class)->open(User::factory()->create(), $this->conversation, 'order'))->toThrow(DomainException::class);
    $this->get('/admin/chat-transcript?conversation='.$this->conversation->public_id)->assertForbidden();
});
