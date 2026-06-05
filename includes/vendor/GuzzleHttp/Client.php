<?php
declare(strict_types=1);

namespace GuzzleHttp;

use GuzzleHttp\Exception\RequestException;

final class Client
{
	private string $baseUri;
	private float $timeout;
	private bool $httpErrors;

	public function __construct(array $options = array())
	{
		$this->baseUri = isset( $options['base_uri'] ) ? (string) $options['base_uri'] : '';
		$this->timeout = isset( $options['timeout'] ) ? (float) $options['timeout'] : 10.0;
		$this->httpErrors = isset( $options['http_errors'] ) ? (bool) $options['http_errors'] : false;
	}

	public function request(string $method, string $path, array $options = array()): Response
	{
		$url = $this->resolveUrl( $path, $options['query'] ?? null );
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $this->timeout,
			'headers' => array(),
		);

		if ( isset( $options['headers'] ) && is_array( $options['headers'] ) ) {
			$args['headers'] = $options['headers'];
		}

		if ( isset( $options['json'] ) ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body'] = wp_json_encode( $options['json'] );
		} elseif ( isset( $options['body'] ) ) {
			$args['body'] = $options['body'];
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new RequestException( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		$normalizedHeaders = array();
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$normalizedHeaders = $headers->getAll();
		} elseif ( is_array( $headers ) ) {
			$normalizedHeaders = $headers;
		}

		$normalizedHeaders = array_change_key_case( $normalizedHeaders, CASE_LOWER );

		if ( $this->httpErrors && $status >= 400 ) {
			throw new RequestException( 'HTTP error ' . $status . ' for ' . $url );
		}

		return new Response( $status, $normalizedHeaders, $body );
	}

	private function resolveUrl(string $path, $query = null): string
	{
		$base = $this->baseUri !== '' ? rtrim( $this->baseUri, '/' ) . '/' : '';
		$url = $base . ltrim( $path, '/' );

		if ( is_array( $query ) && ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		return $url;
	}
}
