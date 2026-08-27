# 19 — Protocolo de entrega

> Qué pasa entre *«la iteración está verde aquí»* y *«la iteración está en
> GitHub»*. Los pasos exactos, en orden, y qué hace cada uno.

**Versión 1.0 — 2026-08-26.**

---

## 0. Por qué existe este documento

Se escribió mirando el estado real del repositorio, y lo que había era esto:

| Lo que parecía | Lo que era |
|---|---|
| «Vamos por 8.6» | lo último **empujado** era `7.7` |
| Cuatro iteraciones entregadas | `8.1`, `8.3`, `8.2` y `8.6` sin commit |
| `main` al día | `main` estaba en **4.9**, ocho iteraciones atrás |
| El CI vigilando | **el CI no había corrido ni una vez** desde 4.9 |

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
`fix/**` y `hotfix/**`.

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

## 3. Y cada cierto tiempo: llevarlo a `main`

Los pasos de arriba dejan el trabajo en una rama. `main` es *«estable y
desplegable»* según `CONTRIBUTING.md`, y hoy lleva ocho iteraciones sin recibir
nada — o sea que ni está al día ni es lo que se desplegaría.

Cuando una fase o un bloque coherente esté cerrado:

```bash
git checkout main
git pull
git merge --no-ff feat/<rama>
git push
```

`--no-ff` deja un commit de merge, que es lo que hace visible en `git log` dónde
empezó y terminó cada bloque.

**O por Pull Request**, que es mejor: dispara el CI antes de tocar `main` y deja
la conversación escrita. Con el repositorio en GitHub y una sola persona
escribiendo código, un PR sigue valiendo la pena por lo primero.

### Sobre las ramas, ya que lo preguntaste

Preguntaste en su día *«¿por qué creamos una rama nueva y no seguimos usando
main?»*. La respuesta corta: **para poder equivocarse sin romper lo desplegable**.
Una iteración en curso puede quedarse a medias —una migración a medio pensar, una
regla que hay que revertir— y si eso vive en `main`, lo que está desplegado deja
de ser lo que dice `main`.

La respuesta larga tiene una condición: eso sólo funciona **si la rama vuelve**.
Una rama que se queda ocho iteraciones sin fusionar ya no protege `main`; lo que
hace es que `main` deje de significar nada. Que es donde estamos.

Hay además una incoherencia que conviene arreglar: `CONTRIBUTING.md` dice
`feature/F<fase>.<it>-<slug>` y las ramas reales son `feat/<it>-<slug>`. Y
menciona una rama `develop` que **no existe**. Elige una de las dos formas y
corrige el documento; da igual cuál, pero no las dos.

---

## 4. Cuando algo sale mal

### `git status` no responde y dice algo de un `.lock`

```bash
del .git\index.lock
```

Si aparece, avísame: significa que algo de mi lado ejecutó `git`, y eso no debe
pasar.

### El CI sale rojo y aquí estaba verde

Casi siempre es **Percona 5.7**, que es lo único que el CI prueba y aquí no.
Mándame el log del job que falló; el patrón se repite y ya ha aparecido tres
veces:

- un `MESSAGE_TEXT` de más de 128 caracteres (`T-43`),
- un `UPDATE tabla WHERE id = (SELECT … FROM tabla)`, que MariaDB tolera y MySQL
  rechaza con `1093` (`T-49`),
- un `CHECK` que en MariaDB se evalúa antes que otro y en MySQL después
  (`T-48`).

### Empujé y no corrió ningún job

La rama no está en el `on: push` del workflow, o `.github/workflows/ci.yml` está
desactualizado. Paso 1.

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
