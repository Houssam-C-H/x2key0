<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Affiliate\Support\AffiliateBills as Bills;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Ledger\Models\LedgerPosting;
use App\Filament\Support\Staff;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Affiliate bills and transfers (docs/ADMIN_PLAN.md item 8): per affiliate and month, commissions, video rewards,
 * reversals and transfers to Credits, all read from the ledger; below, the latest transfers to Credits.
 */
final class AffiliateBills extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?string $navigationLabel = 'Monthly bills';

    protected static ?string $slug = 'affiliate-bills';

    protected string $view = 'filament.pages.affiliate-bills';

    public string $month = '';

    public static function canAccess(): bool
    {
        return Staff::has(StaffRole::Finance, StaffRole::MerchantManager);
    }

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    /**
     * @return list<array{userId: int, username: string, commission: int, rewards: int, reversals: int, net: int, transferred: int}>
     */
    public function bills(): array
    {
        return Bills::month($this->monthStart());
    }

    /**
     * @return list<array{at: string, user: string, amount: int}>
     */
    public function transfers(): array
    {
        return array_values(LedgerJournal::query()->with('postings.account')
            ->where('type', JournalType::AffiliateToCredits)
            ->whereBetween('posted_at', [$this->monthStart(), $this->monthStart()->endOfMonth()])
            ->latest('id')->limit(100)->get()
            ->map(function (LedgerJournal $j): array {
                $posting = $j->postings->first(fn (LedgerPosting $p): bool => $p->amount > 0);

                return [
                    'at' => $j->posted_at->format('Y-m-d H:i'),
                    'user' => $posting !== null ? '#'.$posting->account->owner_id : '—',
                    'amount' => $posting !== null ? $posting->amount : 0,
                ];
            })->all());
    }

    public static function money(int $cents): string
    {
        return 'USD '.number_format($cents / 100, 2);
    }

    private function monthStart(): Carbon
    {
        return preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 ? Carbon::createFromFormat('Y-m-d', $this->month.'-01')?->startOfMonth() ?? now()->startOfMonth() : now()->startOfMonth();
    }
}
