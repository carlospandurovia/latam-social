<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Controllers;

use App\Shared\Database\Choque;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * La postulación pública de un creador (9.21b).
 *
 * ### La mesa estaba puesta desde la Fase 2
 *
 * `creator_applications` existe desde `2026_08_22_000400` **con `source` por
 * defecto `'landing'`**, con su bandeja en el admin (`/backoffice/solicitudes`)
 * y con `uq_creator_applications_open` —una sola solicitud abierta por correo—.
 * Lo único que faltaba era la landing que escribiera en ella. Es el mismo caso
 * que `document_series` en `9.12`: la tabla lista y sin puerta.
 *
 * ### Por qué esto vive en Creator y no en Core
 *
 * Porque escribe en `creator_applications`, y `deptrac.yaml` dice
 * `Core: [Framework, Shared]`. Core pinta la portada —que es contenido— y
 * Creator recibe la postulación —que son sus datos—. Es la lección de `T-74`
 * aplicada antes de romperla: **una consulta a la tabla de otro módulo es una
 * frontera rota que `deptrac` no ve**, porque no hay ninguna clase importada.
 *
 * ### Un formulario público escribe en la base, así que
 *
 * - **`throttle`** en la ruta: cinco por minuto y por IP. Sin eso, esta URL es
 *   una forma cómoda de llenarle la bandeja a alguien.
 * - **Un campo trampa** (`empresa`) que una persona no ve y un robot rellena.
 *   Si viene lleno se contesta *gracias* y no se escribe nada: decirle al robot
 *   que fue detectado sólo le enseña a intentarlo mejor.
 * - **Nada de CAPTCHA.** Es una barrera para quien lee con dificultad y un
 *   trámite de segundos para quien automatiza en serio.
 */
final class PostulacionController
{
    public function postular(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'referral_code' => ['nullable', 'string', 'max:30'],
            // El campo trampa. `nullable` y no `prohibited`: se acepta y se
            // ignora, para que quien lo rellene no aprenda que existe.
            'empresa' => ['nullable', 'string', 'max:100'],
        ]);

        if (($datos['empresa'] ?? '') !== '') {
            return redirect()->route('portada.gracias');
        }

        try {
            DB::table('creator_applications')->insert([
                'uuid' => (string) Str::uuid(),
                'full_name' => trim((string) $datos['full_name']),
                'email' => mb_strtolower(trim((string) $datos['email'])),
                'phone' => ($datos['phone'] ?? '') !== '' ? (string) $datos['phone'] : null,
                'country_id' => (int) $datos['country_id'],
                'source' => 'landing',
                'referral_code' => ($datos['referral_code'] ?? '') !== '' ? (string) $datos['referral_code'] : null,
                'status' => 'submitted',
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Postular dos veces no es un error del que postula: es alguien que
            // no se acuerda de haberlo hecho, o que reintenta porque nadie le
            // contesto. Se le dice lo segundo, que es lo util.
            if (Choque::indice($e) === 'uq_creator_applications_open') {
                return back()->withInput()->with(
                    'aviso',
                    'Ya tienes una postulación en revisión con ese correo. La estamos mirando; '
                    .'no hace falta que vuelvas a enviarla.',
                );
            }

            throw $e;
        }

        return redirect()->route('portada.gracias');
    }
}
