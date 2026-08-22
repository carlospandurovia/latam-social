<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catálogos base. Idempotente: se puede volver a ejecutar sin duplicar nada.
 *
 * Los países son los que el negocio confirmó: PE, EC, CL, MX y US los factura
 * CTS Perú; CO la factura CTS Colombia (docs/16). ES y AR entran como destino
 * previsto, marcados inactivos: existir en el catálogo no es lo mismo que operar.
 */
final class CimientosSeeder extends Seeder
{
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

        // is_active marca dónde se opera hoy, no dónde existe el país.
        $paises = [
            ['iso2' => 'PE', 'iso3' => 'PER', 'numeric_code' => '604', 'name' => 'Perú',      'phone_code' => '+51',  'default_currency_code' => 'PEN', 'timezone' => 'America/Lima',        'is_active' => true],
            ['iso2' => 'CO', 'iso3' => 'COL', 'numeric_code' => '170', 'name' => 'Colombia',  'phone_code' => '+57',  'default_currency_code' => 'COP', 'timezone' => 'America/Bogota',      'is_active' => true],
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
            ['creator.view',           'Creator',  'Ver creadores'],
            ['creator.approve',        'Creator',  'Aprobar o rechazar solicitudes de creador'],
            ['creator.view_sensitive', 'Creator',  'Ver datos fiscales y medios de pago'],
            ['client.view',            'Client',   'Ver clientes y marcas'],
            ['client.manage',          'Client',   'Crear y editar clientes'],
            ['finance.view',           'Finance',  'Ver el ledger y los saldos'],
            ['finance.payout.create',  'Finance',  'Crear lotes de pago'],
            ['finance.payout.approve', 'Finance',  'Aprobar lotes de pago (BR-FIN-005: distinto del creador)'],
            ['finance.invoice.issue',  'Finance',  'Emitir comprobantes'],
            ['content.review',         'Content',  'Revisar y aprobar contenido'],
            ['integration.manage',     'Core',     'Configurar integraciones y credenciales'],
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
                'campaign.view', 'campaign.manage', 'campaign.view_margin',
                'creator.view', 'client.view', 'content.review', 'catalog.view',
            ],
            'finance' => [
                'finance.view', 'finance.payout.create', 'finance.payout.approve',
                'finance.invoice.issue', 'campaign.view', 'campaign.view_margin',
                // Para pagar hace falta ver la cuenta bancaria. Es el único rol
                // no administrador con acceso a datos fiscales del creador.
                'creator.view', 'creator.view_sensitive',
                'client.view', 'catalog.view',
            ],
            'content_reviewer' => [
                'content.review', 'campaign.view', 'creator.view', 'catalog.view',
            ],
            // Portales externos: sus permisos llegan con su fase. Un rol externo
            // con permisos internos por descuido es la peor fuga posible.
            'client_user' => [],
            'creator' => [],
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
        DB::table('platform_brands')->updateOrInsert(
            ['code' => 'latam_social'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'LATAM Social',
                'primary_color' => '#7C3AED',
                'is_active' => true,
                'updated_at' => $ahora, 'created_at' => $ahora,
            ],
        );
        $marcaId = DB::table('platform_brands')->where('code', 'latam_social')->value('id');

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
            DB::table('legal_entities')->updateOrInsert(
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
                    DB::table('legal_entity_countries')->insert([
                        'legal_entity_id' => $entidadId,
                        'country_id' => $cubreId,
                        'coverage_basis' => $motivo,
                        'valid_from' => '2026-01-01',
                        'updated_at' => $ahora, 'created_at' => $ahora,
                    ]);
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
}
