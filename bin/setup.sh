#!/usr/bin/env bash
# Install WordPress in the docker environment and activate the plugin.
set -euo pipefail
cd "$(dirname "$0")/.."

docker compose up -d
echo "Waiting for WordPress files..."
until docker compose exec -T wordpress test -f /var/www/html/wp-config.php; do sleep 2; done

wp() { docker compose run --rm -T --no-deps cli "$@"; }

if ! wp core is-installed 2>/dev/null; then
  wp core install --url=http://localhost:8080 --title="Scene Calendar Dev" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi
# Timezone: an IANA name, or a fixed offset like UTC-5. The default is UTC-5 because
# Manitoba dropped standard time in Nov 2026 and older PHP timezone data doesn't know.
TZ_SETTING="${SCENECAL_TZ:-UTC-5}"
if [[ "$TZ_SETTING" == UTC* ]]; then
  wp option update timezone_string ''
  wp option update gmt_offset "${TZ_SETTING#UTC}"
else
  wp option update timezone_string "$TZ_SETTING"
fi
wp rewrite structure '/%postname%/'
wp plugin activate scene-calendar

if ! wp post list --post_type=page --name=shows --format=ids | grep -q .; then
  wp post create --post_type=page --post_status=publish --post_title="Shows" --post_name=shows \
    --post_content='<!-- wp:shortcode -->[scene_calendar]<!-- /wp:shortcode -->'
fi

echo
echo "Ready: http://localhost:8080/wp-admin (admin / admin), calendar at http://localhost:8080/shows/"
