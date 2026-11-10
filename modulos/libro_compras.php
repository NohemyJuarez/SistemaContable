<?php
require_once "../config/conexion.php";

$desde = trim($_GET["desde"] ?? "");
$hasta = trim($_GET["hasta"] ?? "");
$mensaje = "";
$compras = [];

function fechaValida(string $fecha): bool
{
    $d = DateTime::createFromFormat("Y-m-d", $fecha);
    return $d && $d->format("Y-m-d") === $fecha;
}

if ($desde !== "" && !fechaValida($desde)) {
    $mensaje = "La fecha inicial no es válida.";
} elseif ($hasta !== "" && !fechaValida($hasta)) {
    $mensaje = "La fecha final no es válida.";
} elseif ($desde !== "" && $hasta !== "" && $desde > $hasta) {
    $mensaje = "La fecha inicial no puede ser mayor que la fecha final.";
}

if ($mensaje === "") {

    // fecha_compra es timestamp: se compara solo la parte de la fecha
    // para que el día "hasta" quede incluido completo.
    $sql = "SELECT c.id, c.numero_compra, c.fecha_compra,
                   p.nombre AS proveedor,
                   c.subtotal, c.impuesto, c.total
            FROM compras c
            INNER JOIN proveedores p ON p.id = c.proveedor_id
            WHERE 1 = 1";

    $parametros = [];

    if ($desde !== "") {
        $sql .= " AND CAST(c.fecha_compra AS date) >= CAST(:desde AS date)";
        $parametros[":desde"] = $desde;
    }

    if ($hasta !== "") {
        $sql .= " AND CAST(c.fecha_compra AS date) <= CAST(:hasta AS date)";
        $parametros[":hasta"] = $hasta;
    }

    $sql .= " ORDER BY c.fecha_compra ASC, c.id ASC";

    try {
        $consulta = $conexion->prepare($sql);
        $consulta->execute($parametros);
        $compras = $consulta->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $mensaje = "No se pudo consultar el libro de compras.";
    }
}

$totalSubtotal = array_sum(array_column($compras, "subtotal"));
$totalIva      = array_sum(array_column($compras, "impuesto"));
$totalGeneral  = array_sum(array_column($compras, "total"));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Libro de Compras - Sistema Contable</title>
    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">
</head>
<body>
    <div class="layout">

        <aside class="menu-lateral">
            <h2>SISTEMA CONTABLE</h2>

            <button type="button" id="btn-menu"
                aria-label="Contraer menú"
                aria-expanded="true">
                ☰
            </button>

            <nav>
                <a href="../index.php">Inicio</a>
                <a href="clientes.php">Clientes</a>
                <a href="proveedores.php">Proveedores</a>
                <a href="productos.php">Productos</a>
                <a href="ventas.php">Ventas</a>
                <a href="compras.php">Compras</a>
                <a href="libro_compras.php" class="activo">Libro de compras</a>
                <a href="contabilidad.php">Contabilidad</a>
                <a href="reportes.php">Reportes</a>
            </nav>
        </aside>

        <main class="contenido">
            <header class="encabezado">
                <h1>Libro de Compras</h1>
                <p>Consulta de compras registradas por período</p>
            </header>

            <section class="tarjeta">
                <h2>Filtrar por fechas</h2>

                <?php if (!empty($mensaje)): ?>
                    <div class="mensaje">
                        <?= htmlspecialchars($mensaje) ?>
                    </div>
                <?php endif; ?>

                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: end; margin-bottom: 10px;">

                    <div>
                        <label>Desde</label>
                        <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">
                    </div>

                    <div>
                        <label>Hasta</label>
                        <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
                    </div>

                    <button type="submit">Filtrar</button>
                    <a href="libro_compras.php" class="boton">Limpiar</a>

                </form>
            </section>

            <section class="tarjeta" style="margin-top: 25px;">
                <h2>Compras registradas</h2>

                <?php if (count($compras) > 0): ?>

                    <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>N.º de compra</th>
                                <th>Proveedor</th>
                                <th>Subtotal</th>
                                <th>Impuesto (IVA)</th>
                                <th>Total</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($compras as $compra): ?>
                                <tr>
                                    <td><?= date("d/m/Y", strtotime($compra["fecha_compra"])) ?></td>
                                    <td><?= htmlspecialchars($compra["numero_compra"]) ?></td>
                                    <td><?= htmlspecialchars($compra["proveedor"]) ?></td>
                                    <td>$<?= number_format((float)$compra["subtotal"], 2) ?></td>
                                    <td>$<?= number_format((float)$compra["impuesto"], 2) ?></td>
                                    <td>$<?= number_format((float)$compra["total"], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                        <tfoot>
                            <tr style="font-weight: bold;">
                                <td colspan="3">Totales del período (<?= count($compras) ?> compras)</td>
                                <td>$<?= number_format($totalSubtotal, 2) ?></td>
                                <td>$<?= number_format($totalIva, 2) ?></td>
                                <td>$<?= number_format($totalGeneral, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>

                <?php else: ?>

                    <p>No hay compras registradas para el período seleccionado.</p>

                <?php endif; ?>
            </section>

        </main>

    </div>

    <script src="../assets/js/menu.js"></script>

</body>
</html>