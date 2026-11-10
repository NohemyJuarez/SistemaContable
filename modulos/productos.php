<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";

requerirAcceso("productos");
$puedeEditar = puedeEditar("productos");

$mensaje = "";
$busqueda = trim($_GET["buscar"] ?? "");
$filtroEstado = $_GET["estado"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    requerirEdicion("productos");

    $codigo = trim($_POST["codigo"] ?? "");
    $nombre = trim($_POST["nombre"] ?? "");
    $descripcion = trim($_POST["descripcion"] ?? "");
    $tipo = $_POST["tipo"] ?? "";
    $precio = $_POST["precio_venta"] ?? "";
    $costo = $_POST["costo"] ?? "";
    $existencia = $_POST["existencia"] ?? "";

    if ($codigo === "" || $nombre === "" || $tipo === "") {
        $mensaje = "Completa los campos obligatorios.";
    } elseif (!is_numeric($precio) || !is_numeric($costo) ||
              $precio < 0 || $costo < 0 ||
              filter_var($existencia, FILTER_VALIDATE_INT) === false ||
              $existencia < 0) {
        $mensaje = "Verifica los precios y la existencia ingresada.";
    } else {
        try {
            $sql = "INSERT INTO productos
                    (codigo, nombre, descripcion, tipo, precio_venta, costo, existencia)
                    VALUES
                    (:codigo, :nombre, :descripcion, :tipo, :precio, :costo, :existencia)";

            $consulta = $conexion->prepare($sql);
            $consulta->execute([
                ":codigo" => $codigo,
                ":nombre" => $nombre,
                ":descripcion" => $descripcion ?: null,
                ":tipo" => $tipo,
                ":precio" => $precio,
                ":costo" => $costo,
                ":existencia" => $existencia
            ]);

            header("Location: productos.php?registrado=1");
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() === "23505") {
                $mensaje = "Error: el código del producto ya está registrado.";
            } else {
                $mensaje = "No se pudo registrar el producto.";
            }
        }
    }
}

if (isset($_GET["registrado"])) {
    $mensaje = "¡Producto registrado correctamente!";
}

if (isset($_GET["actualizado"])) {
    $mensaje = "¡Producto actualizado correctamente!";
}

$sql = "SELECT id, codigo, nombre, descripcion, tipo,
               precio_venta, costo, existencia, estado
        FROM productos
        WHERE (
            codigo ILIKE :buscar
            OR nombre ILIKE :buscar
        )";

if (in_array($filtroEstado, ["Activo", "Inactivo"], true)) {
    $sql .= " AND estado = :estado";
}

$sql .= " ORDER BY id DESC";

$consulta = $conexion->prepare($sql);
$parametros = [":buscar" => "%" . $busqueda . "%"];

if (in_array($filtroEstado, ["Activo", "Inactivo"], true)) {
    $parametros[":estado"] = $filtroEstado;
}

$consulta->execute($parametros);
$productos = $consulta->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos - Sistema Contable</title>
    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">
</head>
<body>

<div class="contenedor" style="display:flex; min-height:100vh;">

    <?php require "../includes/menu.php"; ?>

    <main class="contenido">

        <header class="encabezado">
            <h1>Gestión de productos</h1>
            <p>Administra productos, precios y existencias.</p>
        </header>

        <?php if ($mensaje !== ""): ?>
            <div class="mensaje">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET["actualizado"])): ?>
          <div class="mensaje">
              ¡Producto actualizado correctamente!
          </div>
        <?php endif; ?>


        <?php if ($puedeEditar): ?>
        <section class="tarjeta">
            <h2>Registrar nuevo producto</h2>

            <form method="POST">
                <label>Código *</label>
                <input type="text" name="codigo" maxlength="50" required>

                <label>Nombre del producto *</label>
                <input type="text" name="nombre" maxlength="150" required>

                <label>Descripción</label>
                <textarea name="descripcion" rows="2"></textarea>

                <label>Tipo *</label>
                <select name="tipo" required>
                    <option value="">Seleccionar tipo</option>
                    <option value="Producto">Producto</option>
                    <option value="Servicio">Servicio</option>
                </select>

                <label>Precio de venta ($) *</label>
                <input type="number" name="precio_venta" min="0" step="0.01" required>

                <label>Costo ($) *</label>
                <input type="number" name="costo" min="0" step="0.01" required>

                <label>Existencia *</label>
                <input type="number" name="existencia" min="0" step="1" required>

                <button type="submit">Guardar producto</button>
            </form>
        </section>
        <?php endif; ?>

        <section class="tarjeta">
            <h2>Productos registrados</h2>

            <form method="GET">
                <label>Buscar producto</label>
                <input type="text" name="buscar"
                       placeholder="Código o nombre"
                       value="<?= htmlspecialchars($busqueda) ?>">

                <label>Estado</label>
                <select name="estado">
                    <option value="">Todos</option>
                    <option value="Activo" <?= $filtroEstado === "Activo" ? "selected" : "" ?>>Activos</option>
                    <option value="Inactivo" <?= $filtroEstado === "Inactivo" ? "selected" : "" ?>>Inactivos</option>
                </select>

                <button type="submit">Buscar</button>
                <a href="productos.php" class="boton">Limpiar</a>
            </form>

            <div style="overflow-x:auto; margin-top:20px;">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th>Precio</th>
                            <th>Costo</th>
                            <th>Existencia</th>
                            <th>Estado</th>
                            <?php if ($puedeEditar): ?><th>Acciones</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($productos) > 0): ?>
                            <?php foreach ($productos as $producto): ?>
                                <tr>
                                    <td><?= htmlspecialchars($producto["codigo"]) ?></td>
                                    <td><?= htmlspecialchars($producto["nombre"]) ?></td>
                                    <td><?= htmlspecialchars($producto["tipo"]) ?></td>
                                    <td>$<?= number_format($producto["precio_venta"], 2) ?></td>
                                    <td>$<?= number_format($producto["costo"], 2) ?></td>
                                    <td><?= $producto["existencia"] ?></td>
                                    <td><?= htmlspecialchars($producto["estado"]) ?></td>
                                    <?php if ($puedeEditar): ?>
                                    <td>
                                        <a href="editar_producto.php?id=<?= $producto["id"] ?>">Editar</a>
                                        |
                                        <a href="cambiar_estado_producto.php?id=<?= $producto["id"] ?>&accion=<?= $producto["estado"] === "Activo" ? "desactivar" : "reactivar" ?>">
                                            <?= $producto["estado"] === "Activo" ? "Desactivar" : "Reactivar" ?>
                                        </a>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?= $puedeEditar ? 8 : 7 ?>">No se encontraron productos.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

<script src="../assets/js/menu.js"></script>
</body>
</html>