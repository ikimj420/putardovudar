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
use Illuminate\Support\Str;

#[Fillable(['naslov', 'slug', 'vrsta', 'status', 'kratak_opis', 'opis', 'rok', 'rok_stalno_otvoren', 'mesto', 'online', 'naziv_izvora', 'link_izvora'])]
class Prilika extends Model
{
    /** @use HasFactory<PrilikaFactory> */
    use HasFactory;

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
