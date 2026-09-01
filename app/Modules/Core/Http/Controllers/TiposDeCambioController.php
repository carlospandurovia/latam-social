<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Cambio;
use App\Modules\Core\Services\CredencialFuente;
use App\Modules\Core\Services\Decolecta;
use App\Modules\Core\Services\TraidaDeCambio;
use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

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
            'api_key' => ['nullable', 'string', 'min:8', 'max:255'],
            'api_base_url' => ['nullable', 'url', 'max:255'],
        ]);

        $clave = trim((string) ($datos['api_key'] ?? ''));

        try {
            CredencialFuente::guardar(
                Decolecta::FUENTE, $clave, (int) Auth::id(), $datos['api_base_url'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('aviso', $e->getMessage());
        }

        if ($clave !== '') {
            Bitacora::registrar(
                accion: 'fx.credential.set',
                tipoEntidad: 'fx_source',
                idEntidad: (int) DB::table('fx_sources')->where('code', Decolecta::FUENTE)->value('id'),
                cambios: ['credencial' => ['antes' => null, 'despues' => 'termina en '.mb_substr($clave, -4)]],
            );
        }

        return redirect()->route('integraciones.index', ['p' => 'fx'])->with(
            'exito',
            $clave === ''
                ? 'Guardado. La clave anterior sigue en uso: sólo cambia lo demás.'
                : 'Clave guardada, cifrada. Pruebe a traer el tipo de cambio de hoy.',
        );
    }

    public function olvidarCredencial(): RedirectResponse
    {
        CredencialFuente::olvidar(Decolecta::FUENTE);

        Bitacora::registrar(
            accion: 'fx.credential.cleared',
            tipoEntidad: 'fx_source',
            idEntidad: (int) DB::table('fx_sources')->where('code', Decolecta::FUENTE)->value('id'),
            cambios: ['credencial' => ['antes' => 'guardada', 'despues' => 'revocada']],
        );

        return redirect()->route('integraciones.index', ['p' => 'fx'])
            ->with('aviso', 'Clave revocada. Queda en el histórico con quién la puso. '
                .'Si hay una en el entorno, a partir de ahora manda ésa.');
    }

    /**
     * Los datos de la pestaña de tipos de cambio de Integraciones (9.17h).
     *
     * @return array<string, mixed>
     */
    public static function datosDeIntegracion(): array
    {
        $credencial = CredencialFuente::estado(Decolecta::FUENTE);

        return [
            'credencial' => $credencial,
            'urlPorDefecto' => (string) config('latam.cambio.decolecta.url', Decolecta::URL),
            'hoy' => now()->toDateString(),
            'avisosCambio' => self::avisosDeLaFuente(),
            // Dos estados y no cuatro: o entra una tasa nueva cada dia o no
            // entra. «A medias» no existe aqui.
            'estadoCambio' => $credencial['origen'] === CredencialFuente::NINGUNA
                ? ['nivel' => 'falta', 'texto' => 'Falta la clave']
                : ['nivel' => 'activo', 'texto' => 'Activo'],
        ];
    }

    /**
     * Lo que falta para que entren tasas. **Sólo lo que se arregla aquí.**
     *
     * `loQueHayQueMirar()` --que la última traída fue mal-- se queda en el área
     * de Tipos de cambio: eso se mira en el registro de traídas y se reintenta
     * allí, no se arregla tecleando una clave.
     *
     * @return list<Aviso>
     */
    public static function avisosDeLaFuente(): array
    {
        if (CredencialFuente::estado(Decolecta::FUENTE)['origen'] !== CredencialFuente::NINGUNA) {
            return [];
        }

        return [Aviso::rojo(
            'No hay credencial para la fuente oficial de tipos de cambio. Sin ella no entra '
            .'ninguna tasa nueva, y convertir con una tasa vieja es convertir mal.',
        )];
    }

    /** A la pantalla desde la que se pulsó, no siempre a la misma. */
    private static function volver(Request $peticion): RedirectResponse
    {
        return $peticion->input('volver') === 'fx'
            ? redirect()->route('integraciones.index', ['p' => 'fx'])
            : redirect()->route('cambio.index');
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

        // 9.17h: el mismo boton se pulsa desde dos sitios --la pestana de
        // integraciones y la pantalla de tasas-- y devolver siempre a la
        // segunda echaba de la pantalla a quien estaba configurando.
        return self::volver($peticion)->with(
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
