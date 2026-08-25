<?php

declare(strict_types=1);

namespace App\Modules\Creator\Console;

use App\Shared\Crypto\CuentaBancaria;
use App\Shared\Database\Choque;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recalcula las huellas de las cuentas bancarias tras rotar `APP_KEY` (`T-11`).
 *
 * ### Qué se rompe al rotar la clave, y por qué no se nota
 *
 * El número de cuenta va **cifrado y reversible** —hay que poder recuperarlo
 * para pagar— y además lleva una **huella** HMAC-SHA256 que permite comparar
 * dos cuentas sin descifrar ninguna. Es lo que detecta que dos creadores
 * declararon la misma cuenta (`DEC-065`).
 *
 * El HMAC usa `APP_KEY`. Al rotarla:
 *
 * - Los **números siguen recuperándose**: `Crypt` prueba también las claves de
 *   `APP_PREVIOUS_KEYS`.
 * - Las **huellas dejan de casar**: las filas viejas llevan huellas de la clave
 *   vieja y las nuevas de la nueva, así que dos cuentas idénticas dan huellas
 *   distintas.
 *
 * Y aquí está lo peligroso: **nada falla**. No hay error, ni excepción, ni fila
 * rechazada. El control que detecta cuentas compartidas simplemente deja de
 * detectarlas, y nadie se entera hasta que hay dos creadores cobrando en la
 * misma cuenta.
 *
 * ### Por qué no escribe nada si algo no se puede descifrar
 *
 * Si una sola fila no se descifra —porque su clave ya no está en
 * `APP_PREVIOUS_KEYS`— el comando **no toca ninguna**. Un recálculo a medias es
 * peor que ninguno: deja media tabla con huellas de una clave y media de otra,
 * o sea el mismo apagón silencioso pero ahora imposible de sospechar, porque
 * «el comando ya se ejecutó».
 *
 * ### Uso
 *
 *     php artisan pagos:recalcular-huellas            # solo mira e informa
 *     php artisan pagos:recalcular-huellas --aplicar  # escribe
 *
 * Es idempotente: la segunda vez no cambia nada.
 */
final class RecalcularHuellasCommand extends Command
{
    protected $signature = 'pagos:recalcular-huellas
        {--aplicar : Escribe los cambios. Sin esta opcion solo informa.}';

    protected $description = 'Recalcula las huellas de las cuentas bancarias tras rotar APP_KEY (T-11).';

    /**
     * Los estados con los que `open_gate` vale 1.
     *
     * Se escribe aqui y no se deduce: si algun dia el `CASE WHEN` de la columna
     * generada cambia y esto no, la comprobacion mira un conjunto de filas
     * distinto del que mira la unica, y deja de comprobar lo que dice.
     *
     * @var list<string>
     */
    private const ABIERTOS = ['pending', 'verified'];

    public function handle(): int
    {
        $filas = DB::table('creator_payment_methods')
            ->orderBy('id')
            ->get(['id', 'creator_id', 'status', 'account_number_encrypted', 'account_number_fingerprint']);

        if ($filas->isEmpty()) {
            $this->info('No hay medios de pago. Nada que recalcular.');

            return self::SUCCESS;
        }

        $pendientes = [];
        $alDia = 0;
        $ilegibles = [];

        foreach ($filas as $fila) {
            try {
                $numero = CuentaBancaria::descifrar((string) $fila->account_number_encrypted);
            } catch (Throwable) {
                $ilegibles[] = (int) $fila->id;

                continue;
            }

            $huella = CuentaBancaria::huella($numero);

            if ($huella === (string) $fila->account_number_fingerprint) {
                $alDia++;

                continue;
            }

            $pendientes[(int) $fila->id] = ['huella' => $huella, 'fila' => $fila];
        }

        $this->line('  Medios de pago .................. '.$filas->count());
        $this->line('  Huellas ya correctas ............ '.$alDia);
        $this->line('  Huellas a recalcular ............ '.count($pendientes));
        $this->line('  No se pudieron descifrar ........ '.count($ilegibles));

        if ($ilegibles !== []) {
            $this->line('');
            $this->error('No escribo nada: hay filas que no se pueden descifrar.');
            $this->line('  Ids: '.implode(', ', \array_slice($ilegibles, 0, 20))
                .(count($ilegibles) > 20 ? ' ...' : ''));
            $this->line('');
            $this->line('  Su clave ya no esta disponible. Pongala en APP_PREVIOUS_KEYS y');
            $this->line('  vuelva a ejecutar.');
            $this->line('');
            $this->line('  Un recalculo a medias dejaria media tabla con huellas de una clave');
            $this->line('  y media de otra: la deteccion de cuentas repetidas seguiria');
            $this->line('  apagada, pero ya nadie sospecharia porque el comando "ya se ejecuto".');

            return self::FAILURE;
        }

        if ($pendientes === []) {
            $this->line('');
            $this->info('Todas las huellas cuadran con la clave actual. No hay nada que hacer.');

            return self::SUCCESS;
        }

        // ------------------------------------------------- La convergencia (`T-20`)
        //
        // `uq_cpm_open_account (open_gate, creator_id, account_number_fingerprint)`
        // impide que un creador tenga la MISMA cuenta dos veces abierta. Pero
        // con la clave rotada dos filas de la misma cuenta llevan huellas
        // DISTINTAS --una de cada clave--, asi que la unica no las vio y las
        // dos entraron.
        //
        // Al recalcular, las dos huellas convergen. El segundo `UPDATE` choca
        // con un `1062`, la transaccion se cae entera, y el operador ve un
        // `Duplicate entry` en crudo desde un comando de consola.
        //
        // Y este choque **no se absorbe**: no es un valor que calculo el
        // sistema y pueda recalcular, es un dato real que estaba mal y que la
        // rotacion acaba de sacar a la luz --dos medios de pago abiertos del
        // mismo creador que son la misma cuenta--. Cual de los dos sobrevive no
        // lo decide un comando.
        //
        // Se mira ANTES de escribir, como la comprobacion previa de las
        // migraciones: se comprueba todo, se dice de una vez, y no se toca
        // nada. Un fallo a mitad de transaccion aqui no corrompe --hay
        // rollback-- pero deja al operador con un mensaje que no explica que
        // hacer.
        $convergen = self::gruposQueConvergen($filas, $pendientes);

        if ($convergen !== []) {
            $this->line('');
            $this->error('No escribo nada: al recalcular, dos medios de pago del mismo creador '
                .'pasarian a tener la misma huella.');
            $this->line('');

            foreach ($convergen as $grupo) {
                $this->line('  creador '.$grupo['creador'].': medios '.implode(', ', $grupo['ids']));
            }

            $this->line('');
            $this->line('  Son la MISMA cuenta, dada de alta dos veces. La unica');
            $this->line('  `uq_cpm_open_account` no lo impidio porque con la clave vieja cada');
            $this->line('  fila llevaba una huella distinta; al recalcularlas con la clave');
            $this->line('  actual, coinciden.');
            $this->line('');
            $this->line('  Hay que desactivar uno de cada pareja antes de recalcular. Cual');
            $this->line('  sobrevive es una decision de negocio: mire cual esta verificado, por');
            $this->line('  quien, y si alguno tiene pagos detras. No lo decide este comando.');

            return self::FAILURE;
        }

        if (!$this->option('aplicar')) {
            $this->line('');
            $this->warn('Esto ha sido solo una revision. Para escribir:');
            $this->line('    php artisan pagos:recalcular-huellas --aplicar');

            return self::SUCCESS;
        }

        // Entre la comprobacion de arriba y este `UPDATE` cabe otra peticion:
        // alguien puede dar de alta un medio de pago en ese hueco. El choque
        // sigue siendo el mismo problema y merece el mismo mensaje, no un
        // `Duplicate entry` en crudo desde una consola.
        //
        // Se traduce, no se absorbe. `Choque::reintentar()` seria justo lo
        // contrario de lo que hace falta aqui: reintentar daria la misma huella
        // y chocaria igual, tres veces.
        try {
            DB::transaction(function () use ($pendientes): void {
                foreach ($pendientes as $id => $cambio) {
                    DB::table('creator_payment_methods')
                        ->where('id', $id)
                        ->update([
                            'account_number_fingerprint' => $cambio['huella'],
                            'updated_at' => now(),
                        ]);
                }
            });
        } catch (Throwable $e) {
            if (!Choque::esDe($e, 'uq_cpm_open_account')) {
                throw $e;
            }

            $this->line('');
            $this->error('No se escribio nada: dos medios de pago abiertos del mismo creador '
                .'quedarian con la misma huella.');
            $this->line('');
            $this->line('  Aparecio ENTRE la revision y la escritura, asi que alguien dio de alta');
            $this->line('  un medio de pago mientras esto corria. Vuelva a ejecutar sin');
            $this->line('  `--aplicar` para ver cuales son.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info(count($pendientes).' huella(s) recalculada(s).');

        // `shared_account_status` NO queda obsoleto y por eso no se toca: todas
        // las filas se recalculan con la MISMA clave, asi que dos cuentas
        // iguales siguen dando la misma huella. Lo que cambia es el valor, no
        // la relacion entre valores.
        $this->line('  `shared_account_status` no cambia: al recalcularse todas con la misma');
        $this->line('  clave, dos cuentas iguales siguen dando la misma huella.');

        return self::SUCCESS;
    }

    /**
     * Los grupos que quedarian con la misma huella dentro del mismo creador.
     *
     * Se mira el estado **despues** del recalculo, no el de antes: la huella de
     * cada fila es la nueva si la fila esta en `$pendientes`, y la que ya tiene
     * si no. Mirar solo las pendientes entre si dejaria pasar el caso mas
     * probable de todos --una fila vieja y una nueva--, que es justo el que
     * produce la rotacion.
     *
     * Solo cuentan las filas ABIERTAS, que son las que entran en
     * `uq_cpm_open_account`: `open_gate` vale 1 con `pending` o `verified`. Una
     * cuenta desactivada no ocupa sitio y no puede chocar con nadie.
     *
     * `Collection<int, \stdClass>` y no `<int, object>`: `TValue` **no es
     * covariante** en `Collection`, asi que una coleccion de `stdClass` --que
     * es lo que devuelve el constructor de consultas-- no vale donde se pide
     * una de `object`, aunque `stdClass` sea un `object`. Lo dijo PHPStan; a
     * mano parece lo mismo y no lo es.
     *
     * @param Collection<int, \stdClass> $filas
     * @param array<int, array{huella: string, fila: object}> $pendientes
     * @return list<array{creador: int, ids: list<int>}>
     */
    private static function gruposQueConvergen($filas, array $pendientes): array
    {
        $porClave = [];

        foreach ($filas as $fila) {
            if (!in_array((string) $fila->status, self::ABIERTOS, true)) {
                continue;
            }

            $id = (int) $fila->id;
            $huella = $pendientes[$id]['huella'] ?? (string) $fila->account_number_fingerprint;

            $porClave[(int) $fila->creator_id][$huella][] = $id;
        }

        $grupos = [];

        foreach ($porClave as $creadorId => $porHuella) {
            foreach ($porHuella as $ids) {
                if (count($ids) > 1) {
                    $grupos[] = ['creador' => (int) $creadorId, 'ids' => $ids];
                }
            }
        }

        return $grupos;
    }
}
