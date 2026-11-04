
<?php
require_once "../config/conexion.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: clientes.php");
    exit;
}

$id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
$nombre = trim($_POST["nombre"] ?? "");
$dui = trim($_POST["dui"] ?? "") ?: null;
$nit = trim($_POST["nit"] ?? "") ?: null;
$telefono = trim($_POST["telefono"] ?? "") ?: null;
$correo = trim($_POST["correo"] ?? "") ?: null;
$direccion = trim($_POST["direccion"] ?? "") ?: null;

if (!$id || $nombre === "") {
    die("Datos del cliente no válidos.");
}

try {
    $sql = "UPDATE clientes
            SET nombre = :nombre,
                dui = :dui,
                nit = :nit,
                telefono = :telefono,
                correo = :correo,
                direccion = :direccion
            WHERE id = :id";

    $consulta = $conexion->prepare($sql);

    $consulta->execute([
        ":nombre" => $nombre,
        ":dui" => $dui,
        ":nit" => $nit,
        ":telefono" => $telefono,
        ":correo" => $correo,
        ":direccion" => $direccion,
        ":id" => $id
    ]);

    header("Location: clientes.php");
    exit;

} catch (PDOException $e) {
    if ($e->getCode() === "23505") {
        die("Error: el DUI o NIT ingresado ya pertenece a otro cliente.");
    }

    die("No se pudo actualizar el cliente. Verifica los datos.");
}
