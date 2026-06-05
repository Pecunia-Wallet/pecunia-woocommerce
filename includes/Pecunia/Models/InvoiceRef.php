<?php
declare(strict_types=1);

namespace Pecunia\Models;

class InvoiceRef
{
	public string $id;
	public string $href;
	public string $paymentUrl;

	public function __construct(string $id, string $href)
	{
		$this->id = $id;
		$this->href = $href;
		$this->paymentUrl = Invoice::paymentUrl( $id );
	}

	public static function fromArray(array $a): self
	{
		return new self(
			(string) ( $a['id'] ?? '' ),
			(string) ( $a['href'] ?? '' )
		);
	}
}
