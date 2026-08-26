<?php

declare(strict_types=1);

namespace App\Modules\Communication\Listeners;

use App\Modules\Communication\Services\Correo;
use App\Modules\Identity\Eventos\EnlaceDeContrasenaEmitido;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hace llegar el enlace de contraseña a su destinatario (`5.9`, `4.1`).
 *
 * ### Este oyente SÍ importa una clase de Identity, y está permitido
 *
 * `deptrac.yaml` dice `Communication: [Framework, Shared, Core, Identity]`.
 * Identity está en la lista desde el principio: el correo necesita saber a quién
 * escribe, y eso lo define Identity.
 *
 * Es distinto del aviso de cambio sensible, que llega como un `EventoOcurrido`
 * con un array dentro precisamente porque lo levanta **Creator**, que no está en
 * esa lista. Aquí no hace falta el rodeo, y hacerlo igual «por simetría» tendría
 * un coste real: el payload de `EventoOcurrido` **se guarda en `domain_events`**,
 * y aquí lo que viaja es el token en claro.
 *
 * ### El correo no dice por qué se aprobó ni quién lo pidió
 *
 * Dice qué es el enlace, hasta cuándo vale, y qué hacer si no lo pediste. Nada
 * más. Un correo con enlace de contraseña es lo primero que se falsifica, así
 * que cuanto menos contexto lleve, menos material hay para imitarlo.
 */
final class EnviarEnlaceDeContrasena
{
    /**
     * Qué propósito produce qué plantilla. Explícito, no derivado del nombre.
     *
     * @var array<string, string>
     */
    private const PLANTILLAS = [
        'initial' => 'user.password_initial',
        'reset' => 'user.password_reset',
    ];

    public function handle(EnlaceDeContrasenaEmitido $evento): void
    {
        $plantilla = self::PLANTILLAS[$evento->proposito] ?? null;

        if ($plantilla === null) {
            // Un proposito nuevo sin plantilla es un fallo de programacion, no
            // de datos: se registra fuerte y no se envia nada.
            Log::error('Proposito de enlace sin plantilla de correo.', [
                'proposito' => $evento->proposito,
                'usuario' => $evento->usuarioId,
            ]);

            return;
        }

        try {
            Correo::enviar(
                codigo: $plantilla,
                destinatario: $evento->destinatario,
                variables: [
                    'nombre' => $evento->nombre,
                    'enlace' => $evento->enlace,
                    'caduca' => $evento->caduca,
                    'horas' => $evento->horas,
                ],
                idioma: $evento->idioma,
                tipoRelacionado: 'user',
                idRelacionado: $evento->usuarioId,
            );
        } catch (Throwable $e) {
            // Ni la aprobacion de un creador ni una peticion de recuperacion
            // pueden caerse porque el correo falle. Pero el fallo se ve: el
            // enlace ya esta emitido y `domain_events` tiene la fila que dice
            // que se emitio. La diferencia entre las dos —hay evento y no hay
            // `email_log`— es exactamente «se emitio y no salio».
            report($e);

            Log::error('No se pudo encolar el enlace de contrasena.', [
                'plantilla' => $plantilla,
                'usuario' => $evento->usuarioId,
                'motivo' => $e->getMessage(),
            ]);
        }
    }
}
