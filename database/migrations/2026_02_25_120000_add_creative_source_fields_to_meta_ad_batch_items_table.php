<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ad_batch_items', function (Blueprint $table) {
            if (!Schema::hasColumn('meta_ad_batch_items', 'creative_source_path')) {
                $table->string('creative_source_path')->nullable()->after('image_hash');
            }

            if (!Schema::hasColumn('meta_ad_batch_items', 'creative_source_index')) {
                $table->unsignedSmallInteger('creative_source_index')->nullable()->after('creative_source_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meta_ad_batch_items', function (Blueprint $table) {
            if (Schema::hasColumn('meta_ad_batch_items', 'creative_source_index')) {
                $table->dropColumn('creative_source_index');
            }

            if (Schema::hasColumn('meta_ad_batch_items', 'creative_source_path')) {
                $table->dropColumn('creative_source_path');
            }
        });
    }
};
