# app/Services/Waf — WAF applicativo

Stack WAF a livello PHP (pre-route), orchestrato da [`App\Middleware\WafMiddleware`](../../Middleware/WafMiddleware.php) e configurato a runtime dal DB (`waf_config`, `waf_rules`, `waf_*` tables). Admin UI: `/admin/waf` (super_admin). Entry point dei finding/test: vedi `docs/todo/security-history.md` §16.5-16.6.

## File chiave

| File | Ruolo |
|------|-------|
| `WafConfigRepository.php` | Lettura config WAF dal DB (`enabled`, `mode` off/monitor/enforce/under_attack, soglie) |
| `WafRulesService.php` | Valutazione regole custom (field/operator/action, incl. `asn_in_category`) |
| `EdgeContext.php` | Estrazione contesto richiesta anti-spoof (IP reale, header) |
| `GeoIpService.php` | Lookup GeoIP/ASN (db-ip Lite, .mmdb in `storage/geoip/`) |
| `WafBruteforceGuard.php` | Protezione brute-force NAT-safe |
| `WafProofOfWork.php` | Challenge PoW per client sospetti |
| `WafCrowdSecBouncerService.php` | Bouncer CrowdSec self-hosted (LAPI locale) |
| `WafLogService.php` | Scrittura `waf_logs` (outcome per ogni richiesta) |

## Note operative

- **Config nel DB, non in file**: per capire il comportamento runtime guarda `waf_config`/`waf_rules`, non solo il codice. Diagnostica senza SSH: `/admin/waf/diag`.
- **Modalità**: `off` (bypass) → `monitor` (log, no block) → `enforce` (block) → `under_attack` (challenge sempre). Cambiare con cautela in orari a basso traffico.
- **Threat intel / honeypot / 2FA**: layer Phase 25.H-J (tabelle `waf_threat_*`, `waf_asn_categories`), test matrix in `docs/todo/security-history.md` §16.6.
- **Layer 0 infra** (nginx/ModSecurity, fail2ban, Suricata): fuori da questo namespace → `tools/dev/hardening/` + history §16.7.

## Pattern

Ogni modulo security aggiunto richiede **test matrix esplicita** (setup/expected/verify) — vedi convenzione in `security-history.md`. No "deploy + speriamo".
