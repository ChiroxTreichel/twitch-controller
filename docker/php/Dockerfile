FROM php:8.4-apache

# Vereinigte Extension-Liste der frueheren drei Images:
#   gd (+freetype/jpeg/webp) .. Alert-/Overlay-Grafiken
#   pdo_pgsql, pgsql ......... Event-Datenbank
#   intl, zip, mbstring ...... Spendenseite
#   opcache .................. Performance
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpng-dev \
      libjpeg62-turbo-dev \
      libfreetype6-dev \
      libwebp-dev \
      libpq-dev \
      libzip-dev \
      libicu-dev \
      libonig-dev \
      ca-certificates \
      git \
  && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
  && docker-php-ext-install -j"$(nproc)" gd intl zip opcache pdo_pgsql pgsql \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

# sodium ist im offiziellen Image normalerweise schon aktiv. Falls nicht,
# hier nachinstallieren - ohne sodium laesst sich nichts verschluesseln
# und die Einrichtung bricht ab.
RUN php -m | grep -qi '^sodium$' \
  || ( apt-get update \
       && apt-get install -y --no-install-recommends libsodium-dev \
       && docker-php-ext-install sodium \
       && apt-get clean && rm -rf /var/lib/apt/lists/* )

# rewrite  .. alles auf den Front-Controller public/index.php
# remoteip .. echte Client-IP hinter Caddy/NPM
# proxy*   .. fuer Plugins, die einen eigenen Dienst weiterreichen
#             (z.B. ein WebSocket-Server fuer Overlays)
RUN a2enmod rewrite remoteip proxy proxy_http proxy_wstunnel headers

COPY php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
EXPOSE 80
CMD ["apache2-foreground"]
