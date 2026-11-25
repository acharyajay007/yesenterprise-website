<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_popular')->default(false)->index();
            $table->unsignedInteger('popular_order')->default(0);
            $table->string('image_source_url', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_popular', 'popular_order', 'image_source_url']);
        });
    }
};
