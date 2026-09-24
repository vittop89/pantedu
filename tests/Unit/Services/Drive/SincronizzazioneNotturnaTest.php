<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Drive;

use App\Services\Drive\MapSyncService;
use App\Services\Drive\SincronizzazioneNotturna;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il giro notturno di Drive dice la verità con il codice d'uscita (14/9/2026).
 *
 * In produzione ogni mappa finiva in errore da maggio, per le credenziali
 * OAuth assenti, e lo script usciva con zero: l'unità risultava riuscita e
 * nessun avviso partiva. Dallo stesso giorno (ADR-038) l'avviso parte solo per
 * i guasti che non può correggere il docente. Ogni caso qui è provato nei due
 * versi: esce con 1 quando qualcosa non va, e con 0 quando non c'è niente che
 * non vada.
 */
final class SincronizzazioneNotturnaTest extends TestCase
{
    private const CREDENZIALI = ['client_id' => 'id-di-prova', 'client_secret' => 'segreto-di-prova'];
    private const ACCESO = ['enabled' => true, 'oauth' => self::CREDENZIALI];
    private const SPENTO = ['enabled' => false, 'oauth' => self::CREDENZIALI];
    private const GUASTO = ['enabled' => true, 'oauth' => ['client_id' => '', 'client_secret' => '']];

    /** @var list<string> */
    private array $resoconto = [];

    /** @var list<string> */
    private array $errori = [];

    /** @var list<int> */
    private array $sincronizzati = [];

    /**
     * @param array<int, array<string,mixed>|\Throwable> $esiti per docente
     * @param (\Closure(): int)|null $orologio
     */
    private function giro(array $esiti = [], ?\Closure $orologio = null): SincronizzazioneNotturna
    {
        return new SincronizzazioneNotturna(
            sincronizza: function (int $docente) use ($esiti): array {
                $this->sincronizzati[] = $docente;
                $esito = $esiti[$docente] ?? ['count' => 0, 'ok' => 0, 'skip' => 0, 'error' => 0, 'items' => []];
                if ($esito instanceof \Throwable) {
                    throw $esito;
                }
                return $esito;
            },
            scrivi: function (string $riga): void {
                $this->resoconto[] = $riga;
            },
            segnala: function (string $riga): void {
                $this->errori[] = $riga;
            },
            // Un orologio fermo, se la prova non ne dà uno: con time() una durata
            // può diventare «1s» a cavallo di un secondo.
            orologio: $orologio ?? static fn(): int => 1_000,
        );
    }

    /**
     * @param list<array{0:string,1?:string}> $mappe azione e, per gli errori, il motivo
     * @return array<string,mixed>
     */
    private static function esito(array $mappe): array
    {
        $esito = ['count' => count($mappe), 'ok' => 0, 'skip' => 0, 'error' => 0, 'orphan' => 0, 'items' => []];
        foreach ($mappe as $i => $mappa) {
            $voce = ['id' => $i + 1, 'action' => $mappa[0]];
            if (isset($mappa[1])) {
                $voce['error'] = $mappa[1];
            }
            match ($mappa[0]) {
                MapSyncService::ACTION_FAILED => $esito['error']++,
                MapSyncService::ACTION_ORPHAN => $esito['orphan']++,
                MapSyncService::ACTION_SKIPPED => $esito['skip']++,
                default => $esito['ok']++,
            };
            $esito['items'][] = $voce;
        }
        return $esito;
    }

    /**
     * Il resoconto di MapSyncService quando il lotto si ferma su tutto il docente.
     *
     * @return array<string,mixed>
     */
    private static function fermato(string $codice, ?string $motivo = null): array
    {
        $esito = ['count' => 130, 'ok' => 0, 'skip' => 0, 'error' => 0, 'orphan' => 0, 'items' => [], 'fermato' => $codice];
        if ($motivo !== null) {
            $esito['motivo'] = $motivo;
        }
        return $esito;
    }

    #[Test]
    public function con_drive_spento_esce_con_zero_senza_provare_nessuno_anche_con_collegamenti_di_prima(): void
    {
        $codice = $this->giro()->esegui(self::SPENTO, [77], 1, 300);

        $this->assertSame(0, $codice, "un'installazione senza Drive non è un guasto");
        $this->assertSame([], $this->sincronizzati, 'nessun docente provato');
        $this->assertSame([], $this->errori);
        $this->assertStringContainsString('Drive è spento', $this->resoconto[0] ?? '');
        $this->assertStringContainsString('1 collegamento di prima resta salvato', $this->resoconto[0] ?? '');
    }

    #[Test]
    public function con_drive_spento_e_nessun_collegamento_non_parla_di_collegamenti(): void
    {
        $this->assertSame(0, $this->giro()->esegui(self::SPENTO, [], 0, 300));
        $this->assertStringNotContainsString('collegament', $this->resoconto[0] ?? '');
    }

    #[Test]
    public function con_drive_acceso_senza_credenziali_non_prova_nemmeno_ed_esce_con_uno(): void
    {
        $codice = $this->giro()->esegui(self::GUASTO, [77], 1, 300);

        $this->assertSame(1, $codice, "l'unità fallisce, e parte l'avviso");
        $this->assertSame([], $this->sincronizzati, 'nessuna mappa provata: fallirebbero tutte');
        $this->assertCount(1, $this->errori);
        $this->assertStringContainsString('il docente collegato non si sincronizza', $this->errori[0]);
        $this->assertStringContainsString('GOOGLE_DRIVE_CLIENT_ID, GOOGLE_DRIVE_CLIENT_SECRET', $this->errori[0], 'e dice quali chiavi mancano');
        $this->assertStringContainsString('DRIVE_ENABLED=false', $this->errori[0], 'e come si spegne, se non si usa');
    }

    #[Test]
    public function con_drive_acceso_senza_credenziali_e_un_guasto_anche_senza_collegamenti(): void
    {
        $this->assertSame(1, $this->giro()->esegui(self::GUASTO, [], 0, 300));
        $this->assertStringContainsString('nessun docente può collegarsi', $this->errori[0] ?? '');
    }

    #[Test]
    public function con_drive_acceso_e_nessun_docente_da_sincronizzare_esce_con_zero(): void
    {
        $codice = $this->giro()->esegui(self::ACCESO, [], 0, 300);

        $this->assertSame(0, $codice, 'niente da fare non è un guasto');
        $this->assertSame([], $this->errori);
        $this->assertStringContainsString('total ok=0 skip=0 error=0 da_ricollegare=0', $this->resoconto[0] ?? '');
    }

    #[Test]
    public function tutto_riuscito_esce_con_zero_senza_errori(): void
    {
        $esito = self::esito([[MapSyncService::ACTION_CREATED], [MapSyncService::ACTION_UPDATED], [MapSyncService::ACTION_SKIPPED]]);

        $codice = $this->giro([5 => $esito])->esegui(self::ACCESO, [5], 1, 300);

        $this->assertSame(0, $codice);
        $this->assertSame([5], $this->sincronizzati);
        $this->assertSame([], $this->errori);
        $this->assertSame('teacher_id=5 count=3 ok=2 skip=1 error=0 (0s)', $this->resoconto[0]);
        $this->assertStringContainsString('total ok=2 skip=1 error=0', $this->resoconto[1]);
    }

    #[Test]
    public function un_errore_su_una_mappa_esce_con_uno_e_ne_dice_il_motivo(): void
    {
        $esito = self::esito([
            [MapSyncService::ACTION_FAILED, 'sync_failed: quota'],
            [MapSyncService::ACTION_CREATED],
            [MapSyncService::ACTION_FAILED, 'sync_failed: quota'],
            [MapSyncService::ACTION_FAILED, 'not_found'],
            [MapSyncService::ACTION_ORPHAN, 'blob_orphan'],
        ]);

        $codice = $this->giro([5 => $esito])->esegui(self::ACCESO, [5], 1, 300);

        $this->assertSame(1, $codice, "tre mappe in errore: l'unità fallisce");
        $this->assertSame(
            ['teacher_id=5 motivi degli errori: sync_failed: quota ×2; not_found ×1'],
            $this->errori,
            'i motivi dal più frequente, senza le orfane, che non sono errori del giro',
        );
    }

    #[Test]
    public function le_mappe_orfane_da_sole_non_fanno_fallire_il_giro(): void
    {
        $esito = self::esito([[MapSyncService::ACTION_ORPHAN, 'blob_orphan'], [MapSyncService::ACTION_CREATED]]);

        $codice = $this->giro([5 => $esito])->esegui(self::ACCESO, [5], 1, 300);

        $this->assertSame(0, $codice);
        $this->assertSame([], $this->errori);
        $this->assertSame('teacher_id=5 count=2 ok=1 skip=0 error=0 orphan=1 (0s)', $this->resoconto[0], 'ma si dicono');
    }

    #[Test]
    public function un_eccezione_su_un_docente_esce_con_uno_e_prosegue_con_gli_altri(): void
    {
        $giro = $this->giro([
            1 => new \RuntimeException('drive_oauth_not_connected'),
            2 => self::esito([[MapSyncService::ACTION_CREATED]]),
        ]);

        $codice = $giro->esegui(self::ACCESO, [1, 2], 2, 300);

        $this->assertSame(1, $codice);
        $this->assertSame([1, 2], $this->sincronizzati, 'il secondo docente si sincronizza lo stesso');
        $this->assertSame(['teacher_id=1 EXCEPTION: drive_oauth_not_connected'], $this->errori);
    }

    #[Test]
    public function un_docente_da_ricollegare_non_e_un_errore_e_il_resoconto_lo_dice(): void
    {
        $giro = $this->giro([
            5 => self::fermato('drive_da_ricollegare', 'accesso_revocato'),
            6 => self::esito([[MapSyncService::ACTION_CREATED]]),
        ]);

        $codice = $giro->esegui(self::ACCESO, [5, 6], 2, 300);

        $this->assertSame(0, $codice, "lo può correggere solo il docente: all'amministratore non arriva niente");
        $this->assertSame([], $this->errori);
        $this->assertSame([5, 6], $this->sincronizzati, 'il secondo docente si sincronizza');
        $this->assertStringContainsString('teacher_id=5 da ricollegare (accesso_revocato)', $this->resoconto[0] ?? '');
        $this->assertStringContainsString('da_ricollegare=1', (string)end($this->resoconto));
    }

    #[Test]
    public function tutti_i_docenti_rifiutati_insieme_sono_un_guasto_generale(): void
    {
        $giro = $this->giro([
            5 => self::fermato('drive_da_ricollegare', 'accesso_revocato'),
            6 => self::fermato('drive_da_ricollegare', 'accesso_revocato'),
        ]);

        $codice = $giro->esegui(self::ACCESO, [5, 6], 2, 300);

        $this->assertSame(1, $codice, 'due docenti che revocano nella stessa notte: più probabile un guasto');
        $this->assertStringContainsString('tutti i 2 collegamenti', $this->errori[0] ?? '');
    }

    #[Test]
    public function un_solo_docente_rifiutato_non_basta_per_dire_guasto_generale(): void
    {
        $codice = $this->giro([5 => self::fermato('drive_da_ricollegare', 'accesso_revocato')])->esegui(self::ACCESO, [5], 1, 300);

        $this->assertSame(0, $codice, 'con un docente solo non si distingue: lo vede lui nel cruscotto');
        $this->assertSame([], $this->errori);
    }

    #[Test]
    public function un_lotto_fermato_per_un_altra_ragione_e_un_errore(): void
    {
        $codice = $this->giro([5 => self::fermato('drive_guasto')])->esegui(self::ACCESO, [5], 1, 300);

        $this->assertSame(1, $codice, 'Drive guasto a giro iniziato: qualcosa è cambiato sotto il giro');
        $this->assertSame(['teacher_id=5 lotto fermato: drive_guasto'], $this->errori);
    }

    /** Un orologio che avanza di 200 secondi a ogni lettura. */
    private static function orologioLento(): \Closure
    {
        $secondi = 0;
        return static function () use (&$secondi): int {
            $adesso = $secondi;
            $secondi += 200;
            return $adesso;
        };
    }

    #[Test]
    public function oltre_il_tempo_per_docente_il_giro_si_ferma_ed_esce_con_uno_se_resta_qualcuno(): void
    {
        $giro = $this->giro([
            1 => self::esito([[MapSyncService::ACTION_CREATED]]),
            2 => self::esito([[MapSyncService::ACTION_CREATED]]),
        ], self::orologioLento());

        $codice = $giro->esegui(self::ACCESO, [1, 2], 2, 100);

        $this->assertSame([1], $this->sincronizzati, 'il secondo docente non parte');
        $this->assertStringContainsString('exceeded timeout (200s > 100s) — aborting batch, 1 teacher(s) left', $this->errori[0] ?? '');
        $this->assertSame(1, $codice, 'chi resta stanotte non si sincronizza: lavoro non fatto');
    }

    #[Test]
    public function oltre_il_tempo_sull_ultimo_docente_non_resta_niente_da_fare_ed_esce_con_zero(): void
    {
        $giro = $this->giro([1 => self::esito([[MapSyncService::ACTION_CREATED]])], self::orologioLento());

        $codice = $giro->esegui(self::ACCESO, [1], 1, 100);

        $this->assertSame([1], $this->sincronizzati);
        $this->assertStringContainsString('0 teacher(s) left', $this->errori[0] ?? '');
        $this->assertSame(0, $codice, 'lento ma finito: nessuno è rimasto indietro');
    }

    #[Test]
    public function i_motivi_scritti_sono_al_piu_cinque(): void
    {
        $mappe = [];
        foreach (range(1, 7) as $n) {
            $mappe[] = [MapSyncService::ACTION_FAILED, "motivo {$n}"];
        }

        $this->giro([5 => self::esito($mappe)])->esegui(self::ACCESO, [5], 1, 300);

        $this->assertStringContainsString('e altri 2 motivi', $this->errori[0] ?? '');
        $this->assertSame(7, count(SincronizzazioneNotturna::motivi(self::esito($mappe))));
    }
}
