<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait PraviSlug
{
    // Isti naslov ne sme da završi sa istim slugom: drugi dobija -2, treći -3.
    protected static function slugOd(string $naslov): string
    {
        $osnova = Str::slug($naslov) ?: 'stavka';
        $slug = $osnova;

        for ($broj = 2; static::query()->where('slug', $slug)->exists(); $broj++) {
            $slug = $osnova.'-'.$broj;
        }

        return $slug;
    }
}
