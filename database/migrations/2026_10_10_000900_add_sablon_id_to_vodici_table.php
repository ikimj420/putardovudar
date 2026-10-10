<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vodici', function (Blueprint $table) {
            $table->foreignId('sablon_id')->nullable()->after('primer')->constrained('sabloni')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vodici', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sablon_id');
        });
    }
};
