#!/usr/bin/env bash
# Run the test suite inside the docker WordPress. Optional arg: name filter.
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose run --rm -T --no-deps cli eval-file wp-content/plugins/scene-calendar/tests/run.php "$@"
