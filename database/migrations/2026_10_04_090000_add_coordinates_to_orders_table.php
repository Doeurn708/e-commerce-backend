<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery coordinates picked on the checkout map.
 *
 * These two columns are the delivery location. They are nullable here because
 * rows created before the map existed have no pin; the API still requires both
 * on create. decimal(10,7) is the standard GPS precision: 7 decimal places is
 * roughly 1.1cm at the equator, and 10 digits of total precision covers the
 * ±180 range.
 *
 * shipping_address is optional and was made nullable by a later migration: the
 * checkout map is coordinates-only and never reverse-geocodes the pin.
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
