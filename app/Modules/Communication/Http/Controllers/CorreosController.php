<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Modules\Communication\Services\Correo;
use App\Modules\Communication\Services\Plantillas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * El registro de correos y las plantillas (4.9).
 *
 * ### Por qué una pantalla y no un archivo de log
 *
 * Porque el modo de fallo que importa —*«al creador no le llegó su enlace»*— lo
 * descubre alguien de operaciones, no un desarrollador leyendo
 * `storage/logs`. Un `failed` en un archivo que nadie abre es un fallo que no
 * existe hasta que alguien reclama.
 *
 * La pantalla abre **filtrada por fallidos**, que es la pregunta que se hace
 * quien entra aquí. Ver todos es un clic más.
 */
final class CorreosController
{
    public function index(Request $peticion): View
    {
        $estado = (string) $peticion->query('estado', 'failed');

        $consulta = DB::table('email_log')->orderByDesc('queued_at');

        if (in_array($estado, ['queued', 'sent', 'failed', 'cancelled'], true)) {
            $consulta->where('status', $estado);
        }

        return view('correos.index', [
            'correos' => $consulta->limit(200)->get([
                'uuid', 'template_code', 'template_version', 'template_locale',
                'locale_requested', 'to_email', 'subject', 'status', 'attempts',
                'last_error', 'queued_at', 'sent_at', 'failed_at',
            ]),
            'estado' => $estado,
            'conteos' => DB::table('email_log')
                ->groupBy('status')->pluck(DB::raw('COUNT(*)'), 'status'),
            // La lista de traducciones que faltan, sacada de los envios REALES.
            // Es la mitad que justifica anotar el idioma pedido: sin esto, la
            // caida al idioma por defecto seria silenciosa.
            'faltanTraducciones' => Correo::traduccionesQueFaltan(),
        ]);
    }

    public function plantillas(): View
    {
        return view('correos.plantillas', [
            'plantillas' => Plantillas::todas(),
            'porDefecto' => Plantillas::idiomaPorDefecto(),
        ]);
    }
}
