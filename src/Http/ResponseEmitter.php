<?php

declare(strict_types=1);

namespace Omnitrix\Http;

use Omnitrix\Core\Http\Server\ResponseWriter;

/**
 * Writes a {@see Response} to the transport.
 *
 * @internal
 */
final class ResponseEmitter
{
    public function emit(Response $response, ResponseWriter $writer): void
    {
        if ($writer->isEnded()) {
            return;
        }
        $writer->status($response->getStatus());
        foreach ($response->getHeaders() as $name => $value) {
            $writer->header($name, $value);
        }
        foreach ($response->getCookies() as $cookie) {
            $writer->cookie($cookie->name, $cookie->value, $cookie->expires, $cookie->path, $cookie->domain, $cookie->secure, $cookie->httpOnly, $cookie->sameSite);
        }

        switch ($response->bodyType()) {
            case Response::BODY_FILE:
                $writer->sendFile((string) $response->getFile());

                return;

            case Response::BODY_STREAM:
                $stream = $response->getStream();
                if ($stream !== null) {
                    try {
                        $stream(new Stream($writer));
                    } finally {
                        $writer->end();
                    }
                }

                return;

            case Response::BODY_ITERABLE:
                $chunks = $response->getChunks();
                if ($chunks !== null) {
                    foreach ($chunks as $chunk) {
                        if (!$writer->write($chunk)) {
                            break; // client gone
                        }
                    }
                }
                $writer->end();

                return;

            default:
                $writer->end($response->getBody());
        }
    }
}
