# استفاده از تصویر پایه PHP 7.4 با Apache
FROM php:7.4-apache

# نصب وابستگی‌های مورد نیاز
RUN apt-get update && apt-get install -y \
    libssl-dev \
    libcurl4-openssl-dev \
    pkg-config \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# نصب افزونه‌های PHP
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && docker-php-ext-install curl

# نصب Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# کپی فایل‌های پروژه
COPY . /var/www/html

# نصب وابستگی‌های PHP
RUN composer install --no-dev --optimize-autoloader

# تنظیمات Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN a2enmod rewrite
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# تنظیم پورت
ENV PORT=1000
EXPOSE $PORT

# تغییر پورت Apache
RUN sed -i "s/Listen 80/Listen \$PORT/" /etc/apache2/ports.conf
RUN sed -i "s/:80/:$PORT/" /etc/apache2/sites-available/000-default.conf

# اجرای Apache
CMD ["apache2-foreground"]
