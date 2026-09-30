<?php

declare(strict_types=1);

use Apps\Iam\Events\UserRegistered;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Modulith\Data\Envelope;
use Modulith\Services\Stream\TransportManager;
use Modulith\Tests\Support\ModuleAppTestCase;
use Modulith\Transports\Stream\RedisStreamTransport;

uses(ModuleAppTestCase::class);

beforeEach(function () {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('No Redis server reachable.');
    }

    config()->set('streamer.streams.default.driver', 'redis');
    config()->set('streamer.streams.default.prefix', 'modulith-test:'.Str::random(8).':');
    config()->set('streamer.streams.default.block', 100);
});

afterEach(function () {
    $prefix = (string) config('streamer.streams.default.prefix');

    foreach (['iam'] as $channel) {
        Redis::connection()->command('del', [$prefix.$channel]);
    }
});

function redisTransport(): RedisStreamTransport
{
    /** @var RedisStreamTransport */
    return app(TransportManager::class)->stream();
}

/** @return list<int> */
function consumeAll(RedisStreamTransport $transport, string $consumer, int $expected): array
{
    $seen = [];

    $transport->consume($consumer, ['iam'], function (Envelope $envelope) use (&$seen, $transport, $expected): void {
        $seen[] = (int) $envelope->payload['id'];

        if (count($seen) === $expected) {
            $transport->stop();
        }
    });

    return $seen;
}

it('delivers the published envelopes in order to each consumer group', function () {
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect(consumeAll($transport, 'analytics', 2))->toBe([1, 2])
        ->and(consumeAll(redisTransport(), 'iam', 2))->toBe([1, 2])
        ->and(Redis::connection()->command('xpending', [config('streamer.streams.default.prefix').'iam', 'analytics'])[0])->toBe(0);
});

it('replays an entry a dead consumer left unacknowledged', function () {
    config()->set('streamer.streams.default.claim_after', 0);
    $transport = redisTransport();
    $stream = config('streamer.streams.default.prefix').'iam';
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));

    Redis::connection()->command('xgroup', ['CREATE', $stream, 'analytics', '0', true]);
    Redis::connection()->command('xreadgroup', ['analytics', 'dead-consumer', [$stream => '>'], 10]);

    expect(consumeAll($transport, 'analytics', 1))->toBe([1])
        ->and(Redis::connection()->command('xpending', [$stream, 'analytics'])[0])->toBe(0);
});

it('trims only the entries every consumer group has acknowledged', function () {
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect($transport->trim('iam'))->toBe(0);

    consumeAll($transport, 'analytics', 2);

    expect($transport->trim('iam'))->toBe(1)
        ->and((int) Redis::connection()->command('xlen', [config('streamer.streams.default.prefix').'iam']))->toBe(1);
});

it('tells an entry acknowledged only once every consumer group has read it', function () {
    $transport = redisTransport();
    $first = $transport->publishTracked(Envelope::for(new UserRegistered(1, 'a'), 'iam'));

    expect($transport->isAcknowledged('iam', $first))->toBeFalse();

    consumeAll($transport, 'analytics', 1);
    Redis::connection()->command('xgroup', ['CREATE', config('streamer.streams.default.prefix').'iam', 'iam', '$', true]);
    $second = $transport->publishTracked(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect($transport->isAcknowledged('iam', $first))->toBeTrue()
        ->and($transport->isAcknowledged('iam', $second))->toBeFalse();

    consumeAll($transport, 'analytics', 1);
    consumeAll($transport, 'iam', 1);

    expect($transport->isAcknowledged('iam', $second))->toBeTrue();
});
