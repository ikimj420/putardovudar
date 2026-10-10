<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizacije', function (Blueprint $table) {
            $table->string('eposta', 254)->nullable()->after('telefon');
        });
    }

    public function down(): void
    {
        Schema::table('organizacije', function (Blueprint $table) {
            $table->dropColumn('eposta');
        });
    }
};
