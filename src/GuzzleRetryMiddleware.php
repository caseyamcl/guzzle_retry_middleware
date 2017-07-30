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

namespace GuzzleRetry;

use GuzzleHttp\Exception\BadResponseException;
use function GuzzleHttp\Promise\rejection_for;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Retry After Middleware
 *
 * Guzzle 6 middleware that retries requests when encountering responses
 * with certain conditions (429 or 503).  This middleware also respects
 * the `RetryAfter` header
 *
 * @author Casey McLaughlin <caseyamcl@gmail.com>
 */
class GuzzleRetryMiddleware
{
    // HTTP date format
    const DATE_FORMAT = 'D, d M Y H:i:s T';

    /**
     * @var array
     */
    private $defaultOptions = [

        // If server doesn't provide a Retry-After header, then set a default back-off delay
        'default_retry_multiplier'         => 1.5,

        // Set a maximum number of attempts per request
        'max_retry_attempts'               => 10,

        // Set this to TRUE to retry only if the HTTP Retry-After header is specified
        'retry_only_if_retry_after_header' => false,

        // Only retry when status is equal to these response codes
        'retry_on_status'                  => ['429', '503'],

        // Callback to trigger when delay occurs (accepts count, delay, request, response, options)
        'on_retry_callback'                => null
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
     * @param array $defaultOptions
     * @return \Closure
     */
    public static function factory(array $defaultOptions = [])
    {
        return function (callable $handler) use ($defaultOptions) {
            return new static($handler, $defaultOptions);
        };
    }

    /**
     * GuzzleRetryMiddleware constructor.
     *
     * @param callable $nextHandler
     * @param array $defaultOptions
     */
    public function __construct(callable $nextHandler, array $defaultOptions = [])
    {
        $this->nextHandler = $nextHandler;
        $this->defaultOptions = array_replace($this->defaultOptions, $defaultOptions);
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        // Combine options with defaults specified by this middleware
        $options = array_replace($this->defaultOptions, $options);

        // Set the retry count if not already set
        if (! isset($options['retry_count'])) {
            $options['retry_count'] = 0;
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
     * @param array $options
     * @return callable
     */
    private function onFulfilled(RequestInterface $request, array $options)
    {
        return function (ResponseInterface $response) use ($request, $options) {
            return ($this->shouldRetry($options, $response))
                ? $this->doRetry($request, $response, $options)
                : $response;
        };
    }

    /**
     * An exception or error was thrown during processing
     *
     * If the reason is a BadResponseException exception, check to see if
     * the request can be retried.  Otherwise, pass it on.
     *
     * @param RequestInterface $request
     * @param array $options
     * @return callable
     */
    private function onRejected(RequestInterface $request, array $options)
    {
        return function ($reason) use ($request, $options) {

            if ($reason instanceof BadResponseException
                && $reason->getResponse()
                && $this->shouldRetry($options, $reason->getResponse())) {
                return $this->doRetry($request, $reason->getResponse(), $options);
            } else {
                return rejection_for($reason);
            }
        };
    }

    /**
     * Check to see if a request can be retried
     *
     * This checks two things:
     *
     * 1. The response status code against the status codes that should be retried
     * 2. The number of attempts made thus far for this request
     *
     * @param array $options
     * @param ResponseInterface $response
     * @return bool  TRUE if the response should be retried, FALSE if not
     */
    private function shouldRetry(array $options, ResponseInterface $response)
    {
        $retryCount = isset($options['retry_count']) ? $options['retry_count'] : 0;
        $statuses   = array_map('intval', (array) $options['retry_on_status']);

        if ($retryCount >= (int) $options['max_retry_attempts']) {
            return false;
        } elseif (! $response->hasHeader('Retry-After') && $options['retry_only_if_retry_after_header']) {
            return false;
        } else {
            return in_array($response->getStatusCode(), $statuses);
        }
    }

    /**
     * Retry the request
     *
     * Increments the retry count, determines the delay (timeout), executes callbacks, sleeps, and re-send the request
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param array $options
     * @return
     */
    protected function doRetry(RequestInterface $request, ResponseInterface $response, array $options)
    {
        // Increment the retry count
        ++$options['retry_count'];

        // Determine the delay timeout
        $delayTimeout = $this->determineDelayTimeout($response, $options);

        // Callback?
        if ($options['on_retry_callback']) {
            call_user_func(
                $options['on_retry_callback'],
                $options['retry_count'],
                $delayTimeout,
                $request,
                $response,
                $options
            );
        }

        // Delay!
        usleep($delayTimeout * 1000000);

        // Return
        return $this($request, $options);
    }

    /**
     * Determine the delay timeout
     *
     * Attempts to read and interpret the HTTP `Retry-After` header, or defaults
     * to a built-in incremental back-off algorithm.
     *
     * @param ResponseInterface $response
     * @param array $options
     * @return float  Delay timeout, in seconds
     */
    protected function determineDelayTimeout(ResponseInterface $response, array $options)
    {
        $default = $options['default_retry_multiplier'] * $options['retry_count'];

        // Retry-After can be a delay in seconds or a date
        // (see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Retry-After)
        if ($response->hasHeader('Retry-After')) {
            return
                $this->deriveTimeoutFromHeader($response->getHeader('Retry-After')[0])
                ?: $default;
        } else {
            return $default;
        }
    }

    /**
     * Attempt to derive the timeout from the HTTP `Retry-After` header
     *
     * The spec allows the header value to either be a number of seconds or a datetime.
     *
     * @param string $headerValue
     * @return float|null  The number of seconds to wait, or NULL if unsuccessful (invalid header)
     */
    private function deriveTimeoutFromHeader($headerValue)
    {
        // The timeout will either be a number or a HTTP-formatted date,
        // or seconds (integer)
        if ((string) intval(trim($headerValue)) == $headerValue) {
            return intval(trim($headerValue));
        } elseif ($date = \DateTime::createFromFormat(self::DATE_FORMAT, trim($headerValue))) {
            return (int) $date->format('U') - time();
        } else {
            return null;
        }
    }
}
