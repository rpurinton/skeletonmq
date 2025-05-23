<?php

declare(strict_types=1);

namespace RPurinton\Skeleton;

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
use RPurinton\Log;
use RPurinton\Skeleton\CommandLoader;
use RPurinton\Skeleton\Exceptions\AppException;

class App
{
    protected LoopInterface $loop;
    protected Discord $discord;
    protected Activity $activity;
    protected Logger $logger;
    protected array $locales = [];
    protected array $commands = [];

    /**
     * Map command names to their handler classes.
     * Extend this as you add more commands.
     */
    private array $handler_map = [
        'help' => \RPurinton\Skeleton\Commands\Help::class,
        // Add more command mappings here as needed
    ];

    /**
     * App constructor.
     * @throws \RuntimeException if DISCORD_APP_TOKEN is not set
     */
    public function __construct()
    {
        $this->loop = Loop::get();
        $this->locales = Locales::get();
        $this->commands = CommandLoader::get();
        $level = Level::fromName(strtoupper(Log::$logLevel));
        $this->logger = new Logger('skeleton');
        $this->logger->pushHandler(new StreamHandler('php://stdout', $level));
        $token = getenv('DISCORD_APP_TOKEN');
        if (empty($token) || $token === 'your_discord_bot_token_here') {
            throw new AppException('DISCORD_APP_TOKEN not set');
        }
        $this->discord = new Discord([
            'token'   => $token,
            'loop'    => $this->loop,
            'logger'  => $this->logger,
            'intents' => Intents::getDefaultIntents() // | Intents::MESSAGE_CONTENT
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
        Log::info('App running');
        $this->discord->run();
    }

    /**
     * Initialize event handlers and presence.
     */
    protected function init(): void
    {
        Log::notice('App Initialized');
        $this->discord->updatePresence($this->activity);
        //$this->unregister_cmds();
        $this->register_cmds();
        $this->register_handlers();
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
     * Handle incoming interactions.
     *
     * @param Interaction $interaction The interaction object.
     */
    private function interaction(Interaction $interaction): void
    {
        Log::debug('Interaction received', ['interaction' => $interaction]);
        $interaction->acknowledge();

        $command_name = $interaction->data->name ?? null;
        if (!$command_name) {
            $interaction->respondWithMessage('Unknown command.', true);
            return;
        }

        $handler_class = $this->handler_map[$command_name] ?? null;
        if ($handler_class && class_exists($handler_class)) {
            $handler = new $handler_class($this->locales, $interaction);
            $handler->handle();
            return;
        }
        $interaction->respondWithMessage('No handler for this command.', true);
    }

    /**
     * Desctructor to clean up resources.
     */
    public function __destruct()
    {
        Log::info('App Destructed');
        $this->discord->close();
        $this->loop->stop();
    }
}
