# Run `make` (no arguments) to get a short description of what is available
# within this `Makefile`.

help: ## shows this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_\-\.]+:.*?## / {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
.PHONY: help

install: install-tools ## Install PHP dependencies
	composer install
.PHONY: install

update: bump-tools ## Update PHP dependencies
	composer update
.PHONY: update

bump: bump-tools ## Bump PHP dev dependencies and update
	composer update && composer bump -D && composer update
.PHONY: bump

clean: ## Clear out caches and documentation assets
	rm -rf .phpunit.cache
	rm -f .phpcs-cache
	vendor/bin/psalm --clear-cache

static-analysis: ## Run static analysis checks
	vendor/bin/psalm --no-cache
.PHONY: static-analysis

coding-standards: ## Run coding standards checks
	vendor/bin/phpcs
.PHONY: coding-standards

coding-standards-fix: ## Fix coding standard violations
	vendor/bin/phpcbf
.PHONY: coding-standards-fix

test: ## Run unit tests
	vendor/bin/phpunit
.PHONY: test

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

qa: coding-standards static-analysis test composer-require-checker ## Run all QA Checks
.PHONY: qa
