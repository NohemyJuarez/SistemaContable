
<?php
require_once "../config/conexion.php";

$mensaje = "";
$error = "";

$clientes = [];
$productos = [];
$usuarios = [];
$metodos = [];
$impuestos = [];
$historialVentas = [];

try {
    // Cargar información para los formularios
    $clientes = $conexion->query(
        "SELECT id, nombre FROM clientes
         WHERE estado = 'Activo' ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);

    $productos = $conexion->query(
        "SELECT id, codigo, nombre, tipo, precio_venta, existencia
         FROM productos
         WHERE estado = 'Activo'
         ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);

    $usuarios = $conexion->query(
        "SELECT id, nombre FROM usuarios
         WHERE estado = 'Activo' ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);

    $metodos = $conexion->query(
        "SELECT id, nombre FROM metodos_pago
         WHERE estado = 'Activo' ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);

    $impuestos = $conexion->query(
        "SELECT id, nombre, porcentaje FROM impuestos
         WHERE estado = 'Activo' ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Registrar venta
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $cliente_id = filter_input(INPUT_POST, "cliente_id", FILTER_VALIDATE_INT);
        $usuario_id = filter_input(INPUT_POST, "usuario_id", FILTER_VALIDATE_INT);
        $metodo_pago_id = filter_input(INPUT_POST, "metodo_pago_id", FILTER_VALIDATE_INT);
        $tipo_comprobante = trim($_POST["tipo_comprobante"] ?? "");
        $monto_pago = (float)($_POST["monto_pago"] ?? 0);

        $productos_post = $_POST["producto_id"] ?? [];
        $cantidades = $_POST["cantidad"] ?? [];
        $descuentos = $_POST["descuento"] ?? [];
        $impuestos_post = $_POST["impuesto_id"] ?? [];

        if (!$cliente_id || !$usuario_id || !$metodo_pago_id) {
            throw new Exception("Seleccioná el cliente, usuario y método de pago.");
        }

        $tipos_validos = [
            "Factura",
            "Comprobante de crédito fiscal",
            "Comprobante interno"
        ];

        if (!in_array($tipo_comprobante, $tipos_validos, true)) {
            throw new Exception("Seleccioná un tipo de comprobante válido.");
        }

        if (!is_array($productos_post) || count($productos_post) === 0) {
            throw new Exception("Agregá al menos un producto o servicio.");
        }

        if ($monto_pago < 0) {
            throw new Exception("El monto recibido no puede ser negativo.");
        }

        if (
            count($productos_post) !== count($cantidades) ||
            count($productos_post) !== count($descuentos) ||
            count($productos_post) !== count($impuestos_post)
        ) {
            throw new Exception("Los datos del detalle de la venta están incompletos.");
        }

        $conexion->beginTransaction();

        // Verificar cliente
        $verificar = $conexion->prepare(
            "SELECT COUNT(*) FROM clientes
             WHERE id = ? AND estado = 'Activo'"
        );
        $verificar->execute([$cliente_id]);

        if (!$verificar->fetchColumn()) {
            throw new Exception("El cliente seleccionado no está disponible.");
        }

        // Verificar usuario
        $verificar = $conexion->prepare(
            "SELECT COUNT(*) FROM usuarios
             WHERE id = ? AND estado = 'Activo'"
        );
        $verificar->execute([$usuario_id]);

        if (!$verificar->fetchColumn()) {
            throw new Exception("El usuario seleccionado no está disponible.");
        }

        // Verificar método de pago
        $verificar = $conexion->prepare(
            "SELECT COUNT(*) FROM metodos_pago
             WHERE id = ? AND estado = 'Activo'"
        );
        $verificar->execute([$metodo_pago_id]);

        if (!$verificar->fetchColumn()) {
            throw new Exception("El método de pago seleccionado no está disponible.");
        }

        $subtotal_general = 0;
        $impuesto_general = 0;
        $detalles = [];

        foreach ($productos_post as $i => $producto_id) {

            $producto_id = filter_var($producto_id, FILTER_VALIDATE_INT);
            $cantidad = filter_var($cantidades[$i], FILTER_VALIDATE_INT);
            $descuento = (float)$descuentos[$i];

            $impuesto_id = ($impuestos_post[$i] ?? "") !== ""
                ? filter_var($impuestos_post[$i], FILTER_VALIDATE_INT)
                : null;

            if (!$producto_id || !$cantidad || $cantidad < 1 || $descuento < 0) {
                throw new Exception("Revisá los productos, cantidades y descuentos.");
            }

            // Bloquear el producto durante el registro
            $consulta = $conexion->prepare(
                "SELECT id, nombre, tipo, precio_venta, existencia
                 FROM productos
                 WHERE id = ? AND estado = 'Activo'
                 FOR UPDATE"
            );

            $consulta->execute([$producto_id]);
            $producto = $consulta->fetch(PDO::FETCH_ASSOC);

            if (!$producto) {
                throw new Exception("Uno de los productos ya no está disponible.");
            }

            if (
                $producto["tipo"] === "Producto" &&
                $cantidad > (int)$producto["existencia"]
            ) {
                throw new Exception(
                    "Existencias insuficientes para: " . $producto["nombre"]
                );
            }

            $precio = (float)$producto["precio_venta"];
            $subtotal = ($precio * $cantidad) - $descuento;

            if ($subtotal < 0) {
                throw new Exception("El descuento no puede superar el importe de la línea.");
            }

            $porcentaje = 0;

            if ($impuesto_id !== null) {

                if (!$impuesto_id) {
                    throw new Exception("El impuesto seleccionado no es válido.");
                }

                $consulta_impuesto = $conexion->prepare(
                    "SELECT porcentaje FROM impuestos
                     WHERE id = ? AND estado = 'Activo'"
                );

                $consulta_impuesto->execute([$impuesto_id]);
                $dato_impuesto = $consulta_impuesto->fetch(PDO::FETCH_ASSOC);

                if (!$dato_impuesto) {
                    throw new Exception("El impuesto seleccionado no está disponible.");
                }

                $porcentaje = (float)$dato_impuesto["porcentaje"];
            }

            $impuesto_linea = $subtotal * $porcentaje / 100;

            $subtotal_general += $subtotal;
            $impuesto_general += $impuesto_linea;

            $detalles[] = [
                "producto_id" => $producto_id,
                "cantidad" => $cantidad,
                "precio" => $precio,
                "descuento" => $descuento,
                "subtotal" => $subtotal,
                "impuesto_id" => $impuesto_id,
                "porcentaje" => $porcentaje,
                "tipo" => $producto["tipo"]
            ];
        }

        $total = $subtotal_general + $impuesto_general;

        if ($monto_pago > $total) {
            throw new Exception("El monto recibido no puede superar el total de la venta.");
        }

        $estado = ($total > 0 && $monto_pago >= $total)
            ? "Pagada"
            : "Pendiente";

        $numero_venta = "V-" . date("YmdHis") . "-" . random_int(100, 999);

        // Guardar encabezado de venta
        $insertar_venta = $conexion->prepare(
            "INSERT INTO ventas
            (numero_venta, cliente_id, usuario_id, subtotal, impuesto, total, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id"
        );

        $insertar_venta->execute([
            $numero_venta,
            $cliente_id,
            $usuario_id,
            $subtotal_general,
            $impuesto_general,
            $total,
            $estado
        ]);

        $venta_id = $insertar_venta->fetchColumn();

        // Guardar detalle
        $insertar_detalle = $conexion->prepare(
            "INSERT INTO detalle_ventas
            (venta_id, producto_id, cantidad, precio_unitario,
             descuento, subtotal, impuesto_id, porcentaje_impuesto)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        // Actualizar inventario
        $actualizar_stock = $conexion->prepare(
            "UPDATE productos
             SET existencia = existencia - ?
             WHERE id = ?"
        );

        // Registrar movimiento de inventario
        $movimiento_stock = $conexion->prepare(
            "INSERT INTO movimientos_inventario
            (producto_id, tipo_movimiento, cantidad, descripcion, usuario_id)
            VALUES (?, 'Salida', ?, ?, ?)"
        );

        foreach ($detalles as $detalle) {

            $insertar_detalle->execute([
                $venta_id,
                $detalle["producto_id"],
                $detalle["cantidad"],
                $detalle["precio"],
                $detalle["descuento"],
                $detalle["subtotal"],
                $detalle["impuesto_id"],
                $detalle["porcentaje"]
            ]);

            if ($detalle["tipo"] === "Producto") {

                $actualizar_stock->execute([
                    $detalle["cantidad"],
                    $detalle["producto_id"]
                ]);

                $movimiento_stock->execute([
                    $detalle["producto_id"],
                    $detalle["cantidad"],
                    "Salida por venta " . $numero_venta,
                    $usuario_id
                ]);
            }
        }

        // Registrar pago
        if ($monto_pago > 0) {

            $insertar_pago = $conexion->prepare(
                "INSERT INTO pagos (venta_id, metodo_pago_id, monto)
                 VALUES (?, ?, ?)"
            );

            $insertar_pago->execute([
                $venta_id,
                $metodo_pago_id,
                $monto_pago
            ]);
        }

        // Crear comprobante interno
        $numero_comprobante = "C-" . date("YmdHis") . "-" . random_int(100, 999);

        $insertar_comprobante = $conexion->prepare(
            "INSERT INTO comprobantes
            (venta_id, tipo_comprobante, numero_comprobante, estado)
            VALUES (?, ?, ?, 'Emitido')"
        );

        $insertar_comprobante->execute([
            $venta_id,
            $tipo_comprobante,
            $numero_comprobante
        ]);

        $conexion->commit();

        header("Location: ventas.php?registrada=1");
        exit;
    }

} catch (Throwable $e) {

    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $error = $e->getMessage();
}

// Mensaje de éxito
if (isset($_GET["registrada"])) {
    $mensaje = "La venta se registró correctamente.";
}

// Consultar historial de ventas
try {

    $consulta_historial = $conexion->query(
        "SELECT
            v.id,
            v.numero_venta,
            v.fecha_venta,
            v.subtotal,
            v.impuesto,
            v.total,
            v.estado,
            c.nombre AS cliente,
            u.nombre AS responsable
         FROM ventas v
         INNER JOIN clientes c ON v.cliente_id = c.id
         INNER JOIN usuarios u ON v.usuario_id = u.id
         ORDER BY v.fecha_venta DESC, v.id DESC
         LIMIT 50"
    );

    $historialVentas = $consulta_historial->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $error = "No se pudo cargar el historial de ventas: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas - Sistema Contable</title>

    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">

    <style>
        .tabla-ventas {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .tabla-ventas th,
        .tabla-ventas td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        .tabla-ventas input,
        .tabla-ventas select {
            width: 100%;
            min-width: 90px;
            padding: 9px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .resumen-venta {
            max-width: 350px;
            margin: 25px 0 0 auto;
        }

        .resumen-venta div {
            display: flex;
            justify-content: space-between;
            padding: 9px 0;
        }

        .resumen-venta .total {
            border-top: 2px solid #ddd;
            font-size: 20px;
            font-weight: bold;
            padding-top: 15px;
        }

        .mensaje-error {
            background: #ffe8e8;
            color: #a32323;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .mensaje-exito {
            background: #e5f7e9;
            color: #176b32;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .aviso-ventas {
            background: #fff6df;
            color: #805b0b;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .boton-quitar {
            background: #ffe6e6;
            color: #a32323;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
        }

        .tabla-contenedor {
            overflow-x: auto;
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

        .tabla-historial th {
    background: #7136a5;
    color: #ffffff;
    font-weight: bold;
    white-space: nowrap;
    padding: 14px 12px;
    border-bottom: 2px solid #54247f;
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
            <a href="contabilidad.php">Contabilidad</a>
            <a href="reportes.php">Reportes</a>
        </nav>
    </aside>

    <main class="contenido">

        <header class="encabezado">
            <h1>Gestión de Ventas</h1>
            <p>Registro y administración de ventas del negocio.</p>
        </header>

        <?php if ($mensaje): ?>
            <div class="mensaje-exito">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mensaje-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (count($clientes) === 0 || count($productos) === 0): ?>
            <div class="aviso-ventas">
                Para registrar una venta necesitás tener al menos
                un cliente y un producto o servicio activo.
                Podés continuar desarrollando el módulo mientras tanto.
            </div>
        <?php endif; ?>

        <form method="POST" id="formVenta">

            <section class="tarjeta">
                <h2>Información de la venta</h2>

                <label>Cliente</label>
                <select name="cliente_id" required>
                    <option value="">Seleccionar cliente</option>
                    <?php foreach ($clientes as $cliente): ?>
                        <option value="<?= (int)$cliente["id"] ?>">
                            <?= htmlspecialchars($cliente["nombre"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Usuario responsable</label>
                <select name="usuario_id" required>
                    <option value="">Seleccionar usuario</option>
                    <?php foreach ($usuarios as $usuario): ?>
                        <option value="<?= (int)$usuario["id"] ?>">
                            <?= htmlspecialchars($usuario["nombre"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Tipo de comprobante</label>
                <select name="tipo_comprobante" required>
                    <option value="">Seleccionar comprobante</option>
                    <option value="Factura">Factura</option>
                    <option value="Comprobante de crédito fiscal">Comprobante de crédito fiscal</option>
                    <option value="Comprobante interno">Comprobante interno</option>
                </select>

                <label>Método de pago</label>
                <select name="metodo_pago_id" required>
                    <option value="">Seleccionar método</option>
                    <?php foreach ($metodos as $metodo): ?>
                        <option value="<?= (int)$metodo["id"] ?>">
                            <?= htmlspecialchars($metodo["nombre"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </section>

            <section class="tarjeta">
                <h2>Detalle de productos o servicios</h2>

                <div class="tabla-contenedor">
                    <table class="tabla-ventas">
                        <thead>
                            <tr>
                                <th>Producto o servicio</th>
                                <th>Cantidad</th>
                                <th>Descuento ($)</th>
                                <th>Impuesto</th>
                                <th>Subtotal</th>
                                <th>Acción</th>
                            </tr>
                        </thead>

                        <tbody id="filasProductos"></tbody>
                    </table>
                </div>

                <button type="button" id="agregarProducto">
                    + Agregar producto
                </button>

                <div class="resumen-venta">
                    <div>
                        <span>Subtotal:</span>
                        <strong id="subtotalTexto">$0.00</strong>
                    </div>

                    <div>
                        <span>Impuestos:</span>
                        <strong id="impuestoTexto">$0.00</strong>
                    </div>

                    <div class="total">
                        <span>Total:</span>
                        <strong id="totalTexto">$0.00</strong>
                    </div>
                </div>
            </section>

            <section class="tarjeta">
                <h2>Información del pago</h2>

                <label>Monto recibido ($)</label>

                <input
                    type="number"
                    name="monto_pago"
                    id="monto_pago"
                    min="0"
                    step="0.01"
                    value="0"
                    required
                >

                <p>Si el pago es menor que el total, la venta quedará pendiente.</p>

                <button type="submit"
                    <?= (
                        count($clientes) === 0 ||
                        count($productos) === 0 ||
                        count($usuarios) === 0 ||
                        count($metodos) === 0
                    ) ? "disabled" : "" ?>>
                    Registrar venta
                </button>
            </section>

        </form>

        

        <!-- HISTORIAL DE VENTAS -->
        <section class="tarjeta">
            <h2>Historial de ventas</h2>
            <p>Últimas 50 ventas registradas en el sistema.</p>

            <div class="tabla-contenedor">
                <table class="tabla-ventas tabla-historial">
                    <thead>
                        <tr>
                            <th>N.º de venta</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Responsable</th>
                            <th>Subtotal</th>
                            <th>Impuesto</th>
                            <th>Total</th>
                            <th>Estado</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (count($historialVentas) > 0): ?>

                            <?php foreach ($historialVentas as $venta): ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($venta["numero_venta"]) ?>
                                    </td>

                                    <td>
                                        <?= date(
                                            "d/m/Y H:i",
                                            strtotime($venta["fecha_venta"])
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($venta["cliente"]) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($venta["responsable"]) ?>
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
                                        <?php
                                            $claseEstado = "estado-pendiente";

                                            if ($venta["estado"] === "Pagada") {
                                                $claseEstado = "estado-pagada";
                                            } elseif ($venta["estado"] === "Anulada") {
                                                $claseEstado = "estado-anulada";
                                            }
                                        ?>

                                        <span class="estado-venta <?= $claseEstado ?>">
                                            <?= htmlspecialchars($venta["estado"]) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="8" class="sin-registros">
                                    Todavía no hay ventas registradas.
                                </td>
                            </tr>

                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

<script>
const productosDisponibles = <?= json_encode(
    $productos,
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
) ?>;

const impuestosDisponibles = <?= json_encode(
    $impuestos,
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
) ?>;

const filas = document.getElementById("filasProductos");

function escaparHTML(valor) {
    return String(valor ?? "").replace(/[&<>"']/g, caracter => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
    })[caracter]);
}

function agregarFila() {

    const fila = document.createElement("tr");

    const opcionesProductos = productosDisponibles.map(p => `
        <option value="${Number(p.id)}"
            data-precio="${Number(p.precio_venta)}"
            data-tipo="${escaparHTML(p.tipo)}"
            data-existencia="${Number(p.existencia)}">

            ${escaparHTML(p.codigo)} - ${escaparHTML(p.nombre)}

            ${p.tipo === "Producto"
                ? "(Existencia: " + Number(p.existencia) + ")"
                : ""}
        </option>
    `).join("");

    const opcionesImpuestos = `
        <option value="">Sin impuesto</option>

        ${impuestosDisponibles.map(i => `
            <option value="${Number(i.id)}"
                data-porcentaje="${Number(i.porcentaje)}">

                ${escaparHTML(i.nombre)}
                (${Number(i.porcentaje)}%)

            </option>
        `).join("")}
    `;

    fila.innerHTML = `
        <td>
            <select name="producto_id[]" class="producto" required>
                <option value="">Seleccionar</option>
                ${opcionesProductos}
            </select>
        </td>

        <td>
            <input type="number"
                name="cantidad[]"
                class="cantidad"
                min="1"
                value="1"
                required>
        </td>

        <td>
            <input type="number"
                name="descuento[]"
                class="descuento"
                min="0"
                step="0.01"
                value="0"
                required>
        </td>

        <td>
            <select name="impuesto_id[]" class="impuesto">
                ${opcionesImpuestos}
            </select>
        </td>

        <td class="subtotalFila">$0.00</td>

        <td>
            <button type="button" class="boton-quitar eliminar">
                Quitar
            </button>
        </td>
    `;

    filas.appendChild(fila);

    fila.querySelectorAll("input, select").forEach(campo => {
        campo.addEventListener("change", calcularTotales);
        campo.addEventListener("input", calcularTotales);
    });

    fila.querySelector(".eliminar").addEventListener("click", () => {
        fila.remove();
        calcularTotales();
    });

    calcularTotales();
}

function calcularTotales() {

    let subtotalGeneral = 0;
    let impuestoGeneral = 0;

    document.querySelectorAll("#filasProductos tr").forEach(fila => {

        const producto = fila.querySelector(".producto");
        const cantidad = Number(fila.querySelector(".cantidad").value);
        const descuento = Number(fila.querySelector(".descuento").value);
        const impuesto = fila.querySelector(".impuesto");

        const opcionProducto = producto.selectedOptions[0];

        const precio = Number(opcionProducto?.dataset.precio || 0);

        const subtotal = Math.max(
            0,
            (precio * cantidad) - descuento
        );

        const porcentaje = Number(
            impuesto.selectedOptions[0]?.dataset.porcentaje || 0
        );

        const impuestoLinea = subtotal * porcentaje / 100;

        subtotalGeneral += subtotal;
        impuestoGeneral += impuestoLinea;

        fila.querySelector(".subtotalFila").textContent =
            "$" + subtotal.toFixed(2);
    });

    const total = subtotalGeneral + impuestoGeneral;

    document.getElementById("subtotalTexto").textContent =
        "$" + subtotalGeneral.toFixed(2);

    document.getElementById("impuestoTexto").textContent =
        "$" + impuestoGeneral.toFixed(2);

    document.getElementById("totalTexto").textContent =
        "$" + total.toFixed(2);
}

document.getElementById("agregarProducto")
    .addEventListener("click", agregarFila);

document.getElementById("formVenta")
    .addEventListener("submit", function(e) {

        if (filas.children.length === 0) {
            e.preventDefault();
            alert("Agregá al menos un producto o servicio.");
        }
    });

agregarFila();
</script>

<script src="../assets/js/menu.js"></script>

</body>
</html>
