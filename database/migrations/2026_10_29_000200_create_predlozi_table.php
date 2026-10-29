<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Predlog korisnika (paket 79): samo link, napomena i status. Nema imena, mejla, telefona ni IP adrese; predlog nije prilika.
    public function up(): void
    {
        Schema::create('predlozi', function (Blueprint $table) {
            $table->id();
            $table->string('link', 2048);
            $table->string('napomena', 500)->nullable();
            $table->string('status', 20)->default('nov');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predlozi');
    }
};
