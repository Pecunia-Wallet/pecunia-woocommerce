<?php
declare(strict_types=1);

namespace Pecunia\Models;

class Settings
{
	public ?string $name = null;
	public ?string $email = null;
	public ?string $image = null;
	public ?float $inaccuracyPercent = null;
	public ?string $inaccuracyType = null;
	public ?string $supportUrl = null;
	public ?string $aboutUrl = null;
	public ?string $defaultCallbackUrl = null;
	public ?string $successCallbackUrl = null;
	public ?string $failureCallbackUrl = null;
	public ?bool $notifyOnChange = null;
	public ?bool $notifyOnSuccess = null;
	public ?bool $notifyOnFailure = null;

	public static function fromArray(array $a): self
	{
		$s = new self();
		$s->name = $a['name'] ?? null;
		$s->email = $a['email'] ?? null;
		$s->image = $a['image'] ?? null;
		$s->inaccuracyPercent = isset( $a['inaccuracyPercent'] ) ? (float) $a['inaccuracyPercent'] : null;
		$s->inaccuracyType = $a['inaccuracyType'] ?? null;
		$s->supportUrl = $a['supportUrl'] ?? null;
		$s->aboutUrl = $a['aboutUrl'] ?? null;
		$s->defaultCallbackUrl = $a['defaultCallbackUrl'] ?? null;
		$s->successCallbackUrl = $a['successCallbackUrl'] ?? null;
		$s->failureCallbackUrl = $a['failureCallbackUrl'] ?? null;
		$s->notifyOnChange = isset( $a['notifyOnChange'] ) ? (bool) $a['notifyOnChange'] : null;
		$s->notifyOnSuccess = isset( $a['notifyOnSuccess'] ) ? (bool) $a['notifyOnSuccess'] : null;
		$s->notifyOnFailure = isset( $a['notifyOnFailure'] ) ? (bool) $a['notifyOnFailure'] : null;
		return $s;
	}

	public function toArray(): array
	{
		return array_filter(
			get_object_vars( $this ),
			static fn( $v ) => $v !== null
		);
	}
}
