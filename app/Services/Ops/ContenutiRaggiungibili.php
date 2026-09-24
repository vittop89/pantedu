<?php

declare(strict_types=1);

namespace App\Services\Ops;

use PDO;

/**
 * I contenuti che nessuna sezione della barra può chiedere.
 *
 * Il 20 settembre 2026 l'utente segnalava che una verifica appena creata non
 * compariva nella barra. Il dato c'era: la riga in `teacher_content`, con la
 * classe e la materia giuste. A non esistere era la **domanda** che l'avrebbe
 * trovata.
 *
 * La barra chiede i contenuti in due modi (ADR-027):
 *
 *   - una sezione che accetta più di un tipo li chiede **per sezione**
 *     (`?section=verif`), e il filtro sul tipo sparisce;
 *   - una sezione mono-tipo li chiede **per tipo** (`?type=verifica`), e la
 *     sezione non c'entra.
 *
 * Una riga con `section_id` vuoto non risponde alla prima domanda. Se nessuna
 * sezione mono-tipo copre il suo tipo — e in produzione, il 20/9/2026, tutte e
 * sei le sezioni ne accettavano quattro — quella riga non compare in nessun
 * pannello, di nessuna classe, per nessuno. Non dà errore, non lascia traccia:
 * semplicemente non c'è. Erano nove, ferme da aprile.
 *
 * Non è un difetto che si vede provando l'applicazione, perché ogni pezzo fa
 * il suo mestiere: l'API risponde 200 con l'elenco giusto **della domanda che
 * le è stata fatta**. Si vede solo confrontando che cosa c'è nel database con
 * che cosa le sei domande possono restituire. Questo è quel confronto.
 */
final class ContenutiRaggiungibili
{
    /**
     * Le righe che nessuna domanda della barra restituisce.
     *
     * Logica pura, senza database: prende le sezioni come le pubblica
     * `/api/sidebar/config` e le righe dei contenuti, e torna quelle fuori
     * portata con il motivo.
     *
     * @param list<array{id: int, key: string, type: string, allowedTypes: list<string>}> $sezioni
     *        solo quelle attive
     * @param list<array{id: int, content_type: string, section_id: int|null}> $contenuti
     *        già senza gli archiviati (quelli stanno nel cestino apposta)
     * @return list<array{id: int, content_type: string, section_id: int|null, perche: string}>
     */
    public static function irraggiungibili(array $sezioni, array $contenuti): array
    {
        $perSezione = [];   // id delle sezioni che si chiedono per sezione
        $perTipo    = [];   // tipi coperti da una sezione mono-tipo
        foreach ($sezioni as $s) {
            if (\count($s['allowedTypes']) > 1) {
                $perSezione[(int)$s['id']] = true;
            } else {
                $perTipo[(string)$s['type']] = true;
            }
        }

        $fuori = [];
        foreach ($contenuti as $c) {
            $sezione = $c['section_id'] === null ? null : (int)$c['section_id'];
            $tipo    = (string)$c['content_type'];

            if ($sezione !== null && isset($perSezione[$sezione])) {
                continue;
            }
            if (isset($perTipo[$tipo])) {
                continue;
            }

            $fuori[] = [
                'id'           => (int)$c['id'],
                'content_type' => $tipo,
                'section_id'   => $sezione,
                'perche'       => $sezione === null
                    ? 'senza sezione, e nessuna sezione chiede i contenuti per tipo'
                    : "ancorato alla sezione $sezione, che non è fra quelle attive",
            ];
        }

        return $fuori;
    }

    /**
     * Lo stesso, leggendo il database.
     *
     * @return list<array{id: int, content_type: string, section_id: int|null, perche: string}>
     */
    public static function nelDatabase(PDO $pdo): array
    {
        $sezioni = [];
        $righe = $pdo->query(
            'SELECT id, section_key, default_content_type, allowed_content_types
               FROM sidebar_sections WHERE active = 1'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($righe as $r) {
            $tipi = json_decode((string)$r['allowed_content_types'], true);
            $sezioni[] = [
                'id'           => (int)$r['id'],
                'key'          => (string)$r['section_key'],
                'type'         => (string)$r['default_content_type'],
                'allowedTypes' => \is_array($tipi) ? $tipi : [],
            ];
        }

        $contenuti = $pdo->query(
            "SELECT id, content_type, section_id FROM teacher_content
              WHERE visibility <> 'archived'"
        )->fetchAll(PDO::FETCH_ASSOC);

        return self::irraggiungibili($sezioni, $contenuti);
    }
}
