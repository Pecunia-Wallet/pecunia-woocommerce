<?php
declare(strict_types=1);

namespace GuzzleHttp;

use Psr\Http\Message\ResponseInterface;

final class Response implements ResponseInterface
{
	private int $statusCode;
	private array $headers;
	private Stream $body;

	public function __construct(int $statusCode, array $headers = array(), string $body = '')
	{
		$this->statusCode = $statusCode;
		$this->headers = array_change_key_case($headers, CASE_LOWER);
		$this->body = new Stream($body);
	}

	public function getStatusCode(): int
	{
		return $this->statusCode;
	}

	public function getBody()
	{
		return $this->body;
	}

	public function getHeaderLine(string $name): string
	{
		$key = strtolower($name);

		if ( ! isset( $this->headers[ $key ] ) ) {
			return '';
		}

		$value = $this->headers[ $key ];

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}
}
