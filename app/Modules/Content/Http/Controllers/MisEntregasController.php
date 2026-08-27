<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers;

use App\Modules\Content\Http\Requests\EntregarRequest;
use App\Modules\Content\Http\Requests\PublicarRequest;
use App\Modules\Content\Services\Entregables;
use App\Modules\Content\Services\Publicaciones;
use App\Shared\Files\Almacen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lo que le toca entregar a un creador, y cómo lo entrega (8.1).
 *
 * ### Esta es la primera pantalla del portal del creador, y no rompe `T-09`
 *
 * `T-09` —el texto de los términos sin publicar— bloquea la **activación** de
 * creadores. Y para llegar aquí hay que estar en una campaña, para lo que hay que
 * ser `active`, para lo que hay que tener los términos aceptados
 * (`BR-CREATOR-006`). O sea: **quien llegue a esta pantalla ya aceptó los
 * términos**, por construcción.
 *
 * Así que se puede construir y probar hoy. Lo que sigue bloqueado es que exista
 * un creador real al que enseñársela.
 *
 * ### La propiedad se comprueba dos veces, y a propósito
 *
 * El permiso `creator.portal` dice *«esta persona puede ver un portal de
 * creador»*. **No dice cuál.** Lo que ata la pantalla a sus datos es
 * `creators.user_id = Auth::id()`, y eso se comprueba en cada acción: sin ello,
 * cualquier creador con el permiso podría entregar en nombre de otro cambiando un
 * número en la URL.
 *
 * `BR-SEC-006` dice además que un recurso de otro ámbito devuelve **404 y no
 * 403**: no se revela que exista.
 */
final class MisEntregasController
{
    public function index(): View
    {
        $creadorId = self::creadorDe();

        $participaciones = DB::table('campaign_creators as cc')
            ->join('campaigns as c', 'c.id', '=', 'cc.campaign_id')
            ->leftJoin('client_brands as b', 'b.id', '=', 'c.client_brand_id')
            ->where('cc.creator_id', $creadorId)
            ->whereNotNull('cc.accepted_at')
            ->orderByDesc('c.starts_on')
            ->get([
                'cc.id', 'cc.agreed_amount', 'cc.currency_code', 'cc.payment_term_days_snapshot',
                'c.name as campana', 'c.starts_on', 'c.ends_on', 'b.name as marca',
            ]);

        return view('entregas.mias', [
            'participaciones' => $participaciones->map(function (object $p): object {
                $p->entregables = Entregables::de((int) $p->id);

                return $p;
            }),
        ]);
    }

    public function entregar(EntregarRequest $peticion, string $uuid): RedirectResponse
    {
        $entregable = self::suyo($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();

        // El archivo se guarda ANTES de comprobar el veto sólo si el veto pasa:
        // al revés dejaría archivos huérfanos en el almacén cada vez que a
        // alguien le falte un hashtag.
        $motivos = Entregables::vetoParaEntregar(
            $entregable,
            $datos,
            $peticion->hasFile('archivo') ? 1 : null,
        );

        if ($motivos !== []) {
            return back()->withInput()->with('aviso', implode(' ', $motivos));
        }

        $archivoId = $peticion->hasFile('archivo')
            ? Almacen::guardar($peticion->file('archivo'), 'deliverable')
            : null;

        $numero = Entregables::entregar(
            $entregable,
            $datos,
            $archivoId,
            (int) Auth::id(),
            $peticion->ip(),
        );

        return redirect()->route('entregas.mias')->with('exito', $numero === 1
            ? 'Entregado. El equipo lo revisa y te dice algo.'
            : "Entregado (version {$numero}). El equipo revisa la nueva y te dice algo.");
    }

    /**
     * El creador pega el enlace de su post (8.6).
     *
     * Es quien lo sabe primero y quien tiene el enlace en la mano. El equipo
     * puede hacerlo por él —llega por WhatsApp, el creador no entra— desde la
     * pantalla interna, y en los dos casos queda quién lo reportó.
     */
    public function publicar(PublicarRequest $peticion, string $uuid): RedirectResponse
    {
        $entregable = self::suyo($uuid);

        /** @var array<string, mixed> $datos */
        $datos = $peticion->validated();
        $cuando = $datos['published_at'] ?? null;

        $motivos = Publicaciones::vetoParaPublicar($entregable, (string) $datos['url'], $cuando);

        if ($motivos !== []) {
            return back()->withInput()->with('aviso', implode(' ', $motivos));
        }

        Publicaciones::reportar(
            $entregable, (string) $datos['url'], $cuando, (int) Auth::id(), $peticion->ip(),
        );

        return redirect()->route('entregas.mias')->with('exito',
            'Registrado. El equipo comprueba que el post este publicado y ahi termina tu parte.');
    }

    // ------------------------------------------------------------------ apoyo

    /** El creador que hay detrás del usuario conectado. */
    private static function creadorDe(): int
    {
        $creadorId = DB::table('creators')
            ->where('user_id', Auth::id())
            ->whereNull('anonymized_at')
            ->value('id');

        if ($creadorId === null) {
            // Tiene el permiso y no tiene ficha de creador. Pasa si alguien
            // asigna el rol a mano. 404 y no 403: `BR-SEC-006`.
            throw new NotFoundHttpException('Esta cuenta no tiene ficha de creador.');
        }

        return (int) $creadorId;
    }

    /**
     * El entregable, **si es suyo**.
     *
     * El par (creador, entregable) y no sólo el uuid: el uuid es difícil de
     * adivinar, pero «difícil de adivinar» no es una regla de autorización.
     */
    private static function suyo(string $uuid): object
    {
        $entregable = DB::table('deliverables as d')
            ->join('campaign_creators as cc', 'cc.id', '=', 'd.campaign_creator_id')
            ->join('campaign_requirements as r', 'r.id', '=', 'd.campaign_requirement_id')
            ->where('d.uuid', $uuid)
            ->where('cc.creator_id', self::creadorDe())
            ->first([
                'd.id', 'd.uuid', 'd.status', 'd.submitted_at',
                // 8.6: el veto de publicación los necesita, y pedirlos aquí evita
                // que quien llama tenga que ir a buscar la misma fila otra vez.
                'd.approved_at', 'd.approved_version_id',
                'r.hashtags', 'r.mentions',
            ]);

        if ($entregable === null) {
            throw new NotFoundHttpException('No existe ese entregable.');
        }

        return $entregable;
    }
}
