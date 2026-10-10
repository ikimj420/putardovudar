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
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property StatusObjave $status
 * @property list<string>|null $koraci
 * @property int|null $sablon_id šablon po kome pomoćnik piše tekst za ovaj vodič („Napiši moj“)
 * @property string|null $primer gotov primer (pismo, e-pošta, CV…) koji se na strani vodiča pokazuje ispod koraka
 */
#[Fillable(['naslov', 'slug', 'kratak_opis', 'tekst', 'koraci', 'primer', 'sablon_id', 'beleska', 'status'])]
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

    /** @return BelongsTo<Sablon, $this> */
    public function sablon(): BelongsTo
    {
        return $this->belongsTo(Sablon::class);
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
