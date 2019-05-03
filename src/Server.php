<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\JsonRpc;

use Hyperf\HttpMessage\Server\Request as Psr7Request;
use Hyperf\HttpMessage\Uri\Uri;
use Hyperf\Rpc\Contract\EofInterface;
use Hyperf\Rpc\ProtocolManager;
use Hyperf\Rpc\Response as Psr7Response;
use Hyperf\Server\ServerManager;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Server as SwooleServer;

class Server extends \Hyperf\RpcServer\Server
{
    protected function buildResponse(int $fd, SwooleServer $server): ResponseInterface
    {
        $response = new Psr7Response($fd, $server);
        if ($response instanceof EofInterface) {
            $eof = value(function () use ($server) {
                /** @var \Swoole\Server\Port $port */
                [$type, $port] = ServerManager::get($this->serverName);
                if (isset($port->setting['package_eof'])) {
                    return $port->setting['package_eof'];
                }
                if (isset($server->setting['package_eof'])) {
                    return $server->setting['package_eof'];
                }
                return "\r\n";
            });
            $response->setEof($eof);
        }
        return $response;
    }

    protected function buildRequest(int $fd, int $fromId, string $data): ServerRequestInterface
    {
        $protocolManager = $this->container->get(ProtocolManager::class);
        $packer = $protocolManager->getPacker('jsonrpc-20');
        $data = $this->container->get($packer)->unpack($data);
        if (isset($data['jsonrpc'])) {
            return $this->buildJsonRpcRequest($fd, $fromId, $data);
        }
        throw new InvalidArgumentException('Doesn\'t match any protocol.');
    }

    protected function buildJsonRpcRequest(int $fd, int $fromId, array $data)
    {
        if (! isset($data['method'])) {
            $data['method'] = '';
        }
        if (! isset($data['params'])) {
            $data['params'] = [];
        }
        /** @var \Swoole\Server\Port $port */
        [$type, $port] = ServerManager::get($this->serverName);

        $uri = (new Uri())->withPath($data['method'])
            ->withScheme('jsonrpc')
            ->withHost($port->host)
            ->withPort($port->port);
        return (new Psr7Request('GET', $uri))->withAttribute('fd', $fd)
            ->withAttribute('fromId', $fromId)
            ->withAttribute('data', $data)
            ->withProtocolVersion($data['jsonrpc'] ?? '2.0')
            ->withParsedBody($data['params']);
    }
}
