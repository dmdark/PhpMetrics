include qa/Makefile

CURRENT_TAG=$(shell git tag | tail -n 2 | head -n 1)

.PHONY: build
build: build-phar
	@#TODO: docker build --pull -t phpmetrics/phpmetrics:${CURRENT_TAG}
	@docker build --pull -t phpmetrics/phpmetrics:3.0.0 .

# Build phar
.PHONY: build-phar
build-phar: test phpcs
	@# Remove the phar that must be replaced by the new release.
	@rm -f releases/phpmetrics.phar
	@mkdir -p releases
	@echo Copying sources
	@mkdir -p /tmp/phpmetrics-build
	@cp * -R /tmp/phpmetrics-build
	@rm -Rf /tmp/phpmetrics-build/vendor /tmp/phpmetrics-build/composer.lock

	@echo Installing dependencies and building phar
	@cd /tmp/phpmetrics-build && docker run --rm -it -u$(shell id -u):0 -v $$PWD:/app -w /app phpmetrics_tools /bin/sh -c "composer update --no-dev --optimize-autoloader --prefer-dist && php artifacts/phar/build.php"
	@cp /tmp/phpmetrics-build/releases/phpmetrics.phar releases/phpmetrics.phar
	@rm -Rf /tmp/phpmetrics-build
