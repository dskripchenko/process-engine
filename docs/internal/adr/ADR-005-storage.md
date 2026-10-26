# ADR-005. Хранение: снапшот как источник истины + журнал как аудит

- Статус: принято автором 2026-10-26
- Дата: 2026-10-26

## Контекст

Варианты: (a) только снапшот; (b) event sourcing — состояние = свёртка
журнала; (c) снапшот + журнал. Требования: подъём после падения (R3),
аудит, «почему здесь», история, кто что ввёл (R6), версионирование (R5),
ручной перевод в состояние (R7).

## Решение

**Вариант (c).** Снапшот экземпляра — авторитетное состояние для исполнения;
журнал — append-only история, пишется в той же транзакции, что и снапшот.

Журнал **не воспроизводится** для восстановления состояния. Причины:
- воспроизведение требует детерминизма guards/actions на всю жизнь версии
  (ловушка Temporal-патчинга);
- ручные операции и миграции (перевод, правка контекста, смена версии) —
  не результат «повторного шага», их проще фиксировать как факт;
- снапшот читается одной строкой — «где сейчас» без свёрток.

При этом журнал достаточно полон, чтобы **реконструировать** состояние на
любой момент (снапшот после каждой записи вычислим по `context_patch`),
что нужно для «почему здесь» и отладки.

### Таблицы (логическая схема; префикс настраивается, по умолчанию `pe_`)

| Таблица | Ключевые поля | Назначение |
|---|---|---|
| `pe_definitions` | `id`, `name`, `version`, `hash`, `schema` (JSON), `created_at` | неизменяемые снапшоты определений (ADR-008); уникально `(name, version)` |
| `pe_instances` | `id` (uuid7), `definition_id`, `status`, `configuration` (JSON: активные состояния), `context` (JSON), `history` (JSON: память history-узлов), `activations` (JSON: счётчики входов), `seq`, `parent_id`, `parent_invoke`, `business_key`, `subject_type`, `subject_id` (сущность хоста, к которой привязан процесс; Laravel — morph), `locked_by`, `lease_until`, `has_incidents`, `created_at`, `updated_at`, `completed_at` | снапшот |
| `pe_active_states` | `instance_id`, `definition_name`, `state` (полный путь), `entered_at` | активные состояния (атомарные и предки) плоскими строками; переписываются в транзакции шага. Нужны для индексируемых запросов «экземпляры в состоянии X» и scopes моделей хоста: искать по JSON `configuration` при 100 тыс. активных слишком дорого |
| `pe_inbox` | `id`, `instance_id`, `seq`, `name`, `payload`, `actor`, `idempotency_key`, `status` (`pending/processed/ignored/deferred/expired`), `defer_until`, `received_at`, `processed_at` | входящие события; уникально `(instance_id, idempotency_key)` |
| `pe_effects` | `id`, `instance_id`, `key` (уникально), `activation`, `handler`, `input`, `semantics`, `retry_policy`, `status`, `attempt`, `next_attempt_at`, `locked_by`, `lease_until`, `started_at`, `finished_at`, `result`, `error`, `compensation_of` | outbox эффектов (ADR-006) |
| `pe_timers` | `id`, `instance_id`, `key` (уникально), `activation`, `event`, `fire_at`, `status` (`pending/fired/cancelled`) | таймеры и дедлайны (ADR-010) |
| `pe_subscriptions` | `instance_id`, `event`, `correlation_key`, `activation` | маршрутизация внешних событий по ключу (ADR-010) |
| `pe_messages` | `event`, `correlation_key`, `payload`, `idempotency_key`, `expires_at` | буфер «ранних» сообщений без подписчика |
| `pe_incidents` | `id`, `instance_id`, `type`, `effect_id`, `status` (`open/resolved`), `details`, `resolution`, `resolved_by`, timestamps | инциденты (ADR-006, ADR-011) |
| `pe_journal` | `instance_id`, `seq`, `type`, `event`, `transitions`, `exited`, `entered`, `context_patch` (RFC 6902), `actor`, `reason`, `data`, `at` | журнал (ADR-011) |

Индексы под операции: `instances(status)`, `instances(definition_id, status)`,
`instances(business_key)`, `instances(subject_type, subject_id)`, `instances(lease_until)`;
`active_states(definition_name, state)`, `active_states(instance_id)`; `inbox(status, instance_id, seq)`;
`effects(status, next_attempt_at)`, `effects(status, lease_until)`;
`timers(status, fire_at)`; `subscriptions(event, correlation_key)`;
`incidents(status)`; `journal(instance_id, seq)`.

### Конфигурация в снапшоте

`configuration` — множество активных атомарных состояний по полным путям
(`claim.review.waitingDocs`), предки выводятся. `activations` — счётчик
входов в каждое состояние: идентификатор активации `state@n` нужен для
ключей эффектов и таймеров (повторный вход в то же состояние в цикле
дозапросов — новая активация, старые таймеры и результаты отбрасываются
как устаревшие).

### Контракты в ядре

`DefinitionStore`, `InstanceStore`, `Inbox`, `EffectStore`, `TimerStore`,
`SubscriptionStore`, `IncidentStore`, `Journal` и `UnitOfWork`
(транзакционная граница: всё, что рантайм пишет за шаг, коммитится разом).
Реализации: `InMemory*` в ядре, `Database*` в Laravel-слое.

## Последствия

- Персональные данные (ввод клиента) попадают в `context`, `pe_inbox.payload`
  и `pe_journal` **целиком** (решение автора 2026-10-26): это и есть «кто что
  ввёл». Вымарывания и шифрования в v1 нет; защита данных — забота хоста
  (доступ к БД, шифрование диска). Точка расширения `Codec` для
  сериализации колонок оставлена в контрактах, чтобы шифрование можно было
  добавить позже без миграции схемы.
- JSON-колонки: `jsonb` на PostgreSQL, `json` на MySQL, `text` на SQLite.
  Запросы по содержимому контекста движок не делает — для поиска
  «по бизнес-полю» есть `business_key` и теги.

## Масштаб и архив (решение автора: 100 тыс. активных, 10 млн архивных)

Оценка: экземпляр за жизнь даёт ~50–200 записей журнала и ~10–40 строк
inbox/effects/timers. В горячих таблицах 100 тыс. активных экземпляров —
это до ~20 млн строк журнала, что нормально при индексе
`(instance_id, seq)`. А 10 млн завершённых экземпляров в тех же таблицах
дали бы миллиард строк. Поэтому горячие и архивные данные разделены.

- **Горячие таблицы** (`pe_instances`, `pe_inbox`, `pe_effects`,
  `pe_timers`, `pe_subscriptions`, `pe_incidents`, `pe_journal`) содержат
  только активные и недавно завершённые экземпляры.
- **`pe_archive`** — **одна строка на завершённый экземпляр**: индексируемые
  колонки (`id`, `definition_id`, `status`, `business_key`, `subject_type`, `subject_id`, `parent_id`,
  `started_at`, `completed_at`) и `document` — сжатый (gzip) JSON со
  снапшотом, журналом, эффектами и инцидентами целиком. Это 10 млн строк
  без миллиардных таблиц и без партиционирования.
- Команда `process-engine:archive` (её добавляет в планировщик провайдер)
  пачками переносит экземпляры, завершённые больше N дней назад (по
  умолчанию 30). Каждая пачка — одна транзакция: вставка в архив и удаление
  из горячих таблиц. Перенос идемпотентен.
- Экземпляр с незакрытым инцидентом или с дочерними экземплярами, которые
  ещё живы, не архивируется.
- Read-модель (ADR-011) отдаёт архивный экземпляр тем же `InstanceView`:
  журнал читается из `document`, ручные операции запрещены. Экспорт во flow
  работает и для архивных экземпляров.
- Удаление из архива по сроку хранения настраивает хост
  (`archive.retention`, по умолчанию бессрочно).
- Буферы с TTL (`pe_messages`, `ignored`/`expired` строки inbox) чистит
  `tick`.

Партиционирование таблиц в v1 не делаем: при такой схеме оно не нужно.
Если активных экземпляров станет на порядок больше, первым кандидатом будет
партиционирование `pe_journal` по `instance_id` (hash) на PostgreSQL.

## Отвергнутые альтернативы

- **Чистый event sourcing.** См. выше: детерминизм на годы, сложность
  ручных операций, свёртка для простых запросов.
- **Только снапшот + отдельный activity log хоста.** Не атомарно со
  снапшотом — журнал может разойтись с состоянием, «почему здесь» не
  восстановить.
