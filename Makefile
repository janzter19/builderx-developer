.PHONY: verify frontend-build php-lint

verify: php-lint frontend-build

php-lint:
	php -l app/foundation.php
	php -l index.php
	php -l administrator/index.php
	php -l administrator/foundation.php
	php -l phases/index.php
	php -l phases/installation.php

frontend-build:
	cd administrator/frontend && npm run build
