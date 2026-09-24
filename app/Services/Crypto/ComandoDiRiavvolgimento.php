<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Il corpo di tools/crypto/rewrap_master.php (23/9/2026, A-88).
 *
 * Sta qui e non nello script perché si possa provare senza il .env.local
 * della macchina: lo script legge le chiavi come l'applicazione (Dotenv, con
 * .env.local che vince), e una prova che lo lanciasse userebbe la master di
 * sviluppo. Qui le chiavi arrivano come argomento.
 *
 * Stampa soltanto conteggi e id. Mai una chiave, un IV, un testo in chiaro o
 * cifrato: nemmeno nei rifiuti, che nominano la variabile e non il valore.
 *
 * Codici d'uscita: 0 tutto a posto; 1 qualcosa non si apre (o, in verifica,
 * si apre ancora solo con la vecchia), o gli elementi esaminati non sono le
 * righe che il database conta; 2 rifiuto, uso sbagliato o giro interrotto.
 *
 * @phpstan-import-type Esito from RiavvolgimentoDellaMaster
 */
final class ComandoDiRiavvolgimento
{
    private const USO = <<<'TXT'
        Uso: php tools/crypto/rewrap_master.php [--dry-run | --apply | --verify] [--docenti=ID,ID,...]

          --dry-run   (predefinito) conta e controlla che tutto si apra con KMS_MASTER_KEY; non scrive
          --apply     ricifra con KMS_MASTER_KEY_NEW, una transazione per docente
          --verify    controlla che tutto si apra con KMS_MASTER_KEY_NEW
          --docenti   limita il giro a questi docenti (senza: tutti)
          --help      questo aiuto

        Procedura completa: docs/security/operations/kms-recovery.md
        TXT;

    /**
     * @param list<string>          $argomenti  gli argomenti, senza il nome dello script
     * @param array<string, mixed>  $ambiente   KMS_MASTER_KEY e KMS_MASTER_KEY_NEW
     * @param callable(): PDO       $connessione chiamata solo dopo che le chiavi sono state accettate
     * @param resource              $uscita
     * @param resource              $errori
     */
    public static function esegui(array $argomenti, array $ambiente, callable $connessione, $uscita, $errori): int
    {
        $modi = [];
        $docenti = null;
        foreach ($argomenti as $a) {
            if ($a === '--help' || $a === '-h') {
                fwrite($uscita, self::USO . "\n");
                return 0;
            }
            if ($a === '--dry-run') {
                $modi[] = RiavvolgimentoDellaMaster::PROVA;
            } elseif ($a === '--apply') {
                $modi[] = RiavvolgimentoDellaMaster::APPLICA;
            } elseif ($a === '--verify') {
                $modi[] = RiavvolgimentoDellaMaster::VERIFICA;
            } elseif (preg_match('/^--docenti=([0-9]+(?:,[0-9]+)*)$/', $a, $m) === 1) {
                $docenti = array_map('intval', explode(',', $m[1]));
            } else {
                fwrite($errori, "Argomento non riconosciuto: " . self::senzaSegreti($a) . "\n\n" . self::USO . "\n");
                return 2;
            }
        }
        if (count($modi) > 1) {
            fwrite($errori, "Un modo solo alla volta.\n\n" . self::USO . "\n");
            return 2;
        }
        $modo = $modi[0] ?? RiavvolgimentoDellaMaster::PROVA;

        $vecchia = self::chiaveDa($ambiente['KMS_MASTER_KEY'] ?? null);
        $nuova = self::chiaveDa($ambiente['KMS_MASTER_KEY_NEW'] ?? null);
        try {
            // Prima le chiavi, poi il database: un rifiuto non apre nemmeno
            // una connessione.
            RiavvolgimentoDellaMaster::controllaLeChiavi($vecchia, $nuova);
        } catch (InvalidArgumentException $e) {
            fwrite($errori, 'Rifiutato: ' . $e->getMessage() . ".\n");
            return 2;
        }
        try {
            $pdo = $connessione();
        } catch (Throwable $e) {
            fwrite($errori, 'Database non raggiungibile: ' . $e->getMessage() . ".\n");
            return 2;
        }
        try {
            $giro = new RiavvolgimentoDellaMaster($pdo, $vecchia, $nuova, $docenti);
        } catch (InvalidArgumentException $e) {
            fwrite($errori, 'Rifiutato: ' . $e->getMessage() . ".\n");
            return 2;
        }

        try {
            $esito = match ($modo) {
                RiavvolgimentoDellaMaster::APPLICA  => $giro->applica(),
                RiavvolgimentoDellaMaster::VERIFICA => $giro->verifica(),
                default                             => $giro->prova(),
            };
        } catch (Throwable $e) {
            $causa = $e->getPrevious();
            fwrite($errori, 'Interrotto: ' . $e->getMessage() . ".\n");
            if ($causa !== null) {
                fwrite($errori, 'Causa: ' . get_class($causa) . ': ' . $causa->getMessage() . "\n");
            }
            return 2;
        }

        fwrite($uscita, self::resoconto($esito));
        return RiavvolgimentoDellaMaster::riuscito($esito) ? 0 : 1;
    }

    /** @param Esito $esito */
    private static function resoconto(array $esito): string
    {
        $titoli = [
            RiavvolgimentoDellaMaster::PROVA    => 'prova (non scrive niente)',
            RiavvolgimentoDellaMaster::APPLICA  => 'applicazione',
            RiavvolgimentoDellaMaster::VERIFICA => 'verifica con la chiave nuova',
        ];
        $t = "Riavvolgimento della chiave master — " . $titoli[$esito['modo']] . "\n";
        $t .= "docenti esaminati: {$esito['docenti']}\n";

        $tipi = [
            'chiavi'   => 'chiavi dei docenti (teacher_keys, id docente/versione)',
            'recupero' => 'chiavi di recupero (teacher_recovery_keys, id docente)',
        ];
        foreach ($tipi as $tipo => $nome) {
            $c = $esito[$tipo];
            $t .= "\n$nome\n";
            $t .= sprintf("  elementi:               %d\n", $c['totale']);
            $t .= sprintf("  righe nel database:     %d\n", $c['nel_database']);
            if ($c['totale'] !== $c['nel_database']) {
                $t .= "    gli elementi esaminati non sono le righe del database: il giro non ha guardato tutto\n";
            }
            $t .= sprintf("  si aprono con la nuova: %d\n", $c['con_la_nuova']);
            $t .= sprintf(
                "  con la vecchia:         %d (di cui con il prefisso storico: %d)\n",
                $c['con_la_vecchia'],
                $c['prefisso_storico']
            );
            if ($esito['modo'] === RiavvolgimentoDellaMaster::APPLICA) {
                $t .= sprintf("  ricifrati:              %d\n", $c['ricifrati']);
            }
            $t .= sprintf("  con nessuna delle due:  %d\n", count($c['illeggibili']));
            if ($esito['modo'] === RiavvolgimentoDellaMaster::VERIFICA && $c['solo_con_la_vecchia'] !== []) {
                $t .= '    si aprono solo con la vecchia: ' . implode(', ', $c['solo_con_la_vecchia']) . "\n";
            }
            if ($c['illeggibili'] !== []) {
                $t .= '    non si aprono: ' . implode(', ', $c['illeggibili']) . "\n";
            }
        }

        $t .= "\nesito: " . (RiavvolgimentoDellaMaster::riuscito($esito)
            ? 'tutto a posto'
            : 'NON a posto: vedi sopra gli id elencati o il conteggio che non torna') . "\n";
        return $t;
    }

    /**
     * Un argomento sbagliato si ripete a chi l'ha scritto, ma non se ha la
     * forma di una chiave: capita di incollarne una al posto sbagliato.
     */
    private static function senzaSegreti(string $argomento): string
    {
        return preg_match('/[0-9a-fA-F]{16,}/', $argomento) === 1
            ? '(omesso: contiene una sequenza esadecimale lunga)'
            : $argomento;
    }

    /** Una chiave dell'ambiente: già letta da ChiaveMadre (lo script) o una stringa (le prove). */
    private static function chiaveDa(mixed $valore): string|ChiaveMadre
    {
        return $valore instanceof ChiaveMadre || \is_string($valore) ? $valore : '';
    }
}
