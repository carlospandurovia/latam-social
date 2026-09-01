<?php

declare(strict_types=1);

namespace App\Modules\Client\Http\Controllers;

use App\Modules\Client\Services\Prospectos;
use App\Modules\Core\Services\Landing;
use App\Modules\Core\Services\Marca;
use App\Shared\Database\Choque;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * El formulario de contacto de la portada de marcas (9.21c).
 *
 * ### Por qué vive en Client
 *
 * Porque escribe en `client_leads`. Core pinta la portada —que es contenido— y
 * Client recibe el contacto —que es un cliente en potencia—. Mismo reparto que
 * la postulación de `9.21b`, y por el mismo motivo: `deptrac` no ve una consulta
 * a la tabla de otro módulo, que es la peor clase de frontera rota (`T-74`).
 *
 * ### Las mismas tres defensas que la postulación
 *
 * `throttle` en la ruta, un campo trampa que una persona no ve, y **ningún
 * CAPTCHA**. Un formulario público que escribe en la base necesita las tres, y
 * la tercera es una decisión: es una barrera para quien lee con dificultad y un
 * trámite de segundos para quien automatiza en serio.
 */
final class ContactoController
{
    public function enviar(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'company_name' => ['required', 'string', 'min:2', 'max:160'],
            'contact_name' => ['required', 'string', 'min:3', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            // `ck_clead_web` lo exige en la base; pedirlo aqui convierte un
            // `45000` en una frase junto al campo.
            'website' => ['nullable', 'string', 'max:255', 'regex:#^https?://#'],
            'message' => ['nullable', 'string', 'max:1000'],
            'empresa_2' => ['nullable', 'string', 'max:100'],
        ]);

        if (($datos['empresa_2'] ?? '') !== '') {
            return redirect()->route('contacto.gracias');
        }

        try {
            Prospectos::recibir($datos);
        } catch (Throwable $e) {
            // Escribir dos veces no es un error de quien escribe: es alguien que
            // no recibio respuesta. Se le dice eso, que es lo util.
            if (Choque::indice($e) === 'uq_clead_abierto') {
                return back()->withInput()->with(
                    'aviso',
                    'Ya tenemos un contacto tuyo con ese correo y lo estamos mirando. '
                    .'Si es urgente, escríbenos y lo miramos antes.',
                );
            }

            throw $e;
        }

        return redirect()->route('contacto.gracias');
    }

    public function gracias(): View|RedirectResponse
    {
        $pagina = Landing::portada(Landing::MARCAS);

        if ($pagina === null) {
            return redirect()->route('acceso');
        }

        return view('publico.gracias-marca', [
            'pagina' => $pagina,
            'marca' => Marca::datos(),
        ]);
    }
}
