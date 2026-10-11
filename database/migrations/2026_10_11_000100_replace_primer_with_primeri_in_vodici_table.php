<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Vodič može da ima više primera (paket 64): spisak {naslov, tekst}. Postojeći primer se prenosi kao prvi i jedini, pod naslovom „Primer“.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vodici', function (Blueprint $table) {
            $table->json('primeri')->nullable()->after('koraci');
        });

        foreach (DB::table('vodici')->whereNotNull('primer')->get(['id', 'primer']) as $vodic) {
            if (trim((string) $vodic->primer) === '') {
                continue;
            }

            DB::table('vodici')->where('id', $vodic->id)->update(['primeri' => json_encode([['naslov' => 'Primer', 'tekst' => $vodic->primer]], JSON_UNESCAPED_UNICODE)]);
        }

        Schema::table('vodici', function (Blueprint $table) {
            $table->dropColumn('primer');
        });
    }

    // Nazad ide samo prvi primer svakog vodiča (stara kolona je imala jedan).
    public function down(): void
    {
        Schema::table('vodici', function (Blueprint $table) {
            $table->text('primer')->nullable()->after('koraci');
        });

        foreach (DB::table('vodici')->whereNotNull('primeri')->get(['id', 'primeri']) as $vodic) {
            $lista = json_decode((string) $vodic->primeri, true);
            $prvi = is_array($lista) && is_array($lista[0] ?? null) ? ($lista[0]['tekst'] ?? null) : null;

            if (is_string($prvi) && trim($prvi) !== '') {
                DB::table('vodici')->where('id', $vodic->id)->update(['primer' => $prvi]);
            }
        }

        Schema::table('vodici', function (Blueprint $table) {
            $table->dropColumn('primeri');
        });
    }
};
