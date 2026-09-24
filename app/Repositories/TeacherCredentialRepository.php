<?php

namespace App\Repositories;

use App\Core\Database;
use App\Domain\EtichettaCredenziale;
use PDO;

/**
 * Repository per `teacher_access_credentials` (Phase 13).
 *
 * Ogni docente espone N coppie (access_username, password) per
 * consentire agli studenti di accedere alle proprie risorse senza
 * dover essere loro stessi registrati con account individuale.
 *
 * Lo studente inserisce username+password nel prompt sidebar; il
 * backend verifica `password_verify($plain, password_hash)` + scope
 * (institute/indirizzo/classe se settati) → emette token sessione
 * `fm_teacher_access` collegato al teacher_id.
 *
 * LE REGOLE STANNO QUI (19 settembre 2026, ADR-044)
 *   Username, password e aggiunta dell'etichetta hanno le loro regole in
 *   costanti di questa classe e di EtichettaCredenziale: la vista del profilo
 *   le stampa negli attributi dei campi (pattern, minlength, maxlength) e il
 *   server le applica con lo stesso testo. Prima il modulo non diceva le regole
 *   e browser e server non coincidevano del tutto: il browser contava le unità
 *   UTF-16 («🙂🙂🙂» passava il minimo di sei), bcrypt troncava in silenzio le
 *   password oltre i 72 byte, un username ripetuto dava un 500 col testo SQL.
 *   - username: da 3 a 64 caratteri fra lettere senza accenti, cifre, punto,
 *     trattino e trattino basso; unico su tutta la piattaforma (migrazione 134),
 *     maiuscole e minuscole si equivalgono;
 *   - password: da 6 a 64 caratteri ASCII stampabili, senza spazi: si detta in
 *     classe, non cambia da un dispositivo all'altro e resta sotto i 72 byte;
 *   - scadenza: oggi o dopo; una data passata è rifiutata.
 */
final class TeacherCredentialRepository
{
    public const USERNAME_MIN = 3;
    public const USERNAME_MAX = 64;
    /**
     * Il pattern HTML dell'username: la vista lo stampa nell'attributo
     * `pattern`, il server lo usa come /^(?:…)$/.
     */
    public const USERNAME_HTML_PATTERN = '[A-Za-z0-9._\-]{3,64}';
    public const PASSWORD_MIN = 6;
    public const PASSWORD_MAX = 64;
    /** ASCII stampabili senza lo spazio, da «!» (0x21) a «~» (0x7E), da 6 a 64. */
    public const PASSWORD_HTML_PATTERN = '[\x21-\x7E]{6,64}';

    /**
     * Il codice d'errore se l'username non rispetta la regola, null se la
     * rispetta. Pura: il controllo dei doppioni sta in create().
     */
    public static function usernameValido(string $username): ?string
    {
        return preg_match('/^(?:' . self::USERNAME_HTML_PATTERN . ')$/u', $username) === 1 ? null : 'invalid_username';
    }

    /**
     * Il codice d'errore se la password non rispetta la regola, null se la
     * rispetta. Prima i caratteri, poi la lunghezza: a chi scrive «🙂🙂🙂» serve
     * sapere che le emoji non vanno, non che è corta.
     */
    public static function passwordValida(string $password): ?string
    {
        if (preg_match('/^[\x21-\x7E]*$/', $password) !== 1) {
            return 'password_non_valida';
        }
        if (strlen($password) < self::PASSWORD_MIN) {
            return 'weak_password';
        }
        if (strlen($password) > self::PASSWORD_MAX) {
            return 'password_troppo_lunga';
        }
        return null;
    }

    /** @return list<array> */
    public function listForTeacher(int $teacherId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, label, materie, aggiunta, access_username, indirizzo, classe, institute_id, active, created_at,
                    expires_at, last_used_at, use_count,
                    (qr_token IS NOT NULL) AS has_qr,
                    temp_token_expires_at
             FROM teacher_access_credentials
             WHERE teacher_id = ?
             ORDER BY created_at DESC"
        );
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Una credenziale del docente, con i suoi contatori; null se non sua. */
    public function find(int $teacherId, int $credentialId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM teacher_access_credentials WHERE id = ? AND teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$credentialId, $teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Quante credenziali sono attive, e di quanti docenti: il riquadro della
     * dashboard negli scenari 1 e 2, dove la credenziale e' la porta degli
     * studenti. Solo numeri, nessuna identita'.
     *
     * @return array{active:int,teachers:int}
     */
    public function countActive(): array
    {
        $row = Database::connection()->query(
            'SELECT COUNT(*) AS active, COUNT(DISTINCT teacher_id) AS teachers
               FROM teacher_access_credentials
              WHERE active = 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['active' => (int)($row['active'] ?? 0), 'teachers' => (int)($row['teachers'] ?? 0)];
    }

    /**
     * Crea una credenziale e ne restituisce l'id. L'etichetta la compone il
     * server (ADR-044): un 'label' mandato dal client si ignora; contano
     * 'materie' (sigle, lista o separate da virgola) e 'aggiunta'.
     *
     * @param array<string,mixed> $data
     */
    public function create(int $teacherId, array $data): int
    {
        return $this->crea($teacherId, $data)['id'];
    }

    /**
     * Come create(), con l'etichetta composta e l'username ripulito: il
     * controller li restituisce al client, che non deve inventarli ne'
     * rileggerli dal modulo (che intanto si svuota).
     *
     * @param array<string,mixed> $data
     * @return array{id:int,label:string,username:string}
     */
    public function crea(int $teacherId, array $data): array
    {
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');

        $errore = self::usernameValido($username) ?? self::passwordValida($password);
        if ($errore !== null) {
            throw new \InvalidArgumentException($errore);
        }
        // La scuola della credenziale è una del docente (ADR-037, S2). Fino al
        // 13 settembre 2026 l'id arrivava dal client e si scriveva com'era: un
        // perimetro disegnato fuori dal proprio istituto (rilettura degli
        // scenari, §4.1). Senza id vale la scuola in cui sta lavorando, la
        // stessa in cui la barra risolve le sigle; prima era «nessuna», e le
        // sigle non si risolvevano.
        $iid = $this->istitutoDelDocente($teacherId, $data['institute_id'] ?? null);
        $p = $this->perimetro($teacherId, $iid, $data['classe'] ?? null, $data['indirizzo'] ?? null);
        // Piano classi, C — scadenza per default a fine anno scolastico (una
        // credenziale che gira non vive per sempre) e codice del QR permanente.
        $expires = $this->scadenza($data['expires_at'] ?? null) ?? self::defaultExpiry();
        $etichetta = $this->componiEtichetta($teacherId, $iid, $p['classe'], $p['indirizzo'], $data['materie'] ?? null, $data['aggiunta'] ?? null);
        // Username unico su tutta la piattaforma (ADR-044, migrazione 134): due
        // docenti con la stessa coppia mandavano gli studenti del secondo nel
        // portachiavi del primo (misurato). La collation rende uguali maiuscole
        // e minuscole, come all'accesso.
        if ($this->usernameInUso($username)) {
            throw new \InvalidArgumentException('username_in_uso');
        }
        if ($this->etichettaInUso($teacherId, $etichetta['label'], null)) {
            throw new \InvalidArgumentException('etichetta_gia_usata');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = Database::connection()->prepare(
            'INSERT INTO teacher_access_credentials_data
                (teacher_id, label, materie, aggiunta, access_username, password_hash,
                 indirizzo_id, classe_id, institute_id, active, expires_at, qr_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
        );
        try {
            $stmt->execute([
                $teacherId, $etichetta['label'], $etichetta['materie'], $etichetta['aggiunta'], $username, $hash,
                $p['indirizzo_id'], $p['classe_id'], $iid, $expires, self::token(),
            ]);
        } catch (\PDOException $e) {
            // Due creazioni simultanee con lo stesso username: il controllo sopra
            // le lascia passare entrambe, l'indice unico ne ferma una.
            if (self::doppioneUsername($e)) {
                throw new \InvalidArgumentException('username_in_uso', 0, $e);
            }
            throw $e;
        }
        // 21/9/2026 — torna anche l'username, ripulito come è stato scritto:
        // è metà di quello che il docente deve consegnare alla classe, e il
        // pannello lo diceva solo nella tabella, dopo aver ricaricato l'elenco.
        return [
            'id'       => (int)Database::connection()->lastInsertId(),
            'label'    => $etichetta['label'],
            'username' => $username,
        ];
    }

    /**
     * Ricompone l'etichetta di una credenziale esistente con le materie e
     * l'aggiunta scelte ora, sul suo perimetro. Serve alle credenziali nate con
     * l'etichetta libera (prima del 19 settembre 2026) e a chi cambia materie o
     * aggiunta. Restituisce l'etichetta nuova, null se la credenziale non è del
     * docente.
     */
    public function setEtichetta(int $teacherId, int $credentialId, mixed $materie, mixed $aggiunta): ?string
    {
        $row = $this->find($teacherId, $credentialId);
        if ($row === null) {
            return null;
        }
        $iid = $this->istitutoDellaRiga($row, $teacherId);
        $etichetta = $this->componiEtichetta(
            $teacherId,
            $iid,
            $this->blankToNull($row['classe'] ?? null),
            $this->blankToNull($row['indirizzo'] ?? null),
            $materie,
            $aggiunta
        );
        if ($this->etichettaInUso($teacherId, $etichetta['label'], $credentialId)) {
            throw new \InvalidArgumentException('etichetta_gia_usata');
        }
        Database::connection()->prepare(
            'UPDATE teacher_access_credentials_data SET label = ?, materie = ?, aggiunta = ? WHERE id = ? AND teacher_id = ?'
        )->execute([$etichetta['label'], $etichetta['materie'], $etichetta['aggiunta'], $credentialId, $teacherId]);
        return $etichetta['label'];
    }

    /**
     * L'etichetta che si otterrebbe, per l'anteprima del modulo: stessa
     * composizione e stessi controlli di create() (o di setEtichetta() con
     * 'id'), senza scrivere. 'gia_usata' dice se il docente ne ha già una
     * uguale fra le non scadute.
     *
     * @param array<string,mixed> $data
     * @return array{label:string,gia_usata:bool}
     */
    public function anteprimaEtichetta(int $teacherId, array $data): array
    {
        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            $row = $this->find($teacherId, $id);
            if ($row === null) {
                throw new \InvalidArgumentException('not_found');
            }
            $iid = $this->istitutoDellaRiga($row, $teacherId);
            $classe = $this->blankToNull($row['classe'] ?? null);
            $indirizzo = $this->blankToNull($row['indirizzo'] ?? null);
        } else {
            $iid = $this->istitutoDelDocente($teacherId, $data['institute_id'] ?? null);
            $p = $this->perimetro($teacherId, $iid, $data['classe'] ?? null, $data['indirizzo'] ?? null);
            $classe = $p['classe'];
            $indirizzo = $p['indirizzo'];
        }
        $etichetta = $this->componiEtichetta($teacherId, $iid, $classe, $indirizzo, $data['materie'] ?? null, $data['aggiunta'] ?? null);
        return [
            'label'     => $etichetta['label'],
            'gia_usata' => $this->etichettaInUso($teacherId, $etichetta['label'], $id > 0 ? $id : null),
        ];
    }

    /**
     * Le parti dell'etichetta, controllate: aggiunta nella regola, materie fra
     * quelle spuntate dal docente nell'istituto della credenziale, lunghezza
     * nella colonna. `materie` è la stringa da salvare ('' = composta senza
     * materie; NULL resta alle etichette libere di prima).
     *
     * @return array{label:string,materie:string,aggiunta:?string}
     */
    private function componiEtichetta(int $teacherId, ?int $iid, ?string $classe, ?string $indirizzo, mixed $materie, mixed $aggiunta): array
    {
        $agg = trim((string)(\is_scalar($aggiunta) ? $aggiunta : ''));
        if ($agg !== '' && !EtichettaCredenziale::aggiuntaValida($agg)) {
            throw new \InvalidArgumentException('aggiunta_non_valida');
        }
        $sigle = EtichettaCredenziale::materie(self::lista($materie));
        if ($sigle !== []) {
            $mie = [];
            if ($iid !== null) {
                foreach ((new \App\Services\TeacherSubjectService())->forTeacher($teacherId, $iid) as $m) {
                    $mie[strtoupper($m['code'])] = true;
                }
            }
            foreach ($sigle as $s) {
                if (!isset($mie[$s])) {
                    throw new \InvalidArgumentException('materia_non_tua');
                }
            }
        }
        $label = EtichettaCredenziale::componi($classe, $indirizzo, $sigle, $agg === '' ? null : $agg);
        if (mb_strlen($label) > EtichettaCredenziale::LUNGHEZZA_MAX) {
            throw new \InvalidArgumentException('etichetta_troppo_lunga');
        }
        return [
            'label'    => $label,
            'materie'  => implode(',', $sigle),
            'aggiunta' => $agg === '' ? null : strtoupper($agg),
        ];
    }

    /**
     * Il docente ha già una credenziale non scaduta, accesa o spenta, con
     * questa etichetta? Le scadute no: a fine anno la stessa classe riprende la
     * stessa etichetta senza che il docente debba cancellare le vecchie.
     */
    private function etichettaInUso(int $teacherId, string $label, ?int $escludi): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM teacher_access_credentials_data
              WHERE teacher_id = ? AND label = ? AND id <> ?
                AND (expires_at IS NULL OR expires_at >= CURDATE())
              LIMIT 1'
        );
        $stmt->execute([$teacherId, $label, $escludi ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    private function usernameInUso(string $username): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM teacher_access_credentials_data WHERE access_username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        return $stmt->fetchColumn() !== false;
    }

    /** L'errore del database è un username già presente (indice della 134 o quello per docente)? */
    private static function doppioneUsername(\PDOException $e): bool
    {
        $msg = $e->getMessage();
        return (string)$e->getCode() === '23000'
            && (str_contains($msg, 'uq_tac_access_username') || str_contains($msg, 'uq_tac_user'));
    }

    /**
     * Una lista di stringhe da un campo che arriva come array (materie[]) o
     * come testo separato da virgole (materie=FIS,MAT).
     *
     * @return list<string>
     */
    private static function lista(mixed $v): array
    {
        if (\is_string($v)) {
            $v = explode(',', $v);
        }
        if (!\is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $x) {
            if (\is_scalar($x) && trim((string)$x) !== '') {
                $out[] = trim((string)$x);
            }
        }
        return $out;
    }

    /**
     * Il perimetro della credenziale risolto nel catalogo della scuola: gli id
     * da salvare e i codici che finiscono nell'etichetta.
     *
     * @return array{classe_id:?int,indirizzo_id:?int,classe:?string,indirizzo:?string}
     */
    private function perimetro(int $teacherId, ?int $iid, mixed $classe, mixed $indirizzo): array
    {
        $ind = $this->blankToNull($indirizzo);
        $cls = $this->blankToNull($classe);
        // G22.S20 v2.C2 Fase D — solo FK ids (varchar dropped).
        $instLookup = $iid > 0 ? $iid : null;
        $clsId = null;
        if ($cls !== null && $cls !== '') {
            // Piano classi, C (2026-09-06) — la classe sa a quale corso
            // appartiene («2A» sta sotto Scientifico): se il chiamante manda un
            // indirizzo diverso (era quello del selettore in sidebar), vince
            // quello della classe. Altrimenti lo studente si ritrovava «2A
            // Artistico» con una credenziale creata per la 2A dello Scientifico.
            $anchor = $instLookup !== null
                ? \App\Support\CurriculumLookup::classeAnchorForIndirizzo(
                    (string)$cls,
                    $instLookup,
                    $ind !== null ? (string)$ind : null
                )
                : null;
            if ($anchor !== null) {
                $clsId = $anchor['id'];
                if ($anchor['indirizzo'] !== null) {
                    $ind = $anchor['indirizzo'];
                }
            } else {
                $clsId = \App\Support\CurriculumLookup::idFromCode('classi', (string)$cls, $instLookup, null, $ind !== null ? (string)$ind : null);
            }
            // Una classe che la scuola non ha non delimita niente: la
            // credenziale nascerebbe valida per tutte le classi del docente,
            // più larga di quanto chiesto e senza dirlo (ADR-037, S3).
            if ($clsId === null) {
                throw new \InvalidArgumentException('invalid_classe');
            }
            // ADR-041 — una credenziale su una sezione lega il docente a quella
            // sezione: solo se la modalità dell'istituto la ammette.
            if ($instLookup !== null && !(new \App\Services\SezioniDeiDocenti())->ammessa($teacherId, $instLookup, (string)$cls, $ind !== null ? (string)$ind : null)) {
                throw new \InvalidArgumentException('sezione_non_ammessa');
            }
        }
        $indId = $ind !== null && $ind !== ''
            ? \App\Support\CurriculumLookup::idFromCode('indirizzi', (string)$ind, $instLookup) : null;
        if ($ind !== null && $ind !== '' && $indId === null) {
            throw new \InvalidArgumentException('invalid_indirizzo');
        }
        return [
            'classe_id'    => $clsId,
            'indirizzo_id' => $indId,
            'classe'       => $clsId !== null ? strtoupper((string)$cls) : null,
            'indirizzo'    => $indId !== null ? strtoupper((string)$ind) : null,
        ];
    }

    /**
     * Il 31 agosto dell'anno scolastico in corso: fino al 31 agosto e' quello
     * di quest'anno, dal 1° settembre quello dell'anno prossimo. Pura.
     */
    public static function defaultExpiry(?\DateTimeImmutable $today = null): string
    {
        $today ??= new \DateTimeImmutable('today');
        $year = (int)$today->format('Y');
        $end = new \DateTimeImmutable("$year-08-31");
        if ($today > $end) {
            $end = $end->modify('+1 year');
        }
        return $end->format('Y-m-d');
    }

    /** Solo la password: lo studente reinserisce una cosa sola, l'username resta. */
    public function setPassword(int $teacherId, int $credentialId, string $password): bool
    {
        $errore = self::passwordValida($password);
        if ($errore !== null) {
            throw new \InvalidArgumentException($errore);
        }
        $stmt = Database::connection()->prepare(
            'UPDATE teacher_access_credentials_data SET password_hash = ? WHERE id = ? AND teacher_id = ?'
        );
        $stmt->execute([password_hash($password, PASSWORD_BCRYPT), $credentialId, $teacherId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Scadenza (YYYY-MM-DD, oggi o dopo) o null per «senza scadenza».
     *
     * Riportare in vita una credenziale scaduta rimette in gioco la sua
     * etichetta, e va guardata come alla creazione: etichettaInUso() non conta
     * le scadute (ADR-044, punto 8), quindi nel frattempo un'altra credenziale
     * del docente ha potuto prendersi la stessa. Senza questo controllo
     * restavano due credenziali non scadute con la stessa etichetta, che nel
     * portachiavi dello studente sono due voci identiche: non sa quale «Esci»
     * preme. È `etichetta_in_conflitto`, e la scadenza non si sposta.
     */
    public function setExpiry(int $teacherId, int $credentialId, ?string $date): bool
    {
        $exp = $this->scadenza($date);
        $row = $this->find($teacherId, $credentialId);
        if ($row === null) {
            return false;
        }
        // scadenza() ha già rifiutato le date passate: ogni valore ammesso qui
        // (una data da oggi in poi, o «senza scadenza») riporta in vita una
        // credenziale scaduta.
        $scaduta = !empty($row['expires_at']) && (string)$row['expires_at'] < date('Y-m-d');
        if ($scaduta && $this->etichettaInUso($teacherId, (string)($row['label'] ?? ''), $credentialId)) {
            throw new \InvalidArgumentException('etichetta_in_conflitto');
        }
        $stmt = Database::connection()->prepare(
            'UPDATE teacher_access_credentials_data SET expires_at = ? WHERE id = ? AND teacher_id = ?'
        );
        $stmt->execute([$exp, $credentialId, $teacherId]);
        return $stmt->rowCount() > 0;
    }

    /** Nuovo codice per il QR permanente: il vecchio QR smette di valere. */
    public function regenerateQrToken(int $teacherId, int $credentialId): ?string
    {
        $token = self::token();
        $stmt = Database::connection()->prepare(
            'UPDATE teacher_access_credentials_data SET qr_token = ? WHERE id = ? AND teacher_id = ?'
        );
        $stmt->execute([$token, $credentialId, $teacherId]);
        return $stmt->rowCount() > 0 ? $token : null;
    }

    /**
     * Codice a tempo da proiettare in classe: chi e' presente lo scansiona,
     * poi muore. Niente password stabile da far girare.
     *
     * 23/9/2026 (A-70) — la scadenza si scrive con l'orologio del database
     * (NOW() + INTERVAL), lo stesso con cui findByToken() la confronta. Prima
     * si scriveva l'ora di PHP (Europe/Rome) e il confronto usava NOW() del
     * database, che in produzione è in UTC e sulla cui connessione nessuno
     * imposta il fuso: un codice da dieci minuti apriva per circa 130 minuti
     * d'estate e 70 d'inverno. `expires_at` restituito resta l'ora
     * dell'applicazione, cioè quella del docente: fra `$minutes` minuti da
     * adesso, come dice il riquadro («Vale dieci minuti»). Non va riscritto
     * nel database né confrontato con NOW().
     *
     * @return array{token:string,expires_at:string}|null
     */
    public function issueTempToken(int $teacherId, int $credentialId, int $minutes = 10): ?array
    {
        $minutes = max(1, min(120, $minutes));
        $token = self::token();
        $stmt = Database::connection()->prepare(
            'UPDATE teacher_access_credentials_data
                SET temp_token = ?, temp_token_expires_at = NOW() + INTERVAL ? MINUTE
              WHERE id = ? AND teacher_id = ?'
        );
        $stmt->execute([$token, $minutes, $credentialId, $teacherId]);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        $exp = (new \DateTimeImmutable("+{$minutes} minutes"))->format('Y-m-d H:i:s');
        return ['token' => $token, 'expires_at' => $exp];
    }

    /**
     * La credenziale attiva e non scaduta dietro un codice QR (permanente o a
     * tempo ancora valido); null altrimenti. Un codice non e' una password:
     * nessun confronto di hash, ma stessa porta e stesso limite di tentativi.
     */
    public function findByToken(string $token): ?array
    {
        $token = trim($token);
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM teacher_access_credentials
              WHERE active = 1
                AND (expires_at IS NULL OR expires_at >= CURDATE())
                AND (qr_token = ? OR (temp_token = ? AND temp_token_expires_at > NOW()))
              LIMIT 1'
        );
        $stmt->execute([$token, $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * I codici QR permanenti di un insieme di credenziali (per il «pacchetto di classe»).
     *
     * @param list<int> $ids
     * @return list<string>
     */
    public function qrTokensFor(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0));
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT qr_token FROM teacher_access_credentials_data
              WHERE id IN ($place) AND active = 1 AND qr_token IS NOT NULL
                AND (expires_at IS NULL OR expires_at >= CURDATE())"
        );
        $stmt->execute($ids);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * La scadenza piu' vicina fra le credenziali date (YYYY-MM-DD), o null se
     * nessuna ne ha una: e' il limite del cookie «ricorda».
     *
     * @param list<int> $ids
     */
    public function earliestExpiry(array $ids): ?string
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0));
        if ($ids === []) {
            return null;
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT MIN(expires_at) FROM teacher_access_credentials_data WHERE id IN ($place)"
        );
        $stmt->execute($ids);
        $v = $stmt->fetchColumn();
        return ($v !== false && $v !== null && (string)$v !== '') ? (string)$v : null;
    }

    /** Conta un ingresso: solo il numero e il momento, mai chi. */
    public function touchUse(int $credentialId): void
    {
        try {
            Database::connection()->prepare(
                'UPDATE teacher_access_credentials_data
                    SET use_count = use_count + 1, last_used_at = NOW()
                  WHERE id = ?'
            )->execute([$credentialId]);
        } catch (\Throwable) {
            // il contatore e' un aiuto per il docente, non un vincolo
        }
    }

    /**
     * La scadenza chiesta: vuota → null; YYYY-MM-DD da oggi in poi → la data.
     * Una data non riconosciuta è 'invalid_expiry'; una data passata è
     * 'scadenza_passata': fino al 19 settembre 2026 si accettava, e la
     * credenziale nasceva già spenta senza che il docente lo sapesse.
     */
    private function scadenza(mixed $value): ?string
    {
        $s = trim(\is_scalar($value) ? (string)$value : '');
        if ($s === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s) {
            throw new \InvalidArgumentException('invalid_expiry');
        }
        if ($s < date('Y-m-d')) {
            throw new \InvalidArgumentException('scadenza_passata');
        }
        return $s;
    }

    /** 32 byte casuali in base64url: 43 caratteri, imprevedibili. */
    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function delete(int $teacherId, int $credentialId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM teacher_access_credentials_data WHERE id = ? AND teacher_id = ?'
        );
        $stmt->execute([$credentialId, $teacherId]);
        return $stmt->rowCount() > 0;
    }

    public function setActive(int $teacherId, int $credentialId, bool $active): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE teacher_access_credentials_data SET active = ? WHERE id = ? AND teacher_id = ?'
        );
        $stmt->execute([$active ? 1 : 0, $credentialId, $teacherId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Verifica username+password. Restituisce la credential row + teacher_id
     * se valida, altrimenti null.
     */
    public function verify(string $username, string $password): ?array
    {
        // Piano classi, C — una credenziale scaduta non apre piu', come una spenta.
        // ADR-044 — l'username è unico su tutta la piattaforma (migrazione 134):
        // una riga sola. Prima due docenti potevano avere la stessa coppia, e
        // vinceva quella che la query trovava per prima.
        $stmt = Database::connection()->prepare(
            'SELECT * FROM teacher_access_credentials
             WHERE access_username = ? AND active = 1
               AND (expires_at IS NULL OR expires_at >= CURDATE())
             LIMIT 1'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($password, (string)$row['password_hash'])) {
            return $row;
        }
        return null;
    }

    /**
     * L'istituto in cui vale la credenziale: quello chiesto, se è fra le
     * scuole del docente; altrimenti un rifiuto. Senza richiesta, la scuola
     * attiva del docente (o la prima collegata), null per chi non ne ha.
     */
    private function istitutoDelDocente(int $teacherId, mixed $richiesto): ?int
    {
        $iid = (int)($richiesto ?? 0);
        if ($iid > 0) {
            if (!\App\Support\TeacherContextResolver::isLinkedToInstitute($teacherId, $iid)) {
                throw new \InvalidArgumentException('invalid_institute');
            }
            return $iid;
        }
        return \App\Support\CurriculumLookup::instituteForTeacher($teacherId);
    }

    /**
     * L'istituto in cui leggere le materie di una credenziale che esiste già:
     * quello del suo perimetro; se non ce l'ha, quello in cui il docente sta
     * lavorando — lo stesso ripiego di crea() e le stesse caselle che il modulo
     * mostra nella riga (`r.institute_id ?? istituto()`).
     *
     * Il ripiego serve: le credenziali nate prima del 13 settembre 2026 hanno
     * `institute_id` NULL (25 righe su 26 nel database di sviluppo il 19
     * settembre 2026), e sono proprio quelle a etichetta libera che «Rigenera
     * etichetta» deve poter ricomporre. Senza, la lista delle materie del
     * docente restava vuota e qualunque sigla scelta era `materia_non_tua`,
     * mentre il modulo le mostrava tutte spuntate.
     *
     * @param array<string,mixed> $row
     */
    private function istitutoDellaRiga(array $row, int $teacherId): ?int
    {
        return !empty($row['institute_id'])
            ? (int)$row['institute_id']
            : \App\Support\CurriculumLookup::instituteForTeacher($teacherId);
    }

    private function blankToNull(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : null;
        return ($s === null || $s === '') ? null : $s;
    }
}
