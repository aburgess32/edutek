# ============================================================
# EduPak — Makefile
# XAMPP-based offline education platform
# ============================================================
# Usage: make <target>
# Run 'make help' to see all available commands.
# ============================================================

.DEFAULT_GOAL := help

# ── Colours ─────────────────────────────────────────────────
CYAN  := \033[36m
GREEN := \033[32m
RESET := \033[0m

# ── Docker Compose ───────────────────────────────────────────
DC      := docker compose
PHP_CTR := app
DB_CTR  := db

# ── Paths ────────────────────────────────────────────────────
MIGRATE := php scripts/migrate.php

# ============================================================
# Help
# ============================================================

.PHONY: help
help: ## Show this help message
	@echo ""
	@echo "$(CYAN)EduPak — Available Commands$(RESET)"
	@echo "────────────────────────────────────────────────────"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(RESET) %s\n", $$1, $$2}'
	@echo ""

# ============================================================
# Docker Lifecycle
# ============================================================

.PHONY: dev
dev: ## Start all containers in the background
	@echo "$(GREEN)Starting EduPak containers...$(RESET)"
	$(DC) up -d

.PHONY: down
down: ## Stop and remove containers (preserves volumes)
	@echo "$(GREEN)Stopping containers...$(RESET)"
	$(DC) down

.PHONY: logs
logs: ## Tail all container logs (Ctrl+C to exit)
	$(DC) logs -f

.PHONY: shell
shell: ## Open a bash shell inside the PHP container
	$(DC) exec $(PHP_CTR) bash

.PHONY: db
db: ## Open a MySQL CLI session inside the DB container
	$(DC) exec $(DB_CTR) bash -c 'mysql -u$$MYSQL_USER -p$$MYSQL_PASSWORD $$MYSQL_DATABASE'

# ============================================================
# Linting
# ============================================================

.PHONY: lint
lint: lint-php lint-js lint-css ## Run all linters (PHP, JS, CSS)

.PHONY: lint-php
lint-php: ## Lint PHP with PHP_CodeSniffer (PSR-12)
	@echo "$(CYAN)Linting PHP (PSR-12)...$(RESET)"
	$(DC) exec -w /var/www $(PHP_CTR) /var/www/vendor/bin/phpcs --standard=/var/www/phpcs.xml /var/www/html/

.PHONY: lint-js
lint-js: ## Lint JavaScript with ESLint
	@echo "$(CYAN)Linting JS (ESLint)...$(RESET)"
	@if [ -d htdocs/js ]; then npx eslint htdocs/js/ --ext .js; else echo "  No htdocs/js/ directory — skipping."; fi

.PHONY: lint-css
lint-css: ## Lint CSS with Stylelint
	@echo "$(CYAN)Linting CSS (Stylelint)...$(RESET)"
	@if [ -d htdocs/css ]; then npx stylelint "htdocs/css/**/*.css"; else echo "  No htdocs/css/ directory — skipping."; fi

.PHONY: lint-fix
lint-fix: ## Auto-fix all auto-fixable lint issues
	@echo "$(CYAN)Auto-fixing PHP...$(RESET)"
	$(DC) exec -w /var/www $(PHP_CTR) /var/www/vendor/bin/phpcbf --standard=/var/www/phpcs.xml /var/www/html/ || true
	@echo "$(CYAN)Auto-fixing JS...$(RESET)"
	npx eslint htdocs/js/ --ext .js --fix || true
	@echo "$(CYAN)Auto-fixing CSS...$(RESET)"
	npx stylelint "htdocs/css/**/*.css" --fix || true
	@echo "$(GREEN)Auto-fix complete.$(RESET)"

# ============================================================
# Testing
# ============================================================

.PHONY: test
test: ## Run PHP unit tests (PHPUnit)
	@echo "$(CYAN)Running PHPUnit...$(RESET)"
	$(DC) exec $(PHP_CTR) /var/www/vendor/bin/phpunit --colors=always

.PHONY: test-e2e
test-e2e: ## Run end-to-end tests (Playwright)
	@echo "$(CYAN)Running Playwright e2e tests...$(RESET)"
	npx playwright test

.PHONY: test-all
test-all: lint test test-e2e ## Run everything: lint + unit tests + e2e tests
	@echo "$(GREEN)All checks passed!$(RESET)"

# ============================================================
# Database Migrations
# ============================================================

.PHONY: migrate
migrate: ## Apply all pending database migrations
	@echo "$(CYAN)Running pending migrations...$(RESET)"
	$(DC) exec $(PHP_CTR) $(MIGRATE) up

.PHONY: migrate-status
migrate-status: ## Show migration status (applied vs pending)
	$(DC) exec $(PHP_CTR) $(MIGRATE) status

.PHONY: migrate-rollback
migrate-rollback: ## Rollback the last applied migration
	@echo "$(CYAN)Rolling back last migration...$(RESET)"
	$(DC) exec $(PHP_CTR) $(MIGRATE) rollback

# ============================================================
# Performance
# ============================================================

.PHONY: perf
perf: ## Run Lighthouse CI performance audit
	@echo "$(CYAN)Running Lighthouse CI...$(RESET)"
	npx lhci autorun --config=lighthouserc.js

# ============================================================
# Deployment
# ============================================================

.PHONY: deploy
deploy: ## Deploy to production (runs deploy.sh from master only)
	@echo "$(CYAN)Running deployment...$(RESET)"
	bash scripts/deploy.sh

# ============================================================
# Images
# ============================================================

.PHONY: optimize-images
optimize-images: ## Optimize and convert images to WebP
	@echo "$(CYAN)Optimizing images...$(RESET)"
	bash scripts/optimize-images.sh

# ============================================================
# Cleanup
# ============================================================

.PHONY: clean
clean: ## Remove dist/, log files, and Docker volumes
	@echo "$(CYAN)Cleaning build artefacts...$(RESET)"
	rm -rf dist/
	find logs/ -name "*.log" -type f -delete 2>/dev/null || true
	$(DC) down -v
	@echo "$(GREEN)Clean complete.$(RESET)"

# ============================================================
# First-Time Setup
# ============================================================

.PHONY: setup
setup: ## First-time setup: copy .env, install deps, build, migrate
	@echo "$(CYAN)Running first-time EduPak setup...$(RESET)"

	@# Copy .env.example if .env doesn't exist
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		echo "$(GREEN)Created .env from .env.example — please review and update values.$(RESET)"; \
	else \
		echo ".env already exists — skipping copy."; \
	fi

	@# Install PHP dependencies inside container
	$(DC) run --rm $(PHP_CTR) composer install --no-interaction --prefer-dist

	@# Install Node dependencies
	npm install

	@# Build Docker images
	$(DC) build

	@# Start containers
	$(DC) up -d

	@# Wait for DB to be ready
	@echo "Waiting for database to be ready..."
	@sleep 5

	@# Run migrations
	$(MAKE) migrate

	@echo ""
	@echo "$(GREEN)Setup complete! EduPak is running at http://localhost:8080$(RESET)"
	@echo "$(CYAN)Run 'make help' to see all available commands.$(RESET)"
