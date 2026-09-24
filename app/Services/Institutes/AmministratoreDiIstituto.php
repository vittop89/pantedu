<?php

declare(strict_types=1);

namespace App\Services\Institutes;

use App\Domain\Role;
use App\Support\DeploymentScenario;
use PDO;

/**
 * L'amministratore di un istituto (ADR-040, 14/9/2026).
 *
 * Esiste solo nello scenario 3, dove il Titolare è l'Istituto. Il ruolo è
 * `institute_admin`, con l'istituto in `admin_institute_id`: un vincolo e due
 * trigger della migrazione 125 rifiutano ogni altra combinazione. Fino a oggi
 * il wizard scriveva `role='admin'`, che nessuna zona di accesso conosceva.
 */
final class AmministratoreDiIstituto
{
    public const FUORI_SCENARIO = 'amministratore_di_istituto_fuori_scenario';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Si può creare un amministratore di istituto su questa istanza? */
    public static function previsto(): bool
    {
        return DeploymentScenario::isInstitute();
    }

    /**
     * Crea l'amministratore, con una password da cambiare al primo accesso.
     *
     * @param bool|null $scenarioIstituto per le prove; di norma lo scenario dell'istanza
     * @return string la password, da mostrare una volta sola
     * @throws \DomainException fuori dallo scenario 3 (FUORI_SCENARIO)
     */
    public function crea(
        int $istituto,
        string $username,
        string $nome,
        string $cognome,
        string $email,
        ?bool $scenarioIstituto = null,
    ): string {
        if (!($scenarioIstituto ?? self::previsto())) {
            throw new \DomainException(self::FUORI_SCENARIO);
        }
        $password = self::password();
        // Audit 25.R.31 (L7) — must_change_password=1: la password iniziale si
        // cambia al primo accesso (AuthMiddleware).
        $this->pdo->prepare(
            'INSERT INTO users
                (username, role, first_name, last_name, email, password_hash,
                 must_change_password, status, active, admin_institute_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, 1, ?, NOW())'
        )->execute([
            $username, Role::INSTITUTE_ADMIN->value, $nome, $cognome, $email,
            password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'approved', $istituto,
        ]);
        return $password;
    }

    private static function password(int $lunghezza = 16): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $max = strlen($alfabeto) - 1;
        $out = '';
        for ($i = 0; $i < $lunghezza; $i++) {
            $out .= $alfabeto[random_int(0, $max)];
        }
        return $out;
    }
}
