<?php

/**
 * Transport that answers from a script and records every request.
 */
class FakeTransport
{
    /** @var array list of array('method', 'url', 'headers', 'body') */
    public $requests = array();

    /** @var array responses still to hand out, in order */
    private $responses;

    /**
     * @param array $responses each array('status' => int, 'body' => string|array, 'error' => string);
     *                         an array body is JSON-encoded
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function __invoke($method, $url, array $headers, $body)
    {
        $this->requests[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);

        if (!$this->responses) {
            throw new LogicException('Unexpected request: ' . $method . ' ' . $url);
        }

        $response = array_merge(array('status' => 200, 'body' => '', 'error' => ''), array_shift($this->responses));
        if (is_array($response['body'])) {
            $response['body'] = json_encode($response['body']);
        }

        return $response;
    }

    /** @return array the JSON body of request $i, decoded */
    public function sent($i)
    {
        return json_decode($this->requests[$i]['body'], true);
    }
}
