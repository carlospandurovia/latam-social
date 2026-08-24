<?php

declare(strict_types=1);

namespace App\Modules\Creator\Console;

use App\Shared\Crypto\CuentaBancaria;
use Illuminate\Console\Command;
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

    public function handle(): int
    {
        $filas = DB::table('creator_payment_methods')
            ->orderBy('id')
            ->get(['id', 'account_number_encrypted', 'account_number_fingerprint']);

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

            $pendientes[(int) $fila->id] = $huella;
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

        if (!$this->option('aplicar')) {
            $this->line('');
            $this->warn('Esto ha sido solo una revision. Para escribir:');
            $this->line('    php artisan pagos:recalcular-huellas --aplicar');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($pendientes): void {
            foreach ($pendientes as $id => $huella) {
                DB::table('creator_payment_methods')
                    ->where('id', $id)
                    ->update(['account_number_fingerprint' => $huella, 'updated_at' => now()]);
            }
        });

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
}
