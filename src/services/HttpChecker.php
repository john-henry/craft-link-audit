<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\events\DefineVerdictEvent;
use johnhenry\linkaudit\exceptions\UnsafeUrlException;
use johnhenry\linkaudit\helpers\BotBlockHeuristics;
use johnhenry\linkaudit\helpers\UrlSafety;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use yii\base\Component;

/**
 * Asks one URL whether it still works, and turns the answer into a verdict.
 *
 * The request policy is HEAD first, because a link check only needs the status
 * line, and pulling a megabyte of HTML to learn it is rude on someone else's
 * bandwidth as well as slow on yours. Servers that will not answer a HEAD get
 * one GET, ranged to the first couple of kilobytes and streamed, so the body is
 * never read past what a bot-block signature needs. Two requests per URL is the
 * ceiling.
 *
 * Nothing in here writes to the database and nothing in here sleeps. Retries and
 * per-host politeness belong to {@see RequestScheduler}, and the fail count that
 * decides when unreachable becomes broken belongs to
 * {@see UrlStore::recordVerdict()}. This class only ever answers the question it
 * was asked.
 *
 * The Guzzle client is injectable so tests can drive a MockHandler stack, and
 * every option that shapes a request is passed per request rather than baked
 * into the client, so an injected bare client behaves exactly like the built-in
 * one.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class HttpChecker extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @event DefineVerdictEvent The event fired once a verdict has been decided,
     * letting a listener overrule it.
     */
    public const EVENT_DEFINE_VERDICT = 'defineVerdict';

    /**
     * @var int How many bytes of a GET response body are read, to look for a
     * bot-block signature the headers did not carry.
     */
    private const _BODY_SNIPPET_BYTES = 2048;

    /**
     * @var int[] Status codes that mean the server will not answer a HEAD, and
     * says so plainly.
     */
    private const _HEAD_REFUSED_STATUSES = [405, 501];

    /**
     * @var int[] Status codes worth one GET before they are believed: a 400 or
     * 403 is often a server that dislikes the method rather than the URL, and a
     * 503 is where a CAPTCHA hides. Only retried when nothing in the headers
     * already says the refusal was aimed at robots.
     */
    private const _HEAD_SUSPECT_STATUSES = [400, 403, 503];

    /**
     * @var string The Range header sent with the GET fallback.
     */
    private const _RANGE_HEADER = 'bytes=0-2047';

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var ClientInterface|null The HTTP client, built on first use.
     */
    private ?ClientInterface $_client = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Checks one URL and returns the verdict.
     *
     * @param string $url The absolute, normalised URL to check.
     * @return Verdict What the URL had to say for itself.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function check(string $url): Verdict
    {
        $verdict = $this->checkAsync($url)->wait();
        assert($verdict instanceof Verdict);

        return $verdict;
    }

    /**
     * Checks one URL and returns a promise for the verdict.
     *
     * The promise is fulfilled whatever happens: a refused connection is a
     * verdict too, so there is nothing for a caller to catch. That is what lets
     * the scheduler pool these without wrapping every one in a try.
     *
     * @param string $url The absolute, normalised URL to check.
     * @return PromiseInterface A promise fulfilled with a {@see Verdict}.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function checkAsync(string $url): PromiseInterface
    {
        try {
            UrlSafety::assertSafeUrl($url);
        } catch (UnsafeUrlException $e) {
            return Create::promiseFor($this->_defineVerdict(
                url: $url,
                method: null,
                httpStatus: null,
                headers: [],
                finalUrl: null,
                verdict: $this->_guardRefusal($e),
            ));
        }

        return $this->_send($url, 'HEAD')
            ->then(function(array $outcome) use ($url): mixed {
                if (!$this->_worthOneGet($url, $outcome)) {
                    return $this->_verdictFor($url, $outcome);
                }

                return $this->_send($url, 'GET')
                    ->then(fn(array $getOutcome): Verdict => $this->_verdictFor($url, $getOutcome));
            });
    }

    /**
     * The HTTP client requests go out on.
     *
     * @return ClientInterface The client.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getClient(): ClientInterface
    {
        if ($this->_client === null) {
            // Craft's own Guzzle config is the base, so a project's config/guzzle.php
            // still applies. Everything this plugin decides is passed per request.
            $this->_client = Craft::createGuzzleClient();
        }

        return $this->_client;
    }

    /**
     * Sets the HTTP client, which is how tests drive the checker off a mocked
     * handler stack.
     *
     * @param ClientInterface $client The client to use.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function setClient(ClientInterface $client): void
    {
        $this->_client = $client;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The same verdict, recast as a refusal to talk to robots.
     *
     * Kept out of the broken count on purpose: a person following this link gets
     * the page, so telling an author to fix it would be wrong.
     *
     * @param Verdict $verdict The verdict the status code alone suggested.
     * @param string $signature The bot-block signature that matched.
     * @return Verdict The recast verdict.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _blockedVerdict(Verdict $verdict, string $signature): Verdict
    {
        return new Verdict(
            status: UrlStatus::Blocked,
            httpStatus: $verdict->httpStatus,
            method: $verdict->method,
            finalUrl: $verdict->finalUrl,
            redirectCount: $verdict->redirectCount,
            redirectPermanent: $verdict->redirectPermanent,
            redirectStatus: $verdict->redirectStatus,
            reason: Verdict::REASON_HTTP,
            message: "Refused an automated request ($signature).",
            responseTimeMs: $verdict->responseTimeMs,
        );
    }

    /**
     * The bot-block signature a response carries, if any.
     *
     * @param string $url The URL that was requested.
     * @param int|null $httpStatus The status that came back.
     * @param array<string, string[]> $headers The response headers.
     * @param string|null $finalUrl Where a redirect chain ended up.
     * @param string|null $bodySnippet The first couple of kilobytes of the body,
     *                                 when a GET provided one.
     * @return string|null The signature that matched.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _blockSignature(
        string $url,
        ?int $httpStatus,
        array $headers,
        ?string $finalUrl = null,
        ?string $bodySnippet = null,
    ): ?string {
        return BotBlockHeuristics::detect(
            url: $url,
            httpStatus: $httpStatus,
            headers: $headers,
            finalUrl: $finalUrl,
            bodySnippet: $bodySnippet,
            botHostileHosts: BotBlockHeuristics::hostList(
                LinkAudit::$plugin->getSettings()->botHostileHosts,
            ),
        );
    }

    /**
     * The first couple of kilobytes of a response body, lowercased, for
     * signature matching.
     *
     * Nothing is read from a response that answered: only a refusal is worth
     * reading, and the body is closed the moment the snippet is out.
     *
     * @param ResponseInterface $response The response.
     * @return string|null The snippet, or null when there was nothing worth
     *                     reading.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _bodySnippet(ResponseInterface $response): ?string
    {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return null;
        }

        try {
            $body = $response->getBody();
            $snippet = $body->read(self::_BODY_SNIPPET_BYTES);
            $body->close();
        } catch (Throwable $e) {
            Craft::warning('Could not read a response body: ' . $e->getMessage(), 'link-audit');

            return null;
        }

        return $snippet !== '' ? strtolower($snippet) : null;
    }

    /**
     * Hands the verdict to any listener that wants the last word.
     *
     * @param string $url The URL that was checked.
     * @param string|null $method The request method that produced the answer,
     *                            or null when no request was made.
     * @param int|null $httpStatus The status that came back.
     * @param array<string, string[]> $headers The response headers.
     * @param string|null $finalUrl Where a redirect chain ended up.
     * @param Verdict $verdict The verdict as the checker sees it.
     * @return Verdict Whatever the listeners left behind.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _defineVerdict(
        string $url,
        ?string $method,
        ?int $httpStatus,
        array $headers,
        ?string $finalUrl,
        Verdict $verdict,
    ): Verdict {
        if (!$this->hasEventHandlers(self::EVENT_DEFINE_VERDICT)) {
            return $verdict;
        }

        $event = new DefineVerdictEvent([
            'url' => $url,
            'method' => $method,
            'httpStatus' => $httpStatus,
            'headers' => $headers,
            'finalUrl' => $finalUrl,
            'verdict' => $verdict,
        ]);

        $this->trigger(self::EVENT_DEFINE_VERDICT, $event);

        return $event->verdict;
    }

    /**
     * The verdict for a URL the SSRF guard would not let out of the building.
     *
     * A host that does not resolve is an ordinary broken link and is reported as
     * one; anything else the guard refuses is a finding in its own right, so the
     * author can see why it was never requested.
     *
     * @param UnsafeUrlException $e The refusal.
     * @return Verdict The verdict.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _guardRefusal(UnsafeUrlException $e): Verdict
    {
        return match ($e->reason) {
            UnsafeUrlException::REASON_DNS => new Verdict(
                status: UrlStatus::Unreachable,
                reason: Verdict::REASON_DNS,
                message: $e->getMessage(),
            ),
            UnsafeUrlException::REASON_PRIVATE_IP => new Verdict(
                status: UrlStatus::Unsafe,
                reason: Verdict::REASON_PRIVATE_IP,
                message: $e->getMessage(),
            ),
            default => new Verdict(
                status: UrlStatus::Unsafe,
                reason: Verdict::REASON_UNSAFE_SCHEME,
                message: $e->getMessage(),
            ),
        };
    }

    /**
     * The verdict for a request that never produced a response.
     *
     * The distinction between DNS, TLS, timeout and refused connection is read
     * off the cURL message, because that is the only place Guzzle puts it, and
     * it is worth having: a certificate that expired reads very differently to
     * an author than a domain that lapsed.
     *
     * @param Throwable $error What went wrong.
     * @param string $method The request method that failed.
     * @param int $elapsedMs How long it took to fail.
     * @return Verdict The verdict.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _mapError(Throwable $error, string $method, int $elapsedMs): Verdict
    {
        if ($error instanceof UnsafeUrlException) {
            $status = $error->reason === UnsafeUrlException::REASON_DNS
                ? UrlStatus::Unreachable
                : UrlStatus::Unsafe;

            return new Verdict(
                status: $status,
                method: $method,
                reason: $error->reason === UnsafeUrlException::REASON_DNS
                    ? Verdict::REASON_DNS
                    : Verdict::REASON_PRIVATE_IP,
                message: 'A redirect led somewhere it should not: ' . $error->getMessage(),
                responseTimeMs: $elapsedMs,
            );
        }

        // A status code HTTP does not define never reaches the mapper as a
        // response, because the client will not build one. It is still a
        // finding: LinkedIn's 999 arrives exactly this way.
        if (BotBlockHeuristics::isNonstandardStatusError($error)) {
            return new Verdict(
                status: UrlStatus::Blocked,
                method: $method,
                reason: Verdict::REASON_HTTP,
                message: 'The server answered with a status code outside the standard range, which is how '
                    . 'some hosts turn robots away (' . BotBlockHeuristics::SIGNATURE_NONSTANDARD_STATUS . ').',
                responseTimeMs: $elapsedMs,
            );
        }

        if ($error instanceof TooManyRedirectsException) {
            return new Verdict(
                status: UrlStatus::Broken,
                method: $method,
                reason: Verdict::REASON_HTTP,
                message: 'This link redirects round in circles and never lands anywhere.',
                responseTimeMs: $elapsedMs,
            );
        }

        $message = trim($error->getMessage());

        return new Verdict(
            status: UrlStatus::Unreachable,
            method: $method,
            reason: $this->_reasonForError($message),
            message: $message,
            responseTimeMs: $elapsedMs,
        );
    }

    /**
     * The verdict for a response.
     *
     * @param ResponseInterface $response The response.
     * @param string $method The request method that produced it.
     * @param int $elapsedMs How long it took.
     * @return Verdict The verdict.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _mapResponse(ResponseInterface $response, string $method, int $elapsedMs): Verdict
    {
        $status = $response->getStatusCode();
        [$redirectCount, $finalUrl, $redirectPermanent, $redirectStatus] = $this->_redirectInfo($response);
        $message = $this->_reasonPhrase($response);

        if ($status >= 200 && $status < 300) {
            return new Verdict(
                status: $redirectCount > 0 ? UrlStatus::Redirect : UrlStatus::Ok,
                httpStatus: $status,
                method: $method,
                finalUrl: $redirectCount > 0 ? $finalUrl : null,
                redirectCount: $redirectCount,
                redirectPermanent: $redirectCount > 0 ? $redirectPermanent : null,
                redirectStatus: $redirectCount > 0 ? $redirectStatus : null,
                message: $message,
                responseTimeMs: $elapsedMs,
            );
        }

        // A 429 says nothing about the link, only about how hard it is being
        // asked. Hand it back as a deferral so the scheduler can back the whole
        // host off and come again.
        if ($status === 429) {
            return new Verdict(
                status: UrlStatus::Pending,
                httpStatus: $status,
                method: $method,
                reason: Verdict::REASON_RATE_LIMITED,
                message: $message,
                responseTimeMs: $elapsedMs,
                retryAfterSeconds: $this->_retryAfterSeconds($response),
            );
        }

        // Everything the server itself could not manage is held as unreachable.
        // Only a run of these promotes a URL to broken, which is the URL store's
        // business, not the checker's.
        if ($status >= 500) {
            return new Verdict(
                status: UrlStatus::Unreachable,
                httpStatus: $status,
                method: $method,
                finalUrl: $redirectCount > 0 ? $finalUrl : null,
                redirectCount: $redirectCount,
                reason: Verdict::REASON_HTTP,
                message: $message,
                responseTimeMs: $elapsedMs,
            );
        }

        // Everything else the server answered with, 404 and 410 first among
        // them, is the link being wrong.
        return new Verdict(
            status: UrlStatus::Broken,
            httpStatus: $status,
            method: $method,
            finalUrl: $redirectCount > 0 ? $finalUrl : null,
            redirectCount: $redirectCount,
            reason: Verdict::REASON_HTTP,
            message: $message,
            responseTimeMs: $elapsedMs,
        );
    }

    /**
     * Which kind of failure a transport error was.
     *
     * @param string $message The error's message.
     * @return string One of the {@see Verdict} REASON_* constants.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _reasonForError(string $message): string
    {
        $haystack = strtolower($message);

        if (
            str_contains($haystack, 'ssl')
            || str_contains($haystack, 'certificate')
            || str_contains($haystack, 'tls')
        ) {
            return Verdict::REASON_SSL;
        }

        if (str_contains($haystack, 'resolve host') || str_contains($haystack, 'resolve name')) {
            return Verdict::REASON_DNS;
        }

        if (str_contains($haystack, 'timed out') || str_contains($haystack, 'timeout')) {
            return Verdict::REASON_TIMEOUT;
        }

        return Verdict::REASON_CONNECT;
    }

    /**
     * The reason phrase a server sent, or the status code written out when it
     * sent none.
     *
     * @param ResponseInterface $response The response.
     * @return string The phrase.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _reasonPhrase(ResponseInterface $response): string
    {
        $phrase = trim($response->getReasonPhrase());

        return $phrase !== '' ? $phrase : 'HTTP ' . $response->getStatusCode();
    }

    /**
     * What a tracked redirect chain has to say: how many hops, where it ended,
     * whether any of it was permanent, and what the first hop answered with.
     *
     * A permanent hop is the actionable one. It means the address in the content
     * is out of date and the far end has said so, which is worth an author's
     * time in a way a temporary redirect is not.
     *
     * The first hop's status is the one worth keeping. It is the answer the
     * address in the content got, and the only status on the row that says a
     * redirect happened at all: the code the response itself carries belongs to
     * wherever the chain ended, which is a 200 on any redirect worth the name.
     *
     * @param ResponseInterface $response The final response.
     * @return array{0: int, 1: string|null, 2: bool, 3: int|null} The hop count,
     *         the final URL, whether any hop was permanent, and the first hop's
     *         status code.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _redirectInfo(ResponseInterface $response): array
    {
        $chain = $response->getHeader('X-Guzzle-Redirect-History');
        $statuses = $response->getHeader('X-Guzzle-Redirect-Status-History');

        if ($chain === []) {
            return [0, null, false, null];
        }

        $permanent = false;

        foreach ($statuses as $status) {
            if ((int)$status === 301 || (int)$status === 308) {
                $permanent = true;
                break;
            }
        }

        // Guzzle only fills the status history when it is asked to track the
        // chain, so a hop count without one is possible and is not worth losing
        // the rest of the answer over.
        $first = $statuses !== [] ? (int)$statuses[0] : null;

        return [count($chain), (string)end($chain), $permanent, $first];
    }

    /**
     * How long a host asked to be left alone for, read off a Retry-After header
     * in either of the two forms the specification allows.
     *
     * @param ResponseInterface $response The response.
     * @return int|null The delay in seconds, or null when the header is missing
     *                  or unreadable.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _retryAfterSeconds(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int)$value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    /**
     * The Guzzle options for one request.
     *
     * Everything lives here rather than on the client so that a client handed in
     * by a test is shaped by exactly the same policy as the built-in one.
     *
     * @param string $method The request method.
     * @return array<string, mixed> The options.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _requestOptions(string $method): array
    {
        $settings = LinkAudit::$plugin->getSettings();

        $options = [
            // Redirects are followed and recorded, and every hop is put back
            // through the SSRF guard.
            RequestOptions::ALLOW_REDIRECTS => UrlSafety::redirectOptions($settings->maxRedirects, true),
            RequestOptions::CONNECT_TIMEOUT => $settings->connectTimeout,
            // A status code is the answer here, never an exception.
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::HEADERS => [
                'Accept' => '*/*',
                'User-Agent' => $settings->getCheckerUserAgent(),
            ],
            RequestOptions::TIMEOUT => $settings->timeout,
            RequestOptions::VERIFY => $settings->verifySsl,
        ];

        $proxy = $settings->getResolvedProxy();

        if ($proxy !== null) {
            $options[RequestOptions::PROXY] = $proxy;
        }

        if ($method === 'GET') {
            // Ask for the first couple of kilobytes and stream them, so a server
            // that ignores the Range header still costs nothing: the body is
            // closed the moment the headers have been read.
            $options[RequestOptions::HEADERS]['Range'] = self::_RANGE_HEADER;
            $options[RequestOptions::STREAM] = true;
        }

        return $options;
    }

    /**
     * Sends one request and reports what happened, without ever rejecting.
     *
     * @param string $url The URL to request.
     * @param string $method The request method.
     * @return PromiseInterface A promise fulfilled with an outcome array of
     *         `method`, `response`, `error` and `elapsedMs`.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _send(string $url, string $method): PromiseInterface
    {
        $startedAt = microtime(true);
        $elapsed = static fn(): int => (int)round((microtime(true) - $startedAt) * 1000);

        return $this->getClient()
            ->requestAsync($method, $url, $this->_requestOptions($method))
            ->then(
                static fn(ResponseInterface $response): array => [
                    'method' => strtolower($method),
                    'response' => $response,
                    'error' => null,
                    'elapsedMs' => $elapsed(),
                ],
                static fn(Throwable $error): array => [
                    'method' => strtolower($method),
                    'response' => null,
                    'error' => $error,
                    'elapsedMs' => $elapsed(),
                ],
            );
    }

    /**
     * Turns an outcome into the verdict the caller gets.
     *
     * @param string $url The URL that was checked.
     * @param array{method: string, response: ResponseInterface|null, error: Throwable|null, elapsedMs: int} $outcome
     *        What came back.
     * @return Verdict The verdict.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _verdictFor(string $url, array $outcome): Verdict
    {
        $response = $outcome['response'];
        $error = $outcome['error'];
        $method = $outcome['method'];

        if ($response === null) {
            if ($error === null) {
                // Neither a response nor an error should not be possible, but a
                // check that answers nothing at all would stall the URL forever.
                Craft::warning("Checking $url produced neither a response nor an error.", 'link-audit');
            }

            $verdict = $error !== null
                ? $this->_mapError($error, $method, $outcome['elapsedMs'])
                : new Verdict(
                    status: UrlStatus::Unreachable,
                    method: $method,
                    reason: Verdict::REASON_CONNECT,
                    message: 'The request produced no answer.',
                    responseTimeMs: $outcome['elapsedMs'],
                );

            return $this->_defineVerdict($url, $method, null, [], null, $verdict);
        }

        $headers = $response->getHeaders();
        $httpStatus = $response->getStatusCode();
        $verdict = $this->_mapResponse($response, $method, $outcome['elapsedMs']);

        $signature = $this->_blockSignature(
            url: $url,
            httpStatus: $httpStatus,
            headers: $headers,
            finalUrl: $verdict->finalUrl,
            bodySnippet: $method === 'get' ? $this->_bodySnippet($response) : null,
        );

        // A 429 is left as a deferral even when the host is a known bot-blocker:
        // it asked to be left alone, and backing off is a better answer than
        // filing it away as unverifiable.
        if ($signature !== null && !$verdict->isDeferred()) {
            $verdict = $this->_blockedVerdict($verdict, $signature);
        }

        return $this->_defineVerdict($url, $method, $httpStatus, $headers, $verdict->finalUrl, $verdict);
    }

    /**
     * Whether a HEAD answer is inconclusive enough to be worth spending one GET
     * on.
     *
     * A 405 or 501 is the server saying it does not do HEAD. A 400 or 403 is
     * often the same thing said badly. A 503 is where a CAPTCHA hides, and only
     * a body can tell that from a server that is genuinely having a bad day.
     *
     * @param string $url The URL that was requested.
     * @param array{method: string, response: ResponseInterface|null, error: Throwable|null, elapsedMs: int} $outcome
     *        What came back from the HEAD.
     * @return bool Whether to try a GET.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _worthOneGet(string $url, array $outcome): bool
    {
        $response = $outcome['response'];

        if ($outcome['method'] !== 'head' || $response === null) {
            return false;
        }

        $status = $response->getStatusCode();

        if (in_array($status, self::_HEAD_REFUSED_STATUSES, true)) {
            return true;
        }

        if (!in_array($status, self::_HEAD_SUSPECT_STATUSES, true)) {
            return false;
        }

        // A refusal already wearing a bot-block signature is not a server
        // refusing the method, it is a server refusing robots, and a GET would
        // be a second request for the same answer.
        return $this->_blockSignature($url, $status, $response->getHeaders()) === null;
    }
}
