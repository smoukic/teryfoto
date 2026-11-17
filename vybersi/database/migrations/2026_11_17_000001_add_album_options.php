<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained('catalog_entries')->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['kind', 'name']);
        });
        Schema::table('galleries', function (Blueprint $table) {
            $table->json('settings')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
        });
        Schema::table('selections', function (Blueprint $table) {
            $table->json('checkout')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('selections', fn (Blueprint $table) => $table->dropColumn('checkout'));
        Schema::table('galleries', fn (Blueprint $table) => $table->dropColumn(['settings', 'expires_at', 'reminded_at']));
        Schema::dropIfExists('catalog_entries');
    }
};
