<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Tickets\Actions\TicketDesk;
use App\Domains\Tickets\Enums\TicketTopic;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Support\CsvExport;
use App\Shared\Audit\AuditLog;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\CheckoutFixture;

/*
 * Bulk actions and CSV exports (docs/ADMIN_PLAN.md item 17).
 */

it('rejects selected offers with one reason, skipping ones not under review', function (): void {
    $fx = CheckoutFixture::build();
    $pending = $fx['manual'];
    $pending->forceFill(['status' => OfferStatus::PendingReview])->save();
    $active = $fx['auto'];
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->withTwoFactor()->create());

    Livewire::test(ListOffers::class)
        ->removeTableFilter('status')
        ->selectTableRecords([$pending->getKey(), $active->getKey()])
        ->callAction(TestAction::make('rejectSelected')->table()->bulk(), ['reason' => 'Misleading title']);

    expect($pending->refresh()->status)->toBe(OfferStatus::Rejected)
        ->and($active->refresh()->status)->not->toBe(OfferStatus::Rejected);
});

it('assigns selected tickets to a staff member', function (): void {
    $desk = app(TicketDesk::class);
    $make = fn (string $s) => $desk->open(User::factory()->create(), ['topic' => TicketTopic::cases()[0], 'subject' => $s, 'body' => 'Please help me with this.', 'order_id' => null, 'form_data' => []]);
    $a = $make('First problem');
    $b = $make('Second problem');
    $agent = User::factory()->staff(StaffRole::Support)->create();
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create());

    Livewire::test(ListTickets::class)
        ->selectTableRecords([$a->getKey(), $b->getKey()])
        ->callAction(TestAction::make('assignSelected')->table()->bulk(), ['staff' => $agent->id]);

    expect($a->refresh()->assigned_to)->toBe($agent->id)->and($b->refresh()->assigned_to)->toBe($agent->id);
});

it('exports selected orders as CSV, audited, with formula cells neutralised', function (): void {
    $fx = CheckoutFixture::build();
    $this->actingAs($fx['buyer'])->post('/checkout', CheckoutFixture::form($fx['manual']));
    $order = CheckoutFixture::lastOrder($fx['buyer']);
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create());

    Livewire::test(ListOrders::class)
        ->selectTableRecords([$order->getKey()])
        ->callAction(TestAction::make('export')->table()->bulk())
        ->assertFileDownloaded();

    expect(AuditLog::query()->where('action', 'admin.exported')->value('after'))->toMatchArray(['list' => 'orders', 'rows' => 1])
        ->and(CsvExport::cell('=HYPERLINK("x")'))->toBe("'=HYPERLINK(\"x\")")
        ->and(CsvExport::cell('-12.50'))->toBe('-12.50');
});
