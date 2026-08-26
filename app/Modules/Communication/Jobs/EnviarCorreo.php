<?php

declare(strict_types=1);

namespace App\Modules\Communication\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía un correo ya renderizado y anota el resultado (4.9).
 *
 * ### Tres intentos espaciados, y luego se ve
 *
 * Decisión de negocio (2026-08-26): 1, 5 y 15 minutos. Cubre la caída pasajera
 * del proveedor sin insistir eternamente sobre una dirección que no existe —que
 * es la causa más común— y al agotarse queda `failed` **con el error**, en una
 * pantalla que alguien mira. No en un archivo de log que nadie abre.
 *
 * ### El cuerpo viaja en el job, no en la tabla
 *
 * Se renderiza al encolar y viaja con el trabajo. Vive lo que dure la cola, que
 * es exactamente lo que hace falta para enviarlo. `email_log` guarda su huella,
 * no su texto: ver `Correo`.
 *
 * ### Si el registro ya no está `queued`, no se envía
 *
 * Un reintento sobre una fila que ya salió `sent` mandaría el correo dos veces.
 * La comprobación va **dentro** del job y no antes de despacharlo, porque entre
 * las dos cosas puede pasar un reintento.
 */
final class EnviarCorreo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Tres intentos: el primero inmediato y dos reintentos. */
    public int $tries = 3;

    /** @var list<int> Segundos entre intentos: 1, 5 y 15 minutos. */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly string $registroUuid,
        private readonly string $destinatario,
        private readonly string $asunto,
        private readonly string $cuerpo,
    ) {}

    public function handle(): void
    {
        $registro = DB::table('email_log')->where('uuid', $this->registroUuid)->first(['id', 'status']);

        if ($registro === null || (string) $registro->status !== 'queued') {
            // Ya se envio, se cancelo, o alguien borro la fila. Salir en
            // silencio es lo correcto: reintentar mandaria el correo dos veces.
            return;
        }

        DB::table('email_log')->where('id', $registro->id)
            ->update(['attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        try {
            Mail::raw($this->cuerpo, function ($mensaje): void {
                $mensaje->to($this->destinatario)->subject($this->asunto);
            });
        } catch (Throwable $e) {
            // Se anota el intento fallido y se RELANZA: la cola decide si quedan
            // reintentos. Tragarse la excepcion aqui dejaria el correo en
            // `queued` para siempre, que es peor que `failed` --uno se ve y el
            // otro parece que sigue en camino.
            DB::table('email_log')->where('id', $registro->id)->update([
                'last_error' => mb_substr($e->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);

            throw $e;
        }

        DB::table('email_log')->where('id', $registro->id)->update([
            'status' => 'sent',
            'sent_at' => now(),
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    /** Cuando se agotan los tres intentos. */
    public function failed(?Throwable $e): void
    {
        DB::table('email_log')->where('uuid', $this->registroUuid)->update([
            'status' => 'failed',
            'failed_at' => now(),
            // `ck_el_failed` exige cuando fallo Y por que. Si la excepcion viene
            // vacia se escribe algo: un `failed` sin motivo obliga a mirar el
            // log del servidor, que es lo que esta tabla existe para evitar.
            'last_error' => mb_substr(
                $e?->getMessage() ?: 'Se agotaron los tres intentos sin un error concreto.',
                0, 500,
            ),
            'updated_at' => now(),
        ]);
    }
}
