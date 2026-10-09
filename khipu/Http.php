<?php

namespace KhipuWhmcs;

/**
 * HTTP transport shared by every Khipu client.
 *
 * A transport is a callable($method, $url, array $headers, $body) returning
 * array('status' => int, 'body' => string, 'error' => string). Production uses
 * curlTransport(); tests inject a fake one, so nothing here needs WHMCS.
 */
class Http
{
    const BASE_URL = 'https://payment-api.khipu.com/v3';

    /**
     * Sends a request to the v3 API.
     *
     * @param callable   $transport
     * @param string     $apiKey
     * @param string     $method
     * @param string     $path      relative to BASE_URL, with the leading slash
     * @param array|null $payload   sent as JSON when not null
     *
     * @return array what the transport returned
     */
    public static function request($transport, $apiKey, $method, $path, $payload = null)
    {
        $headers = array(
            'x-api-key: ' . $apiKey,
            'User-Agent: ' . Version::USER_AGENT,
            'Accept: application/json',
        );

        $body = null;
        if (null !== $payload) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload);
        }

        return call_user_func($transport, $method, self::BASE_URL . $path, $headers, $body);
    }

    /**
     * Production transport.
     *
     * Every call has a hard time limit: without one a slow Khipu kept the
     * payer's page, or the admin's refund, waiting until max_execution_time.
     *
     * @param int $connectTimeout seconds to establish the connection
     * @param int $timeout        seconds for the whole request
     *
     * @return callable
     */
    public static function curlTransport($connectTimeout, $timeout)
    {
        $connectTimeout = (int) $connectTimeout;
        $timeout = (int) $timeout;

        return function ($method, $url, array $headers, $body) use ($connectTimeout, $timeout) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);

            if ('POST' === $method) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
            }

            $responseBody = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            // A no-op since PHP 8.0 and deprecated in 8.5.
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }

            return array(
                'status' => $status,
                'body' => (false === $responseBody) ? '' : (string) $responseBody,
                'error' => (false === $responseBody) ? ('' !== $error ? $error : 'Error de comunicación con Khipu.') : '',
            );
        };
    }
}
