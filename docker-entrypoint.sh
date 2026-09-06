#!/bin/bash
set -euo pipefail

cd /var/www/glpi

# Espera o banco responder antes de tentar instalar/atualizar
echo "Aguardando banco de dados em ${GLPI_DB_HOST}:${GLPI_DB_PORT:-3306}..."
for i in $(seq 1 30); do
    if php -r "
        \$ok = @mysqli_connect(getenv('GLPI_DB_HOST'), getenv('GLPI_DB_USER'), getenv('GLPI_DB_PASSWORD'), '', (int) (getenv('GLPI_DB_PORT') ?: 3306));
        exit(\$ok ? 0 : 1);
    "; then
        echo "Banco de dados disponível."
        break
    fi
    sleep 2
done

CONFIG_FILE="/var/www/glpi/config/config_db.php"

# Apenas um serviço pode inicializar o banco. app e cron compartilham o volume
# glpi_config e rodam este mesmo entrypoint: se ambos executarem database:install
# eles competem e a importação dos dados padrão falha com chave duplicada.
if [ "${GLPI_SKIP_DB_INIT:-0}" = "1" ]; then
    echo "Este serviço não inicializa o banco; aguardando config_db.php..."
    for _ in $(seq 1 120); do
        [ -f "$CONFIG_FILE" ] && break
        sleep 5
    done
    if [ ! -f "$CONFIG_FILE" ]; then
        echo "config_db.php não apareceu no tempo esperado; abortando." >&2
        exit 1
    fi
    echo "Configuração encontrada."
elif [ ! -f "$CONFIG_FILE" ]; then
    echo "Nenhuma configuração de banco encontrada — executando instalação inicial..."
    php bin/console database:install \
        --no-interaction --allow-superuser \
        --db-host="${GLPI_DB_HOST}" \
        --db-port="${GLPI_DB_PORT:-3306}" \
        --db-name="${GLPI_DB_NAME}" \
        --db-user="${GLPI_DB_USER}" \
        --db-password="${GLPI_DB_PASSWORD}" \
        --default-language="${GLPI_DEFAULT_LANGUAGE:-pt_BR}"
else
    echo "Configuração de banco já existe — verificando atualizações de schema..."
    php bin/console database:update --no-interaction --allow-superuser --allow-unstable || true
fi

# Instala os temas customizados dentro do volume (a imagem os guarda em /opt/glpi-themes,
# pois /var/www/glpi/files é um VOLUME e sobrescreveria o que veio na imagem).
if [ -d /opt/glpi-themes ]; then
    echo "Instalando temas customizados em files/_themes..."
    mkdir -p /var/www/glpi/files/_themes
    cp -f /opt/glpi-themes/*.scss /var/www/glpi/files/_themes/ 2>/dev/null || true
    # O GLPI cacheia a lista de temas; limpa para o tema aparecer nas preferências.
    php bin/console cache:clear --no-interaction --allow-superuser || true
fi

chown -R www-data:www-data /var/www/glpi/files /var/www/glpi/config /var/www/glpi/marketplace 2>/dev/null || true

exec "$@"
