<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Audit\Bitacora;
use App\Shared\Config\Aviso;
use App\Shared\Config\Instalacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los datos que se pintan en la calle (L-2a).
 *
 * ### Qué es esto y qué no
 *
 * `Marca` contesta *«cómo nos llamamos y de qué color somos»*. Esto contesta
 * *«cómo nos contactan y qué se enseña en la portada»*. Son preguntas distintas
 * y por eso son tablas distintas.
 *
 * ### La memoria, y por qué se puede olvidar
 *
 * Igual que `Marca`: esto lo pide **cada petición pública** y a veces más de una
 * vez en la misma página —el WhatsApp sale en el héroe y otra vez en el cierre—.
 * Se lee una vez por petición. `olvidar()` existe para las pruebas y para
 * después de guardar, que es cuando la memoria mentiría.
 *
 * ### Sin fila no hay error
 *
 * Una instalación recién migrada no tiene fila, y eso **no puede ser un 500 en
 * la portada**. `datos()` devuelve la forma completa con todo a `null` y quien
 * pinta decide. Misma regla que `Landing::portada()` en `9.21b`.
 */
final class Sitio
{
    /**
     * Los medidores de visitas que sabe emitir `parciales/analitica` (L-5).
     *
     * Es un enum de **código** y no un catálogo: cada uno tiene su fragmento, y
     * uno inventado desde el panel sería una fila perfectamente válida que
     * ninguna plantilla sabe dibujar —el criterio de `DEC-026`—.
     *
     * @var array<string, string>
     */
    public const MEDIDORES = [
        'ga4' => 'Google Analytics 4 — identificador G-XXXXXXX',
        'gtm' => 'Google Tag Manager — contenedor GTM-XXXXXXX',
        'meta' => 'Meta Pixel — identificador numérico',
        'plausible' => 'Plausible — el dominio, sin https://',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $memoria = null;

    /**
     * Lo que se pinta en la calle.
     *
     * @return array{whatsapp: ?string, whatsappUrl: ?string, mensajeWhatsapp: ?string, correo: ?string, telefono: ?string, direccion: ?string, sociedadId: ?int, paisPorDefecto: ?int, medidor: ?string, medidorId: ?string, configurado: bool}
     */
    public static function datos(): array
    {
        if (self::$memoria !== null) {
            return self::$memoria;
        }

        $fila = self::fila();
        $telefono = self::texto($fila->whatsapp_phone ?? null);
        $mensaje = self::texto($fila->whatsapp_message ?? null);

        return self::$memoria = [
            'whatsapp' => $telefono,
            // Se arma AQUI y no en la plantilla: pegar un numero y un texto
            // dentro de una URL es codificar, no maquetar, y hacerlo en un
            // `.blade.php` es como se acaba con un enlace que no abre nada
            // porque el mensaje llevaba un `&`.
            'whatsappUrl' => self::enlace($telefono, $mensaje),
            'mensajeWhatsapp' => $mensaje,
            'correo' => self::texto($fila->contact_email ?? null),
            'telefono' => self::texto($fila->contact_phone ?? null),
            'direccion' => self::texto($fila->public_address ?? null),
            'sociedadId' => isset($fila->operator_legal_entity_id)
                ? (int) $fila->operator_legal_entity_id : null,
            // L-5 (`C-2`): el pais que sale marcado en los formularios de la
            // calle. `null` significa «el de la sociedad operadora», que se
            // resuelve en `paisPorDefecto()`: no es una constante, es un dato
            // que ya existe y que ya esta bien.
            'paisPorDefecto' => isset($fila->default_country_id)
                ? (int) $fila->default_country_id : null,
            'medidor' => self::texto($fila->analytics_provider ?? null),
            'medidorId' => self::texto($fila->analytics_id ?? null),
            'configurado' => $fila !== null,
        ];
    }

    /**
     * El país que sale marcado en los formularios públicos (L-5, `C-2`).
     *
     * ### Por qué esto no es «Perú»
     *
     * Porque el sistema es white label y el segundo operador puede estar en
     * Colombia. Pero tampoco puede ser «el primero de la lista», que es lo que
     * era: la lista va por nombre y el primero resultaba ser **Chile**, así que
     * un negocio que arranca en Perú etiquetaba mal sus propios leads en
     * silencio desde el primer día. Y el país de un lead no es un adorno: decide
     * el mercado, la moneda y qué comprobante se emite.
     *
     * La regla, en una frase: **el que se haya configurado; si no, el de la
     * sociedad operadora**. La reserva no es una constante escrita en el código:
     * es un dato que ya existe, que ya está bien y que ya se administra.
     *
     * Devuelve `null` sólo si tampoco hay sociedad operadora —una instalación
     * recién migrada—, y entonces el formulario cae en el primero de la lista,
     * que es lo que había antes. Nada bloquea (`DEC-190`).
     */
    public static function paisPorDefecto(): ?int
    {
        $datos = self::datos();

        if ($datos['paisPorDefecto'] !== null) {
            return $datos['paisPorDefecto'];
        }

        if ($datos['sociedadId'] === null || !Schema::hasTable('legal_entities')) {
            return null;
        }

        $pais = DB::table('legal_entities')
            ->where('id', $datos['sociedadId'])->value('country_id');

        return $pais === null ? null : (int) $pais;
    }

    /**
     * La medición: qué proveedor, con qué identificador y **si se emite aquí**.
     *
     * La tercera es la que importa y por eso la decide el servicio y no la
     * plantilla. Se restaura un volcado de producción en el servidor de pruebas
     * —cosa que se hace todas las semanas— y ese volcado trae dentro el
     * identificador bueno de la propiedad, así que cada clic de una prueba se
     * cuenta como una visita real. **No rompe nada, y por eso nadie lo nota:**
     * los números simplemente dejan de significar algo. Es el mismo agujero que
     * `9.22b` cerró para el correo, y se cierra con la misma llave.
     *
     * @return array{proveedor: ?string, id: ?string, emite: bool}
     */
    public static function medicion(): array
    {
        $datos = self::datos();
        $configurada = $datos['medidor'] !== null && $datos['medidorId'] !== null;

        return [
            'proveedor' => $datos['medidor'],
            'id' => $datos['medidorId'],
            'emite' => $configurada && Instalacion::esProduccion(),
        ];
    }

    /**
     * Los países para un desplegable público, **con el de por defecto primero**.
     *
     * El orden importa tanto como la marca: un desplegable que abre en el país
     * correcto pero lo tiene en la posición catorce sigue invitando a que
     * alguien elija otro sin querer.
     *
     * @return Collection<int, \stdClass>
     */
    public static function paisesParaFormulario(): Collection
    {
        $porDefecto = self::paisPorDefecto();

        return DB::table('countries')->where('is_active', 1)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$porDefecto ?? 0])
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * La URL de WhatsApp, ya codificada, o `null` si falta el número.
     *
     * Sin número no hay enlace, aunque haya mensaje: un `wa.me` sin destinatario
     * abre la aplicación en blanco, y quien lo pulsa cree que ha escrito.
     */
    public static function enlace(?string $telefono, ?string $mensaje): ?string
    {
        if ($telefono === null) {
            return null;
        }

        // `wa.me` quiere el numero SIN el `+`.
        $url = 'https://wa.me/'.ltrim($telefono, '+');

        return $mensaje === null ? $url : $url.'?text='.rawurlencode($mensaje);
    }

    /**
     * Las redes visibles, en su orden.
     *
     * @return Collection<int, \stdClass>
     */
    public static function redes(bool $soloVisibles = true): Collection
    {
        if (!Schema::hasTable('social_links')) {
            return collect();
        }

        $marcaId = Marca::idActual();

        if ($marcaId === null) {
            return collect();
        }

        $consulta = DB::table('social_links')->where('platform_brand_id', $marcaId);

        if ($soloVisibles) {
            $consulta->where('is_visible', true);
        }

        return $consulta->orderBy('sort_order')->orderBy('network')
            ->get(['id', 'network', 'label', 'url', 'sort_order', 'is_visible']);
    }

    /** @param array<string, mixed> $datos */
    public static function guardar(array $datos, int $usuarioId): void
    {
        $marcaId = Marca::idActual();

        if ($marcaId === null) {
            return;
        }

        $antes = (array) (self::fila() ?? new \stdClass);

        DB::table('site_settings')->updateOrInsert(
            ['platform_brand_id' => $marcaId],
            $datos + ['updated_at' => now(), 'created_at' => now()],
        );

        self::olvidar();

        Bitacora::registrar('site_settings.updated', 'platform_brand', $marcaId,
            Bitacora::diferencias($antes, $datos));
    }

    /** @param array<string, mixed> $datos */
    public static function guardarRed(?int $id, array $datos, int $usuarioId): void
    {
        $marcaId = Marca::idActual();

        if ($marcaId === null) {
            return;
        }

        if ($id === null) {
            DB::table('social_links')->insert($datos + [
                'platform_brand_id' => $marcaId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            // El `where` de la marca no sobra: sin el, un id de otra marca se
            // podria editar tecleando su numero en la URL.
            DB::table('social_links')->where('id', $id)->where('platform_brand_id', $marcaId)
                ->update($datos + ['updated_at' => now()]);
        }

        Bitacora::registrar('social_link.saved', 'platform_brand', $marcaId,
            ['red' => ['antes' => null, 'despues' => (string) ($datos['network'] ?? '')]]);
    }

    public static function borrarRed(int $id): void
    {
        $marcaId = Marca::idActual();

        if ($marcaId === null) {
            return;
        }

        // Un enlace a una red NO es informacion financiera: se borra de verdad.
        // La regla de no borrar es de lo que hay que poder defender ante un
        // tercero, y el Instagram de la empresa no lo es.
        DB::table('social_links')->where('id', $id)->where('platform_brand_id', $marcaId)->delete();

        Bitacora::registrar('social_link.deleted', 'platform_brand', $marcaId);
    }

    /** Sólo para las pruebas y para después de guardar. */
    public static function olvidar(): void
    {
        self::$memoria = null;
    }

    /**
     * Lo que falta, con prioridad y sin bloquear nada (`DEC-190`).
     *
     * @return list<Aviso>
     */
    public static function avisos(): array
    {
        if (!Schema::hasTable('site_settings')) {
            return [];
        }

        $datos = self::datos();
        $avisos = [];

        // Rojo: sin sociedad operadora, los textos legales no pueden nombrar a
        // NADIE. Una politica de privacidad sin responsable es un documento sin
        // valor, y lo ve un tercero.
        if ($datos['sociedadId'] === null) {
            $avisos[] = Aviso::rojo(
                'No está declarada la sociedad que opera la marca. De ahí salen la razón social, '
                .'el identificador fiscal y el domicilio de la política de privacidad y de los '
                .'términos, así que mientras falte esos documentos no pueden nombrar a nadie.',
            );
        }

        // Rojo: el pie de la portada ensena un correo de contacto, y sin el
        // queda un hueco en la unica pagina que ve quien todavia no es cliente.
        if ($datos['correo'] === null) {
            $avisos[] = Aviso::rojo(
                'No hay correo de contacto público. Es el que sale en el pie de la portada y en los '
                .'textos legales como vía para ejercer derechos sobre los datos personales.',
            );
        }

        if ($datos['whatsapp'] === null) {
            $avisos[] = Aviso::ambar(
                'No hay número de WhatsApp. Es el canal de contacto de menos fricción de la portada; '
                .'mientras falte, el único camino es el formulario.',
            );
        } elseif ($datos['mensajeWhatsapp'] === null) {
            $avisos[] = Aviso::ambar(
                'El WhatsApp está puesto pero no tiene mensaje de arranque: la conversación empieza '
                .'en blanco y quien escribe tiene que explicarse solo.',
            );
        }

        if (self::redes()->isEmpty()) {
            $avisos[] = Aviso::ambar(
                'No hay ninguna red social publicada. En el pie de una portada de Creator Economy, '
                .'su ausencia se nota más que en cualquier otro sector.',
            );
        }

        // L-5 (`C-2`): sin pais por defecto, el desplegable abre en el primero
        // por orden alfabetico. Es ambar y no rojo porque hay una reserva --el
        // pais de la sociedad operadora-- y solo se queda sin ninguna si
        // tampoco hay sociedad, que ya sale en rojo mas arriba.
        if ($datos['paisPorDefecto'] === null && self::paisPorDefecto() === null) {
            $avisos[] = Aviso::ambar(
                'No hay país por defecto para los formularios de la calle. Mientras falte, el '
                .'desplegable abre en el primero por orden alfabético, y quien no se fije etiquetará '
                .'su lead en el país equivocado.',
            );
        }

        // L-5: NO hay aviso por la medicion, ni cuando falta ni cuando esta.
        //
        // Se escribieron los dos y los dos estaban mal, y lo dijo una prueba de
        // `L-2a` --«con todo puesto no queda ningun aviso»-- que se puso roja.
        // No medir es una decision legitima, no una configuracion a medias; y
        // la nota de privacidad de cuando SI se mide tampoco puede vivir aqui,
        // porque seria un ambar que no se apaga nunca. Es exactamente lo que
        // `DEC-282` corrigio en el correo: un aviso permanente acaba tapando los
        // que si hay que leer. Las dos cosas se dicen EN SU SITIO --dentro de la
        // seccion de medicion de la pantalla-- donde se leen cuando importan.

        return $avisos;
    }

    private static function fila(): ?object
    {
        if (!Schema::hasTable('site_settings')) {
            return null;
        }

        $marcaId = Marca::idActual();

        return $marcaId === null
            ? null
            : DB::table('site_settings')->where('platform_brand_id', $marcaId)->first();
    }

    /** Un texto que en la base está vacío es un texto que no hay. */
    private static function texto(mixed $valor): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        return $texto === '' ? null : $texto;
    }
}
