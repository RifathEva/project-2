FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

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

# Create storage directories with proper permissions
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

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
