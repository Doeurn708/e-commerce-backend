<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The column used to hold a local path like `products/abc.jpg`, which fits
     * comfortably in varchar(255). A Cloudinary delivery URL carries the cloud
     * name, a version and the public_id, and grows further once transformations
     * or a CDN suffix are added, so the column has to become unbounded text.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('image')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Truncating is the only safe way back: existing Cloudinary URLs are
        // longer than the 255 characters the original column allowed.
        DB::table('products')->whereNotNull('image')->update(['image' => DB::raw('left(image, 255)')]);

        Schema::table('products', function (Blueprint $table) {
            $table->string('image')->nullable()->change();
        });
    }
};
