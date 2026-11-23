<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerStoreMedia;
use App\Domains\Sellers\StateMachines\SellerProfileStateMachine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Store Management (spec Part 20 /seller/store): store notice, chat auto-reply, languages spoken
 * and vacation mode. Vacation goes through the state machine.
 */
final class UpdateStoreSettings
{
    public function __construct(private readonly SellerProfileStateMachine $machine) {}

    /**
     * @param  array{
     *     store_notice: array<string, string|null>,
     *     auto_reply: array{first_message: string|null, off_hours_message: string|null, hours_start: string|null, hours_end: string|null},
     *     languages: list<array{code: string, level: string}>,
     *     vacation: bool,
     *     vacation_until: string|null
     * }  $data
     */
    public function handle(SellerProfile $profile, User $actor, array $data): SellerProfile
    {
        return DB::transaction(function () use ($profile, $actor, $data): SellerProfile {
            $notice = array_filter($data['store_notice'], static fn (?string $t): bool => $t !== null && trim($t) !== '');
            $autoReply = array_map(static fn (?string $v): ?string => $v === null || trim($v) === '' ? null : trim($v), $data['auto_reply']);

            $profile->store_notice = $notice === [] ? null : array_map('trim', $notice);
            $profile->auto_reply = array_filter($autoReply, static fn (?string $v): bool => $v !== null) === [] ? null : $autoReply;
            $profile->save();

            SellerStoreMedia::query()->updateOrCreate(
                ['seller_profile_id' => $profile->id],
                ['languages' => $data['languages']],
            );

            $until = $data['vacation_until'] === null ? null : Carbon::parse($data['vacation_until'])->endOfDay();

            if ($data['vacation'] && $profile->status === SellerStatus::Active) {
                $profile = $this->machine->startVacation($profile, $until, $actor);
            } elseif ($data['vacation'] && $profile->status === SellerStatus::Vacation) {
                $profile->vacation_until = $until;
                $profile->save();
            } elseif (! $data['vacation'] && $profile->status === SellerStatus::Vacation) {
                $profile = $this->machine->endVacation($profile, $actor);
            }

            return $profile->refresh();
        });
    }
}
