<?php

declare(strict_types=1);

namespace App\Shared\Config;

use Closure;
use Throwable;

/**
 * Qué falta por configurar, en un solo sitio (9.17b).
 *
 * ### El problema
 *
 * `9.16` y `9.17` dejaron cada una su lista de avisos con prioridad, y cada una
 * **sólo se ve entrando en su pantalla**. Los términos sin revisar salen en
 * Términos; la marca sin logotipo, en Marca; los países que no puede facturar
 * nadie, en Entidades legales. Para saber qué falta hay que recorrer seis
 * pantallas acordándose de las seis, que es exactamente lo que nadie hace.
 *
 * `DEC-190` pedía lo contrario: *«en las configuraciones del admin, ponme
 * prioridades y alguna nota en algún badge en rojo o amarillo, según la
 * importancia»*. Una sola pantalla que conteste «¿qué me falta?».
 *
 * ### Por qué un registro y no una clase que lo consulte todo
 *
 * Mismo motivo que el `Vigilante` de `9.15`. Un revisor central tendría que
 * consultar `platform_brands`, `terms_versions`, `legal_entity_countries`,
 * `fx_sources` y `email_log`, y eso pone a `Shared` a saber de cinco módulos.
 * `deptrac` **no lo vería**, porque son consultas a tablas y no clases
 * importadas: una frontera rota en silencio, que es la peor clase de frontera
 * rota.
 *
 * Así que **cada módulo declara las comprobaciones de su área** en su
 * `ServiceProvider`. Communication sabe de correo y nadie más tiene que saberlo.
 *
 * ### Cada área declara quién puede verla
 *
 * Un aviso dice lo que falta, y lo que falta es información de dentro. Quien
 * lleva finanzas no tiene por qué enterarse de que el texto de los términos no
 * lo ha revisado un abogado, ni al revés. El área declara el permiso con el que
 * se arregla y `revision()` filtra por él: **si no puedes arreglarlo, no lo
 * ves**, que además evita la frustración de un aviso que lleva a un 403.
 *
 * ### Una comprobación que revienta NO tumba el panel
 *
 * Es la regla que hace utilizable a esta pantalla. Un revisor que lanza una
 * excepción --una tabla que todavía no existe a mitad de un despliegue, una
 * consulta con un error-- se convierte en un aviso ámbar que lo dice, y las
 * otras cuatro áreas se siguen viendo. Un panel de «qué me falta» que responde
 * 500 porque a un área le pasa algo es peor que no tenerlo: deja de contestar
 * justo el día en que hay algo que contestar.
 */
final class Preparacion
{
    /**
     * Los grupos en que se reparte la configuración (9.20).
     *
     * Hasta `9.20` esto era una lista plana de nueve áreas, y el menú lateral
     * llevaba **las mismas nueve** sueltas entre las pantallas del día a día.
     * Estaban dos veces, y entrar desde aquí a una de ellas dejaba al usuario en
     * una pantalla que no decía de dónde venía. Ahora la configuración tiene
     * **una sola puerta** y por dentro está agrupada por la clase de pregunta
     * que contesta cada bloque.
     *
     * Son constantes y no una tabla a propósito: un grupo no es un dato de la
     * instalación, es cómo está organizado este programa. Añadir uno es escribir
     * una línea aquí, y así el orden en que salen no depende de que nadie
     * recuerde numerarlos.
     */
    public const IDENTIDAD = 'Identidad y textos';

    public const FISCAL = 'Fiscal y facturación';

    public const CONEXIONES = 'Conexiones';

    public const CATALOGOS = 'Catálogos';

    public const OTROS = 'Otros';

    /** En qué orden salen los grupos. Lo que se toca el primer día, arriba. */
    private const ORDEN_GRUPOS = [
        self::IDENTIDAD => 10,
        self::FISCAL => 20,
        self::CONEXIONES => 30,
        self::CATALOGOS => 40,
        self::OTROS => 90,
    ];

    /**
     * @var array<string, array{permiso: ?string, ruta: ?string, orden: int, grupo: string, revisor: Closure(): list<Aviso>}>
     */
    private static array $areas = [];

    /**
     * Declara las comprobaciones de un área.
     *
     * @param string $area Cómo se llama en la pantalla. «Marca», «Correo».
     * @param ?string $permiso El que hace falta para arreglarlo. `null` = lo ve
     *                         cualquiera que pueda abrir el panel.
     * @param ?string $ruta Nombre de la ruta donde se arregla. Un aviso sin
     *                      sitio al que ir es media ayuda.
     * @param int $orden Para empatar dentro del mismo nivel. Más bajo, antes.
     * @param string $grupo Una de las constantes de esta clase. Un grupo que no
     *                      existe se queda en «Otros» en vez de desaparecer: un
     *                      área invisible por una errata sería peor que una mal
     *                      colocada.
     * @param Closure(): list<Aviso> $revisor
     */
    public static function area(
        string $area,
        ?string $permiso,
        ?string $ruta,
        Closure $revisor,
        int $orden = 50,
        string $grupo = self::OTROS,
    ): void {
        self::$areas[$area] = [
            'permiso' => $permiso,
            'ruta' => $ruta,
            'orden' => $orden,
            'grupo' => array_key_exists($grupo, self::ORDEN_GRUPOS) ? $grupo : self::OTROS,
            'revisor' => $revisor,
        ];
    }

    /**
     * Pasa revista a las áreas que este usuario puede arreglar.
     *
     * Ordena por lo peor que tenga cada una --rojo, ámbar, verde-- y dentro del
     * mismo nivel por el orden declarado. Lo urgente arriba, sin que haya que
     * leerse la pantalla entera.
     *
     * @param Closure(string): bool $puede Contesta si el usuario tiene un permiso.
     *                                     Se recibe como parámetro y no se
     *                                     consulta aquí: `Shared` no conoce a
     *                                     `Permisos`, que vive en `Shared\Auth`
     *                                     pero depende de la base de roles.
     * @return list<array{area: string, ruta: ?string, grupo: string, nivel: string, avisos: list<Aviso>}>
     */
    public static function revision(Closure $puede): array
    {
        $revision = [];

        foreach (self::$areas as $area => $definicion) {
            if ($definicion['permiso'] !== null && !$puede($definicion['permiso'])) {
                continue;
            }

            $revision[] = [
                'area' => $area,
                'ruta' => $definicion['ruta'],
                'orden' => $definicion['orden'],
                'grupo' => $definicion['grupo'],
                'avisos' => self::pasarRevista($area, $definicion['revisor']),
            ];
        }

        $revision = array_map(static function (array $fila): array {
            $fila['nivel'] = self::peorNivel($fila['avisos']);

            return $fila;
        }, $revision);

        usort($revision, static function (array $a, array $b): int {
            $peso = [Aviso::ROJO => 0, Aviso::AMBAR => 1, Aviso::VERDE => 2];

            return [$peso[$a['nivel']], $a['orden'], $a['area']]
               <=> [$peso[$b['nivel']], $b['orden'], $b['area']];
        });

        return array_map(
            static fn (array $fila): array => [
                'area' => $fila['area'],
                'ruta' => $fila['ruta'],
                'grupo' => $fila['grupo'],
                'nivel' => $fila['nivel'],
                'avisos' => $fila['avisos'],
            ],
            $revision,
        );
    }

    /**
     * Cuántos avisos hay de cada nivel, para el encabezado.
     *
     * @param list<array{area: string, ruta: ?string, grupo: string, nivel: string, avisos: list<Aviso>}> $revision
     * @return array{rojo: int, ambar: int, areas: int, listas: int}
     */
    public static function recuento(array $revision): array
    {
        $rojo = 0;
        $ambar = 0;
        $listas = 0;

        foreach ($revision as $fila) {
            foreach ($fila['avisos'] as $aviso) {
                $aviso->nivel === Aviso::ROJO ? $rojo++ : $ambar++;
            }

            if ($fila['avisos'] === []) {
                $listas++;
            }
        }

        return ['rojo' => $rojo, 'ambar' => $ambar, 'areas' => count($revision), 'listas' => $listas];
    }

    /**
     * La misma revisión, repartida en grupos y en su orden (9.20).
     *
     * Se calcula aquí y no en la plantilla porque el orden de los grupos es una
     * decisión, no una presentación: la plantilla lo pintaría en el orden en que
     * le llegaran las áreas, que es el de la urgencia, y entonces «Fiscal»
     * saldría antes o después según qué falte hoy. Un sitio que cambia de forma
     * según el día no se aprende.
     *
     * Un grupo sin áreas visibles NO sale: quien no puede tocar nada de
     * «Fiscal y facturación» tampoco necesita ver el título.
     *
     * @param list<array{area: string, ruta: ?string, grupo: string, nivel: string, avisos: list<Aviso>}> $revision
     * @return list<array{grupo: string, areas: list<array{area: string, ruta: ?string, grupo: string, nivel: string, avisos: list<Aviso>}>}>
     */
    public static function porGrupos(array $revision): array
    {
        $grupos = [];

        foreach ($revision as $fila) {
            $grupos[$fila['grupo']][] = $fila;
        }

        uksort($grupos, static fn (string $a, string $b): int => (self::ORDEN_GRUPOS[$a] ?? 99) <=> (self::ORDEN_GRUPOS[$b] ?? 99));

        return array_map(
            static fn (string $grupo): array => ['grupo' => $grupo, 'areas' => $grupos[$grupo]],
            array_keys($grupos),
        );
    }

    /**
     * Las rutas de todo lo que es configuración.
     *
     * El menú lateral la usa para saber si la pantalla en la que estás cuelga de
     * Configuración —y dejar esa entrada encendida— sin tener que repetir la
     * lista. Repetirla es como estaban las cosas antes de `9.20`: nueve entradas
     * escritas dos veces, y añadir una décima exigía acordarse de los dos sitios.
     *
     * @return list<string>
     */
    public static function rutas(): array
    {
        $rutas = [];

        foreach (self::$areas as $definicion) {
            if ($definicion['ruta'] !== null) {
                $rutas[] = $definicion['ruta'];
            }
        }

        sort($rutas);

        /** @var list<string> $rutas */
        return $rutas;
    }

    /** @return list<string> */
    public static function areasRegistradas(): array
    {
        $areas = array_keys(self::$areas);
        sort($areas);

        /** @var list<string> $areas */
        return $areas;
    }

    /** Sólo para las pruebas: olvida las áreas registradas. */
    public static function olvidar(): void
    {
        self::$areas = [];
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * @param Closure(): list<Aviso> $revisor
     * @return list<Aviso>
     */
    private static function pasarRevista(string $area, Closure $revisor): array
    {
        try {
            return $revisor();
        } catch (Throwable $e) {
            // No se propaga. Un panel de «que me falta» que responde 500 porque
            // a un area le pasa algo deja de contestar justo el dia en que hay
            // algo que contestar. Se dice que esa comprobacion no pudo correr
            // --que es un dato-- y las demas se siguen viendo.
            return [Aviso::ambar(sprintf(
                'La comprobación de «%s» no se pudo ejecutar: %s. No significa que falte algo, '
                .'significa que hoy no se sabe.',
                $area,
                $e->getMessage(),
            ))];
        }
    }

    /** @param list<Aviso> $avisos */
    private static function peorNivel(array $avisos): string
    {
        foreach ($avisos as $aviso) {
            if ($aviso->nivel === Aviso::ROJO) {
                return Aviso::ROJO;
            }
        }

        return $avisos === [] ? Aviso::VERDE : Aviso::AMBAR;
    }
}
