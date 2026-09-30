# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Module discovery by `modulith.php` manifest, `WITH_MODULES` locality, pluggable `Contracts\Source`.
- `Providers\ModuleServiceProvider`: module config deep-merge, routes per file (`{module}/{file}`),
  migrations, translations, commands.
- Opt-in status route listing the modules the process boots (`modulith.status_route`).
- A database per module: `Models\Model`, `Traits\TransactsOnModule`, `modulith:migrate`.
- Typed configuration, one reader per scope: `Config\Modules`, `Streamer`, `RedisStream`,
  `QueueStream`, `Rpc` — the only places the package reads its config.
- Calls between modules: a contract bound to its local implementation when the module runs in
  the process, to a remote one otherwise; signed HTTP transport, `rpc` middleware group.
- Events: owned envelope format, `redis` / `queue` / `array` / `null` transports behind an
  extensible manager, ordered transactional outbox, consumption guard, `modulith:events:publish`,
  `modulith:events:republish`, `modulith:events:consume`, `modulith:events:trim` (drops only
  what every consumer group acknowledged).
- Context propagation: chosen `Context` keys travel in the envelope headers (`streamer.propagate`).
- Read-only copies of another module's rows: `Models\ShadowModel`, `Traits\ShadowSource`,
  shadow migrations, `modulith:shadows:announce`, `modulith:shadows:want`.
