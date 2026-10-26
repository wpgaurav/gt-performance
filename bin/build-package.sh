#!/usr/bin/env bash

# Build the WordPress.org package: dist/gt-performance-<version>.zip.
#
# WordPress.org is the only distribution channel. The self-hosted FluentCart
# package (licensing, its own update client, static script files) was retired in
# 1.3.2, so there is one artifact, and it is the one a directory reviewer reads.
#
# Usage: build-package.sh [wporg]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${GTPERF_PACKAGE_VERSION:-1.3.6}"

case "${1:-wporg}" in
	wporg) ;;
	*)
		printf 'Unknown channel "%s". WordPress.org (wporg) is the only package.\n' "$1" >&2
		exit 1
		;;
esac

build_package() {
	local build_root="${ROOT}/build/package-wporg"
	local plugin_dir="${build_root}/gt-performance"
	local archive="${ROOT}/dist/gt-performance-${VERSION}.zip"

	rm -rf "${build_root}"
	mkdir -p "${plugin_dir}" "${ROOT}/dist"

	local -a excludes=(
		--exclude '.git'
		--exclude '.gitattributes'
		--exclude '.github'
		--exclude '.claude'
		--exclude '.DS_Store'
		--exclude '.phpunit.cache'
		--exclude 'bin'
		--exclude 'build'
		--exclude 'dist'
		--exclude 'distribution-assets'
		--exclude '__release-*'
		--exclude '__work'
		--exclude 'FEATURE-IMPLEMENTATION.md'
		--exclude 'FEATURE-AUDIT.md'
		--exclude 'docs'
		--exclude 'notes.md'
		--exclude 'phpcs.xml.dist'
		--exclude 'phpstan.neon.dist'
		--exclude 'phpunit.xml.dist'
		--exclude '/README.md'
		--exclude 'RELEASING.md'
		--exclude '/ROADMAP.md'
		--exclude 'task_plan.md'
		--exclude 'tests'
		--exclude 'VALIDATION.md'
		--exclude 'vendor'
	)

	rsync -a "${excludes[@]}" "${ROOT}/" "${plugin_dir}/"

	composer install \
		--working-dir="${plugin_dir}" \
		--no-dev \
		--no-interaction \
		--prefer-dist \
		--classmap-authoritative \
		--quiet

	rm -rf "${plugin_dir}/vendor/bin"

	# WordPress.org rejects packages containing files that are not normally part of
	# a plugin. Composer packages ship extensionless CLI wrappers in their own bin/
	# directories (matthiasmullie/minify/bin/minifyjs, minifycss); the library code
	# lives in src/, so the wrappers are dead weight in a plugin package.
	find "${plugin_dir}/vendor" -mindepth 3 -maxdepth 3 -type d -name bin -prune -exec rm -rf {} +

	# Drop VCS placeholders and other hidden files the vendor tree carries along.
	find "${plugin_dir}/vendor" -name '.git*' -prune -exec rm -rf {} +

	# Package documentation and thecodingmachine/safe's Rector migration configs are
	# never loaded. Licences and composer.json files stay.
	find "${plugin_dir}/vendor" -type f \( -iname '*.md' -o -name 'rector-migrate.php' \) -delete

	# composer.json stays in the package: Plugin Check flags a bundled vendor/
	# directory whose composer.json is missing.
	rm -f \
		"${plugin_dir}/.gitignore" \
		"${plugin_dir}/CHANGELOG.md" \
		"${plugin_dir}/PRODUCT-PLAN.md" \
		"${plugin_dir}/composer.lock"

	# Directory-hosted plugins must use WordPress.org update authority, and the
	# directory reads Plugin URI as the plugin's canonical page: a /product/ path on a
	# free GPL submission is the commercial-residue signal a reviewer stops on.
	if grep -q '^Update URI:' "${plugin_dir}/gt-performance.php"; then
		printf 'The WordPress.org package must not carry an Update URI header.\n' >&2
		exit 1
	fi
	if grep -q '^Plugin URI: .*/product/' "${plugin_dir}/gt-performance.php"; then
		printf 'Plugin URI must not point at a /product/ page.\n' >&2
		exit 1
	fi

	# An exclude list only stops what it already knows about. A scratch directory in
	# the repo root shipped into a production install and served 531 KB of internal
	# audit notes over HTTP before this check existed. Allowlist the top level instead,
	# so anything new has to be named here before it can ever be packaged.
	local allowed=' assets dropins languages src vendor LICENSE composer.json gt-performance.php readme.txt uninstall.php '
	local unexpected=''
	local entry
	for entry in "${plugin_dir}"/* "${plugin_dir}"/.[!.]*; do
		[[ -e "${entry}" ]] || continue
		local name
		name="$(basename "${entry}")"
		if [[ "${allowed}" != *" ${name} "* ]]; then
			unexpected+="  ${name}"$'\n'
		fi
	done
	if [[ -n "${unexpected}" ]]; then
		printf 'Unexpected top-level entries in the package:\n%s\nAdd them to the allowlist in bin/build-package.sh if they belong.\n' \
			"${unexpected}" >&2
		exit 1
	fi

	# Fail the build rather than ship a file type the directory does not permit.
	local unpermitted
	unpermitted="$(
		find "${plugin_dir}" -type f \
			! -iname '*.php' ! -iname '*.js' ! -iname '*.css' ! -iname '*.txt' \
			! -iname '*.md' ! -iname '*.json' ! -iname '*.xml' ! -iname '*.svg' \
			! -iname '*.png' ! -iname '*.jpg' ! -iname '*.jpeg' ! -iname '*.gif' \
			! -iname '*.pot' ! -iname '*.po' ! -iname '*.mo' \
			! -iname 'LICENSE' ! -iname 'LICENSE.*' ! -iname 'COPYING'
	)"
	if [[ -n "${unpermitted}" ]]; then
		printf 'Unpermitted files in the package:\n%s\n' "${unpermitted}" >&2
		exit 1
	fi

	# The WordPress.org artifact is the one a reviewer reads. Prove, rather than
	# assume, that no licensing, update-client or static-script code came back.
	if [[ -d "${plugin_dir}/src/Licensing" ]] || [[ -d "${plugin_dir}/src/SelfHosted" ]]; then
		printf 'The package must not contain src/Licensing or src/SelfHosted.\n' >&2
		exit 1
	fi
	local residue
	# Deliberately not matching "fluent-cart": Commerce/FluentCartAdapter protects a
	# FluentCart store's cart and checkout from being cached and belongs here. What
	# must not appear is licensing and update-client code.
	residue="$(grep -rlE 'Licensing|SelfHosted|license_key|get_license_version|activation_hash' \
		"${plugin_dir}/src" "${plugin_dir}/gt-performance.php" 2>/dev/null || true)"
	if [[ -n "${residue}" ]]; then
		printf 'Licensing residue in the package:\n%s\n' "${residue}" >&2
		exit 1
	fi

	rm -f "${archive}"
	(
		cd "${build_root}"
		zip -qr "${archive}" gt-performance
	)

	unzip -tq "${archive}"
	printf '%s\n' "${archive}"
}

build_package
