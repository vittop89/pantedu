<?php

declare(strict_types=1);

namespace Tests\Unit\Crypto;

use App\Controllers\Admin\AdminCryptoStatusController;
use App\Services\Crypto\ChiaveMadre;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Crypto\TeacherRecoveryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La chiave madre si legge e si giudica in un posto solo (23/9/2026, A-36).
 *
 * Prima i punti che leggevano `KMS_MASTER_KEY` erano cinque, con tre regole:
 * la firma dell'export per l'autorità firmava con qualunque stringa di almeno
 * 16 byte — anche una chiave che la cifratura rifiutava — e ripiegava su
 * `$_SERVER`. Qui ogni lettore si prova con le stesse tre chiavi: valida,
 * assente, malformata. Sul codice di prima `la_firma_dell_export_...` falliva
 * con le chiavi malformate: la firma usciva comunque.
 *
 * Le prove toccano `$_ENV` e `$_SERVER`, che la suite carica da `.env.local`:
 * si rimettono com'erano alla fine, e il valore vero non si stampa mai.
 */
final class ChiaveMadreTest extends TestCase
{
    private const VARIABILE = 'KMS_MASTER_KEY';

    /** @var array{env:bool, envValore:mixed, server:bool, serverValore:mixed} */
    private array $prima;

    protected function setUp(): void
    {
        $this->prima = [
            'env'          => \array_key_exists(self::VARIABILE, $_ENV),
            'envValore'    => $_ENV[self::VARIABILE] ?? null,
            'server'       => \array_key_exists(self::VARIABILE, $_SERVER),
            'serverValore' => $_SERVER[self::VARIABILE] ?? null,
        ];
        unset($_ENV[self::VARIABILE], $_SERVER[self::VARIABILE]);
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::VARIABILE], $_SERVER[self::VARIABILE]);
        if ($this->prima['env']) {
            $_ENV[self::VARIABILE] = $this->prima['envValore'];
        }
        if ($this->prima['server']) {
            $_SERVER[self::VARIABILE] = $this->prima['serverValore'];
        }
    }

    /**
     * Le chiavi che non vanno: ognuna era accettata da almeno un lettore.
     *
     * @return array<string, array{0:string}>
     */
    public static function malformate(): array
    {
        $trentadueByte = random_bytes(32);
        return [
            'non esadecimale'                    => ['zz-questa-non-e-una-chiave-e-nemmeno-lunga-come-una-chiave-vera'],
            'esadecimale di 16 byte'             => [bin2hex(random_bytes(16))],
            'esadecimale di 33 byte'             => [bin2hex(random_bytes(33))],
            '32 byte in base64'                  => [base64_encode($trentadueByte)],
            'esadecimale con a capo finale'      => [bin2hex($trentadueByte) . "\n"],
            'esadecimale con a capo di Windows'  => [bin2hex($trentadueByte) . "\r\n"],
            'esadecimale con a capo iniziale'    => ["\n" . bin2hex($trentadueByte)],
            'un carattere in più davanti'        => ['x' . bin2hex($trentadueByte)],
            'esadecimale con spazio iniziale'    => [' ' . substr(bin2hex($trentadueByte), 1)],
        ];
    }

    #[Test]
    public function una_chiave_di_64_caratteri_esadecimali_e_valida_e_da_32_byte(): void
    {
        $byte = random_bytes(32);
        foreach ([bin2hex($byte), strtoupper(bin2hex($byte))] as $esadecimale) {
            $chiave = ChiaveMadre::daValore($esadecimale);
            self::assertTrue($chiave->valida());
            self::assertTrue($chiave->presente());
            self::assertFalse($chiave->malformata());
            self::assertSame($byte, $chiave->byte());
        }
    }

    #[Test]
    public function vuota_o_mancante_e_assente_e_non_malformata(): void
    {
        foreach ([null, ''] as $valore) {
            $chiave = ChiaveMadre::daValore($valore);
            self::assertSame(ChiaveMadre::ASSENTE, $chiave->stato());
            self::assertFalse($chiave->presente());
            self::assertFalse($chiave->malformata());
        }
        self::assertSame(ChiaveMadre::ASSENTE, ChiaveMadre::dallAmbiente()->stato(), 'variabile tolta');
        $_ENV[self::VARIABILE] = '';
        self::assertSame(ChiaveMadre::ASSENTE, ChiaveMadre::dallAmbiente()->stato(), 'variabile vuota');
    }

    #[Test]
    #[DataProvider('malformate')]
    public function una_chiave_presente_ma_di_un_altra_forma_e_malformata(string $valore): void
    {
        // Si giudica dalla forma, prima di convertirla: hex2bin() su una
        // chiave non convalidata (65 caratteri, con un a capo che `$` lascia
        // passare) avvisa e restituisce false. Un avviso qui vuol dire che la
        // regola ha accettato un valore che non doveva.
        $avvisi = [];
        set_error_handler(static function (int $livello, string $messaggio) use (&$avvisi): bool {
            $avvisi[] = $messaggio;
            return true;
        });
        try {
            $chiave = ChiaveMadre::daValore($valore);
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $avvisi, 'la regola ha lasciato passare il valore fino a hex2bin()');
        self::assertSame(ChiaveMadre::MALFORMATA, $chiave->stato());
        self::assertTrue($chiave->presente());
        self::assertFalse($chiave->valida());

        $_ENV[self::VARIABILE] = $valore;
        self::assertSame(ChiaveMadre::MALFORMATA, ChiaveMadre::dallAmbiente()->stato(), 'e anche dall\'ambiente');
    }

    #[Test]
    public function i_byte_di_una_chiave_non_valida_non_escono_e_il_messaggio_non_dice_il_valore(): void
    {
        $valore = 'zz-segreto-da-non-ripetere-' . bin2hex(random_bytes(8));
        $errore = null;
        try {
            ChiaveMadre::daValore($valore)->byte();
        } catch (\RuntimeException $e) {
            $errore = $e;
        }
        self::assertNotNull($errore, 'una chiave malformata ha dato dei byte');
        self::assertStringContainsString('kms_not_configured', $errore->getMessage());
        self::assertStringContainsString(ChiaveMadre::MALFORMATA, $errore->getMessage());
        self::assertStringNotContainsString($valore, $errore->getMessage());
        self::assertStringNotContainsString('zz-segreto', $errore->getMessage());
        $valida = bin2hex(random_bytes(32));
        self::assertStringNotContainsString($valida, print_r(ChiaveMadre::daValore($valida), true));
        self::assertStringNotContainsString(hex2bin($valida), print_r(ChiaveMadre::daValore($valida), true));
    }

    #[Test]
    public function la_cifratura_e_il_recupero_leggono_la_chiave_allo_stesso_modo(): void
    {
        $valida = bin2hex(random_bytes(32));

        $_ENV[self::VARIABILE] = $valida;
        self::assertTrue((new TeacherCryptoService())->isConfigured());
        self::assertFalse((new TeacherCryptoService())->hasMalformedKey());
        self::assertTrue((new TeacherRecoveryService())->isConfigured());

        $_ENV[self::VARIABILE] = '';
        self::assertFalse((new TeacherCryptoService())->isConfigured());
        self::assertFalse((new TeacherCryptoService())->hasMalformedKey(), 'assente non è malformata');
        self::assertFalse((new TeacherRecoveryService())->isConfigured());

        foreach (self::malformate() as $nome => [$valore]) {
            $_ENV[self::VARIABILE] = $valore;
            self::assertFalse((new TeacherCryptoService())->isConfigured(), $nome);
            self::assertTrue((new TeacherCryptoService())->hasMalformedKey(), $nome);
            self::assertFalse((new TeacherRecoveryService())->isConfigured(), $nome);
            // Una chiave data a mano passa dalle stesse regole.
            self::assertTrue((new TeacherCryptoService($valore))->hasMalformedKey(), "$nome, a mano");
        }
    }

    #[Test]
    public function la_firma_dell_export_nasce_dalla_stessa_chiave_della_cifratura(): void
    {
        $valida = bin2hex(random_bytes(32));
        $_ENV[self::VARIABILE] = $valida;
        self::assertSame(
            hash_hmac('sha256', 'pantedu-export-signing-v1', (string)hex2bin($valida), true),
            AdminCryptoStatusController::deriveExportSigningKey(),
        );

        $_ENV[self::VARIABILE] = '';
        self::assertNull(AdminCryptoStatusController::deriveExportSigningKey(), 'senza chiave, niente firma');
    }

    #[Test]
    #[DataProvider('malformate')]
    public function la_firma_dell_export_non_nasce_da_una_chiave_malformata(string $valore): void
    {
        // Prima: firmava con i byte grezzi di qualunque stringa di 16 byte o
        // più. Il manifest dichiarava una firma forte con una chiave che la
        // cifratura non riconosceva: il bundle va marcato UNSIGNED.
        $_ENV[self::VARIABILE] = $valore;
        self::assertNull(AdminCryptoStatusController::deriveExportSigningKey());
    }

    /**
     * La chiave nuova di un cambio della master (KMS_MASTER_KEY_NEW) si legge
     * con le stesse regole, da ChiaveMadre, e la lunghezza di un valore
     * malformato si dice senza il valore (23/9/2026: lo strumento di rewrap
     * aveva la sua copia della regola).
     */
    #[Test]
    public function un_altra_variabile_si_legge_con_le_stesse_regole(): void
    {
        $prima = $_ENV['ZZ_CHIAVE_DI_PROVA'] ?? null;
        try {
            $_ENV['ZZ_CHIAVE_DI_PROVA'] = str_repeat('ab', 32);
            self::assertTrue(ChiaveMadre::dallaVariabile('ZZ_CHIAVE_DI_PROVA')->valida());
            $_ENV['ZZ_CHIAVE_DI_PROVA'] = str_repeat('ab', 32) . "\n";
            $malformata = ChiaveMadre::dallaVariabile('ZZ_CHIAVE_DI_PROVA');
            self::assertTrue($malformata->malformata(), 'un a capo finale non passa');
            self::assertSame(65, $malformata->lunghezza());
            unset($_ENV['ZZ_CHIAVE_DI_PROVA']);
            self::assertFalse(ChiaveMadre::dallaVariabile('ZZ_CHIAVE_DI_PROVA')->presente());
        } finally {
            if ($prima === null) {
                unset($_ENV['ZZ_CHIAVE_DI_PROVA']);
            } else {
                $_ENV['ZZ_CHIAVE_DI_PROVA'] = $prima;
            }
        }
    }

    /**
     * Nessun altro legge la chiave da sé: né l'app né gli strumenti. Su
     * `main` prima della correzione la stessa ricerca trova undici file, i
     * cinque punti dell'app e sei strumenti; a metà correzione restava
     * `tools/crypto/migrate_hkdf_prefix.php`, con la sua regola e il suo `$`,
     * e questa prova lo trovava.
     */
    #[Test]
    public function la_chiave_si_legge_solo_in_chiave_madre(): void
    {
        $radice = dirname(__DIR__, 3);
        $ammesso = 'app/Services/Crypto/ChiaveMadre.php';
        $lettura = '/(\$_ENV|\$_SERVER)\s*\[\s*[\'"]KMS_MASTER_KEY[\'"]\s*\]'
            . '|getenv\s*\(\s*[\'"]KMS_MASTER_KEY[\'"]/';
        $trovati = [];
        $contati = 0;
        foreach (['app', 'tools'] as $cartella) {
            $file = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($radice . '/' . $cartella, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($file as $f) {
                if (!$f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }
                $contati++;
                $relativo = substr($f->getPathname(), strlen($radice) + 1);
                if ($relativo !== $ammesso && preg_match($lettura, self::senzaCommenti($f->getPathname())) === 1) {
                    $trovati[] = $relativo;
                }
            }
        }
        self::assertGreaterThan(100, $contati, 'la ricerca ha guardato i file');
        self::assertSame(
            1,
            preg_match($lettura, self::senzaCommenti($radice . '/' . $ammesso)),
            'e riconosce la lettura dove c\'è'
        );
        sort($trovati);
        self::assertSame([], $trovati, 'leggono KMS_MASTER_KEY da sé invece che da ChiaveMadre');
    }

    /** Il codice di un file PHP senza i commenti: un docblock che nomina la variabile non la legge. */
    private static function senzaCommenti(string $percorso): string
    {
        $codice = '';
        foreach (token_get_all((string)file_get_contents($percorso)) as $pezzo) {
            if (\is_array($pezzo) && \in_array($pezzo[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codice .= \is_array($pezzo) ? $pezzo[1] : $pezzo;
        }
        return $codice;
    }

    #[Test]
    public function una_chiave_solo_in_server_non_vale_ne_per_la_firma_ne_per_la_cifratura(): void
    {
        $_SERVER[self::VARIABILE] = bin2hex(random_bytes(32));
        self::assertFalse((new TeacherCryptoService())->isConfigured());
        self::assertNull(AdminCryptoStatusController::deriveExportSigningKey());
    }
}
