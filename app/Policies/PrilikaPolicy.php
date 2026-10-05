<?php

namespace App\Policies;

use App\Models\Prilika;
use App\Models\User;

// Jedino mesto koje kaže ko sme šta sa prilikama. Uloga još nema, pa svaki prijavljen korisnik sme da vidi, doda i izmeni; brisanja nema.
class PrilikaPolicy
{
    public function viewAny(User $korisnik): bool
    {
        return true;
    }

    public function view(User $korisnik, Prilika $prilika): bool
    {
        return true;
    }

    public function create(User $korisnik): bool
    {
        return true;
    }

    public function update(User $korisnik, Prilika $prilika): bool
    {
        return true;
    }

    public function delete(User $korisnik, Prilika $prilika): bool
    {
        return false;
    }

    public function deleteAny(User $korisnik): bool
    {
        return false;
    }

    public function restore(User $korisnik, Prilika $prilika): bool
    {
        return false;
    }

    public function forceDelete(User $korisnik, Prilika $prilika): bool
    {
        return false;
    }
}
