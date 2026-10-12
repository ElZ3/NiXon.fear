FROM php:8.2-apache
# Instala dependencias y el driver de MongoDB para PHP
RUN apt-get update && apt-get install -y libssl-dev \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb
# Habilita mod_rewrite para tu estructura MVC
RUN a2enmod rewrite
# DocumentRoot → public/ (el juego y el panel usan rutas relativas a public/)
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf