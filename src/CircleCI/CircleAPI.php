<?php

namespace UpdateTool\CircleCI;

class CircleAPI
{
    protected $circleToken;

    public function __construct($token = null)
    {
        $this->circleToken = $token;
        if (!$this->circleToken) {
            $this->circleToken = getenv('CIRCLE_TOKEN');
        }
    }

    public function envVars($org, $project, $provider = 'gh')
    {
        $url = $this->url('envvar', $org, $project, $provider);

        $res = $this->api($url);

        $data = [];
        if ($res->getStatusCode() == 200) {
            $body = $res->getBody()->getContents();
            if (!empty($body)) {
                $bodyData = json_decode($body, true);
                if (isset($bodyData['items'])) {
                    foreach ($bodyData['items'] as $varData) {
                        $key = $varData['name'];
                        $value = $varData['value'];
                        $data[$key] = $value;
                    }
                }
            }
        }

        return [$res->getStatusCode(), $data];
    }

    protected function url($command, $org, $project, $provider = 'gh')
    {
        return "https://circleci.com/api/v2/project/$provider/$org/$project/$command";
    }

    protected function post($url, $data, $method = 'POST')
    {
        $res = $this->api($url, $data, $method);
        return $res->getStatusCode();
    }

    protected function api($url, $data = null, $method = 'GET')
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'pantheon/update-tool',
            'Accept' => 'application/json',
        ];

        $params = [
            'headers' => $headers,
            'auth' => [$this->circleToken, ''],
            'json' => $data,
        ];

        if ($data) {
            $params['json'] = $data;
        }

        $client = new \GuzzleHttp\Client();
        $res = $client->request($method, $url, $params);

        return $res;
    }
}
