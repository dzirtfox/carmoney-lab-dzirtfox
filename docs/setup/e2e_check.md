# e2e_check — mileage / max_mileage_km через http://localhost:8080

Дата: 2026-10-20.
Инструмент: Playwright (Chromium).
Все шаги и наблюдения — дословно. Скриншоты лежат рядом в `docs/setup/`.

## 0. Подготовка

Перед запуском проверил, что сервис отвечает:

```
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/health
200
```

## 1. Каталог мест использования (по `ast-index search`)

### 1.1 `mileage` (имя ключа в payload, имя столбца `mileage_km` в БД)

| Файл | Строка | Что (функция / символ) |
|------|--------|------------------------|
| `frontend/app.js` | 8 | константа `NUMERIC_FIELDS` (внутри IIFE на `<script>`) |
| `frontend/index.html` | 30–31 | `<input id="mileage" name="mileage" ...>` (форма) |
| `backend/src/Domain/ApplicationValidator.php` | 43 | функция `validate()` — чтение `$payload['mileage']` |
| `backend/src/Domain/ApplicationValidator.php` | 44 | функция `validate()` — проверка `$mileage > rules['vehicle']['max_mileage_km']` |
| `backend/src/Domain/ApplicationValidator.php` | 45 | функция `validate()` — формирование сообщения `$errors['mileage']` |
| `backend/src/Domain/ApplicationValidator.php` | 78 | функция `validate()` — возврат нормализованного `'mileage' => $mileage` |
| `backend/src/AssessmentService.php` | 33 | (сигнатура не смотрел) — вызов `$this->decisionEngine->decide($ltv, $input['mileage'])` |
| `backend/src/Domain/DecisionEngine.php` | 35 | метод `decide(float $ltv, int $mileage = 0): string` |
| `backend/src/Domain/DecisionEngine.php` | 38 | метод `decide()` — `return $mileage > $this->reviewMaxMileageKm ? self::REVIEW : self::APPROVE;` |
| `backend/src/Repository/ApplicationRepository.php` | 38–39 | INSERT `INSERT INTO vehicles (... mileage_km ...)` |
| `backend/src/Repository/ApplicationRepository.php` | 45 | bind `:mileage => $input['mileage']` |
| `backend/src/Repository/ApplicationRepository.php` | 68 | SELECT `v.vin, v.production_year, v.mileage_km, v.market_value` |
| `db/schema.sql` | 22 | столбец `mileage_km INT UNSIGNED NOT NULL` |
| `db/seed.sql` | 31 | INSERT в `vehicles (..., mileage_km, ...)` |
| `tests/Unit/ApplicationValidatorTest.php` | 34 | фикстура `'mileage' => 84000` |
| `tests/Unit/AssessmentServiceTest.php` | 35 | фикстура `payload(...)` |

### 1.2 `max_mileage_km` (порог валидации)

| Файл | Строка | Что |
|------|--------|-----|
| `backend/config/rules.php` | 23 | `'max_mileage_km' => 500000` (справочник) |
| `backend/src/Domain/ApplicationValidator.php` | 44 | верхняя граница валидации mileage |
| `backend/src/Domain/ApplicationValidator.php` | 45 | подставляется в текст ошибки через `sprintf` |

### 1.3 `review_max_mileage_km` (порог решения, связан с mileage)

| Файл | Строка | Что |
|------|--------|-----|
| `backend/config/rules.php` | 28 | `'review_max_mileage_km' => 400000` (справочник) |
| `backend/src/AppFactory.php` | 37 | `new DecisionEngine($rules['ltv'], $rules['vehicle']['review_max_mileage_km'])` |
| `backend/src/Domain/DecisionEngine.php` | 14 | комментарий: пробег свыше `review_max_mileage_km` понижает approve до review |
| `backend/src/Domain/DecisionEngine.php` | 38 | условие `$mileage > $this->reviewMaxMileageKm` |

Через браузер (frontend → `/api/applications`, `/api/ltv`) виден только путь:
`frontend/app.js` → HTTP → `ApplicationValidator::validate()` → `AssessmentService` → `DecisionEngine::decide()` → ответ.
SQL-путь (`ApplicationRepository`, `db/schema.sql`, `db/seed.sql`) через UI напрямую не виден — это серверная сторона.
Через UI я смог подтвердить путь до `DecisionEngine::decide()` (появление решения `review` при том же LTV) и валидатор (сообщение «Пробег от 0 до 500000 км»). Сам факт записи в БД в браузере не проверял.

## 2. Шаги в Playwright

### Шаг 1. Открыть главную
```
playwright_browser_navigate url=http://localhost:8080
```
Снимок экрана → `docs/setup/01_form_default.png`.

Состояние страницы (дословно по snapshot):
```
Page URL: http://localhost:8080/
Page Title: Предварительная оценка заявки — carmoney-lab
- heading "Предварительная оценка заявки на заём под ПТС"
- paragraph: "Учебный сервис. Все данные синтетические, реальные заявки сюда вводить нельзя."
- textbox "Идентификатор клиента": CL-0025
- textbox "VIN": XTA21099998765432
- spinbutton "Год выпуска": "2019"
- spinbutton "Пробег, км": "84000"
- spinbutton "Оценочная стоимость, ₽": "900000"
- spinbutton "Запрошенная сумма, ₽": "450000"
- spinbutton "Срок, месяцев": "24"
- button "Отправить заявку"
- button "Только рассчитать LTV"
```
В консоли одно сообщение: `[ERROR] Failed to load resource: the server responded with a status of 404 (Not Found)` — это favicon, на форму не влияет.

### Шаг 2. Нажать «Отправить заявку» с дефолтным mileage = 84000
```
playwright_browser_click element="Отправить заявку" ref=e29
```
Снимок экрана → `docs/setup/02_mileage_84000_approve.png`.

Система показала блок «Решение» (дословно):
```
heading "Решение"
Решение:  approve
LTV:      50 %
Возраст авто: 7 лет
Одобренный лимит: 450 000 ₽
Номер заявки:    25
```
mileage = 84000 прошёл валидацию (`84000 ≤ max_mileage_km=500000`) и в `DecisionEngine::decide()` дал ветку `self::APPROVE` (LTV 50 < approve_max 60, mileage 84000 ≤ reviewMaxMileageKm 400000).

### Шаг 3. Заменить mileage на 420000, отправить
```
playwright_browser_fill_form fields=[{name: "Пробег, км", type: textbox, ref: e18, value: "420000"}]
playwright_browser_click   element="Отправить заявку" ref=e29
```
Снимок экрана → `docs/setup/03_mileage_420000_review.png`.

Система показала блок «Решение» (дословно):
```
heading "Решение"
Решение:  review
LTV:      50 %
Возраст авто: 7 лет
Одобренный лимит: 0 ₽
Номер заявки:    26
```
mileage = 420000 > review_max_mileage_km = 400000, поэтому в `DecisionEngine::decide()` (строка 38) сработала ветка
`return $mileage > $this->reviewMaxMileageKm ? self::REVIEW : self::APPROVE;` — решение понизилось с `approve` до `review` при том же LTV 50 %. Валидация прошла (`420000 ≤ max_mileage_km=500000`).

### Шаг 4. Заменить mileage на 600000, отправить
```
playwright_browser_fill_form fields=[{name: "Пробег, км", type: textbox, ref: e18, value: "600000"}]
playwright_browser_click   element="Отправить заявку" ref=e29
```
В консоли браузера: `[ERROR] Failed to load resource: the server responded with a status of 422 (Unprocessable Entity)`.
Снимок экрана → `docs/setup/04_mileage_600000_validation_error.png`.

Система показала блок «Заявка не принята» (дословно):
```
heading "Заявка не принята"
list:
  - "mileage: Пробег от 0 до 500000 км"
```
Сообщение соответствует `sprintf('Пробег от 0 до %d км', $this->rules['vehicle']['max_mileage_km'])` в `ApplicationValidator::validate()` (строка 45) — `max_mileage_km` подставлен как `500000`. HTTP-статус 422, до `DecisionEngine` запрос не дошёл — короткое замыкание в валидаторе.

### Шаг 5. Вернуть mileage = 150000, нажать «Только рассчитать LTV»
```
playwright_browser_fill_form fields=[{name: "Пробег, км", type: textbox, ref: e18, value: "150000"}]
playwright_browser_click   element="Только рассчитать LTV" ref=e30
```
Снимок экрана → `docs/setup/05_ltv_only_mileage_150000.png`.

Система показала блок «Решение» (дословно):
```
heading "Решение"
Решение:  approve
LTV:      50 %
Возраст авто: 7 лет
Одобренный лимит: 450 000 ₽
Номер заявки:    не сохранена
```
mileage прошёл тем же путём через `ApplicationValidator::validate()` (150000 ≤ 500000), а в `frontend/app.js` строка 29 подставила «не сохранена», потому что в ответе `id` отсутствует (эндпоинт `/api/ltv` не пишет в БД). Скрипт `frontend/app.js:66` отправляет форму на `/api/ltv` для этой кнопки.

## 3. Что подтверждено через UI, а что нет

Подтверждено наблюдением в браузере:
- `frontend/index.html:30–31` — поле `<input name="mileage">` есть и принимает значение.
- `frontend/app.js:8` — mileage попадает в `NUMERIC_FIELDS` и приводится к числу.
- `backend/src/Domain/ApplicationValidator.php:43–45` — при mileage > 500000 сервис возвращает 422 и ошибку `mileage: Пробег от 0 до 500000 км` (видна в UI).
- `backend/src/Domain/DecisionEngine.php:35–38` — при том же LTV 50 % смена mileage с 84000 на 420000 меняет решение с `approve` на `review`. Это ветка строки 38.
- `frontend/app.js:66` — кнопка «Только рассчитать LTV» шлёт форму на `/api/ltv`, mileage проходит тем же валидатором.

Не подтверждено через UI (за пределами браузера):
- `backend/src/Repository/ApplicationRepository.php:38–45, 68` — фактическая запись и чтение `mileage_km` из MySQL. Браузер не показывает прямой SQL. В шаге 5 (`/api/ltv`) сервис вернул «не сохранена», что косвенно подтверждает, что для этого эндпоинта запись не происходит, но сам `INSERT` я не видел.
- `db/schema.sql:22`, `db/seed.sql:31` — определение столбца и сидовые данные. В БД не заглядывал.
- `backend/config/rules.php:23, 28` — числа `500000` и `400000` указаны в сообщении валидатора и выведены в решении `review` соответственно; сами строки конфига не показаны в UI.
- `backend/src/AppFactory.php:37` — DI-сборка. Через UI не наблюдается.
- `tests/Unit/ApplicationValidatorTest.php:34`, `tests/Unit/AssessmentServiceTest.php:35` — фикстуры в тестах, в UI не показываются.

## 4. Чего не получилось / не делал

- Не открывал БД и не проверял реальные строки `vehicles.mileage_km` после шагов 2 и 3 — задача была про UI.
- Не запускал `make test` и не трогал пороги в `backend/config/rules.php` — задача на это не указывала, а AGENTS.md просит пороги не менять без решения риск-менеджмента.
- Не проверял путь с mileage = 0 и отрицательным mileage — UI не пускает `<input type="number" min>` к минусу через обычный ввод, а отдельного теста на этот случай в задаче не было. В коде валидатора (`ApplicationValidator.php:44`) проверка `< 0` есть, но руками через эту форму я её не воспроизвёл.
