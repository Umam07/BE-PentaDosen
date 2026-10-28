# ==============================================================================
# Dockerfile for Laravel 12 on Render (PentaDosen Backend)
# ==============================================================================
FROM php:8.3-fpm-bookworm

LABEL maintainer="PentaDosen Team"
LABEL description="Production Docker image for PentaDosen Laravel 12 Backend on Render"

ENV DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER=1

# 1. Install system utilities and libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    curl \
    git \
    unzip \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    gettext-base \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# 2. Configure and install required PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        zip \
        opcache \
        gd \
        bcmath

# 3. Get official Composer binary
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 4. Set application working directory
WORKDIR /var/www/html

# 5. Copy composer dependencies definition first to leverage Docker build cache
COPY composer.json composer.lock ./

# 6. Install PHP production dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --prefer-dist

# 7. Copy application source code
COPY . .

# 8. Dump optimized autoload
RUN composer dump-autoload --optimize --no-dev

# 9. Configure Nginx, Supervisor, PHP, and Entrypoint
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini $PHP_INI_DIR/conf.d/custom.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# 10. Configure directories and permissions
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/www/html/storage/framework/sessions \
    && mkdir -p /var/www/html/storage/framework/views \
    && mkdir -p /var/www/html/storage/framework/cache \
    && mkdir -p /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Remove default site config to prevent conflicts
RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf

# Render binds to dynamic port ($PORT, defaults to 10000)
EXPOSE 10000

# Start container via entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
