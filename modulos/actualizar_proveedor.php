
<?php
require_once "../config/conexion.php";

$id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
$nombre = trim($_POST["nombre"] ?? "");
$nit = trim($_POST["nit"] ?? "") ?: null;
$telefono = trim($_POST["telefono"] ?? "") ?: null;
$correo = trim($_POST["correo"] ?? "") ?: null;
$direccion = trim($_POST["direccion"] ?? "") ?: null;

if (!$id || $nombre === "") {
    die("Verifica los datos obligatorios.");
}

if ($correo !== null && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    die("El correo electrónico no tiene un formato válido.");
}

try {
    $sql = "UPDATE proveedores SET
            nombre = :nombre,
            nit = :nit,
            telefono = :telefono,
            correo = :correo,
            direccion = :direccion
            WHERE id = :id";

    $consulta = $conexion->prepare($sql);
    $consulta->execute([
        ":nombre" => $nombre,
        ":nit" => $nit,
        ":telefono" => $telefono,
        ":correo" => $correo,
        ":direccion" => $direccion,
        ":id" => $id
    ]);

    header("Location: proveedores.php?actualizado=1");
    exit;

} catch (PDOException $e) {
    if ($e->getCode() === "23505") {
        die("Error: el NIT ingresado ya pertenece a otro proveedor.");
    }

    die("No se pudieron actualizar los datos.");
}
