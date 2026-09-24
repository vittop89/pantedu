<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Crypto\EncryptedBlobStore;

/** Deposito cifrato finto: tiene i blob in memoria, conta scritture e cancellazioni. */
final class DepositoInMemoria extends EncryptedBlobStore
{
    /** @var array<string,string> */
    private array $blob = [];
    /** @var list<string> */
    public array $scritti = [];
    /** @var list<string> */
    public array $cancellati = [];
    /** Alla n-esima scrittura (da 1) lancia un errore; 0 = mai. */
    public int $falliscaAlPut = 0;
    /** @var list<int> il docente di ogni scrittura (la chiave con cui si cifra) */
    public array $scrittiPer = [];
    /** @var list<int> il docente di ogni lettura (la chiave con cui si decifra) */
    public array $lettiPer = [];

    public function __construct()
    {
        parent::__construct('verifiche_enc', null, sys_get_temp_dir() . '/pantedu-deposito-finto');
    }

    public function put(int $teacherId, string $plaintext, ?string $ulid = null): string
    {
        if ($this->falliscaAlPut > 0 && count($this->scritti) + 1 === $this->falliscaAlPut) {
            throw new \RuntimeException('blob_store_write_failed');
        }
        $percorso = $teacherId . '/' . ($ulid ?? strtoupper(bin2hex(random_bytes(13)))) . '.bin';
        $this->blob[$percorso] = $plaintext;
        $this->scritti[] = $percorso;
        $this->scrittiPer[] = $teacherId;
        return $percorso;
    }

    public function get(int $ownerTeacherId, string $relPath): string
    {
        $this->lettiPer[] = $ownerTeacherId;
        return $this->blob[$relPath] ?? throw new \RuntimeException('blob_store_not_found');
    }

    public function readKv(string $relPath): int
    {
        return 1;
    }

    public function exists(string $relPath): bool
    {
        return isset($this->blob[$relPath]);
    }

    public function delete(string $relPath): void
    {
        unset($this->blob[$relPath]);
        $this->cancellati[] = $relPath;
    }
}
