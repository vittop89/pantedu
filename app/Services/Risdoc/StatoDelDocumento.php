<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Database;
use DateTimeImmutable;
use Throwable;

/**
 * I campi che un documento risolve da sé.
 *
 * Segnalazione dell'utente (21/9/2026): «cliccando su "Campo" mi esce un popup
 * con il solo `sezione`, ma ci dovrebbero essere molti più campi — classe,
 * docente, disciplina, indirizzo…».
 *
 * Nel compilatore `[field-nome]` vale `state[nome] ?? fields[nome]`, e se non
 * c'è né l'uno né l'altro finisce nel PDF il segnaposto stesso
 * (`PtToTex::renderFieldRef`). Fino a ieri lo `state` portava solo il contesto
 * scelto nella pagina: `nome_docente` e `anno_scolastico`, che pure l'editor
 * suggerisce come esempi, non li riempiva nessuno.
 *
 * Qui si ricavano, una volta, i valori che il documento può sapere da sé: chi
 * lo sta scrivendo, per quale Istituto, in quale anno scolastico. Sono
 * **aggiuntivi**: un valore già presente nello stato non si tocca mai, perché
 * quello l'ha scelto una persona.
 *
 * L'elenco dei nomi sta anche in `js/modules/risdoc/campi-del-documento.js`,
 * che li propone nell'editor; che i due restino d'accordo lo misura
 * `tests/Unit/Services/Risdoc/StatoDelDocumentoTest.php`.
 */
final class StatoDelDocumento
{
    /** Li sceglie chi scrive, dai selettori del documento. */
    public const CONTESTO = ['classe', 'sezione', 'indirizzo', 'disciplina'];

    /** Li ricava questa classe: nessuno li compila a mano. */
    public const DERIVATI = ['nome_docente', 'istituto', 'sede', 'anno_scolastico'];

    /**
     * Nomi che si scrivono spesso al posto di quelli veri. Non si propongono
     * nell'editor (uno solo per cosa), ma se compaiono in un documento si
     * risolvono lo stesso invece di stampare il segnaposto.
     *
     * @var array<string,string>
     */
    private const ALIAS = ['docente' => 'nome_docente'];

    /**
     * L'anno scolastico del giorno dato, nella forma «2026/2027».
     *
     * Comincia a settembre: il 31 agosto si è ancora nell'anno che finisce,
     * il 1° settembre in quello che comincia.
     */
    public static function annoScolastico(?DateTimeImmutable $giorno = null): string
    {
        $g = $giorno ?? new DateTimeImmutable('now');
        $anno = (int)$g->format('Y');
        return (int)$g->format('n') >= 9
            ? $anno . '/' . ($anno + 1)
            : ($anno - 1) . '/' . $anno;
    }

    /**
     * Il nome da stampare per chi scrive: nome e cognome, e se non ci sono
     * l'username — mai una riga vuota, che nel PDF non si capirebbe.
     *
     * @param array<string,mixed> $utente riga di `users`
     */
    public static function nomeDocente(array $utente): string
    {
        $pezzi = array_filter([
            trim((string)($utente['first_name'] ?? '')),
            trim((string)($utente['last_name'] ?? '')),
        ], static fn(string $p): bool => $p !== '');
        if ($pezzi !== []) {
            return implode(' ', $pezzi);
        }
        return trim((string)($utente['username'] ?? ''));
    }

    /**
     * Unisce i valori ricavati allo stato del documento.
     *
     * Regola unica: quello che c'è già vince. Un valore scelto da una persona
     * non lo sovrascrive un valore dedotto, e un valore dedotto vuoto non
     * entra affatto (meglio il segnaposto, che si vede, di una riga sparita).
     *
     * @param array<string,mixed>  $stato    stato che arriva dalla pagina
     * @param array<string,string> $derivati valori ricavati (vedi perDocente)
     * @return array<string,mixed>
     */
    public static function arricchito(array $stato, array $derivati): array
    {
        foreach ($derivati as $nome => $valore) {
            $valore = trim((string)$valore);
            if ($valore === '') {
                continue;
            }
            if (!array_key_exists($nome, $stato) || trim((string)($stato[$nome] ?? '')) === '') {
                $stato[$nome] = $valore;
            }
        }
        foreach (self::ALIAS as $alias => $vero) {
            $v = trim((string)($stato[$vero] ?? ''));
            if ($v !== '' && trim((string)($stato[$alias] ?? '')) === '') {
                $stato[$alias] = $v;
            }
        }
        return $stato;
    }

    /**
     * I valori ricavabili per un docente: come si chiama, per quale Istituto,
     * in quale anno scolastico. Se il database non risponde restano solo
     * l'anno scolastico e il resto vuoto: un'esportazione non deve fallire
     * perché manca un dato dell'intestazione.
     *
     * @return array<string,string>
     */
    public static function perDocente(int $teacherId): array
    {
        $out = ['anno_scolastico' => self::annoScolastico()];

        $istituto = InstituteAssets::instituteFor($teacherId);
        if ($istituto !== null) {
            $out['istituto'] = (string)$istituto['name'];
            $out['sede']     = (string)($istituto['city'] ?? '');
        }

        if ($teacherId > 0) {
            try {
                $st = Database::connection()->prepare(
                    'SELECT username, first_name, last_name FROM users WHERE id = ? LIMIT 1'
                );
                $st->execute([$teacherId]);
                $riga = $st->fetch(\PDO::FETCH_ASSOC);
                if (is_array($riga)) {
                    $out['nome_docente'] = self::nomeDocente($riga);
                }
            } catch (Throwable) {
                // Il nome non c'è: resta il segnaposto, che si vede.
            }
        }

        return array_filter($out, static fn(string $v): bool => $v !== '');
    }
}
