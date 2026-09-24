<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Services\TexCompile\TexCompileClient;
use Throwable;

/**
 * Health & version — endpoint PUBBLICI per deploy-verify e monitoring.
 *
 *   GET /version → git sha corrente (repo è pubblico EUPL → non sensibile).
 *                  Rende la verifica di un deploy un `curl` invece di SSH+git log.
 *   GET /health  → stato DB + conteggio migration (applied/pending). 200 se sano,
 *                  503 se il DB è giù (così i monitor HTTP rilevano l'outage).
 *   GET /health/backup → il backup notturno è recente?
 *   GET /health/tex    → l'applicazione raggiunge il servizio TeX?
 *
 * Nessuna dipendenza da exec(): lo sha è letto direttamente da .git/HEAD.
 */
final class HealthController
{
    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function version(Request $req): Response
    {
        [$gitSha, $branch] = $this->gitRef();
        // In prod .git/refs/heads/main è pantedu:pantedu 0660 → www-data non lo
        // legge; il deploy scrive storage/version.txt (leggibile da www-data).
        // .git/HEAD resta leggibile → il branch viene comunque da gitRef().
        $sha = $this->shaFromFile() ?? $gitSha;
        return Response::json([
            'sha'    => $sha,
            'short'  => $sha !== 'unknown' ? \substr($sha, 0, 8) : 'unknown',
            'branch' => $branch,
        ]);
    }

    private function shaFromFile(): ?string
    {
        $f = @\file_get_contents($this->root() . '/storage/version.txt');
        if ($f === false) {
            return null;
        }
        $f = \trim($f);
        return $f !== '' ? $f : null;
    }

    /**
     * GET /health/backup — il backup notturno e' andato a buon fine di recente?
     *
     * Esiste perche' un monitor HTTP sul sito risponde a "il servizio e' vivo",
     * non a "il lavoro e' stato fatto". Il 28-30 agosto 2026 il backup e'
     * fallito tre notti di fila (MariaDB giu' all'ora del dump) senza che
     * niente lo segnalasse.
     *
     * encrypted_backup.sh scrive il marker come ultima istruzione: con
     * `set -euo pipefail` qualunque fallimento precedente lo lascia vecchio.
     * Qui non serve capire *cosa* sia andato storto: se il marker e' stantio,
     * il backup non e' riuscito.
     *
     * 503 quando e' stantio o assente, cosi' un semplice monitor HTTP (anche
     * gratuito) lo vede come DOWN. Stessa scelta di health(): il codice di
     * stato deve dire la verita', non un flag dentro un 200.
     *
     * Il path va nel bypass del WAF (WafMiddleware), altrimenti la challenge
     * PoW restituisce 200 con la pagina "Verifica…" e il monitor resta verde
     * comunque — la trappola in cui era gia' caduto /health.
     */
    public function backupFreshness(Request $req): Response
    {
        $maxAge = 25 * 3600;   // il timer gira alle 02:30 con scarto casuale
        $file   = Config::get('app.paths.storage') . '/backup-last-ok';

        $raw = @\file_get_contents($file);
        if ($raw === false) {
            return Response::json([
                'ok'     => false,
                'reason' => 'marker assente: nessun backup riuscito registrato',
            ], 503);
        }

        $ts = (int) \trim($raw);
        if ($ts <= 0) {
            return Response::json([
                'ok'     => false,
                'reason' => 'marker illeggibile',
            ], 503);
        }

        $age = \time() - $ts;
        $ok  = $age >= 0 && $age <= $maxAge;

        return Response::json([
            'ok'      => $ok,
            'age_h'   => (int) \round($age / 3600),
            'max_h'   => (int) ($maxAge / 3600),
        ], $ok ? 200 : 503);
    }

    /**
     * GET /health/tex — l'applicazione raggiunge il servizio TeX? (19/9/2026)
     *
     * Dall'8 settembre 2026 il container non lo raggiungeva: cercava il TeX sul
     * proprio 127.0.0.1, e il servizio sta sull'host. Undici giorni senza una
     * compilazione avviata dal sito, e nessun controllo se n'è accorto, perché
     * tutti guardavano dall'host, da cui il TeX si vede sempre. La domanda la
     * deve fare l'applicazione: la fanno il rilascio (passo 8, attraverso
     * nginx) e la diagnostica dell'host.
     *
     * Pubblico come /health e /health/backup, e per questo dice solo sì o no e
     * la classe dell'errore: mai indirizzo, porta o segreto. 503 quando non
     * risponde, per la stessa ragione di health(): il codice deve dire la
     * verità. Non configurato (sviluppo senza TeX, CI) non è un guasto: 200.
     *
     * Sta nel bypass del WAF (WafMiddleware), altrimenti la sfida PoW
     * risponderebbe 200 con la sua pagina.
     *
     * 20/9/2026 — l'esito della sonda vale trenta secondi.
     *
     * Pubblico, fuori dal WAF e con una chiamata sincrona di tre secondi a ogni
     * richiesta: con i pacchetti scartati, sessanta richieste da un indirizzo
     * tengono occupati i dieci processi di php-fpm per una ventina di secondi,
     * e il sito smette di rispondere a tutti. La cura non è nascondere
     * l'endpoint — serve al rilascio e alla diagnostica — ma non ripetere la
     * domanda: trenta secondi sono abbastanza per non rifarla sotto carico e
     * abbastanza pochi perché un servizio morto si veda subito.
     *
     * Il rilascio, che la risposta la vuole fresca dopo lo scambio, cancella il
     * file prima di chiedere (passo 8 di `deploy-container.sh`).
     *
     * Quale errore: `connessione rifiutata` e `tempo scaduto` restano distinti
     * anche qui. Non sono indirizzi né segreti — sono l'esito del **nostro**
     * cURL — e sono la prima colonna della tabella del runbook: chi guarda
     * `/health/tex` dopo un rilascio deve sapere se cercare un indirizzo
     * sbagliato o una regola del firewall, senza entrare sulla macchina.
     */
    public function tex(Request $req): Response
    {
        $client = TexCompileClient::tryDefault();
        if ($client === null) {
            return Response::json(['tex' => 'non_configurato']);
        }

        $esito = $this->sondaTexConCache($client);
        return Response::json($esito['corpo'], $esito['stato']);
    }

    /** Quanto vale l'esito della sonda del TeX prima di rifarla. */
    private const TEX_CACHE_SECONDI = 30;

    /**
     * Quanto si accetta di essere vecchi quando qualcun altro sta già sondando:
     * meglio una risposta di cinque minuti fa che dieci processi fermi.
     */
    private const TEX_CACHE_RIPIEGO_SECONDI = 300;

    private function percorsoCacheTex(): string
    {
        return (string)Config::get('app.paths.storage', $this->root() . '/storage')
            . '/cache/health-tex.json';
    }

    /**
     * La sonda, con la cache davanti.
     *
     * @return array{stato: int, corpo: array<string, mixed>}
     */
    private function sondaTexConCache(TexCompileClient $client): array
    {
        $file = $this->percorsoCacheTex();
        // L'indirizzo del servizio non finisce nel file: ci finisce la sua
        // impronta, perché il registro dei dati d'istanza è condiviso fra il
        // container vecchio e quello nuovo e un rilascio può cambiarlo.
        $chiave = hash('sha256', $client->servizio());

        $fresca = $this->cacheTex($file, $chiave, self::TEX_CACHE_SECONDI);
        if ($fresca !== null) {
            return $fresca;
        }

        // Una sonda per volta: chi non prende il lucchetto si accontenta della
        // risposta di prima. Senza, sessanta richieste nello stesso istante
        // farebbero sessanta chiamate al TeX — cioè esattamente il problema.
        $lucchetto = $this->apriLucchettoTex($file);
        $mio = $lucchetto !== null && @flock($lucchetto, LOCK_EX | LOCK_NB);
        if (!$mio) {
            $vecchia = $this->cacheTex($file, $chiave, self::TEX_CACHE_RIPIEGO_SECONDI);
            if ($lucchetto !== null) {
                fclose($lucchetto);
            }
            // Se non c'è proprio niente da servire si sonda comunque: il
            // lucchetto contiene il carico, non inventa risposte.
            if ($vecchia !== null) {
                return $vecchia;
            }
            $lucchetto = null;
        }

        try {
            $sonda = $client->sonda();
            $esito = $sonda['ok']
                ? ['stato' => 200, 'corpo' => ['tex' => true]]
                : ['stato' => 503, 'corpo' => ['tex' => false, 'errore' => $sonda['classe']]];
            $this->scriviCacheTex($file, $chiave, $esito);
            return $esito;
        } finally {
            if ($mio && $lucchetto !== null) {
                @flock($lucchetto, LOCK_UN);
                fclose($lucchetto);
            }
        }
    }

    /**
     * L'esito in cache, se c'è, è di questo endpoint e non è più vecchio di
     * `$entro` secondi.
     *
     * @return array{stato: int, corpo: array<string, mixed>}|null
     */
    private function cacheTex(string $file, string $chiave, int $entro): ?array
    {
        $grezzo = @file_get_contents($file);
        if ($grezzo === false) {
            return null;
        }
        $voce = json_decode($grezzo, true);
        if (!\is_array($voce) || ($voce['chiave'] ?? null) !== $chiave) {
            return null;
        }
        $quando = (int)($voce['quando'] ?? 0);
        $eta = \time() - $quando;
        // Un orologio che va indietro (o un file con una data futura) non deve
        // valere per sempre: si tratta come scaduto.
        if ($eta < 0 || $eta > $entro) {
            return null;
        }
        $stato = (int)($voce['stato'] ?? 0);
        $corpo = $voce['corpo'] ?? null;
        if (!\is_array($corpo) || ($stato !== 200 && $stato !== 503)) {
            return null;
        }
        return ['stato' => $stato, 'corpo' => $corpo];
    }

    /** @param array{stato: int, corpo: array<string, mixed>} $esito */
    private function scriviCacheTex(string $file, string $chiave, array $esito): void
    {
        $cartella = \dirname($file);
        if (!is_dir($cartella) && !@mkdir($cartella, 0775, true) && !is_dir($cartella)) {
            return; // niente cache: si sonda ogni volta, come prima. Non si fallisce.
        }
        $nuovo = !is_file($file);
        $scritto = @file_put_contents($file, (string)json_encode([
            'quando' => \time(),
            'chiave' => $chiave,
            'stato'  => $esito['stato'],
            'corpo'  => $esito['corpo'],
        ]), LOCK_EX);
        // Lo scrivono www-data dal container e pantedu dall'host: senza gruppo
        // e permessi condivisi il primo che lo crea esclude l'altro. È la
        // stessa trappola di `anomalie.jsonl` (Anomalia::permessiCondivisi).
        if ($scritto !== false && $nuovo) {
            @chmod($file, 0o660);
            $gruppo = @filegroup($cartella);
            if ($gruppo !== false && @filegroup($file) !== $gruppo) {
                @chgrp($file, $gruppo);
            }
        }
    }

    /** @return resource|null */
    private function apriLucchettoTex(string $file)
    {
        $cartella = \dirname($file);
        if (!is_dir($cartella) && !@mkdir($cartella, 0775, true) && !is_dir($cartella)) {
            return null;
        }
        $aperto = @fopen($file . '.lock', 'c');
        return \is_resource($aperto) ? $aperto : null;
    }

    public function health(Request $req): Response
    {
        $dbUp    = false;
        $applied = null;
        $pending = null;

        try {
            if (Database::isAvailable()) {
                $pdo = Database::connection();
                $pdo->query('SELECT 1');
                $dbUp = true;

                // `pendingSenzaCreare()` e non `pending()`: il secondo fa un
                // `CREATE TABLE IF NOT EXISTS` sulla tabella di tracciamento,
                // e questa è la connessione dell'applicazione — quella con cui
                // il sito serve ogni richiesta, che non deve avere permessi di
                // DDL. Un endpoint che dice di osservare non deve modificare.
                $migrator = new Migrator($pdo, $this->root() . '/database/migrations');
                $applied  = \count($migrator->executedFilenames());
                $pending  = \count($migrator->pendingSenzaCreare());
            }
        } catch (Throwable) {
            $dbUp = false;
        }

        return Response::json([
            'ok'         => $dbUp,
            'db'         => $dbUp,
            'migrations' => ['applied' => $applied, 'pending' => $pending],
            'time'       => \gmdate('c'),
        ], $dbUp ? 200 : 503);
    }

    /**
     * Legge [sha, branch] da .git senza exec (sicuro: il deploy fa git reset
     * --hard → .git presente). Gestisce ref, packed-refs e detached HEAD.
     *
     * @return array{0:string,1:string}
     */
    private function gitRef(): array
    {
        $git  = $this->root() . '/.git';
        $head = @\file_get_contents($git . '/HEAD');
        if ($head === false) {
            return ['unknown', 'unknown'];
        }
        $head = \trim($head);

        if (\str_starts_with($head, 'ref:')) {
            $ref    = \trim(\substr($head, 4));
            $branch = \basename($ref);

            $loose = @\file_get_contents($git . '/' . $ref);
            if ($loose !== false && \trim($loose) !== '') {
                return [\trim($loose), $branch];
            }

            $packed = @\file_get_contents($git . '/packed-refs');
            if ($packed !== false) {
                foreach (\explode("\n", $packed) as $line) {
                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }
                    if (\str_ends_with($line, ' ' . $ref)) {
                        return [\substr($line, 0, 40), $branch];
                    }
                }
            }
            return ['unknown', $branch];
        }

        // detached HEAD: contiene direttamente lo sha
        return [$head, 'detached'];
    }
}
