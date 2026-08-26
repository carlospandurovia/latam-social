<?php

declare(strict_types=1);

namespace App\Modules\Communication\Services;

use App\Shared\Database\Vigencia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Las plantillas de correo: resolver la vigente y publicar la siguiente (4.9).
 *
 * ### Se resuelve por (código, idioma), y hay caída de idioma
 *
 * Decisión de negocio (2026-08-26): si no hay plantilla en el idioma del
 * destinatario, **se cae al idioma por defecto y se anota**. Un aviso que no
 * llega es peor que uno en el idioma equivocado.
 *
 * Lo importante es la segunda mitad: **se anota**. El registro guarda el idioma
 * que se pidió junto al que se usó, así que la lista de plantillas que faltan
 * por traducir es una consulta, no una revisión a mano. Sin eso, la caída sería
 * silenciosa y nadie se enteraría nunca de que el portugués no existe.
 *
 * ### Una versión publicada no se edita
 *
 * Se publica la siguiente y la anterior se cierra **el día antes**
 * (`Vigencia::cerrarElDiaAntesDe`). Es el mismo patrón que `terms_versions`, y
 * por la misma razón: lo que se envió tiene que poder demostrarse años después,
 * y una plantilla editable convierte «esto es lo que le mandamos» en «esto es lo
 * que le mandaríamos hoy».
 *
 * `content_sha256` lo hace comprobable: si alguien edita el texto de una versión
 * ya usada, la huella deja de cuadrar con la que guardó el registro de envío.
 */
final class Plantillas
{
    /** El idioma al que se cae cuando no hay plantilla en el del destinatario. */
    public static function idiomaPorDefecto(): string
    {
        return (string) config('latam.correo.idioma_por_defecto', 'es');
    }

    /**
     * La plantilla vigente para ese código e idioma.
     *
     * Devuelve la fila **y el idioma que se pidió**, para que quien envíe pueda
     * anotar la caída. No devuelve `null` en silencio: si no hay ni siquiera en
     * el idioma por defecto, es un fallo de configuración de la plataforma y
     * hay que verlo, no tragarlo.
     *
     * @return array{plantilla: \stdClass, pedido: string, hubo_caida: bool}
     */
    public static function resolver(string $codigo, ?string $idioma = null): array
    {
        $pedido = $idioma !== null && $idioma !== '' ? $idioma : self::idiomaPorDefecto();

        $plantilla = self::vigente($codigo, $pedido);

        if ($plantilla !== null) {
            return ['plantilla' => $plantilla, 'pedido' => $pedido, 'hubo_caida' => false];
        }

        // El idioma puede venir como `pt-BR` y la plantilla estar como `pt`.
        // Se prueba el prefijo antes de rendirse: cae a portugues y no a
        // castellano, que es una caida mucho mas pequena.
        $raiz = Str::before($pedido, '-');

        if ($raiz !== $pedido && ($plantilla = self::vigente($codigo, $raiz)) !== null) {
            return ['plantilla' => $plantilla, 'pedido' => $pedido, 'hubo_caida' => true];
        }

        $porDefecto = self::idiomaPorDefecto();
        $plantilla = self::vigente($codigo, $porDefecto);

        if ($plantilla === null) {
            throw new RuntimeException(
                "No hay ninguna version vigente de la plantilla «{$codigo}», ni siquiera en "
                ."«{$porDefecto}». Es un fallo de configuracion de la plataforma: publiquela con "
                .'`php artisan correos:publicar`.',
            );
        }

        return ['plantilla' => $plantilla, 'pedido' => $pedido, 'hubo_caida' => true];
    }

    /**
     * Publica una versión, cerrando la anterior **el día antes**.
     *
     * `Vigencia::cerrarElDiaAntesDe` y no `subDay()` a mano: `valid_to` es
     * inclusivo, y cerrar el mismo día en que empieza la siguiente deja dos
     * versiones vigentes a la vez. Es el error que este proyecto ha visto once
     * veces y por el que existe una puerta de calidad.
     *
     * @param array<int, string> $variables
     */
    public static function publicar(
        string $codigo,
        string $idioma,
        string $version,
        string $asunto,
        string $cuerpo,
        string $desde,
        array $variables = [],
        ?int $autorId = null,
    ): int {
        return DB::transaction(function () use (
            $codigo, $idioma, $version, $asunto, $cuerpo, $desde, $variables, $autorId
        ): int {
            $vigente = self::vigente($codigo, $idioma);

            if ($vigente !== null) {
                if (!Vigencia::puedeRelevar($desde, (string) $vigente->effective_from)) {
                    throw new RuntimeException(sprintf(
                        'La version vigente de «%s» (%s) empieza el %s: la nueva no puede empezar antes ni el mismo dia.',
                        $codigo, $idioma, $vigente->effective_from,
                    ));
                }

                DB::table('email_templates')->where('id', $vigente->id)
                    ->update([
                        'effective_to' => Vigencia::cerrarElDiaAntesDe($desde),
                        'updated_at' => now(),
                    ]);
            }

            return (int) DB::table('email_templates')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'code' => $codigo,
                'locale' => $idioma,
                'version' => $version,
                'subject' => $asunto,
                'body' => $cuerpo,
                'variables' => json_encode(array_values($variables), JSON_THROW_ON_ERROR),
                // La huella cubre asunto Y cuerpo: cambiar solo el asunto es
                // cambiar lo que la persona vio en su bandeja.
                'content_sha256' => hash('sha256', $asunto."\n".$cuerpo),
                'effective_from' => $desde,
                'effective_to' => null,
                'created_by_user_id' => $autorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Sustituye `{{ variable }}` por su valor.
     *
     * Deliberadamente tonto: **no es Blade**. Una plantilla de correo la edita
     * un operador desde una pantalla, y Blade ejecutaria PHP arbitrario escrito
     * por quien pueda tocar esa tabla. `str_replace` no ejecuta nada.
     *
     * Una variable que la plantilla usa y nadie pasó **revienta**, no sale
     * literal: un `{{ nombre }}` en el correo de una persona es peor que un
     * error en el registro.
     *
     * @param array<string, string|int|float> $variables
     */
    public static function renderizar(string $texto, array $variables): string
    {
        $salida = $texto;

        foreach ($variables as $clave => $valor) {
            $salida = str_replace('{{ '.$clave.' }}', (string) $valor, $salida);
        }

        if (preg_match('/\{\{\s*([A-Za-z_][\w.]*)\s*\}\}/', $salida, $coincide) === 1) {
            throw new RuntimeException(sprintf(
                'La plantilla usa la variable «%s» y nadie la paso. Un correo con «%s» dentro '
                .'es peor que un error aqui.',
                $coincide[1], $coincide[0],
            ));
        }

        return $salida;
    }

    /** @return Collection<int, \stdClass> */
    public static function todas(): Collection
    {
        return DB::table('email_templates')
            ->orderBy('code')->orderBy('locale')->orderByDesc('effective_from')
            ->get([
                'id', 'uuid', 'code', 'locale', 'version', 'subject',
                'effective_from', 'effective_to', 'content_sha256',
            ]);
    }

    /**
     * La versión que **cubre hoy**, que no es lo mismo que la última.
     *
     * La primera versión de esto miraba `current_gate` —la columna puerta que
     * vale 1 cuando `effective_to IS NULL`— y estaba mal. La puerta identifica
     * *«la que no tiene fin»*, y eso es la **última publicada**, no la vigente.
     *
     * El caso que lo destapó: se publica la 2.0 para dentro de un mes. Eso cierra
     * la 1.0 el día antes, así que la 1.0 pierde la puerta y la 2.0 todavía no ha
     * empezado. Resultado: **ninguna vigente**, y el aviso no sale — durante un
     * mes, por haber programado el cambio con antelación.
     *
     * La puerta se queda porque hace bien su trabajo: garantiza una sola versión
     * abierta por (código, idioma). Pero la pregunta *«¿cuál rige hoy?»* se
     * contesta con el **periodo**, que es la lección de `T-21` y de las once
     * apariciones del error de un día. `effective_to` es **inclusivo**: una
     * versión que termina hoy todavía rige hoy.
     */
    private static function vigente(string $codigo, string $idioma): ?object
    {
        $hoy = now()->toDateString();

        return DB::table('email_templates')
            ->where('code', $codigo)
            ->where('locale', $idioma)
            ->whereDate('effective_from', '<=', $hoy)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $hoy))
            ->orderByDesc('effective_from')
            ->first();
    }
}
