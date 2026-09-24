-- 100 — IP e User-Agent come hash nei registri che li conservavano in chiaro.
--
-- Informativa (§3.4), registro art. 30 (B.5, B.6) e DPIA dichiaravano che nei
-- registri di audit IP e User-Agent restano solo come hash SHA-256. Era vero
-- per audit_activity_log (il solo IP), consent_audit e password_resets. Non
-- per:
--   - content_action_log       ip_address, user_agent in chiaro (7 anni)
--   - privileged_access_log    ip_address, user_agent in chiaro (5 anni)
--   - teacher_recovery_audit   ip, user_agent in chiaro (7 anni)
--   - audit_activity_log       user_agent in chiaro (2 anni)
-- Rilevato il 2026-09-03 preparando la nota di aggiornamento per il DPO.
--
-- Le quattro tabelle sono append-only: un trigger BEFORE UPDATE rifiuta ogni
-- modifica (tools/security/apply_audit_append_only.php). Per convertire le
-- righe esistenti il trigger di UPDATE viene tolto e rimesso, identico, alla
-- fine di ogni blocco: la protezione manca solo per la durata degli statement
-- qui sotto, dentro la migration, con l'utenza delle migration. Il trigger di
-- DELETE non si tocca. Dove il trigger di UPDATE non esisteva (installazione
-- mai protetta) viene creato: e' la politica dichiarata per queste tabelle.
--
-- Convenzione: UNHEX(SHA2(valore, 256)) = hash('sha256', valore, true) di PHP,
-- 32 byte grezzi in VARBINARY(32), come consent_audit e
-- audit_activity_log.ip_hash. Per l'IP si prende il primo elemento di un
-- eventuale elenco X-Forwarded-For (RequestFingerprint fa lo stesso); 'unknown'
-- e stringa vuota diventano NULL. Lo User-Agent era gia' troncato in
-- scrittura, quindi l'hash del valore conservato coincide con quello che
-- RequestFingerprint calcola oggi sullo stesso User-Agent.
--
-- Rollback: non previsto. I valori in chiaro non sono ricostruibili
-- dall'hash, ed e' esattamente lo scopo.

-- ── content_action_log ──────────────────────────────────────────────────────

DROP TRIGGER IF EXISTS trg_append_only_content_action_log_update;

ALTER TABLE content_action_log
    ADD COLUMN IF NOT EXISTS ip_hash VARBINARY(32) NULL AFTER details_json,
    ADD COLUMN IF NOT EXISTS ua_hash VARBINARY(32) NULL AFTER ip_hash;

UPDATE content_action_log
   SET ip_hash = CASE
                   WHEN ip_address IS NULL OR TRIM(SUBSTRING_INDEX(ip_address, ',', 1)) IN ('', 'unknown') THEN NULL
                   ELSE UNHEX(SHA2(TRIM(SUBSTRING_INDEX(ip_address, ',', 1)), 256))
                 END,
       ua_hash = CASE
                   WHEN user_agent IS NULL OR user_agent = '' THEN NULL
                   ELSE UNHEX(SHA2(user_agent, 256))
                 END;

ALTER TABLE content_action_log
    DROP COLUMN IF EXISTS ip_address,
    DROP COLUMN IF EXISTS user_agent;

CREATE TRIGGER trg_append_only_content_action_log_update
    BEFORE UPDATE ON content_action_log
    FOR EACH ROW SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'append-only: content_action_log non ammette modifiche';

-- ── privileged_access_log ───────────────────────────────────────────────────

DROP TRIGGER IF EXISTS trg_append_only_privileged_access_log_update;

ALTER TABLE privileged_access_log
    ADD COLUMN IF NOT EXISTS ip_hash VARBINARY(32) NULL AFTER reason,
    ADD COLUMN IF NOT EXISTS ua_hash VARBINARY(32) NULL AFTER ip_hash;

UPDATE privileged_access_log
   SET ip_hash = CASE
                   WHEN ip_address IS NULL OR TRIM(SUBSTRING_INDEX(ip_address, ',', 1)) IN ('', 'unknown') THEN NULL
                   ELSE UNHEX(SHA2(TRIM(SUBSTRING_INDEX(ip_address, ',', 1)), 256))
                 END,
       ua_hash = CASE
                   WHEN user_agent IS NULL OR user_agent = '' THEN NULL
                   ELSE UNHEX(SHA2(user_agent, 256))
                 END;

ALTER TABLE privileged_access_log
    DROP COLUMN IF EXISTS ip_address,
    DROP COLUMN IF EXISTS user_agent;

CREATE TRIGGER trg_append_only_privileged_access_log_update
    BEFORE UPDATE ON privileged_access_log
    FOR EACH ROW SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'append-only: privileged_access_log non ammette modifiche';

-- ── teacher_recovery_audit ──────────────────────────────────────────────────

DROP TRIGGER IF EXISTS trg_append_only_teacher_recovery_audit_update;

ALTER TABLE teacher_recovery_audit
    ADD COLUMN IF NOT EXISTS ip_hash VARBINARY(32) NULL AFTER action,
    ADD COLUMN IF NOT EXISTS ua_hash VARBINARY(32) NULL AFTER ip_hash;

UPDATE teacher_recovery_audit
   SET ip_hash = CASE
                   WHEN ip IS NULL OR TRIM(SUBSTRING_INDEX(ip, ',', 1)) IN ('', 'unknown') THEN NULL
                   ELSE UNHEX(SHA2(TRIM(SUBSTRING_INDEX(ip, ',', 1)), 256))
                 END,
       ua_hash = CASE
                   WHEN user_agent IS NULL OR user_agent = '' THEN NULL
                   ELSE UNHEX(SHA2(user_agent, 256))
                 END;

ALTER TABLE teacher_recovery_audit
    DROP COLUMN IF EXISTS ip,
    DROP COLUMN IF EXISTS user_agent;

CREATE TRIGGER trg_append_only_teacher_recovery_audit_update
    BEFORE UPDATE ON teacher_recovery_audit
    FOR EACH ROW SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'append-only: teacher_recovery_audit non ammette modifiche';

-- ── audit_activity_log (solo lo User-Agent: l'IP era gia' hash) ─────────────

DROP TRIGGER IF EXISTS trg_append_only_audit_activity_log_update;

ALTER TABLE audit_activity_log
    ADD COLUMN IF NOT EXISTS ua_hash VARBINARY(32) NULL AFTER ip_hash;

UPDATE audit_activity_log
   SET ua_hash = CASE
                   WHEN user_agent IS NULL OR user_agent = '' THEN NULL
                   ELSE UNHEX(SHA2(user_agent, 256))
                 END;

ALTER TABLE audit_activity_log
    DROP COLUMN IF EXISTS user_agent;

CREATE TRIGGER trg_append_only_audit_activity_log_update
    BEFORE UPDATE ON audit_activity_log
    FOR EACH ROW SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'append-only: audit_activity_log non ammette modifiche';
