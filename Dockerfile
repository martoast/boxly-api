FROM ubuntu:22.04

LABEL maintainer="Taylor Otwell"

ARG WWWGROUP=1000
ARG NODE_VERSION=20
ARG MYSQL_CLIENT="mysql-client"
ARG POSTGRES_VERSION=15

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND noninteractive
ENV TZ=UTC
ENV SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=80 --no-reload"
ENV PHP_CLI_SERVER_WORKERS=8
ENV SUPERVISOR_PHP_USER="sail"
ENV PGSSLCERT=/tmp/postgresql.crt

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# The ondrej PPA answers 503 under load (it did on PHP2Shell release day):
# retry each download instead of failing the whole deploy on the first one,
# and don't let a hung connection sit for apt's default two minutes.
RUN printf 'Acquire::Retries "10";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\n' \
    > /etc/apt/apt.conf.d/80-retries

RUN apt-get update \
    && mkdir -p /etc/apt/keyrings \
    && apt-get install -y gnupg gosu curl ca-certificates zip unzip git supervisor sqlite3 libcap2-bin libpng-dev python2 dnsutils librsvg2-bin fswatch ffmpeg nano  \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c' | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu jammy main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && PHP_PKGS="php8.3-cli php8.3-dev \
       php8.3-pgsql php8.3-sqlite3 php8.3-gd \
       php8.3-curl \
       php8.3-imap php8.3-mysql php8.3-mbstring \
       php8.3-xml php8.3-zip php8.3-bcmath php8.3-soap \
       php8.3-intl php8.3-readline \
       php8.3-ldap \
       php8.3-msgpack php8.3-igbinary php8.3-redis \
       php8.3-memcached php8.3-pcov php8.3-imagick php8.3-xdebug" \
    # Download in rounds: apt keeps every .deb it already got, so each round
    # only fetches what the busy PPA refused last time. update is inside the
    # loop because a 503 on the index is only a warning to apt-get update.
    && for round in 1 2 3 4 5 6 7 8 9 10 11 12; do \
         apt-get update && apt-get install -y --download-only $PHP_PKGS && break; \
         echo "PHP PPA busy (round $round), retrying in 30s"; sleep 30; \
       done \
    && apt-get install -y $PHP_PKGS \
       && pecl install swoole-5.1.2 \
       && echo "extension=swoole.so" > /etc/php/8.3/cli/conf.d/20-swoole.ini \
    # PHP2Shell (CVE-2026-109618/109619): artisan serve puts PHP itself on the
    # internet, so never ship an 8.3 older than 8.3.36. Living inside this RUN
    # (not a later one) also means changing it busts the cached apt layer.
    && { php -r 'exit(version_compare(PHP_VERSION, "8.3.36", ">=") ? 0 : 1);' \
         || { echo "PHP $(php -r 'echo PHP_VERSION;') is vulnerable (PHP2Shell), need >= 8.3.36"; exit 1; }; } \
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_$NODE_VERSION.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y nodejs \
    && npm install -g npm \
    && npm install -g pnpm \
    && npm install -g bun \
    && curl -sS https://dl.yarnpkg.com/debian/pubkey.gpg | gpg --dearmor | tee /etc/apt/keyrings/yarn.gpg >/dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/yarn.gpg] https://dl.yarnpkg.com/debian/ stable main" > /etc/apt/sources.list.d/yarn.list \
    && curl -sS https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor | tee /etc/apt/keyrings/pgdg.gpg >/dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt jammy-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y yarn \
    && apt-get install -y $MYSQL_CLIENT \
    && apt-get install -y postgresql-client-$POSTGRES_VERSION \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN setcap "cap_net_bind_service=+ep" /usr/bin/php8.3

RUN groupadd --force -g $WWWGROUP sail
RUN useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u 1337 sail

COPY . /var/www/html
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY php.ini /etc/php/8.3/cli/conf.d/99-sail.ini

RUN chown -R sail:sail /var/www/html
RUN chmod -R 775 storage
RUN chmod -R 775 bootstrap/cache

USER sail

RUN composer install --no-dev --optimize-autoloader --no-scripts

USER root

# Create a startup script that runs migrations before starting supervisor
RUN echo '#!/bin/bash\n\
php artisan migrate --force\n\
exec /usr/bin/supervisord' > /start.sh && chmod +x /start.sh

CMD ["/start.sh"]