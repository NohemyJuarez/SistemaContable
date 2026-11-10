<?php
require_once "includes/auth.php";
requerirLogin();

$usuario = usuarioActual();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema Contable</title>

    <link rel="stylesheet" href="assets/css/estilos.css">
    <link rel="stylesheet" href="assets/css/menu.css">
</head>
<body>

    <div class="layout">

        <?php require "includes/menu.php"; ?>

        <main class="contenido">

            <header class="encabezado">
                <h1>Sistema de Facturación y Control de Ventas</h1>
                <p>Gestión administrativa y contable para MYPE</p>
            </header>

            <section class="tarjeta">
                <h2>Bienvenido al Sistema</h2>
                <p>Administra la información de tu negocio desde un solo lugar.</p>
                <p>
                    Sesión iniciada como
                    <strong><?= htmlspecialchars($usuario["nombre"]) ?></strong>
                    (<?= htmlspecialchars($usuario["rol"]) ?>).
                </p>
            </section>

        </main>

    </div>

    <script src="assets/js/menu.js"></script>

</body>
</html>