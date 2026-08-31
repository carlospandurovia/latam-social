<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Communication\Services\Plantillas;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Las plantillas de correo que el sistema necesita para funcionar (4.13).
 *
 * ### Por qué van en un seeder y no en un archivo que alguien publique a mano
 *
 * Porque **sin plantilla no sale el aviso**, y `Correo::enviar()` lanza una
 * excepción a propósito cuando no la encuentra: es un fallo de configuración de
 * la plataforma y tiene que verse. Dejar eso a que alguien se acuerde de correr
 * `correos:publicar` en cada entorno es exactamente el modo de fallo que
 * `DEC-085` —los `GRANT` que llevan un mes sin ejecutarse— ya demostró.
 *
 * Un texto de aviso legalmente relevante puede sustituirse después publicando la
 * siguiente versión; lo que no puede es **no existir**.
 *
 * ### Los textos no llevan el dato dentro
 *
 * Decisión de negocio (2026-08-26). Un correo se lee en pantallas ajenas, se
 * reenvía y se queda en buzones que no controlamos — y el escenario del que nos
 * defendemos es precisamente que alguien tenga acceso a ese buzón. Para decir
 * «yo no fui» no hace falta ver el número de cuenta.
 */
final class PlantillasDeCorreoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::plantillas() as [$codigo, $asunto, $cuerpo]) {
            // Idempotente: si ya hay una version de ese codigo, no se toca. El
            // seeder no debe pisar un texto que alguien haya publicado despues
            // --y menos uno revisado legalmente--.
            if (DB::table('email_templates')->where('code', $codigo)->exists()) {
                continue;
            }

            preg_match_all('/\{\{\s*([A-Za-z_][\w.]*)\s*\}\}/', $asunto."\n".$cuerpo, $c);

            Plantillas::publicar(
                codigo: $codigo,
                idioma: 'es',
                version: '2026.1',
                asunto: $asunto,
                cuerpo: $cuerpo,
                // Una fecha fija y no `now()`: si el seeder corre dos veces en
                // dias distintos --entornos nuevos, restauraciones-- la vigencia
                // tiene que ser la misma en todos.
                desde: '2026-01-01',
                variables: array_values(array_unique($c[1])),
            );
        }
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private static function plantillas(): array
    {
        $pie = "\n\nSi no ha sido usted, responda a este correo cuanto antes: el cambio todavia\n"
            ."no ha surtido efecto y lo podemos parar.\n\n"
            ."Este aviso es automatico y no incluye el detalle del cambio a proposito.\n"
            .'LATAM Social';

        return [
            [
                'creator.tax_profile_changed',
                'Se registro un cambio en sus datos fiscales',
                "Hola {{ nombre }}:\n\n"
                ."El {{ fecha }} alguien registro un cambio en sus datos fiscales.\n\n"
                ."Todavia esta pendiente de aprobacion interna: no surtira efecto hasta que\n"
                .'otra persona de nuestro equipo lo revise.'.$pie,
            ],
            // ---- `9.19b`: hay terminos nuevos que aceptar --------------------
            //
            // Sale SOLO cuando el cambio es de fondo (`9.16`): avisar de una
            // errata corregida molestaria a todo el mundo, y el aviso que llega
            // por todo deja de leerse.
            //
            // Dice el plazo y **que pasa despues**, que es lo que de verdad
            // hace que alguien entre a leerlos. «Hay terminos nuevos» a secas
            // se pospone; «el dia X dejas de poder trabajar» no.
            [
                'creator.terms_reacceptance',
                'Hay una version nueva de los terminos que tienes que aceptar',
                "Hola {{ nombre }}:\n\n"
                ."Hemos publicado una version nueva de los terminos ({{ titulo }},\n"
                ."version {{ version }}) y hace falta que la aceptes.\n\n"
                ."Tienes {{ dias }} dias desde hoy. Puedes leerla y aceptarla aqui:\n\n"
                ."{{ enlace }}\n\n"
                ."Si pasa ese plazo sin que la aceptes podras seguir viendo tus campanas y\n"
                ."tus ingresos, pero no cambiar nada, hasta que la aceptes.\n\n"
                .'LATAM Social',
            ],
            // ---- `5.9` y `4.1`: los enlaces de contrasena --------------------
            //
            // Ninguno de los dos dice por que se aprobo, quien lo pidio ni desde
            // donde. Un correo con enlace de contrasena es lo primero que se
            // falsifica: cuanto menos contexto lleve, menos material hay para
            // imitarlo, y menos se pierde si el buzon es de otro.
            [
                'user.password_initial',
                'Su acceso a LATAM Social',
                "Hola {{ nombre }}:\n\n"
                ."Ya tiene cuenta en LATAM Social. Para entrar por primera vez tiene que\n"
                ."elegir su contrasena aqui:\n\n"
                ."{{ enlace }}\n\n"
                ."El enlace vale {{ horas }} horas (hasta el {{ caduca }}) y solo se puede usar\n"
                ."una vez. Pasado ese plazo, pida otro desde la pantalla de acceso.\n\n"
                ."Nadie de LATAM Social conoce ni le va a pedir su contrasena.\n\n"
                .'LATAM Social',
            ],
            [
                'user.password_reset',
                'Recuperar su contrasena de LATAM Social',
                "Hola {{ nombre }}:\n\n"
                ."Alguien pidio recuperar la contrasena de esta cuenta. Si fue usted, elija\n"
                ."una nueva aqui:\n\n"
                ."{{ enlace }}\n\n"
                ."El enlace vale {{ horas }} hora(s) (hasta el {{ caduca }}) y solo se puede usar\n"
                ."una vez. Al usarlo se cerraran todas las sesiones abiertas de la cuenta.\n\n"
                ."Si no lo pidio usted, no hay nada que hacer: su contrasena actual sigue\n"
                ."siendo valida y este enlace caduca solo. Si le pasa mas veces, avisenos.\n\n"
                .'LATAM Social',
            ],
            // ---- `7.6`: la invitacion a una campana --------------------------
            //
            // Lleva DENTRO lo que el creador necesita para decidir --campana e
            // importe-- porque de eso va el correo. Lo que no lleva es nada
            // interno: ni el ingreso del cliente, ni lo que cobran los demas,
            // ni el presupuesto (`BR-SEC-001`).
            [
                'campaign.invitation',
                'Te queremos invitar a una campana',
                "Hola {{ nombre }}:\n\n"
                ."Tenemos una campana que creemos que te encaja: {{ campana }}.\n\n"
                ."Lo que cobrarias: {{ importe }}.\n\n"
                ."Aqui estan las fechas y el detalle, y ahi mismo puedes aceptar o decir que\n"
                ."no puedes:\n\n"
                ."{{ enlace }}\n\n"
                ."Tienes {{ horas }} horas (hasta el {{ caduca }}). Pasado ese plazo la\n"
                ."invitacion caduca sola y no pasa nada: si te interesa mas adelante,\n"
                ."escribenos.\n\n"
                ."Decir que no tambien nos sirve, y mucho: nos evita insistir y nos ayuda a\n"
                ."proponerte cosas que si te encajen.\n\n"
                .'LATAM Social',
            ],
            // ---- `T-38` y el aviso al invitador ------------------------------
            //
            // Estos TRES van al equipo, no al creador, y por eso si llevan datos
            // dentro: el destinatario ya tiene acceso a la campana. Lo que no
            // llevan es el enlace de la invitacion --ese es del creador--.
            [
                'campaign.invitation_question',
                'Una pregunta sobre {{ campana }}',
                "Hola {{ nombre }}:\n\n"
                ."{{ creador }} ha preguntado sobre la campana {{ campana }}, antes de\n"
                ."contestar a la invitacion:\n\n"
                ."  «{{ pregunta }}»\n\n"
                ."Su invitacion sigue corriendo y caduca el {{ caduca }}: preguntar no mueve\n"
                ."el plazo. Si necesita mas tiempo, anule la invitacion y mandele otra.\n\n"
                ."Contestele por correo, que es donde esta.\n\n"
                .'LATAM Social',
            ],
            [
                'campaign.invitation_accepted',
                '{{ creador }} acepto la invitacion',
                "Hola {{ nombre }}:\n\n"
                ."{{ creador }} ha aceptado participar en {{ campana }} por {{ importe }}.\n\n"
                ."El importe queda cerrado por las dos partes: cambiarlo ahora exige una\n"
                ."enmienda aceptada por ambas (BR-CAMPAIGN-003).\n\n"
                .'LATAM Social',
            ],
            [
                'campaign.invitation_declined',
                '{{ creador }} no puede esta vez',
                "Hola {{ nombre }}:\n\n"
                ."{{ creador }} ha rechazado la invitacion a {{ campana }}.\n\n"
                ."Motivo: {{ motivo }}\n\n"
                ."Puede volver a invitarlo con otra oferta cuando quiera: el rechazo no\n"
                ."cierra la campana para el, y quedan las dos rondas en el historial.\n\n"
                .'LATAM Social',
            ],
            // ---- `8.3`: la correccion -----------------------------------
            //
            // Solo se avisa de la CORRECCION, no de la aprobacion. No es un
            // olvido: una correccion trae una fecha limite y una accion, y si el
            // creador no la ve a tiempo el retraso es de la campana. Una
            // aprobacion no le pide nada, y la ve en su portal la proxima vez
            // que entre. Un buzon que recibe correos que no piden nada es un
            // buzon que se filtra a una carpeta --y entonces tampoco se lee el
            // que si pedia algo--.
            //
            // El comentario VA DENTRO a proposito, al contrario que en los
            // avisos de datos sensibles: aqui el contenido del correo es
            // exactamente lo que el creador necesita para trabajar, y obligarle
            // a entrar al portal para leer una frase es una vuelta de mas en un
            // proceso que existe para ahorrarlas.
            [
                'content.changes_requested',
                'Hay que retocar tu entrega de {{ campana }}',
                "Hola {{ nombre }}:\n\n"
                ."Hemos revisado tu {{ formato }} de {{ campana }} y hay que retocarlo antes\n"
                ."de darlo por bueno:\n\n"
                ."  «{{ comentario }}»\n\n"
                ."La fecha limite sigue siendo el {{ limite }}. Sube la version corregida\n"
                ."desde tu portal:\n\n"
                ."{{ enlace }}\n\n"
                ."No hace falta que borres nada: cada envio es una version nueva y la\n"
                ."anterior se queda en el historial.\n\n"
                .'LATAM Social',
            ],
            // ---- `8.7`: el post no aparece --------------------------------
            //
            // Lleva el motivo DENTRO por lo mismo que el aviso de correccion de
            // 8.3: es lo que el creador necesita para arreglarlo, y obligarle a
            // entrar al portal para leer una frase es una vuelta de mas.
            //
            // Y no dice «no publicaste»: dice «no lo encontramos». La diferencia
            // importa cuando la cuenta es privada o el enlace se copio mal, que
            // es la mayoria de las veces.
            [
                'content.publication_rejected',
                'No encontramos tu post de {{ campana }}',
                "Hola {{ nombre }}:\n\n"
                ."Fuimos a comprobar el post que registraste para {{ campana }} y no lo\n"
                ."pudimos dar por bueno:\n\n"
                ."  «{{ motivo }}»\n\n"
                ."El enlace que revisamos fue este:\n"
                ."{{ enlace_post }}\n\n"
                ."Si el post esta publicado y el enlace estaba mal, o si la cuenta era\n"
                ."privada y ya no lo es, puedes registrar el enlace otra vez desde tu\n"
                ."portal:\n\n"
                ."{{ enlace }}\n\n"
                ."Tu contenido sigue aprobado: lo unico que falta es el enlace.\n\n"
                .'LATAM Social',
            ],
            // ---- `8.5`: el visto bueno del cliente -------------------------
            //
            // El primer correo del sistema dirigido a alguien de la MARCA. No
            // lleva ni un solo numero: ni el importe del creador, ni el
            // presupuesto, ni el margen (`BR-SEC-001`, rojo). Lleva la campana,
            // el formato, quien lo hizo y el enlace.
            //
            // Y dice que la respuesta la revisa una persona, porque un cliente
            // que pulsa «me vale» y no ve nada moverse tiene derecho a saberlo
            // (`DEC-151`).
            [
                'content.client_approval',
                'Una pieza de {{ campana }} espera su visto bueno',
                "Buenos dias:\n\n"
                ."Ya tenemos lista una pieza de {{ campana }} y nos gustaria que le\n"
                ."diera un vistazo antes de publicarla.\n\n"
                ."  Marca:    {{ marca }}\n"
                ."  Formato:  {{ formato }}\n"
                ."  Creador:  {{ creador }}\n\n"
                ."Desde este enlace puede verla y decirnos si le vale o que habria que\n"
                ."cambiar. No hace falta usuario ni contrasena:\n\n"
                ."{{ enlace }}\n\n"
                ."El enlace vence el {{ limite }}.\n\n"
                ."Lo que nos conteste queda registrado y lo revisa su contacto en LATAM\n"
                ."Social, que le confirmara los siguientes pasos.\n\n"
                .'LATAM Social',
            ],
            // ---- `8.8`: el post ya no esta -------------------------------
            //
            // No dice «incumpliste»: dice «no lo encontramos» y le pregunta. La
            // mayoria de las veces la cuenta se puso privada o el post se movio,
            // y acusar a alguien por eso cuesta mas que preguntarle.
            //
            // Y dice EXPRESAMENTE que el pago esta parado. Enterarse de eso
            // cuando no llega el dinero es peor que enterarse ahora.
            [
                'content.permanence_broken',
                'Tu post de {{ campana }} ya no aparece',
                "Hola {{ nombre }}:\n\n"
                ."Fuimos a comprobar tu post de {{ campana }} y ya no lo encontramos:\n\n"
                ."  \u{ab}{{ motivo }}\u{bb}\n\n"
                ."El enlace que revisamos fue este:\n"
                ."{{ enlace_post }}\n\n"
                ."El acuerdo era que siguiera publicado hasta el {{ hasta }}, asi que\n"
                ."mientras tanto el pago de esa entrega queda en pausa.\n\n"
                ."Si el post sigue publicado y el enlace cambio, o si la cuenta estaba\n"
                ."en privado, escribenos y lo revisamos otra vez. Si lo bajaste por algo,\n"
                ."cuentanoslo: se puede arreglar hablandolo.\n\n"
                ."{{ enlace }}\n\n"
                .'LATAM Social',
            ],
            [
                'creator.payment_method_changed',
                'Se registro un cambio en sus datos de pago',
                "Hola {{ nombre }}:\n\n"
                ."El {{ fecha }} alguien registro un cambio en su medio de pago.\n\n"
                ."Todavia esta pendiente de verificacion interna: no se le pagara a esa cuenta\n"
                .'hasta que otra persona de nuestro equipo la verifique.'.$pie,
            ],
            // 9.7: el pago confirmado y el devuelto. Al creador se le
            // escribe cuando el dinero SALIO DE VERDAD --confirmado contra el
            // extracto-- y cuando vuelve. Avisar al enviar seria avisar de una
            // intencion, y el segundo correo desmentiria al primero.
            //
            // Sin numero de cuenta ni referencia bancaria, por lo mismo que el
            // resto: un correo se lee en pantallas ajenas y se reenvia.
            [
                'finance.payout_confirmed',
                'Le pagamos {{ importe }} {{ moneda }}',
                "Hola {{ nombre }}:\n\n"
                ."Ya le transferimos {{ importe }} {{ moneda }} y el banco nos lo confirmo.\n"
                ."Segun su entidad, el abono puede tardar unas horas en verse.\n\n"
                ."El detalle esta en Mis ingresos: {{ enlace }}\n\n"
                ."Si en dos dias habiles no lo ve, responda a este correo.\n\n"
                .'LATAM Social',
            ],
            [
                'finance.payout_returned',
                'Su pago volvio: revise su cuenta',
                "Hola {{ nombre }}:\n\n"
                ."El banco nos devolvio la transferencia de {{ importe }} {{ moneda }}.\n"
                ."Motivo: {{ motivo }}\n\n"
                ."No ha perdido nada: el importe le sigue debiendose y entra en el proximo\n"
                ."pago. Lo que necesitamos es que revise sus datos bancarios en su portal,\n"
                ."porque si estan mal volvera a pasar.\n\n"
                ."Sus ingresos: {{ enlace }}\n\n"
                .'LATAM Social',
            ],
        ];
    }
}
