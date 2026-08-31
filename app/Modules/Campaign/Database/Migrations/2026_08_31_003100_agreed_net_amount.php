<?php

declare(strict_types=1);

use App\Shared\Database\Restriccion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Se pacta lo que el creador RECIBE (9.18).
 *
 * ### El problema, con tus palabras
 *
 * > «Para que no lo sienta, debo tener un campo donde ponga el valor a pagar, y
 * > otro donde vaya la retención por defecto, y luego un tercero donde vaya el
 * > monto que realmente le pagaré… te pagaré 100 soles pero en realidad lo que
 * > estaría provisionando para pagarle sería 141.84.»
 *
 * `agreed_amount` existe desde `2.2` y es **el costo**: lo que la campaña
 * provisiona y lo que el libro mayor devenga. Eso no cambia y no se toca —
 * cambiarle el significado a una columna de la que ya cuelgan devengos, pagos y
 * la rentabilidad de `9.10` sería reescribir el pasado en silencio—.
 *
 * Lo que se añade es **lo que el creador recibe**, y la tasa con la que se
 * calculó. Con `agreed_basis = 'net'`, el operador teclea 100 y el sistema
 * escribe 141,8440 en `agreed_amount`: el creador ve su cifra y la campaña
 * provisiona la de verdad.
 *
 * ### Los números se congelan
 *
 * `withholding_rate_snapshot`, `min_margin_pct_snapshot` y
 * `margin_basis_snapshot` son copias de la política vigente **el día en que se
 * pactó**. Es el mismo criterio que `payment_term_days_snapshot` de `BR-FIN-012`:
 * subir mañana el umbral del 20 al 25 % no puede convertir en mala una
 * participación que se juzgó buena con el umbral de hoy.
 *
 * ### Y el motor comprueba la aritmética
 *
 * `ck_ccr_neto_cuadra` rehace la resta. Un neto que no cuadra con su bruto y su
 * tasa es una cifra que alguien tocó por un lado y no por el otro, y el sitio
 * donde se descubriría es la conversación con el creador que cobró de menos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_creators', function (Blueprint $table): void {
            // 'gross' = lo pactado es el costo (lo de siempre). 'net' = lo
            // pactado es lo que recibe el creador. Las filas que ya existen son
            // 'gross' porque nadie dijo lo contrario: no se les inventa una
            // intencion que no consta.
            $table->string('agreed_basis', 10)->default('gross')->after('agreed_amount');
            $table->decimal('agreed_net_amount', 18, 4)->nullable()->after('agreed_basis');
            $table->decimal('withholding_rate_snapshot', 7, 4)->nullable()->after('agreed_net_amount');
            $table->decimal('min_margin_pct_snapshot', 7, 4)->nullable()->after('withholding_rate_snapshot');
            $table->string('margin_basis_snapshot', 10)->nullable()->after('min_margin_pct_snapshot');
        });

        foreach (self::restricciones() as [$tabla, $nombre, $expresion, $columnas, $mensaje]) {
            Restriccion::comprobacion(
                tabla: $tabla, nombre: $nombre, expresion: $expresion,
                columnas: $columnas, mensaje: $mensaje,
            );
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::restricciones()) as [$tabla, $nombre]) {
            Restriccion::quitar($tabla, $nombre);
        }

        Schema::table('campaign_creators', function (Blueprint $table): void {
            $table->dropColumn(['agreed_basis', 'agreed_net_amount', 'withholding_rate_snapshot',
                'min_margin_pct_snapshot', 'margin_basis_snapshot']);
        });
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>,4:string}> */
    private static function restricciones(): array
    {
        return [
            ['campaign_creators', 'ck_ccr_base', "agreed_basis IN ('gross','net')",
                ['agreed_basis'], 'Lo pactado es el costo o es el neto del creador.'],

            // El neto NUNCA es mayor que el costo: la retencion no puede ser
            // negativa. Un neto por encima del bruto es un signo cambiado.
            ['campaign_creators', 'ck_ccr_neto',
                'agreed_net_amount IS NULL OR (agreed_net_amount >= 0 AND agreed_net_amount <= agreed_amount)',
                ['agreed_net_amount', 'agreed_amount'],
                'Lo que recibe el creador no puede pasar de lo que cuesta.'],

            // Media pactacion no vale: si se pacto el neto, tiene que constar
            // con que tasa se convirtio. Sin ella nadie puede rehacer la cuenta.
            ['campaign_creators', 'ck_ccr_neto_completo',
                "agreed_basis <> 'net' OR (agreed_net_amount IS NOT NULL AND withholding_rate_snapshot IS NOT NULL)",
                ['agreed_basis', 'agreed_net_amount', 'withholding_rate_snapshot'],
                'Si se pacta el neto hay que decir con que retencion se calculo.'],

            // La resta, rehecha por el motor. Un centimo de tolerancia porque
            // `neto = bruto x (100 - tasa) / 100` no cae exacto en DECIMAL(18,4)
            // para casi ningun par de valores.
            ['campaign_creators', 'ck_ccr_neto_cuadra',
                "agreed_basis <> 'net' OR ABS(agreed_amount * (100 - withholding_rate_snapshot) / 100"
                .' - agreed_net_amount) <= 0.01',
                ['agreed_basis', 'agreed_amount', 'withholding_rate_snapshot', 'agreed_net_amount'],
                'El neto no cuadra con el costo y la retencion con la que se calculo.'],
        ];
    }
};
