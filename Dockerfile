FROM php:8.2-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev unzip \
    && rm -rf /var/lib/apt/lists/*

# Fix MPM
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true && a2enmod mpm_prefork

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql mysqli zip opcache

# Apache modules
RUN a2enmod rewrite headers ssl deflate expires

# Redis
RUN pecl install redis && docker-php-ext-enable redis

# ============================================
# KEY FIX: Override default Apache config
# ============================================
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Copy files
WORKDIR /var/www/html
COPY . /var/www/html/

# Permissions
RUN mkdir -p storage/logs storage/cache storage/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage

EXPOSE 80
CMD ["apache2-foreground"]
