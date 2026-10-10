<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizacije', function (Blueprint $table) {
            $table->id();
            $table->string('naziv');
            $table->string('slug')->unique();
            $table->string('vrsta')->index();
            $table->text('kratak_opis');
            $table->longText('opis')->nullable();
            $table->text('mesto')->nullable();
            $table->boolean('online')->default(false);
            $table->string('telefon', 64)->nullable();
            $table->string('sajt', 2048)->nullable();
            $table->json('usluge')->nullable();
            $table->text('beleska')->nullable();
            $table->string('status')->default('nacrt')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizacije');
    }
};
