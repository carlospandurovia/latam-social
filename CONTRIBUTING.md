# Cómo se trabaja en este repositorio

Resumen operativo de `docs/08-DEFINITION-OF-DONE.md`. Si algo aquí contradice ese documento, manda el documento.

## Antes de escribir código

Lee `docs/15-ARRANQUE-MVP.md §4`: cada fase de construcción tiene **una iteración de diseño que la bloquea**. No se empieza una fase sin ella cerrada. No es burocracia: es lo que evita rehacer tablas.

## Puesta en marcha

```bash
powershell -ExecutionPolicy Bypass -File tools\bootstrap-laravel.ps1   # Windows
./tools/bootstrap-laravel.sh                                          # Linux, macOS, WSL
```

## Ramas y commits

- `main` estable y desplegable · `develop` integración · `feature/F<fase>.<it>-<slug>` · `fix/<slug>` · `hotfix/<slug>`
- Conventional Commits en imperativo, referenciando la iteración:
  `feat(creator): añade cola de revisión de solicitudes (F5.7)`
- **Un PR gigante no es revisable y por tanto no aporta calidad.** Una iteración son uno o pocos PRs.

## Las cuatro puertas

`composer quality` corre lo mismo que CI:

| Puerta | Qué comprueba |
|---|---|
| `pint --test` | Formato |
| `phpstan` | Tipos y errores estáticos, nivel 6 |
| `deptrac` | **Que ningún módulo importe lo que no debe** |
| `artisan test` | Las pruebas |

Deptrac es la que más gente intenta saltarse. No lo hagas: es lo único que impide que el monolito modular se convierta en una bola de barro.

## Reglas que no se negocian

1. **La lógica vive en `Application/`**, nunca en controladores ni en plantillas.
2. **Un `Resource` por audiencia** — interno, marca, creador. Nunca se serializa un modelo directamente (`BR-SEC-001`).
3. **Cada endpoint nuevo lleva un test de autorización negativo**: otro ámbito → 404, no 403 (`BR-SEC-006`).
4. **Nada financiero se borra.** `RESTRICT` por defecto en las claves foráneas (`2.2 §5`).
5. **Sin cadenas mágicas**: estados y permisos son enums o constantes.
6. **Nada de secretos en el repositorio.** Ni en `.env`, ni en fixtures, ni en logs.
7. **Ninguna llamada externa dentro de una transacción de negocio** (`2.2 §7`).
8. Toda regla de negocio implementada cita su `BR-…` en el test que la cubre.

## Definition of Done

Los 14 puntos de `docs/08 §2`. Una iteración no está terminada porque el código funcione.
