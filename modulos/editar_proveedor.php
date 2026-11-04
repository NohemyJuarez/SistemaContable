
<?php
require_once "../config/conexion.php";

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id) {
    die("Proveedor no válido.");
}

$consulta = $conexion->prepare("SELECT * FROM proveedores WHERE id = :id");
$consulta->execute([":id" => $id]);
$proveedor = $consulta->fetch(PDO::FETCH_ASSOC);

if (!$proveedor) {
    die("Proveedor no encontrado.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar proveedor</title>
    <link rel="stylesheet" href="../assets/css/estilos.css">
</head>
<body>
<main class="contenido">
    <section class="tarjeta">
        <h1>Editar proveedor</h1>

        <form action="actualizar_proveedor.php" method="POST">
            <input type="hidden" name="id" value="<?= $proveedor["id"] ?>">

            <label>Nombre del proveedor *</label>
            <input type="text" name="nombre" maxlength="150"
                   value="<?= htmlspecialchars($proveedor["nombre"]) ?>" required>

            <label>NIT</label>
            <input type="text" name="nit" maxlength="17"
                   value="<?= htmlspecialchars($proveedor["nit"] ?? "") ?>">

            <label>Teléfono</label>
            <input type="text" name="telefono" maxlength="20"
                   value="<?= htmlspecialchars($proveedor["telefono"] ?? "") ?>">

            <label>Correo electrónico</label>
            <input type="email" name="correo" maxlength="150"
                   value="<?= htmlspecialchars($proveedor["correo"] ?? "") ?>">

            <label>Dirección</label>
            <textarea name="direccion" rows="3"><?= htmlspecialchars($proveedor["direccion"] ?? "") ?></textarea>

            <button type="submit">Guardar cambios</button>
            <a href="proveedores.php" class="boton">Cancelar</a>
        </form>
    </section>
</main>
</body>
</html>
