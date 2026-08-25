<?php

declare(strict_types=1);

namespace App\Modules\Client\Http\Controllers;

use App\Modules\Client\Http\Requests\GuardarClienteRequest;
use App\Modules\Client\Services\CoberturaFacturacion;
use App\Modules\Client\Services\Contactos;
use App\Modules\Client\Services\Marcas;
use App\Shared\Audit\Bitacora;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Clientes: la otra mitad del negocio (iteración 4.1, hoja de ruta `7.0`).
 *
 * Hasta ahora el sistema sabía todo del creador y nada de a quién se le
 * factura. `client_organizations` existía desde la fase 2 con sus reglas
 * puestas, pero **no había ni una ruta**: `client.manage` estaba declarado y no
 * lo tenía ningún rol, así que nadie podía crear un cliente.
 *
 * ### La regla que manda aquí: `BR-LE-003` / `BR-LE-004`
 *
 * Un cliente se factura desde una sociedad del grupo, y una sociedad solo puede
 * facturar a los países que tiene declarados en su **cobertura**. Si ninguna
 * cubre el país del cliente, `BR-LE-004` es tajante: se bloquea con un mensaje
 * accionable, y **nunca** se asigna una por defecto ni se sigue en silencio.
 *
 * Dónde bloquear era la decisión (`DEC-073`), y no es obvia:
 *
 * - **Un `prospect` se puede dar de alta en cualquier país.** Un cliente
 *   potencial en un país que todavía no cubrimos es una oportunidad comercial
 *   legítima, y prohibir apuntarla obligaría a llevarla en una hoja aparte —
 *   que es justo lo que este sistema viene a eliminar.
 * - **Pasar a `active` exige cobertura.** `active` significa «se le puede
 *   facturar», y sin sociedad que cubra su país eso es falso.
 *
 * El propio esquema apuntaba a esta respuesta: `status` nace en `prospect` por
 * defecto, no en `active`.
 *
 * La pantalla enseña la sociedad que facturaría **siempre**, también mientras es
 * prospecto, para que la falta de cobertura se vea el día que se apunta el
 * cliente y no el día que hay que cobrarle.
 */
final class ClientesController
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $consulta = DB::table('client_organizations as co')
            ->join('countries as p', 'p.id', '=', 'co.country_id')
            ->leftJoin('users as u', 'u.id', '=', 'co.owner_user_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'co.industry_category_id')
            ->select([
                'co.uuid', 'co.client_code', 'co.commercial_name', 'co.status',
                'co.country_id', 'p.name as pais', 'u.name as ejecutivo', 'cat.code as industria',
                DB::raw('(SELECT COUNT(*) FROM client_brands cb WHERE cb.client_organization_id = co.id) as marcas'),
            ])
            ->orderBy('co.commercial_name');

        if ($q !== '') {
            // Parámetros ligados, nunca concatenación (docs/08).
            $consulta->where(function ($w) use ($q): void {
                $like = '%'.$q.'%';
                $w->where('co.commercial_name', 'like', $like)
                    ->orWhere('co.client_code', 'like', $like);
            });
        }

        $clientes = $consulta->paginate(25)->withQueryString();

        // La cobertura se resuelve una vez por PAÍS, no una por cliente: la
        // lista de 25 clientes de Perú no necesita 25 consultas idénticas.
        $porPais = [];
        foreach ($clientes as $cliente) {
            $porPais[(int) $cliente->country_id] ??= CoberturaFacturacion::resolver((int) $cliente->country_id);
            $cliente->cobertura = $porPais[(int) $cliente->country_id];
        }

        return view('clientes.index', [
            'clientes' => $clientes,
            'q' => $q,
            'sinCobertura' => count(array_filter($porPais, static fn (CoberturaFacturacion $c): bool => !$c->hay())),
        ]);
    }

    public function show(string $uuid): View
    {
        $cliente = $this->porUuid($uuid);

        return view('clientes.show', [
            'cliente' => $cliente,
            'cobertura' => CoberturaFacturacion::resolver((int) $cliente->country_id),
            'marcas' => DB::table('client_brands as cb')
                ->where('cb.client_organization_id', $cliente->id)
                ->orderBy('cb.name')
                ->get(['cb.uuid', 'cb.name', 'cb.slug', 'cb.status', 'cb.website',
                    DB::raw('(SELECT COUNT(*) FROM client_brand_categories x WHERE x.client_brand_id = cb.id) as categorias')])
                ->map(function (object $m): object {
                    $m->categorias = (int) $m->categorias;

                    return $m;
                }),
            'fiscales' => DB::table('client_tax_profiles as ctp')
                ->join('countries as p', 'p.id', '=', 'ctp.country_id')
                ->where('ctp.client_organization_id', $cliente->id)
                ->orderByDesc('ctp.valid_from')
                ->get(['ctp.id', 'ctp.tax_id_type', 'ctp.tax_id_number', 'ctp.legal_name',
                    'ctp.city', 'ctp.payment_term_days',
                    'ctp.valid_from', 'ctp.valid_to', 'p.name as pais']),
            // Iteración 4.3. El orden pone arriba a los activos y, dentro de
            // cada tipo, al principal primero: es el que se busca.
            'contactos' => DB::table('contacts')
                ->where('client_organization_id', $cliente->id)
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('contact_type')
                ->orderByDesc('is_primary')
                ->orderBy('full_name')
                ->get(['uuid', 'full_name', 'contact_email', 'phone', 'position',
                    'contact_type', 'is_primary', 'status']),
            'tiposContacto' => Contactos::TIPOS,
            'tiposSinPrincipal' => Contactos::tiposSinPrincipal((int) $cliente->id),
        ]);
    }

    public function create(): View
    {
        return view('clientes.form', $this->opciones() + ['cliente' => null]);
    }

    public function edit(string $uuid): View
    {
        return view('clientes.form', $this->opciones() + ['cliente' => $this->porUuid($uuid)]);
    }

    public function store(GuardarClienteRequest $request): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        if (($aviso = $this->vetoPorCobertura($datos)) !== null) {
            return back()->withInput()->with('aviso', $aviso);
        }

        $uuid = (string) Str::uuid();
        $clienteId = 0;

        DB::transaction(function () use ($datos, $uuid, &$clienteId): void {
            $clienteId = (int) DB::table('client_organizations')->insertGetId($datos + [
                'uuid' => $uuid,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Su primera marca, con su mismo nombre (`DEC-074`).
            //
            // El modelo distingue cliente de marca por buenas razones —ver
            // `Marcas`—, pero para un cliente de una sola marca pedir dos altas
            // es papeleo puro, y esos van a ser la mayoria al principio.
            //
            // Se crea aqui, editable y visible, y el mensaje de exito la
            // menciona: el caso simple cuesta UN formulario, el complejo sigue
            // siendo posible, y nadie tiene que entender la diferencia hasta
            // que le hace falta.
            Marcas::crear($clienteId, (string) $datos['commercial_name']);
        });

        Bitacora::registrar(
            accion: 'client.created',
            tipoEntidad: 'client_organization',
            idEntidad: $clienteId,
            cambios: ['cliente' => ['antes' => null, 'despues' => $datos['commercial_name']]],
        );

        return redirect()->route('clientes.show', $uuid)->with(
            'exito',
            "Cliente dado de alta, con su primera marca «{$datos['commercial_name']}». "
            .'Si vende varias marcas, anadalas desde su ficha: una campana se hace para una marca.',
        );
    }

    public function update(GuardarClienteRequest $request, string $uuid): RedirectResponse
    {
        $cliente = $this->porUuid($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        if (($aviso = $this->vetoPorCobertura($datos)) !== null) {
            return back()->withInput()->with('aviso', $aviso);
        }

        $cambios = [];
        foreach ($datos as $campo => $valor) {
            if ((string) ($cliente->{$campo} ?? '') !== (string) ($valor ?? '')) {
                $cambios[$campo] = ['antes' => $cliente->{$campo} ?? null, 'despues' => $valor];
            }
        }

        if ($cambios === []) {
            return redirect()->route('clientes.show', $uuid)->with('aviso', 'No cambio nada.');
        }

        DB::table('client_organizations')->where('id', $cliente->id)
            ->update($datos + ['updated_at' => now()]);

        Bitacora::registrar(
            accion: 'client.updated',
            tipoEntidad: 'client_organization',
            idEntidad: (int) $cliente->id,
            cambios: $cambios,
        );

        return redirect()->route('clientes.show', $uuid)->with('exito', 'Cliente actualizado.');
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * `BR-LE-004` en una frase: activo sin quien le facture, no.
     *
     * Devuelve el aviso, o `null` si se puede guardar. No lanza excepción a
     * propósito: la regla dice «mensaje accionable», y un 500 no lo es.
     *
     * @param array<string, mixed> $datos
     */
    private function vetoPorCobertura(array $datos): ?string
    {
        if ($datos['status'] !== 'active') {
            return null;
        }

        $cobertura = CoberturaFacturacion::resolver((int) $datos['country_id']);

        if ($cobertura->hay()) {
            return null;
        }

        return 'No puedo marcarlo como ACTIVO: '.$cobertura->explicacion
            .' Mientras tanto se puede dejar como PROSPECTO, que es exactamente lo que es.';
    }

    private function porUuid(string $uuid): object
    {
        $cliente = DB::table('client_organizations as co')
            ->join('countries as p', 'p.id', '=', 'co.country_id')
            ->leftJoin('users as u', 'u.id', '=', 'co.owner_user_id')
            ->where('co.uuid', $uuid)
            ->first(['co.*', 'p.name as pais', 'u.name as ejecutivo']);

        if ($cliente === null) {
            throw new NotFoundHttpException('Cliente no encontrado.');
        }

        return $cliente;
    }

    /**
     * @return array<string, mixed>
     */
    private function opciones(): array
    {
        return [
            'paises' => DB::table('countries')->where('is_active', 1)->orderBy('name')->get(['id', 'name']),
            'industrias' => DB::table('categories')->orderBy('code')->get(['id', 'code']),
            'ejecutivos' => DB::table('users')->orderBy('name')->get(['id', 'name']),
        ];
    }
}
