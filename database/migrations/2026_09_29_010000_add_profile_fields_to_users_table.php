<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // `phone` used to be faked from the customer's latest order in
            // UsersController; the profile form needs a real column now.
            $table->string('phone', 30)->nullable()->after('email');

            // Relative path on the public disk, served from /storage/<path>,
            // exactly like the product and category images.
            $table->string('avatar')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'avatar']);
        });
    }
};
