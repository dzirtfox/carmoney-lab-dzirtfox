# Роли агентов Kilo в проекте

Заметка о двух специализированных ролях: что каждая может и чего не может, и
что вернула последняя вылазка scout по пробегу.

Источник прав — блок `permission` в [`kilo.jsonc`](../../kilo.jsonc) и
[`docs/agent-rules.md`](../agent-rules.md); фронтматтер
`.kilo/agents/<имя>.md` **сужает** общие права роли.

---

## planner

Читает код, AGENTS.md и `docs/setup/code_map.md`, пишет планы и спеки.

| Можно | Нельзя |
|---|---|
| читать файлы проекта (включая `backend/`, `tests/`, `docs/`, `frontend/`) | править `backend/`, `tests/`, `frontend/`, `db/`, `kilo.jsonc`, `.env*` |
| писать и редактировать только в `docs/plan/` и `docs/spec/` | запускать `make *`, `git commit`, `git push`, `reset_db*` |
| читать и цитировать `docs/setup/code_map.md`, `docs/sources/*`, AGENTS.md | выдумыывать пороги и формулы — брать из `backend/config/rules.php` и тестов |

Если по ходу плана выясняется, что нужно менять код, план останавливается и
возвращается человеку: «вот гипотеза, дайте добро на реализацию».

## scout

Только поиск и отчёт. Ничего не правит, ничего не запускает.

| Можно | Нельзя |
|---|---|
| `grep` / `glob` / `read` по всему репо (кроме `.env*`) | `edit`, `write`, `bash` (включая `make *` и `git *`) |
| ссылаться на `backend/`, `tests/`, `frontend/`, `db/`, `docs/` | менять конфиги, тестовые ожидания, пороги |
| возвращать отчёт со списком файлов и `file:line` | делать выводы «что менять» — это работа planner |

Типичный запуск — `@scout найди все места, где читается X`, ответ приходит
списком файлов, строк и краткой ролью места (валидация / маппинг / фикстура /
UI / SQL). По итогам scout не предлагает правок.

---

## Что вернул scout по пробегу (mileage)

Запрос: «найди все места, где читается пробег». По слоям:

**Конфиг правил**
- `backend/config/rules.php:23` — порог `max_mileage_km = 500000`, читается
  только `ApplicationValidator`.

**Domain / валидация**
- `backend/src/Domain/ApplicationValidator.php:43` —
  `(int) ($payload['mileage'] ?? -1)` — единственное место, где пробег попадает
  в payload, маркер отсутствия `-1`.
- `backend/src/Domain/ApplicationValidator.php:44` —
  `0 ≤ mileage ≤ rules.vehicle.max_mileage_km` — единственное бизнес-правило
  с пробегом.
- `backend/src/Domain/ApplicationValidator.php:78` —
  `'mileage' => $mileage` — проброс в нормализованный вход.

**Repository / БД**
- `backend/src/Repository/ApplicationRepository.php:45` —
  `:mileage => $input['mileage']` — insert в `vehicles`.
- `backend/src/Repository/ApplicationRepository.php:68` — `v.mileage_km` в
  `find()` для карточки заявки.
- `ApplicationRepository::listApplications()` пробег **не возвращает** (там
  отдельный `SELECT vin, production_year`).

**HTTP**
- `backend/src/Http/ApplicationController.php` — прямого чтения нет, пробег
  идёт транзитивно через payload и нормализованный `input`.

**Frontend**
- `frontend/index.html:31` — `<input name="mileage">`, ключ в JSON-payload.
- `frontend/app.js:8` — `NUMERIC_FIELDS` включает `'mileage'` (приведение к
  числу перед отправкой).
- `showResult` пробег не отображает (показывает только `decision`, `ltv`,
  `vehicle_age`, `approved_limit`, `id`).

**SQL**
- `db/schema.sql:22` — колонка `mileage_km INT UNSIGNED`.
- `db/seed.sql:31–55` — 24 синтетических записи, пробег от 20 000 до 296 000
  (порог 500 000 на seed не срабатывает).

**Тесты**
- `tests/Unit/ApplicationValidatorTest.php:34` — `'mileage' => 84000` в
  фабричном `validPayload()`.
- `tests/Unit/AssessmentServiceTest.php:38` — `'mileage' => 96000` в
  фабричном `payload()` (значение ни на что не влияет, лишь бы пройти
  валидацию).
- Специальных тестов на граничные значения пробега (399999/400000/400001 /
  пустой) **нет** — это материал ДЗ.1, см. `docs/plan/plan_MILEAGE.md` и
  `docs/spec/spec_MILEAGE.md`.

### Главный вывод scout (для planner)

Пробег сейчас **не участвует в расчёте LTV и решении** (`approve` / `review` /
`reject`): `AssessmentService`, `LtvCalculator` и `DecisionEngine::decide()`
работают только по сумме, стоимости и году; пробег попадает в БД, но не в
формулу решения. Это зафиксировано в `docs/setup/code_map.md` (раздел про
«большой пробег → review»).

Если следующая задача — добавить правило «пробег ≥ 400 000 → review», точка
вставки: `DecisionEngine::decide()` (сейчас принимает только `float $ltv`).
План такого изменения пишет planner, а scout ничего не правит.
