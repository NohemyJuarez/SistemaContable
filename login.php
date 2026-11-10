<?php
require_once __DIR__ . "/config/conexion.php";
require_once __DIR__ . "/includes/auth.php";

// Si ya inició sesión, va directo al inicio
if (usuarioActual() !== null) {
    header("Location: index.php");
    exit;
}

$error = "";
$usuarioIngresado = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $usuarioIngresado = trim($_POST["usuario"] ?? "");
    $contrasena = $_POST["contrasena"] ?? "";

    if ($usuarioIngresado === "" || $contrasena === "") {
        $error = "Ingresa tu usuario y tu contraseña.";
    } else {
        try {
            $consulta = $conexion->prepare(
                "SELECT id, nombre, usuario, contrasena, rol, estado
                 FROM usuarios
                 WHERE usuario = :usuario"
            );
            $consulta->execute([":usuario" => $usuarioIngresado]);
            $fila = $consulta->fetch(PDO::FETCH_ASSOC);

            if (
                $fila
                && $fila["estado"] === "Activo"
                && password_verify($contrasena, $fila["contrasena"])
            ) {
                session_regenerate_id(true);

                $_SESSION["usuario"] = [
                    "id"      => (int)$fila["id"],
                    "nombre"  => $fila["nombre"],
                    "usuario" => $fila["usuario"],
                    "rol"     => $fila["rol"],
                ];

                header("Location: index.php");
                exit;
            }

            $error = "Usuario o contraseña incorrectos, o el usuario está inactivo.";

        } catch (PDOException $e) {
            $error = "No se pudo iniciar sesión. Intenta de nuevo.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión - Sistema Contable</title>
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body style="display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px;">

    <section class="tarjeta" style="width: 100%; max-width: 420px;">

        <h1 style="text-align: center; margin-bottom: 6px;">Sistema Contable</h1>
        <p style="text-align: center; margin-bottom: 25px;">Inicia sesión para continuar</p>

        <?php if ($error !== ""): ?>
            <div class="mensaje">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">

            <label>Usuario</label>
            <input type="text" name="usuario" maxlength="50" required autofocus
                   value="<?= htmlspecialchars($usuarioIngresado) ?>">

            <label>Contraseña</label>
            <input type="password" name="contrasena" required>

            <button type="submit" style="width: 100%;">Ingresar</button>

        </form>

    </section>

</body>
</html>