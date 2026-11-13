PHP ?= php
COMPOSER ?= composer
PHPUNIT := $(PHP) bin/omnitrix-phpunit

.PHONY: help install validate lint lint-fix stan test-unit test-integration test check services-up services-down services-status

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-18s %s\n", $$1, $$2}'
install: ## Install Composer dependencies
	$(COMPOSER) install --no-interaction
validate: ## Validate composer.json and audit dependencies
	$(COMPOSER) validate --strict
	$(COMPOSER) audit
lint: ## Check coding standards
	$(PHP) vendor/bin/php-cs-fixer check --diff
lint-fix: ## Fix coding standards
	$(PHP) vendor/bin/php-cs-fixer fix
stan: ## Static analysis
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G
test-unit: ## Unit tests
	$(PHPUNIT) --testsuite unit
test-integration: ## Integration tests (start services first: make services-up)
	$(PHPUNIT) --testsuite integration
test: test-unit test-integration ## Unit and integration tests
check: validate lint stan test ## Run every check
services-up: ## Start local MariaDB and Redis for integration tests
	bin/services up
services-down: ## Stop local services
	bin/services down
services-status: ## Show local service status
	bin/services status
