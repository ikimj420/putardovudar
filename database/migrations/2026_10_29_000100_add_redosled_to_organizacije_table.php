<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Redosled na javnim stranama: manji broj ide gore, prazno ide posle (paket 75).
    public function up(): void
    {
        Schema::table('organizacije', function (Blueprint $table) {
            $table->unsignedSmallInteger('redosled')->nullable()->after('beleska');
        });
    }

    public function down(): void
    {
        Schema::table('organizacije', function (Blueprint $table) {
            $table->dropColumn('redosled');
        });
    }
};
