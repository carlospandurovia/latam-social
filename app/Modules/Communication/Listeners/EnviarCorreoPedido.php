<?php

declare(strict_types=1);

namespace App\Modules\Communication\Listeners;

use App\Modules\Communication\Services\Correo;
use App\Shared\Eventos\CorreoPedido;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Manda el correo que alguien pidió, sin saber quién lo pidió (`5.9`, `4.1`, `7.6`).
 *
 * Es la contraparte de `CorreoPedido`: dos clases, un contrato, y ningún módulo
 * de negocio importando a Communication ni al revés.
 *
 * ### El fallo no tumba lo que lo provocó, pero se ve
 *
 * Misma decisión que `DEC-111`. Ni aprobar a un creador, ni pedir una
 * recuperación, ni invitar a alguien pueden caerse porque el correo falle. Lo que
 * no se hace es tragárselo: `report()` lo manda al manejador de errores y queda
 * la asimetría que lo delata — hay evento de dominio y no hay fila en
 * `email_log`, o sea «se emitió y no salió».
 */
final class EnviarCorreoPedido
{
    public function handle(CorreoPedido $evento): void
    {
        if ($evento->destinatario === '') {
            // Sin destinatario no hay a donde mandar. Se registra en vez de
            // reventar: quien lo pidio sigue teniendo su trabajo hecho.
            Log::warning('Correo pedido sin destinatario.', ['plantilla' => $evento->codigo]);

            return;
        }

        try {
            Correo::enviar(
                codigo: $evento->codigo,
                destinatario: $evento->destinatario,
                variables: $evento->variables,
                idioma: $evento->idioma,
                tipoRelacionado: $evento->tipoRelacionado,
                idRelacionado: $evento->idRelacionado,
            );
        } catch (Throwable $e) {
            report($e);

            Log::error('No se pudo encolar un correo pedido.', [
                'plantilla' => $evento->codigo,
                'relacionado' => $evento->tipoRelacionado.':'.$evento->idRelacionado,
                'motivo' => $e->getMessage(),
            ]);
        }
    }
}
