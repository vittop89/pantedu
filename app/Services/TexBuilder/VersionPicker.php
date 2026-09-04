<?php

namespace App\Services\TexBuilder;

/**
 * Sceglie il preamble LaTeX in base alla variante (Normal / DSA / Dyslexic).
 * Il preamble base è derivato dai .tex esistenti in
 * verifiche/tex_pdf/.../1____-2ar-NOR-stampe_.tex.
 */
final class VersionPicker
{
    public const NORMAL    = 'normal';
    public const DSA       = 'dsa';
    public const DYSLEXIC  = 'dyslexic';

    public const VARIANTS  = [self::NORMAL, self::DSA, self::DYSLEXIC];

    /**
     * G19.49l — path file override del preambolo (admin editable via
     * /admin/templates#verifiche). Quando presente sostituisce il
     * default hardcoded di `defaultBase()`.
     */
    private const OVERRIDE_FILE = 'storage/data/verifica_preamble.tex';

    public static function preamble(string $variant): string
    {
        $variant = \in_array($variant, self::VARIANTS, true) ? $variant : self::NORMAL;
        return self::base() . "\n" . self::fontFor($variant) . "\n" . self::endPreamble();
    }

    /**
     * G19.49l — restituisce il preambolo system-wide (override admin se
     * presente, altrimenti default hardcoded).
     */
    public static function currentBase(): string
    {
        return self::base();
    }

    /** Default hardcoded (mai modificato a runtime). */
    public static function defaultBase(): string
    {
        return self::baseHardcoded();
    }

    /** Path file override (assoluto). */
    public static function overrideFilePath(): string
    {
        $root = \dirname(__DIR__, 3);
        return $root . '/' . self::OVERRIDE_FILE;
    }

    private static function base(): string
    {
        $override = self::overrideFilePath();
        if (\is_file($override)) {
            $content = @file_get_contents($override);
            if (\is_string($content) && trim($content) !== '') {
                return $content;
            }
        }
        return self::baseHardcoded();
    }

    private static function baseHardcoded(): string
    {
        // Preamble base: include i custom commands (\checkbox, \xcheckbox,
        // \schoolyear, \ensureOddPageStart, \fancypagestyle{firstpage}) usati
        // dai frammenti standard di VerificaTemplateStandard.
        return <<<'TEX'
\documentclass[12pt]{article}
\usepackage{wrapfig}
\usepackage{geometry}
\usepackage{enumitem}
\usepackage{cancel}
\usepackage[fleqn]{amsmath}
\usepackage{tabularx}
\usepackage{tikz}
\usepackage{arydshln}
\usepackage{pbox}
\usepackage{physics}
\usepackage{amssymb}
\usepackage{mathtools}
\usepackage{xcolor}
\usepackage{tcolorbox}
\usepackage{circledsteps}
\usepackage{adjustbox}
\usepackage{titling}
\usepackage{pgfplots}
\usepackage{fancyhdr}
\usepackage{multirow}
\usepackage{array}
\usepackage{makecell}
\usepackage{atbegshi}
\usepackage{lastpage}
\usepackage{refcount}
\usepackage{dashrule}
\usepackage{changepage}

\usetikzlibrary{arrows.meta, calc, patterns, positioning, decorations.markings, decorations.pathmorphing, backgrounds}
\pgfplotsset{compat=1.18}

\definecolor{customgreen}{HTML}{25d366}
\definecolor{grigiochiaro}{gray}{0.85}
\geometry{paper=a4paper, top=20pt, margin=54pt}
\setlength{\headheight}{20pt}
\setlength{\mathindent}{0pt}
\setlength{\parindent}{0pt}
\newcolumntype{C}{>{\centering\arraybackslash}X}
\newcommand{\dottedline}[1]{\makebox[#1][s]{\dotfill}}

% Custom commands richiesti dai frammenti standard di
% VerificaTemplateStandard (intestazione, griglia, criteri, footer).
\newcommand{\checkbox}{%
    \begin{tikzpicture}[baseline=0]
        \draw (0,0) rectangle (1.5ex,1.5ex);
    \end{tikzpicture}%
}
\newcommand{\xcheckbox}{%
    \begin{tikzpicture}[baseline=0]
        \draw (0,0) rectangle (1.5ex,1.5ex);
        \draw (0,0) -- (1.5ex,1.5ex);
        \draw (0,1.5ex) -- (1.5ex,0);
    \end{tikzpicture}%
}
\newcommand{\ensureOddPageStart}{%
  \newpage
  \checkoddpage
  \ifoddpage\else\mbox{}\newpage\fi
}
\newcommand{\prevyear}{\the\numexpr\year-1\relax}
\newcommand{\nextyear}{\the\numexpr\year+1\relax}
\newcommand{\thisyear}{\the\year}
\newcommand{\schoolyear}{%
  \ifnum\month<9\prevyear/\thisyear\else\thisyear/\nextyear\fi
}

\fancypagestyle{firstpage}{
  \fancyhf{}
  \fancyhead[L]{\centering\fontsize{8.5pt}{10pt}\selectfont
  I punti (pt.) indicano il peso degli esercizi e possono orientare lo studente verso una stima del voto, tuttavia non costituiscono una valutazione definitiva, che sara' determinata attraverso la griglia di valutazione allegata.}
}

TEX;
    }

    private static function fontFor(string $variant): string
    {
        return match ($variant) {
            self::DYSLEXIC => "% Compilare con XeLaTeX per OpenDyslexic\n"
                           . "\\usepackage{fontspec}\n"
                           . "\\setmainfont{OpenDyslexic}\n",
            self::DSA      => "\\usepackage[scaled]{helvet}\n"
                           . "\\usepackage[T1]{fontenc}\n"
                           . "\\renewcommand{\\familydefault}{\\sfdefault}\n"
                           . "% DSA: spaziatura aumentata\n"
                           . "\\renewcommand{\\baselinestretch}{1.5}\n",
            default        => "\\usepackage[scaled]{helvet}\n"
                           . "\\usepackage[T1]{fontenc}\n"
                           . "\\renewcommand{\\familydefault}{\\sfdefault}\n",
        };
    }

    private static function endPreamble(): string
    {
        return "\\begin{document}\n";
    }
}
