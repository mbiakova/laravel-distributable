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

    config()->set('modulith.events.streams.default.driver', 'redis');
    config()->set('modulith.events.streams.default.key', 'modulith-test:'.Str::random(8));
    config()->set('modulith.events.streams.default.block', 100);
});

afterEach(function () {
    Redis::connection()->command('del', [(string) config('modulith.events.streams.default.key')]);
});

function redisTransport(): RedisStreamTransport
{
    /** @var RedisStreamTransport */
    return app(TransportManager::class)->stream();
}

/**
 * @param  list<string>  $channels
 * @return list<int>
 */
function consumeAll(RedisStreamTransport $transport, string $consumer, int $expected, array $channels = ['iam', 'analytics']): array
{
    $seen = [];

    $transport->consume($consumer, $channels, function (Envelope $envelope) use (&$seen, $transport, $expected): void {
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
        ->and(Redis::connection()->command('xpending', [config('modulith.events.streams.default.key'), 'analytics'])[0])->toBe(0);
});

it('keeps one order across every emitting module, in the single key of the stream', function () {
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'analytics'));
    $transport->publish(Envelope::for(new UserRegistered(3, 'c'), 'iam'));

    expect(consumeAll($transport, 'gateway', 3))->toBe([1, 2, 3])
        ->and((int) Redis::connection()->command('xlen', [config('modulith.events.streams.default.key')]))->toBe(3);
});

it('acknowledges unread the entries of an emitter the consumer does not listen to', function () {
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'analytics'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect(consumeAll($transport, 'gateway', 1, ['iam']))->toBe([2])
        ->and(Redis::connection()->command('xpending', [config('modulith.events.streams.default.key'), 'gateway'])[0])->toBe(0);
});

/** @return list<int> the ids handled, in order, until $last is; the first attempt at $failing throws */
function consumeFailingOnce(string $failing, int $last): array
{
    $transport = redisTransport();
    $seen = [];
    $failed = false;

    $transport->consume('analytics', ['iam'], function (Envelope $envelope) use (&$seen, &$failed, $failing, $last, $transport): void {
        $id = (int) $envelope->payload['id'];

        if ($id === (int) $failing && ! $failed) {
            $failed = true;

            throw new RuntimeException('handler failed');
        }

        $seen[] = $id;

        if ($id === $last) {
            $transport->stop();
        }
    });

    return $seen;
}

it('retries a failed entry before any later one when the stream blocks on failure', function () {
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect(consumeFailingOnce('1', 2))->toBe([1, 2]);
});

it('goes on past a failed entry, left pending for later, when the stream skips on failure', function () {
    config()->set('modulith.events.streams.default.on_failure', 'skip');
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect(consumeFailingOnce('1', 2))->toBe([2])
        ->and(Redis::connection()->command('xpending', [config('modulith.events.streams.default.key'), 'analytics'])[0])->toBe(1);
});

it('replays an entry a dead consumer left unacknowledged', function () {
    config()->set('modulith.events.streams.default.claim_after', 0);
    $transport = redisTransport();
    $key = config('modulith.events.streams.default.key');
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));

    Redis::connection()->command('xgroup', ['CREATE', $key, 'analytics', '0', true]);
    Redis::connection()->command('xreadgroup', ['analytics', 'dead-consumer', [$key => '>'], 10]);

    expect(consumeAll($transport, 'analytics', 1))->toBe([1])
        ->and(Redis::connection()->command('xpending', [$key, 'analytics'])[0])->toBe(0);
});

it('trims only the entries every consumer group has acknowledged', function () {
    $transport = redisTransport();
    $transport->publish(Envelope::for(new UserRegistered(1, 'a'), 'iam'));
    $transport->publish(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect($transport->trim())->toBe(0);

    consumeAll($transport, 'analytics', 2);

    expect($transport->trim())->toBe(1)
        ->and((int) Redis::connection()->command('xlen', [config('modulith.events.streams.default.key')]))->toBe(1);
});

it('tells an entry acknowledged only once every consumer group has read it', function () {
    $transport = redisTransport();
    $first = $transport->publishTracked(Envelope::for(new UserRegistered(1, 'a'), 'iam'));

    expect($transport->isAcknowledged($first))->toBeFalse();

    consumeAll($transport, 'analytics', 1);
    Redis::connection()->command('xgroup', ['CREATE', config('modulith.events.streams.default.key'), 'iam', '$', true]);
    $second = $transport->publishTracked(Envelope::for(new UserRegistered(2, 'b'), 'iam'));

    expect($transport->isAcknowledged($first))->toBeTrue()
        ->and($transport->isAcknowledged($second))->toBeFalse();

    consumeAll($transport, 'analytics', 1);
    consumeAll($transport, 'iam', 1);

    expect($transport->isAcknowledged($second))->toBeTrue();
});
