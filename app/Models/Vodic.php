<?php

namespace App\Models;

use App\Enums\StatusObjave;
use App\Models\Concerns\PraviSlug;
use App\Support\PrikazVodica;
use Database\Factories\VodicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property StatusObjave $status
 * @property list<string>|null $koraci
 * @property int|null $sablon_id šablon po kome pomoćnik piše tekst za ovaj vodič („Napiši moj“)
 * @property list<mixed>|null $primeri gotovi primeri (pismo, e-pošta, CV…), {naslov, tekst}; na strani vodiča stoje jedan ispod drugog, ispod koraka. Podaci u bazi mogu da stignu i mimo admina, pa se ništa o njima ne pretpostavlja
 */
#[Fillable(['naslov', 'slug', 'kratak_opis', 'tekst', 'koraci', 'primeri', 'sablon_id', 'beleska', 'status'])]
class Vodic extends Model
{
    /** @use HasFactory<VodicFactory> */
    use HasFactory, PraviSlug;

    protected $table = 'vodici';

    // Isto kao podrazumevana vrednost u bazi: nov objekat ne sme da ima prazan status.
    protected $attributes = ['status' => 'nacrt'];

    protected static function booted(): void
    {
        static::saving(function (Vodic $vodic): void {
            if (blank($vodic->slug)) {
                $vodic->slug = self::slugOd((string) $vodic->naslov);
            }
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['status' => StatusObjave::class, 'koraci' => 'array', 'primeri' => 'array'];
    }

    /** @return BelongsTo<Sablon, $this> */
    public function sablon(): BelongsTo
    {
        return $this->belongsTo(Sablon::class);
    }

    /**
     * Primeri koji se vide na strani, redom: samo oni sa tekstom; naslov kog nema postaje „Primer“.
     *
     * @return list<array{naslov: string, tekst: string}>
     */
    public function primeriZaPrikaz(): array
    {
        $primeri = [];

        foreach ((array) $this->primeri as $primer) {
            $tekst = is_array($primer) && is_string($primer['tekst'] ?? null) ? $primer['tekst'] : '';

            if (trim($tekst) !== '') {
                $naslov = is_string($primer['naslov'] ?? null) && trim($primer['naslov']) !== '' ? trim($primer['naslov']) : PrikazVodica::PRIMER;
                $primeri[] = ['naslov' => $naslov, 'tekst' => $tekst];
            }
        }

        return $primeri;
    }

    // Prvi primer je uzor oblika kad pomoćnik piše tekst po šablonu ovog vodiča; nikad izvor podataka.
    public function prviPrimer(): ?string
    {
        return $this->primeriZaPrikaz()[0]['tekst'] ?? null;
    }

    // Šablon uz vodič, ali samo ako je javan (objavljen): nacrt šablona se ne nudi nikome.
    public function javniSablon(): ?Sablon
    {
        return $this->sablon_id === null ? null : Sablon::javni()->find($this->sablon_id);
    }

    // Jedino mesto gde se odlučuje koji vodiči su javni.
    /** @param  Builder<Vodic>  $upit */
    #[Scope]
    protected function javni(Builder $upit): void
    {
        $upit->where('status', StatusObjave::Objavljeno);
    }

    // Jedino mesto gde se odlučuje šta je nacrt za pregled; admin pita ovde.
    /** @param  Builder<Vodic>  $upit */
    #[Scope]
    protected function nacrti(Builder $upit): void
    {
        $upit->where('status', StatusObjave::Nacrt);
    }
}
