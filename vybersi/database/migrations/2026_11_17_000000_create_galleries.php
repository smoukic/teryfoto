<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('galleries', function (Blueprint $table) {
            $table->id();
            $table->string('token', 48)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('mode')->default('proof');
            $table->string('password')->nullable();
            $table->boolean('watermark')->default(false);
            $table->timestamps();
        });
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $table->uuid('key');
            $table->string('filename');
            $table->string('mime');
            $table->timestamps();
        });
        Schema::create('selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $table->string('submission_key', 36);
            $table->string('name');
            $table->json('items');
            $table->timestamps();
            $table->unique(['gallery_id', 'submission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('selections');
        Schema::dropIfExists('photos');
        Schema::dropIfExists('galleries');
    }
};
