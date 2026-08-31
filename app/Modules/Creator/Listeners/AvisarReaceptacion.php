<?php

declare(strict_types=1);

namespace App\Modules\Creator\Listeners;

use App\Modules\Creator\Services\Reaceptacion;
use App\Shared\Eventos\CorreoPedido;
use App\Shared\Eventos\TerminosPublicados;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Les llega un correo y tienen 15 días» (`Q-46`, 9.19b).
 *
 * ### Sólo si el cambio es DE FONDO
 *
 * Un cambio `menor` no invalida la aceptación anterior (`9.16`), así que avisar
 * de él sería molestar a todo el mundo por una errata corregida — y el aviso que
 * llega por todo deja de leerse, que es como se pierde el que sí importa.
 *
 * ### Y sólo a la audiencia de esos términos
 *
 * `terms_versions.audience` distingue `creator` de `client`. Este oyente es de
 * Creator y se calla ante unos términos de clientes: el día que exista el portal
 * de clientes, su módulo pondrá el suyo.
 *
 * ### Un correo que falla no puede deshacer la publicación
 *
 * Por eso esto es un oyente y no una línea dentro de `publicar()`. Y por eso
 * cada envío va en su propio `try`: con doscientos creadores, que el número 37
 * tenga el correo mal escrito no puede dejar sin avisar a los 163 siguientes.
 */
final class AvisarReaceptacion
{
    public function handle(TerminosPublicados $evento): void
    {
        if ($evento->cambio !== 'fondo' || $evento->audiencia !== 'creator') {
            return;
        }

        foreach (Reaceptacion::aQuienesAvisar() as $creador) {
            try {
                Event::dispatch(new CorreoPedido(
                    codigo: 'creator.terms_reacceptance',
                    destinatario: (string) $creador->email,
                    variables: [
                        'nombre' => (string) $creador->display_name,
                        'version' => $evento->version,
                        'titulo' => $evento->titulo,
                        'dias' => $evento->diasParaAceptar,
                        'enlace' => route('terminos.mios'),
                    ],
                    idioma: (string) ($creador->locale ?: 'es'),
                    tipoRelacionado: 'terms_version',
                    idRelacionado: $evento->versionId,
                ));
            } catch (Throwable $e) {
                // No se propaga: la publicacion ya esta hecha y los demas
                // creadores tienen que recibir su aviso igual. Queda en el
                // registro para que «a este no le llego» se pueda contestar.
                Log::warning('No se pudo pedir el aviso de reaceptacion.', [
                    'creador' => $creador->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
