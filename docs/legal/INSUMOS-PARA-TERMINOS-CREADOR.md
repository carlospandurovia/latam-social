# Insumos para los términos del creador — para revisión jurídica

> **Versión 0.2 — 2026-08-30.** Corregidos dos puntos del §6 que habían quedado
> viejos: la notificación de `BR-CREATOR-007` **sí** existe desde la iteración
> 4.13, y el creador ya entra al sistema aunque siga sin aceptar por sí mismo.

> **Qué es este documento y qué no es.**
>
> **No son los términos.** No contiene lenguaje contractual y no debe copiarse a
> uno. `§56` del prompt maestro prohíbe implementar supuestos legales sin
> identificarlos para revisión jurídica, y unos términos redactados por el
> equipo técnico convertidos en «lo que el creador aceptó» son exactamente eso.
>
> **Es la lista de lo que el sistema ya hace**, sacada del código y del esquema,
> no de suposiciones. Sirve para que quien redacte los términos sepa qué
> conductas tiene que cubrir, y para detectar lo contrario: cláusulas que el
> software no podría cumplir.
>
> Cada punto lleva la regla o la restricción concreta que lo impone, para que se
> pueda verificar en el repositorio en vez de creerlo.

---

## 1. Lo que el creador entrega y lo que se le exige

| El sistema | Regla | Qué tiene que decir el contrato |
|---|---|---|
| Exige **datos fiscales legales y vigentes** en el país del creador. No existe el pago informal: sin perfil tributario aprobado no se activa, no se le invita y no se le liquida | `BR-CREATOR-013` | Que el creador está obligado a mantener su situación fiscal vigente y a comunicar los cambios |
| Practica **retención** cuando corresponde, con la tasa y la norma congeladas en el perfil aprobado | `ck_ctp_rate_required` | Cómo se comunica la retención y qué pasa si el régimen del creador cambia |
| Exige **verificación de identidad** con tres datos inseparables: cuándo, qué revisor y qué documento quedó archivado | `BR-CREATOR-015` | El tratamiento del documento de identidad: para qué se guarda, cuánto tiempo, quién lo ve |
| Exige **acreditar la propiedad de cada cuenta social** por un método de una lista cerrada, con constancia de quién lo comprobó | `BR-CREATOR-018` | Que el creador declara ser titular de las cuentas y responde de ello |
| Marca toda métrica declarada por el creador como `self_declared` y le aplica chequeos de coherencia | `BR-CREATOR-004` | Consecuencias de declarar métricas falsas |

## 2. Dinero

| El sistema | Regla | Qué tiene que decir el contrato |
|---|---|---|
| Paga a **30 días por defecto**, configurables por creador, contados **desde la verificación de la publicación** — no desde la entrega ni desde la factura | `BR-FIN-012` | El plazo exacto y desde qué hecho se cuenta. Es una de las cláusulas que más se discuten |
| La tarifa declarada por el creador **es una referencia, no un compromiso**. El precio vinculante es el monto congelado en la participación de cada campaña | `BR-CREATOR-008` | Que publicar una tarifa no obliga a nadie, y que lo vinculante es lo pactado por campaña |
| Un importe solo pasa a cobrable cuando la participación está completada, el contenido aprobado y la publicación verificada | `BR-FIN-003` | Qué tiene que ocurrir exactamente para que nazca el derecho de cobro |
| Aplica el **tipo de cambio de la fecha de la operación**, no el actual, y los históricos no se recalculan | `BR-FIN-009` | En qué moneda se pacta, en cuál se paga y quién asume la variación |
| Un medio de pago nuevo o modificado **no es elegible hasta pasado un enfriamiento** y reverificado | `BR-FIN-006` | Que cambiar la cuenta bancaria retrasa el cobro, y por qué |
| Ningún registro financiero se elimina; una anulación se representa con estado y asientos compensatorios | `BR-FIN-008` | Cuánto tiempo se conservan los registros de pago y con qué base legal |

## 3. Menores de edad — **el bloque que más revisión necesita**

| El sistema | Regla |
|---|---|
| Admite creadores menores de 18 años **solo con autorización firmada del padre o tutor**, acreditación de parentesco y documento de identidad del tutor | `BR-CREATOR-010` |
| Para un menor, el perfil tributario tiene que ser **el del tutor activo**, no el del propio menor | `BR-CREATOR-017` |
| Un menor con tutela activa no recibe invitaciones a campañas cuya edad mínima supere la suya, ni a categorías restringidas | `BR-CREATOR-012` |

**Preguntas abiertas para el abogado:**

1. ¿Quién firma los términos cuando el creador es menor: el tutor, los dos, o el menor con ratificación? Hoy el sistema registra la aceptación **a nombre del creador**, no del tutor.
2. ¿Qué pasa al cumplir 18? ¿Hay que volver a aceptar en nombre propio?
3. ¿La autorización del tutor caduca?

## 4. Datos personales y baja

| El sistema | Regla | Tensión que hay que resolver en el texto |
|---|---|---|
| Ante una solicitud de eliminación, **elimina o anonimiza los datos personales pero conserva los registros financieros y fiscales** | `BR-CREATOR-009` | Hay que explicar por qué no se borra todo, y con qué base legal se conservan esos registros |
| **La aceptación de términos no se borra ni se revoca**: queda con canal, fecha y evidencia | `BR-CREATOR-016` | Que el propio consentimiento es evidencia y sobrevive a la baja |
| **Avisa al creador por correo** cuando alguien toca sus datos fiscales o su medio de pago, mientras el cambio todavía se puede parar | `BR-CREATOR-007` | Que ese aviso existe y por qué canal llega |
| Desde `T-16`, **18 tablas rechazan el borrado físico**, incluidas las de aceptaciones, tutela y perfiles fiscales | migración `000650` | Plazos de conservación por tipo de dato |

## 5. Versionado de los términos — cómo funciona, para que el texto no lo contradiga

- La aceptación es **de una versión concreta**, no de «los términos» en abstracto (`BR-CREATOR-016`).
- Publicar una versión nueva **cierra la anterior el día antes**: nunca hay dos vigentes el mismo día (iteración 3.13).
- Cuando se publica una versión nueva, **la aceptación anterior deja de contar** y el creador vuelve a quedar incompleto hasta que acepte la nueva.

**Consecuencia práctica que conviene conocer antes de redactar:** cada cambio de términos deja a **todos** los creadores sin cumplir el requisito hasta que acepten. No hay hoy una noción de «cambio menor que no requiere reaceptación». Si el negocio la quiere, hay que decidirla ahora y construirla.

## 6. Lo que el sistema **no** hace hoy

Conviene que el texto no prometa nada de esto:

- **El creador no acepta por sí mismo.** Desde `8.1` y `9.8` sí entra al sistema —ve lo que tiene que entregar y ve su dinero— pero la aceptación de términos se sigue registrando por un canal externo (correo, por ejemplo) con evidencia adjunta, y la captura un operador interno.
- **No hay firma electrónica** con validez reforzada: hay registro de aceptación con evidencia archivada.
- **El texto aceptado se guarda pero ninguna pantalla lo muestra.** La de activación enseña el título y la versión vigente, no el articulado. Hay que resolverlo antes de que un creador acepte: aceptar algo que no se puede leer no es aceptar.
- **No existe anular una aceptación.** Se puede publicar una versión nueva, no deshacer una aceptación.

## 7. Datos que el texto necesita y que no están en el código

- Razón social, RUC y domicilio de **Soluciones Tecnológicas a Medida S.A.C.**
- Ley aplicable y fuero.
- Canal de reclamaciones y plazo de respuesta.
- Responsable de protección de datos y correo de contacto.
- Política de propiedad intelectual del contenido: **es la laguna más grande de esta lista.** El sistema no modela hoy licencia, exclusividad ni plazo de uso del contenido, y esas tres cosas son el núcleo de un contrato de marketing de creadores. Si el abogado las incluye, habrá que construirlas.

---

## Cómo publicarlos cuando estén

```
php artisan terminos:publicar creator_terms 2026.1 --archivo=docs/legal/terminos-creador-2026.1.md --titulo="Terminos del creador"
```

La ruta es un archivo de verdad. El comando rechaza un texto vacío, no deja
republicar una versión ya existente y cierra la anterior el día antes.
