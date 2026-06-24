<?php

declare(strict_types=1);

namespace App\Services\Verifica;

/**
 * Phase G10/G11 — Modelli standard per verifica_templates.
 *
 * Frammenti TEX derivati direttamente dai file legacy
 *   verifiche/tex_pdf/texCommon/intestaLAteX.txt
 *   verifiche/tex_pdf/texCommon/codaLAteX.txt
 *   verifiche/tex_pdf/texCommon/compensa.txt
 *   verifiche/tex_pdf/texCommon/ult_misure.txt
 *   verifiche/griglie/g_sc.txt
 * (recuperati dal branch master che e' read-only reference, ADR-MEM #2).
 *
 * Le costanti hardcoded (Cognome Nome, di Esempio, 2B, 55 min, etc.)
 * sono state convertite in placeholder {{KEY}} risolti runtime al SalvaTEX
 * tramite VerificaDocumentService::applyTemplate + buildContext.
 *
 * Placeholder supportati:
 *   {{DOCENTE_FULL}}       (nome cognome)
 *   {{DOCENTE_NOME}}       (solo nome)
 *   {{DOCENTE_COGNOME}}    (solo cognome)
 *   {{DOCENTE_EMAIL}}
 *   {{ISTITUTO}}           ("di Esempio")
 *   {{INDIRIZZO}}          ("Scientifico"/"Artistico"/...)
 *   {{CLASSE}}             ("2"/"5")
 *   {{SEZIONE}}            ("B"/"A")
 *   {{ANNO}}               ("2025/2026")
 *   {{TEMPO}}              ("55 min")
 *   {{MATERIA}}            ("MAT"/"FIS")
 *   {{VERSIONE}}           ("v1rc")
 *   {{VERTITLE}}           (titolo verifica completo)
 *   {{DATA_OGGI}}          ("dd/mm/yyyy")
 *
 * I placeholder non risolti restano nel testo (visibili come {{XXX}})
 * cosi' il docente vede subito quali metadata sono mancanti.
 */
final class VerificaTemplateStandard
{
    public static function defaultPack(): array
    {
        return [
            'name'         => 'Default standard (legacy)',
            'is_default'   => 1,
            'intestazione' => self::standardIntestazione(),
            'griglia_voti' => self::standardGriglia(),
            'criteri'      => self::standardCriteri(),
            'footer'       => self::standardFooter(),
        ];
    }

    /**
     * Intestazione: tabularx con DOCENTE/CLASSE/INDIRIZZO/IIS/COGNOME/NOME/
     * DATA/INIZIO/CONSEGNA/TEMPO + title centrato. Iniettata dopo \maketitle
     * (in realta' la legacy SOSTITUISCE \maketitle col proprio markup).
     */
    public static function standardIntestazione(): string
    {
        return <<<'TEX'
\thispagestyle{firstpage}

\noindent

\begin{tabularx}{\textwidth}{|p{5.8cm}|p{2.6cm}|p{3.2cm}|p{3.85cm}|}
\hline
\textbf{DOCENTE:} {{DOCENTE_FULL}} &
\textbf{CLASSE:} {{CLASSE}}{{SEZIONE}} &
\textbf{L.} {{INDIRIZZO}} &
\textbf{I.I.S.} {{ISTITUTO}}\\
\hline
\textbf{COGNOME:} &
\multicolumn{2}{l|}{\textbf{NOME:}} &
\textbf{DATA:}\raisebox{-1mm}{\rule{0.65cm}{0.4pt}}/\raisebox{-1mm}{\rule{0.65cm}{0.4pt}}/\thisyear\\
\hline
\textbf{INIZIO:} ore\hspace{3cm}&
\multicolumn{2}{l|}{\textbf{CONSEGNA:} ore }&
\textbf{TEMPO:} {{TEMPO}}\\
\hline
\end{tabularx}

\vspace{0.3cm}

\begin{center}
    {\large \bfseries {{VERTITLE}} \par}
\end{center}
TEX;
    }

    /**
     * Griglia di valutazione Liceo Scientifico (g_sc.txt) — 4 indicatori
     * (Comprendere/Individuare/Sviluppare/Argomentare) ognuno con 5 livelli
     * 0/1-5. TOTALE su 20.
     */
    /**
     * Griglia di Valutazione (g_sc.txt legacy) — porting 1:1 con descrittori
     * COMPLETI (non abbreviati) per i 4 indicatori. Liceo Scientifico.
     * G12.4 — i descrittori erano stati riassunti per errore: ripristinati
     * dal master verifiche/griglie/g_sc.txt fedeli.
     */
    public static function standardGriglia(): string
    {
        return <<<'TEX'
\newpage

\renewcommand{\arraystretch}{1.3}
\vspace*{-5em}

\begin{center}
    \textbf{IIS {{ISTITUTO}}}\\
    \textbf{Griglia di Valutazione - Elaborato Scritto di {{MATERIA}} - Liceo {{INDIRIZZO}}}
\end{center}
\begin{tabularx}{\textwidth}{|m{6.8cm}|m{5.6cm}|m{3.5cm}|}
\hline
\textbf{DOCENTE:} {{DOCENTE_FULL}} &
\multicolumn{2}{|l|}{\textbf{DATA DELLA VERIFICA:} \rule{1.15cm}{0.4pt}/\rule{1.15cm}{0.4pt}/\rule{1.25cm}{0.4pt}}\\
\hline
\textbf{COGNOME:} &
\textbf{NOME:} &
\textbf{CLASSE:} {{CLASSE}}{{SEZIONE}}\\
\hline
\end{tabularx}
\\
\thispagestyle{empty}

\vspace{1mm}

\begin{tabularx}{\textwidth}{|>{\centering}m{3,5cm}|>{\centering\arraybackslash}m{11.6cm}|>{\centering\arraybackslash}m{0.8cm}|}
\hline
\textbf{INDICATORI} & \textbf{DESCRITTORI} & \textbf{PT}\\
\hline
\renewcommand{\arraystretch}{0.5}
&&\\
\end{tabularx}

\vspace{-0.75cm}

\renewcommand{\arraystretch}{1}
\begin{tabularx}{\textwidth}{|c|>{\arraybackslash}m{11.6cm}|>{\centering\arraybackslash}m{0.8cm}|}

\multirow{4}{*}{\parbox{3,5cm}{ \textbf{\\\\\\Comprendere} \vspace{0.5cm}\\ \fontsize{9}{2}\selectfont{Analizzare la situazione\\problematica, identificare\\i dati ed interpretarli. }}}
& \fontsize{8.5}{2}\selectfont{\textbf{Non comprende} le richieste o le recepisce in maniera \textbf{gravemente inesatta}, non riuscendo a individuare o a collegare i concetti chiave e le informazioni essenziali. } & 1\\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Analizza ed interpreta le richieste in maniera \textbf{parziale}, riuscendo a selezionare \textbf{solo alcuni} dei concetti chiave e delle informazioni essenziali, o, pur avendone individuati diversi, \textbf{commette degli errori} nell'interpretarne alcuni e nello stabilire i collegamenti.} & 2 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Analizza in modo \textbf{complessivamente adeguato} la situazione problematica, individuando e interpretando \textbf{correttamente} i concetti chiave, le informazioni e le relazioni tra queste.} & 3 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Analizza in modo \textbf{adeguato} la situazione problematica, individuando e interpretando correttamente i concetti chiave, le informazioni e le relazioni tra queste; utilizza \textbf{quasi sempre con buona padronanza} i codici matematici grafico-simbolici.} & 4 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Analizza ed interpreta in modo \textbf{completo e pertinente} i concetti chiave, le informazioni essenziali e le relazioni tra queste; utilizza i codici matematici grafico-simbolici con \textbf{buona padronanza e precisione}.} & \textbf{5} \\
\Xhline{2pt}

\multirow{4}{*}{\parbox{3,5cm}{ \textbf{\\\\\\Individuare} \vspace{0.5cm}\\ \fontsize{9}{2}\selectfont{Mettere in campo\\strategie risolutive e\\individuare la strategia\\piu' adatta.}}}
& \fontsize{8.5}{2}\selectfont{\textbf{Non individua} strategie di lavoro. \textbf{Non coglie alcuno spunto} nell'individuare il procedimento risolutivo.} & 0 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Mette in campo strategie di lavoro \textbf{non adeguate}. \textbf{Non e' in grado} di individuare relazioni corrette tra le variabili in gioco. \textbf{Non riesce} ad impostare correttamente le varie fasi del lavoro.} & 1 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Attua strategie di lavoro \textbf{non sempre efficaci}, talora sviluppandole in modo \textbf{poco coerente}. Usa con una \textbf{certa difficolta'} le relazioni tra le variabili. Nella \textbf{maggior parte dei casi non riesce} ad impostare correttamente le varie fasi del lavoro.} & 2 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Sa individuare delle strategie risolutive, anche se \textbf{non sempre le piu' adeguate ed efficienti}. Dimostra di conoscere le procedure consuete e le possibili relazioni tra le variabili e le utilizza in modo \textbf{adeguato}.} & 3 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Attraverso congetture effettua \textbf{chiari collegamenti logici}. Individua strategie di lavoro \textbf{adeguate ed efficienti}. Utilizza in modo adeguato le relazioni matematiche note. Dimostra \textbf{padronanza} nell'impostare le varie fasi di lavoro.} & 4 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Attraverso congetture effettua, \textbf{con padronanza}, chiari collegamenti logici. Individua strategie di lavoro \textbf{adeguate ed efficienti}. Dimostra padronanza nell'impostare le varie fasi di lavoro. Individua \textbf{con cura e precisione} le procedure \textbf{ottimali anche non standard}.} & \textbf{5} \\
\Xhline{2pt}

\multirow{4}{*}{\parbox{3,5cm}{ \textbf{\\\\\\Sviluppare il\\\\processo\\\\risolutivo}\vspace{0.5cm}\\ \fontsize{9}{2}\selectfont{Risolvere la situazione\\ problematica in maniera\\ coerente, completa\\e corretta, applicando\\le regole ed eseguendo\\i calcoli necessari.}}}
& \fontsize{8.5}{2}\selectfont{ \textbf{Non applica} le strategie scelte e \textbf{non sviluppa} il processo risolutivo. \textbf{Non riesce} ad applicare procedure e/o teoremi. \textbf{Non ottiene alcuna soluzione}.} & 1 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Applica le strategie scelte, \textbf{per lo piu', in maniera non corretta}: sviluppa \textbf{la maggior parte} del processo risolutivo in modo \textbf{incompleto e/o errato}. Applica nella \textbf{maggior parte dei casi} procedure e/o teoremi in modo \textbf{errato e/o con diversi errori} nei calcoli. } & 2 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{ \textbf{Quasi sempre} applica le strategie scelte in maniera corretta pur presentando delle \textbf{imprecisioni} e sviluppando il processo risolutivo \textbf{quasi completamente}. \`E in grado di utilizzare procedure e/o teoremi e di applicarli \textbf{quasi sempre} in modo corretto e appropriato. Commette \textbf{alcuni errori} nei calcoli.} & 3 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{ \textbf{Quasi sempre} applica le strategie scelte in maniera corretta e sviluppa il processo risolutivo in modo \textbf{completo}. Applica \textbf{quasi sempre} procedure e/o teoremi o regole in modo corretto e appropriato. Esegue i calcoli in modo \textbf{quasi corretto}.} & 4 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{ Applica le strategie scelte in maniera \textbf{corretta}, sviluppa il processo risolutivo in modo \textbf{corretto, chiaro e completo}. Applica procedure e/o teoremi o regole in modo corretto e appropriato, \textbf{con abilita'}. Esegue i calcoli in modo \textbf{accurato}.} & \textbf{5} \\
\Xhline{2pt}

\multirow{4}{*}{\parbox{3,5cm}{ \textbf{\\\\\\Argomentare}\vspace{0.5cm}\\ \fontsize{9}{2}\selectfont{\mbox{Commentare  e giustificare}\\opportunamente la scelta\\della strategia applicata,\\i passaggi fondamentali\\del processo esecutivo e\\la coerenza dei risultati. }}}
& \fontsize{8.5}{2}\selectfont{\textbf{Non argomenta} la strategia/procedura risolutiva.} & 1 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{\textbf{Non argomenta} o, per lo piu', argomenta in modo \textbf{errato} la strategia/procedura risolutiva utilizzando un linguaggio matematico \textbf{non appropriato o molto impreciso}.} & 2 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Argomenta in maniera \textbf{frammentaria e/o non sempre coerente} la strategia/procedura esecutiva. Utilizza un linguaggio matematico \textbf{per lo piu' appropriato}, ma \textbf{non sempre rigoroso}.} & 3 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Argomenta in modo \textbf{coerente ma incompleto} la procedura esecutiva. Utilizza un linguaggio matematico \textbf{pertinente ma con qualche incertezza}.} & 4 \\
\cline{2-3}
& \fontsize{8.5}{2}\selectfont{Argomenta in modo \textbf{coerente, preciso ed esaustivo} le strategie adottate. Mostra una \textbf{buona padronanza} nell'utilizzo del linguaggio scientifico.} & \textbf{5} \\
\Xhline{2pt}
\end{tabularx}

\vspace{0.2cm}
\hspace{14.2cm} TOTALE: \hspace{1cm}/20
\vspace{0.5cm}

{{LOCALITA}},\raisebox{-1mm}{\rule{1cm}{0.4pt}}/\raisebox{-1mm}{\rule{1cm}{0.4pt}}/{{ANNO_AS_END}}
\hspace{0.5cm} Docente:\rule{5cm}{0.4pt}
\hspace{2.1cm} Voto: \hspace{1.7cm}/10
TEX;
    }

    /**
     * Modulo BES/DSA + ulteriori misure (ult_misure.txt legacy intero).
     * Pagina dispari con:
     *   - Misure dispensative (riduzione quantita / tempo aggiuntivo)
     *   - Tabella strumenti compensativi (schemi/formulari/tabelle/calcolatrice/altro)
     *     con scelta "si avvale di / NON si avvale di"
     *   - Note schemi/tabelle/mappe (forniti/prodotti, concordati/non concordati,
     *     non portato, non approvato, scelta personale)
     *   - Firme studente/docente
     *   - Tabella secondaria RIVALUTAZIONE/RITIRO PER COMPORTAMENTO NON IDONEO
     */
    public static function standardCriteri(): string
    {
        // G12.4 — porting 1:1 del legacy ult_misure.txt: usiamo \parbox per
        // le firme dentro la cella esterna (evita righe vuote + sfasamento
        // bordi). Sub-tabella avvolta in `{\renewcommand\arraystretch{1.5}...}`
        // come legacy. Spaziatura italica del legacy (raisebox 0.32/-0.3)
        // mantenuta per evitare clipping del checkbox sul margine alto.
        return <<<'TEX'
\ensureOddPageStart
\vspace*{-4em}
\textbf{ULTERIORI MISURE E STRUMENTI}
\vspace{0.3cm}

{
\color{grigiochiaro}%
\begin{tabularx}{\textwidth}{|X|}
\hline\vspace{0.2cm}\textbf{PER STUDENTI CON BES/DSA}\,\,\,\,({\footnotesize\textit{da compilare e firmare nel momento della somministrazione}}).\vspace{0.2cm}\newline{\footnotesize
Nella presente verifica, in ottemperanza del PEI/PDP, per lo studente avente diritto valgono le seguenti misure dispensative:\newline\newline
\hspace*{0.3cm}\raisebox{0.32cm}{\checkbox\,\,}\raisebox{-0.3cm}{\parbox{15.5cm}{\textbf{Riduzione della quantita' di esercizi o di domande in verifica}, senza modifica degli obiettivi didattici da raggiungere: i quesiti contrassegnati dal simbolo (*F*) e le richieste racchiuse tra asterischi e sottolineate (*\underline{esempio}*) sono facoltativi; il simbolo (*GF*) indica la giustifica facoltativa alla risposta del relativo quesito; particolari misure dispensative sono specificate in maiuscolo e tra asterischi (*ESEMPIO*).}}\newline\newline
\hspace*{0.3cm}\raisebox{-0.2cm}{\checkbox\,\,}\raisebox{-0.2cm}{\parbox{15cm}{\textbf{Tempo aggiuntivo:} tempo di svolgimento aumentato del 30\%, quindi pari a: \raisebox{-1mm}{\rule{3cm}{0.4pt}}}}\newline\newline
In aggiunta, per lo svolgimento della verifica, sono stati concessi allo studente i seguenti strumenti compensativi.\newline

{\renewcommand{\arraystretch}{1.5}
    \begin{tabularx}{\dimexpr\linewidth-2\tabcolsep\relax}{|m{5cm}|>{\centering\arraybackslash}m{4cm}|>{\centering\arraybackslash}X|}
        \hline
        \multirow{2}{=}{\bfseries concessi} &
        \multicolumn{2}{c|}{\bfseries lo studente} \\
        \cline{2-3}
        & \bfseries si avvale di & \bfseries NON si avvale di \\
        \hline
        \xcheckbox\,\, schemi o mappe & \checkbox & \checkbox \\
        \xcheckbox\,\, formulari & \checkbox & \checkbox \\
        \xcheckbox\,\, tabelle (unita' di misura, tavola pitagorica, ecc.) & \checkbox\,\, & \checkbox \\
        \xcheckbox\,\, calcolatrice & \checkbox & \checkbox \\
        \checkbox\,\, altro: \raisebox{-1mm}{\rule{2cm}{0.4pt}}\hspace*{0.5cm} & \checkbox & \checkbox \\
        \hdashline
        \multicolumn{2}{|l|}{
        \parbox{9cm}{\vspace*{0.3cm}In particolare schemi, tabelle e/o mappe risultano essere:\vspace*{0.3cm}\newline
          \hspace*{1cm}\checkbox\,\, \textbf{forniti} dal docente.\newline
          \hspace*{1cm}\checkbox\,\, \textbf{prodotti} dallo studente e
          \vspace*{0.3cm}\newline
            \hspace*{1.5cm}\checkbox\,\, \textbf{concordati} con il docente.\newline
            \hspace*{1.5cm}\raisebox{0cm}{\checkbox\,\, }\raisebox{-0.18cm}{\parbox{8cm}{\textbf{non concordati} con il docente e quindi,\newline magari, con errori o imprecisioni.}}
            \vspace*{0.3cm}}}
        &
        \parbox{5.9cm}{\vspace*{0.3cm}

        \checkbox\,\,\textbf{previsto ma non portato/prodotto} per la verifica odierna.\newline

        \checkbox\,\,\textbf{richiesto ma non approvato} dal docente perche' non idoneo rispetto a quanto dichiarato nel PDP.\newline

        \checkbox\,\,\textbf{per scelta personale dello studente} (se applicabile e \textbf{concordato}).\newline

        \checkbox\,\,\textbf{per scelta personale dello studente nonostante} gli strumenti (digitali/cartacei) \textbf{offerti} dal docente.
        \vspace*{0.3cm}}
        \\
        \hline
    \end{tabularx}
}
\parbox{21cm}{ \vspace{0.5cm}
\vspace{0.5cm}
Firma Studente: \raisebox{-1mm}{\rule{5cm}{0.4pt}}\hspace*{0.5cm}
Firma Docente: \raisebox{-1mm}{\rule{5cm}{0.4pt}}\vspace*{0.5cm}
}
}\\
\hline
\end{tabularx}
}

\vspace{0.3cm}

\begin{tabularx}{\textwidth}{|X|}
\hline
\vspace{0.2cm}\textbf{RIVALUTAZIONE/RITIRO DELLA VERIFICA PER COMPORTAMENTO NON IDONEO}\vspace{0.2cm}\newline
{\footnotesize
Durante la verifica, alle ore \dottedline{2cm} il docente ha notato e richiamato lo/la studente/studentessa per aver:\newline
\hspace*{1cm}\checkbox\,\,suggerito;\newline
\hspace*{1cm}\checkbox\,\,usato bigliettini;\newline
\hspace*{1cm}\checkbox\,\,usato il cellulare / smartwatch / altri dispositivi smart: \raisebox{-1mm}{\rule{4.65cm}{0.4pt}}\newline
Pertanto:\newline
\hspace*{1cm}\checkbox\,\,la verifica e' stata ritirata con voto negativo e pari a \dottedline{2cm};\newline
\hspace*{1cm}\checkbox\,\,alla verifica e' stata attribuita una penalita' di \dottedline{2cm} punti;\newline
\hspace*{1cm}\checkbox\,\,lo studente ha ricevuto una nota disciplinare.}

\vspace{0.2cm}
Firma Studente: \raisebox{-1mm}{\rule{5cm}{0.4pt}}\hspace*{0.5cm}
Firma Docente: \raisebox{-1mm}{\rule{5cm}{0.4pt}}\vspace*{0.5cm}\\
\hline
\end{tabularx}
TEX;
    }

    /**
     * Footer / Compensazione orale (compensa.txt legacy) — porting 1:1.
     * Iniettato prima di \end{document}. Mantenuti tutti i parametri
     * legacy: \fontsize 9pt/8pt, \hdashrule \\\\, \enlargethispage,
     * \hspace*{8.43cm} per allineare gli esiti positivo/negativo,
     * \rule{5cm} per "nuovo voto" (era 5cm legacy, non 4cm), firme
     * \rule{5.7cm}.
     */
    public static function standardFooter(): string
    {
        return <<<'TEX'
\begingroup

\fontsize{9pt}{8pt}\selectfont

\vspace{0.3cm}
\hdashrule{\textwidth}{1pt}{3pt}\\\\

\checkbox\,\, \textbf{Compensazione orale gia' svolta, come previsto per studenti con DSA (una per periodo).}\\\\
\checkbox\,\, \textbf{Verifica di recupero: nessuna compensazione orale.}\\\\
In data\,\, \raisebox{-1mm}{\rule{1cm}{0.4pt}}/\raisebox{-1mm}{\rule{1cm}{0.4pt}}/{{ANNO_AS_END}} la compensazione orale della verifica e' stata:\\

\checkbox\,\,accettata e affrontata dallo studente con esito:\\
\enlargethispage{2\baselineskip}
\mbox{\hspace*{8.43cm}\checkbox\,\,positivo: nuovo voto \rule{5cm}{0.4pt}}\\
\mbox{\hspace*{8.43cm} \checkbox\,\,negativo: nessuna modifica al voto precedente.}

\checkbox\,\,rifiutata dallo studente.\newline

Firma Studente: \raisebox{-1mm}{\rule{5.7cm}{0.4pt}}\hspace{0.5cm}
Firma Docente: \raisebox{-1mm}{\rule{5.7cm}{0.4pt}}
\endgroup
TEX;
    }

    /**
     * Sostituisce i placeholders {{KEY}} con i valori in $context.
     * Chiavi non risolte restano invariate (visibili come {{XXX}}).
     */
    public static function substitute(string $tex, array $context): string
    {
        if ($tex === '' || !str_contains($tex, '{{')) {
            return $tex;
        }
        foreach ($context as $key => $val) {
            $val = self::escapeTex((string)$val);
            $tex = str_replace('{{' . $key . '}}', $val, $tex);
        }
        // G22.S15.bis Fase 5 — safety net: placeholder non risolti rimangono
        // come `{{X}}` causando "! Missing }" in pdflatex (le graffe diventano
        // sbilanciate dentro tabelle/comandi). Strippa silenziosamente i
        // residui: meglio output vuoto che crash.
        if (str_contains($tex, '{{')) {
            $tex = preg_replace('/\{\{[A-Z_][A-Z0-9_]*\}\}/', '', $tex) ?? $tex;
        }
        return $tex;
    }

    /**
     * Costruisce il context dati per substitute() partendo da:
     *   - $teacher: array Auth::user() con first_name/last_name/email/name
     *   - $selection: payload Selection (anno, mater, iis, cls, sezione, verTitle, ...)
     *   - $extra: dict di override aggiuntivi
     *
     * G12 — espande automaticamente abbreviazioni curriculum:
     *   selectedIIS "sc"   → INDIRIZZO "Scientifico"
     *   selectedMATER "MAT" → MATERIA "Matematica"
     *   selectedCLS "5s"   → CLASSE_LABEL "Quinta scientifico"
     * Lookup da CurriculumService.all() (db o JSON fallback).
     * Se il code non e' in curriculum, mantiene l'abbreviazione.
     */
    public static function buildContext(array $teacher, array $selection, array $extra = []): array
    {
        $name  = trim((string)($teacher['first_name'] ?? ''));
        $surname = trim((string)($teacher['last_name']  ?? ''));
        if ($name === '' && $surname === '') {
            $full = trim((string)($teacher['name'] ?? ''));
            if ($full === '') {
                // Username fallback: "mario.rossi" → "Mario Rossi"
                $u = (string)($teacher['username'] ?? '');
                $u = str_replace(['.', '_', '-'], ' ', $u);
                $full = trim(ucwords($u));
            }
            $parts = preg_split('/\s+/', $full, 2) ?: [$full];
            $name    = (string)($parts[0] ?? '');
            $surname = (string)($parts[1] ?? '');
        }
        // Capitalize prima lettera se era lowercase (es. "docente1" → "Operatore")
        if ($name !== '' && ctype_lower($name[0])) {
            $name = ucfirst($name);
        }
        if ($surname !== '' && ctype_lower($surname[0])) {
            $surname = ucfirst($surname);
        }
        $full = trim($name . ' ' . $surname);
        if ($full === '') {
            $full = (string)($teacher['username'] ?? '');
        }

        // Anno default: anno scolastico corrente (settembre→agosto rule)
        $month = (int)date('n');
        $y1    = $month < 9 ? (int)date('Y') - 1 : (int)date('Y');
        $y2    = $y1 + 1;
        $defaultAS = $y1 . '/' . $y2;

        // Curriculum lookup per espandere abbreviazioni in label leggibili.
        $curriculum = self::loadCurriculum();
        $iisCode    = (string)($selection['selectedIIS']   ?? '');
        $clsCode    = (string)($selection['selectedCLS']   ?? '');
        $materCode  = (string)($selection['selectedMATER'] ?? $selection['materia'] ?? '');

        $iisLabel   = self::resolveLabel($curriculum, 'indirizzi', $iisCode);
        $clsLabel   = self::resolveLabel($curriculum, 'classi', $clsCode);
        $materLabel = self::resolveLabel($curriculum, 'materie', $materCode);

        $istituto    = (string)($selection['istituto'] ?? $extra['istituto'] ?? 'di Esempio');
        $indirizzoVal = $iisLabel !== '' ? $iisLabel
                                          : (string)($selection['addressSchool'] ?? $iisCode);
        $tempoVal     = (string)($selection['verTime'] ?? '55 min');
        // G27.text.escape — escape `_/&/#/%` nel titolo per evitare math
        // subscript artifact in pdflatex (es. "test_ver" → "test\_ver").
        $verTitleRaw = (string)($selection['verTitle'] ?? '');
        $verTitleVal = strtr($verTitleRaw, ['_' => '\_', '&' => '\&', '#' => '\#', '%' => '\%']);

        $context = [
            'DOCENTE_NOME'    => $name,
            'DOCENTE_COGNOME' => $surname,
            'DOCENTE_FULL'    => $full,
            'DOCENTE_EMAIL'   => (string)($teacher['email'] ?? ''),
            'ISTITUTO'        => $istituto,
            // G22.S15.bis Fase 5 — alias usati da intestazione.tex
            'ISTITUTO_NOME'   => $istituto,
            'LOCALITA'        => (string)($selection['localita'] ?? $extra['localita'] ?? 'Comune Esempio'),
            // INDIRIZZO usa label esteso ("Scientifico"); fallback al code.
            'INDIRIZZO'       => $indirizzoVal,
            'INDIRIZZO_LABEL' => $indirizzoVal,   // alias
            'INDIRIZZO_CODE'  => $iisCode,
            'CLASSE'          => (string)($selection['classe'] ?? $clsCode),
            'CLASSE_LABEL'    => $clsLabel !== '' ? $clsLabel : $clsCode,
            'SEZIONE'         => (string)($selection['sezione'] ?? ''),
            'ANNO'            => (string)($selection['anno'] ?? $defaultAS),
            'ANNO_AS_END'     => (string)$y2,
            'TEMPO'           => $tempoVal,
            // TEMPO_MINUTI: estrae numero da "55 min" → "55"; se non parseable usa as-is
            'TEMPO_MINUTI'    => preg_match('/(\d+)/', $tempoVal, $tm) ? $tm[1] : $tempoVal,
            // MATERIA usa label esteso ("Matematica"); fallback al code.
            'MATERIA'         => $materLabel !== '' ? $materLabel : $materCode,
            'MATERIA_CODE'    => $materCode,
            'VERSIONE'        => (string)($selection['versione'] ?? ''),
            'VERTITLE'        => $verTitleVal,
            'TITOLO_VERIFICA' => $verTitleVal,    // alias
            'DATA_OGGI'       => date('d/m/Y'),
        ];
        return array_merge($context, $extra);
    }

    /** Cache curriculum per richiesta. */
    private static ?array $curriculumCache = null;

    private static function loadCurriculum(): array
    {
        if (self::$curriculumCache !== null) {
            return self::$curriculumCache;
        }
        try {
            $svc = new \App\Services\CurriculumService(
                jsonPath:  (string)\App\Core\Config::get('app.paths.storage') . '/data/curriculum.json',
                backupDir: (string)\App\Core\Config::get('app.paths.storage') . '/backups',
            );
            self::$curriculumCache = $svc->all();
        } catch (\Throwable) {
            self::$curriculumCache = ['indirizzi' => [], 'classi' => [], 'materie' => []];
        }
        return self::$curriculumCache;
    }

    private static function resolveLabel(array $curriculum, string $kind, string $code): string
    {
        if ($code === '') {
            return '';
        }
        $items = $curriculum[$kind] ?? [];
        foreach ($items as $row) {
            if (($row['code'] ?? '') === $code) {
                return (string)($row['label'] ?? '');
            }
        }
        return '';
    }

    /** Escape per TEX. G22.S15.bis Fase 5+ delegate alla utility canonica. */
    private static function escapeTex(string $s): string
    {
        return \App\Services\Tex\TexEscape::escape($s);
    }

    /**
     * Wrap di un frammento TEX in un .tex completo per anteprima/preview.
     * Include il preamble esteso con i custom commands legacy
     * (\checkbox, \xcheckbox, \ensureOddPageStart, \schoolyear, ecc.)
     * cosi' i frammenti di intestaLAteX/g_sc/ult_misure/compensa
     * compilano standalone.
     */
    public static function wrapForPreview(string $sectionType, string $snippet): string
    {
        $title = match ($sectionType) {
            'intestazione' => 'Anteprima --- Intestazione',
            'griglia_voti' => 'Anteprima --- Griglia voti',
            'criteri'      => 'Anteprima --- Misure BES/DSA',
            'footer'       => 'Anteprima --- Compensazione orale',
            default        => 'Anteprima frammento',
        };

        $preamble = self::extendedPreamble($title);

        $bodyMid = '';
        if ($sectionType === 'griglia_voti' || $sectionType === 'criteri' || $sectionType === 'footer') {
            $bodyMid = "\n\\section*{Esempio corpo verifica}\nTraccia di esempio per testare il frammento.\n\n";
        }

        return $preamble . "\n" . $bodyMid . $snippet . "\n\n\\end{document}\n";
    }

    /**
     * Preamble esteso (con custom commands legacy) per preview standalone.
     * Replica intestaLAteX.txt fino a \begin{document}.
     */
    public static function extendedPreamble(string $title = ''): string
    {
        $titleLine = $title !== ''
            ? "\\title{" . str_replace(['\\', '{', '}'], ['\\textbackslash{}', '\\{', '\\}'], $title) . "}"
            : "\\title{Verifica}";

        return <<<TEX
\\documentclass[12pt]{article}
\\usepackage{wrapfig}
\\usepackage{geometry}
\\usepackage{enumitem}
\\usepackage{cancel}
\\usepackage[fleqn]{amsmath}
\\usepackage{tabularx}
\\usepackage{tikz}
\\usepackage{arydshln}
\\usepackage{pbox}
\\usepackage{physics}
\\usepackage{amssymb}
\\usepackage{mathtools}
\\usepackage{xcolor}
\\usepackage{tcolorbox}
\\usepackage{adjustbox}
\\usepackage{titling}
\\usepackage{pgfplots}
\\usepackage{fancyhdr}
\\usepackage{multirow}
\\usepackage{array}
\\usepackage{makecell}
\\usepackage{atbegshi}
\\usepackage{lastpage}
\\usepackage{refcount}
\\usepackage{dashrule}
\\usepackage{changepage}
\\usepackage[scaled]{helvet}
\\usepackage[T1]{fontenc}
\\renewcommand{\\familydefault}{\\sfdefault}
\\usetikzlibrary{arrows.meta, calc, patterns, positioning, decorations.markings, decorations.pathmorphing}
\\pgfplotsset{compat=1.18}

\\definecolor{customgreen}{HTML}{25d366}
\\definecolor{grigiochiaro}{gray}{0.85}
\\geometry{paper=a4paper, top=20pt, margin=54pt}
\\setlength{\\headheight}{20pt}
\\setlength{\\mathindent}{0pt}
\\setlength{\\parindent}{0pt}

\\newcolumntype{C}{>{\\centering\\arraybackslash}X}

\\newcommand{\\dottedline}[1]{\\makebox[#1][s]{\\dotfill}}

\\newcommand{\\checkbox}{%
    \\begin{tikzpicture}[baseline=0]
        \\draw (0,0) rectangle (1.5ex,1.5ex);
    \\end{tikzpicture}%
}
\\newcommand{\\xcheckbox}{%
    \\begin{tikzpicture}[baseline=0]
        \\draw (0,0) rectangle (1.5ex,1.5ex);
        \\draw (0,0) -- (1.5ex,1.5ex);
        \\draw (0,1.5ex) -- (1.5ex,0);
    \\end{tikzpicture}%
}
\\newcommand{\\ensureOddPageStart}{%
  \\newpage
  \\checkoddpage
  \\ifoddpage\\else\\mbox{}\\newpage\\fi
}
\\newcommand{\\prevyear}{\\the\\numexpr\\year-1\\relax}
\\newcommand{\\nextyear}{\\the\\numexpr\\year+1\\relax}
\\newcommand{\\thisyear}{\\the\\year}
\\newcommand{\\schoolyear}{%
  \\ifnum\\month<9\\prevyear/\\thisyear\\else\\thisyear/\\nextyear\\fi
}

\\fancypagestyle{firstpage}{
  \\fancyhf{}
  \\fancyhead[L]{\\centering\\fontsize{8.5pt}{10pt}\\selectfont
  I punti (pt.) indicano il peso degli esercizi e possono orientare lo studente verso una stima del voto, tuttavia non costituiscono una valutazione definitiva, che sara' determinata attraverso la griglia di valutazione allegata.}
}

$titleLine
\\author{}
\\date{}

\\begin{document}
TEX;
    }
}
