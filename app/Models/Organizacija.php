<?php

namespace App\Models;

use App\Enums\StatusObjave;
use App\Enums\VrstaOrganizacije;
use App\Models\Concerns\PraviSlug;
use App\Support\LinkSajta;
use Database\Factories\OrganizacijaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * @property StatusObjave $status
 * @property VrstaOrganizacije $vrsta
 * @property list<string>|null $usluge
 */
#[Fillable(['naziv', 'slug', 'vrsta', 'kratak_opis', 'opis', 'mesto', 'online', 'telefon', 'sajt', 'usluge', 'beleska', 'status'])]
class Organizacija extends Model
{
    /** @use HasFactory<OrganizacijaFactory> */
    use HasFactory, PraviSlug;

    public const PORUKA_BEZ_SAJTA = 'Za objavu je potreban link sajta.';

    protected $table = 'organizacije';

    // Isto kao podrazumevane vrednosti u bazi: nov objekat ne sme da ima prazan status.
    protected $attributes = ['status' => 'nacrt', 'online' => false];

    protected static function booted(): void
    {
        static::saving(function (Organizacija $organizacija): void {
            if (blank($organizacija->slug)) {
                $organizacija->slug = self::slugOd((string) $organizacija->naziv);
            }

            // Isto pravilo kao izvor prilike: objava bez ispravnog linka sajta ne prolazi, ma odakle stigla.
            if ($organizacija->status->jeJavno() && ! LinkSajta::jeIspravan($organizacija->sajt)) {
                throw ValidationException::withMessages(['sajt' => self::PORUKA_BEZ_SAJTA]);
            }
        });
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['status' => StatusObjave::class, 'vrsta' => VrstaOrganizacije::class, 'online' => 'boolean', 'usluge' => 'array'];
    }

    // Jedino mesto gde se odlučuje koje organizacije su javne.
    /** @param  Builder<Organizacija>  $upit */
    #[Scope]
    protected function javne(Builder $upit): void
    {
        $upit->where('status', StatusObjave::Objavljeno);
    }

    // Jedino mesto gde se odlučuje šta je nacrt za pregled; admin pita ovde.
    /** @param  Builder<Organizacija>  $upit */
    #[Scope]
    protected function nacrti(Builder $upit): void
    {
        $upit->where('status', StatusObjave::Nacrt);
    }
}
