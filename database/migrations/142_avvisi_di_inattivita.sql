-- Gli avvisi prima della cancellazione di un account inattivo (24/9/2026).
--
-- PERCHÉ
--
-- Un account senza accessi da 730 giorni si cancella con la routine dell'art.
-- 17 (CancellazioneDellAccount). Decisione del titolare del 24/9/2026: prima
-- riceve tre avvisi via email, 60, 30 e 7 giorni prima, e nessun account si
-- cancella se l'avviso dei 7 giorni non è partito almeno 7 giorni prima.
-- Questa tabella ricorda quali avvisi sono partiti, per quale data di
-- cancellazione: un accesso sposta la data, e gli avvisi vecchi non contano
-- più.
--
-- Una riga per utente, data di cancellazione e fase (60, 30 o 7 giorni prima):
-- il vincolo unico impedisce di mandare due volte lo stesso avviso anche se il
-- lavoro notturno gira due volte. Le righe restano dopo la cancellazione
-- dell'account (la riga di users resta come segnaposto): sono la prova che gli
-- avvisi sono partiti.

CREATE TABLE IF NOT EXISTS avvisi_di_inattivita (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED NOT NULL,
    data_cancellazione DATETIME     NOT NULL,
    fase               SMALLINT UNSIGNED NOT NULL COMMENT 'giorni prima della cancellazione: 60, 30 o 7',
    inviato_at         DATETIME     NOT NULL,
    UNIQUE KEY uniq_avviso (user_id, data_cancellazione, fase),
    INDEX idx_avvisi_utente (user_id, data_cancellazione),
    CONSTRAINT fk_avvisi_di_inattivita_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
