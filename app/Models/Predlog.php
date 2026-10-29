<?php

namespace App\Models;

use App\Enums\StatusPredloga;
use Database\Factories\PredlogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property StatusPredloga $status
 */
#[Fillable(['link', 'napomena', 'status'])]
class Predlog extends Model
{
    /** @use HasFactory<PredlogFactory> */
    use HasFactory;

    public const NAJVISE_ZNAKOVA_NAPOMENE = 500;

    public const NAJVISE_ZNAKOVA_LINKA = 2048;

    protected $table = 'predlozi';

    // Isto kao podrazumevana vrednost u bazi: nov objekat ne sme da ima prazan status.
    protected $attributes = ['status' => 'nov'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['status' => StatusPredloga::class];
    }
}
