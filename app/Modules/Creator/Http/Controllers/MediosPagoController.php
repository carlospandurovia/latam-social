<?php

declare(strict_types=1);

namespace App\Modules\Creator\Http\Controllers;

use App\Modules\Creator\Http\Requests\GuardarMedioPagoRequest;
use App\Modules\Creator\Http\Requests\RetirarMedioPagoRequest;
use App\Modules\Creator\Services\CuentasCompartidas;
use App\Shared\Audit\Bitacora;
use App\Shared\Crypto\CuentaBancaria;
use App\Shared\Eventos\Eventos;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Los medios de pago del creador (iteración 3.8).
 *
 * Es la última pieza de la puerta de activación (`BR-CREATOR-006`) y la fila
 * que dice **a dónde va el dinero**. Era también la tabla con menos controles
 * de las tres del bloque fiscal; los siete hallazgos están en
 * `docs/fase-3/3.8-MEDIOS-DE-PAGO.md`.
 *
 * Cinco cosas que no son evidentes:
 *
 * 1. **No hay «editar».** La cuenta es inmutable (`DEC-066`, `H-12`): cambiar
 *    de cuenta es dar de alta otra y retirar la anterior. La pantalla no
 *    esconde el botón — es que la base rechaza el `UPDATE`. Así queda el rastro
 *    de todas las cuentas que existieron, que es lo que hace falta para
 *    reconstruir a dónde se envió cada pago.
 *
 * 2. **Capturar y verificar son dos actos y dos personas** (`H-11`), como en el
 *    perfil fiscal. Aquí la razón es más directa todavía: quien teclea una
 *    cuenta bancaria no puede ser quien certifica que es la correcta.
 *
 * 3. **Verificar no habilita el pago: lo programa.** `BR-FIN-006` exige un
 *    período de enfriamiento, y al verificar se fija `eligible_from` en el
 *    futuro. La base impide acortarlo después.
 *
 * 4. **El número no se descifra en ninguna pantalla.** Sale la máscara. El
 *    único sitio donde hará falta el número entero es el fichero de pagos al
 *    banco, y eso es del módulo de finanzas.
 *
 * 5. **El aviso al canal de contacto anterior sigue siendo manual.**
 *    `BR-FIN-006` lo exige y el módulo Communication no existe. Se dice en
 *    pantalla en vez de callarlo (`T-10`).
 */
final class MediosPagoController
{
    public function index(string $uuid): View
    {
        $creador = $this->porUuid($uuid);

        return view('creadores.pagos', [
            'creador' => $creador,
            // Se anota al LEER, no se guarda: un disparador no puede actualizar
            // su propia tabla (`1442`), asi que la fila anterior se quedaba
            // diciendo `unique` mientras la cuenta estaba duplicada.
            'medios' => CuentasCompartidas::anotar(DB::table('creator_payment_methods as m')
                ->join('countries as c', 'c.id', '=', 'm.country_id')
                ->leftJoin('users as cap', 'cap.id', '=', 'm.created_by_user_id')
                ->leftJoin('users as ver', 'ver.id', '=', 'm.verified_by_user_id')
                ->leftJoin('users as cer', 'cer.id', '=', 'm.closed_by_user_id')
                ->leftJoin('creator_guardians as g', 'g.id', '=', 'm.owner_guardian_id')
                ->where('m.creator_id', $creador->id)
                ->orderByDesc('m.id')
                // Nunca `account_number_encrypted`: si no sale de la base, no
                // puede acabar en un log, en una vista en caché ni en un dump.
                ->get([
                    'm.id', 'm.method_type', 'm.bank_name', 'm.account_type',
                    'm.account_number_masked', 'm.holder_name', 'm.holder_document_type',
                    'm.holder_document_number', 'm.owner_type', 'm.status', 'm.currency_code',
                    'm.verified_at', 'm.eligible_from', 'm.closed_at', 'm.is_default',
                    'm.shared_account_status', 'm.created_by_user_id',
                    // La huella entra para poder resolver «compartida» al leer
                    // (`T-19`); `CuentasCompartidas::anotar()` la quita antes de
                    // que llegue a la plantilla.
                    'm.account_number_fingerprint', 'm.creator_id',
                    'c.name as pais', 'cap.name as capturado_por', 'ver.name as verificado_por',
                    'cer.name as retirado_por', 'g.full_name as tutor',
                ])),
            'paises' => DB::table('countries')->where('is_active', 1)->orderBy('name')->get(['id', 'name']),
            'monedas' => DB::table('currencies')->where('is_active', 1)->orderBy('code')->get(['code', 'name']),
            'tutores' => DB::table('creator_guardians')
                ->where('creator_id', $creador->id)
                ->where('status', 'active')
                ->get(['id', 'full_name']),
            'esMenor' => CarbonImmutable::parse((string) $creador->birth_date)->age < 18,
            'enfriamiento' => (int) config('latam.pagos.enfriamiento_horas', 24),
        ]);
    }

    public function store(GuardarMedioPagoRequest $request, string $uuid): RedirectResponse
    {
        $creador = $this->porUuid($uuid);

        // BR-CREATOR-009: sobre un creador anonimizado no se registran datos
        // personales nuevos, y una cuenta bancaria con nombre y documento del
        // titular lo es.
        if ($creador->anonymized_at !== null) {
            return back()->with('aviso', 'Este creador está anonimizado: no se pueden registrar medios de pago.');
        }

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        // BR-CREATOR-010: al menor se le paga a nombre del tutor. El id llega
        // del formulario, así que se comprueba que el tutor sea de ESTE creador
        // y que su tutela siga activa; la foránea solo dice que existe.
        if ($datos['owner_type'] === 'guardian') {
            $suyo = DB::table('creator_guardians')
                ->where('id', $datos['owner_guardian_id'])
                ->where('creator_id', $creador->id)
                ->where('status', 'active')
                ->exists();

            if (!$suyo) {
                return back()->withInput()->with('aviso', 'Ese tutor no es de este creador o su tutela no está activa.');
            }
        } else {
            $datos['owner_guardian_id'] = null;
        }

        $numero = (string) $datos['account_number'];
        unset($datos['account_number']);

        $huella = CuentaBancaria::huella($numero);

        // La base lo rechaza igual (`uq_cpm_open_account`), pero un 1062 en
        // pantalla no le dice al operador que esa cuenta ya está dada de alta.
        $repetida = DB::table('creator_payment_methods')
            ->where('creator_id', $creador->id)
            ->where('account_number_fingerprint', $huella)
            ->whereIn('status', ['pending', 'verified'])
            ->exists();

        if ($repetida) {
            return back()->withInput()->with('aviso', 'Esa cuenta ya está registrada y abierta para este creador.');
        }

        $id = (int) DB::table('creator_payment_methods')->insertGetId($datos + [
            'uuid' => (string) Str::uuid(),
            'creator_id' => $creador->id,
            'account_number_encrypted' => CuentaBancaria::cifrar($numero),
            'account_number_masked' => CuentaBancaria::mascara($numero),
            'account_number_fingerprint' => $huella,
            // Nace pendiente y sin fecha de elegibilidad. No es un hueco: es
            // que todavía no lo ha mirado nadie (H-02).
            'status' => 'pending',
            'created_by_user_id' => $request->user()?->getAuthIdentifier(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `shared_account_status` NO se escribe aquí a propósito: lo pone el
        // disparador `tg_cpm_compartida` leyendo el resto de la tabla. Si lo
        // escribiera la aplicación, una inserción podría afirmar «única» sin
        // haber mirado nada, que es exactamente el fallo de H-06.
        $marca = DB::table('creator_payment_methods')->where('id', $id)->value('shared_account_status');

        Bitacora::registrar(
            accion: 'creator_payment_method.created',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'medio_pago' => ['antes' => null, 'despues' => $id],
                // La MÁSCARA, nunca el número. La bitácora es inmutable: lo que
                // entre ahí no se puede sacar después.
                'cuenta' => ['antes' => null, 'despues' => CuentaBancaria::mascara($numero)],
                'titular' => ['antes' => null, 'despues' => $datos['owner_type']],
            ],
        );

        // `BR-CREATOR-007`, igual que en el perfil fiscal: se avisa AL CAPTURAR.
        // Aqui pesa mas todavia, porque lo que cambia es A DONDE VA EL DINERO.
        Eventos::ocurrio(
            nombre: 'creator.payment_method_captured',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            payload: [
                'correo' => (string) $creador->email,
                'nombre' => (string) $creador->display_name,
                'idioma' => (string) ($creador->locale ?? ''),
                'fecha' => now()->toDateString(),
            ],
        );

        $mensaje = 'Medio capturado. Queda PENDIENTE: tiene que verificarlo otra persona. '
            .'Se le ha avisado al creador (BR-CREATOR-007).';

        if ($marca === 'pending_review') {
            $mensaje .= ' Ojo: esa misma cuenta está registrada en otro creador y queda marcada para revisión (DEC-065).';
        }

        return redirect()->route('creadores.pagos', $uuid)->with('exito', $mensaje);
    }

    public function verificar(Request $request, string $uuid, int $id): RedirectResponse
    {
        $creador = $this->porUuid($uuid);
        $medio = $this->medioDe($creador, $id);

        if ($medio->status !== 'pending') {
            return back()->with('aviso', "Este medio ya está en «{$medio->status}».");
        }

        $usuarioId = (int) $request->user()?->getAuthIdentifier();

        // `ck_cpm_segregation` lo rechaza igual, pero conviene decir por qué.
        if ($usuarioId === (int) $medio->created_by_user_id) {
            return back()->with('aviso', 'No puedes verificar una cuenta que capturaste tú. '
                .'Tiene que revisarla otra persona (H-11).');
        }

        $horas = (int) config('latam.pagos.enfriamiento_horas', 24);
        $ahora = CarbonImmutable::now();

        // Verificar no habilita el pago: lo programa. `eligible_from` en el
        // futuro es BR-FIN-006, y la base impide acortarlo después.
        DB::table('creator_payment_methods')->where('id', $medio->id)->update([
            'status' => 'verified',
            'verified_at' => $ahora,
            'verified_by_user_id' => $usuarioId,
            'eligible_from' => $ahora->addHours($horas),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'creator_payment_method.verified',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'medio_pago' => ['antes' => null, 'despues' => $medio->id],
                'status' => ['antes' => 'pending', 'despues' => 'verified'],
                'elegible_desde' => ['antes' => null, 'despues' => $ahora->addHours($horas)->toDateTimeString()],
            ],
        );

        return redirect()->route('creadores.pagos', $uuid)->with(
            'exito',
            "Cuenta verificada. No es pagable hasta dentro de {$horas} h (BR-FIN-006). ".
            'Avisa al creador por su canal de contacto anterior: la regla lo exige y no hay envío automático (T-10).',
        );
    }

    /**
     * Rechazar (nunca sirvió) y desactivar (sirvió y se retira) son el mismo
     * gesto para el operador y dos estados distintos en la base. `H-13` prohíbe
     * el borrado, así que retirar es la única forma de sacar una cuenta de
     * circulación y tiene que dejar quién y cuándo.
     */
    public function retirar(RetirarMedioPagoRequest $request, string $uuid, int $id): RedirectResponse
    {
        $creador = $this->porUuid($uuid);
        $medio = $this->medioDe($creador, $id);

        if (in_array($medio->status, ['rejected', 'disabled'], true)) {
            return back()->with('aviso', "Este medio ya está en «{$medio->status}».");
        }

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        // Una cuenta que nunca llegó a verificarse se rechaza; una verificada
        // se desactiva. No es cosmética: `ck_cpm_rejected_clean` no admite un
        // rechazado con verificador escrito, porque eso diría que alguien lo dio
        // por bueno y luego lo rechazó, que no es lo que pasó.
        $nuevo = $medio->verified_at === null ? 'rejected' : 'disabled';

        DB::table('creator_payment_methods')->where('id', $medio->id)->update([
            'status' => $nuevo,
            // Un medio retirado deja de ser el predeterminado. Sin esto,
            // `ck_cpm_default_usable` rechaza el UPDATE entero — y con razón.
            'is_default' => 0,
            'closed_at' => now(),
            'closed_by_user_id' => $request->user()?->getAuthIdentifier(),
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'creator_payment_method.'.$nuevo,
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'medio_pago' => ['antes' => null, 'despues' => $medio->id],
                'status' => ['antes' => $medio->status, 'despues' => $nuevo],
                'motivo' => ['antes' => null, 'despues' => $datos['motivo']],
            ],
        );

        return redirect()->route('creadores.pagos', $uuid)->with('exito', 'Medio retirado y registrado.');
    }

    public function predeterminado(Request $request, string $uuid, int $id): RedirectResponse
    {
        $creador = $this->porUuid($uuid);
        $medio = $this->medioDe($creador, $id);

        if ($medio->status !== 'verified') {
            return back()->with('aviso', 'Solo un medio verificado puede ser el predeterminado (H-14).');
        }

        DB::transaction(function () use ($creador, $medio): void {
            // Quitar el anterior ANTES de poner el nuevo: `uq_cpm_default` solo
            // admite un predeterminado por creador y al revés la base lo
            // rechaza. Mismo orden que en `uq_ctp_current`.
            DB::table('creator_payment_methods')
                ->where('creator_id', $creador->id)
                ->where('is_default', 1)
                ->update(['is_default' => 0, 'updated_at' => now()]);

            DB::table('creator_payment_methods')->where('id', $medio->id)
                ->update(['is_default' => 1, 'updated_at' => now()]);
        });

        Bitacora::registrar(
            accion: 'creator_payment_method.default_set',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: ['medio_pago' => ['antes' => null, 'despues' => $medio->id]],
        );

        return redirect()->route('creadores.pagos', $uuid)->with('exito', 'Medio marcado como predeterminado.');
    }

    /**
     * El veredicto humano sobre una cuenta que aparece en dos creadores
     * (`DEC-065`). Marcarla nunca la rechaza: eso lo decide una persona.
     */
    public function revisarCompartida(RetirarMedioPagoRequest $request, string $uuid, int $id): RedirectResponse
    {
        $creador = $this->porUuid($uuid);
        $medio = $this->medioDe($creador, $id);

        // La condicion se CALCULA. Antes se leia `shared_account_status`, y la
        // fila del primer creador nunca decia `pending_review` aunque su cuenta
        // estuviera duplicada: no se podia revisar desde su pantalla.
        if (!CuentasCompartidas::estaCompartida((string) $medio->account_number_fingerprint, (int) $creador->id)) {
            return back()->with('aviso', 'Esta cuenta no la comparte ningun otro creador.');
        }

        if ($medio->shared_account_status === 'cleared') {
            return back()->with('aviso', 'Esta cuenta ya se reviso.');
        }

        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        DB::table('creator_payment_methods')->where('id', $medio->id)->update([
            'shared_account_status' => 'cleared',
            'updated_at' => now(),
        ]);

        Bitacora::registrar(
            accion: 'creator_payment_method.shared_cleared',
            tipoEntidad: 'creator',
            idEntidad: (int) $creador->id,
            cambios: [
                'medio_pago' => ['antes' => null, 'despues' => $medio->id],
                'cuenta_compartida' => ['antes' => 'pending_review', 'despues' => 'cleared'],
                'motivo' => ['antes' => null, 'despues' => $datos['motivo']],
            ],
        );

        return redirect()->route('creadores.pagos', $uuid)->with('exito', 'Cuenta compartida revisada y aceptada.');
    }

    // ------------------------------------------------------------------ apoyo

    private function medioDe(object $creador, int $id): object
    {
        $medio = DB::table('creator_payment_methods')
            ->where('id', $id)
            ->where('creator_id', $creador->id)
            ->first();

        if ($medio === null) {
            throw new NotFoundHttpException('Medio de pago no encontrado para este creador.');
        }

        return $medio;
    }

    private function porUuid(string $uuid): object
    {
        $creador = DB::table('creators')->where('uuid', $uuid)->first();

        if ($creador === null) {
            throw new NotFoundHttpException('Creador no encontrado.');
        }

        return $creador;
    }
}
