<?php

declare(strict_types=1);

namespace RPurinton\ChatGPT;

use React\EventLoop\Loop;
use RPurinton\{Log, RabbitMQ, OpenAI, MySQL};
use RPurinton\ChatGPT\Commands;

class Inbox
{
    const QUEUE = 'inbox';
    const MODEL = 'gpt-4o-mini';

    private string $api_key;
    private OpenAI $ai;
    private MySQL $sql;
    private RabbitMQ $mq;
    private array $locales;

    /**
     * Inbox constructor.
     * @throws \RuntimeException if required dependencies are not available
     */
    public function __construct()
    {
        $this->sql = MySQL::connect();
        if (null === $this->sql) {
            Log::error('chatgpt-inbox->__construct()', ['error' => 'MySQL connection failed']);
            throw new \RuntimeException('MySQL connection failed');
        }
        $this->locales = Locales::get();
        if (null === $this->locales) {
            Log::error('chatgpt-inbox->__construct()', ['error' => 'Locales not found']);
            throw new \RuntimeException('Locales not found');
        }
        $this->api_key = getenv('OPENAI_API_KEY') ?: throw new \RuntimeException('OPENAI_API_KEY environment variable is not set.');
        $this->ai = OpenAI::connect($this->api_key);
        if (null === $this->ai) {
            Log::error('chatgpt-inbox->__construct()', ['error' => 'OpenAI connection failed']);
            throw new \RuntimeException('OpenAI connection failed');
        }
        $this->mq = RabbitMQ::connect(
            self::QUEUE,            // Exchange Name (same as Queue)
            'direct',               // Exchange Type
            self::QUEUE,            // Queue Name
            $this->callback(...),   // Callback function
            Loop::get(),            // Event loop
            false,                  // passive
            true,                   // durable
            false,                  // exclusive
        );
        Log::info('chatgpt-inbox constructed');
    }

    /**
     * Connect and run the Inbox service.
     */
    public static function connect(): void
    {
        (new self)->run();
    }

    /**
     * Start the Inbox event loop.
     */
    public function run(): void
    {
        Log::notice('chatgpt-inbox initialized and running');
    }

    /**
     * Callback for RabbitMQ messages.
     * @param string $message
     * @return void
     */
    public function callback(string $message): void
    {
        $decoded = json_decode($message, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('chatgpt-inbox->callback()', ['error' => json_last_error_msg()]);
            return;
        }
        if (!isset($decoded['type'])) {
            Log::error('chatgpt-inbox->callback()', ['error' => 'Message type not set', 'message' => $decoded]);
            return;
        }
        switch ($decoded['type']) {
            case 'message_create':
                $this->message_create((string)($decoded['channel_id'] ?? ''), $decoded['messages'] ?? []);
                break;
            case 'interaction_handle':
                $this->interaction_handle($decoded['interaction'] ?? []);
                break;
            default:
                Log::error('chatgpt-inbox->callback()', ['error' => 'Unknown message type', 'type' => $decoded['type']]);
        }
    }

    /**
     * Handle a new message_create event.
     * @param string $channel_id
     * @param array $messages
     * @return void
     */
    public function message_create(string $channel_id, array $messages): void
    {
        Log::debug('chatgpt-inbox->message_create()', ['channel_id' => $channel_id, 'messages_count' => count($messages)]);
        // TODO: Implement message processing logic
    }

    /**
     * Handle an interaction event.
     * @param array $interaction
     * @return void
     */
    public function interaction_handle(array $interaction): void
    {
        $command_name = $interaction['data']['name'] ?? null;
        if (null === $command_name) {
            Log::error('chatgpt-inbox->interaction_handle()', ['error' => 'Command name not set', 'interaction' => $interaction]);
            return;
        }
        // Only allow server-related commands in guild channels, not DMs
        $is_dm = empty($interaction['guild_id']);
        $server_commands = [
            'allow', 'settings', 'dedicate'
        ];
        if ($is_dm && in_array($command_name, $server_commands, true)) {
            $reply = [
                'type' => 'interaction_reply',
                'interaction_id' => $interaction['id'] ?? null,
                'content' => 'This command is only available in servers.'
            ];
            App::publish($reply);
            return;
        }
        $handler_map = [
            'allow' => Commands\Allow::class,
            'settings' => Commands\Settings::class,
            'help' => Commands\Help::class,
            'dedicate' => Commands\Dedicate::class
        ];
        if (isset($handler_map[$command_name])) {
            $handler = new $handler_map[$command_name]($this->sql, $this->locales, $interaction);
            $handler->handle();
        } else {
            Log::error('chatgpt-inbox->interaction_handle()', ['error' => 'Unknown command name', 'command' => $command_name]);
        }
    }

    /**
     * Publish a message to the Inbox queue.
     * @param array $message
     * @return void
     */
    public static function publish(array $message): void
    {
        RabbitMQ::publish(self::QUEUE, 'direct', json_encode($message), false, true, false);
    }
}
