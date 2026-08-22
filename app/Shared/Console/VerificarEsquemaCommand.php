<?php

declare(strict_types=1);

namespace App\Shared\Console;

use App\Shared\Database\Restriccion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Convierte las reglas de docs/fase-2/2.3 y 2.4 en una puerta ejecutable.
 *
 * Una regla que solo vive en un documento se incumple en el tercer sprint sin
 * que nadie lo note. Esto corre en CI y falla el build.
 */
final class VerificarEsquemaCommand extends Command
{
    protected $signature = 'esquema:verificar {--json : Salida en JSON para CI}';

    protected $description = 'Comprueba que el esquema cumple las reglas de tipos, claves y borrado';

    /** Tablas de solo-inserción: no deben tener updated_at ni permitir SET NULL. */
    private const APPEND_ONLY = [
        'domain_events', 'status_transitions', 'audit_logs',
        'ledger_entries', 'xp_entries', 'metric_snapshots',
        'social_account_snapshots', 'deliverable_versions', 'agreement_amendments',
    ];

    /** Tablas del framework que no seguimos nosotros. */
    private const AJENAS = [
        'migrations', 'password_reset_tokens', 'sessions',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public function handle(): int
    {
        $base = DB::connection()->getDatabaseName();
        $fallos = [];

        // Primero el motor. Pero una limitación conocida y compensada NO es un
        // incumplimiento: si esto se pusiera rojo para siempre, en dos semanas
        // nadie miraría la salida. Solo cuenta como fallo lo que se puede corregir.
        $this->line('  <options=bold>Motor</>');
        $limitaciones = [];
        foreach ($this->comprobacionesDeMotor() as $nombre => $resultado) {
            [$severidad, $detalle] = $resultado;
            match ($severidad) {
                'ok' => $this->line("  <fg=green>✓</> {$nombre} <fg=gray>({$detalle})</>"),
                'limitacion' => $this->line("  <fg=yellow>ⓘ</> {$nombre} <fg=gray>({$detalle})</>"),
                default => $this->line("  <fg=red>✗</> {$nombre} <fg=gray>({$detalle})</>"),
            };
            if ($severidad === 'limitacion') {
                $limitaciones[] = $nombre;
            } elseif ($severidad === 'fallo') {
                $fallos[] = "{$nombre}: {$detalle}";
            }
        }
        $this->newLine();
        $this->line('  <options=bold>Esquema</>');

        foreach ($this->comprobaciones() as $nombre => $comprobacion) {
            $encontrados = $comprobacion($base);
            if ($encontrados === []) {
                $this->line("  <fg=green>✓</> {$nombre}");

                continue;
            }
            $this->line("  <fg=red>✗</> {$nombre}");
            foreach ($encontrados as $detalle) {
                $this->line("      <fg=gray>{$detalle}</>");
                $fallos[] = "{$nombre}: {$detalle}";
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(
                ['ok' => $fallos === [], 'fallos' => $fallos, 'limitaciones' => $limitaciones],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            ));
        }

        $this->newLine();

        if ($fallos !== []) {
            $this->error(count($fallos).' incumplimiento(s). Reglas en docs/fase-2/2.3 y 2.4.');

            return self::FAILURE;
        }

        if ($limitaciones !== []) {
            $this->info('Esquema conforme.');
            $this->line(
                '  <fg=yellow>'.count($limitaciones).' limitación(es) del motor, asumidas y compensadas'
                .' (DEC-042). No son incumplimientos.</>',
            );

            return self::SUCCESS;
        }

        $this->info('Esquema conforme, sin limitaciones del motor.');

        return self::SUCCESS;
    }

    /**
     * Comprueba lo que el motor hace DE VERDAD, no lo que dice su número de versión.
     *
     * Existe por una razón concreta: en MySQL 5.7 la cláusula CHECK se analiza y
     * se ignora. La tabla se crea, no hay error, y la restricción no existe. Un
     * esquema lleno de CHECK puede no estar aplicando ninguno sin que nadie lo note
     * hasta que aparece un importe negativo en el ledger (DEC-042).
     *
     * Devuelve [severidad, detalle] por comprobación, donde severidad es
     * 'ok' | 'limitacion' | 'fallo'. Decía `bool` de cuando esto era binario:
     * la anotación se quedó atrás al pasar a tres severidades, y PHPStan la
     * creyó — por eso daba cinco errores de "comparación siempre falsa".
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function comprobacionesDeMotor(): array
    {
        $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;

        return [
            // Que el motor no aplique CHECK es una limitación asumida: se compensa
            // con TRIGGER, y que la compensación exista lo comprueba la regla
            // 'Cada restricción declarada está realmente impuesta'. Si esa falla,
            // el fallo salta ahí, que es donde se puede arreglar.
            'Aplica los CHECK de forma nativa' => $this->severidad(
                $this->pruebaCheck(),
                'limitacion',
                'no, se compensa con TRIGGER',
            ),
            'Soporta CTE (WITH)' => $this->severidad(
                $this->prueba('WITH x AS (SELECT 1 AS a) SELECT a FROM x'),
                'limitacion',
                'no, usar subconsultas',
            ),
            'Soporta funciones de ventana' => $this->severidad(
                $this->prueba('SELECT ROW_NUMBER() OVER (ORDER BY 1) AS r'),
                'limitacion',
                'no, ranking por tablas materializadas',
            ),
            // Esto sí se arregla con un ALTER DATABASE, así que es un fallo.
            'La base usa utf8mb4 (4 bytes: emoji)' => $this->severidad(
                $this->pruebaCharset(),
                'fallo',
            ),
            // Sin modo estricto, un INSERT que omite una columna NOT NULL sin
            // valor por defecto inserta 0 o '' en lugar de fallar. Media docena
            // de restricciones NOT NULL de este esquema dejan de significar lo
            // que parecen: `payouts.payout_batch_id`, por ejemplo, aceptaría 0
            // y solo la clave foránea lo frenaría — por el motivo equivocado.
            'Modo estricto activo (STRICT_TRANS_TABLES)' => $this->severidad(
                $this->pruebaModoEstricto(),
                'fallo',
            ),
            'Versión del servidor' => ['ok', $version],
        ];
    }

    /** @return array{0: bool, 1: string} */
    private function pruebaModoEstricto(): array
    {
        $modo = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        $estricto = str_contains($modo, 'STRICT_TRANS_TABLES')
            || str_contains($modo, 'STRICT_ALL_TABLES');

        return [$estricto, $estricto ? 'sí' : 'NO — añada STRICT_TRANS_TABLES a sql_mode ('.$modo.')'];
    }

    /**
     * Traduce el resultado de una sonda a una severidad.
     *
     * @param array{0: bool, 1: string} $resultado
     * @return array{0: string, 1: string}
     */
    private function severidad(array $resultado, string $siFalla, ?string $detalleSiFalla = null): array
    {
        [$ok, $detalle] = $resultado;

        return $ok ? ['ok', $detalle] : [$siFalla, $detalleSiFalla ?? $detalle];
    }

    /** @return array{0: bool, 1: string} */
    private function pruebaCheck(): array
    {
        // Una cuenta de hosting compartido puede no tener CREATE TABLE. En ese
        // caso hay que decirlo, no reventar el comando con una excepción cruda.
        try {
            DB::statement('DROP TABLE IF EXISTS zz_sonda_check');
            DB::statement('CREATE TABLE zz_sonda_check (n INT, CONSTRAINT ck_sonda CHECK (n > 0))');
        } catch (\Throwable $e) {
            return [false, 'no pude crear la tabla de sonda: '.$this->motivo($e)];
        }

        $aplicado = true;
        try {
            DB::statement('INSERT INTO zz_sonda_check (n) VALUES (-1)');
            $aplicado = false;
        } catch (\Throwable) {
            // Rechazado: el motor sí aplica la restricción.
        } finally {
            DB::statement('DROP TABLE IF EXISTS zz_sonda_check');
        }

        return [$aplicado, $aplicado
            ? 'valor prohibido rechazado'
            : 'ACEPTÓ un valor prohibido: los CHECK del esquema NO se están aplicando'];
    }

    private function motivo(\Throwable $e): string
    {
        $m = $e->getMessage();
        // El mensaje de PDO trae la consulta y a veces los enlaces. Nos quedamos
        // con la primera línea: nunca debe acabar un secreto en un log (docs/03).
        $corte = strpos($m, "\n");

        return trim($corte === false ? $m : substr($m, 0, $corte));
    }

    /** @return array{0: bool, 1: string} */
    private function pruebaCharset(): array
    {
        $fila = DB::selectOne('SELECT @@character_set_database AS cs, @@collation_database AS co');
        $cs = (string) $fila->cs;

        return [str_starts_with($cs, 'utf8mb4'), $cs.' / '.$fila->co];
    }

    /** @return array{0: bool, 1: string} */
    private function prueba(string $sql): array
    {
        try {
            DB::select($sql);

            return [true, 'disponible'];
        } catch (\Throwable $e) {
            return [false, 'no disponible en este motor'];
        }
    }

    /** @return array<string, callable(string): list<string>> */
    private function comprobaciones(): array
    {
        $ajenas = "'".implode("','", self::AJENAS)."'";
        $appendOnly = "'".implode("','", self::APPEND_ONLY)."'";

        return [
            'Ningún importe en punto flotante (BR-FIN-004)' => fn (string $b) => $this->filas(
                "SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME,' es ',DATA_TYPE) AS d
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND DATA_TYPE IN ('float','double','real')", [$b],
            ),

            'Ninguna columna ENUM o SET (docs 2.3 §7)' => fn (string $b) => $this->filas(
                "SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME,' es ',DATA_TYPE) AS d
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND DATA_TYPE IN ('enum','set')", [$b],
            ),

            'Todas las tablas en InnoDB' => fn (string $b) => $this->filas(
                "SELECT CONCAT(TABLE_NAME,' usa ',ENGINE) AS d
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_TYPE='BASE TABLE'
                   AND (ENGINE IS NULL OR ENGINE <> 'InnoDB')", [$b],
            ),

            'Todas las tablas en utf8mb4' => fn (string $b) => $this->filas(
                "SELECT CONCAT(TABLE_NAME,' usa ',TABLE_COLLATION) AS d
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_TYPE='BASE TABLE'
                   AND TABLE_COLLATION NOT LIKE 'utf8mb4%'", [$b],
            ),

            'Ninguna clave foránea con SET NULL (docs 2.2 §5)' => fn (string $b) => $this->filas(
                "SELECT CONCAT(CONSTRAINT_NAME,' en ',TABLE_NAME) AS d
                 FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = ? AND DELETE_RULE = 'SET NULL'", [$b],
            ),

            'Ninguna clave foránea sin política explícita' => fn (string $b) => $this->filas(
                "SELECT CONCAT(CONSTRAINT_NAME,' en ',TABLE_NAME,' -> ',DELETE_RULE) AS d
                 FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = ? AND DELETE_RULE NOT IN ('RESTRICT','CASCADE','NO ACTION')", [$b],
            ),

            'Toda tabla tiene clave primaria' => fn (string $b) => $this->filas(
                "SELECT t.TABLE_NAME AS d
                 FROM information_schema.TABLES t
                 LEFT JOIN information_schema.TABLE_CONSTRAINTS c
                   ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
                  AND c.CONSTRAINT_TYPE = 'PRIMARY KEY'
                 WHERE t.TABLE_SCHEMA = ? AND t.TABLE_TYPE='BASE TABLE'
                   AND t.TABLE_NAME NOT IN ({$ajenas})
                   AND c.CONSTRAINT_NAME IS NULL", [$b],
            ),

            'Las tablas de solo-inserción no tienen updated_at' => fn (string $b) => $this->filas(
                "SELECT CONCAT(TABLE_NAME,' tiene updated_at') AS d
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'updated_at'
                   AND TABLE_NAME IN ({$appendOnly})", [$b],
            ),

            // §57: nunca guardar secretos en claro cuando hay alternativa segura.
            // Una columna que suene a dato sensible debe llevar sufijo que diga
            // cómo está protegida: _encrypted, _hash, _masked, _fingerprint, _last4.
            'Ningún dato sensible almacenado en claro' => fn (string $b) => $this->filas(
                "SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME) AS d
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME NOT IN ('users','password_reset_tokens','sessions','personal_access_tokens')
                   AND (
                     COLUMN_NAME REGEXP '(account_number|card_number|cvv|iban|swift|routing_number)'
                     OR COLUMN_NAME REGEXP '(secret|api_key|private_key|access_token|refresh_token|password)'
                   )
                   AND COLUMN_NAME NOT REGEXP '_(encrypted|hash|hashed|masked|fingerprint|last4|id)$'", [$b],
            ),

            // La comprobación que da sentido a todo lo demás: que cada regla
            // declarada en una migración esté REALMENTE impuesta por el motor.
            // En MySQL 5.7 una restricción puede estar escrita y no existir.
            'Cada restricción declarada está realmente impuesta' => fn (string $b) => $this->restriccionesHuerfanas($b),

            'Ninguna clave foránea apunta a una columna sin índice' => fn (string $b) => $this->filas(
                "SELECT CONCAT(k.TABLE_NAME,'.',k.COLUMN_NAME,' (',k.CONSTRAINT_NAME,')') AS d
                 FROM information_schema.KEY_COLUMN_USAGE k
                 WHERE k.CONSTRAINT_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
                   AND NOT EXISTS (
                     SELECT 1 FROM information_schema.STATISTICS s
                     WHERE s.TABLE_SCHEMA = k.TABLE_SCHEMA AND s.TABLE_NAME = k.TABLE_NAME
                       AND s.COLUMN_NAME = k.COLUMN_NAME AND s.SEQ_IN_INDEX = 1
                   )", [$b],
            ),
        ];
    }

    /**
     * Compara lo que el esquema DICE que impone con lo que el motor impone.
     *
     * @return list<string>
     */
    private function restriccionesHuerfanas(string $base): array
    {
        if (!DB::getSchemaBuilder()->hasTable('schema_constraints')) {
            return ['no existe schema_constraints: ejecuta php artisan migrate'];
        }

        $declaradas = DB::table('schema_constraints')->get();
        if ($declaradas->isEmpty()) {
            return ['no hay ninguna restricción registrada'];
        }

        $checks = $this->checksExistentes($base);
        $triggers = $this->triggersExistentes($base);
        $esperado = Restriccion::mecanismo();
        $huecos = [];

        foreach ($declaradas as $r) {
            $nombre = (string) $r->constraint_name;

            if ($r->mechanism === Restriccion::MECANISMO_CHECK) {
                if (!in_array($nombre, $checks, true)) {
                    $huecos[] = "{$nombre}: declarada como CHECK y el motor no la tiene";
                }
            } else {
                foreach (['ins', 'upd'] as $sufijo) {
                    $tg = 'tg_'.mb_substr($nombre, 0, 57)."_{$sufijo}";
                    if (!in_array($tg, $triggers, true)) {
                        $huecos[] = "{$nombre}: falta el trigger {$tg}";
                    }
                }
            }

            // Si la base se movió a otro motor, el mecanismo grabado se queda viejo.
            if ($r->mechanism !== $esperado) {
                $huecos[] = "{$nombre}: se impuso como '{$r->mechanism}' y este motor admite '{$esperado}'"
                    .' — vuelve a ejecutar las migraciones';
            }
        }

        return $huecos;
    }

    /** @return list<string> */
    private function checksExistentes(string $base): array
    {
        // information_schema.CHECK_CONSTRAINTS no existe en MySQL 5.7.
        try {
            return $this->filas(
                'SELECT CONSTRAINT_NAME AS d FROM information_schema.CHECK_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = ?', [$base],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private function triggersExistentes(string $base): array
    {
        return $this->filas(
            'SELECT TRIGGER_NAME AS d FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [$base],
        );
    }

    /**
     * @param list<mixed> $enlaces
     * @return list<string>
     */
    private function filas(string $sql, array $enlaces): array
    {
        return array_map(
            static fn (object $f): string => (string) $f->d,
            DB::select($sql, $enlaces),
        );
    }
}
