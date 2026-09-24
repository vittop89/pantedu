<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\ClassCode;
use PDO;

/**
 * Chi dei docenti di un istituto può legarsi a una classe (ADR-041, 14/9/2026;
 * ADR-043, 15/9/2026 per gli anni).
 *
 * L'elenco delle classi di una scuola è pubblico (il dataset MIUR delle
 * adozioni). Il legame «il docente X insegna in 2A» è un dato personale del
 * docente, e messo insieme per molti docenti ricostruisce l'organigramma della
 * scuola. La modalità dell'istituto (`institutes.sezioni_docenti`) decide:
 *
 *   - `tutti`            ogni docente dell'istituto, anni e sezioni;
 *   - `solo_incaricati`  solo chi l'amministratore ha incaricato di quella
 *                        classe (`teacher_sections`): una sezione, o un anno di
 *                        un corso («3» di architettura);
 *   - `nessuno`          nessuno usa le sezioni: tutti usano gli anni («2» vale
 *                        per 2A, 2B…).
 *
 * Fino al 15/9/2026 gli anni erano sempre ammessi: con «solo incaricati» un
 * docente vedeva gli anni di ogni corso, anche dove l'amministratore non gli
 * aveva dato niente (scelta dell'utente: «solo con incarico»). Il vocabolario
 * (quali classi esistono) resta com'è: si governa il legame, non l'elenco.
 *
 * Dove si applica, oltre a qui: la spunta dal profilo (CurriculumService), la
 * classe di un contenuto salvato (CurriculumLookup, che ripiega sull'anno), la
 * credenziale di classe, le voci offerte nel profilo (CurriculumController).
 * Le spunte già scritte che la modalità non ammette si sospendono
 * (`curriculum_teacher.sospesa_dalla_scuola`): tutto ciò che legge le spunte
 * attive — pubblicare, spostare di classe, i selettori — le ignora da sé.
 */
final class SezioniDeiDocenti
{
    public const TUTTI           = 'tutti';
    public const SOLO_INCARICATI = 'solo_incaricati';
    public const NESSUNO         = 'nessuno';
    public const MODALITA        = [self::TUTTI, self::SOLO_INCARICATI, self::NESSUNO];

    /** Quella di partenza, anche per un istituto la cui colonna non si legge. */
    public const PREDEFINITA = self::SOLO_INCARICATI;

    /**
     * La condizione SQL «questa spunta su una classe è ammessa», con `ct`
     * (curriculum_teacher), `ce` (curriculum_entries) e `i` (institutes).
     */
    private const SQL_AMMESSA = "(i.sezioni_docenti = 'tutti'
        OR (i.sezioni_docenti = 'nessuno' AND ce.code REGEXP '^[1-9]$')
        OR (i.sezioni_docenti = 'solo_incaricati'
            AND EXISTS (SELECT 1 FROM teacher_sections ts
                         WHERE ts.user_id = ct.user_id
                           AND ts.institute_id = ce.institute_id
                           AND UPPER(ts.classe) = UPPER(ce.code)
                           AND (ce.indirizzo IS NULL OR UPPER(ts.indirizzo) = UPPER(ce.indirizzo)))))";

    /** Anni e sezioni: tutte le classi con una sigla riconosciuta. */
    private const SQL_CLASSE = "ce.kind = 'classi' AND ce.code REGEXP '^[1-9][A-Za-z0-9]*$'";

    /** @var array<int,string> la modalità per istituto, per la vita di questo oggetto */
    private array $modalitaLette = [];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ??= Database::connection();
    }

    /**
     * La regola, senza database. Con «solo incaricati» anni e sezioni vogliono
     * l'incarico; con «nessuno» passano solo gli anni. Una sigla che non è né
     * anno né sezione non è una classe da governare, e passa.
     */
    public static function ammessaPer(string $modalita, string $classe, bool $haIncarico): bool
    {
        $anno = ClassCode::isAnno($classe);
        if (!$anno && !ClassCode::isSezione($classe)) {
            return true;
        }
        return match ($modalita) {
            self::TUTTI           => true,
            self::SOLO_INCARICATI => $haIncarico,
            self::NESSUNO         => $anno,
            default               => false,
        };
    }

    public function modalita(int $istituto): string
    {
        if (isset($this->modalitaLette[$istituto])) {
            return $this->modalitaLette[$istituto];
        }
        try {
            $st = $this->db()->prepare('SELECT sezioni_docenti FROM institutes WHERE id = ? LIMIT 1');
            $st->execute([$istituto]);
            $m = (string)$st->fetchColumn();
            return $this->modalitaLette[$istituto] = \in_array($m, self::MODALITA, true) ? $m : self::PREDEFINITA;
        } catch (\PDOException) {
            // Migrazione 126 non ancora applicata: vale la predefinita.
            return self::PREDEFINITA;
        }
    }

    /**
     * Il docente può legarsi a questa classe di questo istituto? Per un anno
     * l'incarico è quello nel suo corso (`$indirizzo`).
     */
    public function ammessa(int $docente, int $istituto, string $classe, ?string $indirizzo = null): bool
    {
        if (!ClassCode::isAnno($classe) && !ClassCode::isSezione($classe)) {
            return true;
        }
        $modalita = $this->modalita($istituto);
        $incarico = $modalita === self::SOLO_INCARICATI && $this->haIncarico($docente, $istituto, $classe, $indirizzo);
        return self::ammessaPer($modalita, $classe, $incarico);
    }

    private function haIncarico(int $docente, int $istituto, string $classe, ?string $indirizzo): bool
    {
        $sql = 'SELECT 1 FROM teacher_sections WHERE user_id = ? AND institute_id = ? AND UPPER(classe) = UPPER(?)';
        $args = [$docente, $istituto, ClassCode::normalize($classe)];
        if ($indirizzo !== null && $indirizzo !== '') {
            $sql .= ' AND UPPER(indirizzo) = UPPER(?)';
            $args[] = $indirizzo;
        }
        $st = $this->db()->prepare($sql . ' LIMIT 1');
        $st->execute($args);
        return (bool)$st->fetchColumn();
    }

    /**
     * Cambia la modalità di un istituto e riallinea le spunte.
     *
     * @return array{sospese:int, riprese:int}
     */
    public function imposta(int $istituto, string $modalita): array
    {
        if (!\in_array($modalita, self::MODALITA, true)) {
            throw new \InvalidArgumentException('modalita_non_valida');
        }
        $this->db()->prepare('UPDATE institutes SET sezioni_docenti = ? WHERE id = ?')->execute([$modalita, $istituto]);
        $this->modalitaLette[$istituto] = $modalita;
        return $this->riallinea($istituto);
    }

    /**
     * Sospende le spunte su sezioni che la modalità non ammette e riprende
     * quelle sospese che ora ammette. Da chiamare dopo un cambio di modalità o
     * di incarichi. Le spunte spente dal docente non si toccano.
     *
     * @return array{sospese:int, riprese:int}
     */
    public function riallinea(int $istituto, ?int $docente = null): array
    {
        $perDocente = $docente !== null ? ' AND ct.user_id = ?' : '';
        $args = $docente !== null ? [$istituto, $docente] : [$istituto];

        $sospendi = $this->db()->prepare(
            'UPDATE curriculum_teacher ct
               JOIN curriculum_entries ce ON ce.id = ct.curriculum_id
               JOIN institutes i ON i.id = ce.institute_id
                SET ct.active = 0, ct.sospesa_dalla_scuola = 1
              WHERE ' . self::SQL_CLASSE . ' AND ce.institute_id = ?' . $perDocente . '
                AND ct.active = 1 AND NOT ' . self::SQL_AMMESSA
        );
        $sospendi->execute($args);

        $riprendi = $this->db()->prepare(
            'UPDATE curriculum_teacher ct
               JOIN curriculum_entries ce ON ce.id = ct.curriculum_id
               JOIN institutes i ON i.id = ce.institute_id
                SET ct.active = 1, ct.sospesa_dalla_scuola = 0
              WHERE ' . self::SQL_CLASSE . ' AND ce.institute_id = ?' . $perDocente . '
                AND ct.sospesa_dalla_scuola = 1 AND ' . self::SQL_AMMESSA
        );
        $riprendi->execute($args);

        return ['sospese' => $sospendi->rowCount(), 'riprese' => $riprendi->rowCount()];
    }
}
