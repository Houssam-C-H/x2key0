<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Seller API: only tokens of users whose store opens the Seller Center; JSON 403 otherwise (ADR-038). */
final class EnsureSellerApi
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            $user->unsetRelation('sellerProfile');
        }
        if (! $user instanceof User || ! $user->isSeller()) {
            return response()->json(['message' => 'This account has no active store.'], 403);
        }

        return $next($request);
    }
}
