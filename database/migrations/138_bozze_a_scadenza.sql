-- 138 — Le bozze dei modelli hanno una scadenza (2026-09-22).
--
-- PERCHE'
--
-- Una compilazione di un modello risdoc e' cio' che il docente scrive dentro
-- un atto dell'Istituto: relazione finale, scheda di recupero, piano annuale.
-- Per natura puo' nominare uno studente, e per i modelli istituzionali
-- (categoria `modelli`) lo fa quasi sempre. Restava sul server per sempre.
--
-- Finche' resta, resta anche il rischio: ogni giorno in piu' e' un giorno in
-- cui quei dati possono uscire da una violazione. L'art. 5(1)(c) del GDPR non
-- chiede di non tenerli, chiede di non tenerli piu' del necessario — e il
-- necessario finisce quando il docente ha il documento in mano.
--
-- LE DUE COLONNE
--
--   `exported_at`       quando il docente ha SCARICATO il PDF. Non quando ha
--                       aperto l'anteprima, non quando il server ha compilato:
--                       quelle cose succedono mentre si lavora. Scaricare e'
--                       l'unico gesto in cui il docente prende possesso di una
--                       copia, ed e' la condizione perche' cancellare la bozza
--                       sul server non gli tolga niente.
--
--   `expiry_warned_at`  quando gli e' stato scritto che la bozza sta per
--                       scadere. Senza questa colonna l'avviso ripartirebbe
--                       ogni notte, e un avviso che arriva tutti i giorni e'
--                       un avviso che dopo tre giorni non si legge piu'.
--
-- CHE COSA CANCELLA, E QUANDO — la regola vive nel codice
-- (`App\Services\Risdoc\ScadenzaDelleBozze`), non qui: una soglia scritta in
-- due posti diverge, e prima o poi si crede a quella sbagliata. Qui ci sono
-- solo le colonne e l'indice che rende la spazzata economica.
--
-- LA TRAPPOLA DELLA VISTA
--
-- `risdoc_compilations` e' una VISTA sulla tabella vera
-- `risdoc_compilations_data`, e la sua SELECT e' `rc.*`: MariaDB espande
-- l'asterisco **al momento della creazione** e se lo tiene. Senza ricreare la
-- vista, le due colonne nuove esistono nella tabella e non si vedono da
-- nessuna query che passi dalla vista — cioe' da quasi tutte, perche'
-- `CompilationRepository` legge da li'. E' la stessa nota che la migrazione
-- 101 ha lasciato scritta dopo averci sbattuto.
--
-- Rollback:
--   ALTER TABLE risdoc_compilations_data
--       DROP COLUMN exported_at, DROP COLUMN expiry_warned_at;
--   e poi ricreare la vista con la definizione della 101.
-- Non si perde niente: sono due date di servizio, non contenuto del docente.

ALTER TABLE risdoc_compilations_data
    ADD COLUMN IF NOT EXISTS exported_at      DATETIME NULL DEFAULT NULL
        COMMENT 'Quando il docente ha scaricato il PDF: fa partire la grazia.',
    ADD COLUMN IF NOT EXISTS expiry_warned_at DATETIME NULL DEFAULT NULL
        COMMENT 'Quando gli e'' stato scritto che la bozza sta per scadere.';

-- La spazzata notturna cerca due cose: le scadute per esportazione
-- (exported_at valorizzato e vecchio) e le abbandonate (updated_at vecchio).
-- Un indice su entrambe le date evita che diventi una scansione della tabella
-- il giorno in cui le righe non sono piu' sessantuno.
ALTER TABLE risdoc_compilations_data
    ADD INDEX IF NOT EXISTS idx_rc_scadenza (exported_at, updated_at);

-- La vista: `rc.*` va riespanso, altrimenti le colonne nuove non escono.
--
-- `ALGORITHM=UNDEFINED SQL SECURITY DEFINER` e' scritto per esteso di
-- proposito. Le due definizioni precedenti (040 e 101) non lo scrivevano e
-- prendevano il default di MariaDB, che per caso e' lo stesso; misurato il
-- 22/9/2026, la vista in servizio e' DEFINER con ALGORITHM=UNDEFINED. Una
-- ricreazione che tacesse affiderebbe il regime al default del giorno, e un
-- cambio di regime su una vista che porta contenuti cifrati per docente non e'
-- una cosa da lasciare a un valore implicito. E' anche la forma che usano le
-- migrazioni piu' recenti (121:97 per `teacher_content`).
--
-- Cio' che NON si puo' conservare e' il DEFINER: qualunque CREATE lo lega a
-- chi esegue la migrazione. Vale gia' oggi per la 040 e la 101, e resta la
-- trappola del ripristino (un dump non porta gli utenti, e una vista col
-- DEFINER di un utente che non esiste smette di funzionare).
CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW risdoc_compilations AS
SELECT rc.*,
       ci.code AS indirizzo,
       cc.code AS classe
FROM risdoc_compilations_data rc
LEFT JOIN curriculum_entries ci ON ci.id = rc.indirizzo_id AND ci.kind='indirizzi'
LEFT JOIN curriculum_entries cc ON cc.id = rc.classe_id    AND cc.kind='classi';
