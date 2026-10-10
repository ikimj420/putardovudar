<?php

namespace App\Models;

use App\Enums\StatusObjave;
use App\Models\Concerns\PraviSlug;
use App\Support\SklapanjeSablona;
use Database\Factories\SablonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Šablon za pisanje: naslov, kratko objašnjenje, polja koja čovek popunjava i tekst sa mestima {kljuc} za ta polja.
 *
 * @property StatusObjave $status
 * @property list<mixed> $polja polja u bazi mogu da stignu i mimo admina, pa se ništa o njima ne pretpostavlja
 */
#[Fillable(['naslov', 'slug', 'objasnjenje', 'polja', 'tekst', 'status'])]
class Sablon extends Model
{
    /** @use HasFactory<SablonFactory> */
    use HasFactory, PraviSlug;

    public const PORUKA_NEPOZNATO_POLJE = 'Tekst ima mesto za polje koje ne postoji: ';

    protected $table = 'sabloni';

    // Isto kao podrazumevana vrednost u bazi: nov objekat ne sme da ima prazan status.
    protected $attributes = ['status' => 'nacrt'];

    protected static function booted(): void
    {
        static::saving(function (Sablon $sablon): void {
            if (blank($sablon->slug)) {
                $sablon->slug = self::slugOd((string) $sablon->naslov);
            }

            // Objava sa mestom za polje kog nema ostavila bi čoveku prazan red u tekstu; ne prolazi, ma odakle stigla.
            $nepoznata = array_values(array_diff(SklapanjeSablona::kljuceUTekstu((string) $sablon->tekst), $sablon->kljucevi()));

            if ($sablon->status->jeJavno() && $nepoznata !== []) {
                throw ValidationException::withMessages(['tekst' => self::PORUKA_NEPOZNATO_POLJE.'{'.$nepoznata[0].'}']);
            }
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['status' => StatusObjave::class, 'polja' => 'array'];
    }

    /** @return list<string> */
    public function kljucevi(): array
    {
        return array_values(array_filter(array_map(fn (mixed $polje) => is_array($polje) && is_string($polje['kljuc'] ?? null) ? $polje['kljuc'] : null, (array) $this->polja)));
    }

    // Jedino mesto gde se odlučuje koji šabloni su javni.
    /** @param  Builder<Sablon>  $upit */
    #[Scope]
    protected function javni(Builder $upit): void
    {
        $upit->where('status', StatusObjave::Objavljeno);
    }

    // Jedino mesto gde se odlučuje šta je nacrt za pregled; admin pita ovde.
    /** @param  Builder<Sablon>  $upit */
    #[Scope]
    protected function nacrti(Builder $upit): void
    {
        $upit->where('status', StatusObjave::Nacrt);
    }
}
