# Test runtime: PHP 8.3 CLI + pdo_mysql + zip (mirrors typical cPanel PHP extensions)
FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev curl \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
