<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Risdoc;

use App\Services\Risdoc\StatoDelDocumento;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I campi che un documento risolve da sé.
 *
 * Segnalazione dell'utente (21/9/2026): «cliccando su "Campo" mi esce un popup
 * con il solo `sezione`, ma ci dovrebbero essere molti più campi — classe,
 * docente, disciplina, indirizzo…». L'editor suggeriva come esempi
 * `anno_scolastico` e `nome_docente`, che però non li riempiva nessuno: nel
 * PDF usciva il segnaposto.
 *
 * Qui si misura la regola che conta — quello che c'è già vince — e il
 * confine dell'anno scolastico, che è la cosa più facile da sbagliare.
 */
final class StatoDelDocumentoTest extends TestCase
{
    #[Test]
    public function l_anno_scolastico_comincia_a_settembre(): void
    {
        $anno = static fn(string $g): string =>
            StatoDelDocumento::annoScolastico(new DateTimeImmutable($g));

        self::assertSame('2025/2026', $anno('2026-08-31'), 'il 31 agosto si è ancora in quello vecchio');
        self::assertSame('2026/2027', $anno('2026-09-01'), 'il 1° settembre comincia il nuovo');
        self::assertSame('2026/2027', $anno('2026-09-21'));
        self::assertSame('2026/2027', $anno('2026-12-31'));
        self::assertSame('2026/2027', $anno('2027-01-01'), 'a gennaio l\'anno scolastico non cambia');
        self::assertSame('2026/2027', $anno('2027-06-30'));
    }

    #[Test]
    public function il_nome_del_docente_non_esce_mai_vuoto(): void
    {
        self::assertSame('Luca Bianchi', StatoDelDocumento::nomeDocente(
            ['first_name' => 'Luca', 'last_name' => 'Bianchi', 'username' => 'lbianchi']
        ));
        self::assertSame('Bianchi', StatoDelDocumento::nomeDocente(
            ['first_name' => '  ', 'last_name' => 'Bianchi', 'username' => 'lbianchi']
        ));
        self::assertSame('lbianchi', StatoDelDocumento::nomeDocente(
            ['first_name' => '', 'last_name' => '', 'username' => 'lbianchi']
        ), 'senza nome e cognome resta l\'username, mai una riga vuota');
    }

    #[Test]
    public function i_valori_ricavati_riempiono_solo_i_buchi(): void
    {
        $stato = StatoDelDocumento::arricchito(
            ['classe' => '3', 'nome_docente' => 'Chi ha firmato'],
            ['nome_docente' => 'Chi sta esportando', 'istituto' => 'IIS Esempio', 'anno_scolastico' => '2026/2027'],
        );

        self::assertSame('Chi ha firmato', $stato['nome_docente'], 'la scelta di una persona non si tocca');
        self::assertSame('IIS Esempio', $stato['istituto']);
        self::assertSame('2026/2027', $stato['anno_scolastico']);
        self::assertSame('3', $stato['classe'], 'il contesto resta dov\'era');
    }

    #[Test]
    public function un_valore_vuoto_non_conta_come_scelta(): void
    {
        // La pagina manda `sede => ''` quando non l'ha: è un buco, non una
        // decisione, e il valore ricavato lo riempie.
        $stato = StatoDelDocumento::arricchito(
            ['sede' => '', 'istituto' => '   '],
            ['sede' => 'Bari', 'istituto' => 'IIS Esempio'],
        );

        self::assertSame('Bari', $stato['sede']);
        self::assertSame('IIS Esempio', $stato['istituto']);
    }

    #[Test]
    public function un_valore_ricavato_vuoto_non_entra(): void
    {
        // Meglio il segnaposto, che si vede, di una riga sparita nel PDF.
        $stato = StatoDelDocumento::arricchito([], ['istituto' => '', 'sede' => '   ']);

        self::assertArrayNotHasKey('istituto', $stato);
        self::assertArrayNotHasKey('sede', $stato);
    }

    #[Test]
    public function chi_scrive_field_docente_ottiene_il_nome_del_docente(): void
    {
        $stato = StatoDelDocumento::arricchito([], ['nome_docente' => 'Luca Bianchi']);

        self::assertSame('Luca Bianchi', $stato['docente'], 'alias di cortesia');
        self::assertSame('Luca Bianchi', $stato['nome_docente']);
    }

    #[Test]
    public function l_alias_non_sovrascrive_quello_che_c_era(): void
    {
        $stato = StatoDelDocumento::arricchito(
            ['docente' => 'Il docente di classe'],
            ['nome_docente' => 'Chi esporta'],
        );

        self::assertSame('Il docente di classe', $stato['docente']);
    }

    #[Test]
    public function l_editor_propone_esattamente_i_campi_che_il_server_risolve(): void
    {
        // Se qualcuno ne aggiunge uno di qua e non di là, l'editor propone un
        // campo che nel PDF esce come segnaposto (o tiene nascosto un campo
        // che funziona). Lo dice questa prova, non una persona che guarda un
        // PDF con scritto «[field-istituto]».
        $js = (string)file_get_contents(\dirname(__DIR__, 4) . '/js/modules/risdoc/campi-del-documento.js');

        $estrai = static function (string $costante) use ($js): array {
            if (!preg_match('/export const ' . $costante . ' = \[([^\]]*)\]/', $js, $m)) {
                self::fail("nel modulo JS non c'è $costante");
            }
            preg_match_all('/"([a-z_]+)"/', $m[1], $n);
            $v = $n[1];
            sort($v);
            return $v;
        };

        $contesto = StatoDelDocumento::CONTESTO;
        $derivati = StatoDelDocumento::DERIVATI;
        sort($contesto);
        sort($derivati);

        self::assertSame($contesto, $estrai('CAMPI_DI_CONTESTO'));
        self::assertSame($derivati, $estrai('CAMPI_DERIVATI'));
    }
}
