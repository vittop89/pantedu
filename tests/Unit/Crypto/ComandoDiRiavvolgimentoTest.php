<?php

declare(strict_types=1);

namespace Tests\Unit\Crypto;

use App\Services\Crypto\ComandoDiRiavvolgimento;
use App\Services\Crypto\RiavvolgimentoDellaMaster;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * I rifiuti di tools/crypto/rewrap_master.php, senza database (23/9/2026, A-88).
 *
 * Una chiave sbagliata si ferma prima di aprire una connessione, e il
 * messaggio nomina la variabile, mai il valore. Nell'altro verso: due chiavi
 * valide e diverse arrivano a chiedere la connessione — qui la connessione
 * lancia, così si vede che ci si è arrivati senza toccare un database.
 *
 * Le chiavi sono generate dalla prova: niente .env.local.
 */
final class ComandoDiRiavvolgimentoTest extends TestCase
{
    private const CONNESSIONE_CHIESTA = 'connessione chiesta';

    /** @return iterable<string, array{list<string>, string, string, string}> */
    public static function rifiuti(): iterable
    {
        $a = bin2hex(random_bytes(32));
        $b = bin2hex(random_bytes(32));
        yield 'chiavi uguali' => [[], $a, $a, 'uguale'];
        yield 'uguali a meno delle maiuscole' => [[], $a, strtoupper($a), 'uguale'];
        yield 'nuova assente' => [[], $a, '', 'KMS_MASTER_KEY_NEW assente'];
        yield 'vecchia assente' => [[], '', $b, 'KMS_MASTER_KEY assente'];
        yield 'nuova corta' => [[], $a, substr($b, 2), 'KMS_MASTER_KEY_NEW non è di 64 caratteri esadecimali (ne ha 62)'];
        yield 'vecchia non esadecimale' => [[], 'z' . substr($a, 1), $b, 'KMS_MASTER_KEY non è di 64'];
        yield 'nuova con uno spazio' => [[], $a, $b . ' ', 'KMS_MASTER_KEY_NEW non è di 64'];
        // `$` in un'espressione regolare accetta un a capo finale: hex2bin()
        // darebbe false, e la chiave diventerebbe una stringa vuota.
        yield 'nuova con un a capo finale' => [[], $a, $b . "\n", 'KMS_MASTER_KEY_NEW non è di 64 caratteri esadecimali (ne ha 65)'];
        yield 'vecchia con un a capo finale' => [[], $a . "\n", $b, 'KMS_MASTER_KEY non è di 64 caratteri esadecimali (ne ha 65)'];
        yield 'due modi' => [['--apply', '--verify'], $a, $b, 'Un modo solo alla volta'];
        yield 'argomento sconosciuto' => [['--tutto'], $a, $b, 'Argomento non riconosciuto: --tutto'];
        yield 'una chiave incollata come argomento' => [['--old=' . $a], $a, $b, '(omesso: contiene una sequenza esadecimale lunga)'];
        yield 'elenco di docenti malformato' => [['--docenti=3,,4'], $a, $b, 'Argomento non riconosciuto: --docenti=3,,4'];
    }

    /** @param list<string> $argomenti */
    #[Test]
    #[DataProvider('rifiuti')]
    public function rifiuta_prima_di_connettersi_e_senza_stampare_la_chiave(
        array $argomenti,
        string $vecchia,
        string $nuova,
        string $messaggio
    ): void {
        [$codice, $testo, $chiesta] = $this->esegui($argomenti, $vecchia, $nuova);

        self::assertSame(2, $codice);
        self::assertFalse($chiesta, 'con un rifiuto non si apre il database');
        self::assertStringContainsString($messaggio, $testo);
        foreach ([$vecchia, $nuova] as $chiave) {
            // Le chiavi tagliate o storpiate dei casi sopra conservano comunque
            // lunghe sequenze della chiave vera: nessuna deve comparire.
            if (strlen($chiave) >= 16) {
                self::assertStringNotContainsString(strtolower(substr(trim($chiave), 2, 16)), strtolower($testo));
            }
        }
    }

    #[Test]
    public function con_due_chiavi_valide_e_diverse_chiede_la_connessione(): void
    {
        foreach ([[], ['--apply'], ['--verify'], ['--dry-run', '--docenti=7,8']] as $argomenti) {
            $a = bin2hex(random_bytes(32));
            [$codice, $testo, $chiesta] = $this->esegui($argomenti, $a, strtoupper(bin2hex(random_bytes(32))));

            $caso = implode(' ', $argomenti);
            self::assertTrue($chiesta, "le chiavi valide non sono arrivate al database: $caso");
            // Un database che non risponde è un'uscita pulita, non un errore
            // fatale di PHP con la traccia dello stack.
            self::assertSame(2, $codice, $caso);
            self::assertStringContainsString('Database non raggiungibile: ' . self::CONNESSIONE_CHIESTA, $testo);
        }
    }

    #[Test]
    public function l_aiuto_non_chiede_chiavi_ne_database(): void
    {
        [$codice, $testo, $chiesta] = $this->esegui(['--help'], '', '');

        self::assertSame(0, $codice);
        self::assertFalse($chiesta);
        self::assertStringContainsString('--dry-run   (predefinito)', $testo);
    }

    #[Test]
    public function riuscito_guarda_gli_illeggibili_sempre_e_la_vecchia_solo_in_verifica(): void
    {
        $vuoto = [
            'totale' => 1, 'nel_database' => 1, 'con_la_nuova' => 0, 'con_la_vecchia' => 1, 'prefisso_storico' => 0,
            'ricifrati' => 0, 'solo_con_la_vecchia' => ['9/v1'], 'illeggibili' => [],
        ];
        $esito = ['modo' => RiavvolgimentoDellaMaster::PROVA, 'docenti' => 1, 'chiavi' => $vuoto, 'recupero' => $vuoto];

        self::assertTrue(RiavvolgimentoDellaMaster::riuscito($esito), 'in prova, «con la vecchia» è il lavoro da fare');
        $esito['modo'] = RiavvolgimentoDellaMaster::VERIFICA;
        self::assertFalse(RiavvolgimentoDellaMaster::riuscito($esito), 'in verifica, «con la vecchia» è un difetto');
        $esito['chiavi']['con_la_vecchia'] = 0;
        $esito['recupero']['con_la_vecchia'] = 0;
        self::assertTrue(RiavvolgimentoDellaMaster::riuscito($esito));
        $esito['modo'] = RiavvolgimentoDellaMaster::APPLICA;
        $esito['recupero']['illeggibili'] = ['9'];
        self::assertFalse(RiavvolgimentoDellaMaster::riuscito($esito), 'un elemento che non si apre non è mai a posto');
    }

    #[Test]
    public function riuscito_vuole_che_gli_elementi_siano_le_righe_del_database(): void
    {
        $nessuno = [
            'totale' => 0, 'nel_database' => 0, 'con_la_nuova' => 0, 'con_la_vecchia' => 0, 'prefisso_storico' => 0,
            'ricifrati' => 0, 'solo_con_la_vecchia' => [], 'illeggibili' => [],
        ];
        foreach ([RiavvolgimentoDellaMaster::PROVA, RiavvolgimentoDellaMaster::APPLICA, RiavvolgimentoDellaMaster::VERIFICA] as $modo) {
            $esito = ['modo' => $modo, 'docenti' => 0, 'chiavi' => $nessuno, 'recupero' => $nessuno];
            self::assertTrue(RiavvolgimentoDellaMaster::riuscito($esito), "$modo: un database senza chiavi è a posto");

            // Zero elementi esaminati e tre righe nel database: è il giro che
            // non ha trovato nessun docente, non un database vuoto.
            $esito['chiavi']['nel_database'] = 3;
            self::assertFalse(RiavvolgimentoDellaMaster::riuscito($esito), "$modo: chiavi non esaminate");
            $esito['chiavi']['nel_database'] = 0;
            $esito['recupero']['nel_database'] = 1;
            self::assertFalse(RiavvolgimentoDellaMaster::riuscito($esito), "$modo: chiavi di recupero non esaminate");
        }
    }

    /**
     * @param list<string> $argomenti
     * @return array{0: int, 1: string, 2: bool} codice, uscita ed errori, connessione chiesta
     */
    private function esegui(array $argomenti, string $vecchia, string $nuova): array
    {
        $uscita = fopen('php://memory', 'w+');
        $errori = fopen('php://memory', 'w+');
        self::assertIsResource($uscita);
        self::assertIsResource($errori);
        $chiesta = false;
        $codice = ComandoDiRiavvolgimento::esegui(
            $argomenti,
            ['KMS_MASTER_KEY' => $vecchia, 'KMS_MASTER_KEY_NEW' => $nuova],
            static function () use (&$chiesta): PDO {
                $chiesta = true;
                throw new RuntimeException(self::CONNESSIONE_CHIESTA);
            },
            $uscita,
            $errori
        );
        rewind($uscita);
        rewind($errori);
        return [$codice, (string)stream_get_contents($uscita) . (string)stream_get_contents($errori), $chiesta];
    }
}
