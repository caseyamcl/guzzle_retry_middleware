<?php

/**
 * Guzzle Retry Middleware Library
 *
 * @license http://opensource.org/licenses/MIT
 * @link https://github.com/caseyamcl/guzzle_retry_middleware
 * @version 2.0
 * @package caseyamcl/guzzle_retry_middleware
 * @author Casey McLaughlin <caseyamcl@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * ------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GuzzleRetry;

use Closure;
use DateTime;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function call_user_func;
use function call_user_func_array;
use function GuzzleHttp\Promise\rejection_for;
use function in_array;
use function is_callable;

/**
 * Retry After Middleware
 *
 * Guzzle 6/7 middleware that retries requests when encountering responses
 * with certain conditions (429 or 503).  This middleware also respects
 * the `RetryAfter` header
 *
 * @author Casey McLaughlin <caseyamcl@gmail.com>
 */
class GuzzleRetryMiddleware
{
    // HTTP date format
    public const DATE_FORMAT = 'D, d M Y H:i:s T';

    // Default retry header (off by default; configurable)
    public const RETRY_HEADER = 'X-Retry-Counter';

    // Default retry-after header
    public const RETRY_AFTER_HEADER = 'Retry-After';

    /**
     * @var array<string,mixed>
     */
    private $defaultOptions = [

        // Retry enabled.  Toggle retry on or off per request
        'retry_enabled'                    => true,

        // If server doesn't provide a Retry-After header, then set a default back-off delay
        // NOTE: This can either be a float, or it can be a callable that returns a (accepts count and response|null)
        'default_retry_multiplier'         => 1.5,

        // Set a maximum number of attempts per request
        'max_retry_attempts'               => 10,

        // Maximum allowable timeout seconds
        'max_allowable_timeout_secs'       => null,

        // Give up after seconds
        'give_up_after_secs'               => null,

        // Set this to TRUE to retry only if the HTTP Retry-After header is specified
        'retry_only_if_retry_after_header' => false,

        // Only retry when status is equal to these response codes
        'retry_on_status'                  => ['429', '503'],

        // Callback to trigger before delay occurs (accepts count, delay, request, response, options)
        'on_retry_callback'                => null,

        // Retry on connect timeout?
        'retry_on_timeout'                 => false,

        // Add the number of retries to an X-Header
        'expose_retry_header'              => false,

        // The header key
        'retry_header'                     => self::RETRY_HEADER,

        // The retry after header key
        'retry_after_header'               => self::RETRY_AFTER_HEADER,

        // Date format
        'retry_after_date_format'          => self::DATE_FORMAT
    ];

    /**
     * @var callable
     */
    private $nextHandler;

    /**
     * Provides a closure that can be pushed onto the handler stack
     *
     * Example:
     * <code>$handlerStack->push(GuzzleRetryMiddleware::factory());</code>
     *
     * @param array<string,mixed> $defaultOptions
     * @return Closure
     */
    public static function factory(array $defaultOptions = []): Closure
    {
        return function (callable $handler) use ($defaultOptions): self {
            return new static($handler, $defaultOptions);
        };
    }

    /**
     * GuzzleRetryMiddleware constructor.
     *
     * @param callable $nextHandler
     * @param array<string,mixed> $defaultOptions
     */
    final public function __construct(callable $nextHandler, array $defaultOptions = [])
    {
        $this->nextHandler = $nextHandler;
        $this->defaultOptions = array_replace($this->defaultOptions, $defaultOptions);
    }

    /**
     * @param RequestInterface $request
     * @param array<string,mixed> $options
     * @return Promise
     */
    public function __invoke(RequestInterface $request, array $options): Promise
    {
        // Combine options with defaults specified by this middleware
        $options = array_replace($this->defaultOptions, $options);

        // Set the request timestamp as far as we know it
        $options['request_timestamp'] = time();

        // Set the retry counter if not already set
        if (! isset($options['retry_count'])) {
            $options['retry_count'] = 0;
        }

        if ($options['retry_count'] === 0) {
            $options['first_request_timestamp'] = time();
        }

        $next = $this->nextHandler;
        return $next($request, $options)
            ->then(
                $this->onFulfilled($request, $options),
                $this->onRejected($request, $options)
            );
    }

    /**
     * No exceptions were thrown during processing
     *
     * Depending on where this middleware is in the stack, the response could still
     * be unsuccessful (e.g. 429 or 503), so check to see if it should be retried
     *
     * @param RequestInterface $request
     * @param array<string,mixed> $options
     * @return callable
     */
    protected function onFulfilled(RequestInterface $request, array $options): callable
    {
        return function (ResponseInterface $response) use ($request, $options) {
            return $this->shouldRetryHttpResponse($options, $response)
                ? $this->doRetry($request, $options, $response)
                : $this->returnResponse($options, $response);
        };
    }

    /**
     * An exception or error was thrown during processing
     *
     * If the reason is a BadResponseException exception, check to see if
     * the request can be retried.  Otherwise, pass it on.
     *
     * @param RequestInterface $request
     * @param array<string,mixed> $options
     * @return callable
     */
    protected function onRejected(RequestInterface $request, array $options): callable
    {
        return function (Throwable $reason) use ($request, $options): PromiseInterface {
            // If was bad response exception, test if we retry based on the response headers
            if ($reason instanceof BadResponseException) {
                if ($this->shouldRetryHttpResponse($options, $reason->getResponse())) {
                    return $this->doRetry($request, $options, $reason->getResponse());
                }
            // If this was a connection exception, test to see if we should retry based on connect timeout rules
            } elseif ($reason instanceof ConnectException) {
                // If was another type of exception, test if we should retry based on timeout rules
                if ($this->shouldRetryConnectException($options)) {
                    return $this->doRetry($request, $options);
                }
            }

            // If made it here, then we have decided not to retry the request
            // Future-proofing this; remove when bumping minimum Guzzle version to 7.0
            if (class_exists('\GuzzleHttp\Promise\Create')) {
                return \GuzzleHttp\Promise\Create::rejectionFor($reason);
            } else {
                return rejection_for($reason);
            }
        };
    }

    /**
     * Decide whether to retry on connect exception
     *
     * @param array<string,mixed> $options
     * @return bool
     */
    protected function shouldRetryConnectException(array $options): bool
    {
        return $options['retry_enabled']
            && ($options['retry_on_timeout'] ?? false)
            && $this->hasTimeAvailable($options) !== false
            && $this->countRemainingRetries($options) > 0;
    }

    /**
     * Check whether to retry a request that received an HTTP response
     *
     * This checks three things:
     *
     * 1. The response status code against the status codes that should be retried
     * 2. The number of attempts made thus far for this request
     * 3. If 'give_up_after_secs' option is set, time is still available
     *
     * @param array<string,mixed> $options
     * @param ResponseInterface|null $response
     * @return bool  TRUE if the response should be retried, FALSE if not
     */
    protected function shouldRetryHttpResponse(array $options, ?ResponseInterface $response = null): bool
    {
        $statuses = array_map('\intval', (array) $options['retry_on_status']);
        $hasRetryAfterHeader = $response && $response->hasHeader('Retry-After');

        switch (true) {
            case $options['retry_enabled'] === false:
            case $this->hasTimeAvailable($options) === false:
            case $this->countRemainingRetries($options) === 0: // No Retry-After header, and it is required?  Give up!
            case (! $hasRetryAfterHeader && $options['retry_only_if_retry_after_header']):
                return false;

            // Conditions met; see if status code matches one that can be retried
            default:
                $statusCode = $response ? $response->getStatusCode() : 0;
                return in_array($statusCode, $statuses, true);
        }
    }

    /**
     * Count the number of retries remaining.  Always returns 0 or greater.
     *
     * @param array<string,mixed> $options
     * @return int
     */
    protected function countRemainingRetries(array $options): int
    {
        $retryCount  = isset($options['retry_count']) ? (int) $options['retry_count'] : 0;

        $numAllowed  = isset($options['max_retry_attempts'])
            ? (int) $options['max_retry_attempts']
            : $this->defaultOptions['max_retry_attempts'];

        return (int) max([$numAllowed - $retryCount, 0]);
    }

    /**
     * Retry the request
     *
     * Increments the retry count, determines the delay (timeout), executes callbacks, sleeps, and re-sends the request
     *
     * @param RequestInterface $request
     * @param array<string,mixed> $options
     * @param ResponseInterface|null $response
     * @return Promise
     */
    protected function doRetry(RequestInterface $request, array $options, ResponseInterface $response = null): Promise
    {
        // Increment the retry count
        ++$options['retry_count'];

        // Determine the delay timeout
        $delayTimeout = $this->determineDelayTimeout($options, $response);

        // Callback?
        if ($options['on_retry_callback']) {
            call_user_func_array(
                $options['on_retry_callback'],
                [
                    (int) $options['retry_count'],
                    $delayTimeout,
                    &$request,
                    &$options,
                    $response
                ]
            );
        }

        // Delay!
        usleep((int) ($delayTimeout * 1e6));

        // Return
        return $this($request, $options);
    }

    /**
     * @param array<string,mixed> $options
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    protected function returnResponse(array $options, ResponseInterface $response): ResponseInterface
    {
        if ($options['expose_retry_header'] === false || $options['retry_count'] === 0) {
            return $response;
        }

        return $response->withHeader($options['retry_header'], $options['retry_count']);
    }

    /**
     * Determine the delay timeout
     *
     * Attempts to read and interpret the configured Retry-After header, or defaults
     * to a built-in incremental back-off algorithm.
     *
     * @param array<string,mixed> $options
     * @param ResponseInterface|null $response
     * @return float  Delay timeout, in seconds
     */
    protected function determineDelayTimeout(array $options, ?ResponseInterface $response = null): float
    {
        // If 'default_retry_multiplier' option is a callable, call it to determine the default timeout...
        if (is_callable($options['default_retry_multiplier'])) {
            $defaultDelayTimeout = (float) call_user_func(
                $options['default_retry_multiplier'],
                $options['retry_count'],
                $response
            );
        } else { // ...or if it is a numeric value (default), use that.
            $defaultDelayTimeout = (float) $options['default_retry_multiplier'] * $options['retry_count'];
        }

        // Retry-After can be a delay in seconds or a date
        // (see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Retry-After)
        if ($response && $response->hasHeader($options['retry_after_header'])) {
            $timeout = $this->deriveTimeoutFromHeader(
                $response->getHeader($options['retry_after_header'])[0],
                $options['retry_after_date_format']
            ) ?? $defaultDelayTimeout;
        } else {
            $timeout = abs($defaultDelayTimeout);
        }

        // If the max_allowable_timeout_secs is set, ensure the timeout value is less than that
        if (! is_null($options['max_allowable_timeout_secs']) && abs($options['max_allowable_timeout_secs']) > 0) {
            $timeout = min(abs($timeout), (float) abs($options['max_allowable_timeout_secs']));
        } else {
            $timeout = abs($timeout);
        }

        // If 'give_up_after_secs' is set, account for it in determining the timeout
        if ($options['give_up_after_secs']) {
            $giveUpAfterSecs = abs((float) $options['give_up_after_secs']);
            $timeSinceFirstReq =  $options['request_timestamp'] - $options['first_request_timestamp'];
            $timeout = min($timeout, ($giveUpAfterSecs - $timeSinceFirstReq));
        }

        return $timeout;
    }

    /**
     * @param array<string,mixed> $options
     * @return bool
     */
    protected function hasTimeAvailable(array $options): bool
    {
        // If there is not a 'give_up_after_secs' option, or it is set to a non-truthy value, bail
        if (! $options['give_up_after_secs']) {
            return true;
        }

        $giveUpAfterTimestamp = $options['first_request_timestamp'] + abs(intval($options['give_up_after_secs']));
        return $options['request_timestamp'] < $giveUpAfterTimestamp;
    }

    /**
     * Attempt to derive the timeout from the `Retry-After` (or custom) header value
     *
     * The spec allows the header value to either be a number of seconds or a datetime.
     *
     * @param string $headerValue
     * @param string $dateFormat
     * @return float|null  The number of seconds to wait, or NULL if unsuccessful (invalid header)
     */
    protected function deriveTimeoutFromHeader(string $headerValue, string $dateFormat = self::DATE_FORMAT): ?float
    {
        // The timeout will either be a number or a HTTP-formatted date,
        // or seconds (integer)
        if (is_numeric($headerValue)) {
            return (float) trim($headerValue);
        } elseif ($date = DateTime::createFromFormat($dateFormat ?: self::DATE_FORMAT, trim($headerValue))) {
            return (float) $date->format('U') - time();
        }

        return null;
    }
}
