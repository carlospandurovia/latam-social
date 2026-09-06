<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los marcadores de una página, y de dónde sale cada uno (L-2b).
 *
 * ### Para qué existe
 *
 * Lo pidió el negocio con estas palabras: *«será un modelo profesional y listo
 * para usar, sólo reemplazado con los valores que se leerán desde el admin,
 * empresa, razón social, y todo lo que aplique»*.
 *
 * Es decir: el texto legal se escribe **una vez**, y los datos de la empresa
 * salen de donde ya viven. Escribir «Soluciones Tecnológicas a Medida S.A.C.»
 * dentro del cuerpo de una política de privacidad sería `DEC-190` roto en el
 * peor sitio posible: el día que cambie la razón social —o el día que otra marca
 * use esta plataforma— habría que editar a mano un documento legal buscando
 * dónde se nombra a la empresa.
 *
 * ### Los cuatro orígenes
 *
 * | Prefijo | De dónde sale |
 * |---|---|
 * | `marca.` | `platform_brands` — cómo nos llamamos |
 * | `empresa.` | `legal_entities`, la **sociedad operadora** declarada en Sitio público |
 * | `sitio.` | `site_settings` — cómo nos contactan |
 * | `pagina.` | La propia página: su título y desde cuándo rige esta versión |
 *
 * ### Qué pasa con un marcador que no se puede resolver
 *
 * **No sale entre llaves.** Un `{{empresa.razon_social}}` visible en
 * `latamsocial.com/politica-de-privacidad` es peor que cualquier otra cosa: dice
 * que el documento está a medio hacer.
 *
 * Sale una raya (`—`) y **el área de configuración avisa en rojo** nombrando la
 * página y el marcador que falta. El documento se sigue pudiendo leer, y quien
 * lo tiene que arreglar se entera. Es `DEC-190`: nada bloquea, todo se dice.
 *
 * ### Y por qué esto no es una plantilla de Blade
 *
 * Porque el texto lo escribe una persona desde el panel. Pasar contenido de la
 * base por el motor de plantillas es ejecución de código desde la base de datos,
 * que es la puerta de atrás más grande que se puede dejar abierta. Aquí sólo se
 * sustituye texto por texto: no hay condicionales, no hay bucles y no hay forma
 * de que un marcador ejecute nada.
 */
final class Reemplazos
{
    /**
     * Lo que cada marcador significa, para enseñarlo en el editor.
     *
     * Vive aquí y no en la plantilla del admin porque **es la misma lista que
     * resuelve `valores()`**: dos listas separadas es cómo se llega a un editor
     * que ofrece un marcador que nadie sustituye.
     *
     * @var array<string, string>
     */
    public const CATALOGO = [
        'marca.nombre' => 'El nombre comercial de la plataforma',
        'marca.web' => 'La dirección web de la marca',
        'marca.correo_soporte' => 'El correo de soporte',
        'empresa.razon_social' => 'La razón social de la sociedad operadora',
        'empresa.documento' => 'Su identificador fiscal, con el tipo delante (RUC 20…)',
        'empresa.tipo_documento' => 'Sólo el tipo: RUC, NIT, RFC…',
        'empresa.numero_documento' => 'Sólo el número',
        'empresa.domicilio' => 'El domicilio completo, en una línea',
        'empresa.ciudad' => 'La ciudad',
        'empresa.pais' => 'El país',
        'sitio.correo' => 'El correo de contacto público',
        'sitio.telefono' => 'El teléfono público',
        'sitio.whatsapp' => 'El número de WhatsApp',
        'sitio.direccion' => 'La dirección que se enseña en el sitio',
        'pagina.titulo' => 'El título de esta página',
        'pagina.vigente_desde' => 'Desde cuándo rige esta versión',
    ];

    /** Lo que se pinta cuando un marcador no se puede resolver. */
    public const SIN_VALOR = '—';

    /**
     * Lo que siembra `CimientosSeeder` donde nadie puede inventar el dato.
     *
     * Un marcador que se resuelve no es lo mismo que un marcador que se
     * resuelve **bien**: el domicilio de fábrica dice «Por completar, Perú» y
     * eso sale en la política de privacidad publicada y —desde la `L-6`— en lo
     * que leen los buscadores.
     *
     * Vive aquí y no en cada consumidor porque ya iba por tres sitios, y una
     * regla escrita en tres sitios es una regla que el cuarto no tiene.
     */
    public const DE_FABRICA = 'por completar';

    /** Reconoce `{{ marca.nombre }}` con o sin espacios. */
    private const PATRON = '/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/';

    /**
     * Sustituye los marcadores de un texto.
     *
     * @param array<string, string> $extra Lo que sólo sabe quien llama —los de
     *                                     `pagina.`—, porque dependen de la
     *                                     versión que se está pintando.
     */
    public static function aplicar(string $texto, array $extra = []): string
    {
        return self::conValores($texto, self::valores() + $extra);
    }

    /**
     * Lo mismo, pero con la tabla de valores ya resuelta.
     *
     * Existe para quien sustituye MUCHOS textos de una vez —la portada tiene
     * sesenta entre encabezados, bajadas y bloques— y no puede permitirse que
     * cada uno vuelva a preguntar por la sociedad operadora: serían sesenta
     * consultas para pintar una página que mira quien todavía no es cliente.
     *
     * La alternativa habría sido que `valores()` recordara lo leído en una
     * propiedad estática, y eso es justo lo que `T-90` dice que no hay que
     * volver a hacer: una memoria que sobrevive a la petición sólo en las
     * pruebas. Un parámetro no sobrevive a nada.
     *
     * @param array<string, string> $valores
     */
    public static function conValores(string $texto, array $valores): string
    {
        return (string) preg_replace_callback(
            self::PATRON,
            static fn (array $c): string => $valores[$c[1]] ?? self::SIN_VALOR,
            $texto,
        );
    }

    /**
     * Los marcadores de un texto que **no se pueden resolver**.
     *
     * @param array<string, string> $extra
     * @return list<string>
     */
    public static function sinResolver(string $texto, array $extra = []): array
    {
        $valores = self::valores() + $extra;
        preg_match_all(self::PATRON, $texto, $encontrados);

        $faltan = [];

        foreach ($encontrados[1] as $marcador) {
            if (!isset($valores[$marcador]) && !in_array($marcador, $faltan, true)) {
                $faltan[] = $marcador;
            }
        }

        return $faltan;
    }

    /**
     * Todo lo que se puede sustituir hoy. Sin las claves que no tienen valor:
     * `sinResolver()` se apoya en que **ausente y vacío son lo mismo**.
     *
     * @return array<string, string>
     */
    public static function valores(): array
    {
        $marca = Marca::datos();
        $sitio = Sitio::datos();
        $empresa = self::sociedadOperadora($sitio['sociedadId']);

        $valores = [
            'marca.nombre' => $marca['nombre'],
            'marca.web' => $marca['web'],
            'marca.correo_soporte' => $marca['correoSoporte'],
            'sitio.correo' => $sitio['correo'],
            'sitio.telefono' => $sitio['telefono'],
            'sitio.whatsapp' => $sitio['whatsapp'],
            'sitio.direccion' => $sitio['direccion'],
        ];

        if ($empresa !== null) {
            $valores += [
                'empresa.razon_social' => (string) $empresa->legal_name,
                'empresa.tipo_documento' => (string) $empresa->tax_id_type,
                'empresa.numero_documento' => (string) $empresa->tax_id_number,
                'empresa.documento' => trim($empresa->tax_id_type.' '.$empresa->tax_id_number),
                'empresa.domicilio' => self::domicilio($empresa),
                'empresa.ciudad' => (string) $empresa->city,
                'empresa.pais' => (string) ($empresa->pais ?? ''),
            ];
        }

        // Un valor vacio es un valor que NO hay: si se dejara, un documento
        // diria «El responsable es » y nadie lo notaria en el panel.
        return array_filter(
            array_map(static fn (mixed $v): string => trim((string) $v), $valores),
            static fn (string $v): bool => $v !== '',
        );
    }

    private static function sociedadOperadora(?int $id): ?object
    {
        if ($id === null || !Schema::hasTable('legal_entities')) {
            return null;
        }

        return DB::table('legal_entities as le')
            ->leftJoin('countries as c', 'c.id', '=', 'le.country_id')
            ->where('le.id', $id)
            ->first(['le.legal_name', 'le.tax_id_type', 'le.tax_id_number', 'le.address_line1',
                'le.address_line2', 'le.district', 'le.city', 'le.region', 'c.name as pais']);
    }

    /**
     * El domicilio en una línea, sin comas huérfanas.
     *
     * Se arma aquí y no en el texto legal porque **las piezas cambian por país**:
     * `9.17c` añadió el distrito porque el comprobante peruano lo lleva, y una
     * sociedad colombiana no tiene ninguno. Un documento que escriba las cuatro
     * partes a mano sale con «Lima, , , Perú» en cuanto falte una.
     */
    private static function domicilio(object $empresa): string
    {
        $partes = array_filter([
            trim((string) ($empresa->address_line1 ?? '')),
            trim((string) ($empresa->address_line2 ?? '')),
            trim((string) ($empresa->district ?? '')),
            trim((string) ($empresa->city ?? '')),
            trim((string) ($empresa->region ?? '')),
            trim((string) ($empresa->pais ?? '')),
        ], static fn (string $p): bool => $p !== '');

        // Sin repetir: en Lima, `city` y `region` valen las dos «LIMA», y
        // «LIMA, LIMA» en una politica de privacidad se lee como un error.
        return implode(', ', array_values(array_unique($partes)));
    }

    /** ¿Este valor sigue siendo el de partida y no el de verdad? */
    public static function esDeFabrica(?string $valor): bool
    {
        return $valor !== null && mb_stripos($valor, self::DE_FABRICA) !== false;
    }
}
