<?php

namespace RPurinton\SkeletonMQ\Commands;

use RPurinton\MySQL;
use Discord\Parts\Interactions\Interaction;

abstract class CommandHandler
{
    public function __construct(protected array $locales, protected Interaction $interaction) {}

    abstract public function handle(): void;
}
