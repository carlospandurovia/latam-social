<?php

declare(strict_types=1);

namespace App\Modules\Communication\Services;

use App\Modules\Communication\Jobs\EnviarCorreo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Encolar un correo, dejando constancia de qué se envió (4.9).
 *
 * ### Qué se guarda, y sobre todo qué NO
 *
 * Decisión de negocio (2026-08-26): **plantilla, versión, idioma, asunto y la
 * huella del cuerpo**. El cuerpo renderizado no.
 *
 * Es la regla del proyecto —*«no guardar información sensible innecesariamente
 * en logs»*— aplicada con cabeza. El cuerpo lleva el nombre de la persona, a
 * veces importes y a veces datos fiscales; guardarlo convierte `email_log` en
 * una segunda copia de la ficha del creador, que hay que proteger, anonimizar y
 * borrar igual que la primera.
 *
 * Y no se pierde nada. La versión de la plantilla es **inmutable** y la huella
 * demuestra que el cuerpo enviado era exactamente el que sale de renderizarla
 * con esas variables. *«Me llegaron condiciones distintas»* se contesta con la
 * versión y la huella.
 *
 * ### El código de plantilla y su versión se COPIAN
 *
 * `BR-LE-001` aplicado al correo: dentro de dos años, *«¿qué texto se le
 * envió?»* tiene que responderlo esta fila, no una consulta a la plantilla de
 * entonces — que puede haber cambiado de versión y sonar igual de convincente.
 *
 * ### Nada se envía dentro de la petición
 *
 * `EnviarCorreo` va a la cola. Un SMTP lento no puede dejar colgada la pantalla
 * en la que alguien acaba de aprobar a un creador, y un SMTP caído no puede
 * hacer fallar esa aprobación: el correo es una consecuencia, no una condición.
 */
final class Correo
{
    /**
     * Encola un correo y devuelve el uuid de su registro.
     *
     * @param array<string, string|int|float> $variables
     */
    public static function enviar(
        string $codigo,
        string $destinatario,
        array $variables = [],
        ?string $idioma = null,
        ?string $tipoRelacionado = null,
        ?int $idRelacionado = null,
    ): string {
        $resuelto = Plantillas::resolver($codigo, $idioma);
        $plantilla = $resuelto['plantilla'];

        $asunto = Plantillas::renderizar((string) $plantilla->subject, $variables);
        $cuerpo = Plantillas::renderizar((string) $plantilla->body, $variables);

        $uuid = (string) Str::uuid();

        DB::table('email_log')->insert([
            'uuid' => $uuid,
            'email_template_id' => $plantilla->id,
            'template_code' => $plantilla->code,
            'template_version' => $plantilla->version,
            'template_locale' => $plantilla->locale,
            // El idioma que se PIDIO, aunque coincida. De la diferencia entre
            // los dos sale la lista de plantillas que faltan por traducir.
            'locale_requested' => $resuelto['pedido'],
            'to_email' => $destinatario,
            'subject' => $asunto,
            'body_sha256' => hash('sha256', $cuerpo),
            'status' => 'queued',
            'attempts' => 0,
            'related_type' => $tipoRelacionado,
            'related_id' => $idRelacionado,
            'queued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // El cuerpo viaja EN el job y no se guarda en la tabla. Vive lo que dure
        // la cola, que es exactamente lo que hace falta para enviarlo.
        EnviarCorreo::dispatch($uuid, $destinatario, $asunto, $cuerpo);

        return $uuid;
    }

    /**
     * Las plantillas que faltan por traducir, según los envíos reales.
     *
     * Es la mitad que justifica anotar el idioma pedido. Sin esto, la caída al
     * idioma por defecto sería silenciosa y nadie se enteraría nunca de que el
     * portugués no existe.
     *
     * @return Collection<int, \stdClass>
     */
    public static function traduccionesQueFaltan(): Collection
    {
        return DB::table('email_log')
            ->whereColumn('locale_requested', '!=', 'template_locale')
            ->groupBy('template_code', 'locale_requested', 'template_locale')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get([
                'template_code', 'locale_requested', 'template_locale',
                DB::raw('COUNT(*) AS envios'),
                DB::raw('MAX(queued_at) AS ultimo'),
            ]);
    }
}
