#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="ai-provider-for-mistral"
DIST_DIR="${ROOT_DIR}/dist"
STAGING_DIR="${DIST_DIR}/${PLUGIN_SLUG}"
ZIP_PATH="${DIST_DIR}/${PLUGIN_SLUG}.zip"

if ! command -v rsync >/dev/null 2>&1; then
    echo "Error: rsync is required to build the plugin ZIP." >&2
    exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
    echo "Error: zip is required to build the plugin ZIP." >&2
    exit 1
fi

rm -rf "${STAGING_DIR}" "${ZIP_PATH}"
mkdir -p "${STAGING_DIR}"

rsync -a "${ROOT_DIR}/" "${STAGING_DIR}/" \
    --exclude-from="${ROOT_DIR}/.distignore" \
    --exclude ".git" \
    --exclude "dist"

(
    cd "${DIST_DIR}"
    zip -rq "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}"
)

echo "Created ${ZIP_PATH}"
