#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${GTP_PACKAGE_VERSION:-1.0.0-beta-7}"
BUILD_ROOT="${ROOT}/build/package"
PLUGIN_DIR="${BUILD_ROOT}/gt-performance"
ARCHIVE="${ROOT}/dist/gt-performance-${VERSION}.zip"

rm -rf "${BUILD_ROOT}"
mkdir -p "${PLUGIN_DIR}" "${ROOT}/dist"

rsync -a \
	--exclude '.git' \
	--exclude '.github' \
	--exclude '.DS_Store' \
	--exclude '.phpunit.cache' \
	--exclude 'bin' \
	--exclude 'build' \
	--exclude 'dist' \
	--exclude 'distribution-assets' \
	--exclude 'FEATURE-IMPLEMENTATION.md' \
	--exclude 'notes.md' \
	--exclude 'phpcs.xml.dist' \
	--exclude 'phpstan.neon.dist' \
	--exclude 'phpunit.xml.dist' \
	--exclude 'RELEASING.md' \
	--exclude 'task_plan.md' \
	--exclude 'tests' \
	--exclude 'VALIDATION.md' \
	--exclude 'vendor' \
	"${ROOT}/" "${PLUGIN_DIR}/"

composer install \
	--working-dir="${PLUGIN_DIR}" \
	--no-dev \
	--no-interaction \
	--prefer-dist \
	--classmap-authoritative

rm -rf "${PLUGIN_DIR}/vendor/bin"
rm -f \
	"${PLUGIN_DIR}/.gitignore" \
	"${PLUGIN_DIR}/CHANGELOG.md" \
	"${PLUGIN_DIR}/PRODUCT-PLAN.md" \
	"${PLUGIN_DIR}/composer.json" \
	"${PLUGIN_DIR}/composer.lock"

rm -f "${ARCHIVE}"
(
	cd "${BUILD_ROOT}"
	zip -qr "${ARCHIVE}" gt-performance
)

unzip -tq "${ARCHIVE}"
printf '%s\n' "${ARCHIVE}"
