<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\MapBlobStore;
use App\Services\Maps\PreparazioneRipristino;
use App\Services\Maps\RipristinoCaratteri;
use App\Services\Maps\RipristinoCaratteriMappe;
use App\Services\Maps\SalvataggioMappa;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lo strumento dei caratteri persi, dal censimento alla scrittura
 * (tools/maps/ripristina_caratteri.php; wiki/domains/mappe/mappe-overview.md,
 * «Caratteri persi»).
 *
 * Database vero (MariaDB), cifratura vera con una chiave maestra di prova,
 * blob, copie e registro in cartelle temporanee; tutto in transazione →
 * rollback, e le cartelle si tolgono. La mappa di prova ha il blob rovinato
 * come nel 2025 (`asciify` dell'originale) e l'originale fa da «Drive».
 *
 * Nei due versi: la prova a secco non cambia niente, l'applicazione rimette i
 * byte dell'originale; una mappa appena modificata, una versione cambiata fra
 * lettura e scrittura o un contesto che non torna non si scrivono.
 */
final class RipristinoCaratteriMappeTest extends TestCase
{
    private PDO $pdo;
    private int $docente = 0;
    private MapBlobStore $blob;
    private string $radice = '';

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        Config::set('crypto.allow_regenerate', false);
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $nome = 'zzcaratteri' . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Caratteri", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        $this->docente = (int)$this->pdo->lastInsertId();

        $this->radice = sys_get_temp_dir() . '/pantedu-caratteri-' . bin2hex(random_bytes(6));
        $crypto = new TeacherCryptoService(bin2hex(random_bytes(32)));
        $crypto->encrypt($this->docente, 'chiave pronta');
        $this->blob = new MapBlobStore($crypto, $this->radice . '/maps_enc');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->radice !== '' && is_dir($this->radice)) {
            $voci = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->radice, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($voci as $voce) {
                $voce->isDir() ? rmdir($voce->getPathname()) : unlink($voce->getPathname());
            }
            rmdir($this->radice);
        }
    }

    private static function originale(string $testo = 'La velocità è costante: 30 °C, l’unità è il m/s. Misura ??? mm.'): string
    {
        $celle = '';
        for ($i = 2; strlen($celle) < 30_000; $i++) {
            $celle .= '<mxCell id="c' . $i . '" value="nodo senza accenti" vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell>';
        }
        return '<mxfile host="prova"><diagram id="d" name="Pagina-1"><mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
            . $celle . '<mxCell id="t" value="' . htmlspecialchars($testo, ENT_COMPAT | ENT_XML1) . '" vertex="1" parent="1"/>'
            . '</root></mxGraphModel></diagram></mxfile>';
    }

    private function mappa(string $xml, string $modificata = 'NOW() - INTERVAL 1 HOUR', int $versione = 3): int
    {
        $percorso = $this->blob->put($this->docente, $xml);
        $this->pdo->prepare(
            "INSERT INTO teacher_content_data (teacher_id, content_subtype, title, map_blob_path, map_mime, map_size, map_origin, map_version, updated_at)
             VALUES (?, 'mappa', 'Velocità', ?, 'application/xml', ?, 'drive_legacy', ?, $modificata)"
        )->execute([$this->docente, $percorso, strlen($xml), $versione]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array{percorso:string, versione:int, aggiornata:string, xml:string} */
    private function stato(int $id): array
    {
        $st = $this->pdo->prepare('SELECT map_blob_path, map_version, updated_at FROM teacher_content_data WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return [
            'percorso' => (string)$r['map_blob_path'], 'versione' => (int)$r['map_version'],
            'aggiornata' => (string)$r['updated_at'], 'xml' => $this->blob->get($this->docente, (string)$r['map_blob_path']),
        ];
    }

    private function servizio(?SalvataggioMappa $salvataggio = null): RipristinoCaratteriMappe
    {
        return new RipristinoCaratteriMappe($this->pdo, $this->blob, $this->radice . '/copie', $this->radice . '/logs/ripristino.tsv', $salvataggio);
    }

    /** @return array{formato:string, censimento:string, mappe:list<array<string,mixed>>} */
    private function patch(string $originale): array
    {
        $censimento = $this->servizio()->censimento($this->docente);
        return (new PreparazioneRipristino(['Risorse web/mappa.drawio' => $originale]))->prepara($censimento)['patch'];
    }

    private function eventi(int $id): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM audit_activity_log WHERE action = ? AND subject_type = "teacher_content" AND subject_id = ?');
        $st->execute([RipristinoCaratteriMappe::EVENTO, (string)$id]);
        return (int)$st->fetchColumn();
    }

    /**
     * L'ultimo evento della mappa: esito e dettagli già decodificati.
     *
     * @return array{outcome:string, dettagli:array<string,mixed>}
     */
    private function ultimoEvento(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT outcome, details_json FROM audit_activity_log
             WHERE action = ? AND subject_type = "teacher_content" AND subject_id = ?
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute([RipristinoCaratteriMappe::EVENTO, (string)$id]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($riga, 'nessun evento per la mappa ' . $id);
        $dettagli = json_decode((string)$riga['details_json'], true);
        self::assertIsArray($dettagli);
        return ['outcome' => (string)$riga['outcome'], 'dettagli' => $dettagli];
    }

    /** @return list<string> le righe del registro TSV, senza la riga vuota finale */
    private function registro(): array
    {
        $file = $this->radice . '/logs/ripristino.tsv';
        self::assertFileExists($file, 'il registro d\'istanza non è stato scritto');
        return array_values(array_filter(explode("\n", (string)file_get_contents($file))));
    }

    /** @return list<string> le copie cifrate rimaste sul disco */
    private function copie(): array
    {
        return glob($this->radice . '/copie/' . $this->docente . '/*.bin') ?: [];
    }

    #[Test]
    public function il_censimento_trova_le_corse_con_il_contesto_e_non_il_contenuto(): void
    {
        $originale = self::originale();
        $id = $this->mappa(RipristinoCaratteri::asciify($originale));
        $this->mappa('<mxfile><diagram id="x" name="P">tutto a posto, città</diagram></mxfile>');

        $c = $this->servizio()->censimento($this->docente);

        self::assertSame(RipristinoCaratteriMappe::FORMATO_CENSIMENTO, $c['formato']);
        self::assertCount(1, $c['mappe'], 'solo la mappa con le corse');
        $m = $c['mappe'][0];
        self::assertSame($id, $m['id']);
        self::assertSame(hash('sha256', RipristinoCaratteri::asciify($originale)), $m['sha256']);
        self::assertSame(3, $m['map_version']);
        self::assertCount(7, $m['corse'], 'velocit??, ??, ??C, l???unit??, ??, ??? mm');
        foreach ($m['corse'] as $corsa) {
            self::assertLessThanOrEqual(RipristinoCaratteri::CONTESTO, strlen($corsa['sinistra']));
            self::assertLessThanOrEqual(RipristinoCaratteri::CONTESTO, strlen($corsa['destra']));
        }
        self::assertSame(2, $c['riepilogo']['decifrate']);
        self::assertSame(7, $c['riepilogo']['corse']);
    }

    #[Test]
    public function la_prova_a_secco_non_cambia_niente_e_l_applicazione_rimette_l_originale(): void
    {
        $originale = self::originale();
        $corrotto = RipristinoCaratteri::asciify($originale);
        $id = $this->mappa($corrotto);
        $patch = $this->patch($originale);
        self::assertSame(['esatto' => 6], array_count_values(array_column($patch['mappe'][0]['sostituzioni'], 'fonte')));
        $prima = $this->stato($id);

        // A secco.
        [$secco] = $this->servizio()->esegui($patch);
        self::assertSame(RipristinoCaratteriMappe::DA_APPLICARE, $secco['esito']);
        self::assertCount(6, $secco['applicate']);
        self::assertSame($prima, $this->stato($id), 'blob, versione e data come prima');
        self::assertDirectoryDoesNotExist($this->radice . '/copie');
        self::assertSame(0, $this->eventi($id));

        // Applicata.
        [$fatto] = $this->servizio()->esegui($patch, ['applica' => true]);
        self::assertSame(RipristinoCaratteriMappe::APPLICATA, $fatto['esito'], json_encode($fatto) ?: '');
        $dopo = $this->stato($id);
        self::assertSame($originale, $dopo['xml'], 'gli stessi byte dell\'originale');
        self::assertSame($prima['percorso'], $dopo['percorso']);
        self::assertSame(4, $dopo['versione']);
        self::assertSame(1, $this->eventi($id));

        // La copia cifrata di prima c'è, e si decifra nel blob rovinato.
        $copie = $this->copie();
        self::assertCount(1, $copie);
        self::assertSame(hash('sha256', $corrotto), $this->decifraCopia($copie[0]), 'la copia è il blob di prima');

        // Il registro: una riga per sostituzione, senza il testo intorno.
        $registro = $this->registro();
        self::assertCount(6, $registro);
        self::assertStringNotContainsString('velocit', implode("\n", $registro));
        foreach ($registro as $riga) {
            self::assertStringEndsWith("\t" . RipristinoCaratteriMappe::APPLICATA, $riga, 'l\'ultima colonna dice com\'è finita');
        }

        // E l'evento dice che la rilettura è tornata.
        $evento = $this->ultimoEvento($id);
        self::assertSame('ok', $evento['outcome']);
        self::assertSame(RipristinoCaratteriMappe::APPLICATA, $evento['dettagli']['esito']);
        self::assertSame($evento['dettagli']['sha256_atteso'], $evento['dettagli']['sha256_dopo']);

        // E il censimento dopo: resta solo la corsa legittima.
        $c = $this->servizio()->censimento($this->docente);
        self::assertSame(['???'], array_column($c['mappe'][0]['corse'], 'corsa'));
    }

    #[Test]
    public function una_mappa_modificata_da_poco_si_salta_se_non_si_forza(): void
    {
        $originale = self::originale();
        $id = $this->mappa(RipristinoCaratteri::asciify($originale), 'NOW()');
        $patch = $this->patch($originale);
        $prima = $this->stato($id);

        [$saltata] = $this->servizio()->esegui($patch, ['applica' => true]);
        self::assertSame(RipristinoCaratteriMappe::RECENTE, $saltata['esito']);
        self::assertSame($prima, $this->stato($id));

        [$forzata] = $this->servizio()->esegui($patch, ['applica' => true, 'forza' => true]);
        self::assertSame(RipristinoCaratteriMappe::APPLICATA, $forzata['esito']);
        self::assertSame($originale, $this->stato($id)['xml']);
    }

    #[Test]
    public function se_l_editor_salva_fra_la_lettura_e_la_scrittura_non_si_scrive(): void
    {
        $originale = self::originale();
        $corrotto = RipristinoCaratteri::asciify($originale);
        $id = $this->mappa($corrotto);
        $patch = $this->patch($originale);

        // L'«editor» alza la versione un attimo prima che lo strumento scriva.
        $editor = new class ($this->pdo, $this->blob) extends SalvataggioMappa {
            private PDO $db;

            public function __construct(PDO $pdo, MapBlobStore $blob)
            {
                parent::__construct($pdo, $blob);
                $this->db = $pdo;
            }

            public function sovrascrivi(int $id, int $teacherId, string $xml, int $versioneAttesa): array
            {
                $this->db->prepare('UPDATE teacher_content_data SET map_version = map_version + 1 WHERE id = ?')->execute([$id]);
                return parent::sovrascrivi($id, $teacherId, $xml, $versioneAttesa);
            }
        };

        [$esito] = $this->servizio($editor)->esegui($patch, ['applica' => true]);

        self::assertSame(RipristinoCaratteriMappe::CONFLITTO, $esito['esito']);
        $dopo = $this->stato($id);
        self::assertSame($corrotto, $dopo['xml'], 'il blob non è stato scritto');
        self::assertSame(4, $dopo['versione'], 'la versione è quella dell\'editor, non una in più');
        self::assertSame(0, $this->eventi($id), 'nessun evento: non è successo niente');

        // E nemmeno la copia cifrata resta: non si è scritto niente, quindi non
        // è un punto di ritorno, e in mezzo a quelle vere sarebbe una trappola
        // (si rimetterebbe un disegno più vecchio del lavoro del docente).
        self::assertSame([], $this->copie(), 'la copia del tentativo non scritto si toglie');
        self::assertArrayNotHasKey('copia', $esito, 'e non la si annuncia nell\'esito');
        self::assertFileDoesNotExist($this->radice . '/logs/ripristino.tsv', 'né righe nel registro');
    }

    /**
     * La scrittura è avvenuta ma la rilettura trova altro: qualcuno ha scritto
     * sul blob subito dopo il commit. L'esito è un errore — ma la mappa È
     * cambiata, e di una scrittura sui dati del docente deve restare traccia
     * dove si guarda dopo (audit_activity_log e registro d'istanza), non solo
     * sul terminale di chi ha lanciato lo strumento.
     */
    #[Test]
    public function con_la_rilettura_diversa_la_scrittura_avvenuta_si_registra_lo_stesso(): void
    {
        $originale = self::originale();
        $corrotto = RipristinoCaratteri::asciify($originale);
        $id = $this->mappa($corrotto);
        $patch = $this->patch($originale);

        // Un altro processo riscrive lo stesso ULID appena dopo il commit.
        $dopoIlCommit = new class ($this->pdo, $this->blob) extends SalvataggioMappa {
            private PDO $db;
            private MapBlobStore $deposito;

            public function __construct(PDO $pdo, MapBlobStore $blob)
            {
                parent::__construct($pdo, $blob);
                $this->db = $pdo;
                $this->deposito = $blob;
            }

            public function sovrascrivi(int $id, int $teacherId, string $xml, int $versioneAttesa): array
            {
                $esito = parent::sovrascrivi($id, $teacherId, $xml, $versioneAttesa);
                $st = $this->db->prepare('SELECT map_blob_path FROM teacher_content_data WHERE id = ?');
                $st->execute([$id]);
                $percorso = (string)$st->fetchColumn();
                $this->deposito->put(
                    $teacherId,
                    '<mxfile host="altro"><diagram id="d">di un altro</diagram></mxfile>',
                    basename($percorso, '.bin'),
                );
                return $esito;
            }
        };

        [$esito] = $this->servizio($dopoIlCommit)->esegui($patch, ['applica' => true]);

        // Quello che è successo davvero: la scrittura c'è stata.
        self::assertSame(RipristinoCaratteriMappe::ERRORE, $esito['esito']);
        self::assertSame('rilettura_diversa', $esito['motivo']);
        self::assertSame(4, $this->stato($id)['versione'], 'la versione è salita: si è scritto');

        // Quello che deve restarne: l'evento, con l'esito della rilettura.
        self::assertSame(1, $this->eventi($id), 'l\'evento si registra anche quando la rilettura non torna');
        $evento = $this->ultimoEvento($id);
        self::assertSame('error', $evento['outcome']);
        self::assertSame('rilettura_diversa', $evento['dettagli']['esito']);
        self::assertSame(3, $evento['dettagli']['versione_prima']);
        self::assertSame(4, $evento['dettagli']['versione_dopo']);
        self::assertNotSame(
            $evento['dettagli']['sha256_atteso'],
            $evento['dettagli']['sha256_dopo'],
            'i due sha sono nei dettagli, e sono diversi'
        );
        self::assertSame(6, $evento['dettagli']['sostituzioni']);

        // Il registro d'istanza: le righe scritte, marcate per quello che sono.
        $registro = $this->registro();
        self::assertCount(6, $registro);
        foreach ($registro as $riga) {
            self::assertStringEndsWith("\trilettura_diversa", $riga);
        }

        // E la copia cifrata resta: qui è l'unico punto di ritorno che c'è.
        $copie = $this->copie();
        self::assertCount(1, $copie);
        self::assertSame(hash('sha256', $corrotto), $this->decifraCopia($copie[0]));
    }

    #[Test]
    public function con_il_blob_cambiato_dopo_il_censimento_si_ancora_al_contesto(): void
    {
        $originale = self::originale();
        $corrotto = RipristinoCaratteri::asciify($originale);
        $id = $this->mappa($corrotto);
        $patch = $this->patch($originale);

        // Il docente, dopo il censimento, aggiunge una cella con un accento vero.
        $modificato = str_replace('<mxCell id="1" parent="0"/>', '<mxCell id="1" parent="0"/><mxCell id="n" value="già fatto" vertex="1" parent="1"/>', $corrotto);
        $percorso = $this->stato($id)['percorso'];
        $this->blob->put($this->docente, $modificato, basename($percorso, '.bin'));

        [$esito] = $this->servizio()->esegui($patch, ['applica' => true]);

        self::assertSame(RipristinoCaratteriMappe::APPLICATA, $esito['esito'], json_encode($esito['conflitti'] ?? []) ?: '');
        self::assertFalse($esito['per_offset']);
        $xml = $this->stato($id)['xml'];
        self::assertStringContainsString('value="già fatto"', $xml, 'il testo del docente è com\'era');
        self::assertStringContainsString('La velocità è costante: 30 °C, l’unità è il m/s. Misura ??? mm.', $xml);
        self::assertSame(RipristinoCaratteri::asciify($modificato), RipristinoCaratteri::asciify($xml));
    }

    #[Test]
    public function senza_originale_la_corsa_va_da_rivedere_e_si_applica_solo_se_approvata(): void
    {
        $vero = self::originale('Quanto è lungo?');
        $id = $this->mappa(RipristinoCaratteri::asciify($vero));
        $censimento = $this->servizio()->censimento($this->docente);

        // Nessun originale che somigli: niente in patch, una riga da rivedere
        // con la proposta della regola («è» isolata).
        $esito = (new PreparazioneRipristino(['altro.drawio' => '<mxfile>altro</mxfile>']))->prepara($censimento);
        self::assertSame([], $esito['patch']['mappe']);
        self::assertCount(1, $esito['revisione']);
        $riga = $esito['revisione'][0];
        self::assertSame(['regola', 'isolata', 'è', ''], [$riga['fonte'], $riga['regola'], $riga['testo'], $riga['approvata']]);

        // Non approvata: la patch resta vuota.
        $nessuna = PreparazioneRipristino::conApprovate($censimento, [$riga], $esito['patch']);
        self::assertSame(0, $nessuna['aggiunte']);

        // Approvata: entra, e si applica.
        $unite = PreparazioneRipristino::conApprovate($censimento, [['approvata' => 'sì'] + $riga], $esito['patch']);
        self::assertSame(1, $unite['aggiunte']);
        self::assertSame([], $unite['scartate']);
        [$fatto] = $this->servizio()->esegui($unite['patch'], ['applica' => true]);
        self::assertSame(RipristinoCaratteriMappe::APPLICATA, $fatto['esito']);
        self::assertSame($vero, $this->stato($id)['xml']);

        // Un testo approvato che non può stare al posto della corsa si scarta, e lo si dice.
        $sbagliata = PreparazioneRipristino::conApprovate($censimento, [['approvata' => 'si', 'testo' => 'e'] + $riga], $esito['patch']);
        self::assertSame(0, $sbagliata['aggiunte']);
        self::assertStringStartsWith('testo_non_ammesso', $sbagliata['scartate'][0]['motivo']);
    }

    #[Test]
    public function le_copie_piu_vecchie_di_trenta_giorni_si_elencano_e_con_applica_si_cancellano(): void
    {
        $cartella = $this->radice . '/copie/' . $this->docente;
        mkdir($cartella, 0o770, true);
        file_put_contents("$cartella/VECCHIA-20260801-000000.bin", 'x');
        file_put_contents("$cartella/NUOVA-20260918-000000.bin", 'y');
        touch("$cartella/VECCHIA-20260801-000000.bin", time() - 31 * 86400);

        self::assertSame([$this->docente . '/VECCHIA-20260801-000000.bin'], $this->servizio()->pulisciCopie(false));
        self::assertFileExists("$cartella/VECCHIA-20260801-000000.bin", 'senza applica si elenca soltanto');

        $this->servizio()->pulisciCopie(true);
        self::assertFileDoesNotExist("$cartella/VECCHIA-20260801-000000.bin");
        self::assertFileExists("$cartella/NUOVA-20260918-000000.bin");
    }

    /** Lo sha256 del contenuto decifrato di una copia: la si rimette al posto di un blob di prova e la si legge. */
    private function decifraCopia(string $copia): string
    {
        $ulid = '01KQD0QRAGBE09DZ8VV2DN0PF8';
        $destinazione = $this->radice . '/maps_enc/' . $this->docente . '/' . $ulid . '.bin';
        copy($copia, $destinazione);
        return hash('sha256', $this->blob->get($this->docente, $this->docente . '/' . $ulid . '.bin'));
    }
}
