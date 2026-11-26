<?php

declare(strict_types=1);

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\StaffNote;
use App\Domains\Identity\Models\User;
use App\Shared\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Internal notes on a user, store or order. Staff only, never shown to the user, audited.
 */
final class StaffNotes
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function add(User $staff, string $subjectType, int $subjectId, string $body): StaffNote
    {
        if (! $staff->isStaff()) {
            throw new DomainException('Only staff can add notes.');
        }
        if (! in_array($subjectType, StaffNote::SUBJECTS, true)) {
            throw new DomainException("Unknown note subject [{$subjectType}].");
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 5000) {
            throw new DomainException('A note needs 1 to 5000 characters.');
        }

        $note = StaffNote::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'author_id' => $staff->id,
            'body' => $body,
        ]);
        $this->audit->log('staff_notes.added', $note, null, ['subject' => "{$subjectType}:{$subjectId}"], $staff);

        return $note->refresh();
    }

    /**
     * @return Collection<int, StaffNote>
     */
    public function for(string $subjectType, int $subjectId, int $limit = 50): Collection
    {
        return StaffNote::query()->with('author')
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->latest('id')->limit($limit)->get();
    }
}
