# Sistema Contable

Sistema web de facturación y control de ventas dirigido a micro y pequeñas empresas de El Salvador.


## 1. Tecnologías utilizadas

- PHP 8.2
- PostgreSQL 18
- pgAdmin 4
- HTML5
- CSS3
- JavaScript
- XAMPP
- Visual Studio Code

## 2. Requisitos

se necesita instalar:

- XAMPP con Apache y PHP.
- PostgreSQL.
- pgAdmin 4.
- Git.

## 3. Descargar el proyecto

Clonar el repositorio desde la terminal:

    git clone https://github.com/NohemyJuarez/SistemaContable.git

Colocar la carpeta del proyecto dentro de:

    C:\xampp\htdocs\

## 4. Crear la base de datos

Abrir pgAdmin 4 y crear una base de datos llamada:

    sistema_contable

## 5. Crear las tablas

Abrir Query Tool en la base de datos sistema_contable.

Ejecutar el archivo:

    database/esquema.sql

Este archivo crea las tablas, relaciones y restricciones de la base de datos.

## 6. Insertar datos iniciales

Después de ejecutar el esquema, ejecutar:

```
database/datos_iniciales.sql
```

Este archivo registra el IVA y los métodos de pago iniciales.

## 7. Crear roles y usuarios

En el Query Tool de la base `sistema_contable`, ejecutar (después de `datos_iniciales.sql`):

```
database/roles_y_usuarios.sql
```

Este archivo:

- Habilita la extensión `pgcrypto` para cifrar contraseñas.
- Deja únicamente los 4 roles válidos del sistema (reemplaza la restricción de roles anterior).
- Cifra cualquier contraseña que estuviera en texto plano.
- Crea un usuario de ejemplo por cada rol.

Se puede ejecutar más de una vez sin problema.

Usuarios de ejemplo para iniciar sesión:

| Usuario      | Contraseña        | Rol                     |
|--------------|-------------------|-------------------------|
| `admin`      | `Admin123*`       | Administrador           |
| `contador`   | `Contador123*`    | Contador                |
| `vendedor`   | `Vendedor123*`    | Vendedor                |
| `inventario` | `Inventario123*`  | Encargado de inventario |

> Estas contraseñas son solo para pruebas. El Administrador puede cambiarlas
> o crear usuarios nuevos desde el módulo **Usuarios**.

## 8. Configurar la conexión

Cada integrante debe crear su propio archivo:

```
config/conexion.php
```

La configuración debe utilizar sus propios datos locales de PostgreSQL. Ejemplo:

```php
<?php
$host = "localhost";
$port = "5432";
$dbname = "sistema_contable";
$user = "postgres";
$password = "TU_CONTRASEÑA";

try {
    $conexion = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
```

Este archivo no se sube al repositorio porque contiene datos personales.

**Importante:** PHP debe tener activada la extensión de PostgreSQL. En XAMPP:
Apache → Config → `PHP (php.ini)`, y quitar el `;` del inicio de estas líneas:

```ini
extension=pdo_pgsql
extension=pgsql
```

Guardar y reiniciar Apache.

## 9. Ejecutar el sistema

1. Iniciar Apache desde XAMPP.
2. Verificar que PostgreSQL esté funcionando.
3. Abrir el navegador.
4. Ingresar a:

   http://localhost/SistemaContable/

   El sistema redirige a la pantalla de inicio de sesión. Usar uno de los
   usuarios de ejemplo del paso 7.

## 10. Roles y permisos

Cada rol ve únicamente las opciones que le corresponden en el menú.

- **R**: puede consultar y registrar o modificar.
- **V**: solo puede consultar.
- **—**: no tiene acceso.

| Módulo                  | Administrador | Contador | Vendedor | Encargado de inventario |
|-------------------------|:-------------:|:--------:|:--------:|:-----------------------:|
| Clientes                | R             | V        | V        | —                       |
| Proveedores             | R             | V        | —        | —                       |
| Productos               | R             | —        | V        | R                       |
| Ventas                  | R             | V        | R        | —                       |
| Compras                 | R             | V        | —        | R                       |
| Libro de compras        | V             | V        | —        | V                       |
| Contabilidad            | R             | R        | —        | —                       |
| Catálogo de cuentas     | R             | R        | —        | —                       |
| Reportes                | V             | V        | —        | —                       |
| Usuarios                | R             | —        | —        | —                       |

Los permisos se definen en un solo lugar: `includes/auth.php` (constante `PERMISOS`).

### Agregar un módulo nuevo

1. Al inicio del archivo, después de la conexión:

   ```php
   require_once "../includes/auth.php";
   requerirAcceso("nombre_del_modulo");
   $puedeEditar = puedeEditar("nombre_del_modulo");
   ```

2. Antes de procesar un formulario (`POST`): `requerirEdicion("nombre_del_modulo");`
3. Reemplazar el menú lateral por: `<?php require "../includes/menu.php"; ?>`
4. Agregar el módulo en `PERMISOS` (`includes/auth.php`) y en la lista de opciones de `includes/menu.php`.

## 11. Estructura del proyecto

```
SistemaContable/
|
|-- assets/
|   |-- css/
|   |   |-- catalogo-cuentas.css
|   |-- js/
|   |   |-- catalogo-cuentas.js
|   |-- img/
|
|-- config/
|   |-- conexion.php        (local, no se sube al repositorio)
|
|-- database/
|   |-- esquema.sql
|   |-- datos_iniciales.sql
|   |-- roles_y_usuarios.sql
|
|-- includes/
|   |-- auth.php            (sesión, roles y permisos)
|   |-- menu.php            (menú lateral según el rol)
|
|-- modulos/
|   |-- cuentas_contables.php
|
|-- index.php
|-- login.php
|-- logout.php
|-- README.md
```
