# 08 — Definition of Done y proceso de calidad

> Versión 0.1 — 2026-08-21.
> Una iteración **no está terminada porque el código funcione**. Está terminada cuando cumple todo lo siguiente. No es negociable bajo presión de plazos: cuando hay presión, lo que se recorta es el **alcance**, nunca la calidad.

---

## 1. Definition of Ready (antes de empezar una iteración)

Una iteración no comienza hasta que:

- [ ] Tiene objetivo escrito en una frase, en términos de negocio.
- [ ] Tiene user stories con criterios de aceptación verificables.
- [ ] Las entidades y campos afectados están definidos en el modelo de datos.
- [ ] Las reglas de negocio aplicables están identificadas por ID (`BR-*`).
- [ ] Los permisos necesarios están definidos.
- [ ] No depende de ninguna decisión abierta y bloqueante del Decision Log.
- [ ] Cabe en la iteración. Si no cabe, se parte antes de empezar, no a mitad.

## 2. Definition of Done (para cerrar una iteración)

### Funcionalidad
- [ ] Todos los criterios de aceptación se cumplen y fueron verificados manualmente.
- [ ] Los casos límite identificados están cubiertos (vacío, máximo, duplicado, concurrente, sin permisos).
- [ ] Los estados de UI están implementados: vacío, carga, error, sin permiso, sin resultados.
- [ ] Los mensajes al usuario son comprensibles y están en español, sin jerga técnica ni stack traces.

### Datos
- [ ] Migraciones creadas, aplicadas y **reversibles** (o con plan de reversión documentado).
- [ ] Índices necesarios creados y justificados.
- [ ] Seeders o datos de catálogo actualizados cuando corresponda.
- [ ] `DATABASE.md` actualizado con las tablas y columnas nuevas.
- [ ] Todo documento con valor comercial o fiscal lleva `legal_entity_id` persistido (`BR-LE-001`); toda entidad alcanzable desde un portal externo tiene su ámbito (`client_organization_id` o `creator_id`) definido y con test negativo.

### Seguridad
- [ ] Autorización implementada con permiso **y** política de recurso.
- [ ] Existe al menos un **test de autorización negativo** por endpoint nuevo (otro ámbito → 404).
- [ ] Entradas validadas en servidor (la validación de cliente no cuenta).
- [ ] Formularios con CSRF; salidas escapadas.
- [ ] Sin datos de otra audiencia en las respuestas (verificado por test cuando el endpoint sirve a un portal externo).
- [ ] Archivos validados por contenido, no por extensión.
- [ ] Sin secretos en el código ni en el repositorio.

### Calidad de código
- [ ] Análisis estático sin errores nuevos.
- [ ] Formato aplicado.
- [ ] Fronteras entre módulos respetadas (verificación de dependencias en verde).
- [ ] Sin lógica de negocio en plantillas ni SQL en vistas.
- [ ] Sin cadenas mágicas: estados y permisos son enums o constantes.
- [ ] Code review realizado (o revisión adversarial simulada y documentada si el equipo es de una persona).

### Pruebas
- [ ] Tests unitarios de la lógica de dominio nueva.
- [ ] Tests de integración de los casos de uso.
- [ ] Tests de feature (HTTP) de las rutas nuevas.
- [ ] Tests de validación y de autorización.
- [ ] Los flujos críticos del proceso tienen prueba end-to-end.
- [ ] Toda la suite pasa en CI.

### Observabilidad y errores
- [ ] Los errores se capturan, se registran con `request_id` y no exponen detalles internos al usuario.
- [ ] Los eventos relevantes generan registro de auditoría.
- [ ] Las operaciones lentas están en cola, no en el ciclo de la petición.

### Experiencia
- [ ] Responsive verificado en móvil (360 px), tablet y escritorio.
- [ ] Navegable por teclado; etiquetas asociadas; contraste suficiente.
- [ ] Sin tiempos de carga superiores a 2 s en listados con datos de prueba realistas.

### Documentación
- [ ] `CHANGELOG.md` actualizado.
- [ ] Reglas de negocio nuevas añadidas a `06-BUSINESS-RULES.md` con su ID.
- [ ] Decisiones tomadas añadidas a `05-DECISION-LOG.md`.
- [ ] Documento del módulo actualizado si cambió el comportamiento.

### Entrega
- [ ] Commits coherentes y descriptivos (un commit gigante no se acepta).
- [ ] Rama fusionada con la estrategia acordada.
- [ ] Desplegable en el ambiente de QA sin pasos manuales no documentados.

## 3. Entregable de cierre de iteración

Al terminar cada iteración se entrega, en este orden:

1. **Objetivo** cumplido (una frase).
2. **User stories** y su estado.
3. **Criterios de aceptación** y su resultado.
4. **Modelo de datos afectado** (tablas nuevas/modificadas).
5. **Backend** implementado (casos de uso, servicios, eventos).
6. **Frontend** implementado (pantallas).
7. **Seguridad**: permisos añadidos, políticas, controles.
8. **Tests**: cuáles y qué cubren.
9. **Migraciones** creadas.
10. **Datos semilla**.
11. **Checklist de QA manual** para que el negocio lo verifique.
12. **Documentación** actualizada.
13. **Limitaciones conocidas y deuda técnica asumida** (explícita, no oculta).
14. **Propuesta de la siguiente iteración**.

## 4. Phase Review Report (al cerrar cada fase)

Antes de iniciar la fase siguiente, cambio de rol a **arquitecto externo independiente + QA Lead + Security Reviewer** y produzco un informe que responde, con evidencia:

| Pregunta | Qué debe contener la respuesta |
|---|---|
| ¿Qué diseñamos mal? | Decisiones que ya se ven equivocadas, con costo estimado de corregirlas ahora vs. después |
| ¿Qué falta? | Casos de uso, estados, reglas o pantallas que el negocio va a pedir y no están |
| ¿Qué no escala? | Consultas, tablas o procesos que fallan a 10× el volumen actual |
| ¿Qué es inseguro? | Hallazgos concretos, con severidad y plan |
| ¿Qué deuda técnica asumimos? | Lista explícita, con propietario y fase de pago |
| ¿Qué experiencia es mala? | Fricciones observadas en pruebas reales, no supuestas |
| ¿Qué regla no está clara? | Ambigüedades que van a generar bugs o discusiones |
| ¿Qué métricas tenemos ahora? | Cobertura de tests, tamaño del código, consultas por página, tiempos |

El informe termina con una **recomendación explícita: continuar / continuar con condiciones / detener y corregir**.

## 5. Estrategia de pruebas por tipo de riesgo

| Tipo de código | Cobertura esperada | Prioridad |
|---|---|---|
| Cálculos financieros y ledger | ~100%, incluidos casos límite y redondeo | 🔴 |
| Máquinas de estado y transiciones | ~100% de transiciones válidas e inválidas | 🔴 |
| Autorización y aislamiento de datos | Test negativo por cada endpoint | 🔴 |
| Reglas de negocio (`BR-*`) | Un test nombrado por regla, referenciando su ID | 🔴 |
| Casos de uso de dominio | Alta | 🟠 |
| Controladores y validación | Media | 🟠 |
| Plantillas y presentación | Baja (E2E en los flujos críticos) | 🟡 |

**Flujos que exigen prueba end-to-end obligatoria:**
1. Aplicación de creador → aprobación → activación de cuenta → primer acceso.
2. Lead → cliente → invitación al portal.
3. Campaña → invitación → aceptación → entregable → aprobación → publicación → verificación → devengo.
4. Devengo → aprobación → lote de pago → pago → notificación.
5. Intento de acceso cruzado entre ámbitos (debe fallar siempre).

## 6. Convenciones de trabajo

- **Ramas:** `main` (estable, desplegable) · `develop` · `feature/F<fase>.<it>-<slug>` · `fix/<slug>` · `hotfix/<slug>`.
- **Commits:** Conventional Commits (`feat(creator): ...`, `fix(finance): ...`, `docs: ...`), en imperativo, referenciando la iteración.
- **Pull requests:** una iteración = uno o pocos PRs revisables. Un PR de 5.000 líneas no es revisable y por tanto no aporta calidad.
- **CHANGELOG:** actualizado en cada iteración, no al final del proyecto.
- **ADRs:** toda decisión arquitectónica significativa se registra en `05-DECISION-LOG.md` en el momento de tomarla.
