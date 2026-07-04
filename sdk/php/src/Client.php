<?php

declare(strict_types=1);

namespace Liberu\AccountingSdk;

use GuzzleHttp\Client as GuzzleClient;
use Liberu\AccountingSdk\Exception\ApiException;
use Liberu\AccountingSdk\Exception\ForbiddenException;
use Liberu\AccountingSdk\Exception\RateLimitException;
use Liberu\AccountingSdk\Exception\UnauthorizedException;
use Liberu\AccountingSdk\Exception\ValidationException;
use Liberu\AccountingSdk\Resources\CrudResource;
use Liberu\AccountingSdk\Resources\GeneralLedger;

class Client
{
    private GuzzleClient $http;

    public function __construct(string $baseUrl, string $token, ?callable $handler = null)
    {
        $config = [
            'base_uri' => rtrim($baseUrl, '/').'/api/v1/',
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ];
        if ($handler !== null) {
            $config['handler'] = $handler;
        }
        $this->http = new GuzzleClient($config);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    public function request(string $method, string $path, array $options = []): array
    {
        $response = $this->http->request($method, ltrim($path, '/'), $options);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = $body === '' ? [] : json_decode($body, true);
        if (! is_array($decoded)) {
            $decoded = [];
        }

        if ($status >= 400) {
            throw $this->makeException($status, $decoded, $response->getHeaderLine('Retry-After'));
        }

        return $decoded;
    }

    /** @param array<mixed> $body */
    private function makeException(int $status, array $body, string $retryAfter): ApiException
    {
        $message = is_string($body['message'] ?? null) ? $body['message'] : 'API request failed';

        return match ($status) {
            401 => new UnauthorizedException($message, $status),
            403 => new ForbiddenException($message, $status),
            422 => new ValidationException($message, $status, is_array($body['errors'] ?? null) ? $body['errors'] : []),
            429 => new RateLimitException($message, $status, $retryAfter === '' ? null : (int) $retryAfter),
            default => new ApiException($message, $status),
        };
    }

    public function invoices(): CrudResource
    {
        return new CrudResource($this, 'invoices');
    }

    public function bills(): CrudResource
    {
        return new CrudResource($this, 'bills');
    }

    public function estimates(): CrudResource
    {
        return new CrudResource($this, 'estimates');
    }

    public function chartOfAccounts(): CrudResource
    {
        return new CrudResource($this, 'chart-of-accounts');
    }

    public function journalEntries(): CrudResource
    {
        return new CrudResource($this, 'journal-entries');
    }

    public function generalLedger(): GeneralLedger
    {
        return new GeneralLedger($this);
    }
}
