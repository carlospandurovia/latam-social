# L-2a — Los datos que se pintan en la calle

> *«todo lo que me pidas debe ser configurable desde el admin»*
> — el negocio, corrigiendo la auditoría.

---

## 1. De dónde sale

La auditoría de la landing terminaba pidiéndote siete datos: el WhatsApp, el
correo público, las redes, la dirección, el presupuesto mínimo. Para escribirlos
en una plantilla.

**Era la respuesta equivocada, y tu corrección es la buena.** El sitio donde van
esos datos no es el chat: es el admin. Es `DEC-190` otra vez, y esta vez llegó
como corrección — que es la forma en que se aprenden.

Así que esta iteración no pinta ni una sección nueva de la landing. Construye
**el sitio donde pones los datos**, y engancha los primeros donde ya se notan.

---

## 2. Qué hay ahora

**Configuración → Sitio público** (`/backoffice/sitio`), detrás de
`brand.manage` —el mismo permiso que la portada, porque quien decide cómo nos
llamamos decide qué teléfono damos—:

| Bloque | Qué se configura |
|---|---|
| **Quién opera esta marca** | La sociedad de la que salen razón social, RUC y domicilio **para los textos legales**. Se declara, no se adivina |
| **WhatsApp** | Número y **mensaje de arranque**, con el enlace armado a la vista para pulsarlo y comprobarlo |
| **Contacto público** | Correo, teléfono y dirección que se enseñan en la calle |
| **Redes sociales** | Las que sean, en el orden que sea, encendidas o apagadas |

Y el **pie de la portada deja de ser una línea**: tres columnas con marca,
navegación y contacto, con los iconos de las redes.

---

## 3. Cuatro decisiones, y por qué

### 3.1 Una tabla propia, no más columnas en `platform_brands`

`platform_brands` es **identidad**: cómo nos llamamos, de qué color somos, qué
letra usamos. Esto es otra pregunta: **cómo nos contactan**. Meterlo ahí dejaría
una tabla de treinta columnas donde conviven el favicon y el teléfono, y a la
tercera ampliación nadie sabría qué es identidad y qué es marketing.

Mismo reparto que `mail_settings` colgando de `integration_connections` en
`9.17g`: una tabla por pregunta.

### 3.2 Las redes son **filas**, no columnas

Seis columnas `instagram_url`, `tiktok_url`, `linkedin_url`… es la forma obvia y
es la equivocada: el día que exista una red nueva haría falta **una migración y
un despliegue** para algo que es puro contenido.

El código de red es texto libre. La plantilla dibuja el icono que conozca —hay
siete— y, si no lo conoce, **un eslabón de enlace**. Una red nueva funciona el
mismo día, sin icono roto y sin desplegar nada. Hay una prueba que mete una red
inventada y comprueba que la página no se rompe.

### 3.3 El WhatsApp se guarda en E.164 **y la base lo impone**

`+51987654321`. Sin espacios, sin guiones, sin paréntesis.

No es un capricho de formato: **este valor viaja dentro de una URL**
(`https://wa.me/…`), y un espacio la rompe **sin dar ningún error** — el enlace
simplemente no abre nada, o abre WhatsApp sin destinatario. Lo comprueba
`ck_ss_whatsapp` en la base y lo explica el formulario antes de llegar ahí.

Y el enlace se arma **en el servicio, no en la plantilla**. Pegar un número y un
texto dentro de una URL es codificar, no maquetar. Hay una prueba con un mensaje
que lleva `&`, `?` y una tilde: los tres rompen el enlace si alguien lo pega a
mano, y ninguno da error.

### 3.4 Lo que no está configurado **no se pinta**

Ni un hueco, ni un `mailto:` vacío, ni un enlace a ninguna parte. Misma regla que
el logotipo en `9.17`: una imagen rota es peor que ninguna imagen.

---

## 4. La sociedad operadora

Es el campo que menos parece y más pesa. De él salen la **razón social**, el
**RUC** y el **domicilio** que van a aparecer en la política de privacidad y en
los términos (`L-2c`).

No se deduce de «la primera sociedad» ni de «la que tenga más facturas»: **se
declara**. Una política de privacidad que nombra a la sociedad equivocada es un
documento sin valor, y adivinarlo es exactamente cómo se llega ahí.

Mientras falte, el aviso es **rojo** y dice qué se rompe, no sólo qué falta.

---

## 5. Lo que se siembra, y lo que no

Se siembra **la fila**, no los valores. El WhatsApp y el correo público son de la
empresa y nadie los puede inventar (§12: no inventar nada).

Lo único con valor de partida es el **mensaje de arranque** —que es copy, y un
copy de partida es mejor que un campo vacío— y la **sociedad operadora**, que
aquí se sabe con certeza porque la siembra el mismo seeder.

Lo demás sale en rojo y en ámbar en Configuración. El sistema arranca, y dice qué
le falta.

---

## 6. Comprobación

`tests/Feature/SitioPublicoTest.php` — **16 pruebas**.
`tools/pruebas/L2a-sitio.sh` — **16 aserciones SQL**.

Totales: **2 504 aserciones SQL en MariaDB** y **2 494 en MySQL 8**; las seis
puertas en verde.

`ConfiguracionTest` se puso roja sola al añadir el área, que es exactamente para
lo que existe: es la **novena vez** que esa prueba avisa de una pantalla de
configuración que alguien no enchufó al panel.

### Y la suite de SQL cazó un defecto real al primer intento

`ck_sl_red` decía «el código de red va en minúsculas» y **no lo hacía**:

```sql
CONSTRAINT ck_sl_red CHECK (network REGEXP '^[a-z0-9_-]{2,30}$')
```

`REGEXP` compara con la colación de la columna, y la de este proyecto es
`utf8mb4_unicode_ci` — **insensible a mayúsculas**. Así que `'TikTok'` casaba
contra `^[a-z0-9_-]+$` y entraba. La regla decía una cosa y hacía otra.

Se arregla con `network COLLATE utf8mb4_bin REGEXP …`, comprobado en los dos
motores.

**Lo que importa no es el arreglo: es quién lo encontró.** Ninguna prueba de PHP
podía verlo —el defecto estaba en la base, no en el código— y ninguna revisión de
código tampoco: la expresión es correcta a la vista. Lo encontró una aserción que
afirmaba *por qué* se rechaza, ejecutada contra el motor de verdad. Se buscó el
mismo patrón en todo el proyecto: no hay ningún otro caso.

---

## 7. Qué hay que hacer en el servidor

1. `php artisan migrate`
2. `php artisan db:seed --class=CimientosSeeder` (crea la fila con la sociedad y el mensaje de partida)
3. **Configuración → Sitio público**: poner el WhatsApp, el correo público y las redes.

---

## 8. Lo siguiente

- **`L-2b`** — «Páginas»: la sección del admin donde se crean páginas públicas por base de datos, con versionado, y el motor de sustitución que rellena `{{empresa.razon_social}}` y compañía.
- **`L-2c`** — la política de privacidad y los términos, escritos como documento profesional y sembrados como versión 1 **sin revisar por un abogado** (§56).
- **`T-89`** 🟡 — `verificar-migraciones.py` da tres falsos positivos en `electronic_documents` y `document_submissions`: no reconoce `$tabla->uuid(...)` porque esas dos migraciones llaman `$tabla` a lo que las demás llaman `$table`. El verificador miente, y un verificador que miente es peor que no tenerlo.
