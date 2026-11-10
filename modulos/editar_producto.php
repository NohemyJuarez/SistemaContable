
<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";
requerirEdicion("productos");

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$id) die("Producto no válido.");

$consulta = $conexion->prepare("SELECT * FROM productos WHERE id = :id");
$consulta->execute([":id" => $id]);
$producto = $consulta->fetch(PDO::FETCH_ASSOC);

if (!$producto) die("Producto no encontrado.");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar producto</title>
    <link rel="stylesheet" href="../assets/css/estilos.css">
</head>
<body>
<main class="contenido">
    <section class="tarjeta">
        <h1>Editar producto</h1>

        <form action="actualizar_producto.php" method="POST">
            <input type="hidden" name="id" value="<?= $producto["id"] ?>">

            <label>Código *</label>
            <input type="text" name="codigo" maxlength="50"
                   value="<?= htmlspecialchars($producto["codigo"]) ?>" required>

            <label>Nombre *</label>
            <input type="text" name="nombre" maxlength="150"
                   value="<?= htmlspecialchars($producto["nombre"]) ?>" required>

            <label>Descripción</label>
            <textarea name="descripcion" rows="2"><?= htmlspecialchars($producto["descripcion"] ?? "") ?></textarea>

            <label>Tipo *</label>
            <select name="tipo" required>
                <option value="Producto" <?= $producto["tipo"] === "Producto" ? "selected" : "" ?>>Producto</option>
                <option value="Servicio" <?= $producto["tipo"] === "Servicio" ? "selected" : "" ?>>Servicio</option>
            </select>

            <label>Precio de venta ($) *</label>
            <input type="number" name="precio_venta" min="0" step="0.01"
                   value="<?= htmlspecialchars($producto["precio_venta"]) ?>" required>

            <label>Costo ($) *</label>
            <input type="number" name="costo" min="0" step="0.01"
                   value="<?= htmlspecialchars($producto["costo"]) ?>" required>

            <label>Existencia *</label>
            <input type="number" name="existencia" min="0" step="1"
                   value="<?= htmlspecialchars($producto["existencia"]) ?>" required>

            <button type="submit">Guardar cambios</button>
            <a href="productos.php" class="boton">Cancelar</a>
        </form>
    </section>
</main>
</body>
</html>
