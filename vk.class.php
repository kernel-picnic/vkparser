<?php

class vk
{
    private $access_token;
    private $api_version;
    private $url = 'https://api.vk.com/method/';

    // Конструктор
    public function __construct($access_token, $api_version)
    {
        $this->access_token = $access_token;
        $this->api_version = $api_version;
    }

    /**
     * Делает запрос к API VK
     * @param $method
     * @param $params
     */
    public function method($method, $params = null)
    {
        $p = '';

        if ($params && is_array($params))
        {
            foreach ($params as $key => $param)
            {
                $p .= ($p == '' ? '' : '&') . $key . '=' . urlencode($param);
            }
        }

        $curl_handle = curl_init();
        curl_setopt($curl_handle, CURLOPT_URL, $this->url . $method . '?' . ($p ? $p . '&' : '') . 'access_token=' . $this->access_token . '&v=' . $this->api_version);
        curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($curl_handle);
        curl_close($curl_handle);

        if ($response)
        {
            return json_decode($response);
        }

        return false;
    }
}