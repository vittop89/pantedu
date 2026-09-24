---
tags:
    - documentazione/gdpr
    - phase/25.C12
date: 2026-09-25
tipo: template
status: vigente
classification: ⚠️ INTERNAL — bozza compilabile per Art. 34 GDPR
aliases: ["breach-notification-template", "data-breach-email"]
---

# Template comunicazione data breach (Art. 34 GDPR)

*Ultima revisione: 25 settembre 2026.*

> Bozza email pre-formulata per notifica data subject quando un breach
> presenta rischio elevato per i loro diritti e libertà. Compilare i campi
> `[BRACKETED]` con i dettagli specifici dell'incidente.
>
> **Notifica obbligatoria** se rischio elevato (Art. 34 §1).
> **Tempistica**: senza ingiustificato ritardo dopo conferma del breach.
> **Forma**: email diretta. *(Fino al 22 settembre 2026 qui si leggeva «email
> diretta + avviso in dashboard utente al login». L'avviso in dashboard non
> esiste, e questo è il documento peggiore in cui prometterlo: si apre nel
> momento peggiore, con le 72 ore che corrono. Il piano per le violazioni
> — `data_breach_runbook.md` — ha sempre scritto la posta come forma unica,
> e i due documenti si contraddicevano.)*

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
- [ELENCARE — es. nome utente, email, contenuti didattici]
- [SE SONO USCITE LE IMPRONTE DELLE PASSWORD: «Le password non erano scritte in chiaro ma come hash bcrypt. Un hash si può attaccare per tentativi, tanto più facilmente quanto più la password è semplice: per questo ti chiediamo di cambiarla.»]

I dati **NON** coinvolti:
- [ELENCARE SOLO CIÒ CHE È STATO VERIFICATO — es. «Le password: la tabella che ne conserva gli hash non è stata raggiunta.»]
- [SE VERO: «I contenuti didattici cifrati — mappe, verifiche salvate, documenti compilati — sono protetti con una chiave per ciascun docente: chi ha avuto il database senza la chiave del server non li può leggere.» Non vale per i contenuti che la piattaforma conserva in chiaro, come il testo degli esercizi (registro dei trattamenti, B.3).]

## Cosa abbiamo fatto

Immediatamente:
- [ELENCARE — es. "Bloccato l'accesso al vettore identificato"]
- [es. "Forzato logout di tutti gli utenti"]
- [es. "Cambiate tutte le credenziali di sicurezza interne"]
- Notificato il Garante per la protezione dei dati personali entro 72 ore (Art. 33 GDPR).

Successivamente:
- Audit completo dei log di accesso per identificare l'ambito.
- [ALTRO]

## Cosa puoi fare TU

Per precauzione ti consigliamo di:

1. **Cambiare la tua password**. [SE DISPOSTO: al prossimo accesso ti verrà chiesto di sceglierne una nuova.]
2. **Diffidare dei messaggi che sembrano venire da Pantedu**: non ti chiediamo mai la password per email, e ti mandiamo un collegamento solo dopo una tua azione (per esempio il recupero della password).
3. Se hai usato la stessa password su altri servizi, **cambiarla anche lì**.

## I tuoi diritti

Puoi:
- **Esercitare i tuoi diritti GDPR** via [/dpo-contact](https://pantedu.eu/dpo-contact) (accesso, rettifica, oblio, portabilità).
- **Chiedere la cancellazione del tuo account**, dalla pagina «I tuoi dati» («Chiedi la cancellazione dell'account»: arriva un'email per confermarla) o dallo stesso modulo [/dpo-contact](https://pantedu.eu/dpo-contact) (oggetto «Cancellazione / oblio»). La cancellazione toglie i tuoi contenuti conservati in chiaro e distrugge la chiave che protegge quelli cifrati; le copie di sicurezza fatte prima restano fino alla loro scadenza.
- **Presentare reclamo** al [Garante per la protezione dei dati personali](https://www.garanteprivacy.it).

## Contatti

Per domande su questo incidente:
- **Recapito privacy del titolare**: {{OPERATORE_EMAIL}}
- **Form contatto**: [/dpo-contact](https://pantedu.eu/dpo-contact)
- **Riferimento incidente**: [BREACH-ID es. 2026-03-12-001]

Ci scusiamo per il disagio. [MISURE PRESE PERCHÉ NON SI RIPETA.]

Cordiali saluti,
**{{OPERATORE_NOME}}** — Titolare del trattamento dati Pantedu

---

## Checklist invio (operativa)

Prima di inviare la comunicazione:

- [ ] Bozza approvata dal titolare
- [ ] Destinatari confermati (solo gli interessati effettivamente coinvolti)
- [ ] [DATA INCIDENTE], [DESCRIZIONE], [DATI COINVOLTI] compilati
- [ ] [BREACH-ID] assegnato (formato `YYYY-MM-DD-NNN`)
- [ ] Notifica Garante già inviata (Art. 33 — entro 72h)
- [ ] Comunicazione inviata da un indirizzo della piattaforma (`{{OPERATORE_EMAIL}}`
      o il servizio di posta della piattaforma)
- [ ] Invio registrato nell'incidente (`/admin/data-breach`): azione «Marca
      notificato agli utenti», metodo «Email diretta»

## Riferimenti

- Data breach runbook: `docs/privacy/data_breach_runbook.md`
- Prova periodica del piano: `tools/gdpr/breach_drill.php`
- DPIA: `docs/privacy/dpia.md`
- Art. 33 (notifica Garante): https://gdpr-info.eu/art-33-gdpr/
- Art. 34 (notifica interessati): https://gdpr-info.eu/art-34-gdpr/
