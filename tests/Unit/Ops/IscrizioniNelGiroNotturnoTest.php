<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il giro notturno della conservazione GDPR pulisce il file delle domande di
 * iscrizione, e lo fa ogni notte (24/9/2026).
 *
 * ── Il difetto ────────────────────────────────────────────────────────────
 *
 * `tools/gdpr/anonymize_expired.php` applicava i 30 giorni delle domande in
 * attesa con `DELETE FROM registrations`, una tabella che nessun codice
 * scrive: il giro diceva «0 righe» ogni volta e le domande vere, nel file
 * `registrations.json`, restavano per sempre. E il timer era mensile: anche
 * con la tabella giusta, una domanda del 2 del mese sarebbe rimasta fino a
 * circa 60 giorni.
 *
 * ── Che cosa guarda ───────────────────────────────────────────────────────
 *
 * Il giro vero non si lancia da qui: anonimizza account e scrive nel registro
 * degli accessi privilegiati, che è in sola aggiunta, e nel database di prova
 * resterebbe una riga per ogni esecuzione. Si guardano allora i due file che
 * decidono: lo script chiama la regola sul percorso dei dati
 * (`ConservazioneDelleIscrizioniTest` ne prova il comportamento), e il timer
 * gira ogni giorno e finisce prima del salvataggio notturno.
 */
final class IscrizioniNelGiroNotturnoTest extends TestCase
{
    private const RADICE = __DIR__ . '/../../..';

    private static function leggi(string $relativo): string
    {
        $testo = @file_get_contents(self::RADICE . '/' . $relativo);
        self::assertIsString($testo, "$relativo si legge");
        return $testo;
    }

    /** Le righe che non sono commenti: una regola citata in un commento non conta. */
    private static function codice(string $testo): string
    {
        $righe = array_filter(
            preg_split('/\R/', $testo) ?: [],
            static fn(string $r): bool => !preg_match('~^\s*(//|#|\*|/\*)~', $r),
        );
        return implode("\n", $righe);
    }

    #[Test]
    public function lo_script_pulisce_il_file_delle_domande_e_non_la_tabella_vuota(): void
    {
        $codice = self::codice(self::leggi('tools/gdpr/anonymize_expired.php'));

        self::assertDoesNotMatchRegularExpression('/DELETE\s+FROM\s+registrations\b/i', $codice, 'la tabella registrations non la scrive nessuno');
        self::assertStringContainsString('new ConservazioneDelleIscrizioni(', $codice);
        self::assertStringContainsString("Config::get('auth.paths.registrations'", $codice, 'il file è quello dell\'applicazione');
        self::assertStringContainsString("Config::get('auth.paths.registered_users'", $codice, 'e la vecchia copia degli account');
        self::assertMatchesRegularExpression('/->applica\(\$now, \$dry\)/', $codice, 'in simulazione quando la retention è spenta');
    }

    #[Test]
    public function il_timer_gira_ogni_giorno_e_finisce_prima_del_salvataggio(): void
    {
        $timer = self::leggi('tools/systemd/pantedu-gdpr-retention.timer');
        self::assertSame(1, preg_match('/^OnCalendar=\*-\*-\* (\d{2}):(\d{2}):00$/m', $timer, $m), 'un orario, ogni giorno');
        self::assertSame(1, preg_match_all('/^OnCalendar=/m', $timer), 'un OnCalendar solo');
        preg_match('/^RandomizedDelaySec=(\d+)min$/m', $timer, $ritardo);
        $fine = (int)$m[1] * 60 + (int)$m[2] + (int)($ritardo[1] ?? 0);

        $backup = self::leggi('tools/systemd/pantedu-backup-encrypted.timer');
        self::assertSame(1, preg_match('/^OnCalendar=\*-\*-\* (\d{2}):(\d{2}):00$/m', $backup, $b));
        $inizioBackup = (int)$b[1] * 60 + (int)$b[2];

        self::assertLessThan($inizioBackup, $fine, 'la copia della notte non deve contenere una domanda già scaduta');

        $servizio = self::leggi('tools/systemd/pantedu-gdpr-retention.service');
        self::assertStringContainsString('tools/gdpr/anonymize_expired.php', $servizio);
    }
}
