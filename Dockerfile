FROM php:8.2-apache

# Driver de PostgreSQL para PHP
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*

# Configuración de PHP para producción (no muestra errores en pantalla)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Apache: no mostrar el listado de carpetas
RUN sed -i 's/Options Indexes FollowSymLinks/Options FollowSymLinks/' /etc/apache2/apache2.conf

# Código de la aplicación
COPY . /var/www/html/

# Script de arranque (crea config/conexion.php con las variables de entorno)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && rm -rf /var/www/html/docker \
    && chown -R www-data:www-data /var/www/html

ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
