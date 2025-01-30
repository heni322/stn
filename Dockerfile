# Use PHP 8.2 FPM as base image
FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    supervisor \
    cron \
    libzip-dev \
    libonig-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install pdo_mysql \
    bcmath \
    gd \
    xml \
    opcache \
    zip \
    mbstring

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Copy configuration files
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/crontab /etc/cron.d/laravel-cron

# Setup cron job
RUN chmod 0644 /etc/cron.d/laravel-cron && \
    crontab /etc/cron.d/laravel-cron && \
    touch /var/log/cron.log

# Install dependencies
RUN composer install --optimize-autoloader --no-dev

# Generate application key
RUN php artisan key:generate

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Create supervisor log directory
RUN mkdir -p /var/log/supervisor

# Expose port 9000
EXPOSE 9000

# Start PHP-FPM, cron, and Supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
