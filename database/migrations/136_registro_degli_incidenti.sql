-- 136 — il registro delle violazioni: immutabile davvero, e con il titolare del dato (22/9/2026).
--
-- Due difetti, trovati confrontando le affermazioni dei documenti con lo schema.
--
-- 1) «APPEND-ONLY» ERA UNA PAROLA.
--    La migrazione 060 apre con «Append-only register dei data breach
--    (incidenti rilevati). Conservazione permanente (audit + accountability
--    Art. 5 §2)». Ma nessun trigger lo imponeva: la 100 ne mette su altre
--    quattro tabelle, non su questa. `DataBreachRepository` fa UPDATE.
--
--    Il rimedio NON è vietare ogni UPDATE: questa tabella ha un flusso di
--    lavoro (detected → assessing → notified_garante → notified_users →
--    closed) e le date di notifica si scrivono per forza dopo l'inserimento.
--    Bloccare tutto romperebbe il registro invece di proteggerlo.
--
--    Si proteggono i fatti, che sono tre:
--      - `detected_at`, perché da lì decorrono le 72 ore dell'art. 33: è
--        l'unica data che, spostata, falsifica il rispetto del termine;
--      - `occurred_at`, la stima di quando è successo;
--      - le date di notifica UNA VOLTA SCRITTE: si può passare da «non
--        notificato» a «notificato», mai il contrario né a un'altra data.
--        Non si può disdire una notifica fatta.
--    Tutto il resto resta modificabile, perché l'analisi evolve: è la
--    differenza fra un registro e un verbale.
--
--    E la cancellazione non si fa: quella sì, mai.
--
-- 2) NON SI POTEVA DIRE DI CHI FOSSE IL DATO.
--    Il rischio R20 della valutazione d'impatto dice che un docente può
--    scrivere in un campo a testo libero un dato che riguarda uno studente. Di
--    quel dato è titolare l'ISTITUTO presso cui insegna, non l'operatore della
--    piattaforma: il docente lo tratta come autorizzato della sua scuola.
--
--    Se un dato così trapela, l'operatore deve avvisare quell'Istituto senza
--    ingiustificato ritardo, perché è l'unico che può saperlo e solo
--    l'Istituto può adempiere verso i propri interessati. Il registro però non
--    aveva né un modo per dire di chi fosse il dato, né uno per dire che lo si
--    era avvisato: c'erano `notified_garante_at` e `notified_users_at`, non
--    una data per il titolare.
--
--    Si aggiungono quattro colonne, tutte nullable: la stragrande maggioranza
--    degli incidenti riguarda dati di cui il titolare è l'operatore, e per
--    quelli restano vuote.
--
--    `titolare_esterno` esiste accanto a `institute_id` perché il titolare
--    potrebbe non essere un Istituto presente in tabella — e perché un nome
--    scritto nella riga sopravvive alla cancellazione dell'Istituto, mentre
--    una chiave esterna no. In un registro di accountability il fatto deve
--    restare leggibile anche fra dieci anni.
--
-- SICUREZZA: solo aggiunte nullable e trigger. Il codice della versione
-- precedente non conosce le colonne nuove e continua a funzionare: gli INSERT
-- del repository elencano le colonne, gli UPDATE toccano solo campi ammessi
-- dal trigger. Misurato prima di scriverla: zero righe nel registro, quindi
-- nessun dato esistente può violare i vincoli nuovi.
--
-- ROLLBACK: asimmetrico di proposito.
--     DROP TRIGGER IF EXISTS trg_incidenti_fatti_immutabili;
--     DROP TRIGGER IF EXISTS trg_incidenti_non_si_cancellano;
--     ALTER TABLE data_breach_incidents
--         DROP FOREIGN KEY fk_incidenti_istituto,
--         DROP COLUMN institute_id, DROP COLUMN titolare_esterno,
--         DROP COLUMN notified_controller_at,
--         DROP COLUMN controller_notification_method;
-- Togliere le colonne cancella l'informazione su chi è stato avvisato, che è
-- esattamente ciò che il registro serve a dimostrare: si torna indietro solo
-- sui trigger, salvo aver messo al sicuro le righe.

ALTER TABLE data_breach_incidents
    ADD COLUMN IF NOT EXISTS institute_id INT UNSIGNED NULL
        COMMENT 'Istituto titolare dei dati coinvolti, quando non è l operatore (R20)'
        AFTER data_categories,
    ADD COLUMN IF NOT EXISTS titolare_esterno VARCHAR(255) NULL
        COMMENT 'Il titolare del dato in parole, quando non è un Istituto in tabella o per tenerne il nome'
        AFTER institute_id,
    ADD COLUMN IF NOT EXISTS notified_controller_at DATETIME NULL
        COMMENT 'Quando il titolare esterno è stato avvisato (art. 33: senza ingiustificato ritardo)'
        AFTER notified_users_at,
    ADD COLUMN IF NOT EXISTS controller_notification_method VARCHAR(64) NULL
        COMMENT 'Come: email|pec|telefono|raccomandata'
        AFTER notified_controller_at;

-- La chiave esterna resta staccabile: SET NULL, perché il nome sta comunque in
-- `titolare_esterno` e un registro non deve impedire di riorganizzare gli
-- Istituti.
ALTER TABLE data_breach_incidents
    ADD CONSTRAINT fk_incidenti_istituto
        FOREIGN KEY IF NOT EXISTS (institute_id) REFERENCES institutes(id)
        ON DELETE SET NULL ON UPDATE CASCADE;

DELIMITER //

DROP TRIGGER IF EXISTS trg_incidenti_fatti_immutabili//

CREATE TRIGGER trg_incidenti_fatti_immutabili
BEFORE UPDATE ON data_breach_incidents
FOR EACH ROW
BEGIN
    IF NOT (OLD.detected_at <=> NEW.detected_at) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro incidenti: detected_at non si corregge, da lì decorrono le 72 ore';
    END IF;
    IF NOT (OLD.occurred_at <=> NEW.occurred_at) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro incidenti: occurred_at non si corregge';
    END IF;
    IF OLD.notified_garante_at IS NOT NULL AND NOT (OLD.notified_garante_at <=> NEW.notified_garante_at) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro incidenti: una notifica al Garante già fatta non si disdice';
    END IF;
    IF OLD.notified_users_at IS NOT NULL AND NOT (OLD.notified_users_at <=> NEW.notified_users_at) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro incidenti: una comunicazione agli interessati già fatta non si disdice';
    END IF;
    IF OLD.notified_controller_at IS NOT NULL AND NOT (OLD.notified_controller_at <=> NEW.notified_controller_at) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'registro incidenti: un avviso al titolare già fatto non si disdice';
    END IF;
END//

DROP TRIGGER IF EXISTS trg_incidenti_non_si_cancellano//

CREATE TRIGGER trg_incidenti_non_si_cancellano
BEFORE DELETE ON data_breach_incidents
FOR EACH ROW
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'registro incidenti: conservazione permanente, una riga non si cancella'//

DELIMITER ;
