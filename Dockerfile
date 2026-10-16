FROM webdevops/php-apache:8.4
ENV PHP_DATE_TIMEZONE=Europe/Rome
ENV TZ=Europe/Rome
ENV PUID=1000
ENV PGID=1000
ENV WEB_DOCUMENT_ROOT='/app/public/'
ENV php.session.cookie_secure=false
ENV php.memory_limit=256M
# L'immagine e' pronta per la produzione; in sviluppo docker-compose.yml monta ./app e forza APP_ENV=dev.
ENV APP_ENV=prod

RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"

COPY app /app
WORKDIR /app

# vendor/ e gli asset JS non sono nel repo: si ricostruiscono qui.
RUN composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --no-scripts \
    && php bin/console importmap:install \
    && php bin/console asset-map:compile \
    && php bin/console cache:warmup \
    && chmod -R 777 var

# Migration e primo admin all'avvio (vedi il file): l'entrypoint webdevops esegue questi script da root.
COPY docker/entrypoint.d/ /opt/docker/provision/entrypoint.d/
