# Plantilla de importación de creadores

Para cargar los primeros creadores sin teclearlos uno a uno (`docs/10 §C-07`).
Archivo: `tools/plantilla-importacion-creadores.csv` — UTF-8, separador coma, dos filas de ejemplo que hay que borrar.

## Columnas

| Columna | ¿Obligatoria? | Formato y validación |
|---|---|---|
| `nombres` | ✅ | Texto |
| `apellidos` | ✅ | Texto |
| `nombre_artistico` | — | Con el que se le conoce en redes |
| `email` | ✅ | Único en el sistema. Detección de duplicados (`BR-CREATOR-003`) |
| `telefono` | ✅ | Formato internacional `+51987654321` |
| `whatsapp` | — | Si se omite, se asume igual al teléfono |
| `pais` | ✅ | ISO-3166 alfa-2: `PE`, `CO`, `MX`, `EC`, `CL`, `US` |
| `region` / `ciudad` | — | Texto libre; se normaliza contra el catálogo al importar |
| `fecha_nacimiento` | ✅ | `AAAA-MM-DD`. **Determina si es menor de edad** (`BR-CREATOR-010`) |
| `tipo_documento` | ✅ | Según el país: `DNI`, `RUC`, `CE`, `NIT`, `RFC`, `CI`… |
| `numero_documento` | ✅ | Se valida contra el catálogo del país (`BR-CRM-004`) |
| `genero` | — | `F`, `M`, `X` o vacío. Opcional a propósito |
| `idioma_principal` | — | ISO-639-1. Por defecto `es` |
| `categoria_principal` | ✅ | Debe existir en el catálogo de categorías |
| `categoria_secundaria` | — | Subnicho |
| `red_principal` | ✅ | `instagram`, `tiktok`, `youtube`, `facebook`, `x`, `twitch` |
| `usuario_red_principal` | ✅ | Sin `@` |
| `url_red_principal` | ✅ | URL completa. **Se valida que exista** |
| `seguidores_declarados` | ✅ | Entero. Se guarda como `self_declared` (`BR-CREATOR-004`) |
| `red_secundaria` y sus dos columnas | — | Igual que la principal |
| `tarifa_referencia` | — | Decimal con punto. Es referencia, no compromiso (`BR-CREATOR-008`) |
| `moneda` | — | ISO-4217: `PEN`, `USD`, `COP`, `MXN` |
| `origen` | — | `base_previa`, `referido`, `outreach`, `evento`, `landing` |
| `notas` | — | Nota interna. **Nunca visible para el creador** |

## Cómo se comporta la importación

1. **Previsualización antes de escribir nada**: filas válidas, filas con error y duplicados detectados.
2. **Los duplicados no se crean solos.** Coincidencia por email, documento o usuario de red → se marca y se decide a mano.
3. Todo lo importado entra como `CreatorApplication` en estado `submitted`, **no** como creador aprobado: pasa por la misma revisión que cualquier otro (`P1`).
4. Cada importación queda registrada en `ImportBatch` y **se puede revertir** mientras ninguna fila haya sido aprobada.

## Lo que NO va en el CSV

Datos bancarios, datos fiscales, documentos de identidad y autorizaciones de tutor. Todo eso lo aporta el creador en su onboarding, con evidencia — no se importa desde una hoja de cálculo.
