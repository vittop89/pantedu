<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Nelle unità di `tools/systemd` un commento non sta sulla riga di un valore
 * (24/9/2026).
 *
 * systemd riconosce come commento solo una riga che comincia con `#` o `;`.
 * `ProtectSystem=full   # /usr in sola lettura` non è un valore con un
 * commento: è un valore sbagliato, che systemd scarta con «Failed to parse …,
 * ignoring» nel registro e poi parte lo stesso. Nella sandbox di PHP-FPM nove
 * direttive su quattordici erano scritte così: la sandbox sembrava in piedi e
 * ne valevano cinque. `check-deploy-units.mjs` non lo vedeva, e il controllo
 * `unita` della diagnostica confronta i file, non che cosa systemd ne capisce.
 *
 * Il cancelletto dentro un valore, preceduto da uno spazio, è quasi sempre un
 * commento sbagliato; se un giorno ne servirà uno vero (in una Description, in
 * un ExecStart), lo si scriverà senza lo spazio davanti.
 */
final class UnitaSenzaCommentiInLineaTest extends TestCase
{
    private const CARTELLA = __DIR__ . '/../../../tools/systemd';

    /** @return list<int> i numeri delle righe con un commento dopo il valore */
    private static function righeConCommentoInLinea(string $testo): array
    {
        $trovate = [];
        foreach (preg_split('/\R/', $testo) ?: [] as $i => $riga) {
            if (preg_match('/^\s*[A-Za-z][A-Za-z0-9]*\s*=.*\s#/', $riga) === 1) {
                $trovate[] = $i + 1;
            }
        }
        return $trovate;
    }

    #[Test]
    public function nessuna_unita_del_repository_ha_un_commento_sulla_riga_di_un_valore(): void
    {
        $file = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            self::CARTELLA,
            \FilesystemIterator::SKIP_DOTS
        ));
        $esaminati = 0;
        $difetti = [];
        foreach ($file as $f) {
            if (!preg_match('/\.(service|timer|path|socket|mount|conf)$/', $f->getFilename())) {
                continue;
            }
            $esaminati++;
            foreach (self::righeConCommentoInLinea((string) file_get_contents($f->getPathname())) as $n) {
                $difetti[] = substr($f->getPathname(), strlen(self::CARTELLA) + 1) . ':' . $n;
            }
        }

        // Un controllo che non ha letto niente dice sempre «va bene».
        self::assertGreaterThan(20, $esaminati, 'la prova non ha trovato le unità di tools/systemd');
        self::assertSame([], $difetti, 'systemd scarterebbe queste righe: il commento va sulla riga sopra');
    }

    #[Test]
    public function la_forma_sbagliata_si_riconosce_e_quelle_giuste_no(): void
    {
        $unita = "[Service]\n"
            . "# un commento sulla sua riga\n"
            . "; anche questo è un commento\n"
            . "ProtectSystem=full              # /usr in sola lettura\n"
            . "NoNewPrivileges=true\n"
            . "ExecStart=/bin/sh -c 'a ; b'\n"
            . "Description=pantedu — diagnostica#interna\n"
            . "  LockPersonality = true\t# con tabulazione e spazi attorno all'uguale\n";

        self::assertSame([4, 8], self::righeConCommentoInLinea($unita));
    }
}
