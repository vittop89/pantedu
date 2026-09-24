<?php

namespace App\Core;

use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Phase 17 — DB-backed session handler.
 *
 * Sostituisce il default filesystem handler:
 *   - abilita scaling orizzontale (più istanze web server condividono sessioni)
 *   - niente corruzione da crash filesystem hosting legacy
 *   - GC via DELETE WHERE last_access < NOW()-lifetime (idempotente)
 *
 * Tabella `sessions`:
 *   id VARCHAR(128) PK  — session_id PHP
 *   data LONGBLOB       — serialized session data
 *   last_access INT     — unix ts, indicizzato per GC
 *   ip VARCHAR(45)      — audit, optional
 *   ua VARCHAR(255)     — audit, optional
 *
 * Implementa sia SessionHandlerInterface (PHP base) sia
 * SessionUpdateTimestampHandlerInterface (lazy_write: evita UPDATE se i dati
 * non sono cambiati, solo bump last_access). Riduce write load.
 *
 * IL BLOCCO DELLA SESSIONE (2026-09-14)
 *   Il gestore a file di PHP blocca la sessione dalla lettura alla chiusura:
 *   due richieste della stessa sessione si mettono in fila. Questo non lo
 *   faceva, e ogni richiesta riscrive la sessione (Session::enforceTimeout
 *   aggiorna last_activity): una richiesta partita prima di un'altra e finita
 *   dopo ne cancellava le scritture. Misurato nella suite end-to-end del 14/9:
 *   il gettone CSRF preso da /auth/csrf spariva mentre la pagina caricava le
 *   sue richieste, e il POST successivo riceveva «403 — CSRF invalid» (due
 *   corse su quattro). Lo stesso poteva capitare a un permesso appena dato da
 *   una credenziale di classe o a un login.
 *
 *   Ora la lettura prende un blocco con nome di MariaDB sull'id della sessione
 *   (GET_LOCK) e la chiusura lo rilascia: le richieste della stessa sessione
 *   si mettono in fila come con i file. Il blocco è della connessione, che non
 *   è persistente, quindi muore con la richiesta anche se questa cade. Se non
 *   arriva entro l'attesa si prosegue senza, e lo si scrive nel registro degli
 *   errori: meglio una sessione letta senza blocco che una pagina ferma. Le
 *   richieste lunghe lo lasciano prima del lavoro lento con Session::close()
 *   (per esempio l'estrazione del PDF-Import).
 */
final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /** Il blocco tenuto da questa richiesta, se c'è. */
    private ?string $blocco = null;

    /**
     * @param string $tabella la tabella delle sessioni: `sessions`, o quella che
     *                        una prova crea per sé (nome semplice, verificato)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $lifetime = 1800,
        private readonly int $attesa = 15,
        private readonly string $tabella = 'sessions',
    ) {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $tabella)) {
            throw new \InvalidArgumentException('nome di tabella non valido');
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->lasciaIlBlocco();
        return true;
    }

    public function read(string $id): string
    {
        $this->prendiIlBlocco($id);
        $st = $this->pdo->prepare("SELECT data FROM `{$this->tabella}` WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)$row['data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $sql = "INSERT INTO `{$this->tabella}` (id, data, last_access, ip, ua)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE data = VALUES(data),
                                        last_access = VALUES(last_access)";
        $st = $this->pdo->prepare($sql);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $st->execute([$id, $data, time(), $ip, $ua]);
        return true;
    }

    public function destroy(string $id): bool
    {
        $st = $this->pdo->prepare("DELETE FROM `{$this->tabella}` WHERE id = ?");
        $st->execute([$id]);
        return true;
    }

    /**
     * GC chiamato da PHP con session.gc_probability. Ritorna #righe cancellate.
     *
     * `int` e non `int|false`: questa implementazione non fallisce mai in modo
     * silenzioso — se la query non riesce, PDO solleva. Restringere il tipo di
     * ritorno rispetto all'interfaccia è lecito, e dice la verità a chi chiama.
     */
    public function gc(int $max_lifetime): int
    {
        $cutoff = time() - max($max_lifetime, $this->lifetime);
        $st = $this->pdo->prepare("DELETE FROM `{$this->tabella}` WHERE last_access < ?");
        $st->execute([$cutoff]);
        return $st->rowCount();
    }

    /** lazy_write: se la sessione esiste e non è expired, valida per PHP. */
    public function validateId(string $id): bool
    {
        $st = $this->pdo->prepare(
            "SELECT 1 FROM `{$this->tabella}` WHERE id = ? AND last_access >= ? LIMIT 1"
        );
        $st->execute([$id, time() - $this->lifetime]);
        return (bool)$st->fetchColumn();
    }

    /** lazy_write: bump last_access senza UPDATE data. */
    public function updateTimestamp(string $id, string $data): bool
    {
        $st = $this->pdo->prepare("UPDATE `{$this->tabella}` SET last_access = ? WHERE id = ?");
        $st->execute([time(), $id]);
        return true;
    }

    /** Il nome del blocco di una sessione: MariaDB ne accetta al più 64 caratteri. */
    public static function nomeDelBlocco(string $id): string
    {
        return 'pantedu_sessione_' . md5($id);
    }

    private function prendiIlBlocco(string $id): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $nome = self::nomeDelBlocco($id);
        if ($this->blocco === $nome) {
            return;
        }
        // Un id nuovo (session_regenerate_id) con il blocco del vecchio ancora
        // in mano: si lascia il vecchio prima di prendere il nuovo.
        $this->lasciaIlBlocco();
        $st = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute([$nome, $this->attesa]);
        if ((int)$st->fetchColumn() === 1) {
            $this->blocco = $nome;
            return;
        }
        error_log("[sessione] blocco non preso in {$this->attesa}s: si legge senza (richieste della stessa sessione sovrapposte)");
    }

    private function lasciaIlBlocco(): void
    {
        if ($this->blocco === null) {
            return;
        }
        $st = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute([$this->blocco]);
        $this->blocco = null;
    }
}
