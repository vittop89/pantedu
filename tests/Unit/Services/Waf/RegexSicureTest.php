<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Waf;

use App\Services\Waf\WafRulesService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le regole del WAF non accettano pattern che esplodono (23/9/2026).
 *
 * `WafRulesService::isRegexConditionSafe()` è la convalida al salvataggio di
 * una condizione `matches_regex`: un pattern con backtracking catastrofico,
 * provato su ogni richiesta, terrebbe occupato un worker di PHP.
 *
 * Fino all'8/9/2026 finiva con `safeRegexMatch($pattern, $bad) !== null`: un
 * booleano confrontato con `null`, cioè **sempre vero**. La funzione che doveva
 * rifiutare `(a+)+$` approvava tutto, e nessuna prova se ne è accorta: l'ha
 * trovato l'aggiornamento di PHPStan (registro del debito, voce 84). Da allora
 * la correzione non aveva una prova (revisione del 23/9/2026, A-24).
 *
 * I due versi, perché valgono tutti e due:
 *   - i pattern catastrofici si rifiutano: il «sempre vero» li approverebbe;
 *   - i pattern normali si accettano: un confronto con `false` al posto di
 *     `null` li rifiuterebbe quasi tutti, perché sull'input di prova di sole
 *     «a» un pattern normale non trova niente, e «niente» e «esploso» dal
 *     valore di ritorno non si distinguono (è il commento nel codice).
 *
 * Un limite, misurato e non corretto qui: l'input di prova è fatto di «a», e
 * un pattern che esplode solo su altri caratteri — `(\d+)+$`, `(b+)+$` — la
 * convalida lo accetta. A tenerlo a bada resta il limite di backtracking che
 * `safeRegexMatch()` stringe a ogni richiesta, provato in fondo: la ricerca si
 * ferma e risponde «nessun riscontro».
 *
 * I limiti di PCRE sono del processo, e le prove girano in ordine casuale: si
 * fissano a valori noti prima di ogni prova e si rimettono com'erano dopo,
 * così una prova non eredita quello che un'altra ha lasciato.
 */
final class RegexSicureTest extends TestCase
{
    /** Valori volutamente diversi dai 50.000 e 5.000 che il servizio usa. */
    private const BT_NOTO = '1000000';
    private const RC_NOTO = '100000';

    private string|false $btPrima = false;
    private string|false $rcPrima = false;

    protected function setUp(): void
    {
        $this->btPrima = ini_get('pcre.backtrack_limit');
        $this->rcPrima = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', self::BT_NOTO);
        ini_set('pcre.recursion_limit', self::RC_NOTO);
    }

    protected function tearDown(): void
    {
        if ($this->btPrima !== false) {
            ini_set('pcre.backtrack_limit', $this->btPrima);
        }
        if ($this->rcPrima !== false) {
            ini_set('pcre.recursion_limit', $this->rcPrima);
        }
    }

    /** @return array<string, array{string}> */
    public static function catastrofici(): array
    {
        return [
            '(a+)+$'       => ['(a+)+$'],
            '(a|aa)+$'     => ['(a|aa)+$'],
            '(a*)*$'       => ['(a*)*$'],
            '(a|a)*$'      => ['(a|a)*$'],
            '(\w+)+$'      => ['(\w+)+$'],
            '([a-z]+)*$'   => ['([a-z]+)*$'],
            '(.*a){20}'    => ['(.*a){20}'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function normali(): array
    {
        return [
            'parola'               => ['curl'],
            'alternative'          => ['sqlmap|nikto|nmap'],
            'user agent ancorato'  => ['^Mozilla/5\.0'],
            'percorso con barre'   => ['/wp-admin'],
            'senza maiuscole'      => ['(?i)union\s+select'],
            'estensione'           => ['\.php$'],
            'versione dell\'API'   => ['^/api/v[0-9]+/'],
            'dal manuale del WAF'  => ['(?i)mobile|android|iphone'],
            'ripetizione semplice' => ['a+$'],
            'gruppo senza annidare' => ['(a+)$'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function nonValidi(): array
    {
        return [
            'vuoto'            => [''],
            'troppo lungo'     => [str_repeat('a', 513)],
            'non si compila'   => ['(aperta'],
            'intervallo al rovescio' => ['[z-a]'],
        ];
    }

    #[Test]
    #[DataProvider('catastrofici')]
    public function un_pattern_catastrofico_si_rifiuta(string $pattern): void
    {
        self::assertFalse(WafRulesService::isRegexConditionSafe($pattern));
    }

    #[Test]
    #[DataProvider('normali')]
    public function un_pattern_normale_si_accetta(string $pattern): void
    {
        self::assertTrue(WafRulesService::isRegexConditionSafe($pattern));
    }

    #[Test]
    #[DataProvider('nonValidi')]
    public function un_pattern_vuoto_troppo_lungo_o_rotto_si_rifiuta(string $pattern): void
    {
        self::assertFalse(WafRulesService::isRegexConditionSafe($pattern));
    }

    /** Il bordo della lunghezza: 512 caratteri passano, 513 no (sopra). */
    #[Test]
    public function il_bordo_della_lunghezza(): void
    {
        self::assertTrue(WafRulesService::isRegexConditionSafe(str_repeat('b', 512)));
    }

    /**
     * La convalida abbassa i limiti di PCRE a 50.000/5.000 per la durata del
     * dry-run: NON usa quelli del processo. `(a{1,3}){1,10}$` sull'input di
     * prova sfonda il limite stretto (errore di backtracking, misurato 23/9/2026
     * con e senza JIT) e la convalida lo rifiuta; col limite del processo — qui
     * un milione, messo in setUp — la stessa ricerca arriva in fondo senza
     * errore, e senza l'abbassamento la convalida lo accetterebbe.
     *
     * Sorveglia la mutazione che toglie da `regexReggeSottoStress` le due righe
     * `ini_set('pcre.backtrack_limit', '50000')` e `'pcre.recursion_limit',
     * '5000'`: senza, `isRegexConditionSafe` eredita il limite del processo e
     * questo pattern passa.
     */
    #[Test]
    public function la_convalida_stringe_i_limiti_di_pcre(): void
    {
        $pattern = '(a{1,3}){1,10}$';
        $prova   = str_repeat('a', 2048) . '!';

        // Controprova: col limite del processo (un milione, da setUp) la
        // ricerca finisce senza errore. È il limite che la convalida NON usa.
        self::assertSame(self::BT_NOTO, ini_get('pcre.backtrack_limit'));
        @preg_match('/' . str_replace('/', '\\/', $pattern) . '/u', $prova);
        self::assertSame(
            PREG_NO_ERROR,
            preg_last_error(),
            'col limite del processo il pattern non esplode',
        );

        // Con i limiti stretti che la convalida impone, invece, esplode: rifiutato.
        self::assertFalse(
            WafRulesService::isRegexConditionSafe($pattern),
            'la convalida lo rifiuta perché stringe i limiti di PCRE',
        );

        // I limiti del processo restano quelli di prima: la convalida li rimette.
        self::assertSame(self::BT_NOTO, ini_get('pcre.backtrack_limit'), 'dopo la convalida');
        self::assertSame(self::RC_NOTO, ini_get('pcre.recursion_limit'), 'dopo la convalida');
    }

    /**
     * La convalida abbassa i limiti di PCRE per la durata della prova e li deve
     * rimettere com'erano: il resto della richiesta usa gli stessi limiti per
     * ogni altro `preg_*`, e con un limite a 50.000 fallirebbe in silenzio.
     */
    #[Test]
    public function i_limiti_di_pcre_tornano_come_prima(): void
    {
        WafRulesService::isRegexConditionSafe('(a+)+$');
        self::assertSame(self::BT_NOTO, ini_get('pcre.backtrack_limit'), 'dopo un pattern rifiutato');
        self::assertSame(self::RC_NOTO, ini_get('pcre.recursion_limit'), 'dopo un pattern rifiutato');

        WafRulesService::isRegexConditionSafe('curl');
        self::assertSame(self::BT_NOTO, ini_get('pcre.backtrack_limit'), 'dopo un pattern accettato');

        WafRulesService::safeRegexMatch('(a+)+$', str_repeat('a', 2000) . '!');
        self::assertSame(self::BT_NOTO, ini_get('pcre.backtrack_limit'), 'dopo la ricerca a runtime');
        self::assertSame(self::RC_NOTO, ini_get('pcre.recursion_limit'), 'dopo la ricerca a runtime');
    }

    /**
     * La seconda linea: a ogni richiesta la ricerca gira sotto un limite più
     * stretto di quello di PHP, e un pattern che esplode — anche uno che la
     * convalida non riconosce — si ferma e risponde «nessun riscontro».
     *
     * Senza cronometro: `(a+)+b|c` su sedici «a», un punto esclamativo e una
     * «c» trova la «c» solo dopo aver provato tutte le scomposizioni delle «a».
     * Misurato il 23/9/2026, con e senza JIT: col limite a 50.000 si ferma
     * prima (da quindici «a» in su), col milione predefinito di PHP arriva al
     * riscontro (fino a diciotto). Se il limite stretto sparisce, qui c'è un
     * riscontro.
     */
    #[Test]
    public function a_ogni_richiesta_un_pattern_che_esplode_si_ferma_senza_riscontro(): void
    {
        $soggetto = str_repeat('a', 16) . '!c';

        self::assertSame(1, preg_match('/(a+)+b|c/u', $soggetto), 'controprova: col limite di PHP il riscontro c\'è');
        self::assertFalse(WafRulesService::safeRegexMatch('(a+)+b|c', $soggetto), 'col limite stretto la ricerca si ferma prima');

        foreach (
            [
                ['(a+)+$', str_repeat('a', 2000) . '!'],
                ['(\d+)+$', str_repeat('1', 2000) . '!'],
            ] as [$pattern, $lungo]
        ) {
            self::assertFalse(WafRulesService::safeRegexMatch($pattern, $lungo), $pattern);
        }

        self::assertTrue(WafRulesService::safeRegexMatch('curl', 'curl/8.5.0'), 'il verso opposto: un riscontro vero resta tale');
        self::assertTrue(WafRulesService::safeRegexMatch('a/b', 'xa/by'), 'la barra nel pattern non rompe il delimitatore');
    }
}
