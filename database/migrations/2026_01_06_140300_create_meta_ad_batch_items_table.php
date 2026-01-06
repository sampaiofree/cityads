<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ad_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_ad_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('city_name');
            $table->string('state_name')->nullable();
            $table->string('meta_city_key')->nullable();
            $table->string('ad_set_id')->nullable();
            $table->string('ad_id')->nullable();
            $table->string('ad_creative_id')->nullable();
            $table->string('image_hash')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_batch_items');
    }
};
