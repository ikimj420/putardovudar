<?php

namespace App\Models;

use App\Enums\KoJeObjavio;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use Database\Factories\PrilikaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Larastan ne prepoznaje cast-ove ovog modela (status mu je string), pa tipove koje kod koristi navodimo ovde.
 *
 * @property StatusPrilike $status
 * @property VrstaPrilike $vrsta
 * @property Carbon|null $rok
 * @property KoJeObjavio|null $objavio
 * @property Carbon|null $obradeno_at
 * @property array<string, mixed>|null $predlog
 */
#[Fillable(['naslov', 'slug', 'vrsta', 'status', 'kratak_opis', 'opis', 'rok', 'rok_stalno_otvoren', 'mesto', 'online', 'naziv_izvora', 'link_izvora', 'obradeno_at', 'objavio', 'razlog_objave', 'predlog'])]
class Prilika extends Model
{
    /** @use HasFactory<PrilikaFactory> */
    use HasFactory;

    public const PORUKA_BEZ_IZVORA = 'Za objavu je potreban link izvora.';

    protected $table = 'prilike';

    // Isto kao podrazumevane vrednosti u bazi: nov objekat ne sme da ima prazan status.
    protected $attributes = [
        'status' => 'nacrt',
        'rok_stalno_otvoren' => false,
        'online' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (Prilika $prilika): void {
            if (blank($prilika->slug)) {
                $prilika->slug = self::slugOd((string) $prilika->naslov);
            }

            if ($prilika->status->zahtevaIzvor() && ! self::jeLink($prilika->link_izvora)) {
                throw ValidationException::withMessages(['link_izvora' => self::PORUKA_BEZ_IZVORA]);
            }

            // Objava koja nije prošla kroz objavi() je ručna: čovek je promenio status u admin.
            if ($prilika->isDirty('status') && $prilika->status === StatusPrilike::Objavljeno && ! $prilika->isDirty('objavio')) {
                $prilika->objavio = KoJeObjavio::Covek;
            }
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'vrsta' => VrstaPrilike::class,
            'status' => StatusPrilike::class,
            'rok' => 'date',
            'rok_stalno_otvoren' => 'boolean',
            'online' => 'boolean',
            'obradeno_at' => 'datetime',
            'objavio' => KoJeObjavio::class,
            'predlog' => 'array',
        ];
    }

    // Jedino mesto gde se odlučuje da li je rok prošao: ima rok, prijave nisu stalno otvorene, a rok je pre današnjeg dana
    // (rok „danas" nije prošao). „Danas" je po zoni aplikacije (Europe/Belgrade). Javne prilike i oznaka u adminu pitaju ovde.
    private const ROK_PROSAO = 'prilike.rok_stalno_otvoren = 0 and prilike.rok is not null and prilike.rok < ?';

    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function rokProsao(Builder $upit): void
    {
        $upit->whereRaw(self::ROK_PROSAO, [today()->toDateString()]);
    }

    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function rokNijeProsao(Builder $upit): void
    {
        $upit->whereRaw('not ('.self::ROK_PROSAO.')', [today()->toDateString()]);
    }

    // Isti uslov kao rokProsao, ali kao kolona uz svaki zapis spiska, da kartica ne pita bazu za svaki zapis posebno.
    /**
     * @param  Builder<Prilika>  $upit
     * @return Builder<Prilika>
     */
    public static function saOznakomRoka(Builder $upit): Builder
    {
        return $upit->addSelect('prilike.*')->selectRaw('('.self::ROK_PROSAO.') as rok_prosao', [today()->toDateString()]);
    }

    public function rokJeProsao(): bool
    {
        return (bool) ($this->attributes['rok_prosao'] ?? self::query()->rokProsao()->whereKey($this->getKey())->exists());
    }

    // Jedino mesto gde se odlučuje šta je javno.
    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function javne(Builder $upit): void
    {
        $upit->where('status', StatusPrilike::Objavljeno)->rokNijeProsao();
    }

    // Jedino mesto gde se odlučuje šta je nacrt za pregled; admin pita ovde.
    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function nacrti(Builder $upit): void
    {
        $upit->where('status', StatusPrilike::Nacrt);
    }

    private static function jeLink(?string $vrednost): bool
    {
        $link = trim((string) $vrednost);

        return $link !== ''
            && filter_var($link, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    // Objava kroz model: pravilo o izvoru (saving) i dalje važi, a ko je objavio ostaje zapisano.
    public function objavi(KoJeObjavio $ko): void
    {
        $this->status = StatusPrilike::Objavljeno;
        $this->objavio = $ko;
        $this->save();
    }

    // Isti naslov ne sme da završi sa istim slugom: drugi dobija -2, treći -3.
    private static function slugOd(string $naslov): string
    {
        $osnova = Str::slug($naslov);
        $slug = $osnova;

        for ($broj = 2; self::query()->where('slug', $slug)->exists(); $broj++) {
            $slug = $osnova.'-'.$broj;
        }

        return $slug;
    }
}
