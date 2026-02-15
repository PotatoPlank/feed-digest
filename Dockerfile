FROM dunglas/frankenphp:1-php8.4

WORKDIR /app

VOLUME /app/var/

RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends \
		file \
		git \
	; \
	rm -rf /var/lib/apt/lists/*; \
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		zip \
	;


ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

#COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
#COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
#COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile


ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

#COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link --exclude=frankenphp/ . ./

RUN set -eux; \
	mkdir -p var/cache var/log var/share; \
	composer dump-autoload --classmap-authoritative --no-dev; \
	if [ -f importmap.php ]; then \
		php bin/console asset-map:compile; \
	fi; \

#RUN set -eux; \
#	mkdir -p var/cache var/log var/share; \
#	composer dump-autoload --classmap-authoritative --no-dev; \
#	composer dump-env prod; \
#	composer run-script --no-dev post-install-cmd; \
#	if [ -f importmap.php ]; then \
#		php bin/console asset-map:compile; \
#	fi; \
#	chmod +x bin/console; sync;
