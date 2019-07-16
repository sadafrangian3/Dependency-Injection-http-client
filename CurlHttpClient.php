<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Internal\CurlClientState;
use Symfony\Component\HttpClient\Internal\PushedResponse;
use Symfony\Component\HttpClient\Response\CurlResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A performant implementation of the HttpClientInterface contracts based on the curl extension.
 *
 * This provides fully concurrent HTTP requests, with transparent
 * HTTP/2 push when a curl version that supports it is installed.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class CurlHttpClient implements HttpClientInterface, LoggerAwareInterface
{
    use HttpClientTrait;
    use LoggerAwareTrait;

    private $defaultOptions = [
        'buffer' => null, // bool|\Closure - a boolean or a closure telling if the response should be buffered based on its headers
    ] + self::OPTIONS_DEFAULTS + [
        'auth_ntlm' => null, // array|string - an array containing the username as first value, and optionally the
                             //   password as the second one; or string like username:password - enabling NTLM auth
    ];

    /**
     * An internal object to share state between the client and its responses.
     *
     * @var CurlClientState
     */
    private $multi;

    /**
     * @param array $defaultOptions     Default requests' options
     * @param int   $maxHostConnections The maximum number of connections to a single host
     * @param int   $maxPendingPushes   The maximum number of pushed responses to accept in the queue
     *
     * @see HttpClientInterface::OPTIONS_DEFAULTS for available options
     */
    public function __construct(array $defaultOptions = [], int $maxHostConnections = 6, int $maxPendingPushes = 50)
    {
        if (!\extension_loaded('curl')) {
            throw new \LogicException('You cannot use the "Symfony\Component\HttpClient\CurlHttpClient" as the "curl" extension is not installed.');
        }

        $this->defaultOptions['buffer'] = \Closure::fromCallable([__CLASS__, 'shouldBuffer']);

        if ($defaultOptions) {
            [, $this->defaultOptions] = self::prepareRequest(null, null, $defaultOptions, $this->defaultOptions);
        }

        $this->multi = $multi = new CurlClientState();

        // Don't enable HTTP/1.1 pipelining: it forces responses to be sent in order
        if (\defined('CURLPIPE_MULTIPLEX')) {
            curl_multi_setopt($this->multi->handle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        }
        if (\defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
            curl_multi_setopt($this->multi->handle, CURLMOPT_MAX_HOST_CONNECTIONS, 0 < $maxHostConnections ? $maxHostConnections : PHP_INT_MAX);
        }

        // Skip configuring HTTP/2 push when it's unsupported or buggy, see https://bugs.php.net/77535
        if (0 >= $maxPendingPushes || \PHP_VERSION_ID < 70217 || (\PHP_VERSION_ID >= 70300 && \PHP_VERSION_ID < 70304)) {
            return;
        }

        // HTTP/2 push crashes before curl 7.61
        if (!\defined('CURLMOPT_PUSHFUNCTION') || 0x073d00 > ($v = curl_version())['version_number'] || !(CURL_VERSION_HTTP2 & $v['features'])) {
            return;
        }

        $logger = &$this->logger;

        curl_multi_setopt($this->multi->handle, CURLMOPT_PUSHFUNCTION, static function ($parent, $pushed, array $requestHeaders) use ($multi, $maxPendingPushes, &$logger) {
            return self::handlePush($parent, $pushed, $requestHeaders, $multi, $maxPendingPushes, $logger);
        });
    }

    /**
     * @see HttpClientInterface::OPTIONS_DEFAULTS for available options
     *
     * {@inheritdoc}
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        [$url, $options] = self::prepareRequest($method, $url, $options, $this->defaultOptions);
        $scheme = $url['scheme'];
        $authority = $url['authority'];
        $host = parse_url($authority, PHP_URL_HOST);
        $url = implode('', $url);

        if ($pushedResponse = $this->multi->pushedResponses[$url] ?? null) {
            unset($this->multi->pushedResponses[$url]);
            // Accept pushed responses only if their headers related to authentication match the request
            $expectedHeaders = ['authorization', 'cookie', 'x-requested-with', 'range'];
            foreach ($expectedHeaders as $k => $v) {
                $expectedHeaders[$k] = null;

                foreach ($options['normalized_headers'][$v] ?? [] as $h) {
                    $expectedHeaders[$k][] = substr($h, 2 + \strlen($v));
                }
            }

            if ('GET' === $method && $expectedHeaders === $pushedResponse->headers && !$options['body']) {
                $this->logger && $this->logger->debug(sprintf('Connecting request to pushed response: "%s %s"', $method, $url));

                // Reinitialize the pushed response with request's options
                $pushedResponse->response->__construct($this->multi, $url, $options, $this->logger);

                return $pushedResponse->response;
            }

            $this->logger && $this->logger->debug(sprintf('Rejecting pushed response for "%s": authorization headers don\'t match the request', $url));
        }

        $this->logger && $this->logger->info(sprintf('Request: "%s %s"', $method, $url));

        $curlopts = [
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'Symfony HttpClient/Curl',
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 0 < $options['max_redirects'] ? $options['max_redirects'] : 0,
            CURLOPT_COOKIEFILE => '', // Keep track of cookies during redirects
            CURLOPT_TIMEOUT => 0,
            CURLOPT_PROXY => $options['proxy'],
            CURLOPT_NOPROXY => $options['no_proxy'] ?? $_SERVER['no_proxy'] ?? $_SERVER['NO_PROXY'] ?? '',
            CURLOPT_SSL_VERIFYPEER => $options['verify_peer'],
            CURLOPT_SSL_VERIFYHOST => $options['verify_host'] ? 2 : 0,
            CURLOPT_CAINFO => $options['cafile'],
            CURLOPT_CAPATH => $options['capath'],
            CURLOPT_SSL_CIPHER_LIST => $options['ciphers'],
            CURLOPT_SSLCERT => $options['local_cert'],
            CURLOPT_SSLKEY => $options['local_pk'],
            CURLOPT_KEYPASSWD => $options['passphrase'],
            CURLOPT_CERTINFO => $options['capture_peer_cert_chain'],
        ];

        if (isset($options['auth_ntlm'])) {
            $curlopts[CURLOPT_HTTPAUTH] = CURLAUTH_NTLM;

            if (\is_array($options['auth_ntlm'])) {
                $count = \count($options['auth_ntlm']);
                if ($count <= 0 || $count > 2) {
                    throw new InvalidArgumentException(sprintf('Option "auth_ntlm" must contain 1 or 2 elements, %s given.', $count));
                }

                $options['auth_ntlm'] = implode(':', $options['auth_ntlm']);
            }

            if (!\is_string($options['auth_ntlm'])) {
                throw new InvalidArgumentException(sprintf('Option "auth_ntlm" must be string or an array, %s given.', \gettype($options['auth_ntlm'])));
            }

            $curlopts[CURLOPT_USERPWD] = $options['auth_ntlm'];
        }

        if (!ZEND_THREAD_SAFE) {
            $curlopts[CURLOPT_DNS_USE_GLOBAL_CACHE] = false;
        }

        if (\defined('CURLOPT_HEADEROPT')) {
            $curlopts[CURLOPT_HEADEROPT] = CURLHEADER_SEPARATE;
        }

        // curl's resolve feature varies by host:port but ours varies by host only, let's handle this with our own DNS map
        if (isset($this->multi->dnsCache->hostnames[$host])) {
            $options['resolve'] += [$host => $this->multi->dnsCache->hostnames[$host]];
        }

        if ($options['resolve'] || $this->multi->dnsCache->evictions) {
            // First reset any old DNS cache entries then add the new ones
            $resolve = $this->multi->dnsCache->evictions;
            $this->multi->dnsCache->evictions = [];
            $port = parse_url($authority, PHP_URL_PORT) ?: ('http:' === $scheme ? 80 : 443);

            if ($resolve && 0x072a00 > curl_version()['version_number']) {
                // DNS cache removals require curl 7.42 or higher
                // On lower versions, we have to create a new multi handle
                curl_multi_close($this->multi->handle);
                $this->multi->handle = (new self())->multi->handle;
            }

            foreach ($options['resolve'] as $host => $ip) {
                $resolve[] = null === $ip ? "-$host:$port" : "$host:$port:$ip";
                $this->multi->dnsCache->hostnames[$host] = $ip;
                $this->multi->dnsCache->removals["-$host:$port"] = "-$host:$port";
            }

            $curlopts[CURLOPT_RESOLVE] = $resolve;
        }

        if (1.0 === (float) $options['http_version']) {
            $curlopts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_0;
        } elseif (1.1 === (float) $options['http_version'] || 'https:' !== $scheme) {
            $curlopts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        } elseif (\defined('CURL_VERSION_HTTP2') && CURL_VERSION_HTTP2 & curl_version()['features']) {
            $curlopts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
        }

        if ('POST' === $method) {
            // Use CURLOPT_POST to have browser-like POST-to-GET redirects for 301, 302 and 303
            $curlopts[CURLOPT_POST] = true;
        } else {
            $curlopts[CURLOPT_CUSTOMREQUEST] = $method;
        }

        if ('\\' !== \DIRECTORY_SEPARATOR && $options['timeout'] < 1) {
            $curlopts[CURLOPT_NOSIGNAL] = true;
        }

        if (!isset($options['normalized_headers']['accept-encoding'])) {
            $curlopts[CURLOPT_ENCODING] = ''; // Enable HTTP compression
        }

        foreach ($options['headers'] as $header) {
            if (':' === $header[-2] && \strlen($header) - 2 === strpos($header, ': ')) {
                // curl requires a special syntax to send empty headers
                $curlopts[CURLOPT_HTTPHEADER][] = substr_replace($header, ';', -2);
            } else {
                $curlopts[CURLOPT_HTTPHEADER][] = $header;
            }
        }

        // Prevent curl from sending its default Accept and Expect headers
        foreach (['accept', 'expect'] as $header) {
            if (!isset($options['normalized_headers'][$header])) {
                $curlopts[CURLOPT_HTTPHEADER][] = $header.':';
            }
        }

        if (!\is_string($body = $options['body'])) {
            if (\is_resource($body)) {
                $curlopts[CURLOPT_INFILE] = $body;
            } else {
                $eof = false;
                $buffer = '';
                $curlopts[CURLOPT_READFUNCTION] = static function ($ch, $fd, $length) use ($body, &$buffer, &$eof) {
                    return self::readRequestBody($length, $body, $buffer, $eof);
                };
            }

            if (isset($options['normalized_headers']['content-length'][0])) {
                $curlopts[CURLOPT_INFILESIZE] = substr($options['normalized_headers']['content-length'][0], \strlen('Content-Length: '));
            } elseif (!isset($options['normalized_headers']['transfer-encoding'])) {
                $curlopts[CURLOPT_HTTPHEADER][] = 'Transfer-Encoding: chunked'; // Enable chunked request bodies
            }

            if ('POST' !== $method) {
                $curlopts[CURLOPT_UPLOAD] = true;
            }
        } elseif ('' !== $body) {
            $curlopts[CURLOPT_POSTFIELDS] = $body;
        }

        if ($options['peer_fingerprint']) {
            if (!isset($options['peer_fingerprint']['pin-sha256'])) {
                throw new TransportException(__CLASS__.' supports only "pin-sha256" fingerprints.');
            }

            $curlopts[CURLOPT_PINNEDPUBLICKEY] = 'sha256//'.implode(';sha256//', $options['peer_fingerprint']['pin-sha256']);
        }

        if ($options['bindto']) {
            $curlopts[file_exists($options['bindto']) ? CURLOPT_UNIX_SOCKET_PATH : CURLOPT_INTERFACE] = $options['bindto'];
        }

        if (0 < $options['max_duration']) {
            $curlopts[CURLOPT_TIMEOUT_MS] = 1000 * $options['max_duration'];
        }

        $ch = curl_init();

        foreach ($curlopts as $opt => $value) {
            if (null !== $value && !curl_setopt($ch, $opt, $value) && CURLOPT_CERTINFO !== $opt) {
                $constants = array_filter(get_defined_constants(), static function ($v, $k) use ($opt) {
                    return $v === $opt && 'C' === $k[0] && (0 === strpos($k, 'CURLOPT_') || 0 === strpos($k, 'CURLINFO_'));
                }, ARRAY_FILTER_USE_BOTH);

                throw new TransportException(sprintf('Curl option "%s" is not supported.', key($constants) ?? $opt));
            }
        }

        return new CurlResponse($this->multi, $ch, $options, $this->logger, $method, self::createRedirectResolver($options, $host));
    }

    /**
     * {@inheritdoc}
     */
    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof CurlResponse) {
            $responses = [$responses];
        } elseif (!is_iterable($responses)) {
            throw new \TypeError(sprintf('%s() expects parameter 1 to be an iterable of CurlResponse objects, %s given.', __METHOD__, \is_object($responses) ? \get_class($responses) : \gettype($responses)));
        }

        $active = 0;
        while (CURLM_CALL_MULTI_PERFORM === curl_multi_exec($this->multi->handle, $active));

        return new ResponseStream(CurlResponse::stream($responses, $timeout));
    }

    public function __destruct()
    {
        $this->multi->pushedResponses = [];
        if (\defined('CURLMOPT_PUSHFUNCTION')) {
            curl_multi_setopt($this->multi->handle, CURLMOPT_PUSHFUNCTION, null);
        }

        $active = 0;
        while (CURLM_CALL_MULTI_PERFORM === curl_multi_exec($this->multi->handle, $active));

        foreach ($this->multi->openHandles as $ch) {
            curl_setopt($ch, CURLOPT_VERBOSE, false);
        }
    }

    private static function handlePush($parent, $pushed, array $requestHeaders, CurlClientState $multi, int $maxPendingPushes, ?LoggerInterface $logger): int
    {
        $headers = [];
        $origin = curl_getinfo($parent, CURLINFO_EFFECTIVE_URL);

        foreach ($requestHeaders as $h) {
            if (false !== $i = strpos($h, ':', 1)) {
                $headers[substr($h, 0, $i)] = substr($h, 1 + $i);
            }
        }

        if (!isset($headers[':method']) || !isset($headers[':scheme']) || !isset($headers[':authority']) || !isset($headers[':path']) || 'GET' !== $headers[':method'] || isset($headers['range'])) {
            $logger && $logger->debug(sprintf('Rejecting pushed response from "%s": pushed headers are invalid', $origin));

            return CURL_PUSH_DENY;
        }

        $url = $headers[':scheme'].'://'.$headers[':authority'];

        if ($maxPendingPushes <= \count($multi->pushedResponses)) {
            $logger && $logger->debug(sprintf('Rejecting pushed response from "%s" for "%s": the queue is full', $origin, $url));

            return CURL_PUSH_DENY;
        }

        // curl before 7.65 doesn't validate the pushed ":authority" header,
        // but this is a MUST in the HTTP/2 RFC; let's restrict pushes to the original host,
        // ignoring domains mentioned as alt-name in the certificate for now (same as curl).
        if (0 !== strpos($origin, $url.'/')) {
            $logger && $logger->debug(sprintf('Rejecting pushed response from "%s": server is not authoritative for "%s"', $origin, $url));

            return CURL_PUSH_DENY;
        }

        $url .= $headers[':path'];
        $logger && $logger->debug(sprintf('Queueing pushed response: "%s"', $url));

        $multi->pushedResponses[$url] = new PushedResponse(
            new CurlResponse($multi, $pushed),
            [
                $headers['authorization'] ?? null,
                $headers['cookie'] ?? null,
                $headers['x-requested-with'] ?? null,
                null,
            ]
        );

        return CURL_PUSH_OK;
    }

    /**
     * Wraps the request's body callback to allow it to return strings longer than curl requested.
     */
    private static function readRequestBody(int $length, \Closure $body, string &$buffer, bool &$eof): string
    {
        if (!$eof && \strlen($buffer) < $length) {
            if (!\is_string($data = $body($length))) {
                throw new TransportException(sprintf('The return value of the "body" option callback must be a string, %s returned.', \gettype($data)));
            }

            $buffer .= $data;
            $eof = '' === $data;
        }

        $data = substr($buffer, 0, $length);
        $buffer = substr($buffer, $length);

        return $data;
    }

    /**
     * Resolves relative URLs on redirects and deals with authentication headers.
     *
     * Work around CVE-2018-1000007: Authorization and Cookie headers should not follow redirects - fixed in Curl 7.64
     */
    private static function createRedirectResolver(array $options, string $host): \Closure
    {
        $redirectHeaders = [];
        if (0 < $options['max_redirects']) {
            $redirectHeaders['host'] = $host;
            $redirectHeaders['with_auth'] = $redirectHeaders['no_auth'] = array_filter($options['headers'], static function ($h) {
                return 0 !== stripos($h, 'Host:');
            });

            if (isset($options['normalized_headers']['authorization']) || isset($options['normalized_headers']['cookie'])) {
                $redirectHeaders['no_auth'] = array_filter($options['headers'], static function ($h) {
                    return 0 !== stripos($h, 'Authorization:') && 0 !== stripos($h, 'Cookie:');
                });
            }
        }

        return static function ($ch, string $location) use ($redirectHeaders) {
            try {
                $location = self::parseUrl($location);
            } catch (InvalidArgumentException $e) {
                return null;
            }

            if ($redirectHeaders && $host = parse_url('http:'.$location['authority'], PHP_URL_HOST)) {
                $requestHeaders = $redirectHeaders['host'] === $host ? $redirectHeaders['with_auth'] : $redirectHeaders['no_auth'];
                curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
            }

            $url = self::parseUrl(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));

            return implode('', self::resolveUrl($location, $url));
        };
    }
}
