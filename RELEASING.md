# Releasing GT Performance

Every GitHub release is built from a pushed `v<version>` tag. The workflow does not invent tags or versions.

## Release gate

Before tagging, update every version surface:

- `composer.json`
- the plugin header and `GTP_VERSION` in `gt-performance.php`
- `Stable tag` and the changelog section in `readme.txt`
- the current release in `README.md`
- the default version in `bin/build-package.sh`
- `GTP_VERSION` in `tests/phpstan-bootstrap.php`
- a dated top section in `CHANGELOG.md`

Then run:

```bash
composer validate --strict --no-check-version
composer check
php bin/release-metadata.php
./bin/build-package.sh
```

Test the generated ZIP in WordPress Studio before the tag is created.

## Publish

After the release commit is on `main`, create and push an annotated tag:

```bash
VERSION=0.1.0-alpha.8
git tag -a "v${VERSION}" -m "GT Performance ${VERSION}"
git push origin "v${VERSION}"
```

The Release workflow:

1. verifies that the tag, source versions, readme, and changelog agree;
2. runs coding standards, PHPStan, and PHPUnit on PHP 8.1;
3. builds the production ZIP and verifies its internal version;
4. creates and checks a SHA-256 checksum;
5. records GitHub artifact provenance for the ZIP;
6. publishes a prerelease automatically when the version contains a hyphen;
7. attaches the ZIP and checksum to the GitHub release.

Do not move a published release tag. Fix a broken release with a new version. If the workflow fails before publishing, correct the cause and rerun the failed workflow for the same immutable tag only when the tagged source itself is valid.

## Verify

```bash
VERSION=0.1.0-alpha.8
gh release view "v${VERSION}"
gh release download "v${VERSION}" --pattern "gt-performance-*"
sha256sum --check "gt-performance-${VERSION}.zip.sha256"
gh attestation verify "gt-performance-${VERSION}.zip" --repo wpgaurav/gt-performance
```
