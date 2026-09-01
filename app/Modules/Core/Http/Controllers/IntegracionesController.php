<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Certificados;
use App\Modules\Core\Services\Correlativos;
use App\Modules\Core\Services\Integraciones;
use App\Shared\Config\Pestanas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Las credenciales de cada API, desde el admin (9.17d).
 *
 * ### El secreto entra y no vuelve a salir
 *
 * El formulario de credencial es de **escritura**: se teclea el valor nuevo y se
 * guarda. En pantalla sólo se ven los cuatro últimos, quién la puso y cuándo.
 * No hay ninguna acción que devuelva el valor, y eso no es una omisión: es lo
 * que hace que enseñar esta pantalla a alguien no sea entregarle las claves.
 *
 * ### `integration.manage` ya existía
 *
 * Desde `9.2`, para la credencial de la fuente de tipos de cambio. Es el mismo
 * trabajo —quién puede tocar las llaves de las APIs— así que se reutiliza en vez
 * de inventar el segundo permiso para lo mismo.
 */
final class IntegracionesController
{
    public const FEL = 'fel';

    public const FX = 'fx';

    public const CORREO = 'correo';

    /**
     * Las pestañas, por propósito.
     *
     * @var array<string, string>
     */
    public const PESTANAS = [
        self::FEL => 'Facturación electrónica',
        self::FX => 'Tipos de cambio',
        self::CORREO => 'Servidor de correo',
    ];

    /**
     * Las integraciones, por PESTAÑAS y no en un formulario para todo (9.17f).
     *
     * Reportado por el negocio: *«cada proveedor de integración tiene diferentes
     * parámetros, sobre todo si es para diferentes fines»*. Tenía razón — el
     * formulario único pedía lo mismo a un servidor de correo y a un emisor
     * electrónico, y no le servía bien a ninguno.
     *
     * La de facturación electrónica junta **las tres cosas que hacen falta para
     * emitir** y que hasta hoy estaban en tres pantallas distintas: la conexión,
     * el certificado (`9.9c`) y las series (`9.12`). No se duplican: las tres
     * salen de la misma plantilla parcial, y sus pantallas sueltas redirigen
     * aquí — dos puertas a lo mismo es lo que `9.20` vino a quitar.
     */
    /**
     * Las integraciones, por PESTAÑAS y no en un formulario para todo (9.17f).
     *
     * Reportado por el negocio: *«cada proveedor de integración tiene diferentes
     * parámetros, sobre todo si es para diferentes fines»*. Tenía razón — el
     * formulario único pedía lo mismo a un servidor de correo y a un emisor
     * electrónico, y no le servía bien a ninguno.
     *
     * **Qué pestañas hay no lo decide este controlador**: cada módulo registra
     * la suya en `Pestanas` (`9.17g`). La del correo la alimenta `Communication`,
     * y `Core` no puede depender de `Communication` —ni debe—. Es el mismo
     * patrón que `Preparacion` usa para las áreas del panel desde `9.17b`.
     */
    public function index(Request $peticion): View
    {
        $pestana = (string) $peticion->query('p', Pestanas::primera());

        if (!Pestanas::existe($pestana)) {
            $pestana = Pestanas::primera();
        }

        return view('integraciones.index', array_merge(
            [
                'pestanas' => Pestanas::rotulos(),
                'pestana' => $pestana,
                'pendientes' => Pestanas::pendientes(),
                'avisos' => Pestanas::avisosDe($pestana),
            ],
            Pestanas::datosDe($pestana),
        ));
    }

    /**
     * Lo que necesita la pestaña de facturación electrónica.
     *
     * @return array<string, mixed>
     */
    public static function datosDeFacturacion(): array
    {
        $series = Correlativos::series();
        $verId = (int) request()->query('serie', (string) ($series->first()->id ?? 0));
        $conexiones = Integraciones::conexiones()->where('purpose', 'invoicing')->values();
        $certificados = Certificados::todos();

        return [
            // La conexion.
            'extremos' => Integraciones::extremos(),
            'conexiones' => $conexiones,
            // 9.17i: cada tarjeta lleva SU estado y SUS avisos. Se calculan
            // aqui y no en la plantilla: una chapa que dice «activo» es una
            // afirmacion sobre el sistema, no una decision de maquetacion.
            'estadoConexion' => self::chapa($conexiones->contains(
                static fn (object $c): bool => $c->status === 'active',
            )),
            'estadoCertificado' => self::chapa($certificados->contains(
                static fn (object $c): bool => $c->status === 'active',
            )),
            'estadoSerie' => self::chapa($series->contains(
                static fn (object $s): bool => (int) $s->is_active === 1,
            )),
            'avisosConexion' => Integraciones::avisos(),
            'avisosCertificado' => Certificados::avisos(),
            'avisosSerie' => Correlativos::avisos(),
            'proveedores' => Integraciones::proveedores()->where('purpose', 'invoicing')->values(),
            'sociedades' => DB::table('legal_entities')->where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'legal_name', 'tax_id_number']),
            'entornos' => Integraciones::ENTORNOS,
            'estados' => Integraciones::ESTADOS,
            'clases' => Integraciones::CLASES,
            'credenciales' => Integraciones::conexiones()->where('purpose', 'invoicing')
                ->mapWithKeys(fn (object $c): array => [
                    (int) $c->id => Integraciones::estado((int) $c->id),
                ])->all(),

            // Con que se firma.
            'certificados' => $certificados,
            'estadosCertificado' => Certificados::ESTADOS,

            // Que numeros salen.
            'series' => $series,
            'tipos' => Correlativos::tipos(),
            'paises' => DB::table('countries')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name', 'iso2']),
            'entornosSerie' => Correlativos::ENTORNOS,
            'estadosNumero' => Correlativos::ESTADOS,
            'verId' => $verId,
            'ultimos' => $verId > 0 ? Correlativos::ultimos($verId) : collect(),
        ];
    }

    /**
     * La chapa de estado de una tarjeta (9.17i).
     *
     * Dos estados y no cuatro a proposito: para lo que hace falta EMITIR, o hay
     * uno en uso o no lo hay. «A medias» seria decirle a quien mira que puede
     * facturar a medias, y no se puede.
     *
     * @return array{nivel: string, texto: string}
     */
    private static function chapa(bool $enUso): array
    {
        return $enUso
            ? ['nivel' => 'activo', 'texto' => 'Activo']
            : ['nivel' => 'falta', 'texto' => 'Falta configurar'];
    }

    public function store(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate($this->reglas());

        try {
            Integraciones::guardarConexion(null, $datos, (int) Auth::id());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('aviso', $e->getMessage());
        }

        return redirect()->route('integraciones.index')
            ->with('exito', 'Conexión guardada. Ahora ponle sus credenciales.');
    }

    public function update(Request $peticion, string $uuid): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate($this->reglas());

        try {
            Integraciones::guardarConexion($uuid, $datos, (int) Auth::id());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('aviso', $e->getMessage());
        }

        return redirect()->route('integraciones.index')->with('exito', 'Conexión actualizada.');
    }

    /** Guarda un secreto: revoca el anterior y crea la versión siguiente. */
    public function credencial(Request $peticion, string $uuid): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(Integraciones::CLASES))],
            'secreto' => ['required', 'string', 'min:4', 'max:500'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $conexion = Integraciones::porUuid($uuid);

        try {
            Integraciones::guardarSecreto(
                (int) $conexion->id,
                (string) $datos['kind'],
                (string) $datos['secreto'],
                (int) Auth::id(),
                (string) ($datos['motivo'] ?? 'Rotacion desde el admin.'),
            );
        } catch (RuntimeException $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return redirect()->route('integraciones.index')->with(
            'exito',
            'Credencial guardada. La anterior queda revocada, y en pantalla sólo se ven '
            .'sus cuatro últimos: no se puede volver a leer.',
        );
    }

    /** @return array<string, mixed> */
    private function reglas(): array
    {
        return [
            'integration_provider_id' => ['required', 'integer', 'exists:integration_providers,id'],
            'legal_entity_id' => ['nullable', 'integer', 'exists:legal_entities,id'],
            'name' => ['required', 'string', 'max:120'],
            'environment' => ['required', 'in:'.implode(',', array_keys(Integraciones::ENTORNOS))],
            // `https` y no `url`: `ck_iconn_url` exige https en una conexion
            // activa, y una regla que admite `http` aqui deja que el `45000`
            // salga despues sin explicar nada.
            'base_url' => ['nullable', 'string', 'max:255', 'regex:#^https://#'],
            'username' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'in:'.implode(',', array_keys(Integraciones::ESTADOS))],
        ];
    }
}
