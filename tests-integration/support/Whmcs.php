<?php

use KhipuWhmcs\Client;
use KhipuWhmcs\PaymentCustom;
use WHMCS\Database\Capsule;

/**
 * Helpers for the integration suite: a real WHMCS (the dev one) and the real
 * Khipu API with the demo account configured in it.
 *
 * Nothing here prints a credential: assertion messages carry ids and amounts only.
 */
class Whmcs
{
    const CLIENT_FIRSTNAME = 'Integracion';

    /** Item description of the invoice seed.php creates: the only payment live refunds may touch. */
    const SEED_DESCRIPTION = 'Semilla de integracion Khipu';

    /** CLP per USD for a currency the suite has to add; any fixed rate works. */
    const TEST_CLP_PER_USD = 950;

    /**
     * Makes sure WHMCS has the USD and CLP currencies the suite uses.
     *
     * A fresh install only has its default currency. The missing one is added
     * at TEST_CLP_PER_USD relative to the other, with "1234.56" formatting so
     * CLP invoices keep their cents (the rounding tests need 1000.50).
     */
    public static function ensureCurrencies()
    {
        $usd = Capsule::table('tblcurrencies')->where('code', 'USD')->value('rate');
        $clp = Capsule::table('tblcurrencies')->where('code', 'CLP')->value('rate');

        if (null === $usd && null === $clp) {
            throw new RuntimeException('WHMCS needs a USD or a CLP currency for the integration suite.');
        }
        if (null === $clp) {
            self::addCurrency('CLP', (float) $usd * self::TEST_CLP_PER_USD);
        }
        if (null === $usd) {
            self::addCurrency('USD', (float) $clp / self::TEST_CLP_PER_USD);
        }
    }

    private static function addCurrency($code, $rate)
    {
        Capsule::table('tblcurrencies')->insert(array(
            'code' => $code, 'prefix' => '$', 'suffix' => ' ' . $code, 'format' => 1, 'rate' => $rate, 'default' => 0,
        ));
    }

    /** @return array gateway parameters, credentials included */
    public static function params($invoiceId = null)
    {
        return null === $invoiceId ? getGatewayVariables('khipu') : getGatewayVariables('khipu', (int) $invoiceId);
    }

    public static function secret()
    {
        return trim((string) self::params()['secret']);
    }

    public static function receiverId()
    {
        return trim((string) self::params()['receiver_id']);
    }

    /** @return Client */
    public static function khipu($apiKey = null)
    {
        return Client::withTimeouts(null === $apiKey ? trim((string) self::params()['api_key']) : $apiKey, Client::BACKEND_TIMEOUTS);
    }

    /** @return bool whether WHMCS's System URL is public https, which Khipu needs to create payments */
    public static function hasPublicUrl()
    {
        return 0 === strpos((string) self::params()['systemurl'], 'https://');
    }

    /**
     * A test client in this currency, created once.
     *
     * @param string $currency ISO code of a currency WHMCS has
     *
     * @return int
     */
    public static function clientId($currency)
    {
        $currencyId = (int) Capsule::table('tblcurrencies')->where('code', $currency)->value('id');
        $existing = Capsule::table('tblclients')
            ->where('firstname', self::CLIENT_FIRSTNAME)->where('lastname', $currency)->where('currency', $currencyId)
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        $result = localAPI('AddClient', array(
            'firstname' => self::CLIENT_FIRSTNAME, 'lastname' => $currency,
            'email' => 'integracion.' . strtolower($currency) . '.' . time() . '@khipu.local',
            'address1' => 'Calle 1', 'city' => 'Santiago', 'state' => 'RM', 'postcode' => '8320000', 'country' => 'CL',
            'phonenumber' => '+56 2 0000 0000', 'password2' => bin2hex(random_bytes(12)),
            'currency' => $currencyId, 'skipvalidation' => true,
        ));

        if (!isset($result['result']) || 'success' !== $result['result']) {
            throw new RuntimeException('WHMCS did not create the client: ' . (isset($result['message']) ? $result['message'] : 'no message'));
        }

        return (int) $result['clientid'];
    }

    /**
     * @return int a new unpaid invoice on the khipu gateway
     */
    public static function invoice($currency, $amount, $description = 'Prueba de integracion Khipu')
    {
        $result = localAPI('CreateInvoice', array(
            'userid' => self::clientId($currency), 'status' => 'Unpaid', 'paymentmethod' => 'khipu',
            'itemdescription1' => $description, 'itemamount1' => (string) $amount, 'itemtaxed1' => false,
        ));

        if (!isset($result['result']) || 'success' !== $result['result']) {
            throw new RuntimeException('WHMCS did not create the invoice: ' . (isset($result['message']) ? $result['message'] : 'no message'));
        }

        return (int) $result['invoiceid'];
    }

    /**
     * The pay form khipu_link() renders for an invoice, as POST fields.
     *
     * @param array $override parameters to change before rendering, e.g. the secret
     *
     * @return array
     */
    public static function payForm($invoiceId, array $override = array())
    {
        $html = khipu_link(array_merge(self::params($invoiceId), $override));
        preg_match_all('/name="([a-z_]+)" value="([^"]*)"/', $html, $matches, PREG_SET_ORDER);

        $fields = array();
        foreach ($matches as $match) {
            $fields[$match[1]] = html_entity_decode($match[2], ENT_QUOTES);
        }

        return $fields;
    }

    /**
     * A request to this WHMCS through its own Apache, without following redirects.
     *
     * @param string       $path    relative to the WHMCS root
     * @param array|string $body    form fields, or a raw body
     * @param array        $headers
     *
     * @return array array('status' => int, 'location' => string, 'body' => string)
     */
    public static function request($method, $path, $body = null, array $headers = array())
    {
        // The container's own network address, not 127.0.0.1: WHMCS rejects
        // its license (and with it localAPI) on requests arriving on loopback.
        $ch = curl_init('http://' . gethostbyname(gethostname()) . '/' . ltrim($path, '/'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        if (null !== $body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        }

        $responseBody = (string) curl_exec($ch);
        $result = array(
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'location' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
            'body' => $responseBody,
        );
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        return $result;
    }

    /**
     * Sends an API 3.0 notification signed like Khipu signs it.
     *
     * @param array       $notice
     * @param string|null $secret null for the configured one
     *
     * @return array see request()
     */
    public static function notify(array $notice, $secret = null)
    {
        $body = json_encode($notice);
        $t = (string) (time() * 1000);
        $signature = base64_encode(hash_hmac('sha256', $t . '.' . $body, null === $secret ? self::secret() : $secret, true));

        return self::request('POST', 'modules/gateways/callback/khipu.php', $body, array(
            'Content-Type: application/json',
            'X-Khipu-Signature: t=' . $t . ',s=' . $signature,
        ));
    }

    /**
     * Creates a real (unpaid) Khipu payment for an invoice through the pay button.
     *
     * @return string payment_id
     */
    public static function createPayment($invoiceId)
    {
        $response = self::request('POST', 'modules/gateways/callback/khipu_redirect.php', self::payForm($invoiceId));

        if (303 !== $response['status'] || !preg_match('#^https://[^/]*khipu\.com/.*?([a-z0-9]{12})$#', $response['location'], $match)) {
            throw new RuntimeException('Pay button did not reach Khipu: HTTP ' . $response['status'] . ' ' . $response['location']);
        }

        return $match[1];
    }

    /**
     * Holds a MySQL named lock from a second connection until release() is called.
     *
     * A second connection because MySQL locks are re-entrant per session: the
     * code under test running in this process would just take it again.
     *
     * @return PDO keep it alive while the lock must be held
     */
    public static function holdLock($name)
    {
        $config = Capsule::connection()->getConfig();
        $pdo = new PDO(
            'mysql:host=' . $config['host'] . (empty($config['port']) ? '' : ';port=' . $config['port']) . ';dbname=' . $config['database'],
            $config['username'],
            $config['password']
        );

        if (1 !== (int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($name) . ', 0)')->fetchColumn()) {
            throw new RuntimeException('Could not take lock ' . $name);
        }

        return $pdo;
    }

    public static function release(PDO $pdo, $name)
    {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($name) . ')');
    }

    /** @return bool whether this invoice is the one seed.php created for refunds */
    public static function isSeedInvoice($invoiceId)
    {
        return Capsule::table('tblinvoiceitems')
            ->where('invoiceid', (int) $invoiceId)->where('description', self::SEED_DESCRIPTION)
            ->count() > 0;
    }

    /** @return int number of tblaccounts rows with this transaction id */
    public static function rows($transid)
    {
        return Capsule::table('tblaccounts')->where('transid', (string) $transid)->count();
    }

    /** @return int last gateway log id, to read only what a test wrote */
    public static function lastLogId()
    {
        return (int) Capsule::table('tblgatewaylog')->max('id');
    }

    /** @return array gateway log rows written after $sinceId, oldest first */
    public static function logSince($sinceId)
    {
        return Capsule::table('tblgatewaylog')->where('id', '>', $sinceId)->orderBy('id')->get()->all();
    }

    /** @return string raw stored value of a khipu gateway setting (encrypted or not) */
    public static function rawSetting($setting)
    {
        return (string) Capsule::table('tblpaymentgateways')->where('gateway', 'khipu')->where('setting', $setting)->value('value');
    }

    public static function setRawSetting($setting, $value)
    {
        Capsule::table('tblpaymentgateways')->where('gateway', 'khipu')->where('setting', $setting)->update(array('value' => $value));
    }

    /**
     * Recorded Khipu payments of this WHMCS, as Khipu reports them now.
     *
     * Each has: 'row' (tblaccounts), 'payment' (GET /v3/payments body),
     * 'remaining' (amount minus total_refunded), 'custom' (PaymentCustom::read()).
     *
     * @param int $limit most recent first; every one costs an API call
     *
     * @return array
     */
    public static function recordedPayments($limit = 10)
    {
        $rows = Capsule::table('tblaccounts')
            ->where('gateway', 'khipu')->where('amountin', '>', 0)->where('refundid', 0)
            ->orderBy('id', 'desc')->limit($limit)->get()->all();

        $out = array();
        foreach ($rows as $row) {
            $lookup = self::khipu()->getPayment($row->transid);
            if (!$lookup['ok']) {
                continue;
            }
            $payment = $lookup['data'];
            $refunded = isset($payment['total_refunded']) ? (float) $payment['total_refunded'] : 0.0;
            $out[] = array(
                'row' => $row,
                'payment' => $payment,
                'remaining' => (float) $payment['amount'] - $refunded,
                'custom' => PaymentCustom::read(isset($payment['custom']) ? $payment['custom'] : null),
            );
        }

        return $out;
    }

    /**
     * First recorded payment matching $filter, or skip the test with $hint.
     *
     * @return array see recordedPayments()
     */
    public static function fixture(PHPUnit\Framework\TestCase $test, $filter, $hint)
    {
        foreach (self::recordedPayments() as $candidate) {
            if (call_user_func($filter, $candidate)) {
                return $candidate;
            }
        }

        $test->markTestSkipped($hint);

        return array();
    }
}
