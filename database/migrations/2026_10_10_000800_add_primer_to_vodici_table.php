<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vodici', function (Blueprint $table) {
            $table->text('primer')->nullable()->after('koraci');
        });
    }

    public function down(): void
    {
        Schema::table('vodici', function (Blueprint $table) {
            $table->dropColumn('primer');
        });
    }
};
