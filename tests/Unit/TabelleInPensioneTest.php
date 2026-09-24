<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-037, fasi 4a e 4b — nessun codice che gira usa le tabelle in pensione.
 *
 * `published_content_data`, `classe_keys_data` (con le loro viste) e
 * `content_target_classes` le ha tolte la migrazione 120. La regola dei due
 * passi (wiki/dev-workflow.md) voleva che il rilascio precedente non le
 * leggesse e non le scrivesse più: questa prova è stata la misura della riga
 * `-- SICUREZZA:` di quella migrazione, e resta perché nessuno le rimetta in un
 * codice che le cercherebbe in un database dove non ci sono. Guarda il codice
 * che gira in produzione (app, rotte, viste).
 *
 * Cerca l'uso in SQL (FROM, JOIN, INTO, UPDATE, TABLE seguiti dal nome) e le
 * classi che le servivano. Un commento che le cita non è un uso.
 */
final class TabelleInPensioneTest extends TestCase
{
    private const TABELLE = '(published_content(_data)?|classe_keys(_data)?|content_target_classes)';
    private const CLASSI = ['ClasseKeyService', 'PublishedContentExporter', 'ClasseKeysExporter'];

    /** @return list<string> */
    private function fileDelCodice(): array
    {
        $base = dirname(__DIR__, 2);
        $out = [];
        foreach (['app', 'routes', 'views'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$base/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
                    $out[] = $f->getPathname();
                }
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Gli usi trovati nel testo dato, commenti esclusi.
     *
     * @return list<string>
     */
    public static function usi(string $php): array
    {
        $codice = '';
        foreach (token_get_all($php) as $t) {
            if (\is_array($t) && \in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codice .= \is_array($t) ? $t[1] : $t;
        }
        $trovati = [];
        if (preg_match_all('/\b(FROM|JOIN|INTO|UPDATE|TABLE)\s+`?' . self::TABELLE . '\b/i', $codice, $m)) {
            foreach ($m[0] as $uso) {
                $trovati[] = str_replace('`', '', (string)preg_replace('/\s+/', ' ', $uso));
            }
        }
        foreach (self::CLASSI as $classe) {
            if (preg_match('/\b' . $classe . '\b/', $codice)) {
                $trovati[] = $classe;
            }
        }
        return $trovati;
    }

    #[Test]
    public function il_riconoscitore_vede_un_uso_e_non_un_commento(): void
    {
        $this->assertSame(['FROM content_target_classes'], self::usi("<?php\n\$q = 'SELECT * FROM content_target_classes WHERE 1';"));
        $this->assertSame(['INTO classe_keys_data'], self::usi("<?php\n\$q = \"INSERT INTO `classe_keys_data` (x) VALUES (1)\";"));
        $this->assertSame(['ClasseKeyService'], self::usi("<?php\nnew \\App\\Services\\Crypto\\ClasseKeyService();"));
        $this->assertSame([], self::usi("<?php\n// una volta si leggeva FROM published_content\n/** ClasseKeyService */\n\$x = 1;"));
        $this->assertSame([], self::usi("<?php\n\$q = 'SELECT * FROM content_publications';"), 'la tabella nuova non è una di quelle in pensione');
    }

    #[Test]
    public function nessun_file_del_codice_usa_le_tabelle_in_pensione(): void
    {
        $file = $this->fileDelCodice();
        $this->assertGreaterThan(200, count($file), 'la ricerca ha guardato davvero il codice');
        $usi = [];
        foreach ($file as $f) {
            foreach (self::usi((string)file_get_contents($f)) as $uso) {
                $usi[] = substr($f, strlen(dirname(__DIR__, 2)) + 1) . ': ' . $uso;
            }
        }
        $this->assertSame([], $usi);
    }
    /**
     * I documenti di conformità non descrivono le tabelle in pensione
     * (14/9/2026, decisione 4). Registro, informativa e DPIA dichiaravano una
     * copia cifrata per classe con chiavi proprie, mai attivata: descrivere a
     * interessati e DPO un trattamento che non esiste è un errore anche quando
     * è in eccesso. I documenti storici (changelog, piani, analisi) restano.
     */
    #[Test]
    public function i_documenti_di_conformita_non_descrivono_le_tabelle_in_pensione(): void
    {
        $base = dirname(__DIR__, 2);
        $trovati = [];
        foreach (array_merge(glob("$base/docs/privacy/*.md") ?: [], glob("$base/docs/legal/*.md") ?: []) as $file) {
            foreach (file($file) ?: [] as $n => $riga) {
                // Anche al singolare: «rotation classe_key annuale» stava nel registro.
                // E all'inglese: «per-classe class_key» è rimasto nel §4 della DPIA
                // 1.6, allegata al DPO, perché lo schema cercava solo «classe_».
                if (preg_match('/\b(published_content(_data)?|classe?_keys?(_data)?|content_target_classes)\b|ClasseKeyService/', $riga)) {
                    $trovati[] = str_replace($base . '/', '', $file) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $trovati, 'un documento di conformità descrive ancora le tabelle in pensione');
    }
}
