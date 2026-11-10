
<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";
requerirEdicion("clientes");

if (!isset($_GET["id"]) || !ctype_digit($_GET["id"])) {
    die("Cliente no válido.");
}

$id = (int) $_GET["id"];

try {
    $sql = "UPDATE clientes
            SET estado = 'Inactivo'
            WHERE id = :id AND estado = 'Activo'";

    $consulta = $conexion->prepare($sql);
    $consulta->execute([":id" => $id]);

    header("Location: clientes.php");
    exit;

} catch (PDOException $e) {
    die("No se pudo desactivar el cliente.");
}
