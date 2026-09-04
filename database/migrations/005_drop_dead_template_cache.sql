-- Phase 20 — Migration 005 — drop dead table template_cache
--
-- Audit Phase 20: `template_cache` zero uso in app/, views/, tools/ live.
-- Solo backup_mysql_recovery/ (snapshot pre-Phase 14) la referenzia.
-- Ownership + print_info restano (usate da OwnershipService/VerificheService).

DROP TABLE IF EXISTS template_cache;
