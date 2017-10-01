# Guzzle Retry Middleware

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Build Status][ico-travis]][link-travis]
[![Coverage Status][ico-scrutinizer]][link-scrutinizer]
[![Quality Score][ico-code-quality]][link-code-quality]
[![Total Downloads][ico-downloads]][link-downloads]

This is a [Guzzle v6](http://guzzlephp.org) middleware library that implements automatic
retry of requests when responses with `503` or `429` status codes are returned.  It can also
be configured to retry requests that timeout.
 
If a server supplies a [Retry-After header](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Retry-After), 
this middleware will delay subsequent requests per the server's instructed wait period.

Unlike the built-in `RetryAfter` middleware, this middleware provides some default behavior for negotiating retries
based on rules in the HTTP Spec.  You can drop it right into your request stack without any additional configuration.

Features, at-a-glance:

- Automatically retries HTTP requests when a server responds with a 429 or 503 status (this is configurable)
- Sets a retry delay based on the `Retry-After` HTTP header, if it is sent, or automatically backs off exponentially if
  no `Retry-After` header is sent (also configurable)
- Optionally retries requests that time out (based on the `connect_timeout` or `timeout` options)
- Set an optional callback when a retry occurs (useful for logging/reporting)
- Specify a maximum number of retry attempts before giving up (default: 10)
- 100% test coverage, good inline documentation, and PSR-2 compliant

## Install

Via Composer

``` bash
$ composer require caseyamcl/guzzle_retry_middleware
```

## Usage

Basically:

``` php

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;

$stack = HandlerStack::create();
$stack->push(GuzzleRetryMiddleware::factory());

$client = new Client($stack);

// Make requests galore...

```

This is the default configuration.  If a HTTP server responds with a `429` or `503` status, this
middleware with intercept the response and retry it up to 10 times before giving up and doing whatever
behavior Guzzle would do by default (by default, throwing a `BadResponseException`).

If the server provides a `RetryAfter` header, this middleware will wait the specified time before
attempting a request again.  If not, then it will back off, waiting longer each time between requests
until giving up after 10 attempts.

### Options

The following options are available:

| Option                             | Type       | Default  | Summary |
| ---------------------------------- | ---------- | -------- | ------- |
| `max_retry_attempts`               | integer    | 10       | Maximum number of retries per request
| `retry_only_if_retry_after_header` | boolean    | false    | Retry only if `RetryAfter` header sent
| `retry_on_status`                  | array<int> | 503, 429 | The response status codes that will trigger a retry
| `default_retry_multiplier`         | float      | 1.5      | What to multiple the number of requests by if `RetryAfter` not supplied
| `on_retry_callback`                | callable   | null     | Optional callback to call when a retry occurs
| `retry_on_timeout`                 | boolean    | false    | Set to TRUE if you wish to retry requests that timeout (configured with `connect_timeout` or `timeout` options)

Each option is discussed in detail below.


### Configuring Options

Options can be set in one of three places:

```php

// Per request, in the same array as other Guzzle options
$response = $client->get('/some-url', [
   'max_retry_attempts' => 5,
   'on_retry_callback'  => $notifier
]);

// When you instantiate Guzzle, in the same array as other Guzzle options
$client = new \GuzzleHttp\Client([

    // Standard Guzzle options
    'base_url'        => 'http://example.org',
    'connect_timeout' => 10.0,
    
    // Retry options
   'max_retry_attempts' => 5,
   'on_retry_callback'  => $notifier
]);

// When you instantiate the Retry middleware
$stack = \GuzzleHttp\Stack::create();
$stack->push(GuzzleRetryMiddleware([
   'max_retry_attempts' => 5,
   'on_retry_callback'  => $notifier
]));

```

If you specify options in two or more places, the configuration is merged as follows:

1. Request options take precedence over Guzzle constructor options
2. Guzzle constructor options take precedence over middleware constructor options.

### Setting maximum retry attempts

This value should be an integer equal to or greater than 0.  Setting 0 or a negative
effectively disables this middleware.

Setting this value to 0 is useful when you want to retry attempts by default, but disable retries
for a particular request:

```php

// Set the default retry attempts to 5
$client = new \GuzzleHttp\Client(['max_retry_attempts' => 5]);

// Do not retry this request
$client->get('/some/url', ['max_retry_attempts' => 0]);

``` 

### Setting status codes to retry

```php

$response = $client->get('/some-path', [
    'retry_on_status' => [429, 503, 500]
]);

```

If the response includes a `RetryAfter` header, but its status code is not in the list, it will not be processed.

**Note:** I haven't tested this, but I sincerely believe you will see some wonky behavior if you attempt to use
this middleware with 3xx responses.  I don't suggest it.

### Setting default retry delay

If the response includes a valid `RetryAfter` header, this middleware will delay the next retry attempt the amount of
time that the server specifies in that header.

If the response includes a *non-valid* `RetryAfter` or does not provide a `RetryAfter` header, then this middleware
will use a default back-off algorithm: `multipler * number-of-attempts`:

Response with `RetryAfter` header:

```
      Client                 Server
      ------                 ------
      GET /resource    -> 
                       <-    429 Response with `Retry-After: 120`
      wait 120s                 
      GET /resource    ->   
                       <-    200 OK

```

Without `RetryAfter`, the number of requests is multiplied by the multiplier (default: `1.5`):

```
      Client                 Server
      ------                 ------
      GET /resource    -> 
                       <-    429 Response (no header)
      wait 1.5 x 1s                 
      GET /resource    ->   
                       <-    429 Response (no header)
      wait 1.5 x 2s                 
      GET /resource    ->   
                       <-    429 Response (no header)
      wait 1.5 x 3s                 
      GET /resource    ->   
                       <-    429 Response (no header)
      wait 1.5 x 4s                 
      GET /resource    ->   
                       <-    200 OK
```

You can set a custom multiplier:

```php

$response = $client->get('/some-path', [
    'default_retry_multiplier' => 2.5
]);

```

### Retrying requests that timeout

You can configure this middleware to retry requests that timeout.  Simply set the `retry_on_timeout` option to `true`:

```php

# Retry this request if it times out:
$response = $client->get('/some-path', [
    'retry_on_timeout` => true    // Set the retry middleware to retry when the connection or response times out
    'connect_timeout'  => 20,     // This is a built-in Guzzle option
    'timeout'          => 50      // This is also a built-in Guzzle option
]);

# You can also set these as defaults for every request:
$guzzle = new \GuzzleHttp\Client(['retry_on_timeout' => true, 'connect_timeout' => 20]);
$response = $guzzle->get('https://example.org');
```


### On-Retry callback

You can supply a callback method that will be called each time a request is retried.  This is useful for logging,
reporting, or anything else you can think of.

If you specify a callback, it will be called before the middleware calls the `usleep()` delay function.

```php

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Listen for retry events
 *
 * @param int                    $attemptNumber  How many attempts have been tried for this particular request
 * @param float                  $delay          How long the client will wait before retrying the request
 * @param RequestInterface       $request        Request
 * @param array                  $options        Guzzle request options
 * @param ResponseInterface|null $response       Response (or NULL if response not sent; e.g. connect timeout)
 */
$listener = function($attemptNumber, $delay, $request, $options, $response) {
    
    echo sprintf(
        "Retrying request to %s.  Server responded with %s.  Will wait %s seconds.  This is attempt #%s,
        $request->getUri()->getPath(),
        $response->getStatusCode(),
        number_format($delay, 2),
        $attemptNumber
    );
}

$client = new \GuzzleHttp\Client([
    'on_retry_callback' => $listener
]);

$response = $client->get('/some/path');

```


## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.


## Testing

``` bash
$ composer test
```

*Note:* Since this library tests timeouts, a few of the tests take a 2-3 seconds to run.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email caseyamcl@gmail.com instead of using the issue tracker.

## Credits

- [Casey McLaughlin][link-author]

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/caseyamcl/guzzle_retry_middleware.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/caseyamcl/guzzle_retry_middleware/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/caseyamcl/guzzle_retry_middleware.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/caseyamcl/guzzle_retry_middleware.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/caseyamcl/guzzle_retry_middleware.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/caseyamcl/guzzle_retry_middleware
[link-travis]: https://travis-ci.org/caseyamcl/guzzle_retry_middleware
[link-scrutinizer]: https://scrutinizer-ci.com/g/caseyamcl/guzzle_retry_middleware/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/caseyamcl/guzzle_retry_middleware
[link-downloads]: https://packagist.org/packages/caseyamcl/guzzle_retry_middleware
[link-author]: https://github.com/caseyamcl
[link-contributors]: ../../contributors
