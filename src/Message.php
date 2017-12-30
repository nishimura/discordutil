<?php

/*
 * Copyright (c) 2017 Satoshi Nishimura <nishim314@gmail.com>
 *
 * MIT license
 */


namespace DiscordUtil;

class Message
{
    const URL_BASE = 'https://discordapp.com/api';
    const API_VERSION = 6;
    const URL_CREATE = ['POST', 'channels/%d/messages'];
    const URL_UPDATE = ['PATCH', 'channels/%d/messages/%d'];
    private $logger;
    private $token;
    private $url;
    public function __construct($logger, $token)
    {
        $this->logger = $logger;
        $this->token = $token;
    }

    public function send($channelId, $message, $updateId = null)
    {
        if ($updateId === null){
            $method = self::URL_CREATE[0];
            $url = $this->getUrl(self::URL_CREATE[1], $channelId);
        }else{
            $method = self::URL_UPDATE[0];
            $url = $this->getUrl(self::URL_UPDATE[1], $channelId, $updateId);
        }

        $message = json_encode(['content' => $message]);
        $headers = [
            'Authorization: Bot ' . $this->token,
            'User-Agent: ' . Bot::USER_AGENT,
            'Content-Type: application/json',
            'Content-Length:' . strlen($message),
        ];
        $context = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $message,
            ]
        ];

        $ret = file_get_contents($url, false, stream_context_create($context));
        // echo $http_response_header)
        return json_decode($ret);
    }

    private function getUrl($suffix, ...$params)
    {
        $url = self::URL_BASE . '/v' . self::API_VERSION;
        $url .= '/' . $suffix;
        return sprintf($url, ...$params);
    }
}
