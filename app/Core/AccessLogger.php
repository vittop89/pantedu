<?php

namespace App\Core;

final class AccessLogger
{
    /** Etichetta HKDF della chiave delle impronte di sessione (vedi improntaDellaSessione). */
    private const INFO_IMPRONTA_SESSIONE = 'pantedu/access-log/impronta-sessione/v1';

    private string $logFile;
    private string $statsFile;

    // G22.S15.bis Fase 5+ — keys uppercase modernizzati + legacy lowercase
    // per back-compat (log storici prima della migrazione).
    private const INSTITUTE_MAP = [
        'SCI' => 'Scientifico', 'sc' => 'Scientifico',
        'ART' => 'Artistico',   'ar' => 'Artistico',
        'CLA' => 'Classico',    'cl' => 'Classico',
        'LIN' => 'Linguistico', 'ling' => 'Linguistico', 'li' => 'Linguistico',
        'AFM' => 'Amministrazione e Finanza', 'af' => 'Amministrazione e Finanza',
    ];

    // Le voci "Bilinguismo" (1B..5B) sono state tolte: quel percorso non
    // esiste piu' e con le sezioni "3B" significa ora sezione B. Lasciarle
    // avrebbe etichettato le sezioni reali col nome sbagliato nei log.
    private const CLASS_MAP = [
        '1S' => 'Prima Standard',  '2S' => 'Seconda Standard',
        '3S' => 'Terza Standard',  '4S' => 'Quarta Standard',  '5S' => 'Quinta Standard',
        // legacy lowercase
        '1s' => 'Prima Standard',  '2s' => 'Seconda Standard',
        '3s' => 'Terza Standard',  '4s' => 'Quarta Standard',  '5s' => 'Quinta Standard',
    ];

    /**
     * Dove sta il registro degli accessi: lo dice chi lo scrive.
     *
     * Fino al 20/9/2026 tre lettori (`AdminNotificationsService`,
     * `AdminAnalyticsService`, `SecurityAdminController`) se lo costruivano da
     * soli, e lo cercavano in `<dati>/log/data/access_log.json` — un percorso
     * che dalla Phase 25.J non scrive più nessuno. Misurato: con cinque
     * accessi falliti nel registro vero, il riepilogo per l'amministratore
     * diceva «nessun accesso fallito». Adesso la domanda si fa a questa
     * funzione, e la risposta è una sola.
     *
     * @param string|null $base radice dei dati d'istanza; `null` = quella
     *                          della configurazione
     */
    public static function percorsoDelRegistro(?string $base = null): string
    {
        $radice = \App\Support\PercorsiDati::base(dirname(__DIR__, 2), $base);
        $moderno = self::cartellaDelRegistro($base) . '/access_log.json';
        // Il percorso di prima della Phase 25.J: si legge solo se il file c'è
        // davvero, cioè su un'istanza vecchia che non ha ancora scritto niente
        // nel nuovo. Nessuno ci scrive più.
        $legacy = $radice . '/log/data/access_log.json';

        return (!is_file($moderno) && is_file($legacy)) ? $legacy : $moderno;
    }

    /**
     * La cartella dove SI SCRIVE il registro. La usano il costruttore qui
     * sotto (chi scrive) e {@see self::percorsoDelRegistro()} (chi legge):
     * una regola sola, così non possono più divergere.
     *
     * Con una radice esplicita si ricava di lì: `app.paths.logs` discende
     * sempre dalla cartella dei dati della configurazione (app/Config/app.php),
     * e chi passa una radice sta proprio dicendo di non usare quella — serve
     * alle prove e agli strumenti da riga di comando.
     */
    public static function cartellaDelRegistro(?string $base = null): string
    {
        $radice = \App\Support\PercorsiDati::base(dirname(__DIR__, 2), $base);
        if ($base !== null) {
            return $radice . '/storage/logs';
        }

        return (string)Config::get('app.paths.logs', $radice . '/storage/logs');
    }

    public function __construct(?string $logDir = null)
    {
        $dir = $logDir ?? self::cartellaDelRegistro();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->logFile   = $dir . '/access_log.json';
        $this->statsFile = $dir . '/access_stats.json';

        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, '[]');
        }
        if (!file_exists($this->statsFile)) {
            file_put_contents($this->statsFile, json_encode(['daily_stats' => new \stdClass()]));
        }
    }

    public function logAccess(string $username, string $role, ?string $linkref = null, string $action = 'access'): void
    {
        if ($action === 'logout') {
            $this->appendDebug("LOGOUT user=$username role=$role");
            return;
        }
        // 23/9/2026 — il percorso senza query e senza gettoni, come nel
        // registro delle operazioni: qui arrivano REQUEST_URI e il `redirect`
        // del login, e con loro `?token=` della conferma di cancellazione o
        // dell'email, o il QR di classe (A-81).
        if ($linkref !== null) {
            $linkref = \App\Services\Audit\PercorsoSenzaGettoni::perIlRegistro($linkref);
        }

        $entry = array_merge([
            'timestamp'  => date('Y-m-d H:i:s'),
            'date'       => date('Y-m-d'),
            'time'       => date('H:i:s'),
            'username'   => $username,
            'role'       => $role,
            'linkref'    => $linkref,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'ip_address' => $this->ip(),
            'session_fingerprint' => self::improntaDellaSessione(session_id()),
            'action'     => $action,
        ], $this->parsePath($linkref ?? ''));

        $this->append($entry);
        $this->updateStats($entry);
    }

    private function parsePath(string $linkref): array
    {
        $info = [
            'institute_code' => null, 'institute_name' => null,
            'class_code'     => null, 'class_name'     => null,
            'subject'        => null, 'lesson_number'  => null, 'lesson_topic' => null,
        ];
        if (preg_match('#/eser/([a-z]+)/eser_([a-z]+\d+[sb]?)/#', $linkref, $m)) {
            $info['institute_code'] = $m[1];
            $info['institute_name'] = self::INSTITUTE_MAP[$m[1]] ?? 'Sconosciuto';
            if (preg_match('#([a-z]+)(\d+[sb]?)$#', $m[2], $cm)) {
                $info['class_code'] = $cm[2];
                $info['class_name'] = self::CLASS_MAP[$cm[2]] ?? 'Sconosciuta';
            }
            if (preg_match('#/([A-Z]+)/(\d+)_[A-Z]+-([^-]+)-#', $linkref, $lm)) {
                $info['subject']       = $lm[1];
                $info['lesson_number'] = $lm[2];
                $info['lesson_topic']  = $lm[3];
            }
        }
        return $info;
    }

    /**
     * Legge un file JSON di log. Null se il file non si può leggere in questo
     * momento: su Windows un `file_put_contents(LOCK_EX)` concorrente fa
     * fallire la lettura con «Permission denied» (2026-09-05, suite E2E), e in
     * quel caso meglio saltare una riga di log che azzerare il file.
     *
     * @return array<mixed>|null
     */
    private function readJson(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return \is_array($data) ? $data : [];
    }

    private function append(array $entry): void
    {
        $max  = (int)Config::get('audit.access_log_max_entries', 1000);
        $logs = $this->readJson($this->logFile);
        if ($logs === null) {
            return;
        }
        // Le voci scritte prima del 23/9/2026 hanno l'id in chiaro: si
        // convertono alla prima scrittura, che riscrive comunque il file.
        $logs = self::senzaIdDiSessione($logs);
        $logs[] = $entry;
        if (count($logs) > $max) {
            $logs = array_slice($logs, -$max);
        }
        file_put_contents($this->logFile, json_encode($logs, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * I totali di `access_stats.json`: quanti accessi al giorno, e basta.
     *
     * 24/9/2026 — fino a quel giorno il file teneva per sempre, giorno per
     * giorno, i nomi utente di chi era entrato (`unique_users`), e per ogni
     * utente il primo e l'ultimo accesso e il totale (`user_stats`). Nessun
     * documento lo dichiarava, nessun lavoro lo puliva, la cancellazione
     * dell'account non lo toccava: il termine del registro degli accessi (le
     * ultime mille voci) si aggirava per intero. Il cruscotto ne usava solo il
     * totale e il numero di utenti del giorno, che adesso si conta dal
     * registro (utentiDiOggi). Quello che resta del vecchio contenuto si
     * toglie alla prima lettura (statisticheSenzaNomi).
     */
    private function updateStats(array $e): void
    {
        $stats = $this->readJson($this->statsFile);
        if ($stats === null) {
            return;
        }
        $stats = self::statisticheSenzaNomi($stats);
        $date  = $e['date'];
        $stats['daily_stats'][$date] ??= ['total_accesses' => 0];
        $stats['daily_stats'][$date]['total_accesses']++;

        file_put_contents($this->statsFile, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Solo i totali per giorno: via `user_stats`, `unique_users` e i due
     * contenitori per istituto e classe, che nessuno riempiva.
     *
     * @param array<mixed> $stats
     * @return array{daily_stats: array<string, array{total_accesses: int}>}
     */
    private static function statisticheSenzaNomi(array $stats): array
    {
        $giorni = [];
        foreach ((array)($stats['daily_stats'] ?? []) as $data => $giorno) {
            $giorni[(string)$data] = ['total_accesses' => (int)(\is_array($giorno) ? ($giorno['total_accesses'] ?? 0) : 0)];
        }
        return ['daily_stats' => $giorni];
    }

    /**
     * La riga di uscita in `debug.log`, accanto al registro degli accessi.
     *
     * 23/9/2026 — con l'impronta della sessione al posto dell'id, come nel
     * registro: l'uscita si ritrova fra gli accessi della stessa sessione.
     * Prima scriveva in `app.paths.logs`, che è la cartella di chi scrive il
     * registro quando non gliene si passa un'altra: stessa cartella, ma una
     * prova che ne passa una sua non scrive più in quella dell'istanza.
     */
    private function appendDebug(string $msg): void
    {
        $file = \dirname($this->logFile) . '/debug.log';
        $line = sprintf(
            "[%s] %s ip=%s session=%s\n",
            date('d-M-Y H:i:s T'),
            $msg,
            $this->ip(),
            self::improntaDellaSessione(session_id()) ?? '-'
        );
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Un'impronta della sessione che permette di raggrupparne le richieste ma
     * non di adottarla.
     *
     * 23/9/2026 — fino a oggi ogni voce conteneva `session_id()` in chiaro,
     * di sessioni vive: chi legge il registro (il super-amministratore dal
     * pannello, o chi ha accesso ai file dei dati) poteva presentarsi con quel
     * cookie ed essere il docente, dati cifrati compresi, senza la motivazione
     * e la registrazione che ADR-006 chiede per ogni accesso ai contenuti di
     * un altro (revisione architetturale 2026-09, A-64).
     *
     * Un HMAC-SHA256 dell'id, troncato a 16 cifre esadecimali (64 bit: in mille
     * voci due sessioni diverse non si confondono). Dall'impronta non si torna
     * all'id — nemmeno conoscendo la chiave, perché l'id ha più di cento bit
     * casuali; la chiave serve a non poter dire, da un id rubato altrove,
     * quali voci sono sue. È derivata con HKDF da `waf.hmac_secret`, il
     * segreto del server che l'applicazione ha sempre: `WAF_HMAC_SECRET`, o in
     * sua assenza la chiave generata e conservata dalla configurazione
     * (`app/Config/waf.php`). Nessuna variabile d'ambiente nuova. Non la
     * chiave master: protegge i dati cifrati, e un'impronta di navigazione
     * non deve dipendere da lei né dalla sua rotazione. L'etichetta di HKDF
     * separa gli usi: la chiave derivata non firma cookie del WAF, e
     * viceversa. Se il segreto cambia, cambiano le impronte: si perde solo il
     * raggruppamento a cavallo del cambio.
     *
     * Null senza sessione, o senza segreto (non succede: la configurazione ne
     * genera uno).
     */
    public static function improntaDellaSessione(string $idSessione): ?string
    {
        $segreto = (string)Config::get('waf.hmac_secret', '');
        if ($idSessione === '' || $segreto === '') {
            return null;
        }
        $chiave = hash_hkdf('sha256', $segreto, 32, self::INFO_IMPRONTA_SESSIONE);
        return substr(hash_hmac('sha256', $idSessione, $chiave), 0, 16);
    }

    /**
     * Le voci del registro senza id di sessione in chiaro: una voce scritta
     * prima del 23/9/2026 lo perde, e ne riceve l'impronta.
     *
     * @param array<mixed> $voci
     * @return array<mixed>
     */
    private static function senzaIdDiSessione(array $voci): array
    {
        foreach ($voci as $i => $voce) {
            if (\is_array($voce) && \array_key_exists('session_id', $voce)) {
                $id = $voce['session_id'];
                unset($voce['session_id']);
                $voce['session_fingerprint'] = \is_string($id) ? self::improntaDellaSessione($id) : null;
                $voci[$i] = $voce;
            }
        }
        return $voci;
    }

    /**
     * 23/9/2026 — l'IP di EdgeContext. Qui si prendeva `Client-IP`, poi
     * `X-Forwarded-For` per intero: header che sceglie il client. E da questo
     * campo l'analisi delle anomalie decide chi bloccare: con un indirizzo
     * inventato si poteva far bloccare quello di un altro (revisione
     * architetturale 2026-09, A-63).
     */
    private function ip(): string
    {
        $ip = \App\Services\Waf\EdgeContext::clientIp($_SERVER);
        return $ip === '0.0.0.0' ? 'unknown' : $ip;
    }

    public function recent(int $limit = 50): array
    {
        $logs = json_decode(file_get_contents($this->logFile), true) ?: [];
        // Anche in lettura: un file non ancora riscritto dal 23/9/2026 ha le
        // voci vecchie, e il pannello non deve mostrarne l'id.
        $logs = self::senzaIdDiSessione($logs);
        $logs = array_filter($logs, fn($l) => ($l['action'] ?? '') !== 'logout');
        usort($logs, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
        return array_slice($logs, 0, $limit);
    }

    /**
     * I totali per giorno. Se il file ha ancora il contenuto di prima del
     * 24/9/2026, con i nomi utente, lo riscrive senza.
     *
     * @return array<mixed>
     */
    public function stats(string $type = 'all'): array
    {
        $letto = $this->readJson($this->statsFile) ?? [];
        $s = self::statisticheSenzaNomi($letto);
        if ($letto !== [] && $letto !== $s) {
            file_put_contents($this->statsFile, json_encode($s, JSON_PRETTY_PRINT), LOCK_EX);
        }
        return $type === 'all' ? $s : ($s[$type] ?? []);
    }

    /**
     * Quanti utenti diversi sono entrati oggi, contati sul registro degli
     * accessi: le ultime `audit.access_log_max_entries` voci, quindi in una
     * giornata con più accessi il numero è un minimo. Gli anonimi non contano.
     */
    public function utentiDiOggi(): int
    {
        $oggi  = date('Y-m-d');
        $visti = [];
        foreach ($this->readJson($this->logFile) ?? [] as $voce) {
            if (!\is_array($voce) || ($voce['date'] ?? '') !== $oggi) {
                continue;
            }
            $utente = (string)($voce['username'] ?? '');
            if ($utente !== '' && $utente !== 'anonymous') {
                $visti[$utente] = true;
            }
        }
        return count($visti);
    }
}
