<?php

declare(strict_types=1);

namespace App\Modules\Client\Http\Controllers;

use App\Modules\Client\Http\Requests\GuardarContactoRequest;
use App\Modules\Client\Services\Contactos;
use App\Shared\Audit\Bitacora;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Contactos de un cliente (iteración 4.3).
 *
 * Con quién se habla en la empresa cliente: quien negocia, a quién se le manda
 * la factura, quien firma. `uq_contacts_primary` garantiza **un principal activo
 * por cliente y tipo**; toda la lógica que hay aquí existe para que ese límite
 * se note como una frase y no como un `Duplicate entry`.
 *
 * El relevo (`DEC-075`) es automático y se anuncia: si al guardar este contacto
 * otro pierde el puesto, el mensaje de éxito dice su nombre. Un cambio de esa
 * importancia hecho en silencio es un cambio que nadie va a poder deshacer
 * porque nadie se enteró.
 */
final class ContactosController
{
    public function create(string $uuid): View
    {
        $cliente = $this->cliente($uuid);

        return view('contactos.form', [
            'cliente' => $cliente,
            'contacto' => null,
            'tipos' => Contactos::TIPOS,
            'principales' => $this->principalesPorTipo((int) $cliente->id),
        ]);
    }

    public function edit(string $uuid, string $contacto): View
    {
        $cliente = $this->cliente($uuid);
        $fila = $this->contacto($cliente, $contacto);

        return view('contactos.form', [
            'cliente' => $cliente,
            'contacto' => $fila,
            'tipos' => Contactos::TIPOS,
            'principales' => $this->principalesPorTipo((int) $cliente->id, (int) $fila->id),
        ]);
    }

    public function store(GuardarContactoRequest $request, string $uuid): RedirectResponse
    {
        $cliente = $this->cliente($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();
        $datos['is_primary'] = (bool) ($datos['is_primary'] ?? false);

        // Se mira ANTES de tocar nada: después del relevo ya no hay a quién
        // nombrar, porque el que ocupaba el puesto ha dejado de ocuparlo.
        $relevado = $this->relevado($cliente, $datos);

        DB::transaction(function () use ($cliente, $datos): void {
            Contactos::crear((int) $cliente->id, $datos);
        });

        Bitacora::registrar(
            accion: 'client_contact.created',
            tipoEntidad: 'client_organization',
            idEntidad: (int) $cliente->id,
            cambios: ['contacto' => ['antes' => null, 'despues' => $datos['full_name']]],
        );

        return redirect()->route('clientes.show', $uuid)
            ->with('exito', "Contacto «{$datos['full_name']}» dado de alta.".$this->coletillaRelevo($relevado));
    }

    public function update(GuardarContactoRequest $request, string $uuid, string $contacto): RedirectResponse
    {
        $cliente = $this->cliente($uuid);
        $fila = $this->contacto($cliente, $contacto);

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();
        $datos['is_primary'] = (bool) ($datos['is_primary'] ?? false);

        $cambios = [];
        foreach (['full_name', 'contact_email', 'phone', 'position', 'contact_type', 'status'] as $campo) {
            if ((string) ($fila->{$campo} ?? '') !== (string) ($datos[$campo] ?? '')) {
                $cambios[$campo] = ['antes' => $fila->{$campo}, 'despues' => $datos[$campo] ?? null];
            }
        }
        if ((bool) $fila->is_primary !== $datos['is_primary']) {
            $cambios['is_primary'] = ['antes' => (bool) $fila->is_primary, 'despues' => $datos['is_primary']];
        }

        if ($cambios === []) {
            return redirect()->route('clientes.show', $uuid)->with('aviso', 'No cambio nada.');
        }

        $relevado = $this->relevado($cliente, $datos, (int) $fila->id);

        DB::transaction(function () use ($fila, $datos): void {
            Contactos::actualizar($fila, $datos);
        });

        Bitacora::registrar(
            accion: 'client_contact.updated',
            tipoEntidad: 'client_organization',
            idEntidad: (int) $cliente->id,
            cambios: $cambios,
        );

        return redirect()->route('clientes.show', $uuid)
            ->with('exito', 'Contacto actualizado.'.$this->coletillaRelevo($relevado));
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * A quién va a relevar este guardado, si a alguien.
     *
     * @param array<string, mixed> $datos
     */
    private function relevado(object $cliente, array $datos, ?int $excepto = null): ?object
    {
        if ($datos['is_primary'] !== true || ($datos['status'] ?? 'active') !== 'active') {
            return null;
        }

        return Contactos::principal((int) $cliente->id, (string) $datos['contact_type'], $excepto);
    }

    private function coletillaRelevo(?object $relevado): string
    {
        if ($relevado === null) {
            return '';
        }

        return " {$relevado->full_name} deja de ser el contacto principal de ese tipo.";
    }

    /**
     * Quién es hoy el principal de cada tipo, para que el formulario pueda
     * decir a quién se relevaría antes de que se pulse el botón.
     *
     * @return array<string, object>
     */
    private function principalesPorTipo(int $clienteId, ?int $excepto = null): array
    {
        $mapa = [];

        foreach (array_keys(Contactos::TIPOS) as $tipo) {
            $principal = Contactos::principal($clienteId, $tipo, $excepto);

            if ($principal !== null) {
                $mapa[$tipo] = $principal;
            }
        }

        return $mapa;
    }

    private function cliente(string $uuid): object
    {
        $cliente = DB::table('client_organizations')->where('uuid', $uuid)
            ->first(['id', 'uuid', 'commercial_name']);

        if ($cliente === null) {
            throw new NotFoundHttpException('Cliente no encontrado.');
        }

        return $cliente;
    }

    private function contacto(object $cliente, string $uuid): object
    {
        $contacto = DB::table('contacts')
            ->where('uuid', $uuid)
            ->where('client_organization_id', $cliente->id)
            ->first();

        if ($contacto === null) {
            // Igual que en marcas: se exige que sea DE ESTE cliente, no solo
            // que exista. Si no, la URL de un cliente serviria para editar el
            // contacto de otro.
            throw new NotFoundHttpException('Contacto no encontrado en este cliente.');
        }

        return $contacto;
    }
}
