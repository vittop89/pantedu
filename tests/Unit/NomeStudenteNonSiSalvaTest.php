<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PrintInfoService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Il nome di uno studente non arriva al database, nemmeno se il client lo manda.
 *
 * Il pannello delle verifiche ha un interruttore «verifica nominativa» che
 * scopre due campi dedicati al nome e al cognome di un alunno. Non sono campi
 * liberi in cui un nome può scappare: sono campi fatti per contenerlo. Fino al
 * 22/9/2026 il server li ammetteva e li scriveva **in chiaro** in
 * `print_info_data.extra_json`, sotto una chiave che porta istituto e sezione.
 *
 * Quattro documenti dicevano il contrario, fra cui la DPIA («non registrato in
 * DB») e R20 («nei campi strutturati nessun alunno è identificabile, per
 * costruzione»), e i Termini vietavano al docente proprio ciò che
 * l'interfaccia gli offriva con un bottone.
 *
 * Misurato in produzione prima di correggere: tre righe in tutto, zero con quei
 * campi valorizzati. Difetto latente, non incidente.
 *
 * Questa prova è la guardia perché non torni: la funzione può restare — la
 * verifica nominativa si stampa dal browser — ma il nome non si conserva.
 */
final class NomeStudenteNonSiSalvaTest extends TestCase
{
    /** @return list<string> */
    private static function campiSalvati(): array
    {
        $c = new ReflectionClass(PrintInfoService::class);

        /** @var list<string> $v */
        $v = $c->getConstant('SAVE_FIELDS');

        return $v;
    }

    #[Test]
    public function nome_e_cognome_non_sono_fra_i_campi_che_si_salvano(): void
    {
        $campi = self::campiSalvati();

        self::assertNotContains('nome', $campi,
            "il nome dello studente non deve essere fra i campi salvati: la DPIA dichiara che non è registrato in DB");
        self::assertNotContains('cognome', $campi,
            'e nemmeno il cognome');
    }

    #[Test]
    public function il_server_li_scarta_anche_se_il_client_li_manda(): void
    {
        // Il verso che conta: il client li raccoglie ancora, perché servono a
        // stampare. Il server deve buttarli via da sé, senza fidarsi.
        $m = new ReflectionMethod(PrintInfoService::class, 'normalize');
        $m->setAccessible(true);

        $pulito = $m->invoke(new PrintInfoService(), [
            'indirizzo' => 'SCI', 'classe' => '2A', 'materia' => 'MAT',
            'nome' => 'Mario', 'cognome' => 'Rossi',
            'verTitle' => 'Verifica di settembre',
        ], false, true);

        self::assertArrayNotHasKey('nome', $pulito, 'il nome non deve sopravvivere alla normalizzazione');
        self::assertArrayNotHasKey('cognome', $pulito, 'né il cognome');
        self::assertSame('Verifica di settembre', $pulito['verTitle'] ?? null,
            'il resto del modulo deve continuare a passare: una correzione che butta via tutto non serve');
    }

    #[Test]
    public function i_campi_legittimi_restano(): void
    {
        // Senza questo caso, togliere ogni campo dalla lista farebbe passare le
        // due prove sopra e romperebbe il salvataggio delle intestazioni.
        $campi = self::campiSalvati();

        foreach (['indirizzo', 'classe', 'materia', 'istituto', 'verTitle', 'nPrint'] as $atteso) {
            self::assertContains($atteso, $campi, "$atteso serve e deve restare fra i campi salvati");
        }
    }
}
