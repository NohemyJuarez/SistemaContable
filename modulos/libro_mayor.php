
<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";

requerirAcceso("libro_mayor");

$cuentas = [];
$cuentaSeleccionada = null;
$movimientos = [];
$error = "";

$h = static function ($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
};

// Fechas predeterminadas: año actual.
$fechaDesde = $_GET["desde"] ?? date("Y-01-01");
$fechaHasta = $_GET["hasta"] ?? date("Y-12-31");

$cuentaId = filter_var(
    $_GET["cuenta_id"] ?? "",
    FILTER_VALIDATE_INT
);

$saldoInicialCentavos = 0;
$totalDebeCentavos = 0;
$totalHaberCentavos = 0;
$saldoFinalCentavos = 0;

// Validar fechas.
$validarFecha = static function ($fecha): bool {
    if (!is_string($fecha)) {
        return false;
    }

    $objetoFecha = DateTime::createFromFormat("!Y-m-d", $fecha);

    return $objetoFecha &&
        $objetoFecha->format("Y-m-d") === $fecha;
};

$fechasValidas =
    $validarFecha($fechaDesde) &&
    $validarFecha($fechaHasta) &&
    $fechaDesde <= $fechaHasta;

try {

    // Cargar cuentas que permiten movimientos.
    // Se incluyen inactivas para consultar sus movimientos históricos.
    $consultaCuentas = $conexion->query(
        "SELECT
            id,
            codigo,
            nombre,
            tipo,
            naturaleza,
            estado
         FROM cuentas_contables
         WHERE permite_movimientos = TRUE
         ORDER BY codigo"
    );

    $cuentas = $consultaCuentas->fetchAll(PDO::FETCH_ASSOC);

    if (!$fechasValidas) {
        throw new Exception("Selecciona un período con fechas válidas.");
    }

    if ($cuentaId !== false && $cuentaId !== null) {

        // Verificar que la cuenta exista y permita movimientos.
        $consultaCuenta = $conexion->prepare(
            "SELECT
                id,
                codigo,
                nombre,
                tipo,
                naturaleza,
                estado
             FROM cuentas_contables
             WHERE id = ?
               AND permite_movimientos = TRUE"
        );

        $consultaCuenta->execute([$cuentaId]);

        $cuentaSeleccionada = $consultaCuenta->fetch(PDO::FETCH_ASSOC);

        if (!$cuentaSeleccionada) {
            throw new Exception("La cuenta seleccionada no es válida.");
        }

        // Calcular saldo inicial: movimientos anteriores al período.
        $consultaSaldoInicial = $conexion->prepare(
            "SELECT
                COALESCE(SUM(m.debe), 0) AS total_debe,
                COALESCE(SUM(m.haber), 0) AS total_haber
             FROM movimientos_diario m
             INNER JOIN partidas_diario p
                ON p.id = m.partida_id
             WHERE m.cuenta_id = ?
               AND p.estado = 'Contabilizada'
               AND p.fecha < ?"
        );

        $consultaSaldoInicial->execute([
            $cuentaId,
            $fechaDesde
        ]);

        $saldoInicial = $consultaSaldoInicial->fetch(PDO::FETCH_ASSOC);

        $debeInicialCentavos = (int)round(
            (float)$saldoInicial["total_debe"] * 100
        );

        $haberInicialCentavos = (int)round(
            (float)$saldoInicial["total_haber"] * 100
        );

        // El saldo depende de la naturaleza de la cuenta.
        if ($cuentaSeleccionada["naturaleza"] === "Deudora") {
            $saldoInicialCentavos =
                $debeInicialCentavos - $haberInicialCentavos;
        } else {
            $saldoInicialCentavos =
                $haberInicialCentavos - $debeInicialCentavos;
        }

        // Consultar movimientos contabilizados del período.
        $consultaMovimientos = $conexion->prepare(
            "SELECT
    m.id,
    p.id AS partida_id,
    m.descripcion AS descripcion_movimiento,
                m.debe,
                m.haber,
                p.numero_partida,
                p.fecha,
                p.descripcion AS descripcion_partida
             FROM movimientos_diario m
             INNER JOIN partidas_diario p
                ON p.id = m.partida_id
             WHERE m.cuenta_id = ?
               AND p.estado = 'Contabilizada'
               AND p.fecha >= ?
               AND p.fecha <= ?
             ORDER BY p.fecha ASC, p.id ASC, m.id ASC"
        );

        $consultaMovimientos->execute([
            $cuentaId,
            $fechaDesde,
            $fechaHasta
        ]);

        $movimientos = $consultaMovimientos->fetchAll(PDO::FETCH_ASSOC);

        // Calcular saldos acumulados.
        $saldoAcumuladoCentavos = $saldoInicialCentavos;

        foreach ($movimientos as &$movimiento) {

            $debeCentavos = (int)round(
                (float)$movimiento["debe"] * 100
            );

            $haberCentavos = (int)round(
                (float)$movimiento["haber"] * 100
            );

            $totalDebeCentavos += $debeCentavos;
            $totalHaberCentavos += $haberCentavos;

            if ($cuentaSeleccionada["naturaleza"] === "Deudora") {
                $saldoAcumuladoCentavos +=
                    $debeCentavos - $haberCentavos;
            } else {
                $saldoAcumuladoCentavos +=
                    $haberCentavos - $debeCentavos;
            }

            $movimiento["saldo_acumulado"] = $saldoAcumuladoCentavos;
        }

        unset($movimiento);

        $saldoFinalCentavos = $saldoAcumuladoCentavos;
    }

} catch (Throwable $e) {

    error_log("Libro mayor: " . $e->getMessage());

    if ($e instanceof PDOException) {
        $error = "No se pudo consultar el libro mayor.";
    } else {
        $error = $e->getMessage();
    }
}

// Formato monetario.
$dinero = static function ($centavos): string {
    return "$" . number_format(
        $centavos / 100,
        2,
        ".",
        ","
    );
};

// Para mostrar saldos negativos correctamente.
$dineroConSigno = static function ($centavos) use ($dinero): string {
    if ($centavos < 0) {
        return "-" . $dinero(abs($centavos));
    }

    return $dinero($centavos);
};
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Libro mayor - Sistema Contable</title>

    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">

    <style>
        .mayor-contenedor {
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        .mayor-contenedor .tarjeta {
            overflow: hidden;
        }

        .mayor-contenedor label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .mayor-contenedor input,
        .mayor-contenedor select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 7px;
            box-sizing: border-box;
        }

        .filtros-mayor {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
            align-items: end;
        }

        .acciones-mayor {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .datos-cuenta {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .dato-cuenta {
            padding: 14px;
            background: #f8f6fa;
            border-radius: 8px;
        }

        .dato-cuenta strong {
            display: block;
            margin-top: 6px;
        }

        .tabla-contenedor {
            width: 100%;
            overflow-x: auto;
        }

        .tabla-mayor {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .tabla-mayor th,
        .tabla-mayor td {
            padding: 11px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        .tabla-mayor th {
            background: #7136a5;
            color: white;
            white-space: nowrap;
        }

        .tabla-mayor td.numero {
            text-align: right;
            white-space: nowrap;
        }

        .tabla-mayor tfoot th {
            background: #f1eaf7;
            color: #333;
        }

        .resumen-mayor {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .resumen-mayor div {
            padding: 16px;
            background: #f8f6fa;
            border-radius: 8px;
        }

        .resumen-mayor strong {
            display: block;
            font-size: 19px;
            margin-top: 8px;
        }

        .mensaje-mayor {
            padding: 12px;
            border-radius: 8px;
            background: #ffe8e8;
            color: #a32323;
        }

        .texto-secundario {
            color: #777;
        }

        .sin-registros {
            text-align: center !important;
            padding: 25px !important;
            color: #777;
        }

        .estado-cuenta {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #e2f7e8;
            color: #176b32;
            font-size: 12px;
        }

        .estado-cuenta.inactiva {
            background: #ffe6e6;
            color: #a32323;
        }

        @media (max-width: 750px) {
            .filtros-mayor,
            .datos-cuenta,
            .resumen-mayor {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="layout">

    <?php require "../includes/menu.php"; ?>

    <main class="contenido mayor-contenedor">

        <header class="encabezado">
            <div>
                <h1>Libro mayor</h1>
                <p>
                    Consulta los movimientos y saldos acumulados
                    de cada cuenta contable.
                </p>
            </div>
        </header>

        <?php if ($error !== ""): ?>
            <div class="mensaje-mayor">
                <?= $h($error) ?>
            </div>
        <?php endif; ?>

        <section class="tarjeta">

            <h2>Consultar cuenta contable</h2>

            <form method="GET">

                <div class="filtros-mayor">

                    <div>
                        <label for="cuenta_id">Cuenta contable</label>

                        <select name="cuenta_id" id="cuenta_id" required>
                            <option value="">Seleccionar cuenta</option>

                            <?php foreach ($cuentas as $cuenta): ?>

                                <option
                                    value="<?= (int)$cuenta["id"] ?>"
                                    <?= (string)$cuentaId === (string)$cuenta["id"] ? "selected" : "" ?>
                                >
                                    <?= $h($cuenta["codigo"]) ?>
                                    -
                                    <?= $h($cuenta["nombre"]) ?>
                                    (<?= $h($cuenta["naturaleza"]) ?>)
                                    <?= $cuenta["estado"] === "Inactiva" ? " - Inactiva" : "" ?>
                                </option>

                            <?php endforeach; ?>
                        </select>
                    </div>

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

                <div class="acciones-mayor">
                    <button type="submit">Consultar movimientos</button>

                    <a href="libro_mayor.php" class="boton-secundario">
                        Limpiar filtros
                    </a>
                </div>

            </form>

        </section>

        <?php if ($cuentaSeleccionada): ?>

            <section class="tarjeta">

                <h2>Movimientos de la cuenta</h2>

                <div class="datos-cuenta">

                    <div class="dato-cuenta">
                        <span class="texto-secundario">Código</span>
                        <strong><?= $h($cuentaSeleccionada["codigo"]) ?></strong>
                    </div>

                    <div class="dato-cuenta">
                        <span class="texto-secundario">Nombre de la cuenta</span>
                        <strong><?= $h($cuentaSeleccionada["nombre"]) ?></strong>
                    </div>

                    <div class="dato-cuenta">
                        <span class="texto-secundario">Tipo de cuenta</span>
                        <strong><?= $h($cuentaSeleccionada["tipo"]) ?></strong>
                    </div>

                    <div class="dato-cuenta">
                        <span class="texto-secundario">Naturaleza</span>
                        <strong><?= $h($cuentaSeleccionada["naturaleza"]) ?></strong>
                    </div>

                    <div class="dato-cuenta">
                        <span class="texto-secundario">Período consultado</span>
                        <strong>
                            <?= date("d/m/Y", strtotime($fechaDesde)) ?>
                            al
                            <?= date("d/m/Y", strtotime($fechaHasta)) ?>
                        </strong>
                    </div>

                    <div class="dato-cuenta">
                        <span class="texto-secundario">Estado de la cuenta</span>

                        <strong>
                            <span class="estado-cuenta <?= $cuentaSeleccionada["estado"] === "Inactiva" ? "inactiva" : "" ?>">
                                <?= $h($cuentaSeleccionada["estado"]) ?>
                            </span>
                        </strong>
                    </div>

                </div>

                <div class="tabla-contenedor">

                    <table class="tabla-mayor">

                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>N.º de partida</th>
                                <th>Descripción</th>
                                <th>Debe</th>
                                <th>Haber</th>
                                <th>Saldo</th>
                            </tr>
                        </thead>

                        <tbody>

                            <tr>
                                <td colspan="3">
                                    <strong>Saldo inicial</strong>
                                </td>

                                <td class="numero">—</td>
                                <td class="numero">—</td>

                                <td class="numero">
                                    <strong>
                                        <?= $dineroConSigno($saldoInicialCentavos) ?>
                                    </strong>
                                </td>
                            </tr>

                            <?php if (count($movimientos) > 0): ?>

                                <?php foreach ($movimientos as $movimiento): ?>

                                    <tr>

                                        <td>
                                            <?= date(
                                                "d/m/Y",
                                                strtotime($movimiento["fecha"])
                                            ) ?>
                                        </td>

                                        <td>
                                            <a href="libro_diario.php?ver=<?= (int)$movimiento["partida_id"] ?>">
                                                <?= $h($movimiento["numero_partida"]) ?>
                                            </a>
                                        </td>

                                        <td>
                                            <?= $h(
                                                $movimiento["descripcion_movimiento"]
                                                ?: $movimiento["descripcion_partida"]
                                            ) ?>
                                        </td>

                                        <td class="numero">
                                            <?= $dinero(
                                                (int)round(
                                                    (float)$movimiento["debe"] * 100
                                                )
                                            ) ?>
                                        </td>

                                        <td class="numero">
                                            <?= $dinero(
                                                (int)round(
                                                    (float)$movimiento["haber"] * 100
                                                )
                                            ) ?>
                                        </td>

                                        <td class="numero">
                                            <strong>
                                                <?= $dineroConSigno(
                                                    $movimiento["saldo_acumulado"]
                                                ) ?>
                                            </strong>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="6" class="sin-registros">
                                        No hay movimientos contabilizados
                                        para esta cuenta durante el período.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                        <tfoot>
                            <tr>
                                <th colspan="3">Totales del período</th>

                                <th class="numero">
                                    <?= $dinero($totalDebeCentavos) ?>
                                </th>

                                <th class="numero">
                                    <?= $dinero($totalHaberCentavos) ?>
                                </th>

                                <th class="numero">
                                    <?= $dineroConSigno($saldoFinalCentavos) ?>
                                </th>
                            </tr>
                        </tfoot>

                    </table>

                </div>

                <div class="resumen-mayor">

                    <div>
                        <span class="texto-secundario">Saldo inicial</span>

                        <strong>
                            <?= $dineroConSigno($saldoInicialCentavos) ?>
                        </strong>
                    </div>

                    <div>
                        <span class="texto-secundario">Movimientos del período</span>

                        <strong>
                            <?= count($movimientos) ?>
                        </strong>
                    </div>

                    <div>
                        <span class="texto-secundario">Saldo final</span>

                        <strong>
                            <?= $dineroConSigno($saldoFinalCentavos) ?>
                        </strong>
                    </div>

                </div>

            </section>

        <?php elseif ($cuentaId === false || $cuentaId === null): ?>

            <section class="tarjeta">
                <p class="sin-registros">
                    Selecciona una cuenta y un período para consultar
                    su Libro mayor.
                </p>
            </section>

        <?php endif; ?>

    </main>

</div>

<script src="../assets/js/menu.js"></script>

</body>
</html>
