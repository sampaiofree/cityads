<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            if (! Schema::hasColumn('cities', 'meta_city_key')) {
                $table->string('meta_city_key')->nullable()->after('state_code')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            if (Schema::hasColumn('cities', 'meta_city_key')) {
                $table->dropColumn('meta_city_key');
            }
        });
    }
};
