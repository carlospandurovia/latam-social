<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\DB;

/**
 * Qué hay que hacer ahora mismo (`D-3`).
 *
 * ### Por qué va arriba del todo, encima de los indicadores
 *
 * Un panel de control se abre para saber **qué hacer**, no para saber cuánto se
 * vendió. Lo segundo se consulta cuando alguien lo pregunta; lo primero
 * interrumpe. Poner los KPIs primero y las alertas debajo es la disposición
 * habitual y es la equivocada: la pantalla empieza contestando la pregunta que
 * nadie tenía.
 *
 * ### `DEC-311`: las alertas NO dependen del periodo
 *
 * Es la decisión menos obvia de esta iteración. Los filtros de arriba recortan
 * los indicadores; aquí no se aplican, y no es un olvido.
 *
 * Una alerta dice «esto está pendiente **hoy**». Recortarla a «últimos 30 días»
 * escondería justo lo que más urge —la factura vencida hace tres meses, el
 * entregable que lleva medio año sin corregir— y lo escondería **en silencio**:
 * el contador bajaría y parecería que hay menos trabajo. Un filtro que oculta
 * trabajo atrasado convierte esta sección en lo contrario de lo que es.
 *
 * La pantalla lo dice con esas palabras, para que la ausencia de recorte no se
 * lea como que el filtro está roto.
 *
 * ### Si no puedes arreglarlo, no lo ves
 *
 * Cada alerta declara el permiso con el que se resuelve, igual que `Preparacion`
 * desde `9.17b`. Quien lleva contenido no ve las facturas vencidas, y quien
 * lleva finanzas no ve la cola de revisión. Además de `BR-SEC-001`, evita la
 * frustración de un aviso que lleva a un 403.
 *
 * ### Los niveles salen del SIGNIFICADO, no de un número
 *
 * «Vencido», «rechazado» y «devuelto» son rojos porque ya pasó algo malo.
 * «Pendiente» es ámbar porque todavía no. Ningún umbral inventado: no hay aquí
 * un «rojo a los 7 días» que nadie decidió. Escalar por antigüedad es una regla
 * de negocio configurable y está anotada como `T-103`; mientras no exista, la
 * antigüedad **se enseña** y la persona juzga.
 */
final class Alertas
{
    public const ROJO = 'rojo';

    public const AMBAR = 'ambar';

    /**
     * Las que hay que atender, ya filtradas por lo que esta persona puede hacer.
     *
     * @return list<array{clave: string, nivel: string, titulo: string, detalle: string,
     *                    cantidad: int, antiguedad: ?int, ruta: string}>
     */
    public static function para(Authorizable $usuario): array
    {
        $alertas = [];

        foreach (self::definiciones() as $definicion) {
            if (!$usuario->can($definicion['permiso'])) {
                continue;
            }

            $cuenta = self::contar(
                $definicion['tabla'],
                $definicion['condicion'],
                $definicion['fecha'] ?? null,
            );

            if ($cuenta['cantidad'] === 0) {
                continue;
            }

            $alertas[] = [
                'clave' => $definicion['clave'],
                'nivel' => $definicion['nivel'],
                'titulo' => $definicion['titulo'],
                'detalle' => $definicion['detalle'],
                'cantidad' => $cuenta['cantidad'],
                'antiguedad' => $cuenta['antiguedad'],
                'ruta' => $definicion['ruta'],
            ];
        }

        return self::ordenar($alertas);
    }

    /**
     * Rojas primero, y dentro de cada nivel las más numerosas antes.
     *
     * Si dos cosas son igual de graves, la que afecta a más casos se atiende
     * antes. Es una función pura y pública **para poder probarla**: montar a la
     * vez una alerta roja y una ámbar con datos de verdad exige fabricar media
     * campaña —creador activo, medio de pago verificado, lote, entregable—, y
     * una prueba con cinco pasos de preparación acaba comprobando la
     * preparación en vez de la regla.
     *
     * @param list<array{nivel: string, cantidad: int, ...}> $alertas
     * @return list<array{nivel: string, cantidad: int, ...}>
     */
    public static function ordenar(array $alertas): array
    {
        usort($alertas, static function (array $a, array $b): int {
            $peso = static fn (array $x): int => $x['nivel'] === self::ROJO ? 0 : 1;

            return [$peso($a), -$a['cantidad']] <=> [$peso($b), -$b['cantidad']];
        });

        return $alertas;
    }

    /**
     * Todo lo que se vigila, en un solo sitio.
     *
     * Una lista y no trece métodos: añadir una alerta es añadir una fila, y así
     * el orden, el permiso y el destino de cada una se ven de un vistazo en vez
     * de repartidos por el archivo.
     *
     * @return list<array{clave: string, nivel: string, titulo: string, detalle: string,
     *                    tabla: string, condicion: \Closure, fecha?: string,
     *                    permiso: string, ruta: string}>
     */
    private static function definiciones(): array
    {
        $hoy = now()->toDateString();
        $ahora = now()->toDateTimeString();

        return [
            // --------------------------------------------------------- contenido
            [
                'clave' => 'entregables_vencidos',
                'nivel' => self::ROJO,
                'titulo' => 'Entregables vencidos',
                'detalle' => 'Pasó su fecha y no están aprobados.',
                'tabla' => 'deliverables',
                'fecha' => 'due_on',
                'condicion' => static fn ($q) => $q
                    ->where('due_on', '<', $hoy)
                    ->whereNotIn('status', ['approved', 'published', 'verified', 'removed', 'cancelled']),
                'permiso' => 'content.review',
                'ruta' => 'revision.cola',
            ],
            [
                'clave' => 'publicaciones_caidas',
                'nivel' => self::ROJO,
                'titulo' => 'Publicaciones caídas',
                'detalle' => 'Estaban publicadas y ya no están. Afecta al pago.',
                'tabla' => 'publications',
                'fecha' => 'removed_at',
                'condicion' => static fn ($q) => $q->where('status', 'removed'),
                'permiso' => 'content.deliverable.view',
                'ruta' => 'verificacion.cola',
            ],
            [
                'clave' => 'entregables_por_revisar',
                'nivel' => self::AMBAR,
                'titulo' => 'Entregables por revisar',
                'detalle' => 'El creador ya los entregó y esperan respuesta.',
                'tabla' => 'deliverables',
                'fecha' => 'submitted_at',
                'condicion' => static fn ($q) => $q->whereIn('status', ['submitted', 'in_review']),
                'permiso' => 'content.review',
                'ruta' => 'revision.cola',
            ],
            [
                'clave' => 'cambios_sin_corregir',
                'nivel' => self::AMBAR,
                'titulo' => 'Cambios pedidos sin corregir',
                'detalle' => 'Se devolvieron al creador y no ha vuelto a entregar.',
                'tabla' => 'deliverables',
                'fecha' => 'updated_at',
                'condicion' => static fn ($q) => $q->where('status', 'changes_requested'),
                'permiso' => 'content.review',
                'ruta' => 'revision.cola',
            ],
            [
                'clave' => 'publicaciones_por_verificar',
                'nivel' => self::AMBAR,
                'titulo' => 'Publicaciones por verificar',
                'detalle' => 'El creador reportó el enlace y nadie lo ha comprobado.',
                'tabla' => 'publications',
                'fecha' => 'created_at',
                'condicion' => static fn ($q) => $q->where('status', 'reported'),
                'permiso' => 'content.deliverable.view',
                'ruta' => 'verificacion.cola',
            ],

            // ---------------------------------------------------------- comercial
            [
                'clave' => 'solicitudes_creador',
                'nivel' => self::AMBAR,
                'titulo' => 'Solicitudes de creador sin revisar',
                'detalle' => 'Postularon y esperan respuesta.',
                'tabla' => 'creator_applications',
                'fecha' => 'submitted_at',
                'condicion' => static fn ($q) => $q->whereIn('status', ['submitted', 'in_review']),
                'permiso' => 'creator.approve',
                'ruta' => 'solicitudes.index',
            ],
            [
                'clave' => 'prospectos_sin_atender',
                'nivel' => self::AMBAR,
                'titulo' => 'Prospectos sin atender',
                'detalle' => 'Escribieron por la portada y siguen sin cualificar.',
                'tabla' => 'client_leads',
                'fecha' => 'submitted_at',
                'condicion' => static fn ($q) => $q->whereIn('status', ['new', 'contacted']),
                'permiso' => 'client.view',
                'ruta' => 'prospectos.index',
            ],
            [
                'clave' => 'invitaciones_caducadas',
                'nivel' => self::AMBAR,
                'titulo' => 'Invitaciones caducadas sin responder',
                'detalle' => 'Su plaza del cupo sigue ocupada hasta que se cierren.',
                'tabla' => 'invitations',
                'fecha' => 'expires_at',
                'condicion' => static fn ($q) => $q
                    ->where('expires_at', '<', $ahora)
                    ->whereNull('responded_at')
                    ->whereNull('revoked_at'),
                'permiso' => 'campaign.view',
                'ruta' => 'campanas.index',
            ],

            // ----------------------------------------------------------- finanzas
            [
                'clave' => 'cobros_vencidos',
                'nivel' => self::ROJO,
                'titulo' => 'Cobros vencidos',
                'detalle' => 'Facturas emitidas cuyo vencimiento pasó y siguen sin saldar.',
                'tabla' => 'invoices',
                'fecha' => 'due_date',
                'condicion' => static fn ($q) => $q
                    ->where('due_date', '<', $hoy)
                    ->whereIn('status', ['issued', 'sent', 'partially_paid']),
                'permiso' => 'finance.view',
                'ruta' => 'facturas.index',
            ],
            [
                'clave' => 'comprobantes_con_error',
                'nivel' => self::ROJO,
                'titulo' => 'Comprobantes rechazados o con error',
                'detalle' => 'La administración no los aceptó. No son válidos hasta corregirlos.',
                'tabla' => 'invoices',
                'fecha' => 'issued_at',
                'condicion' => static fn ($q) => $q->whereIn('external_status', ['rechazado', 'error_red']),
                'permiso' => 'finance.view',
                'ruta' => 'facturas.index',
            ],
            [
                'clave' => 'pagos_devueltos',
                'nivel' => self::ROJO,
                'titulo' => 'Pagos devueltos',
                'detalle' => 'El banco los rechazó. El creador no ha cobrado.',
                'tabla' => 'payouts',
                'fecha' => 'returned_at',
                'condicion' => static fn ($q) => $q->where('status', 'returned'),
                'permiso' => 'finance.view',
                'ruta' => 'lotes.index',
            ],
            [
                'clave' => 'lotes_por_aprobar',
                'nivel' => self::AMBAR,
                'titulo' => 'Lotes de pago por aprobar',
                'detalle' => 'Están armados y esperan una firma para ejecutarse.',
                'tabla' => 'payout_batches',
                'fecha' => 'created_at',
                'condicion' => static fn ($q) => $q->where('status', 'pending_approval'),
                'permiso' => 'finance.view',
                'ruta' => 'lotes.index',
            ],

            // ---------------------------------------------------------- registros
            [
                'clave' => 'correos_fallidos',
                'nivel' => self::AMBAR,
                'titulo' => 'Correos que no salieron',
                'detalle' => 'Agotaron sus reintentos. Alguien no recibió lo que esperaba.',
                'tabla' => 'email_log',
                'fecha' => 'queued_at',
                'condicion' => static fn ($q) => $q->where('status', 'failed'),
                'permiso' => 'comms.view',
                'ruta' => 'correos.index',
            ],
        ];
    }

    /**
     * Cuántos hay y desde cuándo espera el más viejo.
     *
     * Dos agregados en **una** consulta: `COUNT` y `MIN`. Trece alertas serían
     * veintiséis viajes a la base si se pidieran por separado, y este bloque se
     * pinta en cada carga del panel.
     *
     * La guarda de tabla no es paranoia: durante un despliegue a medias, la
     * portada es justo la pantalla que alguien abre para ver qué pasa.
     *
     * @return array{cantidad: int, antiguedad: ?int}
     */
    private static function contar(string $tabla, \Closure $condicion, ?string $fecha): array
    {
        if (!DB::getSchemaBuilder()->hasTable($tabla)) {
            return ['cantidad' => 0, 'antiguedad' => null];
        }

        $consulta = DB::table($tabla);
        $condicion($consulta);

        $seleccion = [DB::raw('COUNT(*) AS cuantos')];
        if ($fecha !== null) {
            $seleccion[] = DB::raw('MIN(`'.$fecha.'`) AS mas_vieja');
        }

        $fila = $consulta->first($seleccion);
        $cantidad = (int) ($fila->cuantos ?? 0);

        $antiguedad = null;
        if ($cantidad > 0 && ($fila->mas_vieja ?? null) !== null) {
            $antiguedad = (int) now()->diffInDays((string) $fila->mas_vieja, absolute: true);
        }

        return ['cantidad' => $cantidad, 'antiguedad' => $antiguedad];
    }
}
