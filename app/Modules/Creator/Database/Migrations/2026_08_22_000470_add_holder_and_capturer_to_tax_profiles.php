<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos ambigüedades del perfil tributario, cerradas (iteración 3.6).
 *
 * **H-01 — el perfil no decía de quién era.** `creator_payment_methods` ya
 * distinguía si la cuenta es del creador o de su tutor, y
 * `creator_tax_documents` ya sabía quién emite el comprobante. Este perfil era
 * el único que no lo decía: para un menor, `BR-CREATOR-013` obliga a que el
 * perfil exigido sea **el del tutor**, así que el `tax_id_number` guardado es el
 * del tutor y nada en la fila lo indicaba. Un RUC sin titular es una ambigüedad
 * en un dato fiscal, y esas se pagan en la primera declaración.
 *
 * **H-03 — la separación de funciones se apagaba sola.** `created_by_user_id`
 * admitía NULL, y `ck_ctp_segregation` decía *«aprobador distinto del capturador,
 * salvo que alguno sea NULL»*. Bastaba aprobar un perfil sin decir quién lo
 * había capturado para saltarse el control entero. Se comprobó que funcionaba
 * antes de cerrarlo: un `INSERT` con `status='approved'`, `approved_by_user_id`
 * puesto y `created_by_user_id` nulo entraba sin protestar.
 *
 * Es el mismo patrón que `DEC-048`: **un NULL que desactiva un control**. Aquí
 * la columna pasa a NOT NULL, igual que en `payout_batches` (`DEC-044`), y la
 * restricción se simplifica *porque* el modelo se volvió más estricto.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Antes de endurecer la columna hay que saber si hay filas que la
        // violan. Una migración que revienta a mitad deja el esquema en un
        // estado que nadie declaró; mejor parar antes y decir qué hay que
        // arreglar.
        $huerfanos = DB::table('creator_tax_profiles')->whereNull('created_by_user_id')->count();

        if ($huerfanos > 0) {
            throw new RuntimeException(
                "Hay {$huerfanos} perfiles tributarios sin `created_by_user_id`. "
                .'Asígnales el usuario que los capturó antes de aplicar esta migración: '
                .'la columna pasa a ser obligatoria (H-03).',
            );
        }

        Schema::table('creator_tax_profiles', function (Blueprint $table): void {
            $table->string('holder_type', 10)->default('creator')->after('creator_id');
            $table->unsignedBigInteger('holder_guardian_id')->nullable()->after('holder_type');

            $table->index('holder_guardian_id', 'ix_ctp_holder');
            $table->foreign('holder_guardian_id', 'fk_ctp_holder')
                ->references('id')->on('creator_guardians')->restrictOnDelete();
        });

        // Calcada de `ck_cpm_owner`: o es del creador y no hay tutor, o es del
        // tutor y hay que decir cuál. No existe el titular a medias.
        Restriccion::comprobacion(
            tabla: 'creator_tax_profiles',
            nombre: 'ck_ctp_holder',
            expresion: "(holder_type = 'creator' AND holder_guardian_id IS NULL) "
                ."OR (holder_type = 'guardian' AND holder_guardian_id IS NOT NULL)",
            columnas: ['holder_type', 'holder_guardian_id'],
            mensaje: 'El titular del perfil fiscal es el creador o un tutor concreto.',
        );

        // Endurecer una columna que tiene una clave foránea encima exige
        // quitarla y volver a ponerla. **MySQL 8 lo rechaza de plano**:
        //
        //   ERROR 1832: Cannot change column 'created_by_user_id':
        //   used in a foreign key constraint 'fk_ctp_creator_user'
        //
        // MariaDB sí lo admite, y ahí está la trampa: la primera versión de
        // esta migración hacía el MODIFY a secas y pasaba en local. Solo se cae
        // en MySQL 8, que es lo que corre en CI y lo que se parece a producción.
        // Ver `H-08` en docs/fase-3/3.6-PERFIL-FISCAL.md.
        DB::statement('ALTER TABLE creator_tax_profiles DROP FOREIGN KEY fk_ctp_creator_user');
        DB::statement('ALTER TABLE creator_tax_profiles MODIFY created_by_user_id BIGINT UNSIGNED NOT NULL');
        DB::statement(
            'ALTER TABLE creator_tax_profiles ADD CONSTRAINT fk_ctp_creator_user '
            .'FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT',
        );

        // La rama del NULL ya no puede darse, así que sobra. Queda idéntica a
        // `ck_pbatch_segregation`.
        Restriccion::quitar('creator_tax_profiles', 'ck_ctp_segregation');
        Restriccion::comprobacion(
            tabla: 'creator_tax_profiles',
            nombre: 'ck_ctp_segregation',
            expresion: 'approved_by_user_id IS NULL OR approved_by_user_id <> created_by_user_id',
            columnas: ['approved_by_user_id', 'created_by_user_id'],
            mensaje: 'Quien captura el perfil fiscal no puede ser quien lo aprueba.',
        );
    }

    public function down(): void
    {
        Restriccion::quitar('creator_tax_profiles', 'ck_ctp_segregation');
        Restriccion::comprobacion(
            tabla: 'creator_tax_profiles',
            nombre: 'ck_ctp_segregation',
            expresion: 'approved_by_user_id IS NULL OR created_by_user_id IS NULL '
                .'OR approved_by_user_id <> created_by_user_id',
            columnas: ['approved_by_user_id', 'created_by_user_id'],
            mensaje: 'Quien captura el perfil fiscal no puede ser quien lo aprueba.',
        );

        // La vuelta atrás necesita el mismo baile con la clave foránea.
        DB::statement('ALTER TABLE creator_tax_profiles DROP FOREIGN KEY fk_ctp_creator_user');
        DB::statement('ALTER TABLE creator_tax_profiles MODIFY created_by_user_id BIGINT UNSIGNED NULL');
        DB::statement(
            'ALTER TABLE creator_tax_profiles ADD CONSTRAINT fk_ctp_creator_user '
            .'FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT',
        );

        Restriccion::quitar('creator_tax_profiles', 'ck_ctp_holder');

        Schema::table('creator_tax_profiles', function (Blueprint $table): void {
            $table->dropForeign('fk_ctp_holder');
            $table->dropIndex('ix_ctp_holder');
            $table->dropColumn(['holder_guardian_id', 'holder_type']);
        });
    }
};
