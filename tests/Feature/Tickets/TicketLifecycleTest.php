<?php

declare(strict_types=1);

use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Tickets\Actions\TicketDesk;
use App\Domains\Tickets\Enums\TicketPriority;
use App\Domains\Tickets\Enums\TicketStatus;
use App\Domains\Tickets\Enums\TicketTopic;
use App\Domains\Tickets\Exceptions\TicketException;
use App\Domains\Tickets\Models\Ticket;
use App\Domains\Tickets\Models\TicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;

/*
| Acceptance (spec Part 25 M12): ticket lifecycle (6.5) and auto-close.
*/

beforeEach(function (): void {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->staff = User::factory()->staff(StaffRole::Support)->create();
    $this->desk = app(TicketDesk::class);
    $this->open = fn (array $o = []) => $this->desk->open($this->user, [
        'topic' => TicketTopic::Account, 'subject' => 'Cannot change my e-mail', 'body' => 'The verification link says expired.', 'order_id' => null, 'form_data' => [], ...$o,
    ]);
});

it('pending → staff reply → awaiting_reply → user reply → pending → closed → reopened, with history', function (): void {
    $ticket = ($this->open)();
    expect($ticket->status)->toBe(TicketStatus::Pending)
        ->and($ticket->priority)->toBe(TicketPriority::Normal)
        ->and($ticket->first_response_due_at?->isSameHour(now()->addHours(24)))->toBeTrue();

    $ticket = $this->desk->staffReply($ticket, $this->staff, 'Please try the new link we just sent.');
    expect($ticket->status)->toBe(TicketStatus::AwaitingReply)
        ->and($ticket->first_responded_at)->not->toBeNull()
        ->and($ticket->first_response_due_at)->toBeNull()
        ->and($ticket->assigned_to)->toBe($this->staff->id);

    $ticket = $this->desk->userReply($ticket, $this->user, 'Still expired.');
    expect($ticket->status)->toBe(TicketStatus::Pending)->and($ticket->first_response_due_at)->not->toBeNull();

    $ticket = $this->desk->close($ticket, 'user', $this->user);
    expect($ticket->status)->toBe(TicketStatus::Closed)->and($ticket->closed_by)->toBe('user');
    expect(fn () => $this->desk->userReply($ticket, $this->user, 'hello'))->toThrow(TicketException::class);

    $ticket = $this->desk->reopen($ticket, $this->user);
    expect($ticket->status)->toBe(TicketStatus::Pending)->and($ticket->closed_at)->toBeNull();

    expect($ticket->history()->orderBy('id')->pluck('to_status')->map->value->all())
        ->toBe(['pending', 'awaiting_reply', 'pending', 'closed', 'pending']);
});

it('internal notes change nothing and never reach the user', function (): void {
    $ticket = ($this->open)();
    $this->desk->staffReply($ticket, $this->staff, 'User seems to use an old link.', internal: true);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Pending);
    $this->actingAs($this->user)->get($ticket->path())->assertInertia(fn (Assert $page) => $page
        ->component('Tickets/Show')
        ->where('ticket.messages', fn ($messages): bool => collect($messages)->every(fn (array $m): bool => ! str_contains($m['body'], 'old link'))));
});

it('auto-closes only tickets waiting for the user past ticket_autoclose_hours, once', function (): void {
    $waiting = $this->desk->staffReply(($this->open)(), $this->staff, 'Did that work?');
    $pending = ($this->open)(['subject' => 'Another question']);

    $this->travel(71)->hours();
    Artisan::call('tickets:autoclose');
    expect($waiting->refresh()->status)->toBe(TicketStatus::AwaitingReply);

    $this->travel(2)->hours();
    Artisan::call('tickets:autoclose');
    Artisan::call('tickets:autoclose');

    expect($waiting->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($waiting->closed_by)->toBe('system')
        ->and($pending->refresh()->status)->toBe(TicketStatus::Pending)
        ->and($waiting->history()->where('to_status', 'closed')->count())->toBe(1)
        ->and(TicketMessage::query()->where('ticket_id', $waiting->id)->where('body', 'ticket.line.closed_by_system')->count())->toBe(1);
});

it('a user reply resets the auto-close clock', function (): void {
    $ticket = $this->desk->staffReply(($this->open)(), $this->staff, 'Did that work?');
    $this->travel(70)->hours();
    $this->desk->userReply($ticket, $this->user, 'Not yet');
    $this->travel(5)->hours();
    Artisan::call('tickets:autoclose');

    expect($ticket->refresh()->status)->toBe(TicketStatus::Pending);
});

it('reopens within ticket_reopen_days only; rates once after close', function (): void {
    $ticket = $this->desk->close(($this->open)(), 'staff', $this->staff);

    $this->desk->rate($ticket, $this->user, 4, 'Quick answer');
    expect(fn () => $this->desk->rate($ticket->refresh(), $this->user, 5, null))->toThrow(TicketException::class)
        ->and($ticket->refresh()->satisfaction)->toBe(4);

    $this->travel(8)->days();
    expect(fn () => $this->desk->reopen($ticket, $this->user))->toThrow(TicketException::class);
});

it('order tickets get high priority and convert into a dispute in the buyer\'s name', function (): void {
    $fx = CheckoutFixture::build();
    $order = CheckoutFixture::deliveredOrder($fx);
    $ticket = $this->desk->open($fx['buyer'], ['topic' => TicketTopic::Refund, 'subject' => 'Wrong coins amount', 'body' => 'I received 500 instead of 1000 coins.', 'order_id' => $order->id, 'form_data' => []]);
    expect($ticket->priority)->toBe(TicketPriority::High);

    $closed = $this->desk->convertToDispute($ticket, $this->staff, DisputeReason::NotAsDescribed, DisputeOutcome::RefundPartial, 1);

    $dispute = Dispute::query()->sole();
    expect($closed->status)->toBe(TicketStatus::Closed)
        ->and($closed->dispute_id)->toBe($dispute->id)
        ->and($dispute->opened_by_id)->toBe($fx['buyer']->id)
        ->and($dispute->description)->toContain('500 instead of 1000');

    // Someone else's order cannot be attached.
    expect(fn () => $this->desk->open($this->user, ['topic' => TicketTopic::Refund, 'subject' => 'Not mine', 'body' => 'Trying another order.', 'order_id' => $order->id, 'form_data' => []]))
        ->toThrow(TicketException::class);
});

it('HTTP: list tabs with counts, catalog request form, attachments only for the owner', function (): void {
    $this->actingAs($this->user)->post('/tickets', [
        'topic' => 'catalog_request', 'subject' => 'Please add Star Racers', 'body' => 'Would love to sell keys for it.',
        'form' => ['game_name' => 'Star Racers', 'platform' => 'PC'],
    ])->assertSessionHasErrors(['form.area', 'form.server', 'form.product_type', 'form.keywords']);

    $this->post('/tickets', [
        'topic' => 'catalog_request', 'subject' => 'Please add Star Racers', 'body' => 'Would love to sell keys for it.',
        'form' => ['game_name' => 'Star Racers', 'platform' => 'PC', 'area' => 'Global', 'server' => 'All', 'product_type' => 'CD Key', 'keywords' => 'racing'],
        'files' => [UploadedFile::fake()->image('cover.png', 300, 200)],
    ])->assertRedirect();

    $ticket = Ticket::query()->sole();
    expect($ticket->priority)->toBe(TicketPriority::Low)->and($ticket->form_data['game_name'] ?? null)->toBe('Star Racers');

    $this->get('/tickets')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Tickets/Index')->where('counts.all', 1)->where('counts.pending', 1)->where('counts.closed', 0)->has('tickets.data', 1));
    $this->get('/tickets?status=closed')->assertInertia(fn (Assert $page) => $page->where('menu.active.key', 'tickets.closed')->has('tickets.data', 0));

    $file = $ticket->attachments()->sole();
    $this->get("/tickets/{$ticket->id}/files/{$file->id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs(User::factory()->create())->get("/tickets/{$ticket->id}/files/{$file->id}")->assertNotFound();
    $this->get($ticket->path())->assertNotFound();

    $this->actingAs($this->user)->post("{$ticket->path()}/messages", ['body' => 'Any news?'])->assertSessionHasNoErrors();
    $this->post("{$ticket->path()}/close")->assertSessionHasNoErrors();
    $this->post("{$ticket->path()}/rate", ['score' => 5])->assertSessionHasNoErrors();
    expect($ticket->refresh()->satisfaction)->toBe(5);
});

it('only support staff work the queue; the admin screens open', function (string $path): void {
    expect(Gate::forUser($this->staff)->allows('viewAny', Ticket::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->staff(StaffRole::Finance)->create())->allows('viewAny', Ticket::class))->toBeFalse();

    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create())->get($path)->assertOk();
})->with(['/admin/tickets', '/admin/canned-replies', '/admin/canned-replies/create']);
