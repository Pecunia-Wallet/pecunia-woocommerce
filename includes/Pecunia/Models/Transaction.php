<?php
declare(strict_types=1);

namespace Pecunia\Models;

use DateTimeImmutable;

class Transaction
{
	public string $id;
	public string $type;
	public string $amount;
	public ?string $fee = null;
	public ?DateTimeImmutable $time = null;
	public ?int $confirmations = null;
	public ?bool $spendable = null;
	public ?array $addresses = null;

	public static function fromArray(array $a): self
	{
		$t = new self();
		$t->id = $a['id'] ?? '';
		$t->type = $a['type'] ?? '';
		$t->amount = isset( $a['amount'] ) ? (string) $a['amount'] : '0';
		$t->fee = isset( $a['fee'] ) ? (string) $a['fee'] : null;
		$t->time = isset( $a['time'] ) ? new DateTimeImmutable( (string) $a['time'] ) : null;
		$t->confirmations = isset( $a['confirmations'] ) ? (int) $a['confirmations'] : null;
		$t->spendable = isset( $a['spendable'] ) ? (bool) $a['spendable'] : null;
		$t->addresses = $a['addresses'] ?? null;
		return $t;
	}
}
