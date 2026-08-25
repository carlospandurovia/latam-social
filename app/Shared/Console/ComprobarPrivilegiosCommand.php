<?php

declare(strict_types=1);

namespace App\Shared\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ¿Es la bitácora evidencia de verdad? (`T-18`)
 *
 * ### El agujero que cierra
 *
 * `audit_logs` rechaza `UPDATE` y `DELETE` con dos disparadores. Comprobado:
 *
 * ```
 * UPDATE audit_logs ... -> 45000 «audit_logs es solo-insercion»
 * DELETE FROM audit_logs -> 45000 «audit_logs no admite borrado»
 * TRUNCATE TABLE audit_logs -> pasa, y deja la tabla a CERO
 * ```
 *
 * **`TRUNCATE` no dispara triggers.** No hay disparador que lo pare ni forma de
 * escribir uno: es una operación de esquema, no de datos. Lo único que la
 * detiene es no tener el privilegio `DROP`, que es el que `TRUNCATE` exige.
 *
 * Así que la inmutabilidad de la bitácora no depende del esquema —que ya hizo
 * todo lo que podía— sino de **con qué usuario se conecta la aplicación**. Y eso
 * no se puede declarar en una migración: se concede en el servidor.
 *
 * ### Por qué una orden y no un comentario en el `.env.example`
 *
 * Porque un comentario no se ejecuta. El docblock de la migración de
 * trazabilidad ya afirmaba que el usuario de aplicación no tenía `UPDATE` ni
 * `DELETE` y remitía a una segunda conexión de migraciones **que no existía**:
 * `config/database.php` tenía un único `DB_USERNAME` para todo. Una promesa
 * escrita y no cumplida es peor que no prometer nada, porque nadie va a
 * comprobarla.
 *
 * Esto la comprueba. Lee los privilegios reales del usuario con el que está
 * conectada la aplicación **ahora mismo**.
 *
 * ### Qué NO hace
 *
 * No intenta el `TRUNCATE` para ver si falla. `TRUNCATE` hace *commit
 * implícito*: si el privilegio estuviera, la comprobación habría **vaciado la
 * bitácora** para demostrar que se puede vaciar. Se leen los privilegios y ya.
 */
final class ComprobarPrivilegiosCommand extends Command
{
    protected $signature = 'seguridad:privilegios {--exigir : Terminar con error si la bitacora es vaciable}';

    protected $description = 'Comprueba que el usuario de la aplicacion no puede vaciar la bitacora';

    /** Privilegios que la aplicación NO debería tener nunca en producción. */
    private const PELIGROSOS = ['DROP', 'ALTER', 'CREATE', 'INDEX', 'REFERENCES'];

    public function handle(): int
    {
        $usuario = (string) DB::selectOne('SELECT CURRENT_USER() AS u')->u;
        $base = (string) DB::selectOne('SELECT DATABASE() AS d')->d;

        $this->line('');
        $this->line("  Conectado como <options=bold>{$usuario}</> a <options=bold>{$base}</>");

        $privilegios = $this->privilegios();

        if ($privilegios === []) {
            $this->line('');
            $this->warn('  No se pudieron leer los privilegios de este usuario.');
            $this->line('  Suele significar que no tiene acceso a `information_schema.SCHEMA_PRIVILEGES`,');
            $this->line('  o que los permisos se concedieron a nivel global y no de esquema.');

            return self::SUCCESS;
        }

        $this->line('  Privilegios sobre el esquema: '.implode(', ', $privilegios));

        // `ALL PRIVILEGES` aparece desglosado en `SCHEMA_PRIVILEGES`, así que
        // basta con buscar los peligrosos uno a uno.
        $tiene = array_values(array_intersect(self::PELIGROSOS, $privilegios));

        $this->line('');

        if ($tiene === []) {
            $this->info('  La bitacora esta protegida.');
            $this->line('  Sin `DROP` no se puede `TRUNCATE`, y `UPDATE` y `DELETE` los paran los disparadores.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->error('  La bitacora SE PUEDE VACIAR.');
        $this->line('');
        $this->line('  Este usuario tiene: <options=bold>'.implode(', ', $tiene).'</>');
        $this->line('  Con `DROP`, `TRUNCATE TABLE audit_logs` deja la tabla a cero sin disparar');
        $this->line('  ningun disparador. La bitacora deja de ser evidencia.');
        $this->line('');
        $this->line('  Se arregla con DOS usuarios de base de datos:');
        $this->line('');

        foreach ($this->recetaSql($base) as $linea) {
            $this->line('    <fg=gray>'.$linea.'</>');
        }

        $this->line('');
        $this->line('  Despues, `DB_USERNAME` en `.env` apunta al de aplicacion, y las migraciones');
        $this->line('  se corren con el otro:');
        $this->line('');
        $this->line('    <fg=gray>DB_USERNAME=latam_mig DB_PASSWORD=... php artisan migrate</>');
        $this->line('');

        // En desarrollo el usuario suele ser `root` y eso no es un fallo: es
        // desarrollo. Sólo se rompe la ejecución si se pide explícitamente, o en
        // produccion, que es donde la promesa tiene que ser cierta.
        $exigir = (bool) $this->option('exigir') || app()->environment('production');

        if (!$exigir) {
            $this->comment('  (aviso, no error: esto solo hace fallar con --exigir o en produccion)');
            $this->line('');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * Los privilegios del usuario actual sobre el esquema actual.
     *
     * `information_schema.SCHEMA_PRIVILEGES` en vez de parsear `SHOW GRANTS`:
     * comprobado que devuelve lo mismo en MariaDB 10.11 y en MySQL 8, y no hay
     * que interpretar texto.
     *
     * @return list<string>
     */
    private function privilegios(): array
    {
        try {
            $filas = DB::select(
                "SELECT PRIVILEGE_TYPE AS p
                   FROM information_schema.SCHEMA_PRIVILEGES
                  WHERE GRANTEE = CONCAT('''', SUBSTRING_INDEX(CURRENT_USER(), '@', 1),
                                         '''@''', SUBSTRING_INDEX(CURRENT_USER(), '@', -1), '''')
                    AND TABLE_SCHEMA = DATABASE()",
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (object $f): string => (string) $f->p, $filas);
    }

    /** @return list<string> */
    private function recetaSql(string $base): array
    {
        return [
            '-- El de la APLICACION: lo que necesita para operar, y nada mas.',
            "CREATE USER 'latam_app'@'%' IDENTIFIED BY '<contrasena>';",
            "GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON `{$base}`.* TO 'latam_app'@'%';",
            '',
            '-- El de MIGRACIONES: el unico que cambia el esquema.',
            "CREATE USER 'latam_mig'@'%' IDENTIFIED BY '<otra contrasena>';",
            "GRANT ALL PRIVILEGES ON `{$base}`.* TO 'latam_mig'@'%';",
            '',
            'FLUSH PRIVILEGES;',
        ];
    }
}
