# 01 — Mapa completo de módulos

> Versión 0.2 — 2026-08-21.
> **La numeración de fases es la del roadmap revisado** (`04-ROADMAP.md`), no la de la especificación original. El cambio y su justificación están en `04-ROADMAP.md §0`.
> Leyenda MVP: **✅ MVP** · **🟡 Post-MVP cercano (Año 1)** · **⬜ Diferido (Año 1–2)** · **🔮 Visión**

---

## 1. Portal público (`www`)

| Módulo | Dominio | Fase | MVP | Nota crítica |
|---|---|---|---|---|
| Landing Creadores | D2 | F5 | ✅ | Debe convertir; es la puerta de captación orgánica. |
| Formulario de aplicación de creador | D3 | F5 | ✅ | Corto: 6–10 campos. El resto en onboarding. Ver crítica §C-08. |
| Páginas institucionales | D2 | F5 | ✅ | Estáticas. |
| Legales (privacidad, términos, cookies, consentimientos) | D2 | F5 | ✅ | **Bloqueante legal**, no cosmético. Versionadas. |
| Banner de cookies y gestión de consentimiento | D2 | F5 | ✅ | Necesario si hay analytics o píxeles. |
| SEO técnico (sitemap, metadatos, OG, schema.org) | D2 | F5 | ✅ | Barato al inicio, caro después. |
| Landing Marcas (B2B) | D4 | F11 | 🟡 | Venta de activaciones gestionadas, no de "acceso a influencers". |
| Formulario de lead B2B | D4 | F11 | 🟡 | Máximo 6–8 campos visibles. Ver `DEC-012`. |
| WhatsApp CTA / click-to-chat | D4 | F11 | 🟡 | Con parámetros UTM preservados. |
| Blog / contenidos | D2 | — | ⬜ | Alto costo de mantenimiento. Recomendación: CMS externo o estático, no construirlo. |
| Página pública de creador / portfolio público | D3 | — | 🔮 | Riesgo: expone tu red a la competencia. Evaluar con cuidado. |

> Con las 3 marcas iniciales, la captación B2B ocurre por relación directa. La landing B2B entra cuando exista tráfico que convertir.

## 2. Backoffice interno (`admin`)

### 2.1 Núcleo transversal

| Módulo | Dominio | Fase | MVP | Nota crítica |
|---|---|---|---|---|
| Autenticación y sesiones | D1 | F4 | ✅ | Incluye rate limiting y bloqueo progresivo. |
| MFA / TOTP | D1 | F4 | ✅ (roles financieros) | `BR-SEC-005`. Recomiendo no diferirlo. |
| Usuarios internos | D1 | F4 | ✅ | |
| Roles y permisos (RBAC) | D1 | F4 | ✅ | Permisos granulares desde el día 1. `BR-SEC-002`. |
| Log de accesos y sesiones activas | D1 | F4 | ✅ | |
| Auditoría (activity log) | D2 | F4 | ✅ | Append-only. `BR-SEC-003`. |
| **Marcas de plataforma** (`PlatformBrand`) | D2 | F4 | ✅ | Identidad, dominio, remitente, textos legales. MVP: LATAM Social. |
| **Entidades legales** (`LegalEntity`) | D2 | F4 | ✅ | Razón social, país de constitución, fiscal, monedas, contactos. MVP: CTS Perú. |
| **Cobertura de facturación** (entidad ↔ países, con vigencia) | D2 | F4 | ✅ | N:M con `valid_from`/`valid_to` y prioridad. `DEC-017`. |
| **Cuentas bancarias por entidad legal** | D10 | F4 | ✅ | Alimentan las instrucciones de pago de la factura. `BR-LE-006`. |
| Settings por categoría, **jerárquico en cascada** | D2 | F4 | ✅ | 4 niveles: plataforma → marca → entidad → entidad×país. Cifrado de secretos por entidad, sin herencia. `DEC-018`, `BR-SEC-008`. |
| Catálogos maestros | D2 | F4 | ✅ | Países, monedas, idiomas, redes, categorías, tipos de documento, motivos de rechazo. |
| File Manager abstracto | D2 | F4 | ✅ | S3-compatible desde el inicio. `DEC-008`. |
| Notification Center (in-app) | D11 | F4 | ✅ | |
| Email Template Manager | D11 | F4 | ✅ | BD + versión + idioma + diccionario de variables. `BR-COMM-002`. |
| Email Log | D11 | F4 | ✅ | Con reintentos y estado del proveedor. |
| Jobs, colas y scheduler | D2 | F4 | ✅ | Email, archivos, recordatorios, exportaciones. |
| Manejo de errores y logging estructurado | D2 | F4 | ✅ | Con `request_id`. |
| Tabla estándar (filtros, orden, paginación, exportación) | D2 | F4 | ✅ | Componente reutilizado por todos los módulos. |
| Feature flags | D2 | F4 | 🟡 | Barato; permite desplegar sin activar. |
| Importación masiva (CSV/Excel) | D2 | F5 | ✅ | **Adelantada respecto a la spec.** Ver crítica §C-07. |
| Detección de duplicados | D2 | F5 | ✅ | Email, documento, @usuario social, teléfono. `BR-CREATOR-003`. |
| Notas internas por entidad | D2 | F5 | ✅ | Altísima relación valor/costo. |
| Tags | D2 | F11 | 🟡 | |
| Búsqueda global | D2 | F10 | 🟡 | |
| Tareas y recordatorios | D4 | F11 | 🟡 | |
| Calendario operativo | D6 | F7 | 🟡 | Publicaciones, entregas, vencimientos, pagos. |
| Custom fields | D2 | — | ⬜ | Diferido. Ver crítica §C-10. |
| Filtros guardados | D2 | — | ⬜ | |

### 2.2 Creadores

| Módulo | Dominio | Fase | MVP |
|---|---|---|---|
| Bandeja de aplicaciones (cola de revisión) | D3 | F5 | ✅ |
| Ficha 360 del creador | D3 | F5 | ✅ |
| Cuentas sociales y su verificación | D3 | F5 | ✅ |
| Snapshots de audiencia y estadísticas | D3 | F5 | ✅ |
| Documentos y evidencias | D3 | F5 | ✅ |
| Máquina de estados del creador | D3 | F5 | ✅ |
| Blacklist | D3 | F5 | ✅ |
| Tarifario del creador (declarada / negociada / histórica) | D3 | F6 | ✅ |
| Datos fiscales y régimen tributario del creador | D10 | F6 | ✅ (**bloqueado por `DEC-005`**) |
| Medios de pago (cuentas, billeteras) con verificación de cambios | D10 | F6 | ✅ |
| Conflictos de marca / brand safety | D7 | F7 | 🟡 |
| Evaluaciones post-campaña | D12 | F14 | 🟡 |
| Creator Score | D12 | F14 | ⬜ Ver crítica §C-05 |
| **Gamificación: motor de reglas de XP, ledger, niveles** | D13 | F14 | 🟡 (`DEC-034`) |
| **Insignias y Academia del creador** | D13 | F14 | 🟡 |
| **Ligas, temporadas y progreso comparativo** | D13 | F15 | ⬜ (`DEC-038`) |
| **Retos internos** (minicampañas de XP) | D13 | F15 | ⬜ (`DEC-039`) |
| **Programa de referidos con consolidación diferida** | D13 | F15 | ⬜ (`DEC-040`) |
| **Recompensas y canjes** | D13 | F15 | ⬜ (`BR-GAM-013`) |

### 2.3 Clientes y comercial

| Módulo | Dominio | Fase | MVP | Nota |
|---|---|---|---|---|
| Organizaciones cliente (alta manual) | D5 | F7 | ✅ | Mínimo necesario: sin cliente no hay campaña. |
| Marcas del cliente (`ClientBrand`) | D5 | F7 | ✅ | `BR-CRM-002`: la campaña cuelga de la marca del cliente, no de `PlatformBrand`. |
| Contactos del cliente | D5 | F7 | ✅ | |
| Datos fiscales por país (catálogo) | D5 | F7 | ✅ | `BR-CRM-004`. |
| Leads y pipeline Kanban | D4 | F11 | 🟡 | Con 3 marcas no resuelve un dolor real. |
| Actividades, notas y seguimiento comercial | D4 | F11 | 🟡 | |
| Conversión Lead → Cliente con trazabilidad | D4/D5 | F11 | 🟡 | `BR-CRM-001`. |
| Usuarios del cliente e invitaciones | D1/D5 | F13 | ⬜ | En el MVP se sustituye por enlaces firmados. `DEC-013`. |
| Propuestas comerciales / cotizaciones | D4 | F13 | ⬜ | |
| Contratos, OC y firma | D8/D10 | F12 | 🟡 | Ver crítica §F-04. |

### 2.4 Campañas

| Módulo | Dominio | Fase | MVP |
|---|---|---|---|
| Campaña: CRUD, estados y transiciones auditadas | D6 | F7 | ✅ |
| Brief, requisitos y assets | D6 | F7 | ✅ |
| Mercados de campaña (multipaís) | D6 | F7 | ✅ modelo / UI mínima |
| Buscador y filtros de creadores | D7 | F7 | ✅ |
| Shortlist y selección con cupos | D7 | F7 | ✅ |
| Invitaciones, expiración y respuestas | D6 | F7 | ✅ |
| Panel de seguimiento de campaña | D6 | F7 | ✅ (**la pantalla más usada del sistema**) |
| Logística de producto (envío, tracking, recepción) | D6 | F7 | ✅ (**añadido**, crítica §F-02) |
| Campañas UGC vs distribución | D6 | F7 | ✅ |
| Gestión de reemplazos de creadores | D6 | F7 | ✅ (**añadido**, crítica §F-10) |
| Matching asistido con ranking | D7 | F14 | ⬜ |

### 2.5 Contenido

| Módulo | Dominio | Fase | MVP |
|---|---|---|---|
| Entregables y versionado append-only | D8 | F8 | ✅ |
| Cola de revisión interna y comentarios por versión | D8 | F8 | ✅ |
| Aprobación del cliente por enlace firmado | D8 | F8 | ✅ (`DEC-013`) |
| Registro y verificación de publicación | D8 | F8 | ✅ |
| Archivado de evidencia del post en vivo | D8 | F8 | ✅ (**añadido**, crítica §F-03) |
| Control de permanencia mínima del post | D8 | F8 | ✅ |
| Hilos de mensajes creador ↔ equipo | D11 | F8 | ✅ (**añadido**, crítica §F-05) |
| Derechos de uso con alcance y vigencia | D8 | F8 | ✅ modelo / 🟡 alertas (`DEC-014`) |
| Aprobación de contenido dentro del portal de marca | D8 | F13 | 🟡 |

### 2.6 Medición

| Módulo | Dominio | Fase | MVP |
|---|---|---|---|
| Snapshots de métricas por publicación (con fuente) | D9 | F10 | ✅ |
| Captura asistida (creador declara + evidencia) | D9 | F10 | ✅ |
| Consolidación por creador / plataforma / campaña | D9 | F10 | ✅ |
| Reporte de campaña reproducible + PDF | D9 | F10 | ✅ |
| Dashboards por rol | D9 | F10 | ✅ (máx. 6 KPIs, crítica §C-12) |
| Exportaciones CSV/XLSX en cola | D2 | F10 | ✅ |
| Tracking links, UTM y códigos promocionales | D9 | F10 | 🟡 |
| Métricas por API oficial de plataformas | D9 | F12 | ⬜ (`DEC-009`) |

### 2.7 Finanzas

| Módulo | Dominio | Fase | MVP |
|---|---|---|---|
| Monedas y tipo de cambio con historial | D10 | F9 | ✅ |
| Ledger de creadores (append-only) | D10 | F9 | ✅ |
| Devengo automático por eventos de campaña | D10 | F9 | ✅ |
| Aprobación de ganancias, ajustes, bonos, retenciones | D10 | F9 | ✅ |
| Requisitos documentales por país y régimen | D10 | F9 | ✅ (**bloqueado por `DEC-005`**) |
| Lotes de pago con doble aprobación y exportación bancaria | D10 | F9 | ✅ |
| Registro de pago, comprobante y notificación | D10 | F9 | ✅ |
| Facturación al cliente: hitos, OC, CxC, cobro, conciliación | D10 | F9 | ✅ (registro) |
| **Series y numeración correlativa por entidad legal** | D10 | F9 | ✅ (`DEC-021`, `BR-LE-007`) |
| **Registros y configuración fiscal por entidad legal y país** | D10 | F9 | ✅ (modelo) / 🟡 (motor fiscal) |
| Rentabilidad por campaña (P&L interno) **y por sociedad** | D10 | F9 | ✅ (`BR-FIN-007`) |
| Integración de facturación electrónica (PSE) **por entidad legal y país** | D10 | F12 | 🟡 (`BR-LE-008`) |
| Notas de crédito y débito | D10 | F12 | 🟡 |
| Pasarela de pago (Culqi u otra) | D10 | F12 | ⬜ (`DEC-007`, crítica §C-03) |

### 2.8 Plataforma y operación

| Módulo | Dominio | Fase | MVP |
|---|---|---|---|
| **Registro de integraciones** (proveedores, conexiones, asignaciones) | D2 | F4 | ✅ (`DEC-025`) |
| **Resolver de integraciones** con especificidad y aislamiento de ambiente | D2 | F4 | ✅ (`DEC-027`, `DEC-029`) |
| **Bóveda de credenciales** (cifrado sobre, rotación, verificación) | D2 | F4 | ✅ (`DEC-030`) |
| Adaptadores de arranque: SMTP, storage, tipo de cambio | D2 | F4 | ✅ |
| **Matriz de propósitos obligatorios por país** + bloqueo de activación | D2 | F9 | ✅ (`DEC-028`) |
| Adaptadores: PSE de facturación, pasarela, APIs sociales, WhatsApp | D2 | F12 | 🟡 |
| Webhooks entrantes: URL por conexión, firma e idempotencia | D2 | F12 | 🟡 (`DEC-031`) |
| Panel de salud de integraciones y simulador de resolución | D2 | F12 | 🟡 |
| Cortocircuito y límites de tasa por conexión | D2 | F16 | ⬜ |
| Health checks y monitoreo | D2 | F17 | ✅ |
| Backups y restauración probada | D2 | F17 | ✅ |
| Panel de privacidad (acceso, rectificación, supresión) | D2 | F15 | 🟡 |
| Indicador de carga y capacidad del equipo | D6 | F10 | 🟡 (**añadido**, crítica §F-09) |

## 3. Portal del Creador (`creators`) — PWA, móvil primero

| Módulo | Fase | MVP | Nota |
|---|---|---|---|
| Shell PWA, navegación móvil, onboarding con completitud | F6 | ✅ | |
| Mi perfil (personal, ubicación, idiomas, preferencias) | F6 | ✅ | |
| Mis redes y estadísticas con evidencia | F6 | ✅ | |
| Perfil profesional: nichos, formatos, restricciones | F6 | ✅ | |
| Mis tarifas | F6 | ✅ | |
| Datos fiscales y medios de pago | F6 | ✅ | Cambios sensibles → aprobación. `BR-CREATOR-007`, `BR-FIN-006`. |
| Dashboard del creador | F6 | ✅ | Máx. 6 KPIs. |
| **Barra de completitud de perfil + XP básico** | F6 | ✅ | La única gamificación que paga desde el MVP: desbloquea invitación y pago (`BR-CREATOR-006`). |
| Mi progreso: XP, nivel, insignias, racha | F14 | 🟡 | |
| Mis referidos | F15 | ⬜ | |
| Retos disponibles | F15 | ⬜ | |
| Notificaciones y preferencias | F6 | ✅ | |
| Invitaciones: aceptar, rechazar con motivo, preguntar | F7 | ✅ | |
| Mis campañas y brief descargable | F7 | ✅ | |
| Confirmación de recepción de producto | F7 | ✅ | |
| Entregables: subida, versiones, correcciones | F8 | ✅ | **Debe funcionar perfecto desde móvil.** |
| Registro de publicación (URL + evidencia de insights) | F8 | ✅ | |
| Mensajes con el equipo | F8 | ✅ | Sin esto vuelven a WhatsApp. |
| Mis ingresos y estado de pago | F9 | ✅ | El módulo que más retención genera. |
| Portfolio / media kit | F10 | 🟡 | |
| Documentos y contratos firmados | F12 | 🟡 | |
| Referidos | — | ⬜ | |

## 4. Acceso de la Marca

**MVP (`DEC-013`):** sin portal con cuentas. Enlaces firmados por campaña, expirables, revocables y auditados, para dos cosas: **aprobar contenido** y **ver el reporte**.

| Módulo | Fase | MVP | Nota |
|---|---|---|---|
| Aprobación de contenido por enlace firmado | F8 | ✅ | Lo más valioso para la marca. |
| Reporte de campaña por enlace firmado + PDF | F10 | ✅ | |
| Portal con cuentas, usuarios y roles de cliente | F13 | ⬜ | |
| Dashboard de campaña en vivo | F13 | ⬜ | |
| Aprobación de brief dentro del portal | F13 | ⬜ | |
| Biblioteca de contenido aprobado con derechos y vigencia | F13 | ⬜ | Diferenciador comercial real; habilita venta de renovaciones. |
| Empresa, marcas, contactos, usuarios | F13 | ⬜ | |
| Facturas y estado de cuenta | F13 | ⬜ | |

## 5. Módulos deliberadamente NO construidos (y qué hacer en su lugar)

| Solicitado | Recomendación | Motivo |
|---|---|---|
| Blog / CMS propio | WordPress headless, Ghost, o markdown estático | Coste de mantenimiento desproporcionado |
| Facturación electrónica propia | Integrar un PSE/OSE tras `ElectronicInvoiceProviderInterface` | Certificación, cambios normativos, riesgo regulatorio |
| Scraping de redes sociales | APIs oficiales + evidencia manual + proveedor de datos si el volumen lo justifica | Ilegal y frágil. La spec ya lo prohíbe correctamente |
| Firma electrónica propia | Integrar un proveedor, o aceptación con evidencia registrada | Valor probatorio |
| Constructor genérico de reportes | Reportes específicos + exportación CSV/XLSX | Los constructores genéricos casi nunca se usan |
| Chat en tiempo real | Hilos asíncronos con notificación por email/WhatsApp | 10× más barato y resuelve el 90% del caso |
| Motor de campos personalizados | Migraciones cuando haga falta un campo | Ver crítica §C-10 |
