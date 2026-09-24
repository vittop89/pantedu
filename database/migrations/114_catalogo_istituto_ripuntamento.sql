-- 114 — ADR-035, fase 2: i contenuti puntano alla voce dell'istituto, non alla copia del docente.
--
-- SICUREZZA: aggiorna soltanto chiavi esterne, da una riga copia
-- (`owner_user_id = docente`) alla riga di vocabolario con lo stesso (kind,
-- code, istituto). Nessuna riga viene cancellata. Il codice del rilascio
-- precedente legge codice ed etichetta dalla riga puntata con un JOIN sull'id,
-- e la riga puntata esiste: continua a funzionare. Un contenuto non cambia
-- classe né materia, cambia la riga che la rappresenta.
-- ROLLBACK: nessuno necessario per far girare il codice precedente. Per
-- tornare alle copie si ripristina l'istantanea che il rilascio prende prima
-- di migrare; le copie sono ancora tutte lì (le toglie la migrazione
-- successiva, in un rilascio a parte).
--
-- La garanzia che serve — un conteggio per (docente, codice) uguale prima e
-- dopo, in ogni tabella — la dà `tools/curriculum/catalogo_fase0.php`: prima
-- della migrazione conta i riferimenti «su copia con ancora», dopo li conta
-- «su vocabolario», e i totali per tabella non devono cambiare.
--
-- Le chiavi di classe (`classe_keys_data`) hanno un indice unico su
-- (indirizzo_id, classe_id, anno, versione): se in produzione esistessero due
-- chiavi per la stessa aula reale create da due docenti, il ri-puntamento
-- collisione e la migrazione FALLISCE — che è quello che deve fare, perché i
-- contenuti cifrati con una chiave non si aprono con l'altra. La fase 0 ha
-- misurato zero chiavi in produzione e in sviluppo il 13 settembre 2026.
--
-- Idempotente: al secondo giro nessun riferimento punta più a una copia.

SET NAMES utf8mb4;

UPDATE teacher_content_data t
  JOIN curriculum_entries c ON c.id = t.classe_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.classe_id = a.id;
UPDATE teacher_content_data t
  JOIN curriculum_entries c ON c.id = t.subject_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.subject_id = a.id;
UPDATE teacher_content_data t
  JOIN curriculum_entries c ON c.id = t.indirizzo_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.indirizzo_id = a.id;

UPDATE verifica_documents_data t
  JOIN curriculum_entries c ON c.id = t.classe_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.classe_id = a.id;
UPDATE verifica_documents_data t
  JOIN curriculum_entries c ON c.id = t.materia_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.materia_id = a.id;
UPDATE verifica_documents_data t
  JOIN curriculum_entries c ON c.id = t.indirizzo_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.indirizzo_id = a.id;

UPDATE print_info_data t
  JOIN curriculum_entries c ON c.id = t.classe_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.classe_id = a.id;
UPDATE print_info_data t
  JOIN curriculum_entries c ON c.id = t.materia_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.materia_id = a.id;
UPDATE print_info_data t
  JOIN curriculum_entries c ON c.id = t.indirizzo_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.indirizzo_id = a.id;

UPDATE risdoc_compilations_data t
  JOIN curriculum_entries c ON c.id = t.classe_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.classe_id = a.id;
UPDATE risdoc_compilations_data t
  JOIN curriculum_entries c ON c.id = t.indirizzo_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.indirizzo_id = a.id;

UPDATE teacher_access_credentials_data t
  JOIN curriculum_entries c ON c.id = t.classe_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.classe_id = a.id;
UPDATE teacher_access_credentials_data t
  JOIN curriculum_entries c ON c.id = t.indirizzo_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.indirizzo_id = a.id;

UPDATE published_content_data t
  JOIN curriculum_entries c ON c.id = t.subject_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.subject_id = a.id;

UPDATE pdf_import_sessions t
  JOIN curriculum_entries c ON c.id = t.classe_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.classe_id = a.id;
UPDATE pdf_import_sessions t
  JOIN curriculum_entries c ON c.id = t.subject_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.subject_id = a.id;
UPDATE pdf_import_sessions t
  JOIN curriculum_entries c ON c.id = t.indirizzo_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.indirizzo_id = a.id;

UPDATE classe_keys_data t
  JOIN curriculum_entries c ON c.id = t.classe_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.classe_id = a.id;
UPDATE classe_keys_data t
  JOIN curriculum_entries c ON c.id = t.indirizzo_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.indirizzo_id = a.id;

-- exercises_data (lo studio legacy, voce 32 del debito: in produzione le sue
-- 57 righe puntano gia' al vocabolario, ma la regola vale per ogni tabella
-- che referenzia curriculum_entries)
UPDATE exercises_data t
  JOIN curriculum_entries c ON c.id = t.classe_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.classe_id = a.id;
UPDATE exercises_data t
  JOIN curriculum_entries c ON c.id = t.materia_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.materia_id = a.id;
UPDATE exercises_data t
  JOIN curriculum_entries c ON c.id = t.indirizzo_id AND c.owner_user_id IS NOT NULL
  JOIN curriculum_entries a ON a.kind = c.kind AND a.code = c.code AND a.institute_id <=> c.institute_id AND a.owner_user_id IS NULL
   SET t.indirizzo_id = a.id;
