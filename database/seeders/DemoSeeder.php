<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos de demostración para poder ver y probar las pantallas.
 *
 * NO se ejecuta con `db:seed` a secas: hay que pedirlo a propósito con
 *   php artisan db:seed --class=DemoSeeder
 * porque son datos inventados y no deben acabar en producción por descuido.
 *
 * Incluye a propósito un creador MENOR de edad con su tutela, que es el caso
 * que más partes del modelo toca a la vez.
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoSeeder no se ejecuta en producción.');

            return;
        }

        $ahora = now();
        $pe = DB::table('countries')->where('iso2', 'PE')->value('id');
        $ig = DB::table('platforms')->where('code', 'instagram')->value('id');
        $tk = DB::table('platforms')->where('code', 'tiktok')->value('id');

        if ($pe === null || $ig === null) {
            $this->command?->error('Faltan los catálogos. Ejecuta antes: php artisan db:seed');

            return;
        }

        $creadores = [
            ['Valeria', 'Quispe',  'Vale Quispe',  '1997-03-14', 'valeria@demo.pe', '46112233', 'active',  350.00, 'belleza'],
            ['Diego',   'Ramírez', 'Diego R',      '1995-11-02', 'diego@demo.pe',   '44556677', 'active',  500.00, 'gaming'],
            ['Camila',  'Flores',  'Cami Flores',  '2010-06-20', 'camila@demo.pe',  '72889900', 'pending', 180.00, 'belleza'],
            ['Sebastián', 'Ríos',  'Seba Ríos',    '1999-01-08', 'sebastian@demo.pe', '47001122', 'pending', 260.00, 'gastronomia'],
            ['Lucía',   'Mendoza', 'Lu Mendoza',   '1993-09-25', 'lucia@demo.pe',   '43220011', 'suspended', 420.00, 'moda'],
        ];

        foreach ($creadores as [$nombre, $apellido, $alias, $nacimiento, $correo, $doc, $estado, $tarifa, $nicho]) {
            if (DB::table('creators')->where('email', $correo)->exists()) {
                continue;
            }

            $creadorId = DB::table('creators')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'first_name' => $nombre, 'last_name' => $apellido, 'display_name' => $alias,
                'birth_date' => $nacimiento, 'email' => $correo,
                'country_id' => $pe, 'city' => 'Lima',
                'document_country_code' => 'PE', 'document_type' => 'DNI', 'document_number' => $doc,
                'status' => $estado, 'payment_term_days' => 30, 'preferred_currency_code' => 'PEN',
                'activated_at' => $estado === 'active' ? $ahora : null,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            // Nicho principal.
            $categoriaId = DB::table('categories')->where('code', $nicho)->value('id');
            if ($categoriaId !== null) {
                DB::table('creator_categories')->insert([
                    'creator_id' => $creadorId, 'category_id' => $categoriaId,
                    'is_primary' => true, 'created_at' => $ahora,
                ]);
            }

            // Cuenta social verificada, con dos capturas de métricas para que se
            // vea que el histórico se acumula y no se sobrescribe.
            $handle = Str::slug($alias, '');
            $cuentaId = DB::table('social_accounts')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'creator_id' => $creadorId, 'platform_id' => $ig,
                'handle' => $handle, 'profile_url' => "https://instagram.com/{$handle}",
                'verification_status' => $estado === 'active' ? 'verified' : 'unverified',
                'verified_at' => $estado === 'active' ? $ahora : null,
                'is_primary' => true, 'is_active' => true,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            $base = random_int(4_000, 90_000);
            foreach ([[60, $base], [5, (int) ($base * 1.08)]] as [$diasAtras, $seguidores]) {
                DB::table('social_account_snapshots')->insert([
                    'social_account_id' => $cuentaId,
                    'captured_at' => $ahora->copy()->subDays($diasAtras),
                    'source' => 'self_declared',
                    'followers' => $seguidores,
                    'engagement_rate' => round(random_int(150, 720) / 100, 4),
                ]);
            }

            // Tarifa declarada por el formato principal de la red.
            $formatoId = DB::table('content_formats')
                ->where('platform_id', $ig)->where('code', 'reel')->value('id');
            if ($formatoId !== null) {
                DB::table('creator_rates')->insert([
                    'creator_id' => $creadorId, 'content_format_id' => $formatoId,
                    'currency_code' => 'PEN', 'amount' => $tarifa,
                    'source' => 'self_declared', 'valid_from' => $ahora->copy()->subMonths(2)->toDateString(),
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            // Camila tiene 16: le corresponde tutela, y sin los dos documentos
            // la base impide que esté activa (BR-CREATOR-010).
            if ($nacimiento === '2010-06-20') {
                DB::table('creator_guardians')->insert([
                    'creator_id' => $creadorId,
                    'full_name' => 'Rosa Flores Huamán',
                    'relationship' => 'mother',
                    'document_country_code' => 'PE', 'document_type' => 'DNI', 'document_number' => '09887766',
                    'email' => 'rosa.flores@demo.pe',
                    'status' => 'pending',
                    'valid_from' => $ahora->toDateString(),
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }

        // Un cliente con su marca y una campaña.
        if (!DB::table('client_organizations')->where('client_code', 'DEMO01')->exists()) {
            $clienteId = DB::table('client_organizations')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'commercial_name' => 'Grupo Demostración',
                'client_code' => 'DEMO01', 'country_id' => $pe,
                'status' => 'active', 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            DB::table('client_tax_profiles')->insert([
                'client_organization_id' => $clienteId, 'country_id' => $pe,
                'legal_name' => 'Grupo Demostración S.A.C.',
                'tax_id_type' => 'RUC', 'tax_id_number' => '20512345678',
                'address_line1' => 'Av. Demostración 123', 'city' => 'Lima',
                'payment_term_days' => 30, 'valid_from' => $ahora->toDateString(),
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            $marcaId = DB::table('client_brands')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'client_organization_id' => $clienteId,
                'name' => 'Marca Demo', 'slug' => 'marca-demo',
                'status' => 'active', 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            DB::table('campaigns')->insert([
                'uuid' => (string) Str::uuid(),
                'code' => 'DEMO-001', 'name' => 'Lanzamiento Marca Demo',
                'client_organization_id' => $clienteId, 'client_brand_id' => $marcaId,
                'objective' => 'launch', 'status' => 'draft',
                'revenue_amount' => 18000.00, 'currency_code' => 'PEN',
                'included_revision_rounds' => 2,
                'starts_on' => $ahora->copy()->addWeek()->toDateString(),
                'ends_on' => $ahora->copy()->addMonth()->toDateString(),
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        $this->command?->info('Datos de demostración cargados.');
        $this->command?->line('  '.DB::table('creators')->count().' creadores · '
            .DB::table('client_organizations')->count().' clientes · '
            .DB::table('campaigns')->count().' campañas');
        $this->command?->line('  Camila Flores tiene 16 años: fíjate en su ficha y en la tutela pendiente.');
    }
}
