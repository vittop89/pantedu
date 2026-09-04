<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * Health & version — endpoint PUBBLICI per deploy-verify e monitoring.
 *
 *   GET /version → git sha corrente (repo è pubblico EUPL → non sensibile).
 *                  Rende la verifica di un deploy un `curl` invece di SSH+git log.
 *   GET /health  → stato DB + conteggio migration (applied/pending). 200 se sano,
 *                  503 se il DB è giù (così i monitor HTTP rilevano l'outage).
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

                $migrator = new Migrator($pdo, $this->root() . '/database/migrations');
                $applied  = \count($migrator->executedFilenames());
                $pending  = \count($migrator->pending());
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
