FROM php:7.4-apache
RUN apt-get update && apt-get install -y \
    libssl-dev \
    libcurl4-openssl-dev \
    pkg-config \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && docker-php-ext-install curl
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
COPY . /var/www/html
RUN composer install --no-dev --optimize-autoloader
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN a2enmod rewrite
COPY apache.conf /etc/apache2/sites-available/000-default.conf
ENV PORT=1000
EXPOSE $PORT
RUN sed -i "s/Listen 80/Listen \$PORT/" /etc/apache2/ports.conf
RUN sed -i "s/:80/:$PORT/" /etc/apache2/sites-available/000-default.conf
CMD ["apache2-foreground"]
