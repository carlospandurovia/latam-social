<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\Sistema;
use Illuminate\View\View;

/**
 * Configuración → Sistema → Información del sistema (`D-1`).
 *
 * Todo lo que el panel enseñaba y no era una pregunta operativa vive aquí: el
 * motor, cómo están impuestas las reglas, el tamaño del esquema y qué sociedad
 * factura en cada país.
 *
 * Cuelga de `config.view`, el mismo permiso que abre la configuración: quien
 * puede ver qué falta por configurar puede ver contra qué está corriendo. Los
 * datos que enseña no son secretos —una versión de MySQL no lo es— pero tampoco
 * son de nadie que no administre la plataforma.
 */
final class SistemaController
{
    public function __invoke(): View
    {
        return view('sistema.index', [
            'entorno' => Sistema::entorno(),
            'aplicacion' => Sistema::aplicacion(),
            'motor' => Sistema::motor(),
            'reglas' => Sistema::reglas(),
            'cobertura' => Sistema::cobertura(),
            'avisos' => Sistema::avisos(),
        ]);
    }
}
