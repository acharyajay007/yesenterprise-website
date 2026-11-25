<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('category')->index();
            $table->string('brand')->nullable();
            $table->json('translations');
            $table->json('specifications')->nullable();
            $table->json('variants')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_notice')->default('supplierApplication');
            $table->string('source_url', 1000)->nullable();
            $table->boolean('published')->default(false)->index();
            $table->boolean('media_approved')->default(false);
            $table->timestamps();
        });
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->json('translations');
            $table->string('hero_image')->nullable();
            $table->boolean('media_approved')->default(false);
            $table->timestamps();
        });
        Schema::create('gallery_images', function (Blueprint $table) {
            $table->id();
            $table->string('image_path');
            $table->string('category');
            $table->json('translations');
            $table->string('notice')->default('supplied');
            $table->boolean('published')->default(false);
            $table->boolean('media_approved')->default(false);
            $table->timestamps();
        });
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('product')->nullable();
            $table->string('variant')->nullable();
            $table->string('quantity')->nullable();
            $table->string('role')->nullable();
            $table->text('message');
            $table->string('locale', 2);
            $table->string('resume_path')->nullable();
            $table->string('resume_extension', 4)->nullable();
            $table->string('delivery_status')->default('pending')->index();
            $table->text('delivery_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
        Schema::dropIfExists('gallery_images');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('products');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
