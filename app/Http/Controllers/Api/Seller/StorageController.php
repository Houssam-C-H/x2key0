<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Seller;

use App\Domains\Storage\Actions\ImportStorageItems;
use App\Domains\Storage\Exceptions\StorageException;
use App\Domains\Storage\Models\StorageGroup;
use App\Domains\Storage\Support\StoragePayload;
use App\Http\Controllers\Api\Seller\Concerns\ApiSeller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller API › add Storage items (ADR-038): one item per line, same parsing, duplicate checks, encryption and
 * audit as the Storage page. The token needs `storage:write`, which needs two-step verification on the account.
 * The response never echoes items back.
 */
final class StorageController
{
    use ApiSeller;

    public function store(Request $request, ImportStorageItems $import, int $group): JsonResponse
    {
        $request->validate(['items' => ['required', 'string', 'max:1000000']]);
        $model = StorageGroup::query()->where('seller_profile_id', self::profile($request)->id)->whereKey($group)->first();
        abort_if($model === null, 404);

        try {
            $batch = $import->handle($model, self::user($request), 'api', StoragePayload::parse($model->item_type, $request->string('items')->toString()));
        } catch (StorageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'batch_id' => $batch->id,
            'accepted' => $batch->count_accepted,
            'duplicates' => $batch->count_duplicates,
            'flagged' => $batch->count_flagged,
            'invalid' => $batch->count_invalid,
            'available' => $model->refresh()->available_count_cached,
        ]], 201);
    }
}
