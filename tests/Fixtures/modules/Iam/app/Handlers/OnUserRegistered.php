<?php

declare(strict_types=1);

namespace Modules\Iam\Handlers;

use Illuminate\Support\Facades\Context;
use Modules\Iam\Support\Recorder;
use Modulith\Contracts\Handler;

final class OnUserRegistered implements Handler
{
    public function __construct(private readonly Recorder $recorder) {}

    public function handle(string $name, array $payload): void
    {
        $this->recorder->records[] = [$name, $payload];
        $this->recorder->traces[] = Context::get('trace_id');
    }
}
