<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Drive\RecuperoMappeDaDrive;
use App\Services\Maps\MapBlobStore;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il recupero da Drive delle mappe senza file (15/9/2026, 11 in produzione).
 *
 * Database vero, cifratura vera con una chiave maestra di prova, blob in una
 * cartella temporanea; Drive è una funzione che risponde come vogliamo. Nei
 * due versi: si prendono le mappe senza file con un identificativo vero, e non
 * le altre; si scrive solo con `applica`, e solo un contenuto riconosciuto.
 * Tutto in transazione → rollback in tearDown; la cartella si toglie.
 */
final class RecuperoMappeDaDriveTest extends TestCase
{
    private const DIAGRAMMA = '<mxfile host="prova"><diagram id="d" name="Pagina">abc</diagram></mxfile>';

    private PDO $pdo;
    private int $docente = 0;
    private MapBlobStore $blob;
    private string $cartella = '';

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();

        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES ("zzrecuperomappe", "teacher", "Zz", "Recupero", "zzrecuperomappe@example.invalid", "x", "approved", 1, NOW())'
        )->execute();
        $this->docente = (int)$this->pdo->lastInsertId();

        $this->cartella = sys_get_temp_dir() . '/pantedu-recupero-' . bin2hex(random_bytes(6));
        $crypto = new TeacherCryptoService(bin2hex(random_bytes(32)));
        // La chiave del docente prima delle sue mappe, come in produzione: con righe
        // che hanno già un blob, TeacherCryptoService rifiuta di crearne una nuova
        // (kek_regen_guard). In locale ALLOW_CRYPTO_REGENERATE lo nascondeva; in CI no.
        $crypto->encrypt($this->docente, 'chiave pronta');
        $this->blob = new MapBlobStore($crypto, $this->cartella);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->cartella !== '' && is_dir($this->cartella)) {
            $voci = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cartella, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($voci as $voce) {
                $voce->isDir() ? rmdir($voce->getPathname()) : unlink($voce->getPathname());
            }
            rmdir($this->cartella);
        }
    }

    private function mappa(?string $percorso, ?string $idDrive, string $titolo = 'Mappa di prova'): int
    {
        $this->pdo->prepare(
            'INSERT INTO teacher_content_data (teacher_id, content_subtype, title, map_blob_path, map_drive_id, map_origin)
             VALUES (?, "mappa", ?, ?, ?, "upload")'
        )->execute([$this->docente, $titolo, $percorso, $idDrive]);
        return (int)$this->pdo->lastInsertId();
    }

    private function percorsoMancante(): string
    {
        return $this->docente . '/01KQD0QRAGBE09DZ8VV2DN0PF8.bin';
    }

    /** @param \Closure(int, string): string $scarica */
    private function recupero(\Closure $scarica): RecuperoMappeDaDrive
    {
        return new RecuperoMappeDaDrive($this->pdo, $this->blob, $scarica);
    }

    /** @return array<string, mixed> */
    private function riga(int $id): array
    {
        $st = $this->pdo->prepare('SELECT map_blob_path, map_mime, map_size, map_drive_id, map_origin FROM teacher_content_data WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    #[Test]
    public function candidate_solo_le_mappe_senza_file_con_un_identificativo_vero(): void
    {
        $senzaFile = $this->mappa($this->percorsoMancante(), '1pUbPNAVNGRDmTnLG1hKIZRdvkNttTode');
        $conFile = $this->mappa($this->blob->put($this->docente, self::DIAGRAMMA), '1conFileSulServerAAAAAAAAAAAAAAAA');
        $senzaId = $this->mappa($this->docente . '/01KQD0QS7J6M2RT869E28Z6FB1.bin', null);
        $segnaposto = $this->mappa($this->docente . '/01KQD0R06Q40S0T59PR96GTTAS.bin', 'blob_orphan');
        $importDiPrimavera = $this->mappa($this->docente . '/01KQD0SGA17RJGRZ5AN0XKGYY9.bin', 'id-20250913155541732');

        $ids = array_column($this->recupero(static fn() => '')->candidate($this->docente), 'id');

        $this->assertSame([$senzaFile], $ids);
        foreach ([$conFile, $senzaId, $segnaposto, $importDiPrimavera] as $escluso) {
            $this->assertNotContains($escluso, $ids);
        }
    }

    #[Test]
    public function applicando_la_copia_torna_cifrata_al_posto_del_file_mancante(): void
    {
        $id = $this->mappa($this->percorsoMancante(), '1pUbPNAVNGRDmTnLG1hKIZRdvkNttTode');
        $chiesti = [];
        $recupero = $this->recupero(static function (int $tid, string $idFile) use (&$chiesti): string {
            $chiesti[] = [$tid, $idFile];
            return self::DIAGRAMMA;
        });
        [$mappa] = $recupero->candidate($this->docente);

        $esito = $recupero->recupera($mappa, true);

        $this->assertSame(RecuperoMappeDaDrive::RECUPERATA, $esito['esito']);
        $this->assertSame([[$this->docente, '1pUbPNAVNGRDmTnLG1hKIZRdvkNttTode']], $chiesti);
        $riga = $this->riga($id);
        $this->assertNotSame($this->percorsoMancante(), $riga['map_blob_path']);
        $this->assertSame(self::DIAGRAMMA, $this->blob->get($this->docente, (string)$riga['map_blob_path']), 'e si rilegge uguale');
        $this->assertSame('application/xml', $riga['map_mime']);
        $this->assertSame(strlen(self::DIAGRAMMA), (int)$riga['map_size']);
        $this->assertSame('drive_legacy', $riga['map_origin'], 'la sincronizzazione la ricrea nella cartella dell\'app');
        $this->assertSame('1pUbPNAVNGRDmTnLG1hKIZRdvkNttTode', $riga['map_drive_id']);
        $this->assertSame([], $recupero->candidate($this->docente), 'e non è più una candidata');
    }

    #[Test]
    public function in_prova_scarica_ma_non_scrive(): void
    {
        $id = $this->mappa($this->percorsoMancante(), '1pUbPNAVNGRDmTnLG1hKIZRdvkNttTode');
        $recupero = $this->recupero(static fn() => self::DIAGRAMMA);
        $prima = $this->riga($id);

        $esito = $recupero->recupera($recupero->candidate($this->docente)[0], false);

        $this->assertSame(RecuperoMappeDaDrive::DA_RECUPERARE, $esito['esito']);
        $this->assertSame(strlen(self::DIAGRAMMA), $esito['byte']);
        $this->assertSame($prima, $this->riga($id));
        $this->assertDirectoryDoesNotExist($this->cartella . '/' . $this->docente, 'nessun blob scritto');
    }

    #[Test]
    public function una_copia_che_su_drive_non_c_e_non_cambia_niente(): void
    {
        $id = $this->mappa($this->percorsoMancante(), '1pUbPNAVNGRDmTnLG1hKIZRdvkNttTode');
        $recupero = $this->recupero(static fn() => throw new \RuntimeException('{"error":{"code":404,"message":"File not found: 1pUb."}}'));
        $prima = $this->riga($id);

        $esito = $recupero->recupera($recupero->candidate($this->docente)[0], true);

        $this->assertSame(RecuperoMappeDaDrive::NON_SU_DRIVE, $esito['esito']);
        $this->assertSame($prima, $this->riga($id));
    }

    #[Test]
    public function un_errore_diverso_dall_assenza_resta_un_errore(): void
    {
        $id = $this->mappa($this->percorsoMancante(), '1pUbPNAVNGRDmTnLG1hKIZRdvkNttTode');
        $recupero = $this->recupero(static fn() => throw new \RuntimeException('{"error":{"code":403,"message":"insufficientPermissions"}}'));
        $prima = $this->riga($id);

        $esito = $recupero->recupera($recupero->candidate($this->docente)[0], true);

        $this->assertSame(RecuperoMappeDaDrive::ERRORE, $esito['esito']);
        $this->assertSame($prima, $this->riga($id));
    }

    #[Test]
    public function un_contenuto_che_non_si_riconosce_non_si_scrive(): void
    {
        $id = $this->mappa($this->percorsoMancante(), '1pUbPNAVNGRDmTnLG1hKIZRdvkNttTode');
        $recupero = $this->recupero(static fn() => "\x00\x01 non è una mappa");
        $prima = $this->riga($id);

        $esito = $recupero->recupera($recupero->candidate($this->docente)[0], true);

        $this->assertSame(RecuperoMappeDaDrive::NON_RICONOSCIUTA, $esito['esito']);
        $this->assertSame($prima, $this->riga($id));
        $this->assertDirectoryDoesNotExist($this->cartella . '/' . $this->docente);
    }
}
