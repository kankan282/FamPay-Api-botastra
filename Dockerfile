FROM php:8.1-apache

# ---------------------------------------------------------------------------
# System dependencies
# ---------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
    libc-client-dev \
    libkrb5-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------------------
# PHP extensions (mbstring + json are already bundled in the official image)
# ---------------------------------------------------------------------------
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
 && docker-php-ext-install -j"$(nproc)" \
    pdo \
    pdo_pgsql \
    imap \
    gd \
    zip \
    opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache modules
RUN a2enmod rewrite headers

WORKDIR /var/www/html

# Install dependencies first so Docker can cache this layer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction \
 || composer update --no-dev --optimize-autoloader --no-scripts --no-interaction

# Application code
COPY . .

# Apache virtual host + entrypoint
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# PHP production settings
RUN { \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
      echo 'expose_php=Off'; \
      echo 'memory_limit=256M'; \
      echo 'max_execution_time=60'; \
      echo 'upload_max_filesize=8M'; \
      echo 'post_max_size=8M'; \
      echo 'date.timezone=Asia/Kolkata'; \
    } > /usr/local/etc/php/conf.d/zz-fampay.ini

RUN chown -R www-data:www-data /var/www/html \
 && chmod -R 755 /var/www/html

# Render injects PORT (default 10000); Apache is reconfigured at boot.
ENV PORT=80
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
  CMD curl -fsS "http://127.0.0.1:${PORT}/" > /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
