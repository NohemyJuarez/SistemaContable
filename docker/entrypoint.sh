#!/bin/bash
set -e

# 1) Apache debe escuchar en el puerto que asigna la plataforma (variable PORT)
PORT="${PORT:-10000}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# 2) Crear config/conexion.php a partir de las variables de entorno
mkdir -p /var/www/html/config
cat > /var/www/html/config/conexion.php <<'PHP'
<?php
$host     = getenv("DB_HOST");
$port     = getenv("DB_PORT") ?: "5432";
$dbname   = getenv("DB_NAME");
$user     = getenv("DB_USER");
$password = getenv("DB_PASSWORD");
$sslmode  = getenv("DB_SSLMODE") ?: "require";

try {
    $conexion = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode",
        $user,
        $password
    );
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Error de conexión: " . $e->getMessage());
    die("No se pudo conectar con la base de datos.");
}
PHP
chown www-data:www-data /var/www/html/config/conexion.php

exec "$@"
