
document.addEventListener("DOMContentLoaded", function () {

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
