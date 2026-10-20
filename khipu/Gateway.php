<?php

namespace KhipuWhmcs;

/**
 * Helpers shared by khipu.php and the callbacks. Kept out of the global
 * `khipu_*` namespace, where WHMCS looks up the module's own functions.
 */
class Gateway
{
    const MODULE = 'khipu';

    /**
     * @return bool whether API Key, receiver id and secret are all set
     */
    public static function isConfigured(array $params)
    {
        foreach (array('api_key', 'receiver_id', 'secret') as $field) {
            if (!isset($params[$field]) || '' === trim((string) $params[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string the system URL with exactly one trailing slash
     */
    public static function systemUrl(array $params)
    {
        return rtrim(isset($params['systemurl']) ? (string) $params['systemurl'] : '', '/') . '/';
    }

    /**
     * @param array      $params
     * @param string|int $invoiceId
     * @param string     $query     extra query string, without the leading '&'
     *
     * @return string
     */
    public static function invoiceUrl(array $params, $invoiceId, $query = '')
    {
        return self::systemUrl($params) . 'viewinvoice.php?id=' . (int) $invoiceId . ('' !== $query ? '&' . $query : '');
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    public static function html($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param mixed $value
     *
     * @return string trimmed string, or '' for anything that is not a scalar
     */
    public static function param($value)
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param string $text
     * @param int    $length
     *
     * @return string
     */
    public static function truncate($text, $length)
    {
        return function_exists('mb_substr') ? mb_substr((string) $text, 0, $length, 'UTF-8') : substr((string) $text, 0, $length);
    }

    /**
     * @param string $url
     */
    public static function redirect($url)
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    /**
     * Minimal page for the payer; every value is escaped.
     *
     * @param int    $code
     * @param string $message
     * @param string $backUrl
     */
    public static function errorPage($code, $message, $backUrl)
    {
        http_response_code((int) $code);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Khipu</title></head>'
            . '<body style="font-family:sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem">'
            . '<p>' . self::html($message) . '</p>'
            . '<p><a href="' . self::html($backUrl) . '">Volver</a></p>'
            . '</body></html>';
        exit;
    }

    /**
     * Plain-text answer to Khipu's webhook.
     *
     * @param int    $code
     * @param string $text
     */
    public static function respond($code, $text)
    {
        http_response_code((int) $code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $text;
        exit;
    }
}
