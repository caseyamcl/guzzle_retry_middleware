# Changelog

All Notable changes to `guzzle_retry_middleware` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](http://keepachangelog.com/) principles.

## [2.12.1] (2024-12-05)
### Changed
- Added GitHub build action for PHP 8.4
- Updated GitHub build `action/checkout` and `action/cache`
### Fixed
- Implicit nullable in `doRetry` method (for PHP 8.4)

## [2.12.0]
### Added
- Support for connection reset by peer (thanks @fredericgboutin-yapla)

## [2.11.0] (2024-09-16)
### Added
- Include RequestInterface as  [`should_retry_callback` argument](./README.md#custom-retry-decision-logic) when triggered (thanks @Nicolai-).

### Removed
- `.scrutinizer.yml` file (not used anymore)

## [2.10.0] (2024-06-19)
### Added
- Include [`on_retry_callback` argument]() when triggered by `onRejected` (thanks @ViktorCollin)
- PHP 8.3 tests in GitHub builds

### Fixed
- Ensure that no requests are sent after the `give_up_after_secs` expires, and fail quickly (thanks @rubentebogt)
- Minor code syntax and comment fixes
- Cleaned up this CHANGELOG a bit

## [2.9.0] (2023-08-30)
### Added
- New [`retry_on_methods` option](./README.md#setting-specific-http-methods-to-retry-on).

## [2.8.0] (2022-11-20)
### Added
- New [`should_retry_callback` option](./README.md#custom-retry-decision-logic).
- GitHub Action build for PHP 8.2
### Changed
- Added some extra parameters to PHPStan checks to make sure they don't fail on automated builds
- Minor `README` updates

## [2.7.1] (2022-08-07)
### Fixed
- Composer 2.2+ security issue with installing `phpstan/extension-installer`
- Ensure `XDEBUG_MODE` environment variable is set when running PHPUnit

## [2.7.0] (2021-12-03)
### Added
- Support PHP v8.1
- New [`give_up_after_secs` parameter](./README.md#setting-a-hard-time-ceiling-for-all-retries)
### Changed
- Upgraded to PHPStan 1.2
- Improved comments for options array

## [2.6.1] (2020-11-27)
### Added
- PHPStan in dev dependencies
- Additional build checks (PHPStan and PHP-CS)
- Automatic SVG badge generation for code coverage

### Fixed
- Made `GuzzleRetryMiddleware::__construct` method final
- `$options` parameter comments PHPStan was complaining about
- `shouldRetryHttpResponse` values assume that the `$response` parameter is not null
- Ensure date `$dateFormat` is never NULL or empty string in `deriveTimeoutFromHeader`
- Additional cleanup based on PHPStan report

### Removed
- Build dependency on scrutinizer.org service

## [2.6] (2020-11-24)
### Added
- GitHub Actions build status badge in `README.md`
- Support for custom date formats in `Retry-After` header via new `retry_after_date_format` option
- `max_allowable_timeout_secs` option to set a ceiling on the maximum time the client is willing to wait between requests
- Support for Guzzle 7 class-based static methods

### Changed
- Removed unnecessary comments
- Name of GitHub Action to `Github Build`

### Removed
- `.travis.yml` build support (switched to GitHub Actions)

## [2.5] (2020-11-02)
### Added
- Ability to handle non-integer values in `Retry-After` headers (thanks @andrewdalpino)
- Beginning GitHub Workflows code (support for Travis-CI will be removed in the next minor version)
- Support for PHP v8.0 in `composer.json`

## [2.4] (2020-08-19)
### Added
- Option to specify custom HTTP header name other than `Retry-After` (thanks @jamesaspence)

### Changed
- Added a few things to `.gitignore` (minor)
- Updated `phpunit.xml.dist` to latest spec

### Removed
- Removed build tests for PHP 7.1 in `.travis.yml`

## [2.3.3] (2020-05-17)

### Changed
- Minimum allowed version of PHPUnit is v7.5
- Made version constraint syntax consistent in `composer.json`
- Updated alias for `dev-master` to `2.0-dev` in `composer.json`

### Fixed
- Cleaned up comments and updated syntax in tests to be compatible with newer versions of PHPUnit (v8 and v9)

## [2.3.2] (2020-01-27)

### Added
- PHP 7.4 build test in `.travis.yml` (thanks @alexeyshockov)
- Guzzle v7 support in `composer.json` (thanks @alexeyshockov)

## [2.3.1] (2019-10-28)

### Added
- `declare(strict_types=1)` in unit test file

### Changed
- Fixes to README.md
- Code tweaks: Upgrade to PSR-12 compliance

## [2.3] (2019-09-16)

### Added
- PHP 7 goodness: `declare(strict_types=1)` and method return signatures
- PHP v7.3 tests in `.travis.yml`

### Changed
- Made minimum requirement for PHP v7.1 (note: this is considered a [compatible change](https://semver.org/#what-should-i-do-if-i-update-my-own-dependencies-without-changing-the-public-api))
- Updated to Carbon 2.0 (only affects tests)
- The `$request` and `$options` variables are now passed by reference in the retry callback to allow for modification (thanks @Krunch!)  

### Removed
- Removed unsupported tests for unsupported PHP versions from `.travis.yml` file
- Removed support for older versions of PHPUnit 

### Fixed
- Always ensure positive integer used when calculating delay timeout (fixes #12)
- Retry connect exception regardless of cURL error code (thanks @LeoniePhiline) (fixes #14)

## [2.2] (2018-06-03)

### Added
- Added `expose_retry_header` and `retry_header` options for debugging purposes (thanks, @coudenysj)
- Travis CI now tests PHP v7.2

### Changed
- Allow newer versions of PHPUnit in `composer.json` (match Guzzle composer.json PHPUnit requirements)

### Fixed
- Refactored data provider method name in PHPUnit test (`testRetryOccursWhenStatusCodeMatchesProvider` 
  → `providerForRetryOccursWhenStatusCodeMatches`)
- Use PHPUnit new namespaced class name
- Fix `phpunit.xml.dist` specification so that PHPUnit no longer emits warnings
- Travis CI should use lowest library versions on lowest supported version of PHP (v5.5, not 5.6)  

### Removed
- `hhvm` tests in Travis CI; they were causing builds to fail

## [2.1] (2018-02-13)

### Added
- Added `retry_enabled` parameter to allow quick disable of retry on specific requests
- Added ability to pass in a callable to `default_retry_multiplier` in order to implement custom delay logic

## [2.0] (2017-10-02)

### Added
- Added ability to retry on connect or request timeout (`retry_on_timeout` option)
- Added better tests for retry callback

### Changed
- Changed callback signature for `on_retry_callback` callback.  Response object is no longer guaranteed to be present,
  so the callback signature now looks like this: 
  `(int $retryCount, int $delayTimeout, RequestInterface $request, array $options, ResponseInterface|null $response)`.
- Updated Guzzle requirement to v6.3 or newer

### Fixed
- Clarified and cleaned up some documentation in README, including a typo.

## [1.0] (2017-07-29)

### Added
- Everything; this is the initial version.
