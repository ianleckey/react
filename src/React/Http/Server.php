<?php

namespace React\Http;

use Evenement\EventEmitter;
use React\Socket\ServerInterface as SocketServerInterface;
use React\Socket\ConnectionInterface;

/**
 * Events:
 *  * request
 *  * upgrade
 */
class Server extends EventEmitter implements ServerInterface
{
    private $io;

    public function __construct(SocketServerInterface $io)
    {
        $this->io = $io;

        $server = $this;

        $this->io->on('connect', function ($conn) use ($server) {
            // TODO: http 1.1 keep-alive
            // TODO: chunked transfer encoding (also for outgoing data)
            // TODO: multipart parsing

            $server->handleConnection($conn);
        });
    }

    public function handleConnection(ConnectionInterface $conn)
    {
        $server = $this;

        $parser = new RequestHeaderParser();
        $parser->on('headers', function (Request $request, $bodyBuffer) use ($server, $conn, $parser) {
            $server->handleRequest($conn, $parser, $request, $bodyBuffer);

            $conn->removeListener('data', array($parser, 'feed'));
            $conn->on('data', function ($data) use ($request) {
                $request->emit('data', array($data));
            });
        });

        $conn->on('data', array($parser, 'feed'));
    }

    public function handleRequest(ConnectionInterface $conn, RequestHeaderParser $parser, Request $request, $bodyBuffer)
    {
        $response = new Response($conn);
        $response->on('end', array($request, 'end'));

        if (!$this->listeners('request')) {
            $response->end();
            return;
        }

        $headers = $request->getHeaders();
        if (isset($headers['Connection']) && 'close' === $headers['Connection']) {
            $parser->removeAllListeners();
        } else {
            $response->on('end', function () use ($conn, $parser) {
                $parser->rewind();
                $conn->on('data', array($parser, 'feed'));
            });
        }

        $this->emit('request', array($request, $response));
        $request->emit('data', array($bodyBuffer));
    }
}
