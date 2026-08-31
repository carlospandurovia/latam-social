<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La marca deja de ser una constante (9.17).
 *
 * ### El problema
 *
 * «LATAM Social» estaba escrito **en la plantilla**: en el `<title>`, en la
 * barra lateral y en el favicon, que era un archivo del repositorio. Eso
 * contradice de plano lo que se pidió —*«esta es una plataforma white label,
 * todo configurable, hasta la razón social, el RUC, los datos del representante
 * legal, la dirección, el ubigeo»* (`DEC-190`)—: para poner otro nombre había
 * que editar Blade y desplegar.
 *
 * `platform_brands` existía desde 2.10 con `name`, `logo_file_id`,
 * `primary_color`, `legal_footer`, `website` y `support_email`, y **nadie la
 * leía**. La tabla estaba bien; lo que faltaba era que la aplicación se mirase
 * en ella.
 *
 * ### Qué se añade y por qué
 *
 * - `favicon_file_id`: el icono de la pestaña es 32×32 y el logotipo no lo es.
 *   Escalar un logotipo apaisado a un cuadrado da un borrón, así que son dos
 *   archivos. Si no hay favicon se cae al logotipo, y si tampoco, al del
 *   repositorio: **nunca falta**, que es la regla de `DEC-190`.
 * - `tagline`: la frase corta bajo el nombre. La pantalla de acceso tenía una
 *   escrita a mano.
 * - `secondary_color` y `sidebar_color`: el degradado de la marca usaba dos
 *   colores y sólo uno era configurable, y el azul de la barra lateral —el
 *   color que más superficie ocupa en toda la aplicación— no lo era en absoluto.
 * - `font_family`: la tipografía estaba escrita en la plantilla, con su enlace a
 *   un servidor de fuentes. Quien pone su marca pone su letra.
 * - `is_default`: cuál es **la** marca de la plataforma. Sin esto había que
 *   adivinar con `orderBy('id')->first()`, que es lo que hacía
 *   `EntidadesLegalesController` al dar de alta una sociedad.
 *
 * ### Una sola marca por defecto, y lo dice el motor
 *
 * `default_gate` es la vigesimoctava columna puerta del modelo: vale 1 cuando
 * `is_default` y NULL cuando no, y `uq_pb_default` la hace única. Dos marcas por
 * defecto no es un estado raro que se detecte tarde: es que la mitad de las
 * pantallas enseñe un nombre y la otra mitad otro. No se puede llegar a él.
 *
 * ### El `code` no se toca
 *
 * Es la llave con la que el sembrador encuentra la marca (`updateOrInsert`) y
 * con la que se la nombra en la bitácora. Cambiarlo haría que el siguiente
 * sembrado creara una marca nueva en vez de actualizar la que hay, y el sistema
 * amanecería con dos. El nombre visible —`name`— se cambia cuanto se quiera:
 * para eso es la pantalla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_brands', function (Blueprint $table): void {
            $table->string('tagline', 160)->nullable()->after('name');
            $table->unsignedBigInteger('favicon_file_id')->nullable()->after('logo_file_id');
            $table->char('secondary_color', 7)->nullable()->after('primary_color');
            $table->char('sidebar_color', 7)->nullable()->after('secondary_color');
            $table->string('font_family', 80)->nullable()->after('sidebar_color');
            $table->boolean('is_default')->default(false)->after('is_active');

            $table->index('favicon_file_id', 'ix_pb_favicon');
            $table->foreign('favicon_file_id', 'fk_pb_favicon')
                ->references('id')->on('files')->restrictOnDelete();
        });

        // La marca que ya existiera pasa a ser la de por defecto. Si no hay
        // ninguna no se hace nada: el sembrador la crea, y hasta entonces
        // `Marca::actual()` devuelve el respaldo. Que la base este vacia no
        // puede ser un error --`DEC-190`--, es el primer arranque.
        $primera = DB::table('platform_brands')->orderBy('id')->value('id');

        if ($primera !== null) {
            DB::table('platform_brands')->where('id', $primera)->update(['is_default' => true]);
        }

        DB::statement(
            'ALTER TABLE `platform_brands` ADD COLUMN `default_gate` TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN `is_default` = 1 THEN 1 ELSE NULL END) STORED',
        );
        DB::statement(
            'ALTER TABLE `platform_brands` ADD UNIQUE KEY `uq_pb_default` (`default_gate`)',
        );

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }

        // El `code` es la llave del sembrador. Si cambia, el siguiente
        // `db:seed` no encuentra la marca y crea otra: el sistema amanece con
        // dos, y `uq_pb_default` deja a la nueva sin ser la de por defecto,
        // asi que las pantallas siguen ensenando la vieja mientras alguien
        // edita la nueva. Un fallo silencioso perfecto; por eso se impide.
        DB::statement('DROP TRIGGER IF EXISTS `tg_pb_code`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `tg_pb_code`
            BEFORE UPDATE ON `platform_brands`
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.`code` <=> OLD.`code`) THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'El codigo de la marca no se cambia: cambie el nombre.';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS `tg_pb_code`');

        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        DB::statement('ALTER TABLE `platform_brands` DROP INDEX `uq_pb_default`');
        DB::statement('ALTER TABLE `platform_brands` DROP COLUMN `default_gate`');

        Schema::table('platform_brands', function (Blueprint $table): void {
            $table->dropForeign('fk_pb_favicon');
            $table->dropIndex('ix_pb_favicon');
            $table->dropColumn(['tagline', 'favicon_file_id', 'secondary_color',
                'sidebar_color', 'font_family', 'is_default']);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            // El mismo criterio que `ck_pb_color`, que ya existia para el
            // primario. Un color que no es un color deja el panel sin estilo y
            // no falla en ningun sitio: se ve raro y nadie sabe por que.
            ['platform_brands', 'ck_pb_color2',
                "secondary_color IS NULL OR secondary_color REGEXP '^#[0-9A-Fa-f]{6}$'",
                ['secondary_color'], 'El color secundario debe ser hexadecimal (#RRGGBB).'],

            // Un nombre en blanco no es «sin configurar»: es una barra lateral
            // vacia y un `<title>` que dice « · ». Sin configurar es la fila
            // que trae el sembrador, que si dice algo.
            ['platform_brands', 'ck_pb_barra',
                "sidebar_color IS NULL OR sidebar_color REGEXP '^#[0-9A-Fa-f]{6}$'",
                ['sidebar_color'], 'El color de la barra debe ser hexadecimal (#RRGGBB).'],

            // La tipografia se convierte en una URL a un servidor de fuentes y
            // en una regla CSS. Un nombre con comillas o con `;` se sale de la
            // regla y escribe CSS ajeno en TODAS las pantallas: es una
            // inyeccion, no una errata. Letras, numeros y espacios.
            ['platform_brands', 'ck_pb_tipografia',
                "font_family IS NULL OR font_family REGEXP '^[A-Za-z0-9 ]{2,80}$'",
                ['font_family'], 'La tipografia solo admite letras, numeros y espacios.'],

            ['platform_brands', 'ck_pb_nombre', "TRIM(name) <> ''", ['name'],
                'La marca tiene que llamarse de alguna manera.'],

            // Es la direccion a la que un creador escribe cuando algo va mal.
            // Guardar ahi «soporte» a secas produce un enlace `mailto:` roto en
            // todas las pantallas a la vez.
            ['platform_brands', 'ck_pb_correo',
                "support_email IS NULL OR support_email LIKE '%_@_%.__%'",
                ['support_email'], 'El correo de soporte no parece un correo.'],

            // Sale como enlace en los correos que se le mandan al creador. Sin
            // esquema, el navegador lo lee como una ruta relativa y lleva a una
            // pagina del propio panel que no existe.
            ['platform_brands', 'ck_pb_web',
                "website IS NULL OR website LIKE 'http://%' OR website LIKE 'https://%'",
                ['website'], 'La web tiene que empezar por http:// o https://.'],

            // `uq_pb_default` impide DOS por defecto; esto impide que la unica
            // este desactivada, que es el mismo agujero por el otro lado: el
            // panel se quedaria sin marca sin que nadie borrara nada.
            ['platform_brands', 'ck_pb_defecto_activa', 'is_default = 0 OR is_active = 1',
                ['is_default', 'is_active'], 'La marca por defecto no puede estar desactivada.'],
        ];
    }
};
