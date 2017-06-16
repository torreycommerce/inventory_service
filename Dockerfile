FROM quay.io/torreycommerce/service_platform:latest

ENV ACENDA_MODE production

USER root

COPY . /app/services/inventory_service

WORKDIR /app/services/inventory_service

RUN composer install

USER www-data

CMD ["php", "-f", "/app/services/inventory_service/worker.php" ]
