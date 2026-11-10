
<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";
requerirEdicion("productos");

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$accion = $_GET["accion"] ?? "";

if (!$id || !in_array($accion, ["desactivar", "reactivar"], true)) {
    die("Solicitud no válida.");
}

$estado = $accion === "desactivar" ? "Inactivo" : "Activo";

$sql = "UPDATE productos SET estado = :estado WHERE id = :id";
$consulta = $conexion->prepare($sql);
$consulta->execute([
    ":estado" => $estado,
    ":id" => $id
]);

header("Location: productos.php");
exit;
