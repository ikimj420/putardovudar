<?php

namespace App\Models;

use App\Enums\Grupa;
use App\Enums\StatusObjave;
use App\Support\LinkSajta;
use App\Support\PrikazKalendara;
use Database\Factories\KalendarStavkaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Stavka kalendara je ono što je stvarno bilo: kada je prilika bila otvorena prošli put, uz zvanični izvor. Ništa se ne predviđa.
 *
 * @property StatusObjave $status
 * @property list<string>|null $grupe
 * @property Carbon|null $otvoreno_od
 * @property Carbon $otvoreno_do
 * @property int|null $prilika_id
 */
#[Fillable(['naziv', 'ko_daje', 'grupe', 'otvoreno_od', 'otvoreno_do', 'link_izvora', 'prilika_id', 'napomena', 'status'])]
class KalendarStavka extends Model
{
    /** @use HasFactory<KalendarStavkaFactory> */
    use HasFactory;

    public const PORUKA_BEZ_IZVORA = 'Za objavu je potreban link izvora.';

    public const PORUKA_PRILIKA_NIJE_JAVNA = 'Izaberi priliku koja je sada na sajtu.';

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

            // Nova ručna veza sme samo na javnu priliku; veza koja je već stajala ostaje i kad prilika prestane da bude javna (tada se samo ne vidi).
            if ($stavka->prilika_id !== null && $stavka->isDirty('prilika_id') && ! self::prilikaJeJavna($stavka->prilika_id)) {
                throw ValidationException::withMessages(['prilika_id' => self::PORUKA_PRILIKA_NIJE_JAVNA]);
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

    // Koliko različitih meseci ima bar jednu javnu stavku; toliko odeljaka ima strana kalendara (stavka stoji u mesecu svog roka).
    public static function brojMesecaSaStavkama(): int
    {
        return (int) self::query()->javne()->selectRaw('count(distinct month(otvoreno_do)) as broj')->value('broj');
    }

    public static function prilikaJeJavna(int|string|null $id): bool
    {
        return $id !== null && Prilika::javne()->whereKey($id)->exists();
    }

    // Jedino mesto gde se odlučuje šta je „sada otvoreno" uz stavku (paket 89): prvo prilika koju je Ivan izabrao, ako je javna; inače javna prilika sa istim
    // linkom izvora. Kad izabrana prilika prestane da bude javna, ostaje samo pravilo linka; bez oba, stavka nema oznaku.
    /** @param  Collection<int, Prilika>  $javnePrilike prilike koje su sada javne (Prilika::javne) */
    public function sadaOtvorena(Collection $javnePrilike): ?Prilika
    {
        $izabrana = $this->prilika_id === null ? null : $javnePrilike->first(fn (Prilika $prilika) => (int) $prilika->getKey() === (int) $this->prilika_id);

        return $izabrana ?? $javnePrilike->first(fn (Prilika $prilika) => PrikazKalendara::istiLink($prilika->link_izvora, $this->link_izvora));
    }

    /** @return list<Grupa> */
    public function izabraneGrupe(): array
    {
        return Grupa::izVrednosti($this->grupe);
    }
}
