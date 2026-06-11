FROM php:8.2-apache

# ============================================
# MPM FIX - Remove conflicting modules
# ============================================
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/* \
    && a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd \
    pdo \
    pdo_mysql \
    mysqli \
    zip \
    opcache

# Enable Apache modules
RUN a2enmod rewrite headers ssl deflate expires

# Install Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Set working directory
WORKDIR /var/www/html

# Copy all files
COPY . /var/www/html/

# Create storage directories
RUN mkdir -p /var/www/html/storage/logs \
    /var/www/html/storage/cache \
    /var/www/html/storage/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage

# Apache configuration
RUN echo '<Directory /var/www/html>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n</Directory>' > /etc/apache2/conf-available/app.conf \
    && a2enconf app

EXPOSE 80
CMD ["apache2-foreground"]
