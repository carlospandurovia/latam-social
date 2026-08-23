<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Creator\Services\CoherenciaMetrica;
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

        // 3.5: desde esta iteración un creador «activo» no se declara, se
        // sostiene. `ck_creators_active_identity` exige identidad verificada, y
        // esa verificación exige un revisor y un documento archivado. Sin un
        // usuario en la base no hay revisor posible y la demo no puede montar
        // creadores activos: se dice y se para, en vez de reventar en un
        // INSERT con un mensaje que no explica nada.
        $revisorId = DB::table('users')->orderBy('id')->value('id');

        if ($revisorId === null) {
            $this->command?->error('No hay ningún usuario. Ejecuta antes: php artisan db:seed');

            return;
        }

        $documentoId = DB::table('files')->where('purpose', 'demo_identity')->value('id')
            ?? DB::table('files')->insertGetId([
                'uuid' => (string) Str::uuid(), 'disk' => 'local',
                'path' => 'demo/documento-identidad.pdf', 'original_name' => 'documento-identidad.pdf',
                'mime_type' => 'application/pdf', 'size_bytes' => 1024,
                'checksum_sha256' => hash('sha256', 'documento de demostracion'),
                'visibility' => 'private', 'purpose' => 'demo_identity',
                'uploaded_by_user_id' => $revisorId,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

        // DEC-059: unos términos de DEMOSTRACIÓN. El texto real se publica con
        // `php artisan terminos:publicar` cuando exista revisado por el abogado;
        // este seeder no corre en producción precisamente para que un texto
        // inventado no acabe siendo «lo que el creador aceptó».
        $terminosId = DB::table('terms_versions')->where('code', 'creator_terms')->whereNull('effective_to')->value('id')
            ?? DB::table('terms_versions')->insertGetId([
                'uuid' => (string) Str::uuid(), 'audience' => 'creator',
                'code' => 'creator_terms', 'version' => 'demo-1',
                'title' => 'Términos del creador (DEMOSTRACIÓN)',
                'body' => 'Texto de demostración. No tiene valor legal.',
                'content_sha256' => hash('sha256', 'Texto de demostración. No tiene valor legal.'),
                'effective_from' => $ahora->copy()->subMonths(6)->toDateString(),
                'published_by_user_id' => $revisorId,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

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
                // Las tres columnas de identidad van juntas o no van
                // (`ck_creators_identity_evidence`).
                'identity_verified_at' => $estado === 'active' ? $ahora : null,
                'identity_verified_by_user_id' => $estado === 'active' ? $revisorId : null,
                'identity_document_file_id' => $estado === 'active' ? $documentoId : null,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            if ($estado === 'active') {
                // La aceptación de términos y el histórico de estados. Sin
                // esto, la pantalla de activación de la demo enseñaría a un
                // creador activo al que «le faltan requisitos», que es
                // justamente lo que 3.5 viene a evitar.
                DB::table('terms_acceptances')->insert([
                    'uuid' => (string) Str::uuid(), 'terms_version_id' => $terminosId,
                    'subject_type' => 'creator', 'subject_id' => $creadorId,
                    'channel' => 'email', 'recorded_by_user_id' => $revisorId,
                    'evidence_file_id' => $documentoId,
                    'evidence_note' => 'Conformidad por correo (demostración)',
                    'accepted_at' => $ahora, 'created_at' => $ahora,
                ]);

                DB::table('status_transitions')->insert([
                    'entity_type' => 'creator', 'entity_id' => $creadorId,
                    'from_status' => 'pending', 'to_status' => 'active',
                    'actor_user_id' => $revisorId,
                    'reason' => 'Completitud operativa verificada (BR-CREATOR-006).',
                    'occurred_at' => $ahora,
                ]);
            }

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
                // 3.7 / H-05: verificada exige decir COMO y QUIEN. Las tres
                // columnas van juntas, como las de identidad.
                'verification_status' => $estado === 'active' ? 'verified' : 'unverified',
                'verification_method' => $estado === 'active' ? 'bio_code' : null,
                'verified_by_user_id' => $estado === 'active' ? $revisorId : null,
                'verified_at' => $estado === 'active' ? $ahora : null,
                'is_primary' => true, 'is_active' => true,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            // 3.7 / H-06: la coherencia se calcula, no se supone. Antes estas
            // filas nacian con `is_anomalous = 0` -- afirmando haber pasado unos
            // chequeos que no existian. Ahora pasan por el mismo servicio que
            // usa la pantalla, y una de las tres creadoras lleva un salto
            // absurdo a proposito para que la demo enseñe el caso marcado.
            $base = random_int(4_000, 90_000);
            $saltoAbsurdo = $alias === 'Cami Flores';

            // 20 y 5 dias: dentro de la ventana de comparacion, para que el
            // salto de Cami se detecte de verdad en vez de quedar fuera de rango.
            foreach ([[20, $base], [5, (int) ($base * ($saltoAbsurdo ? 4.7 : 1.08))]] as [$diasAtras, $seguidores]) {
                $capturada = $ahora->copy()->subDays($diasAtras);
                $metrica = [
                    'followers' => $seguidores,
                    'engagement_rate' => round(random_int(150, 720) / 100, 4),
                    'captured_at' => $capturada->format('Y-m-d H:i:s.v'),
                ];
                $veredicto = CoherenciaMetrica::evaluar($cuentaId, $metrica);

                DB::table('social_account_snapshots')->insert($metrica + [
                    'social_account_id' => $cuentaId,
                    'source' => 'self_declared',
                    'coherence_status' => $veredicto['estado'],
                    'anomaly_note' => CoherenciaMetrica::nota($veredicto['motivos']),
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
        $this->command?->line('  Sebastián Ríos está pendiente: abre «Revisar activación» y verás qué le falta.');
        $this->command?->line('  Cami Flores tiene un salto de seguidores absurdo: mira cómo queda marcado en sus redes.');
    }
}
