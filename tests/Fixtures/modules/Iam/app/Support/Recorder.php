<?php

declare(strict_types=1);

namespace Modules\Iam\Support;

final class Recorder
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $records = [];

    /** @var list<mixed> the trace id in context while each handler ran */
    public array $traces = [];
}
