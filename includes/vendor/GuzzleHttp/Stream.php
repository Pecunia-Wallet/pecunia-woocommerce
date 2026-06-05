<?php
declare(strict_types=1);

namespace GuzzleHttp;

final class Stream
{
	private string $body;

	public function __construct(string $body)
	{
		$this->body = $body;
	}

	public function __toString(): string
	{
		return $this->body;
	}
}
