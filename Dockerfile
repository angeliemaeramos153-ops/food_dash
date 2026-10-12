FROM php:8.2-apache

# Install PostgreSQL driver extension for PHP PDO
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Copy project source code into Apache document root
COPY . /var/www/html/

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Expose HTTP port
EXPOSE 80

CMD ["apache2-foreground"]