-- 078_collapse_content_types.sql — ADR-027 / Opzione A (snella)
--
-- Collassa teacher_content.content_type da 8 valori a 4: mappa, esercizio,
-- verifica, document.
--   Mapping:  lab → esercizio
--             bes, risdoc, didattica, documento, ''(vuoto) → document
--             mappa / esercizio / verifica  INVARIATI
--
-- Razionale (audit workflow option-a-content-type-collapse):
--   I content_type legacy non hanno branching COMPORTAMENTALE: formatOf() li
--   riduce già a 3 formati (map/exercise/document). L'unica distinzione reale
--   che sopravvive è esercizio≠verifica (filtro esami + accoppiamento pool +
--   path eser/verifiche). bes/risdoc/didattica si renderizzano IDENTICI
--   (→document) e la loro distinzione (pannello + visibilità) è ora ancorata a
--   section_id (ADR-027), non al content_type → collassano in 'document'.
--
-- teacher_content / published_content sono VISTE (SELECT tc.*) → riflettono il
-- nuovo ENUM automaticamente. published_content_data è vuota (publish =
-- setVisibility sulla stessa riga). content_action_log.content_type è
-- VARCHAR(32) → nessun vincolo. Idempotente (UPDATE filtrano sui valori vecchi).
--
-- NB FK: map_shares.content_id → teacher_content_data.id. Lo shrink ENUM forza
-- un table-rebuild che la FK figlia blocca (errno 1834) → FOREIGN_KEY_CHECKS=0
-- attorno agli ALTER (i valori sono già rimappati, nessun orfano introdotto).

SET FOREIGN_KEY_CHECKS = 0;

-- 1) ENUM superset transitorio (ammette vecchi + nuovo 'document')
ALTER TABLE teacher_content_data
  MODIFY content_type ENUM('mappa','esercizio','lab','verifica','bes','risdoc','didattica','documento','document') NOT NULL DEFAULT 'document';
ALTER TABLE published_content_data
  MODIFY content_type ENUM('mappa','esercizio','lab','verifica','bes','risdoc','didattica','documento','document') NOT NULL DEFAULT 'document';

-- 2) DEDUP non-distruttivo: dopo il collasso più righe del gruppo "document"
-- possono condividere (teacher_id, title) → violazione uq_teach_content_title
-- (teacher_id, content_type, title). Disambigua i titoli collidenti (tutti
-- tranne il MIN(id)) suffissando '#<id>' PRIMA del remap. Nessuna perdita dati.
UPDATE teacher_content_data t
  JOIN (
        SELECT teacher_id, title, MIN(id) AS keep_id
          FROM teacher_content_data
         WHERE content_type IN ('bes','risdoc','didattica','documento','')
      GROUP BY teacher_id, title
        HAVING COUNT(*) > 1
  ) d
    ON t.teacher_id = d.teacher_id
   AND t.title      = d.title
   AND t.content_type IN ('bes','risdoc','didattica','documento','')
   AND t.id <> d.keep_id
  SET t.title = CONCAT(t.title, ' #', t.id);

-- 3) rimappa i valori
UPDATE teacher_content_data  SET content_type='esercizio' WHERE content_type='lab';
UPDATE teacher_content_data  SET content_type='document'  WHERE content_type IN ('bes','risdoc','didattica','documento','');
UPDATE published_content_data SET content_type='esercizio' WHERE content_type='lab';
UPDATE published_content_data SET content_type='document'  WHERE content_type IN ('bes','risdoc','didattica','documento','');

-- 4) ENUM finale a 4 valori
ALTER TABLE teacher_content_data
  MODIFY content_type ENUM('mappa','esercizio','verifica','document') NOT NULL DEFAULT 'document';
ALTER TABLE published_content_data
  MODIFY content_type ENUM('mappa','esercizio','verifica','document') NOT NULL DEFAULT 'document';

SET FOREIGN_KEY_CHECKS = 1;

-- 5) sidebar_sections: default + allowed allineati ai 4 valori
UPDATE sidebar_sections SET default_content_type='esercizio' WHERE default_content_type='lab';
UPDATE sidebar_sections SET default_content_type='document'  WHERE default_content_type IN ('bes','risdoc','didattica','documento','');
UPDATE sidebar_sections SET allowed_content_types='["mappa","esercizio","verifica","document"]';
