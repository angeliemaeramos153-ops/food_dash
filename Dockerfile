FROM php:8.2-apache
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql
RUN a2enmod rewrite
WORKDIR /var/www/html
COPY . /var/www/html/
RUN if [ -d "/var/www/html/food_dash" ]; then cp -r /var/www/html/food_dash/* /var/www/html/ 2>/dev/null || true; fi
RUN if [ ! -e "/var/www/html/food_dash" ]; then ln -s /var/www/html /var/www/html/food_dash 2>/dev/null || true; fi
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html
EXPOSE 80
CMD ["apache2-foreground"]