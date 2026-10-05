<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->text('description')->nullable()->after('slug');
            $table->string('image')->nullable()->after('description');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('city')->nullable()->after('shipping_address');
            $table->string('province')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('province');
            $table->string('payment_method')->default('cod')->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['description', 'image']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['city', 'province', 'postal_code', 'payment_method']);
        });
    }
};
