# LATAM Social — Plataforma de Creator Marketing

> Sistema de operación end-to-end para una empresa de Creator / Influencer Marketing.
> **Marca de plataforma:** LATAM Social. **Sociedad operadora actual:** Soluciones Tecnológicas a Medida S.A.C. (RUC 20603203896, Perú).
> La marca y la sociedad son cosas distintas por diseño: ver [`docs/11`](docs/11-ADDENDUM-LEGAL-ENTITIES.md).
> Estado del proyecto: **Fase 0 completada · Fase 2 (modelo de datos) en curso — iteración 2.1 entregada.**
> Última actualización: 2026-08-21.

---

## Qué es esto

El sistema operativo de una agencia de Creator Marketing: capta creadores y marcas, coordina campañas donde decenas o cientos de creadores producen y publican contenido, verifica la ejecución, mide resultados, liquida a los creadores y factura a las marcas — con trazabilidad, márgenes medibles e histórico auditable.

Arranca en Perú y está diseñado, desde la arquitectura, para operar después en LATAM y Europa.

## Estado actual

| Fase | Estado |
|---|---|
| **F0 — Discovery** | ✅ Entregada |
| **F1 — Arquitectura y esqueleto** | 🔄 En curso — esqueleto y puertas de calidad listos |
| **F2 — Modelo de datos** | ✅ **Completa** — iteraciones 2.1 a 2.13. **62 tablas · 150 restricciones · 143 pruebas**, verdes con `CHECK` nativo y con `TRIGGER` generado |
| F3 — Design System y UX | ⏸ |
| F4 — Core técnico | ⏸ |
| F5–F10 — MVP | ⏸ |

## Puesta en marcha

Requisitos: **PHP 8.3+** (con `soap` y `openssl`, que hacen falta para SUNAT), **Composer**, **MySQL 8**, **Redis** y **Node 20+**.

```powershell
powershell -ExecutionPolicy Bypass -File tools\bootstrap-laravel.ps1   # Windows
```
```bash
./tools/bootstrap-laravel.sh                                          # Linux · macOS · WSL
```

El script descarga Laravel 12 (versión fijada a propósito), **respeta todo lo que ya existe** en el
repositorio, crea el árbol de 13 módulos, configura `composer.json` e instala las herramientas de calidad.
Es idempotente.

**¿Vienes de XAMPP (PHP 8.2)?** Hay un instalador que deja PHP 8.3 listo sin tocar XAMPP:

```powershell
powershell -ExecutionPolicy Bypass -File tools\instalar-php83.ps1
```

Descubre la última 8.3 publicada, verifica su SHA-256, escribe un `php.ini` con las extensiones que el
proyecto necesita (`soap` para SUNAT incluida) y pone PHP 8.3 delante de XAMPP en el `PATH`.
Apache y MySQL de XAMPP siguen funcionando igual.

Y si Composer no existe, o quedó atado al PHP viejo:

```powershell
powershell -ExecutionPolicy Bypass -File tools\instalar-composer.ps1
```

Verifica la firma SHA-384 oficial e instala un `composer.bat` que invoca el `php` del `PATH` en vez de
clavar una ruta fija — que es lo que hace que el instalador oficial se quede pegado a la versión de PHP
que hubiera el día que se ejecutó.

> **Si tu PHP es el de XAMPP (8.2), el bootstrap se detiene y te dice cómo actualizar.** Es deliberado:
> PHP 8.2 pierde soporte de seguridad en diciembre de 2026, antes de que este MVP llegue a producción.
> Para arrancar hoy de todos modos — sabiendo que producción irá en 8.3+:
> ```powershell
> powershell -ExecutionPolicy Bypass -File tools\bootstrap-laravel.ps1 -PhpMinimo 8.2
> ```
> ```bash
> PHP_MINIMO=8.2 ./tools/bootstrap-laravel.sh
> ```

Después: edita `.env`, crea la base `latam_social`, y `php artisan migrate`.

Con XAMPP corriendo, la parte de base de datos del `.env` es:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=latam_social
DB_USERNAME=root
DB_PASSWORD=
```

Apache no hace falta: para desarrollo se usa `php artisan serve`. De XAMPP solo se aprovecha MySQL.

```
php artisan migrate --seed        # crea el esquema y carga los catálogos
php artisan esquema:verificar     # puerta de calidad del esquema
composer quality                  # formato + estático + fronteras + pruebas
composer arch                     # solo las fronteras (deptrac.yaml)
```

> **Ya hay 16 tablas: la capa de cimientos** (catálogos, trazabilidad, identidad). El resto del modelo
> va por la iteración 2.4 de 11. `docs/15-ARRANQUE-MVP.md §4` dice qué iteración bloquea cada fase.

## Documentación

| Documento | Contenido |
|---|---|
| [`docs/00-EXECUTIVE-PRODUCT-DEFINITION.md`](docs/00-EXECUTIVE-PRODUCT-DEFINITION.md) | Qué es el producto, supuestos, actores, dominios funcionales |
| [`docs/01-MODULE-MAP.md`](docs/01-MODULE-MAP.md) | Mapa completo de módulos por portal, con fase y marca de MVP |
| [`docs/02-END-TO-END-PROCESSES.md`](docs/02-END-TO-END-PROCESSES.md) | Los 9 procesos operativos y sus máquinas de estado |
| [`docs/03-ARCHITECTURE.md`](docs/03-ARCHITECTURE.md) | Arquitectura, stack, seguridad, i18n, rendimiento, despliegue |
| [`docs/04-ROADMAP.md`](docs/04-ROADMAP.md) | Fases, iteraciones, dependencias, **MVP exacto** y post-MVP |
| [`docs/05-DECISION-LOG.md`](docs/05-DECISION-LOG.md) | Decisiones estructurales con opciones, recomendación e impacto |
| [`docs/06-BUSINESS-RULES.md`](docs/06-BUSINESS-RULES.md) | Reglas de negocio con ID, testeables |
| [`docs/07-RISKS.md`](docs/07-RISKS.md) | Registro de riesgos priorizado por exposición |
| [`docs/08-DEFINITION-OF-DONE.md`](docs/08-DEFINITION-OF-DONE.md) | DoD, estrategia de pruebas, convenciones de trabajo |
| [`docs/09-NEXT-ITERATION.md`](docs/09-NEXT-ITERATION.md) | Qué necesito para desbloquear la Fase 2 |
| [`docs/10-CRITICAL-REVIEW.md`](docs/10-CRITICAL-REVIEW.md) | **Crítica a la especificación: qué cambiar, qué falta, qué acertó** |
| [`docs/11-ADDENDUM-LEGAL-ENTITIES.md`](docs/11-ADDENDUM-LEGAL-ENTITIES.md) | **Addendum: marca de plataforma vs. entidades legales, cobertura de facturación multipaís** |
| [`docs/12-ADDENDUM-INTEGRATIONS.md`](docs/12-ADDENDUM-INTEGRATIONS.md) | **Addendum: integraciones y APIs por entidad legal, resolver y credenciales** |
| [`docs/13-ADDENDUM-GAMIFICATION.md`](docs/13-ADDENDUM-GAMIFICATION.md) | **Addendum: gamificación del creador — XP, niveles, insignias, ligas, retos y referidos** |
| [`docs/14-BRAND-AND-DESIGN-SYSTEM.md`](docs/14-BRAND-AND-DESIGN-SYSTEM.md) | **Marca y sistema de diseño: auditoría del kit, paleta funcional, tipografía y tokens** |
| [`docs/15-ARRANQUE-MVP.md`](docs/15-ARRANQUE-MVP.md) | **Qué falta para empezar a desarrollar: checklist de arranque y replanificación** |
| [`docs/16-RESPUESTAS-NEGOCIO.md`](docs/16-RESPUESTAS-NEGOCIO.md) | **Respuestas del negocio, sus consecuencias y lo que sigue abierto** |

### Fase 2 — Modelo de datos *(en curso)*

| Iteración | Contenido |
|---|---|
| [`docs/fase-2/2.1-ENTIDADES-Y-GLOSARIO.md`](docs/fase-2/2.1-ENTIDADES-Y-GLOSARIO.md) | ✅ Glosario, catálogo de ~146 entidades en 13 dominios, anti-catálogo y matriz entidad × proceso |
| [`docs/fase-2/2.2-RELACIONES-Y-CARDINALIDADES.md`](docs/fase-2/2.2-RELACIONES-Y-CARDINALIDADES.md) | ✅ 12 preguntas resueltas, 7 N:M encubiertas, matriz de borrado y agregados |
| [`docs/fase-2/2.3-NORMALIZACION.md`](docs/fase-2/2.3-NORMALIZACION.md) | ✅ 5 preguntas de normalización resueltas, `CreatorGuardian`, 7 duplicaciones auditadas, política de claves y tipos |
| [`docs/fase-2/2.4-ATRIBUTOS-TIPOS-INDICES.md`](docs/fase-2/2.4-ATRIBUTOS-TIPOS-INDICES.md) | ✅ **Primeras migraciones ejecutables**: 16 tablas de cimientos, seeder, y `esquema:verificar` |
| [`docs/fase-2/2.5-RESTRICCIONES-PORTABLES.md`](docs/fase-2/2.5-RESTRICCIONES-PORTABLES.md) | ✅ Compilador de restricciones: `CHECK` o `TRIGGER` según el motor. Cierra `DEC-042` |
| [`docs/fase-2/2.6-CREADOR-IDENTIDAD-Y-PERFIL.md`](docs/fase-2/2.6-CREADOR-IDENTIDAD-Y-PERFIL.md) | ✅ 7 tablas del creador, tutela de menores, cuentas sociales e histórico de métricas |
| [`docs/fase-2/2.7-CREADOR-PERFIL-COMERCIAL.md`](docs/fase-2/2.7-CREADOR-PERFIL-COMERCIAL.md) | ✅ Nichos, vetos, formatos, idiomas, tarifas y disponibilidad |
| [`docs/fase-2/2.8-CREADOR-FISCAL-Y-PAGOS.md`](docs/fase-2/2.8-CREADOR-FISCAL-Y-PAGOS.md) | ✅ Régimen tributario, cuentas cifradas y comprobantes. **Cierra el dominio Creator** |
| [`docs/fase-2/2.9-CLIENTE.md`](docs/fase-2/2.9-CLIENTE.md) | ✅ Grupo cliente, marcas, perfil fiscal por país y contactos |
| [`docs/fase-2/2.10-MARCA-Y-ENTIDADES-LEGALES.md`](docs/fase-2/2.10-MARCA-Y-ENTIDADES-LEGALES.md) | ✅ Marca de plataforma, sociedades, cobertura de facturación y correlativos |
| [`docs/fase-2/2.11-CAMPANA.md`](docs/fase-2/2.11-CAMPANA.md) | ✅ Campañas, mercados, requisitos, participaciones, invitaciones y enmiendas |
| [`docs/fase-2/2.12-CONTENIDO-Y-EVIDENCIA.md`](docs/fase-2/2.12-CONTENIDO-Y-EVIDENCIA.md) | ✅ Entregables, versiones, revisiones, publicaciones, evidencia y permanencia |
| [`docs/fase-2/2.13-FINANZAS.md`](docs/fase-2/2.13-FINANZAS.md) | ✅ Costos, lotes de pago, pagos, libro mayor solo-inserción, facturas y cobros |
| [`docs/fase-2/2.14-PAGO-A-TERCEROS.md`](docs/fase-2/2.14-PAGO-A-TERCEROS.md) | 🔴 **Análisis, no implementación.** Pagar a un familiar del creador: 3 problemas, 4 alternativas, 1 recomendación |
| [`docs/fase-2/2.15-RETENCIONES.md`](docs/fase-2/2.15-RETENCIONES.md) | ✅ Retención con tres estados: la decisión deja de confundirse con el olvido |
| [`docs/fase-3/3.1-PERMISOS.md`](docs/fase-3/3.1-PERMISOS.md) | ✅ **Fase 3 arranca.** Autorización por permiso; `permission_role` estaba vacía y nada la comprobaba |
| [`docs/fase-3/3.2-BITACORA-Y-EDICION.md`](docs/fase-3/3.2-BITACORA-Y-EDICION.md) | ✅ Bitácora inmutable y primera pantalla de escritura |
| [`docs/fase-3/3.3-PANTALLA-DE-BITACORA.md`](docs/fase-3/3.3-PANTALLA-DE-BITACORA.md) | ✅ Consulta de la bitácora, con redacción de campos sensibles |
| [`docs/fase-3/3.4-BANDEJA-DE-SOLICITUDES.md`](docs/fase-3/3.4-BANDEJA-DE-SOLICITUDES.md) | ✅ Alta de creador. **Aprobar no es activar** (BR-CREATOR-006) |

### Verificar el modelo de datos

Producción es Percona 5.7, que **analiza los `CHECK` y los ignora en silencio** (`DEC-042`). Estas cuatro
herramientas comprueban que lo que se aplica en desarrollo se aplica también allí:

```bash
python3 tools/generar-triggers.py              # esquema sin CHECK + triggers equivalentes
python3 tools/verificar-triggers-generados.py <base>          # ¿acertó el generador con las columnas?
python3 tools/verificar-equivalencia.py <base_check> <base_trigger>   # ¿imponen lo mismo?
python3 tools/verificar-nombres-sql.py         # colisiones de nombres (InnoDB los exige únicos por BASE)
python3 tools/verificar-migraciones.py <base>  # ¿las migraciones declaran lo mismo que el SQL de referencia?
```

La última es la que cierra el hueco de `DEC-050`: una columna que esté en el SQL de referencia y falte en
la migración **no la detecta ninguna prueba de restricción**, porque las pruebas corren contra el SQL. El
verde sería real y no significaría nada.

El cliente de base de datos sale de `MYSQL_CMD` (por defecto `mysql -uroot`), para que las mismas
herramientas corran en local y en CI.

Y las suites de restricciones, que se ejecutan **contra los dos motores**:

```bash
bash tools/pruebas/2.12-contenido.sh <base>
bash tools/pruebas/2.13-finanzas.sh  <base>
```

`tools/pruebas/semilla.sql` tiene los datos mínimos que necesitan. Vive en el repositorio a propósito:
una semilla en `/tmp` se pierde y las pruebas dejan de ser reproducibles.

**Si solo vas a leer dos, lee `10-CRITICAL-REVIEW.md` y `04-ROADMAP.md §3`.**
**Si quieres empezar a construir, lee `15-ARRANQUE-MVP.md`.**

## Lo esencial en una página

**Objetivo del MVP:** operar 3 campañas reales con 150 creadores y 3 marcas **sin usar Excel** para coordinar, revisar o liquidar.

**Los tres dolores que resuelve, en orden:**
1. Coordinación 1-a-N (una campaña de 60 creadores son ~600 interacciones).
2. Prueba de ejecución (evidencia verificable para la marca; los posts se borran).
3. Liquidación a muchos proveedores pequeños (lo más caro y lo que más confianza destruye cuando falla).

**Stack:** PHP 8.3 · Laravel 12 · MySQL 8 · Redis · Blade + Tailwind + Alpine · S3-compatible · monolito modular con 13 dominios y fronteras verificadas en CI.

**Cinco cosas que hay que decidir ya:**
1. `DEC-001` — Framework (recomendación: Laravel).
2. `DEC-005` — 🔴 Cómo se paga legalmente a un creador sin RUC, y a uno no domiciliado. **Bloqueante. Requiere contador.**
3. Recorte del MVP y reordenamiento de fases (motor de campaña adelantado, CRM y portal de marca retrasados).
4. `DEC-016` — Renombrar `Brand`→`ClientBrand` y prohibir `Organization` a secas, antes de la Fase 2. Hoy es un buscar-y-reemplazar; después, una migración sobre decenas de tablas.
5. `DEC-029` — El aislamiento entre sandbox y producción es una **barrera del resolver de integraciones**, no un filtro. Es lo que impide emitir comprobantes reales desde QA o cobrar tarjetas en una demo.

**Los cuatro conceptos organizacionales, que no hay que confundir nunca:**
`PlatformBrand` (LATAM Social) · `LegalEntity` (la sociedad que factura) · `ClientOrganization` (el grupo cliente) · `ClientBrand` (la marca del cliente en la campaña).

**No hay multitenancy** (`DEC-002`, resuelta): la plataforma la operan solo CTS y sus sociedades, así que no existe `tenant_id`.

## Marca y diseño

Kit original en `marca/` (intacto). Versiones corregidas en `marca/derivados/`. Tokens listos para usar en
`design/tokens.css`, y guía visual navegable en `design/guia-visual.html`.

El kit tenía cinco defectos técnicos, todos corregidos y documentados en `docs/14 §2`: texto vivo con una
fuente que casi nadie tiene, el favicon sin pupila, el descriptor ilegible sobre oscuro, la mitad del lienzo
vacía y los assets de PWA ausentes.

**Regla de color:** el naranja y el magenta de marca existen solo dentro del degradado. En interfaz, el
único color de marca plano es el morado — así los semánticos (aviso, error) no colisionan con la identidad.

## Convenciones

Definidas en `docs/08-DEFINITION-OF-DONE.md §6`: Conventional Commits, ramas `feature/F<fase>.<it>-<slug>`, una iteración = uno o pocos PRs revisables, CHANGELOG actualizado en cada iteración.

## Cómo trabajamos

Una iteración a la vez. Cada una se cierra con los 14 puntos de la Definition of Done. Cada fase termina con un Phase Review Report escrito desde el rol de arquitecto externo, que puede recomendar detener y corregir. Ninguna decisión de negocio se toma en silencio dentro de un commit: va al Decision Log.
