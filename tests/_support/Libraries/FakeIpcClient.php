<?php

namespace Tests\Support\Libraries;

use App\Libraries\IpcClient;

/**
 * Test double for IpcClient.
 *
 * Answers from a canned `method => response` map and records the calls it
 * received, so controller failure handling can be exercised without a running
 * daemon (and without relying on Windows mock mode). Inject it with:
 *
 *     Services::injectMock('ipc', new FakeIpcClient([...]));
 */
class FakeIpcClient extends IpcClient
{
    /** @var array<string, array{result: mixed, error: mixed|null}> */
    private array $responses;

    /** @var list<array{method: string, params: array}> */
    public array $calls = [];

    /**
     * @param array<string, array{result: mixed, error: mixed|null}> $responses
     */
    public function __construct(array $responses = [])
    {
        // Intentionally skip the parent constructor: no socket probe or log writes.
        $this->responses = $responses;
    }

    /**
     * Queues a response for a method.
     *
     * @param array{result: mixed, error: mixed|null} $response
     */
    public function respond(string $method, array $response): self
    {
        $this->responses[$method] = $response;

        return $this;
    }

    public function call(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];

        return $this->responses[$method] ?? [
            'result' => null,
            'error'  => ['code' => -32601, 'message' => 'Method not found: ' . $method],
        ];
    }
}
