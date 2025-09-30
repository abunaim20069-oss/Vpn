FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    curl \
    && docker-php-ext-install curl

# Enable Apache modules
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Fix Apache directory permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 644 index.php \
    && chmod 644 .htaccess \
    && chmod 644 composer.json

# Create and set permissions for data file
RUN touch bot_data.json && chmod 666 bot_data.json

# Fix Apache configuration to allow access
RUN echo "<Directory /var/www/html>" > /etc/apache2/conf-available/allow.conf \
    && echo "    Options Indexes FollowSymLinks" >> /etc/apache2/conf-available/allow.conf \
    && echo "    AllowOverride All" >> /etc/apache2/conf-available/allow.conf \
    && echo "    Require all granted" >> /etc/apache2/conf-available/allow.conf \
    && echo "</Directory>" >> /etc/apache2/conf-available/allow.conf

RUN a2enconf allow

EXPOSE 80
