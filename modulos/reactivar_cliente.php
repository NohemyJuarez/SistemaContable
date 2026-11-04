
<?php
require_once "../config/conexion.php";

if (!isset($_GET["id"]) || !ctype_digit($_GET["id"])) {
    die("Cliente no válido.");
}

$id = (int) $_GET["id"];

try {
    $sql = "UPDATE clientes
            SET estado = 'Activo'
            WHERE id = :id AND estado = 'Inactivo'";

    $consulta = $conexion->prepare($sql);
    $consulta->execute([":id" => $id]);

    header("Location: clientes.php");
    exit;

} catch (PDOException $e) {
    die("No se pudo reactivar el cliente.");
}
