-- SICUREZZA: converte un valore di ruolo che il codice di prima non sapeva usare (`admin`, nessuna zona lo conteneva), aggiunge un vincolo sui ruoli e due trigger che rifiutano un amministratore di istituto senza istituto. Misurato il 14/9/2026: in produzione, sviluppo e prova nessuna riga `admin` e nessun `admin_institute_id` valorizzato, quindi nessuna riga cambia; il codice di prima non scrive valori che vincolo e trigger rifiutano, tranne il wizard degli istituti, che con questa versione non scrive più `admin`.
-- ROLLBACK: ALTER TABLE users DROP CONSTRAINT chk_users_ruolo; DROP TRIGGER IF EXISTS trg_users_amm_istituto_bi, trg_users_amm_istituto_bu; e, se servisse, UPDATE users SET role = 'admin' WHERE role = 'institute_admin'.
--
-- L'amministratore di istituto (ADR-040, 14/9/2026).
--
-- Un ruolo suo, `institute_admin`, con l'istituto che amministra in
-- `admin_institute_id` (migrazione 059). Prima il wizard creava `role='admin'`,
-- un valore che nessuna zona di accesso conosceva e che `Role::tryFromString`
-- traduceva in amministratore della piattaforma.
--
-- L'identità non deve dipendere da chi scrive la riga:
--   - il ruolo è uno di quelli che l'applicazione conosce: un vincolo CHECK;
--   - `admin_institute_id` c'è se e solo se il ruolo è `institute_admin`, e un
--     amministratore di istituto non è super-amministratore: due trigger.
--
-- PERCHE' TRIGGER E NON CHECK, PER IL SECONDO. MariaDB non accetta in un CHECK
-- una colonna con una chiave esterna che la modifica (errore 1901), e
-- `fk_users_admin_institute` ha `ON DELETE SET NULL`. Provato il 14/9 sul
-- database di prova. La chiave esterna resta com'è: se un istituto viene
-- cancellato, la cascata toglie l'istituto al suo amministratore (i trigger
-- non scattano sulle cascate), e un `institute_admin` senza istituto non
-- amministra niente — `Auth` risponde di no a ogni domanda d'ambito.

SET NAMES utf8mb4;

-- 1. Le righe `admin` che ci fossero.
UPDATE users SET role = 'administrator'
 WHERE role = 'admin' AND is_super_admin = 1;
UPDATE users SET role = 'institute_admin'
 WHERE role = 'admin' AND admin_institute_id IS NOT NULL AND is_super_admin = 0;
-- Un `admin` senza istituto e senza poteri non ha un significato: diventa un
-- docente non attivo, che un amministratore riattiva con il ruolo giusto.
UPDATE users SET role = 'teacher', active = 0
 WHERE role = 'admin';
-- Un istituto amministrato su un ruolo che non lo amministra.
UPDATE users SET admin_institute_id = NULL
 WHERE role <> 'institute_admin' AND admin_institute_id IS NOT NULL;

-- 2. I ruoli che l'applicazione conosce.
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_ruolo;
ALTER TABLE users
    ADD CONSTRAINT chk_users_ruolo
        CHECK (role IN ('student', 'teacher', 'collaborator', 'administrator', 'institute_admin'));

-- 3. L'amministratore di istituto ha il suo istituto, e solo lui.
DELIMITER //

DROP TRIGGER IF EXISTS trg_users_amm_istituto_bi//
CREATE TRIGGER trg_users_amm_istituto_bi BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    IF (NEW.role = 'institute_admin') <> (NEW.admin_institute_id IS NOT NULL)
       OR (NEW.role = 'institute_admin' AND NEW.is_super_admin = 1) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'amministratore di istituto: admin_institute_id solo e sempre con role institute_admin, mai super-amministratore (ADR-040)';
    END IF;
END//

DROP TRIGGER IF EXISTS trg_users_amm_istituto_bu//
CREATE TRIGGER trg_users_amm_istituto_bu BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    IF (NEW.role = 'institute_admin') <> (NEW.admin_institute_id IS NOT NULL)
       OR (NEW.role = 'institute_admin' AND NEW.is_super_admin = 1) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'amministratore di istituto: admin_institute_id solo e sempre con role institute_admin, mai super-amministratore (ADR-040)';
    END IF;
END//

DELIMITER ;
