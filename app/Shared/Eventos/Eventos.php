<?php

declare(strict_types=1);

namespace App\Shared\Eventos;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * El bus de eventos de dominio (4.13).
 *
 * ### La tabla existía desde la Fase 2 y no la escribía nadie
 *
 * `domain_events` se diseñó en 2.4 y hasta hoy no tenía una sola fila. Es el
 * mismo patrón que `campaign_creators` antes de 7.4: una estructura pensada, sin
 * nadie que la usara, esperando a que hiciera falta.
 *
 * Hace falta ahora porque `BR-CREATOR-007` obliga a **notificar** un cambio
 * sensible, y el módulo que sabe del cambio (Creator) **no puede llamar** al que
 * sabe enviar (Communication): el grafo de `deptrac.yaml` no lo permite, y no
 * por capricho — un fallo del correo no debe poder tumbar la captura de un dato
 * fiscal.
 *
 * ### Se GUARDA y además se despacha
 *
 * Las dos cosas, y son distintas:
 *
 * | Qué | Para qué |
 * |---|---|
 * | La fila en `domain_events` | que el hecho conste aunque nadie reaccione |
 * | El evento de Laravel | que alguien reaccione |
 *
 * Guardar sin despachar sería una tabla que nadie lee. Despachar sin guardar
 * dejaría el hecho a merced de que el oyente funcione: si el correo falla, no
 * quedaría rastro de que el cambio se produjo. La fila se escribe **primero**, a
 * propósito.
 *
 * ### No es la bitácora
 *
 * `Bitacora` responde *«quién tocó qué y qué valores cambió»* — es evidencia de
 * auditoría, append-only y protegida por permisos de base de datos (`DEC-085`).
 * Esto responde *«qué pasó, para que otros reaccionen»*. La primera se consulta
 * cuando hay una discusión; la segunda dispara trabajo. Mezclarlas haría que
 * añadir un oyente ensuciara la evidencia.
 */
final class Eventos
{
    /**
     * Registra el hecho y avisa a quien escuche.
     *
     * @param array<string, string|int|float> $payload
     */
    public static function ocurrio(
        string $nombre,
        string $tipoEntidad,
        int $idEntidad,
        array $payload = [],
    ): void {
        // Primero la fila. Si el oyente revienta, el hecho ya consta.
        DB::table('domain_events')->insert([
            'uuid' => (string) Str::uuid(),
            'event_name' => $nombre,
            'entity_type' => $tipoEntidad,
            'entity_id' => $idEntidad,
            'actor_user_id' => Auth::id(),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'occurred_at' => now(),
        ]);

        Event::dispatch(new EventoOcurrido($nombre, $tipoEntidad, $idEntidad, $payload));
    }
}
