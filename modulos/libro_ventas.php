
<?php
require_once __DIR__ . "/../config/conexion.php";

$desde = trim($_GET["desde"] ?? "");
$hasta = trim($_GET["hasta"] ?? "");

$ventas = [];
$totales = [
    "subtotal" => 0,
    "impuesto" => 0,
    "total" => 0,
    "cantidad" => 0,
    "anuladas" => 0
];

$error = "";

function escapar($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

function fechaValida(string $fecha): bool {
    $formato = DateTime::createFromFormat("!Y-m-d", $fecha);

    return $formato !== false &&
           $formato->format("Y-m-d") === $fecha;
}

try {

    // Validar fechas recibidas
    if ($desde !== "" && !fechaValida($desde)) {
        throw new Exception("La fecha inicial no es válida.");
    }

    if ($hasta !== "" && !fechaValida($hasta)) {
        throw new Exception("La fecha final no es válida.");
    }

    if ($desde !== "" && $hasta !== "" && $desde > $hasta) {
        throw new Exception(
            "La fecha inicial no puede ser posterior a la fecha final."
        );
    }

    // Condiciones del período
    $condiciones = [];
    $parametros = [];

    if ($desde !== "") {
        $condiciones[] = "v.fecha_venta >= CAST(:desde AS DATE)";
        $parametros[":desde"] = $desde;
    }

    if ($hasta !== "") {
        // Incluye todas las ventas del día final, aunque tengan hora.
        $condiciones[] = "v.fecha_venta < CAST(:hasta AS DATE) + INTERVAL '1 day'";
        $parametros[":hasta"] = $hasta;
    }

    $where = count($condiciones) > 0
        ? "WHERE " . implode(" AND ", $condiciones)
        : "";

    // Consultar ventas sin unir los detalles para evitar duplicados.
    $sqlVentas = "
        SELECT
            v.id,
            v.numero_venta,
            v.fecha_venta,
            c.nombre AS cliente,
            v.subtotal,
            v.impuesto,
            v.total,
            v.estado
        FROM ventas v
        INNER JOIN clientes c ON c.id = v.cliente_id
        $where
        ORDER BY v.fecha_venta DESC, v.id DESC
    ";

    $consulta = $conexion->prepare($sqlVentas);
    $consulta->execute($parametros);

    $ventas = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // Calcular totales del período.
    // Las ventas anuladas se muestran, pero no suman.
    $sqlTotales = "
        SELECT
            COUNT(*) FILTER (
                WHERE estado <> 'Anulada'
            ) AS cantidad,

            COUNT(*) FILTER (
                WHERE estado = 'Anulada'
            ) AS anuladas,

            COALESCE(
                SUM(subtotal) FILTER (
                    WHERE estado <> 'Anulada'
                ), 0
            ) AS subtotal,

            COALESCE(
                SUM(impuesto) FILTER (
                    WHERE estado <> 'Anulada'
                ), 0
            ) AS impuesto,

            COALESCE(
                SUM(total) FILTER (
                    WHERE estado <> 'Anulada'
                ), 0
            ) AS total

        FROM ventas v
        $where
    ";

    $consultaTotales = $conexion->prepare($sqlTotales);
    $consultaTotales->execute($parametros);

    $resultadoTotales = $consultaTotales->fetch(PDO::FETCH_ASSOC);

    $totales = [
        "cantidad" => (int)$resultadoTotales["cantidad"],
        "anuladas" => (int)$resultadoTotales["anuladas"],
        "subtotal" => (float)$resultadoTotales["subtotal"],
        "impuesto" => (float)$resultadoTotales["impuesto"],
        "total" => (float)$resultadoTotales["total"]
    ];

} catch (Throwable $e) {
    $error = $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Libro de ventas - Sistema Contable</title>

    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">

    <style>
        .filtros-ventas {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 20px;
        }

        .filtros-ventas div {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filtros-ventas input {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        .filtros-ventas button,
        .filtros-ventas a {
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
        }

        .filtros-ventas button {
            background: #7136a5;
            color: white;
        }

        .filtros-ventas a {
            background: #eee;
            color: #333;
        }

        .resumen-ventas {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .resumen-ventas .dato {
            background: #f7f3fb;
            border-left: 4px solid #7136a5;
            padding: 16px;
            border-radius: 8px;
        }

        .resumen-ventas .dato span {
            display: block;
            color: #666;
            margin-bottom: 8px;
        }

        .resumen-ventas .dato strong {
            font-size: 21px;
            color: #43205f;
        }

        .tabla-contenedor {
            overflow-x: auto;
        }

        .tabla-libro {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .tabla-libro th,
        .tabla-libro td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            text-align: left;
            white-space: nowrap;
        }

        .tabla-libro th {
            background: #7136a5;
            color: white;
        }

        .estado-venta {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .estado-pagada {
            background: #e2f7e8;
            color: #176b32;
        }

        .estado-pendiente {
            background: #fff2d6;
            color: #805b0b;
        }

        .estado-anulada {
            background: #ffe6e6;
            color: #a32323;
        }

        .fila-anulada {
            background: #fffafa;
            color: #888;
        }

        .mensaje-error {
            padding: 12px;
            background: #ffe8e8;
            color: #a32323;
            border-radius: 8px;
            margin: 15px 0;
        }

        .sin-registros {
            text-align: center;
            padding: 25px;
            color: #777;
        }
    </style>
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
   <a href="catalogo_cuentas.php">Catálogo de cuentas</a>
    <a href="libro_ventas.php"class="activo" >Libro de ventas</a>
    <a href="libro_compras.php">Libro de compras</a>
    <a href="contabilidad.php">Contabilidad</a>
    <a href="reportes.php">Reportes</a>
</nav>
    </aside>

    <main class="contenido">

        <header class="encabezado">
            <h1>Libro de ventas</h1>
            <p>
                Consulta y resumen de las ventas registradas en el sistema.
            </p>
        </header>

        <?php if ($error !== ""): ?>
            <div class="mensaje-error">
                <?= escapar($error) ?>
            </div>
        <?php endif; ?>

        <section class="tarjeta">

            <h2>Filtrar ventas por período</h2>

            <form method="GET" class="filtros-ventas">

                <div>
                    <label for="desde">Fecha inicial</label>
                    <input
                        type="date"
                        name="desde"
                        id="desde"
                        value="<?= escapar($desde) ?>"
                    >
                </div>

                <div>
                    <label for="hasta">Fecha final</label>
                    <input
                        type="date"
                        name="hasta"
                        id="hasta"
                        value="<?= escapar($hasta) ?>"
                    >
                </div>

                <button type="submit">Consultar</button>

                <a href="libro_ventas.php">Limpiar filtros</a>

            </form>

        </section>

        <section class="resumen-ventas">

            <div class="dato">
                <span>Ventas válidas</span>
                <strong><?= $totales["cantidad"] ?></strong>
            </div>

            <div class="dato">
                <span>Subtotal del período</span>
                <strong>
                    $<?= number_format($totales["subtotal"], 2) ?>
                </strong>
            </div>

            <div class="dato">
                <span>Impuestos del período</span>
                <strong>
                    $<?= number_format($totales["impuesto"], 2) ?>
                </strong>
            </div>

            <div class="dato">
                <span>Total del período</span>
                <strong>
                    $<?= number_format($totales["total"], 2) ?>
                </strong>
            </div>

            <div class="dato">
                <span>Ventas anuladas</span>
                <strong><?= $totales["anuladas"] ?></strong>
            </div>

        </section>

        <section class="tarjeta">

            <h2>Detalle de ventas</h2>

            <div class="tabla-contenedor">

                <table class="tabla-libro">

                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Número de venta</th>
                            <th>Cliente</th>
                            <th>Subtotal</th>
                            <th>Impuesto</th>
                            <th>Total</th>
                            <th>Estado</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($ventas) > 0): ?>

                        <?php foreach ($ventas as $venta): ?>

                            <?php
                                $claseEstado = "estado-pendiente";

                                if ($venta["estado"] === "Pagada") {
                                    $claseEstado = "estado-pagada";
                                } elseif ($venta["estado"] === "Anulada") {
                                    $claseEstado = "estado-anulada";
                                }
                            ?>

                            <tr class="<?= $venta["estado"] === "Anulada" ? "fila-anulada" : "" ?>">

                                <td>
                                    <?= date(
                                        "d/m/Y H:i",
                                        strtotime($venta["fecha_venta"])
                                    ) ?>
                                </td>

                                <td>
                                    <?= escapar($venta["numero_venta"]) ?>
                                </td>

                                <td>
                                    <?= escapar($venta["cliente"]) ?>
                                </td>

                                <td>
                                    $<?= number_format((float)$venta["subtotal"], 2) ?>
                                </td>

                                <td>
                                    $<?= number_format((float)$venta["impuesto"], 2) ?>
                                </td>

                                <td>
                                    <strong>
                                        $<?= number_format((float)$venta["total"], 2) ?>
                                    </strong>
                                </td>

                                <td>
                                    <span class="estado-venta <?= $claseEstado ?>">
                                        <?= escapar($venta["estado"]) ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7" class="sin-registros">
                                No se encontraron ventas para el período seleccionado.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <p>
                Se muestran todas las ventas del período. Las ventas anuladas
                se identifican, pero no se incluyen en los totales.
            </p>

        </section>

    </main>

</div>

<script src="../assets/js/menu.js"></script>

</body>
</html>
