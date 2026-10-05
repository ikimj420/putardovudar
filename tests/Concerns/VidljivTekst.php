<?php

namespace Tests\Concerns;

trait VidljivTekst
{
    // Samo ono što čovek vidi: bez skripti, stilova, slika i atributa (Livewire u njih upisuje imena klasa).
    protected function vidljivTekst(string $html): string
    {
        $html = preg_replace('#<(script|style|svg)\b.*?</\1>#si', ' ', $html) ?? '';
        $html = preg_replace('#<title>.*?</title>#si', ' ', $html) ?? '';

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('>', '> ', $html)))) ?? '');
    }

    protected function naslovStrane(string $html): string
    {
        preg_match('#<title>(.*?)</title>#si', $html, $nalaz);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($nalaz[1] ?? '')) ?? '');
    }
}
