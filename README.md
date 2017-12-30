DiscordUtil
===========


## Example


```php
<?php

// bot.php

ini_set('display_errors', '1');
error_reporting(-1);
set_time_limit(0);

if (php_sapi_name() !== 'cli') {
    trigger_error('DiscordPHP will not run on a webserver. Please use PHP CLI to run a DiscordPHP bot.', E_USER_ERROR);
}


if (function_exists('pcntl_async_signals')){
    pcntl_async_signals(true);
}


require_once __DIR__ . '/vendor/autoload.php';

$logger = new Monolog\Logger('mybot');
$loglevel = Monolog\Logger::WARNING;
$loglevel = Monolog\Logger::DEBUG;
$logfile = __DIR__ . '/runbot.log';

$logger->pushHandler(new Monolog\Handler\StreamHandler($logfile, $loglevel));

$token = getenv('PHP_DISCORD_BOT_TOKEN');
$bot = new DiscordUtil\Bot($logger, $token);

$bot->on('ready', function(){
    var_dump('*** ready ***');
});
$bot->on('error', function($e, $bot){
    var_dump('error');
    throw $e;
});
$bot->on('MESSAGE_CREATE', function($data, $bot) use ($logger, $token) {
    $id = $bot->getId();
    if ($data->author->id == $id)
        return;

    var_dump([$data->author->username => $data->content]);

    foreach ($data->mentions as $mention){
        if ($mention->id == $id){
            $text = '<@' . $data->author->id . "> Yea!\n..." ;
            $channelId = $data->channel_id;

            $ret = $bot->sendMessage($channelId, $text);
            //
            // or
            //
            // $ret = (new DiscordUtil\Message($logger, $token))
            //      ->send($data->channel_id, $text);

            sleep(1);
            $bot->sendMessage($channelId,
                              str_replace('...', ":grinning:", $ret->content),
                              $ret->id);
        }
    }
});

if (function_exists('pcntl_async_signals')){
    $shutdown = function($signo, $siginfo) use ($bot) {
        echo "\nclosing...\n";
        $bot->close();
    };
    pcntl_signal(SIGTERM, $shutdown);
    pcntl_signal(SIGINT, $shutdown);
}


$bot->connect()->run();
```


```
composer require "nishimura/discordutil:0.0.*"
composer require monolog/monolog

export PHP_DISCORD_BOT_TOKEN=' *** API KEY *** '
php bot.php
```
