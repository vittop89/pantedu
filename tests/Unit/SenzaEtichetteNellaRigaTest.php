<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-037, fase 4c-2 — nessun codice scrive o legge le etichette della riga.
 *
 * `indirizzo_id`, `classe_id` e `subject_id` di `teacher_content_data`, e
 * `indirizzo_id` e `classe_id` di `verifica_documents_data`, non si scrivono
 * più dalla 4c-2: il posto di un documento è la sua pubblicazione principale,
 * scritta da App\Support\PostoPrincipale, e le viste `teacher_content` e
 * `verifica_documents` la leggono da lì (migrazione 121). La 4c-3 (123) ha tolto
 * le colonne: questa prova è stata la misura della sua riga `-- SICUREZZA:`, e
 * resta perché nessuno le rimetta in un codice che le cercherebbe dove non ci
 * sono.
 *
 * `materia_id` di una verifica resta: è parte di che cos'è la verifica (l'indice
 * unico con docente, titolo, variante e versione), non solo di dove sta (ADR-037,
 * fase 4c-2). Qui non si cerca.
 *
 * Guarda il codice che gira (app, rotte, viste) e gli strumenti, tranne quelli
 * d'archivio (`tools/archive`, `tools/migrations`: conversioni fatte una volta,
 * che non si rilanciano). Cerca, nelle stringhe SQL che nominano le due tabelle:
 *   - una colonna di etichetta qualificata con l'alias della tabella (`d.classe_id`);
 *   - una colonna di etichetta nella lista di un INSERT INTO la tabella, o in
 *     un UPDATE della tabella (anche attraverso la vista);
 *   - una colonna di etichetta non qualificata, quando la stringa non nomina
 *     anche `content_publications`, che ha colonne con lo stesso nome.
 * Un commento che le cita non è un uso.
 */
final class SenzaEtichetteNellaRigaTest extends TestCase
{
    private const ETICHETTE = '(indirizzo_id|classe_id|subject_id)';
    private const TABELLE = '(teacher_content_data|verifica_documents_data)';
    private const PAROLE_SQL = ['WHERE', 'SET', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'ON', 'AS', 'ORDER', 'GROUP', 'LIMIT', 'VALUES', 'USING', 'SELECT', 'FROM'];

    /** @return list<string> */
    private function fileDaGuardare(): array
    {
        $base = dirname(__DIR__, 2);
        $out = [];
        foreach (['app', 'routes', 'views', 'tools'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$base/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f instanceof \SplFileInfo || !$f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }
                $rel = substr($f->getPathname(), strlen($base) + 1);
                if (str_starts_with($rel, 'tools/archive/') || str_starts_with($rel, 'tools/migrations/')) {
                    continue;
                }
                $out[] = $rel;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Gli usi trovati nel testo PHP dato.
     *
     * @return list<string>
     */
    public static function usi(string $php): array
    {
        $trovati = [];
        $stringhe = array_values(array_filter(
            token_get_all($php),
            static fn($t): bool => \is_array($t) && \in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
        ));
        // Con il nome della tabella in una variabile (`UPDATE \`$tabella\` SET …`)
        // la stringa non lo contiene: in un file che nomina le due tabelle, un
        // SET su un'etichetta conta lo stesso, se non riguarda le pubblicazioni.
        $nominaLeTabelle = false;
        foreach ($stringhe as $t) {
            if (preg_match('/\b' . self::TABELLE . '\b/i', $t[1])) {
                $nominaLeTabelle = true;
                break;
            }
        }
        foreach ($stringhe as $t) {
            $sql = $t[1];
            if ($nominaLeTabelle && !preg_match('/\bcontent_publications\b/i', $sql)
                && !preg_match('/\b' . self::TABELLE . '\b/i', $sql)
                && preg_match_all('/\bSET\b[^;]*?(?<![.\w])' . self::ETICHETTE . '\s*=/i', $sql, $u)) {
                foreach ($u[1] as $col) {
                    $trovati[] = "SET $col (tabella in una variabile)";
                }
            }
            $conVista = (bool)preg_match('/\b(UPDATE|INTO)\s+`?(teacher_content|verifica_documents)`?\b(?!_)/i', $sql);
            if (!preg_match('/\b' . self::TABELLE . '\b/i', $sql) && !$conVista) {
                continue;
            }
            $s = (string)preg_replace('/\s+/', ' ', $sql);
            /** @var array<string,true> $contate colonne già riportate come INSERT o SET */
            $contate = [];

            // L'alias della tabella: `teacher_content_data d`, `... AS d`.
            if (preg_match_all('/\b' . self::TABELLE . '`?\s+(?:AS\s+)?([a-z]\w{0,3})\b/i', $s, $m)) {
                foreach (array_unique($m[2]) as $alias) {
                    if (\in_array(strtoupper($alias), self::PAROLE_SQL, true)) {
                        continue;
                    }
                    if (preg_match_all('/\b' . preg_quote($alias, '/') . '\.' . self::ETICHETTE . '\b/', $s, $u)) {
                        foreach ($u[0] as $uso) {
                            $trovati[] = $uso;
                        }
                    }
                }
            }
            // INSERT INTO la tabella (o la vista): la lista delle colonne.
            if (preg_match_all('/\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?(teacher_content(?:_data)?|verifica_documents(?:_data)?)`?\s*\(([^)]*)\)/i', $s, $m, PREG_SET_ORDER)) {
                foreach ($m as $ins) {
                    if (preg_match_all('/\b' . self::ETICHETTE . '\b/', $ins[2], $u)) {
                        foreach ($u[1] as $col) {
                            $trovati[] = "INSERT INTO {$ins[1]} ($col)";
                            $contate[$col] = true;
                        }
                    }
                }
            }
            // UPDATE della tabella (o della vista): le colonne assegnate.
            if (preg_match_all('/\bUPDATE\s+`?(teacher_content(?:_data)?|verifica_documents(?:_data)?)`?\s+(?:[a-z]\w{0,3}\s+)?SET\s+(.*?)(?:\bWHERE\b|$)/i', $s, $m, PREG_SET_ORDER)) {
                foreach ($m as $upd) {
                    if (preg_match_all('/(?<![.\w])' . self::ETICHETTE . '\s*=/', $upd[2], $u)) {
                        foreach ($u[1] as $col) {
                            $trovati[] = "UPDATE {$upd[1]} SET $col";
                            $contate[$col] = true;
                        }
                    }
                }
            }
            // Una colonna non qualificata, quando l'unica tabella che le ha è la
            // riga (e non è già stata contata come colonna di un INSERT o di un SET).
            if (!preg_match('/\bcontent_publications\b/i', $s) && preg_match('/\b' . self::TABELLE . '\b/i', $s)
                && preg_match_all('/(?<![.\w`])' . self::ETICHETTE . '\b/', $s, $u)) {
                foreach (array_unique($u[1]) as $col) {
                    if (!isset($contate[$col])) {
                        $trovati[] = "$col (colonna della riga)";
                    }
                }
            }
        }
        return array_values(array_unique($trovati));
    }

    #[Test]
    public function il_riconoscitore_vede_gli_usi_e_non_le_colonne_delle_pubblicazioni(): void
    {
        $this->assertSame(['d.classe_id'], self::usi("<?php\n\$q = 'SELECT d.id FROM teacher_content_data d WHERE d.classe_id = ?';"));
        $this->assertSame(['INSERT INTO verifica_documents_data (classe_id)'],
            self::usi("<?php\n\$q = 'INSERT INTO verifica_documents_data (teacher_id, materia_id, classe_id) VALUES (?, ?, ?)';"),
            'la materia di una verifica resta nella riga; la classe no');
        $this->assertSame(['UPDATE teacher_content_data SET classe_id'],
            self::usi("<?php\n\$q = \"UPDATE teacher_content_data SET classe_id = ?, updated_at = NOW() WHERE id = ?\";"));
        $this->assertSame(['UPDATE teacher_content SET subject_id'],
            self::usi("<?php\n\$q = 'UPDATE teacher_content SET subject_id = ? WHERE id = ?';"), 'anche attraverso la vista');
        $this->assertSame(['subject_id (colonna della riga)'],
            self::usi("<?php\n\$q = 'SELECT id FROM teacher_content_data WHERE subject_id IS NULL';"));
        $this->assertSame(['SET classe_id (tabella in una variabile)'], self::usi(
            "<?php\n\$t = \$v ? 'verifica_documents_data' : 'teacher_content_data';\n\$q = \"UPDATE `\$t` SET classe_id = ? WHERE id = ?\";"
        ), 'anche con il nome della tabella in una variabile');

        // I versi che non devono scattare.
        $this->assertSame([], self::usi("<?php\n\$q = 'SELECT d.id FROM teacher_content d WHERE d.classe_id = ?';"), 'la vista si legge');
        $this->assertSame([], self::usi(
            "<?php\n\$q = 'SELECT p.classe_id FROM content_publications p JOIN teacher_content_data d ON d.id = p.primary_of_tc';"
        ), 'le colonne delle pubblicazioni non sono della riga');
        $this->assertSame([], self::usi(
            "<?php\n\$q = 'INSERT INTO content_publications (teacher_content_id, classe_id) SELECT d.id, ? FROM teacher_content_data d';"
        ));
        $this->assertSame([], self::usi("<?php\n// una volta: UPDATE teacher_content_data SET classe_id = ?\n\$x = 1;"), 'un commento non è un uso');
        $this->assertSame([], self::usi("<?php\n\$q = 'UPDATE teacher_content_data SET updated_at = NOW() WHERE id = ?';"));
        $this->assertSame([], self::usi(
            "<?php\n\$t = 'teacher_content_data';\n\$q = \"UPDATE content_publications SET classe_id = ? WHERE id IN (\$in)\";"
        ), 'un SET sulle pubblicazioni, nello stesso file, no');
    }

    #[Test]
    public function nessun_file_usa_le_etichette_della_riga(): void
    {
        $base = dirname(__DIR__, 2);
        $trovati = [];
        foreach ($this->fileDaGuardare() as $rel) {
            foreach (self::usi((string)file_get_contents("$base/$rel")) as $uso) {
                $trovati[] = "$rel: $uso";
            }
        }
        $this->assertSame([], $trovati, "etichette della riga ancora usate (ADR-037, fase 4c):\n" . implode("\n", $trovati));
    }
}
