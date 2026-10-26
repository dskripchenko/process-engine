# Внутренние документы process-engine

Рабочие документы на русском: исследование, ADR, план. Пользовательская
документация будет в `docs/{en,de,ru,zh}` (этап 9).

## Этап 0 — исследование и дизайн

- [Обзор подходов и готовых решений](research/landscape.md) — Temporal,
  Durable Workflow, Camunda/Zeebe, Step Functions, symfony/workflow,
  Laravel-пакеты, XState/SCXML: что берём, что нет, почему не готовое.
- [План этапов с оценками](plan.md)
- [Вопросы к автору](questions.md)
- [Эталонный сценарий «урегулирование убытка» (эскиз)](reference-scenario.md)
- [Клиентский код: как выглядит использование движка](client-api.md) — на согласовании перед этапом 1a

## ADR

| № | Решение |
|---|---|
| [001](adr/ADR-001-positioning-and-delayed-process.md) | Независимый движок; laravel-delayed-process — только через опциональный мост |
| [002](adr/ADR-002-statecharts-model.md) | Модель — statechart (SCXML-семантика) с явным сериализуемым графом |
| [003](adr/ADR-003-stack-and-packaging.md) | Ядро на чистом PHP 8.2+ без зависимостей + отдельный Laravel-слой |
| [004](adr/ADR-004-execution-model.md) | Шаг — чистая функция + одна транзакция; inbox/outbox; аренда + fencing; очередь — только «звонок» |
| [005](adr/ADR-005-storage.md) | Снапшот — источник истины, журнал — append-only аудит; архив одной сжатой строкой на экземпляр |
| [006](adr/ADR-006-effects-retry-semantics.md) | Эффекты: `idempotent` / `at-most-once` (+`unknown`, сверка) / `compensable`; политики повторов; инциденты |
| [007](adr/ADR-007-definition-format.md) | Класс процесса с DSL поверх канонического JSON; ссылки на методы и классы, без реестра |
| [008](adr/ADR-008-versioning-and-migration.md) | Неизменяемые версии с hash; экземпляр исполняет сохранённую форму; явная миграция с dry-run |
| [009](adr/ADR-009-hierarchy-parallel-subprocess.md) | Составные/параллельные состояния внутри экземпляра + дочерние процессы и `forEach` |
| [010](adr/ADR-010-external-events-and-timers.md) | Адресация по id/корреляции/токену, буфер ранних сообщений, дедуп; таймеры — строки БД |
| [011](adr/ADR-011-journal-observability-manual-ops.md) | Журнал с guard-трассой; read-модель; ручные операции через inbox с актором и причиной |
| [012](adr/ADR-012-flow-export.md) | Экспорт прямо в Graph v1 и MonitorSnapshot flow, drill-down по уровням |
| [013](adr/ADR-013-testing-strategy.md) | Семантика, харнесс падений с инвариантами, гонки (симуляция + реальные БД) |
