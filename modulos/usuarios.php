<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";

requerirEdicion("usuarios");

$yo = usuarioActual();
$mensaje = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verificarCsrf();
    $accion = $_POST["accion"] ?? "";

    try {

        if ($accion === "crear") {

            $nombre = trim($_POST["nombre"] ?? "");
            $usuario = trim($_POST["usuario"] ?? "");
            $correo = trim($_POST["correo"] ?? "");
            $contrasena = $_POST["contrasena"] ?? "";
            $rol = $_POST["rol"] ?? "";

            if ($nombre === "" || $usuario === "" || $correo === "") {
                $mensaje = "Nombre, usuario y correo son obligatorios.";
            } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $mensaje = "El correo electrónico no tiene un formato válido.";
            } elseif (strlen($contrasena) < 8) {
                $mensaje = "La contraseña debe tener al menos 8 caracteres.";
            } elseif (!in_array($rol, ROLES, true)) {
                $mensaje = "Selecciona un rol válido.";
            } else {
                $insertar = $conexion->prepare(
                    "INSERT INTO usuarios (nombre, usuario, correo, contrasena, rol)
                     VALUES (:nombre, :usuario, :correo, :contrasena, :rol)"
                );

                $insertar->execute([
                    ":nombre"     => $nombre,
                    ":usuario"    => $usuario,
                    ":correo"     => $correo,
                    ":contrasena" => password_hash($contrasena, PASSWORD_DEFAULT),
                    ":rol"        => $rol,
                ]);

                $mensaje = "¡Usuario creado correctamente!";
            }

        } elseif ($accion === "actualizar") {

            $id = filter_var($_POST["id"] ?? "", FILTER_VALIDATE_INT);
            $rol = $_POST["rol"] ?? "";
            $estado = $_POST["estado"] ?? "";
            $nueva = $_POST["nueva_contrasena"] ?? "";

            if (!$id || !in_array($rol, ROLES, true) || !in_array($estado, ["Activo", "Inactivo"], true)) {
                $mensaje = "Los datos enviados no son válidos.";
            } elseif ($id === $yo["id"] && ($rol !== $yo["rol"] || $estado !== "Activo")) {
                $mensaje = "No puedes cambiar tu propio rol ni desactivar tu propia cuenta.";
            } elseif ($nueva !== "" && strlen($nueva) < 8) {
                $mensaje = "La nueva contraseña debe tener al menos 8 caracteres.";
            } else {
                $sql = "UPDATE usuarios SET rol = :rol, estado = :estado";
                $parametros = [":rol" => $rol, ":estado" => $estado, ":id" => $id];

                if ($nueva !== "") {
                    $sql .= ", contrasena = :contrasena";
                    $parametros[":contrasena"] = password_hash($nueva, PASSWORD_DEFAULT);
                }

                $sql .= " WHERE id = :id";

                $conexion->prepare($sql)->execute($parametros);

                $mensaje = "¡Usuario actualizado correctamente!";
            }
        }

    } catch (PDOException $e) {
        if ($e->getCode() === "23505") {
            $mensaje = "Error: el usuario o el correo ya está registrado.";
        } else {
            $mensaje = "No se pudo completar la operación. Verifica los datos.";
        }
    }
}

$usuarios = $conexion->query(
    "SELECT id, nombre, usuario, correo, rol, estado
     FROM usuarios
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$csrf = tokenCsrf();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - Sistema Contable</title>
    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">
</head>
<body>
    <div class="layout">

        <?php require "../includes/menu.php"; ?>

        <main class="contenido">
            <header class="encabezado">
                <h1>Gestión de Usuarios</h1>
                <p>Alta de usuarios, asignación de roles y control de acceso</p>
            </header>

            <?php if ($mensaje !== ""): ?>
                <div class="mensaje">
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>

            <section class="tarjeta">
                <h2>Registrar nuevo usuario</h2>

                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="accion" value="crear">

                    <label>Nombre completo *</label>
                    <input type="text" name="nombre" maxlength="100" required>

                    <label>Usuario (para iniciar sesión) *</label>
                    <input type="text" name="usuario" maxlength="50" required>

                    <label>Correo electrónico *</label>
                    <input type="email" name="correo" maxlength="150" required>

                    <label>Contraseña * (mínimo 8 caracteres)</label>
                    <input type="password" name="contrasena" minlength="8" required>

                    <label>Rol *</label>
                    <select name="rol" required>
                        <option value="">Seleccionar rol</option>
                        <?php foreach (ROLES as $opcion): ?>
                            <option value="<?= htmlspecialchars($opcion) ?>">
                                <?= htmlspecialchars($opcion) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit">Guardar usuario</button>
                </form>
            </section>

            <section class="tarjeta" style="margin-top: 25px;">
                <h2>Usuarios registrados</h2>

                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Usuario</th>
                                <th>Correo</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Nueva contraseña</th>
                                <th>Acción</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($usuarios as $u): ?>
                                <?php $formId = "form-usuario-" . (int)$u["id"]; ?>
                                <tr>
                                    <td><?= (int)$u["id"] ?></td>
                                    <td><?= htmlspecialchars($u["nombre"]) ?></td>
                                    <td><?= htmlspecialchars($u["usuario"]) ?></td>
                                    <td><?= htmlspecialchars($u["correo"]) ?></td>

                                    <td>
                                        <select name="rol" form="<?= $formId ?>">
                                            <?php foreach (ROLES as $opcion): ?>
                                                <option value="<?= htmlspecialchars($opcion) ?>"
                                                    <?= $u["rol"] === $opcion ? "selected" : "" ?>>
                                                    <?= htmlspecialchars($opcion) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <td>
                                        <select name="estado" form="<?= $formId ?>">
                                            <option value="Activo" <?= $u["estado"] === "Activo" ? "selected" : "" ?>>Activo</option>
                                            <option value="Inactivo" <?= $u["estado"] === "Inactivo" ? "selected" : "" ?>>Inactivo</option>
                                        </select>
                                    </td>

                                    <td>
                                        <input type="password" name="nueva_contrasena" form="<?= $formId ?>"
                                               minlength="8" placeholder="Dejar vacío para no cambiar">
                                    </td>

                                    <td>
                                        <form id="<?= $formId ?>" method="POST">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="accion" value="actualizar">
                                            <input type="hidden" name="id" value="<?= (int)$u["id"] ?>">
                                            <button type="submit">Guardar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </main>

    </div>

    <script src="../assets/js/menu.js"></script>

</body>
</html>