<?php

declare(strict_types=1);

namespace Distributable\Providers;

use Microservices\Providers\RpcServiceProvider;

/**
 * Base of the application's {Foundation}\FoundationServiceProvider: each contract of $rpc is bound
 * to the RpcService that calls its module, found from the foundation folder the contract is in.
 * A module answers its own contracts in its provider's $services.
 */
abstract class FoundationServiceProvider extends RpcServiceProvider {}
