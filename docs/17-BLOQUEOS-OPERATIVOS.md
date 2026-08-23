# Lo que bloquea la operación — y por qué ya no es el código

> **Fecha:** 2026-08-23 · Tras cerrar la iteración 3.6
> Este documento existe porque el proyecto ha cruzado una línea que conviene decir en voz alta.

---

## 1. El estado real

Con lo entregado hasta 3.6, el back-office puede: dar de alta un creador desde
una solicitud, verificar su identidad con documento archivado, registrar la
aceptación de términos, capturar y aprobar su perfil tributario, y activarlo
comprobando las seis condiciones de `BR-CREATOR-006`.

Y sin embargo **hoy no se puede activar a nadie**. No por un fallo: porque
faltan tres cosas que no son código.

Esto no es un problema; es el resultado esperado de haber puesto las
restricciones donde tocaba. El sistema se niega a inventarse lo que nadie ha
decidido. Pero significa que **el camino crítico del proyecto ya no pasa por
mí**, y eso merece un documento propio en vez de quedar disperso en el Decision
Log.

---

## 2. Bloqueo inmediato — sin esto no se activa ningún creador

### 🔴 B-1 · No hay términos y condiciones publicados

`BR-CREATOR-006` exige aceptación vigente de los términos. `DEC-059` decidió no
sembrar un texto de relleno, porque un texto inventado por el equipo técnico
convertido en «lo que el creador aceptó» es exactamente lo que `§56` prohíbe.

La pantalla de activación lo dice con estas palabras: *«No hay ninguna versión
vigente publicada. Es un asunto de la plataforma, no del creador.»*

**Qué hace falta:** el texto legal real, revisado. Después:

```bash
php artisan terminos:publicar creator_terms 2026.1 \
    --titulo="Términos del creador" \
    --archivo=docs/legal/terminos-creador-2026.1.md
```

**Quién:** tú, con quien te lleve los temas legales. → `T-09`

---

### 🔴 B-2 · Solo existe un usuario interno

`ck_ctp_segregation` exige que quien aprueba un perfil tributario **no sea quien
lo capturó**. Es la misma separación de funciones que en los lotes de pago
(`DEC-044`, `BR-FIN-005`), y aquí se decide con qué tasa se retiene.

`UsuarioAdminSeeder` crea un usuario. Con uno solo, **ningún perfil fiscal se
aprueba y por tanto ningún creador se activa**.

**Qué hace falta:** crear al menos un segundo usuario interno con rol `finance`.

**Quién:** tú. Es un minuto. → `DEC-062`

---

### 🔴 B-3 · `Q-40` — con qué tasa se retiene a un creador no domiciliado

Solo bloquea a los creadores **no domiciliados en Perú**. Para un creador
peruano con RUC, B-1 y B-2 son suficientes.

`DEC-048` no responde la pregunta —nadie inventa una tasa tributaria— pero hace
imposible ignorarla: un perfil fiscal **no se aprueba** con la retención sin
decidir, y si se retiene hay que citar la norma que lo sustenta.

**Quién:** contador. Está abierta desde la Fase 2.

---

## 3. Bloqueo del primer cobro a un cliente del exterior

### 🟠 B-4 · `Q-44` — ¿es exportación de servicios?

¿Los servicios de marketing de influencers prestados a un cliente **no
domiciliado** califican como exportación de servicios (sin IGV) o van gravados
al 18 %?

Depende de las condiciones concurrentes del art. 33-A de la Ley del IGV, y
**encontré la vigencia de la lista de servicios aplicable contradicha entre
fuentes**. Por eso no lo resuelvo yo.

El modelo admite las cuatro respuestas (`gravado`, `exportacion`, `exonerado`,
`inafecto`) y no fuerza ninguna.

**Quién:** contador.

### 🟠 B-5 · `T-07` — inscripción en el Registro de Exportadores de Servicios

Según SUNAT, para que la operación califique como exportación hay que estar
inscrito **previamente**. Es un trámite, y es previo a la primera factura al
exterior. Si B-4 se responde «sí es exportación», esto pasa a ser urgente.

**Quién:** tú / contador.

---

## 4. Preguntas que puedo cerrar yo si no contestas

Estas tienen una respuesta razonable por defecto. Si no dices nada, adopto la
recomendación y la registro en el Decision Log — como se hizo con `Q-34` y
`Q-35`.

| # | Pregunta | Mi recomendación si callas |
|---|---|---|
| `Q-46` | Al publicar términos nuevos, ¿qué pasa con los creadores **ya activos**? | **No desactivarlos**, y pedir la nueva aceptación la próxima vez que se les invite a una campaña. Suspender a toda la red por un cambio de redacción es desproporcionado. |
| `Q-47` | El periodo de gracia de 30 días, ¿global o por creador? | **Global**, como constante de configuración. Es una política operativa, no un término comercial como `payment_term_days`. Si algún día hace falta por creador, se añade la columna. |

---

## 5. Lo que preguntarle exactamente al contador

Esto es lo que copiaría y enviaría tal cual. Son cuatro preguntas y todas tienen
consecuencia directa en el sistema.

1. **Retención a no domiciliados.** Cuando pagamos a un creador de contenido que
   no está domiciliado en Perú por un servicio prestado a distancia: ¿qué tasa
   de retención de renta de fuente peruana aplica, con qué artículo se sustenta,
   y qué documento le exigimos a él?
2. **Exportación de servicios.** Nuestros servicios de marketing de influencers
   facturados a un cliente no domiciliado, ¿califican como exportación de
   servicios del art. 33-A de la Ley del IGV (sin IGV) o van gravados al 18 %?
   Si califican, ¿qué condiciones tenemos que poder demostrar?
3. **Registro de Exportadores de Servicios.** ¿Tenemos que inscribirnos antes de
   la primera factura al exterior? ¿Qué requiere el trámite?
4. **Creadores sin RUC.** Confirmamos que **no** vamos a pagar informalmente ni a
   terceros (`DEC-049`): el creador se formaliza o no cobra. ¿Ves algún riesgo
   en esa política, y hay algún régimen simplificado que podamos recomendarles?

> Las respuestas 1 y 2 no cambian el modelo de datos: ya admite las cuatro
> opciones de régimen y la retención con su norma. Solo hay que rellenarlas.

---

## 6. Lo que NO te bloquea

Para que no parezca que todo está parado. Estas siguen abiertas pero **no
impiden avanzar**:

- `Q-03`, `Q-04`, `Q-21` — facturación electrónica. Llegan en F12.
- `Q-23` a `Q-27` — gamificación. `DEC-039` la dejó opcional; llega en F14.
- `Q-29` a `Q-31` — tipografía y kit de marca. Cosméticas.
- `Q-17`, `Q-18` — segunda sociedad y marca registrada. Estratégicas, no técnicas.
- `T-08` — instructivo de formalización para el equipo de captación. Contenido.
- `T-10` — aviso automático al cambiar datos fiscales. Hoy la pantalla se lo
  recuerda al operador; llega con el módulo de Communication.

---

## 7. Y mientras tanto

Del lado del software, lo que sigue son las dos pantallas que faltan para que la
puerta de activación se pueda satisfacer entera: **redes sociales** y **medios de
pago**. Ninguna depende de las respuestas de arriba, así que puedo seguir.

La de medios de pago cierra además `H-02`: `eligible_from` admite NULL en un
medio ya verificado, así que hoy «no hay enfriamiento» y «nadie ha fijado desde
cuándo» son el mismo valor — el mismo fallo que `DEC-048` corrigió en la
retención. Mientras no se cierre, el sistema trata el NULL como **no elegible**:
el silencio no da permiso.
