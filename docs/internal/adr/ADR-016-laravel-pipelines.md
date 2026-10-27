# ADR-016. Laravel: очереди эффектов, исключительный запуск, флаги в БД, экраны конвейеров

- Статус: принято автором 2026-10-27
- Дата: 2026-10-27

## Контекст

Пакеты `laravel-process-engine` и `laravel-admin-process` под конвейеры данных
(ADR-014, ADR-015): у этапов разные очереди и таймауты (один воркер на
медленный внешний сервис, задачи по 6 часов), запуски по расписанию не должны
накладываться, переключатели этапов хранятся в БД и меняются из админки,
оператору нужна схема процесса целиком, а не только одного экземпляра.

## Решение

### 1. Очередь и таймаут на эффект (`laravel-process-engine` 1.1)

Ядро не меняется: `QueueDispatcher::effectReady($id)` читает эффект по
первичному ключу (класс и политику повтора) и выбирает маршрут:
1. атрибут `#[OnQueue(queue: 'nspd', connection: null)]` на классе
   обработчика (эффекта или пакета);
2. иначе `process-engine.routes` — `[класс => 'очередь' | ['queue' => …, 'connection' => …]]`;
3. иначе общая очередь движка (`process-engine.queue`).

Таймаут задачи `ExecuteEffect` = `attemptTimeout` политики + 30 с: задача не
убивается раньше, чем движок сочтёт попытку мёртвой. Документируется связь с
`retry_after` очереди. Экземпляры (`RunInstance`) — всегда общая очередь
движка: шаг короткий.

### 2. Исключительный запуск (`laravel-process-engine` 1.1)

`Processes::startExclusive(Process::class, string $lock, context:, subject:, by:)` —
если есть незавершённый экземпляр этого процесса с тем же `lock` (хранится в
`pe_instances.exclusive_key`, уникальный индекс по `(definition_name,
exclusive_key)` для незавершённых — частичный в PG, иначе проверка под
блокировкой), возвращает его, иначе запускает. Ключ освобождается при
завершении экземпляра. Для расписаний: `startExclusive(Monitoring::class, 'monitoring')`,
`startExclusive(RiskSurface::class, "surface:{$profile}")`.

### 3. Флаги в БД (`laravel-process-engine` 1.1)

`DatabaseFlags implements FlagSwitch` на таблице `pe_flags (name, enabled,
reason, changed_by, changed_at)`, чтение с кэшем на время запроса/задачи.
Начальное значение нового флага — из `process-engine.flags` (как сейчас у
`ConfigFlags`), дальше владеет БД. Включается конфигом
`process-engine.flag_store = database` (по умолчанию `config` — обратная
совместимость). Событие Laravel `ProcessSignal` с типом `flag.changed`.

### 4. Экраны конвейеров (`laravel-admin-process` 1.1)

- **Процессы** (список определений): имя, версия, идут / с инцидентами /
  упали за сутки, выключенные этапы.
- **Схема процесса** (экран определения): `FlowExporter::overview` со
  счётчиками экземпляров по состояниям; переключатели этапов на узлах
  (`toggleable` + `persistToggle` → метод экрана → `Engine::switchOff/On`,
  причина обязательна, право `admin.process.flags`); «Запустить» для
  определений, объявленных запускаемыми вручную (`Process::startable()` —
  поля контекста запуска и выбор выключенных на запуск этапов, право
  `admin.process.start`); последние экземпляры.
- **Экземпляр**: прогресс пакетов на узлах; вкладка «Элементы» (пакет,
  статус, причина; постранично) с действиями «Повторить упавшие» и
  «Пропустить остаток» (`admin.process.operate`).
- Права: новые `admin.process.flags`, `admin.process.start`.

## Последствия

- Миграция моста: `pe_batches`, `pe_batch_items`, `pe_flags`, колонка `pe_instances.exclusive_key`.
- `laravel-admin-process` требует `laravel-process-engine ^1.1`.
