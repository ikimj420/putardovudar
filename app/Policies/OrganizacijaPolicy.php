<?php

namespace App\Policies;

use App\Models\Organizacija;
use App\Models\User;

// Jedino mesto koje kaže ko sme šta sa organizacijama. Uloga još nema, pa svaki prijavljen korisnik sme da vidi, doda i izmeni; brisanja nema.
class OrganizacijaPolicy
{
    public function viewAny(User $korisnik): bool
    {
        return true;
    }

    public function view(User $korisnik, Organizacija $organizacija): bool
    {
        return true;
    }

    public function create(User $korisnik): bool
    {
        return true;
    }

    public function update(User $korisnik, Organizacija $organizacija): bool
    {
        return true;
    }

    public function delete(User $korisnik, Organizacija $organizacija): bool
    {
        return false;
    }

    public function deleteAny(User $korisnik): bool
    {
        return false;
    }

    public function restore(User $korisnik, Organizacija $organizacija): bool
    {
        return false;
    }

    public function forceDelete(User $korisnik, Organizacija $organizacija): bool
    {
        return false;
    }
}
