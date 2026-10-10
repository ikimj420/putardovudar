<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kalendar_stavke', function (Blueprint $table) {
            $table->id();
            $table->string('naziv');
            $table->string('ko_daje')->nullable();
            $table->json('grupe')->nullable();
            $table->date('otvoreno_od')->nullable();
            $table->date('otvoreno_do')->index();
            $table->string('link_izvora', 2048)->nullable();
            $table->text('napomena')->nullable();
            $table->string('status')->default('nacrt')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kalendar_stavke');
    }
};
