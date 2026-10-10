<?php

namespace App\Policies;

use App\Models\Sablon;
use App\Models\User;

// Jedino mesto koje kaže ko sme šta sa šablonima. Uloga još nema, pa svaki prijavljen korisnik sme da vidi, doda i izmeni; brisanja nema.
class SablonPolicy
{
    public function viewAny(User $korisnik): bool
    {
        return true;
    }

    public function view(User $korisnik, Sablon $sablon): bool
    {
        return true;
    }

    public function create(User $korisnik): bool
    {
        return true;
    }

    public function update(User $korisnik, Sablon $sablon): bool
    {
        return true;
    }

    public function delete(User $korisnik, Sablon $sablon): bool
    {
        return false;
    }

    public function deleteAny(User $korisnik): bool
    {
        return false;
    }

    public function restore(User $korisnik, Sablon $sablon): bool
    {
        return false;
    }

    public function forceDelete(User $korisnik, Sablon $sablon): bool
    {
        return false;
    }
}
