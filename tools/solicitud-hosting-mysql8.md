# Solicitud al proveedor de hosting — actualización de MySQL

> Texto listo para pegar en un ticket de soporte de cPanel.
> Sustituye lo que esté entre `<>`.

---

**Asunto:** Consulta sobre disponibilidad de MySQL 8 / Percona Server 8 en la cuenta `<usuario cPanel>`

Buenas tardes,

Estoy desarrollando una aplicación sobre la cuenta `<usuario cPanel>` del servidor `<IP o hostname>`.
Actualmente el servidor de base de datos es:

```
Percona Server 5.7.44-48 (GPL), Release 48
```

La aplicación necesita tres características que MySQL 5.7 no proporciona:

1. **Restricciones `CHECK` aplicadas.** En MySQL 5.7 la cláusula `CHECK` se analiza y se ignora
   silenciosamente; se aplica de verdad a partir de MySQL 8.0.16. La aplicación las usa para garantizar
   la integridad de datos contables y fiscales.
2. **Expresiones de tabla comunes (`WITH ... AS`)**, disponibles desde MySQL 8.0.
3. **Funciones de ventana (`ROW_NUMBER()`, `RANK()`)**, disponibles desde MySQL 8.0.

Además, MySQL 5.7 y Percona Server 5.7 finalizaron su ciclo de soporte en octubre de 2023 y no reciben
parches de seguridad.

Mis preguntas:

- ¿Ofrecen **MySQL 8.0 / 8.4 o Percona Server 8** en algún plan o servidor de la infraestructura?
- Si es así, ¿es posible **migrar esta cuenta** a un servidor con esa versión? ¿Qué implicaría en tiempo
  de inactividad y en coste?
- Si no está disponible, ¿tienen prevista una fecha de actualización?
- ¿Permiten conectar la aplicación a una **base de datos externa** (por ejemplo un servicio gestionado),
  manteniendo el alojamiento web aquí?

Quedo atento. Gracias.

`<nombre>`

---

## Cómo interpretar la respuesta

| Si responden | Entonces |
|---|---|
| "Sí, podemos migrarte a un servidor con MySQL 8" | Se cierra `DEC-042` por la opción A. Es la mejor. Pedir ventana de mantenimiento. |
| "No, pero puedes conectar a una base externa" | Opción B. Valorar coste mensual frente a lo que ahorra. |
| "No, y no permitimos bases externas" | Opción C: contingencia con `TRIGGER`. Y conviene empezar a mirar otro proveedor antes de que el negocio crezca. |

## Mientras tanto, un arreglo de un minuto

El valor por defecto de la base está en `utf8` de 3 bytes. Las tablas que crea Laravel ya son `utf8mb4`,
así que no hay problema hoy, pero cualquier tabla creada fuera de la aplicación heredaría el valor malo:

```sql
ALTER DATABASE cpanduro_latam_social_dev
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Se ejecuta desde phpMyAdmin y no altera ninguna tabla existente.
