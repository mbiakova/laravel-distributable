<?php

declare(strict_types=1);

namespace Apps\Iam\View\Components;

use Illuminate\View\Component;

final class Alert extends Component
{
    public function __construct(public string $message = 'from the class') {}

    public function render(): string
    {
        return 'iam::components.alert';
    }
}
