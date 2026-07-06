<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Pecunia_Order
{
    public const META_INVOICE_ID = '_pecunia_invoice_id';
    public const META_INVOICE_URL = '_pecunia_invoice_url';
    public const META_INVOICE_HREF = '_pecunia_invoice_href';
    public const META_INVOICE_PAYMENT_URL = '_pecunia_invoice_payment_url';
    public const META_INVOICE_STATUS = '_pecunia_invoice_status';
    public const META_INVOICE_FINGERPRINT = '_pecunia_invoice_fingerprint';
    public const META_INVOICE_CREATED_AT = '_pecunia_invoice_created_at';
    public const META_INVOICE_LAST_SYNC_AT = '_pecunia_invoice_last_sync_at';
    public const META_INVOICE_EXPIRES_AT = '_pecunia_invoice_expires_at';
    public const META_INVOICE_PAYLOAD = '_pecunia_invoice_payload';
    public const META_INVOICE_ERROR = '_pecunia_invoice_error';
    public const META_CALLBACK_HASH = '_pecunia_last_callback_hash';

    public function get( int $order_id ): ?array
    {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }

        return array(
            'order' => $order,
            'invoice_id' => (string) $order->get_meta( self::META_INVOICE_ID, true ),
            'invoice_url' => (string) $order->get_meta( self::META_INVOICE_URL, true ),
            'href' => (string) $order->get_meta( self::META_INVOICE_HREF, true ),
            'payment_url' => (string) $order->get_meta( self::META_INVOICE_PAYMENT_URL, true ),
            'status' => strtolower( (string) $order->get_meta( self::META_INVOICE_STATUS, true ) ),
            'fingerprint' => (string) $order->get_meta( self::META_INVOICE_FINGERPRINT, true ),
            'created_at' => (string) $order->get_meta( self::META_INVOICE_CREATED_AT, true ),
            'last_sync_at' => (string) $order->get_meta( self::META_INVOICE_LAST_SYNC_AT, true ),
            'expires_at' => (string) $order->get_meta( self::META_INVOICE_EXPIRES_AT, true ),
            'payload' => (string) $order->get_meta( self::META_INVOICE_PAYLOAD, true ),
            'error' => (string) $order->get_meta( self::META_INVOICE_ERROR, true ),
        );
    }

    public function saveInvoice( WC_Order $order, array $data ): void
    {
        // Sanitize every field before storing
        $map = array(
            self::META_INVOICE_ID           => sanitize_text_field( $data['invoice_id'] ?? '' ),
            self::META_INVOICE_URL          => esc_url_raw( $data['invoice_url'] ?? '' ),
            self::META_INVOICE_HREF         => esc_url_raw( $data['href'] ?? '' ),
            self::META_INVOICE_PAYMENT_URL  => esc_url_raw( $data['payment_url'] ?? ( $data['invoice_url'] ?? '' ) ),
            self::META_INVOICE_STATUS       => sanitize_key( strtolower( (string) ( $data['status'] ?? 'pending' ) ) ),
            self::META_INVOICE_FINGERPRINT  => sanitize_text_field( $data['fingerprint'] ?? '' ),
            self::META_INVOICE_CREATED_AT   => sanitize_text_field( $data['created_at'] ?? gmdate( 'c' ) ),
            self::META_INVOICE_LAST_SYNC_AT => sanitize_text_field( $data['last_sync_at'] ?? gmdate( 'c' ) ),
            self::META_INVOICE_EXPIRES_AT   => sanitize_text_field( $data['expires_at'] ?? '' ),
            self::META_INVOICE_PAYLOAD      => isset( $data['payload'] ) && is_array( $data['payload'] )
                ? wp_json_encode( map_deep( $data['payload'], 'sanitize_text_field' ) )
                : '',
            self::META_INVOICE_ERROR        => sanitize_text_field( $data['error'] ?? '' ),
        );

        foreach ( $map as $key => $value ) {
            $order->update_meta_data( $key, $value );
        }

        $order->save();
    }

    public function updateStatus( WC_Order $order, string $status ): void
    {
        $order->update_meta_data( self::META_INVOICE_STATUS, sanitize_key( strtolower( $status ) ) );
        $order->update_meta_data( self::META_INVOICE_LAST_SYNC_AT, sanitize_text_field( gmdate( 'c' ) ) );
        $order->save();
    }

    public function markCallbackHash( WC_Order $order, string $hash ): void
    {
        $order->update_meta_data( self::META_CALLBACK_HASH, sanitize_text_field( $hash ) );
        $order->save();
    }

    public function getCallbackHash( WC_Order $order ): string
    {
        return (string) $order->get_meta( self::META_CALLBACK_HASH, true );
    }
}