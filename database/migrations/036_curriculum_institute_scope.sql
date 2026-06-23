-- Phase G22.S15.bis Fase 5+ — curriculum scope per istituto.
--
-- curriculum_entries diventa per-istituto: ogni Galileo/ITIS/etc ha le
-- proprie materie, classi, indirizzi. La view UI in /area-docente/profilo
-- (rimosso /admin/curriculum) mostra il catalog dell'istituto attivo del
-- docente, modificabile da chi e' collegato a quel istituto.
--
-- Strategia migrazione SOFT (no big-bang clone):
--   - institute_id NULLABLE: entries esistenti restano NULL = "globali
--     fallback" (visibili da tutti gli istituti come catalog legacy)
--   - Nuove entries CREATE specificano institute_id (NULL non permesso)
--   - Pivot curriculum_users invariato (FK su entry.id stabile)
--   - Admin puo' eventualmente "adottare" entries NULL nel proprio istituto
--     manualmente in seguito (fuori scope di questa migration)
--
-- Constraint UNIQUE: (kind, code, institute_id) — permette stesso `code`
-- in istituti diversi (es. "MAT" globale + "MAT" custom Galileo).

ALTER TABLE curriculum_entries
    ADD COLUMN institute_id INT UNSIGNED NULL AFTER kind,
    ADD CONSTRAINT fk_ce_institute FOREIGN KEY (institute_id)
        REFERENCES institutes(id) ON DELETE CASCADE;

-- Drop old unique on (kind, code) global, replace with per-institute.
ALTER TABLE curriculum_entries DROP INDEX uq_curriculum;
ALTER TABLE curriculum_entries
    ADD UNIQUE KEY uq_curriculum_inst (kind, code, institute_id);

-- Index per query frequente: catalog di un istituto.
ALTER TABLE curriculum_entries
    ADD INDEX idx_curriculum_inst_active (institute_id, kind, active);
