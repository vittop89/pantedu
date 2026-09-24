<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\Curriculum\CurriculumTeacherRepository;
use PDO;

/**
 * La risoluzione fra codice e id delle voci di `curriculum_entries`, per i
 * tre kind: indirizzi (SCI, ART, LIN), classi (1, 2B, 1ALSS), materie (MAT,
 * FIS, GEO).
 *
 * Dal 13 settembre 2026 (ADR-035) un codice risolve SEMPRE alla voce del
 * VOCABOLARIO dell'istituto — la riga senza proprietario — e mai a una copia
 * per docente. Quando un docente usa un codice per un contenuto, la voce viene
 * spuntata per lui (`curriculum_teacher`), come prima veniva clonata: è lo
 * stesso automatismo, senza la copia.
 *
 * Dal 15 settembre 2026 (ADR-042) un anno («3») esiste una volta per corso:
 * per le classi si passa anche l'indirizzo. Le sigle delle sezioni («3A»)
 * restano uniche nella scuola e l'indirizzo non serve.
 *
 * Cache statica per richiesta: il catalogo sono poche decine di righe, e il
 * precaricamento è pigro (parte alla prima lettura).
 *
 * Esempi:
 *   CurriculumLookup::idFromCode('indirizzi', 'sc', 108)                // → id di SCI nell'istituto 108
 *   CurriculumLookup::idFromCode('classi', '1', 108, null, 'SCI')       // → id della prima dello scientifico
 *   CurriculumLookup::idFromCode('classi', '1A', 108)                   // → id della sezione 1A
 *   CurriculumLookup::codeFromId(172, 'indirizzi')                      // → 'SCI'
 *   CurriculumLookup::idFromCodeForTeacher('materie', 'MAT', 77)        // → id di MAT nell'istituto del docente, spuntata per lui
 */
final class CurriculumLookup
{
    /** @var array<string,int> Cache "{kind}|{code}|{instituteId}|{corso di un anno}" → id */
    private static array $idCache = [];
    /** @var array<string,string> Cache "{id}|{kind}" → code */
    private static array $codeCache = [];
    /** @var array<string,true> Spunte già fatte in questa richiesta: "{id}|{teacherId}" */
    private static array $spuntate = [];
    private static bool $preloaded = false;

    /** Map legacy indirizzo lowercase → canonical UPPER. */
    private const INDIRIZZO_LEGACY_MAP = [
        'sc'   => 'SCI',
        'ar'   => 'ART',
        'cl'   => 'CLA',
        'li'   => 'LIN',
        'ling' => 'LIN',
        'af'   => 'AFM',
    ];

    /** Kind validi per curriculum_entries. */
    public const KINDS = ['indirizzi', 'classi', 'materie'];

    /**
     * Normalizza codice: per indirizzi rimappa legacy + UPPER; per classi/materie
     * ritorna trim+UPPER. Stringa vuota → ''.
     */
    public static function canonicalize(string $kind, ?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        $trimmed = trim($code);
        if ($kind === 'indirizzi') {
            $low = strtolower($trimmed);
            if (isset(self::INDIRIZZO_LEGACY_MAP[$low])) {
                return self::INDIRIZZO_LEGACY_MAP[$low];
            }
            return strtoupper($trimmed);
        }
        if ($kind === 'classi') {
            // Classi: rimuovi prefisso indirizzo legacy ART3/SCI1 → 3/1
            $cleaned = preg_replace('/^[A-Z]{3}/', '', $trimmed) ?? $trimmed;
            // G19.49 / ADR-024 — la forma canonica è SHORT ("2"), NON il legacy
            // "2s"/"2b"/"2S". shrink() allinea la WRITE alla read query; è
            // idempotente su "2".
            $short = ClsNormalizer::shrink(strtolower($cleaned));
            return strtoupper($short);
        }
        return strtoupper($trimmed);
    }

    /**
     * Risolve code → id della voce di vocabolario.
     *
     * Match per (kind, code canonico, istituto), righe senza proprietario;
     * ripiego sulle voci senza istituto (legacy della 036). `$ownerUserId`
     * resta nella firma per i chiamanti storici ma non cambia la risposta:
     * il docente non ha righe sue.
     *
     * Un anno si risolve con `$indirizzo` (ADR-042): la voce di quel corso, o
     * null se il corso non ha quell'anno. Senza indirizzo, solo se la scuola ha
     * quell'anno in un corso solo; altrimenti null, perché scegliere un corso a
     * caso metterebbe il contenuto sotto un altro indirizzo.
     *
     * @return int|null id, null se code non valido / non in catalogo
     */
    public static function idFromCode(
        string $kind,
        ?string $code,
        ?int $instituteId = null,
        ?int $ownerUserId = null,
        ?string $indirizzo = null,
    ): ?int {
        unset($ownerUserId);
        $canon = self::canonicalize($kind, $code);
        if ($canon === '') {
            return null;
        }
        if ($kind === 'classi' && \App\Domain\ClassCode::isAnno($canon) && $instituteId !== null) {
            return self::anno($canon, $instituteId, $indirizzo);
        }

        self::preload();
        $cacheKey = $kind . '|' . $canon . '|' . ($instituteId ?? 'NULL') . '|';
        if (isset(self::$idCache[$cacheKey])) {
            return self::$idCache[$cacheKey];
        }

        $db = Database::connection();
        if ($instituteId !== null) {
            $stmt = $db->prepare(
                'SELECT id FROM curriculum_entries
                  WHERE kind = ? AND code = ? AND institute_id = ?
                  LIMIT 1'
            );
            $stmt->execute([$kind, $canon, $instituteId]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                self::$idCache[$cacheKey] = (int)$id;
                self::$codeCache[$id . '|' . $kind] = $canon;
                return (int)$id;
            }
        }
        // 2026-09-22 — qui c'era una seconda interrogazione su
        // `institute_id IS NULL`, il «catalogo globale». Non puo' tornare
        // niente: la colonna e' NOT NULL nello schema e le righe globali le ha
        // cancellate la migrazione 043. Misurato prima di toglierla: zero voci
        // per indirizzi, classi e materie.
        //
        // Costava una query per ogni codice non risolto, e soprattutto faceva
        // credere che un ripiego ci fosse.
        return null;
    }

    /**
     * L'anno di un corso nell'istituto (ADR-042). Con il corso: quella voce, o
     * null. Senza: la voce solo se l'anno c'è in un corso solo.
     */
    private static function anno(string $anno, int $instituteId, ?string $indirizzo): ?int
    {
        $corso = $indirizzo !== null && trim($indirizzo) !== '' ? self::canonicalize('indirizzi', $indirizzo) : '';
        self::preload();
        $cacheKey = 'classi|' . $anno . '|' . $instituteId . '|' . ($corso !== '' ? $corso : '?');
        if (isset(self::$idCache[$cacheKey])) {
            return self::$idCache[$cacheKey];
        }
        $db = Database::connection();
        if ($corso !== '') {
            $stmt = $db->prepare(
                'SELECT id FROM curriculum_entries
                  WHERE kind = ? AND code = ? AND institute_id = ? AND indirizzo = ?
                  LIMIT 1'
            );
            $stmt->execute(['classi', $anno, $instituteId, $corso]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = $db->prepare(
                'SELECT id FROM curriculum_entries
                  WHERE kind = ? AND code = ? AND institute_id = ?
                  LIMIT 2'
            );
            $stmt->execute(['classi', $anno, $instituteId]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        if (\count($ids) !== 1) {
            return null;
        }
        $id = (int)$ids[0];
        self::$idCache[$cacheKey] = $id;
        self::$codeCache[$id . '|classi'] = $anno;
        return $id;
    }

    /**
     * La classe dell'istituto per un codice, tenendo conto dell'indirizzo
     * (migrazione 100: «2A» sta sotto un corso). Ritorna anche l'indirizzo
     * della riga, così chi crea una coppia (indirizzo, classe) può correggere un
     * indirizzo sbagliato: la sezione sa a quale corso appartiene.
     *
     * Un anno è solo quello del corso chiesto (ADR-042): «3» con l'artistico
     * non diventa «3» dello scientifico.
     *
     * @return array{id:int,indirizzo:?string}|null
     */
    public static function classeAnchorForIndirizzo(?string $code, int $instituteId, ?string $indirizzo): ?array
    {
        $canon = self::canonicalize('classi', $code);
        if ($canon === '' || $instituteId <= 0) {
            return null;
        }
        $ind = $indirizzo !== null && trim($indirizzo) !== '' ? self::canonicalize('indirizzi', $indirizzo) : null;
        if (\App\Domain\ClassCode::isAnno($canon)) {
            $id = self::anno($canon, $instituteId, $ind);
            if ($id === null) {
                return null;
            }
            $st = Database::connection()->prepare('SELECT indirizzo FROM curriculum_entries WHERE id = ?');
            $st->execute([$id]);
            $rowInd = (string)$st->fetchColumn();
            return ['id' => $id, 'indirizzo' => $rowInd !== '' ? $rowInd : null];
        }
        $stmt = Database::connection()->prepare(
            'SELECT id, indirizzo FROM curriculum_entries
              WHERE kind = ? AND code = ? AND institute_id = ?
              ORDER BY (indirizzo = ?) DESC, (indirizzo IS NULL) DESC, id
              LIMIT 1'
        );
        $stmt->execute(['classi', $canon, $instituteId, $ind ?? '']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $rowInd = isset($row['indirizzo']) && (string)$row['indirizzo'] !== '' ? (string)$row['indirizzo'] : null;
        return ['id' => (int)$row['id'], 'indirizzo' => $rowInd];
    }

    /** Inverso: id → code. */
    public static function codeFromId(?int $id, ?string $kind = null): ?string
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        self::preload();
        if ($kind !== null && isset(self::$codeCache[$id . '|' . $kind])) {
            return self::$codeCache[$id . '|' . $kind];
        }
        $sql = 'SELECT code, kind FROM curriculum_entries WHERE id = ?';
        $args = [$id];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $args[] = $kind;
        }
        $sql .= ' LIMIT 1';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        self::$codeCache[$id . '|' . $row['kind']] = (string)$row['code'];
        return (string)$row['code'];
    }

    /**
     * A quale istituto si attribuisce il lavoro di questo docente, adesso.
     *
     * Vince l'istituto CORRENTE della sessione — quello scelto nel selettore
     * (TenantController::switch → Auth::setCurrentInstitute) — con tre
     * condizioni, tutte necessarie: c'è una sessione autenticata; il docente da
     * risolvere è l'utente collegato (un admin che agisce sui contenuti di un
     * altro non deve attribuirli alla PROPRIA scuola corrente); il docente è
     * davvero collegato a quell'istituto. Fuori da queste, il primo istituto
     * collegato per id, che per chi ne ha uno solo è corretto.
     *
     * Il motivo sta nella storia: sul Esempio 229 contenuti erano finiti sul
     * plesso sbagliato perché vinceva sempre l'id più basso.
     */
    public static function instituteForTeacher(?int $teacherId): ?int
    {
        if ($teacherId === null || $teacherId <= 0) {
            return null;
        }

        $corrente = self::currentInstituteIfOwn($teacherId);
        // La cache include l'istituto corrente: cambiare scuola dal selettore
        // deve cambiare la risposta, non pescare quella di prima.
        $chiave = $teacherId . '|' . ($corrente ?? 0);
        static $cache = [];
        if (array_key_exists($chiave, $cache)) {
            return $cache[$chiave];
        }
        if ($corrente !== null) {
            return $cache[$chiave] = $corrente;
        }

        $stmt = Database::connection()->prepare(
            'SELECT institute_id FROM teacher_institutes
              WHERE user_id = ? ORDER BY institute_id ASC LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        $id = $stmt->fetchColumn();
        return $cache[$chiave] = $id !== false ? (int)$id : null;
    }

    /**
     * L'istituto corrente, ma solo se è davvero di questo docente e lui ci
     * lavora. Null in tutti gli altri casi, compreso quello della CLI.
     */
    private static function currentInstituteIfOwn(int $teacherId): ?int
    {
        try {
            if (!Auth::check()) {
                return null;
            }
            if ((int)(Auth::user()['id'] ?? 0) !== $teacherId) {
                return null;
            }
            $corrente = (int)(Auth::currentInstitute() ?? 0);
            if ($corrente <= 0) {
                return null;
            }
            $chk = Database::connection()->prepare(
                'SELECT 1 FROM teacher_institutes WHERE user_id = ? AND institute_id = ? LIMIT 1'
            );
            $chk->execute([$teacherId, $corrente]);
            return $chk->fetchColumn() ? $corrente : null;
        } catch (\Throwable) {
            // Un problema nel leggere la sessione non deve impedire di salvare:
            // si ricade sul comportamento storico.
            return null;
        }
    }

    /**
     * code + docente → id della voce dell'istituto del docente, spuntata per
     * lui. È quello che succede quando un docente salva un contenuto con un
     * codice: la voce entra nei suoi menù, come prima ne nasceva la copia.
     * Null se la scuola non ha quel codice: usare un codice non lo inventa.
     *
     * Per una classe `$indirizzo` è il corso scelto insieme (ADR-042): senza, un
     * anno si risolve solo se la scuola lo ha in un corso solo.
     */
    public static function idFromCodeForTeacher(string $kind, ?string $code, ?int $teacherId, ?string $indirizzo = null): ?int
    {
        $inst = self::instituteForTeacher($teacherId);
        [$code, $indirizzo] = self::sezioneAmmessaOAnno($kind, $code, $indirizzo, $teacherId, $inst);
        $id = self::idFromCode($kind, $code, $inst, null, $indirizzo);
        if ($id !== null && $teacherId !== null && $teacherId > 0 && self::ammessa($kind, $code, $indirizzo, $teacherId, $inst)) {
            self::spunta($id, $teacherId);
        }
        return $id;
    }

    /**
     * Spunta per il docente la voce dell'istituto con quel codice. Idempotente;
     * null se la scuola non ha quel codice («attivare non è inventare»). Per un
     * anno serve il corso, come in idFromCode().
     */
    public static function ensureEntryForTeacher(string $kind, int $teacherId, string $code, ?int $instituteId, ?string $indirizzo = null): ?int
    {
        if (!in_array($kind, self::KINDS, true) || $instituteId === null || $teacherId <= 0) {
            return null;
        }
        [$code, $indirizzo] = self::sezioneAmmessaOAnno($kind, $code, $indirizzo, $teacherId, $instituteId);
        $id = self::idFromCode($kind, $code, $instituteId, null, $indirizzo);
        if ($id === null || !self::ammessa($kind, $code, $indirizzo, $teacherId, $instituteId)) {
            return null;
        }
        self::spunta($id, $teacherId);
        return $id;
    }

    /**
     * ADR-043 — anche un anno si spunta solo se la modalità dell'istituto lo
     * ammette (con «solo incaricati», l'incarico su quell'anno). Un anno che non
     * è ammesso resta la classe del contenuto, se lo era: salvando non si perde il
     * posto; ma la spunta non si accende, e lì non si pubblica di nuovo.
     */
    private static function ammessa(string $kind, ?string $code, ?string $indirizzo, ?int $teacherId, ?int $instituteId): bool
    {
        if ($kind !== 'classi' || $code === null || $teacherId === null || $teacherId <= 0 || $instituteId === null) {
            return true;
        }
        return (new \App\Services\SezioniDeiDocenti())->ammessa($teacherId, $instituteId, $code, $indirizzo);
    }

    /**
     * ADR-041 — la classe che il docente può usare: la sezione, se la modalità
     * dell'istituto gliela ammette, altrimenti il suo anno («2A» → «2»).
     *
     * Qui passano la classe di un contenuto salvato, di un'importazione, di un
     * incarico: un legame con una sezione che la scuola non ammette non si
     * scrive. Ripiegare sull'anno e non rifiutare è voluto: dall'interfaccia
     * quelle sezioni non si possono scegliere, e ci arrivano pacchetti
     * importati o chiamate dirette, per cui l'anno è la classe giusta che vale
     * anche per quella sezione. Se l'istituto non ha l'anno la classe resta
     * vuota, come per una sigla che la scuola non ha.
     *
     * ADR-042 — l'anno è quello del corso della sezione, scritto nel catalogo:
     * «3AR» ripiega su «3» di architettura anche se chi chiama non dice il corso.
     *
     * @return array{0:?string,1:?string} codice e indirizzo da risolvere
     */
    private static function sezioneAmmessaOAnno(string $kind, ?string $code, ?string $indirizzo, ?int $teacherId, ?int $instituteId): array
    {
        if ($kind !== 'classi' || $code === null || $teacherId === null || $teacherId <= 0 || $instituteId === null) {
            return [$code, $indirizzo];
        }
        if (!\App\Domain\ClassCode::isSezione($code)) {
            return [$code, $indirizzo];
        }
        if ((new \App\Services\SezioniDeiDocenti())->ammessa($teacherId, $instituteId, $code)) {
            return [$code, $indirizzo];
        }
        $st = Database::connection()->prepare(
            'SELECT indirizzo FROM curriculum_entries WHERE kind = ? AND code = ? AND institute_id = ? LIMIT 1'
        );
        $st->execute(['classi', self::canonicalize('classi', $code), $instituteId]);
        $corso = (string)$st->fetchColumn();
        return [\App\Domain\ClassCode::anno($code), $corso !== '' ? $corso : $indirizzo];
    }

    private static function spunta(int $curriculumId, int $teacherId): void
    {
        $k = $curriculumId . '|' . $teacherId;
        if (isset(self::$spuntate[$k])) {
            return;
        }
        (new CurriculumTeacherRepository())->attiva($curriculumId, $teacherId);
        self::$spuntate[$k] = true;
    }

    /**
     * Precarica il vocabolario in cache con una query sola. Idempotente.
     */
    public static function preload(): void
    {
        if (self::$preloaded) {
            return;
        }
        try {
            $stmt = Database::connection()->query(
                'SELECT id, kind, institute_id, code, indirizzo
                   FROM curriculum_entries
                  WHERE kind IN ("indirizzi", "classi", "materie")
                    AND active = 1'
            );
            foreach ($stmt as $r) {
                // ADR-042 — un anno ha il suo corso nella chiave: «3» dello
                // scientifico e «3» dell'artistico sono due voci.
                $corso = $r['kind'] === 'classi' && \App\Domain\ClassCode::isAnno((string)$r['code'])
                    ? (string)($r['indirizzo'] ?? '') : '';
                if ($r['kind'] === 'classi' && \App\Domain\ClassCode::isAnno((string)$r['code']) && $corso === '') {
                    continue;
                }
                $cacheKey = $r['kind'] . '|' . $r['code'] . '|' . ($r['institute_id'] ?? 'NULL') . '|' . $corso;
                self::$idCache[$cacheKey] = (int)$r['id'];
                self::$codeCache[$r['id'] . '|' . $r['kind']] = (string)$r['code'];
            }
            self::$preloaded = true;
        } catch (\Throwable) {
            // best-effort: se DB non disponibile, lookup runtime farà fallback
        }
    }

    /** Reset cache (solo testing). */
    public static function resetCache(): void
    {
        self::$idCache = [];
        self::$codeCache = [];
        self::$spuntate = [];
        self::$preloaded = false;
    }
}
