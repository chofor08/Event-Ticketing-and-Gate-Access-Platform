   FROM php:8.3-apache

   RUN apt-get update && apt-get install -y \
       git unzip libpq-dev libzip-dev libpng-dev libonig-dev \
       && docker-php-ext-install pdo pdo_pgsql pdo_mysql zip gd mbstring bcmath \
       && a2enmod rewrite

   COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

   ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
   RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
       /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

   WORKDIR /var/www/html
   COPY . .

   RUN composer install --no-dev --optimize-autoloader --no-interaction
   RUN chown -R www-data:www-data storage bootstrap/cache

   COPY docker/start.sh /start.sh
   RUN chmod +x /start.sh
   CMD ["/start.sh"]
