-- 115 — Il catalogo dei libri in adozione è dell'istituto (ADR-036).
--
-- SICUREZZA: additiva, una tabella nuova che nessun codice precedente legge.
-- ROLLBACK: DROP TABLE adozioni_libri. Si ricostruisce ricaricando il CSV
-- delle adozioni dal pannello.
--
-- Il dataset MIUR delle adozioni (dati.istruzione.it, un file per regione)
-- porta, sulla stessa riga della classe e della disciplina, il libro adottato:
-- ISBN, titolo, autori, editore, volume, prezzo. Finora l'importatore ne
-- ricavava soltanto indirizzi, sezioni e materie, e i libri li buttava via;
-- ogni docente riscriveva a mano le proprie fonti in /area-docente/fonti.
--
-- Da qui in poi i libri in adozione stanno in questa tabella, per istituto,
-- anno scolastico, classe e disciplina. Il docente le vede filtrate sulle
-- classi e sulle materie che ha spuntato nel proprio catalogo, e con un clic
-- ne fa una fonte propria — che poi personalizza come oggi. Le fonti del
-- docente restano nel suo registro (`sources.registry.json`): il catalogo è la
-- provenienza, non il posto dove il docente lavora.
--
-- `origine`: `miur` per le righe dell'importatore, `istituto` per quelle che
-- l'amministratore aggiunge a mano (un libro adottato dopo la chiusura del
-- dataset, un testo consigliato). Un nuovo import non tocca le seconde.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS adozioni_libri (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    institute_id    INT UNSIGNED NOT NULL,
    anno_scolastico VARCHAR(9)   NOT NULL COMMENT 'es. 2026/2027, come nel dataset',
    indirizzo       VARCHAR(16)  NULL COMMENT 'sigla dell indirizzo nel vocabolario dell istituto; NULL se non risolta',
    classe          VARCHAR(16)  NOT NULL COMMENT 'anno + sezione, es. 2A',
    materia         VARCHAR(16)  NULL COMMENT 'sigla della materia nel vocabolario; NULL se la disciplina non ha una sigla',
    disciplina      VARCHAR(255) NOT NULL COMMENT 'la descrizione MIUR, com e nel dataset',
    isbn            VARCHAR(20)  NOT NULL,
    titolo          VARCHAR(255) NOT NULL,
    sottotitolo     VARCHAR(255) NULL,
    autori          VARCHAR(255) NULL,
    editore         VARCHAR(128) NULL,
    volume          VARCHAR(64)  NULL,
    prezzo          DECIMAL(8,2) NULL,
    nuova_adozione  TINYINT(1)   NOT NULL DEFAULT 0,
    da_acquistare   TINYINT(1)   NOT NULL DEFAULT 0,
    consigliato     TINYINT(1)   NOT NULL DEFAULT 0,
    origine         VARCHAR(16)  NOT NULL DEFAULT 'miur' COMMENT 'miur (importatore) | istituto (amministratore)',
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_adozione (institute_id, anno_scolastico, classe, isbn, disciplina(64)),
    KEY idx_adozioni_ricerca (institute_id, classe, materia),
    CONSTRAINT fk_adozioni_institute FOREIGN KEY (institute_id) REFERENCES institutes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='I libri in adozione per classe e disciplina, dal dataset MIUR (ADR-036)';
