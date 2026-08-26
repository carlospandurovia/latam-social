<?php

declare(strict_types=1);

namespace App\Modules\Communication\Console;

use App\Modules\Communication\Services\Plantillas;
use Illuminate\Console\Command;
use Throwable;

/**
 * Publica una versión de una plantilla de correo desde dos archivos (4.9).
 *
 * ### Por qué desde archivos y no por pantalla
 *
 * Porque el texto de un correo que se le manda a 150 creadores lo escribe
 * alguien con cuidado, se revisa, y se versiona en el repositorio como cualquier
 * otro texto legal. La pantalla de edición llega cuando el negocio pida cambiar
 * un asunto sin desplegar; hasta entonces, esto.
 *
 * El asunto va en la primera línea del archivo del cuerpo, separado por una
 * línea en blanco — el mismo formato que un correo de verdad. Dos archivos para
 * dos cosas que van juntas era una invitación a publicar uno y olvidar el otro.
 *
 * ### La opción se llama `--etiqueta` y no `--version`
 *
 * Porque **Symfony Console ya define `--version`** para toda la aplicación, y
 * declararla aquí revienta con *«An option named "version" already exists»*. No
 * era un aviso de estilo: el comando no se podía ni registrar. Lo cazó PHPStan
 * antes de que nadie lo ejecutara.
 */
final class PublicarPlantillaCommand extends Command
{
    protected $signature = 'correos:publicar
        {codigo : El codigo estable del aviso, por ejemplo creator.tax_profile_changed}
        {archivo : Ruta al archivo con el asunto en la primera linea y el cuerpo debajo}
        {--idioma=es : El idioma de esta version}
        {--etiqueta= : La etiqueta de version. Por omision, la fecha}
        {--desde= : Desde que dia esta vigente. Por omision, hoy}';

    protected $description = 'Publica una version de una plantilla de correo, cerrando la anterior el dia antes.';

    public function handle(): int
    {
        $archivo = (string) $this->argument('archivo');

        if (!is_file($archivo)) {
            $this->error("No existe el archivo {$archivo}.");

            return self::FAILURE;
        }

        $contenido = (string) file_get_contents($archivo);
        $partes = preg_split("/\r?\n\r?\n/", $contenido, 2);

        if ($partes === false || count($partes) < 2 || trim($partes[0]) === '' || trim($partes[1]) === '') {
            $this->error('El archivo tiene que llevar el ASUNTO en la primera linea, una linea en blanco, y el cuerpo debajo.');

            return self::FAILURE;
        }

        [$asunto, $cuerpo] = [trim($partes[0]), trim($partes[1])];
        $desde = (string) ($this->option('desde') ?: now()->toDateString());
        $version = (string) ($this->option('etiqueta') ?: $desde);
        $idioma = (string) $this->option('idioma');

        // Las variables se DEDUCEN del texto, no se piden. Pedirlas aparte
        // garantiza que un dia la lista y el texto digan cosas distintas.
        preg_match_all('/\{\{\s*([A-Za-z_][\w.]*)\s*\}\}/', $asunto."\n".$cuerpo, $c);
        $variables = array_values(array_unique($c[1]));

        try {
            $id = Plantillas::publicar(
                codigo: (string) $this->argument('codigo'),
                idioma: $idioma,
                version: $version,
                asunto: $asunto,
                cuerpo: $cuerpo,
                desde: $desde,
                variables: $variables,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Plantilla «%s» (%s) publicada como version %s, vigente desde el %s.',
            $this->argument('codigo'), $idioma, $version, $desde));
        $this->line('  Asunto:    '.$asunto);
        $this->line('  Variables: '.($variables === [] ? '(ninguna)' : implode(', ', $variables)));
        $this->line('  Id:        '.$id);
        $this->line('');
        $this->line('  La version anterior, si la habia, quedo cerrada el dia ANTES: `valid_to` es');
        $this->line('  inclusivo y cerrarla el mismo dia dejaria dos vigentes a la vez.');

        return self::SUCCESS;
    }
}
