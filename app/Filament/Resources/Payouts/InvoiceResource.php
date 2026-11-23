<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts;

use App\Domains\Invoices\Models\Invoice;
use App\Filament\Resources\Payouts\Pages\ListInvoices;
use BackedEnum;
use Brick\Money\Money;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Issued invoices and vouchers (Part 16), read-only for finance; each opens as the printable document. */
final class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Ledger';

    protected static ?string $slug = 'invoices';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('user.username')->label('User')->searchable(),
                TextColumn::make('period_start')->label('Period')->date('Y-m')->placeholder('—'),
                TextColumn::make('total')->formatStateUsing(fn (Invoice $record): string => (string) Money::ofMinor($record->total, $record->currency)),
                TextColumn::make('issued_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('type')->options([Invoice::SERVICE => 'Service invoice', Invoice::VOUCHER => 'Withdrawal voucher']),
            ])
            ->recordActions([
                Action::make('open')->url(fn (Invoice $record): string => $record->path(), shouldOpenInNewTab: true),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/')];
    }
}
