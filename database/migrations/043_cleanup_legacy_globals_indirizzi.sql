-- G22.S21 hotfix — Pulizia indirizzi legacy globali (institute_id IS NULL).
--
-- Strategia B (drop legacy globals, vedi 041_drop_legacy_globals.sql)
-- prevedeva che TUTTE le curriculum_entries con institute_id NULL fossero
-- rimosse dopo replicazione per istituto.
--
-- Bug osservato su local DB: 041 marcato come applicato senza eseguire
-- l'ultimo DELETE → restano 45 indirizzi institute_id NULL (ART, AFM,
-- CLA, LIN, SCI duplicati). Su VPS 041 ha eseguito correttamente.
--
-- I FK indirizzo_id su teacher_content/verifica_documents/exercises/
-- curriculum_users sono gia' stati ri-mappati a copie institute-scoped
-- (step 2-4 di 041). Quindi DELETE qui e' sicuro.
--
-- Idempotente: no-op se l'ambiente e' gia' pulito.

DELETE FROM curriculum_entries WHERE institute_id IS NULL;
