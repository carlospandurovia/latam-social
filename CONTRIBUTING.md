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

**Los pasos exactos para entregar una iteración están en
`docs/19-PROTOCOLO-DE-ENTREGA.md`.** Esto es sólo la convención.

- `main` estable y desplegable · `feat/<it>-<slug>` · `fix/<slug>` · `hotfix/<slug>`
- El mensaje empieza por el **número de iteración**, en imperativo y en una línea:
  `7.5: presupuesto de creadores y compromiso congelado al aceptar`
  Es lo que permite leer `git log` y reconstruir el roadmap sin abrirlo.
- **Una iteración, un commit.** Un commit que mezcla cuatro iteraciones no se puede
  revertir sin llevarse las otras tres por delante.
- **Una rama que no vuelve deja de proteger `main`.** Se abre para poder equivocarse
  sin romper lo desplegable; si se queda ocho iteraciones sin fusionar, lo único que
  consigue es que `main` no signifique nada. Pasó, y por eso existe el documento 19.

## Las puertas

En tu máquina, `php tools/diagnostico.php` las corre todas y dice cuál falló y
cómo arreglarla. `composer quality` corre las cuatro clásicas:

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
