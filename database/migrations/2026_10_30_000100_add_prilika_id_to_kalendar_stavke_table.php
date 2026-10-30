<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kalendar_stavke', function (Blueprint $table) {
            // Ručna veza „sada otvoreno kao" (paket 89): izabrana prilika; kad se prilika ukloni, veza nestaje, a stavka ostaje.
            $table->foreignId('prilika_id')->nullable()->after('link_izvora')->constrained('prilike')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kalendar_stavke', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prilika_id');
        });
    }
};
