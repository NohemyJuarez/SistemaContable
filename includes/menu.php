<?php
/*
 * Menú lateral compartido. Reemplaza el <aside class="menu-lateral"> de cada página:
 *   en modulos/:  <?php require "../includes/menu.php"; ?>
 *   en la raíz:   <?php require "includes/menu.php"; ?>
 * Solo muestra las opciones a las que el rol del usuario tiene acceso.
 */
require_once __DIR__ . "/auth.php";

$raiz = rutaRaiz();
$prefijoModulos = $raiz === "../" ? "" : "modulos/";
$usuarioMenu = usuarioActual();

// [clave en PERMISOS, texto del enlace, archivo]
$opcionesMenu = [
    ["clientes",      "Clientes",         "clientes.php"],
    ["proveedores",   "Proveedores",      "proveedores.php"],
    ["productos",     "Productos",        "productos.php"],
    ["ventas",        "Ventas",           "ventas.php"],
    ["compras",       "Compras",          "compras.php"],
    ["libro_compras", "Libro de compras", "libro_compras.php"],
    ["contabilidad",  "Contabilidad",     "contabilidad.php"],
    ["reportes",      "Reportes",         "reportes.php"],
    ["usuarios",      "Usuarios",         "usuarios.php"],
];
?>
<aside class="menu-lateral">
    <h2>SISTEMA CONTABLE</h2>

    <button type="button" id="btn-menu"
        aria-label="Contraer menú"
        aria-expanded="true">
        ☰
    </button>

    <nav>
        <a href="<?= $raiz ?>index.php">Inicio</a>

        <?php foreach ($opcionesMenu as [$clave, $texto, $archivo]): ?>
            <?php if (nivelAcceso($clave) !== null): ?>
                <a href="<?= $prefijoModulos . $archivo ?>"><?= htmlspecialchars($texto) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($usuarioMenu !== null): ?>
            <a href="<?= $raiz ?>logout.php">Cerrar sesión</a>
        <?php endif; ?>
    </nav>

    <?php if ($usuarioMenu !== null): ?>
        <div class="pie-menu">
            <strong><?= htmlspecialchars($usuarioMenu["nombre"]) ?></strong><br>
            <?= htmlspecialchars($usuarioMenu["rol"]) ?>
        </div>
    <?php endif; ?>
</aside>