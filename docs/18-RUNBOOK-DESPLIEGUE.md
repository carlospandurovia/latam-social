# 18 — Runbook de despliegue

> **Versión 2.0 — 2026-09-05.** Reescrito al cerrar `Landing comercial v1`, para
> el primer despliegue real a `latamsocial.com`.
>
> La v1.0 daba por hecho un servidor cómodo. La v2.0 añade **§0: el primer
> despliegue en un hosting compartido con SSH y MySQL 5.7**, que es el caso que
> hay delante y el que tiene una mina enterrada (§0.3).
>
> **Regla:** si un paso sólo existe en un mensaje, no existe. Va aquí.

---

## 0. El primer despliegue, paso a paso

Escrito para: **cPanel/Plesk compartido, con acceso SSH, MySQL/Percona 5.7**.

Trece pasos. Léelos todos antes de ejecutar el primero, porque el paso 3
puede decidir que este hosting no sirve, y es mejor saberlo antes de haber
subido nada.

### 0.1 Antes de tocar el servidor: dejar el repositorio limpio

**Esto es un defecto mío y hay que corregirlo antes de desplegar.** Desde la
iteración `L-3` estuve escribiendo los archivos en `stage/…` y `.entrega/…` —que
son rutas de mi contenedor de trabajo, no de tu repositorio—. El resultado es
que tu árbol vivo se quedó en `L-2b` y las iteraciones `L-3` a `L-7` estaban en
carpetas que Laravel no mira.

Ya está corregido: los 45 archivos están re-entregados en `app/`, `database/`,
`lang/`, `resources/`, `routes/` y `tests/`. Lo que falta es **borrar las
carpetas sombra**, que se colaron en el commit `3418284` y siguen versionadas.

```bash
cd D:\Proyectos\Influencers\ManageCampaingInfluencer

# 1. Fuera las carpetas sombra (borra en disco y en el índice de git)
git rm -r --quiet stage .entrega

# 2. Que no vuelvan a aparecer nunca
printf '\n# Rutas del contenedor de trabajo, nunca del proyecto\n/stage\n/.entrega\n' >> .gitignore

# 3. Las puertas, EN LOCAL, antes de subir nada
composer install
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/deptrac
php artisan test

# 4. Commit
git add -A
git commit -m "Landing comercial v1: los archivos a su sitio, fuera stage/ y .entrega/"
git push origin main
```

> **Por qué importa aquí y no en otro sitio.** Si despliegas antes de esto, el
> servidor recibirá el `L-2b` que hay en `HEAD` y la portada nueva no existirá.
> No fallará: se verá la vieja, que es peor que fallar.

Y una comprobación de treinta segundos que vale la pena, **en local**, antes de
subir: `php artisan serve`, abrir `http://localhost:8000/marcas` y ver el
titular *«Muchas voces. Una sola campaña.»*. Si ves otra cosa, el paso 1 no se
aplicó.

### 0.2 Reconocimiento: qué tiene de verdad este servidor

Entra por SSH y pega esto tal cual. No cambia nada; sólo pregunta.

```bash
# PHP: la versión por defecto casi nunca es la buena
php -v
which -a php php8.3 php83
ls -d /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/ea-php83 2>/dev/null

# Extensiones que el proyecto necesita
php -m | grep -Eix 'pdo_mysql|mbstring|openssl|tokenizer|xml|ctype|json|bcmath|fileinfo|curl|zip|gd|intl|dom|simplexml'

# Funciones que el hosting suele capar, y que hacen falta
php -r 'echo "disable_functions: ", ini_get("disable_functions"), "\n";'
php -r 'echo "memory_limit: ", ini_get("memory_limit"), "\n";'

# Herramientas
which composer git node npm unzip
node -v 2>/dev/null; npm -v 2>/dev/null

# Base de datos
mysql --version
```

Cómo leer la respuesta:

| Lo que ves | Qué significa | Qué hacer |
|---|---|---|
| `php -v` dice 8.1 o menos, pero existe `/opt/cpanel/ea-php83/root/usr/bin/php` | el binario por defecto no sirve | usa **siempre la ruta completa** del 8.3, también en el cron |
| No hay ningún PHP 8.3 | el proyecto no arranca (`composer.json` exige `^8.3`) | cambiar la versión de PHP del dominio en el panel; si el hosting no llega a 8.3, **este hosting no sirve** |
| Falta `intl`, `gd`, `zip` o `bcmath` | fallará al instalar o al generar imágenes | activarlas en «Select PHP Version → Extensions» del panel |
| `disable_functions` contiene `proc_open` | **Composer no puede correr en el servidor** | paso 0.5, opción B |
| `disable_functions` contiene `symlink` | `storage:link` fallará | paso 0.9 tiene la alternativa |
| `memory_limit` = 128M o menos | Composer se queda sin memoria | `php -d memory_limit=-1 composer.phar …` |
| No hay `node` / `npm`, o `node -v` < 20 | no puedes compilar los assets ahí | paso 0.6, opción B (compilar en tu Windows) |
| No hay `git` | no puedes clonar | subir por SFTP un `.zip` del proyecto |

Guarda esta salida. Si algo se tuerce más adelante, la respuesta suele estar
aquí.

### 0.3 La base de datos, y la mina de MySQL 5.7

**Esto es lo que puede hacer que el hosting no valga. Compruébalo antes de subir
el código.**

MySQL 5.7 **analiza la cláusula `CHECK` y la ignora**. La tabla se crea, no hay
error, y la restricción no existe (`DEC-042`). El proyecto lo sabe: `Restriccion`
detecta que el motor no aplica `CHECK` —lo comprueba, no se fía de la versión— e
**instala un disparador equivalente**. Hoy hay del orden de 140 reglas así.

O sea: en 5.7, **casi todas las reglas de negocio del esquema son TRIGGERs**. Si
el usuario de migraciones no puede crear disparadores, no es que falten unas
cuantas validaciones: es que la mitad del esquema no se puede instalar.

**a) Crear la base y los dos usuarios.** En cPanel: *MySQL® Databases*. Ojo: el
panel prefija todo con tu usuario (`cpuser_latamsocial`, `cpuser_app`).

- Base: `latamsocial`, cotejamiento **`utf8mb4_unicode_ci`**.
- Usuario **de aplicación** (`app`): marcar sólo `SELECT, INSERT, UPDATE, DELETE, EXECUTE`.
  **No marcar `DROP`.** Es lo único que impide `TRUNCATE TABLE audit_logs`, que
  no dispara disparadores y deja la bitácora a cero (`DEC-085`).
- Usuario **de migraciones** (`mig`): `ALL PRIVILEGES`.

Si el panel deja abrir una consola SQL o hay cliente `mysql`, es exactamente lo
que dice `.env.example`:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON `cpuser_latamsocial`.* TO 'cpuser_app'@'localhost';
GRANT ALL PRIVILEGES              ON `cpuser_latamsocial`.* TO 'cpuser_mig'@'localhost';
FLUSH PRIVILEGES;
```

**b) La prueba que decide si este hosting sirve.** Conectado **con el usuario de
migraciones**:

```sql
SELECT VERSION(), @@sql_mode, @@character_set_database, @@collation_database, @@log_bin;

CREATE TABLE zz_prueba (n INT);
CREATE TRIGGER zz_prueba_bi BEFORE INSERT ON zz_prueba FOR EACH ROW SET NEW.n = NEW.n;
DROP TABLE zz_prueba;
```

| Resultado | Lectura |
|---|---|
| Los tres comandos pasan | ✅ adelante |
| `ERROR 1419 (HY000): You do not have the SUPER privilege and binary logging is enabled` | 🔴 **la mina.** Hay binlog activo y tu usuario no es SUPER |
| `ERROR 1142 … TRIGGER command denied` | 🔴 falta el privilegio `TRIGGER` en el usuario de migraciones |

Si sale **1419**, hay que pedirle al hosting, por ticket, una de estas dos —la
primera es la barata y no afecta a nadie más:

```
SET GLOBAL log_bin_trust_function_creators = 1;   -- y en my.cnf, para que sobreviva a un reinicio
```

Si el hosting dice que no a las dos, **este servidor no puede alojar el
proyecto** y no hay forma de esquivarlo desde el código: no es una preferencia,
es que las reglas no se pueden instalar. La salida es un VPS o un MySQL
gestionado **8.0.16+** (o MariaDB 10.2+), donde `CHECK` es nativo y este
problema desaparece entero.

**c) Dos cosas más que se leen en el mismo `SELECT`:**

- `@@sql_mode` **tiene que contener `STRICT_TRANS_TABLES`**. Sin modo estricto,
  un `INSERT` que omite una columna `NOT NULL` mete `0` o `''` en vez de fallar,
  y media docena de restricciones dejan de significar lo que parecen.
- `@@character_set_database` **tiene que ser `utf8mb4`**. Si el panel la creó en
  `latin1`: `ALTER DATABASE cpuser_latamsocial CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
  **antes** de migrar.

Las dos las vuelve a comprobar `php artisan esquema:verificar` en el paso 0.11,
pero mirarlas ahora ahorra una migración a medias.

### 0.4 El código en el servidor, y dónde va la raíz del dominio

**La raíz del dominio NO puede ser la carpeta del proyecto.** Tiene que apuntar
a su subcarpeta `public/`; todo lo demás —el `.env`, `storage/`, `.git`— queda
fuera del alcance del navegador.

En este hosting el dominio ya tenía carpeta propia,
`/home3/cpanduro/latamsocial`, y **no estaba vacía**: contenía una instalación
de ChatPion (CodeIgniter: `application/`, `system/`, `ci/`, `member/`,
`plugins/`, `vendor/`). Lo primero es apartarla, sin borrar nada.

```bash
cd /home3/cpanduro

# 1. Copia de seguridad de lo que había, por si acaso. Reversible.
mkdir -p _archivo
tar -czf _archivo/latamsocial-chatpion-2026-09.tar.gz latamsocial

# 2. Apartar, no borrar
mv latamsocial latamsocial-viejo

# 3. El proyecto, en la carpeta que el dominio ya conoce
git clone https://github.com/carlospandurovia/latam-social.git latamsocial

# 4. `.well-known` es de AutoSSL: se conserva, y va DENTRO de la nueva raíz
mv latamsocial-viejo/.well-known latamsocial/public/ 2>/dev/null
```

> La base de datos del sitio viejo **no se toca**. Apartar los archivos no la
> borra, y si algún día hay que volver atrás, el `.tar.gz` y la base siguen ahí.

Queda así:

```
/home3/cpanduro/latamsocial/          ← el proyecto (aquí vive el .env)
/home3/cpanduro/latamsocial/public/   ← A ESTO apunta latamsocial.com
```

Y en el panel, dos ajustes por dominio:

1. **cPanel → Domains → latamsocial.com → Edit → Document Root** =
   `/home3/cpanduro/latamsocial/public`
2. **cPanel → MultiPHP Manager → latamsocial.com** = PHP **8.3**. En un hosting
   con varios sitios, cada dominio tiene su propia versión y la del servidor no
   dice nada.

> Para clonar un repositorio privado por HTTPS usa un **token personal de
> GitHub** de sólo lectura, no tu contraseña, y no lo dejes escrito en el
> `remote` (`git clone https://TOKEN@github.com/...` lo guarda en
> `.git/config` en claro). Mejor: clonar por SSH con una clave de despliegue.

**Si el panel no dejara mover el Document Root** —no es el caso aquí, se deja
dicho por si cambia el hosting—, la alternativa es repartir: el proyecto fuera
de la raíz web y **sólo el contenido de `public/`** dentro de ella, con el
`index.php` de `tools/servidor/index-raiz-fija.php`, que apunta a la carpeta
privada. Funciona, pero añade un paso a cada despliegue y rompe `storage:link`,
así que sólo se usa cuando no hay más remedio.

Comprobación, cuando el sitio ya responda:
`https://latamsocial.com/.env` y `https://latamsocial.com/storage/logs/laravel.log`
tienen que dar **404**. Si alguno descarga algo, para y arregla la raíz.

### 0.5 Dependencias de PHP

`vendor/` está en `.gitignore`, así que no viene con el clon.

**Opción A — Composer en el servidor** (si `proc_open` no está capado):

```bash
cd ~/apps/latamsocial
curl -sS https://getcomposer.org/installer | /opt/cpanel/ea-php83/root/usr/bin/php
/opt/cpanel/ea-php83/root/usr/bin/php -d memory_limit=-1 composer.phar install \
    --no-dev --optimize-autoloader --no-interaction
```

`--no-dev` no es cosmético: sin él suben PHPUnit, Pest, Larastan y Deptrac a
producción, que es superficie que no hace falta.

**Opción B — `proc_open` está deshabilitado.** Composer no puede correr ahí.
Instala en tu Windows con las mismas banderas y sube la carpeta:

```powershell
composer install --no-dev --optimize-autoloader
```
y sube `vendor/` por SFTP. Es lenta (miles de archivos): comprime a `.zip`,
súbelo y descomprime con `unzip` por SSH.

### 0.6 Los assets (CSS y JS)

`public/build/` también está en `.gitignore`, y **sin él la aplicación lanza una
excepción de Vite en cada página**. No es un fallo silencioso, pero sorprende.

**Opción A — Node ≥ 20 en el servidor:**
```bash
npm ci && npm run build
```

**Opción B — recomendada en hosting compartido:** compilar en tu Windows y subir
la carpeta.
```powershell
npm ci
npm run build
```
y subes `public/build/` por SFTP.

> **Consecuencia de la opción B, dicha ahora para que no sorprenda en el segundo
> despliegue:** como `public/build/` está ignorado, `git pull` **no** lo
> actualiza. Cada vez que cambie una vista o el CSS hay que volver a compilar y
> volver a subir. La alternativa —versionar `public/build/`— hace los `git pull`
> autosuficientes a cambio de meter binarios compilados en el repositorio y
> conflictos en cada rama. Para un solo entorno y un solo desplegador, subirlo a
> mano es lo más simple; si algún día hay dos personas desplegando, hay que
> mover esto a un pipeline.

**Aviso que hay que anotar (`T-94`):** la portada carga las tipografías desde
**`fonts.bunny.net`**, un tercero. Eso tiene que estar declarado en la política
de privacidad antes de anunciar el sitio.

### 0.7 El `.env` de producción

Parte de `.env.example` —lleva cada bloque comentado con su motivo— y **cambia
estos valores**, porque los que trae por defecto asumen Redis y S3, que un
hosting compartido no tiene:

```bash
cp .env.example .env
chmod 600 .env
/opt/cpanel/ea-php83/root/usr/bin/php artisan key:generate
```

```dotenv
APP_NAME="LATAM Social"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://latamsocial.com
APP_LOCALE=es_PE
APP_FALLBACK_LOCALE=es
APP_TIMEZONE=UTC

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cpuser_latamsocial
DB_USERNAME=cpuser_app
DB_PASSWORD=...

# Hosting compartido: no hay Redis
CACHE_STORE=file
QUEUE_CONNECTION=database
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true          # ver el paso 0.10: ponerlo DESPUÉS del SSL
SESSION_SAME_SITE=lax

# Hasta que haya cuenta S3/R2 (los vídeos de creadores la van a pedir)
FILESYSTEM_DISK=local

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-responder@latamsocial.com"

LOG_LEVEL=warning

# La facturación electrónica sigue en pruebas: la landing no la necesita
INTEGRATIONS_ENVIRONMENT=sandbox
SUNAT_ENVIRONMENT=beta
PERMITIR_CONEXIONES_DE_PRODUCCION=false

# Sólo para la primera semilla; se borra después (paso 0.8)
ADMIN_NAME="Carlos Panduro"
ADMIN_EMAIL=administracion@portalcts.com
ADMIN_PASSWORD=...
```

Cuatro cosas de este bloque que no son detalles:

- **`APP_ENV=production` es una barrera, no una etiqueta** (`9.22a`, `DEC-029`).
  Al ponerlo: se abre el envío real a SUNAT, el correo deja de desviarse al
  capturador, la analítica empieza a emitir, y `robots.txt` pasa de
  `Disallow: /` a permitir la indexación. Los cuatro cambios ocurren a la vez.
  Es lo que quieres el día del lanzamiento y **no** lo que quieres mientras
  pruebas: para un ensayo previo, deja `APP_ENV=staging`, el sitio funciona
  entero y no lo indexa nadie.
- **`APP_KEY`: genérala una vez y no la vuelvas a tocar.** Los números de cuenta
  bancaria están cifrados con ella. Cambiarla los deja ilegibles para siempre,
  con la huella cuadrando —el peor de los dos mundos—.
- **`SESSION_DRIVER=database` es un requisito de seguridad**, no una preferencia
  (`DEC-118`): cambiar la contraseña tiene que cerrar las sesiones abiertas, y
  con `file` eso no se puede hacer.
- **`DECOLECTA_API_KEY` sigue pendiente de rotar.** 🔴 La clave anterior quedó
  expuesta; genera una nueva en Decolecta antes de ponerla aquí.

### 0.8 Migraciones y semillas

**Con el usuario de migraciones, no con el de la aplicación**, y **antes** de
cachear la configuración (con `config:cache` puesto, Laravel deja de leer el
`.env` y estas variables de línea no llegan):

```bash
cd ~/apps/latamsocial
PHP=/opt/cpanel/ea-php83/root/usr/bin/php

DB_USERNAME=cpuser_mig DB_PASSWORD='...' $PHP artisan migrate --force
```

Esto es lo que instala los ~140 disparadores del paso 0.3. Si aquí sale un
`ERROR 1419`, para: vuelve al 0.3, no sigas con el esquema a medias.

Después, las semillas —cimientos del sitio, usuario administrador, plantillas de
correo y términos base—:

```bash
DB_USERNAME=cpuser_mig DB_PASSWORD='...' $PHP artisan db:seed --force
```

El administrador sale de `ADMIN_EMAIL` / `ADMIN_PASSWORD`. Si dejas
`ADMIN_PASSWORD` vacía, el seeder **genera una al azar y la imprime una sola
vez** — cópiala en ese momento o tendrás que usar `usuarios:contrasena`.

En cuanto entres al panel y cambies la contraseña, **borra `ADMIN_PASSWORD` del
`.env`**. Una contraseña en un archivo del servidor es una contraseña de más.

### 0.9 Permisos, enlaces y cachés

```bash
chmod -R 755 storage bootstrap/cache
find storage bootstrap/cache -type f -exec chmod 644 {} \;

$PHP artisan storage:link        # si `symlink` no está capado
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
```

**Nunca `chmod 777`.** En un servidor compartido eso significa «escribible por
cualquier otra cuenta de esta máquina».

Si `symlink` está deshabilitado, `storage:link` falla. Alternativa: crear el
enlace desde SSH (`ln -s ~/apps/latamsocial/storage/app/public ~/apps/latamsocial/public/storage`),
que es la misma operación sin pasar por PHP.

### 0.10 HTTPS, y por qué el orden importa

1. Emite el certificado (*AutoSSL* / *Let's Encrypt* en el panel).
2. Fuerza la redirección a HTTPS.
3. **Sólo entonces** pon `SESSION_SECURE_COOKIE=true` y vuelve a
   `php artisan config:cache`.

Al revés no se puede entrar: una cookie marcada como segura no viaja por HTTP,
así que el formulario de acceso acepta la contraseña y te devuelve al mismo
formulario, sin error. Es de los fallos más desconcertantes que existen.

### 0.11 Los autochequeos

En este orden, porque cada uno descarta una capa:

```bash
$PHP artisan esquema:verificar
$PHP artisan seguridad:privilegios --exigir
$PHP artisan migrate:status
$PHP artisan correos:probar administracion@portalcts.com
```

Qué es normal ver **en MySQL 5.7** y qué no:

| Línea | En 5.7 | ¿Problema? |
|---|---|---|
| `Aplica los CHECK de forma nativa` | `no, se compensa con TRIGGER` | **No.** Es la limitación asumida |
| `Soporta CTE (WITH)` | `no, usar subconsultas` | **No.** Ninguna consulta del proyecto usa CTE |
| `Soporta funciones de ventana` | `no` | **No.** Igual |
| `La base usa utf8mb4` | tiene que decir **sí** | 🔴 si no, vuelve al 0.3c |
| `Modo estricto activo` | tiene que decir **sí** | 🔴 si no, vuelve al 0.3c |
| `Cada restricción declarada está realmente impuesta` | tiene que pasar | 🔴 si falla, faltan disparadores: es el 1419 del 0.3 |

`seguridad:privilegios --exigir` termina con error si el usuario de la
aplicación puede vaciar `audit_logs`. Si falla, el usuario `app` tiene `DROP` y
hay que quitárselo (paso 0.3a).

### 0.12 El cron: dos líneas, y no son opcionales

Del §2 de este documento, con **la ruta completa del PHP 8.3**:

```cron
* * * * * cd /home/cpuser/apps/latamsocial && /opt/cpanel/ea-php83/root/usr/bin/php artisan queue:work --stop-when-empty --max-time=55 >> storage/logs/queue.log 2>&1
* * * * * cd /home/cpuser/apps/latamsocial && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> storage/logs/planificador.log 2>&1
```

La primera manda los correos. La segunda dispara cuatro trabajos programados
(§1). **No son la misma cosa**: `schedule:run` no procesa la cola.

### 0.13 Después de lanzar: lo que todavía es manual

Lo que sigue no es despliegue, es puesta a punto, y **sin ello la portada sale
con textos de fábrica**:

1. **Panel → Sitio público**: nombre comercial, descripción, redes, **número de
   WhatsApp**, país por defecto, y el medidor de analítica (GA4 / GTM / Meta /
   Plausible) si lo vas a usar.
2. **Panel → Empresa**: razón social, RUC y **dirección completa**. La portada de
   marcas los muestra en la franja «por qué confiar» y en el JSON-LD que leen los
   buscadores; mientras estén sin completar aparece *«por completar»* y el
   JSON-LD omite el dato en vez de mentir.
3. **Panel → Páginas**: revisar y **publicar** la política de privacidad y los
   términos. 🔴 Los textos actuales son un borrador profesional con marcadores;
   **`T-09` sigue abierto: falta la revisión de tu abogado**, y `T-94` exige
   además declarar ahí las tipografías de `fonts.bunny.net` y la analítica.
4. Abrir `https://latamsocial.com/robots.txt`: en producción **ya no** debe decir
   `Disallow: /`. Y `https://latamsocial.com/sitemap.xml` debe listar las
   portadas publicadas y las páginas del pie.
5. Entrar al panel, cambiar la contraseña del administrador y **borrar
   `ADMIN_PASSWORD` del `.env`**.
6. Mirar la portada en un móvil de verdad, no en el simulador del navegador.

---

## 1. Qué es cada cosa

El proyecto necesita **tres procesos** en producción, y sólo uno es obvio:

| Proceso | Qué hace | Sin él |
|---|---|---|
| El servidor web | atiende las pantallas | no hay sistema |
| **La cola** | envía los correos encolados | los avisos se quedan en `queued` para siempre |
| **El planificador** | dispara cuatro tareas programadas | ver la tabla del §2 |

Los dos últimos **no** se levantan solos al desplegar. Y son dos cosas
distintas: el error más común es poner sólo el planificador y preguntarse por
qué los correos no salen. **`schedule:run` no procesa la cola.**

> Nota de la v2.0: la v1.0 decía que del planificador no colgaba «nada hoy». Ya
> no es verdad: cuelgan cuatro trabajos, y dos de ellos paran el dinero.

---

## 2. La cola en hosting compartido: cron, pero con la línea correcta

`queue:work` normalmente es un proceso **daemon** que se queda escuchando. En un
hosting compartido no se puede dejar un daemon vivo, así que se hace al revés:
un cron que arranca el worker, vacía lo que haya, y se muere.

```cron
* * * * * cd /ruta/al/proyecto && /ruta/a/php8.3 artisan queue:work --stop-when-empty --max-time=55 >> storage/logs/queue.log 2>&1
* * * * * cd /ruta/al/proyecto && /ruta/a/php8.3 artisan schedule:run >> storage/logs/planificador.log 2>&1
```

### Las dos banderas no son opcionales

| Bandera | Qué evita |
|---|---|
| `--stop-when-empty` | que el worker se quede vivo esperando trabajo que no llega |
| `--max-time=55` | **que cada minuto arranque uno más** y en una hora haya sesenta peleándose |

Sin `--max-time`, el cron de cada minuto va apilando procesos hasta tumbar el
servidor. Es el modo de fallo que hay que conocer antes de poner la línea.

### Lo que cuelga de la SEGUNDA línea

Cuatro trabajos, registrados en los proveedores de cada módulo. Los dos primeros
tienen consecuencias que nadie ve hasta que duelen:

| Trabajo | Cada cuánto | Qué pasa si la línea no está |
|---|---|---|
| `invitaciones:caducar` (7.6) | 10 min | una invitación sin contestar deja su importe comprometido y su plaza del cupo ocupada **para siempre** |
| `permanencia:vigilar` (8.8) | diario, 06:00 | ninguna ventana de permanencia se cierra, y **ningún pago se habilita** |
| `cambio:traer` (9.2) | diario, 05:30 | no entran tipos de cambio nuevos; se factura con el último que haya |
| `ledger:revisar` | diario, 06:30 | nadie comprueba que los devengos cuadran |

Los cuatro escriben en `storage/logs/planificador.log` y dicen lo que hicieron
**también cuando son cero**: «0 ventanas cerradas» demuestra que el cron corrió,
y el silencio no distingue entre «no había nada» y «la línea no está».

Si al mirar ese log no hay una línea de hoy, la línea de cron no está puesta.

### Dos cosas que se rompen en silencio

1. **`php` a secas puede no ser la versión correcta.** Muchos paneles tienen PHP
   7.4 como binario por defecto y el 8.3 en otra ruta. Comprobar con
   `which -a php` y **usar la ruta completa**. Con la versión equivocada el cron
   falla cada minuto y no avisa a nadie.
2. **`>> /dev/null` deja ciego.** Las primeras semanas conviene el log de verdad,
   como arriba. Se puede cambiar a `/dev/null` cuando haya confianza — y
   entonces conviene poner una rotación, o el log crece sin fin.

### Lo que cambia respecto a un daemon

Los correos salen **con hasta un minuto de retraso**. Para lo que manda hoy el
sistema —avisos de cambio de datos fiscales, enlaces de contraseña,
invitaciones— es irrelevante.

Los **reintentos** siguen funcionando sin tocar nada: `EnviarCorreo` espera 1, 5
y 15 minutos entre intentos (`DEC-108`), y una ejecución posterior del cron los
recoge cuando toca.

> **Si el hosting permite procesos permanentes** (VPS con Supervisor o systemd),
> ésa es la opción buena: `queue:work --tries=3` como servicio, y el retraso
> desaparece. El cron es la alternativa para hosting compartido, y para el MVP
> sobra.

---

## 3. Los pasos de despliegue, en orden

### 3.1 Antes del primer despliegue — una sola vez

Todo esto está desarrollado paso a paso en el **§0**. Resumen:

**a) Los dos usuarios de base de datos (`DEC-085`).** Esto es lo que impide que
la aplicación pueda vaciar la bitácora. Mientras no se ejecute, **la bitácora es
truncable desde la aplicación** y la garantía de auditoría es una intención.

```sql
-- El de APLICACIÓN: no puede cambiar el esquema.
CREATE USER 'latam_app'@'%' IDENTIFIED BY '<contraseña>';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON `latam_social`.* TO 'latam_app'@'%';

-- El de MIGRACIONES: el único que cambia el esquema.
CREATE USER 'latam_mig'@'%' IDENTIFIED BY '<otra contraseña>';
GRANT ALL PRIVILEGES ON `latam_social`.* TO 'latam_mig'@'%';

FLUSH PRIVILEGES;
```

Y **comprobarlo**, no darlo por hecho:

```bash
php artisan seguridad:privilegios
```

**b) `APP_KEY`.** `php artisan key:generate`. Si se pierde o se cambia después,
**los números de cuenta bancaria cifrados dejan de poder descifrarse** — están
cifrados con ella. La huella (`account_number_fingerprint`) seguiría cuadrando y
el número no se podría leer: el peor de los dos mundos.

**c) El resto del `.env`.** Partir de `.env.example`, que lleva todos los
bloques comentados con su motivo. Los que no pueden quedar vacíos:

| Variable | Sin ella |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false` | los errores enseñan el `.env` entero al visitante |
| `APP_URL` | los enlaces de los correos apuntan a `localhost` — y uno de esos enlaces es el de poner la contraseña, o sea que la cuenta no se puede estrenar |
| `APP_FALLBACK_LOCALE=es` | 🔴 el sitio público imprime **claves de traducción** (`publico.entrar`) con un 200 y sin error visible. El proyecto se defiende de esto en `CoreServiceProvider`, pero no hay razón para depender de la defensa |
| `DB_*` | no arranca |
| `CACHE_STORE=file` | busca un Redis que en hosting compartido no existe |
| `QUEUE_CONNECTION=database` | los correos se enviarían **dentro** de la petición |
| `SESSION_DRIVER=database` | ⚠️ **requisito de seguridad desde `4.1`, no una preferencia** (`DEC-118`) |
| `SESSION_SECURE_COOKIE=true` | la cookie de sesión viaja en claro |
| `FILESYSTEM_DISK` | por defecto es `s3`; sin cuenta, toda subida de archivo falla |
| `MAIL_*` | ver §3.3 |

**d) `php artisan storage:link`**, si los archivos van en disco local.

### 3.2 En cada despliegue

```bash
# 1. Código
git pull

# 2. Dependencias, sin las de desarrollo
composer install --no-dev --optimize-autoloader

# 3. Assets — si se compilan fuera, subir `public/build/` AHORA (§0.6)
npm ci && npm run build

# 4. Migraciones, CON EL USUARIO DE MIGRACIONES y ANTES de cachear
php artisan config:clear
DB_USERNAME=latam_mig DB_PASSWORD=... php artisan migrate --force

# 5. Cachés
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Reiniciar los workers para que cojan el código nuevo
php artisan queue:restart
```

**El paso 6 no es opcional.** Un worker que ya estaba corriendo tiene el código
viejo en memoria y lo seguirá usando hasta que muera. Con el cron de
`--max-time=55` el worker muere solo cada minuto, así que el riesgo es pequeño —
pero `queue:restart` lo cierra del todo y no cuesta nada.

> **`config:cache` y `env()`.** Con la configuración cacheada, Laravel **deja de
> leer el `.env`** y `env()` devuelve `null` fuera de `config/`. Por eso todo lo
> del proyecto pasa por `config/latam.php`, y por eso el paso 4 lleva un
> `config:clear` delante: sin él, el `DB_USERNAME=latam_mig` de la línea de
> comandos no llega y la migración corre con el usuario de la aplicación —que no
> puede crear disparadores— y falla a la mitad.

### 3.3 Para que los correos salgan de verdad

Fuera de producción el correo **siempre** pasa por un capturador
(`MAIL_SAFETY_CATCHER`, `BR-INT-005`): se escribe en
`storage/logs/laravel.log` y no sale a internet. Es deliberado.

Para producción hace falta la cuenta de SMTP (**`Q-20`, sigue pendiente**) y:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-responder@latamsocial.com
```

Y comprobarlo:

```bash
php artisan correos:probar tu-correo@ejemplo.com
```

Si dice *«OJO: MAIL_MAILER=log»*, no ha salido a internet.

**Además hay que publicar las plantillas.** Sin al menos una versión vigente,
`Correo::enviar()` lanza una excepción a propósito — es un fallo de
configuración de la plataforma y tiene que verse, no tragarse. La semilla
`PlantillasDeCorreoSeeder` deja las básicas; para una nueva:

```bash
php artisan correos:publicar creator.tax_profile_changed avisos/fiscal-es.txt --idioma=es
```

---

## 4. Lo que sigue pendiente y no es código

| Qué | Quién | Consecuencia mientras tanto |
|---|---|---|
| `log_bin_trust_function_creators` en 5.7 (§0.3) | el hosting, por ticket | 🔴 **el esquema no se puede instalar entero** |
| Ejecutar los dos `GRANT` (`DEC-085`) | tú, al desplegar | la bitácora es truncable desde la aplicación |
| Rotar la clave de Decolecta | tú | 🔴 hay una clave expuesta en circulación |
| Cuenta de SMTP (`Q-20`) | tú / el proveedor | ningún aviso sale a internet |
| Cuenta S3 o equivalente | tú | los archivos van a disco local; los vídeos de creadores la van a pedir |
| Texto real de los términos (`T-09`) | tu abogado | 🔴 **ningún creador puede activarse** |
| Declarar `fonts.bunny.net` y la analítica en privacidad (`T-94`) | tú + abogado | la política no dice la verdad completa |
| Tasa de retención a no domiciliados (`Q-40`) | tu contador | un perfil fiscal así no se puede aprobar |
| Exportación de servicios vs IGV (`Q-44`) | tu contador | no se puede facturar al exterior |

Los tres últimos están desarrollados en `docs/17-BLOQUEOS-OPERATIVOS.md`.

---

## 5. Comprobación después de desplegar

En este orden, porque cada uno descarta una capa:

```bash
php artisan esquema:verificar          # el motor hace lo que creemos
php artisan seguridad:privilegios      # los GRANT están puestos
php artisan migrate:status             # no falta ninguna migración
php artisan correos:probar tu@correo   # la salida de correo funciona
```

Y a mano:

1. Entrar al panel. Si sale un error con trazas, `APP_DEBUG` sigue en `true`.
1a. **Comprobar que el planificador corre.** Mirar `storage/logs/planificador.log`;
   vacío significa que no corre, y entonces las invitaciones sin contestar se
   quedan vivas para siempre y ningún pago se habilita.
1b. **Probar `/recuperar` con tu propio correo.** Es la comprobación que recorre
   más capas de una vez: cola, SMTP, plantilla y `APP_URL`. Si el enlace que
   llega empieza por `http://localhost`, `APP_URL` está mal y ninguna cuenta
   nueva se podrá estrenar.
2. Encolar un correo real (aprobar un creador, por ejemplo) y mirar
   **`/correos`**. Si al minuto sigue en `queued`, el cron de la cola no está
   corriendo o el binario de PHP es el equivocado.
3. Mirar `storage/logs/queue.log`. Vacío también es una respuesta: significa que
   el cron no se está ejecutando.
4. **La portada, `/marcas`, en un móvil.** Que el titular sea «Muchas voces. Una
   sola campaña.» y no un texto de fábrica; que el menú se abra; que el
   formulario mande y lleve a la página de gracias.

---

## 6. Lo que este runbook todavía no cubre

Se dice para que no parezca completo cuando no lo está. Todo esto es `F17`:

- Copias de seguridad y **restauración probada** — una copia que nadie ha
  restaurado nunca no es una copia.
- Monitorización y alertas: hoy, que la cola deje de correr no avisa a nadie.
- Renovación del certificado SSL.
- Rotación de logs.
- Plan de vuelta atrás si una migración sale mal. Con ~140 disparadores en 5.7,
  el `down()` de una migración es más frágil que en MySQL 8, y esto **hay que
  probarlo antes de necesitarlo**.

---

## 7. Diagnóstico rápido: síntoma → causa

| Lo que ves | Casi siempre es |
|---|---|
| Página en blanco / 500 sin más | mirar `storage/logs/laravel.log`; si está vacío, permisos de `storage/` |
| «403 Forbidden» en la raíz | el Document Root no apunta a `public/` (§0.4) |
| `Vite manifest not found` | falta `public/build/` (§0.6) |
| La página imprime `publico.entrar`, `publico.pie.para_marcas` | la carpeta `lang/` no se subió, o `APP_FALLBACK_LOCALE` no es `es` |
| Los mensajes de error del formulario dicen `validation.email` | lo mismo: falta `lang/es/validation.php` |
| El acceso acepta la contraseña y vuelve al formulario, sin error | `SESSION_SECURE_COOKIE=true` sin HTTPS funcionando (§0.10) |
| `ERROR 1419` al migrar | la mina del §0.3 |
| `SQLSTATE[HY000] [2002]` | `DB_HOST`: en cPanel casi siempre `localhost`, no `127.0.0.1` |
| Los correos se quedan en `queued` | el cron de la cola, o la ruta del PHP (§0.12) |
| La portada muestra «por completar» | falta rellenar Empresa y Sitio público (§0.13) |
| Google no indexa | `APP_ENV` no es `production`, así que `robots.txt` dice `Disallow: /` |
| `robots.txt` no cambia con el entorno | había un `public/robots.txt` estático: Apache lo sirve **antes** que Laravel y la ruta nunca corre |
| Ningún estilo carga, y el navegador pide `http://[::1]:5173` | se copió `public/hot` al servidor. Es el marcador del Vite de desarrollo: **nunca se sube** |
