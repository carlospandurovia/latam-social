<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Cambio;
use App\Modules\Core\Services\CredencialFuente;
use App\Modules\Core\Services\Decolecta;
use App\Modules\Core\Services\TraidaDeCambio;
use App\Shared\Audit\Bitacora;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * La pantalla de tipos de cambio (9.2).
 *
 * Contesta cuatro preguntas y en este orden, que es el orden en que se
 * preguntan cuando algo va mal:
 *
 * 1. **¿Hay algo que mirar?** Arriba del todo, y sólo si lo hay.
 * 2. **¿Quién manda para cada par?** Sin eso no se convierte nada.
 * 3. **¿Qué tasas tenemos?** Las últimas, con su fuente y su lado.
 * 4. **¿Está configurada la credencial y quién la puso?**
 *
 * La clave **nunca sale de aquí hacia la vista**: la vista recibe `estado()`,
 * que son los cuatro últimos y poco más. Es la misma barrera que
 * `Aprobaciones::pieza()` en `8.5` — el sitio donde se decide qué columnas
 * cruzan a una plantilla es el sitio donde se filtra o no se filtra
 * (`BR-SEC-001`).
 */
final class TiposDeCambioController
{
    public function index(): View
    {
        return view('cambio.index', [
            'aviso' => TraidaDeCambio::loQueHayQueMirar(Decolecta::FUENTE),
            'credencial' => CredencialFuente::estado(Decolecta::FUENTE),
            'fuentes' => DB::table('fx_sources')->orderBy('code')->get(['code', 'name', 'description', 'is_active']),
            'oficiales' => DB::table('fx_official_sources')
                ->orderBy('base_currency_code')->orderBy('quote_currency_code')->orderByDesc('valid_from')
                ->get(['base_currency_code', 'quote_currency_code', 'source_code',
                    'valid_from', 'valid_to', 'current_gate']),
            'tasas' => DB::table('exchange_rates')
                ->orderByDesc('rate_date')->orderBy('base_currency_code')->orderBy('side')
                ->limit(40)
                ->get(['base_currency_code', 'quote_currency_code', 'rate_date', 'rate', 'side', 'source']),
            'corridas' => TraidaDeCambio::ultimas(Decolecta::FUENTE),
            'lados' => Cambio::LADOS,
            'monedas' => DB::table('currencies')->where('is_active', 1)->orderBy('code')->get(['code', 'name']),
            'hoy' => now()->toDateString(),
            'diasAtras' => Cambio::DIAS_ATRAS,
        ]);
    }

    /**
     * Guarda la credencial. **No la registra en la bitácora.**
     *
     * Lo que se anota es que alguien la cambió y sus cuatro últimos, nunca el
     * valor: la bitácora se consulta desde una pantalla y se exporta, y un
     * secreto que pasa por ahí ya no es un secreto (`BR-SEC-001`, y la regla de
     * no guardar información sensible innecesariamente en los logs).
     */
    public function guardarCredencial(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'api_key' => ['required', 'string', 'min:8', 'max:255'],
            'api_base_url' => ['nullable', 'url', 'max:255'],
        ]);

        $clave = trim((string) $datos['api_key']);

        DB::transaction(function () use ($datos, $clave): void {
            if (($datos['api_base_url'] ?? null) !== null) {
                DB::table('fx_sources')->where('code', Decolecta::FUENTE)
                    ->update(['api_base_url' => $datos['api_base_url'], 'updated_at' => now()]);
            }

            CredencialFuente::guardar(Decolecta::FUENTE, $clave, (int) Auth::id());
        });

        Bitacora::registrar(
            accion: 'fx.credential.set',
            tipoEntidad: 'fx_source',
            idEntidad: (int) DB::table('fx_sources')->where('code', Decolecta::FUENTE)->value('id'),
            cambios: ['credencial' => ['antes' => null, 'despues' => 'termina en '.mb_substr($clave, -4)]],
        );

        return redirect()->route('cambio.index')
            ->with('exito', 'Credencial guardada, cifrada. Pruebe a traer el tipo de cambio de hoy.');
    }

    public function olvidarCredencial(): RedirectResponse
    {
        CredencialFuente::olvidar(Decolecta::FUENTE);

        Bitacora::registrar(
            accion: 'fx.credential.cleared',
            tipoEntidad: 'fx_source',
            idEntidad: (int) DB::table('fx_sources')->where('code', Decolecta::FUENTE)->value('id'),
            cambios: ['credencial' => ['antes' => 'guardada', 'despues' => null]],
        );

        return redirect()->route('cambio.index')
            ->with('aviso', 'Credencial borrada de la base. Si hay una en el entorno, sigue mandando esa.');
    }

    /**
     * Trae ahora mismo, para no tener que esperar al cron.
     *
     * Es el botón que convierte «configuré la clave» en «la clave funciona» sin
     * esperar a mañana. Pasa por el mismo camino que el comando —cliente y
     * registro— para que lo que se prueba aquí sea lo que va a correr solo.
     */
    public function traer(Request $peticion): RedirectResponse
    {
        $fecha = $peticion->validate([
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ])['fecha'];

        $resultado = Decolecta::traer((string) $fecha);
        TraidaDeCambio::anotar(Decolecta::FUENTE, (string) $fecha, $resultado);

        return redirect()->route('cambio.index')->with(
            $resultado['outcome'] === Decolecta::OK ? 'exito' : 'aviso',
            $resultado['detalle'],
        );
    }

    /**
     * Declara qué fuente manda para un par, desde una fecha.
     *
     * El veto de solape lo pone la base (`fos_sin_solape`), no este método: una
     * comprobación que sólo vive en el controlador no protege al próximo que
     * escriba en la tabla. Aquí sólo se traduce el rechazo a algo legible.
     */
    public function declararOficial(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'base_currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'quote_currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code', 'different:base_currency_code'],
            'source_code' => ['required', 'string', 'exists:fx_sources,code'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
        ]);

        $veto = Cambio::vetoParaDeclarar(
            (string) $datos['base_currency_code'],
            (string) $datos['quote_currency_code'],
            (string) $datos['valid_from'],
        );

        if ($veto !== null) {
            return redirect()->route('cambio.index')->with('aviso', $veto);
        }

        DB::transaction(fn () => Cambio::declararOficial(
            (string) $datos['base_currency_code'],
            (string) $datos['quote_currency_code'],
            (string) $datos['source_code'],
            (string) $datos['valid_from'],
        ));

        return redirect()->route('cambio.index')->with('exito', sprintf(
            'Desde el %s, %s publica el tipo de cambio de %s a %s.',
            $datos['valid_from'], $datos['source_code'],
            mb_strtoupper((string) $datos['base_currency_code']),
            mb_strtoupper((string) $datos['quote_currency_code']),
        ));
    }

    /**
     * Anota una tasa a mano, con la fuente `manual`.
     *
     * Existe porque SUNAT **sólo publica `USD → PEN`**: para pagarle a un
     * creador mexicano no hay proveedor que traiga `MXN`, y la alternativa a
     * teclearla es no poder convertir. Va con fuente propia y no disfrazada de
     * `sunat`, que es lo que permite distinguir después qué se trajo y qué se
     * tecleó.
     */
    public function anotarAMano(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'base_currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'quote_currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code', 'different:base_currency_code'],
            'rate_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'side' => ['required', 'string', 'in:'.implode(',', array_keys(Cambio::LADOS))],
        ]);

        $nueva = Cambio::anotar(
            (string) $datos['base_currency_code'],
            (string) $datos['quote_currency_code'],
            (string) $datos['rate_date'],
            (string) $datos['rate'],
            'manual',
            (string) $datos['side'],
        );

        Bitacora::registrar(
            accion: 'fx.rate.manual',
            tipoEntidad: 'exchange_rate',
            idEntidad: null,
            cambios: ['tasa' => ['antes' => null, 'despues' => sprintf(
                '%s->%s %s %s el %s',
                mb_strtoupper((string) $datos['base_currency_code']),
                mb_strtoupper((string) $datos['quote_currency_code']),
                $datos['rate'], $datos['side'], $datos['rate_date'],
            )]],
        );

        return redirect()->route('cambio.index')->with(
            $nueva ? 'exito' : 'aviso',
            $nueva
                ? 'Tasa anotada.'
                : 'Esa tasa ya estaba, con esa misma fuente y ese mismo lado. Una publicada no se reescribe.',
        );
    }
}
