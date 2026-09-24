<?php

declare(strict_types=1);

namespace App\Services\Ops;

/**
 * Le unità systemd installate sono quelle del repository? (23/9/2026)
 *
 * Fino all'8 settembre 2026 le installava il rilascio: `deploy.sh`, passo 7,
 * confrontava ogni `.service`, `.timer` e `.path` di `tools/systemd` con quello
 * in `/etc/systemd/system`, copiava i diversi e accendeva i timer. Il rilascio a
 * container (`deploy-container.sh`) non lo fa più, e nessuno se n'era accorto:
 * una correzione a un timer o al contenimento di un'unità, unita e verde,
 * restava nel repository finché qualcuno non la copiava a mano, e niente diceva
 * che non l'aveva copiata (A-14 e R-10 della revisione architetturale del
 * 23/9/2026). `tools/ci/check-deploy-units.mjs` legge solo il repository.
 *
 * Il rilascio continua a non installarle (ADR-048, «Che cosa il rilascio non
 * installa»), ma adesso lo scarto si vede: il controllo `unita` della
 * diagnostica chiama questa classe due volte al giorno sull'host.
 *
 * Le risposte, per ogni file:
 *
 *   - **uguale**: stesso contenuto, byte per byte;
 *   - **diversa**: installata con un altro contenuto;
 *   - **non installata**: nel repository sì, sul server no;
 *   - **spenta**: un timer o un path installato senza il collegamento in
 *     `<bersaglio>.wants/` che `systemctl enable` crea. Non parte mai: la
 *     pulizia di `rate_limits` installata e non accesa lascerebbe falso il
 *     registro dei trattamenti esattamente come se non ci fosse;
 *   - **orfana**: un'unità `pantedu-*` installata che il repository non ha più.
 *     Il timer di una fonte tolta continua a girare, e a fallire;
 *   - **non ho potuto confrontare**: il file o la cartella c'è ma non si legge,
 *     oppure la cartella non c'è. **Non è un «uguale»**: `glob()` e
 *     `file_get_contents()` rispondono vuoto sia a «non c'è niente» sia a «non
 *     posso guardare», e confonderli è il verde che non ha guardato (è successo
 *     al controllo `lavori` il 9/9/2026, al primo giro come www-data).
 *
 * Gli script (`*.sh`) di `tools/systemd` non si confrontano: vanno in
 * `/usr/local/bin` o `/usr/local/sbin` con nomi propri, e li reinstalla
 * `tools/webhook/install_auto_deploy.sh`.
 *
 * Logica pura sui file: le prove la chiamano con cartelle finte
 * (`tests/Unit/Ops/UnitaInstallateTest.php`).
 */
final class UnitaInstallate
{
    /** I tipi che il vecchio rilascio installava (deploy.sh, passo 7). */
    private const TIPI = ['service', 'timer', 'path'];

    /** I tipi che si accendono con `systemctl enable`, e che quindi possono restare spenti. */
    private const SI_ACCENDONO = ['timer', 'path'];

    /**
     * @param string $sorgente   la cartella `tools/systemd` del repository
     * @param string $installate dove systemd le legge (`/etc/systemd/system`)
     * @return array{
     *     confrontabile: bool,
     *     perche: string,
     *     uguali: list<string>,
     *     diverse: list<string>,
     *     mancanti: list<string>,
     *     illeggibili: list<string>,
     *     spente: list<string>,
     *     orfane: list<string>,
     *     accese: int
     * }
     */
    public static function confronta(string $sorgente, string $installate): array
    {
        $esito = [
            'confrontabile' => false,
            'perche'        => '',
            'uguali'        => [],
            'diverse'       => [],
            'mancanti'      => [],
            'illeggibili'   => [],
            'spente'        => [],
            'orfane'        => [],
            'accese'        => 0,
        ];

        $nomi = self::elenco($sorgente);
        if ($nomi === null) {
            $esito['perche'] = "la cartella del repository $sorgente "
                . (is_dir($sorgente) ? 'non si legge' : 'non c’è');
            return $esito;
        }
        if ($nomi === []) {
            // Zero unità da confrontare darebbe zero differenze: un verde su
            // niente. Se il repository non ne ha, qualcosa è fuori posto.
            $esito['perche'] = "nessuna unità in $sorgente: non c’è niente da confrontare";
            return $esito;
        }
        if (!is_dir($installate)) {
            $esito['perche'] = "la cartella $installate non c’è";
            return $esito;
        }
        $presenti = self::voci($installate);
        if ($presenti === null) {
            $esito['perche'] = "la cartella $installate non si legge";
            return $esito;
        }
        $esito['confrontabile'] = true;

        foreach ($nomi as $rel) {
            $nostro = $sorgente . '/' . $rel;
            $loro = $installate . '/' . $rel;
            if (!file_exists($loro) && !is_link($loro)) {
                $esito['mancanti'][] = $rel;
                continue;
            }
            $a = is_readable($nostro) ? @file_get_contents($nostro) : false;
            $b = is_readable($loro) ? @file_get_contents($loro) : false;
            if ($a === false || $b === false) {
                $esito['illeggibili'][] = $rel;
                continue;
            }
            if ($a === $b) {
                $esito['uguali'][] = $rel;
            } else {
                $esito['diverse'][] = $rel;
            }

            // Accesa o spenta si chiede solo a chi è installata: una non
            // installata è già nell'elenco sopra, e contarla due volte
            // raddoppierebbe il rumore senza dire niente di più.
            $tipo = pathinfo($rel, PATHINFO_EXTENSION);
            if (str_contains($rel, '/') || !\in_array($tipo, self::SI_ACCENDONO, true)) {
                continue;
            }
            $bersagli = self::bersagli($a);
            if ($bersagli === []) {
                continue; // senza WantedBy= non si accende con enable: niente da chiedere
            }
            $accesa = false;
            foreach ($bersagli as $t) {
                $collegamento = "$installate/$t.wants/$rel";
                if (is_link($collegamento) || file_exists($collegamento)) {
                    $accesa = true;
                    break;
                }
            }
            if ($accesa) {
                $esito['accese']++;
            } else {
                $esito['spente'][] = $rel;
            }
        }

        // Le orfane: unità nostre (prefisso `pantedu-`) che systemd ha ancora e
        // il repository no. Solo il prefisso nostro: in quella cartella stanno
        // anche le unità del sistema, che non ci riguardano.
        $nostre = array_flip($nomi);
        foreach ($presenti as $nome) {
            $nostra = self::eUnita($nome) && str_starts_with($nome, 'pantedu-');
            if ($nostra && !isset($nostre[$nome]) && is_file($installate . '/' . $nome)) {
                $esito['orfane'][] = $nome;
            }
        }
        foreach (self::cartelleDropIn($sorgente) as $cartella) {
            foreach (self::voci($installate . '/' . $cartella) ?? [] as $nome) {
                $rel = $cartella . '/' . $nome;
                if (str_starts_with($nome, 'pantedu-') && str_ends_with($nome, '.conf') && !isset($nostre[$rel])) {
                    $esito['orfane'][] = $rel;
                }
            }
        }

        return $esito;
    }

    /**
     * Le unità del repository, e i drop-in (`<unità>.service.d/*.conf`) con il
     * loro percorso relativo. Null se la cartella non si legge.
     *
     * @return list<string>|null
     */
    private static function elenco(string $sorgente): ?array
    {
        $voci = self::voci($sorgente);
        if ($voci === null) {
            return null;
        }
        $nomi = [];
        foreach ($voci as $nome) {
            if (self::eUnita($nome) && is_file($sorgente . '/' . $nome)) {
                $nomi[] = $nome;
            }
        }
        foreach (self::cartelleDropIn($sorgente) as $cartella) {
            foreach (self::voci($sorgente . '/' . $cartella) ?? [] as $nome) {
                if (str_ends_with($nome, '.conf') && is_file("$sorgente/$cartella/$nome")) {
                    $nomi[] = "$cartella/$nome";
                }
            }
        }
        sort($nomi);
        return $nomi;
    }

    /** @return list<string> le cartelle `*.service.d` del repository */
    private static function cartelleDropIn(string $sorgente): array
    {
        $cartelle = [];
        foreach (self::voci($sorgente) ?? [] as $nome) {
            if (str_ends_with($nome, '.service.d') && is_dir($sorgente . '/' . $nome)) {
                $cartelle[] = $nome;
            }
        }
        return $cartelle;
    }

    /**
     * I nomi in una cartella, senza `.` e `..`. Null se non si legge: con
     * `scandir()` e non con `glob()`, che risponde vuoto anche quando non può
     * guardare. E senza `GLOB_BRACE`, che non c'è dappertutto.
     *
     * @return list<string>|null
     */
    private static function voci(string $cartella): ?array
    {
        if (!is_dir($cartella)) {
            return null;
        }
        $voci = @scandir($cartella);
        if ($voci === false) {
            return null;
        }
        return array_values(array_filter($voci, static fn(string $v): bool => $v !== '.' && $v !== '..'));
    }

    private static function eUnita(string $nome): bool
    {
        return \in_array(pathinfo($nome, PATHINFO_EXTENSION), self::TIPI, true);
    }

    /**
     * I bersagli di `WantedBy=` nella sezione `[Install]`: sono le cartelle
     * `<bersaglio>.wants/` dove `systemctl enable` mette il collegamento.
     *
     * @return list<string>
     */
    private static function bersagli(string $testo): array
    {
        $bersagli = [];
        $sezione = '';
        foreach (preg_split('/\R/', $testo) ?: [] as $riga) {
            $riga = trim($riga);
            if (preg_match('/^\[(.+)\]$/', $riga, $m)) {
                $sezione = $m[1];
                continue;
            }
            if ($sezione === 'Install' && preg_match('/^WantedBy\s*=\s*(.+)$/', $riga, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $t) {
                    if ($t !== '') {
                        $bersagli[] = $t;
                    }
                }
            }
        }
        return $bersagli;
    }
}
