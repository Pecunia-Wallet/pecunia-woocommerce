<?php
declare(strict_types=1);

namespace Pecunia\Models;

use DateTimeImmutable;

class Invoice
{
	public string $id;
	public string $paymentUrl;
	public string $status;
	public object $amount;
	public string $sourceCurrency;
	public DateTimeImmutable $creationDate;
	public ?object $expires = null;
	public ?DateTimeImmutable $closureDate = null;
	public ?array $availableCoins = null;
	public ?string $operationCoin = null;
	public ?string $exchangeRate = null;
	public ?array $relatedTransactions = null;
	public mixed $meta = null;

	public static function paymentUrl(string $id): string
	{
		return 'https://pecuniawallet.com/invoices?id=' . rawurlencode( $id );
	}

	public static function fromArray(array $a): self
	{
		$it = new self();
		$it->id = (string) ( $a['id'] ?? '' );
		$it->paymentUrl = self::paymentUrl( $it->id );
		$it->status = strtolower( (string) ( $a['status'] ?? 'pending' ) );
		$it->amount = (object) array(
			'requested' => (string) ( $a['amount']['requested'] ?? '0' ),
			'received'  => (string) ( $a['amount']['received'] ?? '0' ),
			'pending'   => (string) ( $a['amount']['pending'] ?? '0' ),
		);
		$it->sourceCurrency = (string) ( $a['sourceCurrency'] ?? '' );
		$it->creationDate = isset( $a['creationDate'] ) ? new DateTimeImmutable( (string) $a['creationDate'] ) : new DateTimeImmutable();

		if ( ! empty( $a['expires'] ) && is_array( $a['expires'] ) ) {
			$it->expires = (object) array(
				'date'     => new DateTimeImmutable( (string) ( $a['expires']['date'] ?? 'now' ) ),
				'duration' => (string) ( $a['expires']['duration'] ?? '' ),
			);
		}

		if ( ! empty( $a['closureDate'] ) ) {
			$it->closureDate = new DateTimeImmutable( (string) $a['closureDate'] );
		}

		$it->availableCoins = $a['availableCoins'] ?? null;
		$it->operationCoin = $a['operationCoin'] ?? null;
		$it->exchangeRate = isset( $a['exchangeRate'] ) ? (string) $a['exchangeRate'] : null;
		$it->relatedTransactions = $a['relatedTransactions'] ?? null;

		$it->meta = null;
		if ( array_key_exists( 'meta', $a ) ) {
			if ( is_string( $a['meta'] ) ) {
				$decoded = base64_decode( $a['meta'], true );
				if ( $decoded !== false ) {
					$json = json_decode( $decoded, true );
					if ( json_last_error() === JSON_ERROR_NONE ) {
						$it->meta = $json;
					} else {
						$it->meta = $a['meta'];
					}
				} else {
					$json = json_decode( $a['meta'], true );
					$it->meta = ( json_last_error() === JSON_ERROR_NONE ) ? $json : $a['meta'];
				}
			} else {
				$it->meta = $a['meta'];
			}
		}

		return $it;
	}
}
