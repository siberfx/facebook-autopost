<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class FacebookRequestFailed extends RuntimeException
{
    /** Graph API error code for an invalid, expired or revoked access token. */
    private const INVALID_TOKEN = 190;

    public function __construct(string $message, public readonly int $status = 0, public readonly ?int $errorCode = null)
    {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error');

        $reason = is_array($error) ? ($error['message'] ?? null) : $error;
        $code = is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : null;

        $reason = is_string($reason) && $reason !== '' ? $reason : 'HTTP '.$response->status();

        return new self('Facebook rejected the request: '.$reason, $response->status(), $code);
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401 || $this->errorCode === self::INVALID_TOKEN;
    }

    /** A 4xx other than 429: sending the same request again cannot succeed. */
    public function isPermanent(): bool
    {
        return $this->status >= 400 && $this->status < 500 && $this->status !== 429;
    }
}
