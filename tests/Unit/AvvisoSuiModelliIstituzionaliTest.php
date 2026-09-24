<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Risdoc\AvvisoCompilazione;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il divieto di scrivere dati degli studenti si legge mentre si compila.
 *
 * I Termini lo dicono già, ma li legge chi si iscrive — una volta — e chi
 * scrive una relazione finale sei mesi dopo non li ha davanti. L'avviso non
 * impedisce niente (il testo libero resta libero: è R20 della DPIA), e sposta
 * l'unica cosa che possa spostare: chi scrive un dato vietato l'ha fatto dopo
 * averlo letto lì.
 *
 * Le due direzioni, che è il punto di questa prova:
 *   — compare dove deve (modello istituzionale, senza account studente);
 *   — NON compare altrove, e in particolare **non** nello scenario 3, dove
 *     l'Istituto è Titolare, gli studenti sono interessati che la piattaforma
 *     conosce e la stessa frase sarebbe falsa.
 *
 * E una terza, che è quella che si romperebbe in silenzio: dove il salvataggio
 * è spento, l'avviso non deve promettere uno svuotamento che non avviene —
 * sarebbe una rassicurazione a vuoto, cioè il difetto che questo progetto
 * insegue.
 */
final class AvvisoSuiModelliIstituzionaliTest extends TestCase
{
    private const ISTITUZIONALE = true;
    private const ALTRA_CATEGORIA = false;
    private const CON_ACCOUNT_STUDENTE = true;
    private const SENZA_ACCOUNT_STUDENTE = false;
    private const SI_SALVA = true;
    private const NON_SI_SALVA = false;

    #[Test]
    public function compare_sul_modello_istituzionale_senza_account_studente(): void
    {
        $avviso = AvvisoCompilazione::perModello(
            self::ISTITUZIONALE,
            self::SENZA_ACCOUNT_STUDENTE,
            self::SI_SALVA
        );

        self::assertNotNull($avviso, 'il modello istituzionale deve avvisare');
        self::assertStringContainsString('Non inserire dati personali degli studenti', $avviso);
        self::assertStringContainsString('non un atto della scuola', $avviso);
    }

    /**
     * La controprova della mezza verità: si dice anche ciò che lo scrubber
     * NON copre, altrimenti l'avviso rassicura invece di avvisare.
     */
    #[Test]
    public function dove_si_salva_dice_anche_che_il_testo_libero_non_e_protetto(): void
    {
        $avviso = (string)AvvisoCompilazione::perModello(
            self::ISTITUZIONALE,
            self::SENZA_ACCOUNT_STUDENTE,
            self::SI_SALVA
        );

        self::assertStringContainsString('svuotati al salvataggio', $avviso);
        self::assertStringContainsString('il testo libero no', $avviso);
    }

    #[Test]
    public function dove_non_si_salva_non_promette_uno_svuotamento_che_non_avviene(): void
    {
        $avviso = (string)AvvisoCompilazione::perModello(
            self::ISTITUZIONALE,
            self::SENZA_ACCOUNT_STUDENTE,
            self::NON_SI_SALVA
        );

        self::assertStringContainsString('non si salva sul server', $avviso);
        self::assertStringNotContainsString(
            'svuotati al salvataggio',
            $avviso,
            'dove non si salva niente, parlare di campi svuotati al salvataggio rassicura a vuoto'
        );
    }

    /**
     * @return array<string, array{0:bool, 1:bool, 2:bool}>
     */
    public static function casiSenzaAvviso(): array
    {
        return [
            'categoria non istituzionale'    => [self::ALTRA_CATEGORIA, self::SENZA_ACCOUNT_STUDENTE, self::SI_SALVA],
            'scenario 3, con account studente' => [self::ISTITUZIONALE, self::CON_ACCOUNT_STUDENTE, self::SI_SALVA],
            'non istituzionale e scenario 3' => [self::ALTRA_CATEGORIA, self::CON_ACCOUNT_STUDENTE, self::NON_SI_SALVA],
        ];
    }

    #[Test]
    public function non_compare_dove_non_deve(): void
    {
        foreach (self::casiSenzaAvviso() as $caso => [$istituzionale, $accountStudente, $siSalva]) {
            self::assertNull(
                AvvisoCompilazione::perModello($istituzionale, $accountStudente, $siSalva),
                "l'avviso non deve comparire: {$caso}"
            );
        }
    }

    /**
     * La funzione può essere giusta e non essere chiamata da nessuno: questa
     * guarda che il controller le passi le fonti vere e infili il risultato
     * nella pagina. È la parte che si rompe in silenzio se qualcuno riscrive
     * l'heredoc.
     */
    #[Test]
    public function il_controller_la_chiama_con_le_fonti_vere_e_stampa_il_risultato(): void
    {
        $s = (string)file_get_contents(
            dirname(__DIR__, 2) . '/app/Controllers/Risdoc/TemplateViewController.php'
        );

        self::assertStringContainsString('AvvisoCompilazione::perModello(', $s);
        self::assertStringContainsString('CompilationStoragePolicy::isInstitutional($tmpl)', $s);
        self::assertStringContainsString('DeploymentScenario::studentAccountsEnabled()', $s);
        self::assertStringContainsString('CompilationStoragePolicy::allowedFor($tid, $tmpl)', $s);
        self::assertStringContainsString(
            '{$avvisoHtml}',
            $s,
            "l'avviso è calcolato ma non finisce nella pagina"
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // La seconda frase: quanto dura la bozza (ADR-046, 22/9/2026)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Dove non si salva non c'è niente che scada, e dirlo sarebbe una
     * minaccia inventata.
     */
    #[Test]
    public function dove_non_si_salva_non_si_parla_di_scadenza(): void
    {
        self::assertNull(AvvisoCompilazione::scadenza(false));
    }

    /**
     * E dove si salva la frase c'è, con i numeri veri — non una copia scritta
     * a mano che il giorno dopo diverge dal lavoro notturno.
     */
    #[Test]
    public function dove_si_salva_dice_i_giorni_veri(): void
    {
        $testo = AvvisoCompilazione::scadenza(true);

        self::assertNotNull($testo);
        self::assertStringContainsString(
            (string)\App\Services\Risdoc\ScadenzaDelleBozze::GRAZIA_GIORNI . ' giorni',
            $testo,
            'i giorni devono essere quelli che applica la spazzata'
        );
        self::assertStringContainsString(
            (string)\App\Services\Risdoc\ScadenzaDelleBozze::RETE_GIORNO . ' agosto',
            $testo
        );
        self::assertStringContainsString(
            'ogni modifica fa ripartire il conto',
            $testo,
            'è la parte che rende la regola accettabile: va detta'
        );
        self::assertStringContainsString(
            'Il modello resta',
            $testo,
            'altrimenti il docente crede di perdere anche il modello'
        );
    }

    /**
     * Vale per QUALUNQUE compilazione salvata, non solo per le istituzionali:
     * il lavoro notturno non distingue, e la pagina non deve distinguere.
     */
    #[Test]
    public function la_scadenza_non_dipende_dal_tipo_di_modello(): void
    {
        self::assertSame(
            AvvisoCompilazione::scadenza(true),
            AvvisoCompilazione::scadenza(true),
            "la frase dipende solo dal salvataggio: se un giorno prendesse altri argomenti, questa riga va ripensata"
        );
    }

    #[Test]
    public function anche_la_scadenza_finisce_nella_pagina(): void
    {
        $s = (string)file_get_contents(
            dirname(__DIR__, 2) . '/app/Controllers/Risdoc/TemplateViewController.php'
        );

        self::assertStringContainsString('AvvisoCompilazione::scadenza($siSalva)', $s);
        self::assertStringContainsString(
            'foreach ([$avviso, $scadenza] as $riga)',
            $s,
            'le due frasi si stampano tutte e due, o la seconda è codice morto'
        );
    }
}
