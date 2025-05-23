<?php

declare(strict_types=1);

namespace RPurinton\ChatGPT;

use Discord\{
    Discord,
    Builders\MessageBuilder,
    Parts\Embed\Embed,
    Parts\Channel\Message,
    Parts\Interactions\Command\Command,
    Parts\Interactions\Interaction,
    Parts\User\Activity,
    WebSockets\Event,
    WebSockets\Intents,
};
use Monolog\{Level, Logger};
use Monolog\Handler\StreamHandler;
use React\Async;
use React\EventLoop\{Loop, LoopInterface};
use RPurinton\{Log, RabbitMQ};
use RPurinton\ChatGPT\{Commands, Splitter, Inbox};

class App
{
    const QUEUE = 'discord';
    protected RabbitMQ $mq;
    protected LoopInterface $loop;
    protected Discord $discord;
    protected Activity $activity;
    protected Logger $logger;
    protected array $commands = [];
    protected array $interactions = [];

    /**
     * App constructor.
     * @throws \RuntimeException if DISCORD_APP_TOKEN is not set
     */
    public function __construct()
    {
        $this->commands = CommandLoader::get();
        $this->loop = Loop::get();
        $this->logger = new Logger('chatgpt-app');
        $this->logger->pushHandler(new StreamHandler('php://stdout', Level::Error));
        $this->mq = RabbitMQ::connect(self::QUEUE, 'direct', self::QUEUE, $this->callback(...), $this->loop);
        $token = getenv('DISCORD_APP_TOKEN');
        if (empty($token)) {
            $this->logger->error('DISCORD_APP_TOKEN not set');
            throw new \RuntimeException('DISCORD_APP_TOKEN not set');
        }
        $this->discord = new Discord([
            'token'   => $token,
            'loop'    => $this->loop,
            'logger'  => $this->logger,
            'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT,
        ]);
        $this->activity = new Activity($this->discord, [
            'name' => "AI Language Model",
            'type' => Activity::TYPE_GAME,
        ]);
        $this->discord->on('init', $this->init(...));
        $this->logger->info('App constructed');
    }

    /**
     * Connect and run the Discord bot.
     */
    public static function connect(): void
    {
        (new self)->run();
    }

    /**
     * Run the Discord event loop.
     */
    public function run(): void
    {
        $this->logger->info('App running');
        $this->discord->run();
    }

    /**
     * Initialize event handlers and presence.
     */
    protected function init(): void
    {
        Log::notice('chatgpt-app initialized');
        $this->discord->on(Event::MESSAGE_CREATE, $this->message_create(...));
        $this->discord->updatePresence($this->activity);
        //$this->unregister_cmds();
        //$this->register_cmds();
        $this->register_handlers();
    }

    /**
     * Handle new message events.
     */
    protected function message_create(Message $message): void
    {
        if ($message->author->id === $this->discord->id) return;
        if (
            null === $message->guild ||
            $message->mentions->has($this->discord->application->id) ||
            ($message->referenced_message && $message->referenced_message->author->id === $this->discord->application->id)
        ) {
            $message->channel->getMessageHistory(['limit' => 100])
                ->then(function ($messages) use ($message) {
                    Inbox::publish([
                        'type' => 'message_create',
                        'channel_id' => $message->channel_id,
                        'messages' => $messages,
                    ]);
                })
                ->catch(function (\Throwable $e) {
                    Log::error("Failed to fetch message history: " . $e->getMessage());
                });
        }
    }

    /**
     * Callback for RabbitMQ messages.
     */
    public function callback(string $message): bool
    {
        $messageArray = json_decode($message, true);
        if (!is_array($messageArray) || !isset($messageArray['type'])) return false;
        return match ($messageArray['type']) {
            'send_message' => $this->send_message($messageArray),
            'start_typing' => $this->start_typing($messageArray),
            'interaction_reply' => $this->interaction_reply($messageArray),
            default => true,
        };
    }

    /**
     * Send a message to a Discord channel.
     */
    private function send_message(array $message): bool
    {
        $channel = $this->getChannelById($message['channel_id'] ?? null);
        if (!$channel) {
            Log::error("discord-app::send_message() channel not found");
            return true;
        }
        foreach (Splitter::split($message['content'] ?? '') as $msg) {
            Async\await($channel->sendMessage($msg));
        }
        return true;
    }

    /**
     * Start typing indicator in a Discord channel.
     */
    private function start_typing(array $message): bool
    {
        $channel = $this->getChannelById($message['channel_id'] ?? null);
        if (!$channel) {
            Log::error("discord-app::start_typing() channel not found");
            return true;
        }
        $channel->broadcastTyping();
        return true;
    }

    /**
     * Publish a message to RabbitMQ.
     */
    public static function publish(array $message): void
    {
        RabbitMQ::publish(self::QUEUE, 'direct', json_encode($message));
    }

    /**
     * Unregister all Discord commands.
     */
    private function unregister_cmds(): void
    {
        $old_cmds = Async\await($this->discord->application->commands->freshen());
        foreach ($old_cmds as $old_cmd) {
            Async\await($this->discord->application->commands->delete($old_cmd));
        }
    }

    /**
     * Register all Discord commands.
     */
    private function register_cmds(): void
    {
        foreach ($this->commands as $command) {
            $perms = isset($command['perms']) ? $command['perms'] : 0;
            unset($command['perms']);
            $slashcommand = new Command($this->discord, $command);
            if (!empty($perms)) $slashcommand->setDefaultMemberPermissions($perms);
            $this->discord->application->commands->save($slashcommand);
        }
    }

    /**
     * Register command handlers.
     */
    private function register_handlers(): void
    {
        foreach ($this->commands as $command) {
            $this->discord->listenCommand($command["name"], $this->interaction(...));
        }
    }

    /**
     * Handle a Discord interaction.
     */
    private function interaction(Interaction $interaction): void
    {
        Log::debug('DiscordClient->interaction()', ['interaction' => $interaction]);
        $interaction->acknowledgeWithResponse(true);
        $this->interactions[$interaction->id] = $interaction;
        Inbox::publish([
            "type" => 'interaction_handle',
            "interaction" => $interaction,
        ]);
    }

    /**
     * Reply to a Discord interaction.
     */
    private function interaction_reply(array $data): bool
    {
        Log::debug("DiscordClient->interaction_reply()", ['data' => $data]);
        if (empty($data['interaction_id']) || empty($data['content'])) {
            Log::warn("DiscordClient->interaction_reply() - Invalid interaction format");
            return true;
        }
        if (!isset($this->interactions[$data['interaction_id']])) {
            Log::warn("DiscordClient->interaction_reply() - Interaction not found: {$data['interaction_id']}");
            return true;
        }
        $this->interactions[$data['interaction_id']]->updateOriginalResponse($this->message_builder($data));
        unset($this->interactions[$data['interaction_id']]);
        return true;
    }

    /**
     * Build a Discord message.
     */
    private function message_builder(array $message): MessageBuilder
    {
        $mb = MessageBuilder::new();
        if (!empty($message['content'])) {
            $mb->setContent($message['content']);
        }
        // Possible Future Use
        if (!empty($message['embeds']) && is_array($message['embeds'])) {
            foreach ($message['embeds'] as $embed) {
                if (!empty($embed['type']) && $embed['type'] === 'rich') {
                    $new_embed = new Embed($this->discord);
                    $new_embed->fill($embed);
                    $mb->addEmbed($new_embed);
                }
            }
        }
        return $mb;
    }

    /**
     * Get a Discord channel by ID, or return null if not found.
     */
    private function getChannelById($channelId): ?object
    {
        if (empty($channelId)) return null;
        return $this->discord->getChannel((string)$channelId);
    }

    /**
     * Desctructor to clean up resources.
     */
    public function __destruct()
    {
        $this->logger->info('App destructed');
        $this->discord->close();
        $this->loop->stop();
    }
}
