-- G20.0 Phase 6 — Institute identity columns.
-- header_label: nome esteso da iniettare nel `\textbf{I.I.S.} <label>`
--               (sostituisce/personalizza `name` in TEX rendering).
-- footer_signature: testo libero LaTeX, append in main_*.tex via placeholder.
-- logo_path: path relativo (es. `storage/uploads/institutes/{code}/logo.png`)
--            per `\includegraphics` nel `texCommon/intestazione.tex`.

ALTER TABLE institutes
    ADD COLUMN header_label     VARCHAR(255) NULL AFTER name,
    ADD COLUMN footer_signature TEXT         NULL AFTER header_label,
    ADD COLUMN logo_path        VARCHAR(255) NULL AFTER footer_signature;
