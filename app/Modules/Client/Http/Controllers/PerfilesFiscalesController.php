<?php

declare(strict_types=1);

namespace App\Modules\Client\Http\Controllers;

use App\Modules\Client\Http\Requests\GuardarPerfilFiscalRequest;
use App\Modules\Client\Services\CoberturaFacturacion;
use App\Modules\Client\Services\PerfilesFiscales;
use App\Shared\Audit\Bitacora;
use App\Shared\Database\Vigencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La identidad fiscal del cliente, por país y con vigencia (iteración 4.4).
 *
 * Cierra el bloque 7.0 de la hoja de ruta. Hasta ahora la ficha del cliente
 * **mostraba** los perfiles fiscales y no había forma de crear ninguno: el dato
 * del que salen la razón social y el RUC de la factura sólo se podía meter por
 * SQL.
 *
 * ### Dos actos distintos, y sólo uno reescribe algo
 *
 * - **Abrir periodo** (`create`/`store`): el cliente cambia de identidad fiscal
 *   —se muda, cambia de razón social, se reorganiza—. El anterior se cierra
 *   *el día antes* y el nuevo empieza. El histórico se conserva entero.
 * - **Corregir el vigente** (`edit`/`update`): había un error de captura. Se
 *   edita en sitio, y sólo el vigente.
 *
 * **Los periodos cerrados están congelados** (`DEC-078`). Un periodo cerrado es
 * el registro de quién era el cliente entre esas fechas, y es de donde se
 * explica una factura pasada. `client_tax_profiles` ya no admite `DELETE`; que
 * admitiera reescritura silenciosa sería la misma pérdida por otra puerta.
 *
 * ### La factura no depende de que esto no cambie
 *
 * `invoices` guarda `receiver_legal_name_snapshot`, `receiver_tax_id_snapshot` y
 * `receiver_address_snapshot`. Una corrección de hoy **no reescribe una factura
 * de ayer**. Eso es lo que hace que corregir el vigente sea seguro, y no una
 * excepción incómoda.
 */
final class PerfilesFiscalesController
{
    public function create(string $uuid): View
    {
        $cliente = $this->cliente($uuid);

        return view('clientes.fiscal.form', [
            'cliente' => $cliente,
            'perfil' => null,
            'paises' => $this->paises(),
            'vigentes' => $this->vigentesPorPais((int) $cliente->id),
        ]);
    }

    public function edit(string $uuid, string $perfil): View
    {
        $cliente = $this->cliente($uuid);

        return view('clientes.fiscal.form', [
            'cliente' => $cliente,
            'perfil' => $this->perfilVigente($cliente, $perfil),
            'paises' => $this->paises(),
            'vigentes' => [],
        ]);
    }

    public function store(GuardarPerfilFiscalRequest $request, string $uuid): RedirectResponse
    {
        $cliente = $this->cliente($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();
        $paisId = (int) $datos['country_id'];

        $vigente = PerfilesFiscales::vigente((int) $cliente->id, $paisId);

        if (($aviso = $this->vetoPorFechaDeInicio($vigente, (string) $datos['valid_from'])) !== null) {
            return back()->withInput()->with('aviso', $aviso);
        }

        if (($aviso = $this->vetoPorDocumentoAjeno($cliente, $paisId, $datos)) !== null) {
            return back()->withInput()->with('aviso', $aviso);
        }

        DB::transaction(function () use ($cliente, $paisId, $datos, $vigente): void {
            // Cerrar el anterior y abrir el nuevo van juntos o no van. Cerrar y
            // no abrir deja al cliente sin identidad fiscal, que es peor que
            // dejarlo como estaba.
            PerfilesFiscales::abrirPeriodo((int) $cliente->id, $paisId, $datos, $vigente);
        });

        Bitacora::registrar(
            accion: 'client_tax_profile.created',
            tipoEntidad: 'client_organization',
            idEntidad: (int) $cliente->id,
            cambios: [
                'identidad_fiscal' => [
                    'antes' => $vigente === null ? null : $vigente->tax_id_type.' '.$vigente->tax_id_number,
                    'despues' => $datos['tax_id_type'].' '.$datos['tax_id_number'],
                ],
            ],
        );

        $respuesta = redirect()->route('clientes.show', $uuid)
            ->with('exito', $this->mensajeDeAlta($vigente, $datos));

        // Sólo se marca la sesión si hay algo que avisar. Dejar un `aviso` a
        // `null` en la sesión hace que `session()->has('aviso')` sea cierto y
        // la ficha pinte una caja de aviso vacía.
        $aviso = $this->avisoDeCobertura($paisId, (string) $datos['valid_from']);

        return $aviso === null ? $respuesta : $respuesta->with('aviso', $aviso);
    }

    public function update(GuardarPerfilFiscalRequest $request, string $uuid, string $perfil): RedirectResponse
    {
        $cliente = $this->cliente($uuid);
        $fila = $this->perfilVigente($cliente, $perfil);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        $cambios = [];
        foreach (['legal_name', 'tax_id_type', 'tax_id_number', 'address_line1', 'address_line2',
            'city', 'region', 'postal_code', 'billing_email', 'payment_term_days'] as $campo) {
            if ((string) ($fila->{$campo} ?? '') !== (string) ($datos[$campo] ?? '')) {
                $cambios[$campo] = ['antes' => $fila->{$campo}, 'despues' => $datos[$campo] ?? null];
            }
        }

        if ($cambios === []) {
            return redirect()->route('clientes.show', $uuid)->with('aviso', 'No cambio nada.');
        }

        if (($aviso = $this->vetoPorDocumentoAjeno($cliente, (int) $fila->country_id, $datos)) !== null) {
            return back()->withInput()->with('aviso', $aviso);
        }

        DB::table('client_tax_profiles')->where('id', $fila->id)->update([
            'legal_name' => $datos['legal_name'],
            'tax_id_type' => $datos['tax_id_type'],
            'tax_id_number' => $datos['tax_id_number'],
            'address_line1' => $datos['address_line1'],
            'address_line2' => $datos['address_line2'] ?? null,
            'city' => $datos['city'],
            'region' => $datos['region'] ?? null,
            'postal_code' => $datos['postal_code'] ?? null,
            'billing_email' => $datos['billing_email'] ?? null,
            'payment_term_days' => $datos['payment_term_days'],
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'client_tax_profile.updated',
            tipoEntidad: 'client_organization',
            idEntidad: (int) $cliente->id,
            cambios: $cambios,
        );

        return redirect()->route('clientes.show', $uuid)
            ->with('exito', 'Identidad fiscal corregida.');
    }

    // ------------------------------------------------------------------ vetos

    /**
     * El periodo nuevo tiene que empezar DESPUÉS que el vigente (`DEC-071`).
     *
     * Si empezara el mismo día o antes, cerrar el anterior «el día antes» le
     * pondría un `valid_to` anterior a su propio `valid_from`, que es lo que
     * prohíbe `ck_ctxp_dates`. Y no se arregla recortando la fecha: lo que ese
     * caso significa de verdad es que el periodo vigente **no estuvo vigente
     * nunca**, y eso no es cerrarlo.
     *
     * Se contesta con palabras y no con un `45000`, que es lo que saldría si se
     * dejara llegar a la base.
     */
    private function vetoPorFechaDeInicio(?object $vigente, string $empieza): ?string
    {
        if ($vigente === null || Vigencia::puedeRelevar($empieza, (string) $vigente->valid_from)) {
            return null;
        }

        return sprintf(
            'Esta identidad fiscal empezaria el %s, y la vigente (%s %s) empezo el %s. '
            .'La nueva tiene que empezar despues: si no, habria dos razones sociales '
            .'aplicables el mismo dia y de ahi sale el nombre que va impreso en la factura. '
            .'Si lo que hay que corregir es la vigente, use «Corregir» en vez de abrir un periodo.',
            $empieza,
            $vigente->tax_id_type,
            $vigente->tax_id_number,
            $vigente->valid_from,
        );
    }

    /**
     * El mismo documento no puede ser la identidad vigente de dos clientes en
     * el mismo país (`uq_ctxp_taxid`).
     *
     * La base contesta `Duplicate entry '1-1-RUC-20123456789'`, que no le dice
     * nada a nadie. Lo que ese choque significa casi siempre es que **la misma
     * empresa está dada de alta dos veces**, así que el mensaje nombra al otro
     * cliente: se arregla mirándolo, no cambiando el número.
     *
     * @param array<string, mixed> $datos
     */
    private function vetoPorDocumentoAjeno(object $cliente, int $paisId, array $datos): ?string
    {
        $otro = PerfilesFiscales::chocaConOtroCliente(
            $paisId,
            (string) $datos['tax_id_type'],
            (string) $datos['tax_id_number'],
            (int) $cliente->id,
        );

        if ($otro === null) {
            return null;
        }

        return sprintf(
            'El %s %s ya es la identidad fiscal vigente de «%s» (%s) en ese pais. '
            .'Lo mas probable es que sea la misma empresa dada de alta dos veces: '
            .'revisela antes de seguir. Si de verdad son dos, el anterior tiene que '
            .'cerrar su periodo primero.',
            $datos['tax_id_type'],
            $datos['tax_id_number'],
            $otro->commercial_name,
            $otro->client_code,
        );
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * @param array<string, mixed> $datos
     */
    private function mensajeDeAlta(?object $vigente, array $datos): string
    {
        if ($vigente === null) {
            return "Identidad fiscal registrada: {$datos['tax_id_type']} {$datos['tax_id_number']}.";
        }

        // Se dice la fecha de cierre, no sólo que se cerró. Es la que decide
        // con qué RUC se factura el día del relevo, y es el sitio donde este
        // proyecto se ha equivocado seis veces.
        return sprintf(
            'Identidad fiscal registrada: %s %s. La anterior (%s %s) queda cerrada el %s, '
            .'el dia antes de que empiece la nueva.',
            $datos['tax_id_type'],
            $datos['tax_id_number'],
            $vigente->tax_id_type,
            $vigente->tax_id_number,
            PerfilesFiscales::cerrarElDiaAntes((string) $datos['valid_from']),
        );
    }

    /**
     * Si el país no tiene sociedad que lo cubra, se **avisa** y no se bloquea.
     *
     * `BR-LE-003` se resuelve en la fecha de la operación, y una factura es una
     * operación futura: registrar hoy la identidad de un cliente cuyo país se
     * cubrirá el mes que viene es legítimo. Bloquear aquí impediría preparar el
     * alta. Lo que no puede pasar es enterarse el día de facturar.
     */
    private function avisoDeCobertura(int $paisId, string $fecha): ?string
    {
        $cobertura = CoberturaFacturacion::resolver($paisId, $fecha);

        if ($cobertura->hay()) {
            return null;
        }

        return 'Ojo: '.$cobertura->explicacion
            .' La identidad fiscal queda registrada, pero mientras eso siga asi no se le '
            .'podra emitir una factura en ese pais.';
    }

    private function cliente(string $uuid): object
    {
        $cliente = DB::table('client_organizations')->where('uuid', $uuid)
            ->first(['id', 'uuid', 'commercial_name', 'country_id']);

        if ($cliente === null) {
            throw new NotFoundHttpException('Cliente no encontrado.');
        }

        return $cliente;
    }

    /**
     * El perfil, exigiendo que sea de este cliente **y que esté vigente**.
     *
     * Las dos comprobaciones importan por razones distintas: la primera impide
     * que la URL de un cliente sirva para editar el perfil de otro; la segunda
     * es `DEC-078`, que congela los periodos cerrados. Un cerrado devuelve 404 y
     * no un formulario deshabilitado, porque la ruta no existe para él.
     */
    private function perfilVigente(object $cliente, string $id): object
    {
        $perfil = DB::table('client_tax_profiles')
            ->where('id', $id)
            ->where('client_organization_id', $cliente->id)
            ->whereNull('valid_to')
            ->first();

        if ($perfil === null) {
            throw new NotFoundHttpException(
                'No hay un perfil fiscal vigente con ese id en este cliente. '
                .'Los periodos cerrados no se editan (DEC-078).',
            );
        }

        return $perfil;
    }

    /** @return Collection<int, \stdClass> */
    private function paises(): Collection
    {
        return DB::table('countries')->orderBy('name')->get(['id', 'name', 'iso2']);
    }

    /**
     * Qué países ya tienen identidad vigente, para que el formulario avise de
     * que elegir uno de ellos abre un periodo nuevo y cierra el actual.
     *
     * @return array<int, object>
     */
    private function vigentesPorPais(int $clienteId): array
    {
        $filas = DB::table('client_tax_profiles')
            ->where('client_organization_id', $clienteId)
            ->whereNull('valid_to')
            ->get(['country_id', 'tax_id_type', 'tax_id_number', 'valid_from']);

        $mapa = [];
        foreach ($filas as $fila) {
            $mapa[(int) $fila->country_id] = $fila;
        }

        return $mapa;
    }
}
