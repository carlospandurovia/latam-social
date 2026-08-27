<?php

declare(strict_types=1);

namespace App\Modules\Communication\Listeners;

use App\Modules\Communication\Services\Correo;
use App\Shared\Eventos\EventoOcurrido;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Convierte un cambio de dato sensible en un aviso al creador (`T-10`, 4.13).
 *
 * Media `BR-CREATOR-007`, que es 🔴 y llevaba desde la Fase 3 a medio cumplir:
 *
 * > Los cambios en datos fiscales, medios de pago o documento de identidad
 * > **requieren aprobación interna** antes de surtir efecto, **y notifican al
 * > canal de contacto anterior.**
 *
 * La primera mitad está desde 3.6 y 3.8 —y con dos personas distintas, que la
 * base exige—. **La segunda no existía.** La pantalla se lo recordaba al
 * operador para que lo hiciera a mano, que es otra forma de decir que no se
 * hacía.
 *
 * ### Se avisa al CAPTURAR, no al aprobar
 *
 * Decisión de negocio (2026-08-26). Es lo único que le da al creador margen para
 * decir *«yo no fui»* mientras el cambio todavía se puede parar. Avisar después
 * de aprobarlo es contarle un hecho consumado: si alguien suplantó su cuenta
 * bancaria, el dinero ya va camino de otro sitio.
 *
 * ### El correo no lleva el dato dentro
 *
 * Decisión de negocio (2026-08-26): *«alguien registró un cambio en sus datos de
 * pago; si no fue usted, escríbanos»*. Sin número, sin banco, sin régimen.
 *
 * Un correo se lee en pantallas ajenas, se reenvía y se queda en buzones que no
 * controlamos — y el escenario del que nos defendemos es precisamente que
 * alguien tenga acceso a ese buzón. **Para decir «yo no fui» no hace falta ver
 * el número.**
 *
 * ### Y si el aviso no sale, el cambio sigue
 *
 * Decisión de negocio (2026-08-26). Bloquear la captura de un dato fiscal porque
 * un SMTP se cayó convierte un problema de infraestructura en un creador al que
 * no se le puede corregir un dato.
 *
 * El fallo **no se traga**: si el correo se encoló, aparece en `/correos` como
 * `failed` con su motivo. Si ni siquiera se pudo encolar —no hay plantilla
 * publicada, que es un fallo de configuración de la plataforma— se registra con
 * `report()` y queda la fila de `domain_events` diciendo que el hecho ocurrió
 * sin que saliera aviso.
 */
final class AvisarCambioSensible
{
    /**
     * Qué evento produce qué plantilla.
     *
     * Un mapa explícito y no una convención de nombres: `creator.tax_profile_changed`
     * podría derivarse del evento con una regla, y esa regla es justo lo que hace
     * que un renombrado silencioso deje de mandar avisos sin que falle nada.
     *
     * @var array<string, string>
     */
    private const AVISOS = [
        'creator.tax_profile_captured' => 'creator.tax_profile_changed',
        'creator.payment_method_captured' => 'creator.payment_method_changed',
    ];

    public function handle(EventoOcurrido $evento): void
    {
        $plantilla = self::AVISOS[$evento->nombre] ?? null;

        if ($plantilla === null) {
            return;
        }

        $correo = (string) ($evento->payload['correo'] ?? '');

        if ($correo === '') {
            // Sin correo no hay a donde avisar. Se registra en vez de reventar:
            // el creador existe y su dato hay que poder corregirlo igual.
            Log::warning('Cambio sensible sin correo al que avisar.', [
                'evento' => $evento->nombre,
                'creador' => $evento->idEntidad,
            ]);

            return;
        }

        try {
            Correo::enviar(
                codigo: $plantilla,
                destinatario: $correo,
                variables: [
                    'nombre' => (string) ($evento->payload['nombre'] ?? ''),
                    'fecha' => (string) ($evento->payload['fecha'] ?? now()->toDateString()),
                ],
                idioma: (string) ($evento->payload['idioma'] ?? ''),
                tipoRelacionado: $evento->tipoEntidad,
                idRelacionado: $evento->idEntidad,
            );
        } catch (Throwable $e) {
            // El cambio sigue adelante (decision de negocio). Pero el fallo NO se
            // traga: `report()` lo manda al manejador de errores, y la fila de
            // `domain_events` deja constancia de que el hecho ocurrio aunque el
            // aviso no saliera.
            report($e);

            Log::error('No se pudo encolar el aviso de cambio sensible.', [
                'evento' => $evento->nombre,
                'plantilla' => $plantilla,
                'creador' => $evento->idEntidad,
                'motivo' => $e->getMessage(),
            ]);
        }
    }
}
