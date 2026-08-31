<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Http\Requests\GuardarEntidadLegalRequest;
use App\Modules\Core\Services\Cobertura;
use App\Modules\Core\Services\Marca;
use App\Shared\Audit\Bitacora;
use App\Shared\Database\Vigencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Las sociedades del grupo y su cobertura de facturación (iteración 4.5).
 *
 * ### Esta pantalla lleva dos iteraciones existiendo sólo en los mensajes
 *
 * `BR-LE-004` obliga a que, cuando ninguna sociedad cubra el país de un cliente,
 * la operación se bloquee **con un mensaje accionable**. El mensaje que escribió
 * `CoberturaFacturacion` en 4.1 dice: *«Hay que dar de alta la cobertura en
 * Entidades legales»*. En 4.4 el aviso de los perfiles fiscales del cliente
 * empezó a decir lo mismo.
 *
 * Ese sitio **no existía**: la única forma de declarar cobertura era el seeder o
 * SQL a mano (`Q-51`). Un mensaje accionable que manda a una pantalla inexistente
 * es sólo la mitad de accionable. Esta iteración la construye.
 *
 * ### El bloqueo que se encontró al construirla
 *
 * `uq_lec_country` admite **una sola cobertura abierta por país**, mire o no el
 * estado de la sociedad. Pero resolver quién factura sólo cuenta las sociedades
 * `active`. Desactivar la sociedad que cubre un país sin cerrar su cobertura
 * deja ese país **sin cubrir y sin poder cubrirse**: la fila abierta de la
 * inactiva sigue ocupando el sitio y ninguna otra puede entrar.
 *
 * Comprobado contra el motor. De ahí `DEC-081`: dar de baja cierra las
 * coberturas abiertas en la misma transacción, y se dice qué países quedan
 * descubiertos y desde cuándo.
 */
final class EntidadesLegalesController
{
    public function index(): View
    {
        $hoy = now()->toDateString();

        $entidades = DB::table('legal_entities as le')
            ->join('countries as c', 'c.id', '=', 'le.country_id')
            ->orderBy('le.status')
            ->orderBy('le.code')
            ->get(['le.uuid', 'le.code', 'le.legal_name', 'le.status',
                'le.default_currency_code', 'c.name as pais']);

        return view('entidades.index', [
            'entidades' => $entidades,
            // Los países que hoy no puede facturar nadie. Es la pregunta por la
            // que se entra a esta pantalla, así que se contesta en el listado y
            // no escondida en una ficha.
            'descubiertos' => Cobertura::paisesDescubiertos($hoy),
            'hoy' => $hoy,
        ]);
    }

    public function show(string $uuid): View
    {
        $entidad = $this->entidad($uuid);

        return view('entidades.show', [
            'entidad' => $entidad,
            'coberturas' => DB::table('legal_entity_countries as lec')
                ->join('countries as c', 'c.id', '=', 'lec.country_id')
                ->where('lec.legal_entity_id', $entidad->id)
                ->orderByDesc('lec.valid_from')
                ->get(['lec.id', 'lec.coverage_basis', 'lec.valid_from', 'lec.valid_to',
                    'c.name as pais', 'c.iso2']),
            'paises' => DB::table('countries')->orderBy('name')->get(['id', 'name', 'iso2']),
            // Quién ocupa hoy el sitio de cada país, para que el formulario
            // pueda avisar de a quién relevaría antes de que se pulse.
            'ocupados' => $this->ocupadosPorPais(),
            'motivos' => self::MOTIVOS,
            'hoy' => now()->toDateString(),
        ]);
    }

    public function create(): View
    {
        return view('entidades.form', $this->opciones() + ['entidad' => null]);
    }

    public function edit(string $uuid): View
    {
        return view('entidades.form', $this->opciones() + ['entidad' => $this->entidad($uuid)]);
    }

    public function store(GuardarEntidadLegalRequest $request): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();
        $uuid = (string) Str::uuid();

        DB::table('legal_entities')->insert([
            'uuid' => $uuid,
            // 9.17: la marca POR DEFECTO, no «la del id mas bajo». Con una sola
            // marca daban lo mismo; con dos, el `orderBy('id')` habria colgado
            // la sociedad nueva de la marca vieja sin que nadie lo pidiera, y
            // eso sale en el emisor de una factura. Cuando haya dos de verdad,
            // esto es un campo del formulario.
            'platform_brand_id' => (int) (Marca::actual()->id ?? 0),
            'code' => $datos['code'],
            'legal_name' => $datos['legal_name'],
            'trade_name' => $datos['trade_name'] ?? null,
            'country_id' => (int) $datos['country_id'],
            'tax_id_type' => $datos['tax_id_type'],
            'tax_id_number' => $datos['tax_id_number'],
            'address_line1' => $datos['address_line1'],
            'address_line2' => $datos['address_line2'] ?? null,
            'city' => $datos['city'],
            'region' => $datos['region'] ?? null,
            'postal_code' => $datos['postal_code'] ?? null,
            'default_currency_code' => $datos['default_currency_code'],
            'timezone' => $datos['timezone'],
            'legal_representative' => $datos['legal_representative'] ?? null,
            'incorporated_on' => $datos['incorporated_on'] ?? null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'legal_entity.created',
            tipoEntidad: 'legal_entity',
            idEntidad: (int) DB::table('legal_entities')->where('uuid', $uuid)->value('id'),
            cambios: ['code' => ['antes' => null, 'despues' => $datos['code']]],
        );

        return redirect()->route('entidades.show', $uuid)->with(
            'exito',
            "Sociedad {$datos['code']} dada de alta. Todavia no cubre ningun pais: "
            .'mientras no se le declare cobertura no se le puede facturar a nadie desde ella.',
        );
    }

    public function update(GuardarEntidadLegalRequest $request, string $uuid): RedirectResponse
    {
        $entidad = $this->entidad($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        $cambios = [];
        foreach (array_keys($datos) as $campo) {
            if ((string) ($entidad->{$campo} ?? '') !== (string) ($datos[$campo] ?? '')) {
                $cambios[$campo] = ['antes' => $entidad->{$campo}, 'despues' => $datos[$campo] ?? null];
            }
        }

        if ($cambios === []) {
            return redirect()->route('entidades.show', $uuid)->with('aviso', 'No cambio nada.');
        }

        DB::table('legal_entities')->where('id', $entidad->id)->update($datos + ['updated_at' => now()]);

        Bitacora::registrar(
            accion: 'legal_entity.updated',
            tipoEntidad: 'legal_entity',
            idEntidad: (int) $entidad->id,
            cambios: $cambios,
        );

        return redirect()->route('entidades.show', $uuid)->with('exito', 'Sociedad actualizada.');
    }

    /**
     * Declara que esta sociedad cubre un país desde una fecha.
     *
     * Si otra lo cubría, se la releva cerrándola **el día antes** (`valid_to` es
     * inclusivo). El orden —cerrar y luego abrir— lo impone `uq_lec_country`.
     */
    public function abrirCobertura(Request $peticion, string $uuid): RedirectResponse
    {
        $entidad = $this->entidad($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'coverage_basis' => ['required', 'string', 'in:'.implode(',', array_keys(self::MOTIVOS))],
            'valid_from' => ['required', 'date_format:Y-m-d'],
        ]);

        $paisId = (int) $datos['country_id'];
        $desde = (string) $datos['valid_from'];
        $ocupada = Cobertura::abiertaEnPais($paisId);

        if (($aviso = $this->vetoPorEstado($entidad)) !== null) {
            return back()->withInput()->with('aviso', $aviso);
        }

        if ($ocupada !== null && !Vigencia::puedeRelevar($desde, (string) $ocupada->valid_from)) {
            // Cerrar «el día antes» le pondría a la anterior un `valid_to`
            // previo a su propio `valid_from`: eso es `ck_lec_dates`, un 45000.
            return back()->withInput()->with('aviso', sprintf(
                'Esta cobertura empezaria el %s y la de %s empezo el %s. La nueva tiene que '
                .'empezar despues: si no, habria dos sociedades facturando el mismo pais el '
                .'mismo dia, y de ahi sale quien emite la factura.',
                $desde, $ocupada->code, $ocupada->valid_from,
            ));
        }

        // Se pasa la fila que ya se leyo, en vez de dejar que `abrir()` la vuelva
        // a leer: con dos lecturas, el veto de arriba y el cierre de abajo
        // pueden estar mirando filas distintas, y entonces el mensaje y la
        // bitacora nombran a una sociedad y se releva a otra.
        DB::transaction(function () use ($entidad, $paisId, $datos, $desde, $ocupada): void {
            Cobertura::abrir((int) $entidad->id, $paisId, (string) $datos['coverage_basis'], $desde, $ocupada);
        });

        $pais = (string) DB::table('countries')->where('id', $paisId)->value('name');

        Bitacora::registrar(
            accion: 'legal_entity.coverage_opened',
            tipoEntidad: 'legal_entity',
            idEntidad: (int) $entidad->id,
            cambios: ['cobertura' => ['antes' => $ocupada?->code, 'despues' => $entidad->code.' · '.$pais]],
        );

        $mensaje = "{$entidad->code} factura a {$pais} desde el {$desde}.";

        // Si la que ocupaba el sitio es ESTA MISMA sociedad —redeclarar la
        // cobertura de un pais que ya cubre, con fecha posterior— no se releva a
        // nadie. Sin esta comprobacion el mensaje decia «E45-A factura a Peru
        // desde el 01/06. E45-A deja de cubrirlo el 31/05»: se anunciaba a si
        // misma como relevada, que no significa nada.
        if ($ocupada !== null && (int) $ocupada->legal_entity_id === (int) $entidad->id) {
            $mensaje .= ' Su cobertura anterior de ese pais queda cerrada el '
                .Vigencia::cerrarElDiaAntesDe($desde).'.';
        } elseif ($ocupada !== null) {
            $mensaje .= sprintf(
                ' %s deja de cubrirlo el %s, el dia antes.',
                $ocupada->code,
                Vigencia::cerrarElDiaAntesDe($desde),
            );
        }

        return redirect()->route('entidades.show', $uuid)->with('exito', $mensaje);
    }

    /**
     * Da de baja una sociedad, cerrando sus coberturas abiertas (`DEC-081`).
     *
     * Sin esto, los países que cubría quedan **incomunicados**: ninguna sociedad
     * activa los cubre, y ninguna otra puede empezar a cubrirlos porque la fila
     * abierta de esta sigue ocupando el sitio en `uq_lec_country`.
     */
    public function desactivar(Request $peticion, string $uuid): RedirectResponse
    {
        $entidad = $this->entidad($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'hasta' => ['required', 'date_format:Y-m-d'],
            'estado' => ['required', 'in:inactive,dissolved'],
        ]);

        $hasta = (string) $datos['hasta'];
        $estado = (string) $datos['estado'];

        if ($entidad->status !== 'active') {
            return back()->with('aviso', "{$entidad->code} ya estaba dada de baja ({$entidad->status}).");
        }

        // Una cobertura que empieza DESPUÉS de la baja no se puede cerrar en esa
        // fecha (`ck_lec_dates`), y tampoco se puede borrar: es evidencia. Se
        // dice y no se toca nada.
        // `ck_le_dates` exige `dissolved_on >= incorporated_on`. Todos los demas
        // vetos de esta pantalla existen para que la base no conteste con un
        // `45000`; a este le faltaba el suyo.
        if ($estado === 'dissolved'
            && $entidad->incorporated_on !== null
            && Vigencia::fecha($hasta) < Vigencia::fecha((string) $entidad->incorporated_on)) {
            return back()->with('aviso', sprintf(
                'No se puede disolver %s el %s: se constituyo el %s, y una sociedad no puede '
                .'dejar de existir antes de existir. Revise la fecha.',
                $entidad->code, $hasta, $entidad->incorporated_on,
            ));
        }

        $noCerrables = Cobertura::noCerrablesEn((int) $entidad->id, $hasta);

        if ($noCerrables->isNotEmpty()) {
            return back()->with('aviso', sprintf(
                'No se puede dar de baja el %s: %s tiene cobertura de %s que empieza DESPUES, el %s. '
                .'Una cobertura no puede terminar antes de empezar, y borrarla no es posible porque '
                .'es la prueba de quien podia facturar que. Cambie esa fecha o dé de baja mas tarde.',
                $hasta,
                $entidad->code,
                $noCerrables->pluck('pais')->implode(', '),
                $noCerrables->first()->valid_from,
            ));
        }

        $cerradas = collect();

        DB::transaction(function () use ($entidad, $hasta, $estado, &$cerradas): void {
            $cerradas = Cobertura::cerrarTodasDe((int) $entidad->id, $hasta);

            DB::table('legal_entities')->where('id', $entidad->id)->update([
                'status' => $estado,
                // `ck_le_dissolved` exige que una sociedad disuelta diga cuándo.
                'dissolved_on' => $estado === 'dissolved' ? $hasta : $entidad->dissolved_on,
                'updated_at' => now(),
            ]);
        });

        Bitacora::registrar(
            accion: 'legal_entity.deactivated',
            tipoEntidad: 'legal_entity',
            idEntidad: (int) $entidad->id,
            cambios: [
                'status' => ['antes' => $entidad->status, 'despues' => $estado],
                'coberturas_cerradas' => ['antes' => $cerradas->pluck('pais')->all(), 'despues' => []],
            ],
        );

        $mensaje = "{$entidad->code} queda como {$estado} el {$hasta}.";

        if ($cerradas->isNotEmpty()) {
            // Lo que falta por hacer se dice aquí y ahora. `BR-LE-004` prohíbe
            // continuar en silencio, y descubrir el día de facturar que nadie
            // cubre un país es exactamente el silencio que prohíbe.
            $mensaje .= sprintf(
                ' Se cerraron sus coberturas de %s: desde el %s no se le puede facturar a un cliente '
                .'de %s hasta que otra sociedad declare cobertura.',
                $cerradas->pluck('pais')->implode(', '),
                Vigencia::elDiaDespuesDe($hasta),
                $cerradas->count() === 1 ? 'ese pais' : 'esos paises',
            );
        }

        return redirect()->route('entidades.show', $uuid)->with('exito', $mensaje);
    }

    // ------------------------------------------------------------------ apoyo

    /** Los motivos de `ck_lec_basis`, con nombre legible. */
    public const MOTIVOS = [
        'local_entity' => 'Sociedad local en el pais',
        'service_export' => 'Exportacion de servicios',
        'branch' => 'Sucursal',
        'other' => 'Otro',
    ];

    private function vetoPorEstado(object $entidad): ?string
    {
        if ($entidad->status === 'active') {
            return null;
        }

        // Dejar que una sociedad inactiva abra cobertura fabrica justo el
        // bloqueo que esta iteración arregla: ocuparía el sitio del país sin
        // que `quienCubre()` la cuente.
        return sprintf(
            '%s esta %s, asi que no puede cubrir un pais: ocuparia el sitio sin poder facturar, '
            .'y ninguna otra sociedad podria tomarlo. Reactivela primero.',
            $entidad->code,
            $entidad->status,
        );
    }

    /** @return array<int, object> */
    private function ocupadosPorPais(): array
    {
        $filas = DB::table('legal_entity_countries as lec')
            ->join('legal_entities as le', 'le.id', '=', 'lec.legal_entity_id')
            ->whereNull('lec.valid_to')
            ->get(['lec.country_id', 'lec.valid_from', 'le.code', 'le.status']);

        $mapa = [];
        foreach ($filas as $fila) {
            $mapa[(int) $fila->country_id] = $fila;
        }

        return $mapa;
    }

    private function entidad(string $uuid): object
    {
        $entidad = DB::table('legal_entities')->where('uuid', $uuid)->first();

        if ($entidad === null) {
            throw new NotFoundHttpException('Sociedad no encontrada.');
        }

        return $entidad;
    }

    /** @return array<string, mixed> */
    private function opciones(): array
    {
        return [
            'paises' => DB::table('countries')->orderBy('name')->get(['id', 'name', 'iso2']),
            'monedas' => DB::table('currencies')->where('is_active', 1)->orderBy('code')->get(['code', 'name']),
        ];
    }
}
