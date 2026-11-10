<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";

requerirAcceso("compras");
$puedeRegistrar = puedeEditar("compras");
$usuarioSesion = usuarioActual();

$mensaje = "";
$error = "";

$proveedores = [];
$productos = [];
$impuestos = [];
$compras = [];

try {
    // Cargar proveedores activos
    $proveedores = $conexion->query(
        "SELECT id, nombre, nit
         FROM proveedores
         WHERE estado = 'Activo'
         ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Cargar productos activos (no servicios)
    $productos = $conexion->query(
        "SELECT id, codigo, nombre, costo, existencia
         FROM productos
         WHERE estado = 'Activo' AND tipo = 'Producto'
         ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Cargar impuestos activos
    $impuestos = $conexion->query(
        "SELECT id, nombre, porcentaje
         FROM impuestos
         WHERE estado = 'Activo'
         ORDER BY nombre"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Registrar compra
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        // Solo los roles con permiso de registro pueden guardar compras
        requerirEdicion("compras");

        $proveedor_id = filter_input(INPUT_POST, "proveedor_id", FILTER_VALIDATE_INT);

        // El responsable es el usuario que inició sesión
        $usuario_id = (int)$usuarioSesion["id"];

        $productos_post = $_POST["producto_id"] ?? [];
        $cantidades = $_POST["cantidad"] ?? [];
        $costos = $_POST["costo_unitario"] ?? [];
        $impuestos_post = $_POST["impuesto_id"] ?? [];

        if (!$proveedor_id) {
            throw new Exception("Seleccioná el proveedor.");
        }

        if (!is_array($productos_post) || count($productos_post) === 0) {
            throw new Exception("Agregá al menos un producto a la compra.");
        }

        if (
            count($productos_post) !== count($cantidades) ||
            count($productos_post) !== count($costos) ||
            count($productos_post) !== count($impuestos_post)
        ) {
            throw new Exception("Los datos del detalle de compra están incompletos.");
        }

        $conexion->beginTransaction();

        // Verificar proveedor activo
        $verificar = $conexion->prepare(
            "SELECT COUNT(*) FROM proveedores
             WHERE id = ? AND estado = 'Activo'"
        );
        $verificar->execute([$proveedor_id]);

        if (!$verificar->fetchColumn()) {
            throw new Exception("El proveedor seleccionado no está disponible.");
        }

        // Verificar usuario activo
        $verificar = $conexion->prepare(
            "SELECT COUNT(*) FROM usuarios
             WHERE id = ? AND estado = 'Activo'"
        );
        $verificar->execute([$usuario_id]);

        if (!$verificar->fetchColumn()) {
            throw new Exception("Tu usuario no está activo.");
        }

        $subtotal_general = 0;
        $impuesto_general = 0;
        $detalles = [];

        foreach ($productos_post as $i => $producto_id) {

            $producto_id = filter_var($producto_id, FILTER_VALIDATE_INT);
            $cantidad = filter_var($cantidades[$i], FILTER_VALIDATE_INT);
            $costo = filter_var($costos[$i], FILTER_VALIDATE_FLOAT);

            $impuesto_id = ($impuestos_post[$i] ?? "") !== ""
                ? filter_var($impuestos_post[$i], FILTER_VALIDATE_INT)
                : null;

            if (
                !$producto_id ||
                !$cantidad ||
                $cantidad < 1 ||
                $costo === false ||
                $costo < 0
            ) {
                throw new Exception("Revisá los productos, cantidades y costos.");
            }

            // Bloquear producto durante el registro
            $consulta = $conexion->prepare(
                "SELECT id, nombre, tipo, existencia
                 FROM productos
                 WHERE id = ? AND estado = 'Activo'
                 FOR UPDATE"
            );
            $consulta->execute([$producto_id]);
            $producto = $consulta->fetch(PDO::FETCH_ASSOC);

            if (!$producto || $producto["tipo"] !== "Producto") {
                throw new Exception("Uno de los productos seleccionados no está disponible.");
            }

            $subtotal = $cantidad * $costo;

            $porcentaje = 0;

            if ($impuesto_id !== null) {

                if (!$impuesto_id) {
                    throw new Exception("El impuesto seleccionado no es válido.");
                }

                $consulta_impuesto = $conexion->prepare(
                    "SELECT porcentaje
                     FROM impuestos
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
                "costo" => $costo,
                "subtotal" => $subtotal,
                "impuesto_id" => $impuesto_id,
                "porcentaje" => $porcentaje
            ];
        }

        $total = $subtotal_general + $impuesto_general;

        // Generar número de compra
        $numero_compra = "CO-" . date("YmdHis") . "-" . random_int(100, 999);

        // Guardar encabezado
        $insertar_compra = $conexion->prepare(
            "INSERT INTO compras
            (numero_compra, proveedor_id, usuario_id, subtotal, impuesto, total, estado)
            VALUES (?, ?, ?, ?, ?, ?, 'Pendiente')
            RETURNING id"
        );

        $insertar_compra->execute([
            $numero_compra,
            $proveedor_id,
            $usuario_id,
            $subtotal_general,
            $impuesto_general,
            $total
        ]);

        $compra_id = $insertar_compra->fetchColumn();

        // Preparar consultas del detalle e inventario
        $insertar_detalle = $conexion->prepare(
            "INSERT INTO detalle_compras
            (compra_id, producto_id, cantidad, costo_unitario,
             subtotal, impuesto_id, porcentaje_impuesto)
            VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $actualizar_producto = $conexion->prepare(
            "UPDATE productos
             SET existencia = existencia + ?, costo = ?
             WHERE id = ?"
        );

        $registrar_movimiento = $conexion->prepare(
            "INSERT INTO movimientos_inventario
            (producto_id, tipo_movimiento, cantidad, descripcion, usuario_id)
            VALUES (?, 'Entrada', ?, ?, ?)"
        );

        foreach ($detalles as $detalle) {

            $insertar_detalle->execute([
                $compra_id,
                $detalle["producto_id"],
                $detalle["cantidad"],
                $detalle["costo"],
                $detalle["subtotal"],
                $detalle["impuesto_id"],
                $detalle["porcentaje"]
            ]);

            // Aumentar existencias y actualizar costo del producto
            $actualizar_producto->execute([
                $detalle["cantidad"],
                $detalle["costo"],
                $detalle["producto_id"]
            ]);

            // Registrar entrada en el historial de inventario
            $registrar_movimiento->execute([
                $detalle["producto_id"],
                $detalle["cantidad"],
                "Entrada por compra " . $numero_compra,
                $usuario_id
            ]);
        }

        $conexion->commit();

        header("Location: compras.php?registrada=1");
        exit;
    }

    // Consultar compras registradas
    $compras = $conexion->query(
        "SELECT
            c.id,
            c.numero_compra,
            c.fecha_compra,
            c.subtotal,
            c.impuesto,
            c.total,
            c.estado,
            p.nombre AS proveedor
         FROM compras c
         INNER JOIN proveedores p ON c.proveedor_id = p.id
         ORDER BY c.fecha_compra DESC
         LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $error = $e->getMessage();
}

if (isset($_GET["registrada"])) {
    $mensaje = "La compra se registró correctamente y se actualizaron las existencias.";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compras - Sistema Contable</title>

    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">

    <style>
        .tabla-compras {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .tabla-compras th,
        .tabla-compras td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        .tabla-compras input,
        .tabla-compras select {
            width: 100%;
            min-width: 100px;
            padding: 9px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .tabla-contenedor {
            overflow-x: auto;
        }

        .mensaje-error,
        .mensaje-exito,
        .aviso-compras {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .mensaje-error {
            background: #ffe8e8;
            color: #a32323;
        }

        .mensaje-exito {
            background: #e5f7e9;
            color: #176b32;
        }

        .aviso-compras {
            background: #fff6df;
            color: #805b0b;
        }

        .resumen-compra {
            max-width: 350px;
            margin: 25px 0 0 auto;
        }

        .resumen-compra div {
            display: flex;
            justify-content: space-between;
            padding: 9px 0;
        }

        .resumen-compra .total {
            border-top: 2px solid #ddd;
            font-size: 20px;
            font-weight: bold;
            padding-top: 15px;
        }

        .boton-quitar {
            background: #ffe6e6;
            color: #a32323;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
        }

        .estado-compra {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 15px;
            background: #fff0d2;
            color: #805b0b;
            font-size: 12px;
        }
    </style>
</head>

<body>

<div class="layout">

    <?php require "../includes/menu.php"; ?>

    <main class="contenido">

        <header class="encabezado">
            <h1>Gestión de Compras</h1>
            <p>Registro y administración de compras a proveedores.</p>
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

        <?php if ($puedeRegistrar): ?>

        <?php if (count($proveedores) === 0 || count($productos) === 0): ?>
            <div class="aviso-compras">
                Para registrar una compra necesitás tener al menos
                un proveedor y un producto activo.
            </div>
        <?php endif; ?>

        <form method="POST" id="formCompra">

            <section class="tarjeta">
                <h2>Información de la compra</h2>

                <label>Proveedor</label>
                <select name="proveedor_id" required>
                    <option value="">Seleccionar proveedor</option>

                    <?php foreach ($proveedores as $proveedor): ?>
                        <option value="<?= (int)$proveedor["id"] ?>">
                            <?= htmlspecialchars($proveedor["nombre"]) ?>
                            <?= $proveedor["nit"]
                                ? " - NIT: " . htmlspecialchars($proveedor["nit"])
                                : "" ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <p style="margin-top: 15px;">
                    <strong>Responsable:</strong>
                    <?= htmlspecialchars($usuarioSesion["nombre"]) ?>
                    (<?= htmlspecialchars($usuarioSesion["rol"]) ?>)
                </p>
            </section>

            <section class="tarjeta">
                <h2>Detalle de productos comprados</h2>

                <div class="tabla-contenedor">
                    <table class="tabla-compras">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Cantidad</th>
                                <th>Costo unitario ($)</th>
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

                <div class="resumen-compra">
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
                <h2>Confirmación de compra</h2>

                <p>
                    La compra se registrará como pendiente de pago.
                    Las existencias de los productos se actualizarán
                    automáticamente al guardar.
                </p>

                <button type="submit"
                    <?= (
                        count($proveedores) === 0 ||
                        count($productos) === 0
                    ) ? "disabled" : "" ?>>
                    Registrar compra
                </button>
            </section>

        </form>

        <?php endif; ?>

        <section class="tarjeta">
            <h2>Compras registradas</h2>

            <div class="tabla-contenedor">
                <table class="tabla-compras">
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Fecha</th>
                            <th>Proveedor</th>
                            <th>Subtotal</th>
                            <th>Impuesto</th>
                            <th>Total</th>
                            <th>Estado</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (count($compras) > 0): ?>

                            <?php foreach ($compras as $compra): ?>
                                <tr>
                                    <td><?= htmlspecialchars($compra["numero_compra"]) ?></td>
                                    <td><?= date("d/m/Y H:i", strtotime($compra["fecha_compra"])) ?></td>
                                    <td><?= htmlspecialchars($compra["proveedor"]) ?></td>
                                    <td>$<?= number_format((float)$compra["subtotal"], 2) ?></td>
                                    <td>$<?= number_format((float)$compra["impuesto"], 2) ?></td>
                                    <td><strong>$<?= number_format((float)$compra["total"], 2) ?></strong></td>
                                    <td>
                                        <span class="estado-compra">
                                            <?= htmlspecialchars($compra["estado"]) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    Todavía no hay compras registradas.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

<?php if ($puedeRegistrar): ?>
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
            data-costo="${Number(p.costo)}">
            ${escaparHTML(p.codigo)} - ${escaparHTML(p.nombre)}
        </option>
    `).join("");

    const opcionesImpuestos = `
        <option value="">Sin impuesto</option>
        ${impuestosDisponibles.map(i => `
            <option value="${Number(i.id)}"
                data-porcentaje="${Number(i.porcentaje)}">
                ${escaparHTML(i.nombre)} (${Number(i.porcentaje)}%)
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
            <input type="number" name="cantidad[]" class="cantidad"
                min="1" value="1" required>
        </td>

        <td>
            <input type="number" name="costo_unitario[]" class="costo"
                min="0" step="0.01" value="0" required>
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

    const selectorProducto = fila.querySelector(".producto");
    const campoCosto = fila.querySelector(".costo");

    selectorProducto.addEventListener("change", function() {
        const opcion = selectorProducto.selectedOptions[0];
        campoCosto.value = opcion?.dataset.costo || "0";
        calcularTotales();
    });

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
        const cantidad = Number(fila.querySelector(".cantidad").value);
        const costo = Number(fila.querySelector(".costo").value);
        const impuesto = fila.querySelector(".impuesto");

        const subtotal = Math.max(0, cantidad * costo);

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

document.getElementById("formCompra").addEventListener("submit", function(e) {
    if (filas.children.length === 0) {
        e.preventDefault();
        alert("Agregá al menos un producto a la compra.");
    }
});

agregarFila();
</script>
<?php endif; ?>

<script src="../assets/js/menu.js"></script>

</body>
</html>