# API PHP (Slim) servida pelo Apache.
# No Railway este servico NAO tem dominio publico: so o frontend fala com ele pela rede privada.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Producao: sem erros na resposta; $_ENV preenchido com as variaveis do container
RUN { \
      echo 'variables_order = "EGPCS"'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/app.ini \
    && echo 'ServerTokens Prod' >> /etc/apache2/conf-available/security.conf \
    && echo 'ServerSignature Off' >> /etc/apache2/conf-available/security.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-enabled/servername.conf

# DocumentRoot em public/ e toda rota desconhecida vai para o index.php do Slim
RUN { \
      echo '<VirtualHost *:${PORT}>'; \
      echo '    DocumentRoot /var/www/html/public'; \
      echo '    <Directory /var/www/html/public>'; \
      echo '        AllowOverride None'; \
      echo '        Require all granted'; \
      echo '        FallbackResource /index.php'; \
      echo '    </Directory>'; \
      echo '    ErrorLog /proc/self/fd/2'; \
      echo '    CustomLog /proc/self/fd/1 combined'; \
      echo '</VirtualHost>'; \
    } > /etc/apache2/sites-available/000-default.conf \
    && echo 'Listen ${PORT}' > /etc/apache2/ports.conf

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
# Remove CRLF caso o script tenha sido salvo no Windows
RUN sed -i 's/[[:cntrl:]]$//' docker-entrypoint.sh && chmod +x docker-entrypoint.sh

# Railway injeta PORT; 8080 e o padrao local. "Listen 8080" escuta em IPv4 e IPv6
# (a rede privada do Railway usa IPv6).
ENV PORT=8080 APP_ENV=production APP_DEBUG=false
EXPOSE 8080

ENTRYPOINT ["./docker-entrypoint.sh"]
CMD ["apache2-foreground"]
