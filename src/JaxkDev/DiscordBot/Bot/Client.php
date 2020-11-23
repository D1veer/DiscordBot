<?php
/*
 * DiscordBot, PocketMine-MP Plugin.
 *
 * Licensed under the Open Software License version 3.0 (OSL-3.0)
 * Copyright (C) 2020 JaxkDev
 *
 * Twitter :: @JaxkDev
 * Discord :: JaxkDev#2698
 * Email   :: JaxkDev@gmail.com
 */

namespace JaxkDev\DiscordBot\Bot;

use Carbon\Carbon;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\Activity;
use Discord\Parts\User\Member;
use ErrorException;
use Exception;
use JaxkDev\DiscordBot\Communication\BotThread;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use pocketmine\utils\MainLogger;
use React\EventLoop\TimerInterface;

class Client {
	/**
	 * @var BotThread
	 */
	private $thread;

	/**
	 * @var Discord
	 */
	private $client;

	/**
	 * @var bool
	 */
	private $ready = false;

	/**
	 * @var bool
	 */
	private $closed = false;

	/**
	 * @var TimerInterface|null
	 */
	private $readyTimer;

	/**
	 * @var array
	 */
	private $config;

	public function __construct(BotThread $thread, array $config) {
		$this->thread = $thread;
		$this->config = $config;

		gc_enable();

		error_reporting(E_ALL & ~E_NOTICE);
		set_error_handler(array($this, 'errorHandler'));
		register_shutdown_function(array($this, 'close'));

		$logger = new Logger('DiscordPHP');
		$handler = new RotatingFileHandler($config['logging']['directory'].DIRECTORY_SEPARATOR."DiscordBot.log", $config['logging']['maxFiles'], Logger::DEBUG);
		$handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');
		$logger->setHandlers(array($handler));

		// TODO ONLY IF DEBUG ENABLED:
		$handler = new StreamHandler(($r = fopen('php://stdout', 'w')) === false ? "" : $r);
		$logger->pushHandler($handler);

		// No intents specified yet so IntentException is impossible.
		/** @noinspection PhpUnhandledExceptionInspection */
		$this->client = new Discord([
			'token' => $config['discord']['token'],
			'logger' => $logger
		]);
		$this->config['discord']['token'] = "REDACTED";

		$this->registerHandlers();
		$this->registerTimers();

		$this->client->run();
	}

	private function registerTimers(): void{
		// Handles shutdown.
		$this->client->getLoop()->addPeriodicTimer(1, function(){
			if($this->thread->isStopping()){
				$this->close();
			}
		});

		// Handles any problems pre-ready.
		$this->readyTimer = $this->client->getLoop()->addTimer(30, function(){
			if($this->client->id !== null){
				MainLogger::getLogger()->warning("Client has taken >30s to get ready, is your discord server large ?");
				$this->client->getLoop()->addTimer(30, function(){
					if(!$this->ready) {
						MainLogger::getLogger()->critical("Client has taken too long to become ready, shutting down.");
						$this->close();
					}
				});
			} else {
				MainLogger::getLogger()->critical("Client failed to login/connect within 30 seconds, See log file for details.");
				$this->close();
			}
		});

		// TODO 'Ticking' Communication handling array of data inbound.
	}

	/** @noinspection PhpUnusedParameterInspection */
	private function registerHandlers(): void{
		// https://github.com/teamreflex/DiscordPHP/issues/433
		// Note ready is emitted after successful connection + all servers/users loaded.
		$this->client->on('ready', function (Discord $discord) {
			if($this->readyTimer !== null) {
				$this->client->getLoop()->cancelTimer($this->readyTimer);
				$this->readyTimer = null;
			}
			$this->ready = true;
			MainLogger::getLogger()->info("Client ready.");

			$this->logDebugInfo();
			$this->updatePresence($this->config['discord']['presence']['text'], $this->config['discord']['presence']['type']);

			// Listen for messages.
			$discord->on('message', function (Message $message, Discord $discord) {
				if($message->author instanceof Member ? $message->author->user->bot : $message->author->bot){
					//Ignore Bot's (including self)
					return;
				}

				if($message->content[0] === "!"){
					$args = explode(" ", $message->content);
					$cmd = substr(array_shift($args), 1);
					switch($cmd){
						case 'version':
						case 'ver':
							$message->channel->sendMessage("Version information:```\n".
								"> PHP - v".PHP_VERSION."\n".
								"> DiscordPHP - ".Discord::VERSION."\n".
								"> PocketMine - v".\pocketmine\VERSION."\n".
								"> DiscordBot - ".\JaxkDev\DiscordBot\VERSION."```"
							)->otherwise(function($e) use($message) {
								MainLogger::getLogger()->logException($e);
								// At least try a static message, if this fails client probably only has read-only perms
								// In that channel.
								$message->channel->sendMessage("**ERROR** Failed to send version information...");
							});
							break;
						case 'ping':
							$message->channel->sendMessage("Difference: ".(Carbon::now()->valueOf()-$message->timestamp->valueOf())."ms");
							break;
					}
				}
			});
		});
	}

	public function updatePresence(string $text, int $type): bool{
		/** @var Activity $presence */
		$presence = $this->client->factory(Activity::class, [
			'name' => $text,
			'type' => $type
		]);

		try {
			$this->client->updatePresence($presence);
			return true;
		} catch (Exception $e) {
			return false;
		}
	}

	public function logDebugInfo(): void{
		MainLogger::getLogger()->debug("Debug Information:\n".
			"> Username: {$this->client->username}#{$this->client->discriminator}\n".
			"> ID: {$this->client->id}\n".
			"> Servers: {$this->client->guilds->count()}\n".
			"> Users: {$this->client->users->count()}"
		);
	}

	public function errorHandler(int $severity, string $message, string $file, int $line): bool{
		if(substr($message,0,61) === "stream_socket_client(): unable to connect to udp://8.8.8.8:53"){
			// Really nasty hack to check if connection failed on bot construction,
			// Could manually ping discord API before ?
			// Really need to fork/fix the shit in DiscordPHP...
			MainLogger::getLogger()->emergency("Failed to connect to udp://8.8.8.8:53, please check your internet connection.");
		} else {
			MainLogger::getLogger()->logException(new ErrorException($message, 0, $severity, $file, $line));
		}
		$this->close();
		return true;
	}

	public function close(): void{
		if($this->closed) return;
		if($this->client instanceof Discord) $this->client->close(true);
		$this->closed = true;
		MainLogger::getLogger()->debug("Client closed.");
		exit(0);
	}
}