# Shared helpers for bin/dev-up, bin/dev-down, bin/wp, bin/smoke.sh and bin/e2e. Source it; POSIX-ish bash.
#
# Environment:
#   WP_IMAGE      WordPress image (default wordpress:7.1-php8.3-apache; the minimum is wordpress:6.1-php7.4-apache)
#   WPCLI_IMAGE   WP-CLI image (default: derived from WP_IMAGE's PHP version)
#   DB_PORT       host port of MariaDB, bound to 127.0.0.1 (default 8091)
#
# WordPress is always http://localhost:8089 (bound to 127.0.0.1). The Compose project is always odavent-wp-dev:
# COMPOSE_PROJECT_NAME from the environment is ignored, so `bin/dev-down --volumes` can never hit another project.

ODV_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export COMPOSE_PROJECT_NAME="odavent-wp-dev"
export WP_IMAGE="${WP_IMAGE:-wordpress:7.1-php8.3-apache}"
export DB_PORT="${DB_PORT:-8091}"

if [ -z "${WPCLI_IMAGE:-}" ]; then
	_php="$(printf '%s' "$WP_IMAGE" | sed -n 's/.*php\([0-9][0-9]*\.[0-9][0-9]*\).*/\1/p')"
	case "$_php" in
		7.*) WPCLI_IMAGE="wordpress:cli-2-php${_php}" ;;
		"") WPCLI_IMAGE="wordpress:cli-2-php8.3" ;;
		*) WPCLI_IMAGE="wordpress:cli-2-php${_php}" ;;
	esac
fi
export WPCLI_IMAGE

ODV_SITE="http://localhost:8089"
ODV_STUB_KEY="odv_wp_stubkey_for_dev_0123456789"
ODV_PLUGIN_DIR="/var/www/html/wp-content/plugins/odavent-events"

dc() {
	docker compose -f "$ODV_ROOT/docker/docker-compose.yml" "$@"
}

# WP-CLI in the running stack (same PHP, user and network as the site). Extra args go to `wp`.
wp_cli() {
	dc exec -T wpcli wp "$@"
}

# A command in the WordPress (Apache/PHP) container, as www-data.
wp_exec() {
	dc exec -T -u 33:33 wordpress "$@"
}

# POST a JSON control document to the stub (from inside the stack: the stub is not published).
stub_control() {
	dc exec -T wordpress curl -sS -X POST -H 'Content-Type: application/json' --data "$1" http://127.0.0.1:8081/__control >/dev/null
}

stub_get() {
	dc exec -T wordpress curl -sS "http://127.0.0.1:8081$1"
}

log() {
	printf '%s\n' "$*" >&2
}
