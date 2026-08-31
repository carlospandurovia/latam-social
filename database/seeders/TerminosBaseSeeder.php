<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El texto de partida de los términos del creador (9.16).
 *
 * ### Por qué esto sí es una semilla, y en 3.5 no lo era
 *
 * `PublicarTerminosCommand` decía que sembrar unos términos de relleno sería
 * «un texto inventado por el equipo técnico convertido en lo que el creador
 * aceptó». El razonamiento era correcto en su mitad —nadie debe dar por
 * revisado lo que no lo está— y equivocado en la otra: la consecuencia era que
 * **el sistema no arrancaba**, y una configuración que impide arrancar no es
 * una cautela, es un defecto.
 *
 * La solución no es no sembrar: es sembrar **diciendo lo que es**. La versión
 * nace con `review_status = 'sin_revisar'`, el panel lo enseña en rojo, el
 * texto lleva sus marcas `[REVISAR]` y todo ello se edita desde la pantalla.
 * El sistema opera desde el primer día y nadie puede confundir ese texto con
 * uno revisado.
 *
 * No pisa nada: si ya hay una versión de ese código, no hace nada.
 */
final class TerminosBaseSeeder extends Seeder
{
    public function run(): void
    {
        $codigo = (string) config('latam.terminos.creador', 'creator_terms');

        if (DB::table('terms_versions')->where('code', $codigo)->exists()) {
            return;
        }

        // El texto viaja CON la aplicacion, no en `docs/`.
        //
        // La primera version leia de `docs/legal/`, que no existe junto a la
        // aplicacion en todos los entornos: la semilla se iba por su camino de
        // respaldo y sembraba 192 caracteres en vez de los terminos completos,
        // sin fallar y sin avisar. Se vio midiendo el largo, no leyendo.
        $ruta = database_path('seeders/textos/terminos-creador-2026.1.md');
        $completo = is_file($ruta);
        $cuerpo = $completo ? (string) file_get_contents($ruta) : self::textoMinimo();

        // Publicar exige responsable (`ck_terms_publicada`). Sin usuarios
        // todavia, se deja el borrador: la pantalla lo publica en un clic.
        $autor = DB::table('users')->orderBy('id')->value('id');

        DB::table('terms_versions')->insert([
            'uuid' => (string) Str::uuid(),
            'audience' => 'creator',
            'code' => $codigo,
            'version' => '2026.1',
            'title' => 'Términos y Condiciones para Creadores',
            'body' => $cuerpo,
            'content_sha256' => hash('sha256', $cuerpo),
            'effective_from' => now()->toDateString(),
            'published_at' => $autor === null ? null : now(),
            'published_by_user_id' => $autor,
            // Lo que hace honesto sembrarlo.
            'review_status' => 'sin_revisar',
            // Si el archivo faltara, la nota LO DICE. Degradar en silencio es
            // lo que se acaba de arreglar.
            'review_note' => $completo
                ? 'Borrador base generado por el sistema. Pendiente de revisión jurídica.'
                : 'NO se encontró el texto base: se sembró un texto mínimo. Redáctelo desde el panel.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Si el archivo no está, el sistema sigue arrancando y lo dice. */
    private static function textoMinimo(): string
    {
        return "# Términos y Condiciones para Creadores\n\n"
            ."**Versión de partida, pendiente de redacción y de revisión jurídica.**\n\n"
            .'Este texto se edita desde el panel de administración, sección Términos.';
    }
}
