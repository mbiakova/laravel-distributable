<?php

declare(strict_types=1);

namespace Apps\Iam\Support;

final class Recorder
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $records = [];

    /** @var list<mixed> the trace id in context while each handler ran */
    public array $traces = [];

    /** @var list<string> the default connection while each handler ran */
    public array $connections = [];
}
