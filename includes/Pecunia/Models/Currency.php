<?php
declare(strict_types=1);

namespace Pecunia\Models;

class Currency
{
	public string $symbol;
	public string $name;
	public ?int $decimals = null;
	public ?string $sign = null;

	public static function fromArray(array $a): self
	{
		$c = new self();
		$c->symbol = $a['symbol'] ?? '';
		$c->name = $a['name'] ?? '';
		$c->decimals = isset( $a['decimals'] ) ? (int) $a['decimals'] : null;
		$c->sign = $a['sign'] ?? null;
		return $c;
	}
}
