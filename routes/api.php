<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Seller\OffersController;
use App\Http\Controllers\Api\Seller\OrdersController;
use App\Http\Controllers\Api\Seller\StorageController;
use Illuminate\Support\Facades\Route;

/*
| Seller API v1 (ADR-038, docs/API.md): personal access tokens from Seller Center › API tokens, one ability per
| area, 120 requests per minute per token. Every write goes through the same actions as the website.
*/
Route::prefix('v1/seller')->middleware(['auth:sanctum', 'seller.api', 'throttle:seller-api'])->group(function (): void {
    Route::get('/offers', [OffersController::class, 'index'])->middleware('abilities:offers:read');
    Route::get('/offers/{publicId}', [OffersController::class, 'show'])->middleware('abilities:offers:read');
    Route::post('/offers', [OffersController::class, 'store'])->middleware('abilities:offers:write');
    Route::patch('/offers/{publicId}', [OffersController::class, 'update'])->middleware('abilities:offers:write');
    Route::post('/offers/{publicId}/{operation}', [OffersController::class, 'operate'])->whereIn('operation', ['deactivate', 'relist'])->middleware('abilities:offers:write');

    Route::get('/orders', [OrdersController::class, 'index'])->middleware('abilities:orders:read');
    Route::get('/orders/{publicId}', [OrdersController::class, 'show'])->middleware('abilities:orders:read');

    Route::post('/storage/groups/{group}/items', [StorageController::class, 'store'])->whereNumber('group')->middleware('abilities:storage:write');
});
