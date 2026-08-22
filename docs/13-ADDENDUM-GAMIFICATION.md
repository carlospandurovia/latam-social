# 13 — Addendum: gamificación del lado del creador

> Versión 0.1 — 2026-08-21. **Parte integral del Master Prompt desde ahora.**
> Propuesta para revisión **antes** de fijar relaciones en la iteración 2.2.
>
> **Veredicto en una línea:** la idea es buena y encaja mejor de lo que parece —arquitectónicamente cuesta poco porque ya existe `DomainEvent`—, pero tiene **un riesgo que puede volverse en contra del negocio**, y está exactamente en la parte que más ilusión suele hacer: las minicampañas de XP.

---

## 1. El riesgo que hay que resolver antes de diseñar nada

Tus creadores no son usuarios de una app: son **proveedores profesionales a los que les pagas**. Y en cuanto un sistema de puntos aparece cerca del dinero, corre el riesgo de leerse como un sustituto.

La versión concreta del problema está en tu propia propuesta: *"minicampañas de XP por hacer videos para la plataforma"*. Leído desde el otro lado de la pantalla, eso puede sonar a **"produce contenido de marketing para mi empresa y te pago con puntos"**. Un creador que ya produce por dinero para tus clientes lo va a notar de inmediato, y el daño reputacional en una red pequeña se propaga en un grupo de WhatsApp.

Es justo el riesgo que tu propia especificación ya anticipó en §16 —*"no convertir promoción de la plataforma en obligación de trabajo gratuito"*—, y me parece un acierto que estuviera ahí desde el principio. Este documento lo convierte en reglas verificables.

**La regla que lo resuelve** (`DEC-039`): todo reto interno lleva **recompensa tangible además del XP** — dinero, producto, acceso prioritario a campañas o difusión pagada de su contenido. El XP es el envoltorio y el reconocimiento; **nunca el pago**. Y la participación es opcional de verdad: no participar no puede reducir las oportunidades de campaña de nadie, y eso debe estar escrito en la interfaz, no solo en nuestra intención.

Con eso resuelto, el resto del sistema es sólido y sí aporta valor real. Vamos a ello.

---

## 2. Siete principios de diseño

Estos siete principios son lo que separa una gamificación que retiene creadores de una que los espanta. Cada uno se convierte más abajo en decisión o en regla.

**1 · Gamifica el comportamiento, no el resultado.**
Dar XP por engagement alto de un post premia el algoritmo de Instagram, no al creador. Dar XP por **responder rápido, entregar a tiempo y subir la evidencia** premia exactamente lo que el creador controla y lo que a ti te ahorra trabajo.

**2 · El XP nunca baja.**
Quitar puntos se percibe como robo y destruye la confianza de golpe. Las consecuencias negativas viven en el **Creator Score**, que es interno. El XP solo sube. Son dos sistemas distintos y §10 explica por qué no deben fusionarse.

**3 · Solo XP desde eventos verificados por el sistema.**
Nunca desde algo autodeclarado. Si el sistema no observó el hecho, no hay puntos. Esto elimina de raíz la mitad de los vectores de fraude.

**4 · Transparencia total.**
Cada movimiento de XP muestra por qué se otorgó y qué regla lo produjo. Si el nivel abre puertas a campañas —y va a hacerlo—, entonces el XP tiene consecuencia económica, y un sistema opaco con consecuencia económica es indefendible ante el creador y frágil ante una reclamación.

**5 · Progreso propio siempre visible; comparación, con mucho cuidado.**
Ver que subes es motivador. Ver que eres el 847 de 1.000 no lo es. Ver un ranking donde siempre ganan los mismos, tampoco. §6 desarrolla esto.

**6 · Lo que el nivel desbloquea tiene que ser real.**
Un número que no abre ninguna puerta es decoración y la gente lo detecta en dos semanas. Pero en cuanto abre puertas, deja de ser un juego y pasa a ser una política comercial — con todo lo que eso implica.

**7 · Alcanzable haciendo bien el trabajo normal.**
La vía principal para subir de nivel tiene que ser **completar campañas bien**. Retos y referidos son un carril adicional, nunca el principal. Si la única forma de subir es hacer marketing gratis para ti, el sistema es una trampa.

---

## 3. Dónde aplicar gamificación

### 3.1 Lo que pediste, evaluado

| Elemento | Veredicto | Nota |
|---|---|---|
| XP | ✅ | Base de todo. Ledger append-only |
| Niveles | ✅ | Calculados desde una curva recalibrable, no almacenados |
| Medallas / insignias | ✅ | Lo más barato y de mejor retorno emocional |
| Ligas | ⚠️ Con condiciones | Solo por cohortes acotadas y con temporadas. Ver §6 |
| Ranking | ⚠️ Reformulado | Nunca una tabla global de posiciones. Ver §6 |
| Enlace de referido | ✅ | Con consolidación diferida antifraude. Ver §8 |
| XP por invitar | ✅ | Pero no al registrarse el invitado: al alcanzar un hito real |
| XP por registrarse | ⚠️ Poco útil | Registrarse no es un logro. Mejor: XP por **completar el perfil**, que sí desbloquea la operación |
| XP por participar en campañas | ✅ | Pero por **completar**, no por aceptar. Ver §3.3 |
| Minicampañas de XP | ⚠️ Con recompensa real | El punto delicado del §1 |
| Administración total configurable | ✅ | Es lo correcto. Ver §5 |

### 3.2 Mis propuestas — dónde la gamificación de verdad mueve tu negocio

Ordenadas por retorno operativo. Cada una ataca un dolor real ya identificado en el proyecto:

| # | Comportamiento premiado | Qué problema tuyo resuelve |
|---|---|---|
| **G-01** | **Completar el perfil** (fiscal, bancario, redes verificadas, tarifas) | `BR-CREATOR-006`: sin esto no se le puede invitar ni pagar. **Es la única gamificación que paga desde el día 1 del MVP** |
| **G-02** | **Responder rápido a una invitación — aceptando *o* rechazando** | El dolor operativo n.º 1: perseguir gente. Premiar el rechazo rápido es contraintuitivo y es exactamente lo correcto: un "no" en 2 horas vale más que un "sí" en 5 días |
| **G-03** | **Entregar antes de la fecha** | Reduce la carga del Campaign Manager y el riesgo de campaña |
| **G-04** | **Aprobado sin rondas de corrección** | Premia leer el brief. Cada ronda evitada es tiempo del equipo de revisión |
| **G-05** | **Subir la evidencia de publicación e insights sin que se la pidan** | Ataca `P8`, el proceso más manual del sistema |
| **G-06** | **Mantener las estadísticas actualizadas** (refresco trimestral con evidencia) | Resuelve el problema del dato viejo, que es lo que hace inútil el matching |
| **G-07** | **Respetar la permanencia del post** | `BR-CONTENT-006`, sin tener que perseguirlo |
| **G-08** | **Academia del creador**: módulos cortos sobre buen UGC, iluminación, brief, derechos | Sube la calidad del contenido, baja las rondas de corrección, y las **certificaciones son argumento de venta ante la marca** |
| **G-09** | **Racha de campañas completadas** | Retención pura |
| **G-10** | **Ampliar formatos** (insignia por haber hecho Reel + Story + UGC) | Un creador más versátil es más veces invitable |
| **G-11** | **Referidos que se activan** | Crecimiento de red. Ver §8 |

> **G-08, la Academia, es la que más me gusta de todas.** No es un adorno: reduce rondas de corrección (`G-04` la refuerza), sube la calidad que ve la marca, y te da una etiqueta vendible — *"creadores certificados"* — que ninguna competencia local tiene. Y es contenido que produces una vez.

> **G-02 merece un párrafo aparte.** Casi todo el mundo diseña esto premiando la aceptación, y es un error: creas presión para aceptar campañas que el creador no puede cumplir, y acabas con incumplimientos. Premia **responder**, no aceptar.

### 3.3 Dónde NO gamificar

Tan importante como lo anterior:

| No gamificar | Por qué |
|---|---|
| Número de campañas **aceptadas** | Produce sobrecompromiso y luego incumplimiento |
| Número de seguidores | Desmotiva justo al nano y micro creador que es el corazón de tu red, y premia el vector de fraude que ya identificamos en `R-04` |
| Engagement de la publicación | No lo controla el creador |
| Ingresos generados | Convierte el dinero en marcador público. Es el camino directo a que la red compare tarifas |
| **A los clientes / marcas** | Gamificar B2B casi siempre se lee como poco serio. Lo que la marca quiere es rapidez y prueba, no insignias. Una barra de completitud del brief es buena UX, no gamificación |
| **Al equipo interno** | Poner puntos a los Campaign Managers optimiza el marcador, no la operación. Ley de Goodhart en estado puro |

---

## 4. Modelo conceptual — dominio D13

### 4.1 Regla arquitectónica que lo hace barato

Gamificación es un **dominio nuevo (D13 — Engagement & Gamification)** con la misma restricción dura que D12 Intelligence:

> **Consumidor de eventos, nunca fuente. Y nunca dependencia de nadie.** Si el motor de gamificación se cae, la operación sigue funcionando exactamente igual: se invita, se produce, se aprueba y se paga. Los eventos quedan en `DomainEvent` y el XP se recalcula después.

Esto no es solo higiene: es lo que hace que el sistema sea **recalculable**. Si el año que viene cambias la tabla de puntos, puedes reprocesar el histórico de eventos y recalcular todo el XP. Si el XP se escribiera a mano en el momento, eso sería imposible.

Y aquí está la buena noticia de coste: **`DomainEvent` ya existe** desde la iteración 2.1, introducido para alimentar el Creator Score. La gamificación es un segundo consumidor del mismo flujo. La mitad del trabajo ya estaba planificado.

### 4.2 Entidades

| Entidad | Propósito |
|---|---|
| `XpRule` | La regla configurable: tipo de evento + condición + puntos + topes + vigencia + estado |
| `XpEntry` | Asiento append-only de XP: creador, puntos, regla que lo otorgó, evento origen, fecha. **Mismo patrón que el ledger financiero** |
| `LevelTrack` | La curva de niveles: umbrales, nombres, iconos. Recalibrable sin tocar el XP |
| `Badge` | Insignia: nombre, descripción, icono, criterio, si es única o repetible |
| `BadgeAward` | Concesión a un creador, con el evento que la disparó y la fecha |
| `League` | Agrupación competitiva acotada (por nivel, país o categoría) |
| `Season` | Ventana temporal de competición. Los puntos de liga se reinician; **el XP jamás** |
| `LeagueStanding` | Posición de un creador en una liga y temporada |
| `Challenge` | Reto interno opcional: objetivo, requisitos, ventana, cupo, XP y **recompensa tangible** |
| `ChallengeParticipation` | Participación de un creador, con su estado y entregable |
| `Reward` | Lo que un nivel, insignia o reto desbloquea |
| `RewardRedemption` | Canje: quién, qué, cuándo, con qué coste real. **Auditado** |
| `Referral` | Enlace/código, referente, referido, hito alcanzado, estado de consolidación |
| `CreatorProgress` | Proyección derivada y reconstruible: XP total, nivel, insignias, racha |

`CreatorReferral`, que en la iteración 2.1 estaba en D3 como diferida, **se traslada a D13** y se convierte en `Referral` con el modelo antifraude de §8.

### 4.3 Volumetría

| Entidad | 1 año | 3 años |
|---|---|---|
| `XpEntry` | 45 k | 800 k |
| `BadgeAward` | 6 k | 90 k |
| `LeagueStanding` | 12 k | 250 k |
| `Referral` | 400 | 6 k |
| `ChallengeParticipation` | 800 | 15 k |

Nada que preocupe. Sigue siendo válido lo dicho en 2.1 §7: el problema de escala de este sistema no son las filas.

---

## 5. El motor de reglas configurable

Pediste que todo sea administrable. La forma correcta:

```
XpRule
  ├── evento disparador   (enum cerrado en código, como `purpose` en DEC-026)
  ├── condición           (opcional: "solo si la entrega fue ≥24h antes del plazo")
  ├── puntos              (fijos, o fórmula acotada)
  ├── topes               (por día / por campaña / por temporada / total histórico)
  ├── vigencia            (valid_from / valid_to)
  └── estado              (activa / inactiva)
```

Cuatro decisiones que hacen que esto no se rompa:

**Los tipos de evento son un enum cerrado en código, no un catálogo editable.** Mismo argumento que `DEC-026`: el código es el que sabe qué significa `deliverable_submitted_early`. Una regla que apunte a un evento inventado desde el panel nunca se dispararía, y nadie entendería por qué.

**Los topes son obligatorios, no opcionales.** Toda regla debe declarar un límite por periodo. Sin tope, cualquier regla es explotable — y alguien lo va a intentar el primer mes.

**Vigencia en lugar de borrado.** Igual que en cobertura de facturación (`DEC-017`) y en asignaciones de integración (`DEC-025`): una regla que dejó de aplicar se cierra con fecha, no se elimina, para poder explicar dentro de dos años por qué alguien tiene los puntos que tiene.

**Simulador antes de activar.** El panel debe poder responder *"si activo esta regla, ¿cuánto XP habría repartido el mes pasado?"* sobre los eventos históricos. Sin eso, cada cambio de reglas es una apuesta a ciegas sobre la economía de puntos.

---

## 6. Ligas y ranking — reformulados

Aquí es donde más gente se equivoca, así que te propongo un cambio.

**El problema.** Una tabla global de clasificación con 1.000 creadores significa que 990 personas abren la app y ven que están perdiendo. En un negocio donde la retención del creador lo es todo, eso juega en contra. Y con el tiempo se estanca: los que llevan más tiempo acumulan más y los nuevos ven una montaña imposible.

**La propuesta.**

- **Progreso personal, siempre visible.** Tu XP, tu nivel, cuánto falta para el siguiente, tus insignias, tu racha. Esto es lo que motiva de verdad y no depende de nadie más.
- **Ligas por cohorte acotada.** Grupos de tamaño limitado (~30 creadores) agrupados por nivel similar, y con **temporadas**. Se compite contra pares, no contra el creador estrella que factura 40 campañas al año.
- **Movimiento, no posición absoluta.** Lo que se muestra y se premia es *subir de liga*, no ser el número 1 del mundo. Todo el mundo puede ascender.
- **Los puntos de liga se reinician cada temporada; el XP nunca.** Así el sistema se mantiene vivo y los recién llegados tienen una oportunidad real cada temporada.
- **Sin ranking público por ingresos, jamás.** Ver §3.3.

Un ranking global visible solo tiene sentido en una vista interna, para el equipo, y ahí no es gamificación: es un reporte.

---

## 7. Retos internos (tus "minicampañas de XP")

Bien planteados, son valiosos: contenido para tus landings, testimonios reales, material de venta B2B. Pero con las salvaguardas del §1.

**Reglas duras:**

1. **Siempre recompensa tangible además del XP.** Dinero, producto, acceso prioritario o difusión pagada del contenido del creador. Sin excepción.
2. **Opcional de verdad y dicho explícitamente en la interfaz:** no participar no afecta a las oportunidades de campaña.
3. **Cesión de derechos explícita.** Si vas a usar ese vídeo en tus landings o en publicidad, es una licencia (`DEC-014`), con alcance y vigencia, igual que con cualquier cliente. Que sea "interno" no lo exime.
4. **Cupo y ventana.** Un reto sin límite de plazas genera trabajo que luego no puedes revisar ni recompensar.
5. **Revisión real.** Si alguien produce y no recibe respuesta, el efecto es peor que no haber hecho el reto.

**Decisión de modelado abierta.** Un reto con entregable necesita subida, versiones, revisión y aprobación — todo eso ya existe en D8. Hay dos caminos: reutilizar `Campaign` con un tipo interno (contamina métricas, márgenes y facturación), o crear `Challenge` propio y **generalizar `Deliverable`** para que cuelgue tanto de una participación de campaña como de una participación en reto. Me inclino por lo segundo, pero el polimorfismo tiene coste y es una decisión de la **iteración 2.2**, no de este documento.

---

## 8. Referidos con antifraude

Un programa de referidos con recompensa es, sin excepción, un imán de fraude: autorreferidos, cuentas falsas, granjas de referidos.

**El mecanismo que lo resuelve** (`DEC-040`): **consolidación diferida**. El XP del referido no se otorga al registrarse el invitado, sino cuando alcanza un hito real:

```
Invitado se registra        →  0 XP  (solo se registra el vínculo)
Invitado es aprobado        →  XP parcial
Invitado completa su 1ª campaña  →  XP completo + posible recompensa
```

Más: tope de referidos consolidables por periodo, verificación de que referente y referido no comparten documento, teléfono o medio de pago, y revisión manual por encima de un umbral. El vínculo referente→referido se conserva siempre, aunque el XP no se consolide: es dato de negocio.

Y el programa sigue siendo **voluntario**, en línea con §16 de tu especificación: embajador es un rol al que uno se apunta, no una expectativa tácita.

---

## 9. Recompensas: aquí vive la consecuencia económica

Lo que un nivel desbloquea es lo que hace que el sistema funcione — y también lo que lo convierte en política comercial. Candidatos, de menor a mayor consecuencia:

| Recompensa | Consecuencia |
|---|---|
| Insignia visible en su perfil | Ninguna. Puro reconocimiento |
| Acceso anticipado a invitaciones (unas horas antes) | Baja |
| Aparecer destacado en el buscador interno | Media |
| Bonificación por campaña completada | **Económica** |
| Prioridad en selección para campañas | **Económica y sensible** |
| Mejor plazo de pago | **Económica y operativa** |

Las tres últimas exigen tres cosas: quedar **auditadas**, ser **explicables al creador**, y no producir discriminación indirecta —por ejemplo, que el nivel dependa de algo que solo alcanzan los creadores de una ciudad concreta—. Es el mismo estándar que le exigimos al Creator Score en `docs/10 §C-05`, y por la misma razón: afecta a los ingresos de una persona.

---

## 10. Gamificación y Creator Score: parecidos, y deben seguir separados

Los dos calculan un número sobre un creador a partir de los mismos eventos. La tentación de fusionarlos es enorme y sería un error.

| | **Creator Score** (D12) | **Gamificación** (D13) |
|---|---|---|
| Para quién | Interno: decide a quién invitar | Externo: motiva al creador |
| Puede bajar | **Sí** | **No, nunca** |
| Visible al creador | Parcialmente | Totalmente |
| Naturaleza | Juicio operativo | Contrato transparente |
| Si falla | Se invita a ojo | No pasa nada |

Comparten **origen** (`DomainEvent`) pero no **semántica**. Un creador que incumplió una vez debe bajar en el score interno y **conservar** el XP que ganó antes: quitárselo sería castigarlo dos veces y con el mecanismo equivocado.

---

## 11. Riesgos legales y fiscales

No los damos por resueltos: los identificamos para revisión, como pide §56 de tu especificación.

- **Premios y sorteos.** Si una liga entrega premios, en Perú puede entrar en el terreno de las promociones comerciales y de la protección al consumidor, con obligaciones de bases publicadas y posible autorización. **Requiere validación legal.**
- **XP convertible en dinero o bienes.** Entonces es una contraprestación, con implicaciones tributarias y documentales que enlazan directamente con `DEC-005` y `Q-13`. Si el XP solo desbloquea acceso o visibilidad, el problema se reduce mucho — y es un argumento fuerte para diseñarlo así.
- **Bases del programa versionadas.** Las reglas del programa son un documento con versión y aceptación (`LegalDocumentVersion` + `ConsentRecord`, ya en el modelo). Cambiar la tabla de puntos sin avisar a quien acumuló bajo otras reglas es la vía rápida al conflicto.
- **Menores de edad.** Si se admiten creadores menores (`Q-07`), competiciones y recompensas exigen cautela adicional.

---

## 12. Alcance y fases

Seamos honestos con el tamaño: construido entero son **6 a 8 semanas**. Es más que cualquiera de los dos addenda anteriores. Y con 150 creadores y 3 campañas, una liga está vacía: no hay actividad suficiente para que un marcador signifique algo.

Pero hay una parte que **sí paga desde el primer día**, y es pequeña.

| Bloque | Fase | ¿MVP? | Por qué ahí |
|---|---|---|---|
| **Barra de completitud de perfil + XP básico** (`G-01`) | **F6** | ✅ | Resuelve un problema operativo real del MVP: creadores que no completan datos fiscales y bancarios no se pueden invitar ni pagar |
| Registro de eventos que alimentan todo | F4–F9 | ✅ | `DomainEvent` ya está planificado. **Coste cero adicional** |
| Motor de reglas, ledger de XP, niveles, panel de administración | F14 | 🟡 | Junto a Intelligence: mismo origen de datos, mismas garantías de auditabilidad |
| Insignias y Academia del creador (`G-08`) | F14 | 🟡 | Alto retorno, bajo coste técnico |
| Ligas, temporadas y progreso comparativo | F15 | ⬜ | Necesita ≥500 creadores y flujo constante de campañas para no estar vacío |
| Retos internos (`Challenge`) | F15 | ⬜ | Requiere generalizar `Deliverable` (decisión de 2.2) |
| Referidos con consolidación diferida | F15 | ⬜ | Hollow sin campañas que ofrecer al invitado |
| Recompensas con consecuencia económica | F15 | ⬜ | Requiere validación legal y fiscal previa |

**Coste neto en el MVP: prácticamente nulo** — una barra de progreso y unas reglas de XP sobre eventos que ya se registran. Todo lo demás se construye cuando la red tenga tamaño para que signifique algo.

**Lo que sí hay que hacer ahora, en la iteración 2.2:** dejar el modelo preparado. Que `DomainEvent` cubra los eventos de `G-01` a `G-11`, y decidir si `Deliverable` se generaliza. Retrofitear eventos es imposible: los hechos que no se registraron no se pueden inventar.

---

## 13. Decisiones nuevas

Se incorporan al Decision Log como `DEC-034` a `DEC-041`. Resumen:

| Ref. | Decisión | Recomendación |
|---|---|---|
| `DEC-034` | Gamificación como dominio propio (D13) | Consumidor de eventos, nunca fuente ni dependencia. Recalculable desde el histórico |
| `DEC-035` | Naturaleza del XP | Append-only y nunca decreciente. Las penalizaciones viven en el Creator Score |
| `DEC-036` | Qué se premia | Comportamiento verificado por el sistema, no resultados ni volumen ni audiencia |
| `DEC-037` | Niveles | Calculados desde una curva recalibrable, no almacenados |
| `DEC-038` | Ranking | Cohortes acotadas con temporadas; sin tabla global de posiciones |
| `DEC-039` | Retos internos | Siempre con recompensa tangible además del XP. El XP nunca sustituye el pago |
| `DEC-040` | Referidos | Consolidación diferida al hito real, con topes y verificación antifraude |
| `DEC-041` | Separación Gamificación / Creator Score | Mismo origen de eventos, semántica opuesta. No se fusionan |

---

## 14. Reglas de negocio nuevas

| ID | Regla | Crit. |
|---|---|---|
| **BR-GAM-001** | El XP es append-only y nunca disminuye. No existe operación de resta ni de borrado. | 🔴 |
| **BR-GAM-002** | Todo asiento de XP referencia la regla que lo otorgó y el evento de dominio que lo disparó, y ambos son visibles para el creador. | 🔴 |
| **BR-GAM-003** | Solo generan XP eventos verificados por el sistema. Ninguna acción autodeclarada otorga puntos. | 🔴 |
| **BR-GAM-004** | Toda regla de XP declara un tope por periodo. Una regla sin tope no puede activarse. | 🟠 |
| **BR-GAM-005** | El nivel es una función del XP acumulado y de la curva vigente; no se almacena como verdad mutable. Recalibrar la curva no elimina XP a nadie. | 🟠 |
| **BR-GAM-006** | Las reglas de XP no se eliminan: se cierran con `valid_to`, para poder explicar puntos otorgados en el pasado. | 🟠 |
| **BR-GAM-007** | Los puntos de liga se reinician al cambiar de temporada; el XP acumulado nunca. | 🟠 |
| **BR-GAM-008** | No existe tabla pública de posiciones global ni ranking por ingresos. La comparación se limita a cohortes acotadas. | 🟠 |
| **BR-GAM-009** | Todo reto interno incluye una recompensa tangible además del XP, y su participación es opcional. No participar no afecta a la elegibilidad para campañas. | 🔴 |
| **BR-GAM-010** | El contenido producido en un reto interno requiere licencia de uso explícita con alcance y vigencia, igual que el de cualquier campaña. | 🔴 |
| **BR-GAM-011** | El XP por referido se consolida solo cuando el referido alcanza el hito definido, nunca al registrarse. | 🔴 |
| **BR-GAM-012** | Referente y referido no pueden compartir documento, teléfono ni medio de pago. La coincidencia bloquea la consolidación y marca para revisión. | 🔴 |
| **BR-GAM-013** | Toda recompensa con consecuencia económica queda auditada y es explicable al creador. | 🔴 |
| **BR-GAM-014** | Las bases del programa son un documento versionado con aceptación registrada. Un cambio de reglas no altera retroactivamente el XP ya otorgado. | 🔴 |
| **BR-GAM-015** | Si el motor de gamificación no está disponible, ninguna operación de campaña, contenido o pago se ve afectada. | 🟠 |

---

## 15. Preguntas abiertas para el negocio

| # | Pregunta | Bloquea |
|---|---|---|
| **Q-23** | ¿Qué desbloquea realmente el nivel? Es la pregunta más importante del addendum: define si esto es reconocimiento o política comercial. | Diseño de recompensas |
| **Q-24** | ¿El XP podrá convertirse alguna vez en dinero o bienes? Si la respuesta es no, el problema fiscal y legal se reduce drásticamente. | Validación legal |
| **Q-25** | ¿Cuál es el hito de consolidación del referido: aprobación, o primera campaña completada? | `DEC-040` |
| **Q-26** | ¿Los retos internos pagarán en dinero, en producto o en acceso prioritario? | `DEC-039` |
| **Q-27** | ¿Hay presupuesto para la Academia del creador (`G-08`)? Es contenido, no software. | F14 |
| **Q-28** | ¿Quién opera esto? Un programa de gamificación sin nadie que lo cuide se apaga en tres meses. | F14 |

---

## 16. Lo que esta propuesta acierta

- **Reconocer que la retención del creador se gana con algo más que dinero** es correcto: en una red de micro-creadores, pertenencia y progreso son motivadores reales.
- **Pedir que todo sea configurable desde el inicio** evita el error clásico de hardcodear la tabla de puntos y no poder cambiarla nunca.
- **Vincular referidos con XP** es el uso más natural de la mecánica y la vía de crecimiento más barata que tienes.
- Y el instinto de §16 de tu especificación original —**no convertir la promoción de la plataforma en trabajo gratuito obligatorio**— es exactamente la salvaguarda que hace que todo lo demás sea defendible.
