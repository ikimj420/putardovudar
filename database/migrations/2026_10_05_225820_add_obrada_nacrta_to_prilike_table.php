<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prilike', function (Blueprint $table) {
            $table->timestamp('obradeno_at')->nullable()->index();
            $table->string('objavio')->nullable();
            $table->text('razlog_objave')->nullable();
            $table->json('predlog')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prilike', function (Blueprint $table) {
            $table->dropColumn(['obradeno_at', 'objavio', 'razlog_objave', 'predlog']);
        });
    }
};
