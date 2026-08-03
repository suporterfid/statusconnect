#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE=(docker compose)
COMPOSE_FILES=(-f compose.yaml)

if [[ "${STC_CI:-}" == "1" || "${CI:-}" == "true" || "${GITHUB_ACTIONS:-}" == "true" ]]; then
  COMPOSE_FILES+=(-f compose.ci.yaml)
fi

compose() {
  "${COMPOSE[@]}" "${COMPOSE_FILES[@]}" "$@"
}

warn_packagist_mirror() {
  if [[ -n "${COMPOSER_PACKAGIST_URL:-}" ]]; then
    echo "WARNING: COMPOSER_PACKAGIST_URL is set (${COMPOSER_PACKAGIST_URL}). Custom Packagist mirrors can cause stale or incomplete installs." >&2
  fi
}

composer_env_args() {
  local args=()
  if [[ -n "${COMPOSER_PACKAGIST_URL:-}" ]]; then
    args+=(-e "COMPOSER_PACKAGIST_URL=${COMPOSER_PACKAGIST_URL}")
  fi
  printf '%s\n' "${args[@]}"
}

composer_install_with_retry() {
  local max_attempts=3
  local attempt=1
  local delay=5

  warn_packagist_mirror

  mapfile -t env_args < <(composer_env_args)

  while (( attempt <= max_attempts )); do
    if compose run --rm "${env_args[@]}" app composer "$@"; then
      return 0
    fi
    if (( attempt == max_attempts )); then
      echo "Composer failed after ${max_attempts} attempts." >&2
      return 1
    fi
    echo "Composer attempt ${attempt} failed; retrying in ${delay}s..." >&2
    sleep "$delay"
    delay=$((delay * 2))
    attempt=$((attempt + 1))
  done
}

usage() {
  cat <<'EOF'
StatusConnect Docker toolchain

Usage:
  ./scripts/stc.sh <verb> [args...]

Verbs:
  up           Start core services (app, mysql, mailpit, target)
  down         Stop and remove containers
  bootstrap    Install dependencies, prepare env, migrate database
  composer     Run composer via app container
  artisan      Run artisan via app container
  npm          Run npm via node container (dev profile)
  test         Run PHPUnit test suite
  e2e          Run Playwright end-to-end suite
  release      Build production release zip into dist/
  deploy       Build release and publish over FTP(S)+SSH
  shell        Open shell in app container
  help         Show this help
EOF
}

cmd_up() {
  compose up -d --build mysql mailpit target app
}

cmd_down() {
  compose down "$@"
}

cmd_bootstrap() {
  if [[ ! -f .env ]]; then
    cp .env.example .env
    echo "Created .env from .env.example"
  fi

  compose up -d --build mysql mailpit target
  compose up -d --wait mysql

  composer_install_with_retry install

  if compose run --rm app test -f artisan; then
    compose run --rm app php artisan key:generate --force
    compose run --rm app php artisan migrate --force
  else
    echo "Laravel not scaffolded yet; skipping artisan bootstrap steps."
  fi

  if [[ -f package.json ]]; then
    compose --profile dev run --rm node npm ci || compose --profile dev run --rm node npm install
  fi

  compose up -d --build app
  echo "Bootstrap complete."
}

cmd_composer() {
  if [[ "${1:-}" == "install" ]]; then
    composer_install_with_retry install "${@:2}"
    return
  fi

  warn_packagist_mirror
  mapfile -t env_args < <(composer_env_args)
  compose run --rm "${env_args[@]}" app composer "$@"
}

cmd_artisan() {
  compose run --rm app php artisan "$@"
}

cmd_npm() {
  compose --profile dev run --rm --service-ports node npm "$@"
}

cmd_test() {
  if compose run --rm app test -f artisan; then
    compose run --rm app php artisan test "$@"
  elif compose run --rm app test -f vendor/bin/pest; then
    compose run --rm app vendor/bin/pest "$@"
  elif compose run --rm app test -f vendor/bin/phpunit; then
    compose run --rm app vendor/bin/phpunit "$@"
  else
    echo "No test runner found. Scaffold Laravel or install dev dependencies first." >&2
    return 1
  fi
}

cmd_e2e() {
  if [[ ! -f package.json ]]; then
    echo "No package.json found." >&2
    return 1
  fi

  if ! grep -qE '"e2e"[[:space:]]*:' package.json; then
    echo "No e2e script defined in package.json." >&2
    return 1
  fi

  compose --profile dev run --rm --service-ports \
    -e "E2E_EMAIL=${E2E_EMAIL:-}" \
    -e "E2E_PASSWORD=${E2E_PASSWORD:-}" \
    -e "PLAYWRIGHT_BASE_URL=${PLAYWRIGHT_BASE_URL:-http://app}" \
    node npm run e2e -- "$@"
}

cmd_release() {
  mkdir -p dist
  docker build -f docker/release/Dockerfile --target export --output "type=local,dest=./dist" .
  echo "Release artifact written to dist/"
  if [[ -f "$ROOT_DIR/scripts/validate-release.sh" ]]; then
    bash "$ROOT_DIR/scripts/validate-release.sh" "$ROOT_DIR/dist"
  fi
}

cmd_deploy() {
  local config="${1:-deploy.config.json}"

  if [[ ! -f "$config" ]]; then
    echo "Deploy config '$config' not found." >&2
    return 1
  fi

  echo "Building production release tree (dist/app)..."
  rm -rf dist/app
  mkdir -p dist
  docker build -f docker/release/Dockerfile --target export --output "type=local,dest=./dist" .

  echo "Building deploy image..."
  docker build -f docker/deploy/Dockerfile -t statusconnect-deploy .

  echo "Publishing to remote host..."
  docker run --rm -v "$ROOT_DIR:/work" -w /work statusconnect-deploy \
    -c "tr -d '\r' < scripts/deploy.sh > /tmp/deploy.sh && bash /tmp/deploy.sh '$config'"
}

cmd_shell() {
  compose run --rm app bash
}

main() {
  local verb="${1:-help}"
  shift || true

  case "$verb" in
    up) cmd_up "$@" ;;
    down) cmd_down "$@" ;;
    bootstrap) cmd_bootstrap "$@" ;;
    composer) cmd_composer "$@" ;;
    artisan) cmd_artisan "$@" ;;
    npm) cmd_npm "$@" ;;
    test) cmd_test "$@" ;;
    e2e) cmd_e2e "$@" ;;
    release) cmd_release "$@" ;;
    deploy) cmd_deploy "$@" ;;
    shell) cmd_shell "$@" ;;
    help|-h|--help) usage ;;
    *)
      echo "Unknown verb: $verb" >&2
      usage >&2
      exit 1
      ;;
  esac
}

main "$@"
