<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cómo va una campaña (7.7).
 *
 * ### La pantalla más usada del sistema, según el roadmap
 *
 * Y por eso lo que decide no es qué se puede enseñar, sino **qué pregunta
 * contesta**. Son cuatro, y en este orden:
 *
 * | Pregunta | Qué la contesta |
 * |---|---|
 * | ¿Por dónde va cada uno? | el embudo por estado |
 * | ¿Me faltan creadores? | el cupo por mercado |
 * | ¿Me cabe otro? | el dinero |
 * | ¿Qué tengo que atender **hoy**? | las alertas |
 *
 * La cuarta es la que justifica que esto no sea una consulta suelta en un
 * controlador: una alerta que hay que deducir mirando cuatro tablas es una
 * alerta que nadie ve.
 *
 * ### El margen no está aquí
 *
 * `margen()` vive en `Compromiso` y sigue detrás de `campaign.view_margin`
 * (`BR-FIN-007`). Lo que sí devuelve este servicio es presupuesto, comprometido
 * y disponible: sin esos tres números no se puede decidir a quién invitar, y
 * dejarlos detrás de un permiso que entonces necesitaría todo el mundo sería un
 * permiso que no protege nada (decisión de negocio, 2026-08-26).
 *
 * La diferencia importa y no es sutil: **lo comprometido es lo que se le paga a
 * los creadores; el margen es lo que se queda la casa.** Lo segundo no lo ve
 * nadie que no tenga por qué.
 */
final class Seguimiento
{
    /**
     * Los estados de participación, en el orden en que se recorren.
     *
     * Es el orden del embudo, no el alfabético ni el de la restricción. Un
     * embudo desordenado no es un embudo: es una lista de conteos.
     */
    public const EMBUDO = [
        'shortlisted' => 'En lista corta',
        'invited' => 'Invitados',
        'accepted' => 'Aceptados',
        'in_production' => 'Produciendo',
        'delivered' => 'Entregado',
        'approved' => 'Aprobado',
        'published' => 'Publicado',
        'verified' => 'Verificado',
        'completed' => 'Terminado',
    ];

    /** Los que salieron del embudo. Se cuentan aparte: no son un paso, son una salida. */
    public const SALIDAS = [
        'declined' => 'Rechazaron',
        'expired' => 'No contestaron',
        'cancelled' => 'Cancelados',
    ];

    /** A cuántas horas de caducar una invitación empieza a ser urgente (`DEC-127`). */
    public const HORAS_URGENTE = 12;

    /** A cuántos días del arranque una campaña sin confirmar es un problema (`DEC-127`). */
    public const DIAS_SIN_CONFIRMAR = 14;

    /**
     * Cuántos hay en cada estado, en el orden del embudo.
     *
     * Devuelve **todos** los pasos, también los que están a cero. Un embudo que
     * esconde los ceros no enseña dónde se atasca la gente: enseña dónde llegó.
     *
     * @return array{pasos: array<string, int>, salidas: array<string, int>, total: int, vivos: int}
     */
    public static function embudo(int $campanaId): array
    {
        $conteos = DB::table('campaign_creators')
            ->where('campaign_id', $campanaId)
            ->groupBy('status')
            ->pluck(DB::raw('COUNT(*)'), 'status');

        $pasos = [];
        foreach (array_keys(self::EMBUDO) as $estado) {
            $pasos[$estado] = (int) ($conteos[$estado] ?? 0);
        }

        $salidas = [];
        foreach (array_keys(self::SALIDAS) as $estado) {
            $salidas[$estado] = (int) ($conteos[$estado] ?? 0);
        }

        return [
            'pasos' => $pasos,
            'salidas' => $salidas,
            'total' => array_sum($pasos) + array_sum($salidas),
            // Los que siguen contando para el presupuesto y para el cupo.
            'vivos' => array_sum($pasos),
        ];
    }

    /**
     * El cupo de cada mercado y cuánto lleva cubierto.
     *
     * **Cubierto = aceptado o más allá**, no invitado. Una invitación sin
     * contestar no es una plaza cubierta: es una plaza esperando, y contarla
     * como cubierta es exactamente cómo se llega al día de arranque con la mitad
     * del equipo. Los invitados se enseñan aparte, que es otra cosa.
     *
     * @return Collection<int, \stdClass>
     */
    public static function cupos(int $campanaId): Collection
    {
        $desdeAceptado = array_slice(array_keys(self::EMBUDO), 2);

        return Mercados::de($campanaId)->map(function (object $mercado) use ($campanaId, $desdeAceptado): object {
            $mercado->cubiertos = DB::table('campaign_creators')
                ->where('campaign_id', $campanaId)
                ->where('campaign_market_id', $mercado->id)
                ->whereIn('status', $desdeAceptado)
                ->count();

            $mercado->invitados = DB::table('campaign_creators')
                ->where('campaign_id', $campanaId)
                ->where('campaign_market_id', $mercado->id)
                ->where('status', 'invited')
                ->count();

            // `target_creators` es NULL cuando nadie dijo cuantos hacen falta, y
            // eso NO es cero: es «sin cupo declarado». Un mercado sin cupo no
            // puede estar corto, porque no hay contra que compararlo.
            $mercado->faltan = $mercado->target_creators === null
                ? null
                : max(0, (int) $mercado->target_creators - $mercado->cubiertos);

            return $mercado;
        });
    }

    /**
     * Presupuesto, comprometido y disponible. **No el margen.**
     *
     * @return array{presupuesto: float, comprometido: float, disponible: float, autorizado: bool, motivo: ?string}
     */
    public static function dinero(object $campana): array
    {
        $presupuesto = (float) $campana->creator_budget_amount;
        $comprometido = Compromiso::comprometido((int) $campana->id);

        return [
            'presupuesto' => $presupuesto,
            'comprometido' => $comprometido,
            // Puede salir NEGATIVO, y se enseña negativo. Redondearlo a cero
            // escondería exactamente el caso que hay que ver: una campaña que se
            // pasó porque finanzas lo autorizó.
            'disponible' => $presupuesto - $comprometido,
            'autorizado' => $campana->budget_override_at !== null,
            'motivo' => $campana->budget_override_reason,
        ];
    }

    /**
     * Lo que hay que atender hoy, lo más urgente primero.
     *
     * Las cuatro las eligió el negocio (2026-08-26). Cada una devuelve **qué
     * pasa y qué hacer**: una alerta que dice «hay un problema» y no dice cuál
     * obliga a buscarlo, y entonces deja de leerse.
     *
     * @return list<array{nivel: string, titulo: string, detalle: string}>
     */
    public static function alertas(object $campana): array
    {
        $alertas = [];

        // 1. La campana empieza pronto y no esta confirmada.
        //
        // Va la primera porque es la que bloquea a las demas: sin confirmar no
        // se puede invitar a nadie, asi que un cupo corto en una campana sin
        // confirmar no se arregla reclutando.
        if ($campana->confirmed_at === null && $campana->closed_at === null) {
            $dias = self::diasHastaArranque($campana);

            if ($dias !== null && $dias <= self::DIAS_SIN_CONFIRMAR) {
                $alertas[] = [
                    'nivel' => $dias < 0 ? 'rojo' : 'ambar',
                    'titulo' => $dias < 0
                        ? 'La campana ya deberia haber empezado y sigue sin confirmar'
                        : sprintf('Empieza en %d dia(s) y sigue sin confirmar', $dias),
                    'detalle' => 'Sin confirmar no se puede invitar a nadie: cada dia que pasa es un dia '
                        .'menos para reclutar. Confirmela o mueva la fecha de inicio.',
                ];
            }
        }

        // 2. Preguntas sin atender.
        //
        // Antes que las invitaciones a punto de caducar, y a proposito: un
        // creador que pregunto y no recibe respuesta MIENTRAS su plazo corre es
        // la combinacion que convierte un si en un silencio.
        $preguntas = Invitaciones::preguntasPendientes((int) $campana->id);

        if ($preguntas > 0) {
            $alertas[] = [
                'nivel' => 'ambar',
                'titulo' => sprintf('%d pregunta(s) de creadores sin atender', $preguntas),
                'detalle' => 'Su invitacion sigue corriendo mientras esperan. Estan en la pantalla de '
                    .'candidatos, en ambar.',
            ];
        }

        // 3. Invitaciones a punto de caducar.
        $urgentes = self::invitacionesUrgentes((int) $campana->id);

        if ($urgentes->isNotEmpty()) {
            $alertas[] = [
                'nivel' => 'ambar',
                'titulo' => sprintf('%d invitacion(es) caducan en menos de %d h',
                    $urgentes->count(), self::HORAS_URGENTE),
                'detalle' => 'Sin contestar: '.$urgentes->pluck('creador')->implode(', ')
                    .'. Al caducar, su importe vuelve al presupuesto y su plaza al cupo.',
            ];
        }

        // 4. Cupo de mercado sin cubrir.
        //
        // La ultima de la lista y la mas importante de todas cuando la campana
        // ya arranco: si no se ve a tiempo, no da tiempo a buscar a nadie.
        foreach (self::cupos((int) $campana->id) as $mercado) {
            if ($mercado->faltan === null || $mercado->faltan === 0) {
                continue;
            }

            $dias = self::diasHastaArranque($campana);

            $alertas[] = [
                'nivel' => $dias !== null && $dias <= 7 ? 'rojo' : 'ambar',
                'titulo' => sprintf('%s: faltan %d de %d creadores',
                    $mercado->pais, $mercado->faltan, (int) $mercado->target_creators),
                'detalle' => $mercado->invitados > 0
                    ? sprintf('Hay %d invitacion(es) sin contestar que podrian cubrirlo.', $mercado->invitados)
                    : 'No hay ninguna invitacion pendiente en ese mercado: hay que buscar a alguien.',
            ];
        }

        return $alertas;
    }

    /**
     * Quién está en cada estado, para la lista de debajo del embudo.
     *
     * @return Collection<int, \stdClass>
     */
    public static function participantes(int $campanaId): Collection
    {
        return DB::table('campaign_creators as cc')
            ->join('creators as c', 'c.id', '=', 'cc.creator_id')
            ->leftJoin('campaign_markets as m', 'm.id', '=', 'cc.campaign_market_id')
            ->leftJoin('countries as p', 'p.id', '=', 'm.country_id')
            ->where('cc.campaign_id', $campanaId)
            ->orderByRaw(self::ordenDelEmbudo())
            ->orderBy('c.display_name')
            ->get([
                'cc.id', 'cc.status', 'cc.agreed_amount', 'cc.currency_code',
                'cc.invited_at', 'cc.accepted_at', 'cc.declined_at',
                'c.display_name', 'c.uuid as creador_uuid', 'p.name as mercado',
            ]);
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Las invitaciones vivas que caducan pronto.
     *
     * @return Collection<int, \stdClass>
     */
    public static function invitacionesUrgentes(int $campanaId): Collection
    {
        return DB::table('invitations as i')
            ->join('campaign_creators as cc', 'cc.id', '=', 'i.campaign_creator_id')
            ->join('creators as c', 'c.id', '=', 'cc.creator_id')
            ->where('cc.campaign_id', $campanaId)
            ->where('i.viva_gate', 1)
            ->where('i.expires_at', '<=', now()->addHours(self::HORAS_URGENTE))
            ->orderBy('i.expires_at')
            ->get(['i.expires_at', 'c.display_name as creador']);
    }

    /**
     * Días hasta que arranca. Negativo si ya arrancó, `null` si no hay fecha.
     */
    public static function diasHastaArranque(object $campana): ?int
    {
        if (($campana->starts_on ?? null) === null) {
            return null;
        }

        // `startOfDay` en los dos lados: la diferencia que interesa es de DIAS
        // de calendario, no de horas. Sin esto, una campana que empieza manana
        // sale como «0 dias» a partir de media tarde.
        return (int) now()->startOfDay()->diffInDays(
            Carbon::parse((string) $campana->starts_on)->startOfDay(),
            false,
        );
    }

    /**
     * `ORDER BY` que respeta el orden del embudo.
     *
     * Se construye desde `EMBUDO` y `SALIDAS` en vez de escribirlo a mano para
     * que añadir un estado no deje una lista ordenada de una forma y contada de
     * otra.
     */
    private static function ordenDelEmbudo(): string
    {
        $orden = array_merge(array_keys(self::EMBUDO), array_keys(self::SALIDAS));

        $casos = '';
        foreach ($orden as $i => $estado) {
            // Los valores salen de constantes de esta clase, no de la peticion:
            // no hay nada que interpolar que venga de fuera.
            $casos .= sprintf(" WHEN '%s' THEN %d", $estado, $i);
        }

        return 'CASE cc.status'.$casos.' ELSE 99 END';
    }
}
