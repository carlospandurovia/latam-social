<?php

declare(strict_types=1);

namespace App\Shared\Texto;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Throwable;

/**
 * Markdown a HTML, con el HTML de dentro **escapado** (L-2b).
 *
 * ### Por qué existe esta clase y no una llamada suelta
 *
 * Porque las opciones son la mitad de la seguridad, y una llamada suelta es una
 * llamada a la que un día se le olvidan.
 *
 * El texto que pasa por aquí lo escribe una persona desde el panel y lo lee
 * cualquiera en `latamsocial.com`. Con las opciones por defecto, CommonMark
 * **deja pasar el HTML crudo**: un `<script>` escrito en el editor de una página
 * se ejecutaría en el navegador de cada visitante. Eso es XSS almacenado en la
 * página más pública del sitio, y basta con que alguien le robe la sesión a
 * quien edita.
 *
 * - `html_input: escape` — el HTML de dentro se **enseña**, no se ejecuta.
 * - `allow_unsafe_links: false` — nada de `javascript:` en un enlace.
 *
 * ### Y por qué hace falta la extensión de tablas
 *
 * Porque **CommonMark no lleva tablas**: son una extensión de GitHub, no del
 * estándar. Se descubrió mirando la política de privacidad publicada: la tabla
 * de «para qué usamos los datos y con qué legitimación» salía como un párrafo de
 * texto lleno de barras verticales. Un documento legal con una tabla rota se lee
 * como un documento a medio hacer, y esa tabla es justo la parte que alguien
 * consulta.
 *
 * Se añade **sólo** `TableExtension` y no el paquete entero de GitHub: los
 * enlaces automáticos y las menciones de `@usuario` no tienen nada que hacer en
 * una página legal, y cada extensión es superficie.
 *
 * ### Y si el conversor revienta
 *
 * Devuelve el texto escapado dentro de un `<pre>`. Una página legal que se ve
 * fea sigue diciendo lo que dice; una que devuelve 500 no dice nada, y es
 * justamente la que alguien está intentando leer cuando pasa algo.
 */
final class Marcado
{
    public static function aHtml(string $markdown): string
    {
        try {
            $entorno = new Environment([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
                'max_nesting_level' => 50,
            ]);
            $entorno->addExtension(new CommonMarkCoreExtension);
            $entorno->addExtension(new TableExtension);

            return (new MarkdownConverter($entorno))->convert($markdown)->getContent();
        } catch (Throwable) {
            return '<pre>'.e($markdown).'</pre>';
        }
    }
}
