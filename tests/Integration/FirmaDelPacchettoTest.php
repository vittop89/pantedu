<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\ImportBundleController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Crypto\TeacherRecoveryService;
use App\Services\Maps\MapBlobStore;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La firma del pacchetto all'import, attraverso l'export e il controller veri
 * (23/9/2026).
 *
 * L'export firma il manifest con HMAC(R), dove R è la chiave di recupero del
 * docente, riletta dal database (TeacherRecoveryService::
 * signManifestForExporter); l'import ricalcola l'HMAC con il codice R che
 * digita chi importa (verifyManifestHmac), senza database né chiave master.
 * La firma prova che il manifest, e con le sue impronte i file, non l'ha
 * cambiato chi non conosce la R di chi l'ha esportato; non prova chi l'ha
 * esportato rispetto al server, ed è voluto (docblock del servizio).
 *
 * Nei due versi. Passano: il collega con il codice dell'esportatore,
 * l'esportatore che reimporta il proprio pacchetto, l'applicazione che scrive
 * la mappa, un pacchetto di un'altra istanza con il suo codice, un pacchetto
 * dopo che la chiave master è cambiata o persa, un pacchetto firmato prima
 * della revoca della chiave. Non passano: il pacchetto vero con un codice
 * sbagliato, un pacchetto cambiato dopo la firma, il codice giusto con la
 * firma di un'altra chiave. Database vero, due docenti con nome unico, tutto
 * in una transazione annullata alla fine; file cifrati in una cartella
 * temporanea.
 */
final class FirmaDelPacchettoTest extends TestCase
{
    private const DRAWIO = '<mxfile host="e2e"><diagram id="d" name="Pagina"><mxGraphModel><root>'
        . '<mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel></diagram></mxfile>';
    private const PERCORSO = 'ist/SCI/2A/FIS/mappe/Cinematica.drawio';

    private PDO $pdo;
    private TeacherRecoveryService $recupero;
    private MapBlobStore $blob;
    private string $cartella = '';
    private int $esportatore = 0;
    private int $importatore = 0;
    /** La chiave dell'esportatore, come la vede lui (esadecimale). */
    private string $chiaveEsportatore = '';

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
        $this->esportatore = $this->docente('zzfirmaesp');
        $this->importatore = $this->docente('zzfirmaimp');

        // Una chiave master della prova, la stessa per le KEK e per le
        // chiavi di recupero: niente dipende da quella di .env.local.
        $kms = bin2hex(random_bytes(32));
        $crypto = new TeacherCryptoService($kms);
        $crypto->encrypt($this->importatore, 'chiave pronta');
        $this->cartella = sys_get_temp_dir() . '/pantedu-firma-' . bin2hex(random_bytes(6));
        $this->blob = new MapBlobStore($crypto, $this->cartella);
        $this->recupero = new TeacherRecoveryService($kms);

        $generata = $this->recupero->generate($this->esportatore);
        self::assertTrue($generata['ok'], 'la chiave di recupero dell\'esportatore');
        $this->chiaveEsportatore = (string)$generata['recovery_hex'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->cartella !== '' && is_dir($this->cartella)) {
            array_map('unlink', glob($this->cartella . '/*/*') ?: []);
            array_map('rmdir', glob($this->cartella . '/*', GLOB_ONLYDIR) ?: []);
            rmdir($this->cartella);
        }
    }

    private function docente(string $prefisso): int
    {
        $nome = $prefisso . date('His') . bin2hex(random_bytes(3));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Firma", ?, "x", "approved", 1, NOW())'
        )->execute([$nome, "$nome@example.invalid"]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Il manifest come lo costruisce l'export (VerificaSyncController::
     * manifestSigned), senza il campo `hmac`.
     *
     * @return array<string, mixed>
     */
    private function manifesto(int $esportatore): array
    {
        return [
            'version'           => 1,
            'exported_at'       => date('c'),
            'exporter_user_id'  => $esportatore,
            'exporter_username' => 'zzfirma',
            'institute_code'    => 'ZZ',
            'files'             => [[
                'path'   => self::PERCORSO,
                'size'   => \strlen(self::DRAWIO),
                'sha256' => hash('sha256', self::DRAWIO),
                'type'   => 'mappa',
            ]],
        ];
    }

    /** Il manifest firmato dall'export, con la chiave dell'esportatore. @return array<string, mixed> */
    private function firmatoDallExport(): array
    {
        $m = $this->manifesto($this->esportatore);
        $hmac = $this->recupero->signManifestForExporter($this->esportatore, $m);
        self::assertNotNull($hmac, 'l\'export firma');
        $m['hmac'] = $hmac;
        return $m;
    }

    /**
     * Un manifest firmato con una R scelta da chi lo manda, con la stessa
     * derivazione dell'export (HKDF, poi HMAC sul JSON con le chiavi in
     * ordine): è quello che fa l'export di un'altra istanza, e che può fare
     * chiunque abbia una R.
     *
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private static function firmatoCon(string $r, array $m): array
    {
        $ordina = static function (mixed &$v) use (&$ordina): void {
            if (\is_array($v)) {
                if (array_keys($v) !== range(0, \count($v) - 1)) {
                    ksort($v);
                }
                foreach ($v as &$figlio) {
                    $ordina($figlio);
                }
            }
        };
        $copia = $m;
        $ordina($copia);
        $chiave = hash_hkdf('sha256', $r, 32, 'manifest-hmac', 'pantedu-recovery-key-v1');
        $m['hmac'] = base64_encode(hash_hmac(
            'sha256',
            (string)json_encode($copia, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $chiave,
            true
        ));
        return $m;
    }

    /**
     * Anteprima (senza file) o applicazione (con il file), come docente $chi.
     *
     * @param array<string, mixed> $manifesto
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function importa(
        int $chi,
        string $codice,
        array $manifesto,
        bool $applica = false,
        ?TeacherRecoveryService $recupero = null
    ): array {
        $_SESSION = [
            'autenticato' => true, 'username' => 'zzfirma' . $chi, 'user_id' => $chi,
            'user_role' => 'teacher', 'is_super_admin' => false,
        ];
        $corpo = json_encode([
            'recovery_code'     => $codice,
            'manifest'          => $manifesto,
            'files'             => $applica
                ? [['path' => self::PERCORSO, 'content_b64' => base64_encode(self::DRAWIO)]]
                : [],
            'conflict_strategy' => 'rename',
        ], JSON_THROW_ON_ERROR);
        $controller = new ImportBundleController($recupero ?? $this->recupero, null, $this->blob);
        $risposta = $applica
            ? $controller->apply(new Request($corpo))
            : $controller->preview(new Request($corpo));
        return [$risposta->status, json_decode($risposta->body, true) ?: []];
    }

    private function rifiutato(int $stato, array $json): void
    {
        self::assertSame(403, $stato, json_encode($json) ?: '');
        self::assertSame('invalid_recovery_code_or_manifest', $json['error'] ?? null);
    }

    private function mappeDellImportatore(): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM teacher_content_data WHERE teacher_id = ? AND content_subtype = "mappa"'
        );
        $st->execute([$this->importatore]);
        return (int)$st->fetchColumn();
    }

    #[Test]
    public function il_collega_con_la_chiave_dell_esportatore_importa(): void
    {
        [$stato, $json] = $this->importa($this->importatore, $this->chiaveEsportatore, $this->firmatoDallExport());

        self::assertSame(200, $stato, json_encode($json) ?: '');
        self::assertTrue($json['preview'] ?? null);
        self::assertCount(1, $json['report']['created'] ?? []);
    }

    #[Test]
    public function l_esportatore_reimporta_il_proprio_pacchetto(): void
    {
        // Il codice come lo digita una persona: minuscolo, a gruppi.
        $codice = implode(' ', str_split(strtolower($this->chiaveEsportatore), 8));
        [$stato, $json] = $this->importa($this->esportatore, $codice, $this->firmatoDallExport());

        self::assertSame(200, $stato, json_encode($json) ?: '');
    }

    #[Test]
    public function l_applicazione_del_pacchetto_legittimo_scrive(): void
    {
        [$stato, $json] = $this->importa($this->importatore, $this->chiaveEsportatore, $this->firmatoDallExport(), true);

        self::assertSame(200, $stato, json_encode($json) ?: '');
        self::assertSame(1, $json['report']['applied'] ?? null, json_encode($json) ?: '');
        self::assertSame(1, $this->mappeDellImportatore());
    }

    #[Test]
    public function un_pacchetto_di_un_altra_istanza_si_importa_con_il_suo_codice(): void
    {
        // Lo scenario B di docs/architecture/sync-strategy.md: la chiave e
        // l'esportatore stanno su un'altra istanza, qui non ci sono.
        $altrove = random_bytes(32);
        $assente = (int)$this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM users')->fetchColumn();
        $pacchetto = self::firmatoCon($altrove, $this->manifesto($assente));

        [$stato, $json] = $this->importa($this->importatore, bin2hex($altrove), $pacchetto, true);

        self::assertSame(200, $stato, json_encode($json) ?: '');
        self::assertSame(1, $json['report']['applied'] ?? null, json_encode($json) ?: '');
        self::assertSame(1, $this->mappeDellImportatore());
    }

    #[Test]
    public function cambiata_o_persa_la_chiave_master_il_pacchetto_si_importa_ancora(): void
    {
        // Firmato con la chiave master di prima; importato da un servizio con
        // un'altra chiave master, che la R salvata nel database non la sa più
        // leggere, e da uno senza chiave master: la verifica non la usa.
        $pacchetto = $this->firmatoDallExport();
        $ruotata = new TeacherRecoveryService(bin2hex(random_bytes(32)));
        self::assertNull(
            $ruotata->signManifestForExporter($this->esportatore, $this->manifesto($this->esportatore)),
            'con la master nuova la R salvata non si legge più'
        );
        $persa = new TeacherRecoveryService('');
        self::assertFalse($persa->isConfigured(), 'il servizio è senza chiave master');

        foreach (['master ruotata' => $ruotata, 'master persa' => $persa] as $caso => $servizio) {
            [$stato, $json] = $this->importa($this->esportatore, $this->chiaveEsportatore, $pacchetto, false, $servizio);
            self::assertSame(200, $stato, $caso . ': ' . (json_encode($json) ?: ''));
        }
    }

    #[Test]
    public function revocata_la_chiave_i_pacchetti_firmati_prima_si_importano_ancora(): void
    {
        // Documentato nel docblock del servizio: la verifica non guarda
        // teacher_recovery_keys, e revocare non invalida ciò che è già firmato.
        $pacchetto = $this->firmatoDallExport();
        self::assertTrue($this->recupero->revoke($this->esportatore)['ok']);

        [$stato, $json] = $this->importa($this->importatore, $this->chiaveEsportatore, $pacchetto);

        self::assertSame(200, $stato, json_encode($json) ?: '');
    }

    #[Test]
    public function il_pacchetto_vero_con_un_codice_sbagliato_non_passa(): void
    {
        $pacchetto = $this->firmatoDallExport();

        [$stato, $json] = $this->importa($this->importatore, bin2hex(random_bytes(32)), $pacchetto);
        $this->rifiutato($stato, $json);

        [$stato, $json] = $this->importa($this->importatore, bin2hex(random_bytes(32)), $pacchetto, true);
        $this->rifiutato($stato, $json);
        self::assertSame(0, $this->mappeDellImportatore(), 'nessuna mappa scritta');
    }

    #[Test]
    public function un_pacchetto_cambiato_dopo_la_firma_non_passa(): void
    {
        $m = $this->firmatoDallExport();
        $altro = '<mxfile host="altro"><diagram id="d"/></mxfile>';
        $m['files'][0]['sha256'] = hash('sha256', $altro);
        $m['files'][0]['size'] = \strlen($altro);

        [$stato, $json] = $this->importa($this->importatore, $this->chiaveEsportatore, $m);
        $this->rifiutato($stato, $json);
    }

    #[Test]
    public function il_codice_giusto_con_una_firma_di_un_altra_chiave_non_passa(): void
    {
        $falso = self::firmatoCon(random_bytes(32), $this->manifesto($this->esportatore));

        [$stato, $json] = $this->importa($this->importatore, $this->chiaveEsportatore, $falso);
        $this->rifiutato($stato, $json);
    }
}
