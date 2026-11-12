
<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";

requerirAcceso("contabilidad");

// --------------------------------------------------
// FUNCIONES AUXILIARES
// --------------------------------------------------

$h = static function ($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
};

$dinero = static function ($centavos): string {
    $centavos = (int)$centavos;
    $signo = $centavos < 0 ? "-$" : "$";

    return $signo . number_format(abs($centavos) / 100, 2, ".", ",");
};

$aCentavos = static function ($valor): int {
    return (int)round((float)$valor * 100);
};

$validarFecha = static function ($fecha): bool {
    if (!is_string($fecha)) {
        return false;
    }

    $objetoFecha = DateTime::createFromFormat("!Y-m-d", $fecha);

    return $objetoFecha !== false
        && $objetoFecha->format("Y-m-d") === $fecha;
};

// --------------------------------------------------
// FILTROS
// --------------------------------------------------

$fechaDesde = $_GET["desde"] ?? date("Y-01-01");
$fechaHasta = $_GET["hasta"] ?? date("Y-12-31");

$vista = $_GET["vista"] ?? "comprobacion";

$vistasPermitidas = [
    "comprobacion",
    "balance",
    "resultados"
];

if (!in_array($vista, $vistasPermitidas, true)) {
    $vista = "comprobacion";
}

$fechasValidas =
    $validarFecha($fechaDesde)
    && $validarFecha($fechaHasta)
    && $fechaDesde <= $fechaHasta;

// --------------------------------------------------
// VARIABLES
// --------------------------------------------------

$cuentas = [];
$error = "";

$totalDebe = 0;
$totalHaber = 0;
$totalSaldoDeudor = 0;
$totalSaldoAcreedor = 0;

$totalActivos = 0;
$totalPasivos = 0;
$totalPatrimonio = 0;
$totalPatrimonioResultado = 0;
$resultadoPeriodo = 0;
$diferenciaBalance = 0;

$totalIngresos = 0;
$totalCostos = 0;
$totalGastos = 0;
$utilidadPerdida = 0;

$cantidadCuentas = 0;
$cantidadCuentasConMovimiento = 0;

// --------------------------------------------------
// CONSULTA CONTABLE
// --------------------------------------------------

try {

    if (!$fechasValidas) {
        throw new Exception("Selecciona un período con fechas válidas.");
    }

    /*
     * Se consultan las cuentas que permiten movimientos.
     * Los saldos se calculan únicamente con partidas contabilizadas
     * hasta la fecha final seleccionada.
     *
     * Se conservan las cuentas sin movimientos mediante LEFT JOIN.
     */

    $consulta = $conexion->prepare(
        "SELECT
            c.id,
            c.codigo,
            c.nombre,
            c.tipo,
            c.naturaleza,
            c.estado,

            COALESCE(
                SUM(
                    CASE
                        WHEN p.id IS NOT NULL THEN m.debe
                        ELSE 0
                    END
                ), 0
            ) AS debe_acumulado,

            COALESCE(
                SUM(
                    CASE
                        WHEN p.id IS NOT NULL THEN m.haber
                        ELSE 0
                    END
                ), 0
            ) AS haber_acumulado,

            COALESCE(
                SUM(
                    CASE
                        WHEN p.id IS NOT NULL
                         AND p.fecha >= :desde
                        THEN m.debe
                        ELSE 0
                    END
                ), 0
            ) AS debe_periodo,

            COALESCE(
                SUM(
                    CASE
                        WHEN p.id IS NOT NULL
                         AND p.fecha >= :desde2
                        THEN m.haber
                        ELSE 0
                    END
                ), 0
            ) AS haber_periodo

        FROM cuentas_contables c

        LEFT JOIN movimientos_diario m
            ON m.cuenta_id = c.id

        LEFT JOIN partidas_diario p
            ON p.id = m.partida_id
           AND p.estado = 'Contabilizada'
           AND p.fecha <= :hasta

        WHERE c.permite_movimientos = TRUE

        GROUP BY
            c.id,
            c.codigo,
            c.nombre,
            c.tipo,
            c.naturaleza,
            c.estado

        ORDER BY c.codigo"
    );

    $consulta->execute([
        ":desde" => $fechaDesde,
        ":desde2" => $fechaDesde,
        ":hasta" => $fechaHasta
    ]);

    $registros = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // --------------------------------------------------
    // PROCESAMIENTO DE CUENTAS
    // --------------------------------------------------

    foreach ($registros as $registro) {

        $debeAcumulado = $aCentavos($registro["debe_acumulado"]);
        $haberAcumulado = $aCentavos($registro["haber_acumulado"]);

        $debePeriodo = $aCentavos($registro["debe_periodo"]);
        $haberPeriodo = $aCentavos($registro["haber_periodo"]);

        // Diferencia entre movimientos deudores y acreedores.
        $saldoNeto = $debeAcumulado - $haberAcumulado;

        // Clasificación del saldo para el balance de comprobación.
        $saldoDeudor = max($saldoNeto, 0);
        $saldoAcreedor = max(-$saldoNeto, 0);

        $registro["debe_centavos"] = $debeAcumulado;
        $registro["haber_centavos"] = $haberAcumulado;
        $registro["saldo_neto"] = $saldoNeto;
        $registro["saldo_deudor"] = $saldoDeudor;
        $registro["saldo_acreedor"] = $saldoAcreedor;

        $registro["debe_periodo_centavos"] = $debePeriodo;
        $registro["haber_periodo_centavos"] = $haberPeriodo;

        $cuentas[] = $registro;

        $cantidadCuentas++;

        if ($debeAcumulado !== 0 || $haberAcumulado !== 0) {
            $cantidadCuentasConMovimiento++;
        }

        // Totales del balance de comprobación.
        $totalDebe += $debeAcumulado;
        $totalHaber += $haberAcumulado;

        $totalSaldoDeudor += $saldoDeudor;
        $totalSaldoAcreedor += $saldoAcreedor;

        // BALANCE GENERAL.
        switch ($registro["tipo"]) {

            case "Activo":
                $totalActivos += $saldoNeto;
                break;

            case "Pasivo":
                $totalPasivos -= $saldoNeto;
                break;

            case "Patrimonio":
                $totalPatrimonio -= $saldoNeto;
                break;
        }

        // ESTADO DE RESULTADOS.
        switch ($registro["tipo"]) {

            case "Ingreso":
                $totalIngresos += $haberPeriodo - $debePeriodo;
                break;

            case "Costo":
                $totalCostos += $debePeriodo - $haberPeriodo;
                break;

            case "Gasto":
                $totalGastos += $debePeriodo - $haberPeriodo;
                break;
        }
    }

    // --------------------------------------------------
    // RESULTADO DEL PERÍODO
    // --------------------------------------------------

    $utilidadPerdida =
        $totalIngresos - $totalCostos - $totalGastos;

    $resultadoPeriodo = $utilidadPerdida;

    // --------------------------------------------------
    // ECUACIÓN DEL BALANCE GENERAL
    // --------------------------------------------------

    $totalPatrimonioResultado =
        $totalPatrimonio + $resultadoPeriodo;

    $diferenciaBalance =
        $totalActivos - $totalPasivos - $totalPatrimonioResultado;

} catch (Throwable $e) {

    error_log("Contabilidad: " . $e->getMessage());

    if ($e instanceof PDOException) {
        $error = "No fue posible obtener la información contable. Verifica la conexión y las tablas.";
    } else {
        $error = $e->getMessage();
    }
}

// --------------------------------------------------
// PRESENTACIÓN
// --------------------------------------------------

$fechaDesdeMostrar = $validarFecha($fechaDesde)
    ? date("d/m/Y", strtotime($fechaDesde))
    : "";

$fechaHastaMostrar = $validarFecha($fechaHasta)
    ? date("d/m/Y", strtotime($fechaHasta))
    : "";

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Contabilidad - Sistema Contable</title>

    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">

    <style>

        .contabilidad-contenedor {
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        .contabilidad-contenedor .tarjeta {
            overflow: hidden;
        }

        .introduccion-contabilidad {
            color: #777;
            margin-top: 5px;
        }

        .filtros-contabilidad {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            align-items: end;
        }

        .filtros-contabilidad label {
            display: block;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .filtros-contabilidad input {
            width: 100%;
            padding: 11px;
            border: 1px solid #ddd;
            border-radius: 7px;
            box-sizing: border-box;
        }

        .acciones-contabilidad {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 17px;
        }

        .navegacion-contabilidad {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .opcion-contabilidad {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 18px;
            border: 1px solid #e8e0ef;
            border-radius: 10px;
            text-decoration: none;
            color: #333;
            background: #fff;
            transition: 0.2s;
        }

        .opcion-contabilidad:hover {
            border-color: #7136a5;
            background: #faf7fc;
            transform: translateY(-2px);
        }

        .opcion-contabilidad.activa {
            border: 2px solid #7136a5;
            background: #f7f0fc;
        }

        .opcion-contabilidad .icono {
            font-size: 25px;
        }

        .opcion-contabilidad strong {
            font-size: 15px;
        }

        .opcion-contabilidad small {
            color: #777;
            line-height: 1.4;
        }

        .resumen-contabilidad {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
        }

        .resumen-contabilidad .resumen-item {
            background: #f8f6fa;
            border-radius: 9px;
            padding: 17px;
        }

        .resumen-item span {
            display: block;
            color: #777;
            font-size: 13px;
        }

        .resumen-item strong {
            display: block;
            font-size: 21px;
            margin-top: 8px;
            overflow-wrap: anywhere;
        }

        .tabla-contabilidad-contenedor {
            width: 100%;
            overflow-x: auto;
        }

        .tabla-contabilidad {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
            margin-top: 16px;
        }

        .tabla-contabilidad th,
        .tabla-contabilidad td {
            padding: 12px 11px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        .tabla-contabilidad th {
            background: #7136a5;
            color: white;
            white-space: nowrap;
        }

        .tabla-contabilidad td.numero {
            text-align: right;
            white-space: nowrap;
        }

        .tabla-contabilidad tfoot th {
            background: #f1eaf7;
            color: #333;
        }

        .tabla-contabilidad .fila-total td {
            font-weight: bold;
            background: #f8f6fa;
        }

        .tabla-contabilidad .fila-seccion td {
            background: #f5eff9;
            font-weight: bold;
            color: #7136a5;
        }

        .mensaje-contabilidad {
            padding: 13px 15px;
            border-radius: 8px;
            background: #ffe8e8;
            color: #a32323;
        }

        .mensaje-informativo {
            padding: 14px;
            border-radius: 8px;
            background: #f4eff9;
            color: #57357a;
            line-height: 1.6;
            margin-top: 17px;
        }

        .mensaje-advertencia {
            padding: 14px;
            border-radius: 8px;
            background: #fff5df;
            color: #795414;
            line-height: 1.6;
            margin-top: 17px;
        }

        .estado-cuenta {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 20px;
            background: #e2f7e8;
            color: #176b32;
            font-size: 11px;
        }

        .estado-cuenta.inactiva {
            background: #ffe6e6;
            color: #a32323;
        }

        .resultado-positivo {
            color: #176b32;
        }

        .resultado-negativo {
            color: #a32323;
        }

        .sin-registros {
            text-align: center !important;
            padding: 25px !important;
            color: #777;
        }

        .etiqueta-periodo {
            display: inline-block;
            background: #f1eaf7;
            color: #7136a5;
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 13px;
            margin: 8px 0 15px;
        }

        @media (max-width: 850px) {
            .navegacion-contabilidad {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .filtros-contabilidad,
            .resumen-contabilidad {
                grid-template-columns: 1fr;
            }
        }

        @media print {

            .menu-lateral,
            .acciones-contabilidad,
            .navegacion-contabilidad,
            .filtros-contabilidad {
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

            .tarjeta {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                break-inside: avoid;
            }

            .tabla-contabilidad {
                min-width: 100%;
            }
        }

    </style>

</head>

<body>

<div class="layout">

    <?php require "../includes/menu.php"; ?>

    <main class="contenido contabilidad-contenedor">

        <header class="encabezado">

            <div>

                <h1>Contabilidad</h1>

                <p class="introduccion-contabilidad">
                    Consulta, análisis y presentación de la información financiera
                    de la empresa.
                </p>

            </div>

        </header>

        <?php if ($error !== ""): ?>

            <div class="mensaje-contabilidad">
                <?= $h($error) ?>
            </div>

        <?php else: ?>

            <!-- NAVEGACIÓN -->

            <section class="navegacion-contabilidad">

                <a
                    class="opcion-contabilidad <?= $vista === "comprobacion" ? "activa" : "" ?>"
                    href="?vista=comprobacion&desde=<?= $h($fechaDesde) ?>&hasta=<?= $h($fechaHasta) ?>"
                >
                    <span class="icono">📊</span>
                    <strong>Balance de comprobación</strong>
                    <small>
                        Consulta los movimientos y saldos de las cuentas contables.
                    </small>
                </a>

                <a
                    class="opcion-contabilidad <?= $vista === "balance" ? "activa" : "" ?>"
                    href="?vista=balance&desde=<?= $h($fechaDesde) ?>&hasta=<?= $h($fechaHasta) ?>"
                >
                    <span class="icono">🏛️</span>
                    <strong>Balance general</strong>
                    <small>
                        Consulta los activos, pasivos y patrimonio de la empresa.
                    </small>
                </a>

                <a
                    class="opcion-contabilidad <?= $vista === "resultados" ? "activa" : "" ?>"
                    href="?vista=resultados&desde=<?= $h($fechaDesde) ?>&hasta=<?= $h($fechaHasta) ?>"
                >
                    <span class="icono">📈</span>
                    <strong>Estado de resultados</strong>
                    <small>
                        Consulta los ingresos, costos, gastos y resultado del período.
                    </small>
                </a>

            </section>

            <!-- FILTROS -->

            <section class="tarjeta">

                <h2>Período de consulta</h2>

                <p class="introduccion-contabilidad">
                    Selecciona las fechas para consultar la información contable.
                </p>

                <form method="GET">

                    <input type="hidden" name="vista" value="<?= $h($vista) ?>">

                    <div class="filtros-contabilidad">

                        <div>
                            <label for="desde">Fecha inicial</label>
                            <input
                                type="date"
                                name="desde"
                                id="desde"
                                value="<?= $h($fechaDesde) ?>"
                                required
                            >
                        </div>

                        <div>
                            <label for="hasta">Fecha final</label>
                            <input
                                type="date"
                                name="hasta"
                                id="hasta"
                                value="<?= $h($fechaHasta) ?>"
                                required
                            >
                        </div>

                    </div>

                    <div class="acciones-contabilidad">

                        <button type="submit">
                            Consultar información
                        </button>

                        <a
                            href="contabilidad.php?vista=<?= $h($vista) ?>"
                            class="boton-secundario"
                        >
                            Restablecer
                        </a>

                        <button
                            type="button"
                            class="boton-secundario"
                            onclick="window.print()"
                        >
                            Imprimir
                        </button>

                    </div>

                </form>

            </section>

            <!-- RESULTADOS -->

            <section class="tarjeta">

                <h2>
                    <?php
                    if ($vista === "comprobacion") {
                        echo "Balance de comprobación";
                    } elseif ($vista === "balance") {
                        echo "Balance general";
                    } else {
                        echo "Estado de resultados";
                    }
                    ?>
                </h2>

                <span class="etiqueta-periodo">

                    <?php if ($vista === "comprobacion"): ?>

                        Saldos acumulados hasta <?= $h($fechaHastaMostrar) ?>

                    <?php elseif ($vista === "balance"): ?>

                        Situación financiera al <?= $h($fechaHastaMostrar) ?>

                    <?php else: ?>

                        Del <?= $h($fechaDesdeMostrar) ?>
                        al <?= $h($fechaHastaMostrar) ?>

                    <?php endif; ?>

                </span>

                <!-- BALANCE DE COMPROBACIÓN -->

                <?php if ($vista === "comprobacion"): ?>

                    <div class="resumen-contabilidad">

                        <div class="resumen-item">
                            <span>Total de movimientos en el Debe</span>
                            <strong><?= $dinero($totalDebe) ?></strong>
                        </div>

                        <div class="resumen-item">
                            <span>Total de movimientos en el Haber</span>
                            <strong><?= $dinero($totalHaber) ?></strong>
                        </div>

                        <div class="resumen-item">
                            <span>Cuentas consultadas</span>
                            <strong><?= number_format($cantidadCuentas) ?></strong>
                        </div>

                    </div>

                    <div class="tabla-contabilidad-contenedor">

                        <table class="tabla-contabilidad">

                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Cuenta contable</th>
                                    <th>Tipo</th>
                                    <th>Debe</th>
                                    <th>Haber</th>
                                    <th>Saldo deudor</th>
                                    <th>Saldo acreedor</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($cuentas as $cuenta): ?>

                                    <tr>

                                        <td><?= $h($cuenta["codigo"]) ?></td>

                                        <td>
                                            <?= $h($cuenta["nombre"]) ?>

                                            <?php if ($cuenta["estado"] === "Inactiva"): ?>
                                                <span class="estado-cuenta inactiva">
                                                    Inactiva
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td><?= $h($cuenta["tipo"]) ?></td>

                                        <td class="numero">
                                            <?= $dinero($cuenta["debe_centavos"]) ?>
                                        </td>

                                        <td class="numero">
                                            <?= $dinero($cuenta["haber_centavos"]) ?>
                                        </td>

                                        <td class="numero">
                                            <?= $dinero($cuenta["saldo_deudor"]) ?>
                                        </td>

                                        <td class="numero">
                                            <?= $dinero($cuenta["saldo_acreedor"]) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                <?php if (count($cuentas) === 0): ?>
                                    <tr>
                                        <td colspan="7" class="sin-registros">
                                            No existen cuentas que permitan movimientos.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                            </tbody>

                            <tfoot>
                                <tr>
                                    <th colspan="3">Totales</th>

                                    <th class="numero">
                                        <?= $dinero($totalDebe) ?>
                                    </th>

                                    <th class="numero">
                                        <?= $dinero($totalHaber) ?>
                                    </th>

                                    <th class="numero">
                                        <?= $dinero($totalSaldoDeudor) ?>
                                    </th>

                                    <th class="numero">
                                        <?= $dinero($totalSaldoAcreedor) ?>
                                    </th>
                                </tr>
                            </tfoot>

                        </table>

                    </div>

                    <div class="mensaje-informativo">

                        <strong>Interpretación:</strong>

                        El balance de comprobación permite verificar que los
                        movimientos contables acumulados mantengan igualdad
                        entre el total del Debe y el total del Haber.

                        Los saldos deudores y acreedores muestran la diferencia
                        entre ambos lados para cada cuenta.

                    </div>

                    <?php if ($totalDebe !== $totalHaber): ?>

                        <div class="mensaje-advertencia">

                            <strong>Atención:</strong>

                            Los totales del Debe y del Haber no coinciden.
                            Es necesario revisar los registros contables y
                            verificar que las partidas contabilizadas estén
                            correctamente equilibradas.

                        </div>

                    <?php else: ?>

                        <div class="mensaje-informativo">

                            <strong>Comprobación:</strong>

                            Los totales acumulados del Debe y del Haber coinciden.

                        </div>

                    <?php endif; ?>

                <!-- BALANCE GENERAL -->

                <?php elseif ($vista === "balance"): ?>

                    <div class="resumen-contabilidad">

                        <div class="resumen-item">
                            <span>Total de activos</span>
                            <strong><?= $dinero($totalActivos) ?></strong>
                        </div>

                        <div class="resumen-item">
                            <span>Total de pasivos</span>
                            <strong><?= $dinero($totalPasivos) ?></strong>
                        </div>

                        <div class="resumen-item">
                            <span>Patrimonio con resultado del período</span>
                            <strong><?= $dinero($totalPatrimonioResultado) ?></strong>
                        </div>

                    </div>

                    <div class="tabla-contabilidad-contenedor">

                        <table class="tabla-contabilidad">

                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Cuenta contable</th>
                                    <th>Tipo</th>
                                    <th>Saldo</th>
                                </tr>
                            </thead>

                            <tbody>

                                <tr class="fila-seccion">
                                    <td colspan="4">ACTIVOS</td>
                                </tr>

                                <?php foreach ($cuentas as $cuenta): ?>

                                    <?php if ($cuenta["tipo"] === "Activo" && $cuenta["saldo_neto"] !== 0): ?>

                                        <tr>
                                            <td><?= $h($cuenta["codigo"]) ?></td>
                                            <td><?= $h($cuenta["nombre"]) ?></td>
                                            <td>Activo</td>
                                            <td class="numero">
                                                <?= $dinero($cuenta["saldo_neto"]) ?>
                                            </td>
                                        </tr>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                                <tr class="fila-total">
                                    <td colspan="3">TOTAL ACTIVOS</td>
                                    <td class="numero"><?= $dinero($totalActivos) ?></td>
                                </tr>

                                <tr class="fila-seccion">
                                    <td colspan="4">PASIVOS</td>
                                </tr>

                                <?php foreach ($cuentas as $cuenta): ?>

                                    <?php if ($cuenta["tipo"] === "Pasivo" && $cuenta["saldo_neto"] !== 0): ?>

                                        <tr>
                                            <td><?= $h($cuenta["codigo"]) ?></td>
                                            <td><?= $h($cuenta["nombre"]) ?></td>
                                            <td>Pasivo</td>
                                            <td class="numero">
                                                <?= $dinero(-$cuenta["saldo_neto"]) ?>
                                            </td>
                                        </tr>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                                <tr class="fila-total">
                                    <td colspan="3">TOTAL PASIVOS</td>
                                    <td class="numero"><?= $dinero($totalPasivos) ?></td>
                                </tr>

                                <tr class="fila-seccion">
                                    <td colspan="4">PATRIMONIO</td>
                                </tr>

                                <?php foreach ($cuentas as $cuenta): ?>

                                    <?php if ($cuenta["tipo"] === "Patrimonio" && $cuenta["saldo_neto"] !== 0): ?>

                                        <tr>
                                            <td><?= $h($cuenta["codigo"]) ?></td>
                                            <td><?= $h($cuenta["nombre"]) ?></td>
                                            <td>Patrimonio</td>
                                            <td class="numero">
                                                <?= $dinero(-$cuenta["saldo_neto"]) ?>
                                            </td>
                                        </tr>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                                <tr>
                                    <td>—</td>
                                    <td>Resultado del período</td>
                                    <td>Resultado</td>
                                    <td class="numero">
                                        <?= $dinero($resultadoPeriodo) ?>
                                    </td>
                                </tr>

                                <tr class="fila-total">
                                    <td colspan="3">TOTAL PATRIMONIO Y RESULTADO</td>
                                    <td class="numero">
                                        <?= $dinero($totalPatrimonioResultado) ?>
                                    </td>
                                </tr>

                            </tbody>

                            <tfoot>
                                <tr>
                                    <th colspan="3">TOTAL PASIVO Y PATRIMONIO</th>
                                    <th class="numero">
                                        <?= $dinero($totalPasivos + $totalPatrimonioResultado) ?>
                                    </th>
                                </tr>
                            </tfoot>

                        </table>

                    </div>

                    <div class="resumen-contabilidad">

                        <div class="resumen-item">
                            <span>Total activos</span>
                            <strong><?= $dinero($totalActivos) ?></strong>
                        </div>

                        <div class="resumen-item">
                            <span>Pasivo + patrimonio</span>
                            <strong>
                                <?= $dinero($totalPasivos + $totalPatrimonioResultado) ?>
                            </strong>
                        </div>

                        <div class="resumen-item">
                            <span>Diferencia</span>
                            <strong class="<?= $diferenciaBalance === 0 ? "resultado-positivo" : "resultado-negativo" ?>">
                                <?= $dinero($diferenciaBalance) ?>
                            </strong>
                        </div>

                    </div>

                    <?php if ($diferenciaBalance === 0): ?>

                        <div class="mensaje-informativo">

                            <strong>Ecuación contable equilibrada.</strong>

                            El total de activos coincide con el total de
                            pasivos más patrimonio, incluyendo el resultado
                            del período seleccionado.

                        </div>

                    <?php else: ?>

                        <div class="mensaje-advertencia">

                            <strong>El balance presenta una diferencia.</strong>

                            Revisa las cuentas, sus clasificaciones, los
                            movimientos contabilizados y los cierres de
                            ejercicios anteriores.

                        </div>

                    <?php endif; ?>

                    <div class="mensaje-informativo">

                        <strong>Nota contable:</strong>

                        El resultado mostrado corresponde exclusivamente
                        al período seleccionado. Para presentar correctamente
                        ejercicios anteriores, sus resultados deben haberse
                        cerrado y trasladado a las cuentas de patrimonio.

                    </div>

                <!-- ESTADO DE RESULTADOS -->

                <?php elseif ($vista === "resultados"): ?>

                    <div class="resumen-contabilidad">

                        <div class="resumen-item">
                            <span>Total de ingresos</span>
                            <strong><?= $dinero($totalIngresos) ?></strong>
                        </div>

                        <div class="resumen-item">
                            <span>Total de costos y gastos</span>
                            <strong><?= $dinero($totalCostos + $totalGastos) ?></strong>
                        </div>

                        <div class="resumen-item">

                            <span>
                                <?= $utilidadPerdida >= 0
                                    ? "Utilidad del período"
                                    : "Pérdida del período" ?>
                            </span>

                            <strong class="<?= $utilidadPerdida >= 0 ? "resultado-positivo" : "resultado-negativo" ?>">
                                <?= $dinero($utilidadPerdida) ?>
                            </strong>

                        </div>

                    </div>

                    <div class="tabla-contabilidad-contenedor">

                        <table class="tabla-contabilidad">

                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Cuenta contable</th>
                                    <th>Tipo</th>
                                    <th>Importe del período</th>
                                </tr>
                            </thead>

                            <tbody>

                                <tr class="fila-seccion">
                                    <td colspan="4">INGRESOS</td>
                                </tr>

                                <?php foreach ($cuentas as $cuenta): ?>

                                    <?php if ($cuenta["tipo"] === "Ingreso" && ($cuenta["haber_periodo_centavos"] - $cuenta["debe_periodo_centavos"]) !== 0): ?>

                                        <tr>
                                            <td><?= $h($cuenta["codigo"]) ?></td>
                                            <td><?= $h($cuenta["nombre"]) ?></td>
                                            <td>Ingreso</td>
                                            <td class="numero">
                                                <?= $dinero($cuenta["haber_periodo_centavos"] - $cuenta["debe_periodo_centavos"]) ?>
                                            </td>
                                        </tr>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                                <tr class="fila-total">
                                    <td colspan="3">TOTAL INGRESOS</td>
                                    <td class="numero"><?= $dinero($totalIngresos) ?></td>
                                </tr>

                                <tr class="fila-seccion">
                                    <td colspan="4">COSTOS</td>
                                </tr>

                                <?php foreach ($cuentas as $cuenta): ?>

                                    <?php if ($cuenta["tipo"] === "Costo" && ($cuenta["debe_periodo_centavos"] - $cuenta["haber_periodo_centavos"]) !== 0): ?>

                                        <tr>
                                            <td><?= $h($cuenta["codigo"]) ?></td>
                                            <td><?= $h($cuenta["nombre"]) ?></td>
                                            <td>Costo</td>
                                            <td class="numero">
                                                <?= $dinero($cuenta["debe_periodo_centavos"] - $cuenta["haber_periodo_centavos"]) ?>
                                            </td>
                                        </tr>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                                <tr class="fila-total">
                                    <td colspan="3">TOTAL COSTOS</td>
                                    <td class="numero"><?= $dinero($totalCostos) ?></td>
                                </tr>

                                <tr class="fila-seccion">
                                    <td colspan="4">GASTOS</td>
                                </tr>

                                <?php foreach ($cuentas as $cuenta): ?>

                                    <?php if ($cuenta["tipo"] === "Gasto" && ($cuenta["debe_periodo_centavos"] - $cuenta["haber_periodo_centavos"]) !== 0): ?>

                                        <tr>
                                            <td><?= $h($cuenta["codigo"]) ?></td>
                                            <td><?= $h($cuenta["nombre"]) ?></td>
                                            <td>Gasto</td>
                                            <td class="numero">
                                                <?= $dinero($cuenta["debe_periodo_centavos"] - $cuenta["haber_periodo_centavos"]) ?>
                                            </td>
                                        </tr>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                                <tr class="fila-total">
                                    <td colspan="3">TOTAL GASTOS</td>
                                    <td class="numero"><?= $dinero($totalGastos) ?></td>
                                </tr>

                            </tbody>

                            <tfoot>
                                <tr>
                                    <th colspan="3">
                                        <?= $utilidadPerdida >= 0
                                            ? "UTILIDAD DEL PERÍODO"
                                            : "PÉRDIDA DEL PERÍODO" ?>
                                    </th>

                                    <th class="numero">
                                        <?= $dinero($utilidadPerdida) ?>
                                    </th>
                                </tr>
                            </tfoot>

                        </table>

                    </div>

                    <div class="mensaje-informativo">

                        <strong>Interpretación del resultado:</strong>

                        <?php if ($utilidadPerdida > 0): ?>

                            Durante el período seleccionado, los ingresos
                            superaron los costos y gastos, generando una
                            utilidad de <?= $dinero($utilidadPerdida) ?>.

                        <?php elseif ($utilidadPerdida < 0): ?>

                            Durante el período seleccionado, los costos y
                            gastos superaron los ingresos, generando una
                            pérdida de <?= $dinero(abs($utilidadPerdida)) ?>.

                        <?php else: ?>

                            Durante el período seleccionado, los ingresos
                            fueron iguales a los costos y gastos, por lo que
                            el resultado fue de equilibrio.

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </section>

        <?php endif; ?>

        <footer class="texto-secundario">

            <small>
                Sistema de Facturación y Control de Ventas |
                Módulo de Contabilidad
            </small>

        </footer>

    </main>

</div>

<script src="../assets/js/menu.js"></script>

</body>
</html>
