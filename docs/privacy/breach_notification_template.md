---
tags:
    - documentazione/gdpr
    - phase/25.C12
date: 2026-04-27
tipo: template
status: vigente
classification: ⚠️ INTERNAL — bozza compilabile per Art. 34 GDPR
aliases: ["breach-notification-template", "data-breach-email"]
---

# Template comunicazione data breach (Art. 34 GDPR)

> Bozza email pre-formulata per notifica data subject quando un breach
> presenta rischio elevato per i loro diritti e libertà. Compilare i campi
> `[BRACKETED]` con i dettagli specifici dell'incidente.
>
> **Notifica obbligatoria** se rischio elevato (Art. 34 §1).
> **Tempistica**: senza ingiustificato ritardo dopo conferma del breach.
> **Forma**: email diretta + avviso in dashboard utente al login.

## Email subject

```
[Pantedu] Comunicazione importante sui tuoi dati personali — Incidente di sicurezza
```

## Email body

---

Gentile **[NOME UTENTE]**,

ti contattiamo per informarti che il **[DATA INCIDENTE — es. 12 marzo 2026]** abbiamo individuato un incidente di sicurezza che potrebbe aver coinvolto alcuni dati personali.

## Cosa è successo

[DESCRIZIONE BREVE — es. "Un accesso non autorizzato al nostro database è stato rilevato e immediatamente bloccato. La causa probabile è stata [vettore]. L'accesso è durato approssimativamente [durata]."]

## Quali dati sono coinvolti

I dati potenzialmente esposti sono:
- [ELENCARE — es. username, email, contenuti didattici]
- [SE RILEVANTE: indicare se password sono coinvolte]

I dati **NON** coinvolti:
- Le password sono memorizzate cifrate (bcrypt) — non sono leggibili in chiaro.
- I contenuti didattici (esercizi, verifiche, mappe) sono cifrati at-rest con AES-256-GCM (envelope encryption Phase 25.D) — anche con accesso al database, il contenuto resta illeggibile senza la chiave master.
- [ALTRO se applicabile]

## Cosa abbiamo fatto

Immediatamente:
- [ELENCARE — es. "Bloccato l'accesso al vettore identificato"]
- [es. "Forzato logout di tutti gli utenti"]
- [es. "Rotated tutte le credenziali di sicurezza interne"]
- Notificato il Garante per la protezione dei dati personali entro 72 ore (Art. 33 GDPR).

Successivamente:
- Audit completo dei log di accesso per identificare l'ambito.
- [ALTRO]

## Cosa puoi fare TU

Per precauzione ti consigliamo di:

1. **Cambiare la tua password** al prossimo login (sarà richiesto automaticamente).
2. **Verificare gli accessi recenti** alla tua dashboard.
3. **Diffidare di email sospette** che simulino comunicazioni Pantedu — non clicchiamo mai link o richieste credenziali via email a meno che tu non l'abbia esplicitamente avviato (es. reset password).
4. Se hai usato la stessa password su altri servizi, **cambiarla anche lì**.

## I tuoi diritti

Puoi:
- **Esercitare i tuoi diritti GDPR** via [/dpo-contact](https://pantedu.eu/dpo-contact) (accesso, rettifica, oblio, portabilità).
- **Cancellare il tuo account** in qualsiasi momento via [/me/request-deletion](https://pantedu.eu/me/request-deletion) — la cancellazione include crypto-shredding immediato.
- **Presentare reclamo** al [Garante per la protezione dei dati personali](https://www.garanteprivacy.it).

## Contatti

Per domande su questo incidente:
- **Email DPO**: info@pantedu.eu
- **Form contatto**: [/dpo-contact](https://pantedu.eu/dpo-contact)
- **Riferimento incidente**: [BREACH-ID es. 2026-03-12-001]

Ci scusiamo per il disagio. La sicurezza dei tuoi dati è la nostra priorità e abbiamo già implementato misure aggiuntive per prevenire incidenti simili.

Cordiali saluti,
**Vittorio Pantaleo** — Titolare del trattamento dati Pantedu

---

## Avviso dashboard utente (al login post-breach)

```
⚠️ Avviso importante sulla sicurezza
Il [DATA] abbiamo rilevato un incidente di sicurezza che potrebbe aver coinvolto
i tuoi dati. Ti abbiamo inviato dettagli completi via email a [EMAIL_OFUSCATA].

[Leggi i dettagli] [Cambia password ora]
```

## Checklist invio (operativa)

Prima di inviare la comunicazione:

- [ ] Bozza approvata da DPO/Titolare
- [ ] Lista distribuzione confermata (solo utenti effettivamente impacted)
- [ ] [DATA INCIDENTE], [DESCRIZIONE], [DATI COINVOLTI] compilati
- [ ] [BREACH-ID] assegnato (formato `YYYY-MM-DD-NNN`)
- [ ] Notifica Garante già inviata (Art. 33 — entro 72h)
- [ ] Comunicazione inviata da indirizzo ufficiale (`info@pantedu.eu` o
      mailer SMTP configurato)
- [ ] Avviso dashboard predisposto e gating (mostrato solo a utenti impacted)
- [ ] Log invio: `storage/gdpr/breach_notifications/[BREACH-ID].log`

## Riferimenti

- Data breach runbook: `docs/privacy/data_breach_runbook.md`
- Procedura drill: `tools/gdpr/breach_drill.php`
- DPIA: `docs/privacy/dpia.md`
- Art. 33 (notifica Garante): https://gdpr-info.eu/art-33-gdpr/
- Art. 34 (notifica interessati): https://gdpr-info.eu/art-34-gdpr/
