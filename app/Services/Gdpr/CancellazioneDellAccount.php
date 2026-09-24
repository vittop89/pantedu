<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Core\Config;
use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * La cancellazione di un account: una sola routine per le due strade che la
 * chiedono (24/9/2026, rilievo DOC-18 della revisione di settembre).
 *
 *   - l'art. 17, a fine ripensamento: DeletionRequestService::executeOne(),
 *     timer pantedu-gdpr-deletions;
 *   - l'anonimizzazione degli account inattivi da 730 giorni:
 *     AnonimizzazioneDegliInattivi, timer pantedu-gdpr-retention.
 *
 * CHE COSA FA, NELL'ORDINE
 *
 * Niente di irreversibile finché tutto il resto non è riuscito: la chiave si
 * distrugge per ULTIMA. Fino al 24/9/2026 (prima versione di questa classe)
 * era la prima, e un file che non si cancellava lasciava la chiave distrutta,
 * l'account attivo e la richiesta ancora annullabile: annullandola, il
 * contenuto cifrato era perso e la cancellazione non si ripeteva più.
 *
 *   1. Cancella i file del docente dai dati d'istanza (vedi cancellaIFile()):
 *      i contratti di esercizi, verifiche e laboratori (in chiaro), le
 *      sessioni di importazione dai PDF, i blob cifrati di mappe e verifiche,
 *      le preferenze e i modelli personali, le scelte e le stampe salvate a
 *      nome suo. Se un file c'è e non si riesce a cancellarlo, si ferma qui,
 *      prima di toccare il database, e lo dice: il giro successivo riparte
 *      da capo (tutto è ripetibile) e ritrova i file, perché il nome utente
 *      che ne compone alcuni percorsi non è ancora stato sostituito.
 *   2. In una transazione:
 *        - cancella le righe di RIGHE_DA_CANCELLARE (contenuti, titoli e
 *          argomenti, preferenze di stampa con i contatori DSA/DIS, versioni
 *          precedenti dei contenuti, scuole, classi e incarichi, credenziali
 *          di classe, collegamenti a Drive e GitHub, gettoni e codici);
 *        - nel verbale di accettazione dei Termini svuota IP e User-Agent
 *          (restano data e versioni);
 *        - toglie il motivo scritto nelle sue richieste di cancellazione;
 *        - dove il suo nome utente è scritto fuori dai registri (versioni dei
 *          contenuti di altri, `users.approved_by`,
 *          `registration_allowed_classes.created_by`), mette quello nuovo;
 *        - riduce la riga di `users` a segnaposto: nome utente `anon-<id>`,
 *          email `anon-<id>@invalid.local`, nome, cognome, data di nascita,
 *          scuola, indirizzo, classe, password e secondo fattore svuotati,
 *          `active = 0`, `status = 'anonymized'`, `deleted_at` alla prima
 *          esecuzione.
 *   3. Distrugge la chiave del docente (TeacherCryptoService::shred): tutto
 *      ciò che è cifrato con quella chiave, anche nelle copie di sicurezza,
 *      non si legge più con le chiavi del server in servizio. Se questo passo
 *      fallisce, l'eccezione lascia la richiesta dov'è e il giro dopo riprende:
 *      i passi 1 e 2 sono ripetibili e non trovano più niente.
 *
 * La riga di `users` NON si cancella: i registri (audit, cifratura, accessi,
 * richieste di cancellazione, verbale dei Termini) la puntano con l'id, e
 * cancellarla farebbe partire le cascate delle chiavi esterne anche su di
 * loro. Il segnaposto tiene i registri integri senza tenere la persona.
 *
 * CHE COSA RESTA, e dove lo si dice
 *
 *   - i riferimenti elencati in RIFERIMENTI_CHE_RESTANO, ognuno con il suo
 *     perché: registri con i loro termini, e puntatori all'id del segnaposto;
 *   - nei registri in sola aggiunta (trigger BEFORE UPDATE che rifiuta ogni
 *     modifica, migrazione 100), fino al loro termine
 *     (tools/audit/tabelle_da_purgare.php): in `audit_activity_log` (730
 *     giorni) il nome utente di allora, in `actor_name`, in `subject_id` e nei
 *     dettagli (sessioni revocate, iscrizioni); in `privileged_access_log`
 *     (1825 giorni) in `actor_name`; in `content_action_log` (1825 giorni) i
 *     dettagli di ogni contenuto creato, con titolo, classe, indirizzo e
 *     materia. Riscriverli vorrebbe dire togliere la garanzia che nessuno li
 *     ritocca: restano, e i documenti lo dicono;
 *   - le copie di sicurezza fatte prima della cancellazione, che contengono
 *     il database e la chiave com'erano, fino alla loro scadenza. Questa
 *     classe non le tocca: dopo un ripristino le cancellazioni si rieseguono
 *     (docs/security/operations/restore-reerasure.md), e questa routine è
 *     fatta per poterlo fare.
 *
 * Ripetibile: una seconda esecuzione sullo stesso id non trova niente da
 * cancellare e riscrive gli stessi valori.
 *
 * PRIMA (fino al 24/9/2026)
 *
 * Le due strade facevano cose diverse, e tutte e due meno di quanto dicevano
 * i documenti: l'art. 17 distruggeva la chiave e svuotava email, nome e
 * password; l'anonimizzazione a 730 giorni svuotava gli stessi campi e non
 * distruggeva niente. Restavano in chiaro, in tutti e due i casi, il nome
 * utente (nome.cognome), scuola, indirizzo e classe, i contratti degli
 * esercizi, le preferenze di stampa, titoli e argomenti, l'email dell'account
 * Google collegato, IP e User-Agent del verbale dei Termini.
 */
final class CancellazioneDellAccount
{
    /**
     * Le righe dell'utente che si cancellano: tabella => colonna con l'id.
     *
     * L'ordine conta dove una tabella dipende da un'altra senza cascata:
     * `content_versions` (legata ai contenuti con `content_id`, senza chiave
     * esterna) si toglie in cancellaLeRighe() prima di `teacher_content_data`.
     * Le cascate delle chiavi esterne fanno il resto: `content_publications` e
     * `map_shares` dietro ai contenuti, `verifica_compile_jobs` dietro alle
     * verifiche, `share_group_members` dietro ai gruppi.
     *
     * @var array<string, string>
     */
    public const RIGHE_DA_CANCELLARE = [
        // I contenuti e ciò che li descrive: titoli, argomenti, corpo in
        // chiaro (body_html, metadata_json), e la parte cifrata.
        'teacher_content_data'            => 'teacher_id',
        'verifica_documents_data'         => 'teacher_id',
        'verifica_compile_jobs'           => 'teacher_id',
        'risdoc_compilations_data'        => 'teacher_id',
        'risdoc_teacher_overrides'        => 'teacher_id',
        'risdoc_template_pending_changes' => 'submitted_by',
        'risdoc_template_collaborators'   => 'teacher_id',
        'risdoc_template_visibility'      => 'teacher_id',
        'pdf_import_sessions'             => 'teacher_id',
        'print_info_data'                 => 'user_id',
        'teacher_category_labels'         => 'teacher_id',
        'sidebar_section_overrides'       => 'teacher_id',
        'sidebar_section_teachers'        => 'teacher_id',
        'storage_objects'                 => 'owner_user_id',
        'ownership'                       => 'user_id',
        'content_shares'                  => 'owner_user_id',
        'map_shares'                      => 'granted_by',
        'share_groups'                    => 'owner_user_id',
        'share_group_members'             => 'member_user_id',
        // Scuola, classi, incarichi, credenziali di classe.
        'teacher_institutes'              => 'user_id',
        'curriculum_teacher'              => 'user_id',
        'teacher_sections'                => 'user_id',
        'teacher_access_credentials_data' => 'teacher_id',
        'teacher_capability_overrides'    => 'user_id',
        'student_class_history'           => 'user_id',
        // Collegamenti ad altri servizi: l'email dell'account Google è in
        // chiaro in teacher_drive_oauth.
        'teacher_drive_oauth'             => 'teacher_id',
        'teacher_drive_folder_cache'      => 'teacher_id',
        'teacher_github_sync'             => 'user_id',
        'spid_cie_identities'             => 'user_id',
        // Accesso: gettoni, codici, chiave di recupero, consensi in corso
        // (la loro storia resta in consent_audit).
        'teacher_recovery_keys'           => 'user_id',
        'password_resets'                 => 'user_id',
        'email_change_requests'           => 'user_id',
        'two_factor_email_codes'          => 'user_id',
        'consents'                        => 'user_id',
    ];

    /**
     * Le chiavi del docente: le cancella TeacherCryptoService::shred(), per
     * primo e con la sua riga nel registro della cifratura.
     *
     * @var list<string>
     */
    public const DISTRUZIONE_DELLA_CHIAVE = ['teacher_keys.teacher_id'];

    /**
     * I riferimenti all'utente che restano, e perché. Chiave `tabella.colonna`.
     *
     * La prova tests/Integration/Gdpr/CancellazioneDellAccountTest.php legge
     * dal database ogni chiave esterna verso `users` e ogni colonna che si
     * chiama come un id di utente: una tabella nuova che non sta né qui né in
     * RIGHE_DA_CANCELLARE la fa fallire. Così una tabella nuova con dati del
     * docente non sfugge alla cancellazione senza che qualcuno lo decida.
     *
     * @var array<string, string>
     */
    public const RIFERIMENTI_CHE_RESTANO = [
        // I registri: prova di che cosa è successo, con i loro termini.
        'deletion_requests.user_id'           => 'registro delle richieste e della loro esecuzione; senza il motivo',
        'user_tos_acceptance.user_id'         => 'verbale dei Termini: restano data e versioni, IP e User-Agent no',
        'legal_version_notifications.user_id' => 'registro degli avvisi di nuove versioni: id, versione, data',
        'teacher_recovery_audit.user_id'      => 'registro append-only della chiave di recupero (impronte, non IP)',
        'consent_audit.user_id'               => 'storia dei consensi (art. 7 §1)',
        'crypto_access_log.teacher_id'        => 'registro della cifratura, con la distruzione della chiave',
        'crypto_access_log.accessor_id'       => 'registro della cifratura',
        'avvisi_di_inattivita.user_id'        => 'gli avvisi partiti prima della cancellazione per inattività: la prova che l\'utente è stato avvisato',
        'crypto_custody_events.teacher_id'    => 'registro della custodia delle chiavi',
        'crypto_custody_events.actor_user_id' => 'registro della custodia delle chiavi',
        'audit_activity_log.actor_user_id'    => 'registro delle operazioni, append-only',
        'content_action_log.teacher_id'       => 'registro delle azioni sui contenuti, append-only',
        'content_action_log.actor_user_id'    => 'registro delle azioni sui contenuti, append-only',
        'privileged_access_log.user_id'       => 'registro degli accessi privilegiati, append-only',
        'data_breach_incidents.reported_by_user_id' => 'registro degli incidenti, immutabile',
        'takedown_requests.uploader_user_id'  => 'registro delle segnalazioni di rimozione',
        'takedown_requests.actioned_by'       => 'registro delle segnalazioni di rimozione',
        'parent_consents.student_user_id'     => 'prova del consenso del genitore (studenti, Scenario 3)',
        // Le versioni dei contenuti di ALTRI docenti in cui l'utente è
        // l'autore della modifica: il contenuto non è suo; il suo nome utente
        // accanto si sostituisce con quello nuovo.
        'content_versions.actor_user_id'      => 'versioni di contenuti di altri: resta l\'id del segnaposto',
        // Puntatori all'id del segnaposto in righe di altri: chi ha assegnato,
        // invitato, rivisto o aggiornato. Nessun dato della persona.
        'teacher_sections.assigned_by'                => 'chi ha assegnato un incarico a un altro docente',
        'risdoc_template_collaborators.invited_by'    => 'chi ha invitato un collaboratore',
        'risdoc_template_visibility.granted_by'       => 'chi ha concesso la visibilità di un modello',
        'risdoc_template_pending_changes.reviewed_by' => 'chi ha rivisto una modifica di un altro',
        'risdoc_institutional_overrides.updated_by'   => 'chi ha aggiornato un modello dell\'istituto',
        'risdoc_curriculum_data.updated_by'           => 'chi ha aggiornato un dato di curricolo dell\'istituto',
        'waf_blocked_ips.created_by'                  => 'l\'amministratore che ha bloccato un indirizzo',
        'waf_whitelisted_ips.created_by'              => 'l\'amministratore che ha ammesso un indirizzo',
        'waf_rules.created_by'                        => 'l\'amministratore che ha scritto una regola',
        'waf_config.updated_by'                       => 'l\'amministratore che ha cambiato la configurazione',
    ];

    /** Il prefisso del nome utente e dell'email del segnaposto. */
    public const PREFISSO = 'anon-';

    private TeacherCryptoService $crypto;

    /**
     * @param ?string $radiceDati    la cartella `storage` dei dati d'istanza
     *                               (`app.paths.storage`); le prove ne passano una loro
     * @param ?string $radiceOggetti la radice del deposito degli oggetti
     *                               (`storage.local.root`)
     */
    public function __construct(
        private ?PDO $pdo = null,
        ?TeacherCryptoService $crypto = null,
        private ?string $radiceDati = null,
        private ?string $radiceOggetti = null,
    ) {
        $this->crypto = $crypto ?? new TeacherCryptoService();
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    public static function nomeUtenteDelSegnaposto(int $userId): string
    {
        return self::PREFISSO . $userId;
    }

    public static function emailDelSegnaposto(int $userId): string
    {
        return self::PREFISSO . $userId . '@invalid.local';
    }

    /**
     * Cancella l'account `$userId` come descritto in testa alla classe.
     *
     * @param string $motivo scritto nel registro della cifratura accanto alla
     *                       distruzione della chiave (es. `art_17_self_service_deletion`)
     * @return array{righe: array<string, int>, file: int}
     *         quante righe per tabella e quanti file ha tolto questo giro
     * @throws RuntimeException se l'utente non c'è, se un file non si cancella
     *                          (prima di toccare il database) o se la
     *                          transazione fallisce (annullata)
     */
    public function esegui(int $userId, string $motivo): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('cancellazione: id utente non valido');
        }
        $db = $this->db();
        $st = $db->prepare('SELECT username FROM users WHERE id = ?');
        $st->execute([$userId]);
        $nomeUtente = $st->fetchColumn();
        if ($nomeUtente === false) {
            throw new RuntimeException("cancellazione: l'utente {$userId} non c'è");
        }

        // 1. I file, prima del database: alcuni percorsi hanno dentro il
        //    nome utente, che il passo 2 sostituisce. Un file che non si
        //    cancella ferma tutto qui, senza niente di irreversibile.
        $file = $this->cancellaIFile($userId, (string)$nomeUtente);

        // 2. Il database, tutto o niente.
        $propria = !$db->inTransaction();
        if ($propria) {
            $db->beginTransaction();
        }
        try {
            $righe = $this->cancellaLeRighe($db, $userId, (string)$nomeUtente);
            if ($propria) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($propria && $db->inTransaction()) {
                $db->rollBack();
            }
            throw new RuntimeException('cancellazione: database non aggiornato (' . $e->getMessage() . ')', 0, $e);
        }

        // 3. La chiave, per ultima: solo ora che file e righe non ci sono più.
        //    Su un account già cancellato (un giro ripreso) la chiave non c'è
        //    e shred non trova niente.
        $this->crypto->shred($userId, accessorId: 0, reason: $motivo);

        return ['righe' => $righe, 'file' => $file];
    }

    /** @return array<string, int> */
    private function cancellaLeRighe(PDO $db, int $userId, string $nomeVecchio): array
    {
        $nuovo = self::nomeUtenteDelSegnaposto($userId);
        $righe = [];

        // Le versioni precedenti dei suoi contenuti (snapshot_json, in
        // chiaro): legate con content_id, senza chiave esterna, quindi prima
        // dei contenuti.
        $st = $db->prepare(
            'DELETE cv FROM content_versions cv
               JOIN teacher_content_data t ON t.id = cv.content_id
              WHERE t.teacher_id = ?'
        );
        $st->execute([$userId]);
        $righe['content_versions'] = $st->rowCount();

        foreach (self::RIGHE_DA_CANCELLARE as $tabella => $colonna) {
            $st = $db->prepare("DELETE FROM `{$tabella}` WHERE `{$colonna}` = ?");
            $st->execute([$userId]);
            $righe[$tabella] = $st->rowCount();
        }

        // Il verbale dei Termini: data e versioni restano, IP e User-Agent no.
        // `accepted_ip` è NOT NULL: stringa vuota.
        $st = $db->prepare("UPDATE user_tos_acceptance SET accepted_ip = '', user_agent = NULL WHERE user_id = ?");
        $st->execute([$userId]);
        $righe['user_tos_acceptance'] = $st->rowCount();

        // Il motivo della richiesta è testo libero scritto dall'utente.
        $st = $db->prepare('UPDATE deletion_requests SET reason = NULL WHERE user_id = ? AND reason IS NOT NULL');
        $st->execute([$userId]);
        $righe['deletion_requests'] = $st->rowCount();

        // Il vecchio nome utente scritto fuori dai registri: accanto al suo id
        // nelle versioni dei contenuti di altri, e da solo dove ha approvato
        // un'iscrizione o ammesso una classe. Mai con un nome vuoto, che
        // combacerebbe con tutte le righe senza autore.
        if ($nomeVecchio !== '' && $nomeVecchio !== $nuovo) {
            $st = $db->prepare('UPDATE content_versions SET actor_name = ? WHERE actor_user_id = ?');
            $st->execute([$nuovo, $userId]);
            $st = $db->prepare('UPDATE users SET approved_by = ? WHERE approved_by = ?');
            $st->execute([$nuovo, $nomeVecchio]);
            $st = $db->prepare('UPDATE registration_allowed_classes SET created_by = ? WHERE created_by = ?');
            $st->execute([$nuovo, $nomeVecchio]);
        }

        // Il segnaposto. `role`, `admin_institute_id` e `is_super_admin`
        // restano: il trigger trg_users_amm_istituto_bu (ADR-040) li vuole
        // coerenti fra loro, e da soli non dicono chi era la persona.
        $st = $db->prepare(
            "UPDATE users
                SET username = ?, email = ?, first_name = '', last_name = '',
                    birth_date = NULL, institute_id = NULL, indirizzo = NULL, classe = NULL,
                    password_hash = '', must_change_password = 0,
                    totp_secret = NULL, totp_enabled = 0, two_factor_method = NULL,
                    totp_backup_codes = NULL, totp_enrolled_at = NULL, totp_last_counter = NULL,
                    pubblica_in_rete = 0, pubblica_in_rete_unica = NULL,
                    active = 0, status = 'anonymized', deleted_at = COALESCE(deleted_at, NOW())
              WHERE id = ?"
        );
        $st->execute([$nuovo, self::emailDelSegnaposto($userId), $userId]);
        $righe['users'] = $st->rowCount();

        return $righe;
    }

    /**
     * I file del docente nei dati d'istanza. Si tolgono:
     *
     *   - nel deposito degli oggetti, ogni `institutes/<scuola>/private/<id>/`
     *     (contratti di esercizi, verifiche e laboratori, in chiaro; sessioni
     *     di importazione dai PDF), più le chiavi scritte nelle sue righe
     *     (`metadata_json.contract_key`, `storage_objects.storage_key`) anche
     *     se stanno altrove;
     *   - `objects/teachers/<id>/` (modelli TikZ, scorciatoie LaTeX, catalogo
     *     GeoGebra), `maps_enc/<id>/` e `verifiche_enc/<id>/` (blob cifrati),
     *     `templates/verifiche/t_<id>/` (modelli di verifica personali),
     *     `config/pdf-import/teacher-<id>/` e `cache/pdf-import/teacher-<id>/`;
     *   - con il nome utente: `data/scelte/<nome>/` (le scelte delle versioni
     *     di una verifica), `data/print_info/<nome>.json` (preferenze di
     *     stampa quando il database non rispondeva), `temp/teachers/<nome>/`
     *     (i TeX delle stampe).
     *
     * @return int quanti file ha cancellato
     */
    private function cancellaIFile(int $userId, string $nomeUtente): int
    {
        $provider = (string)Config::get('storage.default_provider', 'local');
        if ($provider !== 'local') {
            // Il deposito S3 esiste solo come abbozzo: cancellare i file sul
            // disco locale lascerebbe i veri. Meglio fermarsi e dirlo.
            throw new RuntimeException("cancellazione: deposito '{$provider}' non gestito, i file non si cancellano");
        }
        $dati = rtrim($this->radiceDati ?? (string)Config::get('app.paths.storage', ''), '/');
        $oggetti = rtrim($this->radiceOggetti ?? (string)Config::get('storage.local.root', $dati . '/objects'), '/');
        if ($dati === '' || $oggetti === '') {
            throw new RuntimeException('cancellazione: cartella dei dati non configurata');
        }

        $errori = [];
        $n = 0;

        foreach ($this->chiaviDegliOggetti($userId) as $chiave) {
            $percorso = self::dentro($oggetti, $chiave);
            if ($percorso === null) {
                $errori[] = "chiave fuori dal deposito: {$chiave}";
                continue;
            }
            $n += self::cancellaFile($percorso, $errori);
        }

        $cartelle = glob($oggetti . '/institutes/*/private/' . $userId, GLOB_ONLYDIR) ?: [];
        foreach (self::cartelleDelDocente($userId) as $relativa) {
            $cartelle[] = $dati . '/' . $relativa;
        }

        // Gli stessi caratteri che usano VerificheService, PrintInfoService e
        // le stampe; un nome che dopo la pulizia potrebbe uscire dalla
        // cartella (`..`) non si usa.
        $sicuro = (string)preg_replace('/[^a-zA-Z0-9._-]/', '_', $nomeUtente);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $sicuro) === 1 && !str_contains($sicuro, '..')) {
            $cartelle[] = $dati . '/data/scelte/' . $sicuro;
            $cartelle[] = $dati . '/temp/teachers/' . $sicuro;
            $n += self::cancellaFile($dati . '/data/print_info/' . $sicuro . '.json', $errori);
        }

        foreach ($cartelle as $cartella) {
            $n += self::cancellaCartella($cartella, $errori);
        }

        if ($errori !== []) {
            throw new RuntimeException(sprintf(
                'cancellazione: %d file o cartelle non cancellati, il database non è stato toccato; il primo: %s',
                count($errori),
                $errori[0]
            ));
        }
        return $n;
    }

    /**
     * Le cartelle del docente sotto la cartella `storage` dei dati d'istanza,
     * indicate con il suo id. Un elenco solo, che usano la routine e la prova:
     * un percorso nuovo per docente va aggiunto qui, o resta sul disco dopo la
     * cancellazione. Il 24/9/2026 ne mancavano tre, trovati dalla revisione:
     * le librerie draw.io caricate (in chiaro), gli override dei modelli risdoc
     * con le loro immagini, la cache delle figure TikZ (cifrata).
     *
     * @return list<string>
     */
    public static function cartelleDelDocente(int $userId): array
    {
        return [
            "objects/teachers/{$userId}",
            "maps_enc/{$userId}",
            "verifiche_enc/{$userId}",
            "templates/verifiche/t_{$userId}",
            "templates/drawio/teachers/{$userId}",
            "overrides/teacher_{$userId}",
            "config/pdf-import/teacher-{$userId}",
            "cache/pdf-import/teacher-{$userId}",
            "cache/tikz/teacher_{$userId}",
        ];
    }

    /** @return list<string> le chiavi del deposito scritte nelle righe dell'utente */
    private function chiaviDegliOggetti(int $userId): array
    {
        $db = $this->db();
        $chiavi = [];
        $st = $db->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.contract_key'))
               FROM teacher_content_data
              WHERE teacher_id = ? AND metadata_json IS NOT NULL"
        );
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
            if (is_string($k) && $k !== '' && $k !== 'null') {
                $chiavi[] = $k;
            }
        }
        $st = $db->prepare('SELECT storage_key FROM storage_objects WHERE owner_user_id = ?');
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
            if (is_string($k) && $k !== '') {
                $chiavi[] = $k;
            }
        }
        return array_values(array_unique($chiavi));
    }

    /** Il percorso di `$chiave` sotto `$radice`, o null se ne uscirebbe. */
    private static function dentro(string $radice, string $chiave): ?string
    {
        $pulita = ltrim(str_replace('\\', '/', $chiave), '/');
        if ($pulita === '' || in_array('..', explode('/', $pulita), true)) {
            return null;
        }
        return $radice . '/' . $pulita;
    }

    /**
     * @param list<string> $errori
     * @return int 1 se ha cancellato, 0 se non c'era
     */
    private static function cancellaFile(string $percorso, array &$errori): int
    {
        if (!is_link($percorso) && !file_exists($percorso)) {
            return 0;
        }
        if (is_dir($percorso) && !is_link($percorso)) {
            $errori[] = "è una cartella, non un file: {$percorso}";
            return 0;
        }
        if (@unlink($percorso)) {
            return 1;
        }
        $errori[] = "non cancellato: {$percorso}";
        return 0;
    }

    /**
     * Cancella una cartella con tutto quello che contiene. Un collegamento
     * simbolico si toglie come collegamento: non si segue.
     *
     * @param list<string> $errori
     * @return int quanti file ha cancellato
     */
    private static function cancellaCartella(string $cartella, array &$errori): int
    {
        if (is_link($cartella)) {
            return self::cancellaFile($cartella, $errori);
        }
        if (!is_dir($cartella)) {
            return 0;
        }
        $n = 0;
        $voci = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($voci as $voce) {
            /** @var \SplFileInfo $voce */
            $p = $voce->getPathname();
            if ($voce->isDir() && !$voce->isLink()) {
                if (!@rmdir($p)) {
                    $errori[] = "cartella non cancellata: {$p}";
                }
                continue;
            }
            if (@unlink($p)) {
                $n++;
            } else {
                $errori[] = "non cancellato: {$p}";
            }
        }
        if (!@rmdir($cartella)) {
            $errori[] = "cartella non cancellata: {$cartella}";
        }
        return $n;
    }
}
