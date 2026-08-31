# Términos del creador — dónde vive el texto

El texto de partida **no está aquí**: vive con la semilla que lo carga, en

    database/seeders/textos/terminos-creador-2026.1.md

y ésa es la copia que manda.

## Por qué se movió (9.16)

La primera versión de `TerminosBaseSeeder` lo leía de `docs/`. En el contenedor
de pruebas `docs/` no existe junto a la aplicación, así que la semilla se fue por
su camino de respaldo y sembró **192 caracteres de texto mínimo** en vez de los
términos completos — sin fallar, sin avisar, y sin que nadie lo notara hasta que
alguien midió el largo.

Una semilla que degrada en silencio es peor que una que revienta. El texto pasa a
`database/seeders/textos/`, que viaja siempre con la aplicación, y
`TerminosTest::test_el_texto_base_no_es_el_de_respaldo` lo comprueba.

## Cómo se cambia

Desde el panel: **Términos** → nueva versión → editar → publicar. El archivo de
la semilla sólo se usa la primera vez, en una base vacía.
