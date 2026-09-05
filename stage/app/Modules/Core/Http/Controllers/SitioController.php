<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Marca;
use App\Modules\Core\Services\Sitio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Los datos que se pintan en la calle, desde el admin (L-2a).
 *
 * WhatsApp, correo y teléfono públicos, dirección, redes sociales y **qué
 * sociedad opera la marca** —de la que salen la razón social y el RUC de los
 * textos legales—.
 *
 * Va detrás de `brand.manage` y no de un permiso nuevo, por el mismo criterio
 * que la portada en `9.21b`: **quien decide cómo nos llamamos decide qué
 * teléfono damos**. Un permiso más es una fila más que alguien tiene que
 * acordarse de asignar el día que contrate a la persona de marketing.
 */
final class SitioController
{
    public function index(): View
    {
        return view('sitio.index', [
            'datos' => Sitio::datos(),
            'fila' => DB::table('site_settings')
                ->where('platform_brand_id', Marca::idActual())->first(),
            'redes' => Sitio::redes(soloVisibles: false),
            'avisos' => Sitio::avisos(),
            'sociedades' => DB::table('legal_entities')
                ->where('status', 'active')->orderBy('legal_name')
                ->get(['id', 'legal_name', 'tax_id_type', 'tax_id_number']),
            // L-5: los paises para el desplegable del pais por defecto, y el
            // que rige HOY --que puede venir de la sociedad operadora y no de
            // esta pantalla--, para poder decirlo al lado del campo.
            'paises' => DB::table('countries')->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name']),
            'paisEnVigor' => Sitio::paisPorDefecto(),
            'medicion' => Sitio::medicion(),
            'medidores' => Sitio::MEDIDORES,
        ]);
    }

    public function update(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'operator_legal_entity_id' => ['nullable', 'integer', 'exists:legal_entities,id'],
            // El mismo formato que exige `ck_ss_whatsapp`. Pedirlo aqui
            // convierte un `45000` en una frase junto al campo, que es la regla
            // de este proyecto desde `9.17e`.
            'whatsapp_phone' => ['nullable', 'string', 'max:20', 'regex:/^\+[0-9]{8,15}$/'],
            'whatsapp_message' => ['nullable', 'string', 'min:10', 'max:300'],
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'public_address' => ['nullable', 'string', 'max:255'],
            'default_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'analytics_provider' => ['nullable', 'in:'.implode(',', array_keys(Sitio::MEDIDORES))],
            // La MISMA regla que `ck_ss_medidor_id`, y no por gusto: este valor
            // entra dentro de un `<script>` de todas las paginas publicas. Aqui
            // se pide para que una errata sea una frase junto al campo; en la
            // base se impone para que una fila que entre por otro camino
            // tampoco pueda llevar una comilla.
            'analytics_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
        ], [
            'whatsapp_phone.regex' => 'El WhatsApp va en formato internacional, sin espacios ni '
                .'guiones: +51987654321. Va dentro de un enlace, y un espacio lo rompe sin dar error.',
            'analytics_id.regex' => 'El identificador de medición sólo admite letras, números, punto '
                .'y guion. Va dentro de un <script>, así que cualquier otra cosa sería una inyección.',
        ]);

        // La regla de `ck_ss_medidor_par`, dicha como frase. Un proveedor sin
        // identificador no mide nada y un identificador sin proveedor no lo lee
        // nadie: los dos casos son configuracion a medias que PARECE completa.
        $proveedor = trim((string) ($datos['analytics_provider'] ?? ''));
        $identificador = trim((string) ($datos['analytics_id'] ?? ''));

        if (($proveedor === '') !== ($identificador === '')) {
            return back()->withInput()->with(
                'aviso',
                'La medición necesita las dos cosas: el proveedor y su identificador. Con una sola '
                .'no se mide nada, y la pantalla diría que está configurada.',
            );
        }

        // Un campo en blanco se guarda como NULL y no como ''. Los CHECK
        // admiten NULL --«no configurado»-- y rechazan la cadena vacia, que no
        // significa lo mismo.
        foreach ($datos as $campo => $valor) {
            if (trim((string) $valor) === '') {
                $datos[$campo] = null;
            }
        }

        Sitio::guardar($datos, (int) Auth::id());

        return back()->with('mensaje', 'Datos del sitio guardados.');
    }

    public function guardarRed(Request $peticion, ?int $red = null): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            'network' => ['required', 'string', 'regex:/^[a-z0-9_-]{2,30}$/'],
            'label' => ['required', 'string', 'min:2', 'max:60'],
            'url' => ['required', 'string', 'max:255', 'regex:#^https://#'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_visible' => ['nullable', 'boolean'],
        ], [
            'network.regex' => 'El código de la red va en minúsculas y sin espacios: instagram, tiktok, linkedin.',
            'url.regex' => 'El enlace tiene que empezar por https://.',
        ]);

        $datos['sort_order'] = (int) ($datos['sort_order'] ?? 100);
        $datos['is_visible'] = (bool) ($datos['is_visible'] ?? false);

        Sitio::guardarRed($red, $datos, (int) Auth::id());

        return back()->with('mensaje', 'Red guardada.');
    }

    public function borrarRed(int $red): RedirectResponse
    {
        Sitio::borrarRed($red);

        return back()->with('mensaje', 'Red quitada.');
    }
}
