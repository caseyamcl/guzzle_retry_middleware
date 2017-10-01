# Changelog

All Notable changes to `guzzle_retry_middleware` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](http://keepachangelog.com/) principles.

## v2.0 (2017-10-01)

### Added
- Added ability to retry on connect or request timeout (`retry_on_timeout` option)
- Added better tests for retry callback

### Changed
- Changed callback signature for `on_retry_callback` callback.  Response object is no longer guaranteed to be present,
  so the callback signature now looks like this: 
  `(int $retryCount, int $delayTimeout, RequestInterface $request, array $options, ResponseInterface|null $response)`.
- Updated Guzzle requirement to v6.3 or newer

### Fixed
- TODO: Clarified and cleaned up some documentation in README

## v1.0 (2017-07-29)

### Added
- Everything; this is the initial version.