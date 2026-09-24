<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Il testo della pagina pubblica /security (misure dell'art. 32).
 *
 * Tolto da TrustPagesController il 24/9/2026, quando la pagina è stata
 * riscritta dopo la rilettura dei documenti legali: diceva la chiave master
 * «off-line», le copie «illeggibili senza la chiave master», il gettone CSRF
 * «ruotato a ogni modifica» e che l'amministratore non poteva accedere ai
 * contenuti, e niente di questo era vero. Il segnaposto {{CONTATTO_SICUREZZA}}
 * lo sostituisce il controller.
 */
final class PaginaDellaSicurezza
{
    public static function html(): string
    {
        return <<<'HTML'
            <h1>Sicurezza tecnica — Pantedu</h1>
            <p>Questa pagina riassume le misure tecniche (Art. 32 GDPR) implementate per proteggere i tuoi dati.</p>

            <h2>🔐 Cifratura at-rest (Phase 25.D)</h2>
            <ul>
                <li><strong>Algoritmo</strong>: AES-256-GCM (NIST SP 800-38D), authenticated encryption.</li>
                <li><strong>Architettura</strong>: ogni docente ha una chiave casuale (KEK), custodita avvolta con una
                    chiave derivata (HKDF-SHA256) dalla chiave master. La chiave master sta sul server, perché la
                    piattaforma funzioni; le sue copie di sicurezza stanno fuori dal server.</li>
                <li><strong>Che cosa è cifrato</strong>: mappe, verifiche, compilazioni, collegamenti a Drive e GitHub,
                    chiavi dell'import da PDF. Il corpo di esercizi e laboratori, titoli e argomenti non lo sono:
                    l'informativa dice dove stanno.</li>
                <li><strong>Cancellazione dell'account</strong>: si cancellano i contenuti del docente e, per ultima,
                    la sua chiave. Nelle copie di sicurezza fatte prima i dati restano fino alla loro scadenza.</li>
                <li><strong>Copie di sicurezza</strong>: cifrate (GPG) prima di uscire dal server.</li>
            </ul>

            <h2>🛡️ Sicurezza in transito</h2>
            <ul>
                <li><strong>HTTPS obbligatorio</strong> in produzione (HSTS 1 anno, includeSubDomains).</li>
                <li><strong>CSP rigorosa</strong>: prevenzione XSS + frame injection.</li>
                <li><strong>SameSite=Lax</strong> sui cookie sessione.</li>
                <li><strong>Gettone CSRF</strong>: legato alla sessione e verificato su ogni richiesta che modifica
                    dati; si rinnova all'accesso, all'uscita e al cambio di password o di email.</li>
            </ul>

            <h2>🔑 Autenticazione</h2>
            <ul>
                <li><strong>Password</strong>: bcrypt cost 12 (resistant a rainbow tables + brute-force GPU).</li>
                <li><strong>Rate-limiting</strong>: 10/min/IP su /login (anti-brute-force).</li>
                <li><strong>Session rotation</strong>: ID rigenerato a ogni privilege change.</li>
            </ul>

            <h2>📊 Audit & monitoring</h2>
            <ul>
                <li><strong>privileged_access_log</strong>: append-only, ogni azione admin loggata con motivazione obbligatoria.</li>
                <li><strong>crypto_access_log</strong>: tracking di ogni encrypt/decrypt/shred (Art. 5 §2 accountability).</li>
                <li><strong>consent_audit</strong>: storia immutabile di consent grant/revoke.</li>
                <li><strong>Pseudonimizzazione IP/UA</strong>: dal 24 settembre 2026 i registri di audit,
                    dei consensi e delle richieste (cancellazione, privacy, recupero della password, cambio
                    dell'email) tengono dell'IP un'impronta con chiave (HMAC-SHA256); le righe scritte prima hanno
                    uno SHA-256 senza chiave e restano fino al termine del loro registro. Lo User-Agent è un hash
                    SHA-256. Sono dati pseudonimi, non anonimi. L'IP resta in chiaro, per il tempo breve indicato
                    nell'informativa, nei registri del filtro, dei limitatori e della navigazione, e come prova
                    nell'accettazione dei Termini e nelle segnalazioni di contenuti.</li>
            </ul>

            <h2>🔒 Isolation per-utente</h2>
            <ul>
                <li>Ogni docente vede i propri contenuti e quelli che un collega condivide con lui.</li>
                <li>Nessuna funzione mostra all'amministratore i contenuti dei docenti in chiaro. L'amministratore del
                    server custodisce però la chiave master: un accesso ai contenuti è ammesso solo nei casi dei
                    Termini §3(c), con la motivazione nel registro, e il docente lo vede nel suo profilo.</li>
                <li>Permission system per-template + multi-instance fork isolato per teacher_id.</li>
            </ul>

            <h2>🚨 Data breach response</h2>
            <ul>
                <li>Notifica al Garante entro <strong>72 ore</strong> quando la violazione presenta un rischio per le
                    persone (art. 33 GDPR); ogni valutazione, anche senza notifica, resta scritta.</li>
                <li>Comunicazione agli interessati se il rischio è elevato (art. 34 GDPR).</li>
                <li>Prova semestrale del piano, con un rapporto scritto.</li>
            </ul>

            <h2>🐞 Segnalare una vulnerabilità</h2>
            <p>
                Questa pagina è il <code>Policy</code> indicato in
                <a href="/.well-known/security.txt">security.txt</a> (RFC 9116).
                {{CONTATTO_SICUREZZA}}
            </p>
            <ul>
                <li>Non divulgare pubblicamente prima della patch.</li>
                <li>Non accedere a dati di altri utenti, non testare in modo
                    da interrompere il servizio in produzione.</li>
                <li>Tempi di fix target: 30 giorni per High, 90 per Medium/Low.
                    Acknowledgement entro 72 ore.</li>
            </ul>

            <p class="fm-trust-meta">
                <a href="/privacy/your-data">Esercita i tuoi diritti GDPR</a> ·
                <a href="/dpo-contact">Richieste privacy</a> ·
                <a href="/privacy/informativa">Informativa privacy</a>
            </p>
            HTML;
    }
}
