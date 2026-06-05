<?php
declare(strict_types=1);

namespace Pecunia;

final class Callback
{
	private const HEADER_NAME = 'X-Signature';

	public static function verifySignature(
		string  $payload,
		?string $header,
		string  $currentSecret,
		?string $previousSecret = null
	): bool|string
	{
		if ($header === null) {
			$header = $_SERVER['HTTP_X_SIGNATURE'] ?? ($_SERVER['X_SIGNATURE'] ?? null);
		}

		if ( ! $header || ! is_string( $header ) ) {
			return false;
		}

		$sigs = self::parseSignatureHeader( $header );
		if ( empty( $sigs ) || ! isset( $sigs['v1'] ) ) {
			return false;
		}

		$expectedV1 = hash_hmac( 'sha256', $payload, $currentSecret );
		if ( self::safeEqualsHex( $sigs['v1'], $expectedV1 ) ) {
			return 'v1';
		}

		if ( $previousSecret !== null && isset( $sigs['v0'] ) ) {
			$expectedV0 = hash_hmac( 'sha256', $payload, $previousSecret );
			if ( self::safeEqualsHex( $sigs['v0'], $expectedV0 ) ) {
				return 'v0';
			}
		}

		return false;
	}

	private static function parseSignatureHeader(string $header): array
	{
		$out = array();
		foreach (preg_split('/\s*,\s*/', trim($header)) as $part) {
			if ($part === '') continue;
			$kv = explode('=', $part, 2);
			if (count($kv) !== 2) continue;
			$key = trim($kv[0]);
			$val = trim($kv[1]);

			if (preg_match('/^v[0-9]+$/i', $key) && preg_match('/^[0-9a-fA-F]+$/', $val)) {
				$out[strtolower($key)] = strtolower($val);
			}
		}
		return $out;
	}

	private static function safeEqualsHex(string $aHex, string $bHex): bool
	{
		if ($aHex === '' || $bHex === '') return false;
		if (!ctype_xdigit($aHex) || !ctype_xdigit($bHex)) return false;
		if (strlen($aHex) !== strlen($bHex)) return false;

		$a = hex2bin($aHex);
		$b = hex2bin($bHex);
		if ($a === false || $b === false) return false;

		return hash_equals($a, $b);
	}
}
