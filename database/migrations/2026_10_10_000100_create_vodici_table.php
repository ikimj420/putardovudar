<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vodici', function (Blueprint $table) {
            $table->id();
            $table->string('naslov');
            $table->string('slug')->unique();
            $table->text('kratak_opis');
            $table->longText('tekst');
            $table->json('koraci')->nullable();
            $table->text('beleska')->nullable();
            $table->string('status')->default('nacrt')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vodici');
    }
};
