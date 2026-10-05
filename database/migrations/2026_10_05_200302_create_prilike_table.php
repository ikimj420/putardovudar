<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prilike', function (Blueprint $table) {
            $table->id();
            $table->string('naslov');
            $table->string('slug')->unique();
            $table->string('vrsta')->index();
            $table->string('status')->default('nacrt')->index();
            $table->text('kratak_opis');
            $table->longText('opis')->nullable();
            $table->date('rok')->nullable()->index();
            $table->boolean('rok_stalno_otvoren')->default(false);
            $table->string('mesto')->nullable();
            $table->boolean('online')->default(false);
            $table->string('naziv_izvora')->nullable();
            $table->string('link_izvora', 2048)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prilike');
    }
};
