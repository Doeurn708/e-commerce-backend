<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make orders.shipping_address optional.
 *
 * The delivery location is now a bare coordinate pair. The checkout map is
 * coordinates-only: it never reverse-geocodes a pin, so no street address is
 * produced client-side and none can be required here. `shipping_address` stays
 * in the schema, fillable and (nullable-)validated so an admin panel, a seed,
 * or an older client that does have a text address can still store one.
 *
 * Existing rows are untouched; only the column's NOT NULL constraint is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Cannot restore NOT NULL while coordinate-only orders hold NULL,
            // so backfill a placeholder instead of failing the rollback.
            DB::table('orders')
                ->whereNull('shipping_address')
                ->update(['shipping_address' => 'Coordinates only']);

            $table->string('shipping_address')->nullable(false)->change();
        });
    }
};
