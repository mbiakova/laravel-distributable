<?php

declare(strict_types=1);

namespace Modulith\Contracts;

/** A handler whose replay changes nothing by itself: it runs without the consumption guard. */
interface Idempotent {}
