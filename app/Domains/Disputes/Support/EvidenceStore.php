<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Support;

use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Models\DisputeEvidence;
use App\Domains\Identity\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Dispute evidence on the private `local` disk with its SHA-256 (ADR-019 conventions; antivirus scanning is a
 * follow-up, `scan_status = pending`). Files are only ever streamed to the parties and staff.
 */
final class EvidenceStore
{
    public const int MAX_FILES = 5;

    /** Validation rules for one evidence file (images, PDF, short video). */
    public const array FILE_RULES = ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf,mp4,mov,txt'];

    public static function store(Dispute $dispute, UploadedFile $file, User $by, DisputeRole $role, ?string $description = null): DisputeEvidence
    {
        $path = $file->store("disputes/{$dispute->public_id}", 'local');

        $evidence = new DisputeEvidence;
        $evidence->forceFill([
            'dispute_id' => $dispute->id,
            'uploaded_by' => $by->id,
            'uploaded_by_role' => $role,
            'file_path' => (string) $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 191),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'sha256' => (string) hash_file('sha256', $file->getRealPath()),
            'description' => $description === null ? null : mb_substr($description, 0, 255),
            'scan_status' => 'pending',
            'created_at' => now(),
        ])->save();

        return $evidence;
    }
}
