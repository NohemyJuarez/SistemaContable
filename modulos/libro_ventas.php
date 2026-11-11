<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";

requerirAcceso("libro_ventas");

// Este módulo es de consulta. No permite registrar ni modificar ventas.

$desde = trim($_GET["desde"] ?? "");
$hasta = trim($_GET["hasta"] ?? "");
$tipo = trim($_GET["tipo"] ?? "");
$estado = trim($_GET["estado"] ?? "");
$buscar = trim($_GET["buscar"] ?? "");
$detalleId = filter_input(INPUT_GET, "detalle", FILTER_VALIDATE_INT);

$ventas = [];
$detallesVenta = [];
$ventaDetalle = null;
$error = "";

$totales = [
    "cantidad" => 0,
    "anuladas" => 0,
    "gravadas" => 0,
    "exentas" => 0,
    "no_sujetas" => 0,
    "subtotal" => 0,
    "impuesto" => 0,
    "total" => 0
];

function escapar($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

function fechaValida(string $fecha): bool
{
    $formato = DateTime::createFromFormat("!Y-m-d", $fecha);

    return $formato !== false &&
        $formato->format("Y-m-d") === $fecha;
}

function dinero($valor): string
{
    return "$" . number_format((float)$valor, 2);
}

function claseEstado(string $estado): string
{
    if ($estado === "Pagada") {
        return "estado-pagada";
    }

    if ($estado === "Anulada") {
        return "estado-anulada";
    }

    return "estado-pendiente";
}

function protegerCSV($valor): string
{
    $valor = (string)$valor;

    // Evita que Excel interprete el contenido como una fórmula.
    if ($valor !== "" && in_array($valor[0], ["=", "+", "-", "@"], true)) {
        return "'" . $valor;
    }

    return $valor;
}

try {

    // Validaciones de filtros.
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

    $tiposValidos = [
        "Factura",
        "Comprobante de crédito fiscal",
        "Comprobante interno"
    ];

    $estadosValidos = [
        "Pagada",
        "Pendiente",
        "Anulada"
    ];

    if ($tipo !== "" && !in_array($tipo, $tiposValidos, true)) {
        throw new Exception("El tipo de comprobante seleccionado no es válido.");
    }

    if ($estado !== "" && !in_array($estado, $estadosValidos, true)) {
        throw new Exception("El estado seleccionado no es válido.");
    }

    // Construir filtros.
    $condiciones = [];
    $parametros = [];

    if ($desde !== "") {
        $condiciones[] = "v.fecha_venta >= CAST(:desde AS DATE)";
        $parametros[":desde"] = $desde;
    }

    if ($hasta !== "") {
        $condiciones[] = "v.fecha_venta < CAST(:hasta AS DATE) + INTERVAL '1 day'";
        $parametros[":hasta"] = $hasta;
    }

    if ($tipo !== "") {
        $condiciones[] = "cp.tipo_comprobante = :tipo";
        $parametros[":tipo"] = $tipo;
    }

    if ($estado !== "") {
        $condiciones[] = "v.estado = :estado";
        $parametros[":estado"] = $estado;
    }

    if ($buscar !== "") {
        $condiciones[] = "(
            c.nombre ILIKE :buscar_cliente
            OR v.numero_venta ILIKE :buscar_venta
            OR COALESCE(cp.numero_comprobante, '') ILIKE :buscar_comprobante
            OR COALESCE(to_jsonb(c)->>'dui', '') ILIKE :buscar_dui
            OR COALESCE(to_jsonb(c)->>'nit', '') ILIKE :buscar_nit
        )";

        $textoBusqueda = "%" . $buscar . "%";

        $parametros[":buscar_cliente"] = $textoBusqueda;
        $parametros[":buscar_venta"] = $textoBusqueda;
        $parametros[":buscar_comprobante"] = $textoBusqueda;
        $parametros[":buscar_dui"] = $textoBusqueda;
        $parametros[":buscar_nit"] = $textoBusqueda;
    }

    $where = count($condiciones) > 0
        ? "WHERE " . implode(" AND ", $condiciones)
        : "";

    /*
     * Consulta principal.
     *
     * Se utiliza una subconsulta lateral para obtener el comprobante
     * más reciente de cada venta y otra para resumir sus detalles.
     * Así se evita duplicar los importes del encabezado.
     *
     * to_jsonb(c) permite consultar DUI/NIT sin depender de que
     * todas las instalaciones tengan exactamente los mismos nombres
     * de columnas para esos datos.
     */
    $sqlVentas = "
        SELECT
            v.id,
            v.numero_venta,
            v.fecha_venta,
            v.subtotal,
            v.impuesto,
            v.total,
            v.estado,

            c.nombre AS cliente,

            COALESCE(
                to_jsonb(c)->>'dui',
                to_jsonb(c)->>'numero_dui',
                to_jsonb(c)->>'documento_identidad',
                ''
            ) AS dui,

            COALESCE(
                to_jsonb(c)->>'nit',
                to_jsonb(c)->>'numero_nit',
                ''
            ) AS nit,

            COALESCE(
                cp.tipo_comprobante,
                'Sin comprobante'
            ) AS tipo_comprobante,

            COALESCE(
                cp.numero_comprobante,
                v.numero_venta
            ) AS numero_comprobante,

            COALESCE(d.gravadas, 0) AS gravadas,
            COALESCE(d.exentas, 0) AS exentas,
            COALESCE(d.no_sujetas, 0) AS no_sujetas

        FROM ventas v

        INNER JOIN clientes c
            ON c.id = v.cliente_id

        LEFT JOIN LATERAL (
            SELECT
                comprobante.tipo_comprobante,
                comprobante.numero_comprobante
            FROM comprobantes comprobante
            WHERE comprobante.venta_id = v.id
            ORDER BY comprobante.id DESC
            LIMIT 1
        ) cp ON TRUE

        LEFT JOIN LATERAL (
            SELECT
                SUM(
                    CASE
                        WHEN COALESCE(
                            NULLIF(to_jsonb(dv)->>'clasificacion_fiscal', ''),
                            'Gravada'
                        ) = 'Gravada'
                        THEN dv.subtotal
                        ELSE 0
                    END
                ) AS gravadas,

                SUM(
                    CASE
                        WHEN to_jsonb(dv)->>'clasificacion_fiscal' = 'Exenta'
                        THEN dv.subtotal
                        ELSE 0
                    END
                ) AS exentas,

                SUM(
                    CASE
                        WHEN to_jsonb(dv)->>'clasificacion_fiscal' = 'No sujeta'
                        THEN dv.subtotal
                        ELSE 0
                    END
                ) AS no_sujetas

            FROM detalle_ventas dv
            WHERE dv.venta_id = v.id
        ) d ON TRUE

        $where

        ORDER BY v.fecha_venta DESC, v.id DESC
    ";

    $consulta = $conexion->prepare($sqlVentas);
    $consulta->execute($parametros);

    $ventas = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // Calcular totales del período.
    // Las ventas anuladas se muestran, pero no suman importes.
    foreach ($ventas as $venta) {

        if ($venta["estado"] === "Anulada") {
            $totales["anuladas"]++;
            continue;
        }

        $totales["cantidad"]++;

        $totales["gravadas"] += (float)$venta["gravadas"];
        $totales["exentas"] += (float)$venta["exentas"];
        $totales["no_sujetas"] += (float)$venta["no_sujetas"];

        $totales["subtotal"] += (float)$venta["subtotal"];
        $totales["impuesto"] += (float)$venta["impuesto"];
        $totales["total"] += (float)$venta["total"];
    }

    // Exportar el resultado filtrado a CSV.
    if (isset($_GET["exportar"]) && $_GET["exportar"] === "csv") {

        $nombreArchivo = "libro_ventas_" . date("Ymd_His") . ".csv";

        header("Content-Type: text/csv; charset=UTF-8");
        header(
            'Content-Disposition: attachment; filename="' .
            $nombreArchivo . '"'
        );

        // BOM para que Excel reconozca correctamente los caracteres.
        echo "\xEF\xBB\xBF";

        $salida = fopen("php://output", "w");

        fputcsv($salida, [
            "Fecha",
            "Tipo de comprobante",
            "Número de comprobante",
            "Número de venta",
            "Cliente",
            "DUI",
            "NIT",
            "Ventas gravadas",
            "Ventas exentas",
            "Ventas no sujetas",
            "Débito fiscal (IVA)",
            "Subtotal",
            "Total",
            "Estado"
        ], ";");

        foreach ($ventas as $venta) {

            fputcsv($salida, [
                protegerCSV(date(
                    "d/m/Y H:i",
                    strtotime($venta["fecha_venta"])
                )),
                protegerCSV($venta["tipo_comprobante"]),
                protegerCSV($venta["numero_comprobante"]),
                protegerCSV($venta["numero_venta"]),
                protegerCSV($venta["cliente"]),
                protegerCSV($venta["dui"]),
                protegerCSV($venta["nit"]),
                number_format((float)$venta["gravadas"], 2, ".", ""),
                number_format((float)$venta["exentas"], 2, ".", ""),
                number_format((float)$venta["no_sujetas"], 2, ".", ""),
                number_format((float)$venta["impuesto"], 2, ".", ""),
                number_format((float)$venta["subtotal"], 2, ".", ""),
                number_format((float)$venta["total"], 2, ".", ""),
                protegerCSV($venta["estado"])
            ], ";");
        }

        fputcsv($salida, [], ";");
        fputcsv($salida, [
            "RESUMEN",
            "",
            "",
            "",
            "",
            "",
            "",
            number_format($totales["gravadas"], 2, ".", ""),
            number_format($totales["exentas"], 2, ".", ""),
            number_format($totales["no_sujetas"], 2, ".", ""),
            number_format($totales["impuesto"], 2, ".", ""),
            number_format($totales["subtotal"], 2, ".", ""),
            number_format($totales["total"], 2, ".", ""),
            "Ventas válidas: " . $totales["cantidad"] .
            " | Anuladas: " . $totales["anuladas"]
        ], ";");

        fclose($salida);
        exit;
    }

    // Consultar el detalle de una venta seleccionada.
    if ($detalleId) {

        $consultaDetalle = $conexion->prepare("
            SELECT
                v.id,
                v.numero_venta,
                v.fecha_venta,
                v.estado,
                c.nombre AS cliente,
                COALESCE(cp.tipo_comprobante, 'Sin comprobante') AS tipo_comprobante,
                COALESCE(cp.numero_comprobante, v.numero_venta) AS numero_comprobante
            FROM ventas v
            INNER JOIN clientes c ON c.id = v.cliente_id

            LEFT JOIN LATERAL (
                SELECT
                    comprobante.tipo_comprobante,
                    comprobante.numero_comprobante
                FROM comprobantes comprobante
                WHERE comprobante.venta_id = v.id
                ORDER BY comprobante.id DESC
                LIMIT 1
            ) cp ON TRUE

            WHERE v.id = :id
        ");

        $consultaDetalle->execute([":id" => $detalleId]);
        $ventaDetalle = $consultaDetalle->fetch(PDO::FETCH_ASSOC);

        if ($ventaDetalle) {

            $consultaLineas = $conexion->prepare("
                SELECT
                    dv.id,
                    p.codigo,
                    p.nombre AS producto,
                    p.tipo,
                    dv.cantidad,
                    dv.precio_unitario,
                    dv.descuento,
                    dv.subtotal,

                    COALESCE(
                        NULLIF(to_jsonb(dv)->>'clasificacion_fiscal', ''),
                        'Gravada'
                    ) AS clasificacion_fiscal,

                    COALESCE(
                        (to_jsonb(dv)->>'porcentaje_impuesto')::NUMERIC,
                        0
                    ) AS porcentaje_impuesto

                FROM detalle_ventas dv
                INNER JOIN productos p ON p.id = dv.producto_id
                WHERE dv.venta_id = :venta_id
                ORDER BY dv.id
            ");

            $consultaLineas->execute([
                ":venta_id" => $detalleId
            ]);

            $detallesVenta = $consultaLineas->fetchAll(PDO::FETCH_ASSOC);

        } else {
            $error = "No se encontró la venta que intentás consultar.";
        }
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
    $ventas = [];
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
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            align-items: end;
            gap: 15px;
            margin-top: 20px;
        }

        .filtros-ventas .campo {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filtros-ventas input,
        .filtros-ventas select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        .acciones-filtros {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .boton,
        .enlace-boton {
            display: inline-block;
            padding: 10px 15px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
            text-align: center;
        }

        .boton-principal {
            background: #7136a5;
            color: white;
        }

        .boton-secundario {
            background: #eee;
            color: #333;
        }

        .boton-exportar {
            background: #237a45;
            color: white;
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
            font-size: 20px;
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
            padding: 11px;
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

        .nota-libro {
            background: #fff6df;
            color: #805b0b;
            padding: 13px;
            border-radius: 8px;
            margin: 15px 0;
            line-height: 1.5;
        }

        .detalle-encabezado {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .detalle-datos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin: 15px 0;
        }

        .detalle-datos div {
            background: #f7f3fb;
            padding: 12px;
            border-radius: 7px;
        }

        .detalle-datos span {
            display: block;
            color: #666;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .enlace-detalle {
            color: #7136a5;
            font-weight: bold;
            text-decoration: none;
        }

        .enlace-detalle:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .resumen-ventas .dato strong {
                font-size: 18px;
            }
        }
    </style>
</head>

<body>

<div class="layout">

    <?php require "../includes/menu.php"; ?>

    <main class="contenido">

        <header class="encabezado">
            <h1>Libro de ventas</h1>
            <p>
                Consulta, clasificación y resumen de las ventas registradas.
            </p>
        </header>

        <?php if ($error !== ""): ?>
            <div class="mensaje-error">
                <?= escapar($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($ventaDetalle): ?>

            <section class="tarjeta">

                <div class="detalle-encabezado">
                    <div>
                        <h2>Detalle de la venta</h2>
                        <p>
                            <?= escapar($ventaDetalle["numero_venta"]) ?>
                        </p>
                    </div>

                    <a
                        class="enlace-boton boton-secundario"
                        href="libro_ventas.php<?= escapar(
                            "?" . http_build_query(array_filter([
                                "desde" => $desde,
                                "hasta" => $hasta,
                                "tipo" => $tipo,
                                "estado" => $estado,
                                "buscar" => $buscar
                            ], fn($valor) => $valor !== ""))
                        ) ?>"
                    >
                        Volver al libro
                    </a>
                </div>

                <div class="detalle-datos">

                    <div>
                        <span>Fecha</span>
                        <strong>
                            <?= date(
                                "d/m/Y H:i",
                                strtotime($ventaDetalle["fecha_venta"])
                            ) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Cliente</span>
                        <strong>
                            <?= escapar($ventaDetalle["cliente"]) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Tipo de comprobante</span>
                        <strong>
                            <?= escapar($ventaDetalle["tipo_comprobante"]) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Número de comprobante</span>
                        <strong>
                            <?= escapar($ventaDetalle["numero_comprobante"]) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Estado</span>
                        <span class="estado-venta <?= claseEstado($ventaDetalle["estado"]) ?>">
                            <?= escapar($ventaDetalle["estado"]) ?>
                        </span>
                    </div>

                </div>

                <h3>Productos o servicios</h3>

                <div class="tabla-contenedor">
                    <table class="tabla-libro">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Producto o servicio</th>
                                <th>Cantidad</th>
                                <th>Precio unitario</th>
                                <th>Descuento</th>
                                <th>Clasificación fiscal</th>
                                <th>IVA</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($detallesVenta) > 0): ?>

                                <?php foreach ($detallesVenta as $linea): ?>
                                    <tr>
                                        <td><?= escapar($linea["codigo"]) ?></td>
                                        <td><?= escapar($linea["producto"]) ?></td>
                                        <td><?= (int)$linea["cantidad"] ?></td>
                                        <td><?= dinero($linea["precio_unitario"]) ?></td>
                                        <td><?= dinero($linea["descuento"]) ?></td>
                                        <td><?= escapar($linea["clasificacion_fiscal"]) ?></td>
                                        <td>
                                            <?= number_format(
                                                (float)$linea["porcentaje_impuesto"],
                                                2
                                            ) ?>%
                                        </td>
                                        <td>
                                            <strong><?= dinero($linea["subtotal"]) ?></strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="8" class="sin-registros">
                                        Esta venta no tiene detalles registrados.
                                    </td>
                                </tr>

                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="nota-libro">
                    Este detalle muestra la información almacenada al registrar
                    la venta. Las ventas anuladas se conservan para consulta
                    y trazabilidad.
                </p>

            </section>

        <?php else: ?>

            <section class="tarjeta">

                <h2>Filtros de consulta</h2>

                <form method="GET" class="filtros-ventas">

                    <div class="campo">
                        <label for="desde">Fecha inicial</label>
                        <input
                            type="date"
                            name="desde"
                            id="desde"
                            value="<?= escapar($desde) ?>"
                        >
                    </div>

                    <div class="campo">
                        <label for="hasta">Fecha final</label>
                        <input
                            type="date"
                            name="hasta"
                            id="hasta"
                            value="<?= escapar($hasta) ?>"
                        >
                    </div>

                    <div class="campo">
                        <label for="tipo">Tipo de comprobante</label>
                        <select name="tipo" id="tipo">
                            <option value="">Todos</option>

                            <option value="Factura" <?= $tipo === "Factura" ? "selected" : "" ?>>
                                Factura
                            </option>

                            <option
                                value="Comprobante de crédito fiscal"
                                <?= $tipo === "Comprobante de crédito fiscal" ? "selected" : "" ?>
                            >
                                Comprobante de crédito fiscal
                            </option>

                            <option
                                value="Comprobante interno"
                                <?= $tipo === "Comprobante interno" ? "selected" : "" ?>
                            >
                                Comprobante interno
                            </option>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="estado">Estado</label>
                        <select name="estado" id="estado">
                            <option value="">Todos</option>

                            <option value="Pagada" <?= $estado === "Pagada" ? "selected" : "" ?>>
                                Pagada
                            </option>

                            <option value="Pendiente" <?= $estado === "Pendiente" ? "selected" : "" ?>>
                                Pendiente
                            </option>

                            <option value="Anulada" <?= $estado === "Anulada" ? "selected" : "" ?>>
                                Anulada
                            </option>
                        </select>
                    </div>

                    <div class="campo">
                        <label for="buscar">Buscar</label>
                        <input
                            type="search"
                            name="buscar"
                            id="buscar"
                            placeholder="Cliente, DUI, NIT o documento"
                            value="<?= escapar($buscar) ?>"
                        >
                    </div>

                    <div class="acciones-filtros">
                        <button type="submit" class="boton boton-principal">
                            Consultar
                        </button>

                        <a
                            href="libro_ventas.php"
                            class="enlace-boton boton-secundario"
                        >
                            Limpiar
                        </a>
                    </div>

                </form>

            </section>

            <section class="resumen-ventas">

                <div class="dato">
                    <span>Ventas válidas</span>
                    <strong><?= $totales["cantidad"] ?></strong>
                </div>

                <div class="dato">
                    <span>Ventas gravadas</span>
                    <strong><?= dinero($totales["gravadas"]) ?></strong>
                </div>

                <div class="dato">
                    <span>Ventas exentas</span>
                    <strong><?= dinero($totales["exentas"]) ?></strong>
                </div>

                <div class="dato">
                    <span>Ventas no sujetas</span>
                    <strong><?= dinero($totales["no_sujetas"]) ?></strong>
                </div>

                <div class="dato">
                    <span>Débito fiscal (IVA)</span>
                    <strong><?= dinero($totales["impuesto"]) ?></strong>
                </div>

                <div class="dato">
                    <span>Subtotal</span>
                    <strong><?= dinero($totales["subtotal"]) ?></strong>
                </div>

                <div class="dato">
                    <span>Total del período</span>
                    <strong><?= dinero($totales["total"]) ?></strong>
                </div>

                <div class="dato">
                    <span>Ventas anuladas</span>
                    <strong><?= $totales["anuladas"] ?></strong>
                </div>

            </section>

            <section class="tarjeta">

                <div class="detalle-encabezado">
                    <div>
                        <h2>Registro de ventas</h2>
                        <p>
                            Se encontraron <?= count($ventas) ?>
                            registro(s) con los filtros seleccionados.
                        </p>
                    </div>

                    <a
                        class="enlace-boton boton-exportar"
                        href="libro_ventas.php?<?= escapar(http_build_query(array_merge(
                            array_filter([
                                "desde" => $desde,
                                "hasta" => $hasta,
                                "tipo" => $tipo,
                                "estado" => $estado,
                                "buscar" => $buscar
                            ], fn($valor) => $valor !== ""),
                            ["exportar" => "csv"]
                        ))) ?>"
                    >
                        Exportar a CSV
                    </a>
                </div>

                <div class="tabla-contenedor">

                    <table class="tabla-libro">

                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Tipo de comprobante</th>
                                <th>N.º de comprobante</th>
                                <th>Cliente</th>
                                <th>DUI</th>
                                <th>NIT</th>
                                <th>Gravadas</th>
                                <th>Exentas</th>
                                <th>No sujetas</th>
                                <th>IVA</th>
                                <th>Total</th>
                                <th>Estado</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (count($ventas) > 0): ?>

                                <?php foreach ($ventas as $venta): ?>

                                    <tr class="<?= $venta["estado"] === "Anulada" ? "fila-anulada" : "" ?>">

                                        <td>
                                            <?= date(
                                                "d/m/Y H:i",
                                                strtotime($venta["fecha_venta"])
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapar($venta["tipo_comprobante"]) ?>
                                        </td>

                                        <td>
                                            <?= escapar($venta["numero_comprobante"]) ?>
                                        </td>

                                        <td>
                                            <?= escapar($venta["cliente"]) ?>
                                        </td>

                                        <td>
                                            <?= escapar($venta["dui"] ?: "—") ?>
                                        </td>

                                        <td>
                                            <?= escapar($venta["nit"] ?: "—") ?>
                                        </td>

                                        <td><?= dinero($venta["gravadas"]) ?></td>
                                        <td><?= dinero($venta["exentas"]) ?></td>
                                        <td><?= dinero($venta["no_sujetas"]) ?></td>
                                        <td><?= dinero($venta["impuesto"]) ?></td>

                                        <td>
                                            <strong><?= dinero($venta["total"]) ?></strong>
                                        </td>

                                        <td>
                                            <span class="estado-venta <?= claseEstado($venta["estado"]) ?>">
                                                <?= escapar($venta["estado"]) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <a
                                                class="enlace-detalle"
                                                href="libro_ventas.php?<?= escapar(http_build_query(array_merge(
                                                    array_filter([
                                                        "desde" => $desde,
                                                        "hasta" => $hasta,
                                                        "tipo" => $tipo,
                                                        "estado" => $estado,
                                                        "buscar" => $buscar
                                                    ], fn($valor) => $valor !== ""),
                                                    ["detalle" => $venta["id"]]
                                                ))) ?>"
                                            >
                                                Ver detalle
                                            </a>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="13" class="sin-registros">
                                        No se encontraron ventas para los filtros seleccionados.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <p class="nota-libro">
                    Las ventas anuladas se conservan en el registro para
                    consulta, pero no se incluyen en los totales del período.
                    Los importes se obtienen de las ventas y sus detalles
                    registrados en el sistema.
                </p>

            </section>

        <?php endif; ?>

        <section class="tarjeta">
            
        </section>

    </main>

</div>

<script src="../assets/js/menu.js"></script>

</body>
</html>