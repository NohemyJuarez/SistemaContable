
<?php
require_once "../config/conexion.php";
$busqueda = trim($_GET["buscar"] ?? "");
$filtroEstado = $_GET["estado"] ?? "";

$mensaje = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $nombre = trim($_POST["nombre"]);

    if ($nombre === "") {
    $mensaje = "El nombre del cliente es obligatorio.";
} elseif (!empty($_POST["correo"]) && !filter_var(trim($_POST["correo"]), FILTER_VALIDATE_EMAIL)) {
    $mensaje = "El correo electrónico no tiene un formato válido.";
}

    $dui = trim($_POST["dui"]) ?: null;
    $nit = trim($_POST["nit"]) ?: null;
    $telefono = trim($_POST["telefono"]);
    $correo = trim($_POST["correo"]);
    $direccion = trim($_POST["direccion"]);

    if ($mensaje === "") {

    try {
        $sql = "INSERT INTO clientes
                (nombre, dui, nit, telefono, correo, direccion)
                VALUES
                (:nombre, :dui, :nit, :telefono, :correo, :direccion)";

        $consulta = $conexion->prepare($sql);

        $consulta->execute([
            ":nombre" => $nombre,
            ":dui" => $dui,
            ":nit" => $nit,
            ":telefono" => $telefono ?: null,
            ":correo" => $correo ?: null,
            ":direccion" => $direccion ?: null
        ]);

        $mensaje = "¡Cliente registrado correctamente!";

    } catch (PDOException $e) {
        if ($e->getCode() === "23505") {
            $mensaje = "Error: el DUI o NIT ingresado ya está registrado.";
        } else {
            $mensaje = "No se pudo registrar el cliente. Verifica los datos.";
        }
    }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - Sistema Contable</title>
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
                <a href="contabilidad.php">Contabilidad</a>
                <a href="reportes.php">Reportes</a>
            </nav>
        </aside>

        <main class="contenido">
            <header class="encabezado">
                <h1>Gestión de Clientes</h1>
                <p>Registro y administración de clientes</p>
            </header>

            <section class="tarjeta">
                <h2>Registrar nuevo cliente</h2>

                <?php if (!empty($mensaje)): ?>
    <div class="mensaje">
        <?= htmlspecialchars($mensaje) ?>
    </div>
<?php endif; ?>

                <form method="POST">

                    <label>Nombre completo *</label>
                    <input type="text" name="nombre" maxlength="150" required>

                    <label>DUI</label>
                    <input type="text" name="dui" maxlength="10" placeholder="00000000-0">

                    <label>NIT</label>
                    <input type="text" name="nit" maxlength="17">

                    <label>Teléfono</label>
                    <input type="text" name="telefono" maxlength="20">

                    <label>Correo electrónico</label>
                    <input type="email" name="correo" maxlength="150">

                    <label>Dirección</label>
                    <textarea name="direccion" rows="3"></textarea>

                    <button type="submit">Guardar cliente</button>

                </form>
            </section>
            
            <section class="tarjeta" style="margin-top: 25px;">
                <h2>Clientes registrados</h2>

                
<form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 22px;">

    <input
        type="text"
        name="buscar"
        placeholder="Buscar por nombre, DUI o NIT..."
        value="<?= htmlspecialchars($busqueda) ?>"
        style="flex: 1; min-width: 200px;"
    >

    <select name="estado" style="width: 180px;">
        <option value="">Todos los estados</option>

        <option value="Activo"
            <?= $filtroEstado === "Activo" ? "selected" : "" ?>>
            Activos
        </option>

        <option value="Inactivo"
            <?= $filtroEstado === "Inactivo" ? "selected" : "" ?>>
            Inactivos
        </option>
    </select>

    <button type="submit">Buscar</button>

    <a href="clientes.php" class="boton">Limpiar</a>

</form>

    
                <?php
                    
$busqueda = trim($_GET["buscar"] ?? "");
$filtroEstado = $_GET["estado"] ?? "";

$sql = "SELECT id, nombre, dui, nit, telefono, correo, direccion, estado
        FROM clientes
        WHERE (
            nombre ILIKE :buscar
            OR COALESCE(dui, '') ILIKE :buscar
            OR COALESCE(nit, '') ILIKE :buscar
        )";

if (in_array($filtroEstado, ["Activo", "Inactivo"], true)) {
    $sql .= " AND estado = :estado";
}

$sql .= " ORDER BY id DESC";

$consultaClientes = $conexion->prepare($sql);

$parametros = [
    ":buscar" => "%" . $busqueda . "%"
];

if (in_array($filtroEstado, ["Activo", "Inactivo"], true)) {
    $parametros[":estado"] = $filtroEstado;
}

$consultaClientes->execute($parametros);

$clientes = $consultaClientes->fetchAll(PDO::FETCH_ASSOC);

                ?>

                <?php if (count($clientes) > 0): ?>

                 <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                 <thead>
                 <tr>
                   <th>ID</th>
                   <th>Nombre</th>
                   <th>DUI</th>
                   <th>NIT</th>
                   <th>Teléfono</th>
                   <th>Correo</th>
                   <th>Dirección</th>
                   <th>Estado</th>
                   <th>Acciones</th>
                 </tr>
                 </thead>

                 <tbody>
               
                 <?php foreach ($clientes as $cliente): ?>
               
                 <tr>
                    <td><?= htmlspecialchars($cliente["id"]) ?></td>
                    <td><?= htmlspecialchars($cliente["nombre"]) ?></td>
                    <td><?= htmlspecialchars($cliente["dui"] ?? "") ?></td>
                    <td><?= htmlspecialchars($cliente["nit"] ?? "") ?></td>
                    <td><?= htmlspecialchars($cliente["telefono"] ?? "") ?></td>
                    <td><?= htmlspecialchars($cliente["correo"] ?? "") ?></td>
                    <td><?= htmlspecialchars($cliente["direccion"] ?? "") ?></td>
                    <td><?= htmlspecialchars($cliente["estado"]) ?></td>}
                    <td>
    <a href="editar_cliente.php?id=<?= $cliente['id'] ?>">
        Editar
    </a>

    <?php if ($cliente["estado"] === "Activo"): ?>

    <a href="desactivar_cliente.php?id=<?= $cliente['id'] ?>">
        Desactivar
    </a>

<?php else: ?>

    <a href="reactivar_cliente.php?id=<?= $cliente['id'] ?>">
        Reactivar
    </a>

<?php endif; ?>

</td>
                 </tr>
                
                 <?php endforeach; ?>
                 </tbody>
                 </table>

                 <?php else: ?>

                 <p>Todavía no hay clientes registrados.</p>

                 <?php endif; ?>
                 clientes.</p>

            </section>
        
        </main>

    </div>

    <script src="../assets/js/menu.js"></script>

</body>
</html>
