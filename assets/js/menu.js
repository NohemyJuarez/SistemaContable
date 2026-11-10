document.addEventListener("DOMContentLoaded", function () {

    // Marca como activo el enlace de la página actual
    const paginaActual = location.pathname.split("/").pop() || "index.php";

    document.querySelectorAll(".menu-lateral nav a").forEach(function (enlace) {
        const destino = enlace.getAttribute("href").split("/").pop();

        if (destino === paginaActual) {
            enlace.classList.add("activo");
        }
    });

    const menu = document.querySelector(".menu-lateral");
    const boton = document.querySelector("#btn-menu");

    if (!menu || !boton) {
        return;
    }

    boton.addEventListener("click", function () {
        menu.classList.toggle("menu-contraido");

        const contraido = menu.classList.contains("menu-contraido");

        boton.setAttribute("aria-expanded", String(!contraido));
        boton.setAttribute(
            "aria-label",
            contraido ? "Expandir menú" : "Contraer menú"
        );
    });

});