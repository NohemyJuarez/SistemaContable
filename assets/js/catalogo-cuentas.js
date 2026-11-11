(() => {
    const modalCuenta = document.getElementById("modal-cuenta");
    const modalDetalle = document.getElementById("modal-detalle");
    const buscar = document.getElementById("buscar-cuenta");
    const filtroTipo = document.getElementById("filtrar-tipo");
    const filas = Array.from(document.querySelectorAll("#filas-cuentas tr[data-tipo]"));
    const sinResultados = document.getElementById("sin-resultados");
    const contador = document.getElementById("contador-cuentas");

    function cerrarMenuAcciones(excepto) {
        document.querySelectorAll(".menu-acciones[open]").forEach((menu) => {
            if (menu !== excepto) {
                menu.removeAttribute("open");
            }
        });
    }

    function filtrarCuentas() {
        const termino = buscar.value.trim().toLocaleLowerCase();
        const tipo = filtroTipo.value;
        let visibles = 0;

        filas.forEach((fila) => {
            const coincideTexto = fila.dataset.busqueda.toLocaleLowerCase().includes(termino);
            const coincideTipo = tipo === "" || fila.dataset.tipo === tipo;
            const mostrar = coincideTexto && coincideTipo;
            fila.hidden = !mostrar;
            visibles += mostrar ? 1 : 0;
        });

        sinResultados.hidden = visibles > 0;
        contador.textContent = `${visibles} ${visibles === 1 ? "cuenta" : "cuentas"}`;
    }

    document.getElementById("abrir-formulario")?.addEventListener("click", () => {
        modalCuenta.showModal();
    });

    if (modalCuenta.dataset.abrir === "true") {
        modalCuenta.showModal();
    }

    document.querySelectorAll("[data-cerrar-modal]").forEach((boton) => {
        boton.addEventListener("click", () => boton.closest("dialog").close());
    });

    document.querySelectorAll(".ver-detalle").forEach((boton) => {
        boton.addEventListener("click", () => {
            cerrarMenuAcciones();
            ["codigo", "nombre", "tipo", "naturaleza", "padre", "movimientos", "estado"]
                .forEach((campo) => {
                    modalDetalle.querySelector(`[data-detalle="${campo}"]`).textContent =
                        boton.dataset[campo];
                });
            modalDetalle.showModal();
        });
    });

    document.querySelectorAll(".menu-acciones").forEach((menu) => {
        menu.addEventListener("toggle", () => {
            if (menu.open) {
                cerrarMenuAcciones(menu);
            }
        });
    });

    document.addEventListener("click", (evento) => {
        if (!evento.target.closest(".menu-acciones")) {
            cerrarMenuAcciones();
        }
    });

    buscar.addEventListener("input", filtrarCuentas);
    filtroTipo.addEventListener("change", filtrarCuentas);
    filtrarCuentas();
})();
