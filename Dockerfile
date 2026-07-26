FROM php:8.4.22-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-0 libsqlite3-dev tzdata \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite headers \
    && apt-get purge -y --auto-remove libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides in the document root
RUN printf '%s\n' \
    '<Directory /var/www/html/public>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/portal.conf \
    && a2enconf portal

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p /var/www/html/data /var/www/html/public/uploads/icons \
    && chown -R www-data:www-data /var/www/html/data /var/www/html/public/uploads \
    && chmod +x /var/www/html/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
