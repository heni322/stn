# Use an official PHP image with Apache as the base image.
FROM php:8.3.9-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    gnupg \
    curl \
    nano \
    g++ \
    git \
    libbz2-dev \
    libfreetype6-dev \
    libicu-dev \
    libjpeg-dev \
    libmcrypt-dev \
    libpng-dev \
    libreadline-dev \
    sudo \
    unzip \
    zip \
    libonig-dev \
    libzip-dev \
    cron \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# Install and enable GD extension
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# Install and enable Redis extension
RUN pecl install redis && \
    docker-php-ext-enable redis

# Apache config and setting document root
RUN echo "ServerName 127.0.0.1" >> /etc/apache2/apache2.conf

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Enable mod_rewrite and mod_headers
RUN a2enmod rewrite headers

# Set PHP configuration
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY custom-php.ini /usr/local/etc/php/conf.d/

# Install PHP extensions
RUN docker-php-ext-install \
    bcmath \
    bz2 \
    calendar \
    iconv \
    intl \
    mbstring \
    opcache \
    pdo_mysql \
    zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set user to avoid permissions issues between the host and container
ARG uid=1000
RUN useradd -G www-data,root -u ${uid} -d /home/devuser devuser \
    && mkdir -p /home/devuser/.composer \
    && chown -R devuser:devuser /home/devuser
# Set working directory to Laravel project
WORKDIR /var/www/html

# Copy Laravel files
COPY . .

# Install Laravel dependencies using Composer
RUN composer install --no-interaction --optimize-autoloader

# Set permissions for Laravel directories
RUN chown -R www-data:www-data storage bootstrap/cache

# Add and configure cron jobs
COPY ./crontab /etc/cron.d/crontab
RUN chmod 0644 /etc/cron.d/crontab
RUN crontab /etc/cron.d/crontab

# Create the log file for cron
RUN touch /var/log/cron.log

# Configure Supervisor
COPY ./supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Expose port 8000 for Apache
EXPOSE 8000

# Start the services
CMD /usr/bin/supervisord && cron && tail -f /var/log/cron.log
