<?php

class vk
{
    private $access_token;
    private $url = 'https://api.vk.com/method/';

    // Конструктор
    public function __construct($access_token)
    {
        $this->access_token = $access_token;
    }

    /**
     * Делает запрос к API VK
     * @param $method
     * @param $params
     */
    public function method($method, $params = null)
    {
        $p = "";

        if ($params && is_array($params))
            foreach ($params as $key => $param)
                $p .= ($p == "" ? "" : "&") . $key . "=" . urlencode($param);

        $curl_handle = curl_init();
        curl_setopt($curl_handle, CURLOPT_URL, $this->url . $method . "?" . ($p ? $p . "&" : "") . "access_token=" . $this->access_token);
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