<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_connections', function (Blueprint $table) {
            $table->string('app_id')->nullable()->after('token_expires_at');
            $table->text('app_secret')->nullable()->after('app_id');
        });
    }

    public function down(): void
    {
        Schema::table('meta_connections', function (Blueprint $table) {
            $table->dropColumn(['app_id', 'app_secret']);
        });
    }
};
