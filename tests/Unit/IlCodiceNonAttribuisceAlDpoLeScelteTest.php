<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il codice non descrive le scelte del Titolare come prese dal DPO di un
 * Istituto.
 *
 * ── Perché (22/9/2026) ────────────────────────────────────────────────────
 *
 * La comunicazione al DPO dell'Istituto dice che l'impostazione sulle
 * compilazioni «non è più descritta come presa su indicazione del DPO», nei
 * documenti e nel **codice sorgente**, che è pubblico e che la lettera offre
 * come verifica. La pull request #227 aveva corretto cinque punti. Una
 * rilettura della lettera ne ha trovati altri sei, scritti con parole diverse
 * — «Il DPO di un Istituto puo' chiedere», «Un DPO puo' chiedere», «parere del
 * DPO ricevuto» — e uno era codice che gira: ogni cambio dell'impostazione
 * scriveva «(indicazione del DPO dell'Istituto)» nel registro degli accessi
 * privilegiati.
 *
 * Non è una questione di parole. Un mezzo del trattamento determinato su
 * indicazione di un soggetto esterno è l'elemento che l'art. 26 cerca per
 * parlare di contitolarità; una frase del genere nel codice dice il contrario
 * di tutto l'impianto.
 *
 * ── Che cosa guarda ───────────────────────────────────────────────────────
 *
 * Il codice che gira e i suoi commenti (`app/`, `routes/`, `views/`,
 * `database/migrations/`, `js/`). Non guarda `docs/`: lì le storie delle
 * revisioni raccontano la vecchia formula, e devono poterlo fare. Per la
 * stessa ragione, nel codice si ignora il testo fra «virgolette», che è il
 * modo in cui un commento cita ciò che c'era prima.
 */
final class IlCodiceNonAttribuisceAlDpoLeScelteTest extends TestCase
{
    /** Le forme trovate il 22/9/2026. */
    private const FORME = [
        '/indicazione del (?:suo )?DPO/i',
        '/\bDPO di (?:un|quell[oa]) Istituto pu/i',
        '/\bUn DPO pu[oò]\'? chiedere/i',
        '/parere del DPO ricevuto/i',
    ];

    private const CARTELLE = ['app', 'routes', 'views', 'database/migrations', 'js/modules', 'js/entries', 'js/components'];

    private static function radice(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @return list<string> riscontri «file:riga — forma» */
    private static function riscontriIn(string $testo, string $nome): array
    {
        $fuori = [];
        foreach (preg_split('/\R/', $testo) ?: [] as $i => $riga) {
            // Il testo fra «» è una citazione della formula vecchia.
            $senzaCitazioni = (string)preg_replace('/«[^»]*»/u', '', $riga);
            foreach (self::FORME as $forma) {
                if (preg_match($forma, $senzaCitazioni) === 1) {
                    $fuori[] = sprintf('%s:%d — %s', $nome, $i + 1, trim($riga));
                }
            }
        }
        return $fuori;
    }

    /** @return list<string> */
    private static function fileDelCodice(): array
    {
        $fuori = [];
        foreach (self::CARTELLE as $cartella) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::radice() . '/' . $cartella, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && \in_array($file->getExtension(), ['php', 'sql', 'js', 'ts'], true)) {
                    $fuori[] = $file->getPathname();
                }
            }
        }
        return $fuori;
    }

    /** Direzione «deve scattare»: le formule vere, riscritte come esca. */
    #[Test]
    public function le_forme_scattano_sulle_frasi_trovate(): void
    {
        foreach ([
            "'solo nel browser del docente (indicazione del DPO dell\\'Istituto)'",
            "-- L'amministratore lo imposta per Istituto, su indicazione del DPO di quello.",
            " * Il DPO di un Istituto puo' chiedere che per i suoi docenti",
            "-- parlano di studenti. Un DPO puo' chiedere che per i docenti del suo",
            "? 'es. parere del DPO ricevuto: apro le iscrizioni ai colleghi'",
        ] as $esca) {
            self::assertNotSame([], self::riscontriIn($esca, 'esca'), "la frase non viene riconosciuta: {$esca}");
        }

        // E non scatta sulla citazione di ciò che c'era prima.
        self::assertSame(
            [],
            self::riscontriIn('// prima la riga terminava con «(indicazione del DPO dell\'Istituto)»', 'citazione')
        );
    }

    /** Controllo positivo sulla scoperta dei file: zero file passerebbe sempre. */
    #[Test]
    public function i_file_del_codice_si_trovano(): void
    {
        $file = self::fileDelCodice();
        self::assertGreaterThan(300, \count($file), 'il codice ha molti più file: la ricerca non ha funzionato');
        self::assertContains(
            self::radice() . '/app/Controllers/Admin/AdminInstitutesController.php',
            array_map(static fn (string $f): string => str_replace('\\', '/', $f), $file)
        );
    }

    #[Test]
    public function nessuna_scelta_del_titolare_e_attribuita_al_dpo(): void
    {
        $riscontri = [];
        foreach (self::fileDelCodice() as $f) {
            $nome = ltrim(str_replace('\\', '/', substr($f, \strlen(self::radice()))), '/');
            array_push($riscontri, ...self::riscontriIn((string)file_get_contents($f), $nome));
        }

        self::assertSame(
            [],
            $riscontri,
            "Il codice descrive una scelta del Titolare come presa dal DPO di un Istituto:\n  "
            . implode("\n  ", $riscontri) . "\n"
        );
    }
}
