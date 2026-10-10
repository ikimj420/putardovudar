<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prilike', function (Blueprint $table) {
            $table->date('izvor_objavljen')->nullable()->after('link_izvora');
        });
    }

    public function down(): void
    {
        Schema::table('prilike', function (Blueprint $table) {
            $table->dropColumn('izvor_objavljen');
        });
    }
};
