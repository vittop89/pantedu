-- Phase PDF-Import — aggiunge lo stato 'cancelled' (pulsante Stop).
-- La sessione interrotta dall'utente diventa terminale 'cancelled' (il worker
-- background la abbandona; il poll smette). Senza questo valore l'UPDATE veniva
-- rifiutato (ENUM truncation) e lo Stop non aveva effetto.

ALTER TABLE pdf_import_sessions
    MODIFY status ENUM('uploaded','rasterized','extracting','extracted',
                       'reviewing','inserting','inserted','failed','retry','cancelled')
                  NOT NULL DEFAULT 'uploaded';
