# 20 — Consultas externas

> Lo que el sistema necesita de fuera: una respuesta legal, dos tributarias y
> dos órdenes en el servidor. Cada sección está escrita para reenviarse tal cual.

**Versión 1.0 — 2026-08-27.**

---

## 0. Qué bloquea cada una, y dónde se responde

| | Qué bloquea hoy | Se responde |
|---|---|---|
| `T-09` | **Toda activación de creadores.** Visible en pantalla | publicando el texto con un comando |
| `Q-40` | La aprobación de un perfil fiscal de creador **no domiciliado** | contestando aquí; yo lo codifico |
| `Q-44` | La primera factura a un cliente **del exterior** | contestando aquí; yo lo codifico |
| `DEC-085` | Nada visible. La bitácora es truncable desde la aplicación | dos `GRANT` en el servidor |

**Ninguna se responde dentro de la aplicación.** No hay una pantalla de
«preguntas pendientes»: son decisiones de fuera, y el sistema lo único que hace
es no dejar avanzar sin ellas.

---

## 1. `T-09` · Términos y condiciones del creador — **para el abogado**

### Qué está pasando ahora mismo

La pantalla de activación de cada creador muestra esto, en rojo:

> No hay ninguna versión vigente publicada. Publícala con
> `php artisan terminos:publicar` antes de registrar aceptaciones. **No se puede
> aceptar un documento que no existe.**

Aceptar los términos es uno de los seis requisitos de `BR-CREATOR-006`, así que
**ningún creador puede pasar a `activo`**. Todo lo demás funciona —alta,
aprobación, verificación de identidad, redes sociales, tarifas, perfil fiscal,
medio de pago— y se queda parado en el último paso.

### Lo que hace falta

El **texto íntegro** de los términos y condiciones que un creador acepta antes
de trabajar en una campaña. En castellano, en `.md`, `.txt` o `.html`.

### El texto tiene que cubrir, como mínimo

Esto no es un índice legal: es la lista de cosas que el sistema ya hace y que el
documento tiene que respaldar. El abogado dirá qué falta y qué sobra.

| Lo que el sistema hace | Lo que el texto tiene que decir |
|---|---|
| Guarda datos personales, documento de identidad y una imagen de él | qué se guarda, para qué y durante cuánto |
| Guarda datos fiscales y una cuenta bancaria (cifrada) | igual, y quién puede verlos |
| Registra un precio acordado por campaña, congelado al aceptar | cómo se pacta y cuándo deja de poder cambiarse |
| Paga a **N días** desde un hito (hoy configurable por creador) | cuál es el hito y cuál el plazo |
| Incluye **2 rondas de corrección** por pieza, sólo las que pide el cliente | qué es una ronda y qué pasa con las de más |
| Exige que el post **permanezca publicado N días** (por campaña y red) | la obligación de permanencia, y qué pasa si se retira antes |
| Archiva una **captura de pantalla** del post como prueba | que se conserva evidencia y para qué |
| Puede **retener el pago** si el post se retira antes de tiempo | esto en particular: hoy el sistema lo bloquea y no descuenta nada, y una persona decide |
| El creador puede ser **menor de edad** con apoderado | el consentimiento del apoderado |
| Guarda IP y fecha de cada aceptación | que la aceptación electrónica vale como firma |

### Dos preguntas concretas que el sistema necesita responder

1. **¿La aceptación por enlace, con IP y fecha, es prueba suficiente?** Hoy el
   equipo la recibe por correo o WhatsApp y la archiva a mano. Si hace falta
   otra cosa (doble confirmación, un PDF firmado), hay que saberlo antes de
   construir el portal del creador.
2. **¿Puede el texto cambiar y afectar a quien ya aceptó?** El sistema guarda
   **qué versión** aceptó cada creador y la conserva; si legalmente hay que
   volver a pedirla al cambiar el texto, eso es una funcionalidad que no existe.

### Cómo entra al sistema, cuando llegue

```bash
php artisan terminos:publicar creator_terms 2026.1 \
  --titulo="Términos y condiciones del creador" \
  --archivo=ruta/al/texto.md \
  --publico=creator \
  --desde=2026-09-01
```

El comando cierra la versión anterior el día antes de que empiece la nueva, así
que el histórico nunca tiene dos textos vigentes a la vez.

---

## 2. `Q-40` · Retención a creadores no domiciliados — **para el contador**

### La pregunta

**¿Con qué tasa se practica la retención de renta a un creador no domiciliado en
Perú, y con qué norma se sustenta?**

### Contexto que el contador necesita

- La empresa es **Soluciones Tecnológicas a Medida S.A.C.** (RUC 20603203896),
  domiciliada en Perú.
- Contrata a creadores de contenido para campañas publicitarias. Algunos son
  peruanos; el plan es abrir a **Colombia, México, Ecuador, Chile, España y
  Estados Unidos**.
- Al creador se le paga un importe acordado por campaña. No es relación laboral.
- El servicio del creador —grabar y publicar contenido en sus redes— **se presta
  desde su país**, y el resultado se consume desde Perú.

### Antes de la tabla: quién paga NO depende del país del creador

Esto cambió el 2026-08-27 (`DEC-156`) y cambia el eje de la pregunta, así que va
antes que los números.

**Cada campaña lleva escrita una sociedad del grupo, y esa sociedad paga a todos
los creadores de esa campaña, sea cual sea el país de cada uno.** La sociedad
sale del país del CLIENTE, no del creador: un cliente peruano hace campañas de
CTS Perú, y CTS Perú paga a los creadores de esa campaña aunque uno sea
colombiano y otro mexicano.

Lo que el país del creador sí cambia es **cómo** se le paga: la retención, la
moneda, y qué documento hace falta. Eso es exactamente lo que se pregunta abajo.

Por eso **la tabla es de CTS Perú**, no de «cada país con su pagador».

### Lo que el sistema necesita de vuelta

**Esta tabla, rellena.**

#### Tabla 1 — **CTS Perú paga** (Soluciones Tecnológicas a Medida S.A.C., RUC 20603203896)

Una fila por país del creador. Todas son pagos hechos **desde Perú**.

| País del creador | Tasa de retención | Norma que la sustenta | ¿Convenio de doble imposición? ¿Cuál? | ¿Qué documento hace falta del creador? |
|---|---|---|---|---|
| 🇵🇪 Perú (domiciliado) | | | — | |
| 🇨🇴 Colombia | | | | |
| 🇪🇨 Ecuador | | | | |
| 🇨🇱 Chile | | | | |
| 🇲🇽 México | | | | |
| 🇺🇸 Estados Unidos | | | | |
| 🇪🇸 España | | | | |

#### Tabla 2 — **CTS Colombia paga** — *esta la contesta un contador colombiano*

Misma tabla, otra ley. Se envía aparte, y hasta que CTS Colombia tenga clientes
propios puede quedarse en blanco sin bloquear nada.

| País del creador | Tasa de retención | Norma que la sustenta | ¿Convenio de doble imposición? ¿Cuál? | ¿Qué documento hace falta del creador? |
|---|---|---|---|---|
| 🇨🇴 Colombia (domiciliado) | | | — | |
| 🇵🇪 Perú | | | | |
| 🇪🇨 Ecuador | | | | |
| 🇨🇱 Chile | | | | |
| 🇲🇽 México | | | | |
| 🇺🇸 Estados Unidos | | | | |
| 🇪🇸 España | | | | |

**Perú va en la tabla 1** aunque no sea el caso de «no domiciliado»: si a un
creador peruano se le retiene algo —renta de cuarta categoría, por ejemplo—, el
sistema tiene que guardarlo igual.

**Y España ya no es un hueco.** Antes esta tabla decía «España: ninguna sociedad
todavía», porque `Q-15` reparte PE, EC, CL, MX y US a CTS Perú y CO a CTS
Colombia, y España quedaba fuera. Con `DEC-156` eso deja de bloquear a los
creadores: un creador español cobra de CTS Perú si la campaña es de CTS Perú.
Lo que España sigue sin tener es **cobertura para facturar a un CLIENTE
español** —eso es `Q-15` y sigue abierto—, que es otra pregunta.

### La forma exacta de cada dato

| Columna | Cómo se guarda |
|---|---|
| Tasa | `DECIMAL(7,4)`, entre 0 y 100. Un 30 % es `30.0000` |
| Norma | texto de hasta 160 caracteres, p. ej. «LIR art. 54 inc. …» |
| Convenio | si lo hay y modifica la tasa, va en la norma |

### Y una regla, además de los números

**¿La tasa depende sólo del país, o también del tipo de renta?** Eso decide si
el sistema guarda una tasa por país o una por perfil fiscal del creador. Hoy la
guarda por perfil, que es lo más flexible; si basta con el país, se puede poner
un valor por defecto y ahorrar la decisión en cada alta.

### Por qué no se puede avanzar sin esto

El sistema **ya impide** aprobar un perfil fiscal de un creador no domiciliado
sin decidir la retención. Antes no lo impedía, y era peor: «no se retiene» y
«nadie lo ha mirado todavía» eran el mismo valor por defecto, así que un olvido
producía un pago sin retención idéntico a una decisión (`DEC-048`).

Cada retención que se practique congela su tasa y su norma en el asiento
contable, y no se pueden cambiar después. Si la tasa cambia mañana, las
retenciones de ayer siguen explicándose con la tasa de ayer.

**Cuándo hace falta:** antes del primer pago a un creador no domiciliado.

---

## 3. `Q-44` · IGV en facturas al exterior — **para el contador**

### La pregunta

**¿Los servicios de marketing con creadores de contenido, facturados a un cliente
no domiciliado, califican como exportación de servicios —y por tanto sin IGV— o
van gravados al 18 %?**

### Por qué se pregunta y no se asume

Depende de las condiciones concurrentes del **artículo 33-A de la Ley del IGV** y
de la lista de servicios que le resulte aplicable. Al buscarlo encontré
**fuentes que se contradicen sobre qué versión de esa lista está vigente**, y
poner un 0 % o un 18 % por mi cuenta sería inventar una posición tributaria.

### Lo que el sistema necesita de vuelta

**Esta tabla, rellena.** Una fila por país del **cliente** — el que recibe la
factura, no el creador.

| País del cliente | ¿IGV? | Norma que lo sustenta | Qué hay que poder demostrar |
|---|---|---|---|
| 🇨🇴 Colombia | | | |
| 🇪🇨 Ecuador | | | |
| 🇨🇱 Chile | | | |
| 🇲🇽 México | | | |
| 🇺🇸 Estados Unidos | | | |
| 🇪🇸 España | | | |
| Cualquier otro | | | |

En la columna **«¿IGV?»**, una de estas tres: `0 % — exportación de servicios`,
`18 % — gravado`, o `depende` (y entonces de qué).

La columna **«qué hay que poder demostrar»** es la que más importa para el
sistema. Hoy se guarda el país del cliente, su identidad fiscal y la sociedad
que emite. Si hace falta algo más —un contrato firmado, una constancia, el lugar
de uso o explotación del servicio, la condición de no domiciliado acreditada—,
hay que saberlo **antes** de emitir, no después: una factura emitida no se
corrige, se anula y se vuelve a emitir.

### Esta pregunta es sólo de CTS Perú

El IGV es peruano, y `Q-15` dice que **CTS Perú factura a PE, EC, CL, MX y US**.
Lo que facture **CTS Colombia** tiene su propia pregunta equivalente —el IVA
colombiano— y la contesta un contador colombiano. No está en esta tabla porque
no la puede contestar el mismo profesional.

### Estado en el sistema

El modelo **admite las cuatro opciones y no fuerza ninguna**. No hay nada roto:
hay una decisión sin tomar, y la primera factura al exterior la necesita.

**Cuándo hace falta:** antes de la primera factura a un cliente no domiciliado.

---

## 4. `DEC-085` · Los dos usuarios de base de datos — **para ti, en el servidor**

Esto no es una consulta: son dos órdenes SQL. Está aquí porque es la tercera
cosa que bloquea y no se arregla escribiendo código.

### Qué pasa mientras no estén

La aplicación se conecta con un usuario que **puede cambiar el esquema**. Eso
significa que puede ejecutar `TRUNCATE audit_logs` — y la bitácora es
append-only precisamente porque nadie debe poder vaciarla. Mientras el `GRANT`
no esté, esa garantía es una intención.

### Las dos órdenes

```sql
-- El de APLICACIÓN: no puede cambiar el esquema.
CREATE USER 'latam_app'@'%' IDENTIFIED BY '<contraseña>';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON `latam_social`.* TO 'latam_app'@'%';

-- El de MIGRACIONES: el único que cambia el esquema.
CREATE USER 'latam_mig'@'%' IDENTIFIED BY '<otra contraseña>';
GRANT ALL PRIVILEGES ON `latam_social`.* TO 'latam_mig'@'%';

FLUSH PRIVILEGES;
```

`.env` de la aplicación usa `latam_app`. `latam_mig` se usa **sólo** al ejecutar
`php artisan migrate`, y su contraseña no vive en el `.env` de producción.

### Y comprobarlo, no darlo por hecho

```bash
php artisan seguridad:privilegios --exigir
```

Termina con error si el usuario de la aplicación todavía puede vaciar la
bitácora. Los pasos completos están en `docs/18-RUNBOOK-DESPLIEGUE.md` §3.1.

---

## 5. Cuando lleguen las respuestas

- **`T-09`**: publica el texto con el comando de §1. No hace falta que me lo
  mandes; el sistema lo lee de la tabla.
- **`Q-40` y `Q-44`**: pásame la respuesta y la registro en el `DECISION LOG`
  con su norma, la anoto en `docs/16-RESPUESTAS-NEGOCIO.md` y la codifico donde
  toque. **No las implemento sin la norma citada**: `§56` del prompt maestro dice
  que no se implementan supuestos legales sin identificarlos.
- **`DEC-085`**: ejecútalas en el servidor y corre la comprobación.
