<?php

declare(strict_types=1);

// Every test file declares its own base case: the pure-unit ones use TestCase, the ones that
// need a booted application use ModuleAppTestCase. No folder-wide rule — a file-level uses()
// would silently conflict with it.
