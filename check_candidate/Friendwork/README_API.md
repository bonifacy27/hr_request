# Интеграции FriendWork

## Public API (`https://api.friend.work`)

Все поддерживаемые запросы авторизуются заголовком `Authorization: Bearer ...`.
Токен читается из глобальной константы бизнес-процессов
`Constant1789370789700` и не передаётся в URL или теле запроса.

| Метод | Интеграция | Назначение |
| --- | --- | --- |
| `GET /api/v2/accounts` | `check_candidate_prof.php`, `check_mass.php`, `request_to_fw.php` | Сопоставление аккаунта FriendWork с рекрутером |
| `POST /jobs` | `request_to_fw.php` | Создание вакансии из заявки на подбор |
| `GET /jobs/{jobId}` | `create_anketa.php`, `diagnose_public_jobs.php` | Проверка доступности вакансии и ручная read-only диагностика |

Устаревший fallback `GET /accounts` удалён: этот маршрут отсутствует в
`openapi.yaml`. При ошибке `GET /api/v2/accounts` интеграция продолжает работу с
резервными правилами сопоставления либо показывает ID ответственного.

Вызов `POST /Candidate/{candidateId}/CandidateHistories/set` также удалён. В
предоставленном Public API нет операции смены статуса кандидата. Метод
`POST /candidateDisqualifications` предназначен для отказа кандидату и не
является семантически эквивалентной заменой. После импорта анкеты интеграция
оставляет статус FriendWork без изменений и пишет это событие в журнал.

## Внутренний API (`https://app.friend.work/api`)

Следующие операции пока остаются на cookie-аутентификации по логину и паролю:

| Метод | Интеграция | Назначение |
| --- | --- | --- |
| `GET /Accounts/LogIn` | `check_candidate_prof.php`, `check_mass.php`, `create_anketa.php` | Получение cookie внутреннего API |
| `POST /Candidates` | `check_candidate_prof.php`, `check_mass.php`, `create_anketa.php` | Выборка кандидатов вакансии по статусу |

В `openapi.yaml` нет эквивалента выборки кандидатов вакансии, поэтому заменить
эти два внутренних вызова токеном без изменения функциональности невозможно.
В `create_anketa.php` сама вакансия дополнительно проверяется документированным
`GET /jobs/{jobId}` с токеном из `Constant1789370789700`, а список кандидатов
временно загружается через внутренний API.
Их можно удалить после появления документированного Public API метода или
перевода сценария на webhooks.
