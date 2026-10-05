@extends('javno.raspored', ['naslov' => '404'])

@section('sadrzaj')
    <p class="prazno">{{ \App\Support\PrikazPrilike::NEMA_STRANE }}</p>
@endsection
