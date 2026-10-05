<?php

namespace App\Models;

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
 */
#[Fillable(['naslov', 'slug', 'vrsta', 'status', 'kratak_opis', 'opis', 'rok', 'rok_stalno_otvoren', 'mesto', 'online', 'naziv_izvora', 'link_izvora'])]
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
        ];
    }

    // Jedino mesto gde se odlučuje šta je javno; „danas" je po zoni aplikacije (Europe/Belgrade).
    /** @param  Builder<Prilika>  $upit */
    #[Scope]
    protected function javne(Builder $upit): void
    {
        $upit->where('status', StatusPrilike::Objavljeno)
            ->where(fn (Builder $rok) => $rok
                ->whereNull('rok')
                ->orWhere('rok_stalno_otvoren', true)
                ->orWhere('rok', '>=', today()->toDateString()));
    }

    private static function jeLink(?string $vrednost): bool
    {
        $link = trim((string) $vrednost);

        return $link !== ''
            && filter_var($link, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true);
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
