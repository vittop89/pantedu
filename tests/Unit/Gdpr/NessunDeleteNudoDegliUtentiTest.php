<?php

declare(strict_types=1);

namespace Tests\Unit\Gdpr;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Nell'applicazione un account si cancella solo con CancellazioneDellAccount
 * (24/9/2026).
 *
 * Fino a quel giorno il pannello di amministrazione («Eliminare
 * definitivamente l'utente?») faceva `DELETE FROM users`: le cascate delle
 * chiavi esterne cancellavano registri che vanno tenuti, la chiave del docente
 * spariva senza la riga «shred» nel registro della cifratura, e i file del
 * docente, contratti in chiaro compresi, restavano sul disco senza più un
 * proprietario. Questa prova impedisce che una strada così torni in `app/`.
 *
 * Nei due versi: le forme sbagliate si riconoscono, quelle su altre tabelle
 * (`users_foo`, `teacher_users`) no.
 */
final class NessunDeleteNudoDegliUtentiTest extends TestCase
{
    private const CARTELLA = __DIR__ . '/../../../app';

    /**
     * Le eccezioni, guardate una per una e con il perché.
     *
     * ParentConsentService::cleanupExpired(): toglie gli account di studenti
     * minori mai attivati, il cui consenso del genitore è scaduto. Non hanno
     * contenuti né chiave né file; un segnaposto `anon-<id>` resterebbe per
     * sempre senza servire a niente, e cancellarli del tutto è la scelta che
     * tiene meno dati.
     */
    private const ECCEZIONI = ['Services/Gdpr/ParentConsentService.php'];

    /** @return list<int> le righe con un DELETE sulla tabella users */
    private static function righeConDelete(string $testo): array
    {
        $trovate = [];
        foreach (preg_split('/\R/', $testo) ?: [] as $i => $riga) {
            if (preg_match('/\bDELETE\s+(?:\w+\s+)?FROM\s+`?users`?(?![\w`])/i', $riga) === 1) {
                $trovate[] = $i + 1;
            }
        }
        return $trovate;
    }

    #[Test]
    public function in_app_nessun_delete_sulla_tabella_degli_utenti(): void
    {
        $file = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            self::CARTELLA,
            \FilesystemIterator::SKIP_DOTS
        ));
        $letti = 0;
        $difetti = [];
        foreach ($file as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $letti++;
            $relativo = substr($f->getPathname(), strlen(self::CARTELLA) + 1);
            if (\in_array($relativo, self::ECCEZIONI, true)) {
                continue;
            }
            foreach (self::righeConDelete((string) file_get_contents($f->getPathname())) as $n) {
                $difetti[] = $relativo . ':' . $n;
            }
        }

        self::assertGreaterThan(100, $letti, 'la prova non ha letto i file di app/');
        self::assertSame([], $difetti, 'un account si cancella con CancellazioneDellAccount, non con un DELETE');
    }

    #[Test]
    public function le_forme_sbagliate_si_riconoscono_e_le_altre_tabelle_no(): void
    {
        $codice = "\$db->prepare('DELETE FROM users WHERE id = ?');\n"
            . "\$db->exec(\"delete from `users` where id = 1\");\n"
            . "\$db->prepare('DELETE u FROM users u JOIN x ON x.id = u.id');\n"
            . "\$db->prepare('DELETE FROM users_backup WHERE id = ?');\n"
            . "\$db->prepare('DELETE FROM teacher_users WHERE id = ?');\n"
            . "\$db->prepare('UPDATE users SET active = 0');\n";

        self::assertSame([1, 2, 3], self::righeConDelete($codice));
    }
}
