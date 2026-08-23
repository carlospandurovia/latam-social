<?php

declare(strict_types=1);

namespace App\Shared\Workflow;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Escribe en `status_transitions`.
 *
 * La tabla existe desde la iteración 2.4 y, igual que pasaba con `audit_logs`
 * antes de la 3.2, **nadie escribía en ella**. Un histórico de estados vacío es
 * peor que no tenerlo: da la impresión de que se puede reconstruir cómo llegó
 * cada entidad a donde está.
 *
 * Por qué existe además de la bitácora, si parecen lo mismo:
 *
 * - `audit_logs` responde «¿quién tocó qué?». Es evidencia, es inmutable por
 *   disparadores y guarda cualquier campo que cambie.
 * - `status_transitions` responde «¿por dónde pasó esta entidad y cuándo?».
 *   Es la materia prima de los informes de embudo —cuánto tarda un creador
 *   desde `pending` hasta `active`, cuántos se quedan por el camino—, y por eso
 *   es una tabla estrecha y consultable, no un JSON de cambios.
 *
 * `docs/02` §N-04: el histórico manda sobre la columna vigente. La columna
 * `status` es una caché de la última fila de aquí.
 */
final class Transicion
{
    /**
     * @param string|null $desde NULL solo cuando la entidad acaba de nacer.
     *
     * @throws \LogicException si el estado de origen y el de destino coinciden.
     */
    public static function registrar(
        string $tipoEntidad,
        int $idEntidad,
        ?string $desde,
        string $hacia,
        ?string $motivo = null,
    ): void {
        // `ck_status_transitions_change` ya lo rechaza en la base, pero un
        // error 45000 en mitad de una transacción no dice dónde estaba el fallo.
        // Aquí sí: una transición de un estado a sí mismo es un bug del código
        // que la llama, no un dato malo del usuario.
        if ($desde !== null && $desde === $hacia) {
            throw new \LogicException(
                "Transicion vacia en {$tipoEntidad}#{$idEntidad}: de '{$desde}' a '{$hacia}'.",
            );
        }

        DB::table('status_transitions')->insert([
            'entity_type' => $tipoEntidad,
            'entity_id' => $idEntidad,
            'from_status' => $desde,
            'to_status' => $hacia,
            'actor_user_id' => Auth::user()?->getAuthIdentifier(),
            'reason' => $motivo === null ? null : mb_substr($motivo, 0, 255),
            'occurred_at' => now(),
        ]);
    }
}
