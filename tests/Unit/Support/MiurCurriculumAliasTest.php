<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MiurCurriculumAlias;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MiurCurriculumAliasTest extends TestCase
{
    private function alias(): MiurCurriculumAlias
    {
        return new MiurCurriculumAlias([
            ['miur' => 'LICEO ARTISTICO - BIENNIO COMUNE', 'code' => 'ART', 'label' => 'Artistico'],
            ['miur' => 'L. ART. IND. ARTI FIGU.( CURV. ARTE PLASTICO PITTO', 'code' => 'AFI',
             'label' => 'Artistico — arti figurative'],
            ['miur' => 'SCIENTIFICO - SEZIONE AD INDIRIZZO SPORTIVO', 'code' => 'SCS'],
            ['miur' => 'LICEO MUSICALE - SEZ. MUSICALE', 'label' => 'Musicale'],
        ]);
    }

    #[Test]
    public function il_confronto_ignora_maiuscole_e_punteggiatura(): void
    {
        // Le descrizioni MIUR cambiano forma fra un dataset e l'altro: se il
        // registro dipendesse dalla punteggiatura esatta smetterebbe di valere
        // al primo aggiornamento del file.
        $a = $this->alias();
        foreach ([
            'LICEO ARTISTICO - BIENNIO COMUNE',
            'liceo artistico, biennio comune',
            'Liceo Artistico — Biennio Comune',
        ] as $variante) {
            $this->assertSame('ART', $a->lookup($variante)['code'] ?? null, $variante);
        }
    }

    #[Test]
    public function l_etichetta_sostituisce_la_descrizione_quando_c_e(): void
    {
        $v = $this->alias()->lookup('L. ART. IND. ARTI FIGU.( CURV. ARTE PLASTICO PITTO');
        $this->assertSame('AFI', $v['code']);
        $this->assertSame('Artistico — arti figurative', $v['label']);
    }

    #[Test]
    public function senza_etichetta_resta_null_e_il_chiamante_usa_la_descrizione(): void
    {
        $this->assertNull($this->alias()->lookup('SCIENTIFICO - SEZIONE AD INDIRIZZO SPORTIVO')['label']);
    }

    #[Test]
    public function una_voce_senza_codice_viene_scartata(): void
    {
        // Il codice e' l'identita': senza, al secondo import l'etichetta
        // riscritta non somiglia piu' alla descrizione MIUR e la riga non si
        // ritrova — nascerebbe un doppione accanto a quella gia' creata.
        $this->assertNull($this->alias()->lookup('LICEO MUSICALE - SEZ. MUSICALE'));
    }

    #[Test]
    public function una_descrizione_sconosciuta_non_produce_alias(): void
    {
        $this->assertNull($this->alias()->lookup('LICEO SCIENTIFICO'));
    }

    #[Test]
    public function le_voci_malformate_vengono_scartate(): void
    {
        // Un code che non rispetta ^[A-Z]{3,6}$ verrebbe poi rifiutato da
        // CurriculumService::add(): meglio ignorarlo qui che fallire all'INSERT.
        $a = new MiurCurriculumAlias([
            ['miur' => 'X', 'code' => 'troppo-lungo-e-minuscolo'],
            ['miur' => '',  'code' => 'ART'],
            ['code' => 'ART'],
            ['miur' => 'SOLO ETICHETTA', 'label' => 'Senza codice'],
        ]);
        $this->assertSame(0, $a->count());
    }

    #[Test]
    public function segnala_quali_codici_unificano_piu_descrizioni(): void
    {
        // Non e' un errore — unificare e' il caso d'uso — ma chi rilegge il
        // registro deve vederlo invece di scoprirlo dai dati.
        $a = new MiurCurriculumAlias([
            ['miur' => 'LICEO ARTISTICO - BIENNIO COMUNE', 'code' => 'ART'],
            ['miur' => 'LICEO ARTISTICO', 'code' => 'ART'],
            ['miur' => 'LICEO SCIENTIFICO', 'code' => 'SCI'],
        ]);
        $u = $a->unificazioni();
        $this->assertArrayHasKey('ART', $u);
        $this->assertArrayNotHasKey('SCI', $u);
        $this->assertCount(2, $u['ART']);
    }

    #[Test]
    public function il_registro_reale_del_progetto_e_valido(): void
    {
        $a = MiurCurriculumAlias::fromFile('indirizzi');
        $this->assertGreaterThan(0, $a->count(), 'docs/curriculum/miur_alias.json deve caricare');
        $this->assertSame('SCS', $a->lookup('SCIENTIFICO - SEZIONE AD INDIRIZZO SPORTIVO')['code'] ?? null);
    }

    #[Test]
    public function il_registro_reale_ha_anche_le_materie(): void
    {
        $m = MiurCurriculumAlias::fromFile('materie');
        $this->assertGreaterThan(0, $m->count());
        // GEO a registro e' "Geometria": Storia e geografia non puo' prenderselo.
        $this->assertSame('STG', $m->lookup('STORIA E GEOGRAFIA')['code'] ?? null);
        $this->assertNotSame('GEO', $m->lookup('STORIA E GEOGRAFIA')['code'] ?? null);
    }

    #[Test]
    public function i_kind_hanno_registri_separati(): void
    {
        // Una descrizione di indirizzo non deve risolversi fra le materie.
        $this->assertNull(MiurCurriculumAlias::fromFile('materie')->lookup('LICEO ARTISTICO - BIENNIO COMUNE'));
        $this->assertNull(MiurCurriculumAlias::fromFile('indirizzi')->lookup('STORIA E GEOGRAFIA'));
    }

    #[Test]
    public function un_file_assente_non_e_un_errore(): void
    {
        // Gli alias sono rifiniture: senza, il derivatore funziona lo stesso.
        $this->assertSame(0, MiurCurriculumAlias::fromFile('indirizzi', sys_get_temp_dir() . '/non_esiste_' . uniqid() . '.json')->count());
    }
}
