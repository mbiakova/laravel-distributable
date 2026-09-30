<?php

declare(strict_types=1);

namespace Apps\Iam\Handlers;

use Apps\Iam\Support\Recorder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Modulith\Contracts\Stream\Handler;

final class OnUserRegistered implements Handler
{
    public function __construct(private readonly Recorder $recorder) {}

    public function handle(string $name, array $payload): void
    {
        $this->recorder->records[] = [$name, $payload];
        $this->recorder->traces[] = Context::get('trace_id');
        $this->recorder->connections[] = DB::getDefaultConnection();
    }
}
