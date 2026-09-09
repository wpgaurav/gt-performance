#!/usr/bin/env bash

# Build the distribution packages.
#
# Two channels ship from one tree:
#
#   wporg      The WordPress.org submission artifact. Carries no licensing code,
#              no update client and no remote call of its own. src/Licensing is
#              excluded outright, so the reviewed package cannot contain it.
#   fluentcart The self-hosted package sold through gauravtiwari.org. Includes
#              src/Licensing, because FluentCart's get_license_version endpoint
#              only returns a download URL for an activated license, so without
#              it a self-hosted install has no update path at all.
#
# Usage: build-package.sh [wporg|fluentcart|all]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${GTPERF_PACKAGE_VERSION:-1.0.10}"
CHANNELS="${1:-all}"

build_channel() {
	local channel="$1"
	local build_root="${ROOT}/build/package-${channel}"
	local plugin_dir="${build_root}/gt-performance"
	local archive

	if [[ "${channel}" == "wporg" ]]; then
		archive="${ROOT}/dist/gt-performance-${VERSION}.zip"
	else
		archive="${ROOT}/dist/gt-performance-${VERSION}-${channel}.zip"
	fi

	rm -rf "${build_root}"
	mkdir -p "${plugin_dir}" "${ROOT}/dist"

	local -a excludes=(
		--exclude '.git'
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
		--exclude 'notes.md'
		--exclude 'phpcs.xml.dist'
		--exclude 'phpstan.neon.dist'
		--exclude 'phpunit.xml.dist'
		--exclude 'RELEASING.md'
		--exclude 'task_plan.md'
		--exclude 'tests'
		--exclude 'VALIDATION.md'
		--exclude 'vendor'
	)

	# The one structural difference between the channels.
	if [[ "${channel}" == "wporg" ]]; then
		excludes+=(--exclude 'src/Licensing')
	fi

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

	# composer.json stays in the package: Plugin Check flags a bundled vendor/
	# directory whose composer.json is missing.
	rm -f \
		"${plugin_dir}/.gitignore" \
		"${plugin_dir}/CHANGELOG.md" \
		"${plugin_dir}/PRODUCT-PLAN.md" \
		"${plugin_dir}/composer.lock"

	# Point each package at the home its users actually update from. The directory
	# reads Plugin URI as the plugin's canonical page, and a /product/ path on a free
	# GPL submission is the commercial-residue signal a reviewer stops on.
	if [[ "${channel}" == "wporg" ]]; then
		perl -0pi -e 's{^Plugin URI: .*$}{Plugin URI: https://gauravtiwari.org/gt-performance/}m' "${plugin_dir}/gt-performance.php"
		# Directory-hosted plugins must use WordPress.org update authority.
		perl -0pi -e 's{^Update URI: .*\n}{}m' "${plugin_dir}/gt-performance.php"
	fi

	# An exclude list only stops what it already knows about. A scratch directory in
	# the repo root shipped into a production install and served 531 KB of internal
	# audit notes over HTTP before this check existed. Allowlist the top level instead,
	# so anything new has to be named here before it can ever be packaged.
	local allowed=' assets dropins src vendor LICENSE README.md composer.json gt-performance.php readme.txt uninstall.php '
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
		printf 'Unexpected top-level entries in the %s package:\n%s\nAdd them to the allowlist in bin/build-package.sh if they belong.\n' \
			"${channel}" "${unexpected}" >&2
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
		printf 'Unpermitted files in %s package:\n%s\n' "${channel}" "${unpermitted}" >&2
		exit 1
	fi

	# The WordPress.org artifact is the one a reviewer reads. Prove, rather than
	# assume, that nothing licensing-related survived into it.
	if [[ "${channel}" == "wporg" ]]; then
		if [[ -d "${plugin_dir}/src/Licensing" ]]; then
			printf 'The WordPress.org package must not contain src/Licensing.\n' >&2
			exit 1
		fi
		local residue
		# Deliberately not matching "fluent-cart": Commerce/FluentCartAdapter protects a
		# FluentCart store's cart and checkout from being cached and belongs in both
		# packages. What must not appear is licensing and update-client code.
		residue="$(grep -rlE 'Licensing|license_key|get_license_version|activation_hash' \
			"${plugin_dir}/src" "${plugin_dir}/gt-performance.php" 2>/dev/null || true)"
		if [[ -n "${residue}" ]]; then
			printf 'Licensing residue in the WordPress.org package:\n%s\n' "${residue}" >&2
			exit 1
		fi
	fi

	# And the FluentCart artifact is useless without the updater it exists for.
	if [[ "${channel}" == "fluentcart" ]] && [[ ! -f "${plugin_dir}/src/Licensing/Updater.php" ]]; then
		printf 'The FluentCart package must contain src/Licensing/Updater.php.\n' >&2
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

case "${CHANNELS}" in
	wporg|fluentcart)
		build_channel "${CHANNELS}"
		;;
	all)
		build_channel wporg
		build_channel fluentcart
		;;
	*)
		printf 'Unknown channel "%s". Use wporg, fluentcart, or all.\n' "${CHANNELS}" >&2
		exit 1
		;;
esac
