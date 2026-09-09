FROM php:8.3-apache

RUN docker-php-ext-install mysqli

ENV VBOX_BASE_URL=http://localhost:8080 \
    VBOX_FORCE_HTTPS=0 \
    VBOX_CRON_SECRET= \
    VBOX_METRICS_RETENTION_HOURS=6 \
    VBOX_ENCRYPTION_KEY= \
    VBOX_WEBHOOK_URL= \
    VBOX_WEBHOOK_SECRET= \
    VBOX_DB_HOST=db \
    VBOX_DB_NAME=vmange \
    VBOX_DB_USER=vmange \
    VBOX_DB_PASS= \
    VBOX_MAIL_FROM= \
    VBOX_SMTP_HOST= \
    VBOX_SMTP_PORT=587 \
    VBOX_SMTP_USERNAME= \
    VBOX_SMTP_PASSWORD= \
    VBOX_SMTP_ENCRYPTION=tls \
    VBOX_IMAP_HOST= \
    VBOX_IMAP_PORT=993 \
    VBOX_IMAP_USERNAME= \
    VBOX_IMAP_PASSWORD= \
    VBOX_IMAP_ENCRYPTION=ssl

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html/storage \
    && a2enmod headers

EXPOSE 80
