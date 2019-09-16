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

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * GuzzleRetryMiddlewareTest
 *
 * @author Casey McLaughlin <caseyamcl@gmail.com>
 */
class GuzzleRetryMiddlewareTest extends TestCase
{
    /**
     * Simple instantiation test provides immediate feedback on syntax errors
     */
    public function testInstantiation()
    {
        $handler = new MockHandler();
        $obj = new GuzzleRetryMiddleware($handler);
        $this->assertInstanceOf(GuzzleRetryMiddleware::class, $obj);
    }

    /**
     * Test retry occurs when status codes match or do not match
     *
     * @dataProvider providerForRetryOccursWhenStatusCodeMatches
     * @param Response $response
     * @param bool $retryShouldOccur
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testRetryOccursWhenStatusCodeMatches(Response $response, $retryShouldOccur)
    {
        $retryOccurred = false;

        $stack = HandlerStack::create(new MockHandler([
            $response,
            new Response(200, [], 'All Good')
        ]));

        $stack->push(GuzzleRetryMiddleware::factory([
            'default_retry_multiplier' => 0,
            'on_retry_callback' => function () use (&$retryOccurred) {
                $retryOccurred = true;
            }
        ]));

        $client = new Client(['handler' => $stack]);
        $response = $client->request('GET', '/');

        $this->assertEquals($retryShouldOccur, $retryOccurred);
        $this->assertEquals('All Good', (string) $response->getBody());
    }

    /**
     * Provides data for test above
     *
     * @return array
     */
    public function providerForRetryOccursWhenStatusCodeMatches()
    {
        return [
            [new Response(429, [], 'back off'),       true],
            [new Response(503, [], 'back off buddy'), true],
            [new Response(200, [], 'All Good'),       false]
        ];
    }

    /**
     * Test that the max_retry_attempts parameter is respected
     *
     * @dataProvider retriesFailAfterSpecifiedLimitProvider
     * @param array $responses
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testRetriesFailAfterSpecifiedLimit(array $responses)
    {
        $retryCount = 0;

        $stack = HandlerStack::create(new MockHandler($responses));

        $stack->push(GuzzleRetryMiddleware::factory([
            'max_retry_attempts'       => 5, // Allow only 5 attempts
            'retry_on_timeout'         => true,
            'default_retry_multiplier' => 0,
            'on_retry_callback'        => function ($num) use (&$retryCount) {
                $retryCount = $num;
            }
        ]));

        $client = new Client(['handler' => $stack]);

        try {
            $client->request('GET', '/');
        } catch (TransferException $e) {
            $this->assertEquals(5, $retryCount);
        }
    }

    /**
     * Returns Data sets for testRetriesFailAfterSpecifiedLimit
     *
     * #0 is a collection of 10 429 exceptions, and data-set
     * #1 is a collection of 10 connect timeouts
     *
     * @return array
     */
    public function retriesFailAfterSpecifiedLimitProvider()
    {
        $http429Response = new Response(429, [], 'Wait');
        $connectException = new ConnectException(
            'Connect Timeout',
            new Request('GET', '/'),
            null,
            ['errno' => 28]
        );

        return [
            [array_fill(0, 10, $http429Response)],
            [array_fill(0, 10, $connectException)]
        ];
    }

    /**
     * Test that setting options in the Guzzle client constructor works
     */
    public function testDefaultOptionsCanBeSetInGuzzleClientConstructor()
    {
        $numRetries = 0;

        // Build 2 responses with 429 headers and one good one
        $responses = [
            new Response(429, [], 'Wait'),
            new Response(429, [], 'Wait...'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory());

        $client = new Client([
            'handler' => $stack,

            // set some defaults in Guzzle..
            'default_retry_multiplier' => 0,
            'max_retry_attempts'       => 2,
            'on_retry_callback'        => function ($retryCount) use (&$numRetries) {
                $numRetries = $retryCount;
            }
        ]);

        $response = $client->request('GET', '/');
        $this->assertEquals(2, $numRetries);
        $this->assertEquals('Good', (string) $response->getBody());
    }

    /**
     * Test that the X header is injected when requested
     */
    public function testHeaderIsInjectedWhenRequested()
    {
        // Build 2 responses with 429 headers and one good one
        $responses = [
            new Response(429, [], 'Wait'),
            new Response(429, [], 'Wait...'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory());

        $client = new Client([
            'handler' => $stack,

            // set some defaults in Guzzle..
            'default_retry_multiplier' => 0,
            'max_retry_attempts'       => 2,
            'expose_retry_header'            => true
        ]);

        $response = $client->request('GET', '/');
        $this->assertTrue($response->hasHeader(GuzzleRetryMiddleware::RETRY_HEADER));
        $this->assertEquals([2], $response->getHeader(GuzzleRetryMiddleware::RETRY_HEADER));
        $this->assertEquals('Good', (string) $response->getBody());
    }

    /**
     * Test that the X header is not injected when no retries occurred
     */
    public function testHeaderIsNotInjectedWhenNoRetriesOccurred()
    {
        $responses = [
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory());

        $client = new Client([
            'handler' => $stack,

            // set some defaults in Guzzle..
            'default_retry_multiplier' => 0,
            'max_retry_attempts'       => 2,
            'expose_retry_header'            => true
        ]);

        $response = $client->request('GET', '/');
        $this->assertFalse($response->hasHeader(GuzzleRetryMiddleware::RETRY_HEADER));
        $this->assertEquals('Good', (string) $response->getBody());
    }

    /**
     * Test that setting options per request overrides other options correctly
     */
    public function testOptionsCanBeSetInRequest()
    {
        $numRetries = 0;

        // Build 2 responses with 429 headers and one good one
        $responses = [
            new Response(429, [], 'Wait'),
            new Response(429, [], 'Wait...'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory([
            'max_retry_attempts' => 1
        ]));

        $client = new Client(['handler' => $stack]);

        // Override default settings in request
        $response = $client->request('GET', '/', [
            'default_retry_multiplier' => 0,
            'max_retry_attempts'       => 2,
            'on_retry_callback'        => function ($retryCount) use (&$numRetries) {
                $numRetries = $retryCount;
            }
        ]);

        $this->assertEquals(2, $numRetries);
        $this->assertEquals('Good', (string) $response->getBody());
    }

    /**
     * Test delay is set correctly when server provides `Retry-After` header in date form
     */
    public function testDelayDerivedFromDateIfServerProvidesValidRetryAfterDateHeader()
    {
        $calculatedDelay = null;

        $retryAfter = Carbon::now()
            ->addSeconds(2)
            ->format(GuzzleRetryMiddleware::DATE_FORMAT);

        $responses = [
            new Response(429, ['Retry-After' => $retryAfter], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory([
            'on_retry_callback' => function ($numRetries, $delay) use (&$calculatedDelay) {
                $calculatedDelay = $delay;
            }
        ]));

        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');

        $this->assertTrue($calculatedDelay > 1 && $calculatedDelay < 3);
    }

    /**
     * Test delay is set correctly when server provides `Retry-After` header in integer form
     */
    public function testDelayDerivedFromSecondsIfServerProvidesValidRetryAfterSecsHeader()
    {
        $calculatedDelay = null;

        $responses = [
            new Response(429, ['Retry-After' => 3], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory([
            'on_retry_callback' => function ($numRetries, $delay) use (&$calculatedDelay) {
                $calculatedDelay = $delay;
            }
        ]));

        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');

        $this->assertEquals(3, $calculatedDelay);
    }

    /**
     * Test default delay is used if server provides a malformed `Retry-After` header
     */
    public function testDefaultDelayOccursIfServerProvidesInvalidRetryAfterHeader()
    {
        $calculatedDelay = null;

        $responses = [
            new Response(429, ['Retry-After' => 'nope-lol3'], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory([
            'default_retry_multiplier' => 1.5,
            'on_retry_callback' => function ($numRetries, $delay) use (&$calculatedDelay) {
                $calculatedDelay = $delay;
            }
        ]));

        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');

        $this->assertEquals(1.5, $calculatedDelay);
    }

    /**
     * Test that retry does not occur if no `Retry-After` header and option is set
     */
    public function testDelayDoesNotOccurIfNoRetryAfterHeaderAndOptionSetToIgnore()
    {
        $retryOccurred = false;

        $responses = [
            new Response(429, [], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory([
            'retry_only_if_retry_after_header' => true,
            'on_retry_callback' => function () use (&$retryOccurred) {
                $retryOccurred = true;
            }
        ]));

        $client = new Client(['handler' => $stack]);

        try {
            $client->request('GET', '/');
        } catch (BadResponseException $e) {
            // pass..
        }

        $this->assertFalse($retryOccurred);
    }

    /**
     * Test that the retry multiplier works as predicted
     */
    public function testRetryMultiplierWorksAsExpected()
    {
        $delayTimes = [];

        $responses = [
            new Response(429, [], 'Wait'),
            new Response(429, [], 'Wait'),
            new Response(429, [], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory([
            'default_retry_multiplier' => 0.5,
            'on_retry_callback' => function ($numRetries, $delay) use (&$delayTimes) {
                $delayTimes[] = $delay;
            }
        ]));

        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');

        $this->assertEquals([0.5 * 1, 0.5 * 2, 0.5 * 3], $delayTimes);
    }

    public function testRetryMultiplierWorksAsCallback()
    {
        $programmedDelays = [0.5, 0.1, 0.3];
        $actualDelays     = [];

        $responses = [
            new Response(429, [], 'Wait'),
            new Response(429, [], 'Wait'),
            new Response(429, [], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory([
            'default_retry_multiplier' => function ($num, ResponseInterface $response = null) use (&$programmedDelays) {
                return $programmedDelays[$num-1];
            },
            'on_retry_callback' => function ($numRetries, $delay) use (&$actualDelays) {
                $actualDelays[] = $delay;
            }
        ]));
        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');

        $this->assertEquals($programmedDelays, $actualDelays);
    }

    /**
     * Test that BadResponseException and child classes are caught and handled
     */
    public function testBadResponseExceptionIsHandled()
    {
        $numberOfRetries = 0;
        $request = new Request('GET', '/');

        $responses = [
            new BadResponseException('Test', $request, new Response(429, [], 'Wait 1')),
            new ServerException('Test', $request, new Response(503, [], 'Wait 2')),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory([
            'on_retry_callback' => function ($numRetries) use (&$numberOfRetries) {
                $numberOfRetries = $numRetries;
            }
        ]));

        $client = new Client(['handler' => $stack]);
        $client->send($request);

        $this->assertEquals(2, $numberOfRetries);
    }

    /**
     * Test that other exceptions (non-BadResponseException) are not caught or handled
     *
     * @expectedException \GuzzleHttp\Exception\TransferException
     */
    public function testNonBadResponseExceptionIsNotHandled()
    {
        $responses = [new TransferException('Something terrible happened')];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory([]));

        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');
    }

    public function testConnectTimeoutIsHandledWhenOptionIsSetToTrue()
    {
        // Send a connect timeout (cURL error 28) then a good response
        $responses = [

            new ConnectException(
                'Connection timed out',
                new Request('get', '/'),
                null,
                ['errno' => 28]
            ),

            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory(['retry_on_timeout' => true])); // Enable connect timeout

        $client = new Client(['handler' => $stack]);
        $response = $client->request('GET', '/');

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testConnectTimeoutIsNotHandledWhenOptionIsSetToFalse()
    {
        // Send a connect timeout (cURL error 28) then a good response
        $responses = [

            new ConnectException(
                'Connection timed out',
                new Request('get', '/'),
                null,
                ['errno' => 28]
            ),

            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory(['retry_on_connect_timeout' => false])); // DISABLE connect timeout

        $client = new Client(['handler' => $stack]);
        $errorNo = null;

        try {
            $client->request('GET', '/');
        } catch (ConnectException $e) {
            $errorNo = $e->getHandlerContext()['errno'];
        }

        $this->assertEquals(28, $errorNo);
    }

    /**
     * Ensure retry callback accepts expected arguments
     */
    public function testRetryCallbackReceivesExpectedArguments()
    {
        $callback = function ($retryCount, $delayTimeout, $request, $options, $response) {
            $this->assertInternalType('int', $retryCount);
            $this->assertInternalType('float', $delayTimeout);
            $this->assertInstanceOf(RequestInterface::class, $request);
            $this->assertInternalType('array', $options);
            $this->assertInstanceOf(ResponseInterface::class, $response);
        };

        $responses = [
            new Response(429, [], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory([
            'default_retry_multiplier' => 0,
            'on_retry_callback' => $callback
        ]));

        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');
    }

    /**
     * The only use-case that exists for connect exceptions is timeouts
     *
     * @expectedException \GuzzleHttp\Exception\ConnectException
     */
    public function testNonTimeoutConnectExceptionIsNotRetried()
    {
        // Send a connect timeout (cURL error 28) then a good response
        $responses = [
            new ConnectException('Non-timeout issue', new Request('get', '/')),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory(['retry_on_connect_timeout' => true])); // DISABLE connect timeout

        $client = new Client(['handler' => $stack]);
        $client->get('/');
    }

    /**
     * Test that setting `retry_enabled` to FALSE on an individual request actually disables retries
     */
    public function testRetryEnableSettingOverridesDefaultConfigurationPerRequest()
    {
        $responses = [
            new Response(429, [], 'Wait'), // Queue for request with retry enabled
            new Response(200, [], 'Good'),

            new Response(429, [], 'Wait'), // Queue for request with retry disabled
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory());
        $client = new Client(['handler' => $stack]);

        // Default should retry and get the response with 200
        $response = $client->get('/');
        $this->assertEquals(200, $response->getStatusCode());

        // Now we disable retry and we should get the 429

        try {
            $response = $client->get('/', ['retry_enabled' => false]);
            $code = $response->getStatusCode();
        } catch (ClientException $e) {
            $code = $e->getResponse()->getStatusCode();
        }

        $this->assertEquals(429, $code); // should be the first response queued
    }

    /**
     * Test that modifying request and options inside the retry callback works
     */
    public function testRetryCallbackReferenceModification()
    {
        // Build one response with 429 headers and one good one
        $responses = [
            new Response(429, [], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $test_request = null;
        $test_options = null;

        // Use a middleware to grab the request and options arguments to store
        // them for validation. This gets executed after GuzzleRetryMiddleware.
        $middleware = function ($handler) use (&$test_request, &$test_options) {
            return function ($request, $options
            ) use ($handler, &$test_request, &$test_options) {
                $test_request = $request;
                $test_options = $options;
                return $handler($request, $options);
            };
        };

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryMiddleware::factory());
        $stack->push($middleware);

        $client = new Client([
            'handler' => $stack,

            // set some defaults in Guzzle..
            'default_retry_multiplier' => 0,
            'on_retry_callback'        => function ($attemptNumber, $delay, &$request, &$options) {
                $request = $request->withHeader('TestHeader', 'GoodHeader');
                $options['TestOption'] = 'GoodOption';
            }
        ]);

        $client->request('GET', '/');

        $this->assertEquals('GoodHeader', $test_request->getHeaderLine('TestHeader'));
        $this->assertArrayHasKey('TestOption', $test_options);
        $this->assertEquals('GoodOption', $test_options['TestOption']);
    }
}
