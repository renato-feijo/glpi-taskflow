# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# GLPI-TaskFlow — Dockerfile de produção (multi-stage)
# Baseado na versão do fork validada em: GLPI 11.0.9-dev (composer.json: php >=8.2)
# ---------------------------------------------------------------------------

ARG PHP_VERSION=8.4

# ---------------------------------------------------------------------------
# Stage 1: build de dependências (composer + npm) com o código-fonte completo
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli AS builder

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libldap2-dev libxml2-dev \
    && docker-php-ext-configure ldap \
    && docker-php-ext-install -j"$(nproc)" zip intl ldap xml mbstring \
    && rm -rf /var/lib/apt/lists/*

# Node.js 20.x (para o build dos assets front-end)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /build
COPY . .

# Dependências PHP de produção (sem dev), autoload otimizado
RUN composer install \
        --no-dev --optimize-autoloader --prefer-dist \
        --no-interaction --no-progress

# Assets front-end (webpack + traduções de ilustrações)
RUN npm ci \
    && npm run build:pack \
    && npm run build:vue

# ---------------------------------------------------------------------------
# Stage 2: imagem final enxuta de execução
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-apache AS runtime

# Extensões PHP exigidas pelo GLPI 11
# https://github.com/mlocati/docker-php-extension-installer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        apcu bz2 curl exif gd intl ldap mbstring mysqli \
        opcache simplexml sockets soap xml zip

# Configuração recomendada de OPcache/APCu para produção
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'apc.enable_cli=1'; \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=64M'; \
        echo 'post_max_size=64M'; \
    } > /usr/local/etc/php/conf.d/glpi-production.ini

RUN a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!/var/www/glpi/public!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!/var/www/glpi/!g' /etc/apache2/apache2.conf

WORKDIR /var/www/glpi

# Código-fonte + vendor + assets já compilados no stage anterior
COPY --from=builder /build /var/www/glpi

# Temas customizados: guardados FORA de /var/www/glpi/files, porque esse caminho é
# um VOLUME e seria mascarado em runtime. O entrypoint copia para dentro do volume.
COPY files/_themes/ /opt/glpi-themes/

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/glpi

VOLUME ["/var/www/glpi/files", "/var/www/glpi/config", "/var/www/glpi/marketplace"]

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
