<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RetentionSql;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * La retention non deve anonimizzare chi sta usando il sistema.
 *
 * ── Il difetto che ha fatto nascere questo test ────────────────────────────
 *
 * Fino al 9 settembre 2026 la condizione era:
 *
 *     status <> 'anonymized'
 *     AND (approved_at IS NULL OR approved_at < ?)
 *     AND created_at < ?
 *
 * Nessun riferimento all'uso — il dato non esisteva. Ma `approved_at < limite`
 * significa «iscritto da più di due anni», non «inattivo da due anni»: per chi
 * entra ogni giorno è vero.
 *
 * Misurato sui dati veri: al primo giro utile quella condizione avrebbe
 * anonimizzato **un account di ruolo `administrator`**, cioè avrebbe chiuso
 * l'amministratore fuori dal proprio sistema. Email sostituita, nome svuotato,
 * password azzerata. Irreversibile.
 *
 * Non è successo solo perché il timer non era mai partito e la retention
 * girava in simulazione. Due caselle che non c'entrano niente con la
 * correttezza della query.
 *
 * ── Perché SQLite ──────────────────────────────────────────────────────────
 *
 * Si prova la sola clausola `WHERE`, che è SQL standard, contro una tabella
 * in memoria. L'`UPDATE` intorno usa `CONCAT()`, che è di MariaDB, ma non è
 * quello il pezzo pericoloso: il pezzo pericoloso è **chi viene scelto**.
 *
 * La stringa provata è la stessa costante che usa `anonymize_expired.php`:
 * una copia qui dentro si separerebbe dal codice al primo ritocco.
 */
final class RetentionInattiviTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            // Esplicito, non silenzioso: una suite che salta senza dirlo è un
            // verde che non misura, ed è già costato caro in questo progetto.
            self::markTestSkipped('pdo_sqlite non caricata: questa prova non può girare');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                status TEXT,
                created_at TEXT,
                approved_at TEXT,
                last_access_at TEXT
            )'
        );
    }

    private function inserisci(
        string $username,
        string $creato,
        ?string $approvato,
        ?string $accesso,
        string $stato = 'active',
    ): void {
        $this->pdo->prepare(
            'INSERT INTO users (username, status, created_at, approved_at, last_access_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$username, $stato, $creato, $approvato, $accesso]);
    }

    /** @return list<string> gli username che la retention anonimizzerebbe */
    private function colpiti(string $limite): array
    {
        $sel = $this->pdo->prepare(
            'SELECT username FROM users WHERE ' . RetentionSql::INATTIVI_WHERE
        );
        $sel->execute([$limite, $limite, $limite]);
        return $sel->fetchAll(PDO::FETCH_COLUMN);
    }

    private const LIMITE  = '2024-09-09 00:00:00'; // due anni prima di «adesso»
    private const VECCHIO = '2023-01-01 00:00:00';
    private const IERI    = '2026-09-08 00:00:00';
    private const RECENTE = '2026-08-30 00:00:00';

    public function testChiEntraOgniGiornoNonSiTocca(): void
    {
        // Il caso che contava: iscritto e approvato da tre anni, ma attivo.
        $this->inserisci('amministratore', self::VECCHIO, self::VECCHIO, self::IERI);

        self::assertSame(
            [],
            $this->colpiti(self::LIMITE),
            'un account usato ieri non è inattivo, per quanto vecchia sia l\'iscrizione',
        );
    }

    public function testChiNonEntraDaAnniSiAnonimizza(): void
    {
        $this->inserisci('abbandonato', self::VECCHIO, self::VECCHIO, self::VECCHIO);

        self::assertSame(['abbandonato'], $this->colpiti(self::LIMITE));
    }

    public function testIscrittoDaAnniSenzaNessunAccessoRegistratoSiAnonimizza(): void
    {
        // `last_access_at` è NULL per tutti gli account precedenti alla
        // migrazione 108. Qui conta come inattività **perché** anche
        // l'iscrizione è più vecchia del limite.
        $this->inserisci('mai-visto', self::VECCHIO, self::VECCHIO, null);

        self::assertSame(['mai-visto'], $this->colpiti(self::LIMITE));
    }

    public function testIscrittoDaPocoENonAncoraUsatoNonSiTocca(): void
    {
        // Il caso che impedisce di trattare «non lo sappiamo» come «inattivo»:
        // altrimenti, il primo giro dopo la migrazione avrebbe anonimizzato
        // chiunque, ripetendo il difetto con un'altra colonna.
        $this->inserisci('appena-iscritto', self::RECENTE, self::RECENTE, null);

        self::assertSame([], $this->colpiti(self::LIMITE));
    }

    public function testChiEGiaAnonimizzatoNonSiRitocca(): void
    {
        $this->inserisci('gia-fatto', self::VECCHIO, self::VECCHIO, self::VECCHIO, 'anonymized');

        self::assertSame([], $this->colpiti(self::LIMITE));
    }

    public function testLaVecchiaCondizioneAvrebbePresoLAmministratore(): void
    {
        // Non difende il codice di oggi: documenta perché è cambiato, e
        // fallisce se qualcuno tornasse indietro pensando fossero equivalenti.
        $this->inserisci('amministratore', self::VECCHIO, self::VECCHIO, self::IERI);

        $vecchia = 'status <> \'anonymized\'
                      AND (approved_at IS NULL OR approved_at < ?)
                      AND created_at < ?';
        $sel = $this->pdo->prepare('SELECT username FROM users WHERE ' . $vecchia);
        $sel->execute([self::LIMITE, self::LIMITE]);

        self::assertSame(
            ['amministratore'],
            $sel->fetchAll(PDO::FETCH_COLUMN),
            'la condizione precedente prendeva chi era solo iscritto da tanto: è il difetto corretto',
        );
        self::assertSame([], $this->colpiti(self::LIMITE), 'quella nuova no');
    }
}
