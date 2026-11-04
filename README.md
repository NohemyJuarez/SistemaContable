# Sistema Contable

Sistema web de facturación y control de ventas dirigido a micro y pequeñas empresas de El Salvador.


## 1. Tecnologías utilizadas

- PHP 8.2
- PostgreSQL 18
- pgAdmin 4
- HTML5
- CSS3
- JavaScript
- XAMPP
- Visual Studio Code

## 2. Requisitos

se necesita instalar:

- XAMPP con Apache y PHP.
- PostgreSQL.
- pgAdmin 4.
- Git.

## 3. Descargar el proyecto

Clonar el repositorio desde la terminal:

    git clone https://github.com/NohemyJuarez/SistemaContable.git

Colocar la carpeta del proyecto dentro de:

    C:\xampp\htdocs\

## 4. Crear la base de datos

Abrir pgAdmin 4 y crear una base de datos llamada:

    sistema_contable

## 5. Crear las tablas

Abrir Query Tool en la base de datos sistema_contable.

Ejecutar el archivo:

    database/esquema.sql

Este archivo crea las tablas, relaciones y restricciones de la base de datos.

## 6. Insertar datos iniciales

Después de ejecutar el esquema, ejecutar:

    database/datos_iniciales.sql

Este archivo registra el IVA y los métodos de pago iniciales.

## 7. Configurar la conexión

Cada integrante debe crear su propio archivo:

    config/conexion.php

La configuración debe utilizar sus propios datos locales de PostgreSQL.

## 8. Ejecutar el sistema

1. Iniciar Apache desde XAMPP.
2. Verificar que PostgreSQL esté funcionando.
3. Abrir el navegador.
4. Ingresar a:

    http://localhost/SistemaContable/

## 9. Estructura del proyecto

    SistemaContable/
    |
    |-- assets/
    |   |-- css/
    |   |-- js/
    |   |-- img/
    |
    |-- config/
    |
    |-- database/
    |   |-- esquema.sql
    |   |-- datos_iniciales.sql
    |
    |-- modulos/
    |
    |-- index.php
    |-- README.md



