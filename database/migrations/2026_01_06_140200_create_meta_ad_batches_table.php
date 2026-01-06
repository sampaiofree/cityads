<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ad_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('objective');
            $table->string('ad_account_id');
            $table->string('page_id')->nullable();
            $table->string('instagram_actor_id')->nullable();
            $table->string('pixel_id')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->text('url_template');
            $table->text('title_template');
            $table->text('body_template');
            $table->string('image_path')->nullable();
            $table->boolean('auto_activate')->default(false);
            $table->unsignedInteger('daily_budget_cents')->default(660);
            $table->string('status')->default('queued');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->string('meta_campaign_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_batches');
    }
};
