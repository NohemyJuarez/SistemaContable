<?php
/*
 * Autenticación, roles y permisos del Sistema Contable.
 * Se incluye al inicio de cada página (antes de cualquier HTML).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        "httponly" => true,
        "samesite" => "Lax",
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
    "libro_compras" => [
        "Administrador"           => "V",
        "Contador"                => "V",
        "Encargado de inventario" => "V",
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