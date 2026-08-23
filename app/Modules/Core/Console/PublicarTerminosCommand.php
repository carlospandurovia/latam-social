<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Publica una versión de los términos y condiciones (iteración 3.5, DEC-059).
 *
 * **Por qué es un comando y no una semilla.** Sería cómodo dejar unos términos
 * de relleno en `CimientosSeeder` para que la puerta de activación no se
 * quedara bloqueada. Sería también un texto inventado por el equipo técnico
 * convertido en «lo que el creador aceptó», y eso es precisamente lo que
 * `docs/00` §56 prohíbe: no se implementan supuestos legales sin que alguien
 * los revise. Mientras no haya un texto real revisado, la pantalla de
 * activación dice que faltan los términos, que es la verdad.
 *
 * **Por qué tampoco es una pantalla.** Publicar términos es un acto que ocurre
 * dos o tres veces en la vida del producto y lo hace quien tiene el documento
 * legal delante. Una pantalla para eso es superficie de ataque a cambio de
 * nada; cuando exista el portal del creador y haya que versionar seguido, se
 * hará entonces.
 *
 *   php artisan terminos:publicar creator_terms 2026.1 \
 *       --titulo="Términos del creador" --archivo=docs/legal/terminos-creador-2026.1.md
 *
 * Cierra la versión anterior con `effective_to` y abre la nueva. A partir de
 * ese momento las aceptaciones de la versión vieja dejan de estar vigentes
 * solas, sin revocar nada.
 */
final class PublicarTerminosCommand extends Command
{
    protected $signature = 'terminos:publicar
        {codigo : Codigo del documento, p.ej. creator_terms}
        {version : Etiqueta de version, p.ej. 2026.1}
        {--titulo= : Titulo legible}
        {--archivo= : Ruta al texto integro (md, txt o html)}
        {--publico=creator : creator o client}
        {--desde= : Fecha de entrada en vigor (por defecto, hoy)}';

    protected $description = 'Publica una version de los terminos y cierra la anterior.';

    public function handle(): int
    {
        // `argument()` y `option()` devuelven `array|string|bool|null` segun como
        // este declarada la firma. Se normalizan aqui una vez en vez de sembrar
        // castings por todo el metodo.
        $codigo = self::texto($this->argument('codigo'));
        $version = self::texto($this->argument('version'));
        $publico = self::texto($this->option('publico'));
        $archivo = self::texto($this->option('archivo'));

        if (!in_array($publico, ['creator', 'client'], true)) {
            $this->error('El publico debe ser «creator» o «client».');

            return self::FAILURE;
        }

        if ($archivo === '' || !is_file($archivo)) {
            $this->error('Falta --archivo con el texto de los terminos, o la ruta no existe.');

            return self::FAILURE;
        }

        $texto = (string) file_get_contents($archivo);

        if (trim($texto) === '') {
            $this->error('El archivo esta vacio. Unos terminos sin texto no se le pueden oponer a nadie.');

            return self::FAILURE;
        }

        if (DB::table('terms_versions')->where('code', $codigo)->where('version', $version)->exists()) {
            $this->error("La version {$version} de «{$codigo}» ya existe. Una version publicada no se reescribe.");

            return self::FAILURE;
        }

        $desde = self::texto($this->option('desde')) ?: now()->toDateString();
        $titulo = self::texto($this->option('titulo')) ?: $codigo.' '.$version;

        DB::transaction(function () use ($codigo, $version, $publico, $texto, $desde, $titulo): void {
            // Cerrar la vigente ANTES de abrir la nueva: `uq_terms_versions_current`
            // solo admite una fila con `effective_to IS NULL` por codigo, asi
            // que si esto se hiciera al reves la base lo rechazaria. Es la
            // restriccion haciendo de guardarrail del orden correcto.
            DB::table('terms_versions')
                ->where('code', $codigo)
                ->whereNull('effective_to')
                ->update(['effective_to' => $desde, 'updated_at' => now()]);

            DB::table('terms_versions')->insert([
                'uuid' => (string) Str::uuid(),
                'audience' => $publico,
                'code' => $codigo,
                'version' => $version,
                'title' => mb_substr($titulo, 0, 160),
                'body' => $texto,
                'content_sha256' => hash('sha256', $texto),
                'effective_from' => $desde,
                'published_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->info("Publicada la version {$version} de «{$codigo}» con vigencia desde {$desde}.");
        $this->line('Huella sha256: '.hash('sha256', $texto));
        $this->warn('Las aceptaciones de versiones anteriores dejan de estar vigentes: '
            .'los creadores activos siguen activos, pero la puerta de activacion pedira esta version a los nuevos.');

        return self::SUCCESS;
    }

    /** Normaliza lo que devuelven `argument()` y `option()` a una cadena. */
    private static function texto(mixed $valor): string
    {
        return is_string($valor) ? trim($valor) : '';
    }
}
