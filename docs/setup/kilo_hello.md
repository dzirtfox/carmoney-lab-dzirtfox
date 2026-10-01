готов.

Что за сервис: учебный сервис предварительной оценки заявки на заём под ПТС (carmoney-lab, практикум М3) — принимает заявку с VIN/годом/пробегом/оценочной стоимостью/суммой/сроком, считает LTV и возвращает решение approve / review / reject; данные синтетические.
Команды запуска и проверки: make up (docker compose up -d --build, сервис + MySQL), make down, make logs, make ps, make test (PHPUnit), make lint (php -l по backend/ и tests/), make seed (перезалить db/seed.sql), make install, make help; в docker-compose.yml сервисы backend (php -S на 0.0.0.0:8080, порт через APP_PORT, по умолчанию 8080) и db (mysql:8.0, порт через DB_PORT, по умолчанию 3307), init-скрипты db/schema.sql и db/seed.sql.
Где считается решение: в backend/src/Domain/ — LtvCalculator.php, DecisionEngine.php, AssessmentService.php, ApplicationValidator.php (пороги и лимиты — в backend/config/rules.php).

модель: training-2026-09-minimax-m3
