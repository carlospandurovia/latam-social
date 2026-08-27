# 19 — Protocolo de entrega

> Qué pasa entre *«la iteración está verde aquí»* y *«la iteración está en
> GitHub»*. Los pasos exactos, en orden, y qué hace cada uno.

**Versión 2.0 — 2026-08-27.** `main` vuelve a ser la línea de trabajo
(`DEC-149`). La versión 1.0 daba por buena una rama larga que no protegía nada;
la sección 3 explica por qué se abandona y cuándo se vuelve a ella.

**Versión 1.0 — 2026-08-26.**

---

## 0. Por qué existe este documento

Se escribió mirando el estado real del repositorio, y lo que había era esto:

| Lo que parecía | Lo que era |
|---|---|
| «Vamos por 8.6» | lo último **empujado** era `7.7` |
| Cuatro iteraciones entregadas | `8.1`, `8.3`, `8.2` y `8.6` sin commit |
| `main` al día | `main` estaba en **4.9**, ocho iteraciones atrás |
| El CI vigilando | no se disparaba desde 4.9 **y estaba en rojo desde 4.2** |

Lo último es lo grave y merece su propio párrafo.

### El CI no se estaba disparando

`.github/workflows/ci.yml` decía:

```yaml
on:
  push:
    branches: [main, develop]
  pull_request:
```

El trabajo vivía en `feat/7.6-invitaciones`. Empujar ahí **no dispara ningún
job**, y sin Pull Request tampoco. `main` no recibía nada desde 4.9, así que
tampoco corría allí. Ocho iteraciones —de `5.9` a `8.6`— se empujaron sin que el
CI las mirara una sola vez.

**Un CI que no se dispara no falla: no dice nada**, que es la forma más cara de
estar en verde. Es exactamente el defecto que `tools/sincronizar-ci.php` ya
documenta para un CI *desactualizado*, sólo que un escalón antes.

Arreglado en esta misma entrega: el `on: push` incluye ahora `feat/**`,
`fix/**` y `hotfix/**`. (Desde `DEC-149` lo que importa es `main`; los otros se
quedan para el día que vuelvan las ramas.)

### Y cuando por fin se disparó, salió rojo

Que no se disparara escondía algo peor: **el CI llevaba en rojo desde la
ejecución 25**. El último verde fue el **24**, en `4.1 Clientes`. Veinticuatro
ejecuciones seguidas fallando, todas por lo mismo — `T-52`, un archivo de
`laravel new` que mis puertas no podían ver.

**Verde otra vez el 2026-08-27, ejecución 44, en `main`**, con todo dentro: las
tres baterías de restricciones, **Percona 5.7**, el build del frontend y las 687
pruebas de PHPUnit. Seis minutos y cincuenta y ocho segundos.

---

## 1. Quién hace qué

| | Lo hace |
|---|---|
| Escribir el código y las pruebas | **yo** |
| Correr las suites SQL en los dos motores, las mutaciones y las seis puertas | **yo**, en el contenedor |
| Escribir los documentos, el `CHANGELOG` y el Excel | **yo** |
| Dejar los archivos en `D:\Proyectos\Influencers\ManageCampaingInfluencer\` | **yo**, por el puente |
| `git add`, `git commit`, `git push`, merges y PRs | **tú** |

### Por qué yo no toco `git`

No es prudencia: es un fallo real y reproducible. Cada `git` que se ejecuta desde
mi lado del puente crea un `.git/*.lock` que el puente **no puede borrar**
(`EPERM`), y ese archivo bloquea todos tus commits hasta que lo borras a mano.
Pasó una vez y costó una sesión. Desde entonces no ejecuto `git` **nunca**.

Sí leo `.git/HEAD`, `.git/config` y `.git/logs/HEAD` —leer no crea locks— y de
ahí sale la mitad de este documento.

---

## 2. Los pasos, en orden

Todo desde `D:\Proyectos\Influencers\ManageCampaingInfluencer\`.

### Paso 1 — Sincronizar el CI

```bash
php tools/sincronizar-ci.php
```

`.github/workflows/` es ruta protegida y el puente no escribe ahí. El fichero
viaja en `tools/github-workflow-ci.yml` y esta copia la haces tú.

**Cuándo hace falta:** cuando te lo diga al entregar. `tools/diagnostico.php` lo
comprueba igualmente, así que si se olvida sale en el paso 2.

### Paso 2 — Las puertas, en tu máquina

```bash
php tools/diagnostico.php
```

Corre lo mismo que el CI: Pint, PHPStan, Deptrac, PHPUnit, vigencias y el
chequeo del CI. Yo ya las he corrido en el contenedor, pero **tu máquina es otra
máquina**: otro PHP (8.3 contra 8.4), otro sistema de archivos, otro MySQL. Dos
de las seis puertas se han caído históricamente sólo de tu lado.

Si algo sale rojo, **para aquí y dímelo**. No commitees en rojo: un commit rojo
obliga a un segundo commit para arreglarlo y el historial deja de contar la
verdad.

### Paso 3 — Mirar qué va a entrar

```bash
git status
git diff --stat
```

Lo que tiene que aparecer es lo que te dije que entregué, ni más ni menos. Si
aparece algo que no reconoces, pregúntame antes de añadirlo.

**Lo que NO va a aparecer nunca**, y está bien: `tools/sql/generado/` está en
`.gitignore` porque es salida derivada —la regenera
`python3 tools/generar-triggers.py`— y versionarla invita a que alguien edite el
disparador generado en vez del esquema de origen.

### Paso 4 — Añadir todo, borrados incluidos

```bash
git add -A
```

`-A` y no `.`: **estadía también los archivos borrados**. Una iteración a veces
elimina código —`7.6` se llevó dos clases por delante— y `git add .` los deja
fuera, así que el commit dice que existen cuando ya no.

### Paso 5 — Commit, con el número de la iteración delante

```bash
git commit -m "8.6: el post publicado, solo de lo aprobado y con la red comprobada"
```

Formato: `<iteración>: <qué cambia, en imperativo y en una línea>`. El número
delante es lo que permite leer `git log` y reconstruir el roadmap sin abrirlo.

**Una iteración, un commit.** Si has acumulado varias sin empujar —pasa— haz un
commit por iteración, en orden, no uno gigante: un commit que mezcla cuatro
iteraciones no se puede revertir sin llevarse las otras tres por delante.

### Paso 6 — Empujar

```bash
git push
```

Si la rama es nueva:

```bash
git push -u origin <rama>
```

### Paso 7 — Comprobar que el CI corrió **y en verde**

<https://github.com/carlospandurovia/latam-social/actions>

Este paso no es opcional y es el que faltaba. El CI corre cosas que aquí no se
pueden correr: **Percona 5.7**, que es el motor de producción, con las suites
completas y el gate de mensajes. Que esté verde aquí no dice nada sobre Percona.

Si no aparece ningún job, el CI no se disparó: vuelve al paso 1.

---

## 3. Dónde se trabaja: en `main`, hasta que haya producción

### `DEC-149`, y por qué se cambia de opinión

La versión 1.0 de este documento defendía la rama de trabajo con el argumento
correcto —*«para poder equivocarse sin romper lo desplegable»*— y con una
condición que nunca se cumplió: **sólo funciona si la rama vuelve**.

Miradas las cosas de cerca, el historial del repositorio era esto:

```
main (4.9) ── 5.9 ── 7.6 ── 7.6b ── 7.7 ── 8.1 ── 8.1-8.7 ── 8.8
```

Una **línea recta**. Nunca hubo dos cosas pasando a la vez. Las tres ramas que
existían no eran tres caminos: eran tres nombres puestos sobre el mismo camino, y
dos de ellos apuntaban a puntos que ya habían quedado atrás.

Así que la pregunta no era «¿por qué ramas si al final se fusiona?» sino **«¿qué
me ha dado esta rama?»**. Y la respuesta, medida, es: nada. Ni una vez.

| Para lo que sirve una rama | ¿Aplica hoy? |
|---|---|
| Dos personas tocando cosas distintas sin pisarse | **No.** Un desarrollador |
| Dejar algo a medias y atender una urgencia de producción | **No.** No hay producción todavía |
| Que el CI opine antes de que el código llegue a la línea buena | Sí, pero `php tools/diagnostico.php` ya lo hace antes de commitear |
| Revisión por otra persona | No |
| Tener siempre un `main` desplegable | Sí — y es justo el que la rama dejó **nueve iteraciones** obsoleto |

Y el coste sí se estaba pagando: quien clonara el repositorio sin cambiar de rama
se llevaba un proyecto de hace nueve iteraciones. **La rama que se suponía que
protegía a `main` lo que hizo fue dejarlo mentir.** Eso es lo peor de los dos
mundos: la ceremonia sin ninguno de los beneficios.

### La regla, entonces

**Se trabaja en `main`.** Un commit por iteración, que es lo que ya se hacía. Sin
ramas, sin fusiones y sin decidir cada vez cómo se llama la rama.

Lo que sustituye a la rama no es la confianza: son las seis puertas. **No se
commitea en rojo.** Esa es la disciplina entera, y ya existía.

### Cuándo se vuelve a las ramas

**El día que el sistema esté desplegado.** Ese día `main` pasa a significar algo
real —*«esto es lo que está corriendo»*— y entonces sí duele meterle una
iteración a medias.

A partir de ahí: **una rama por iteración, fusionada el mismo día**. Tres órdenes
más, y con `main` valiendo algo, valen la pena. Lo que no se vuelve a hacer nunca
es una rama larga que acumula iteraciones: eso no es una rama, es un `main` con
otro nombre.

### La consolidación, una sola vez

Para volver a `main` desde donde estamos. Se hace **una vez** y no se repite.

```bash
git checkout main
git pull
git merge --no-ff feat/7.6-invitaciones -m "Fusiona 5.9 a 8.8 desde la rama de trabajo"
git push
```

No hay conflictos posibles: `main` no ha recibido nada desde 4.9, así que la
fusión es un avance limpio. `--no-ff` deja el commit de fusión, que es lo que hace
visible en `git log` dónde empezó y terminó el bloque.

Y después, borrar lo que ya no significa nada:

```bash
git branch -d feat/7.6-invitaciones
git branch -d feat/5.9-4.1-enlace-contrasena
git push origin --delete feat/7.6-invitaciones
git push origin --delete feat/5.9-4.1-enlace-contrasena
```

`-d` y no `-D` a propósito: `-d` se niega si la rama tiene algo sin fusionar, así
que es la comprobación de que la fusión se llevó todo. Si protesta, **no la
fuerces**: significa que quedó trabajo fuera y hay que mirarlo.

> `feat/5.9-4.1-enlace-contrasena` es un puntero muerto: su commit
> (`20d9dd9c`) es el padre de la rama de 7.6, así que su trabajo entra en la
> fusión igual. Comprobado antes de escribir esto, no supuesto.

A partir de ese momento, el **paso 6** de la sección 2 empuja a `main` y el CI
corre allí en cada push.

## 4. Cuando algo sale mal

### `git status` no responde y dice algo de un `.lock`

```bash
del .git\index.lock
```

Si aparece, avísame: significa que algo de mi lado ejecutó `git`, y eso no debe
pasar.

### El CI ve archivos que yo no veo

Esto tumbó el CI durante **veinticuatro ejecuciones** y merece estar escrito.

Mi contenedor no tiene una copia del repositorio: tiene `stage/`, que es
**sólo lo que yo he entregado**. `tools/verificar-pantallas.py` lo sabe y elige
la raíz según lo que encuentre:

```python
CODIGO = RAIZ / 'stage' if (RAIZ / 'stage/app').is_dir() else RAIZ
```

En tu máquina no hay `stage/`, así que escanea el repositorio entero. Aquí sí lo
hay, así que escanea **lo mío**. Todo archivo que exista sólo en tu copia —lo que
vino de `laravel new` y nunca toqué, por ejemplo— es **invisible para mis seis
puertas**.

Fue exactamente eso: `resources/views/welcome.blade.php`, la página de inicio que
trae Laravel de fábrica, llamaba a `route('login')` y `route('register')`, que
este proyecto no declara. La raíz redirige a `/panel` y nadie renderizaba esa
vista, así que no rompía nada visible — pero el gate la veía y tenía razón.

**Consecuencia práctica:** el paso 7 no es una formalidad. El CI es el único
sitio donde se mira el árbol completo, y además el único donde corre Percona 5.7.
Verde aquí no es verde.

### El CI sale rojo y aquí estaba verde

Casi siempre es **Percona 5.7**, que es lo único que el CI prueba y aquí no.
Mándame el log del job que falló; el patrón se repite y ya ha aparecido tres
veces:

- un `MESSAGE_TEXT` de más de 128 caracteres (`T-43`),
- un `UPDATE tabla WHERE id = (SELECT … FROM tabla)`, que MariaDB tolera y MySQL
  rechaza con `1093` (`T-49`),
- un `CHECK` que en MariaDB se evalúa antes que otro y en MySQL después
  (`T-48`, y otra vez en `T-51`). La fila violaba **dos** restricciones a la vez
  y cada motor contestaba con una distinta.

### Empujé y no corrió ningún job

`.github/workflows/ci.yml` está desactualizado. Paso 1. (Desde `DEC-149` se
trabaja en `main`, que siempre ha estado en el `on: push`, así que el disparador
ya no es el sospechoso.)

---

## 5. La versión corta, para pegar en la terminal

```bash
php tools/sincronizar-ci.php     # solo si te lo pedí
php tools/diagnostico.php        # las seis puertas, aquí
git status                       # ¿es lo que esperabas?
git add -A                       # -A: también los borrados
git commit -m "<it>: <qué cambia>"
git push
# y abrir Actions y ver que el job existe y está verde
```
