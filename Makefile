# Minimum version of PHP supported by this project
PHP_VERSION ?= 8.3
PHP_EXTENSIONS ?= mbstring pdo json xdebug
DOCKER_IMAGE_NAME = mezzio/fastroute

# Includes _everything_ supplied by the internal tooling lib
include vendor/laminas/internal-tooling/Makefile


bump: bump-tools ## Bump PHP dev dependencies and update
	composer update && composer bump -D && composer update
.PHONY: bump

install-tools: ## Install standalone tools
	cd tools/crc && composer install
.PHONY: install-tools

bump-tools: ## Bump deps for all standalone tools
	cd tools/crc && composer update && composer bump -D && composer update
.PHONY: bump-tools

composer-require-checker: ## Check composer.json for un-declared dependencies
	tools/crc/vendor/bin/composer-require-checker check \
		--config-file=tools/crc/config.json \
		composer.json
.PHONY: composer-require-checker
