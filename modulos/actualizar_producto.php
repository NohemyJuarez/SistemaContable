
<?php
require_once "../config/conexion.php";

$id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
$codigo = trim($_POST["codigo"] ?? "");
$nombre = trim($_POST["nombre"] ?? "");
$descripcion = trim($_POST["descripcion"] ?? "") ?: null;
$tipo = $_POST["tipo"] ?? "";
$precio = $_POST["precio_venta"] ?? "";
$costo = $_POST["costo"] ?? "";
$existencia = $_POST["existencia"] ?? "";

if (!$id || $codigo === "" || $nombre === "" ||
    !in_array($tipo, ["Producto", "Servicio"], true) ||
    !is_numeric($precio) || !is_numeric($costo) ||
    $precio < 0 || $costo < 0 ||
    filter_var($existencia, FILTER_VALIDATE_INT) === false ||
    $existencia < 0) {
    die("Verifica los datos ingresados.");
}

try {
    $sql = "UPDATE productos SET
            codigo = :codigo,
            nombre = :nombre,
            descripcion = :descripcion,
            tipo = :tipo,
            precio_venta = :precio,
            costo = :costo,
            existencia = :existencia
            WHERE id = :id";

    $consulta = $conexion->prepare($sql);
    $consulta->execute([
        ":codigo" => $codigo,
        ":nombre" => $nombre,
        ":descripcion" => $descripcion,
        ":tipo" => $tipo,
        ":precio" => $precio,
        ":costo" => $costo,
        ":existencia" => $existencia,
        ":id" => $id
    ]);

    header("Location: productos.php?actualizado=1");
    exit;

} catch (PDOException $e) {
    if ($e->getCode() === "23505") {
        die("Error: el código del producto ya está registrado.");
    }
    die("No se pudo actualizar el producto.");
}
