<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Semaforo;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Los umbrales del semáforo, desde el admin (`D-6`).
 *
 * ### Por qué existe esta pantalla y no tres constantes
 *
 * Porque `DEC-190` no admite otra lectura: *«no me digas que algo es un stopper,
 * eso no debe ser así»* y *«el sistema debe ser 100 % parametrizable desde el
 * admin»*. Cuándo una campaña está en riesgo es un juicio del negocio que se
 * ajusta con datos reales, no una propiedad del programa.
 *
 * ### Detrás de `campaign.manage`
 *
 * Y no de `config.view`, por el mismo criterio que la política de precios en
 * `9.18`: **quien lleva las campañas decide cuándo una campaña va mal**. No es
 * un parámetro de instalación como el correo saliente; es el criterio con el
 * que su propio equipo mira su propio trabajo.
 *
 * ### Se edita en sitio, no se versiona
 *
 * A diferencia de la política de precios —donde el umbral de ayer explica lo que
 * se pactó ayer y por eso se guarda con fecha— aquí el número sólo colorea una
 * pantalla. Nadie va a tener que justificar dentro de un año por qué el 12 de
 * marzo el ámbar empezaba a los siete días. Quién lo cambió y cuándo queda en la
 * bitácora, que es lo que hace falta.
 */
final class UmbralesController
{
    public function index(): View
    {
        $fila = Semaforo::fila();

        return view('umbrales.index', [
            'umbrales' => Semaforo::umbrales(),
            'avisos' => Semaforo::avisos(),
            'partida' => Semaforo::PARTIDA,
            // Ya formateada: una plantilla que llama a `Carbon::parse` es
            // logica en la plantilla, y `docs/08` la prohibe.
            'modificado' => $fila?->updated_at === null
                ? null
                : CarbonImmutable::parse((string) $fila->updated_at)->format('d/m/Y H:i'),
        ]);
    }

    public function update(Request $peticion): RedirectResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $peticion->validate([
            // Los mismos rangos que imponen `ck_tt_cierre`, `ck_tt_avance` y
            // `ck_tt_arranque`. Pedirlos aqui convierte un `45000` a media
            // pantalla en una frase junto al campo, que es la regla de este
            // proyecto desde `9.17e`.
            'risk_days_before_end' => ['required', 'integer', 'min:0', 'max:365'],
            'min_progress_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'recruiting_days_before_start' => ['required', 'integer', 'min:0', 'max:365'],
        ], [
            'risk_days_before_end.max' => 'Un umbral por encima de 365 días dejaría casi todas las '
                .'campañas en ámbar para siempre, y un aviso permanente esconde los que importan.',
            'recruiting_days_before_start.max' => 'Un umbral por encima de 365 días dejaría casi '
                .'todas las convocatorias en ámbar para siempre.',
        ]);

        Semaforo::guardar([
            'risk_days_before_end' => (int) $datos['risk_days_before_end'],
            'min_progress_pct' => (int) $datos['min_progress_pct'],
            'recruiting_days_before_start' => (int) $datos['recruiting_days_before_start'],
        ], Auth::id() === null ? null : (int) Auth::id());

        return back()->with('mensaje', sprintf(
            'Umbrales guardados: ámbar a %d días del cierre por debajo del %d %% de avance, y a '
            .'%d días del arranque con la convocatoria incompleta.',
            (int) $datos['risk_days_before_end'],
            (int) $datos['min_progress_pct'],
            (int) $datos['recruiting_days_before_start'],
        ));
    }
}
