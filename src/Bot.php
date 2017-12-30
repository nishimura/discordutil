<?php

/*
 * Copyright (c) 2017 Satoshi Nishimura <nishim314@gmail.com>
 *
 *
 * Original is: https://github.com/teamreflex/DiscordPHP
 *
 * This file is apart of the DiscordPHP project.
 *
 * Copyright (c) 2016 David Cole <david@team-reflex.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace DiscordUtil;

use Psr\Log\LoggerInterface;
use React\EventLoop;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging;
use Evenement\EventEmitterTrait;

class Bot
{
    const OP_DISPATCH = 0;
    const OP_HEARTBEAT = 1;
    const OP_IDENTIFY = 2;
    const OP_STATUS_UPDATE = 3;
    const OP_VOICE_STATUS_UPDATE = 4;
    const OP_VOICE_SERVER_PING = 5;
    const OP_RESUME = 6;
    const OP_RECONNECT = 7;
    const OP_REQUEST_GUILD_MEMBERS = 8;
    const OP_INVALID_SESSION = 9;
    const OP_HELLO = 10;
    const OP_HEARTBEAT_ACK = 11;

    const CLOSE_INVALID_TOKEN = 4004;

    const EV_READY = 'READY';
    const EV_RESUMED = 'RESUMED';
    const EV_GUILD_CREATE = 'GUILD_CREATE';

    const USER_AGENT = 'PhpSimpleBot (https://github.com/nishimura, 0.0.1)';
    const CONNECTION_ERROR_SLEEP_SECOND = 5;

    use EventEmitterTrait;

    private $logger;
    private $token;

    private $loop;
    private $conn;
    private $ws;
    private $closing = false;

    private $url;

    private $seq;
    private $connected = false;
    private $reconnecting = false;
    private $reconnectCount = 0;
    private $sessionId = null;
    private $userId = null;

    private $heartbeatTimer = null;
    private $heartbeatInterval = 0;
    private $heartbeatAckTimer = 0;
    private $emittedReady = false;

    private $unavailableGuildCount = 0;
    private $guilds = [];

    public function __construct(LoggerInterface $logger, string $token)
    {
        $this->logger = $logger;
        $this->token = $token;

        $this->on('ready', function () {
            $this->emittedReady = true;
        });
    }

    public function connect($url = null)
    {
        $this->logger->info('starting connect');

        if ($url === null)
            $url = 'wss://gateway.discord.gg/';

        $this->loop = EventLoop\Factory::create();
        $this->conn = new Connector($this->loop);
        $this->url = $url . '?v=6&encoding=json';

        $this->connectInternal();

        return $this;
    }

    public function run()
    {
        $this->loop->run();
        return $this;
    }

    public function connectInternal()
    {
        $this->logger->info('connectInternal');

        ($this->conn)($this->url)->then(
            [$this, 'onConnect'], [$this, 'onErrorConnect']
        );
    }

    public function onConnect(WebSocket $ws)
    {
        $this->logger->info('websocket connection has been created');

        $this->connected = true;
        $this->ws = $ws;

        $ws->on('message', [$this, 'onMessage']);
        $ws->on('close', [$this, 'onClose']);
        $ws->on('error', [$this, 'onError']);
    }
    public function onErrorConnect(\Exception $e)
    {
        // Pawl pls
        if (strpos($e->getMessage(), 'Tried to write to closed stream') !== false) {
            return;
        }

        $this->logger->error('websocket error', ['e' => $e->getMessage()]);
        $this->emit('error', [$e, $this]);

        sleep(self::CONNECTION_ERROR_SLEEP_SECOND);
    }
    public function onError(\Exception $e)
    {
        $this->logger->error('websocket error', ['e' => $e->getMessage()]);
        $this->emit('error', [$e, $this]);

        $this->onClose(0, 'websocket error');
    }

    public function onMessage(Messaging\Message $message)
    {
        if ($message->isBinary()) {
            $data = zlib_decode($message->getPayload());
        } else {
            $data = $message->getPayload();
        }

        $data = json_decode($data);
        $this->emit('raw', [$data, $this]);

        if (isset($data->s)) {
            $this->seq = $data->s;
        }

        $op = [
            self::OP_DISPATCH => 'dispatch',
            self::OP_HEARTBEAT => 'heartbeat',
            self::OP_IDENTIFY => 'identify',
            self::OP_STATUS_UPDATE => 'statusUpdate',
            //self::OP_VOICE_STATUS_UPDATE => '',
            //self::OP_VOICE_SERVER_PING => '',
            self::OP_RESUME => 'resume',
            self::OP_RECONNECT => 'reconnect',
            //self::OP_REQUEST_GUILD_MEMBERS => '',
            self::OP_INVALID_SESSION => 'invalidSession',
            self::OP_HELLO => 'hello',
            self::OP_HEARTBEAT_ACK => 'heartbeatAck',
        ];

        if (isset($op[$data->op])) {
            $this->{'on' . ucfirst($op[$data->op])}($data);
        }
    }

    public function close()
    {
        $this->logger->info('close called');

        $this->closing = true;
        $this->ws->close(1000, 'bot connection closing...');
        $this->emit('closed', [$this]);
    }

    public function onClose(int $op, string $reason)
    {
        $this->logger->info('websocket closed', ['op' => $op, 'reason' => $reason]);

        $this->connected = false;

        if (! is_null($this->heartbeatTimer)) {
            $this->heartbeatTimer->cancel();
            $this->heartbeatTimer = null;
        }

        if (! is_null($this->heartbeatAckTimer)) {
            $this->heartbeatAckTimer->cancel();
            $this->heartbeatAckTimer = null;
        }

        if ($this->closing) {
            return;
        }

        $this->logger->warning('websocket closed', ['op' => $op, 'reason' => $reason]);

        if ($op == self::CLOSE_INVALID_TOKEN) {
            $this->emit('error', [new \Exception('token is invalid'), $this]);
            $this->logger->error('the token you provided is invalid');

            return;
        }

        ++$this->reconnectCount;
        $this->reconnecting = true;
        $this->logger->info('starting reconnect', ['reconnect_count' => $this->reconnectCount]);
        $this->connectInternal();
    }

    public function onHello($data)
    {
        $this->logger->info('received hello');

        $resume = $this->identify(true);
        $this->logger->info('resume', [$resume]);

        if (!$resume){
            $this->setupHeartbeat($data->d->heartbeat_interval);
        }
    }

    public function onHeartbeatAck($data)
    {
        $received = microtime(true);
        $diff     = $received - $this->heartbeatTime;
        $time     = $diff * 1000;

        $this->heartbeatAckTimer->cancel();
        $this->emit('heartbeat-ack', [$time, $this]);
        $this->logger->debug('received heartbeat ack', ['response_time' => $time]);
    }

    private function setupHeartbeat($interval)
    {
        $this->heartbeatInterval = $interval;
        if (isset($this->heartbeatTimer)) {
            $this->heartbeatTimer->cancel();
        }

        $interval = $interval / 1000;
        $this->heartbeatTimer = $this->loop->addPeriodicTimer($interval, [$this, 'heartbeat']);
        $this->heartbeat();
    }
    public function heartbeat()
    {
        $payload = [
            'op' => self::OP_HEARTBEAT,
            'd'  => $this->seq,
        ];

        $this->send($payload);
        $this->heartbeatTime = microtime(true);
        $this->emit('heartbeat', [$this->seq, $this]);

        $this->heartbeatAckTimer = $this->loop->addTimer($this->heartbeatInterval / 1000, function () {
            if (! $this->connected) {
                return;
            }

            $this->logger->warning('did not receive heartbeat ACK within heartbeat interval, closing connection');
            $this->conn->close(1001, 'did not receive heartbeat ack');
        });
    }

    public function onDispatch($data)
    {
        $this->logger->info('dispatch: ' . $data->t);

        $this->emit($data->t, [$data->d, $this]);

        $ev = [
            //self::OP_VOICE_SERVER_UPDATE => '',
            //self::EV_RESUMED             => 'resume',
            self::EV_READY               => 'Ready',
            self::EV_GUILD_CREATE               => 'GuildCreate',
            //self::OP_GUILD_MEMBERS_CHUNK => '',
            //self::OP_VOICE_STATE_UPDATE  => '',
        ];
        if (isset($ev[$data->t])) {
            $this->{'on' . $ev[$data->t]}($data);
        }
    }

    public function onGuildCreate($data)
    {
        $guild = $data->d;
        $this->logger->info('guild create', (array)$guild->id);
        $this->guilds[$guild->id] = $guild;
        $this->checkReady();
    }

    private function checkReady()
    {
        $unavailable = [];
        foreach ($this->guilds as $guild){
            if ($guild->unavailable)
                $unavailable[$guild->id] = $guild->id;
        }

        if (count($unavailable) < 1) {
            return $this->ready();
        }
    }

    public function onReady($data)
    {
        $this->logger->debug('ready packet received');
        $content = $data->d;

        $this->sessionId = $content->session_id;
        $this->userId = $content->user->id;

        $this->guilds = [];
        foreach ($content->guilds as $guild){
            $this->guilds[$guild->id] = $guild;
        }

        $this->checkReady();
    }

    public function getId()
    {
        return $this->userId;
    }

    private function ready()
    {
        if ($this->emittedReady) {
            return false;
        }

        $this->logger->info('client is ready');
        $this->emit('ready', [$this]);
    }

    public function onInvalidSession($data)
    {
        $this->logger->warning('invalid session, re-identifying');
        $this->identify(false);
    }

    private function identify($resume)
    {
        $this->logger->info('identify called');

        $ret = false;
        if ($resume && $this->reconnecting && $this->sessionId){
            $payload = [
                'op' => self::OP_RESUME,
                'd'  => [
                    'session_id' => $this->sessionId,
                    'seq'        => $this->seq,
                    'token'      => $this->token,
                ],
            ];

            $this->logger->info('resuming connection', ['payload' => $payload]);
            $ret = true;

        }else{
            $payload = [
                'op' => self::OP_IDENTIFY,
                'd'  => [
                    'token'      => $this->token,
                    'properties' => [
                        '$os'               => PHP_OS,
                        '$browser'          => self::USER_AGENT,
                        '$device'           => self::USER_AGENT,
                        '$referrer'         => 'https://github.com/nishimura',
                        '$referring_domain' => 'https://github.com/nishimura',
                    ],
                    'compress' => true,
                ],
            ];

            $this->logger->info('identifying', ['payload' => $payload]);
        }

        $this->send($payload);
        return $ret;
    }

    private function send($data)
    {
        $json = json_encode($data);
        $this->ws->send($json);
    }

    public function sendMessage($channelId, $text, $updateId = null)
    {
        $message = new Message($this->logger, $this->token);
        return $message->send($channelId, $text, $updateId);
    }
}
