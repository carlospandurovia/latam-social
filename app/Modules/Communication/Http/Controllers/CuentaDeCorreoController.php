<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Modules\Communication\Services\CuentaDeCorreo;
use App\Modules\Core\Services\Integraciones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La cuenta de correo, desde el admin (9.17g).
 *
 * ### Un formulario, dos tablas y una credencial
 *
 * La pantalla pide **una sola cosa** —«con qué cuenta sale el correo»— y por
 * debajo eso son tres escrituras: la conexión (`9.17d`), sus parámetros
 * (`mail_settings`) y la contraseña cifrada. Se hacen en **una transacción**:
 * media cuenta guardada es una cuenta que parece configurada y no manda nada.
 *
 * ### `integration.manage`, y no `comms.view`
 *
 * `comms.view` abre la bandeja de lo que salió, y la tiene más gente. Poner la
 * cuenta con la que sale todo el correo del sistema es otra cosa: es la misma
 * decisión que cargar la credencial de SUNAT.
 */
final class CuentaDeCorreoController
{
    public function guardar(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'name' => ['required', 'string', 'max:120'],
            'host' => ['required', 'string', 'max:160'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['nullable', 'in:tls,ssl'],
            'username' => ['nullable', 'string', 'max:120'],
            // Vacia = no se cambia. Obligarla en cada guardado haria que
            // corregir un puerto exigiera volver a teclear la contrasena, y eso
            // acaba con la contrasena escrita en un papel.
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'min:2', 'max:120'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        try {
            DB::transaction(function () use ($datos, $peticion): void {
                // `guardada()` y no `vigente()` (9.17i): si la cuenta está
                // APAGADA hay que reescribir ésa, no crear una segunda. Con
                // `vigente()` cada guardado tras un apagado dejaba una conexión
                // huérfana más en la tabla.
                $cuenta = CuentaDeCorreo::guardada();
                $usuarioId = (int) Auth::id();

                $uuid = Integraciones::guardarConexion(
                    $cuenta === null ? null : (string) $cuenta->uuid,
                    [
                        'integration_provider_id' => self::proveedorDeCorreo(),
                        'legal_entity_id' => null,
                        'name' => (string) $datos['name'],
                        'environment' => 'production',
                        'username' => (string) ($datos['username'] ?? ''),
                        // La direccion de un servidor de correo no es una URL
                        // web: es servidor y puerto. Se escribe con su esquema
                        // para que la conexion sepa a donde llama --que es lo
                        // que exige `tg_iconn_activa_*`-- sin fingir un https.
                        'base_url' => sprintf(
                            '%s://%s:%d',
                            ($datos['encryption'] ?? '') === 'ssl' ? 'smtps' : 'smtp',
                            trim((string) $datos['host']),
                            (int) $datos['port'],
                        ),
                        'status' => 'active',
                    ],
                    $usuarioId,
                );

                $conexionId = (int) Integraciones::porUuid($uuid)->id;

                CuentaDeCorreo::guardar($conexionId, $datos);

                if (($datos['password'] ?? '') !== '') {
                    Integraciones::guardarSecreto(
                        $conexionId, 'password', (string) $datos['password'], $usuarioId,
                    );
                }

                unset($peticion);
            });
        } catch (Throwable $e) {
            return back()->withInput()->with('aviso', $e->getMessage());
        }

        return redirect()->route('integraciones.index', ['p' => 'correo'])
            ->with('exito', 'Cuenta guardada. Desde ahora manda ésta, no el .env. Pruébela antes de fiarse.');
    }

    /**
     * Enciende o apaga la cuenta (9.17i).
     *
     * `9.17g` dejó una puerta de un solo sentido: guardar activaba la cuenta y
     * no había forma de volver al `.env` sin tocar la base a mano. Apagar no
     * borra: la cuenta se queda escrita, con su contraseña, para poder volver.
     */
    public function conmutar(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'encendida' => ['required', 'boolean'],
        ]);

        $cuenta = CuentaDeCorreo::guardada();

        if ($cuenta === null) {
            return redirect()->route('integraciones.index', ['p' => 'correo'])
                ->with('aviso', 'No hay ninguna cuenta de correo guardada que encender o apagar.');
        }

        $encendida = (bool) $datos['encendida'];

        try {
            CuentaDeCorreo::conmutar((int) $cuenta->id, $encendida);
        } catch (Throwable $e) {
            return redirect()->route('integraciones.index', ['p' => 'correo'])
                ->with('aviso', $e->getMessage());
        }

        return redirect()->route('integraciones.index', ['p' => 'correo'])->with(
            'exito',
            $encendida
                ? 'Cuenta encendida: desde ahora el correo sale de aquí.'
                : 'Cuenta apagada: el correo vuelve a salir del .env. No se ha borrado nada.',
        );
    }

    public function probar(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'destino' => ['required', 'email', 'max:255'],
        ]);

        try {
            CuentaDeCorreo::probar((string) $datos['destino']);
        } catch (Throwable $e) {
            return redirect()->route('integraciones.index', ['p' => 'correo'])
                ->with('aviso', $e->getMessage());
        }

        return redirect()->route('integraciones.index', ['p' => 'correo'])
            ->with('exito', 'Correo de prueba enviado a '.$datos['destino'].'. Si no llega, no está bien.');
    }

    /**
     * El proveedor de correo del catálogo.
     *
     * Se busca por `purpose` y no por el código `smtp`: el día que se use otro
     * —una API de envío en vez de un servidor— el código cambia y el propósito
     * no. Es la misma razón por la que la puerta de `9.17f` es por propósito.
     */
    private static function proveedorDeCorreo(): int
    {
        $id = DB::table('integration_providers')
            ->where('purpose', 'email')->where('is_active', 1)
            ->orderBy('id')->value('id');

        if ($id === null) {
            throw new \RuntimeException(
                'No hay ningun proveedor de correo en el catalogo: siembre los cimientos.',
            );
        }

        return (int) $id;
    }
}
