<?php

declare(strict_types=1);

namespace Distributable\Support;

use Illuminate\Console\Scheduling\Event;
use WeakMap;

/** The module each scheduled task was declared by, so schedule:run runs it in that module. */
final class ScheduledTasks
{
    /** @var WeakMap<Event, string> */
    private WeakMap $modules;

    public function __construct()
    {
        $this->modules = new WeakMap;
    }

    public function assign(Event $task, string $module): void
    {
        $this->modules[$task] = $module;
    }

    public function moduleOf(Event $task): ?string
    {
        return $this->modules[$task] ?? null;
    }
}
