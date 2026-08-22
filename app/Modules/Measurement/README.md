# Measurement (D9)

Metricas por publicacion, enlaces de seguimiento y reportes de campana.

## Dependencias permitidas

`Shared, Core, Campaign, Content`

Cualquier otra importación **hace fallar CI**. La regla vive en `deptrac.yaml` y
la justificación en `docs/00-EXECUTIVE-PRODUCT-DEFINITION.md §D.1`.

## Estructura

| Carpeta | Qué va aquí |
|---|---|
| `Domain/` | Entidades, enums de estado, reglas invariantes y eventos. Sin dependencias del framework donde sea razonable. |
| `Application/` | Casos de uso, DTOs y políticas. **Toda la lógica de negocio vive aquí**, no en los controladores. |
| `Infrastructure/` | Repositorios, adaptadores e integraciones externas. |
| `Http/` | Controladores, requests y resources — **uno por audiencia**: interno, marca, creador (`BR-SEC-001`). |
| `Database/Migrations/` | Migraciones propias del módulo. |
| `Tests/` | Pruebas del módulo, incluidos los tests de autorización negativos. |
