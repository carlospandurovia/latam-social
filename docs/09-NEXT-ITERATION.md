# 09 — Estado del proyecto y siguiente iteración

> **Versión 1.7 — 2026-08-26.** Actualizado al cerrar `5.9` + `4.1`.
>
> **Versión 1.6 — 2026-08-26.** Actualizado al cerrar 4.13 (`T-10`).
>
> **Versión 1.5 — 2026-08-26.** Actualizado al cerrar `F4.9` (el correo).
>
> **Versión 1.4 — 2026-08-26.** Actualizado al cerrar 7.5.
>
> **Versión 1.3 — 2026-08-26.** Actualizado al cerrar 7.4.
>
> **Versión 1.2 — 2026-08-25.** Actualizado al cerrar 7.3.
>
> **Versión 1.1 — 2026-08-25.** Actualizado al cerrar 7.2. La versión 1.0
> es de esta misma fecha y ya tenía los números de 4.11: se actualizan aquí
> porque este documento es exactamente el que no puede quedarse atrás.
>
> **Versión 1.0 — 2026-08-25.** Reescrito entero.
>
> La versión 0.1 era del 21 de agosto y decía *«me detengo aquí; no hay código,
> no hay esquema de base de datos y no hay wireframes»*. Cuatro días después eso
> describía un proyecto que ya no existe: hay 64 tablas, 41 migraciones, 259
> pruebas de PHPUnit y 812 aserciones de restricción. **El documento cuyo único
> trabajo es decir qué viene ahora llevaba cuatro días mintiendo**, y nadie lo
> habría notado hasta abrirlo.
>
> Es el mismo defecto que dejó `T-12` marcada como pendiente durante un mes
> estando resuelta. Un registro que no se mantiene no es un registro: es un
> documento antiguo con fecha nueva.

---

## 1. Dónde estamos, medido

| | |
|---|---|
| Tablas | 68 |
| Migraciones | 48, verdes desde cero en MySQL 8 — y **con vuelta atrás completa**, que hasta hoy no existía |
| Pruebas de PHPUnit | **427**, 1.429 aserciones |
| Aserciones de restricción (SQL) | **1.118** en MariaDB, **1.108** en MySQL 8 |
| Puertas de calidad | 6: formato, análisis estático, fronteras, pruebas, vigencias, nombres entre capas |
| Decisiones registradas | hasta `DEC-118` |

### Lo que se puede hacer hoy por pantalla

Dar de alta y gestionar **creadores** (solicitud, aprobación, activación con seis
requisitos, identidad, redes sociales verificadas, perfil comercial y tarifas,
perfil fiscal aprobado por dos personas distintas, medios de pago verificados) y
**clientes** (organización, marcas, contactos con su principal, identidad fiscal
por país con vigencia, y la sociedad que les factura según cobertura).

Y desde 7.1 y 7.2, **campañas**: alta con la sociedad que factura resuelta a la
fecha de inicio y congelada al confirmar, grafo de estados con su permiso por
transición, y un brief que dice qué hay que entregar y a qué precio — con el
cero declarado, porque «regalada» y «sin precio» no son lo mismo.

Y desde 7.3, en qué países corre cada campaña, con su cupo de creadores y con un
brief que se puede especializar por mercado.

Y desde 7.4, buscar a quién invitar: el buscador aplica solo los mercados, los
formatos del brief, la edad mínima y las categorías de la marca, y la lista corta
veta a quien no cumple `BR-CREATOR-006`.

Y desde 7.5, el dinero: presupuesto de creadores, veto de sobrecosto con
autorización auditada, y monto acordado congelado al aceptar.

Y desde `F4.9` el correo —plantillas versionadas, registro auditable,
reintentos— y desde 4.13 su primer uso real: **el creador recibe un aviso cuando
alguien toca sus datos fiscales o su medio de pago**, mientras el cambio todavía
se puede parar. Eso cerró `T-10` y la mitad que faltaba de `BR-CREATOR-007`.

Y desde `5.9` + `4.1`, **la contraseña**: aprobar a un creador le crea su cuenta
y le manda un enlace de 72 h para elegirla —nadie más la ve nunca— y cualquiera
puede recuperar la suya desde `/recuperar`, con una hora de plazo y la misma
respuesta exista o no el correo. Es la primera vez que entra al sistema alguien
que no es del equipo, y eso destapó que la portada le enseñaba los totales
internos a cualquier autenticado.

Eso completa **7.0 a 7.5 del roadmap**, más `F4.9`, `5.9` y `4.1`.

---

## 2. Lo que bloquea, y a quién le toca

Esto es lo importante de este documento. **La cola de trabajo de ingeniería está
vacía**: no queda ninguna tarea técnica que yo pueda hacer sin una decisión tuya
o sin abrir un módulo nuevo.

| # | Qué está parado | Quién lo desbloquea | Qué pasa mientras tanto |
|---|---|---|---|
| `T-09` | Publicar la **primera versión real de los términos del creador** | **Tu abogado** | 🔴 **Ningún creador puede activarse.** La pantalla lo dice explícitamente |
| `Q-40` | Con qué **tasa** se retiene a un creador no domiciliado | **Tu contador** | Un perfil fiscal con retención sin decidir no se puede aprobar (`DEC-048`) |
| `DEC-085` | Ejecutar los dos `GRANT` en el servidor de producción | **Tú, al desplegar** | La bitácora es truncable por la aplicación hasta que se haga. **Pasos en `docs/18-RUNBOOK-DESPLIEGUE.md` §3.1** |
| `Q-44` | ¿Los servicios a un cliente **no domiciliado** son exportación de servicios (sin IGV) o van al 18 %? | **Tu contador** | El modelo admite las cuatro opciones y no fuerza ninguna |

Los tres primeros son de verdad urgentes. `T-09` es el más caro de todos: **todo
el trabajo de adquisición de creadores está construido y probado, y no se puede
usar con un creador real hasta que exista ese texto.**

---

## 3. Decisiones de negocio abiertas

Ninguna bloquea código hoy; todas bloquean una iteración futura concreta.

| # | Pregunta | Cuándo hace falta |
|---|---|---|
| `Q-46` | Al publicar términos nuevos, ¿qué pasa con los creadores **ya activos**? | Antes de la 2ª versión de los términos |
| `Q-47` | El periodo de gracia de 30 días, ¿global o por creador? | Iteración de rechazo de creadores |
| `Q-50` | ¿`campaign_creators` y compañía son **evidencia** (no se borran)? | **Al construir campañas — o sea, ahora** |
| `Q-52` | ¿Un cliente debería exigir contacto de facturación antes de estar `active`? | Facturación (F9) |
| `Q-53` | ¿El mismo correo repetido en el mismo cliente y tipo es un error? | Importación de clientes |
| `Q-54` | ¿Se puede corregir un periodo fiscal ya **cerrado**? | Primera corrección real |
| `Q-55` | ¿Se valida el formato del documento fiscal por país? | Alta de clientes en el 2º país |
| `Q-34` | Colombia: ¿DIAN directo o proveedor certificado? *(recomendé proveedor, contra lo que dijiste — revísalo)* | F12 |
| `Q-38` | ¿Cuántos desarrolladores? Con uno solo, las estimaciones ×1,7 | Todo el plan |

---

## 4. Lo que propongo como siguiente iteración

**`7.6` — las invitaciones.**

Es lo único de la Fase 7 que ya no tiene nada delante. `7.4` deja la lista corta
—a quién se quiere invitar—, `7.5` deja el monto acordado —cuánto se le paga— y
`F4.9` deja el correo. Falta el paso que convierte las tres cosas en una
conversación con el creador: mandarle la invitación, que la acepte o la rechace, y
que el rechazo tenga motivo.

Y trae una decisión de negocio de verdad: **qué pasa cuando una invitación
caduca**. Un creador que no contesta no es lo mismo que uno que dice que no, y el
cupo del mercado (`target_creators`) tiene que saber la diferencia.

### Lo que NO propongo, y por qué

- **El portal del creador (`F6`).** Sigue bloqueado por `T-09`. Ahora el creador
  ya puede entrar —tiene cuenta y contraseña— y lo que ve es una sala de espera.
  Abrirle el portal sin términos publicados sería abrirle una puerta a un sitio
  donde no puede aceptar nada.
- **Facturación (`F9`).** Depende de `Q-40` y `Q-44`, o sea de tu contador.
- **`T-36` — el enlace para usuarios internos.** Es media hora y cierra del todo
  `BR-SEC-004`, pero toca `usuarios:crear`, que tiene su propio flujo. Va detrás
  de `7.6` salvo que prefieras lo contrario: hoy sigue habiendo un momento en el
  que dos personas conocen la credencial de quien aprueba perfiles fiscales.

---

## 5. Deuda de documentación reconocida

Cinco iteraciones de la Fase 3 no tienen su documento, mientras todas las demás
sí: **3.9** (tarifas), **3.11** (anulación), **3.12** (no borrar), **3.13**
(términos) y **3.14** (rotación de clave). El trabajo está hecho y verificado por
sus suites; lo que falta es el documento que explica **por qué**.

Se anota aquí en vez de en un comentario para que no pase lo de `T-12`.

---

## 6. Qué necesito de ti

Tres cosas, por orden de coste para el proyecto:

1. **Manda el texto de los términos del creador a tu abogado.** Es lo único que
   impide usar de verdad todo lo construido en las fases 3 y 4.
2. **Pregúntale a tu contador `Q-40` y `Q-44`.** Las dos tienen respuesta corta y
   las dos bloquean el dinero.
3. **La cuenta de SMTP (`Q-20`) sube de prioridad.** Ya no es sólo para los
   avisos: desde `5.9`, **sin correo saliente un creador aprobado no puede
   estrenar su cuenta** — su contraseña viaja en un correo y en ningún otro
   sitio. Hasta entonces el enlace se escribe en `storage/logs` y el flujo se
   puede probar entero, pero eso no sirve con una persona real.
