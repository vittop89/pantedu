<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use RuntimeException;

/**
 * La chiave madre (`KMS_MASTER_KEY`): dove si legge e quando è buona, in un
 * posto solo (23/9/2026, revisione architetturale A-36).
 *
 * Fino a oggi la leggevano cinque punti con tre regole diverse:
 * `TeacherCryptoService` e `TeacherRecoveryService` volevano 64 caratteri
 * esadecimali; la firma dell'export per l'autorità
 * (`AdminCryptoStatusController::deriveExportSigningKey`) prendeva qualunque
 * stringa di almeno 16 byte, esadecimale o no; `SessionStorage` e
 * `ProviderKeyStore` guardavano soltanto che non fosse vuota. Con la chiave
 * vera i cinque concordano. Con una chiave presente ma sbagliata no, e il
 * punto peggiore era `CompilationRepository::seal`: la trattava come assente e
 * scriveva le compilazioni in chiaro.
 *
 * Tre stati, e chi usa la chiave decide che cosa fare di ciascuno:
 *   - assente: la variabile manca o è vuota. È l'installazione di sviluppo
 *     senza chiave, e chi lo prevede scrive in chiaro, come sempre;
 *   - valida: 64 caratteri esadecimali, cioè 32 byte. È la forma che genera
 *     `tools/crypto/generate_kms_key.php` e l'unica che la cifratura abbia mai
 *     accettato. Niente base64 e niente spazi tolti: un'altra forma è un
 *     errore di chi l'ha scritta, e una chiave non si indovina;
 *   - malformata: c'è, ma non ha quella forma. Chi scrive dati si ferma, non
 *     ripiega sul chiaro.
 *
 * Si legge solo `$_ENV`, come la cifratura ha sempre fatto: Dotenv ce la mette
 * da `.env.local`. La firma dell'export ripiegava anche su `$_SERVER`, ma una
 * chiave che vede solo la firma non cifra niente, e firmare con una chiave che
 * la cifratura non vede non prova niente.
 *
 * Il valore esce di qui solo come byte, per chi deve derivarne altre chiavi, e
 * non compare nei messaggi né intero né in parte.
 */
final class ChiaveMadre
{
    public const ASSENTE    = 'assente';
    public const VALIDA     = 'valida';
    public const MALFORMATA = 'malformata';

    /** 64 caratteri esadecimali, e niente prima o dopo: nemmeno un a capo. */
    private const FORMA = '/\A[0-9a-fA-F]{64}\z/';
    private const BYTE  = 32;

    /** Proprietà classiche, non promosse nel costruttore: semgrep non legge quelle `readonly`. */
    private string $stato;
    private ?string $byte;

    /** Quanti caratteri aveva il valore dato: dice perché è malformata, senza dirlo. */
    private int $lunghezza;

    private function __construct(string $stato, ?string $byte, int $lunghezza = 0)
    {
        $this->stato = $stato;
        $this->byte  = $byte;
        $this->lunghezza = $lunghezza;
    }

    /** La chiave dell'ambiente, `$_ENV['KMS_MASTER_KEY']`. */
    public static function dallAmbiente(): self
    {
        // Il nome scritto per intero: una grep di KMS_MASTER_KEY deve trovare
        // questa riga (e tools/ci/check-env-example.mjs la conta).
        $valore = $_ENV['KMS_MASTER_KEY'] ?? null;
        if ($valore !== null && !\is_string($valore)) {
            return new self(self::MALFORMATA, null);
        }
        return self::daValore($valore);
    }

    /**
     * Un'altra variabile dell'ambiente con la stessa forma: la chiave nuova di
     * un cambio della master (`KMS_MASTER_KEY_NEW`, tools/crypto/rewrap_master.php).
     * Stesse regole della master; la master si legge solo con dallAmbiente().
     */
    public static function dallaVariabile(string $nome): self
    {
        $valore = $_ENV[$nome] ?? null;
        if ($valore !== null && !\is_string($valore)) {
            return new self(self::MALFORMATA, null);
        }
        return self::daValore($valore);
    }

    /** Una chiave data a mano (le prove, gli strumenti): le stesse regole. */
    public static function daValore(?string $esadecimale): self
    {
        if ($esadecimale === null || $esadecimale === '') {
            return new self(self::ASSENTE, null);
        }
        // Da `\A` a `\z`, non da `^` a `$`: `$` lascia passare anche un a capo
        // finale (la regola di prima, in TeacherCryptoService e negli
        // strumenti), e hex2bin() su 65 caratteri avvisa e restituisce false.
        // hex2bin() si chiama solo su un valore che la regola ha accettato.
        $lunghezza = \strlen($esadecimale);
        if (\preg_match(self::FORMA, $esadecimale) !== 1) {
            return new self(self::MALFORMATA, null, $lunghezza);
        }
        $byte = \hex2bin($esadecimale);
        if (!\is_string($byte) || \strlen($byte) !== self::BYTE) {
            return new self(self::MALFORMATA, null, $lunghezza);
        }
        return new self(self::VALIDA, $byte, $lunghezza);
    }

    /** Uno di ASSENTE, VALIDA, MALFORMATA. */
    public function stato(): string
    {
        return $this->stato;
    }

    /** Quanti caratteri aveva il valore letto o dato (0 se assente). */
    public function lunghezza(): int
    {
        return $this->lunghezza;
    }

    /** C'è qualcosa nella variabile, valido o no. */
    public function presente(): bool
    {
        return $this->stato !== self::ASSENTE;
    }

    public function valida(): bool
    {
        return $this->stato === self::VALIDA;
    }

    public function malformata(): bool
    {
        return $this->stato === self::MALFORMATA;
    }

    /**
     * I 32 byte della chiave. Solo per una chiave valida: per le altre
     * un'eccezione che dice lo stato, non il valore.
     */
    public function byte(): string
    {
        if ($this->byte === null) {
            throw new RuntimeException('kms_not_configured: KMS_MASTER_KEY ' . $this->stato);
        }
        return $this->byte;
    }

    /**
     * Un var_dump() o un print_r() dell'oggetto mostra lo stato, non i byte.
     *
     * @return array{stato:string}
     */
    public function __debugInfo(): array
    {
        return ['stato' => $this->stato];
    }
}
