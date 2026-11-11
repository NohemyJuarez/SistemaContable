<?php
require_once "../config/conexion.php";
require_once "../includes/auth.php";

requerirAcceso("catalogo_cuentas");
$puedeEditar = puedeEditar("catalogo_cuentas");

$tiposCuenta = ["Activo", "Pasivo", "Patrimonio", "Ingreso", "Costo", "Gasto"];
$naturalezas = ["Deudora", "Acreedora"];
$mensaje = "";
$tipoMensaje = "exito";
$valoresFormulario = [
    "codigo" => "",
    "nombre" => "",
    "tipo" => "",
    "naturaleza" => "",
    "cuenta_padre_id" => "",
    "permite_movimientos" => "1",
];
$idEdicion = filter_input(INPUT_GET, "editar", FILTER_VALIDATE_INT);
$cuentaEdicion = null;
$abrirFormulario = false;

if ($puedeEditar && $idEdicion) {
    $consulta = $conexion->prepare("SELECT * FROM cuentas_contables WHERE id = :id");
    $consulta->execute([":id" => $idEdicion]);
    $cuentaEdicion = $consulta->fetch(PDO::FETCH_ASSOC);

    if (!$cuentaEdicion) {
        $idEdicion = null;
        $mensaje = "La cuenta que intentas editar no existe.";
        $tipoMensaje = "error";
    } else {
        $abrirFormulario = true;
        $valoresFormulario = [
            "codigo" => $cuentaEdicion["codigo"],
            "nombre" => $cuentaEdicion["nombre"],
            "tipo" => $cuentaEdicion["tipo"],
            "naturaleza" => $cuentaEdicion["naturaleza"],
            "cuenta_padre_id" => $cuentaEdicion["cuenta_padre_id"] ?? "",
            "permite_movimientos" => $cuentaEdicion["permite_movimientos"] ? "1" : "0",
        ];
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    requerirEdicion("catalogo_cuentas");
    verificarCsrf();

    $accion = $_POST["accion"] ?? "";

    if ($accion === "crear" || $accion === "actualizar") {
        $id = filter_var($_POST["id"] ?? "", FILTER_VALIDATE_INT);
        $codigo = trim($_POST["codigo"] ?? "");
        $nombre = trim($_POST["nombre"] ?? "");
        $tipo = $_POST["tipo"] ?? "";
        $naturaleza = $_POST["naturaleza"] ?? "";
        $idPadre = $_POST["cuenta_padre_id"] ?? "";
        $permiteMovimientos = $_POST["permite_movimientos"] ?? "";
        $idEdicion = $accion === "actualizar" ? $id : null;
        $abrirFormulario = true;

        if ($accion === "actualizar" && $id) {
            $consulta = $conexion->prepare("SELECT * FROM cuentas_contables WHERE id = :id");
            $consulta->execute([":id" => $id]);
            $cuentaEdicion = $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $valoresFormulario = [
            "codigo" => $codigo,
            "nombre" => $nombre,
            "tipo" => $tipo,
            "naturaleza" => $naturaleza,
            "cuenta_padre_id" => $idPadre,
            "permite_movimientos" => $permiteMovimientos,
        ];

        if (($accion === "actualizar" && !$id) ||
            $codigo === "" || strlen($codigo) > 20 ||
            $nombre === "" || mb_strlen($nombre) > 150 ||
            !in_array($tipo, $tiposCuenta, true) ||
            !in_array($naturaleza, $naturalezas, true) ||
            !in_array($permiteMovimientos, ["0", "1"], true) ||
            ($accion === "actualizar" && !$cuentaEdicion)) {
            $mensaje = "Verifica los datos obligatorios y sus formatos.";
            $tipoMensaje = "error";
        } else {
            $idPadre = $idPadre === "" ? null : filter_var($idPadre, FILTER_VALIDATE_INT);
            $idsPadrePermitidos = [];

            if ($idEdicion) {
                $consulta = $conexion->prepare(
                    "WITH RECURSIVE descendientes(id) AS (
                        SELECT id FROM cuentas_contables WHERE cuenta_padre_id = :id
                        UNION
                        SELECT c.id
                        FROM cuentas_contables c
                        INNER JOIN descendientes d ON c.cuenta_padre_id = d.id
                    )
                    SELECT id FROM descendientes"
                );
                $consulta->execute([":id" => $idEdicion]);
                $idsNoPermitidos = array_map("intval", $consulta->fetchAll(PDO::FETCH_COLUMN));
                $idsNoPermitidos[] = (int)$idEdicion;
            } else {
                $idsNoPermitidos = [];
            }

            $cuentasPadre = $conexion->query(
                "SELECT id FROM cuentas_contables ORDER BY codigo"
            )->fetchAll(PDO::FETCH_COLUMN);
            $idsPadrePermitidos = array_map("intval", $cuentasPadre);
            $idsPadrePermitidos = array_values(array_diff($idsPadrePermitidos, $idsNoPermitidos));

            if ($idPadre !== null &&
                ($idPadre === false || !in_array((int)$idPadre, $idsPadrePermitidos, true))) {
                $mensaje = "Selecciona una cuenta padre válida.";
                $tipoMensaje = "error";
            } else {
                try {
                    if ($accion === "crear") {
                        $sql = "INSERT INTO cuentas_contables
                                    (codigo, nombre, tipo, naturaleza, cuenta_padre_id, permite_movimientos)
                                VALUES
                                    (:codigo, :nombre, :tipo, :naturaleza, :cuenta_padre_id, :permite_movimientos)";
                        $parametros = [
                            ":codigo" => $codigo,
                            ":nombre" => $nombre,
                            ":tipo" => $tipo,
                            ":naturaleza" => $naturaleza,
                            ":cuenta_padre_id" => $idPadre,
                            ":permite_movimientos" => $permiteMovimientos === "1" ? "true" : "false",
                        ];
                        $mensajeGuardado = "¡Cuenta registrada correctamente!";
                    } else {
                        $sql = "UPDATE cuentas_contables
                                SET codigo = :codigo,
                                    nombre = :nombre,
                                    tipo = :tipo,
                                    naturaleza = :naturaleza,
                                    cuenta_padre_id = :cuenta_padre_id,
                                    permite_movimientos = :permite_movimientos
                                WHERE id = :id";
                        $parametros = [
                            ":codigo" => $codigo,
                            ":nombre" => $nombre,
                            ":tipo" => $tipo,
                            ":naturaleza" => $naturaleza,
                            ":cuenta_padre_id" => $idPadre,
                            ":permite_movimientos" => $permiteMovimientos === "1" ? "true" : "false",
                            ":id" => $id,
                        ];
                        $mensajeGuardado = "¡Cuenta actualizada correctamente!";
                    }

                    $conexion->prepare($sql)->execute($parametros);
                    header("Location: cuentas_contables.php?mensaje=" . urlencode($mensajeGuardado));
                    exit;
                } catch (PDOException $e) {
                    if ($e->getCode() === "23505") {
                        $mensaje = "Ya existe una cuenta con ese código.";
                    } else {
                        error_log("Catálogo de cuentas: " . $e->getMessage());
                        $mensaje = "No se pudo guardar la cuenta. Verifica los datos e inténtalo de nuevo.";
                    }
                    $tipoMensaje = "error";
                }
            }
        }
    } elseif ($accion === "cambiar_estado") {
        $id = filter_var($_POST["id"] ?? "", FILTER_VALIDATE_INT);
        $estado = $_POST["estado"] ?? "";

        if (!$id || !in_array($estado, ["Activa", "Inactiva"], true)) {
            $mensaje = "La solicitud para cambiar el estado no es válida.";
            $tipoMensaje = "error";
        } else {
            $consulta = $conexion->prepare(
                "UPDATE cuentas_contables SET estado = :estado WHERE id = :id"
            );
            $consulta->execute([":estado" => $estado, ":id" => $id]);

            if ($consulta->rowCount() === 0) {
                $mensaje = "No se encontró la cuenta o ya tenía ese estado.";
                $tipoMensaje = "error";
            } else {
                header("Location: cuentas_contables.php?mensaje=" . urlencode("Estado de la cuenta actualizado."));
                exit;
            }
        }
    } else {
        $mensaje = "La acción solicitada no es válida.";
        $tipoMensaje = "error";
    }
}

if (isset($_GET["mensaje"])) {
    $mensaje = trim($_GET["mensaje"]);
}

$cuentas = $conexion->query(
    "SELECT c.id, c.codigo, c.nombre, c.tipo, c.naturaleza,
            c.cuenta_padre_id, c.permite_movimientos, c.estado,
            padre.codigo AS codigo_padre, padre.nombre AS nombre_padre
     FROM cuentas_contables c
     LEFT JOIN cuentas_contables padre ON padre.id = c.cuenta_padre_id
     ORDER BY c.codigo"
)->fetchAll(PDO::FETCH_ASSOC);

if ($idEdicion && $cuentaEdicion) {
    $consulta = $conexion->prepare(
        "WITH RECURSIVE descendientes(id) AS (
            SELECT id FROM cuentas_contables WHERE cuenta_padre_id = :id
            UNION
            SELECT c.id
            FROM cuentas_contables c
            INNER JOIN descendientes d ON c.cuenta_padre_id = d.id
        )
        SELECT id FROM descendientes"
    );
    $consulta->execute([":id" => $idEdicion]);
    $idsNoPermitidosPadre = array_map("intval", $consulta->fetchAll(PDO::FETCH_COLUMN));
    $idsNoPermitidosPadre[] = (int)$idEdicion;
} else {
    $idsNoPermitidosPadre = [];
}

$cuentasPadreFormulario = array_values(array_filter(
    $cuentas,
    static function (array $cuenta) use ($idsNoPermitidosPadre): bool {
        return !in_array((int)$cuenta["id"], $idsNoPermitidosPadre, true);
    }
));
$csrf = tokenCsrf();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de cuentas - Sistema Contable</title>
    <link rel="stylesheet" href="../assets/css/estilos.css">
    <link rel="stylesheet" href="../assets/css/menu.css">
    <link rel="stylesheet" href="../assets/css/catalogo-cuentas.css">
</head>
<body>
<div class="layout">
    <?php require "../includes/menu.php"; ?>

    <main class="contenido catalogo-cuentas">
        <header class="encabezado">
            <div>
                <h1>Catálogo de cuentas</h1>
                <p>Administra las cuentas contables de tu empresa.</p>
            </div>
            <?php if ($puedeEditar): ?>
                <button type="button" class="boton-nueva" id="abrir-formulario">
                    + Nueva cuenta
                </button>
            <?php endif; ?>
        </header>

        <?php if ($mensaje !== ""): ?>
            <div class="mensaje <?= $tipoMensaje === "error" ? "mensaje-error" : "" ?>" role="status">
                <?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <section class="tarjeta">
            <h2>Cuentas registradas</h2>
            <div class="filtros-cuentas">
                <label for="buscar-cuenta">Buscar</label>
                <input type="search" id="buscar-cuenta"
                       placeholder="Código, nombre o cuenta padre..." autocomplete="off">

                <label for="filtrar-tipo">Tipo de cuenta</label>
                <select id="filtrar-tipo">
                    <option value="">Todos los tipos</option>
                    <?php foreach ($tiposCuenta as $tipo): ?>
                        <option value="<?= htmlspecialchars($tipo, ENT_QUOTES, "UTF-8") ?>">
                            <?= htmlspecialchars($tipo, ENT_QUOTES, "UTF-8") ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="contador-cuentas" id="contador-cuentas"></span>
            </div>

            <div class="tabla-cuentas">
                <table>
                    <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre de cuenta</th>
                        <th>Tipo</th>
                        <th>Naturaleza</th>
                        <th>Cuenta padre</th>
                        <th>Movimientos</th>
                        <th>Estado</th>
                        <th><span class="solo-lectura">Acciones</span></th>
                    </tr>
                    </thead>
                    <tbody id="filas-cuentas">
                    <?php foreach ($cuentas as $cuenta): ?>
                        <tr data-tipo="<?= htmlspecialchars($cuenta["tipo"], ENT_QUOTES, "UTF-8") ?>"
                            data-busqueda="<?= htmlspecialchars(
                                implode(" ", [
                                    $cuenta["codigo"],
                                    $cuenta["nombre"],
                                    $cuenta["tipo"],
                                    $cuenta["naturaleza"],
                                    $cuenta["codigo_padre"] ?? "",
                                    $cuenta["nombre_padre"] ?? "",
                                    $cuenta["estado"],
                                ]),
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>">
                            <td><?= htmlspecialchars($cuenta["codigo"], ENT_QUOTES, "UTF-8") ?></td>
                            <td><?= htmlspecialchars($cuenta["nombre"], ENT_QUOTES, "UTF-8") ?></td>
                            <td><?= htmlspecialchars($cuenta["tipo"], ENT_QUOTES, "UTF-8") ?></td>
                            <td><?= htmlspecialchars($cuenta["naturaleza"], ENT_QUOTES, "UTF-8") ?></td>
                            <td>
                                <?php if ($cuenta["codigo_padre"] !== null): ?>
                                    <?= htmlspecialchars($cuenta["codigo_padre"] . " - " . $cuenta["nombre_padre"], ENT_QUOTES, "UTF-8") ?>
                                <?php else: ?>
                                    <span class="texto-secundario">Cuenta raíz</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $cuenta["permite_movimientos"] ? "Sí" : "No" ?></td>
                            <td>
                                <span class="estado-cuenta <?= $cuenta["estado"] === "Activa" ? "activa" : "inactiva" ?>">
                                    <?= htmlspecialchars($cuenta["estado"], ENT_QUOTES, "UTF-8") ?>
                                </span>
                            </td>
                            <td class="celda-acciones">
                                <details class="menu-acciones">
                                    <summary aria-label="Acciones de <?= htmlspecialchars($cuenta["codigo"], ENT_QUOTES, "UTF-8") ?>">⋮</summary>
                                    <div class="opciones-acciones">
                                        <button type="button" class="ver-detalle"
                                                data-codigo="<?= htmlspecialchars($cuenta["codigo"], ENT_QUOTES, "UTF-8") ?>"
                                                data-nombre="<?= htmlspecialchars($cuenta["nombre"], ENT_QUOTES, "UTF-8") ?>"
                                                data-tipo="<?= htmlspecialchars($cuenta["tipo"], ENT_QUOTES, "UTF-8") ?>"
                                                data-naturaleza="<?= htmlspecialchars($cuenta["naturaleza"], ENT_QUOTES, "UTF-8") ?>"
                                                data-padre="<?= htmlspecialchars(
                                                    $cuenta["codigo_padre"] !== null
                                                        ? $cuenta["codigo_padre"] . " - " . $cuenta["nombre_padre"]
                                                        : "Cuenta raíz",
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>"
                                                data-movimientos="<?= $cuenta["permite_movimientos"] ? "Sí" : "No" ?>"
                                                data-estado="<?= htmlspecialchars($cuenta["estado"], ENT_QUOTES, "UTF-8") ?>">
                                            Ver detalles
                                        </button>
                                        <?php if ($puedeEditar): ?>
                                            <a href="cuentas_contables.php?editar=<?= (int)$cuenta["id"] ?>">Editar</a>
                                            <form method="POST">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>">
                                                <input type="hidden" name="accion" value="cambiar_estado">
                                                <input type="hidden" name="id" value="<?= (int)$cuenta["id"] ?>">
                                                <input type="hidden" name="estado"
                                                       value="<?= $cuenta["estado"] === "Activa" ? "Inactiva" : "Activa" ?>">
                                                <button type="submit">
                                                    <?= $cuenta["estado"] === "Activa" ? "Desactivar" : "Activar" ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="sin-resultados" <?= count($cuentas) > 0 ? "hidden" : "" ?>>
                        <td colspan="8">No se encontraron cuentas con esos criterios.</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<dialog id="modal-cuenta" class="modal-cuenta" data-abrir="<?= $abrirFormulario ? "true" : "false" ?>">
    <form method="POST" class="formulario-cuenta">
        <div class="cabecera-modal">
            <div>
                <h2><?= $idEdicion ? "Editar cuenta contable" : "Registrar nueva cuenta" ?></h2>
                <p>Completa la información de la cuenta.</p>
            </div>
            <button type="button" class="cerrar-modal" data-cerrar-modal aria-label="Cerrar">×</button>
        </div>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, "UTF-8") ?>">
        <input type="hidden" name="accion" value="<?= $idEdicion ? "actualizar" : "crear" ?>">
        <?php if ($idEdicion): ?>
            <input type="hidden" name="id" value="<?= (int)$idEdicion ?>">
        <?php endif; ?>

        <div class="campos-cuenta">
            <div>
                <label for="codigo">Código *</label>
                <input id="codigo" name="codigo" maxlength="20" required
                       value="<?= htmlspecialchars((string)$valoresFormulario["codigo"], ENT_QUOTES, "UTF-8") ?>">
            </div>
            <div>
                <label for="nombre">Nombre de la cuenta *</label>
                <input id="nombre" name="nombre" maxlength="150" required
                       value="<?= htmlspecialchars((string)$valoresFormulario["nombre"], ENT_QUOTES, "UTF-8") ?>">
            </div>
            <div>
                <label for="tipo">Tipo de cuenta *</label>
                <select id="tipo" name="tipo" required>
                    <option value="">Selecciona un tipo</option>
                    <?php foreach ($tiposCuenta as $tipo): ?>
                        <option value="<?= htmlspecialchars($tipo, ENT_QUOTES, "UTF-8") ?>"
                            <?= $valoresFormulario["tipo"] === $tipo ? "selected" : "" ?>>
                            <?= htmlspecialchars($tipo, ENT_QUOTES, "UTF-8") ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="naturaleza">Naturaleza *</label>
                <select id="naturaleza" name="naturaleza" required>
                    <option value="">Selecciona una naturaleza</option>
                    <?php foreach ($naturalezas as $naturaleza): ?>
                        <option value="<?= htmlspecialchars($naturaleza, ENT_QUOTES, "UTF-8") ?>"
                            <?= $valoresFormulario["naturaleza"] === $naturaleza ? "selected" : "" ?>>
                            <?= htmlspecialchars($naturaleza, ENT_QUOTES, "UTF-8") ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="cuenta_padre_id">Cuenta padre</label>
                <select id="cuenta_padre_id" name="cuenta_padre_id">
                    <option value="">Sin cuenta padre (cuenta raíz)</option>
                    <?php foreach ($cuentasPadreFormulario as $cuentaPadre): ?>
                        <option value="<?= (int)$cuentaPadre["id"] ?>"
                            <?= (string)$valoresFormulario["cuenta_padre_id"] === (string)$cuentaPadre["id"] ? "selected" : "" ?>>
                            <?= htmlspecialchars($cuentaPadre["codigo"] . " - " . $cuentaPadre["nombre"], ENT_QUOTES, "UTF-8") ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="permite_movimientos">¿Permite registrar movimientos?</label>
                <select id="permite_movimientos" name="permite_movimientos" required>
                    <option value="1" <?= $valoresFormulario["permite_movimientos"] === "1" ? "selected" : "" ?>>Sí</option>
                    <option value="0" <?= $valoresFormulario["permite_movimientos"] === "0" ? "selected" : "" ?>>No (cuenta agrupadora)</option>
                </select>
            </div>
        </div>
        <div class="acciones-formulario">
            <button type="button" class="boton-secundario" data-cerrar-modal>Cancelar</button>
            <button type="submit"><?= $idEdicion ? "Guardar cambios" : "Guardar cuenta" ?></button>
        </div>
    </form>
</dialog>

<dialog id="modal-detalle" class="modal-cuenta modal-detalle">
    <div class="cabecera-modal">
        <div>
            <h2>Detalles de la cuenta</h2>
            <p>Información registrada en el catálogo.</p>
        </div>
        <button type="button" class="cerrar-modal" data-cerrar-modal aria-label="Cerrar">×</button>
    </div>
    <dl class="detalle-cuenta">
        <div><dt>Código</dt><dd data-detalle="codigo"></dd></div>
        <div><dt>Nombre</dt><dd data-detalle="nombre"></dd></div>
        <div><dt>Tipo</dt><dd data-detalle="tipo"></dd></div>
        <div><dt>Naturaleza</dt><dd data-detalle="naturaleza"></dd></div>
        <div><dt>Cuenta padre</dt><dd data-detalle="padre"></dd></div>
        <div><dt>Permite movimientos</dt><dd data-detalle="movimientos"></dd></div>
        <div><dt>Estado</dt><dd data-detalle="estado"></dd></div>
    </dl>
</dialog>

<script src="../assets/js/menu.js"></script>
<script src="../assets/js/catalogo-cuentas.js"></script>
</body>
</html>
