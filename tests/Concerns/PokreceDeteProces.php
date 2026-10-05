<?php

namespace Tests\Concerns;

use Symfony\Component\Process\Process;

trait PokreceDeteProces
{
    /**
     * Dnevnik događaja se čita umesto ispisa: ispis menja oblik zavisno od okruženja u kome se pokreće.
     *
     * @param  list<string>  $argumenti
     * @param  array<string, string>  $okruzenje
     * @return array{0: int, 1: string}
     */
    protected function pokreniPhpunit(array $argumenti, array $okruzenje): array
    {
        $dnevnik = sys_get_temp_dir().'/dete-'.bin2hex(random_bytes(4)).'.log';

        $proces = new Process(
            [PHP_BINARY, 'vendor/bin/phpunit', ...$argumenti, '--log-events-text', $dnevnik],
            base_path(),
            $okruzenje,
        );
        $proces->setTimeout(120)->run();

        $tekst = is_file($dnevnik) ? file_get_contents($dnevnik) : '';
        @unlink($dnevnik);

        $this->assertNotSame('', $tekst, 'Dete proces nije ostavio dnevnik: '.$proces->getErrorOutput());

        return [$proces->getExitCode(), $tekst];
    }
}
