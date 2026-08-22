# 15 — Qué falta para empezar a desarrollar el MVP

> Versión 0.1 — 2026-08-22. Documento de puerta: se revisa y se marca, no se lee una vez.

---

## Respuesta corta

**Para escribir la primera línea de código falta una sola cosa: que confirmes `DEC-001` (Laravel).**

Para *terminar* el MVP faltan tres, y ninguna depende de mí: la consulta al contador, los textos legales y el dominio con su correo. Las tres tienen plazo externo de semanas, así que **el momento de arrancarlas es hoy**, no cuando el código las necesite.

Y falta trabajo de diseño, sí — pero **no hay que terminarlo todo antes de empezar**, y en §4 explico por qué proponerlo así sería un error.

---

## 1. Dónde estamos

| Fase | Estado |
|---|---|
| **F0 Discovery** | ✅ Completa — 15 documentos |
| **F1 Esqueleto técnico** | ⬜ No iniciada — **bloqueada solo por `DEC-001`** |
| **F2 Modelo de datos** | 🔄 2 de 11 iteraciones (2.1, 2.2) |
| **F3 Design System** | 🔄 2 de 7 — tokens e identidad por portal, resueltos con el kit de marca |
| **F4–F10 MVP** | ⬜ No iniciadas |

---

## 2. Lo único que bloquea la primera línea de código

### 🔴 `DEC-001` — Framework

Es la decisión más cara de revertir del proyecto y la única que impide arrancar. Mi recomendación sigue siendo **Laravel 12**; `Symfony 7` es defendible si el equipo ya lo domina; un MVC propio no, por las razones de `docs/03 §2.1`.

**En cuanto la confirmes, la Fase 1 puede empezar el mismo día** — esqueleto, estructura de módulos, CI, convenciones. No necesita nada del modelo de datos.

### Y una condición previa que no es técnica

Todas las decisiones del Decision Log están en estado **PROPUESTA**. Las he adoptado provisionalmente para no bloquearte, tal como pide §94 de tu especificación. Antes de escribir código conviene que las apruebes o las corrijas — sobre todo las que se vuelven caras después:

`DEC-016` (renombrados) · `DEC-017` (cobertura con vigencia) · `DEC-019` (entidad legal congelada) · `DEC-026` (propósito como enum) · `DEC-029` (aislamiento de ambiente) · `DEC-035` (XP nunca decrece) · `DEC-036` (premiar comportamiento).

Son quince minutos de lectura y evitan discusiones dentro de seis meses.

---

## 3. Lo que bloquea *terminar* el MVP — y hay que empezar hoy

Estas tres tienen **plazo externo**. Si esperas a que el código las necesite, el código se detiene.

| # | Qué | Bloquea | Cuándo hace falta | Responsable |
|---|---|---|---|---|
| 🔴 1 | **Consulta al contador**: pago a creadores sin RUC (`DEC-005`) y a no domiciliados (`Q-13`) | F9 entera, y condiciona el formulario de F5 y el onboarding de F6 | ~semana 14 | Administración |
| 🔴 2 | **Textos legales**: términos, privacidad, cesión de derechos, consentimiento | **F5 no puede salir a producción sin ellos** | ~semana 9 | Abogado |
| 🔴 3 | **Dominio + correo remitente** con SPF, DKIM y DMARC | F5: los correos de aprobación acabarían en spam | ~semana 9, pero la reputación se calienta antes | TI / Negocio |

Y cuatro más, de menor plazo pero que conviene resolver ya porque son datos de configuración:

| # | Qué | Bloquea |
|---|---|---|
| 4 | Cuenta S3-compatible | F4.7 |
| 5 | Cuentas de SMTP y proveedor de tipo de cambio (`Q-20`) | F4.6b |
| 6 | Países que CTS Perú puede facturar hoy (`Q-14`, `Q-15`) | F4.5b y sus seeders |
| 7 | Base de creadores existente, en un archivo (`Q-11`) | F5.6 importación masiva |

---

## 4. El diseño que falta — y por qué no hay que terminarlo todo antes

Quedan **9 iteraciones de modelo de datos** y **5 de UX**. Al ritmo actual, terminarlas todas antes de tocar código son entre tres y cuatro semanas más de documentos.

**No lo recomiendo, y quiero ser explícito sobre por qué.**

Tu especificación pide en §2 *"30 iteraciones pequeñas correctamente terminadas antes que una implementación gigantesca"*. Eso significa diseñar y construir **en rebanadas**, no diseñarlo todo y después construirlo todo — que es exactamente el modelo en cascada que §2 quiere evitar. Si terminamos las 14 iteraciones y luego empezamos a programar, habremos hecho cascada con nombre de iteración.

Además hay un argumento técnico concreto: **la iteración 2.10, índices y rendimiento, no se puede hacer bien en el vacío.** Los índices se diseñan contra consultas reales, no contra consultas imaginadas. Hacerla antes de que exista una sola consulta produce índices especulativos — justo lo que `docs/03 §6` prohíbe.

### Qué iteración de diseño bloquea realmente a cada fase

| Fase de construcción | Necesita, y solo esto |
|---|---|
| **F1** Esqueleto y CI | **Nada del modelo.** Solo `DEC-001` |
| **F4** Core técnico | 2.3 normalización · 2.4 estados e históricos |
| **F4.5b** Entidades legales | 2.6 multi-entidad |
| **F4.6b** Integraciones | 2.7 integraciones |
| **F5** Captación de creadores | 2.3, 2.4 + textos legales |
| **F6** Portal del creador | + `DEC-005` resuelto |
| **F7** Motor de campaña | + 3.1 journeys, 3.5 wireframes |
| **F9** Finanzas | + 2.5 finanzas + `DEC-005` + `Q-13` |
| **F10** Medición | + 2.9 auditoría y ámbitos |

La conclusión es que **F1 no depende de nada** y **F4 solo depende de dos iteraciones más**.

---

## 5. Propuesta de replanificación

En lugar de *terminar F2 → terminar F3 → empezar F1*, propongo tres carriles en paralelo:

```
Carril A — Construcción      F1 esqueleto ──> F4 core ──> F5 ──> F6 ...
Carril B — Modelo de datos   2.3, 2.4 ──> 2.5, 2.6, 2.7 ──> 2.8, 2.9 ──> 2.11 revisión
Carril C — Externo           contador · abogado · dominio · cuentas
```

Con dos reglas que impiden que esto degenere en improvisación:

1. **Ninguna fase de construcción arranca sin su iteración de diseño cerrada.** La tabla de §4 es el contrato. F9 no empieza hasta que 2.5 esté aprobada, y punto.
2. **La iteración 2.11 —revisión adversarial del modelo— sigue siendo una puerta obligatoria antes de F5.** Es el momento de cazar los errores estructurales, y no puede saltarse porque el código ya haya empezado.

Y un cambio de contenido: **2.10 (índices y volumetría) se mueve al final de F7**, cuando existan consultas reales que optimizar. Diseñar índices antes que consultas es adivinar.

**Efecto:** el trabajo de construcción empieza entre **3 y 4 semanas antes**, sin saltarse ninguna validación.

---

## 6. Checklist de arranque

### Para arrancar la Fase 1 — esta semana
- [ ] `DEC-001` confirmado: Laravel 12 *(o la alternativa que elijas)*
- [ ] Decision Log revisado: aprobar o corregir las decisiones en estado PROPUESTA
- [ ] Repositorio Git creado y acceso para quien vaya a desarrollar
- [ ] Definido quién programa: ¿uno o dos desarrolladores? Cambia todas las estimaciones

### Para no bloquearse en la semana 9
- [ ] Consulta al contador iniciada (`DEC-005`, `Q-13`)
- [ ] Textos legales encargados al abogado
- [ ] Dominio definitivo elegido y remitente configurado con SPF/DKIM/DMARC
- [ ] Cuenta S3-compatible abierta
- [ ] Proveedor SMTP contratado
- [ ] Países de cobertura de facturación confirmados (`Q-14`, `Q-15`)
- [ ] Base de creadores existente reunida en un archivo (`Q-11`)

### Para cerrar el diseño mientras se construye
- [ ] Iteración 2.3 — normalización
- [ ] Iteración 2.4 — estados e históricos
- [ ] Iteraciones 2.5 a 2.9
- [ ] Iteración 2.11 — revisión adversarial *(puerta antes de F5)*
- [ ] Iteraciones 3.1, 3.3, 3.4, 3.5, 3.7 — journeys, componentes, estados, wireframes, marca

### Decisiones menores que siguen abiertas
- [ ] `Q-29` tipografía · `Q-05` plazo de pago al creador · `Q-08` rondas de corrección · `Q-09` permanencia del post · `Q-10` quién asume el costo del producto enviado

---

## 7. Qué haría yo el lunes por la mañana

1. **Responder `DEC-001`.** Un minuto. Desbloquea la Fase 1 completa.
2. **Llamar al contador.** Es lo único que puede parar el proyecto y que ni tú ni yo podemos resolver.
3. **Encargar los textos legales.** Tienen plazo de semanas y bloquean el lanzamiento de la landing.
4. Decirme si arranco la iteración 2.3 o prefieres que empiece por F1.

Con esos cuatro movimientos, el proyecto pasa de estar en fase de documento a estar en marcha.

---

## 8. Una advertencia honesta sobre las estimaciones

Las 7–10 semanas de la Ola 0 y las 10–14 de la Ola 1 asumen **dos desarrolladores a tiempo completo**. Con uno solo, multiplica por 1,7: el MVP se va a unos siete meses en lugar de cuatro.

No es un problema si se sabe desde el principio. Lo es si se descubre en el mes cuatro. Por eso la primera casilla del checklist es *quién programa*.
