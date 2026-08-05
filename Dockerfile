FROM php:8.2-apache

# Install PostgreSQL client and PDO drivers
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

# mod_rewrite for routing; mod_headers for the security headers in
# public/.htaccess -- without it that <IfModule> block silently does nothing.
RUN a2enmod rewrite headers

# Point Apache at public/ and allow .htaccess overrides.
# NOTE: a sed on 000-default.conf cannot do this -- the <Directory> block that
# carries AllowOverride lives in apache2.conf, not the site config. Ship a vhost.
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# Composer (PSR-4 autoloading)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies first so the layer caches independently of source changes
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

# Copy project files
COPY . .

RUN composer dump-autoload --no-dev --optimize

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Applies migrations when RUN_MIGRATIONS=1, then hands off to Apache
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/health") ? 0 : 1);'
