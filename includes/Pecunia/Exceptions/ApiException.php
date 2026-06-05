<?php
declare(strict_types=1);

namespace Pecunia\Exceptions;

use RuntimeException;
use Psr\Http\Message\ResponseInterface;

class ApiException extends RuntimeException
{
	private ?ResponseInterface $response;
	private ?array $payload;

	public function __construct(
		string $message,
		int $code = 0,
		?ResponseInterface $response = null,
		?array $payload = null
	) {
		parent::__construct( $message, $code );
		$this->response = $response;
		$this->payload = $payload;
	}

	public function getResponse(): ?ResponseInterface
	{
		return $this->response;
	}

	public function getPayload(): ?array
	{
		return $this->payload;
	}
}
