<nav class="deljenje" aria-label="{{ \App\Support\PrikazDeljenja::NASLOV }}">
    <span class="deljenje-naslov">{{ \App\Support\PrikazDeljenja::NASLOV }}</span>
    <a class="dugme-veza" href="{{ \App\Support\PrikazDeljenja::viber($naslov, $adresa) }}" rel="noopener noreferrer nofollow">{{ \App\Support\PrikazDeljenja::VIBER }}</a>
    <a class="dugme-veza" href="{{ \App\Support\PrikazDeljenja::whatsapp($naslov, $adresa) }}" rel="noopener noreferrer nofollow">{{ \App\Support\PrikazDeljenja::WHATSAPP }}</a>
</nav>
