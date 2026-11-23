<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Actions;

use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Seller Information (spec 1.8): shop name, store avatar, store banner, store description.
 * Uploaded images go to the public disk; the replaced file is deleted after the save.
 */
final class UpdateSellerInformation
{
    /**
     * @param  array{store_name: string, bio: array<string, string|null>|null}  $data  bio null = keep the current description
     */
    public function handle(SellerProfile $profile, array $data, ?UploadedFile $logo = null, ?UploadedFile $banner = null): SellerProfile
    {
        $obsolete = [];
        $profile->store_name = trim($data['store_name']);

        if ($data['bio'] !== null) {
            $bio = array_filter($data['bio'], static fn (?string $text): bool => $text !== null && trim($text) !== '');
            $profile->bio = $bio === [] ? null : array_map('trim', $bio);
        }

        if ($logo !== null) {
            $obsolete[] = $profile->logo_path;
            $profile->logo_path = $logo->store("stores/{$profile->id}", 'public') ?: null;
        }

        if ($banner !== null) {
            $obsolete[] = $profile->banner_path;
            $profile->banner_path = $banner->store("stores/{$profile->id}", 'public') ?: null;
        }

        $profile->save();

        $obsolete = array_values(array_filter($obsolete, static fn (?string $path): bool => $path !== null));
        if ($obsolete !== []) {
            Storage::disk('public')->delete($obsolete);
        }

        return $profile->refresh();
    }
}
