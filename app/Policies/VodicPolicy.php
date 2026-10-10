<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vodic;

// Jedino mesto koje kaže ko sme šta sa vodičima. Uloga još nema, pa svaki prijavljen korisnik sme da vidi, doda i izmeni; brisanja nema.
class VodicPolicy
{
    public function viewAny(User $korisnik): bool
    {
        return true;
    }

    public function view(User $korisnik, Vodic $vodic): bool
    {
        return true;
    }

    public function create(User $korisnik): bool
    {
        return true;
    }

    public function update(User $korisnik, Vodic $vodic): bool
    {
        return true;
    }

    public function delete(User $korisnik, Vodic $vodic): bool
    {
        return false;
    }

    public function deleteAny(User $korisnik): bool
    {
        return false;
    }

    public function restore(User $korisnik, Vodic $vodic): bool
    {
        return false;
    }

    public function forceDelete(User $korisnik, Vodic $vodic): bool
    {
        return false;
    }
}
