<?php
/*
 * Autenticación, roles y permisos del Sistema Contable.
 * Se incluye al inicio de cada página (antes de cualquier HTML).
 */

/*
 * Guarda las sesiones en la base de datos (tabla "sesiones", se crea sola).
 * Se activa solo en el servidor, con la variable de entorno SESIONES_EN_BD=1,
 * porque allí el disco es temporal y las sesiones en archivos se perderían
 * cada vez que el servicio se apaga o se reinicia.
 * En XAMPP (local) no se activa y las sesiones funcionan como siempre.
 */
class SesionesEnBD implements SessionHandlerInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function open(string $path, string $name): bool
    {
        try {
            $existe = $this->pdo->query("SELECT to_regclass('sesiones')")->fetchColumn();

            if ($existe === null || $existe === false) {
                $this->pdo->exec(
                    "CREATE TABLE IF NOT EXISTS sesiones (
                        id VARCHAR(128) PRIMARY KEY,
                        datos TEXT NOT NULL,
                        actualizado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )"
                );
            }
        } catch (PDOException $e) {
            error_log("Sesiones: no se pudo preparar la tabla: " . $e->getMessage());
        }

        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $consulta = $this->pdo->prepare("SELECT datos FROM sesiones WHERE id = :id");
            $consulta->execute([":id" => $id]);
            $datos = $consulta->fetchColumn();

            return $datos === false ? "" : $datos;
        } catch (PDOException $e) {
            error_log("Sesiones: error al leer: " . $e->getMessage());
            return "";
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $consulta = $this->pdo->prepare(
                "INSERT INTO sesiones (id, datos, actualizado)
                 VALUES (:id, :datos, CURRENT_TIMESTAMP)
                 ON CONFLICT (id) DO UPDATE
                 SET datos = EXCLUDED.datos, actualizado = CURRENT_TIMESTAMP"
            );

            return $consulta->execute([":id" => $id, ":datos" => $data]);
        } catch (PDOException $e) {
            error_log("Sesiones: error al guardar: " . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->pdo->prepare("DELETE FROM sesiones WHERE id = :id")->execute([":id" => $id]);
            return true;
        } catch (PDOException $e) {
            error_log("Sesiones: error al eliminar: " . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $consulta = $this->pdo->prepare(
                "DELETE FROM sesiones
                 WHERE actualizado < CURRENT_TIMESTAMP - make_interval(secs => :segundos)"
            );
            $consulta->execute([":segundos" => $max_lifetime]);

            return $consulta->rowCount();
        } catch (PDOException $e) {
            error_log("Sesiones: error en la limpieza: " . $e->getMessage());
            return false;
        }
    }
}

if (session_status() === PHP_SESSION_NONE) {

    if (getenv("SESIONES_EN_BD") === "1") {
        require_once __DIR__ . "/../config/conexion.php";

        session_set_save_handler(new SesionesEnBD($conexion), true);
        ini_set("session.gc_maxlifetime", "7200"); // las sesiones duran 2 horas sin actividad
    }

    // Cookie segura cuando la página se sirve por HTTPS (también detrás del proxy de Render)
    $esHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
        || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");

    session_set_cookie_params([
        "httponly" => true,
        "samesite" => "Lax",
        "secure"   => $esHttps,
    ]);
    session_start();
}

const ROLES = [
    "Administrador",
    "Contador",
    "Vendedor",
    "Encargado de inventario",
];

/*
 * MATRIZ DE PERMISOS (único lugar donde se definen)
 *   "R" = puede ver y registrar/editar
 *   "V" = solo puede consultar
 *   (sin entrada) = no tiene acceso
 */
const PERMISOS = [
    "clientes" => [
        "Administrador" => "R",
        "Contador"      => "V",
        "Vendedor"      => "V",
    ],
    "proveedores" => [
        "Administrador" => "R",
        "Contador"      => "V",
    ],
    "productos" => [
        "Administrador"           => "R",
        "Vendedor"                => "V",
        "Encargado de inventario" => "R",
    ],
    "ventas" => [
        "Administrador" => "R",
        "Contador"      => "V",
        "Vendedor"      => "R",
    ],
    "compras" => [
        "Administrador"           => "R",
        "Contador"                => "V",
        "Encargado de inventario" => "R",
    ],
    
    "catalogo_cuentas" => [
        "Administrador" => "R",
        "Contador"      => "R",
    ],
    "libro_compras" => [
    "Administrador"           => "V",
    "Contador"                => "V",
    "Encargado de inventario" => "V",
    ],
    "libro_ventas" => [
    "Administrador" => "V",
    "Contador"      => "V",
    ],
    "libro_diario" => [
    "Administrador" => "R",
    "Contador"      => "R",
    ],

    "libro_mayor" => [
    "Administrador" => "V",
    "Contador"      => "V",
    ],
    "contabilidad" => [
        "Administrador" => "R",
        "Contador"      => "R",
    ],
    "reportes" => [
        "Administrador" => "V",
        "Contador"      => "V",
    ],
    "usuarios" => [
        "Administrador" => "R",
    ],
];

/* Devuelve "../" si la página actual está dentro de /modulos, o "" si está en la raíz. */
function rutaRaiz(): string
{
    return basename(dirname($_SERVER["SCRIPT_NAME"])) === "modulos" ? "../" : "";
}

function usuarioActual(): ?array
{
    return $_SESSION["usuario"] ?? null;
}

function requerirLogin(): void
{
    if (usuarioActual() === null) {
        header("Location: " . rutaRaiz() . "login.php");
        exit;
    }
}

/* Nivel de acceso del usuario actual a un módulo: "R", "V" o null. */
function nivelAcceso(string $modulo): ?string
{
    $usuario = usuarioActual();

    if ($usuario === null) {
        return null;
    }

    return PERMISOS[$modulo][$usuario["rol"]] ?? null;
}

function puedeEditar(string $modulo): bool
{
    return nivelAcceso($modulo) === "R";
}

function denegar(): void
{
    http_response_code(403);
    $raiz = rutaRaiz();

    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>Acceso denegado</title>'
       . '<link rel="stylesheet" href="' . $raiz . 'assets/css/estilos.css"></head>'
       . '<body style="display:flex;justify-content:center;align-items:center;min-height:100vh;">'
       . '<section class="tarjeta" style="max-width:480px;text-align:center;">'
       . '<h2>Acceso denegado</h2>'
       . '<p>Tu rol no tiene permiso para esta sección.</p>'
       . '<a href="' . $raiz . 'index.php" class="boton">Volver al inicio</a>'
       . '</section></body></html>';
    exit;
}

/* Exige sesión iniciada y al menos permiso de consulta sobre el módulo. */
function requerirAcceso(string $modulo): void
{
    requerirLogin();

    if (nivelAcceso($modulo) === null) {
        denegar();
    }
}

/* Exige sesión iniciada y permiso de registrar/editar sobre el módulo. */
function requerirEdicion(string $modulo): void
{
    requerirLogin();

    if (!puedeEditar($modulo)) {
        denegar();
    }
}

/* Protección CSRF para formularios que modifican datos importantes. */
function tokenCsrf(): string
{
    if (empty($_SESSION["csrf"])) {
        $_SESSION["csrf"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf"];
}

function verificarCsrf(): void
{
    $enviado = $_POST["csrf"] ?? "";

    if (!hash_equals($_SESSION["csrf"] ?? "", $enviado)) {
        http_response_code(400);
        exit("Solicitud no válida. Recarga la página e inténtalo de nuevo.");
    }
}