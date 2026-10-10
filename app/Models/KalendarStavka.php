<?php

namespace App\Models;

use App\Enums\Grupa;
use App\Enums\StatusObjave;
use App\Support\LinkSajta;
use Database\Factories\KalendarStavkaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Stavka kalendara je ono što je stvarno bilo: kada je prilika bila otvorena prošli put, uz zvanični izvor. Ništa se ne predviđa.
 *
 * @property StatusObjave $status
 * @property list<string>|null $grupe
 * @property Carbon|null $otvoreno_od
 * @property Carbon $otvoreno_do
 */
#[Fillable(['naziv', 'ko_daje', 'grupe', 'otvoreno_od', 'otvoreno_do', 'link_izvora', 'napomena', 'status'])]
class KalendarStavka extends Model
{
    /** @use HasFactory<KalendarStavkaFactory> */
    use HasFactory;

    public const PORUKA_BEZ_IZVORA = 'Za objavu je potreban link izvora.';

    protected $table = 'kalendar_stavke';

    // Isto kao podrazumevana vrednost u bazi: nov objekat ne sme da ima prazan status.
    protected $attributes = ['status' => 'nacrt'];

    protected static function booted(): void
    {
        static::saving(function (KalendarStavka $stavka): void {
            // Grupe se čuvaju kao vrednosti iz enuma, u njegovom redosledu, bez ponavljanja; prazno je null.
            $grupe = array_map(fn (Grupa $grupa) => $grupa->value, Grupa::izVrednosti($stavka->grupe));
            $stavka->grupe = $grupe === [] ? null : $grupe;

            // Objava bez ispravnog linka izvora ne prolazi, ma odakle stigla.
            if ($stavka->status->jeJavno() && ! LinkSajta::jeIspravan($stavka->link_izvora)) {
                throw ValidationException::withMessages(['link_izvora' => self::PORUKA_BEZ_IZVORA]);
            }
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'grupe' => 'array',
            'otvoreno_od' => 'date',
            'otvoreno_do' => 'date',
            'status' => StatusObjave::class,
        ];
    }

    // Jedino mesto gde se odlučuje šta je javno u kalendaru: objavljeno, i samo ono što je već bilo (poslednji dan pre danas);
    // „danas" je po zoni aplikacije. Stavka čiji je kraj danas ili kasnije bila bi najava, a najava nema mesta u kalendaru.
    /** @param  Builder<KalendarStavka>  $upit */
    #[Scope]
    protected function javne(Builder $upit): void
    {
        $upit->where('status', StatusObjave::Objavljeno)->where('otvoreno_do', '<', today()->toDateString());
    }

    /** @return list<Grupa> */
    public function izabraneGrupe(): array
    {
        return Grupa::izVrednosti($this->grupe);
    }
}
