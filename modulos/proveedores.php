
<?php
require_once "../config/conexion.php";

$mensaje = "";
$busqueda = trim($_GET["buscar"] ?? "");
$filtroEstado = $_GET["estado"] ?? "";

// Registrar proveedor
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombre = trim($_POST["nombre"] ?? "");
    $nit = trim($_POST["nit"] ?? "") ?: null;
    $telefono = trim($_POST["telefono"] ?? "") ?: null;
    $correo = trim($_POST["correo"] ?? "") ?: null;
    $direccion = trim($_POST["direccion"] ?? "") ?: null;

    if ($nombre === "") {
        $mensaje = "El nombre del proveedor es obligatorio.";
    } elseif ($correo !== null && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $mensaje = "El correo electrónico no tiene un formato válido.";
    } else {
        try {
            $sql = "INSERT INTO proveedores
                    (nombre, nit, telefono, correo, direccion)
                    VALUES (:nombre, :nit, :telefono, :correo, :direccion)";

            $consulta = $conexion->prepare($sql);
            $consulta->execute([
                ":nombre" => $nombre,
                ":nit" => $nit,
                ":telefono" => $telefono,
                ":correo" => $correo,
                ":direccion" => $direccion
            ]);

            header("Location: proveedores.php?registrado=1");
            exit;

        } catch (PDOException $e) {
            if ($e->getCode() === "23505") {
                $mensaje = "Error: el NIT ingresado ya está registrado.";
            } else {
                $mensaje = "No se pudo registrar el proveedor. Verifica los datos.";
            }
        }
    }
}

if (isset($_GET["registrado"])) {
    $mensaje = "¡Proveedor registrado correctamente!";
}

// Consultar proveedores
$sql = "SELECT id, nombre, nit, telefono, correo, direccion, estado
        FROM proveedores
        WHERE (
            nombre ILIKE :buscar
            OR COALESCE(nit, '') ILIKE :buscar
        )";

if (in_array($filtroEstado, ["Activo", "Inactivo"], true)) {
    $sql .= " AND estado = :estado";
}

$sql .= " ORDER BY id DESC";

$consulta = $conexion->prepare($sql);

$parametros = [
    ":buscar" => "%" . $busqueda . "%"
];

if (in_array($filtroEstado, ["Activo", "Inactivo"], true)) {
    $parametros[":estado"] = $filtroEstado;
}

$consulta->execute($parametros);
$proveedores = $consulta->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proveedores - Sistema Contable</title>
    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">
</head>
<body>

<div class="contenedor" style="display:flex; min-height:100vh;">

    <aside class="menu-lateral">
        <h2>SISTEMA CONTABLE</h2>
        <button type="button" id="btn-menu" aria-label="Contraer menú" aria-expanded="true">☰</button>

        <nav>
            <a href="../index.php">Inicio</a>
            <a href="clientes.php">Clientes</a>
            <a href="proveedores.php" class="activo">Proveedores</a>
        </nav>
    </aside>

    <main class="contenido">

        <header class="encabezado">
            <h1>Gestión de proveedores</h1>
            <p>Administra la información de tus proveedores.</p>
        </header>

        <?php if (!empty($mensaje)): ?>
    <div class="mensaje">
        <?= htmlspecialchars($mensaje) ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET["actualizado"])): ?>
    <div class="mensaje">
        ¡Proveedor actualizado correctamente!
    </div>
<?php endif; ?>

        <section class="tarjeta">
            <h2>Registrar nuevo proveedor</h2>

            <form method="POST">
                <label>Nombre del proveedor *</label>
                <input type="text" name="nombre" maxlength="150" required>

                <label>NIT</label>
                <input type="text" name="nit" maxlength="17">

                <label>Teléfono</label>
                <input type="text" name="telefono" maxlength="20">

                <label>Correo electrónico</label>
                <input type="email" name="correo" maxlength="150">

                <label>Dirección</label>
                <textarea name="direccion" rows="3"></textarea>

                <button type="submit">Guardar proveedor</button>
            </form>
        </section>

        <section class="tarjeta">
            <h2>Proveedores registrados</h2>

            <form method="GET">
                <label>Buscar proveedor</label>
                <input type="text" name="buscar"
                       placeholder="Nombre o NIT"
                       value="<?= htmlspecialchars($busqueda) ?>">

                <label>Filtrar por estado</label>
                <select name="estado">
                    <option value="">Todos</option>
                    <option value="Activo" <?= $filtroEstado === "Activo" ? "selected" : "" ?>>Activos</option>
                    <option value="Inactivo" <?= $filtroEstado === "Inactivo" ? "selected" : "" ?>>Inactivos</option>
                </select>

                <button type="submit">Buscar</button>
                <a href="proveedores.php" class="boton">Limpiar</a>
            </form>

            <div style="overflow-x:auto; margin-top:20px;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>NIT</th>
                            <th>Teléfono</th>
                            <th>Correo</th>
                            <th>Dirección</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($proveedores) > 0): ?>
                            <?php foreach ($proveedores as $proveedor): ?>
                                <tr>
                                    <td><?= $proveedor["id"] ?></td>
                                    <td><?= htmlspecialchars($proveedor["nombre"]) ?></td>
                                    <td><?= htmlspecialchars($proveedor["nit"] ?? "") ?></td>
                                    <td><?= htmlspecialchars($proveedor["telefono"] ?? "") ?></td>
                                    <td><?= htmlspecialchars($proveedor["correo"] ?? "") ?></td>
                                    <td><?= htmlspecialchars($proveedor["direccion"] ?? "") ?></td>
                                    <td><?= htmlspecialchars($proveedor["estado"]) ?></td>
                                    <td>
                                        <a href="editar_proveedor.php?id=<?= $proveedor["id"] ?>">Editar</a>

<?php if ($proveedor["estado"] === "Activo"): ?>
    | <a href="cambiar_estado_proveedor.php?id=<?= $proveedor["id"] ?>&accion=desactivar">Desactivar</a>
<?php else: ?>
    | <a href="cambiar_estado_proveedor.php?id=<?= $proveedor["id"] ?>&accion=reactivar">Reactivar</a>
<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8">No se encontraron proveedores.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

<script src="../assets/js/menu.js"></script>
</body>
</html>
