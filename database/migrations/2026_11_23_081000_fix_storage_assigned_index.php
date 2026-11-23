<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5 made storage_items.assigned_order_item_id unique, which stops any order of two or more units from being
 * delivered (several items belong to one order item). "A storage item is assigned to at most one order item"
 * (invariant 4.3 #4) holds anyway: it is a single column per item, and delivery_storage_items.storage_item_id
 * is unique. A plain index keeps lookups fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_items', function (Blueprint $table): void {
            $table->dropUnique(['assigned_order_item_id']);
            $table->index('assigned_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('storage_items', function (Blueprint $table): void {
            $table->dropIndex(['assigned_order_item_id']);
            $table->unique('assigned_order_item_id');
        });
    }
};
