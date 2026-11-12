
<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";

requerirAcceso("reportes");

$tipoReporte = $_GET["tipo"] ?? "ventas";

$reportesPermitidos = [
    "ventas",
    "compras",
    "clientes",
    "productos",
    "inventario",
    "pagos",
    "comprobantes",
    "contabilidad"
];

if (!in_array($tipoReporte, $reportesPermitidos, true)) {
    $tipoReporte = "ventas";
}

$h = static function ($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
};

$dinero = static function ($valor): string {
    return "$" . number_format((float)$valor, 2);
};

$fechaActual = date("Y-m-d");
$primerDiaMes = date("Y-m-01");

$desde = $_GET["desde"] ?? $primerDiaMes;
$hasta = $_GET["hasta"] ?? $fechaActual;

$fechaValida = static function ($fecha): bool {
    $objeto = DateTime::createFromFormat("!Y-m-d", $fecha);
    return $objeto && $objeto->format("Y-m-d") === $fecha;
};

$error = "";
$filas = [];
$columnas = [];
$resumen = [];
$tituloReporte = "";
$descripcionReporte = "";

try {

    if (!$fechaValida($desde) || !$fechaValida($hasta)) {
        throw new Exception("Seleccioná un rango de fechas válido.");
    }

    if ($desde > $hasta) {
        throw new Exception("La fecha inicial no puede ser posterior a la fecha final.");
    }

    // Se utiliza el día siguiente como límite exclusivo
    // para incluir todas las horas del último día.
    $hastaExclusivo = (new DateTime($hasta))
        ->modify("+1 day")
        ->format("Y-m-d");

    $parametrosFecha = [
        ":desde" => $desde,
        ":hasta" => $hastaExclusivo
    ];

    switch ($tipoReporte) {

        // =====================================================
        // REPORTE DE VENTAS
        // =====================================================
        case "ventas":

            $tituloReporte = "Reporte de ventas";
            $descripcionReporte = "Ventas registradas durante el período seleccionado.";

            $sql = "
                SELECT
                    v.numero_venta,
                    v.fecha_venta,
                    c.nombre AS cliente,
                    u.nombre AS responsable,
                    v.subtotal,
                    v.impuesto,
                    v.total,
                    v.estado
                FROM ventas v
                INNER JOIN clientes c ON c.id = v.cliente_id
                INNER JOIN usuarios u ON u.id = v.usuario_id
                WHERE v.fecha_venta >= :desde
                  AND v.fecha_venta < :hasta
                ORDER BY v.fecha_venta DESC, v.id DESC
            ";

            $consulta = $conexion->prepare($sql);
            $consulta->execute($parametrosFecha);
            $datos = $consulta->fetchAll(PDO::FETCH_ASSOC);

            $cantidad = 0;
            $totalVentas = 0;
            $subtotalVentas = 0;
            $impuestosVentas = 0;
            $ventasAnuladas = 0;
            $ventasPendientes = 0;

            foreach ($datos as $fila) {

                $anulada = $fila["estado"] === "Anulada";

                if ($anulada) {
                    $ventasAnuladas++;
                } else {
                    $cantidad++;
                    $totalVentas += (float)$fila["total"];
                    $subtotalVentas += (float)$fila["subtotal"];
                    $impuestosVentas += (float)$fila["impuesto"];
                }

                if ($fila["estado"] === "Pendiente") {
                    $ventasPendientes++;
                }

                $filas[] = [
                    $h($fila["numero_venta"]),
                    date("d/m/Y H:i", strtotime($fila["fecha_venta"])),
                    $h($fila["cliente"]),
                    $h($fila["responsable"]),
                    $dinero($fila["subtotal"]),
                    $dinero($fila["impuesto"]),
                    $dinero($fila["total"]),
                    $h($fila["estado"])
                ];
            }

            $resumen = [
                ["Ventas válidas", $cantidad],
                ["Subtotal", $dinero($subtotalVentas)],
                ["Impuestos", $dinero($impuestosVentas)],
                ["Total de ventas", $dinero($totalVentas)],
                ["Pendientes", $ventasPendientes],
                ["Anuladas", $ventasAnuladas]
            ];

            $columnas = [
                "Número de venta",
                "Fecha",
                "Cliente",
                "Responsable",
                "Subtotal",
                "Impuesto",
                "Total",
                "Estado"
            ];

            break;


        // =====================================================
        // REPORTE DE COMPRAS
        // =====================================================
        case "compras":

            $tituloReporte = "Reporte de compras";
            $descripcionReporte = "Compras registradas durante el período seleccionado.";

            $sql = "
                SELECT
                    co.numero_compra,
                    co.fecha_compra,
                    p.nombre AS proveedor,
                    u.nombre AS responsable,
                    co.subtotal,
                    co.impuesto,
                    co.total,
                    co.estado
                FROM compras co
                INNER JOIN proveedores p ON p.id = co.proveedor_id
                INNER JOIN usuarios u ON u.id = co.usuario_id
                WHERE co.fecha_compra >= :desde
                  AND co.fecha_compra < :hasta
                ORDER BY co.fecha_compra DESC, co.id DESC
            ";

            $consulta = $conexion->prepare($sql);
            $consulta->execute($parametrosFecha);
            $datos = $consulta->fetchAll(PDO::FETCH_ASSOC);

            $cantidad = 0;
            $subtotalCompras = 0;
            $impuestosCompras = 0;
            $totalCompras = 0;
            $comprasAnuladas = 0;
            $comprasPendientes = 0;

            foreach ($datos as $fila) {

                $anulada = $fila["estado"] === "Anulada";

                if ($anulada) {
                    $comprasAnuladas++;
                } else {
                    $cantidad++;
                    $subtotalCompras += (float)$fila["subtotal"];
                    $impuestosCompras += (float)$fila["impuesto"];
                    $totalCompras += (float)$fila["total"];
                }

                if ($fila["estado"] === "Pendiente") {
                    $comprasPendientes++;
                }

                $filas[] = [
                    $h($fila["numero_compra"]),
                    date("d/m/Y H:i", strtotime($fila["fecha_compra"])),
                    $h($fila["proveedor"]),
                    $h($fila["responsable"]),
                    $dinero($fila["subtotal"]),
                    $dinero($fila["impuesto"]),
                    $dinero($fila["total"]),
                    $h($fila["estado"])
                ];
            }

            $resumen = [
                ["Compras válidas", $cantidad],
                ["Subtotal", $dinero($subtotalCompras)],
                ["Impuestos", $dinero($impuestosCompras)],
                ["Total de compras", $dinero($totalCompras)],
                ["Pendientes", $comprasPendientes],
                ["Anuladas", $comprasAnuladas]
            ];

            $columnas = [
                "Número de compra",
                "Fecha",
                "Proveedor",
                "Responsable",
                "Subtotal",
                "Impuesto",
                "Total",
                "Estado"
            ];

            break;


        // =====================================================
        // REPORTE DE CLIENTES
        // =====================================================
        case "clientes":

            $tituloReporte = "Reporte de clientes";
            $descripcionReporte = "Listado general de clientes registrados.";

            $sql = "
                SELECT
                    id,
                    nombre,
                    dui,
                    nit,
                    telefono,
                    correo,
                    direccion,
                    estado,
                    fecha_creacion
                FROM clientes
                ORDER BY nombre
            ";

            $datos = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            $activos = 0;
            $inactivos = 0;

            foreach ($datos as $fila) {

                if ($fila["estado"] === "Activo") {
                    $activos++;
                } else {
                    $inactivos++;
                }

                $filas[] = [
                    (int)$fila["id"],
                    $h($fila["nombre"]),
                    $h($fila["dui"] ?: "No registrado"),
                    $h($fila["nit"] ?: "No registrado"),
                    $h($fila["telefono"] ?: "No registrado"),
                    $h($fila["correo"] ?: "No registrado"),
                    $h($fila["direccion"] ?: "No registrada"),
                    $h($fila["estado"]),
                    date("d/m/Y", strtotime($fila["fecha_creacion"]))
                ];
            }

            $resumen = [
                ["Total de clientes", count($datos)],
                ["Clientes activos", $activos],
                ["Clientes inactivos", $inactivos]
            ];

            $columnas = [
                "ID",
                "Nombre",
                "DUI",
                "NIT",
                "Teléfono",
                "Correo",
                "Dirección",
                "Estado",
                "Fecha de registro"
            ];

            break;


        // =====================================================
        // REPORTE DE PRODUCTOS
        // =====================================================
        case "productos":

            $tituloReporte = "Reporte de productos";
            $descripcionReporte = "Listado de productos y servicios registrados.";

            $sql = "
                SELECT
                    codigo,
                    nombre,
                    tipo,
                    precio_venta,
                    costo,
                    existencia,
                    estado
                FROM productos
                ORDER BY nombre
            ";

            $datos = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            $activos = 0;
            $inactivos = 0;
            $existencias = 0;
            $valorInventario = 0;

            foreach ($datos as $fila) {

                if ($fila["estado"] === "Activo") {
                    $activos++;
                } else {
                    $inactivos++;
                }

                $existencias += (int)$fila["existencia"];

                if ($fila["tipo"] === "Producto") {
                    $valorInventario +=
                        (float)$fila["costo"] * (int)$fila["existencia"];
                }

                $filas[] = [
                    $h($fila["codigo"]),
                    $h($fila["nombre"]),
                    $h($fila["tipo"]),
                    $dinero($fila["precio_venta"]),
                    $dinero($fila["costo"]),
                    (int)$fila["existencia"],
                    $h($fila["estado"])
                ];
            }

            $resumen = [
                ["Total de registros", count($datos)],
                ["Activos", $activos],
                ["Inactivos", $inactivos],
                ["Unidades en existencia", $existencias],
                ["Valor estimado al costo", $dinero($valorInventario)]
            ];

            $columnas = [
                "Código",
                "Nombre",
                "Tipo",
                "Precio de venta",
                "Costo",
                "Existencia",
                "Estado"
            ];

            break;


        // =====================================================
        // REPORTE DE INVENTARIO
        // =====================================================
        case "inventario":

            $tituloReporte = "Reporte de movimientos de inventario";
            $descripcionReporte = "Entradas y salidas de productos durante el período seleccionado.";

            $sql = "
                SELECT
                    mi.fecha_movimiento,
                    p.codigo,
                    p.nombre AS producto,
                    mi.tipo_movimiento,
                    mi.cantidad,
                    mi.descripcion,
                    u.nombre AS responsable
                FROM movimientos_inventario mi
                INNER JOIN productos p ON p.id = mi.producto_id
                INNER JOIN usuarios u ON u.id = mi.usuario_id
                WHERE mi.fecha_movimiento >= :desde
                  AND mi.fecha_movimiento < :hasta
                ORDER BY mi.fecha_movimiento DESC, mi.id DESC
            ";

            $consulta = $conexion->prepare($sql);
            $consulta->execute($parametrosFecha);
            $datos = $consulta->fetchAll(PDO::FETCH_ASSOC);

            $entradas = 0;
            $salidas = 0;
            $cantidadEntradas = 0;
            $cantidadSalidas = 0;

            foreach ($datos as $fila) {

                if ($fila["tipo_movimiento"] === "Entrada") {
                    $entradas++;
                    $cantidadEntradas += (int)$fila["cantidad"];
                } else {
                    $salidas++;
                    $cantidadSalidas += (int)$fila["cantidad"];
                }

                $filas[] = [
                    date("d/m/Y H:i", strtotime($fila["fecha_movimiento"])),
                    $h($fila["codigo"]),
                    $h($fila["producto"]),
                    $h($fila["tipo_movimiento"]),
                    (int)$fila["cantidad"],
                    $h($fila["descripcion"] ?: "Sin descripción"),
                    $h($fila["responsable"])
                ];
            }

            $resumen = [
                ["Movimientos registrados", count($datos)],
                ["Número de entradas", $entradas],
                ["Unidades ingresadas", $cantidadEntradas],
                ["Número de salidas", $salidas],
                ["Unidades retiradas", $cantidadSalidas]
            ];

            $columnas = [
                "Fecha",
                "Código",
                "Producto",
                "Movimiento",
                "Cantidad",
                "Descripción",
                "Responsable"
            ];

            break;


        // =====================================================
        // REPORTE DE PAGOS
        // =====================================================
        case "pagos":

            $tituloReporte = "Reporte de pagos";
            $descripcionReporte = "Pagos registrados y asociados a ventas no anuladas.";

            $sql = "
                SELECT
                    pa.id,
                    v.numero_venta,
                    c.nombre AS cliente,
                    mp.nombre AS metodo_pago,
                    pa.monto,
                    pa.fecha_pago,
                    pa.referencia
                FROM pagos pa
                INNER JOIN ventas v ON v.id = pa.venta_id
                INNER JOIN clientes c ON c.id = v.cliente_id
                INNER JOIN metodos_pago mp ON mp.id = pa.metodo_pago_id
                WHERE pa.fecha_pago >= :desde
                  AND pa.fecha_pago < :hasta
                  AND v.estado <> 'Anulada'
                ORDER BY pa.fecha_pago DESC, pa.id DESC
            ";

            $consulta = $conexion->prepare($sql);
            $consulta->execute($parametrosFecha);
            $datos = $consulta->fetchAll(PDO::FETCH_ASSOC);

            $totalPagos = 0;

            foreach ($datos as $fila) {

                $totalPagos += (float)$fila["monto"];

                $filas[] = [
                    (int)$fila["id"],
                    $h($fila["numero_venta"]),
                    $h($fila["cliente"]),
                    $h($fila["metodo_pago"]),
                    $dinero($fila["monto"]),
                    date("d/m/Y H:i", strtotime($fila["fecha_pago"])),
                    $h($fila["referencia"] ?: "Sin referencia")
                ];
            }

            $resumen = [
                ["Cantidad de pagos", count($datos)],
                ["Total recibido", $dinero($totalPagos)]
            ];

            $columnas = [
                "ID",
                "Número de venta",
                "Cliente",
                "Método de pago",
                "Monto",
                "Fecha",
                "Referencia"
            ];

            break;


        // =====================================================
        // REPORTE DE COMPROBANTES
        // =====================================================
        case "comprobantes":

            $tituloReporte = "Reporte de comprobantes";
            $descripcionReporte = "Comprobantes emitidos durante el período seleccionado.";

            $sql = "
                SELECT
                    co.numero_comprobante,
                    co.tipo_comprobante,
                    co.fecha_emision,
                    v.numero_venta,
                    c.nombre AS cliente,
                    co.estado
                FROM comprobantes co
                INNER JOIN ventas v ON v.id = co.venta_id
                INNER JOIN clientes c ON c.id = v.cliente_id
                WHERE co.fecha_emision >= :desde
                  AND co.fecha_emision < :hasta
                ORDER BY co.fecha_emision DESC, co.id DESC
            ";

            $consulta = $conexion->prepare($sql);
            $consulta->execute($parametrosFecha);
            $datos = $consulta->fetchAll(PDO::FETCH_ASSOC);

            $emitidos = 0;
            $anulados = 0;

            foreach ($datos as $fila) {

                if ($fila["estado"] === "Emitido") {
                    $emitidos++;
                } else {
                    $anulados++;
                }

                $filas[] = [
                    $h($fila["numero_comprobante"]),
                    $h($fila["tipo_comprobante"]),
                    date("d/m/Y H:i", strtotime($fila["fecha_emision"])),
                    $h($fila["numero_venta"]),
                    $h($fila["cliente"]),
                    $h($fila["estado"])
                ];
            }

            $resumen = [
                ["Total de comprobantes", count($datos)],
                ["Emitidos", $emitidos],
                ["Anulados", $anulados]
            ];

            $columnas = [
                "Número de comprobante",
                "Tipo",
                "Fecha de emisión",
                "Número de venta",
                "Cliente",
                "Estado"
            ];

            break;


        // =====================================================
        // REPORTE CONTABLE
        // =====================================================
        case "contabilidad":

            $tituloReporte = "Reporte de partidas contables";
            $descripcionReporte = "Partidas contabilizadas y sus movimientos durante el período seleccionado.";

            $sql = "
                SELECT
                    p.id,
                    p.numero_partida,
                    p.fecha,
                    p.descripcion,
                    u.nombre AS responsable,
                    COALESCE(SUM(m.debe), 0) AS total_debe,
                    COALESCE(SUM(m.haber), 0) AS total_haber,
                    COUNT(m.id) AS cantidad_movimientos
                FROM partidas_diario p
                INNER JOIN usuarios u ON u.id = p.usuario_id
                LEFT JOIN movimientos_diario m ON m.partida_id = p.id
                WHERE p.estado = 'Contabilizada'
                  AND p.fecha >= :desde
                  AND p.fecha < :hasta
                GROUP BY
                    p.id,
                    p.numero_partida,
                    p.fecha,
                    p.descripcion,
                    u.nombre
                ORDER BY p.fecha DESC, p.id DESC
            ";

            $consulta = $conexion->prepare($sql);
            $consulta->execute($parametrosFecha);
            $datos = $consulta->fetchAll(PDO::FETCH_ASSOC);

            $totalDebe = 0;
            $totalHaber = 0;
            $partidasDescuadradas = 0;

            foreach ($datos as $fila) {

                $debe = (float)$fila["total_debe"];
                $haber = (float)$fila["total_haber"];

                $totalDebe += $debe;
                $totalHaber += $haber;

                if (round($debe, 2) !== round($haber, 2)) {
                    $partidasDescuadradas++;
                }

                $filas[] = [
                    $h($fila["numero_partida"]),
                    date("d/m/Y", strtotime($fila["fecha"])),
                    $h($fila["descripcion"]),
                    $h($fila["responsable"]),
                    (int)$fila["cantidad_movimientos"],
                    $dinero($debe),
                    $dinero($haber),
                    round($debe, 2) === round($haber, 2)
                        ? "Cuadrada"
                        : "Revisar"
                ];
            }

            $resumen = [
                ["Partidas contabilizadas", count($datos)],
                ["Total del debe", $dinero($totalDebe)],
                ["Total del haber", $dinero($totalHaber)],
                ["Diferencia", $dinero(abs($totalDebe - $totalHaber))],
                ["Partidas por revisar", $partidasDescuadradas]
            ];

            $columnas = [
                "Número de partida",
                "Fecha",
                "Descripción",
                "Responsable",
                "Movimientos",
                "Debe",
                "Haber",
                "Resultado"
            ];

            break;
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
}

$nombreTipos = [
    "ventas" => "Ventas",
    "compras" => "Compras",
    "clientes" => "Clientes",
    "productos" => "Productos",
    "inventario" => "Inventario",
    "pagos" => "Pagos",
    "comprobantes" => "Comprobantes",
    "contabilidad" => "Contabilidad"
];

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reportes - Sistema Contable</title>

    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">

    <style>
        .encabezado-reporte {
            margin-bottom: 25px;
        }

        .encabezado-reporte h1 {
            margin-bottom: 7px;
        }

        .filtros-reporte {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 15px;
            margin-bottom: 22px;
        }

        .campo-filtro {
            display: flex;
            flex-direction: column;
            gap: 7px;
            min-width: 180px;
        }

        .campo-filtro label {
            font-weight: 600;
        }

        .campo-filtro input,
        .campo-filtro select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 7px;
            background: white;
        }

        .boton-reporte {
            padding: 11px 17px;
            border: none;
            border-radius: 7px;
            background: #7136a5;
            color: white;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
        }

        .boton-reporte:hover {
            background: #54247f;
        }

        .boton-secundario {
            background: #eeeeee;
            color: #333;
        }

        .boton-secundario:hover {
            background: #dddddd;
        }

        .resumen-reportes {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .tarjeta-resumen {
            background: white;
            border-radius: 10px;
            padding: 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid #7136a5;
        }

        .tarjeta-resumen span {
            display: block;
            color: #666;
            font-size: 13px;
            margin-bottom: 9px;
        }

        .tarjeta-resumen strong {
            font-size: 21px;
            color: #333;
        }

        .contenedor-tabla-reporte {
            width: 100%;
            overflow-x: auto;
        }

        .tabla-reporte {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        .tabla-reporte th {
            background: #7136a5;
            color: white;
            padding: 13px;
            text-align: left;
            white-space: nowrap;
        }

        .tabla-reporte td {
            padding: 12px;
            border-bottom: 1px solid #eeeeee;
            color: #444;
        }

        .tabla-reporte tbody tr:hover {
            background: #faf7fc;
        }

        .sin-datos {
            text-align: center;
            padding: 30px !important;
            color: #777 !important;
        }

        .mensaje-error-reporte {
            padding: 14px;
            border-radius: 8px;
            background: #ffe8e8;
            color: #a32323;
            margin-bottom: 20px;
        }

        .navegacion-reportes {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-bottom: 22px;
        }

        .enlace-reporte {
            padding: 9px 14px;
            background: #f0ebf5;
            color: #54247f;
            border-radius: 7px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .enlace-reporte.activo {
            background: #7136a5;
            color: white;
        }

        .informacion-periodo {
            color: #666;
            font-size: 13px;
            margin-top: 12px;
        }

        .encabezado-impresion {
            display: none;
        }

        @media print {

            .menu-lateral,
            .navegacion-reportes,
            .filtros-reporte,
            .no-imprimir,
            button {
                display: none !important;
            }

            .layout {
                display: block !important;
            }

            .contenido {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            body {
                background: white !important;
            }

            .tarjeta {
                box-shadow: none !important;
                border: 1px solid #ddd;
                page-break-inside: avoid;
            }

            .encabezado-impresion {
                display: block;
                margin-bottom: 20px;
            }

            .tabla-reporte {
                min-width: 100%;
                font-size: 10px;
            }

            .tabla-reporte th,
            .tabla-reporte td {
                padding: 7px;
            }

            .resumen-reportes {
                grid-template-columns: repeat(3, 1fr);
            }
        }
    </style>
</head>

<body>

<div class="layout">

    <?php require "../includes/menu.php"; ?>

    <main class="contenido">

        <header class="encabezado encabezado-reporte">
            <h1>Reportes del sistema</h1>
            <p>Consulta y generación de información administrativa y contable.</p>
        </header>

        <section class="tarjeta no-imprimir">

            <h2>Seleccionar reporte</h2>

            <nav class="navegacion-reportes">

                <?php foreach ($nombreTipos as $clave => $nombre): ?>

                    <a
                        href="reportes.php?tipo=<?= $h($clave) ?>"
                        class="enlace-reporte <?= $tipoReporte === $clave ? "activo" : "" ?>"
                    >
                        <?= $h($nombre) ?>
                    </a>

                <?php endforeach; ?>

            </nav>

            <form method="GET" class="filtros-reporte">

                <input
                    type="hidden"
                    name="tipo"
                    value="<?= $h($tipoReporte) ?>"
                >

                <div class="campo-filtro">
                    <label for="desde">Fecha inicial</label>
                    <input
                        type="date"
                        name="desde"
                        id="desde"
                        value="<?= $h($desde) ?>"
                        required
                    >
                </div>

                <div class="campo-filtro">
                    <label for="hasta">Fecha final</label>
                    <input
                        type="date"
                        name="hasta"
                        id="hasta"
                        value="<?= $h($hasta) ?>"
                        required
                    >
                </div>

                <button type="submit" class="boton-reporte">
                    Consultar
                </button>

                <button
                    type="button"
                    class="boton-reporte boton-secundario"
                    onclick="window.print()"
                >
                    Imprimir / Guardar PDF
                </button>

            </form>

            <p class="informacion-periodo">
                Los filtros de fechas se aplican a los reportes de
                ventas, compras, inventario, pagos, comprobantes y contabilidad.
                Clientes y productos muestran el listado general.
            </p>

        </section>


        <?php if ($error !== ""): ?>

            <div class="mensaje-error-reporte">
                <?= $h($error) ?>
            </div>

        <?php else: ?>

            <div class="encabezado-impresion">
                <h1>Sistema de Facturación y Control de Ventas</h1>
                <h2><?= $h($tituloReporte) ?></h2>
                <p>Período: <?= $h($desde) ?> al <?= $h($hasta) ?></p>
                <p>Fecha de generación: <?= date("d/m/Y H:i") ?></p>
            </div>

            <section class="encabezado-reporte">
                <h2><?= $h($tituloReporte) ?></h2>
                <p><?= $h($descripcionReporte) ?></p>
            </section>


            <!-- RESUMEN -->
            <section class="resumen-reportes">

                <?php foreach ($resumen as $indicador): ?>

                    <article class="tarjeta-resumen">
                        <span><?= $h($indicador[0]) ?></span>
                        <strong><?= $h($indicador[1]) ?></strong>
                    </article>

                <?php endforeach; ?>

            </section>


            <!-- TABLA -->
            <section class="tarjeta">

                <h2>Detalle del reporte</h2>

                <div class="contenedor-tabla-reporte">

                    <table class="tabla-reporte">

                        <thead>
                            <tr>
                                <?php foreach ($columnas as $columna): ?>
                                    <th><?= $h($columna) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (count($filas) > 0): ?>

                                <?php foreach ($filas as $fila): ?>

                                    <tr>
                                        <?php foreach ($fila as $valor): ?>
                                            <td><?= $valor ?></td>
                                        <?php endforeach; ?>
                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td
                                        colspan="<?= count($columnas) ?>"
                                        class="sin-datos"
                                    >
                                        No se encontraron registros para este reporte.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <p class="informacion-periodo">
                    Registros encontrados: <?= count($filas) ?>
                </p>

            </section>

        <?php endif; ?>

    </main>

</div>

<script src="../assets/js/menu.js"></script>

</body>
</html>
