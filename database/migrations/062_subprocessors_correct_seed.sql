-- Phase 25.R.4.1 follow-up — correzione seed subprocessors.
--
-- 060 aveva inserito "il provider di hosting — Web hosting" che era ereditato dalla
-- vecchia infrastruttura legacy. Ora:
--   - Hosting reale: Hetzner Cloud (nbg1 Nuremberg, host pantedu-app-nbg1-1)
--   - Storage backup: Backblaze B2 (bucket pantedu-backup-vps, eu-central-003)
--   - hosting legacy: SOLO registrar dominio (DNS) → non sub-processor di trattamento dati
--   - Google: opt-in OAuth + Drive (resta)
--
-- Strategy: marca hosting legacy come inactive (preserva history audit), inserisce
-- Hetzner + Backblaze come active. Niente DROP/UPDATE distruttivo —
-- mantiene tracciabilità delle revisioni.
--
-- Rollback: UPDATE subprocessors SET active=1 WHERE name='il provider di hosting';
--           DELETE FROM subprocessors WHERE name IN ('Hetzner Online GmbH','Backblaze Inc.');
-- ═════════════════════════════════════════════════════════════════════════

-- Deattiva il vecchio hosting legacy (hosting legacy in dismissione, no più trattamento)
UPDATE subprocessors
   SET active = 0,
       service_description = 'Hosting legacy in dismissione — dominio resta su hosting condiviso (registrar DNS)'
 WHERE name = 'il provider di hosting';

-- Hetzner Cloud — VPS reale
INSERT IGNORE INTO subprocessors
    (name, service_description, country, extra_eu_transfer, transfer_safeguards,
     dpa_signed, dpa_url, contact_email, active)
VALUES (
    'Hetzner Online GmbH',
    'VPS hosting (datacenter nbg1 Nuremberg) — web server + database + storage applicativo',
    'Germania',
    0,
    NULL,
    1,
    'https://www.hetzner.com/AV/DPA_en.pdf',
    'privacy@hetzner.com',
    1
);

-- Backblaze B2 — storage backup cifrato
INSERT IGNORE INTO subprocessors
    (name, service_description, country, extra_eu_transfer, transfer_safeguards,
     dpa_signed, dpa_url, contact_email, active)
VALUES (
    'Backblaze Inc.',
    'Object storage S3-compatible per backup cifrati (bucket pantedu-backup-vps, region eu-central-003 Amsterdam)',
    'USA (storage UE)',
    1,
    'SCC 2021/914 + DPF + bucket region eu-central-003 (Amsterdam, dati a riposo in UE)',
    1,
    'https://www.backblaze.com/company/dpa.html',
    'privacy@backblaze.com',
    1
);
