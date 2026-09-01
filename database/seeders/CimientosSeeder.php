<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Shared\Database\Vigencia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catálogos base. Idempotente: se puede volver a ejecutar sin duplicar nada.
 *
 * Los países son los que el negocio confirmó: PE, EC, CL, MX y US los factura
 * CTS Perú; CO la factura CTS Colombia (docs/16). ES y AR entran como destino
 * previsto, marcados inactivos: existir en el catálogo no es lo mismo que operar.
 *
 * ### Idempotente no era lo mismo que inofensivo (`T-77`)
 *
 * Todo esto se escribía con `updateOrInsert`, que **no duplica** —de ahí la
 * palabra «idempotente» de arriba— pero **sí reescribe**. Y lo que reescribía no
 * eran catálogos: era la marca de plataforma, el domicilio de la sociedad y la
 * fuente oficial de tipos de cambio, o sea justo lo que `DEC-190` dice que una
 * persona configura desde el admin.
 *
 * Volver a ejecutar el sembrador después de una migración —que es lo que dice el
 * runbook, y lo que hay que hacer para que entren los catálogos nuevos— dejaba
 * el nombre de la marca, sus colores, su tipografía y la dirección de la
 * sociedad **como el primer día**, y de paso **regeneraba sus `uuid`**, que son
 * los que viajan en las URLs.
 *
 * Se descubrió porque además **reventó**: con un periodo de tipo de cambio ya
 * cerrado a mano, reescribir el `valid_from` del abierto lo hacía solaparse con
 * el cerrado, y `tg_fos_sin_solape_upd` paraba el sembrador entero —así que los
 * catálogos que venían después, los proveedores de integración de `9.17d` y los
 * tipos de comprobante de `9.12`, no llegaban a entrar—.
 *
 * La regla desde aquí: **lo que una persona puede cambiar se siembra si falta y
 * no se toca si está**. Eso es `sembrarSiFalta()`. Los catálogos respaldados por
 * código —monedas, países, permisos, roles, plataformas— siguen con
 * `updateOrInsert`, porque ahí actualizar ES cómo se despliega una versión nueva.
 */
final class CimientosSeeder extends Seeder
{
    /** El día desde el que valen los valores de partida de esta instalación. */
    private const ARRANQUE = '2026-01-01';

    public function run(): void
    {
        $ahora = now();

        $monedas = [
            ['code' => 'PEN', 'name' => 'Sol peruano',       'symbol' => 'S/',  'decimal_places' => 2],
            ['code' => 'USD', 'name' => 'Dólar americano',   'symbol' => '$',   'decimal_places' => 2],
            ['code' => 'COP', 'name' => 'Peso colombiano',   'symbol' => '$',   'decimal_places' => 2],
            ['code' => 'MXN', 'name' => 'Peso mexicano',     'symbol' => '$',   'decimal_places' => 2],
            ['code' => 'CLP', 'name' => 'Peso chileno',      'symbol' => '$',   'decimal_places' => 0],
            ['code' => 'ARS', 'name' => 'Peso argentino',    'symbol' => '$',   'decimal_places' => 2],
            ['code' => 'EUR', 'name' => 'Euro',              'symbol' => '€',   'decimal_places' => 2],
            ['code' => 'BRL', 'name' => 'Real brasileño',    'symbol' => 'R$',  'decimal_places' => 2],
        ];
        foreach ($monedas as $m) {
            DB::table('currencies')->updateOrInsert(
                ['code' => $m['code']],
                $m + ['is_active' => true, 'updated_at' => $ahora, 'created_at' => $ahora],
            );
        }

        // 9.1: quién publica tipos de cambio. Van aquí y no en la migración
        // porque ninguna migración de este proyecto siembra datos —y la primera
        // que lo intentó rompió `recolectar-esquema.php`, que ejecuta los
        // `up()` sin Laravel entero—. La clave ajena de `exchange_rates` no las
        // necesita para crearse; las necesita quien vaya a insertar una tasa.
        $fuentes = [
            ['code' => 'sunat', 'name' => 'SUNAT',
                'description' => 'Tipo de cambio publicado por SUNAT. Es el que la ley peruana pide citar.'],
            ['code' => 'manual', 'name' => 'Carga manual',
                'description' => 'Tecleado por una persona del equipo, con su nombre en la bitacora.'],
        ];

        foreach ($fuentes as $f) {
            // `sembrarSiFalta` y no `updateOrInsert`: `is_active` es de quien
            // administra. Con `updateOrInsert`, volver a sembrar REACTIVABA una
            // fuente que alguien habia apagado a proposito (`T-77`).
            self::sembrarSiFalta('fx_sources', ['code' => $f['code']],
                $f + ['is_active' => true, 'updated_at' => $ahora, 'created_at' => $ahora]);
        }

        // 9.2: quién publica el tipo de cambio de USD a PEN. Va en el
        // catálogo y no en una pantalla porque sin esta fila `Cambio` contesta
        // `SIN_FUENTE` y el sistema recién instalado no convierte nada — y
        // porque la respuesta no está en duda: SUNAT es la que la ley peruana
        // pide citar. Los demás pares NO se declaran aquí a propósito: SUNAT no
        // los publica, y declarar una fuente que no publica es peor que no
        // tener ninguna (ver `Q-64`).
        //
        // Se siembra si NO hay ninguna abierta para el par, y empezando el dia
        // siguiente al ultimo cierre.
        //
        // Antes esto era un `updateOrInsert` sobre el par, y ahi estaba `T-77`:
        // reescribia el `valid_from` de la fila ABIERTA y la hacia solaparse con
        // el periodo que alguien habia cerrado a mano. `tg_fos_sin_solape_upd`
        // paraba el sembrador entero con un 45000 --y con el, todos los
        // catalogos que vienen despues--. El defecto es el de `T-73` visto desde
        // el sembrador: el historico tambien ocupa fechas.
        self::sembrarSiFalta(
            'fx_official_sources',
            ['base_currency_code' => 'USD', 'quote_currency_code' => 'PEN', 'valid_to' => null],
            [
                'source_code' => 'sunat',
                'valid_from' => self::desdeCuandoSePuede('fx_official_sources', [
                    'base_currency_code' => 'USD', 'quote_currency_code' => 'PEN',
                ]),
                'updated_at' => $ahora, 'created_at' => $ahora,
            ],
        );

        // is_active marca dónde se opera hoy, no dónde existe el país.
        // 9.17c: `tax_location_*` es como se llama y que forma tiene el codigo de
        // localidad que exige la administracion tributaria de cada pais --el
        // ubigeo del INEI en Peru, el codigo DANE en Colombia--. Son VALORES DE
        // PARTIDA y se cambian desde el catalogo de paises: la regla («valida
        // contra el patron del pais») esta en el codigo, el patron no (DEC-190).
        //
        // Solo se declara donde se sabe. Mexico no tiene equivalente y usa el
        // codigo postal; Ecuador, Chile y EEUU no lo piden en el comprobante que
        // emitimos. Declarar un patron inventado seria peor que no declararlo:
        // rechazaria codigos buenos.
        $paises = [
            ['iso2' => 'PE', 'iso3' => 'PER', 'numeric_code' => '604', 'name' => 'Perú',      'phone_code' => '+51',  'default_currency_code' => 'PEN', 'timezone' => 'America/Lima',        'is_active' => true,
                'tax_location_label' => 'Ubigeo', 'tax_location_pattern' => '^[0-9]{6}$', 'requires_tax_location' => true],
            ['iso2' => 'CO', 'iso3' => 'COL', 'numeric_code' => '170', 'name' => 'Colombia',  'phone_code' => '+57',  'default_currency_code' => 'COP', 'timezone' => 'America/Bogota',      'is_active' => true,
                'tax_location_label' => 'Código DANE', 'tax_location_pattern' => '^[0-9]{5}$', 'requires_tax_location' => false],
            ['iso2' => 'MX', 'iso3' => 'MEX', 'numeric_code' => '484', 'name' => 'México',    'phone_code' => '+52',  'default_currency_code' => 'MXN', 'timezone' => 'America/Mexico_City', 'is_active' => true],
            ['iso2' => 'EC', 'iso3' => 'ECU', 'numeric_code' => '218', 'name' => 'Ecuador',   'phone_code' => '+593', 'default_currency_code' => 'USD', 'timezone' => 'America/Guayaquil',   'is_active' => true],
            ['iso2' => 'CL', 'iso3' => 'CHL', 'numeric_code' => '152', 'name' => 'Chile',     'phone_code' => '+56',  'default_currency_code' => 'CLP', 'timezone' => 'America/Santiago',    'is_active' => true],
            ['iso2' => 'US', 'iso3' => 'USA', 'numeric_code' => '840', 'name' => 'Estados Unidos', 'phone_code' => '+1', 'default_currency_code' => 'USD', 'timezone' => 'America/New_York', 'is_active' => true],
            ['iso2' => 'AR', 'iso3' => 'ARG', 'numeric_code' => '032', 'name' => 'Argentina', 'phone_code' => '+54',  'default_currency_code' => 'ARS', 'timezone' => 'America/Argentina/Buenos_Aires', 'is_active' => false],
            ['iso2' => 'ES', 'iso3' => 'ESP', 'numeric_code' => '724', 'name' => 'España',    'phone_code' => '+34',  'default_currency_code' => 'EUR', 'timezone' => 'Europe/Madrid',       'is_active' => false],
            ['iso2' => 'BR', 'iso3' => 'BRA', 'numeric_code' => '076', 'name' => 'Brasil',    'phone_code' => '+55',  'default_currency_code' => 'BRL', 'timezone' => 'America/Sao_Paulo',   'is_active' => false],
        ];
        foreach ($paises as $p) {
            DB::table('countries')->updateOrInsert(
                ['iso2' => $p['iso2']],
                $p + ['updated_at' => $ahora, 'created_at' => $ahora],
            );
        }

        // url_pattern es lo que permite validar que el enlace que sube el creador
        // pertenece de verdad a la red que dice. El negocio lo pidió explícitamente.
        $redes = [
            ['code' => 'instagram', 'name' => 'Instagram', 'url_pattern' => '^https?://(www\.)?instagram\.com/'],
            ['code' => 'tiktok',    'name' => 'TikTok',    'url_pattern' => '^https?://(www\.)?tiktok\.com/'],
            ['code' => 'youtube',   'name' => 'YouTube',   'url_pattern' => '^https?://(www\.)?(youtube\.com|youtu\.be)/'],
            ['code' => 'facebook',  'name' => 'Facebook',  'url_pattern' => '^https?://(www\.)?facebook\.com/'],
            ['code' => 'x',         'name' => 'X',         'url_pattern' => '^https?://(www\.)?(x|twitter)\.com/'],
            ['code' => 'linkedin',  'name' => 'LinkedIn',  'url_pattern' => '^https?://(www\.)?linkedin\.com/'],
        ];
        foreach ($redes as $r) {
            DB::table('platforms')->updateOrInsert(
                ['code' => $r['code']],
                $r + ['is_active' => true, 'updated_at' => $ahora, 'created_at' => $ahora],
            );
        }

        $formatos = [
            'instagram' => [['reel', 14], ['story', 1], ['post', 30], ['carousel', 30], ['live', 1]],
            'tiktok' => [['video', 30], ['photo_post', 30], ['live', 1]],
            'youtube' => [['video', 90], ['short', 30], ['community_post', 30]],
            'facebook' => [['reel', 14], ['post', 30], ['story', 1]],
            'x' => [['post', 30], ['thread', 30]],
            'linkedin' => [['post', 30], ['article', 90]],
        ];
        foreach ($formatos as $codigoRed => $lista) {
            $redId = DB::table('platforms')->where('code', $codigoRed)->value('id');
            foreach ($lista as [$codigo, $permanencia]) {
                DB::table('content_formats')->updateOrInsert(
                    ['platform_id' => $redId, 'code' => $codigo],
                    ['default_permanence_days' => $permanencia, 'is_active' => true, 'updated_at' => $ahora, 'created_at' => $ahora],
                );
            }
        }

        // Dos niveles, ni uno más (docs 2.2 P-10). min_age queda en 0 hasta que
        // Q-37 diga qué categorías se restringen a menores.
        // Q-37 resuelta: 18 años en las categorías con restricción legal o
        // reputacional. Son datos del catálogo, no código: se ajustan sin desplegar.
        $edadMinima = [
            'alcohol' => 18, 'tabaco' => 18, 'apuestas' => 18, 'contenido_adulto' => 18,
            'armas' => 18, 'criptoactivos' => 18, 'creditos' => 18, 'perdida_de_peso' => 18,
        ];

        $categorias = [
            'alcohol' => [],
            'tabaco' => [],
            'apuestas' => [],
            'criptoactivos' => [],
            'creditos' => [],
            'perdida_de_peso' => [],
            'belleza' => ['skincare', 'maquillaje', 'cabello'],
            'gaming' => ['mobile', 'pc_consola', 'esports'],
            'gastronomia' => ['recetas', 'restaurantes'],
            'moda' => ['streetwear', 'formal'],
            'fitness' => ['entrenamiento', 'nutricion'],
            'tecnologia' => ['gadgets', 'software'],
            'viajes' => [],
            'finanzas' => [],
            'hogar' => [],
            'entretenimiento' => [],
        ];
        foreach ($categorias as $padre => $hijos) {
            DB::table('categories')->updateOrInsert(
                ['code' => $padre],
                [
                    'parent_id' => null, 'depth' => 0,
                    'min_age' => $edadMinima[$padre] ?? 0,
                    'is_active' => true, 'updated_at' => $ahora, 'created_at' => $ahora,
                ],
            );
            $padreId = DB::table('categories')->where('code', $padre)->value('id');
            foreach ($hijos as $hijo) {
                DB::table('categories')->updateOrInsert(
                    ['code' => $padre.'.'.$hijo],
                    [
                        'parent_id' => $padreId, 'depth' => 1,
                        // El subnicho hereda la edad mínima de su nicho padre.
                        'min_age' => $edadMinima[$padre] ?? 0,
                        'is_active' => true, 'updated_at' => $ahora, 'created_at' => $ahora,
                    ],
                );
            }
        }

        $idiomas = [
            ['code' => 'es',    'name' => 'Español'],
            ['code' => 'en',    'name' => 'Inglés'],
            ['code' => 'pt',    'name' => 'Portugués'],
            ['code' => 'qu',    'name' => 'Quechua'],
            ['code' => 'ay',    'name' => 'Aymara'],
        ];
        foreach ($idiomas as $i) {
            DB::table('languages')->updateOrInsert(
                ['code' => $i['code']],
                $i + ['is_active' => true, 'updated_at' => $ahora, 'created_at' => $ahora],
            );
        }

        // El código pregunta por el permiso, nunca por el rol (docs/08).
        $permisos = [
            ['campaign.view',          'Campaign', 'Ver campañas'],
            ['campaign.manage',        'Campaign', 'Crear y editar campañas'],
            ['campaign.view_margin',   'Campaign', 'Ver el margen interno de la campaña (BR-FIN-007)'],
            // Aprobar una campaña fija el ingreso comprometido y **congela la
            // sociedad que la factura** (`BR-LE-002`). Es una decisión de
            // dinero, así que no la firma quien negoció el precio: misma
            // separación que `DEC-044` impone en la base para los perfiles
            // fiscales y los medios de pago.
            ['campaign.approve',       'Campaign', 'Aprobar una campaña: fija el ingreso y congela la sociedad emisora'],
            // 7.6: invitar tiene permiso propio, y no va dentro de
            // `campaign.manage`. Editar una campaña es trabajo interno;
            // invitar es el momento en que un compromiso económico sale de
            // la empresa y llega a una persona. Misma división que
            // `creator.verify` frente a `creator.activate`.
            ['campaign.invite',        'Campaign', 'Invitar creadores a una campaña y anular invitaciones'],
            // 8.1: el primer permiso de ámbito EXTERNO del sistema. `BR-SEC-003`
            // dice que un rol externo nunca recibe permisos INTERNOS; éste no lo
            // es: sólo abre la pantalla donde un creador ve y entrega lo suyo,
            // y la propiedad se comprueba además contra `creators.user_id`.
            ['creator.portal',         'Content',  'Ver y entregar lo propio (portal del creador)'],
            ['content.deliverable.view', 'Content', 'Ver los entregables de una campaña'],
            ['creator.view',           'Creator',  'Ver creadores'],
            ['creator.approve',        'Creator',  'Aprobar o rechazar solicitudes de creador'],
            ['creator.manage',         'Creator',  'Editar los datos de contacto y comerciales del creador'],
            ['creator.view_sensitive', 'Creator',  'Ver datos fiscales y medios de pago'],
            // 3.5: registrar evidencia y activar son cosas distintas. La
            // primera es trabajo de reclutamiento; la segunda le abre al creador
            // las campañas y los pagos.
            ['creator.verify',         'Creator',  'Registrar identidad, aceptacion de terminos y propiedad de cuentas sociales'],
            ['creator.activate',       'Creator',  'Activar un creador que cumple BR-CREATOR-006'],
            // 3.6: capturar el dato fiscal y aprobarlo son dos permisos, y
            // ademas `ck_ctp_segregation` exige que sean dos PERSONAS. Es la
            // misma separacion que en los lotes de pago (DEC-044): aqui se
            // decide con que tasa se retiene, y eso toca dinero.
            ['creator.tax.manage',     'Creator',  'Capturar y corregir el perfil tributario del creador'],
            ['creator.tax.approve',    'Creator',  'Aprobar o rechazar el perfil tributario (BR-CREATOR-007)'],
            // 3.11 / T-15: permiso PROPIO, no reutilizar `approve`. Anular
            // reescribe el historico del que sale la retencion practicada;
            // quien aprueba a diario no debe poder hacerlo por descuido.
            ['creator.tax.annul',      'Creator',  'Anular el perfil tributario vigente: se aprobo y no debio aprobarse'],
            // 3.8: mismo reparto que los fiscales, y por el mismo motivo. Aqui
            // se decide A DONDE VA EL DINERO, asi que `ck_cpm_segregation`
            // exige ademas dos personas distintas, no solo dos permisos.
            ['creator.payment.manage', 'Creator',  'Capturar medios de pago del creador'],
            ['creator.payment.verify', 'Creator',  'Verificar o retirar un medio de pago (BR-FIN-006)'],
            // 3.9 / DEC-069: la tarifa es el COSTO del creador y alimenta el
            // margen que BR-FIN-007 protege. Permiso propio para poder darselo
            // a campanas y a finanzas sin abrir tambien los datos fiscales ni
            // la cuenta bancaria, que estan detras de `creator.view_sensitive`.
            ['creator.rate.manage',    'Creator',  'Fijar tarifas, disponibilidad y bloqueos de agenda del creador'],
            // 4.9: ver el registro de correos NO es ver el correo. Solo se
            // guarda plantilla, version, asunto y la huella del cuerpo --el
            // texto con los datos de la persona no esta ahi-- pero saber a
            // quien se le escribio y cuando sigue siendo informacion, y el
            // permiso lo acota.
            ['comms.view',             'Communication', 'Ver el registro de correos enviados y las plantillas'],
            ['client.view',            'Client',   'Ver clientes y marcas'],
            ['client.manage',          'Client',   'Crear y editar clientes'],
            // 4.4: la identidad fiscal del cliente va en permiso propio, no en
            // `client.manage`. De ella salen la razon social y el RUC que se
            // imprimen en la factura, y un permiso que se pueda quitar aparte
            // permite que alguien edite la ficha comercial sin poder tocar eso.
            //
            // NO sigue la simetria de `creator.tax.manage`, que vive solo en
            // `finance`. El RUC de un creador es dato PERSONAL sensible; el de
            // una empresa es publico —se consulta en SUNAT—. Aqui el riesgo no
            // es fuga, es ERROR, asi que el permiso lo tienen tambien las
            // campanas, que son quienes hablan con el cliente y tienen el dato.
            ['client.tax.manage',      'Client',   'Registrar y editar la identidad fiscal del cliente'],
            ['finance.view',           'Finance',  'Ver el ledger y los saldos'],
            ['file.view',              'Core',     'Pedir un archivo (que archivo, lo decide el Vigilante) (9.15)'],
            // 9.17: PROPIO y no `legal_entity.manage`. Quien da de alta
            // sociedades no tiene por que poder cambiar lo que ve todo el mundo
            // en todas las pantallas, incluida la de acceso. Solo `admin`.
            ['brand.manage',           'Core',     'Cambiar la identidad de la plataforma: nombre, logotipo y colores (9.17)'],
            // 9.17b: abrir el panel de «que falta por configurar». QUE se ve
            // dentro lo decide el permiso de cada area, asi que darlo de mas no
            // ensena de mas: quien no puede arreglar un area no la ve.
            ['config.view',            'Core',     'Abrir el panel de configuracion pendiente (9.17b)'],
            // 9.18: con que retencion se pacta y que margen se considera
            // aceptable. Decision de direccion y finanzas, no de quien monta
            // campanas: quien lo cambia mueve el costo de TODO lo que se pacte.
            ['pricing.manage',         'Core',     'Fijar la retencion y el umbral de rentabilidad (9.18)'],
            ['finance.cost.manage',    'Finance',  'Anotar y anular gastos de campana (9.10a)'],
            ['finance.payout.create',  'Finance',  'Crear lotes de pago'],
            ['finance.payout.approve', 'Finance',  'Aprobar lotes de pago (BR-FIN-005: distinto del creador)'],
            ['finance.invoice.issue',  'Finance',  'Emitir comprobantes'],
            // 8.3: `content.review` decia «Revisar y aprobar» y ahora solo
            // revisa. Aprobar es lo que deja el contenido listo para el cliente
            // y --cuando exista F9-- lo que dispara el pago, asi que se separa,
            // por el mismo criterio que separo capturar de aprobar en el perfil
            // fiscal (`DEC-062`).
            ['content.review',         'Content',  'Ver la cola de revision y pedir cambios'],
            ['content.approve',        'Content',  'Dar el visto bueno a un entregable'],
            ['content.extra_round',    'Content',  'Autorizar una ronda de correccion por encima de las incluidas'],
            // 8.2: volver atras sobre algo ya aprobado. Lo tienen los DOS roles
            // que revisan: «se aprobo por error» lo descubre normalmente quien
            // aprobo, y obligarle a pedirselo a otro convierte un clic en un
            // correo --y en la practica, en que nadie lo arregle--.
            ['content.reopen',         'Content',  'Reabrir un entregable ya aprobado, con motivo'],
            // 8.6: registrar el post publicado POR el creador. El creador lo
            // hace desde su portal con `creator.portal`; esto es para cuando el
            // enlace llega por WhatsApp y lo mete el equipo.
            ['content.publication.manage', 'Content', 'Registrar el post publicado en nombre del creador'],
            // 8.7: dar por buena una publicacion. De `verified` cuelga el pago
            // (`BR-CONTENT-004`, rojo), asi que es una firma con dinero detras
            // y va aparte, por el mismo criterio que separo revisar de aprobar
            // en 8.3. Finanzas NO lo necesita: paga contra lo verificado.
            ['content.verify',         'Content',  'Verificar que el post existe y archivar su prueba'],
            // 4.5: dar de alta una sociedad es constituir una empresa en el
            // sistema. De ella salen la numeracion de comprobantes
            // (`BR-LE-007`), el emisor de cada factura (`BR-LE-005`) y las
            // cuentas de cobro (`BR-LE-006`). Se toca dos o tres veces al anio.
            // Decision de negocio (2026-08-25): SOLO `admin`. Finanzas emite
            // desde ellas, no necesita crearlas.
            ['legal_entity.manage',    'Core',     'Dar de alta sociedades del grupo y su cobertura de facturacion'],
            ['integration.manage',     'Core',     'Configurar integraciones y credenciales'],
            // 9.2: la pantalla de tipos de cambio. Declarar quien manda para un
            // par y teclear una tasa que ningun proveedor publica --SUNAT solo
            // da USD/PEN-- es trabajo de finanzas. La CREDENCIAL no: esa va con
            // `integration.manage`, porque una clave de un tercero es un permiso
            // de gasto y no la necesita quien anota una tasa un lunes.
            ['fx.manage',              'Core',     'Declarar fuentes de tipo de cambio y anotar tasas'],
            ['audit.view',             'Core',     'Consultar la bitácora de auditoría'],
            ['catalog.view',           'Core',     'Consultar los catálogos de referencia'],
        ];
        foreach ($permisos as [$codigo, $modulo, $descripcion]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $codigo],
                ['module' => $modulo, 'description' => $descripcion, 'updated_at' => $ahora, 'created_at' => $ahora],
            );
        }

        $roles = [
            ['admin',            'Administrador',        'internal', true],
            ['campaign_manager', 'Gestor de campañas',   'internal', true],
            ['finance',          'Finanzas',             'internal', true],
            ['content_reviewer', 'Revisor de contenido', 'internal', true],
            ['client_user',      'Usuario de cliente',   'client',   true],
            ['creator',          'Creador',              'creator',  true],
        ];
        foreach ($roles as [$codigo, $nombre, $ambito, $esSistema]) {
            DB::table('roles')->updateOrInsert(
                ['code' => $codigo],
                ['name' => $nombre, 'scope' => $ambito, 'is_system' => $esSistema, 'updated_at' => $ahora, 'created_at' => $ahora],
            );
        }

        // ---- Qué puede hacer cada rol (iteración 3.1, DEC-053) ----
        //
        // Hasta ahora `permission_role` estaba VACÍA: había 16 permisos y 6
        // roles, y ni una sola concesión. Nada comprobaba permisos, así que no
        // se notaba; en cuanto el middleware existe, un rol sin concesiones no
        // puede hacer nada.
        //
        // `admin` recibe todos los permisos como DATO, recorriendo la tabla. No
        // hay atajo en el código: ver `App\Shared\Auth\Permisos`.
        $matriz = [
            'campaign_manager' => [
                // DEC-181: `campaign.view_margin` YA NO esta aqui. Quien lleva
                // una campana carga sus gastos y ve cuanto lleva gastado; el
                // margen --lo que se gana-- es una cifra de direccion, y el
                // camino por el que un numero interno acaba en un correo a un
                // cliente empieza siempre por alguien que podia verlo sin
                // necesitarlo.
                'campaign.view', 'campaign.manage', 'campaign.invite',
                'finance.cost.manage', 'file.view',
                'content.deliverable.view',
                'creator.view', 'creator.manage', 'creator.approve',
                // DEC-060: el mismo rol verifica y activa. El equipo de
                // reclutamiento es pequeno y exigir dos personas por creador
                // pararia el alta. La segregacion se reserva para el dinero
                // (DEC-044, BR-FIN-005), donde el dano de un error no se
                // deshace.
                'creator.verify', 'creator.activate',
                // DEC-069: para armar una campana hay que saber cuanto cuesta
                // un creador y cuando puede trabajar. Eso NO abre sus datos
                // fiscales ni su cuenta bancaria, que siguen en otro permiso.
                'creator.rate.manage',
                // 4.1: quien monta la campana da de alta al cliente para el
                // que la monta. `client.manage` estaba declarado desde 3.1 y no
                // lo tenia NINGUN rol, asi que el permiso existia y nadie podia
                // crear un cliente.
                'client.view', 'client.manage', 'catalog.view',
                // 8.3: quien lleva la campana revisa, aprueba, y es quien
                // autoriza una ronda por encima de las incluidas --es quien
                // responde del margen y quien va a tener que explicarsela al
                // cliente--.
                'content.review', 'content.approve', 'content.extra_round', 'content.reopen',
                'content.publication.manage', 'content.verify',
                // 4.4: quien habla con el cliente es quien tiene su RUC.
                'client.tax.manage',
                // 4.9: quien invita a un creador necesita poder comprobar que la
                // invitacion salio. Un «no me llego» sin registro es la palabra
                // de uno contra la del otro.
                'comms.view',
            ],
            'finance' => [
                // 9.2: quien convierte dinero necesita poder arreglar la tabla
                // de la que sale la tasa. No la credencial: eso es de `admin`.
                'fx.manage',
                // 9.18: finanzas fija la retencion y el umbral. Es su terreno:
                // la retencion es tributaria y el umbral es el margen que hay
                // que defender.
                'pricing.manage',
                // 9.17b: el panel de configuracion. Finanzas tiene `fx.manage`,
                // asi que le sale el area de tipos de cambio --y solo esa-- y
                // se entera de que el cron lleva dias sin traer nada sin tener
                // que entrar a mirarlo.
                'config.view',
                'finance.view', 'finance.payout.create', 'finance.payout.approve',
                'finance.invoice.issue', 'finance.cost.manage', 'file.view',
                'campaign.view', 'campaign.view_margin',
                'campaign.approve',
                // Para pagar hace falta ver la cuenta bancaria. Es el único rol
                // no administrador con acceso a datos fiscales del creador.
                'creator.view', 'creator.view_sensitive',
                // 3.6 / DEC-062: los dos permisos fiscales van al MISMO rol, y
                // la separacion la impone la base exigiendo dos personas
                // distintas. Repartirlos entre roles habria obligado a dar
                // datos fiscales a `campaign_manager`, que es justo lo que
                // DEC-053 decidio no hacer.
                'creator.tax.manage', 'creator.tax.approve', 'creator.tax.annul',
                'creator.payment.manage', 'creator.payment.verify',
                'creator.rate.manage',
                'client.view', 'catalog.view',
                // 4.4: finanzas emite la factura, asi que tiene que poder
                // corregir la identidad fiscal con la que se emite.
                'client.tax.manage',
                'comms.view',
            ],
            // 8.3: revisa y aprueba, pero NO autoriza una ronda de mas. Esa
            // decision es de dinero --se le cobra al cliente o se come el
            // margen-- y quien revisa contenido no tiene por que cargar con
            // ella. `campaign_manager` la tiene.
            'content_reviewer' => [
                'file.view',
                'content.review', 'content.approve', 'content.reopen', 'content.deliverable.view',
                'content.publication.manage', 'content.verify',
                'campaign.view', 'creator.view', 'catalog.view',
            ],
            // Portales externos: sus permisos llegan con su fase. Un rol externo
            // con permisos internos por descuido es la peor fuga posible.
            'client_user' => [],
            // 8.1: el rol `creator` deja de estar vacio. Lo unico que le abre es
            // SU pantalla: ver lo que le toca entregar y entregarlo. No hay
            // ningun permiso interno aqui (`BR-SEC-003`).
            // 9.15: el creador puede PEDIR un archivo. Cual --el suyo y solo
            // el suyo-- lo decide la regla que registro cada modulo, no este
            // permiso (`BR-SEC-003` sigue intacto: aqui no hay nada interno).
            'creator' => ['creator.portal', 'file.view'],
        ];

        $idsPermiso = DB::table('permissions')->pluck('id', 'code')->all();

        foreach ($matriz as $codigoRol => $codigosPermiso) {
            $rolId = DB::table('roles')->where('code', $codigoRol)->value('id');
            if ($rolId === null) {
                continue;
            }
            foreach ($codigosPermiso as $codigoPermiso) {
                $permisoId = $idsPermiso[$codigoPermiso] ?? null;
                if ($permisoId !== null) {
                    DB::table('permission_role')->updateOrInsert(
                        ['role_id' => $rolId, 'permission_id' => $permisoId],
                    );
                }
            }
        }

        // `admin`: todos los permisos, como filas reales y no como excepción.
        $adminId = DB::table('roles')->where('code', 'admin')->value('id');
        if ($adminId !== null) {
            foreach ($idsPermiso as $permisoId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $adminId, 'permission_id' => $permisoId],
                );
            }
        }

        // ---- Marca de plataforma y sociedades (iteración 2.10) ----
        // 9.17: la marca ya no vive en las plantillas, asi que lo que se siembra
        // aqui es lo que se ve. Son valores DE PARTIDA, no la marca definitiva:
        // se cambian desde `/marca` sin desplegar nada (DEC-190). El codigo sale
        // de la configuracion porque es la llave de este `updateOrInsert`, y una
        // instalacion que quiera otra debe poder ponerla antes del primer
        // arranque.
        //
        // `is_default` la deja marcada. `uq_pb_default` garantiza que solo una
        // lo este; sin ninguna, `Marca::actual()` tendria que adivinar.
        //
        // El logotipo NO se siembra: no hay ningun archivo que subir desde un
        // sembrador, y por eso la pantalla nace con un aviso rojo que dice
        // exactamente eso. Es el criterio de DEC-190: falta configuracion, se
        // avisa con prioridad, no se bloquea nada.
        // `sembrarSiFalta` desde `T-77`. Con `updateOrInsert`, cada vez que se
        // volvia a sembrar --lo que el runbook manda hacer tras cada migracion--
        // la marca volvia al primer dia: nombre, lema, colores y tipografia
        // pisados, y **el `uuid` regenerado**. Los valores de partida se ponen
        // una vez; a partir de ahi manda `/marca` (`DEC-190`).
        self::sembrarSiFalta(
            'platform_brands',
            ['code' => (string) config('latam.marca.codigo', 'latam_social')],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'LATAM Social',
                'tagline' => 'Plataforma de Creator Marketing',
                // La linea que estaba escrita a mano al pie de la pantalla de
                // acceso. Los datos completos de la sociedad estan en
                // `legal_entities`; esto es solo lo que acompana a la marca.
                'legal_footer' => 'Soluciones Tecnológicas a Medida S.A.C. · RUC 20603203896',
                'primary_color' => '#7C3AED',
                'secondary_color' => '#22D3EE',
                'sidebar_color' => '#070A2B',
                'font_family' => 'Plus Jakarta Sans',
                'is_active' => true,
                'is_default' => true,
                'updated_at' => $ahora, 'created_at' => $ahora,
            ],
        );

        // 9.17d: el catalogo de proveedores de integracion. Es una tabla y no
        // una constante porque el dia que entre uno nuevo --otro emisor
        // electronico, una pasarela-- eso es una fila y no un despliegue
        // (`DEC-190`). Se siembran los que el negocio ya nombro; la CONEXION
        // --URL, usuario, claves-- se crea desde `/integraciones`, porque esos
        // valores son de cada instalacion y no del codigo.
        $proveedores = [
            ['code' => 'sunat', 'name' => 'SUNAT — facturación electrónica', 'purpose' => 'invoicing',
                'doc_url' => 'https://cpe.sunat.gob.pe/'],
            ['code' => 'decolecta', 'name' => 'Decolecta — tipos de cambio', 'purpose' => 'fx',
                'doc_url' => 'https://decolecta.com/'],
            ['code' => 'smtp', 'name' => 'Servidor de correo (SMTP)', 'purpose' => 'email',
                'doc_url' => null],
        ];

        foreach ($proveedores as $p) {
            // Si falta, no si cambia (`T-77`): `is_active` y el nombre son de
            // quien administra, y `updateOrInsert` reactivaba un proveedor
            // apagado a proposito cada vez que se volvia a sembrar.
            self::sembrarSiFalta('integration_providers', ['code' => $p['code']],
                $p + ['is_active' => true, 'updated_at' => $ahora, 'created_at' => $ahora]);
        }

        // 9.12: los tipos de comprobante de Peru. Son DATOS, no un enum: hasta
        // hoy `ck_ds_type` los tenia escritos en el codigo, y la boleta es
        // peruana --el CFDI mexicano no estaba y anadirlo exigia desplegar--.
        //
        // Los codigos oficiales son los del catalogo 01 de SUNAT, y las formas
        // de serie las que exige para comprobantes electronicos: `F` mas tres
        // para factura, `B` mas tres para boleta, y las notas van con la serie
        // del comprobante que corrigen, asi que admiten las dos.
        //
        // Se siembran los tipos --que son la regla del pais-- y NO las series,
        // que son de cada instalacion y se registran ante SUNAT: una serie
        // inventada produciria comprobantes invalidos. La falta de series sale
        // en rojo en el panel de configuracion, no bloquea nada (`DEC-190`).
        $paisPeru = DB::table('countries')->where('iso2', 'PE')->value('id');

        if ($paisPeru !== null) {
            $tipos = [
                ['code' => 'invoice', 'name' => 'Factura electrónica', 'official_code' => '01',
                    'series_pattern' => '^F[A-Z0-9]{3}$', 'series_label' => 'Serie: F y tres más (F001)',
                    'requires_customer_tax_id' => true, 'sort_order' => 10],
                ['code' => 'boleta', 'name' => 'Boleta de venta electrónica', 'official_code' => '03',
                    'series_pattern' => '^B[A-Z0-9]{3}$', 'series_label' => 'Serie: B y tres más (B001)',
                    'requires_customer_tax_id' => false, 'sort_order' => 20],
                ['code' => 'credit_note', 'name' => 'Nota de crédito electrónica', 'official_code' => '07',
                    'series_pattern' => '^[FB][A-Z0-9]{3}$', 'series_label' => 'Serie del comprobante que corrige',
                    'requires_customer_tax_id' => false, 'sort_order' => 30],
                ['code' => 'debit_note', 'name' => 'Nota de débito electrónica', 'official_code' => '08',
                    'series_pattern' => '^[FB][A-Z0-9]{3}$', 'series_label' => 'Serie del comprobante que corrige',
                    'requires_customer_tax_id' => false, 'sort_order' => 40],
            ];

            foreach ($tipos as $t) {
                // Si falta, no si cambia (`T-77`): la forma de la serie y los
                // digitos del correlativo se pueden ajustar desde `/series`, y
                // volver a sembrar no puede devolverlos a los de fabrica.
                self::sembrarSiFalta('document_types',
                    ['country_id' => $paisPeru, 'code' => $t['code']],
                    $t + ['number_length' => 8, 'is_active' => true,
                        'updated_at' => $ahora, 'created_at' => $ahora]);
            }
        }

        // 9.18: la politica de precios de partida. 29,5 % es la retencion que el
        // negocio confirmo en `Q-13`, y 20 % sobre el COSTO es lo que sale de su
        // propio ejemplo --100 de neto cuestan 141,84 y el ingreso aceptable mas
        // bajo era 170,21, que es 141,84 x 1,20--.
        //
        // Son valores DE PARTIDA y se cambian desde `/politica` sin desplegar
        // (DEC-190). `updateOrInsert` sobre la vigente y no sobre un codigo: la
        // llave natural de una politica es «la que esta abierta».
        if (DB::table('pricing_policies')->whereNull('valid_to')->doesntExist()) {
            DB::table('pricing_policies')->insert([
                'uuid' => (string) Str::uuid(),
                'withholding_rate' => 29.5,
                'min_margin_pct' => 20,
                'margin_basis' => 'cost',
                'note' => 'Valores de partida de la instalacion: retencion de renta de cuarta '
                    .'categoria y umbral del 20 % sobre el costo. Revisar con el contador.',
                'valid_from' => '2026-01-01',
                'updated_at' => $ahora, 'created_at' => $ahora,
            ]);
        }

        $marcaId = DB::table('platform_brands')
            ->where('code', (string) config('latam.marca.codigo', 'latam_social'))->value('id');

        $sociedades = [
            [
                'code' => 'CTS_PE', 'legal_name' => 'Soluciones Tecnológicas a Medida S.A.C.',
                'iso2' => 'PE', 'tax_id_type' => 'RUC', 'tax_id_number' => '20603203896',
                'currency' => 'PEN', 'timezone' => 'America/Lima',
                // CTS Perú factura estos países. Los cuatro últimos por
                // exportación de servicios, que fue lo que confirmaste.
                'cubre' => ['PE' => 'local_entity', 'EC' => 'service_export', 'CL' => 'service_export',
                    'MX' => 'service_export', 'US' => 'service_export'],
            ],
            [
                'code' => 'CTS_CO', 'legal_name' => 'CTS Colombia S.A.S.',
                'iso2' => 'CO', 'tax_id_type' => 'NIT', 'tax_id_number' => '000000000',
                'currency' => 'COP', 'timezone' => 'America/Bogota',
                'cubre' => ['CO' => 'local_entity'],
            ],
        ];

        foreach ($sociedades as $e) {
            $paisId = DB::table('countries')->where('iso2', $e['iso2'])->value('id');
            // `sembrarSiFalta` desde `T-77`, y aqui es lo mas grave que hacia el
            // `updateOrInsert`: la razon social, la direccion, la ciudad y el
            // ESTADO de la sociedad volvian a los valores de fabrica --con la
            // direccion en «Por completar»-- cada vez que se sembraba, y el
            // `uuid` se regeneraba, que es el que viaja en las URLs. Justo lo
            // que `9.17c` acababa de dejar configurable desde el admin.
            self::sembrarSiFalta(
                'legal_entities',
                ['code' => $e['code']],
                [
                    'uuid' => (string) Str::uuid(),
                    'platform_brand_id' => $marcaId,
                    'legal_name' => $e['legal_name'],
                    'country_id' => $paisId,
                    'tax_id_type' => $e['tax_id_type'],
                    'tax_id_number' => $e['tax_id_number'],
                    'address_line1' => 'Por completar',
                    'city' => 'Por completar',
                    'default_currency_code' => $e['currency'],
                    'timezone' => $e['timezone'],
                    'status' => 'active',
                    'updated_at' => $ahora, 'created_at' => $ahora,
                ],
            );
            $entidadId = DB::table('legal_entities')->where('code', $e['code'])->value('id');

            foreach ($e['cubre'] as $iso => $motivo) {
                $cubreId = DB::table('countries')->where('iso2', $iso)->value('id');
                if ($cubreId === null) {
                    continue;
                }
                $existe = DB::table('legal_entity_countries')
                    ->where('legal_entity_id', $entidadId)
                    ->where('country_id', $cubreId)
                    ->whereNull('valid_to')
                    ->exists();
                if (!$existe) {
                    // No desde el arranque a secas: desde el dia siguiente al
                    // ultimo cierre. Desactivar una sociedad cierra sus
                    // coberturas (`DEC-081`), y volver a sembrar con la fecha de
                    // fabrica se solapaba con lo cerrado --el `45000` de `T-73`,
                    // esta vez disparado por el sembrador--.
                    DB::table('legal_entity_countries')->insert([
                        'legal_entity_id' => $entidadId,
                        'country_id' => $cubreId,
                        'coverage_basis' => $motivo,
                        'valid_from' => self::desdeCuandoSePuede('legal_entity_countries', [
                            'legal_entity_id' => $entidadId, 'country_id' => $cubreId,
                        ]),
                        'updated_at' => $ahora, 'created_at' => $ahora,
                    ]);
                }
            }
        }

        // 9.9a: el IGV de Peru. Solo el de Peru, que es donde se factura:
        // sembrar el IVA colombiano o el mexicano seria inventar el dato de un
        // pais donde todavia no se emite nada --el mismo criterio que `Q-64` con
        // los tipos de cambio--. Una tasa que nadie ha confirmado es peor que
        // ninguna, porque se usa sin mirarla.
        //
        // Rige desde el arranque de la instalacion y no desde 2011: esta base no
        // tiene facturas anteriores, y fechar una vigencia hacia atras es
        // inventarse una historia que nadie puede comprobar.
        if ($paisPeru !== null) {
            self::sembrarSiFalta(
                'tax_rates',
                ['country_id' => $paisPeru, 'code' => 'IGV', 'valid_to' => null],
                [
                    'name' => 'Impuesto General a las Ventas',
                    'rate' => '18.0000',
                    // Catalogo 05 de SUNAT: es lo que viaja en el XML.
                    'official_code' => '1000',
                    'valid_from' => self::desdeCuandoSePuede('tax_rates', [
                        'country_id' => $paisPeru, 'code' => 'IGV',
                    ]),
                    'note' => 'Valor de partida de la instalacion. Se cambia publicando la '
                        .'siguiente desde /backoffice/impuestos, sin desplegar.',
                    'updated_at' => $ahora, 'created_at' => $ahora,
                ],
            );
        }

        // 9.21b: las dos portadas publicas, con texto DE PARTIDA.
        //
        // Se siembran porque una instalacion recien montada tiene que poder
        // ensenarse a alguien el primer dia: sin esto, `/` lleva al acceso y no
        // hay nada que mirar. Y se siembran con `sembrarSiFalta` --`T-77`--:
        // volver a sembrar despues de una migracion NO puede devolver el texto
        // de marketing a los valores de fabrica.
        //
        // El texto es de fabrica y se nota a proposito: dice lo que hace el
        // sistema, sin promesas que nadie ha decidido todavia. Se cambia entero
        // desde `/backoffice/landing` sin desplegar (`DEC-190`).
        if ($marcaId !== null) {
            $portadas = [
                [
                    'code' => 'marcas',
                    'headline' => 'Campañas con creadores, de principio a fin y con todo a la vista',
                    'subheadline' => 'Elegimos a los creadores, gestionamos el contenido y las publicaciones, '
                        .'y te entregamos cada campaña con su comprobante y sus métricas.',
                    'cta_label' => 'Quiero lanzar una campaña',
                    'meta_title' => 'Marketing con creadores en LATAM',
                    'meta_description' => 'Campañas con creadores en Perú y LATAM: selección, contenido, '
                        .'publicación y facturación en un solo sitio.',
                    'bloques' => [
                        ['feature', 'Creadores verificados', 'Identidad, cuentas y métricas comprobadas antes de proponerte a nadie.', 10],
                        ['feature', 'Un precio cerrado', 'Sabes lo que cuesta la campaña antes de empezarla, y qué recibe cada creador.', 20],
                        ['feature', 'Todo queda registrado', 'Cada entrega, cada publicación y cada pago, con su evidencia y su fecha.', 30],
                        ['step', '1. Nos cuentas la campaña', 'Marca, objetivo, países y presupuesto.', 40],
                        ['step', '2. Te proponemos creadores', 'Con sus métricas y su tarifa, para que elijas.', 50],
                        ['step', '3. Producimos y publicamos', 'Revisamos el contenido contigo antes de que salga.', 60],
                        ['step', '4. Te entregamos resultados', 'Publicaciones verificadas, métricas y comprobante.', 70],
                    ],
                ],
                [
                    'code' => 'creadores',
                    'headline' => 'Trabaja con marcas y cobra sin perseguir a nadie',
                    'subheadline' => 'Postula una vez. Cuando haya una campaña que encaje contigo te '
                        .'escribimos con el trabajo y el monto en claro, antes de aceptar nada.',
                    'cta_label' => 'Quiero postular',
                    'meta_title' => 'Creadores: trabaja con marcas',
                    'meta_description' => 'Postula para trabajar con marcas en campañas pagadas: sabes qué '
                        .'tienes que hacer y cuánto recibes antes de aceptar.',
                    'bloques' => [
                        ['feature', 'Sabes cuánto recibes', 'El monto que ves es el que te queda a ti, con la retención ya descontada.', 10],
                        ['feature', 'Sin perseguir el pago', 'Cada campaña tiene su plazo, y el pago sale por lote con su comprobante.', 20],
                        ['feature', 'Tú decides', 'Recibes la propuesta con el trabajo y el monto; aceptas o la rechazas.', 30],
                        ['step', '1. Postulas', 'Nombre, correo y país. Nada más para empezar.', 40],
                        ['step', '2. Revisamos tu perfil', 'Tus cuentas y tus métricas.', 50],
                        ['step', '3. Te llega una propuesta', 'Con el trabajo, las fechas y el monto.', 60],
                        ['faq', '¿Cuánto tarda?', 'Revisar una postulación toma unos días. Te escribimos igual si no encaja todavía.', 70],
                        ['faq', '¿Tengo que emitir comprobante?', 'Depende de tu país y de tu situación tributaria. Te lo decimos antes del primer pago.', 80],
                    ],
                ],
            ];

            foreach ($portadas as $portada) {
                $bloques = $portada['bloques'];
                unset($portada['bloques']);

                self::sembrarSiFalta(
                    'landing_pages',
                    ['platform_brand_id' => $marcaId, 'code' => $portada['code']],
                    $portada + ['is_published' => true, 'updated_at' => $ahora, 'created_at' => $ahora],
                );

                $paginaId = DB::table('landing_pages')
                    ->where('platform_brand_id', $marcaId)->where('code', $portada['code'])->value('id');

                foreach ($bloques as [$tipo, $titulo, $texto, $orden]) {
                    self::sembrarSiFalta(
                        'landing_blocks',
                        ['landing_page_id' => $paginaId, 'heading' => $titulo],
                        ['kind' => $tipo, 'body' => $texto, 'sort_order' => $orden,
                            'is_visible' => true, 'updated_at' => $ahora, 'created_at' => $ahora],
                    );
                }
            }
        }

        // admin recibe todo; el resto se configura desde el back-office.
        $adminId = DB::table('roles')->where('code', 'admin')->value('id');
        foreach (DB::table('permissions')->pluck('id') as $permisoId) {
            DB::table('permission_role')->updateOrInsert(
                ['role_id' => $adminId, 'permission_id' => $permisoId], [],
            );
        }
    }

    /**
     * Siembra una fila **sólo si no existe**. Nunca reescribe lo que ya hay.
     *
     * Es la mitad de `updateOrInsert` que hace falta para un valor de partida
     * (`DEC-190`): el sistema arranca configurado, y a partir de ahí manda quien
     * lo configura. Volver a sembrar no puede deshacer su trabajo.
     *
     * Un `NULL` en la llave se busca con `whereNull` y no con `=`: en SQL
     * `columna = NULL` no es falso, es **desconocido**, así que nunca encuentra
     * nada y la fila se insertaría siempre —que es el duplicado que se quería
     * evitar—.
     *
     * @param array<string, mixed> $llave Cómo se reconoce esta fila.
     * @param array<string, mixed> $valores Lo demás, sólo para el alta.
     * @return bool `true` si la sembró; `false` si ya estaba.
     */
    private static function sembrarSiFalta(string $tabla, array $llave, array $valores): bool
    {
        $consulta = DB::table($tabla);

        foreach ($llave as $columna => $valor) {
            $valor === null ? $consulta->whereNull($columna) : $consulta->where($columna, $valor);
        }

        if ($consulta->exists()) {
            return false;
        }

        DB::table($tabla)->insert($llave + $valores);

        return true;
    }

    /**
     * Desde cuándo puede empezar un periodo nuevo sin pisar al anterior.
     *
     * El día siguiente al último cierre, o el arranque de la instalación si no
     * hay historia. Sin esto, sembrar una cobertura o una fuente oficial después
     * de haber cerrado la anterior produce el `45000` de solape —que es el mismo
     * defecto de `T-73`, esta vez por el lado del sembrador—.
     *
     * @param array<string, mixed> $filtro
     */
    private static function desdeCuandoSePuede(string $tabla, array $filtro, string $columna = 'valid_to'): string
    {
        $consulta = DB::table($tabla);

        foreach ($filtro as $col => $valor) {
            $consulta->where($col, $valor);
        }

        $ultimoCierre = $consulta->max($columna);

        if ($ultimoCierre === null) {
            return self::ARRANQUE;
        }

        $siguiente = Vigencia::elDiaDespuesDe((string) $ultimoCierre);

        return Vigencia::fecha($siguiente) > Vigencia::fecha(self::ARRANQUE)
            ? $siguiente
            : self::ARRANQUE;
    }
}
