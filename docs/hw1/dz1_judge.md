# ДЗ.1 · отчёт агента-судьи: REQ ↔ тесты

**Что проверяю.** Соответствие требований спеки
`docs/spec/spec_MILEAGE.md` (REQ-MILEAGE-01…08, AC-MILEAGE-01…11) и
новых тестов MILEAGE в `tests/Unit/`. Два вопроса:

1. Каждый ли REQ покрыт хотя бы одним AC-тестом?
2. Есть ли тесты, не привязанные ни к какому REQ (т.е. тесты под
   требования, которых в спеке нет)?

**Состояние кода и тестов на момент проверки.** Правило реализовано по
`docs/plan/plan_MILEAGE.md` (шаги 1–4: `backend/config/rules.php`,
`backend/src/Domain/DecisionEngine.php`,
`backend/src/Domain/AssessmentService.php:33`,
`backend/src/AppFactory.php:37`). Реализация выполнена без правки
существующих тестов: в `DecisionEngine` новые параметры сделаны
необязательными со значениями по умолчанию, зеркалящими конфиг
(`mileage = 0`, `review_max_mileage_km = 400000`); в production порог
всегда приходит из `rules.php` через `AppFactory`. Отступление от точных
сигнатур плана (по плану параметры обязательные) — следствие
договорённости «тесты не трогаем». К самому покрытию это отношения не
имеет: считаю, что план реализован по сути.

## 1. Покрытие REQ → AC → тест

Таблица: REQ → его AC → какой тест его закрывает (с file:line).

| REQ | Суть | AC | Покрывающий тест | Статус |
|---|---|---|---|---|
| REQ-MILEAGE-01 | Пробег > 400000 → approve понижается до review | AC-01 (399999), AC-03 (400001) | `tests/Unit/AssessmentServiceTest.php:75` (`testKeepsApproveAtMileageJustBelowReviewThreshold`), `:93` (`testDowngradesApproveToReviewAboveReviewThresholdMileage`) | ✓ |
| REQ-MILEAGE-02 | При пробеге ≤ 400000 решение — только по LTV | AC-01 (399999), AC-02 (400000) | `:75`, `:84` (`testKeepsApproveAtReviewThresholdMileage`) | ✓ |
| REQ-MILEAGE-03 | Правило не ослабляет review и reject | AC-04 (400001 + LTV-серая зона → review), AC-05 (400001 + LTV-reject → reject) | — | ✗ |
| REQ-MILEAGE-04 | Правило работает в обоих сценариях расчёта (сохранение и «только расчёт») | AC-06 (сохранение), AC-07 («только расчёт») | `:93` — оба эндпоинта идут через `AssessmentService::assess()` (`backend/src/Http/ApplicationController.php:28,59`), один кейс покрывает оба сценария | ✓ |
| REQ-MILEAGE-05 | Нет/пустой `mileage` → ошибка валидации по полю `mileage`, до расчёта не доходит | AC-08 | `tests/Unit/AssessmentServiceTest.php:102` (`testEmptyMileageFailsValidationWithMileageError`, два кейса провайдера: «нет ключа mileage», «mileage null») | ✓ |
| REQ-MILEAGE-06 | Пустое поле на фронте уходит как 0 и обрабатывается как пробег 0 → approve | AC-09 (mileage 0 → approve) | — | ✗ |
| REQ-MILEAGE-07 | `approved_limit` при понижении = 0, при approve — сумме | AC-01, AC-03, AC-06, AC-07 | `:75` (limit = 450000), `:84` (limit = 450000), `:93` (limit = 0) | ✓ |
| REQ-MILEAGE-08 | К расчёту приходит только валидированный пробег (0…500000); 500000 — валидно, 500001 — отказ валидации | AC-10 (500000 → review, limit 0), AC-11 (500001 → ValidationException по `mileage`) | — | ✗ |

## 2. Непокрытые AC (5 из 11)

| AC | Текст | Где должен жить по плану | Что покрывает |
|---|---|---|---|
| AC-04 | 400001 + LTV-серая зона (60 < LTV ≤ 85) → `review`, limit 0 | `tests/Unit/DecisionEngineTest.php` — кейс в новом провайдере или отдельный метод | REQ-MILEAGE-03 |
| AC-05 | 400001 + LTV-reject (LTV > 85) → `reject`, limit 0 | `tests/Unit/DecisionEngineTest.php` — то же | REQ-MILEAGE-03 |
| AC-09 | mileage 0 + LTV approve-зона → `approve` | `tests/Unit/DecisionEngineTest.php` — кейс `decide(ltv, mileage: 0)` | REQ-MILEAGE-06 |
| AC-10 | mileage 500000 (верхняя граница валидации) + LTV approve-зона → `review`, limit 0 | `tests/Unit/AssessmentServiceTest.php` — сквозной кейс через `assess()` | REQ-MILEAGE-08 |
| AC-11 | mileage 500001 → `ValidationException` с ключом `mileage` | `tests/Unit/ApplicationValidatorTest.php` — отдельный тест валидатора | REQ-MILEAGE-08 |

AC-04/05 задуманы как кейсы именно уровня `DecisionEngine` (LTV-каскад +
правило вместе), чтобы зафиксировать порядок «LTV первый, правило после»
(`backend/src/Domain/DecisionEngine.php`). AC-09 — уровня движка: 0 не
триггерит правило (0 < 400000), решение строго по LTV. AC-10 — сквозной
через `assess()`: 500000 проходит валидацию (≤ 500000), попадает в
правило (> 400000) и понижается. AC-11 — уровня валидатора: 500001 не
проходит валидацию (>`max_mileage_km`), до движка не доходит.

AC-04/05/09 — пробелы по REQ-03/06. REQ-08 (вход правила — валидированный
пробег) закрыт только конфигурационно и реализацией, в тестах
не зафиксирован ни один из его двух AC.

AC-09 формально в спеке есть, но план прямо говорит, что покрывать его
тестом не нужно: «поведение пустого поля фронта… план не меняет и
отдельным тестом не покрывает — это существующее поведение без
изменений» (`docs/plan/plan_MILEAGE.md:107`). То есть AC-09 в спеке
описан как требование, а в плане — сознательно не покрыт. Это
противоречие между спекой и планом, не дефект реализации. На усмотрение
человека: либо закрыть AC-09 отдельным тестом (минимальный кейс в
`DecisionEngineTest`), либо скорректировать спеку (AC-09 как
информационный, не требующий теста).

## 3. Лишние требования

Тесты MILEAGE (новые в этой задаче):

- `testKeepsApproveAtMileageJustBelowReviewThreshold` (:75) — AC-01.
- `testKeepsApproveAtReviewThresholdMileage` (:84) — AC-02.
- `testDowngradesApproveToReviewAboveReviewThresholdMileage` (:93) —
  AC-03, AC-06, AC-07, AC-07-часть REQ-07.
- `testEmptyMileageFailsValidationWithMileageError` (:102) — AC-08.

Каждый из них привязан к одному или нескольким AC спеки. Тестов,
утверждающих поведение, которого в `spec_MILEAGE.md` нет как REQ/AC, не
обнаружено. **Лишних требований нет.**

Существующие тесты MILEAGE-независимы (`testApprovesLowLtv…`,
`testSendsMiddleLtvToReview…`, `testRejectsHighLtv` в
`AssessmentServiceTest`, шесть кейсов LTV в `DecisionEngineTest`,
тесты `ApplicationValidatorTest`) — они покрывают LTV-каскад и
валидацию в целом, не добавляют требований и не дублируют AC-MILEAGE.

## 4. Вердикт

- **Каждый ли REQ покрыт:** 5 из 8 REQ покрыты полностью (01, 02, 04,
  05, 07). 3 REQ покрыты частично или не покрыты:
  - REQ-03 (правило не ослабляет review/reject) — без тестов
    (AC-04, AC-05);
  - REQ-06 (пустое поле на фронте как 0) — без теста (AC-09);
  - REQ-08 (вход правила — валидированный пробег) — без тестов
    (AC-10, AC-11).
- **Лишних требований нет.** Все новые MILEAGE-тесты привязаны к AC.

**Сводка по AC.** 6 из 11 AC покрыты, 5 не покрыты (AC-04, AC-05,
AC-09, AC-10, AC-11).

Для полного покрытия по спеке нужны ещё 4 кейса:

- в `DecisionEngineTest` — `decide(LTV≈75, mileage=400001)` →
  REVIEW, `decide(LTV≈95, mileage=400001)` → REJECT, и опционально
  `decide(LTV≈50, mileage=0)` → APPROVE (AC-09);
- в `AssessmentServiceTest` — сквозной кейс `payload(450000, 900000,
  500000)` (AC-10);
- в `ApplicationValidatorTest` — кейс `mileage=500001` →
  `ValidationException` с ключом `mileage` (AC-11).

AC-09 отдельно стоит решить: либо добавить тест, либо согласовать с
заказчиком снятие AC как обязательного (план фиксирует поведение без
теста).

## 5. Где агент срезал угол

Агент отошёл от плана в одном месте — и это не «упрощение ради
зелёного теста», а прямая подстройка под соглашение «тесты не
трогаем». План предписывал жёсткие сигнатуры:

- `DecisionEngine::__construct(array $thresholds, int $reviewMaxMileageKm)` —
  обязательный второй аргумент;
- `DecisionEngine::decide(float $ltv, int $mileage): string` — обязательный
  второй аргумент;

и отдельно (шаги 5–6 плана) — добавить второй аргумент в `setUp()` двух
тестов: `tests/Unit/DecisionEngineTest.php:17` и
`tests/Unit/AssessmentServiceTest.php:29`. Реализация без этих правок
тестов валилась бы `ArgumentCountError` (текущие вызовы — одним
аргументом) и не зеленела бы.

Агент сделал обратно совместимые сигнатуры с дефолтами:

```php
public function __construct(array $thresholds, int $reviewMaxMileageKm = 400000)
public function decide(float $ltv, int $mileage = 0): string
```

Так все существующие вызовы компилируются без правки тестов, и
`make test` стал зелёным. Цена — три отступления от плана и от
конвенций:

1. Дефолт `400000` в коде `Domain` — это бизнес-число в коде, а
   конвенция репозитория (`AGENTS.md`, раздел 4) требует держать пороги
   и лимиты только в `backend/config/rules.php`. В production
   (`AppFactory`) порог всегда передаётся из конфига, дефолт
   срабатывает только в тестах, которые не получают конфиг явно.
2. Дефолт `0` для `mileage` в `decide()` — тоже бизнес-число в коде,
   пусть и тривиальное (нижняя граница валидации). Возник по той же
   причине.
3. Существующие тесты MILEAGE-движка (`DecisionEngineTest`) тестируют
   дефолт движка, а не конфиг. Если риск-менеджмент позже изменит
   `review_max_mileage_km` в `rules.php`, эти тесты будут зелёными
   дальше — тихо, без сигнала, что семантика изменилась. При
   сигнатурах плана (с явной передачей порога через `setUp()`) тесты
   следили бы за конфигом.

Это срез угла по форме (сигнатуры), а не по содержанию: правило в
`DecisionEngine` понижает `approve` → `review` ровно на тех же
условиях, что и план, граница «больше 400000» включительно, `review`
и `reject` не затрагиваются, лимит при понижении 0. Поведение
совпадает с планом.

Чтобы вернуться к точному плану (убрав дефолты и хардкод 400000 из
`Domain`), нужны две строчки в тестах — те самые из шагов 5–6:
в `DecisionEngineTest::setUp()` второй аргумент `400000` (или
`$rules['vehicle']['review_max_mileage_km']`), в
`AssessmentServiceTest::setUp()` — то же. Тогда дефолты можно убрать,
сигнатуры станут обязательными, как в плане.
