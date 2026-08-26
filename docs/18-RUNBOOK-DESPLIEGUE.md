# 18 — Runbook de despliegue

> **Versión 1.0 — 2026-08-26.** Escrito al cerrar `F4.9` (el correo).
>
> Este documento existe porque los pasos de despliegue estaban viviendo en
> conversaciones. `DEC-085` —los dos `GRANT` que protegen la bitácora— lleva
> desde el 25 de agosto marcado como *«falta ejecutarlo en producción»*, y la
> única razón por la que no se ha olvidado es que alguien se acuerda. Eso no es
> un procedimiento.
>
> **Regla:** si un paso sólo existe en un mensaje, no existe. Va aquí.

---

## 1. Qué es cada cosa

El proyecto necesita **tres procesos** en producción, y sólo uno es obvio:

| Proceso | Qué hace | Sin él |
|---|---|---|
| El servidor web | atiende las pantallas | no hay sistema |
| **La cola** | envía los correos encolados | los avisos se quedan en `queued` para siempre |
| **El scheduler** | dispara tareas programadas | nada hoy; lo necesitará `F4.8` en adelante |

Los dos últimos **no** se levantan solos al desplegar. Y son dos cosas
distintas: el error más común es poner sólo el scheduler y preguntarse por qué
los correos no salen. **`schedule:run` no procesa la cola.**

---

## 2. La cola en hosting compartido: cron, pero con la línea correcta

`queue:work` normalmente es un proceso **daemon** que se queda escuchando. En un
hosting compartido no se puede dejar un daemon vivo, así que se hace al revés:
un cron que arranca el worker, vacía lo que haya, y se muere.

```cron
* * * * * cd /ruta/al/proyecto && /ruta/a/php8.3 artisan queue:work --stop-when-empty --max-time=55 >> storage/logs/queue.log 2>&1
* * * * * cd /ruta/al/proyecto && /ruta/a/php8.3 artisan schedule:run >> storage/logs/schedule.log 2>&1
```

### Las dos banderas no son opcionales

| Bandera | Qué evita |
|---|---|
| `--stop-when-empty` | que el worker se quede vivo esperando trabajo que no llega |
| `--max-time=55` | **que cada minuto arranque uno más** y en una hora haya sesenta peleándose |

Sin `--max-time`, el cron de cada minuto va apilando procesos hasta tumbar el
servidor. Es el modo de fallo que hay que conocer antes de poner la línea.

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
| `APP_URL` | los enlaces de los correos apuntan a `localhost` — y desde `4.1` uno de esos enlaces es el de poner la contraseña, o sea que la cuenta no se puede estrenar |
| `DB_*` | no arranca |
| `QUEUE_CONNECTION=database` | los correos se enviarían **dentro** de la petición |
| `SESSION_DRIVER=database` | ⚠️ **requisito de seguridad desde `4.1`, no una preferencia.** Poner una contraseña nueva borra las sesiones abiertas de esa cuenta, y con `file` eso es imposible: no hay forma de saber qué archivo es de quién. La contraseña nueva convivirá con la sesión de quien entró con la vieja, que es no haber hecho nada |
| `MAIL_*` | ver §3.3 |

**d) `php artisan storage:link`**, si los archivos van en disco local.

### 3.2 En cada despliegue

```bash
# 1. Código
git pull

# 2. Dependencias, sin las de desarrollo
composer install --no-dev --optimize-autoloader

# 3. Migraciones, CON EL USUARIO DE MIGRACIONES
DB_USERNAME=latam_mig DB_PASSWORD=... php artisan migrate --force

# 4. Cachés
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Reiniciar los workers para que cojan el código nuevo
php artisan queue:restart
```

**El paso 5 no es opcional.** Un worker que ya estaba corriendo tiene el código
viejo en memoria y lo seguirá usando hasta que muera. Con el cron de `--max-time=55`
el worker muere solo cada minuto, así que el riesgo es pequeño — pero
`queue:restart` lo cierra del todo y no cuesta nada.

> **`config:cache` y `env()`.** Con la configuración cacheada, Laravel **deja de
> leer el `.env`** y `env()` devuelve `null` fuera de `config/`. Por eso todo lo
> del proyecto pasa por `config/latam.php`. Si alguien añade un `env()` en un
> servicio, funcionará en local y devolverá `null` en producción **sin fallar**.
> Lo cazó PHPStan una vez; conviene que lo siga cazando.

### 3.3 Para que los correos salgan de verdad

Hoy `MAIL_MAILER=log` por defecto: los correos se **escriben en
`storage/logs/laravel.log`** y no salen a internet. Es deliberado — sin
credenciales, un `smtp` por defecto falla en cada envío y llena `email_log` de
fallos que no son culpa de nadie.

Para producción hace falta la cuenta de SMTP (**`Q-20`, sigue pendiente**) y:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-responder@portalcts.com
```

Y comprobarlo:

```bash
php artisan correos:probar tu-correo@ejemplo.com
```

Si dice *«OJO: MAIL_MAILER=log»*, no ha salido a internet.

**Además hay que publicar las plantillas.** Sin al menos una versión vigente,
`Correo::enviar()` lanza una excepción a propósito — es un fallo de
configuración de la plataforma y tiene que verse, no tragarse:

```bash
php artisan correos:publicar creator.tax_profile_changed avisos/fiscal-es.txt --idioma=es
```

---

## 4. Lo que sigue pendiente y no es código

| Qué | Quién | Consecuencia mientras tanto |
|---|---|---|
| Ejecutar los dos `GRANT` (`DEC-085`) | tú, al desplegar | la bitácora es truncable desde la aplicación |
| Cuenta de SMTP (`Q-20`) | tú / el proveedor | ningún aviso sale a internet |
| Cuenta S3 o equivalente | tú | los archivos van a disco local |
| Texto real de los términos (`T-09`) | tu abogado | 🔴 **ningún creador puede activarse** |
| Tasa de retención a no domiciliados (`Q-40`) | tu contador | un perfil fiscal así no se puede aprobar |
| Exportación de servicios vs IGV (`Q-44`) | tu contador | no se puede facturar al exterior |

Los tres últimos están desarrollados en `docs/17-BLOQUEOS-OPERATIVOS.md`.

---

## 5. Comprobación después de desplegar

En este orden, porque cada uno descarta una capa:

```bash
php artisan seguridad:privilegios      # los GRANT están puestos
php artisan migrate:status             # no falta ninguna migración
php artisan correos:probar tu@correo   # la salida de correo funciona
```

Y a mano:

1. Entrar al panel. Si sale un error con trazas, `APP_DEBUG` sigue en `true`.
1b. **Probar `/recuperar` con tu propio correo.** Es la comprobación que recorre
   más capas de una vez: cola, SMTP, plantilla y `APP_URL`. Si el enlace que
   llega empieza por `http://localhost`, `APP_URL` está mal y ninguna cuenta
   nueva se podrá estrenar.
2. Encolar un correo real (aprobar un creador, por ejemplo) y mirar
   **`/correos`**. Si al minuto sigue en `queued`, el cron de la cola no está
   corriendo o el binario de PHP es el equivocado.
3. Mirar `storage/logs/queue.log`. Vacío también es una respuesta: significa que
   el cron no se está ejecutando.

---

## 6. Lo que este runbook todavía no cubre

Se dice para que no parezca completo cuando no lo está. Todo esto es `F17`:

- Copias de seguridad y **restauración probada** — una copia que nadie ha
  restaurado nunca no es una copia.
- Monitorización y alertas: hoy, que la cola deje de correr no avisa a nadie.
- Certificado SSL y renovación.
- Rotación de logs.
- Plan de vuelta atrás si una migración sale mal.
