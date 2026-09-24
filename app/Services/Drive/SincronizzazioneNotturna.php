<?php

declare(strict_types=1);

namespace App\Services\Drive;

/**
 * Il giro notturno della sincronizzazione con Drive (tools/cron/drive_sync_nightly.php).
 *
 * Sta in una classe per una ragione sola: dire la verità con il codice d'uscita.
 * Fino al 14 settembre 2026 lo script usciva sempre con zero. Almeno dal 20
 * maggio, a notti alterne, ogni mappa del docente collegato finiva in errore
 * («error=130» nel giornale) perché in produzione mancano le credenziali OAuth
 * di Google, e l'unità systemd risultava riuscita: nessun avviso, nessuna riga
 * fra le unità fallite. È la forma di guasto elencata in docs/ops/diagnostica.md,
 * un'altra volta.
 *
 * Dallo stesso giorno (ADR-038) l'avviso all'amministratore parte solo per
 * quello che può correggere lui, o il codice:
 *   - Drive spento sull'installazione (`DRIVE_ENABLED`): non c'è niente da
 *     fare, si dice e si esce con zero, anche se restano collegamenti di prima;
 *   - Drive acceso ma senza credenziali: guasto generale, si dice quali chiavi
 *     mancano e si esce con 1;
 *   - un docente da ricollegare (Google ha rifiutato il suo token): lo può
 *     correggere solo lui, e lo vede nel cruscotto; si scrive, e non è un
 *     errore;
 *   - tutti i docenti provati, almeno due, rifiutati nello stesso giro: è più
 *     probabile un guasto generale, per esempio l'app OAuth in modalità di
 *     prova, dove i token durano sette giorni. Si esce con 1;
 *   - un errore su una mappa, o un'eccezione su un docente: si dice il motivo
 *     e si esce con 1;
 *   - il giro fermato dal freno sul tempo con altri docenti ancora da fare:
 *     anche questo è 1, perché quei docenti stanotte restano indietro.
 */
final class SincronizzazioneNotturna
{
    /** Quanti motivi diversi si scrivono al più, per docente. */
    private const MOTIVI_SCRITTI = 5;

    /**
     * @param \Closure(int): array<string,mixed> $sincronizza il lavoro su un docente (syncAllForTeacher)
     * @param \Closure(string): void             $scrivi      una riga del resoconto
     * @param \Closure(string): void             $segnala     una riga d'errore
     * @param (\Closure(): int)|null             $orologio    i secondi; time() se manca
     */
    public function __construct(
        private readonly \Closure $sincronizza,
        private readonly \Closure $scrivi,
        private readonly \Closure $segnala,
        private readonly ?\Closure $orologio = null,
    ) {
    }

    /**
     * @param array<string,mixed> $drive                   la configurazione `drive`
     * @param list<int>           $docenti                 i collegamenti attivi da sincronizzare
     * @param int                 $collegamenti            quanti collegamenti ci sono, in ogni stato
     * @param int                 $limiteSecondiPerDocente oltre, il giro si ferma (sospetto limite di Drive)
     * @return int il codice d'uscita
     */
    public function esegui(array $drive, array $docenti, int $collegamenti, int $limiteSecondiPerDocente): int
    {
        $istanza = StatoDiDrive::dellInstallazione($drive);
        if ($istanza === StatoDiDrive::SPENTO) {
            ($this->scrivi)(
                'Drive è spento su questa installazione (DRIVE_ENABLED): niente da sincronizzare.'
                . match (true) {
                    $collegamenti === 0 => '',
                    $collegamenti === 1 => ' 1 collegamento di prima resta salvato'
                        . ' finché il docente non lo scollega dal cruscotto.',
                    default => " {$collegamenti} collegamenti di prima restano salvati"
                        . ' finché i docenti non li scollegano dal cruscotto.',
                }
            );
            return 0;
        }
        if ($istanza === StatoDiDrive::GUASTO) {
            $oauth = $drive['oauth'] ?? [];
            ($this->segnala)(sprintf(
                'Drive è acceso (DRIVE_ENABLED) ma mancano le credenziali OAuth (%s): %s. '
                . 'O si configurano, o si spegne Drive con DRIVE_ENABLED=false.',
                implode(', ', StatoDiDrive::credenzialiMancanti(is_array($oauth) ? $oauth : [])),
                match (true) {
                    $collegamenti === 0 => 'nessun docente può collegarsi',
                    $collegamenti === 1 => 'il docente collegato non si sincronizza',
                    default             => "i {$collegamenti} docenti collegati non si sincronizzano",
                },
            ));
            return 1;
        }

        $inizio = $this->adesso();
        $ok = 0;
        $saltate = 0;
        $errori = 0;
        $provati = 0;
        $daRicollegare = 0;
        $interrotto = false;
        foreach ($docenti as $posizione => $docente) {
            $inizioDocente = $this->adesso();
            $provati++;
            try {
                $esito = ($this->sincronizza)($docente);
            } catch (\Throwable $e) {
                ($this->segnala)(sprintf('teacher_id=%d EXCEPTION: %s', $docente, $e->getMessage()));
                $errori++;
                continue;
            }
            $durata = $this->adesso() - $inizioDocente;

            $fermato = (string)($esito['fermato'] ?? '');
            if ($fermato === 'drive_da_ricollegare') {
                $daRicollegare++;
                ($this->scrivi)(sprintf(
                    'teacher_id=%d da ricollegare (%s): il giro lo salta finché non ricollega,'
                    . ' e lui lo vede nel cruscotto',
                    $docente,
                    (string)($esito['motivo'] ?? 'motivo non detto'),
                ));
                continue;
            }
            if ($fermato !== '') {
                // Spento, guasto o scollegato a giro iniziato: qualcosa è cambiato sotto il giro.
                ($this->segnala)(sprintf('teacher_id=%d lotto fermato: %s', $docente, $fermato));
                $errori++;
                continue;
            }

            // Le orfane (riga senza file) non sono errori del giro, ma si dicono:
            // il 15/9/2026 erano 11, e contate come errori nascondevano il motivo.
            $orfane = (int)($esito['orphan'] ?? 0);
            ($this->scrivi)(sprintf(
                'teacher_id=%d count=%d ok=%d skip=%d error=%d%s (%ds)',
                $docente,
                (int)($esito['count'] ?? 0),
                (int)($esito['ok'] ?? 0),
                (int)($esito['skip'] ?? 0),
                (int)($esito['error'] ?? 0),
                $orfane > 0 ? " orphan={$orfane}" : '',
                $durata,
            ));
            $ok += (int)($esito['ok'] ?? 0);
            $saltate += (int)($esito['skip'] ?? 0);
            $errori += (int)($esito['error'] ?? 0);

            $motivi = self::motivi($esito);
            if ($motivi !== []) {
                ($this->segnala)(sprintf('teacher_id=%d motivi degli errori: %s', $docente, self::elenco($motivi)));
            }

            if ($durata > $limiteSecondiPerDocente) {
                $restano = count($docenti) - $posizione - 1;
                ($this->segnala)(sprintf(
                    'teacher_id=%d exceeded timeout (%ds > %ds) — aborting batch, %d teacher(s) left',
                    $docente,
                    $durata,
                    $limiteSecondiPerDocente,
                    $restano,
                ));
                // Chi resta non si sincronizza stanotte: è lavoro non fatto.
                $interrotto = $restano > 0;
                break;
            }
        }

        $tuttiRifiutati = $daRicollegare >= 2 && $daRicollegare === $provati;
        if ($tuttiRifiutati) {
            ($this->segnala)(sprintf(
                "Google ha rifiutato tutti i %d collegamenti provati stanotte: è più probabile un guasto generale "
                . "che %d docenti che revocano l'accesso insieme. Per esempio l'app OAuth in modalità di prova, "
                . "dove i token durano sette giorni, o il client OAuth cambiato in Google Cloud.",
                $daRicollegare,
                $daRicollegare,
            ));
        }

        ($this->scrivi)(sprintf(
            '%s done — total ok=%d skip=%d error=%d da_ricollegare=%d (elapsed=%ds)',
            date('Y-m-d H:i:s'),
            $ok,
            $saltate,
            $errori,
            $daRicollegare,
            $this->adesso() - $inizio,
        ));
        return $errori > 0 || $interrotto || $tuttiRifiutati ? 1 : 0;
    }

    /**
     * Quante mappe per motivo d'errore, dal più frequente. Le mappe orfane
     * (riga senza file) non sono errori del giro: il servizio le conta a parte.
     *
     * @param array<string,mixed> $esito il resoconto di MapSyncService::syncAllForTeacher
     * @return array<string,int>
     */
    public static function motivi(array $esito): array
    {
        $motivi = [];
        $voci = $esito['items'] ?? [];
        foreach (is_array($voci) ? $voci : [] as $voce) {
            if (!is_array($voce) || !isset($voce['error'])) {
                continue;
            }
            if (($voce['action'] ?? '') === MapSyncService::ACTION_ORPHAN) {
                continue;
            }
            $motivo = mb_substr((string)$voce['error'], 0, 160);
            $motivi[$motivo] = ($motivi[$motivo] ?? 0) + 1;
        }
        arsort($motivi);
        return $motivi;
    }

    /** @param array<string,int> $motivi */
    private static function elenco(array $motivi): string
    {
        $righe = [];
        foreach (array_slice($motivi, 0, self::MOTIVI_SCRITTI, true) as $motivo => $quante) {
            $righe[] = "{$motivo} ×{$quante}";
        }
        $altri = count($motivi) - self::MOTIVI_SCRITTI;
        if ($altri > 0) {
            $righe[] = "e altri {$altri} motivi";
        }
        return implode('; ', $righe);
    }

    private function adesso(): int
    {
        return $this->orologio !== null ? ($this->orologio)() : time();
    }
}
