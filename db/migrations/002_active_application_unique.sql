-- Миграция 002: одна активная заявка на клиента (учебная фича «защита от повторной заявки»).
--
-- db/schema.sql уже содержит эту схему — он применяется только на пустом томе.
-- Этот файл нужен уже поднятой базе (make migrate), где applications создана без
-- ограничения на повторную активную заявку.
--
-- Смысл ограничения (решение риск-менеджмента B1):
--   активная заявка  ⇔ status <> 'cancelled'
--   закрытая заявка  ⇔ status =  'cancelled'
-- active_ref — STORED-генерированная копия applicant_ref, равная NULL у закрытых заявок.
-- NULL в UNIQUE-ключе не конфликтует, поэтому закрытые заявки не мешают друг другу,
-- а на клиенте остаётся ровно один слот для незакрытой заявки. Второй INSERT того же
-- клиента получает SQLSTATE 23000 по ключу uq_applications_active_ref;
-- ApplicationRepository::save() превращает его в DuplicateActiveApplicationException,
-- а ApplicationController отдаёт HTTP 409 с телом {"errors":{"applicant_ref":"..."}}.
--
-- Как применять: ровно один раз, вручную, на поднятой базе:
--
--     make up && make migrate
--
-- (это docker compose exec -T db mysql -ulab -plab carmoney_lab
--  < db/migrations/002_active_application_unique.sql)
--
-- Если ограничение уже на месте, mysql остановится с Duplicate column name 'active_ref' —
-- это нормально, миграция уже применена.
--
-- Дальше по порядку:
--   1. Проверить, что ограничение на месте:
--        docker compose exec -T db mysql -ulab -plab carmoney_lab -e "SHOW INDEX FROM applications"
--      В выводе должны быть uq_applications_active_ref (Non_unique = 0)
--      и idx_applications_applicant_ref.
--   2. Проверить руками две одновременные заявки (AC-4) — запустить обе команды
--      почти одновременно, в двух терминалах:
--        curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8080/api/applications \
--          -H 'Content-Type: application/json' \
--          -d '{"vin":"XTA21099998765432","year":2022,"mileage":96000,"market_value":900000,"requested_amount":450000,"term_months":24,"applicant_ref":"CL-9001"}'
--      Ожидание: один ответ 201, второй 409. Если 409 нет — миграция не применена
--      или применена не к той базе.
--
-- Откат (вернуть старую схему без ограничения):
--   ALTER TABLE applications
--       DROP INDEX idx_applications_applicant_ref,
--       DROP INDEX uq_applications_active_ref,
--       DROP COLUMN active_ref;

-- ===========================================================================
-- ШАГ 1 (обязательный). Клиенты, у которых уже больше одной активной заявки.
-- Пока такие есть, UNIQUE-ключ создать нельзя. Оператор обязан убедиться, что
-- результат пуст: пустой результат — можно применять, строки — миграция
-- остановится на шаге 2 и до ALTER не дойдёт.
-- ===========================================================================
SELECT applicant_ref, COUNT(*) AS active_cnt
  FROM applications
 WHERE status <> 'cancelled'
 GROUP BY applicant_ref
HAVING active_cnt > 1;

-- ===========================================================================
-- ШАГ 2. Автоматический отказ до ALTER, если такие клиенты есть.
-- CHECK (duplicates = 0) не пропускает положительное число, поэтому mysql
-- прервёт файл с ошибкой 3819 (Check constraint violated) и ALTER не выполнится.
-- Ничего не удаляем и не генерируем: с дублями разбирается оператор на стенде
-- (закрывает лишние заявки через status = 'cancelled').
-- Таблица временная — в базе не остаётся ничего, даже если файл упал.
-- Нужен MySQL 8.0.16+, где CHECK действительно проверяется (в проекте mysql:8.0).
-- ===========================================================================
DROP TEMPORARY TABLE IF EXISTS migration_002_guard;

CREATE TEMPORARY TABLE migration_002_guard (
    duplicates INT NOT NULL,
    CONSTRAINT chk_migration_002_no_duplicates CHECK (duplicates = 0)
) ENGINE = InnoDB;

INSERT INTO migration_002_guard (duplicates)
SELECT COUNT(*) FROM (
    SELECT applicant_ref
      FROM applications
     WHERE status <> 'cancelled'
     GROUP BY applicant_ref
    HAVING COUNT(*) > 1
) AS clients_with_two_active_applications;

DROP TEMPORARY TABLE migration_002_guard;

-- ===========================================================================
-- ШАГ 3. Само ограничение.
-- ===========================================================================
ALTER TABLE applications
    ADD COLUMN active_ref VARCHAR(32)
        GENERATED ALWAYS AS (IF(status = 'cancelled', NULL, applicant_ref)) STORED
        COMMENT 'дубликат applicant_ref для активной заявки, NULL для закрытой'
        AFTER status,
    ADD UNIQUE KEY uq_applications_active_ref (active_ref),
    ADD KEY idx_applications_applicant_ref (applicant_ref, status);