
<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";

requerirAcceso("libro_diario");

$puedeEditar = puedeEditar("libro_diario");
$usuarioSesion = usuarioActual();

$mensaje = "";
$error = "";
$cuentas = [];
$historial = [];
$detallePartida = null;
$movimientosDetalle = [];

$h = static function ($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
};

// Cargar cuentas que pueden recibir movimientos.
try {
    $consulta = $conexion->query(
        "SELECT id, codigo, nombre, tipo, naturaleza
         FROM cuentas_contables
         WHERE estado = 'Activa'
           AND permite_movimientos = TRUE
         ORDER BY codigo"
    );

    $cuentas = $consulta->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log("Libro diario - cuentas: " . $e->getMessage());
    $error = "No se pudieron cargar las cuentas contables.";
}

// Procesar formularios.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    requerirEdicion("libro_diario");
    verificarCsrf();

    $accion = $_POST["accion"] ?? "";

    try {

        // Crear una partida manual.
        if ($accion === "crear") {

            $fecha = trim($_POST["fecha"] ?? "");
            $descripcion = trim($_POST["descripcion"] ?? "");
            $estadoDestino = $_POST["estado_destino"] ?? "Borrador";

            $cuentasPost = $_POST["cuenta_id"] ?? [];
            $descripcionesPost = $_POST["descripcion_movimiento"] ?? [];
            $debesPost = $_POST["debe"] ?? [];
            $haberesPost = $_POST["haber"] ?? [];

            if (
                !in_array($estadoDestino, ["Borrador", "Contabilizada"], true)
            ) {
                throw new Exception("El estado solicitado no es válido.");
            }

            $fechaValida = DateTime::createFromFormat("!Y-m-d", $fecha);

            if (
                !$fechaValida ||
                $fechaValida->format("Y-m-d") !== $fecha
            ) {
                throw new Exception("Selecciona una fecha válida.");
            }

            if ($descripcion === "" || mb_strlen($descripcion) > 2000) {
                throw new Exception("Escribe una descripción válida para la partida.");
            }

            if (
                !is_array($cuentasPost) ||
                !is_array($descripcionesPost) ||
                !is_array($debesPost) ||
                !is_array($haberesPost)
            ) {
                throw new Exception("Los movimientos enviados no son válidos.");
            }

            $cantidadMovimientos = count($cuentasPost);

            if (
                $cantidadMovimientos < 2 ||
                $cantidadMovimientos !== count($descripcionesPost) ||
                $cantidadMovimientos !== count($debesPost) ||
                $cantidadMovimientos !== count($haberesPost)
            ) {
                throw new Exception(
                    "La partida debe contener al menos dos movimientos completos."
                );
            }

            // Preparar cuentas permitidas.
            $cuentasPermitidas = [];

            foreach ($cuentas as $cuenta) {
                $cuentasPermitidas[(int)$cuenta["id"]] = $cuenta;
            }

            $movimientos = [];
            $totalDebeCentavos = 0;
            $totalHaberCentavos = 0;

            foreach ($cuentasPost as $i => $cuentaPost) {

                $cuentaId = filter_var(
                    $cuentaPost,
                    FILTER_VALIDATE_INT
                );

                if (
                    !$cuentaId ||
                    !isset($cuentasPermitidas[$cuentaId])
                ) {
                    throw new Exception(
                        "Selecciona una cuenta activa que permita movimientos."
                    );
                }

                $descripcionMovimiento = trim(
                    $descripcionesPost[$i] ?? ""
                );

                if (mb_strlen($descripcionMovimiento) > 500) {
                    throw new Exception(
                        "La descripción de un movimiento es demasiado larga."
                    );
                }

                $debeTexto = trim((string)$debesPost[$i]);
                $haberTexto = trim((string)$haberesPost[$i]);

                if (
                    ($debeTexto !== "" && !is_numeric($debeTexto)) ||
                    ($haberTexto !== "" && !is_numeric($haberTexto))
                ) {
                    throw new Exception(
                        "Los importes del debe y haber deben ser numéricos."
                    );
                }

                $debe = $debeTexto === "" ? 0 : (float)$debeTexto;
                $haber = $haberTexto === "" ? 0 : (float)$haberTexto;

                if (
                    !is_finite($debe) ||
                    !is_finite($haber) ||
                    $debe < 0 ||
                    $haber < 0 ||
                    $debe > 9999999999.99 ||
                    $haber > 9999999999.99
                ) {
                    throw new Exception("Uno de los importes no es válido.");
                }

                $debeCentavos = (int)round($debe * 100);
                $haberCentavos = (int)round($haber * 100);

                // Cada movimiento debe pertenecer al debe o al haber.
                if (
                    ($debeCentavos > 0 && $haberCentavos > 0) ||
                    ($debeCentavos === 0 && $haberCentavos === 0)
                ) {
                    throw new Exception(
                        "Cada movimiento debe tener un importe en el debe o en el haber, pero no en ambos."
                    );
                }

                $totalDebeCentavos += $debeCentavos;
                $totalHaberCentavos += $haberCentavos;

                $movimientos[] = [
                    "cuenta_id" => $cuentaId,
                    "descripcion" => $descripcionMovimiento,
                    "debe" => number_format(
                        $debeCentavos / 100,
                        2,
                        ".",
                        ""
                    ),
                    "haber" => number_format(
                        $haberCentavos / 100,
                        2,
                        ".",
                        ""
                    ),
                ];
            }

            if (
                $estadoDestino === "Contabilizada" &&
                (
                    $totalDebeCentavos <= 0 ||
                    $totalDebeCentavos !== $totalHaberCentavos
                )
            ) {
                throw new Exception(
                    "No se puede contabilizar la partida porque el debe y el haber no están equilibrados."
                );
            }

            $conexion->beginTransaction();

            // Número único de partida.
            $numeroPartida =
                "PD-" .
                date("YmdHis") .
                "-" .
                random_int(100, 999);

            // Guardar encabezado.
            $insertarPartida = $conexion->prepare(
                "INSERT INTO partidas_diario
                (
                    numero_partida,
                    fecha,
                    descripcion,
                    tipo_origen,
                    estado,
                    usuario_id
                )
                VALUES (?, ?, ?, 'Manual', ?, ?)
                RETURNING id"
            );

            $insertarPartida->execute([
                $numeroPartida,
                $fecha,
                $descripcion,
                $estadoDestino,
                (int)$usuarioSesion["id"]
            ]);

            $partidaId = $insertarPartida->fetchColumn();

            // Guardar movimientos.
            $insertarMovimiento = $conexion->prepare(
                "INSERT INTO movimientos_diario
                (
                    partida_id,
                    cuenta_id,
                    descripcion,
                    debe,
                    haber
                )
                VALUES (?, ?, ?, ?, ?)"
            );

            foreach ($movimientos as $movimiento) {

                $insertarMovimiento->execute([
                    $partidaId,
                    $movimiento["cuenta_id"],
                    $movimiento["descripcion"] !== ""
                        ? $movimiento["descripcion"]
                        : null,
                    $movimiento["debe"],
                    $movimiento["haber"]
                ]);
            }

            $conexion->commit();

            $textoEstado = $estadoDestino === "Contabilizada"
                ? "contabilizada"
                : "guardada como borrador";

            header(
                "Location: libro_diario.php?guardada=1&estado=" .
                urlencode($textoEstado)
            );
            exit;

        // Contabilizar un borrador existente.
        } elseif ($accion === "contabilizar") {

            $partidaId = filter_var(
                $_POST["partida_id"] ?? "",
                FILTER_VALIDATE_INT
            );

            if (!$partidaId) {
                throw new Exception("La partida seleccionada no es válida.");
            }

            $conexion->beginTransaction();

            $consulta = $conexion->prepare(
                "SELECT id, estado
                 FROM partidas_diario
                 WHERE id = ?
                 FOR UPDATE"
            );

            $consulta->execute([$partidaId]);
            $partida = $consulta->fetch(PDO::FETCH_ASSOC);

            if (!$partida || $partida["estado"] !== "Borrador") {
                throw new Exception(
                    "Solo se pueden contabilizar partidas que estén en borrador."
                );
            }

            $consultaTotales = $conexion->prepare(
                "SELECT
                    COUNT(*) AS cantidad,
                    COALESCE(SUM(debe), 0) AS total_debe,
                    COALESCE(SUM(haber), 0) AS total_haber
                 FROM movimientos_diario
                 WHERE partida_id = ?"
            );

            $consultaTotales->execute([$partidaId]);
            $totales = $consultaTotales->fetch(PDO::FETCH_ASSOC);

            $debeCentavos = (int)round(
                (float)$totales["total_debe"] * 100
            );

            $haberCentavos = (int)round(
                (float)$totales["total_haber"] * 100
            );

            if (
                (int)$totales["cantidad"] < 2 ||
                $debeCentavos <= 0 ||
                $debeCentavos !== $haberCentavos
            ) {
                throw new Exception(
                    "La partida no está equilibrada. Revisa sus movimientos."
                );
            }

            $actualizar = $conexion->prepare(
                "UPDATE partidas_diario
                 SET estado = 'Contabilizada'
                 WHERE id = ? AND estado = 'Borrador'"
            );

            $actualizar->execute([$partidaId]);

            if ($actualizar->rowCount() !== 1) {
                throw new Exception(
                    "No se pudo contabilizar la partida."
                );
            }

            $conexion->commit();

            header("Location: libro_diario.php?contabilizada=1");
            exit;

        } else {
            throw new Exception("La acción solicitada no es válida.");
        }

    } catch (Throwable $e) {

        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }

        error_log("Libro diario: " . $e->getMessage());

        if ($e instanceof PDOException) {
            $error = "No se pudo guardar la operación. Verifica los datos e inténtalo de nuevo.";
        } else {
            $error = $e->getMessage();
        }
    }
}

// Mensajes de confirmación.
if (isset($_GET["guardada"])) {
    $mensaje = "La partida fue " .
        $h($_GET["estado"] ?? "guardada correctamente") .
        ".";
}

if (isset($_GET["contabilizada"])) {
    $mensaje = "La partida fue contabilizada correctamente.";
}

// Consultar detalle de una partida.
$idDetalle = filter_input(INPUT_GET, "ver", FILTER_VALIDATE_INT);

if ($idDetalle) {

    $consulta = $conexion->prepare(
        "SELECT
            p.*,
            u.nombre AS responsable
         FROM partidas_diario p
         INNER JOIN usuarios u ON u.id = p.usuario_id
         WHERE p.id = ?"
    );

    $consulta->execute([$idDetalle]);
    $detallePartida = $consulta->fetch(PDO::FETCH_ASSOC);

    if ($detallePartida) {

        $consultaMovimientos = $conexion->prepare(
            "SELECT
                m.id,
                m.descripcion,
                m.debe,
                m.haber,
                c.codigo,
                c.nombre AS cuenta,
                c.tipo,
                c.naturaleza
             FROM movimientos_diario m
             INNER JOIN cuentas_contables c ON c.id = m.cuenta_id
             WHERE m.partida_id = ?
             ORDER BY m.id"
        );

        $consultaMovimientos->execute([$idDetalle]);

        $movimientosDetalle = $consultaMovimientos->fetchAll(
            PDO::FETCH_ASSOC
        );
    }
}

// Filtros del historial.
$filtroEstado = $_GET["estado_filtro"] ?? "";
$fechaDesde = $_GET["desde"] ?? "";
$fechaHasta = $_GET["hasta"] ?? "";

$estadosValidos = ["Borrador", "Contabilizada", "Anulada"];

if (!in_array($filtroEstado, $estadosValidos, true)) {
    $filtroEstado = "";
}

$sqlHistorial = "
    SELECT
        p.id,
        p.numero_partida,
        p.fecha,
        p.descripcion,
        p.tipo_origen,
        p.estado,
        u.nombre AS responsable,
        COALESCE(SUM(m.debe), 0) AS total_debe,
        COALESCE(SUM(m.haber), 0) AS total_haber
    FROM partidas_diario p
    INNER JOIN usuarios u ON u.id = p.usuario_id
    LEFT JOIN movimientos_diario m ON m.partida_id = p.id
    WHERE 1 = 1
";

$parametros = [];

if ($filtroEstado !== "") {
    $sqlHistorial .= " AND p.estado = :estado";
    $parametros[":estado"] = $filtroEstado;
}

if (
    $fechaDesde !== "" &&
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)
) {
    $sqlHistorial .= " AND p.fecha >= :desde";
    $parametros[":desde"] = $fechaDesde;
}

if (
    $fechaHasta !== "" &&
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)
) {
    $sqlHistorial .= " AND p.fecha <= :hasta";
    $parametros[":hasta"] = $fechaHasta;
}

$sqlHistorial .= "
    GROUP BY
        p.id,
        p.numero_partida,
        p.fecha,
        p.descripcion,
        p.tipo_origen,
        p.estado,
        u.nombre
    ORDER BY p.fecha DESC, p.id DESC
    LIMIT 100
";

try {
    $consultaHistorial = $conexion->prepare($sqlHistorial);
    $consultaHistorial->execute($parametros);

    $historial = $consultaHistorial->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log("Libro diario - historial: " . $e->getMessage());
    $error = "No se pudo cargar el historial de partidas.";
}

$csrf = tokenCsrf();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Libro diario - Sistema Contable</title>

    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">

    <style>
        .diario-contenedor {
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        .diario-contenedor .tarjeta {
            overflow: hidden;
        }

        .diario-contenedor label {
            display: block;
            font-weight: 600;
            margin: 12px 0 6px;
        }

        .diario-contenedor input,
        .diario-contenedor select,
        .diario-contenedor textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 7px;
            box-sizing: border-box;
        }

        .campos-partida {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .campo-completo {
            grid-column: 1 / -1;
        }

        .tabla-contenedor {
            width: 100%;
            overflow-x: auto;
        }

        .tabla-diario {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
            margin-top: 15px;
        }

        .tabla-diario th,
        .tabla-diario td {
            padding: 11px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        .tabla-diario th {
            background: #7136a5;
            color: white;
            white-space: nowrap;
        }

        .tabla-diario input,
        .tabla-diario select {
            min-width: 100px;
        }

        .tabla-diario .campo-descripcion {
            min-width: 180px;
        }

        .boton-quitar {
            background: #ffe6e6;
            color: #a32323;
            border: none;
            padding: 8px 11px;
            border-radius: 6px;
            cursor: pointer;
        }

        .acciones-diario {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .resumen-diario {
            max-width: 380px;
            margin: 20px 0 0 auto;
        }

        .resumen-diario div {
            display: flex;
            justify-content: space-between;
            padding: 9px 0;
        }

        .resumen-diario .total {
            border-top: 2px solid #ddd;
            font-size: 18px;
            font-weight: bold;
        }

        .mensaje-diario {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .mensaje-diario.error {
            background: #ffe8e8;
            color: #a32323;
        }

        .mensaje-diario.exito {
            background: #e5f7e9;
            color: #176b32;
        }

        .estado-partida {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .estado-borrador {
            background: #fff2d6;
            color: #805b0b;
        }

        .estado-contabilizada {
            background: #e2f7e8;
            color: #176b32;
        }

        .estado-anulada {
            background: #ffe6e6;
            color: #a32323;
        }

        .filtros-diario {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            align-items: end;
        }

        .detalle-partida {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin: 15px 0;
        }

        .detalle-partida div {
            padding: 12px;
            background: #f8f6fa;
            border-radius: 7px;
        }

        .texto-secundario {
            color: #777;
        }

        .sin-registros {
            text-align: center;
            padding: 25px;
            color: #777;
        }

        @media (max-width: 700px) {
            .campos-partida,
            .filtros-diario,
            .detalle-partida {
                grid-template-columns: 1fr;
            }

            .campo-completo {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>

<div class="layout">

    <?php require "../includes/menu.php"; ?>

    <main class="contenido diario-contenedor">

        <header class="encabezado">
            <div>
                <h1>Libro diario</h1>
                <p>Registro cronológico de las operaciones contables.</p>
            </div>
        </header>

        <?php if ($mensaje !== ""): ?>
            <div class="mensaje-diario exito">
                <?= $h($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="mensaje-diario error">
                <?= $h($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($idDetalle && $detallePartida): ?>

            <section class="tarjeta">
                <h2>Detalle de partida</h2>

                <div class="detalle-partida">
                    <div>
                        <strong>Número:</strong><br>
                        <?= $h($detallePartida["numero_partida"]) ?>
                    </div>

                    <div>
                        <strong>Fecha:</strong><br>
                        <?= date("d/m/Y", strtotime($detallePartida["fecha"])) ?>
                    </div>

                    <div>
                        <strong>Estado:</strong><br>
                        <?= $h($detallePartida["estado"]) ?>
                    </div>

                    <div>
                        <strong>Origen:</strong><br>
                        <?= $h($detallePartida["tipo_origen"]) ?>
                    </div>

                    <div>
                        <strong>Responsable:</strong><br>
                        <?= $h($detallePartida["responsable"]) ?>
                    </div>

                    <div class="campo-completo">
                        <strong>Descripción:</strong><br>
                        <?= nl2br($h($detallePartida["descripcion"])) ?>
                    </div>
                </div>

                <div class="tabla-contenedor">
                    <table class="tabla-diario">
                        <thead>
                            <tr>
                                <th>Cuenta</th>
                                <th>Descripción del movimiento</th>
                                <th>Debe</th>
                                <th>Haber</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php
                            $totalDebe = 0;
                            $totalHaber = 0;
                            ?>

                            <?php foreach ($movimientosDetalle as $movimiento): ?>
                                <?php
                                $totalDebe += (float)$movimiento["debe"];
                                $totalHaber += (float)$movimiento["haber"];
                                ?>

                                <tr>
                                    <td>
                                        <?= $h($movimiento["codigo"]) ?>
                                        - <?= $h($movimiento["cuenta"]) ?>
                                    </td>

                                    <td>
                                        <?= $h($movimiento["descripcion"] ?? "—") ?>
                                    </td>

                                    <td>
                                        $<?= number_format(
                                            (float)$movimiento["debe"],
                                            2
                                        ) ?>
                                    </td>

                                    <td>
                                        $<?= number_format(
                                            (float)$movimiento["haber"],
                                            2
                                        ) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                        <tfoot>
                            <tr>
                                <th colspan="2">Totales</th>
                                <th>$<?= number_format($totalDebe, 2) ?></th>
                                <th>$<?= number_format($totalHaber, 2) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if (
                    $puedeEditar &&
                    $detallePartida["estado"] === "Borrador"
                ): ?>
                    <form method="POST" class="acciones-diario">
                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="accion" value="contabilizar">
                        <input type="hidden" name="partida_id" value="<?= (int)$detallePartida["id"] ?>">

                        <button type="submit"
                            onclick="return confirm('¿Deseas contabilizar esta partida? Esta acción no se podrá deshacer desde el módulo.');">
                            Contabilizar partida
                        </button>
                    </form>
                <?php endif; ?>

                <div class="acciones-diario">
                    <a href="libro_diario.php" class="boton-secundario">
                        Volver al libro diario
                    </a>
                </div>
            </section>

        <?php elseif ($idDetalle): ?>

            <section class="tarjeta">
                <p>No se encontró la partida solicitada.</p>
                <a href="libro_diario.php">Volver al libro diario</a>
            </section>

        <?php endif; ?>

        <?php if ($puedeEditar && !$idDetalle): ?>

            <section class="tarjeta">
                <h2>Registrar partida contable</h2>

                <?php if (count($cuentas) < 2): ?>

                    <p>
                        Necesitas al menos dos cuentas activas que permitan
                        movimientos para registrar una partida.
                    </p>

                    <a href="cuentas_contables.php">
                        Ir al catálogo de cuentas
                    </a>

                <?php else: ?>

                    <form method="POST" id="formPartida">

                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="accion" value="crear">

                        <div class="campos-partida">

                            <div>
                                <label for="fecha">Fecha de la partida *</label>

                                <input
                                    type="date"
                                    id="fecha"
                                    name="fecha"
                                    value="<?= date("Y-m-d") ?>"
                                    required
                                >
                            </div>

                            <div>
                                <label>Responsable</label>

                                <input
                                    type="text"
                                    value="<?= $h($usuarioSesion["nombre"]) ?>"
                                    disabled
                                >
                            </div>

                            <div class="campo-completo">
                                <label for="descripcion">Descripción de la partida *</label>

                                <textarea
                                    id="descripcion"
                                    name="descripcion"
                                    rows="3"
                                    maxlength="2000"
                                    required
                                    placeholder="Describe la operación contable..."
                                ></textarea>
                            </div>

                        </div>

                        <h3>Movimientos contables</h3>

                        <p class="texto-secundario">
                            Cada movimiento debe registrarse en el debe o en el haber.
                            Una misma línea no puede contener ambos importes.
                        </p>

                        <div class="tabla-contenedor">
                            <table class="tabla-diario">
                                <thead>
                                    <tr>
                                        <th>Cuenta contable</th>
                                        <th>Descripción</th>
                                        <th>Debe ($)</th>
                                        <th>Haber ($)</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>

                                <tbody id="filasMovimientos"></tbody>
                            </table>
                        </div>

                        <button type="button" id="agregarMovimiento">
                            + Agregar movimiento
                        </button>

                        <div class="resumen-diario">

                            <div>
                                <span>Total debe:</span>
                                <strong id="totalDebe">$0.00</strong>
                            </div>

                            <div>
                                <span>Total haber:</span>
                                <strong id="totalHaber">$0.00</strong>
                            </div>

                            <div class="total">
                                <span>Diferencia:</span>
                                <strong id="diferencia">$0.00</strong>
                            </div>

                            <p id="estadoEquilibrio" class="texto-secundario">
                                Pendiente de completar.
                            </p>

                        </div>

                        <div class="acciones-diario">

                            <button
                                type="submit"
                                name="estado_destino"
                                value="Borrador"
                            >
                                Guardar como borrador
                            </button>

                            <button
                                type="submit"
                                name="estado_destino"
                                value="Contabilizada"
                                id="botonContabilizar"
                            >
                                Guardar y contabilizar
                            </button>

                        </div>

                    </form>

                <?php endif; ?>
            </section>

        <?php endif; ?>

        <?php if (!$idDetalle): ?>

            <section class="tarjeta">

                <h2>Historial de partidas</h2>
                <p>Últimas 100 partidas registradas.</p>

                <form method="GET" class="filtros-diario">

                    <div>
                        <label for="desde">Desde</label>
                        <input
                            type="date"
                            name="desde"
                            id="desde"
                            value="<?= $h($fechaDesde) ?>"
                        >
                    </div>

                    <div>
                        <label for="hasta">Hasta</label>
                        <input
                            type="date"
                            name="hasta"
                            id="hasta"
                            value="<?= $h($fechaHasta) ?>"
                        >
                    </div>

                    <div>
                        <label for="estado_filtro">Estado</label>

                        <select name="estado_filtro" id="estado_filtro">
                            <option value="">Todos</option>

                            <?php foreach ($estadosValidos as $estado): ?>
                                <option
                                    value="<?= $h($estado) ?>"
                                    <?= $filtroEstado === $estado ? "selected" : "" ?>
                                >
                                    <?= $h($estado) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="acciones-diario">
                        <button type="submit">Filtrar</button>
                        <a href="libro_diario.php" class="boton-secundario">
                            Limpiar filtros
                        </a>
                    </div>

                </form>

                <div class="tabla-contenedor">

                    <table class="tabla-diario">

                        <thead>
                            <tr>
                                <th>N.º de partida</th>
                                <th>Fecha</th>
                                <th>Descripción</th>
                                <th>Responsable</th>
                                <th>Debe</th>
                                <th>Haber</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if (count($historial) > 0): ?>

                                <?php foreach ($historial as $partida): ?>

                                    <?php
                                    $claseEstado = "estado-borrador";

                                    if ($partida["estado"] === "Contabilizada") {
                                        $claseEstado = "estado-contabilizada";
                                    } elseif ($partida["estado"] === "Anulada") {
                                        $claseEstado = "estado-anulada";
                                    }
                                    ?>

                                    <tr>

                                        <td>
                                            <?= $h($partida["numero_partida"]) ?>
                                        </td>

                                        <td>
                                            <?= date(
                                                "d/m/Y",
                                                strtotime($partida["fecha"])
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= $h($partida["descripcion"]) ?>
                                        </td>

                                        <td>
                                            <?= $h($partida["responsable"]) ?>
                                        </td>

                                        <td>
                                            $<?= number_format(
                                                (float)$partida["total_debe"],
                                                2
                                            ) ?>
                                        </td>

                                        <td>
                                            $<?= number_format(
                                                (float)$partida["total_haber"],
                                                2
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="estado-partida <?= $claseEstado ?>">
                                                <?= $h($partida["estado"]) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <a href="libro_diario.php?ver=<?= (int)$partida["id"] ?>">
                                                Ver detalle
                                            </a>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="8" class="sin-registros">
                                        Todavía no hay partidas registradas.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        <?php endif; ?>

    </main>

</div>

<?php if ($puedeEditar && !$idDetalle && count($cuentas) >= 2): ?>

<script>
const cuentasDisponibles = <?= json_encode(
    $cuentas,
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
) ?>;

const filasMovimientos = document.getElementById("filasMovimientos");

function escaparHTML(valor) {
    return String(valor ?? "").replace(/[&<>"']/g, caracter => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
    })[caracter]);
}

function agregarMovimiento() {

    const fila = document.createElement("tr");

    const opcionesCuentas = cuentasDisponibles.map(cuenta => `
        <option value="${Number(cuenta.id)}">
            ${escaparHTML(cuenta.codigo)} -
            ${escaparHTML(cuenta.nombre)}
        </option>
    `).join("");

    fila.innerHTML = `
        <td>
            <select name="cuenta_id[]" required>
                <option value="">Seleccionar cuenta</option>
                ${opcionesCuentas}
            </select>
        </td>

        <td>
            <input
                type="text"
                name="descripcion_movimiento[]"
                class="campo-descripcion"
                maxlength="500"
                placeholder="Descripción opcional"
            >
        </td>

        <td>
            <input
                type="number"
                name="debe[]"
                class="campo-debe"
                min="0"
                max="9999999999.99"
                step="0.01"
                placeholder="0.00"
            >
        </td>

        <td>
            <input
                type="number"
                name="haber[]"
                class="campo-haber"
                min="0"
                max="9999999999.99"
                step="0.01"
                placeholder="0.00"
            >
        </td>

        <td>
            <button type="button" class="boton-quitar">
                Quitar
            </button>
        </td>
    `;

    filasMovimientos.appendChild(fila);

    fila.querySelectorAll("input, select").forEach(campo => {
        campo.addEventListener("input", calcularTotales);
        campo.addEventListener("change", calcularTotales);
    });

    fila.querySelector(".boton-quitar").addEventListener("click", () => {
        fila.remove();
        calcularTotales();
    });

    calcularTotales();
}

function calcularTotales() {

    let debeCentavos = 0;
    let haberCentavos = 0;

    document.querySelectorAll("#filasMovimientos tr").forEach(fila => {

        const debe = Number(fila.querySelector(".campo-debe").value || 0);
        const haber = Number(fila.querySelector(".campo-haber").value || 0);

        debeCentavos += Math.round(debe * 100);
        haberCentavos += Math.round(haber * 100);
    });

    const diferenciaCentavos = Math.abs(
        debeCentavos - haberCentavos
    );

    document.getElementById("totalDebe").textContent =
        "$" + (debeCentavos / 100).toFixed(2);

    document.getElementById("totalHaber").textContent =
        "$" + (haberCentavos / 100).toFixed(2);

    document.getElementById("diferencia").textContent =
        "$" + (diferenciaCentavos / 100).toFixed(2);

    const estado = document.getElementById("estadoEquilibrio");

    if (
        debeCentavos > 0 &&
        debeCentavos === haberCentavos
    ) {
        estado.textContent = "La partida está equilibrada.";
    } else {
        estado.textContent = "La partida todavía no está equilibrada.";
    }
}

document.getElementById("agregarMovimiento")
    .addEventListener("click", agregarMovimiento);

document.getElementById("formPartida")
    .addEventListener("submit", function(event) {

        const boton = event.submitter;
        const filas = document.querySelectorAll("#filasMovimientos tr");

        if (filas.length < 2) {
            event.preventDefault();
            alert("Agrega al menos dos movimientos.");
            return;
        }

        if (boton?.value === "Contabilizada") {

            let debeCentavos = 0;
            let haberCentavos = 0;

            filas.forEach(fila => {
                const debe = Number(
                    fila.querySelector(".campo-debe").value || 0
                );

                const haber = Number(
                    fila.querySelector(".campo-haber").value || 0
                );

                debeCentavos += Math.round(debe * 100);
                haberCentavos += Math.round(haber * 100);
            });

            if (
                debeCentavos <= 0 ||
                debeCentavos !== haberCentavos
            ) {
                event.preventDefault();
                alert(
                    "No puedes contabilizar la partida. " +
                    "El debe y el haber deben ser iguales."
                );
                return;
            }

            if (!confirm("¿Deseas guardar y contabilizar esta partida?")) {
                event.preventDefault();
            }
        }
    });

// Iniciar con dos movimientos.
agregarMovimiento();
agregarMovimiento();
</script>

<?php endif; ?>

<script src="../assets/js/menu.js"></script>

</body>
</html>
