# 存储适配器

引擎通过一个接口进行持久化：`Dskripchenko\ProcessEngine\Store\Store`。核心包提供
`InMemoryStore`（测试、脚本）；Laravel 桥接包提供支持 PostgreSQL、MySQL / MariaDB 和
SQLite 的数据库存储。如需运行在其他存储上，可以自行编写。

## 契约要求

- **`transaction(callable)`**——单步的工作单元：其中写入的所有内容要么一起提交，要么
  都不提交；嵌套调用加入外层事务。
- **隔离（fencing）**——`updateInstance($record, $expectedSeq)` 仅当已存储的 `seq`
  等于 `$expectedSeq` 时才写入，否则返回 `false`。正是这一点让租约已过期的 worker
  无法造成危害。
- **比较并设置（compare-and-set）**——`acquireLease()`、`claimEffect()` 和
  `fireTimer()` 是原子条件更新（`UPDATE … WHERE status = ? …`），各自独立提交。
- **幂等插入**——`insertInbox()`（每个实例和键唯一）、`insertEffect()` 和
  `insertTimer()`（键唯一）、`insertMessage()`（事件 + 关联键 + 键唯一）、
  `insertInstance()`（id 唯一，业务键在流程内唯一）在遇到重复时返回 `false`，而不是
  抛出异常。
- **顺序**——`nextInbox()` 按到达顺序返回待处理事件；`appendJournal()` 分配递增的
  `seq`。
- **查询**——按状态（status）、流程、版本、状态、业务键、主体（subject）、事故查询
  实例；到期的定时器和副作用；已过期的租约；可归档的实例。

不需要任何特定于数据库的锁机制：条件更新和唯一键在所有 SQL 数据库上都已足够。

## 表（数据库存储）

| 表 | 存放内容 |
|---|---|
| `definitions` | 已存储的版本：名称、版本、哈希、规范 JSON |
| `instances` | 快照、seq、租约、父实例、业务键、主体 |
| `active_states` | 以行形式存储的活动状态，用于带索引的“处于状态 X 的实例”查询 |
| `inbox` | 事件及其键和状态 |
| `effects` | outbox：键、类、语义、策略、状态、尝试次数、租约、结果 |
| `timers` | 键、事件、时刻、状态 |
| `subscriptions`, `messages` | 关联以及提前到达事件的缓冲区 |
| `incidents`, `journal` | |
| `archive` | 每个已结束实例一个压缩文档 |

## 规模

设计目标约为 100,000 个活动实例和 1000 万个已归档实例：热表只保存活动实例和最近结束
的实例，一个已归档实例只占一行。活动实例的日志按 `(instance_id, seq)` 分页。

## SQLite 注意事项

使用多个 worker 时，请为连接配置 `transaction_mode = IMMEDIATE` 和 WAL：升级为写操作
的延迟事务会以 "database is locked" 失败，而不是等待。单个 worker 或测试场景下，默认
配置即可。
