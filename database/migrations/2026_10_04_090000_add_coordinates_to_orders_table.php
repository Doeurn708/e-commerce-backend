<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery coordinates picked on the checkout map.
 *
 * decimal(10,7) is the standard GPS precision: 7 decimal places is roughly
 * 1.1cm at the equator, and 10 digits of total precision covers the ±180
 * range. Nullable because rows created before the map existed have no pin.
 *
 * shipping_address itself is untouched: it stays the human-readable label the
 * customer selected, and the coordinates are the machine-readable pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('shipping_address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};