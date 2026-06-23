#!/usr/bin/env bash
# Setup per la suite E2E studio/esercizio (tests/e2e/studio_eser_*.spec.js).
#
# Crea (idempotente) una COPIA DI LAVORO dell'esercizio reale 1291 → riga
# teacher_content "PROOF-COPY" (id stampato) sullo slot SCI/3/MAT/topic 7, con
# un contract_key dedicato, così i test di editing non toccano contenuti reali.
# Poi genera il contract ricco (3 gruppi, liste nested, TikZ+GeoGebra per riga,
# formattazione, TikZ complesso) via gen_proof_contract.cjs.
#
# Prerequisiti: XAMPP MySQL attivo (pantedu_dev, root no-password); l'esercizio
# reale 1291 deve esistere (teacher 77 = superadmin).
#
# Uso:  bash tools/dev/setup_studio_eser_e2e.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql}"
DB="-h127.0.0.1 -uroot pantedu_dev"

# 1. copia di lavoro (riusa se già presente)
EX=$("$MYSQL" $DB -N -e "SELECT id FROM teacher_content_data WHERE title LIKE 'PROOF-COPY%' AND topic='7' AND content_subtype='esercizio' ORDER BY id DESC LIMIT 1" 2>/dev/null || true)
if [ -n "$EX" ]; then
  echo "PROOF-COPY già presente: id=$EX"
else
  "$MYSQL" $DB -e "
    SET @nid := (SELECT MAX(id)+1 FROM teacher_content_data);
    INSERT INTO teacher_content_data (id,teacher_id,content_subtype,content_format,section_id,subject_id,indirizzo_id,classe_id,topic,title,metadata_json,visibility,publish_scope,created_at,updated_at)
    SELECT @nid,teacher_id,content_subtype,content_format,section_id,subject_id,indirizzo_id,classe_id,topic,'PROOF-COPY di 1291 (test toolbar)',REPLACE(metadata_json,'7.contract.json','7-proofcopy.contract.json'),'draft',publish_scope,NOW(),NOW()
    FROM teacher_content_data WHERE id=1291;
    SELECT CONCAT('PROOF-COPY creata: id=', @nid) AS msg;"
fi

# 2. genera il contract ricco della copia
node tools/dev/gen_proof_contract.cjs
echo "Setup completato."
