
<?php
require_once "../config/conexion.php";

if (!isset($_GET["id"]) || !ctype_digit($_GET["id"])) {
    die("Cliente no válido.");
}

$id = (int) $_GET["id"];

$consulta = $conexion->prepare(
    "SELECT * FROM clientes WHERE id = :id"
);

$consulta->execute([":id" => $id]);

$cliente = $consulta->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    die("El cliente no existe.");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar cliente</title>
    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">
</head>
<body>

<div class="layout">

    <aside class="menu-lateral">
        <h2>SISTEMA CONTABLE</h2>
        <nav>
            <a href="../index.php">Inicio</a>
            <a href="clientes.php">Clientes</a>
            <a href="proveedores.php">Proveedores</a>
            <a href="productos.php">Productos</a>
            <a href="ventas.php">Ventas</a>
            <a href="compras.php">Compras</a>
            <a href="contabilidad.php">Contabilidad</a>
            <a href="reportes.php">Reportes</a>
        </nav>
    </aside>

    <main class="contenido">
        <header class="encabezado">
            <h1>Editar cliente</h1>
            <p>Modificar información del cliente</p>
        </header>

        <section class="tarjeta">
            <h2>Datos del cliente</h2>

            <form method="POST" action="actualizar_cliente.php">

                <input type="hidden" name="id"
                       value="<?= htmlspecialchars($cliente['id']) ?>">

                <label>Nombre completo *</label>
                <input type="text" name="nombre" maxlength="150"
                       value="<?= htmlspecialchars($cliente['nombre']) ?>" required>

                <label>DUI</label>
                <input type="text" name="dui" maxlength="10"
                       value="<?= htmlspecialchars($cliente['dui'] ?? '') ?>">

                <label>NIT</label>
                <input type="text" name="nit" maxlength="17"
                       value="<?= htmlspecialchars($cliente['nit'] ?? '') ?>">

                <label>Teléfono</label>
                <input type="text" name="telefono" maxlength="20"
                       value="<?= htmlspecialchars($cliente['telefono'] ?? '') ?>">

                <label>Correo electrónico</label>
                <input type="email" name="correo" maxlength="150"
                       value="<?= htmlspecialchars($cliente['correo'] ?? '') ?>">

                <label>Dirección</label>
                <textarea name="direccion" rows="3"><?= htmlspecialchars($cliente['direccion'] ?? '') ?></textarea>

                <button type="submit">Actualizar cliente</button>

                <a href="clientes.php">Cancelar</a>

            </form>
        </section>
    </main>

</div>

</body>
</html>
