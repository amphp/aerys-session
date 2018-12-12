<?php

namespace Amp\Http\Server\Session;

use Amp\Http\Cookie\CookieAttributes;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Promise;
use function Amp\call;

class SessionMiddleware implements Middleware
{
    const DEFAULT_COOKIE_NAME = "session";

    /** @var Driver */
    private $driver;

    /** @var string */
    private $cookieName;

    /** @var CookieAttributes */
    private $cookieAttributes;

    /** @var string */
    private $requestAttribute;

    /**
     * @param Driver                $driver
     * @param CookieAttributes|null $cookieAttributes Attribute set for session cookies.
     * @param string                $cookieName Name of session identifier cookie.
     * @param string                $requestAttribute Name of the request attribute being used to store the session.
     */
    public function __construct(
        Driver $driver,
        CookieAttributes $cookieAttributes = null,
        string $cookieName = self::DEFAULT_COOKIE_NAME,
        string $requestAttribute = Session::class
    ) {
        $this->driver = $driver;
        $this->cookieName = $cookieName;
        $this->cookieAttributes = $cookieAttributes ?? CookieAttributes::default();
        $this->requestAttribute = $requestAttribute;
    }

    /**
     * @param Request        $request
     * @param RequestHandler $responder Request responder.
     *
     * @return Promise<\Amp\Http\Server\Response>
     */
    public function handleRequest(Request $request, RequestHandler $responder): Promise
    {
        return call(function () use ($request, $responder) {
            $cookie = $request->getCookie($this->cookieName);

            $originalId = $cookie ? $cookie->getValue() : null;
            $session = new Session($this->driver, $originalId);

            $request->setAttribute($this->requestAttribute, $session);

            try {
                $response = yield $responder->handleRequest($request);
            } finally {
                if ($session->isLocked()) {
                    $session->unlock();
                }
            }

            if (!$response instanceof Response) {
                throw new \TypeError("Request handler must resolve to an instance of " . Response::class);
            }

            $id = $session->getId();

            if ($id === null || !$session->isRead()) {
                return $response;
            }

            if ($session->isEmpty()) {
                $attributes = $this->cookieAttributes->withExpiry(
                    new \DateTimeImmutable("@0", new \DateTimeZone("UTC"))
                );

                $response->setCookie(new ResponseCookie($this->cookieName, '', $attributes));

                return $response;
            }

            if ($cookie === null || $cookie->getValue() !== $id) {
                $response->setCookie(new ResponseCookie($this->cookieName, $id, $this->cookieAttributes));
            }

            $cacheControl = $response->getHeaderArray("cache-control");

            if (empty($cacheControl)) {
                $response->setHeader("cache-control", "private");
            } else {
                foreach ($cacheControl as $key => $value) {
                    $tokens = \array_map("trim", \explode(",", $value));
                    $tokens = \array_filter($tokens, function ($token) {
                        return $token !== "public";
                    });

                    if (!\in_array("private", $tokens, true)) {
                        $tokens[] = "private";
                    }

                    $cacheControl[$key] = \implode(",", $tokens);
                }

                $response->setHeader("cache-control", $cacheControl);
            }

            return $response;
        });
    }
}
