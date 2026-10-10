<?php

namespace App\Models;

use App\Enums\StatusObjave;
use App\Models\Concerns\PraviSlug;
use Database\Factories\VodicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property StatusObjave $status
 * @property list<string>|null $koraci
 */
#[Fillable(['naslov', 'slug', 'kratak_opis', 'tekst', 'koraci', 'beleska', 'status'])]
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
        return ['status' => StatusObjave::class, 'koraci' => 'array'];
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
