# m2-smoketest

Read-mostly post-deployment diagnostics for Magento 2.4.7 and later, initially for DTF Distributors. Runs as a standalone PHP CLI application in a Magento subdirectory.

## Requirements and isolation

- Linux/SSH, PHP CLI 8.2+, cURL and DOM extensions, Composer 2.
- Run as the normal Magento filesystem owner, with the same PHP executable/configuration as Magento CLI. Do not run as root.
- **Own Composer project and vendor directory.** Run Composer inside this directory only. Do not change Magento's root composer.json.
- Magento CLI commands and the cron reader execute in separate PHP processes. The tool's dependencies never enter Magento's autoloader.
- Fixtures cover Magento 2.4.7 CLI output. Real staging validation is required for a particular Magento release and extensions.
- Version reporting does **not** verify VULN_39341 or other patches. Utility PHP compatibility does not change Magento's supported PHP combinations.

## Install and run

From Magento's root, outside the web document root (normally pub/):

~~~bash
git clone https://github.com/msquaredstl/m2-smoketest.git m2-smoketest
cd m2-smoketest
composer install --no-dev --prefer-dist --no-interaction
cp .env.example .env
chmod 600 .env
~~~

Edit .env, then:

~~~bash
php bin/m2-smoketest
php bin/m2-smoketest --verbose
php bin/m2-smoketest --verbose --log=/private/path/smoke-2026-09-23.jsonl
php bin/m2-smoketest --format=json --fail-on-warning
~~~

Root detection walks upward from the working directory looking for bin/magento and app/bootstrap.php. Use --root=/path/to/magento or M2_SMOKE_ROOT when running elsewhere. Do not install under pub/, vendor/, or app/code/. Exclude /m2-smoketest/ from the parent deployment repository as appropriate.

**Web access:** keep the whole utility outside the served document root. The included .htaccess denies Apache access when overrides are enabled; Nginx ignores it. If hosting serves Magento's root directly, configure a server-level denial for this directory before installing. Store diagnostics outside the served root too.

## Saved environments and categorized URLs

The default .env comes from the utility directory, not Magento's root. It and .env.* are git-ignored; .env.example is committed. Existing OS environment variables override file values.

~~~dotenv
M2_SMOKE_BASE_URL=https://stage.dtfstore.com/

M2_SMOKE_PAGE_HOMEPAGE_URL=/
M2_SMOKE_PAGE_HOMEPAGE_TYPE=homepage

M2_SMOKE_PAGE_VINYL_URL=/replace-with-real-category.html
M2_SMOKE_PAGE_VINYL_TYPE=category

M2_SMOKE_PAGE_TRANSFER_URL=/replace-with-real-product.html
M2_SMOKE_PAGE_TRANSFER_TYPE=product

M2_SMOKE_PAGE_CATALOG_SEARCH_URL="/catalogsearch/result/?q=replace-me"
M2_SMOKE_PAGE_CATALOG_SEARCH_TYPE=search
~~~

Add as many URL/TYPE pairs as needed. IDs use uppercase letters, digits and underscores; report IDs use lowercase. Types: homepage, category, product, search, cart, checkout, login, custom. Reports group pages by type. Homepage pages check linked CSS/JS by default; YAML can enable asset checks for other pages.

Default category, product and search entries are blank until real paths are selected. Fill those entries, or replace the page set in YAML. Blank entries always WARN. No real product/category paths or search queries have been assumed.

Relative paths are appended to the base URL, including any store-code subdirectory. Absolute URLs must match its scheme, hostname and effective port. A staging run cannot silently test production pages. For another website:

~~~bash
cp .env.example .env.production
chmod 600 .env.production
# Set M2_SMOKE_BASE_URL=https://dtfdistributors.com/ and appropriate page paths.
php bin/m2-smoketest --env=.env.production
~~~

--url overrides only the base URL. Prefer relative paths when switching environments. An absolute URL for a different origin causes a configuration error.

## Advanced YAML configuration

Use --config=config/staging.local.yml or M2_SMOKE_CONFIG=config/staging.local.yml in .env. An environment config path is relative to the .env directory; a CLI config path is relative to the working directory.

Precedence: defaults < YAML < .env/OS environment < CLI. YAML pages replace the default page mapping; environment pages merge by ID. Lists replace, rather than append.

~~~yaml
base_url: https://stage.dtfstore.com/
expected_mode: production
command_timeout: 30
run_timeout: 120
pages:
  homepage:
    type: homepage
    path: /
    contains: ['</html>']
    assets: true
  category:
    type: category
    path: /replace-me.html
    expect_status: 200
    contains: ['Known category heading']
    not_contains: ['No products found']
    assets: true
indexers:
  fail_on_invalid: true
  ignore: []
cache:
  ignore: []
cron:
  enabled: true
  stale_after_minutes: 10
  lookback_minutes: 60
  max_failures: 0
  max_overdue: 0
logs:
  lookback_minutes: 15
  max_bytes: 2097152
  files: [var/log/system.log, var/log/exception.log]
http:
  timeout: 10
  max_assets: 60
  max_body_bytes: 2097152
~~~

See config/defaults.yml for all settings. The .env.example exposes common settings without YAML. Unknown YAML keys, invalid types and nonpositive timeouts are rejected.

Staging HTTP Basic auth uses M2_SMOKE_HTTP_USER and M2_SMOKE_HTTP_PASSWORD in .env or OS environment. TLS verification is always enabled; credentials are not put in URLs. Redirects to another origin are rejected.

## Checks and coverage

| Check | Behavior |
| --- | --- |
| Versions | Reports PHP/Magento; warns for Magento earlier than 2.4.7 |
| Mode / maintenance | Unexpected mode warns; maintenance active fails |
| Indexers | Individual states parsed; non-ready fails by default |
| Caches | Disabled caches warn; explicit exclusions supported |
| Cron | SELECT on prefixed cron_schedule through isolated Magento bootstrap; stale/missing successful jobs fail; excessive failed/missed jobs or overdue pending jobs warn |
| Pages | GET, expected 2xx, HTML content type, required/forbidden strings, Magento error signatures |
| Redirects | Up to three same-origin redirects; changed page/query fails (trailing slash normalization allowed) |
| Assets | Same-origin linked CSS/JS, deduplicated globally; HEAD with GET fallback for 405/501; status and MIME verification |
| Logs | Recent timestamped Monolog records; ERROR+ fails, WARNING warns; bounded tail with incomplete-coverage warnings |

Cron thresholds depend on deployment windows and scheduler setup. This is global cron history, not proof every job/group is healthy. It never runs cron:run.

External/CDN assets and assets skipped due to limits warn as unverified. This does not execute JavaScript, enumerate RequireJS/CSS imports, lazy-loaded assets, or every image.

Search checks **HTTP page health only**. Algolia results, Elasticsearch service health, checkout JavaScript, and interactive cart/login behavior are not verified. A checkout redirect to an empty cart fails instead of reporting successful checkout.

Defaults: 30 seconds per command, 10 per HTTP request, 120 seconds for total external work. No fixed maximum on configured URLs; increase the budget for larger suites. Checks skipped due to the budget warn; individual request/command timeouts fail.

Logs default to a 15-minute lookback. Missing/unreadable logs, unrecognized records and insufficient byte coverage warn. Supports Magento timestamped Monolog format. Rotated logs outside the configured set, arbitrary web-server logs and var/report are not scanned in this MVP.

~~~bash
php bin/m2-smoketest --since="30 minutes" --verbose
php bin/m2-smoketest --since="2026-09-23T02:00:00Z"
~~~

## Failure diagnostics and exits

--verbose (-v) adds bounded stdout/stderr, exit codes, timings, HTTP failures and recent log excerpts for warnings/failures. Verbose JSON includes all check details.

--log=/new/path.jsonl saves redacted diagnostics for all checks, independently of terminal verbosity. Files are created exclusively with owner-only permissions; existing files/symlinks are never overwritten. The parent directory must exist. Failed log creation/writes are tool errors.

Redaction covers configured credentials, common secret assignments/headers, URL queries/fragments, email addresses and terminal escape sequences. Full HTML and request headers are excluded. **Extension output may contain other sensitive business data; review diagnostic files before sharing.** YAML/.env syntax errors omit source lines.

| Exit | Meaning |
| --- | --- |
| 0 | Pass, or warnings under default policy |
| 1 | Warnings with --fail-on-warning |
| 2 | Failed check(s) |
| 3 | Tool/configuration/dependency error |

JSON has schema_version, overall status, counts and per-check IDs/status/messages; tool errors use status ERROR. No automatic remediation.

## Operational boundaries

Magento's command allowlist is --version, deploy:mode:show, maintenance:status, indexer:status and cache:status. The cron helper issues SELECT only. No setup/compile/static deploy, reindex, cache flush, configuration/module changes, inventory edits, cart mutations, orders or payments.

Magento bootstraps and normal HTTP GETs can cause ordinary framework/extension side effects such as cache warming and access logging. This is not database-enforced or filesystem-enforced read-only execution. Validate on staging first as the normal filesystem owner.

## Development

~~~bash
composer install
composer lint
composer test
~~~

GitHub Actions checks PHP 8.2, 8.3 and 8.4 using fake Magento and a localhost HTTP server. Tests do not contact DTF endpoints or use a real Magento database. Coverage includes command restrictions, isolated cron reads, bounded output, timeouts, categories, dotenv precedence, HTTP/asset failures, redaction and exit codes.

Composer uses a committed lock file for repeatable installations of the Symfony 6.4 dependency line, independently of Magento. Use composer install for deployments; review dependency updates separately.

Manually verify Algolia results, navigation, configurable products, cart, Amasty checkout, Webkul fees, shipping, taxes, payment, order creation and confirmation email.
