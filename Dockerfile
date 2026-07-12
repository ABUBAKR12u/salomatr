FROM php:8.2-apache
RUN apt-get update && apt-get install -y libcurl4-openssl-dev && docker-php-ext-install curl
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
COPY . /var/www/html/
EXPOSE 80