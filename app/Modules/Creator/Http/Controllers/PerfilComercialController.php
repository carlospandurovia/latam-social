<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Controllers;

use App\Modules\Creator\Http\Requests\GuardarBloqueoRequest;
use App\Modules\Creator\Http\Requests\GuardarDisponibilidadRequest;
use App\Modules\Creator\Http\Requests\GuardarTarifaRequest;
use App\Shared\Audit\Bitacora;
use App\Shared\Database\Vigencia;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cuánto cuesta un creador y cuándo puede trabajar (iteración 3.9).
 *
 * Es lo que falta para poder invitarlo a una campaña. `campaign_creators`
 * congela lo pactado en `agreed_amount`; esta pantalla es **de dónde sale ese
 * número**.
 *
 * Cuatro cosas que no son evidentes:
 *
 * 1. **`valid_to` es inclusivo, así que cerrar es poner el día ANTERIOR.**
 *    Cambiar de tarifa no edita la anterior: la cierra el día antes y abre una
 *    nueva. Si se cerrara el mismo día, las dos estarían vigentes esa fecha y
 *    «cuánto costaba el 1 de mayo» volvería a tener dos respuestas (`H-16`).
 *
 * 2. **Nadie ve el margen.** `BR-FIN-007` lo reserva a `campaign.view_margin`;
 *    aquí solo se ve el costo del creador, que es una de sus tres partes.
 *
 * 3. **Un bloqueo de agenda no se rechaza aunque pise una campaña aceptada**
 *    (`DEC-070`). Si el creador se opera o viaja, el bloqueo es un hecho: la
 *    pantalla dice qué campañas quedan dentro para que alguien hable con él.
 *    Marcar, no rechazar — como `DEC-063` y `DEC-065`.
 *
 * 4. **`creator.rate.manage` es un permiso propio** (`DEC-069`). La tarifa es
 *    el costo del creador; darla al gestor de campañas no abre además sus datos
 *    fiscales ni su cuenta bancaria.
 */
final class PerfilComercialController
{
    public function index(string $uuid): View
    {
        $creador = $this->porUuid($uuid);
        $hoy = CarbonImmutable::now()->toDateString();

        $bloqueos = DB::table('creator_blackouts')
            ->where('creator_id', $creador->id)
            ->orderByDesc('starts_on')
            ->get(['id', 'starts_on', 'ends_on', 'reason']);

        return view('creadores.comercial', [
            'creador' => $creador,
            'hoy' => $hoy,
            'tarifas' => DB::table('creator_rates as r')
                ->join('content_formats as f', 'f.id', '=', 'r.content_format_id')
                ->join('platforms as p', 'p.id', '=', 'f.platform_id')
                ->leftJoin('users as u', 'u.id', '=', 'r.created_by_user_id')
                ->where('r.creator_id', $creador->id)
                ->orderBy('p.name')->orderBy('f.code')->orderByDesc('r.valid_from')
                ->get([
                    'r.id', 'r.amount', 'r.is_gratis', 'r.currency_code', 'r.source',
                    'r.valid_from', 'r.valid_to', 'r.content_format_id',
                    'f.code as formato', 'p.name as plataforma', 'u.name as puesta_por',
                ]),
            'formatos' => DB::table('content_formats as f')
                ->join('platforms as p', 'p.id', '=', 'f.platform_id')
                ->where('f.is_active', 1)
                ->orderBy('p.name')->orderBy('f.code')
                ->get(['f.id', 'f.code', 'p.name as plataforma']),
            'monedas' => DB::table('currencies')->where('is_active', 1)->orderBy('code')->get(['code', 'name']),
            'disponibilidades' => DB::table('creator_availability')
                ->where('creator_id', $creador->id)
                ->orderByDesc('valid_from')
                ->get(),
            'bloqueos' => $bloqueos,
            // Los choques se calculan una vez para toda la lista, no uno por
            // bloqueo dentro de la vista: una consulta por fila en una plantilla
            // es la forma mas facil de que una pantalla se vuelva lenta sin que
            // nadie sepa por que.
            'choques' => $this->choquesDeAgenda($creador->id, $bloqueos->pluck('id')->all()),
        ]);
    }

    public function guardarTarifa(GuardarTarifaRequest $request, string $uuid): RedirectResponse
    {
        $creador = $this->porUuid($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();
        $gratis = (bool) ($datos['is_gratis'] ?? false);
        $desde = (string) $datos['valid_from'];

        $vigente = DB::table('creator_rates')
            ->where('creator_id', $creador->id)
            ->where('content_format_id', $datos['content_format_id'])
            ->where('currency_code', $datos['currency_code'])
            ->whereNull('valid_to')
            ->first(['id', 'valid_from', 'amount']);

        if ($vigente !== null && !Vigencia::puedeRelevar($desde, (string) $vigente->valid_from)) {
            // Cerrarla el dia antes la dejaria terminando antes de empezar, que
            // es lo que `ck_creator_rates_dates` prohibe. La base lo rechazaria
            // igual; aqui se dice por que.
            return back()->withInput()->with('aviso',
                "Ya hay una tarifa vigente desde el {$vigente->valid_from}. La nueva tiene que empezar después.");
        }

        DB::transaction(function () use ($creador, $datos, $gratis, $desde, $vigente, $request): void {
            if ($vigente !== null) {
                // El dia ANTERIOR, no el mismo. Ver el comentario de la clase.
                DB::table('creator_rates')->where('id', $vigente->id)->update([
                    'valid_to' => Vigencia::cerrarElDiaAntesDe($desde),
                    'updated_at' => now(),
                ]);
            }

            DB::table('creator_rates')->insert([
                'creator_id' => $creador->id,
                'content_format_id' => $datos['content_format_id'],
                'currency_code' => $datos['currency_code'],
                'amount' => $gratis ? 0 : $datos['amount'],
                'is_gratis' => $gratis,
                // H-17: quien la fija dice de donde sale. No hay valor por
                // omision que pueda responder eso.
                'source' => $datos['source'],
                'created_by_user_id' => $request->user()?->getAuthIdentifier(),
                'valid_from' => $desde,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Bitacora::registrar(
            accion: 'creator_rate.set',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'formato' => ['antes' => null, 'despues' => $datos['content_format_id']],
                'importe' => ['antes' => $vigente?->amount, 'despues' => $gratis ? 'gratuita' : $datos['amount']],
                'moneda' => ['antes' => null, 'despues' => $datos['currency_code']],
                'origen' => ['antes' => null, 'despues' => $datos['source']],
                'desde' => ['antes' => null, 'despues' => $desde],
            ],
        );

        $mensaje = 'Tarifa registrada.';

        if ($vigente !== null) {
            $mensaje .= ' La anterior queda cerrada el día antes, para que el histórico no se pise.';
        }

        return redirect()->route('creadores.comercial', $uuid)->with('exito', $mensaje);
    }

    public function guardarDisponibilidad(GuardarDisponibilidadRequest $request, string $uuid): RedirectResponse
    {
        $creador = $this->porUuid($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();
        $desde = (string) $datos['valid_from'];

        // Desde la peticion cruda, no desde `validated()`: `boolean()` de
        // Laravel devuelve false cuando la clave no existe, que es justo lo que
        // significa una casilla sin marcar.
        $marcadas = [
            'accepts_travel' => $request->boolean('accepts_travel'),
            'accepts_in_person' => $request->boolean('accepts_in_person'),
            'accepts_product_only' => $request->boolean('accepts_product_only'),
        ];

        // Y se quitan de `$datos` para que no entren dos veces con valores
        // distintos: lo que manda es `$marcadas`.
        unset($datos['accepts_travel'], $datos['accepts_in_person'], $datos['accepts_product_only']);

        $vigente = DB::table('creator_availability')
            ->where('creator_id', $creador->id)
            ->whereNull('valid_to')
            ->first(['id', 'valid_from']);

        if ($vigente !== null && !Vigencia::puedeRelevar($desde, (string) $vigente->valid_from)) {
            return back()->withInput()->with('aviso',
                "Ya hay una disponibilidad vigente desde el {$vigente->valid_from}. La nueva tiene que empezar después.");
        }

        // BR-CREATOR-010 no aplica aqui, pero la coherencia de `accepts_travel`
        // si: la base la exige con `ck_creator_availability_scope_required`.
        if (!(bool) ($datos['accepts_travel'] ?? false)) {
            $datos['travel_scope'] = null;
        }

        DB::transaction(function () use ($creador, $datos, $desde, $vigente, $marcadas): void {
            if ($vigente !== null) {
                DB::table('creator_availability')->where('id', $vigente->id)->update([
                    'valid_to' => Vigencia::cerrarElDiaAntesDe($desde),
                    'updated_at' => now(),
                ]);
            }

            DB::table('creator_availability')->insert($datos + [
                'creator_id' => $creador->id,
                // Las tres casillas se escriben SIEMPRE, con su valor explicito.
                //
                // Una casilla sin marcar no viaja, asi que `validated()` no
                // traia la clave y la columna tomaba su DEFAULT. Para
                // `accepts_travel` y `accepts_product_only` el default es 0 y
                // coincidia por suerte; `accepts_in_person` tiene DEFAULT 1, asi
                // que desmarcarla la guardaba como **si**. El operador declaraba
                // «no acepta presencial» y la tabla de la misma pantalla lo
                // mostraba como que si.
                'accepts_travel' => $marcadas['accepts_travel'],
                'accepts_in_person' => $marcadas['accepts_in_person'],
                'accepts_product_only' => $marcadas['accepts_product_only'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Bitacora::registrar(
            accion: 'creator_availability.set',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: ['desde' => ['antes' => null, 'despues' => $desde]],
        );

        return redirect()->route('creadores.comercial', $uuid)->with('exito', 'Disponibilidad registrada.');
    }

    public function guardarBloqueo(GuardarBloqueoRequest $request, string $uuid): RedirectResponse
    {
        $creador = $this->porUuid($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        $id = (int) DB::table('creator_blackouts')->insertGetId($datos + [
            'creator_id' => $creador->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'creator_blackout.created',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'desde' => ['antes' => null, 'despues' => $datos['starts_on']],
                'hasta' => ['antes' => null, 'despues' => $datos['ends_on']],
                'motivo' => ['antes' => null, 'despues' => $datos['reason'] ?? null],
            ],
        );

        // DEC-070: el bloqueo se guarda igual. Lo que NO se hace es callarse el
        // choque: si el creador ya acepto una campana dentro de esas fechas,
        // alguien tiene que hablar con el hoy, no cuando no llegue el entregable.
        $choques = $this->choquesDeAgenda($creador->id, [$id]);

        if ($choques === []) {
            return redirect()->route('creadores.comercial', $uuid)->with('exito', 'Bloqueo registrado.');
        }

        $nombres = implode(', ', array_map(
            static fn (object $c): string => $c->codigo.' ('.$c->campana.')',
            $choques[$id] ?? [],
        ));

        return redirect()->route('creadores.comercial', $uuid)->with(
            'aviso',
            "Bloqueo registrado, pero pisa campañas que este creador YA aceptó: {$nombres}. "
            .'No se rechaza —si no puede, no puede— pero hay que hablar con él y con el cliente.',
        );
    }

    public function eliminarBloqueo(Request $request, string $uuid, int $id): RedirectResponse
    {
        $creador = $this->porUuid($uuid);

        $bloqueo = DB::table('creator_blackouts')
            ->where('id', $id)->where('creator_id', $creador->id)
            ->first(['id', 'starts_on', 'ends_on']);

        if ($bloqueo === null) {
            throw new NotFoundHttpException('Bloqueo no encontrado para este creador.');
        }

        // Un bloqueo de agenda NO es un registro financiero: `BR-FIN-008` no
        // aplica y borrarlo es lo correcto cuando se registro por error. Lo que
        // queda es el rastro en la bitacora.
        DB::table('creator_blackouts')->where('id', $bloqueo->id)->delete();

        Bitacora::registrar(
            accion: 'creator_blackout.deleted',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'desde' => ['antes' => $bloqueo->starts_on, 'despues' => null],
                'hasta' => ['antes' => $bloqueo->ends_on, 'despues' => null],
            ],
        );

        return redirect()->route('creadores.comercial', $uuid)->with('exito', 'Bloqueo eliminado.');
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Campañas ya aceptadas que caen dentro de cada bloqueo.
     *
     * `shortlisted` e `invited` no cuentan: todavía no hay compromiso. Desde
     * `accepted` en adelante sí, y también los estados de producción, porque un
     * creador que está entregando tampoco puede desaparecer.
     *
     * @param list<int> $bloqueos
     * @return array<int, list<object>>
     */
    private function choquesDeAgenda(int $creadorId, array $bloqueos): array
    {
        if ($bloqueos === []) {
            return [];
        }

        $filas = DB::table('creator_blackouts as b')
            ->join('campaign_creators as cc', 'cc.creator_id', '=', 'b.creator_id')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->where('b.creator_id', $creadorId)
            ->whereIn('b.id', $bloqueos)
            ->whereIn('cc.status', [
                'accepted', 'in_production', 'delivered', 'approved', 'published', 'verified',
            ])
            // Dos periodos se pisan si cada uno empieza antes de que el otro
            // termine. Las dos condiciones, no una.
            ->whereColumn('c.starts_on', '<=', 'b.ends_on')
            ->whereColumn('c.ends_on', '>=', 'b.starts_on')
            ->get(['b.id as bloqueo_id', 'c.code as codigo', 'c.name as campana', 'cc.status']);

        $porBloqueo = [];

        foreach ($filas as $fila) {
            $porBloqueo[(int) $fila->bloqueo_id][] = $fila;
        }

        return $porBloqueo;
    }

    private function porUuid(string $uuid): object
    {
        $creador = DB::table('creators')->where('uuid', $uuid)->first();

        if ($creador === null) {
            throw new NotFoundHttpException('Creador no encontrado.');
        }

        return $creador;
    }
}
