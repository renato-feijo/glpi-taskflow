# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# GLPI-TaskFlow — Dockerfile de produção (multi-stage)
# Baseado na versão do fork validada em: GLPI 11.0.9-dev (composer.json: php >=8.2)
# ---------------------------------------------------------------------------

ARG PHP_VERSION=8.4

# Lista única de extensões, compartilhada pelos dois stages. O builder precisa
# delas para o composer passar na checagem de plataforma (ext-bcmath, ext-mysqli
# e cia.); mantê-las em um só lugar evita que os stages divirjam.
# O script é baixado da release em vez de vir da imagem mlocati/php-extension-installer:
# a imagem publicada (2 meses) traz um script que falha ao instalar apcu porque o
# endpoint REST do pecl.php.net responde 404. A release 2.11.12 contorna isso.
ARG IPE_VERSION=2.11.12

ARG PHP_EXTENSIONS="apcu bcmath bz2 curl exif gd intl ldap mbstring mysqli opcache simplexml sockets soap xml zip"

# ---------------------------------------------------------------------------
# Stage 1: build de dependências (composer + npm) com o código-fonte completo
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli AS builder

# Extensões via install-php-extensions: resolve sozinho as libs de sistema
# (libonig para mbstring, libicu para intl, libldap, libzip...), o que evita
# ter de rastrear cada -dev na mão e funciona igual em amd64 e arm64.
ARG IPE_VERSION
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/${IPE_VERSION}/install-php-extensions /usr/local/bin/install-php-extensions
ARG PHP_EXTENSIONS
RUN install-php-extensions ${PHP_EXTENSIONS}

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates gettext \
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

# Traduções: os tarballs de release do GLPI trazem os .mo prontos, mas quem
# builda do código-fonte precisa compilá-los. Sem eles, database:install aborta
# com "Could not find or open file /var/www/glpi/locales/en_GB.mo".
# Usamos msgfmt direto porque bin/console tools:locales:compile vive sob
# Glpi\Tools\, que é autoload-dev e portanto ausente num install --no-dev.
RUN set -eu; \
    for po in locales/*.po; do msgfmt "$po" -o "${po%.po}.mo"; done; \
    echo "compilados: $(ls -1 locales/*.mo | wc -l) arquivos .mo"

# Assets front-end
RUN npm ci \
    && npm run build:pack \
    && npm run build:vue

# ---------------------------------------------------------------------------
# Stage 2: imagem final enxuta de execução
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-apache AS runtime

# Extensões PHP exigidas pelo GLPI 11
# https://github.com/mlocati/docker-php-extension-installer
ARG IPE_VERSION
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/${IPE_VERSION}/install-php-extensions /usr/local/bin/install-php-extensions
ARG PHP_EXTENSIONS
RUN install-php-extensions ${PHP_EXTENSIONS}

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
