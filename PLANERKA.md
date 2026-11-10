# Планёрка — полный код приложения для переноса на другой сервер

Общий список задач команды: входящие, исполнители, срочное, принятие/отказ, уведомления на телефон,
история и статистика. Веб-приложение (PWA) на PHP без базы данных и без сторонних библиотек: данные — JSON-файлы.

Этот файл — **полная копия кода** приложения, которое работает по адресу `/planerka/` (сборка от 28.10.2026),
**без данных**: исполнителей нет, задач нет, «Срочное», «Входящие», история и статистика пустые,
ключей, паролей и подписок нет — всё это создаётся заново на новом сервере.

Проверено 10.11.2026: файлы распакованы из этого .md, собраны и запущены на чистом PHP 7.4 без расширений;
API (вход, задачи, принятие, история, статистика, уведомления в режиме «сток») и интерфейс — настоящими
кликами в Chromium (вход по паролю, пустые «Входящие» и «Срочное», новая задача, «Принять», «Выполнено», история).

Репозиторий: https://github.com/marcelkg-collab/planerka · Лицензия: код — MIT (текст — в конце раздела 10),
шрифт Inter — SIL Open Font License 1.1 (`planerka/fonts/OFL.txt`).

> **Для Claude Code в другой сессии.** Не читай файл целиком — в нём ~0,8 МБ кода и картинок в base64.
> Прочитай разделы 1–10 (до «Распаковщика»), затем распакуй файлы скриптом из раздела 11 — он сам
> проверит контрольную сумму каждого файла. Код из раздела 12 вручную не перепечатывай.

---

## 1. Быстрый старт

```bash
# 1) распаковать файлы из этого .md (скрипт — в разделе 11; нужен Python 3.6+)
python extract.py PLANERKA.md .            # создаст папку ./planerka/ (22 файлов)

# 2) указать адрес сайта и при необходимости другие настройки
#    planerka/config.php → PL_SITE = 'https://ваш-домен'  (или переменная окружения PLANERKA_SITE)

# 3) собрать страницу (вшивает стиль, шрифты и значки в один app.html)
python planerka/build.py

# 4) выложить папку planerka/ на сервер так, чтобы она открывалась по адресу https://ваш-домен/planerka/
#    и все адреса /planerka/* шли в planerka/index.php (раздел 5)

# 5) на сервере, от имени пользователя веб-сервера (например www-data):
sudo -u www-data php planerka/admin.php init                 # папка данных, ключи, пустой список
sudo -u www-data php planerka/admin.php password 'пароль'     # пароль входа для исполнителей
sudo -u www-data php planerka/admin.php add ivan 'Иван'       # исполнители — по одному
sudo -u www-data php planerka/admin.php owner ivan            # постановщик (руководитель)
sudo -u www-data php planerka/admin.php list                  # личные ссылки исполнителей
```

Дальше: открыть `https://ваш-домен/planerka/`, ввести пароль — или разослать личные ссылки из `admin.php list`.

## 2. Требования

| Что | Зачем |
|---|---|
| PHP **7.0+** для веба (без расширений; mbstring желателен, но не обязателен) | страница, API (`index.php`, `api.php`) |
| PHP **7.3+ с openssl и curl** в командной строке (`PL_PHP_CLI`) | отправка уведомлений (`push-send.php`), ключи VAPID. Без него приложение работает, только без уведомлений |
| Функция `exec()` в веб-PHP не отключена | веб-PHP запускает `push-send.php` фоновым процессом |
| **HTTPS** | без него не работают сервис-воркер, уведомления и установка «на экран Домой» |
| Python 3.6+ (только на машине, где собирают) | `build.py`, распаковщик |
| Право записи веб-сервера в папку данных | там всё состояние |

Базы данных нет. Одновременные запросы разводятся блокировкой файла (`flock`), запись атомарная (временный файл + `rename`).

## 3. Файлы

| Файл | Что это |
|---|---|
| `planerka/config.php` | **все настройки сервера** (адрес сайта, папка данных, PHP для уведомлений, часовой пояс) |
| `planerka/index.php` | точка входа: страница, личные ссылки `/planerka/<id>`, API, `sw.js`, значки, постер `og.png`, манифест, `/dial` |
| `planerka/api.php` | API: задачи, ответы исполнителей, присутствие, история, статистика, подписки на уведомления, вход по паролю |
| `planerka/admin.php` | консоль администратора: создать хранилище, пароль, исполнители, постановщик, ссылки |
| `planerka/push-send.php` | отправка одного уведомления (запускается из `api.php` отдельным процессом) |
| `planerka/lib-webpush.php` | Web Push без библиотек: VAPID (RFC 8292) + шифрование aes128gcm (RFC 8291) |
| `planerka/gen-vapid.php` | создать ключи уведомлений отдельно (если у PHP из `admin.php init` не было openssl) |
| `planerka/sw.js` | сервис-воркер: показ уведомлений, открытие нужной задачи, работа без интернета |
| `planerka/src.html` | исходник страницы: вся вёрстка и логика интерфейса (один IIFE на чистом JS) |
| `planerka/app.css` | общий базовый стиль (взят из родственного приложения Los Todos; `/*FONTS*/` подставляет сборка) |
| `planerka/build.py` | сборка `app.html` + `build.txt` из `src.html`, `app.css`, шрифтов и значков |
| `planerka/.htaccess` | маршрутизация для Apache |
| `planerka/fonts/Inter-*.woff2`, `OFL.txt` | шрифт Inter (кириллица, латиница), вшивается в страницу; его лицензия |
| `planerka/assets/*.png` | значки приложения (192, 512, бейдж уведомления, favicon, apple-touch, логотип в шапке) и постер `og.png` 1200×630 |

Создаются сами: `planerka/app.html`, `planerka/build.txt` (сборка) и папка данных (`admin.php init`).

## 4. Настройки — `planerka/config.php`

| Константа | Переменная окружения | По умолчанию | Смысл |
|---|---|---|---|
| `PL_SITE` | `PLANERKA_SITE` | `https://example.com` | внешний адрес сайта без `/` в конце; из него — абсолютные `og:url`/`og:image` и подпись уведомлений |
| `PL_DATA` | `PLANERKA_DIR` | `../planerka-data` (рядом с папкой приложения) | папка данных. **Только вне веб-корня**: там ключи доступа |
| `PL_PHP_CLI` | `PLANERKA_PHP` | `php` | PHP 7.3+ с openssl и curl для `push-send.php` (полный путь, если в PATH другой PHP) |
| `PL_TZ` | `PLANERKA_TZ` | `Asia/Bishkek` | часовой пояс («сегодня», «просрочено» в напоминаниях) |

Что вшито в код и меняется поиском-заменой:
- **Путь `/planerka/`** — во всех файлах (`index.php`, `src.html`, `sw.js`, `push-send.php`, `build.py`), в `src.html`
  ещё и в регулярном выражении `\/planerka\/`. Проще всего оставить приложение по адресу `/planerka/`.
- **Код страны 996** (Кыргызстан) для местных номеров `0555…`/`555…` → `+996555…`: `src.html` (`telHref`, `waHref`)
  и `push-send.php` (`tel_norm`).
- Язык интерфейса — русский; названия приоритетов — `PN` в `src.html` (1 «Очень срочно», 2 «Срочно», 3 «Несрочно», 4 «Потом»).

После правки `src.html` или `app.css` — снова `python planerka/build.py` и выложить `app.html` **вместе с** `build.txt`
(по новому номеру сборки открытые приложения сами перезагрузятся).

## 5. Веб-сервер

Все адреса `/planerka/*` должны попадать в `planerka/index.php` (путь после `/planerka` он берёт из `PATH_INFO`,
а если его нет — из `REQUEST_URI`).

**Apache** — уже настроено файлом `planerka/.htaccess` (нужны `mod_rewrite` и `AllowOverride All`).

**nginx + PHP-FPM:**
```nginx
location = /planerka { return 301 /planerka/; }
location ^~ /planerka/ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/planerka/index.php;   # путь к index.php на диске
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

**Проверка без веб-сервера** (встроенный сервер PHP; уведомлений и сервис-воркера не будет — нет HTTPS):
```bash
cd planerka && PLANERKA_SITE=http://127.0.0.1:8080 php -S 127.0.0.1:8080 index.php
# открыть http://127.0.0.1:8080/planerka/
```

Папку данных отдавать веб-сервером нельзя: по умолчанию она лежит вне папки приложения; `admin.php init`
на всякий случай кладёт в неё `.htaccess` с запретом.

## 6. Администрирование — `admin.php`

Запускать от имени пользователя веб-сервера (иначе он не сможет менять созданные файлы):

| Команда | Что делает |
|---|---|
| `php admin.php init` | создаёт папку данных, ключи `team`/`sync`, пустой `tasks.json` (исполнителей нет), ключи уведомлений. Повторный запуск ничего не перетирает |
| `php admin.php password <пароль>` | пароль Планёрки (от 6 знаков): им исполнители входят на экране «Вход в Планёрку» |
| `php admin.php add <id> <Имя>` | добавить исполнителя. `id` — латиница, цифры, дефис (2–24 знака): это адрес личной ссылки `/planerka/<id>` |
| `php admin.php rename <id> <Имя>` | переименовать |
| `php admin.php remove <id>` | убрать (его открытые задачи вернутся во «Входящие») |
| `php admin.php owner <id>` | постановщик: удаляет любые задачи, чистит историю и статистику, получает уведомления «принята / отказ» |
| `php admin.php list` | исполнители и личные ссылки `https://…/planerka/<id>?k=<ключ>` |
| `php admin.php keys` | ключи `team` и `sync` |
| `php admin.php client-password <пароль>` | пароль внешнего клиента администратора (выдаёт ключ `sync`) — нужен только если пишете такой клиент |

Исполнители видят изменения списка сами (приложение сверяет номер версии данных дважды в секунду).

**Как войти.** Два способа: (1) общий адрес `/planerka/` + пароль Планёрки; затем в меню «⋯ → Кто вы?» выбрать себя;
(2) личная ссылка `/planerka/<id>?k=<ключ>` — сразу открывает «Мои задачи», ключ запоминается в телефоне (дальше
открывается и адрес без ключа). В поле пароля можно вставить и сам ключ `team` (32 знака).

## 7. Что умеет приложение

**Вкладки нижней панели** (переключаются и свайпом влево/вправо):
- **Мои** — только при входе по личной ссылке или после «Кто вы?»: задачи этого исполнителя.
- **Входящие** — задачи без исполнителя (число — на значке вкладки). Исполнителя назначают прямо в строке.
- **Команда** — исполнители с цветной точкой присутствия (зелёная — приложение открыто, жёлтая — свёрнуто,
  красная — закрыто или нет сигнала 30 мин), число задач у каждого, карточка «Задачи команды»,
  «История завершённых», «Статистика». Нажатие на человека — его задачи.
- **Срочное** — открытые задачи с приоритетом «Очень срочно» и «Срочно», исполнитель текстом.
- **Все** — все открытые задачи, сгруппированы: Входящие, затем по исполнителям.

**Шапка:** логотип, «нет сети · ждут: N» (неотправленные правки), кнопка «показать выполненные», меню
(Статистика, Завершённые задачи, Тёмная тема, Выполненные, «Кто вы?», состояние уведомлений,
«Проверить уведомление», время последнего обновления). Плавающая «+» — новая задача.

**Задача (шторка):** название; подробности (ссылки из текста собираются в блок «Ссылки», в списках скрыты);
пункты-чеклист до 50 (отмеченные зачёркиваются и уходят вниз, Enter — следующий пункт); номер контрагента
с кнопками «Контакты» (выбор из контактов телефона, где браузер это умеет; иначе — «Вставить номер» из буфера),
«Позвонить», «WhatsApp»; срок (Сегодня, Завтра, В эти выходные, Следующая неделя, Без срока, свой календарь);
приоритет; исполнитель; «Удалить задачу» — постановщику и тому, кто её поставил. Строки: метка срока
(просрочено / сегодня / завтра / неделя), исполнитель, «Ждёт ответа», отказ с причиной.

**Жизненный цикл:** назначили исполнителя → статус «новая» (ждёт ответа) → у исполнителя сразу окно
«Новая задача: Принять / Отказать / Позже»; отказ — только с причиной, задача возвращается во «Входящие»
с пометкой «Отказ (кто, когда): причина». Назначил сам себе — принята сразу. Выполнено — галочка
(с «Отменить» в плашке 6 секунд).

**Уведомления (Web Push):** исполнителю — о новой задаче (с номером контрагента и кнопками «Позвонить»/«WhatsApp»
на Android); постановщику — «принята» / «отказ: причина». Нажатие открывает именно эту задачу.
Включаются кнопкой «Включить уведомления» в шапке (только при входе по личной ссылке). На iPhone — только
после «Поделиться → На экран Домой» и запуска с экрана «Домой».

**Приветствие:** раз в сутки при входе по личной ссылке — «Доброе утро, Имя!» и дела: просроченные и на сегодня.

**История завершённых:** выполненные задачи по месяцам; «Вернуть» (снова в работу) — любой;
удалить из истории и статистики — только постановщик.

**Статистика:** сейчас в работе / ждут ответа / не назначены; загрузка команды (задачи и доля, ждёт ответа,
просрочено); итоги за квартал или год — выполнено и отказы по каждому, таблицы по кварталам.
Учёт — с первого сохранения после установки; удалённые задачи не учитываются.

**Без интернета:** задачи хранятся в телефоне, правки встают в очередь и уходят сами по порядку, когда сеть
появится; страница открывается из кэша сервис-воркера. Новая сборка на сервере — открытое приложение само
перезагружается. Тёмная тема (по системе или вручную). Окна поднимаются над клавиатурой iPhone.

## 8. Данные (папка `PL_DATA`)

| Файл | Содержимое |
|---|---|
| `keys.json` | `{team, sync}` — ключи доступа (32 hex). `team` — приложение, `sync` — администратор |
| `tasks.json` | `{rev, tasks[], people[{id,name}], owner, ev[], statsFrom}` — всё состояние |
| `auth.json` | `{team_pw, pw}` — хэши паролей (`password_hash`) |
| `push.json` | `{subs[{person, endpoint, p256dh, auth, at, ua}]}` — подписки, до 5 устройств на человека |
| `vapid.json` | `{pem, pub, subject}` — ключ подписи уведомлений |
| `presence.json`, `remind.json`, `pwlimit.json` | присутствие, защита от повторных напоминаний, защита от подбора пароля (8 попыток с адреса / 40 всего за 15 мин) |
| `access.log`, `push.log` | журналы (по 200 КБ, затем `.old`) |
| `backups/tasks-ГГГГММДД.json` | копия раз в сутки, хранится 30 дней |
| `sandbox.flag` (необязательно) | режим песочницы: уведомления не отправляются, а пишутся в `push-sandbox.log` |

**Задача:** `id`, `t` (название, ≤200), `desc` (≤2000), `due` (`ГГГГ-ММ-ДД` или null), `pri` (1–4), `done`, `doneAt` (мс),
`phone` (≤32), `items[{t,d}]` (≤50), `assignee` (id исполнителя или null), `status` (null | `new` | `accepted`),
`assignedAt`, `acceptedAt`, `declines[{by, reason, at}]`, `cby` (кто поставил), `created`, служебные `src`/`srcId`
(задача из внешнего клиента), `del`/`gone` (удалена), `xs` (не учитывать в статистике), `mt` (время правки).
**Событие статистики** `ev`: `{id, p (исполнитель), t: 'd' выполнено | 'x' отказ, at}`.

Сроки хранения: удалённые задачи стираются через 30 дней; выполненные скрываются из списков через 60 дней
(в истории остаются); события статистики — 3 года.

## 9. API — `POST /planerka/api.php`

Тело — JSON `{op: …}`, ключ — заголовок `X-Key`. Ответы — JSON; ошибки: 401/403 (ключ), 400 (данные).

| op | Ключ | Что делает |
|---|---|---|
| `pw.login` `{for:'planerka', password}` | без ключа | пароль → `{team}` (без `for` — пароль клиента → `{sync}`); 409 — пароль не задан, 429 — много попыток |
| `list` `{since?}` | team | `{rev, tasks, people, owner, presence, now, build}`; при совпавшем `since` — короткое `{same:true,…}` |
| `add` `{id, t, desc, phone, due, pri, assignee, items, by}` | team | новая задача (повтор с тем же `id` дубля не создаёт) |
| `upd` `{id, f:{поля}, by}` | team | правка только присланных полей; `f.del` — удаление (постановщик или автор) |
| `resp` `{id, act, reason, by}` (act: `accept` / `decline`) | team | ответ назначенного исполнителя |
| `ping` `{by, s, pid, t}` (s: `open` / `bg` / `closed`) | team | присутствие |
| `stats` | team | события статистики |
| `hist` / `hist.del` `{id, undo?, by}` | team или sync | история завершённых; удаление — постановщик (или ключ sync) |
| `push.key`, `push.sub` `{person, sub}`, `push.unsub` `{endpoint}`, `push.test` `{person}` | team | уведомления |
| `sync` `{people?, upsert?, remove?}`, `pull`, `info`, `pw.status`, `pw.set`, `remind` `{id, check?}` | sync | для внешнего клиента администратора: список исполнителей целиком, обмен задачами по полям, ключ team, пароли, напоминание исполнителю |

## 10. Проверка после установки

```bash
S=https://ваш-домен
curl -s -o /dev/null -w '%{http_code}\n' $S/planerka/                         # 200
curl -s -A "facebookexternalhit/1.1" $S/planerka/ | grep -o '<meta property="og:[^>]*>'   # абсолютные og:url и og:image
curl -s -o /dev/null -w '%{http_code} %{content_type}\n' $S/planerka/og.png   # 200 image/png
curl -s -X POST $S/planerka/api.php -H 'Content-Type: application/json' \
     -d '{"op":"pw.login","for":"planerka","password":"ваш пароль"}'           # {"team":"…"}
curl -s -X POST $S/planerka/api.php -H "X-Key: <team>" -d '{"op":"list"}'      # tasks: [] и ваши исполнители
```

Превью ссылки (WhatsApp, Telegram): постер `assets/og.png` 1200×630, теги `og:*` и `twitter:card` абсолютные,
`noindex` нет. WhatsApp кэширует превью на дни — для проверки добавлять к ссылке `?1`.
Уведомления: открыть личную ссылку по HTTPS → «Включить уведомления» → меню «⋯ → Проверить уведомление».

### Лицензия

Код приложения — лицензия MIT; шрифт Inter (`planerka/fonts/Inter-*.woff2`) — SIL Open Font License 1.1,
её текст распаковывается в `planerka/fonts/OFL.txt`.

```text
MIT License

Copyright (c) 2026 marcelkg-collab

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

## 11. Распаковщик

Сохранить как `extract.py` и запустить `python extract.py PLANERKA.md <папка>` — создаст `<папка>/planerka/…`,
проверит контрольную сумму SHA-256 каждого файла и число файлов.

```python
import base64, hashlib, os, re, sys
md = open(sys.argv[1], encoding='utf8').read().replace('\r\n', '\n')
out = sys.argv[2] if len(sys.argv) > 2 else '.'
want = int(re.search(r'<!-- FILES (\d+) -->', md).group(1))
pat = re.compile(r'<!-- FILE (\S+) (text|base64) sha256=([0-9a-f]{64}) -->\n(`{4,})[^\n]*\n(.*?)\n\4\n<!-- END FILE -->', re.S)
n = 0
for path, kind, sha, fence, body in pat.findall(md):
    data = base64.b64decode(''.join(body.split())) if kind == 'base64' else (body + '\n').encode('utf8')
    if hashlib.sha256(data).hexdigest() != sha:
        sys.exit('контрольная сумма не сошлась: ' + path)
    dst = os.path.join(out, *path.split('/'))
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    open(dst, 'wb').write(data)
    n += 1
    print('  ' + path)
if n != want:
    sys.exit('распаковано %d файлов из %d' % (n, want))
print('готово: %d файлов' % n)
```

---

## 12. Код

<!-- FILES 22 -->

### `planerka/config.php` — настройки сервера — правится под новый сервер (21 строк)

<!-- FILE planerka/config.php text sha256=b9ed992771a70af126a8e72c340d194c8d4961fdf699a8560022c0dc1e0dff67 -->
````php
<?php
// ─────────────────────────────────────────────────────────────
//  Планёрка — настройки сервера. Единственный файл, который правят под новый сервер.
//  Любое значение можно задать и переменной окружения (она важнее файла).
// ─────────────────────────────────────────────────────────────

// Внешний адрес сайта, БЕЗ слеша в конце и без /planerka. Нужен для превью ссылок
// (og:url, og:image — только абсолютные) и для подписи уведомлений (VAPID).
if (!defined('PL_SITE')) define('PL_SITE', rtrim(getenv('PLANERKA_SITE') ?: 'https://example.com', '/'));

// Папка данных: tasks.json, keys.json, auth.json, push.json, vapid.json, журналы, backups/.
// ДОЛЖНА лежать ВНЕ веб-корня (там ключи доступа). Веб-сервер должен иметь право писать в неё.
if (!defined('PL_DATA')) define('PL_DATA', getenv('PLANERKA_DIR') ?: dirname(__DIR__) . '/planerka-data');

// PHP для отправки уведомлений (push-send.php). Нужен PHP 7.3+ с расширениями openssl и curl.
// Веб-PHP может быть и без них — уведомления отправляет этот внешний процесс.
if (!defined('PL_PHP_CLI')) define('PL_PHP_CLI', getenv('PLANERKA_PHP') ?: 'php');

// Часовой пояс: «сегодня», «просрочено» в напоминаниях, даты в журналах.
if (!defined('PL_TZ')) define('PL_TZ', getenv('PLANERKA_TZ') ?: 'Asia/Bishkek');
date_default_timezone_set(PL_TZ);
````
<!-- END FILE -->

### `planerka/index.php` — точка входа и маршруты (87 строк)

<!-- FILE planerka/index.php text sha256=291a3548115770d342eb5c5c7705d88dd3dd1681d48591dd4aeb0f7c0462ecb1 -->
````php
<?php
// Страница Планёрки и личные ссылки исполнителей: /planerka/<имя> (например /planerka/ivan?k=<ключ>).
// Веб-сервер ведёт ВСЕ адреса /planerka/* на этот файл (front controller). Путь после /planerka берётся
// из PATH_INFO, а если его нет — из REQUEST_URI. /planerka/api.php тоже идёт через этот файл и отдаётся обработчику.
require_once __DIR__ . '/config.php';
$pi = isset($_SERVER['PATH_INFO']) ? (string)$_SERVER['PATH_INFO'] : '';
if ($pi === '' || $pi === '/') $pi = (string)parse_url(isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
// у одних серверов в пути есть /planerka и /index.php (встроенный php -S, Apache), у других — уже нет (прокси, срезающий префикс)
if ($pi === '/planerka' || strpos($pi, '/planerka/') === 0) $pi = (string)substr($pi, 9);
if ($pi === '/index.php' || strpos($pi, '/index.php/') === 0) $pi = (string)substr($pi, 10);
if ($pi === '/') $pi = '';
$dirData = PL_DATA;
if ($pi === '/api.php') { require __DIR__ . '/api.php'; exit; }

// Служебные файлы приложения: сервис-воркер, значки, постер, манифест (для установки на экран «Домой» с ключом в ссылке)
if ($pi === '/sw.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /planerka/');
    header('Cache-Control: no-cache');
    readfile(__DIR__ . '/sw.js'); exit;
}
// /planerka/dial?n=+996... — открыть набор номера (кнопка «Позвонить» в уведомлении). Данных здесь нет.
if ($pi === '/dial') {
    $n = isset($_GET['n']) ? preg_replace('/[^0-9+]/', '', (string)$_GET['n']) : '';
    header('Content-Type: text/html; charset=utf-8');
    $h = htmlspecialchars($n, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Позвонить</title>'
       . '<style>body{margin:0;font:600 20px system-ui,sans-serif;background:#FBF8F2;color:#0E1626;display:grid;place-items:center;min-height:100vh;padding:16px}a{display:block;padding:18px 26px;border-radius:16px;background:#0B3FD0;color:#fff;text-decoration:none;text-align:center}</style></head><body>'
       . ($h !== '' ? '<a href="tel:' . $h . '">Позвонить ' . $h . '</a><script>location.href="tel:' . $h . '";</script>' : 'Номер не указан')
       . '</body></html>';
    exit;
}
if ($pi === '/icon-192.png' || $pi === '/icon-512.png' || $pi === '/badge.png') {
    header('Content-Type: image/png'); header('Cache-Control: public, max-age=86400');
    readfile(__DIR__ . '/assets/' . substr($pi, 1)); exit;
}
// постер превью ссылки (WhatsApp, Telegram): 1200×630, открывается напрямую — 200 и image/png
if ($pi === '/og.png') {
    header('Content-Type: image/png'); header('Cache-Control: public, max-age=86400');
    readfile(__DIR__ . '/assets/og.png'); exit;
}
function key_ok($dir, $k) {
    $keys = @json_decode(@file_get_contents($dir . '/keys.json'), true);
    return is_array($keys) && !empty($keys['team']) && is_string($k) && $k !== '' && hash_equals((string)$keys['team'], $k);
}
if ($pi === '/manifest.json') {
    $k = isset($_GET['k']) ? (string)$_GET['k'] : '';
    $p = isset($_GET['p']) && preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $_GET['p']) ? $_GET['p'] : '';
    if (!key_ok($dirData, $k)) { http_response_code(401); exit; }
    $start = '/planerka/' . ($p !== '' ? $p . '?k=' . $k : '?k=' . $k);
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode(['name' => 'Планёрка', 'short_name' => 'Планёрка', 'id' => '/planerka/' . $p, 'start_url' => $start, 'scope' => '/planerka/',
        'display' => 'standalone', 'background_color' => '#FBF8F2', 'theme_color' => '#FBF8F2', 'lang' => 'ru',
        'icons' => [['src' => '/planerka/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => '/planerka/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
$html = @file_get_contents(__DIR__ . '/app.html');
if ($html === false) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); echo 'Планёрка не собрана: запустите python build.py'; exit; }

// Личная ссылка: в превью (WhatsApp) и заголовке вкладки — имя исполнителя. Данных, кроме имени, здесь нет.
$slug = trim($pi, '/');
if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $slug)) {
    $db = @json_decode(@file_get_contents($dirData . '/tasks.json'), true);
    $people = (is_array($db) && isset($db['people']) && is_array($db['people'])) ? $db['people'] : [];
    foreach ($people as $p) {
        if (isset($p['id']) && $p['id'] === $slug) {
            $n = htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8');
            $html = str_replace('<title>Планёрка</title>', '<title>Планёрка · ' . $n . '</title>', $html);
            $html = str_replace('<meta property="og:title" content="Планёрка">', '<meta property="og:title" content="Планёрка · ' . $n . '">', $html);
            $html = str_replace('<meta property="og:url" content="%%SITE%%/planerka/">',
                '<meta property="og:url" content="%%SITE%%/planerka/' . $slug . '">', $html);
            break;
        }
    }
}
// манифест для «На экран Домой»: ссылка с ключом, когда ключ в адресе верный
if ($slug !== '' && isset($_GET['k']) && key_ok($dirData, (string)$_GET['k'])) {
    $mf = '<link rel="manifest" href="/planerka/manifest.json?p=' . $slug . '&k=' . rawurlencode((string)$_GET['k']) . '">';
    $html = str_replace('<title>', $mf . "\n<title>", $html);
}
// абсолютные адреса превью (og:url, og:image) — из config.php
$html = str_replace('%%SITE%%', htmlspecialchars(PL_SITE, ENT_QUOTES, 'UTF-8'), $html);
echo $html;
````
<!-- END FILE -->

### `planerka/api.php` — API и хранилище (564 строк)

<!-- FILE planerka/api.php text sha256=dcf8b1caddd375e5debe3cab030c1e2e65ba2a8db3c6bd904a3cb6fdb5ec9fd7 -->
````php
<?php
// Планёрка: общее хранилище задач (PHP 7.0-совместимо, без расширений).
// Данные: папка PL_DATA из config.php — {tasks.json, keys.json, auth.json, push.json, backups/}. В git не кладутся.
//
// Два ключа, два права:
//   team — открывает Планёрку: читать, добавлять, менять, назначать исполнителя, удалять;
//   sync — ключ администратора (внешнее приложение-клиент): pull (прочитать все задачи), sync (записать
//          изменения и список исполнителей), info (выдаёт ключ team для личных ссылок), remind, pw.*.
//
// Список исполнителей и пароль ведёт администратор: консольный admin.php (работает с файлами напрямую)
// или внешний клиент с ключом sync (op=sync с полем people). Двусторонний обмен с внешним клиентом:
//   • задача, созданная во внешнем клиенте, приходит событием upsert (src='todos', srcId = id у клиента);
//   • задача, созданная здесь (src=null), забирается клиентом через pull;
//   • правки идут по полям: клиент шлёт только изменённые поля, поэтому не затирает чужие изменения.

// Встраивание: другой PHP-код может подключить этот файл изнутри, заранее задав папку данных ($GLOBALS['PLANERKA_DIR_FORCE']),
// тело запроса ($GLOBALS['PLANERKA_INPUT']) и доверие (константы PLANERKA_TRUSTED / PLANERKA_TRUSTED_ADMIN) — ключ тогда не нужен.
require_once __DIR__ . '/config.php';
$DIR = isset($GLOBALS['PLANERKA_DIR_FORCE']) ? (string)$GLOBALS['PLANERKA_DIR_FORCE'] : PL_DATA;
putenv('PLANERKA_DIR=' . $DIR);   // фоновые процессы (уведомления) работают с данными этого же дома
function pl_input() { return isset($GLOBALS['PLANERKA_INPUT']) ? (string)$GLOBALS['PLANERKA_INPUT'] : (string)file_get_contents('php://input'); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($a, $code = 200) {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}
function cut($s, $n) {
    $s = trim((string)$s);
    if (function_exists('mb_substr')) return mb_substr($s, 0, $n, 'UTF-8');
    $a = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return $a ? implode('', array_slice($a, 0, $n)) : '';
}
// номер контрагента: только цифры, +, скобки, дефис и пробел; до 32 знаков
function phone_ok($v) { return substr(trim(preg_replace('/[^0-9+()\-\s]/', '', (string)$v)), 0, 32); }
function due_ok($v) { return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null; }
function pri_ok($v) { $v = (int)$v; return ($v >= 1 && $v <= 4) ? $v : 4; }
function id_ok($v) { return (is_string($v) && preg_match('/^[a-z0-9]{4,40}$/', $v)) ? $v : null; }
function slug_ok($v) { return (is_string($v) && preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $v) && !in_array($v, ['api', 'index'], true)) ? $v : null; }

function default_people() { return []; }   // исполнителей нет, пока их не добавит администратор (php admin.php add …)
function people_ids($people) { $r = []; foreach ($people as $p) $r[$p['id']] = 1; return $r; }
// пункты задачи: [{t: текст, d: выполнен}], до 50 штук; принимает массив или JSON-строку
function items_ok($v) {
    if (is_string($v)) $v = @json_decode($v, true);
    $r = [];
    if (!is_array($v)) return $r;
    foreach ($v as $x) {
        if (!is_array($x) || !isset($x['t']) || !is_string($x['t'])) continue;
        $t = cut($x['t'], 200);
        if ($t === '') continue;
        $r[] = ['t' => $t, 'd' => !empty($x['d'])];
        if (count($r) >= 50) break;
    }
    return $r;
}
function who_ok($v, $db) { $ids = people_ids($db['people']); return (is_string($v) && isset($ids[$v])) ? $v : null; }

$keys = @json_decode(@file_get_contents($DIR . '/keys.json'), true);
if (!is_array($keys) || empty($keys['team'])) out(['error' => 'not configured'], 503);

// Вход ПАРОЛЕМ вместо ключа. Два пароля: team_pw — пароль Планёрки для исполнителей (выдаёт ключ team),
// pw — пароль внешнего клиента администратора (выдаёт ключ sync). Задаёт администратор (php admin.php password …);
// хранится только хэш (password_hash). Вход по паролю выдаёт ключ — дальше приложение работает с ключом.
// Защита от подбора: 8 неудачных попыток с одного адреса или 40 всего за 15 минут — пауза. IP не храним, только отпечаток.
$in0 = json_decode(pl_input(), true);
if (is_array($in0) && isset($in0['op']) && $in0['op'] === 'pw.login') {
    $ipRaw = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : '';
    if ($ipRaw === '') $ipRaw = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
    $ipf = substr(sha1('planerka|' . $ipRaw), 0, 16);
    $limF = $DIR . '/pwlimit.json'; $t0 = time();
    $L = @json_decode(@file_get_contents($limF), true); if (!is_array($L)) $L = [];
    $tot = 0;
    foreach ($L as $kk => $vv) { $L[$kk] = array_values(array_filter((array)$vv, function ($x) use ($t0) { return $x > $t0 - 900; })); if (!$L[$kk]) unset($L[$kk]); else $tot += count($L[$kk]); }
    if ((isset($L[$ipf]) && count($L[$ipf]) >= 8) || $tot >= 40) out(['error' => 'too many'], 429);
    $A = @json_decode(@file_get_contents($DIR . '/auth.json'), true);
    $forTeam = isset($in0['for']) && $in0['for'] === 'planerka';   // пароль Планёрки (исполнители) или пароль клиента администратора
    $field = $forTeam ? 'team_pw' : 'pw';
    if (!is_array($A) || empty($A[$field])) out(['error' => 'no password'], 409);
    $pw = isset($in0['password']) ? (string)$in0['password'] : '';
    if ($pw !== '' && password_verify($pw, $A[$field])) out($forTeam ? ['team' => $keys['team']] : ['sync' => $keys['sync']]);
    $L[$ipf][] = $t0;
    @file_put_contents($limF, json_encode($L)); @chmod($limF, 0660);
    sleep(1);
    out(['error' => 'wrong'], 403);
}
$k = isset($_SERVER['HTTP_X_KEY']) ? (string)$_SERVER['HTTP_X_KEY'] : '';
$isTeam = defined('PLANERKA_TRUSTED') || ($k !== '' && hash_equals((string)$keys['team'], $k));
$isSync = defined('PLANERKA_TRUSTED_ADMIN') || ($k !== '' && !empty($keys['sync']) && hash_equals((string)$keys['sync'], $k));
function alog($dir, $line) {
    $f = $dir . '/access.log';
    if (@filesize($f) > 200000) @rename($f, $f . '.old');
    @file_put_contents($f, date('c') . ' ' . $line . ' ua=' . substr(preg_replace('/\s+/', ' ', isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 70) . "\n", FILE_APPEND);
}
if (!$isTeam && !$isSync) { alog($DIR, 'DENIED keylen=' . strlen($k)); out(['error' => 'auth'], 401); }

$in = json_decode(pl_input(), true);
if (!is_array($in)) $in = ['op' => 'list'];
$op = isset($in['op']) ? (string)$in['op'] : 'list';
$adminOps = ['sync', 'info', 'pull', 'pw.status', 'pw.set', 'hist', 'hist.del', 'remind'];
$bothOps = ['hist', 'hist.del'];   // история завершённых: клиент администратора (ключ sync) и Планёрка (ключ team)
if (!in_array($op, $bothOps, true)) {
    if (in_array($op, $adminOps, true) && !$isSync) out(['error' => 'auth'], 403);
    if (!in_array($op, $adminOps, true) && !$isTeam) out(['error' => 'auth'], 403);
}
if ($op === 'info') out(['team' => $keys['team']]);
if ($op === 'pw.status') {
    $A = @json_decode(@file_get_contents($DIR . '/auth.json'), true);
    out(['set' => is_array($A) && !empty($A['pw']), 'teamSet' => is_array($A) && !empty($A['team_pw'])]);
}
if ($op === 'pw.set') {
    $new = isset($in['password']) ? (string)$in['password'] : '';
    if (preg_match_all('/./u', $new) < 6) out(['error' => 'short'], 400);
    $A = @json_decode(@file_get_contents($DIR . '/auth.json'), true);
    if (!is_array($A)) $A = [];
    if (isset($in['for']) && $in['for'] === 'planerka') {
        // пароль Планёрки задаёт и меняет только владелец ключа sync (администратор); старый пароль не нужен
        $A['team_pw'] = password_hash($new, PASSWORD_DEFAULT); $A['team_at'] = date('c');
    } else {
        if (!empty($A['pw'])) {
            $old = isset($in['old']) ? (string)$in['old'] : '';
            if (!password_verify($old, $A['pw'])) out(['error' => 'old'], 403);
        }
        $A['pw'] = password_hash($new, PASSWORD_DEFAULT); $A['at'] = date('c');
    }
    $af = $DIR . '/auth.json'; $tmp = $af . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($A));
    @chmod($tmp, 0660); rename($tmp, $af);
    out(['ok' => true]);
}

// Присутствие: приложение исполнителя открыто / свёрнуто / закрыто. Отдельный файл, версию задач не трогает.
function app_build() { $b = @file_get_contents(__DIR__ . '/build.txt'); return $b ? trim($b) : ''; }
function pres_load($dir) { $p = @json_decode(@file_get_contents($dir . '/presence.json'), true); return is_array($p) ? $p : []; }
function ms_now() { return (int)(microtime(true) * 1000); }
if ($op === 'ping') {
    $q = @json_decode(@file_get_contents($DIR . '/tasks.json'), true);
    $ids = (is_array($q) && isset($q['people']) && is_array($q['people'])) ? people_ids($q['people']) : [];
    $by = (isset($in['by']) && is_string($in['by']) && isset($ids[$in['by']])) ? $in['by'] : null;
    $st = isset($in['s']) ? (string)$in['s'] : '';
    if (!$by || !in_array($st, ['open', 'bg', 'closed'], true)) out(['error' => 'bad'], 400);
    $pf = $DIR . '/presence.json';
    $lk = @fopen($pf . '.lock', 'c'); if ($lk) flock($lk, LOCK_EX);
    $p = pres_load($DIR);
    $pid = isset($in['pid']) ? substr(preg_replace('/[^a-z0-9]/', '', (string)$in['pid']), 0, 24) : '';
    $ct = isset($in['t']) ? (int)$in['t'] : 0;
    $old = isset($p[$by]) ? $p[$by] : null;
    if ($old && $pid !== '' && isset($old['pid']) && $old['pid'] === $pid && $ct > 0 && isset($old['t']) && $ct < $old['t']) out(['ok' => true, 'stale' => true]);
    $p[$by] = ['s' => $st, 'at' => ms_now(), 'pid' => $pid, 't' => $ct];
    $tmp = $pf . '.tmp' . getmypid(); file_put_contents($tmp, json_encode($p)); @chmod($tmp, 0660); rename($tmp, $pf);
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
    out(['ok' => true]);
}
// Быстрый опрос: если версия данных совпадает с присланной since, отвечаем коротко и без блокировки
if (($op === 'list' || $op === 'pull') && isset($in['since'])) {
    $q = @json_decode(@file_get_contents($DIR . '/tasks.json'), true);
    if (is_array($q) && isset($q['rev']) && (int)$q['rev'] === (int)$in['since']) out(['rev' => (int)$q['rev'], 'same' => true, 'presence' => pres_load($DIR), 'now' => ms_now(), 'build' => app_build()]);
}

if (!(($op === 'list' || $op === 'pull') && isset($in['since']))) alog($DIR, ($isSync ? 'sync-key ' : 'team-key ') . $op . (isset($in['upsert']) ? ' up=' . count((array)$in['upsert']) : '') . (isset($in['remove']) ? ' rm=' . count((array)$in['remove']) : ''));

// Напоминание исполнителю о незавершённой задаче — для клиента администратора (только ключ sync).
// check=true — проверка: ТО ЖЕ уведомление приходит постановщику (owner), чтобы он увидел его своими глазами.
// Отправка синхронная: ответ говорит, сколько устройств приняли уведомление (честнее, чем «поставлено в очередь»).
// Блокировку задач здесь не берём: push-send.php сам ненадолго берёт её, чтобы прочитать подписки.
// Ответ всегда 200 с ok/why — это не ошибки сервера, а то, что надо показать человеку.
if ($op === 'remind') {
    $q = @json_decode(@file_get_contents($DIR . '/tasks.json'), true);
    if (!is_array($q) || !isset($q['tasks']) || !is_array($q['tasks'])) out(['ok' => false, 'why' => 'notask']);
    if (!isset($q['people']) || !is_array($q['people'])) $q['people'] = default_people();
    $owner = (isset($q['owner']) && is_string($q['owner'])) ? $q['owner'] : '';
    $id = id_ok(isset($in['id']) ? $in['id'] : null);
    $t = null;
    foreach ($q['tasks'] as $x) if ($id !== null && $x['id'] === $id) { $t = $x; break; }
    if (!$t || !empty($t['del']) || !empty($t['gone'])) out(['ok' => false, 'why' => 'notask']);
    if (!empty($t['done'])) out(['ok' => false, 'why' => 'done']);
    $who = who_ok(isset($t['assignee']) ? $t['assignee'] : null, $q);
    if (!$who) out(['ok' => false, 'why' => 'noassignee']);
    if (is_file($DIR . '/sandbox.flag')) out(['ok' => false, 'why' => 'sandbox']);   // песочница: напоминания не отправляются
    $check = !empty($in['check']);
    $to = $check ? $owner : $who;
    $store = @json_decode(@file_get_contents($DIR . '/push.json'), true);
    $dev = 0;
    if (is_array($store) && isset($store['subs']) && is_array($store['subs'])) foreach ($store['subs'] as $x) if (($x['person'] ?? '') === $to) $dev++;
    if (!$dev) out(['ok' => false, 'why' => 'nosub', 'to' => $to]);
    // защита от двойного нажатия и потока напоминаний: одно и то же — не чаще раза в минуту (проверка — раз в 10 с).
    // Учитываются только доставленные: если не дошло, повторить можно сразу.
    $win = $check ? 10000 : 60000;
    $rk = $t['id'] . '|' . $to . ($check ? '|check' : '');
    $rf = $DIR . '/remind.json';
    $now = ms_now();
    $rl = @fopen($rf . '.lock', 'c'); if ($rl) flock($rl, LOCK_EX);
    $R = @json_decode(@file_get_contents($rf), true); if (!is_array($R)) $R = [];
    foreach ($R as $kk => $vv) if ($vv < $now - 86400000) unset($R[$kk]);
    if (isset($R[$rk]) && $now - $R[$rk] < $win) {
        if ($rl) { flock($rl, LOCK_UN); fclose($rl); }
        out(['ok' => false, 'why' => 'pause', 'wait' => (int)ceil(($win - ($now - $R[$rk])) / 1000), 'to' => $to]);
    }
    $R[$rk] = $now;
    $tmp = $rf . '.tmp' . getmypid(); file_put_contents($tmp, json_encode($R)); @chmod($tmp, 0660); rename($tmp, $rf);
    // файл замка закрываем ДО запуска внешнего процесса: он наследует открытые файлы (грабли 09.10.2026)
    if ($rl) { flock($rl, LOCK_UN); fclose($rl); }
    $php = PL_PHP_CLI;
    $n = ['type' => 'remind', 'person' => $to, 'about' => $who, 'task' => $t['id'], 'title' => $t['t'], 'check' => $check];
    $lines = []; $rc = 0;
    @exec(escapeshellcmd($php) . ' ' . escapeshellarg(__DIR__ . '/push-send.php') . ' ' . escapeshellarg(base64_encode(json_encode($n, JSON_UNESCAPED_UNICODE))) . ' 2>/dev/null', $lines, $rc);
    $res = null;
    foreach ($lines as $l) if (strpos($l, 'RESULT ') === 0) $res = @json_decode(substr($l, 7), true);
    $sent = is_array($res) && isset($res['sent']) ? (int)$res['sent'] : 0;
    if ($sent < 1) {
        // не дошло — снимаем отметку, чтобы можно было сразу повторить
        $rl = @fopen($rf . '.lock', 'c'); if ($rl) flock($rl, LOCK_EX);
        $R = @json_decode(@file_get_contents($rf), true);
        if (is_array($R) && isset($R[$rk]) && $R[$rk] === $now) { unset($R[$rk]); $tmp = $rf . '.tmp' . getmypid(); file_put_contents($tmp, json_encode($R)); @chmod($tmp, 0660); rename($tmp, $rf); }
        if ($rl) { flock($rl, LOCK_UN); fclose($rl); }
    }
    alog($DIR, 'remind ' . ($check ? 'check ' : '') . $t['id'] . ' to=' . $to . ' devices=' . $dev . ' sent=' . $sent);
    out(['ok' => $sent > 0, 'why' => $sent > 0 ? null : 'fail', 'to' => $to, 'devices' => $dev, 'sent' => $sent, 'at' => $now]);
}

$lock = fopen($DIR . '/lock', 'c');
if (!$lock) out(['error' => 'storage'], 500);
flock($lock, LOCK_EX);

$file = $DIR . '/tasks.json';
$db = @json_decode(@file_get_contents($file), true);
if (!is_array($db) || !isset($db['tasks']) || !is_array($db['tasks'])) $db = ['rev' => 0, 'tasks' => []];
if (!isset($db['people']) || !is_array($db['people'])) $db['people'] = default_people();
// Журнал статистики: события «выполнено» (d) и «отказ» (x) пишутся при сохранении, сравнением с тем, что было до запроса.
// Считается с момента statsFrom; события удалённых задач (флаг xs) в статистику не входят.
if (!isset($db['ev']) || !is_array($db['ev'])) $db['ev'] = [];
function ledger_snap($db) {
    $r = [];
    foreach ($db['tasks'] as $t) $r[$t['id']] = ['done' => !empty($t['done']), 'nd' => (isset($t['declines']) && is_array($t['declines'])) ? count($t['declines']) : 0];
    return $r;
}
$ORIG = ledger_snap($db);

function find_by(&$db, $field, $val) {
    if ($val === null) return -1;
    foreach ($db['tasks'] as $i => $t) if (isset($t[$field]) && $t[$field] === $val) return $i;
    return -1;
}
function ledger(&$db) {
    global $ORIG;
    if (empty($db['statsFrom'])) $db['statsFrom'] = (int)(microtime(true) * 1000);
    foreach ($db['tasks'] as $t) {
        $id = $t['id'];
        $o = isset($ORIG[$id]) ? $ORIG[$id] : ['done' => false, 'nd' => 0];
        $done = !empty($t['done']);
        if ($done && !$o['done'] && !empty($t['assignee'])) $db['ev'][] = ['id' => $id, 'p' => $t['assignee'], 't' => 'd', 'at' => (int)(!empty($t['doneAt']) ? $t['doneAt'] : microtime(true) * 1000)];
        if (!$done && $o['done']) $db['ev'] = array_values(array_filter($db['ev'], function ($e) use ($id) { return !($e['id'] === $id && $e['t'] === 'd'); }));
        $dl = (isset($t['declines']) && is_array($t['declines'])) ? array_values($t['declines']) : [];
        for ($k = $o['nd']; $k < count($dl); $k++) if (!empty($dl[$k]['by']) && !empty($dl[$k]['at'])) $db['ev'][] = ['id' => $id, 'p' => $dl[$k]['by'], 't' => 'x', 'at' => (int)$dl[$k]['at']];
    }
    $ORIG = ledger_snap($db);
}
function save_db(&$db, $dir, $file) {
    $db['rev'] = (int)$db['rev'] + 1;
    ledger($db);
    // удалённые и снятые давно (30+ дней) убираем совсем; события удалённых уходят вместе с ними,
    // а у выполненных, убранных очисткой во внешнем клиенте, остаются в статистике
    $lim = time() - 30 * 86400;
    $drop = [];
    $db['tasks'] = array_values(array_filter($db['tasks'], function ($t) use ($lim, &$drop) {
        if (empty($t['del']) && empty($t['gone'])) return true;
        if (!isset($t['mt']) || $t['mt'] / 1000 > $lim) return true;
        if (!empty($t['xs'])) $drop[$t['id']] = 1;
        return false;
    }));
    $old = (time() - 3 * 366 * 86400) * 1000;
    $db['ev'] = array_values(array_filter($db['ev'], function ($e) use ($drop, $old) { return !isset($drop[$e['id']]) && $e['at'] > $old; }));
    $tmp = $file . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($db, JSON_UNESCAPED_UNICODE));
    @chmod($tmp, 0660);
    rename($tmp, $file);
    // копия за сутки, хранится 30 дней
    $bk = $dir . '/backups';
    if (!is_dir($bk)) @mkdir($bk, 0770, true);
    $f = $bk . '/tasks-' . date('Ymd') . '.json';
    if (!file_exists($f)) {
        @copy($file, $f);
        foreach ((array)glob($bk . '/tasks-*.json') as $old) if (filemtime($old) < time() - 30 * 86400) @unlink($old);
    }
}
function norm($t, $db) {
    $ids = people_ids($db['people']);
    // исполнитель удалён из списка — задача снова во Входящих
    if (!empty($t['assignee']) && !isset($ids[$t['assignee']])) $t['assignee'] = null;
    return $t;
}
function visible($db) {
    $r = [];
    $limit = time() - 60 * 86400;
    foreach ($db['tasks'] as $t) {
        if (!empty($t['del']) || !empty($t['gone'])) continue;
        if (!empty($t['done']) && !empty($t['doneAt']) && $t['doneAt'] / 1000 < $limit) continue;
        $t = norm($t, $db);
        unset($t['srcId'], $t['srcDone'], $t['mt']);
        $r[] = $t;
    }
    return $r;
}
function reply($db) { global $OWNER, $DIR; out(['rev' => (int)$db['rev'], 'tasks' => visible($db), 'people' => $db['people'], 'owner' => $OWNER, 'presence' => pres_load($DIR), 'now' => ms_now(), 'build' => app_build()]); }

// применить к задаче только присланные поля
// Исполнитель назначен/снят/сменён. Жизненный цикл: new (ждёт ответа) -> accepted; отказ возвращает задачу во «Входящие».
// Назначил сам себе (actor == исполнитель) — принято сразу.
function set_assignee(&$t, $new, $actor, $now) {
    $old = $t['assignee'] ?? null;
    if ($new === $old) return;
    $t['assignee'] = $new;
    if ($new === null) { $t['status'] = null; $t['assignedAt'] = null; $t['acceptedAt'] = null; return; }
    $t['assignedAt'] = $now;
    if ($actor !== null && $actor === $new) { $t['status'] = 'accepted'; $t['acceptedAt'] = $now; }
    else { $t['status'] = 'new'; $t['acceptedAt'] = null; }
}
function apply_fields(&$t, $f, $db, $now, $actor = null) {
    if (isset($f['t'])) { $v = cut($f['t'], 200); if ($v !== '') $t['t'] = $v; }
    if (array_key_exists('desc', $f)) $t['desc'] = cut($f['desc'], 2000);
    if (array_key_exists('phone', $f)) $t['phone'] = phone_ok($f['phone']);
    if (array_key_exists('items', $f)) $t['items'] = items_ok($f['items']);
    if (array_key_exists('due', $f)) $t['due'] = due_ok($f['due']);
    if (isset($f['pri'])) $t['pri'] = pri_ok($f['pri']);
    if (array_key_exists('assignee', $f)) set_assignee($t, who_ok($f['assignee'], $db), $actor, $now);
    // время выполнения меняется только при НАСТОЯЩЕЙ смене отметки (повторная отправка того же значения его не сдвигает)
    if (array_key_exists('done', $f) && (bool)$f['done'] !== !empty($t['done'])) { $t['done'] = (bool)$f['done']; $t['doneAt'] = $t['done'] ? $now : null; }
    $t['mt'] = $now;
}
function new_task($id, $title, $f, $db, $now, $actor = null) {
    $t = ['id' => $id, 't' => $title, 'desc' => '', 'due' => null, 'pri' => 4, 'done' => false, 'doneAt' => null,
          'phone' => '', 'items' => [], 'assignee' => null, 'status' => null, 'assignedAt' => null, 'acceptedAt' => null, 'declines' => [], 'src' => null, 'created' => $now];
    apply_fields($t, $f, $db, $now, $actor);
    $t['t'] = $title;
    return $t;
}

$changed = false;
$now = (int)(microtime(true) * 1000);
$notes = [];   // кому и о какой задаче отправить уведомление после сохранения
$OWNER = (isset($db['owner']) && is_string($db['owner'])) ? $db['owner'] : '';   // постановщик (php admin.php owner …): может удалять любые задачи, ответы исполнителей уведомляют его

// Уведомление исполнителю: внешний PHP (PL_PHP_CLI) в фоне — веб-PHP может быть без openssl и curl
function fire_push($notes, $dir) {
    if (!$notes) return;
    if (is_file($dir . '/sandbox.flag')) return;     // песочница (демо-дом): push в браузеры не уходят
    // Блокировку отпускаем ДО запуска фонового процесса: он наследует открытые файлы и иначе держал бы её вечно
    global $lock;
    if (isset($lock) && is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    $store = @json_decode(@file_get_contents($dir . '/push.json'), true);
    $has = [];
    if (is_array($store) && isset($store['subs'])) foreach ($store['subs'] as $x) $has[$x['person']] = 1;
    $php = PL_PHP_CLI;
    foreach ($notes as $n) {
        if (empty($has[$n['person']])) continue;
        $cmd = escapeshellcmd($php) . ' ' . escapeshellarg(__DIR__ . '/push-send.php') . ' ' . escapeshellarg(base64_encode(json_encode($n, JSON_UNESCAPED_UNICODE))) . ' >/dev/null 2>&1 &';
        @exec($cmd);
    }
}
function note_assign(&$notes, $before, $after, $task) {
    if ($after && $after !== $before && ($task['status'] ?? null) === 'new') $notes[] = ['type' => 'assign', 'person' => $after, 'task' => $task['id'], 'title' => $task['t']];
}

if ($op === 'push.key') {
    $v = @json_decode(@file_get_contents($DIR . '/vapid.json'), true);   // создаёт gen-vapid.php (или admin.php init)
    out(['key' => is_array($v) && isset($v['pub']) ? $v['pub'] : null]);
} elseif ($op === 'push.sub' || $op === 'push.unsub' || $op === 'push.test') {
    $pf = $DIR . '/push.json';
    $store = @json_decode(@file_get_contents($pf), true);
    if (!is_array($store) || !isset($store['subs']) || !is_array($store['subs'])) $store = ['subs' => []];
    $person = isset($in['person']) ? who_ok($in['person'], $db) : null;
    if ($op === 'push.test') {
        if (!$person) out(['error' => 'person'], 400);
        $n = 0; foreach ($store['subs'] as $x) if ($x['person'] === $person) $n++;
        fire_push([['person' => $person, 'task' => null, 'title' => '', 'test' => true]], $DIR);
        out(['ok' => true, 'devices' => $n]);
    }
    if ($op === 'push.unsub') {
        $ep = isset($in['endpoint']) ? (string)$in['endpoint'] : '';
        $store['subs'] = array_values(array_filter($store['subs'], function ($x) use ($ep) { return $x['endpoint'] !== $ep; }));
    } else {
        $sub = (isset($in['sub']) && is_array($in['sub'])) ? $in['sub'] : [];
        $ep = isset($sub['endpoint']) ? (string)$sub['endpoint'] : '';
        $kk = (isset($sub['keys']) && is_array($sub['keys'])) ? $sub['keys'] : [];
        $host = parse_url($ep, PHP_URL_HOST);
        $okHost = $host && preg_match('/(^|\.)(fcm\.googleapis\.com|push\.apple\.com|push\.services\.mozilla\.com|notify\.windows\.com)$/i', $host);
        if (!$person || strpos($ep, 'https://') !== 0 || !$okHost || empty($kk['p256dh']) || empty($kk['auth']) || strlen($ep) > 600) out(['error' => 'bad subscription'], 400);
        $store['subs'] = array_values(array_filter($store['subs'], function ($x) use ($ep) { return $x['endpoint'] !== $ep; }));
        $store['subs'][] = ['person' => $person, 'endpoint' => $ep, 'p256dh' => (string)$kk['p256dh'], 'auth' => (string)$kk['auth'],
                            'at' => date('c'), 'ua' => substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 80)];
        // не больше 5 устройств на человека: старые вытесняются
        $cnt = []; $keep = [];
        foreach (array_reverse($store['subs']) as $x) { $cnt[$x['person']] = ($cnt[$x['person']] ?? 0) + 1; if ($cnt[$x['person']] <= 5) $keep[] = $x; }
        $store['subs'] = array_reverse($keep);
    }
    $tmp = $pf . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($store));
    @chmod($tmp, 0660);
    rename($tmp, $pf);
    out(['ok' => true]);
} elseif ($op === 'stats') {
    // кто сколько выполнил и отклонил: «d» — выполнена (по исполнителю на момент выполнения), «x» — отказ
    // считаем только с момента запуска журнала (statsFrom); удалённые задачи не учитываются
    if (empty($db['statsFrom'])) save_db($db, $DIR, $file);
    $xs = [];
    foreach ($db['tasks'] as $t) if (!empty($t['xs'])) $xs[$t['id']] = 1;
    $ev = [];
    foreach ($db['ev'] as $e) if (!isset($xs[$e['id']]) && $e['at'] >= $db['statsFrom']) $ev[] = ['p' => $e['p'], 't' => $e['t'], 'at' => $e['at']];
    out(['events' => $ev, 'people' => $db['people'], 'from' => (int)$db['statsFrom']]);
} elseif ($op === 'resp') {
    $i = find_by($db, 'id', id_ok(isset($in['id']) ? $in['id'] : null));
    $by = isset($in['by']) ? who_ok($in['by'], $db) : null;
    $act = isset($in['act']) ? (string)$in['act'] : '';
    if ($i < 0 || !$by || ($act !== 'accept' && $act !== 'decline')) reply($db);
    $t = &$db['tasks'][$i];
    // отвечает только назначенный исполнитель, и только пока задача ждёт ответа
    if (($t['assignee'] ?? null) === $by && ($t['status'] ?? null) === 'new') {
        if ($act === 'accept') {
            $t['status'] = 'accepted'; $t['acceptedAt'] = $now; $t['mt'] = $now;
            $notes[] = ['type' => 'accept', 'person' => $OWNER, 'task' => $t['id'], 'title' => $t['t'], 'from' => $by];
        } else {
            $why = cut(isset($in['reason']) ? $in['reason'] : '', 300);
            if ($why === '') { unset($t); reply($db); }
            if (!isset($t['declines']) || !is_array($t['declines'])) $t['declines'] = [];
            $t['declines'][] = ['by' => $by, 'reason' => $why, 'at' => $now];
            $t['assignee'] = null; $t['status'] = null; $t['assignedAt'] = null; $t['acceptedAt'] = null; $t['mt'] = $now;
            $notes[] = ['type' => 'decline', 'person' => $OWNER, 'task' => $t['id'], 'title' => $t['t'], 'from' => $by, 'reason' => $why];
        }
        $changed = true;
    }
    unset($t);
} elseif ($op === 'add') {
    $id = id_ok(isset($in['id']) ? $in['id'] : null);
    $title = cut(isset($in['t']) ? $in['t'] : '', 200);
    if ($title === '') out(['error' => 'empty'], 400);
    // повтор того же запроса (связь оборвалась, приложение отправило ещё раз) дубля не создаёт
    if ($id && find_by($db, 'id', $id) >= 0) reply($db);
    if (!$id) $id = 'p' . substr(md5(uniqid('', true)), 0, 10);
    $by = (isset($in['by']) && is_string($in['by'])) ? $in['by'] : null;
    $nt = new_task($id, $title, $in, $db, $now, $by);   // src=null: задача рождена в Планёрке (внешний клиент заберёт её через pull)
    $nt['cby'] = $by !== null ? who_ok($by, $db) : null;   // кто поставил: он и постановщик (owner) могут удалить
    $db['tasks'][] = $nt;
    note_assign($notes, null, $nt['assignee'], $nt);
    $changed = true;
} elseif ($op === 'upd') {
    $i = find_by($db, 'id', id_ok(isset($in['id']) ? $in['id'] : null));
    $f = (isset($in['f']) && is_array($in['f'])) ? $in['f'] : [];
    if ($i < 0) reply($db);
    $t = &$db['tasks'][$i];
    $was = $t['assignee'] ?? null;
    $by = (isset($in['by']) && is_string($in['by'])) ? $in['by'] : null;
    // удалить (и вернуть удалённую) может постановщик (owner) или тот, кто поставил задачу
    if (isset($f['del']) && $by !== $OWNER && ($by === null || ($t['cby'] ?? null) !== $by)) { unset($t); reply($db); }
    apply_fields($t, $f, $db, $now, $by);
    if (isset($f['del'])) { $t['del'] = (bool)$f['del']; if ($t['del']) $t['xs'] = true; else unset($t['xs']); }
    if (empty($t['del'])) note_assign($notes, $was, $t['assignee'] ?? null, $t);
    unset($t);
    $changed = true;
} elseif ($op === 'hist' || $op === 'hist.del') {
    // История завершённых задач (видят все). Удаление убирает задачу из истории и из статистики (флаг xs);
    // «вернуть» (undo) — возвращает и туда, и туда.
    if ($op === 'hist.del') {
        // из Планёрки удалить может только постановщик (owner); с ключом sync — всегда (это администратор)
        if (!$isSync && (!isset($in['by']) || $in['by'] !== $OWNER)) out(['error' => 'auth'], 403);
        $i = find_by($db, 'id', id_ok(isset($in['id']) ? $in['id'] : null));
        if ($i < 0) out(['error' => 'not found'], 404);
        $t = &$db['tasks'][$i];
        if (!empty($in['undo'])) { $t['del'] = false; unset($t['xs']); }
        else { if (empty($t['done'])) { unset($t); out(['error' => 'not done'], 400); } $t['del'] = true; $t['xs'] = true; }
        $t['mt'] = $now;
        unset($t);
        save_db($db, $DIR, $file);
    }
    $h = [];
    foreach ($db['tasks'] as $t) {
        if (empty($t['done']) || !empty($t['xs']) || !empty($t['del'])) continue;
        $h[] = ['id' => $t['id'], 't' => $t['t'], 'desc' => isset($t['desc']) ? $t['desc'] : '', 'assignee' => isset($t['assignee']) ? $t['assignee'] : null,
                'doneAt' => isset($t['doneAt']) ? $t['doneAt'] : null, 'created' => isset($t['created']) ? $t['created'] : null, 'pri' => isset($t['pri']) ? $t['pri'] : 4];
    }
    usort($h, function ($a, $b) { return ((int)$b['doneAt']) - ((int)$a['doneAt']); });
    out(['ok' => true, 'tasks' => array_slice($h, 0, 1000), 'people' => $db['people']]);
} elseif ($op === 'pull') {
    // клиент администратора забирает всё, включая пометки del/gone: так он узнаёт об удалениях
    $r = []; $limit = time() - 60 * 86400;
    foreach ($db['tasks'] as $t) {
        if (empty($t['del']) && empty($t['gone']) && !empty($t['done']) && !empty($t['doneAt']) && $t['doneAt'] / 1000 < $limit) continue;
        $t = norm($t, $db);
        unset($t['srcDone'], $t['mt']);
        $r[] = $t;
    }
    out(['rev' => (int)$db['rev'], 'tasks' => $r, 'people' => $db['people'], 'presence' => pres_load($DIR), 'now' => ms_now(), 'build' => app_build()]);
} elseif ($op === 'sync') {
    // 1) список исполнителей целиком (если клиент администратора его прислал)
    if (isset($in['people']) && is_array($in['people'])) {
        $pp = []; $seen = [];
        foreach (array_slice($in['people'], 0, 20) as $p) {
            if (!is_array($p)) continue;
            $sid = slug_ok(isset($p['id']) ? $p['id'] : null);
            $nm = cut(isset($p['name']) ? $p['name'] : '', 40);
            if (!$sid || $nm === '' || isset($seen[$sid])) continue;
            $seen[$sid] = 1; $pp[] = ['id' => $sid, 'name' => $nm];
        }
        $db['people'] = $pp;
        $changed = true;
    }
    // 2) изменения задач: upsert {id (у клиента), sid (в Планёрке или null), f (поля)}, remove [{id,sid}]
    $map = [];
    $up = (isset($in['upsert']) && is_array($in['upsert'])) ? $in['upsert'] : [];
    $rm = (isset($in['remove']) && is_array($in['remove'])) ? $in['remove'] : [];
    foreach (array_slice($up, 0, 500) as $u) {
        if (!is_array($u)) continue;
        $src = id_ok(isset($u['id']) ? $u['id'] : null);
        $sid = id_ok(isset($u['sid']) ? $u['sid'] : null);
        $f = (isset($u['f']) && is_array($u['f'])) ? $u['f'] : [];
        if (!$src) continue;
        $i = $sid ? find_by($db, 'id', $sid) : -1;
        if ($i < 0) $i = find_by($db, 'srcId', $src);
        if ($i < 0) {
            $title = cut(isset($f['t']) ? $f['t'] : '', 200);
            if ($title === '') continue;
            $nid = 'p' . substr(md5($src . uniqid('', true)), 0, 10);
            $t = new_task($nid, $title, $f, $db, $now, $OWNER);
            $t['src'] = 'todos'; $t['srcId'] = $src; $t['cby'] = $OWNER;
            $db['tasks'][] = $t;
            note_assign($notes, null, $t['assignee'], $t);
            $map[$src] = $nid;
        } else {
            $t = &$db['tasks'][$i];
            $was = $t['assignee'] ?? null;
            apply_fields($t, $f, $db, $now, $OWNER);
            if (array_key_exists('assignee', $f)) note_assign($notes, $was, $t['assignee'] ?? null, $t);
            if (empty($t['src']) && $src) { /* задача, рождённая в Планёрке, получает id у клиента */ $t['srcId'] = $src; }
            unset($t['gone'], $t['xs']);
            $map[$src] = $t['id'];
            unset($t);
        }
        $changed = true;
    }
    foreach (array_slice($rm, 0, 500) as $r) {
        $src = id_ok(is_array($r) ? (isset($r['id']) ? $r['id'] : null) : $r);
        $sid = is_array($r) ? id_ok(isset($r['sid']) ? $r['sid'] : null) : null;
        $i = $sid ? find_by($db, 'id', $sid) : -1;
        if ($i < 0) $i = find_by($db, 'srcId', $src);
        if ($i >= 0) {
            if (($db['tasks'][$i]['src'] ?? null) === 'todos') $db['tasks'][$i]['gone'] = true;
            else $db['tasks'][$i]['del'] = true;
            // удаление невыполненной задачи убирает её из статистики; очистка выполненных у клиента статистику не трогает
            if (empty($db['tasks'][$i]['done'])) $db['tasks'][$i]['xs'] = true;
            $db['tasks'][$i]['mt'] = $now;
            $changed = true;
        }
    }
    if ($changed) save_db($db, $DIR, $file);
    fire_push($notes, $DIR);
    out(['ok' => true, 'map' => $map]);
} elseif ($op !== 'list') {
    out(['error' => 'bad op'], 400);
}

if ($changed) save_db($db, $DIR, $file);
fire_push($notes, $DIR);
reply($db);
````
<!-- END FILE -->

### `planerka/admin.php` — консоль администратора (147 строк)

<!-- FILE planerka/admin.php text sha256=84b99c5837e373dd09c8eae25f6d4b53ada5b4d28416068f7db9e5cf0582b891 -->
````php
<?php
// ─────────────────────────────────────────────────────────────
//  Планёрка — консоль администратора. Только из командной строки, с правами веб-сервера
//  (иначе созданные файлы веб-сервер не сможет менять):   sudo -u www-data php admin.php <команда>
//
//    init                    создать папку данных, ключи, ПУСТОЙ список задач и исполнителей, ключи уведомлений
//    password <пароль>       пароль Планёрки — им исполнители входят в приложение (от 6 знаков)
//    add <id> <Имя>          добавить исполнителя; id — латиница/цифры/дефис, 2–24 знака, это адрес /planerka/<id>
//    rename <id> <Имя>       переименовать исполнителя
//    remove <id>             убрать исполнителя (его открытые задачи вернутся во «Входящие»)
//    owner <id>              постановщик: удаляет любые задачи и чистит историю, получает ответы исполнителей
//    list                    исполнители и их личные ссылки (ссылка содержит ключ — пересылать только своим)
//    keys                    ключи доступа team и sync (не публиковать)
//    client-password <пароль> пароль внешнего клиента администратора (выдаёт ключ sync) — нужен только для такого клиента
//
//  Любая правка списка поднимает номер версии данных — открытые приложения подхватят её сами за секунду.
// ─────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
$DIR = PL_DATA;

function say($s) { fwrite(STDOUT, $s . "\n"); }
function fail($s) { fwrite(STDERR, 'Ошибка: ' . $s . "\n"); exit(1); }
function jread($f) { $v = @json_decode(@file_get_contents($f), true); return is_array($v) ? $v : null; }
function jwrite($f, $data) {
    $tmp = $f . '.tmp' . getmypid();
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) === false) fail('не удалось записать ' . $f);
    @chmod($tmp, 0660);
    if (!rename($tmp, $f)) fail('не удалось заменить ' . $f);
}
function ulen($s) { return preg_match_all('/./u', (string)$s); }
function need_init($dir) { if (!is_file($dir . '/keys.json') || !is_file($dir . '/tasks.json')) fail('Планёрка ещё не создана: php admin.php init'); }
function id_check($id) {
    if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $id)) fail('id — латиница в нижнем регистре, цифры и дефис, 2–24 знака (например ivan или anna-k)');
    if (in_array($id, ['api', 'index', 'dial', 'manifest', 'planerka', 'h'], true)) fail('id «' . $id . '» занят служебным адресом, выберите другой');
}
function name_check($n) {
    $n = trim((string)$n);
    if ($n === '') fail('укажите имя');
    if (ulen($n) > 40) fail('имя длиннее 40 знаков');
    return $n;
}
// правка tasks.json под той же блокировкой, что у api.php, с подъёмом версии (rev)
function with_db($dir, $fn) {
    $lock = fopen($dir . '/lock', 'c');
    if (!$lock) fail('нет доступа к папке данных ' . $dir);
    flock($lock, LOCK_EX);
    $db = jread($dir . '/tasks.json');
    if (!$db || !isset($db['tasks']) || !is_array($db['tasks'])) $db = ['rev' => 0, 'tasks' => [], 'people' => [], 'ev' => [], 'owner' => ''];
    if (!isset($db['people']) || !is_array($db['people'])) $db['people'] = [];
    $msg = $fn($db);
    $db['rev'] = (int)$db['rev'] + 1;
    jwrite($dir . '/tasks.json', $db);
    flock($lock, LOCK_UN); fclose($lock);
    return $msg;
}
function link_of($id, $team) { return PL_SITE . '/planerka/' . ($id !== '' ? $id : '') . '?k=' . $team; }

$cmd = isset($argv[1]) ? $argv[1] : '';
$a2 = isset($argv[2]) ? $argv[2] : null;
$a3 = isset($argv[3]) ? implode(' ', array_slice($argv, 3)) : null;

if ($cmd === 'init') {
    if (!is_dir($DIR) && !@mkdir($DIR, 0770, true)) fail('не удалось создать папку данных ' . $DIR);
    @chmod($DIR, 0770);
    // страховка: если папку всё же положили в веб-корень, Apache её не отдаст
    if (!is_file($DIR . '/.htaccess')) @file_put_contents($DIR . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!is_file($DIR . '/keys.json')) { jwrite($DIR . '/keys.json', ['team' => bin2hex(random_bytes(16)), 'sync' => bin2hex(random_bytes(16))]); say('ключи доступа созданы'); }
    else say('ключи доступа уже есть — не трогаю');
    if (!is_file($DIR . '/tasks.json')) { jwrite($DIR . '/tasks.json', ['rev' => 0, 'tasks' => [], 'people' => [], 'ev' => [], 'owner' => '']); say('пустой список задач создан (исполнителей нет)'); }
    else say('список задач уже есть — не трогаю');
    if (is_file($DIR . '/vapid.json')) say('ключи уведомлений уже есть');
    elseif (function_exists('openssl_pkey_new') && function_exists('openssl_pkey_derive')) {
        require __DIR__ . '/lib-webpush.php';
        list($k, $pub) = wp_ec_new();
        openssl_pkey_export($k, $pem);
        jwrite($DIR . '/vapid.json', ['pem' => $pem, 'pub' => wp_b64u($pub), 'subject' => PL_SITE . '/planerka/']);
        say('ключи уведомлений (VAPID) созданы');
    } else say('ВНИМАНИЕ: у этого PHP нет openssl — ключи уведомлений не созданы. Запустите: ' . PL_PHP_CLI . ' gen-vapid.php');
    say('Папка данных: ' . $DIR);
    say('Дальше: php admin.php password <пароль>, php admin.php add <id> <Имя>, php admin.php owner <id>, php admin.php list');
    exit(0);
}
if ($cmd === 'password' || $cmd === 'client-password') {
    need_init($DIR);
    if ($a2 === null || ulen($a2) < 6) fail('пароль — не короче 6 знаков');
    $A = jread($DIR . '/auth.json'); if (!$A) $A = [];
    if ($cmd === 'password') { $A['team_pw'] = password_hash($a2, PASSWORD_DEFAULT); $A['team_at'] = date('c'); }
    else { $A['pw'] = password_hash($a2, PASSWORD_DEFAULT); $A['at'] = date('c'); }
    jwrite($DIR . '/auth.json', $A);
    say($cmd === 'password' ? 'Пароль Планёрки задан: исполнители входят им на ' . PL_SITE . '/planerka/' : 'Пароль клиента администратора задан');
    exit(0);
}
if ($cmd === 'add' || $cmd === 'rename') {
    need_init($DIR);
    id_check($a2); $nm = name_check($a3);
    say(with_db($DIR, function (&$db) use ($cmd, $a2, $nm) {
        foreach ($db['people'] as $i => $p) if ($p['id'] === $a2) {
            if ($cmd === 'add') fail('исполнитель ' . $a2 . ' уже есть (переименовать: rename)');
            $db['people'][$i]['name'] = $nm; return 'Переименован: ' . $a2 . ' → ' . $nm;
        }
        if ($cmd === 'rename') fail('исполнителя ' . $a2 . ' нет');
        if (count($db['people']) >= 20) fail('не больше 20 исполнителей');
        $db['people'][] = ['id' => $a2, 'name' => $nm];
        return 'Добавлен исполнитель: ' . $nm . ' (' . $a2 . ')';
    }));
    exit(0);
}
if ($cmd === 'remove') {
    need_init($DIR);
    id_check($a2);
    say(with_db($DIR, function (&$db) use ($a2) {
        $was = count($db['people']);
        $db['people'] = array_values(array_filter($db['people'], function ($p) use ($a2) { return $p['id'] !== $a2; }));
        if (count($db['people']) === $was) fail('исполнителя ' . $a2 . ' нет');
        if (isset($db['owner']) && $db['owner'] === $a2) $db['owner'] = '';
        return 'Убран: ' . $a2 . ' (его открытые задачи снова во «Входящих»)';
    }));
    exit(0);
}
if ($cmd === 'owner') {
    need_init($DIR);
    id_check($a2);
    say(with_db($DIR, function (&$db) use ($a2) {
        $ok = false; foreach ($db['people'] as $p) if ($p['id'] === $a2) $ok = true;
        if (!$ok) fail('сначала добавьте исполнителя: php admin.php add ' . $a2 . ' <Имя>');
        $db['owner'] = $a2;
        return 'Постановщик: ' . $a2;
    }));
    exit(0);
}
if ($cmd === 'list' || $cmd === 'keys') {
    need_init($DIR);
    $k = jread($DIR . '/keys.json');
    if ($cmd === 'keys') { say('team: ' . $k['team']); say('sync: ' . $k['sync']); exit(0); }
    $db = jread($DIR . '/tasks.json');
    $A = jread($DIR . '/auth.json');
    say('Вход по паролю: ' . PL_SITE . '/planerka/' . (!empty($A['team_pw']) ? '' : '   (пароль ещё не задан: php admin.php password …)'));
    $people = isset($db['people']) && is_array($db['people']) ? $db['people'] : [];
    if (!$people) { say('Исполнителей нет: php admin.php add <id> <Имя>'); exit(0); }
    $own = isset($db['owner']) ? $db['owner'] : '';
    foreach ($people as $p) say(str_pad($p['id'], 14) . ' ' . $p['name'] . ($p['id'] === $own ? ' (постановщик)' : '') . "\n               " . link_of($p['id'], $k['team']));
    if ($own === '') say('Постановщик не назначен: php admin.php owner <id>');
    exit(0);
}
say("Команды: init | password <пароль> | add <id> <Имя> | rename <id> <Имя> | remove <id> | owner <id> | list | keys | client-password <пароль>");
exit($cmd === '' || $cmd === 'help' ? 0 : 1);
````
<!-- END FILE -->

### `planerka/push-send.php` — отправка уведомления (139 строк)

<!-- FILE planerka/push-send.php text sha256=d4ca23190fb23c5b58c30a2a91e4e89178dbabfbc5bddcb5285b000b0de2bcc0 -->
````php
<?php
// Отправка Web Push исполнителю. Запускается внешним PHP (PL_PHP_CLI из config.php) из api.php (в фоне; напоминание
// remind — с ожиданием ответа), а не веб-PHP: веб-PHP может быть без openssl и curl.
//   php push-send.php <base64(json)>   json: {person, task, title, type?, test?}
//   type: assign (новая задача) · accept/decline (ответ исполнителя) · remind (напоминание о незавершённой задаче,
//   about — чья задача; check — проверка: то же напоминание приходит постановщику).
// Последней строкой печатает «RESULT {"sent":N,"tried":M}» — её читает api.php при синхронной отправке (remind).
// Ключи VAPID и подписки: папка данных PL_DATA — {vapid.json, push.json}. Журнал: push.log.
// PLANERKA_PUSH_SINK=<файл> — ТОЛЬКО для тестов: уведомления не отправляются, а дописываются в файл (как будто доставлены).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
require __DIR__ . '/lib-webpush.php';
$DIR = PL_DATA;
$arg = isset($argv[1]) ? json_decode(base64_decode($argv[1]), true) : null;
if (!is_array($arg) || empty($arg['person'])) exit(1);

function plog($dir, $s) {
    $f = $dir . '/push.log';
    if (@filesize($f) > 200000) @rename($f, $f . '.old');
    @file_put_contents($f, date('c') . ' ' . $s . "\n", FILE_APPEND);
}

function result($sent, $tried) { echo 'RESULT ', json_encode(['sent' => $sent, 'tried' => $tried]), "\n"; }
$SINK = getenv('PLANERKA_PUSH_SINK');
// Песочница (файл sandbox.flag в папке данных): уведомления никуда не отправляются, а дописываются в push-sandbox.log.
if (!$SINK && is_file($DIR . '/sandbox.flag')) $SINK = $DIR . '/push-sandbox.log';
$vapid = @json_decode(@file_get_contents($DIR . '/vapid.json'), true);
if (!$SINK && (!is_array($vapid) || empty($vapid['pem']))) { plog($DIR, 'no vapid keys'); result(0, 0); exit(1); }

$lock = fopen($DIR . '/lock', 'c');
flock($lock, LOCK_EX);
$store = @json_decode(@file_get_contents($DIR . '/push.json'), true);
$subs = (is_array($store) && isset($store['subs']) && is_array($store['subs'])) ? $store['subs'] : [];
flock($lock, LOCK_UN);

$db = @json_decode(@file_get_contents($DIR . '/tasks.json'), true);
$name = $arg['person'];
if (is_array($db) && isset($db['people'])) foreach ($db['people'] as $p) if ($p['id'] === $arg['person']) $name = $p['name'];

$title = isset($arg['title']) ? (string)$arg['title'] : '';
$type = isset($arg['type']) ? $arg['type'] : 'assign';
$fromName = isset($arg['from']) ? $arg['from'] : '';
if (is_array($db) && isset($db['people'])) foreach ($db['people'] as $p) if ($p['id'] === $fromName) $fromName = $p['name'];
// Напоминание о незавершённой задаче: кому, что за задача, срок, ответ, пункты.
// Проверка (check) приходит постановщику (owner) с тем же текстом и пометкой, чей это вид.
function plural($n, $one, $few, $many) { $m = $n % 100; $k = $n % 10; return ($m >= 11 && $m <= 14) ? $many : ($k === 1 ? $one : (($k >= 2 && $k <= 4) ? $few : $many)); }
function remind_payload($arg, $db, $title) {
    $about = isset($arg['about']) ? (string)$arg['about'] : $arg['person'];
    $aboutName = $about;
    if (is_array($db) && isset($db['people'])) foreach ($db['people'] as $p) if ($p['id'] === $about) $aboutName = $p['name'];
    $tk = null;
    if (is_array($db) && isset($db['tasks'])) foreach ($db['tasks'] as $x) if ($x['id'] === $arg['task']) { $tk = $x; break; }
    $L = [$aboutName . ', напоминание: у вас не завершена задача «' . $title . '».'];
    $due = $tk && !empty($tk['due']) ? (string)$tk['due'] : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        $tz = new DateTimeZone(PL_TZ);
        $today = new DateTime('today', $tz);
        $d = DateTime::createFromFormat('!Y-m-d', $due, $tz);
        $mon = ['янв.', 'февр.', 'мар.', 'апр.', 'мая', 'июн.', 'июл.', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.'];
        $lab = (int)$d->format('j') . ' ' . $mon[(int)$d->format('n') - 1];
        $days = (int)$today->diff($d)->format('%r%a');
        if ($days < 0) $L[] = 'Срок был ' . $lab . ' — просрочено на ' . (-$days) . ' ' . plural(-$days, 'день', 'дня', 'дней') . '.';
        elseif ($days === 0) $L[] = 'Срок — сегодня.';
        elseif ($days === 1) $L[] = 'Срок — завтра, ' . $lab . '.';
        else $L[] = 'Срок — ' . $lab . '.';
    }
    if ($tk && ($tk['status'] ?? null) === 'new') $L[] = 'Задача ещё не принята — откройте её и ответьте.';
    $items = ($tk && isset($tk['items']) && is_array($tk['items'])) ? $tk['items'] : [];
    if ($items) { $dn = 0; foreach ($items as $it) if (!empty($it['d'])) $dn++; $L[] = 'Пункты: выполнено ' . $dn . ' из ' . count($items) . '.'; }
    return ['title' => 'Планёрка · напоминание', 'body' => implode("\n", $L), 'tag' => (!empty($arg['check']) ? 'remind-check-' : 'remind-') . $arg['task'],
            'url' => '/planerka/' . $arg['person'] . '?task=' . $arg['task'], 'task' => $arg['task'],
            'checkNote' => !empty($arg['check']) ? 'Это проверка: так напоминание увидит ' . $aboutName . '.' : ''];
}
if (!empty($arg['test'])) {
    $payload = ['title' => 'Планёрка', 'body' => $name . ', уведомления включены. Так будут приходить новые задачи.', 'tag' => 'test',
                'url' => '/planerka/' . $arg['person'], 'task' => null];
} elseif ($type === 'accept') {
    $payload = ['title' => 'Планёрка', 'body' => $fromName . ': задача принята — «' . $title . '»', 'tag' => 'resp-' . $arg['task'],
                'url' => '/planerka/' . $arg['person'] . '?task=' . $arg['task'], 'task' => $arg['task']];
} elseif ($type === 'decline') {
    $payload = ['title' => 'Планёрка', 'body' => $fromName . ': отказ от задачи «' . $title . '». Причина: ' . (isset($arg['reason']) ? $arg['reason'] : ''), 'tag' => 'resp-' . $arg['task'],
                'url' => '/planerka/' . $arg['person'] . '?task=' . $arg['task'], 'task' => $arg['task']];
} elseif ($type === 'remind') {
    $payload = remind_payload($arg, $db, $title);
} else {
    $payload = ['title' => 'Планёрка', 'body' => $name . ', у вас новая задача: ' . $title, 'tag' => 'task-' . $arg['task'],
                'url' => '/planerka/' . $arg['person'] . '?task=' . $arg['task'], 'task' => $arg['task']];
}
// Номер контрагента: в тексте уведомления и кнопками «Позвонить» / «WhatsApp» (кнопки показывает Android; iPhone их не поддерживает).
// Местный номер (0555… или 555…) дополняется кодом страны 996 (Кыргызстан) — для другой страны поменять здесь и в src.html (telHref, waHref).
function tel_norm($p) {
    $p = (string)$p; $n = preg_replace('/\D/', '', $p);
    if ($n === '') return '';
    if (strlen($n) === 10 && $n[0] === '0') return '+996' . substr($n, 1);
    if (strlen($n) === 9) return '+996' . $n;
    return '+' . $n;
}
if (!empty($payload['task']) && is_array($db) && isset($db['tasks'])) {
    foreach ($db['tasks'] as $tk) {
        if ($tk['id'] !== $payload['task']) continue;
        $ph = isset($tk['phone']) ? trim((string)$tk['phone']) : '';
        $tn = tel_norm($ph);
        if ($tn !== '') {
            $payload['body'] .= "\nКонтрагент: " . $ph;
            $payload['phone'] = $tn;
            $payload['wa'] = 'https://wa.me/' . ltrim($tn, '+');
            $payload['actions'] = [['action' => 'call', 'title' => 'Позвонить'], ['action' => 'wa', 'title' => 'WhatsApp']];
        }
        break;
    }
}
// пометка проверки — последней строкой, после контрагента
if (isset($payload['checkNote'])) { if ($payload['checkNote'] !== '') $payload['body'] .= "\n" . $payload['checkNote']; unset($payload['checkNote']); }
$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
if (getenv('PLANERKA_DRYRUN')) { echo $json, "\n"; exit(0); }   // для проверки: показать уведомление, не отправляя

$dead = [];
$sent = 0; $tried = 0;
foreach ($subs as $s) {
    if (($s['person'] ?? '') !== $arg['person']) continue;
    $tried++;
    if ($SINK) { @file_put_contents($SINK, json_encode(['person' => $arg['person'], 'payload' => $payload], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND); $code = 201; $err = ''; }
    else list($code, $err) = wp_send($s, $json, $vapid);
    $host = parse_url($s['endpoint'], PHP_URL_HOST);
    plog($DIR, $arg['person'] . ' ' . $host . ' http=' . $code . ($code >= 300 || $code === 0 ? ' ' . $err : ''));
    if ($code >= 200 && $code < 300) $sent++;
    if ($code === 404 || $code === 410) $dead[$s['endpoint']] = 1;
}
if ($dead) {
    flock($lock, LOCK_EX);
    $store = @json_decode(@file_get_contents($DIR . '/push.json'), true);
    if (is_array($store) && isset($store['subs'])) {
        $store['subs'] = array_values(array_filter($store['subs'], function ($s) use ($dead) { return !isset($dead[$s['endpoint']]); }));
        file_put_contents($DIR . '/push.json', json_encode($store));
    }
    flock($lock, LOCK_UN);
}
result($sent, $tried);
exit($sent > 0 ? 0 : 2);
````
<!-- END FILE -->

### `planerka/lib-webpush.php` — Web Push без библиотек (102 строк)

<!-- FILE planerka/lib-webpush.php text sha256=a03dfda86282e67a0679ccf05ea1d006df77fc75c4fc9c570fe18f6b58f33444 -->
````php
<?php
// Web Push без сторонних библиотек: VAPID (RFC 8292) + шифрование полезной нагрузки aes128gcm (RFC 8291).
// Нужен PHP 7.3+ с openssl и curl. Веб-PHP может быть без них — отправляет внешний PHP (push-send.php, PL_PHP_CLI).

function wp_b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function wp_b64u_dec($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

// сырая точка 65 байт -> PEM открытого ключа P-256
function wp_pub_pem($raw65) {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
// скаляр d + точка -> PEM закрытого ключа P-256
function wp_priv_pem($d32, $pub65) {
    $der = hex2bin('30770201010420') . $d32 . hex2bin('a00a06082a8648ce3d030107a144034200') . $pub65;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}
// новая пара ключей: [ресурс ключа, открытая точка 65 байт, скаляр d]
function wp_ec_new() {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($k);
    $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    return [$k, $pub, str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT)];
}

// Шифрование по RFC 8291. Возвращает тело запроса (salt | rs | idlen | keyid | шифртекст).
// asKey/asPub/salt задаются только в тестах (вектор из RFC).
function wp_encrypt($payload, $uaPub65, $authSecret, $asKey = null, $asPub = null, $salt = null) {
    if (!$asKey) { list($asKey, $asPub) = wp_ec_new(); }
    if ($salt === null) $salt = random_bytes(16);
    $uaKey = openssl_pkey_get_public(wp_pub_pem($uaPub65));
    $secret = openssl_pkey_derive($uaKey, $asKey, 32);
    if ($secret === false) return null;
    $keyInfo = "WebPush: info\0" . $uaPub65 . $asPub;
    $ikm = hash_hkdf('sha256', $secret, 32, $keyInfo, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = $payload . "\x02"; // последняя (единственная) запись
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) return null;
    return $salt . pack('N', 4096) . chr(strlen($asPub)) . $asPub . $ct . $tag;
}
// обратная операция (для самопроверки в тестах): получатель с закрытым ключом ua
function wp_decrypt($body, $uaKey, $uaPub65, $authSecret) {
    $salt = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPub = substr($body, 21, $idlen);
    $rest = substr($body, 21 + $idlen);
    $ct = substr($rest, 0, -16); $tag = substr($rest, -16);
    $asKey = openssl_pkey_get_public(wp_pub_pem($asPub));
    $secret = openssl_pkey_derive($asKey, $uaKey, 32);
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPub65 . $asPub, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = openssl_decrypt($ct, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $plain === false ? null : rtrim($plain, "\x02");
}

// подпись ES256: DER -> сырые r|s (64 байта)
function wp_der_to_raw($der) {
    $o = (ord($der[1]) & 0x80) ? 2 + (ord($der[1]) & 0x7f) : 2;
    $rl = ord($der[$o + 1]); $r = substr($der, $o + 2, $rl);
    $o2 = $o + 2 + $rl;
    $sl = ord($der[$o2 + 1]); $s = substr($der, $o2 + 2, $sl);
    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return $r . $s;
}
function wp_vapid_jwt($audience, $subject, $privPem, $ttl = 43200) {
    $h = wp_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = wp_b64u(json_encode(['aud' => $audience, 'exp' => time() + $ttl, 'sub' => $subject]));
    $der = '';
    openssl_sign($h . '.' . $c, $der, openssl_pkey_get_private($privPem), OPENSSL_ALGO_SHA256);
    return $h . '.' . $c . '.' . wp_b64u(wp_der_to_raw($der));
}

// Отправка одной подписки. $sub: endpoint, p256dh (b64url), auth (b64url). $vapid: pem, pub (b64url), subject.
// Возвращает [код HTTP, текст ошибки]. 404/410 означают, что подписка мертва.
function wp_send($sub, $payloadJson, $vapid) {
    $u = parse_url($sub['endpoint']);
    if (empty($u['scheme']) || $u['scheme'] !== 'https' || empty($u['host'])) return [0, 'bad endpoint'];
    $aud = 'https://' . $u['host'];
    $body = wp_encrypt($payloadJson, wp_b64u_dec($sub['p256dh']), wp_b64u_dec($sub['auth']));
    if ($body === null) return [0, 'encrypt failed'];
    $jwt = wp_vapid_jwt($aud, $vapid['subject'], $vapid['pem']);
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . $vapid['pub'],
            'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream',
            'TTL: 86400', 'Urgency: high',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $err !== '' ? $err : substr((string)$resp, 0, 160)];
}
````
<!-- END FILE -->

### `planerka/gen-vapid.php` — ключи уведомлений (15 строк)

<!-- FILE planerka/gen-vapid.php text sha256=d86300a0bef02cbcfc024496abdd5a98cd20dc80eb4ccc683d9cc6309338f8f5 -->
````php
<?php
// Один раз на сервере: создаёт ключи VAPID (подпись уведомлений). Повторный запуск ничего не меняет.
// Нужен PHP 7.3+ с openssl. Обычно ключи создаёт уже `php admin.php init`; этот файл — если там openssl не было.
//   php gen-vapid.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
require __DIR__ . '/lib-webpush.php';
$DIR = PL_DATA;
$f = $DIR . '/vapid.json';
if (is_file($f)) { echo "уже есть\n"; exit(0); }
list($k, $pub) = wp_ec_new();
openssl_pkey_export($k, $pem);
file_put_contents($f, json_encode(['pem' => $pem, 'pub' => wp_b64u($pub), 'subject' => PL_SITE . '/planerka/']));
@chmod($f, 0660);
echo "создано\n";
````
<!-- END FILE -->

### `planerka/sw.js` — сервис-воркер (80 строк)

<!-- FILE planerka/sw.js text sha256=7ab457abea7fc473c9534ee97d219a85e32f204f6f6592d10d614c2c9fb2c755 -->
````javascript
// Сервис-воркер Планёрки: показывает уведомление и по нажатию открывает именно эту задачу.
// Номер задачи передаётся приложению тремя путями сразу (на iPhone какой-то из них может не сработать):
//   1) запись в Cache Storage — приложение забирает её при открытии и при возврате на экран;
//   2) сообщение уже открытому окну;
//   3) открытие окна по ссылке ?task=<id>.
var NAV_CACHE = 'planerka-nav';
var NAV_KEY = '/planerka/__open-task';

var SHELL_CACHE = 'planerka-shell-v1';
var SHELL = '/planerka/__shell';
// страница сохраняется уже при установке — без сети приложение откроется даже после первого же захода
self.addEventListener('install', function (e) {
  self.skipWaiting();
  e.waitUntil(caches.open(SHELL_CACHE).then(function (c) {
    return fetch('/planerka/', { cache: 'no-store' }).then(function (r) { if (r && r.ok) return c.put(SHELL, r); });
  }).catch(function () {}));
});
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

// Страница Планёрки без интернета: «сначала сеть» (свежая версия и её копия на устройстве),
// нет сети или она очень медленная — сохранённая копия. Обмен данными приложение догоняет само.
function timeout(p, ms) {
  return new Promise(function (ok, no) { var t = setTimeout(function () { no(new Error('timeout')); }, ms); p.then(function (r) { clearTimeout(t); ok(r); }, function (x) { clearTimeout(t); no(x); }); });
}
self.addEventListener('fetch', function (e) {
  var r = e.request;
  if (r.method !== 'GET' || r.mode !== 'navigate') return;
  var u = new URL(r.url);
  if (u.origin !== self.location.origin || u.pathname.indexOf('/planerka') !== 0 || /\/planerka\/(dial|api\.php|manifest\.json)/.test(u.pathname)) return;
  e.respondWith(caches.open(SHELL_CACHE).then(function (c) {
    return timeout(fetch(r), 5000).then(function (resp) {
      if (resp && resp.ok && (resp.headers.get('content-type') || '').indexOf('text/html') === 0) c.put(SHELL, resp.clone());
      return resp;
    }).catch(function () {
      return c.match(SHELL).then(function (m) { return m || fetch(r); });
    });
  }));
});

self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = { title: 'Планёрка', body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'Планёрка', {
    body: d.body || '',
    tag: d.tag || undefined,
    renotify: true,
    icon: '/planerka/icon-192.png',
    badge: '/planerka/badge.png',
    actions: d.actions || [],
    data: d
  }));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var d = e.notification.data || {};
  // кнопки под уведомлением (Android): позвонить контрагенту или написать в WhatsApp
  if (e.action === 'call' && d.phone) { e.waitUntil(self.clients.openWindow('/planerka/dial?n=' + encodeURIComponent(d.phone))); return; }
  if (e.action === 'wa' && d.wa) { e.waitUntil(self.clients.openWindow(d.wa)); return; }
  var url = d.url || '/planerka/';
  e.waitUntil((async function () {
    if (d.task) {
      try {
        var c = await caches.open(NAV_CACHE);
        await c.put(NAV_KEY, new Response(JSON.stringify({ id: d.task, at: Date.now() }), { headers: { 'Content-Type': 'application/json' } }));
      } catch (x) {}
    }
    var list = [];
    try { list = await self.clients.matchAll({ type: 'window', includeUncontrolled: true }); } catch (x) {}
    for (var i = 0; i < list.length; i++) {
      var w = list[i];
      if (w.url.indexOf('/planerka') >= 0) {
        try { w.postMessage({ type: 'open-task', id: d.task || null }); } catch (x) {}
        try { await w.focus(); return; } catch (x) {}
        try { if (w.navigate) { await w.navigate(url); return; } } catch (x) {}
      }
    }
    try { await self.clients.openWindow(url); } catch (x) {}
  })());
});
````
<!-- END FILE -->

### `planerka/.htaccess` — маршрутизация Apache (5 строк)

<!-- FILE planerka/.htaccess text sha256=17f0a947084ae08a2766f72ecf9406d916df5af12932b665fa9efca4db77d5be -->
````apache
# Apache (нужен mod_rewrite): все адреса /planerka/* ведут в index.php — он сам раздаёт страницу,
# API, сервис-воркер, значки, постер и манифест. На nginx этот файл не действует (см. PLANERKA.md).
RewriteEngine On
RewriteRule ^index\.php$ - [L]
RewriteRule ^ index.php [L,QSA]
````
<!-- END FILE -->

### `planerka/build.py` — сборка app.html (52 строк)

<!-- FILE planerka/build.py text sha256=25000c4fcbb3b12eba26de4b8b098a94ca196902dcff5b859d6f22caa7f90e4b -->
````python
"""Собирает planerka/app.html — полную страницу Планёрки (OG-превью, шрифты, значки и логотип вшиты в один файл).
Исходники: src.html (разметка и JS), app.css (общий стиль), fonts/*.woff2, assets/*.png.
Адрес сайта в превью не вшивается: в app.html стоит %%SITE%%, его подставляет index.php из config.php.
Запуск (Python 3.6+): python build.py     — после КАЖДОЙ правки src.html или app.css.
Номер сборки (build.txt) меняется при каждой сборке: открытые приложения сами перезагрузятся на новую версию."""
import base64, os, re, time
here = os.path.dirname(os.path.abspath(__file__))
rd = lambda *p: open(os.path.join(here, *p), 'rb').read()
b64 = lambda *p: base64.b64encode(rd(*p)).decode()

def ff(fam, file, rng, wt):
    return (f"@font-face{{font-family:'{fam}';font-style:normal;font-weight:{wt};font-display:swap;"
            f"src:url(data:font/woff2;base64,{b64('fonts', file)}) format('woff2');unicode-range:{rng}}}\n")

CY = "U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116"
LA = "U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD"
fonts = ff('Inter', 'Inter-cyrillic.woff2', CY, '100 900') + ff('Inter', 'Inter-latin.woff2', LA, '100 900')

css = open(os.path.join(here, 'app.css'), encoding='utf8').read().replace('/*FONTS*/', fonts)

s = open(os.path.join(here, 'src.html'), encoding='utf8').read()
s = s.replace('/*CSS*/', css).replace('/*MARK*/', b64('assets', 'mark-96.png'))
fav = f'<link rel="icon" type="image/png" href="data:image/png;base64,{b64("assets", "favicon-64.png")}">\n' \
      f'<link rel="apple-touch-icon" href="data:image/png;base64,{b64("assets", "apple-touch-icon.png")}">'
s = s.replace('<!--ICONS-->', fav)

U = '%%SITE%%/planerka/'
head = f'''<!doctype html>
<html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#131921">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Планёрка">
<meta property="og:type" content="website">
<meta property="og:title" content="Планёрка">
<meta property="og:description" content="Общий список задач: входящие и исполнители.">
<meta property="og:url" content="{U}">
<meta property="og:image" content="{U}og.png">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<style>:root{{padding-block:env(safe-area-inset-top,0px) env(safe-area-inset-bottom,0px);color-scheme:light}}body{{margin:0}}[hidden]{{display:none!important}}</style>
'''
n = s.replace('<title>Планёрка</title>\n', head + '<title>Планёрка</title>\n', 1)
n = n.replace('</style>\n<div class="app"', '</style>\n</head><body>\n<div class="app"', 1) + '\n</body></html>\n'
for need in ('%%SITE%%/planerka/og.png', '</head><body>', 'data:font/woff2', 'data:image/png;base64,iVBOR'):
    assert need in n, 'сборка не удалась: нет ' + need
BUILD = str(int(time.time()))
n = n.replace('/*BUILD*/', BUILD)
open(os.path.join(here, 'app.html'), 'w', encoding='utf8', newline='\n').write(n)
open(os.path.join(here, 'build.txt'), 'w', encoding='utf8').write(BUILD)
print('app.html', len(n), 'build', BUILD)
````
<!-- END FILE -->

### `planerka/src.html` — интерфейс: вёрстка и логика (1485 строк)

<!-- FILE planerka/src.html text sha256=60810809241db5984d0019d1af583eacf23e2727dd9d07766e3328eb9369432c -->
````html
<title>Планёрка</title>
<!--ICONS-->
<style>
/*CSS*/
/* Планёрка: отличия от Los Todos */
.wm{font-family:var(--f);letter-spacing:-.02em;color:var(--accent)}
:root{--accent:#0B3FD0;--p3:#0B3FD0;--navon:#E3EAFB}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--accent:#7C9BFF;--p3:#7C9BFF;--navon:#22304D}}
:root[data-theme="dark"]{--accent:#7C9BFF;--p3:#7C9BFF;--navon:#22304D}
.net{font-size:12px;color:var(--od);font-weight:600;margin-left:6px}
.sub{font-size:14px;color:var(--mute);margin:0 0 4px}
.sdelw{margin-top:10px;border-top:1px solid var(--line);padding-top:6px}
.sdelb{display:flex;align-items:center;gap:8px;width:100%;min-height:44px;padding:8px 6px;border:0;background:none;color:var(--od);font:inherit;font-size:15px;font-weight:600;cursor:pointer;border-radius:10px;text-align:left}
.sdelb svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;flex:0 0 auto}
.sdelq{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:6px 0}
.sdelq span{flex:1 1 180px;font-size:15px;font-weight:600;color:var(--od)}
.sdelq button{min-height:40px;padding:6px 16px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--ink);font:inherit;font-size:15px;font-weight:600;cursor:pointer}
.sdelq button.y{background:var(--od);border-color:var(--od);color:#fff}
.stf{font-size:13px;color:var(--mute);margin:-4px 0 12px}
.sx-sub{font-size:17px;color:var(--mute);margin:-6px 0 14px}
.sx-h2{font-size:21px;font-weight:800;letter-spacing:-.01em;margin:6px 0 10px}
.sx-card{background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:16px;min-width:0;margin-bottom:12px}
.sx-ct{font-size:18px;font-weight:800;margin-bottom:4px}
.sx-cs{font-size:15px;color:var(--mute);margin-bottom:8px}
.sx-k3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:12px}
.sx-k{position:relative;background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:14px 12px 12px;min-width:0}
.sx-k b{display:block;font-size:36px;line-height:1.1;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums;color:var(--ink)}
.sx-k b.wt{color:var(--tm)}.sx-k b.vi{color:var(--wk)}
.sx-kl{display:block;font-size:15px;line-height:1.25;color:var(--mute);margin-top:4px;overflow-wrap:break-word;hyphens:auto}
.sx-ki{position:absolute;right:10px;top:10px;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:color-mix(in srgb,var(--accent) 12%,transparent);color:var(--accent)}
.sx-ki.wt{background:color-mix(in srgb,var(--tm) 15%,transparent);color:var(--tm)}.sx-ki.vi{background:color-mix(in srgb,var(--wk) 14%,transparent);color:var(--wk)}
.sx-ki svg,.sx-fi svg,.sx-ri svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.sx-k.main{background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 72%,#000));border-color:transparent;box-shadow:0 8px 22px color-mix(in srgb,var(--accent) 30%,transparent)}
.sx-k.main b,.sx-k.main .sx-kl{color:#fff}.sx-k.main .sx-ki{background:rgba(255,255,255,.18);color:#fff}
.sx-g2{display:grid;grid-template-columns:1fr;gap:0 12px}
@media(min-width:620px){.sx-g2{grid-template-columns:repeat(2,minmax(0,1fr))}}
.sx-dw{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding-top:6px}
.sx-dn{width:150px;height:150px;flex:0 0 auto}
.sx-dnn{font:800 34px var(--f);fill:var(--ink)}.sx-dnl{font:500 14px var(--f);fill:var(--mute)}
.sx-lg{display:flex;flex-direction:column;gap:10px;min-width:0;flex:1 1 120px}
.sx-li{display:flex;align-items:flex-start;gap:10px;min-width:0}
.sx-li i{flex:0 0 auto;width:14px;height:14px;border-radius:50%;margin-top:4px}
.sx-li b{display:block;font-size:16px;overflow-wrap:anywhere}.sx-li span span{display:block;font-size:15px;color:var(--mute)}
.sx-lr{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr) 28px;align-items:center;gap:10px;width:100%;background:none;border:0;padding:8px 0;font:inherit;color:var(--ink);text-align:left;cursor:pointer}
.sx-ln{font-size:16px;font-weight:600;overflow-wrap:anywhere;min-width:0}
.sx-lsub{display:block;font-size:13px;font-weight:500;margin-top:1px}
.sx-lr b{text-align:right;font-size:17px;font-variant-numeric:tabular-nums}
.sx-bar{display:block;height:12px;border-radius:99px;background:var(--line);overflow:hidden;min-width:0}
.sx-bar i{display:block;height:100%;border-radius:99px}
.sx-free,.sx-idle{display:flex;align-items:center;gap:14px}
.sx-fi{flex:0 0 auto;width:46px;height:46px;border-radius:50%;display:grid;place-items:center;background:color-mix(in srgb,var(--accent) 12%,transparent);color:var(--accent)}
.sx-fi svg{width:24px;height:24px}
.sx-free b{display:block;font-size:17px;color:var(--accent)}.sx-free span span,.sx-idle span span{display:block;font-size:15px;color:var(--mute);overflow-wrap:anywhere}
.sx-idle{border-top:1px solid var(--line);margin-top:6px;padding-top:12px}.sx-idle b{display:block;font-size:15px}
.sx-idle .sx-fi{background:color-mix(in srgb,var(--mute) 14%,transparent);color:var(--mute)}
.sx-res .sx-card{display:flex;align-items:center;gap:14px}
.sx-ri{flex:0 0 auto;width:52px;height:52px;border-radius:50%;display:grid;place-items:center}
.sx-ri svg{width:26px;height:26px}
.sx-res b{display:block;font-size:38px;line-height:1.05;font-weight:800;font-variant-numeric:tabular-nums}
.sx-rl{display:block;font-size:17px;font-weight:700}.sx-rp{display:block;font-size:15px;color:var(--mute)}
.sx-res .ok{background:color-mix(in srgb,var(--td) 9%,var(--surface));border-color:color-mix(in srgb,var(--td) 25%,var(--line))}
.sx-res .ok .sx-ri{background:color-mix(in srgb,var(--td) 16%,transparent);color:var(--td)}.sx-res .ok b,.sx-res .ok .sx-rl{color:var(--td)}
.sx-res .no{background:color-mix(in srgb,var(--od) 8%,var(--surface));border-color:color-mix(in srgb,var(--od) 22%,var(--line))}
.sx-res .no .sx-ri{background:color-mix(in srgb,var(--od) 14%,transparent);color:var(--od)}.sx-res .no b{color:var(--od)}
.sx-pr{display:grid;grid-template-columns:44px minmax(0,1.3fr) minmax(0,1fr) auto;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--line)}
.sx-av{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:18px}
.sx-pn b{display:block;font-size:16px;overflow-wrap:anywhere}.sx-pn span{display:block;font-size:14px;color:var(--mute)}
.sx-pb{display:flex;align-items:center;gap:8px;min-width:0}.sx-pb .sx-bar{flex:1 1 auto}.sx-pb b{font-size:16px;font-variant-numeric:tabular-nums}
.sx-px{font-size:14px;font-weight:600;color:var(--mute);background:color-mix(in srgb,var(--mute) 12%,transparent);border-radius:99px;padding:5px 10px;text-align:center}
.sx-px.has{color:var(--od);background:color-mix(in srgb,var(--od) 12%,transparent)}
@media(max-width:480px){.sx-pr{grid-template-columns:44px minmax(0,1fr) auto}.sx-pb{grid-column:2 / 4;grid-row:2}.sx-k3{gap:8px}.sx-k{padding:12px 10px 10px}.sx-k b{font-size:30px}.sx-kl{font-size:13px}.sx-ki{width:32px;height:32px;right:8px;top:8px}.sx-ki svg{width:17px;height:17px}}
.sx-foot{font-size:14px;color:var(--mute);margin:14px 0 6px;line-height:1.45}
.seg button{font-size:16px;min-height:42px;padding:0 20px}
.qs button{font-size:16px;padding:9px 4px}.qs button span{font-size:13px}
.yr b{font-size:19px}
.nav button{position:relative}
.bdg{position:absolute;top:1px;left:calc(50% + 4px);min-width:17px;height:17px;border-radius:99px;background:var(--p1);color:#fff;font-size:10.5px;font-weight:700;display:grid;place-items:center;padding:0 4px}
.gate{text-align:center;padding:72px 12px;color:var(--mute);font-size:15px}
.gate img{width:72px;height:72px;margin-bottom:14px}
.gate b{display:block;color:var(--ink);font-size:21px;margin-bottom:8px}
.gk{display:flex;gap:8px;max-width:360px;margin:22px auto 0}
.gk input{flex:1;min-width:0;font:500 17px var(--f);color:var(--ink);background:var(--surface);border:2px solid var(--line);border-radius:14px;padding:0 14px;height:50px;outline:0}
.gk input:focus{border-color:var(--accent)}
.gkb{flex:none;border:0;border-radius:14px;background:var(--accent);color:var(--accent-ink);font:700 16px var(--f);padding:0 20px;min-height:50px;cursor:pointer}
.me{font-size:12px;font-weight:700;color:var(--accent);background:color-mix(in srgb,var(--accent) 12%,transparent);border-radius:99px;padding:2px 9px}
.greet{position:fixed;left:16px;right:16px;top:50%;transform:translateY(-50%);max-height:82vh;overflow:auto;max-width:480px;margin:0 auto;z-index:25;background:var(--bg);border-radius:24px;padding:22px 20px 18px;box-shadow:var(--shadow);border:1px solid var(--line);animation:up .22s ease}
.greet h2{font-size:25px;font-weight:800;letter-spacing:-.025em;line-height:1.15;margin:0 0 4px;overflow-wrap:anywhere}
.greet .gs{color:var(--mute);font-size:14px;margin:0 0 12px}
.greet .gh{font-size:13px;font-weight:700;margin:10px 0 2px}
.greet .gi{display:flex;gap:10px;align-items:baseline;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--line);font-size:16px}
.greet .gi span:first-child{min-width:0;overflow-wrap:anywhere}
.greet .gi .gd{flex:none;font-size:13px;color:var(--mute)}
.greet .gi.urg span:first-child{color:var(--p1);font-weight:600}
.greet .gnone{padding:12px 0;color:var(--mute);font-size:15px}
.gok{width:100%;margin-top:16px;min-height:50px;border:0;border-radius:14px;background:var(--accent);color:var(--accent-ink);font:700 17px var(--f);cursor:pointer}
.pbanner{display:block;width:100%;margin:8px 0 0;border:1px solid var(--accent);border-radius:12px;background:color-mix(in srgb,var(--accent) 10%,transparent);color:var(--accent);font:600 14px var(--f);padding:10px 12px;text-align:left;cursor:pointer}
.pbanner.info{border-style:dashed;cursor:default;font-weight:500;color:var(--mute);border-color:var(--line);background:none}
.ds.inf{margin-top:3px}.stw{color:var(--tm);font-weight:600}.std{color:var(--od)}
.rbtns{display:flex;gap:8px;margin-top:8px}
.rb{flex:1;min-height:44px;border-radius:12px;border:0;font:700 15px var(--f);cursor:pointer}
.rb.ok{background:var(--accent);color:var(--accent-ink)}
.rb.no{background:none;border:1.5px solid var(--od);color:var(--od)}
.greet textarea{width:100%;height:100px;font:500 16px var(--f);color:var(--ink);background:var(--surface);border:1.5px solid var(--line);border-radius:12px;padding:10px;outline:0;resize:none;margin-top:4px}
.gsec{width:100%;margin-top:8px;min-height:44px;border:0;background:none;color:var(--mute);font:600 15px var(--f);cursor:pointer}
.task.hl .tb{background:color-mix(in srgb,var(--accent) 16%,transparent);border-radius:10px}
.task .tb{transition:background .4s}
.stc{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:6px 0 10px}
.seg{display:inline-flex;background:var(--surface);border-radius:12px;padding:3px;gap:2px}
.seg button{border:0;background:none;font:600 14px var(--f);color:var(--mute);padding:0 16px;border-radius:10px;min-height:38px;cursor:pointer}
.seg button.on{background:var(--bg);color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.14)}
.yr{display:flex;align-items:center;gap:6px}
.yr button{width:40px;height:40px;border-radius:10px;border:1px solid var(--line);background:none;color:var(--ink);font:600 20px var(--f);cursor:pointer}
.yr button:disabled{opacity:.35;cursor:default}
.yr b{font-size:17px;min-width:52px;text-align:center;font-variant-numeric:tabular-nums}
.qs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin-bottom:12px}
.qs button{border:1px solid var(--line);background:none;border-radius:10px;padding:7px 4px;font:700 14px var(--f);color:var(--ink);cursor:pointer;line-height:1.2}
.qs button span{display:block;font-size:11px;font-weight:500;color:var(--mute)}
.qs button.on{border-color:var(--accent);color:var(--accent);background:color-mix(in srgb,var(--accent) 9%,transparent)}
.kpi{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:2px 0 10px}
.kpi div{background:var(--surface);border-radius:14px;padding:10px 12px;min-width:0}
.kpi b{display:block;font-size:28px;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.kpi span{display:block;font-size:12px;color:var(--mute);overflow-wrap:anywhere}
.str{padding:10px 0;border-bottom:1px solid var(--line)}
.snm{font-weight:700;font-size:16px;overflow-wrap:anywhere;margin-bottom:6px}
.sbar{display:grid;grid-template-columns:76px 1fr 32px;align-items:center;gap:8px;margin-top:4px}
.sbar .sl{font-size:12px;color:var(--mute)}
.sbar b{text-align:right;font-variant-numeric:tabular-nums;font-size:15px}
.bar{height:10px;border-radius:99px;background:var(--surface);overflow:hidden}
.bar i{display:block;height:100%;border-radius:99px;min-width:0}
.bar.d i{background:var(--td)}.bar.x i{background:var(--od)}.bar.w i{background:var(--accent)}
.kpi.k3{grid-template-columns:repeat(3,minmax(0,1fr))}
.kpi.k3 b{font-size:24px}
.nwr{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr) 34px;align-items:center;gap:10px;width:100%;background:none;border:0;border-bottom:1px solid var(--line);padding:10px 0;text-align:left;cursor:pointer;font:inherit;color:var(--ink)}
.nwn{font-weight:700;font-size:16px;overflow-wrap:anywhere;min-width:0}
.nws{display:block;font-size:12px;font-weight:500;margin-top:2px}
.nwr b{text-align:right;font-size:17px;font-variant-numeric:tabular-nums}
.sttw{overflow-x:auto;margin-bottom:6px}
.stt{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums;font-size:14px}
.stt th{font-size:12px;color:var(--mute);font-weight:600;text-align:right;padding:6px 4px;border-bottom:1px solid var(--line);white-space:nowrap}
.stt th:first-child,.stt td:first-child{text-align:left;overflow-wrap:anywhere}
.stt td{padding:9px 4px;border-bottom:1px solid var(--line);text-align:right}
.stt td.z{color:var(--mute)}.stt td.tot{font-weight:800}
.stt tr.all td{font-weight:700;border-bottom:0}

/* ═══════════ ПЛАНЁРКА · UX как у Los Todos, свой облик (13.10.2026) ═══════════
   Устройство экранов — как в Los Todos (проверенный образец: Todoist / Things / TickTick):
   крупный заголовок, плотный список с тонкими разделителями, кружок срочности, строка «срок · исполнитель»,
   плавающая «+» под большим пальцем, плавающая нижняя панель, отмена действий плашкой.
   Отличие от Los Todos: индигово-фиолетовая гамма, цветная шапка, квадратная «+», свои плашки и аватары. */
:root{
  --bg:#F7F7FC; --surface:#FFFFFF; --ink:#151833; --mute:#727896; --line:#E6E7F2; --soft:#F0F0F8;
  --accent:#4B4FD8; --accent2:#7B5CE6; --accent-ink:#FFFFFF; --navon:#ECEBFD; --nav:#FFFFFF;
  --p1:#E0484F; --p2:#E08A0C; --p3:#4B4FD8; --p4:#A4A8BD;
  --od:#E0484F; --td:#1B9A6B; --tm:#E08A0C; --wk:#7B5CE6;
  --hdr1:#4B4FD8; --hdr2:#7B5CE6;
  --scrim:rgba(21,24,51,.36);
  --sh1:0 1px 2px rgba(21,24,51,.05),0 4px 14px rgba(21,24,51,.06);
  --sh2:0 2px 6px rgba(21,24,51,.07),0 14px 32px rgba(21,24,51,.12);
  --shadow:var(--sh2);
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --bg:#0F1020; --surface:#191B30; --ink:#ECEDF8; --mute:#9196B3; --line:#262A45; --soft:#21243C;
  --accent:#8F8CFF; --accent2:#B39CFF; --accent-ink:#0F1020; --navon:#28284D; --nav:#191B30;
  --p1:#FF7A80; --p2:#FFB547; --p3:#8F8CFF; --p4:#6C7090;
  --od:#FF7A80; --td:#4ED3A0; --tm:#FFB547; --wk:#B39CFF;
  --hdr1:#2C2C74; --hdr2:#43308A;
  --scrim:rgba(3,3,10,.62);
  --sh1:0 1px 2px rgba(0,0,0,.3),0 6px 18px rgba(0,0,0,.28);
  --sh2:0 2px 8px rgba(0,0,0,.38),0 18px 40px rgba(0,0,0,.45);
}}
:root[data-theme="dark"]{
  --bg:#0F1020; --surface:#191B30; --ink:#ECEDF8; --mute:#9196B3; --line:#262A45; --soft:#21243C;
  --accent:#8F8CFF; --accent2:#B39CFF; --accent-ink:#0F1020; --navon:#28284D; --nav:#191B30;
  --p1:#FF7A80; --p2:#FFB547; --p3:#8F8CFF; --p4:#6C7090;
  --od:#FF7A80; --td:#4ED3A0; --tm:#FFB547; --wk:#B39CFF;
  --hdr1:#2C2C74; --hdr2:#43308A;
  --scrim:rgba(3,3,10,.62);
  --sh1:0 1px 2px rgba(0,0,0,.3),0 6px 18px rgba(0,0,0,.28);
  --sh2:0 2px 8px rgba(0,0,0,.38),0 18px 40px rgba(0,0,0,.45);
}
button,a,.prow,.ltr,.chip,.popt,.cald,.qs button,.seg button,.epill{transition:background-color .18s ease,box-shadow .2s ease,color .18s ease,transform .15s ease}
button:active:not(:disabled){transform:scale(.97)}
/* ШАПКА — цветная полоса (у Los Todos — светлая) */
.top{height:50px;background:linear-gradient(100deg,var(--hdr1),var(--hdr2));border-bottom:0;box-shadow:0 2px 10px color-mix(in srgb,var(--hdr1) 30%,transparent);color:#fff}
.logo img{width:28px;height:28px;background:#fff;border-radius:8px;padding:2px}
.wm{font:800 18px/1 var(--f);letter-spacing:-.025em;color:#fff}
.top .ib{color:#fff}
.top .ib:hover{background:rgba(255,255,255,.14)}
.top .ib.on{color:#fff;background:rgba(255,255,255,.2)}
.net{color:#fff;background:rgba(255,255,255,.2);font-weight:600;border-radius:99px;padding:3px 9px;margin-left:8px;font-size:11.5px}
.pbanner{margin:10px 0 0;border:0;border-radius:14px;background:color-mix(in srgb,var(--accent) 9%,var(--surface));color:var(--accent)}
.pbanner.info{background:var(--soft);color:var(--mute)}
/* КРУПНЫЙ ЗАГОЛОВОК + счётчик + сводка */
.ph{display:flex;align-items:center;gap:10px;margin:16px 0 0}
.pht{display:flex;align-items:center;gap:8px;flex:1;min-width:0;flex-wrap:wrap}
.ph h1,h1{margin:0;font-size:28px;font-weight:800;letter-spacing:-.03em;line-height:1.1}
.hcnt{display:inline-grid;place-items:center;min-width:28px;height:26px;padding:0 9px;border-radius:99px;font:700 14px var(--f);color:var(--accent);background:color-mix(in srgb,var(--accent) 12%,transparent)}
.hcnt.red{color:var(--p1);background:color-mix(in srgb,var(--p1) 12%,transparent)}
.padd,.addrow{display:none!important}
.psub{font-size:14px;color:var(--mute);margin:3px 0 4px}
/* ГРУППЫ — как в Los Todos: небольшой жирный заголовок, число рядом */
.sh2{display:flex;align-items:center;gap:8px;margin:16px 0 0;min-height:30px;font-size:14px;font-weight:700;color:var(--ink)}
.sh2 i{font-style:normal;font-weight:600;color:var(--mute)}
.sh2.red{color:var(--p1)}.sh2.amber{color:var(--p2)}
.sh2 .av{width:22px;height:22px;font-size:11px}
.sh2.who{margin:24px 0 2px;padding:8px 12px;min-height:46px;border-radius:14px;font-size:17px;font-weight:800;letter-spacing:-.01em;background:color-mix(in srgb,var(--c,var(--accent)) 10%,var(--surface));box-shadow:inset 3px 0 0 var(--c,var(--accent))}
.sh2.who .av{width:30px;height:30px;font-size:14px}
.sh2.who i{margin-left:auto;display:inline-grid;place-items:center;min-width:28px;height:26px;padding:0 9px;border-radius:99px;font-size:14px;font-weight:700;color:var(--c,var(--accent));background:var(--surface)}
/* ЗАДАЧИ — плотный список с тонкими линиями (как Los Todos) */
.task{gap:12px}
.tb{padding:9px 0 9px}
.tt{font-size:16.5px;font-weight:500}
.task.urg:not(.done) .tt{color:var(--p1);font-weight:600}
.task .ds{font-size:13px;line-height:1.3}
.task .ds.inf{font-size:12.5px;margin-top:2px}
.task .inf .stw,.task .inf .std{font-weight:500}
.cb{margin-top:9px}
.trow{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:3px;min-height:24px}
.dchip{display:inline-flex;align-items:center;gap:5px;font:500 13px var(--f);color:var(--mute)}
.dchip svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
.dchip.od{color:var(--od)}.dchip.td{color:var(--td)}.dchip.tm{color:var(--tm)}.dchip.wk{color:var(--wk)}
.epill{position:relative;display:inline-flex;align-items:center;gap:6px;margin-left:auto;min-height:26px;padding:0 4px 0 3px;border-radius:99px;background:color-mix(in srgb,var(--accent) 7%,transparent);cursor:pointer;max-width:60%;min-width:0}
.epill.st{padding-right:10px;font:500 13px var(--f);color:var(--mute);cursor:default}
.asel{appearance:none;-webkit-appearance:none;margin:0;border:0;min-height:26px;padding:2px 20px 2px 0;font:500 13px var(--f);color:var(--accent);cursor:pointer;min-width:0;max-width:100%;background:transparent url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%237B5CE6' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 3px center/13px}
.asel:focus{outline:2px solid color-mix(in srgb,var(--accent) 40%,transparent);outline-offset:2px;border-radius:6px}
.av{flex:none;display:inline-grid;place-items:center;width:20px;height:20px;border-radius:50%;font:700 10.5px var(--f);color:#fff;background:var(--c)}
.av.big{width:36px;height:36px;font-size:15px}
.av.none{background:var(--soft);color:var(--mute)}
.av svg{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2}
.rbtns{display:flex;gap:8px;margin-top:8px}
.rb{flex:1;min-height:38px;border-radius:12px;border:0;font:700 14px var(--f);cursor:pointer}
.rb.ok{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff}
.rb.no{background:color-mix(in srgb,var(--od) 10%,transparent);color:var(--od)}
/* КОМАНДА — список как в Los Todos */
.plist{margin-top:6px}
.prow{display:flex;align-items:center;gap:12px;width:100%;min-height:56px;padding:6px 0;border:0;border-bottom:1px solid var(--line);background:none;text-align:left;cursor:pointer;font:inherit;color:var(--ink)}
.prow .pn{flex:1;min-width:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:16.5px;font-weight:500;overflow-wrap:anywhere}
.me{font-size:11.5px;font-weight:700;color:var(--accent);background:color-mix(in srgb,var(--accent) 12%,transparent);border-radius:99px;padding:2px 8px}
.pcnt{font:600 15px var(--f);color:var(--accent);min-width:20px;text-align:right}
.pcnt.z{color:var(--mute)}
.prow svg{flex:none;width:18px;height:18px;stroke:var(--mute);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.tcard{margin-top:20px}
.tct{font-size:14px;font-weight:700;min-height:30px;display:flex;align-items:center}
.tci{display:flex;align-items:center;gap:12px;min-height:48px;border-bottom:1px solid var(--line);font-size:15.5px;color:var(--ink)}
.tci span:nth-child(2){flex:1}
.tci b{font-size:16px}
.tic{flex:none;width:30px;height:30px;border-radius:9px;display:grid;place-items:center;color:var(--accent);background:color-mix(in srgb,var(--accent) 10%,transparent)}
.tic svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.tclink{display:flex;align-items:center;justify-content:space-between;width:100%;min-height:48px;border:0;border-bottom:1px solid var(--line);background:none;color:var(--accent);font:600 15.5px var(--f);cursor:pointer;padding:0}
.tclink svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
/* НИЖНЯЯ ПАНЕЛЬ — плавающая, как в Los Todos, но в своей гамме */
.nav{box-shadow:var(--sh2);border:1px solid var(--line);background:var(--nav)}
.nav button{color:var(--mute)}
.nav button.on{background:linear-gradient(135deg,color-mix(in srgb,var(--accent) 16%,var(--surface)),color-mix(in srgb,var(--accent2) 14%,var(--surface)));color:var(--accent)}
.nav button[data-t=urgent]:not(.on){color:color-mix(in srgb,var(--p1) 80%,var(--mute))}
@media (hover:none){.nav button:not(.on):hover{color:var(--mute)}}
/* «+» — квадратная с градиентом (у Los Todos — круглая синяя) */
.fab{width:56px;height:56px;border-radius:18px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;box-shadow:0 10px 24px color-mix(in srgb,var(--accent) 38%,transparent)}
/* окна в своей гамме */
.sheet{border-radius:24px 24px 0 0}
.send{border-radius:14px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff}
.chip.exs.on{color:var(--accent);border-color:var(--accent)}
.pick,.menu{border-radius:20px}
.cald.sel{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff}
.sw.on{background:var(--accent)}
.gok,.gkb,.gkb2{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff}
.toast{bottom:calc(82px + env(safe-area-inset-bottom,0px))}
.bdg{background:var(--p1)}
/* СТАТИСТИКА — сводка карточками (здесь карточки уместны), в той же гамме */
.sx-k3{gap:8px}
.sx-k{border:1px solid var(--line);box-shadow:none;border-radius:16px;padding:12px 12px 12px}
.sx-ki{position:static;width:34px;height:34px;border-radius:10px;margin-bottom:8px}
.sx-k b{font-size:30px}
.sx-kl{font-size:13.5px}
.sx-k.main{background:linear-gradient(140deg,var(--accent),var(--accent2));border-color:transparent;box-shadow:0 10px 24px color-mix(in srgb,var(--accent) 30%,transparent)}
.sx-k.main .sx-ki{background:rgba(255,255,255,.2);color:#fff}
.sx-card{border:1px solid var(--line);box-shadow:none;border-radius:18px}
.sx-ct{font-size:17px;font-weight:800}
.lt{margin-top:4px}
.lth,.ltt{display:grid;grid-template-columns:minmax(0,1fr) 52px 60px;align-items:center;gap:8px}
.lth{font-size:13px;color:var(--mute);padding:6px 8px;border-bottom:1px solid var(--line)}
.lth span:not(:first-child),.ltr b,.ltr em,.ltt b,.ltt em{text-align:right}
.ltr{display:grid;grid-template-columns:24px minmax(0,1fr) 52px 60px;align-items:center;gap:8px;width:100%;border:0;background:none;font:inherit;color:var(--ink);text-align:left;padding:8px;border-radius:12px;margin-top:2px;cursor:pointer}
.ltr .av{width:24px;height:24px;font-size:11.5px}
.ltr.on{background:color-mix(in srgb,var(--c) 10%,transparent)}
.ltn{font-size:15.5px;font-weight:500;overflow-wrap:anywhere;min-width:0}
.lts{display:block;font-size:12px;font-weight:500;margin-top:1px}
.ltr b{font-size:16px;font-weight:600;font-variant-numeric:tabular-nums}
.ltr em{font-style:normal;font-size:15px;font-variant-numeric:tabular-nums;color:var(--mute)}
.ltr.on em{color:var(--c);font-weight:600}
.ltt{padding:12px 8px 4px;border-top:1px solid var(--line);margin-top:4px;font-size:16px;font-weight:700}
.ltt b,.ltt em{font-style:normal}
.sx-res .ok,.sx-res .no{border:0}
.sx-bar{background:var(--soft)}
.seg{background:var(--soft);border-radius:12px}
.seg button.on{background:var(--surface);color:var(--accent)}
.yr button{border:1px solid var(--line);border-radius:12px}
.qs button{border:1px solid var(--line);border-radius:12px}
.qs button.on{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border-color:transparent}
.qs button.on span{color:rgba(255,255,255,.85)}
.sx-pr .av{width:40px;height:40px;font-size:16px}
/* ИСТОРИЯ — тоже список */
.hlist{margin-top:2px}
.hrow{display:flex;align-items:center;gap:12px;min-height:54px;padding:6px 0;border-bottom:1px solid var(--line)}
.hrow .av{width:28px;height:28px;font-size:12.5px}
.hrow .hb{flex:1;min-width:0}
.htt{font-size:16px;font-weight:500;overflow-wrap:anywhere}
.hrow .ds{font-size:12.5px;margin-top:1px}
.hrow .ib{color:var(--mute)}
/* «Вернуть в работу» — может любой участник */
.hret{flex:none;display:inline-flex;align-items:center;gap:5px;min-height:34px;padding:0 12px 0 9px;border-radius:99px;border:1px solid var(--line);background:var(--surface);font:600 13px var(--f);color:var(--accent);cursor:pointer}
.hret svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;flex:none}
/* «Срочное»: исполнитель — простой текст сразу после срока, тем же кеглем */
.trow.lft{justify-content:flex-start;flex-wrap:wrap;gap:4px 8px}
.dchip.wt{color:var(--mute);overflow-wrap:anywhere;min-width:0}
.dchip+.dchip.wt::before{content:'·';margin-right:3px}
/* ═══════════ ЦВЕТА В СТИЛЕ AMAZON (13.10.2026) ═══════════
   Шапка — тёмно-синяя (#131921 → #232F3E), основные кнопки — жёлтые (#FFD814) с тёмным текстом,
   «+» и акценты — оранжевые (#FF9900), ссылки и выбранное — бирюзовые (#007185), фон — светло-серый (#EAEDED). */
:root{
  --bg:#EAEDED; --surface:#FFFFFF; --ink:#0F1111; --mute:#565959; --line:#D5D9D9; --soft:#F0F2F2;
  --accent:#007185; --accent2:#00838F; --accent-ink:#FFFFFF; --navon:#E6F2F3; --nav:#FFFFFF;
  --p1:#CC0C39; --p2:#C45500; --p3:#007185; --p4:#A6ABAB;
  --od:#CC0C39; --td:#067D62; --tm:#C45500; --wk:#007185;
  --hdr1:#131921; --hdr2:#232F3E; --amz-y:#FFD814; --amz-y2:#F7CA00; --amz-o:#FF9900; --amz-o2:#FA8900; --amz-btn-ink:#0F1111;
  --scrim:rgba(15,17,17,.45);
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --bg:#0F1111; --surface:#1B2026; --ink:#F0F2F2; --mute:#A2A6A6; --line:#2E353C; --soft:#232A31;
  --accent:#4FC3CB; --accent2:#2FB5BE; --accent-ink:#0F1111; --navon:#1F3438; --nav:#1B2026;
  --p1:#FF6B81; --p2:#FFA24C; --p3:#4FC3CB; --p4:#6B7178;
  --od:#FF6B81; --td:#3CC79E; --tm:#FFA24C; --wk:#4FC3CB;
  --hdr1:#131921; --hdr2:#232F3E; --scrim:rgba(0,0,0,.65);
}}
:root[data-theme="dark"]{
  --bg:#0F1111; --surface:#1B2026; --ink:#F0F2F2; --mute:#A2A6A6; --line:#2E353C; --soft:#232A31;
  --accent:#4FC3CB; --accent2:#2FB5BE; --accent-ink:#0F1111; --navon:#1F3438; --nav:#1B2026;
  --p1:#FF6B81; --p2:#FFA24C; --p3:#4FC3CB; --p4:#6B7178;
  --od:#FF6B81; --td:#3CC79E; --tm:#FFA24C; --wk:#4FC3CB;
  --hdr1:#131921; --hdr2:#232F3E; --scrim:rgba(0,0,0,.65);
}
.top{background:linear-gradient(180deg,var(--hdr1),var(--hdr2));box-shadow:0 2px 6px rgba(0,0,0,.25)}
.top::after{content:"";position:absolute;left:0;right:0;bottom:-3px;height:3px;background:linear-gradient(90deg,var(--amz-o),var(--amz-o2) 60%,transparent)}
.top{position:sticky}
/* основные кнопки — жёлтые «как "Добавить в корзину"», тёмный текст */
.rb.ok,.gok,.gkb,.gkb2,.send,.npb{background:var(--amz-y);color:var(--amz-btn-ink);border:1px solid var(--amz-y2);box-shadow:0 2px 5px rgba(213,217,217,.5)}
.rb.ok:hover,.gok:hover,.gkb:hover,.send:hover{background:var(--amz-y2)}
.send svg{stroke:var(--amz-btn-ink)}
/* «+» — оранжевая, как «Купить сейчас» */
.fab{background:linear-gradient(180deg,var(--amz-o),var(--amz-o2));color:var(--amz-btn-ink);box-shadow:0 8px 20px rgba(255,153,0,.38)}
.fab svg{stroke:var(--amz-btn-ink)}
/* выбранное, счётчики, ссылки — бирюза; срочное — оранжево-красное */
.hcnt{color:var(--amz-btn-ink);background:var(--amz-o)}
.hcnt.red{color:#fff;background:var(--p1)}
.nav button.on{background:var(--navon);color:var(--accent);box-shadow:inset 0 -3px 0 var(--amz-o)}
.nav button[data-t=urgent]:not(.on){color:var(--p2)}
.bdg{background:var(--amz-o);color:var(--amz-btn-ink)}
.cald.sel{background:var(--amz-y);color:var(--amz-btn-ink)}
.cald.td{color:var(--accent);box-shadow:inset 0 0 0 1.5px var(--accent)}
.qs button.on{background:var(--amz-y);color:var(--amz-btn-ink);border-color:var(--amz-y2)}
.qs button.on span{color:#3a3a3a}
.seg button.on{color:var(--accent);box-shadow:inset 0 -3px 0 var(--amz-o)}
.sx-k.main{background:linear-gradient(160deg,var(--hdr2),var(--hdr1));box-shadow:0 8px 20px rgba(19,25,33,.3)}
.sx-k.main .sx-ki{background:rgba(255,153,0,.25);color:var(--amz-o)}
.sw.on{background:var(--accent)}
.epill{background:color-mix(in srgb,var(--accent) 8%,transparent)}
.asel{color:var(--accent);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23007185' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E")}
.me{color:var(--amz-btn-ink);background:var(--amz-y)}
.pbanner{background:color-mix(in srgb,var(--accent) 8%,var(--surface));color:var(--accent)}
@media (max-width:420px){.ph h1,h1{font-size:26px}.sx-k b{font-size:26px}.sx-kl{font-size:12.5px}.ltr{grid-template-columns:24px minmax(0,1fr) 40px 50px}.lth,.ltt{grid-template-columns:minmax(0,1fr) 40px 50px}}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}
/* ПРОКРУТКА ВНУТРИ ЛЕНТЫ (13.10.2026). На iPhone «резинка» прокрутки всего документа утягивала
   за собой нижнюю панель и «+». Теперь документ стоит на месте, прокручивается сама лента (.app):
   пружинка у края остаётся (внутри ленты), а панель всегда на своём месте внизу. */
html,body{height:100%;overflow:hidden}
.app.under{pointer-events:none;overflow:hidden}
/* КЛАВИАТУРА (13.10.2026). На iPhone клавиатура закрывает закреплённые снизу окна: они стоят у низа
   экрана, а видимая часть стала короче. Скрипт меряет видимую часть (visualViewport) и даёт --kb
   (высота клавиатуры), --vvh/--vvt (высота и верх видимого); окна встают над клавиатурой,
   а поле, в котором печатают, само подтягивается в видимую часть. */
html.kb .sheet{bottom:var(--kb);max-height:calc(var(--vvh) - 12px);padding-bottom:12px}
html.kb .pick{bottom:calc(var(--kb) + 8px);max-height:calc(var(--vvh) - 16px)}
html.kb .greet{top:calc(var(--vvt) + var(--vvh) / 2);max-height:calc(var(--vvh) - 24px)}
html.kb .app{bottom:var(--kb)}
html.kb .nav,html.kb .fab,html.kb .toast{visibility:hidden}
.app.under::after{content:"";position:fixed;inset:0;background:color-mix(in srgb,var(--bg) 55%,#fff);opacity:var(--fog,0);pointer-events:none}
body{padding-inline:0}
.app{background:var(--bg);touch-action:pan-y pinch-zoom;position:fixed;inset:0;max-width:none;margin:0;overflow-x:hidden;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior-y:contain;
  padding-inline:max(16px,calc(50% - 280px));padding-top:env(safe-area-inset-top,0px)}
</style>
<div class="app" id="app"></div>
<button class="fab" id="fab" data-a="add" aria-label="Добавить задачу"><svg viewBox="0 0 24 24"><path d="M12 4v16M4 12h16"/></svg></button>
<nav class="nav" id="nav"></nav>
<div id="layer"></div>
<div class="toast" id="toast" hidden><span id="tmsg"></span><button id="undo" type="button">Отменить</button></div>
<script>
(function(){
  var API='/planerka/api.php', KS='planerka-key', KC='planerka-cache', KU='planerka-ui', KG='planerka-greeted-';
  var PN={1:'Очень срочно',2:'Срочно',3:'Несрочно',4:'Потом'};
  var MON=['янв.','февр.','мар.','апр.','мая','июн.','июл.','авг.','сент.','окт.','нояб.','дек.'];
  var WD=['Воскресенье','Понедельник','Вторник','Среда','Четверг','Пятница','Суббота'];
  var pad=function(n){return (n<10?'0':'')+n;};
  var ymd=function(d){return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());};
  var parse=function(s){var p=s.split('-');return new Date(+p[0],+p[1]-1,+p[2]);};
  var today=function(){return ymd(new Date());};
  var addDays=function(s,n){var d=parse(s);d.setDate(d.getDate()+n);return ymd(d);};
  var diff=function(a,b){return Math.round((parse(a)-parse(b))/864e5);};
  var uid=function(){return 'c'+Date.now().toString(36)+Math.random().toString(36).slice(2,7);};
  function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function short(s){var d=parse(s),t=new Date();return d.getDate()+' '+MON[d.getMonth()]+(d.getFullYear()!==t.getFullYear()?' '+d.getFullYear():'');}
  function dueLabel(s){var d=diff(s,today());return d===0?'Сегодня':d===1?'Завтра':d===-1?'Вчера':(d>1&&d<7)?WD[parse(s).getDay()]:short(s);}
  function dueCls(s){var d=diff(s,today());return d<0?'od':d===0?'td':d===1?'tm':d<7?'wk':'';}

  var I={
    inbox:'<svg viewBox="0 0 24 24"><path d="M4 13l2.5-7.5A2 2 0 018.4 4h7.2a2 2 0 011.9 1.5L20 13v5a2 2 0 01-2 2H6a2 2 0 01-2-2z"/><path d="M4 13h4.5a1 1 0 011 .8 2.5 2.5 0 005 0 1 1 0 011-.8H20"/></svg>',
    person:'<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.8"/><path d="M4.5 20.5c.8-4 3.7-6 7.5-6s6.7 2 7.5 6"/></svg>',
    book:'<svg viewBox="0 0 24 24"><rect x="4.5" y="3.5" width="15" height="17" rx="2.5"/><circle cx="12" cy="10" r="2.6"/><path d="M7.8 17.5c.7-2 2.2-3 4.2-3s3.5 1 4.2 3"/></svg>',
    people:'<svg viewBox="0 0 24 24"><circle cx="9" cy="8.5" r="3.3"/><path d="M2.8 20c.6-3.6 3-5.4 6.2-5.4s5.6 1.8 6.2 5.4"/><circle cx="17.5" cy="9.5" r="2.5"/><path d="M17.5 14.4c2.4 0 3.9 1.4 4.3 4"/></svg>',
    list:'<svg viewBox="0 0 24 24"><rect x="4" y="3.5" width="16" height="17" rx="3"/><path d="M8 9h8M8 13h8M8 17h5"/></svg>',
    chart:'<svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
    bolt:'<svg viewBox="0 0 24 24"><path d="M13 2.5L5 13.5h6l-1 8 8-11h-6z"/></svg>',
    dots:'<svg viewBox="0 0 24 24"><circle cx="5.5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="18.5" cy="12" r="1.8"/></svg>',
    cal:'<svg viewBox="0 0 24 24"><rect x="4" y="4.5" width="16" height="16" rx="3"/><path d="M4 9.5h16M8 3v3M16 3v3"/></svg>',
    up:'<svg viewBox="0 0 24 24"><rect x="4" y="4.5" width="16" height="16" rx="3"/><path d="M4 9.5h16M8 3v3M16 3v3"/><path d="M8.5 14h.01M12 14h.01M15.5 14h.01M8.5 17h.01M12 17h.01" stroke-width="2.6"/></svg>',
    check:'<svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
    flag:'<svg viewBox="0 0 24 24"><path d="M5 21V4M5 4h11l-2 4 2 4H5"/></svg>',
    send:'<svg viewBox="0 0 24 24"><path d="M12 19V5M5.5 11.5L12 5l6.5 6.5"/></svg>',
    trash:'<svg viewBox="0 0 24 24"><path d="M5 7h14M10 7V4.5h4V7M7 7l1 13h8l1-13M10 11v6M14 11v6"/></svg>',
    back:'<svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>',
    moon:'<svg viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 019.5 4a8 8 0 1010.5 10.5z"/></svg>',
    sun:'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M5.3 18.7l1.8-1.8M16.9 7.1l1.8-1.8"/></svg>',
    done:'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M8.2 12.3l2.5 2.5 5-5.2"/></svg>',
    wk:'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2"/></svg>',
    next:'<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
    phone:'<svg viewBox="0 0 24 24"><path d="M6.6 3.5h2.8l1.4 4.2-2 1.4a12 12 0 006.1 6.1l1.4-2 4.2 1.4v2.8a2 2 0 01-2.2 2A16.8 16.8 0 014.6 5.7a2 2 0 012-2.2z"/></svg>',
    eye:'<svg viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>',
    eyeoff:'<svg viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 5.1A10.7 10.7 0 0112 5c6.4 0 10 7 10 7a17.6 17.6 0 01-3.2 4.2M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7c1.6 0 3-.4 4.3-1M9.9 9.9a3 3 0 004.2 4.2"/></svg>',
    none:'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M6 6l12 12"/></svg>',
    chev:'<svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>',
    undo:'<svg viewBox="0 0 24 24"><path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 010 11H11"/></svg>'
  };
  var LOGO='<img src="data:image/png;base64,/*MARK*/" alt="" width="26" height="26">';

  /* ключ доступа приходит в ссылке #k=... и запоминается в телефоне; имя исполнителя берётся из адреса /planerka/<имя> */
  var key=null;
  try{
    var mk=(location.hash+'&'+location.search).match(/[#?&]k=([0-9a-f]{16,64})/);
    if(mk){key=mk[1];try{localStorage.setItem(KS,key);}catch(e){} try{history.replaceState(null,'',location.pathname+location.search);}catch(e){}}
    else key=localStorage.getItem(KS);
  }catch(e){}
  var meSlug=null;
  var meFromPath=false;
  (function(){var m=location.pathname.match(/\/planerka\/([a-z0-9][a-z0-9-]{1,23})\/?$/);if(m&&m[1]!=='index'&&m[1]!=='api'){meSlug=m[1];meFromPath=true;}
    if(!meSlug){try{meSlug=localStorage.getItem('planerka-me')||null;}catch(e){}}})();
  var S={tasks:[],people:[],theme:null,err:null,at:null,loaded:false,rev:-1};
  var U={tab:meSlug?'me':'inbox',person:null,showDone:false,sheet:null,pick:null,menu:false,greet:null,resp:null,prevTab:null,st:{mode:'q',year:new Date().getFullYear(),q:Math.floor(new Date().getMonth()/3)}};
  // сохранённые на устройстве задачи: приложение сразу рабочее, даже без интернета
  try{var c=JSON.parse(localStorage.getItem(KC)||'null');if(c&&Array.isArray(c.tasks)){S.tasks=c.tasks;S.people=c.people||[];S.at=c.at||null;S.owner=c.owner||null;S.loaded=S.tasks.length>0||S.people.length>0;}}catch(e){}
  try{var u=JSON.parse(localStorage.getItem(KU)||'null');if(u){S.theme=u.theme||null;if(u.tab&&!meSlug&&u.tab!=='me')U.tab=u.tab;}}catch(e){}
  function saveCache(){try{localStorage.setItem(KC,JSON.stringify({tasks:S.tasks,people:S.people,at:S.at,owner:S.owner||null}));}catch(e){}}
  function saveUi(){try{localStorage.setItem(KU,JSON.stringify({theme:S.theme,tab:U.tab}));}catch(e){}}
  var $=function(id){return document.getElementById(id);};
  var undoFn=null,toastT=null;

  function fmtDT(ms){var d=new Date(ms);return d.getDate()+' '+MON[d.getMonth()]+', '+pad(d.getHours())+':'+pad(d.getMinutes());}
  // статус, время принятия и завершения, отказ — одной строкой под задачей
  function infoParts(t,full){
    var a=[];
    if(t.assignee&&t.status==='new'&&!t.done)a.push('<span class="stw">Ждёт ответа</span>');
    if(t.acceptedAt)a.push('Принята '+fmtDT(t.acceptedAt));
    if(t.done&&t.doneAt)a.push('Завершена '+fmtDT(t.doneAt));
    var dl=t.declines&&t.declines.length?t.declines:null;
    if(dl&&!t.assignee&&!t.done){var l=dl[dl.length-1];a.push('<span class="std">Отказ ('+esc(who(l.by)||l.by)+', '+fmtDT(l.at)+'): '+esc(l.reason)+'</span>');}
    if(full&&dl&&dl.length>1)for(var i=dl.length-2;i>=0;i--)a.push('<span class="std">Ранее отказ ('+esc(who(dl[i].by)||dl[i].by)+', '+fmtDT(dl[i].at)+'): '+esc(dl[i].reason)+'</span>');
    return a;
  }
  function who(id){for(var i=0;i<S.people.length;i++)if(S.people[i].id===id)return S.people[i].name;return '';}
  function me(){for(var i=0;i<S.people.length;i++)if(S.people[i].id===meSlug)return S.people[i];return null;}

  function isDark(){
    if(S.theme)return S.theme==='dark';
    try{return window.matchMedia('(prefers-color-scheme: dark)').matches;}catch(e){return false;}
  }
  function applyTheme(){
    var r=document.documentElement;
    if(S.theme)r.setAttribute('data-theme',S.theme);else r.removeAttribute('data-theme');
    var m=document.querySelector('meta[name=theme-color]');
    if(m)m.setAttribute('content','#131921');
  }

  /* Обмен с сервером. Правки (создать, изменить, ответить) сначала ложатся в ОЧЕРЕДЬ на устройстве и уходят
     строго по порядку. Нет интернета — очередь ждёт (и переживает перезагрузку), появился — уходит сама.
     Пока в очереди что-то есть, ответ сервера не затирает то, что человек уже видит у себя. */
  var KQ='planerka-outbox',Q=[];
  try{Q=JSON.parse(localStorage.getItem(KQ)||'[]');if(!Array.isArray(Q))Q=[];}catch(e){Q=[];}
  function saveQ(){try{localStorage.setItem(KQ,JSON.stringify(Q));}catch(e){}}
  var pending=0,flushing=false;
  function send(body){
    return fetch(API,{method:'POST',headers:{'Content-Type':'application/json','X-Key':key||''},body:JSON.stringify(body)})
      .then(function(r){
        if(r.status===401||r.status===403){S.err='auth';throw new Error('auth');}
        if(r.status>=400&&r.status<500){var e=new Error('rejected');e.rejected=true;throw e;}
        if(!r.ok)throw new Error('http '+r.status);
        return r.json();
      });
  }

  /* Присутствие исполнителей: зелёная точка — приложение открыто, жёлтая — свёрнуто, красная — закрыто.
     Открытое приложение сообщает о себе каждые 20 с; свернули — «свёрнуто»; закрыли — «закрыто».
     Если сигналов нет больше 30 минут (телефон закрыл приложение молча) — тоже «закрыто». */
  var PRES={},PRES_S='',PSKEW=0;
  function presOf(id){var p=PRES[id];if(!p)return 'r';var age=(Date.now()-PSKEW)-p.at;if(p.s==='open'&&age<60000)return 'g';if((p.s==='open'||p.s==='bg')&&age<1800000)return 'y';return 'r';}
  function pdot(id,inl){var c=presOf(id);return '<i class="pdot '+c+(inl?' inl':'')+'" title="'+(c==='g'?'Приложение открыто':c==='y'?'Приложение свёрнуто':'Приложение закрыто')+'"></i>';}
  function presUpd(j){if(!j||!j.presence)return;PSKEW=j.now?Date.now()-j.now:0;var s=JSON.stringify(j.presence);if(s!==PRES_S){PRES_S=s;PRES=j.presence;if(U.tab==='people'||U.tab==='all'||U.tab==='stats')renderApp();}}
  setInterval(function(){if(!document.hidden&&(U.tab==='people'||U.tab==='all'||U.tab==='stats')&&!U.sheet)renderApp();},15000);
  /* версия приложения: открытая давно Планёрка (особенно «на экране Домой») сама переходит на новую версию */
  var APPBUILD='/*BUILD*/';
  function buildCheck(j){
    if(!j||!j.build||!APPBUILD||APPBUILD.charAt(0)==='/'||j.build===APPBUILD)return;
    if(U.sheet||U.pick||U.resp||Q.length)return;
    var k='planerka-reload-'+j.build;try{if(sessionStorage.getItem(k))return;sessionStorage.setItem(k,'1');}catch(e){}
    location.reload();
  }
  function applyList(j){
    presUpd(j);buildCheck(j);
    if(j&&j.same){var was=S.err;S.err=null;S.at=Date.now();if(!S.loaded){S.loaded=true;renderApp();afterLoad();}else if(was)renderApp();return;}
    if(!j||!j.tasks)return;
    S.tasks=j.tasks;if(Array.isArray(j.people))S.people=j.people;if(j.rev!==undefined)S.rev=j.rev;if(j.owner)S.owner=j.owner;
    S.err=null;S.at=Date.now();S.loaded=true;saveCache();renderApp();afterLoad();
  }
  function netFail(e){if(e&&e.message==='auth'){renderApp();return;}if(S.err!=='net'){S.err='net';renderApp();}}
  // счётчик неотправленного в шапке обновляется сразу, без перерисовки всего экрана
  function netLabel(){var e=document.getElementById("netst");if(e)e.textContent="нет сети"+(Q.length?" · ждут: "+Q.length:"");}
  function call(body){Q.push(body);saveQ();netLabel();flush();}
  function flush(){
    if(flushing||!Q.length||!key||S.err==='auth')return;
    flushing=true;
    send(Q[0]).then(function(j){
      Q.shift();saveQ();flushing=false;netLabel();
      if(Q.length)flush();else{S.rev=-1;applyList(j);}
    }).catch(function(e){
      flushing=false;
      if(e&&e.rejected){Q.shift();saveQ();if(Q.length)flush();else refresh();return;}   // сервер отклонил правку — дальше не застреваем
      netFail(e);
    });
  }
  function refresh(){
    if(!key)return;
    if(Q.length){flush();return;}
    if(pending)return;
    pending++;
    send(S.rev>=0?{op:'list',since:S.rev}:{op:'list'}).then(function(j){pending--;if(!Q.length)applyList(j);}).catch(function(e){pending--;netFail(e);});
  }
  window.addEventListener('online',function(){refresh();});
  var PID=Math.random().toString(36).slice(2,12),PCLOSING=false,PT=0;
  function ping(st){if(!key||!meSlug)return;PT=Math.max(PT+1,Date.now());try{fetch(API,{method:'POST',keepalive:true,headers:{'Content-Type':'application/json','X-Key':key},body:JSON.stringify({op:'ping',by:meSlug,s:st,pid:PID,t:PT})}).catch(function(){});}catch(e){}}
  setInterval(function(){if(!document.hidden&&!PCLOSING)ping('open');},20000);
  document.addEventListener('visibilitychange',function(){if(!PCLOSING)ping(document.hidden?'bg':'open');});
  window.addEventListener('pagehide',function(e){if(e&&e.persisted){ping('bg');return;}PCLOSING=true;ping('closed');});
  window.addEventListener('pageshow',function(e){if(e&&e.persisted){PCLOSING=false;ping('open');}});
  setTimeout(function(){if(!document.hidden)ping('open');},300);

  // удалить задачу может постановщик (owner) или тот, кто её поставил
  function canDel(t){return !!(t&&meSlug&&(meSlug===(S.owner||'')||t.cby===meSlug));}
  function find(id){for(var i=0;i<S.tasks.length;i++)if(S.tasks[i].id===id)return S.tasks[i];return null;}
  function upd(id,f){
    var t=find(id);if(!t)return;
    for(var k in f)t[k]=f[k];
    if(f.done!==undefined)t.doneAt=f.done?Date.now():null;
    if('assignee' in f){var nw=Date.now();t.status=f.assignee?(f.assignee===meSlug?'accepted':'new'):null;t.assignedAt=f.assignee?nw:null;t.acceptedAt=(f.assignee&&f.assignee===meSlug)?nw:null;}
    if(f.del)S.tasks=S.tasks.filter(function(x){return x.id!==id;});
    saveCache();renderApp();call({op:'upd',id:id,f:f,by:meSlug||null});
  }
  function addTask(o){
    var t={id:uid(),t:o.t,desc:o.desc||'',phone:o.phone||'',due:o.due||null,pri:o.pri||4,done:false,doneAt:null,assignee:o.assignee||null,items:o.items||[],src:null,created:Date.now()};
    t.cby=meSlug||null;S.tasks.push(t);saveCache();renderApp();call({op:'add',id:t.id,t:t.t,desc:t.desc,phone:t.phone,due:t.due,pri:t.pri,assignee:t.assignee,items:t.items,by:meSlug||null});
  }

  function cmp(a,b){
    var da=a.due||'9999',db=b.due||'9999';
    if(da!==db)return da<db?-1:1;
    return (a.pri-b.pri);
  }
  function openT(f){return S.tasks.filter(function(t){return !t.done&&f(t);}).sort(cmp);}
  function doneT(f){return S.tasks.filter(function(t){return t.done&&f(t);}).sort(function(a,b){return (b.doneAt||0)-(a.doneAt||0);}).slice(0,50);}

  // номер контрагента: ссылки «позвонить» и WhatsApp (местный 0555… -> +996555…; 996 — код страны, Кыргызстан)
  function telHref(p){var d=String(p||'').replace(/[^\d+]/g,'');if(!d.replace(/\D/g,''))return '';if(d[0]!=='+'){var n=d.replace(/\D/g,'');if(n.length===10&&n[0]==='0')d='+996'+n.slice(1);else if(n.length===9)d='+996'+n;else if(n.indexOf('996')===0)d='+'+n;else d=n;}return 'tel:'+d;}
  function waHref(p){var n=String(p||'').replace(/\D/g,'');if(!n)return '';if(n.length===10&&n[0]==='0')n='996'+n.slice(1);else if(n.length===9)n='996'+n;return 'https://wa.me/'+n;}
  function phoneBtns(p){var th=telHref(p);if(!th)return '';return '<a class="phb" href="'+th+'">'+I.phone+'Позвонить</a><a class="phb" href="'+waHref(p)+'" target="_blank" rel="noopener">WhatsApp</a>';}
  function phoneLine(t){return t.phone&&telHref(t.phone)?'<div class="ds phl"><a href="'+telHref(t.phone)+'" data-a="noop">'+I.phone+esc(t.phone)+'</a></div>':'';}
  // выбор номера из контактов телефона. Список открывает сам телефон (Contact Picker API: Chrome на Android; в Safari на
  // iPhone его нет, пока не включён флаг). Нет API — подсказка и «Вставить номер» из буфера обмена, молча не пропускаем.
  function cleanTel(v){return String(v||'').replace(/[^\d+()\-\s]/g,'').replace(/\s+/g,' ').trim().slice(0,32);}
  function telDigits(v){return String(v||'').replace(/\D/g,'').length;}
  function telHint(h){var e=document.getElementById('phh');if(e)e.innerHTML=h||'';}
  function telPaste(lead){telHint('<span>'+lead+' Скопируйте номер (в «Контактах» или в переписке), вернитесь сюда и нажмите «Вставить номер».</span><button type="button" class="phb" data-a="pastetel">Вставить номер</button>');}
  function setTel(v){
    var s=U.sheet;if(!s)return;
    var t=cleanTel(v);s.phone=t;
    var i=document.getElementById('sph');if(i)i.value=t;
    var pl=document.getElementById('phl');if(pl)pl.innerHTML=phoneBtns(t);
    telHint('');
  }
  function contactsOk(){return !!(navigator.contacts&&typeof navigator.contacts.select==='function'&&window.ContactsManager);}
  function pickContact(){
    if(!U.sheet)return;
    if(!contactsOk()){telPaste('Список контактов в этом браузере недоступен.');return;}
    var p;
    try{p=navigator.contacts.select(['name','tel'],{multiple:false});}catch(er){telPaste('Не удалось открыть контакты.');return;}
    Promise.resolve(p).then(function(r){
      if(!r||!r.length||!U.sheet)return;
      var c=r[0],nm=String((c.name&&c.name[0])||'').trim(),seen={},tels=[];
      (c.tel||[]).forEach(function(x){var t=cleanTel(x),k=t.replace(/\D/g,'');if(k.length>=6&&!seen[k]){seen[k]=1;tels.push(t);}});
      if(!tels.length){toast(nm?'У контакта «'+nm+'» нет номера телефона':'У этого контакта нет номера телефона');return;}
      if(tels.length===1){setTel(tels[0]);toast(nm?'Номер подставлен: '+nm:'Номер подставлен');return;}
      U.pick={kind:'tel',name:nm,list:tels};renderLayer();
    }).catch(function(er){if(er&&er.name==='AbortError')return;telPaste('Не удалось открыть контакты.');});
  }
  function pasteTel(){
    var cb=navigator.clipboard,fail=function(){toast('Не удалось вставить: разрешите вставку или введите номер вручную');};
    if(!cb||!cb.readText){fail();return;}
    cb.readText().then(function(t){
      var m=String(t||'').match(/\+?\(?\d[\d\s()\-]{4,}\d/);
      if(!m||telDigits(m[0])<6){toast('В буфере обмена нет номера телефона');return;}
      setTel(m[0]);toast('Номер вставлен');
    },fail);
  }
  function growTa(){var ta=document.getElementById('sdc');if(ta){ta.style.height='auto';ta.style.height=Math.min(ta.scrollHeight+2,Math.round((window.visualViewport?window.visualViewport.height:window.innerHeight)*0.45))+'px';}}
  // подсказка при неверном пароле: самые частые причины на телефоне — русская раскладка, большая буква, 0 и O
  function pwHint(v){
    if(/[А-Яа-яЁё]/.test(v))return 'Неверный пароль: в нём русские буквы. Переключите клавиатуру на английский (латиницу) и введите заново.';
    return 'Неверный пароль. Проверьте: английская раскладка, большие и маленькие буквы (F и f — разные), цифра 0 и буква O. Нажмите на глазок, чтобы увидеть, что набрано.';
  }
  function pwField(id,ph,ac){return '<span class="pwf"><input type="password" id="'+id+'" placeholder="'+ph+'" autocomplete="'+(ac||'current-password')+'" autocapitalize="none" autocorrect="off" spellcheck="false" data-lpignore="true" maxlength="80" aria-label="'+ph+'"><button type="button" class="eye" data-a="eye" aria-label="Показать пароль">'+I.eye+'</button></span>';}
  function eyeToggle(b){var inp=b.parentNode.querySelector('input');if(!inp)return;var show=inp.type==='password';inp.type=show?'text':'password';b.innerHTML=show?I.eyeoff:I.eye;b.setAttribute('aria-label',show?'Скрыть пароль':'Показать пароль');inp.focus();}
  function setErr(id,msg){var e=document.getElementById(id);if(e)e.textContent=msg||'';}
  /* аватар исполнителя: буква имени в цветном круге; цвет закреплён за человеком (тот же в статистике) */
  var AVC=['#7A86E6','#3FA68B','#8492A8','#D9687C','#8C84EA','#D4AA6E','#5BA6D4','#E0895A','#9C7BD6'];
  function colOf(id){for(var i=0;i<S.people.length;i++)if(S.people[i].id===id)return AVC[i%AVC.length];return '#9AA0B4';}
  function av(id,big){var n=id?who(id):'';return '<span class="av'+(big?' big':'')+(n?'':' none')+'" style="--c:'+(n?colOf(id):'#A8ADBE')+'">'+(n?esc(n.charAt(0).toUpperCase()):'?')+'</span>';}
  function taskRow(t,o){
    o=o||{};
    var chips='';
    if(t.due)chips+='<span class="dchip '+dueCls(t.due)+'">'+I.cal+'<span>'+esc(dueLabel(t.due))+'</span></span>';
    if(o.noWho){}
    else if(o.whoText){chips+='<span class="dchip wt">'+(t.assignee&&who(t.assignee)?esc(who(t.assignee)):'Без исполнителя')+'</span>';}
    else if(!t.done&&S.people.length){
      chips+='<label class="epill" data-a="noop">'+av(t.assignee)+'<select class="asel" data-a="noop" data-tid="'+t.id+'" aria-label="Исполнитель"><option value="">Не назначен</option>'+
        S.people.map(function(p){return '<option value="'+p.id+'"'+(t.assignee===p.id?' selected':'')+'>'+esc(p.name)+'</option>';}).join('')+'</select></label>';
    }else if(t.assignee&&who(t.assignee)){
      chips+='<span class="epill st">'+av(t.assignee)+'<span>'+esc(who(t.assignee))+'</span></span>';
    }
    var inf=infoParts(t,false).filter(function(x){return !/^(Принята|Завершена) /.test(x);}),act='';
    if(!t.done&&meSlug&&t.assignee===meSlug&&t.status==='new')act='<div class="rbtns"><button class="rb ok" data-a="raccept" type="button">Принять</button><button class="rb no" data-a="rdecl" type="button">Отказать</button></div>';
    return '<div class="task p'+t.pri+(t.done?' done':'')+(t.pri===1?' urg':'')+'" data-id="'+t.id+'">'+
      '<button class="cb p'+t.pri+'" data-a="done" type="button" aria-label="'+(t.done?'Вернуть задачу':'Выполнить задачу')+'">'+I.check+'</button>'+
      '<div class="tb" data-a="open"><div class="tt">'+listTitle(t)+'</div>'+listDesc(t)+
      (chips?'<div class="trow'+(o.whoText?' lft':'')+'">'+chips+'</div>':'')+act+(inf.length?'<div class="ds inf">'+inf.join(' · ')+'</div>':'')+'</div></div>';
  }
  function list(arr,o){return arr.map(function(t){return taskRow(t,o);}).join('');}
  function empty(a,b){return '<div class="empty"><b>'+a+'</b>'+b+'</div>';}
  function doneBlock(arr,o){
    if(!U.showDone)return '';
    return '<div class="sh"><span>Выполнено</span></div>'+(arr.length?list(arr,o):'<div class="ds" style="padding:8px 0">Пока нет выполненных</div>');
  }
  function ttl(title,cnt,sub,tone){
    return '<div class="ph"><div class="pht"><h1>'+title+'</h1>'+(cnt!==null&&cnt!==undefined?'<span class="hcnt'+(tone?' '+tone:'')+'">'+cnt+'</span>':'')+'</div>'+
      '<button class="padd" data-a="add" type="button" aria-label="Добавить задачу"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button></div>'+
      (sub?'<div class="psub">'+sub+'</div>':'');
  }
  function addRow(){return '<button class="addrow" data-a="add" type="button"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg><span>Новая задача</span></button>';}
  function sh2(name,n,tone,lead,col){return '<div class="sh2'+(tone?' '+tone:'')+'"'+(col?' style="--c:'+col+'"':'')+'>'+(lead||'')+'<span>'+name+'</span><i>'+n+'</i></div>';}
  function hdr(back){
    return '<div class="top">'+(back?'<button class="ib backb" data-a="goback" aria-label="Назад">'+I.back+'</button>':'')+
      '<div class="logo">'+LOGO+'<span class="wm">Планёрка</span>'+(S.err==='net'?'<span class="net" id="netst">нет сети'+(Q.length?' · ждут: '+Q.length:'')+'</span>':'')+'</div>'+
      '<div class="tools"><button class="ib'+(U.showDone?' on':'')+'" data-a="showdone" aria-label="Показать выполненные">'+I.done+'</button>'+
      '<button class="ib" data-a="menu" aria-label="Меню">'+I.dots+'</button></div></div>'+pushBanner();
  }

  /* ---------- уведомления на телефон (Web Push) ---------- */
  var PUSH={sup:('serviceWorker' in navigator)&&('PushManager' in window)&&('Notification' in window),state:'',err:'',tried:false};
  var IOS=/iPhone|iPad|iPod/.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
  var STANDALONE=(window.navigator.standalone===true)||(window.matchMedia&&window.matchMedia('(display-mode: standalone)').matches);
  function pushBanner(){
    if(!meSlug||!me())return '';
    if(!PUSH.sup)return (IOS&&!STANDALONE)?'<div class="pbanner info">Чтобы получать уведомления: «Поделиться» → «На экран Домой», затем откройте Планёрку с экрана «Домой».</div>':'';
    if(Notification.permission==='denied')return '<div class="pbanner info">Уведомления запрещены. Разрешите их для Планёрки в настройках телефона.</div>';
    if(Notification.permission==='default')return '<button class="pbanner" data-a="pushon" type="button">Включить уведомления о новых задачах</button>';
    if(PUSH.state==='err')return '<div class="pbanner info">Уведомления не включились: '+esc(PUSH.err)+'</div>';
    return '';
  }
  function urlB64(b){var pd='='.repeat((4-b.length%4)%4),t=(b+pd).replace(/-/g,'+').replace(/_/g,'/'),r=atob(t),a=new Uint8Array(r.length);for(var i=0;i<r.length;i++)a[i]=r.charCodeAt(i);return a;}
  function apiPost(body){return fetch(API,{method:'POST',headers:{'Content-Type':'application/json','X-Key':key||''},body:JSON.stringify(body)});}
  function pushEnsure(){
    if(!PUSH.sup||!meSlug||!key||Notification.permission!=='granted')return Promise.resolve();
    return navigator.serviceWorker.register('/planerka/sw.js',{scope:'/planerka/'}).then(function(){return navigator.serviceWorker.ready;}).then(function(reg){
      return apiPost({op:'push.key'}).then(function(r){return r.json();}).then(function(j){
        if(!j.key)throw new Error('на сервере нет ключей уведомлений');
        return reg.pushManager.getSubscription().then(function(sub){return sub||reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:urlB64(j.key)});});
      }).then(function(sub){return apiPost({op:'push.sub',person:meSlug,sub:sub.toJSON()});})
      .then(function(r){if(!r.ok)throw new Error('сервер не принял подписку ('+r.status+')');PUSH.state='on';});
    }).catch(function(e){PUSH.state='err';PUSH.err=(e&&e.message)||'ошибка';}).then(function(){render();});
  }
  function pushAuto(){if(PUSH.tried||!PUSH.sup||!meSlug||!me())return;PUSH.tried=true;if(Notification.permission==='granted')pushEnsure();}
  // нажатие на уведомление открывает приложение сразу на нужной задаче
  var openTaskId=null;
  try{var mt=location.search.match(/[?&]task=([a-z0-9]{4,40})/);if(mt)openTaskId=mt[1];}catch(e){}
  var openRetry=false;
  function hilite(id){
    var r=document.querySelector('.task[data-id="'+id+'"]');
    if(!r)return;
    try{r.scrollIntoView({block:'center'});}catch(e){}
    r.classList.add('hl');setTimeout(function(){r.classList.remove('hl');},2600);
  }
  // открыть задачу из уведомления: нужный раздел, подсветка строки, шторка с подробностями (или окно ответа)
  function maybeOpenTask(){
    if(!openTaskId||!S.loaded)return;
    var t=find(openTaskId);
    if(!t&&!openRetry){openRetry=true;S.rev=-1;refresh();setTimeout(maybeOpenTask,1500);return;}
    openTaskId=null;openRetry=false;
    try{history.replaceState(null,'',location.pathname+location.search.replace(/([?&])task=[a-z0-9]+&?/,'$1').replace(/[?&]$/,''));}catch(e){}
    if(!t){toast('Задача не найдена: возможно, её удалили');return;}
    if(U.greet){U.greet=null;try{localStorage.removeItem(KG+meSlug);}catch(e){}}
    U.sheet=null;U.pick=null;U.menu=false;U.resp=null;
    if(!t.assignee){U.tab='inbox';U.person=null;}
    else if(meSlug&&t.assignee===meSlug){U.tab='me';U.person=null;}
    else{U.tab='people';U.person=t.assignee;}
    if(t.done)U.showDone=true;
    render();hilite(t.id);
    if(!t.done&&t.assignee===meSlug&&t.status==='new'){U.resp={id:t.id,step:'ask'};renderLayer();return;}
    openSheet(t);
  }
  // номер задачи, оставленный сервис-воркером при нажатии на уведомление
  function checkNav(){
    try{
      if(!('caches' in window))return;
      caches.open('planerka-nav').then(function(c){
        return c.match('/planerka/__open-task').then(function(r){
          if(!r)return;
          return r.json().then(function(j){
            c.delete('/planerka/__open-task');
            if(j&&j.id&&Date.now()-(j.at||0)<15*60*1000){openTaskId=j.id;openRetry=false;S.rev=-1;refresh();maybeOpenTask();}
          });
        });
      }).catch(function(){});
    }catch(e){}
  }
  function respond(id,act,reason){
    var t=find(id);if(!t)return;
    if(act==='accept'){t.status='accepted';t.acceptedAt=Date.now();}
    else{if(!t.declines)t.declines=[];t.declines.push({by:meSlug,reason:reason,at:Date.now()});t.assignee=null;t.status=null;t.assignedAt=null;t.acceptedAt=null;}
    saveCache();U.resp=null;render();call({op:'resp',id:id,act:act,reason:reason||'',by:meSlug});
    toast(act==='accept'?'Задача принята':'Отказ отправлен');
  }
  var respSkip={};
  function maybeResp(){
    if(!meSlug||!me()||!S.loaded||U.resp||U.greet||U.sheet)return;
    var t=null;S.tasks.forEach(function(x){if(!t&&!x.done&&x.assignee===meSlug&&x.status==='new'&&!respSkip[x.id])t=x;});
    if(t){U.resp={id:t.id,step:'ask'};renderLayer();}
  }
  function respHtml(){
    var r=U.resp,t=find(r.id);
    if(!t){U.resp=null;return '';}
    var h='<div class="back" style="z-index:24" data-a="rlater"></div><div class="greet" role="dialog" aria-modal="true" aria-label="Новая задача">';
    if(r.step==='reason'){
      return h+'<h2>Причина отказа</h2><div class="gs">«'+esc(t.t)+'»</div><textarea id="rr" placeholder="Напишите, почему не можете взять задачу" maxlength="300" aria-label="Причина отказа"></textarea>'+
        '<button class="gok" type="button" data-a="rsend" style="background:var(--od);color:#fff">Отправить отказ</button><button class="gsec" type="button" data-a="rback">Назад</button></div>';
    }
    return h+'<h2>Новая задача</h2><div class="gs">Вам назначена задача. Примите её или откажитесь.</div>'+
      '<div class="gi"><span style="font-weight:600">'+esc(t.t)+'</span>'+(t.due?'<span class="gd '+dueCls(t.due)+'">'+esc(dueLabel(t.due))+'</span>':'')+'</div>'+
      (t.desc?'<div class="ds dsc" style="padding:6px 0">'+linkify(t.desc)+'</div>':'')+(t.phone&&telHref(t.phone)?'<div class="phbs" style="margin:6px 0">'+phoneBtns(t.phone)+'</div>':'')+
      '<div class="rbtns" style="margin-top:14px"><button class="rb ok" type="button" data-a="raccept2">Принять</button><button class="rb no" type="button" data-a="rdecl2">Отказать</button></div>'+
      '<button class="gsec" type="button" data-a="rlater">Позже</button></div>';
  }
  function afterLoad(){maybeGreet();maybeOpenTask();maybeResp();pushAuto();}
  try{
    navigator.serviceWorker.addEventListener('message',function(e){
      if(e.data&&e.data.type==='open-task'&&e.data.id){
        try{caches.open('planerka-nav').then(function(c){c.delete('/planerka/__open-task');});}catch(x){}
        openTaskId=e.data.id;openRetry=false;S.rev=-1;refresh();maybeOpenTask();
      }
    });
    if(navigator.serviceWorker.startMessages)navigator.serviceWorker.startMessages();
    // держим сервис-воркер свежим: новая логика нажатия на уведомление начинает работать сразу
    if(key)navigator.serviceWorker.register('/planerka/sw.js',{scope:'/planerka/'}).then(function(r){try{r.update();}catch(x){}}).catch(function(){});
  }catch(e){}
  window.addEventListener('focus',checkNav);
  window.addEventListener('pageshow',checkNav);
  function injectManifest(){try{var old=document.querySelector('link[rel=manifest]');if(old)old.remove();if(meSlug&&key){var lk=document.createElement('link');lk.rel='manifest';lk.href='/planerka/manifest.json?p='+meSlug+'&k='+key;document.head.appendChild(lk);}}catch(e){}}
  injectManifest();

  /* представления */
  function viewInbox(){
    var a=openT(function(t){return !t.assignee;});
    return hdr()+ttl('Входящие',a.length,a.length?'Без исполнителя — выберите, кому поручить':'Новые задачи без исполнителя появятся здесь')+
      (a.length?list(a)+addRow():empty('Входящие пусты','Нажмите «+», чтобы добавить задачу.'))+
      doneBlock(doneT(function(t){return !t.assignee;}));
  }
  function personTasks(id,back,title){
    var a=openT(function(t){return t.assignee===id;}),w=a.filter(function(t){return t.status==='new';}).length;
    var sub=a.length+' '+plu(a.length,'задача','задачи','задач')+' в работе'+(w?' · '+w+' '+plu(w,'ждёт','ждут','ждут')+' ответа':'');
    return hdr(back)+ttl(title||esc(who(id)),a.length,sub)+(a.length?list(a,{noWho:true})+addRow():empty('Задач нет','Назначенные задачи появятся здесь.'))+
      doneBlock(doneT(function(t){return t.assignee===id;}),{noWho:true});
  }
  function viewMe(){
    var m=me();
    if(!m)return hdr()+ttl('Мои задачи',null,'')+empty(S.loaded?'Исполнитель не найден':'Загрузка…',S.loaded?'Такого имени нет в списке. Откройте «Команда» внизу.':'');
    return personTasks(m.id,false);
  }
  function viewPeople(){
    if(U.person)return personTasks(U.person,true);
    var busy=0,tot=0,inbox=S.tasks.filter(function(t){return !t.done&&!t.assignee;}).length;
    var rows=S.people.map(function(p){
      var n=S.tasks.filter(function(t){return !t.done&&t.assignee===p.id;}).length;if(n)busy++;tot+=n;
      return '<button class="prow" data-a="openperson" data-p="'+p.id+'" type="button"><span class="avw">'+av(p.id,true)+pdot(p.id)+'</span><span class="pn">'+esc(p.name)+(p.id===meSlug?'<span class="me">это вы</span>':'')+'</span>'+
        '<span class="pcnt'+(n?'':' z')+'">'+n+'</span>'+I.chev+'</button>';
    }).join('');
    var h=hdr()+ttl('Исполнители',null,S.people.length?S.people.length+' '+plu(S.people.length,'участник','участника','участников')+' · '+busy+' с задачами':'');
    if(!S.people.length)return h+empty(S.loaded?'Исполнителей нет':'Загрузка…','');
    h+='<div class="plist">'+rows+'</div>';
    h+='<div class="tcard"><div class="tct">Задачи команды</div>'+
      '<div class="tci"><span class="tic">'+SXI.doc+'</span><span>В работе</span><b>'+tot+'</b></div>'+
      '<div class="tci"><span class="tic">'+SXI.user+'</span><span>Без исполнителя</span><b>'+inbox+'</b></div>'+
      '<button class="tclink" data-a="hist" type="button"><span>История завершённых</span>'+I.chev+'</button>'+
      '<button class="tclink" data-a="stats" type="button"><span>Открыть статистику</span>'+I.chev+'</button></div>';
    return h;
  }
  function viewUrgent(){
    var a1=openT(function(t){return t.pri===1;}),a2=openT(function(t){return t.pri===2;});
    var sub=[];if(a1.length)sub.push(a1.length+' '+plu(a1.length,'очень срочная','очень срочные','очень срочных'));if(a2.length)sub.push(a2.length+' '+plu(a2.length,'срочная','срочные','срочных'));
    var h=hdr()+ttl('Срочное',a1.length+a2.length,sub.join(' · ')||'Срочных задач нет','red');
    if(!a1.length&&!a2.length)h+=empty('Срочного нет','Сюда попадают задачи «'+PN[1]+'» и «'+PN[2]+'».');
    if(a1.length)h+=sh2(PN[1],a1.length,'red')+list(a1,{whoText:true});
    if(a2.length)h+=sh2(PN[2],a2.length,'amber')+list(a2,{whoText:true});
    if(a1.length||a2.length)h+=addRow();
    return h+doneBlock(doneT(function(t){return t.pri<=2;}),{whoText:true});
  }
  function viewAll(){
    var all=openT(function(){return true;}),inb=all.filter(function(t){return !t.assignee;}).length;
    var h=hdr()+ttl('Все задачи',all.length,all.length?all.length+' в работе · '+inb+' без исполнителя':''),any=false;
    var groups=[{n:'Входящие',id:null,f:function(t){return !t.assignee;},o:{}}].concat(S.people.map(function(p){return {n:p.name,id:p.id,f:function(t){return t.assignee===p.id;},o:{noWho:true}};}));
    groups.forEach(function(g){
      var a=openT(g.f);if(!a.length)return;any=true;
      h+=sh2(esc(g.n),a.length,'who',g.id?'<span class="avw">'+av(g.id)+pdot(g.id)+'</span>':'<span class="av none" style="--c:#A8ADBE">'+I.inbox+'</span>',g.id?colOf(g.id):'#9AA0B4')+list(a,g.o);
    });
    if(!any)h+=empty('Задач нет','Нажмите «+», чтобы добавить.');else h+=addRow();
    return h+doneBlock(doneT(function(){return true;}));
  }
  function gate(){
    var msg=S.err==='auth'?['Нужно войти заново','Введите пароль Планёрки.']:['Вход в Планёрку','Введите пароль Планёрки — его сообщает руководитель.'];
    return '<div class="gate">'+LOGO.replace('width="26" height="26"','width="72" height="72"')+'<b>'+msg[0]+'</b>'+msg[1]+
      '<div class="gk">'+pwField('gk','Пароль')+'<button type="button" class="gkb" data-a="gkok">Войти</button></div><div class="perr" id="gkerr" role="alert" style="text-align:center"></div></div>';
  }

  // вкладки нижней панели по порядку — по ним же идёт свайп влево/вправо
  function navTabs(){var tabs=[['inbox','Входящие',I.inbox],['people','Команда',I.people],['urgent','Срочное',I.bolt],['all','Все',I.list]];if(meSlug)tabs.unshift(['me','Мои',I.person]);return tabs;}
  function navHtml(){
    var n=S.tasks.filter(function(t){return !t.done&&!t.assignee;}).length;
    return navTabs().map(function(x){
      return '<button data-a="tab" data-t="'+x[0]+'" class="'+(U.tab===x[0]?'on':'')+'" type="button">'+x[2]+'<span>'+x[1]+'</span>'+(x[0]==='inbox'&&n?'<i class="bdg">'+n+'</i>':'')+'</button>';
    }).join('');
  }
  function renderApp(){
    applyTheme();
    if(!key||S.err==='auth'){$('app').innerHTML=gate();$('nav').hidden=true;$('fab').hidden=true;return;}
    $('nav').hidden=false;$('fab').hidden=!!U.sheet||!!U.greet||U.tab==='stats'||U.tab==='hist';
    $('app').innerHTML=viewHtml();
    $('nav').innerHTML=navHtml();
  }
  function viewHtml(){var t=U.tab;return t==='hist'?viewHist():t==='stats'?viewStats():t==='me'&&meSlug?viewMe():t==='people'?viewPeople():t==='urgent'?viewUrgent():t==='all'?viewAll():viewInbox();}
  // вид другой вкладки, не переключаясь на неё (нижняя страница при свайпе)
  function viewOf(tab){var keep=U.tab,kp=U.person;U.tab=tab;U.person=null;try{return viewHtml();}finally{U.tab=keep;U.person=kp;}}

  /* ---------- статистика: кто сколько выполнил и отклонил (квартал / год) ---------- */
  var QN=['I квартал','II квартал','III квартал','IV квартал'],QM=['янв–мар','апр–июн','июл–сен','окт–дек'];
  function statsLoad(){
    S.statsErr=null;
    apiPost({op:'stats'}).then(function(r){return r.json();}).then(function(j){
      S.stats=Array.isArray(j.events)?j.events:[];S.statsFrom=j.from||null;if(Array.isArray(j.people))S.people=j.people;if(U.tab==='stats')renderApp();
    }).catch(function(){S.statsErr='Нет связи с сервером';if(U.tab==='stats')renderApp();});
  }
  function statsAgg(){
    var y=U.st.year,rows={},order=[];
    S.people.forEach(function(p){rows[p.id]={id:p.id,name:p.name,d:[0,0,0,0],x:[0,0,0,0]};order.push(p.id);});
    (S.stats||[]).forEach(function(e){
      var dt=new Date(e.at);if(dt.getFullYear()!==y)return;
      var qi=Math.floor(dt.getMonth()/3);
      if(!rows[e.p]){rows[e.p]={id:e.p,name:who(e.p)||e.p,d:[0,0,0,0],x:[0,0,0,0]};order.push(e.p);}
      rows[e.p][e.t==='d'?'d':'x'][qi]++;
    });
    return order.map(function(id){return rows[id];});
  }
  function sum(a){return a[0]+a[1]+a[2]+a[3];}
  /* Статистика: карточки, кольцевая диаграмма распределения, загрузка команды, итоги периода, результаты участников */
  var SXC=['var(--accent)','#6FB5A6','#A296DC','#D6AD6B','#D697B3','#86B893','#7FA8D4','#D58E8E','#9CA1B5'];   // приглушённая пастель
  function plu(n,a,b,c){var m=n%100,k=n%10;return (m>=11&&m<=14)?c:k===1?a:(k>=2&&k<=4)?b:c;}
  var SXI={
    doc:'<svg viewBox="0 0 24 24"><path d="M7 3.5h7l4 4V20a.5.5 0 01-.5.5h-10A.5.5 0 017 20z"/><path d="M14 3.5V8h4M9.5 12h5M9.5 15.5h5"/></svg>',
    clock:'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
    user:'<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.8"/><path d="M4.5 20.5c.8-4 3.7-6 7.5-6s6.7 2 7.5 6"/></svg>',
    team:'<svg viewBox="0 0 24 24"><circle cx="9" cy="8.5" r="3.2"/><circle cx="17" cy="9.5" r="2.5"/><path d="M3 19c.6-3.3 3-5 6-5s5.4 1.7 6 5M15.5 14.3c2.6-.4 4.8 1 5.5 4.2"/></svg>',
    ok:'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M8.2 12.3l2.5 2.5 5-5.2"/></svg>',
    no:'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M9 9l6 6M15 9l-6 6"/></svg>'
  };
  function sxDonut(parts,total){
    var r=52,c=2*Math.PI*r,off=0,h='<svg class="sx-dn" viewBox="0 0 140 140" role="img" aria-label="Распределение задач"><circle cx="70" cy="70" r="'+r+'" fill="none" stroke="var(--line)" stroke-width="22"/>';
    parts.forEach(function(p){var len=c*p.n/total;h+='<circle cx="70" cy="70" r="'+r+'" fill="none" stroke="'+p.col+'" stroke-width="22" stroke-dasharray="'+len.toFixed(2)+' '+(c-len).toFixed(2)+'" stroke-dashoffset="'+(-off).toFixed(2)+'" transform="rotate(-90 70 70)"/>';off+=len;});
    return h+'<text x="70" y="70" text-anchor="middle" class="sx-dnn">'+total+'</text><text x="70" y="92" text-anchor="middle" class="sx-dnl">'+plu(total,'задача','задачи','задач')+'</text></svg>';
  }
  // сейчас в работе: открытые задачи с исполнителем (не зависят от выбранного периода)
  function nowWork(){
    var day=today(),by={},tot=0,wait=0;
    S.tasks.forEach(function(t){
      if(t.done||!t.assignee)return;
      var r=by[t.assignee]||(by[t.assignee]={n:0,w:0,od:0});
      r.n++;tot++;
      if(t.status==='new'){r.w++;wait++;}
      if(t.due&&t.due<day)r.od++;
    });
    var inbox=S.tasks.filter(function(t){return !t.done&&!t.assignee;}).length;
    var list=S.people.map(function(p){return {id:p.id,name:p.name,r:by[p.id]||{n:0,w:0,od:0}};});
    Object.keys(by).forEach(function(id){if(!list.some(function(x){return x.id===id;}))list.push({id:id,name:who(id)||id,r:by[id]});});
    var busy=list.filter(function(x){return x.r.n;}).length;
    var h='<div class="sx-k3">'+
      '<div class="sx-k main"><span class="sx-ki">'+SXI.doc+'</span><b>'+tot+'</b><span class="sx-kl">В работе</span></div>'+
      '<div class="sx-k"><span class="sx-ki wt">'+SXI.clock+'</span><b>'+wait+'</b><span class="sx-kl">Ждут ответа</span></div>'+
      '<div class="sx-k"><span class="sx-ki vi">'+SXI.team+'</span><b>'+inbox+'</b><span class="sx-kl">Не назначены</span></div></div>';
    h+='<div class="sx-card"><div class="sx-ct">Загрузка команды</div><div class="sx-cs">'+busy+' из '+list.length+' '+plu(list.length,'участника','участников','участников')+' с задачами</div>'+
      '<div class="lt"><div class="lth"><span>Участник</span><span>Задачи</span><span>Доля</span></div>';
    list.slice().sort(function(a,b){return b.r.n-a.r.n;}).forEach(function(x){
      var sub=[];if(x.r.w)sub.push('<span class="stw">ждёт ответа: '+x.r.w+'</span>');if(x.r.od)sub.push('<span class="std">просрочено: '+x.r.od+'</span>');
      var pct=tot?Math.round(x.r.n/tot*100):0;
      h+='<button type="button" class="ltr'+(x.r.n?' on':'')+'" data-a="stperson" data-p="'+x.id+'" style="--c:'+colOf(x.id)+'"><span class="avw">'+av(x.id)+pdot(x.id)+'</span>'+
        '<span class="ltn">'+esc(x.name)+(sub.length?'<span class="lts">'+sub.join(' · ')+'</span>':'')+'</span><b>'+x.r.n+'</b><em>'+pct+'%</em></button>';
    });
    h+='<div class="ltt"><span>Всего</span><b>'+tot+'</b><em>'+(tot?'100':'0')+'%</em></div></div></div>';
    return h;
  }
  function viewStats(){
    var h=hdr(true)+'<h1>Статистика</h1><div class="sx-sub">Задачи и результаты команды</div>'+nowWork();
    var y=U.st.year,mode=U.st.mode,q=U.st.q,nowY=new Date().getFullYear();
    var fd=S.statsFrom?new Date(S.statsFrom):null,minY=fd?fd.getFullYear():0;
    h+='<div class="sx-h2" style="margin-top:22px">Итоги за период</div>'+
      '<div class="stc"><div class="seg" role="group" aria-label="Период">'+
      '<button type="button" data-a="stmode" data-m="q" class="'+(mode==='q'?'on':'')+'" aria-pressed="'+(mode==='q')+'">Квартал</button>'+
      '<button type="button" data-a="stmode" data-m="y" class="'+(mode==='y'?'on':'')+'" aria-pressed="'+(mode==='y')+'">Год</button></div>'+
      '<div class="yr"><button type="button" data-a="styr" data-d="-1" aria-label="Предыдущий год"'+(y<=minY?' disabled':'')+'>‹</button><b>'+y+'</b>'+
      '<button type="button" data-a="styr" data-d="1" aria-label="Следующий год"'+(y>=nowY?' disabled':'')+'>›</button></div></div>';
    if(mode==='q'){
      h+='<div class="qs">'+QN.map(function(n,i){return '<button type="button" data-a="stq" data-q="'+i+'" class="'+(i===q?'on':'')+'">'+(i+1)+' кв.<span>'+QM[i]+'</span></button>';}).join('')+'</div>';
    }
    var foot=fd?'<div class="sx-foot">Учёт с '+pad(fd.getDate())+'.'+pad(fd.getMonth()+1)+'.'+fd.getFullYear()+', '+pad(fd.getHours())+':'+pad(fd.getMinutes())+'<br>Удалённые задачи не учитываются.</div>':'';
    if(S.statsErr)return h+empty(S.statsErr,'Попробуйте открыть статистику ещё раз.');
    if(!S.stats)return h+empty('Загрузка…','');
    var rows=statsAgg(),get=function(r,k){return mode==='q'?r[k][q]:sum(r[k]);};
    var td=0,tx=0;rows.forEach(function(r){td+=get(r,'d');tx+=get(r,'x');});
    var label=mode==='q'?(QN[q]+' '+y):(y+' год');
    h+='<div class="sx-g2 sx-res"><div class="sx-card ok"><span class="sx-ri">'+SXI.ok+'</span><span><b>'+td+'</b><span class="sx-rl">Выполнено</span><span class="sx-rp">'+label+'</span></span></div>'+
      '<div class="sx-card no"><span class="sx-ri">'+SXI.no+'</span><span><b>'+tx+'</b><span class="sx-rl">'+plu(tx,'Отказ','Отказа','Отказов')+'</span><span class="sx-rp">'+label+'</span></span></div></div>';
    if(!td&&!tx)return h+empty('За этот период данных нет','Здесь появятся выполненные задачи и отказы.')+foot;
    var act=rows.filter(function(r){return get(r,'d')||get(r,'x');}).sort(function(a,b){return get(b,'d')-get(a,'d')||get(a,'x')-get(b,'x');});
    var mx=1;act.forEach(function(r){mx=Math.max(mx,get(r,'d'));});
    var idx={};S.people.forEach(function(p,i){idx[p.id]=i;});
    h+='<div class="sx-card"><div class="sx-ct">Результаты участников</div>';
    act.forEach(function(r){
      var d=get(r,'d'),x=get(r,'x'),col=colOf(r.id);
      h+='<div class="sx-pr">'+av(r.id,true)+
        '<span class="sx-pn"><b>'+esc(r.name)+'</b><span>'+d+' '+plu(d,'выполненная задача','выполненные задачи','выполненных задач')+'</span></span>'+
        '<span class="sx-pb"><span class="sx-bar"><i style="width:'+(d?Math.max(6,Math.round(d/mx*100)):0)+'%;background:var(--td)"></i></span><b>'+d+'</b></span>'+
        '<span class="sx-px'+(x?' has':'')+'">'+x+' '+plu(x,'отказ','отказа','отказов')+'</span></div>';
    });
    var idle=rows.filter(function(r){return !get(r,'d')&&!get(r,'x');}).map(function(r){return esc(r.name);});
    if(idle.length)h+='<div class="sx-idle"><span class="sx-fi">'+SXI.team+'</span><span><b>Без выполненных задач и отказов:</b><span>'+idle.join(', ')+'.</span></span></div>';
    h+='</div>';
    if(mode==='y'){
      ['d','x'].forEach(function(k){
        h+='<div class="sh"><span'+(k==='x'?' class="std"':'')+'>'+(k==='d'?'Выполнено':'Отказы')+' по кварталам</span></div><div class="sttw"><table class="stt"><thead><tr><th>Исполнитель</th><th>1 кв.</th><th>2 кв.</th><th>3 кв.</th><th>4 кв.</th><th>Год</th></tr></thead><tbody>';
        var tq=[0,0,0,0];
        rows.slice().sort(function(a,b){return sum(b[k])-sum(a[k]);}).forEach(function(r){
          for(var i=0;i<4;i++)tq[i]+=r[k][i];
          h+='<tr><td>'+esc(r.name)+'</td>'+r[k].map(function(v){return '<td'+(v?'':' class="z"')+'>'+v+'</td>';}).join('')+'<td class="tot">'+sum(r[k])+'</td></tr>';
        });
        h+='<tr class="all"><td>Всего</td>'+tq.map(function(v){return '<td>'+v+'</td>';}).join('')+'<td class="tot">'+sum(tq)+'</td></tr></tbody></table></div>';
      });
    }
    return h+foot;
  }
  /* История завершённых задач: видят все; удалить (из истории и статистики) может только постановщик (owner) */
  var H={list:null,err:''};
  function histLoad(){
    H.err='';
    apiPost({op:'hist'}).then(function(r){if(!r.ok)throw new Error('ответ '+r.status);return r.json();}).then(function(j){H.list=j.tasks||[];if(U.tab==='hist')renderApp();})
      .catch(function(e){H.err=(!navigator.onLine||(e&&e.name==='TypeError'))?'Нет интернета — история загрузится, когда связь появится.':((e&&e.message)||'Ошибка');if(U.tab==='hist')renderApp();});
  }
  function isOwner(){return !!meSlug&&meSlug===(S.owner||'');}
  function viewHist(){
    var h=hdr(true)+'<h1>Завершённые</h1><div class="psub">'+(isOwner()?'«Вернуть» — задача снова в работе. Удалённая пропадает из истории и из статистики':'Выполненные задачи команды. «Вернуть» — задача снова в работе')+'</div>';
    if(H.err)return h+empty('Не удалось загрузить',esc(H.err));
    if(!H.list)return h+empty('Загрузка…','');
    if(!H.list.length)return h+empty('Завершённых задач нет','Здесь появятся выполненные задачи.');
    var cur='',own=isOwner();
    H.list.forEach(function(t){
      var d=t.doneAt?new Date(t.doneAt):null,mk=d?(MONN[d.getMonth()]+' '+d.getFullYear()):'Без даты';
      if(mk!==cur){if(cur)h+='</div>';cur=mk;h+='<div class="sh2 grey"><span>'+mk+'</span></div><div class="plist hlist">';}
      h+='<div class="hrow" data-hid="'+t.id+'">'+av(t.assignee)+'<div class="hb"><div class="htt">'+esc(noLinks(t.t)||'Ссылка')+'</div>'+
        '<div class="ds">'+(t.assignee&&who(t.assignee)?esc(who(t.assignee))+' · ':'')+(d?'завершена '+fmtDT(t.doneAt):'')+'</div></div>'+
        '<button class="hret" data-a="histret" data-id="'+t.id+'" type="button" aria-label="Вернуть задачу в работу">'+I.undo+'<span>Вернуть</span></button>'+
        (own?'<button class="ib" data-a="histdel" data-id="'+t.id+'" type="button" aria-label="Удалить из истории и статистики">'+I.trash+'</button>':'')+'</div>';
    });
    return h+(cur?'</div>':'');
  }
  function render(){renderApp();renderLayer();}
  // прокручивается лента #app, а не документ
  function toTop(){var e=document.getElementById('app');if(e)e.scrollTop=0;}

  /* приветствие: один раз за календарные сутки, при заходе по личной ссылке, когда задачи уже загружены */
  function greetWord(){
    var h=new Date().getHours();
    return h>=6&&h<12?'Доброе утро':h>=12&&h<16?'Добрый день':'Добрый вечер';
  }
  function maybeGreet(){
    var m=me();
    if(!meSlug||!m||!S.loaded||U.greet||U.sheet||openTaskId)return;
    var day=today(),seen=null;
    try{seen=localStorage.getItem(KG+meSlug);}catch(e){}
    if(seen===day)return;
    try{localStorage.setItem(KG+meSlug,day);}catch(e){}
    var mine=openT(function(t){return t.assignee===m.id;});
    U.greet={name:m.name,word:greetWord(),od:mine.filter(function(t){return t.due&&t.due<day;}),td:mine.filter(function(t){return t.due===day;}),other:mine.filter(function(t){return !t.due||t.due>day;}).length};
    renderLayer();$('fab').hidden=true;
  }
  function greetHtml(){
    var g=U.greet,n=0,LIM=12;
    function rows(arr,cls){
      var out='';
      arr.forEach(function(t){if(n>=LIM)return;n++;out+='<div class="gi'+(t.pri===1?' urg':'')+'"><span>'+listTitle(t)+'</span>'+(t.due?'<span class="gd '+dueCls(t.due)+'">'+esc(dueLabel(t.due))+'</span>':'')+'</div>';});
      return out;
    }
    var h='<div class="back" style="z-index:24" data-a="gok"></div><div class="greet" role="dialog" aria-modal="true" aria-label="Приветствие">'+
      '<h2>'+g.word+', '+esc(g.name)+'!</h2>';
    var total=g.od.length+g.td.length;
    h+='<div class="gs">'+(total?'Дела на сегодня':'Дела на сегодня')+'</div>';
    if(g.od.length)h+='<div class="gh od">Просрочено · '+g.od.length+'</div>'+rows(g.od);
    if(g.td.length)h+='<div class="gh td">Сегодня · '+g.td.length+'</div>'+rows(g.td);
    if(!total)h+='<div class="gnone">На сегодня задач нет.'+(g.other?' Всего открытых задач: '+g.other+'.':'')+'</div>';
    else if(total>LIM)h+='<div class="gnone">и ещё '+(total-LIM)+'</div>';
    return h+'<button class="gok" type="button" data-a="gok">ОК</button></div>';
  }

  /* окна: меню, шторка задачи, выбор срока и приоритета */
  function renderLayer(){
    var h='';
    if(U.menu){
      var at=S.at?new Date(S.at):null;
      h+='<div class="back" style="background:none" data-a="mclose"></div><div class="menu">'+
        '<button class="popt" data-a="stats" type="button">'+I.chart+'<span>Статистика</span></button>'+
        '<button class="popt" data-a="hist" type="button">'+I.done+'<span>Завершённые задачи</span></button>'+
        '<button class="popt" data-a="theme" type="button">'+(isDark()?I.moon:I.sun)+'<span>Тёмная тема</span><span class="sw'+(isDark()?' on':'')+'" style="margin-left:auto"></span></button>'+
        '<button class="popt" data-a="showdone" type="button">'+I.done+'<span>Выполненные</span><span class="sw'+(U.showDone?' on':'')+'" style="margin-left:auto"></span></button>'+
        (!meFromPath?'<div class="popt" style="cursor:default;color:var(--mute);font-size:13px;min-height:30px"><span>Кто вы?</span></div>'+S.people.map(function(pp){return '<button class="popt'+(pp.id===meSlug?' sel':'')+'" data-a="iam" data-p="'+pp.id+'" type="button">'+I.person+'<span>'+esc(pp.name)+(pp.id===meSlug?' (это вы)':'')+'</span></button>';}).join(''):'')+
        (meSlug&&me()?'<div class="popt" style="cursor:default;color:var(--mute);font-size:13px;min-height:30px"><span>Уведомления: '+(!PUSH.sup?(IOS&&!STANDALONE?'нужно открыть с экрана «Домой»':'не поддерживаются'):Notification.permission==='granted'?(PUSH.state==='err'?'ошибка — '+esc(PUSH.err):'включены'):Notification.permission==='denied'?'запрещены в настройках':'выключены')+'</span></div>':'')+
        (meSlug&&PUSH.sup&&Notification.permission==='granted'?'<button class="popt" data-a="pushtest" type="button">'+I.bolt+'<span>Проверить уведомление</span></button>':'')+
        '<div class="popt" style="cursor:default;color:var(--mute);font-size:13px;min-height:36px"><span>'+(at?'Обновлено '+pad(at.getHours())+':'+pad(at.getMinutes()):'Ещё не обновлялось')+'</span></div></div>';
    }
    if(U.sheet)h+=sheetHtml();
    if(U.pick)h+=pickHtml();
    if(U.greet)h+=greetHtml();
    if(U.resp)h+=respHtml();
    $('layer').innerHTML=h;
    $('fab').hidden=!!U.sheet||!!U.greet||!!U.resp||!key||S.err==='auth'||U.tab==='stats'||U.tab==='hist';
    if(U.sheet)refreshChips();
  }
  /* пункты задачи («что нужно сделать»): видны только в открытой задаче; отмеченные зачёркиваются и уходят вниз */
  var CLMAX=50;
  function clClean(a){var r=[];(Array.isArray(a)?a:[]).forEach(function(x){if(x&&typeof x.t==='string'&&x.t.trim()&&r.length<CLMAX)r.push({t:x.t.trim().slice(0,200),d:!!x.d});});return r;}
  function clSort(a){return a.filter(function(x){return !x.d;}).concat(a.filter(function(x){return x.d;}));}
  function clUndone(a){var n=0;a.forEach(function(x){if(!x.d)n++;});return n;}
  var CLX='<svg viewBox="0 0 24 24"><path d="M7 7l10 10M17 7L7 17"/></svg>';
  function clRows(a){return a.map(function(x,i){
    return '<div class="cli'+(x.d?' dn':'')+'"><button type="button" class="clc" data-a="cltog" data-i="'+i+'" aria-label="'+(x.d?'Вернуть пункт в работу':'Отметить пункт выполненным')+'" aria-pressed="'+(x.d?'true':'false')+'">'+I.check+'</button>'+
      '<textarea class="clt" rows="1" maxlength="200" autocapitalize="sentences" data-i="'+i+'" aria-label="Пункт">'+esc(x.t)+'</textarea>'+
      '<button type="button" class="ib clx" data-a="cldel" data-i="'+i+'" aria-label="Удалить пункт">'+CLX+'</button></div>';}).join('');}
  function clHead(a){var d=0;a.forEach(function(x){if(x.d)d++;});return a.length?'Пункты · выполнено '+d+' из '+a.length:'';}
  function clHtml(s){
    return '<div class="cl"><div class="clh" id="clh">'+esc(clHead(s.items))+'</div><div id="cli">'+clRows(s.items)+'</div>'+
      '<div class="cla"><span class="clp" aria-hidden="true">+</span><input type="text" id="cln" autocapitalize="sentences" enterkeyhint="enter" placeholder="Добавить пункт" maxlength="200" autocomplete="off" aria-label="Новый пункт">'+
      '<button type="button" class="ib" data-a="cladd" aria-label="Добавить пункт"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></button></div></div>';
  }
  function clFit(el){el.style.height='auto';el.style.height=(el.scrollHeight+1)+'px';}
  function clFitAll(){var l=document.querySelectorAll('.clt');for(var i=0;i<l.length;i++)clFit(l[i]);}
  function clRender(){
    var s=U.sheet;if(!s)return;
    s.items=s.items.filter(function(x){return x.t.trim();});
    var l=$('cli');if(l){l.innerHTML=clRows(s.items);clFitAll();}
    var h=$('clh');if(h)h.textContent=clHead(s.items);
    lnkRefresh();
  }
  function clAdd(){
    var inp=$('cln'),s=U.sheet;if(!inp||!s)return false;
    var v=inp.value.trim();if(!v||s.items.length>=CLMAX)return false;
    v=v.charAt(0).toUpperCase()+v.slice(1);
    s.items.splice(clUndone(s.items),0,{t:v.slice(0,200),d:false});
    inp.value='';clRender();clCommit();return true;
  }
  function clCommit(){var s=U.sheet;if(!s||!s.id)return;if(find(s.id))upd(s.id,{items:clClean(s.items)});}
  function sheetHtml(){
    var s=U.sheet;
    var opts='<option value="">Входящие (не назначен)</option>'+S.people.map(function(p){return '<option value="'+p.id+'">'+esc(p.name)+'</option>';}).join('');
    var left='<label class="chip proj"><span id="cpj"></span><select id="sp" aria-label="Исполнитель">'+opts+'</select></label>';
    return '<div class="back" data-a="sclose"></div><form class="sheet" id="sf" autocomplete="off">'+
      (s.id&&find(s.id)&&infoParts(find(s.id),true).length?'<div class="ds inf" style="margin-bottom:6px">'+infoParts(find(s.id),true).join('<br>')+'</div>':'')+
      '<input type="text" id="st" placeholder="Название задачи" maxlength="200" aria-label="Название задачи" value="'+esc(s.t)+'">'+
      '<div class="perr" id="sterr" role="alert"></div>'+
      '<textarea class="sd" id="sdc" placeholder="Подробности задачи" maxlength="2000" rows="3" aria-label="Подробности задачи">'+esc(s.desc)+'</textarea><div class="lnk" id="lnk">'+lnkHtml(s)+'</div>'+clHtml(s)+
      '<div class="phr"><input type="tel" id="sph" placeholder="Номер контрагента" maxlength="32" autocomplete="off" aria-label="Номер контрагента" value="'+esc(s.phone||'')+'"><button type="button" class="phb" id="spc" data-a="pickcontact" aria-label="Выбрать номер из контактов телефона">'+I.book+'Контакты</button><span id="phl" class="phbs">'+phoneBtns(s.phone)+'</span></div><div class="phh" id="phh" role="status"></div>'+
      '<div class="chips"><button type="button" class="chip" id="cdue" data-a="pickdue"></button><button type="button" class="chip" id="cpri" data-a="pickpri"></button></div>'+
      '<div class="srow">'+left+'<button class="send" type="submit" id="ssend" aria-label="Сохранить">'+I.send+'</button></div>'+
      (s.id&&canDel(find(s.id))?'<div class="sdelw" id="sdelw">'+delBtn()+'</div>':'')+
      '</form>';
  }
  function delBtn(){return '<button type="button" class="sdelb" data-a="sdelask">'+I.trash+'<span>Удалить задачу</span></button>';
  }
  function refreshChips(){
    var s=U.sheet;if(!s)return;
    clFitAll();
    var d=$('cdue'),p=$('cpri'),sb=$('ssend'),st=$('st');
    if(d){d.innerHTML=I.cal+(s.due?esc(dueLabel(s.due)):'Срок');d.className='chip '+(s.due?dueCls(s.due):'');}
    if(p){p.innerHTML=I.flag+(s.pri<4?PN[s.pri]:'Приоритет');p.className='chip fl';p.style.color=s.pri<4?'var(--p'+s.pri+')':'';}
    var sel=$('sp'),j=$('cpj');
    if(sel)sel.value=s.assignee||'';
    if(j)j.innerHTML=I.person.replace('<svg','<svg style="width:19px;height:19px;stroke:currentColor;fill:none;stroke-width:2"')+'&nbsp;'+esc(s.assignee?who(s.assignee):'Исполнитель');
    if(sb)sb.disabled=false;
    growTa();
  }
  /* Свой календарь вместо системного поля даты. Системное <input type=date> на компьютере
     по клику на строку календарь не открывает (только по маленькому значку), поэтому выбор даты
     не работал. Здесь всё — обычные кнопки: одинаково на телефоне и на компьютере. */
  var WDS=['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
  var MONN=['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
  var MONG=['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
  function calShift(ym,dl){var p=ym.split('-'),d=new Date(+p[0],+p[1]-1+dl,1);return d.getFullYear()+'-'+pad(d.getMonth()+1);}
  function calHtml(sel){
    var p=U.pick.cal.split('-'),y=+p[0],m=+p[1]-1,t=today();
    var start=(new Date(y,m,1).getDay()+6)%7,n=new Date(y,m+1,0).getDate();
    var h='<div class="cal"><div class="calh"><button type="button" class="ib" data-a="calnav" data-d="-1" aria-label="Предыдущий месяц"><svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>'+
      '<b id="calm">'+MONN[m]+' '+y+'</b><button type="button" class="ib" data-a="calnav" data-d="1" aria-label="Следующий месяц"><svg viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg></button></div><div class="calg">';
    ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'].forEach(function(w){h+='<span class="calw">'+w+'</span>';});
    for(var i=0;i<start;i++)h+='<span></span>';
    for(var d=1;d<=n;d++){
      var v=y+'-'+pad(m+1)+'-'+pad(d);
      h+='<button type="button" class="cald'+(v<t?' past':'')+(v===t?' td':'')+(v===sel?' sel':'')+'" data-a="calday" data-v="'+v+'" aria-label="'+d+' '+MONG[m]+' '+y+'">'+d+'</button>';
    }
    return h+'</div><button type="button" class="popt" data-a="calback"><svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg><span>К вариантам срока</span></button></div>';
  }
  /* Ссылки: внутри задачи — кликабельные (блок «Ссылки» под подробностями), в списках — скрыты, остальной текст виден */
  var URLRE=/(?:https?:\/\/|www\.)[^\s<>"'«»]+/gi;
  var LKI='<svg viewBox="0 0 24 24"><path d="M10 14a4.5 4.5 0 006.4 0l3-3a4.5 4.5 0 00-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 00-6.4 0l-3 3a4.5 4.5 0 006.4 6.4l1-1"/></svg>';
  function urlCut(u){return u.replace(/[.,;:!?)\]}»"'…]+$/,'');}
  function urlHref(u){return /^www\./i.test(u)?'https://'+u:u;}
  function linksOf(){var r=[];for(var i=0;i<arguments.length;i++)String(arguments[i]||'').replace(URLRE,function(m){var u=urlCut(m);if(u.length>4&&r.indexOf(u)<0)r.push(u);return m;});return r;}
  function noLinks(s){return String(s||'').replace(URLRE,function(m){return m.slice(urlCut(m).length);}).replace(/[ \t]{2,}/g,' ').replace(/[ \t]+([,.;:!?])/g,'$1').replace(/[ \t]+$/gm,'').replace(/^[ \t]+/gm,'').replace(/\n{3,}/g,'\n\n').trim();}
  function linkA(u){return '<a class="lka" href="'+esc(urlHref(u))+'" target="_blank" rel="noopener noreferrer">'+LKI+'<span>'+esc(u)+'</span></a>';}
  function linkify(s){s=String(s||'');var out='',last=0;s.replace(URLRE,function(m,off){var u=urlCut(m);out+=esc(s.slice(last,off))+'<a class="lki" href="'+esc(urlHref(u))+'" target="_blank" rel="noopener noreferrer">'+esc(u)+'</a>';last=off+u.length;return m;});return out+esc(s.slice(last));}
  function lnkHtml(s){var l=linksOf(s.t,s.desc,(s.items||[]).map(function(x){return x.t;}).join('\n'));return l.length?'<div class="lkh">Ссылки</div>'+l.map(linkA).join(''):'';}
  function lnkRefresh(){var w=document.getElementById('lnk');if(w&&U.sheet)w.innerHTML=lnkHtml(U.sheet);}
  function listTitle(t){return esc(noLinks(t.t)||'Ссылка');}
  function listDesc(t){var d=noLinks(t.desc);return d?'<div class="ds dsc">'+esc(d)+'</div>':'';}
  document.addEventListener('input',function(e){
    var el=e.target;
    if(U.sheet&&(el.id==='st'||el.id==='sdc'||(el.classList&&el.classList.contains('clt'))))lnkRefresh();
  });
  function pickHtml(){
    var k=U.pick,h='<div class="back" style="z-index:22" data-a="pclose"></div><div class="pick" style="z-index:23">';
    if(k.kind==='histdel'){
      var ht=null;(H.list||[]).forEach(function(x){if(x.id===k.id)ht=x;});
      return h+'<h3>Удалить из истории?</h3><div class="ds" id="hdmsg" style="padding:2px 12px 8px;font-size:15px">«'+esc(ht?noLinks(ht.t):'')+'»: задача пропадёт из истории и из статистики.</div>'+
        '<button class="popt" data-a="histdelok" type="button" style="justify-content:center;background:var(--od);color:#fff;font-weight:700">Удалить</button>'+
        '<button class="popt" data-a="pclose" type="button" style="justify-content:center">Отмена</button></div>';
    }
    if(k.kind==='tel'){
      h+='<h3>'+(k.name?esc(k.name)+': выберите номер':'Выберите номер')+'</h3>';
      k.list.forEach(function(t,i){h+='<button class="popt" data-a="telopt" data-v="'+i+'" type="button">'+I.phone+'<span>'+esc(t)+'</span></button>';});
      return h+'</div>';
    }
    if(k.kind==='due'){
      if(k.cal)return h+'<h3>Срок: выберите дату</h3>'+calHtml(U.sheet?U.sheet.due:null)+'</div>';
      var t=today(),dow=new Date().getDay();
      var sat=addDays(t,(6-dow+7)%7||7),mon=addDays(t,(8-dow)%7||7);
      var o=[['Сегодня',t,I.cal],['Завтра',addDays(t,1),I.cal],['В эти выходные',sat,I.wk],['Следующая неделя',mon,I.next]];
      h+='<h3>Срок</h3>';
      o.forEach(function(x){h+='<button class="popt" data-a="popt" data-v="'+x[1]+'" type="button">'+x[2]+'<span>'+x[0]+'</span><span class="r">'+WDS[parse(x[1]).getDay()]+'</span></button>';});
      h+='<button class="popt" data-a="popt" data-v="" type="button">'+I.none+'<span>Без срока</span></button>';
      h+='<button class="popt" data-a="calopen" type="button">'+I.up+'<span>Выбрать дату</span></button>';
    }else{
      h+='<h3>Приоритет</h3>';
      [1,2,3,4].forEach(function(n){h+='<button class="popt'+(U.sheet.pri===n?' sel':'')+'" data-a="popt" data-v="'+n+'" type="button"><span style="color:var(--'+(n<4?'p'+n:'mute')+')">'+I.flag.replace('<svg','<svg style="fill:currentColor"')+'</span><span>'+PN[n]+'</span></button>';});
    }
    return h+'</div>';
  }
  function openSheet(t){
    U.menu=false;
    U.sheet=t?{id:t.id,t:t.t,desc:t.desc||'',phone:t.phone||'',due:t.due||null,pri:t.pri,assignee:t.assignee||null,items:clSort(clClean(t.items))}:{id:null,t:'',desc:'',phone:'',due:null,pri:4,assignee:null,items:[]};
    renderLayer();
    var st=$('st');if(st&&!t)st.focus();
  }
  function closeSheet(){U.sheet=null;U.pick=null;renderLayer();renderApp();}
  function toast(msg,fn){
    $('tmsg').textContent=msg;undoFn=fn;$('toast').hidden=false;
    clearTimeout(toastT);toastT=setTimeout(function(){$('toast').hidden=true;undoFn=null;},6000);
  }

  document.addEventListener('input',function(e){
    if(e.target.id==='st'&&U.sheet){U.sheet.t=e.target.value;setErr('sterr','');refreshChips();}
    if(e.target.id==='sdc'&&U.sheet){U.sheet.desc=e.target.value;growTa();}
    if(e.target.id==='sph'&&U.sheet){U.sheet.phone=e.target.value;var pl=document.getElementById('phl');if(pl)pl.innerHTML=phoneBtns(e.target.value);telHint('');}
  });
  document.addEventListener('change',function(e){
    if(e.target.id==='sp'&&U.sheet){U.sheet.assignee=e.target.value||null;refreshChips();}
    if(e.target.classList&&e.target.classList.contains('asel')){
      var tk=find(e.target.dataset.tid);
      if(tk){var prev=tk.assignee||null,w=e.target.value||null;upd(tk.id,{assignee:w});
        toast(w?'Назначено: '+who(w):'Исполнитель снят',function(){upd(tk.id,{assignee:prev});});}
      return;
    }
    if(e.target.id==='pd'&&e.target.value&&U.pick){U.sheet.due=e.target.value;U.pick=null;renderLayer();}
  });
  document.addEventListener('click',function(e){
    var b=e.target.closest&&e.target.closest('[data-a]');if(!b||!U.sheet)return;
    var a=b.dataset.a,s=U.sheet,i=+b.dataset.i;
    if(a==='cltog'&&s.items[i]){
      var it=s.items.splice(i,1)[0];it.d=!it.d;
      if(it.d)s.items.push(it);else s.items.splice(clUndone(s.items),0,it);
      clRender();clCommit();
    }else if(a==='cldel'&&s.items[i]){s.items.splice(i,1);clRender();clCommit();}
    else if(a==='cladd'){clAdd();var n=$('cln');if(n)n.focus();}
  });
  document.addEventListener('input',function(e){
    var el=e.target;
    if(el.classList&&el.classList.contains('clt')&&U.sheet&&U.sheet.items[+el.dataset.i]){U.sheet.items[+el.dataset.i].t=el.value;clFit(el);}
  });
  document.addEventListener('change',function(e){
    var el=e.target;
    if(el.classList&&el.classList.contains('clt')&&U.sheet&&U.sheet.items[+el.dataset.i]){U.sheet.items[+el.dataset.i].t=el.value.trim();clCommit();}
    else if(el.id==='cln')clAdd();
  });
  document.addEventListener('keydown',function(e){
    if(e.key!=='Enter')return;
    var el=e.target;
    if(el.id==='cln'){e.preventDefault();var ok=clAdd();var n2=$('cln');if(n2){if(ok){try{n2.setSelectionRange(0,0);}catch(x){}n2.blur();}n2.focus();}}
    else if(el.classList&&el.classList.contains('clt')){e.preventDefault();var n=$('cln');if(n)n.focus();}
  });
  document.addEventListener('submit',function(e){
    if(e.target.id!=='sf')return;
    e.preventDefault();
    clAdd();
    var s=U.sheet,v=(s.t||'').trim();
    if(!v){
      var dd=(s.desc||'').trim();
      if(!dd){setErr('sterr','Напишите название задачи.');var sf=$('st');if(sf)sf.focus();return;}
      var ln=dd.split('\n');v=ln[0].trim().slice(0,200);s.desc=ln.slice(1).join('\n');
    }
    if(s.id){
      var o0=find(s.id)||{},nv={t:v,desc:(s.desc||'').trim(),phone:(s.phone||'').trim(),due:s.due||null,pri:s.pri,assignee:s.assignee||null},ch={},nch=0;
      for(var kk in nv){var ov=o0[kk];if(kk==='desc'||kk==='phone')ov=ov||'';if(kk==='due'||kk==='assignee')ov=ov||null;if(nv[kk]!==ov){ch[kk]=nv[kk];nch++;}}
      if(nch)upd(s.id,ch);
    }
    else addTask({t:v,desc:(s.desc||'').trim(),phone:(s.phone||'').trim(),due:s.due,pri:s.pri,assignee:s.assignee,items:clClean(s.items)});
    U.sheet=null;U.pick=null;if(!s.id){U.tab=s.assignee?'people':'inbox';U.person=s.assignee||null;}render();
  });
  document.addEventListener('keydown',function(e){
    if(e.key==='Enter'&&e.target.id==='gk'){e.preventDefault();var gb=document.querySelector('[data-a=gkok]');if(gb)gb.click();return;}
    if(e.key==='Escape'){if(U.greet){U.greet=null;renderLayer();renderApp();}else if(U.pick){U.pick=null;renderLayer();}else if(U.sheet)closeSheet();else if(U.menu){U.menu=false;renderLayer();}}
  });
  document.addEventListener('click',function(e){
    var b=e.target.closest('[data-a]');if(!b)return;
    var a=b.dataset.a;
    if(a==='noop')return;
    if(a==='iam'){
      meSlug=b.dataset.p;try{localStorage.setItem('planerka-me',meSlug);}catch(er){}
      PUSH.tried=false;U.tab='me';U.person=null;U.menu=false;injectManifest();render();toTop();
      var gk='';try{gk=localStorage.getItem(KG+meSlug);}catch(er){}
      maybeResp();   // у выбранного человека есть задачи «ждёт ответа» — спросить сразу
      pushAuto();return;
    }
    if(a==='pushon'){
      // запрос разрешения должен идти прямо из нажатия
      try{Notification.requestPermission().then(function(perm){if(perm==='granted')pushEnsure();else render();});}catch(er){render();}
      return;
    }
    if(a==='pushtest'){
      U.menu=false;renderLayer();
      apiPost({op:'push.test',person:meSlug}).then(function(r){return r.json();}).then(function(j){
        toast(j&&j.devices?'Тестовое уведомление отправлено':'Это устройство ещё не подписано: включите уведомления');
      }).catch(function(){toast('Нет связи');});
      return;
    }
    if(a==='eye'){eyeToggle(b);return;}
    if(a==='gkok'){
      var raw=(($('gk')||{}).value||'').trim();
      setErr('gkerr','');
      if(!raw){setErr('gkerr','Введите пароль.');return;}
      var useTeam=function(k2){key=k2.toLowerCase();try{localStorage.setItem(KS,key);}catch(er){}S.err=null;S.loaded=false;render();refresh();};
      var hk=raw.replace(/^.*k=/,'');
      if(/^[0-9a-f]{32}$/i.test(hk)){useTeam(hk);return;}   // прежний код доступа тоже принимается
      var gb2=document.querySelector('[data-a=gkok]');if(gb2)gb2.disabled=true;
      fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({op:'pw.login',for:'planerka',password:raw})})
        .then(function(r){return r.json().then(function(j){return {st:r.status,j:j};});})
        .then(function(x){
          if(gb2)gb2.disabled=false;
          if(x.j&&x.j.team){useTeam(x.j.team);return;}
          setErr('gkerr',x.st===409?'Пароль Планёрки ещё не задан. Попросите руководителя задать его.':x.st===429?'Слишком много попыток. Попробуйте через 15 минут.':pwHint(raw));
          var gi=$('gk');if(gi)gi.select();
        }).catch(function(){if(gb2)gb2.disabled=false;setErr('gkerr','Нет связи с сервером. Проверьте интернет.');});
      return;
    }
    if(a==='gok'){U.greet=null;renderLayer();renderApp();maybeOpenTask();maybeResp();return;}
    if(a==='raccept'){respond(b.closest('.task').dataset.id,'accept');return;}
    if(a==='raccept2'){respond(U.resp.id,'accept');return;}
    if(a==='rdecl'){U.resp={id:b.closest('.task').dataset.id,step:'reason'};renderLayer();var ra=$('rr');if(ra)ra.focus();return;}
    if(a==='rdecl2'){U.resp.step='reason';renderLayer();var rb2=$('rr');if(rb2)rb2.focus();return;}
    if(a==='rback'){U.resp.step='ask';renderLayer();return;}
    if(a==='rsend'){
      var why=(($('rr')||{}).value||'').trim();
      if(!why){toast('Напишите причину отказа');return;}
      respond(U.resp.id,'decline',why);return;
    }
    if(a==='rlater'){if(U.resp)respSkip[U.resp.id]=true;U.resp=null;renderLayer();return;}
    if(a==='done'){
      var row=b.closest('.task'),t=find(row.dataset.id);if(!t)return;
      if(!t.done){
        row.classList.add('leaving');
        setTimeout(function(){upd(t.id,{done:true});toast('Задача выполнена',function(){upd(t.id,{done:false});});},200);
      }else upd(t.id,{done:false});
      return;
    }
    if(a==='open'){var t3=find(b.closest('.task').dataset.id);if(t3)openSheet(t3);return;}
    if(a==='add'){openSheet(null);return;}
    if(a==='tab'){U.tab=b.dataset.t;U.person=null;U.menu=false;saveUi();render();toTop();return;}
    if(a==='openperson'){U.person=b.dataset.p;renderApp();toTop();return;}
    if(a==='hist'){if(U.tab!=='hist'&&U.tab!=='stats')U.prevTab=U.tab;U.tab='hist';U.menu=false;H.list=null;render();histLoad();toTop();return;}
    if(a==='histret'){
      // вернуть выполненную задачу в работу (снять отметку «выполнено»); может любой участник
      var rid=b.dataset.id,keepR=H.list,setDone=function(v){if(find(rid))upd(rid,{done:v});else call({op:'upd',id:rid,f:{done:v},by:meSlug||null});};   // старше 60 дней — в списке устройства её нет, ответ сервера вернёт её в список
      H.list=(H.list||[]).filter(function(x){return x.id!==rid;});renderApp();
      respSkip[rid]=true;   // сам вернул — окно «Принять/Отказать» поверх плашки «Отменить» не показываем
      setDone(false);
      toast('Задача снова в работе',function(){setDone(true);H.list=keepR;if(U.tab==='hist')renderApp();});
      return;
    }
    if(a==='histdel'){U.pick={kind:'histdel',id:b.dataset.id};renderLayer();return;}
    if(a==='histdelok'){
      var hid=U.pick&&U.pick.id;U.pick=null;renderLayer();if(!hid)return;
      var keep=H.list;H.list=(H.list||[]).filter(function(x){return x.id!==hid;});renderApp();
      apiPost({op:'hist.del',id:hid,by:meSlug}).then(function(r){if(!r.ok)throw new Error('');return r.json();}).then(function(j){H.list=j.tasks||H.list;if(U.tab==='hist')renderApp();S.rev=-1;refresh();
        toast('Удалено из истории и статистики',function(){apiPost({op:'hist.del',id:hid,undo:true,by:meSlug}).then(function(r2){return r2.json();}).then(function(j2){H.list=j2.tasks||H.list;if(U.tab==='hist')renderApp();S.rev=-1;refresh();});});
      }).catch(function(){H.list=keep;renderApp();toast('Не удалось удалить — нет связи');});
      return;
    }
    if(a==='goback'){if(U.tab==='hist'){U.tab=U.prevTab||'inbox';U.prevTab=null;renderApp();toTop();return;}if(U.tab==='stats'){U.tab=U.prevTab||'inbox';U.prevTab=null;}else U.person=null;renderApp();toTop();return;}
    if(a==='stats'){if(U.tab!=='stats')U.prevTab=U.tab;U.tab='stats';U.menu=false;S.stats=S.stats||null;render();statsLoad();toTop();return;}
    if(a==='stperson'){U.prevTab=null;U.tab='people';U.person=b.dataset.p;render();toTop();return;}
    if(a==='stmode'){U.st.mode=b.dataset.m;renderApp();return;}
    if(a==='styr'){var ny=U.st.year+(+b.dataset.d);if(ny<=new Date().getFullYear()&&ny>=2020)U.st.year=ny;renderApp();return;}
    if(a==='stq'){U.st.q=+b.dataset.q;renderApp();return;}
    if(a==='showdone'){U.showDone=!U.showDone;render();return;}
    if(a==='menu'){U.menu=!U.menu;renderLayer();return;}
    if(a==='mclose'){U.menu=false;renderLayer();return;}
    if(a==='theme'){S.theme=isDark()?'light':'dark';saveUi();render();return;}
    if(a==='sclose'){closeSheet();return;}
    if(a==='pclose'){U.pick=null;renderLayer();return;}
    if(a==='pickdue'){U.pick={kind:'due'};renderLayer();return;}
    if(a==='pickcontact'){pickContact();return;}
    if(a==='pastetel'){pasteTel();return;}
    if(a==='telopt'){var telL=U.pick&&U.pick.list,telV=telL&&telL[+b.dataset.v];U.pick=null;renderLayer();if(telV)setTel(telV);return;}
    if(a==='calopen'){U.pick.cal=((U.sheet&&U.sheet.due)||today()).slice(0,7);renderLayer();return;}
    if(a==='calnav'){U.pick.cal=calShift(U.pick.cal,+b.dataset.d);renderLayer();return;}
    if(a==='calback'){delete U.pick.cal;renderLayer();return;}
    if(a==='calday'){U.sheet.due=b.dataset.v;U.pick=null;renderLayer();return;}
    if(a==='pickpri'){U.pick={kind:'pri'};renderLayer();return;}
    if(a==='popt'){
      if(U.pick.kind==='pri')U.sheet.pri=+b.dataset.v;else U.sheet.due=b.dataset.v||null;
      U.pick=null;renderLayer();return;
    }
    if(a==='sdelask'){var w=$('sdelw');if(w)w.innerHTML='<div class="sdelq" role="alert"><span>Удалить задачу совсем? В статистику она не войдёт.</span><button type="button" data-a="sdelno">Отмена</button><button type="button" class="y" data-a="sdel">Удалить</button></div>';return;}
    if(a==='sdelno'){var w2=$('sdelw');if(w2)w2.innerHTML=delBtn();return;}
    if(a==='sdel'){
      var id=U.sheet.id;U.sheet=null;U.pick=null;upd(id,{del:true});renderLayer();
      toast('Задача удалена',function(){call({op:'upd',id:id,f:{del:false},by:meSlug||null});});
    }
  });
  $('undo').addEventListener('click',function(){
    var f=undoFn;undoFn=null;$('toast').hidden=true;clearTimeout(toastT);if(f)f();
  });
  document.addEventListener('visibilitychange',function(){if(!document.hidden){refresh();checkNav();}});
  setInterval(function(){if(!document.hidden)refresh();},500);
  try{var mq=window.matchMedia('(prefers-color-scheme: dark)');if(mq.addEventListener)mq.addEventListener('change',function(){if(!S.theme)render();});}catch(e){}
  /* СВАЙП МЕЖДУ ВКЛАДКАМИ (13.10.2026): палец влево — следующая вкладка нижней панели, вправо — предыдущая.
     Под сдвигаемой страницей сразу видна соседняя — размытая и осветлённая; чем дальше сдвиг, тем она яснее
     и тем ближе к своему месту. У верхней страницы тень по краю, который наезжает на нижнюю.
     У крайней вкладки страница лишь чуть поддаётся и возвращается: дальше не идёт.
     Вертикальная прокрутка не мешает: направление жеста определяется по первым 10 px. */
  (function(){
    var el=$('app'),sx=0,sy=0,st=0,dx=0,mode=-1,busy=false;   // mode: 0 — ещё не ясно, 1 — свайп, -1 — не наш жест
    var un=null,unTab=null;                                    // нижняя страница и чья она
    var RM=false;try{RM=matchMedia('(prefers-reduced-motion: reduce)').matches;}catch(e){}
    var EASE='cubic-bezier(.22,.7,.3,1)';
    function tabIds(){return navTabs().map(function(x){return x[0];});}
    function can(){return !!key&&S.err!=='auth'&&!U.menu&&!U.sheet&&!U.pick&&!U.greet&&!U.resp&&!U.person&&tabIds().indexOf(U.tab)>=0;}
    function hscroll(n){for(;n&&n!==el;n=n.parentElement){if(/^(INPUT|TEXTAREA|SELECT)$/.test(n.tagName))return true;var o=getComputedStyle(n).overflowX;if((o==='auto'||o==='scroll')&&n.scrollWidth>n.clientWidth+1)return true;}return false;}
    function tr(ms){return ms?'transform '+ms+'ms '+EASE+',box-shadow '+ms+'ms '+EASE+',filter '+ms+'ms '+EASE:'none';}
    function setX(x,ms){
      el.style.transition=tr(ms);el.style.transform=x?'translate3d('+x+'px,0,0)':'';
      // тень падает на нижнюю страницу: со стороны, которая открывается
      el.style.boxShadow=x?((x<0?'14px':'-14px')+' 0 34px -6px rgba(0,0,0,.30),'+(x<0?'2px':'-2px')+' 0 6px rgba(0,0,0,.12)'):'';
    }
    function underFor(tab){
      if(!un){un=document.createElement('div');un.id='under';un.className='app under';un.setAttribute('aria-hidden','true');el.parentNode.insertBefore(un,el);}
      if(unTab!==tab){un.innerHTML=viewOf(tab);unTab=tab;un.scrollTop=0;}
    }
    // p — сколько пройдено (0…1): размытие и осветление уходят, страница доезжает до места
    function underSet(p,dir,ms){
      if(!un)return;var w=el.clientWidth||innerWidth;
      un.style.transition=tr(ms);
      un.style.transform=p<1?'translate3d('+Math.round(-dir*(1-p)*w*0.22)+'px,0,0)':'';
      un.style.filter=p<1?'blur('+(7*(1-p)).toFixed(2)+'px)':'';
      un.style.setProperty('--fog',(0.5*(1-p)).toFixed(3));
    }
    function underOff(){if(un){un.remove();un=null;unTab=null;}}
    el.addEventListener('touchstart',function(e){
      if(busy||e.touches.length!==1||!can()||hscroll(e.target)){mode=-1;return;}
      sx=e.touches[0].clientX;sy=e.touches[0].clientY;st=Date.now();dx=0;mode=0;
    },{passive:true});
    el.addEventListener('touchmove',function(e){
      if(mode<0)return;
      if(e.touches.length!==1){mode=-1;setX(0,200);underSet(0,dx<0?-1:1,200);setTimeout(underOff,210);return;}
      var x=e.touches[0].clientX-sx,y=e.touches[0].clientY-sy;
      if(!mode){if(Math.abs(x)<10&&Math.abs(y)<10)return;mode=Math.abs(x)>Math.abs(y)*1.2?1:-1;if(mode<0)return;}
      e.preventDefault();
      var T=tabIds(),i=T.indexOf(U.tab),edge=(x>0&&i<=0)||(x<0&&i>=T.length-1);
      if(edge){dx=(x>0?1:-1)*Math.min(Math.abs(x)*0.2,28);underOff();}   // у края — лёгкое сопротивление, соседа нет
      else{dx=x;underFor(T[x<0?i+1:i-1]);underSet(Math.min(1,Math.abs(x)/(el.clientWidth||innerWidth)),x<0?-1:1,0);}
      setX(dx,0);
    },{passive:false});
    function end(){
      if(mode!==1){mode=-1;return;}
      mode=-1;
      var T=tabIds(),i=T.indexOf(U.tab),w=el.clientWidth||innerWidth,v=Math.abs(dx)/Math.max(1,Date.now()-st);
      var j=dx<0?i+1:i-1,go=j>=0&&j<T.length&&(Math.abs(dx)>w*0.22||(v>0.45&&Math.abs(dx)>30));
      var dir=dx<0?-1:1,ms=RM?0:220;
      if(!go){busy=true;setX(0,ms);underSet(0,dir,ms);setTimeout(function(){underOff();busy=false;},ms+10);return;}
      busy=true;ms=RM?0:200;
      setX(dir*w,ms);underSet(1,dir,ms);
      setTimeout(function(){
        // нижняя страница уже на месте и без размытия — подменяем верхнюю на неё же, шва не видно
        U.tab=T[j];U.person=null;saveUi();setX(0,0);render();toTop();underOff();busy=false;
      },ms+10);
    }
    el.addEventListener('touchend',end);
    el.addEventListener('touchcancel',function(){if(mode===1){mode=-1;setX(0,200);underSet(0,dx<0?-1:1,200);setTimeout(underOff,210);}else mode=-1;});
  })();
  /* КЛАВИАТУРА: окна и поле ввода — над клавиатурой (см. CSS «КЛАВИАТУРА») */
  (function(){
    var R=document.documentElement,t=null;
    function fit(){
      var vv=window.visualViewport;if(!vv)return;
      // при увеличении щипком видимая часть тоже меньше — это не клавиатура
      var kb=vv.scale>1.05?0:Math.max(0,Math.round(innerHeight-vv.height-vv.offsetTop)),on=kb>80;
      R.style.setProperty('--kb',kb+'px');R.style.setProperty('--vvh',Math.round(vv.height)+'px');R.style.setProperty('--vvt',Math.round(vv.offsetTop)+'px');
      if(R.classList.contains('kb')!==on)R.classList.toggle('kb',on);
      if(on)reveal();
    }
    function reveal(){
      clearTimeout(t);
      t=setTimeout(function(){var a=document.activeElement;if(a&&/^(INPUT|TEXTAREA)$/.test(a.tagName)&&R.classList.contains('kb')){try{a.scrollIntoView({block:'nearest'});}catch(e){}growTa();}},60);
    }
    try{var vv=window.visualViewport;if(vv){vv.addEventListener('resize',fit);vv.addEventListener('scroll',fit);}}catch(e){}
    window.addEventListener('resize',fit);
    document.addEventListener('focusin',function(){fit();reveal();setTimeout(fit,350);});
    document.addEventListener('focusout',function(){setTimeout(fit,350);});
    fit();
  })();
  render();refresh();checkNav();
})();
</script>
````
<!-- END FILE -->

### `planerka/app.css` — общий базовый стиль (214 строк)

<!-- FILE planerka/app.css text sha256=b0097c6c50eb931030f527107d4110445faf963e961e58d20b2e0b02dfe7fb13 -->
````css
/*FONTS*/
/* Layout: Todoist-style mobile app. Large title, hairline task rows, floating tab pill and round add button at the bottom */
:root{
  --bg:#FBF8F2; --ink:#0E1626; --mute:#6F7889; --line:#E8E3D8; --surface:#FFFFFF;
  --accent:#0B6EF5; --accent-ink:#FFFFFF; --pill:#F2EFE8; --nav:#FFFFFF; --navon:#E4EEFE; --shadow:0 6px 24px rgba(14,22,38,.14);
  --p1:#D1453B; --p2:#E09B00; --p3:#0B6EF5; --p4:#9AA1AF;
  --od:#D1453B; --td:#078A62; --tm:#B87900; --wk:#7B4FD0;
  --scrim:rgba(14,22,38,.4);
  --f:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
  --fl:'Poppins','Inter',-apple-system,sans-serif;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --bg:#0E1626; --ink:#F4F6FA; --mute:#93A0B8; --line:#25324B; --surface:#17223A;
  --accent:#5B9BFF; --accent-ink:#0E1626; --pill:#131D32; --nav:#131D32; --navon:#22304D; --shadow:0 6px 24px rgba(0,0,0,.5);
  --p1:#FF7066; --p2:#FFC20E; --p3:#5B9BFF; --p4:#8190AA; --od:#FF7066; --td:#3CC49A; --tm:#FFC20E; --wk:#A58AF0; --scrim:rgba(0,0,0,.6); color-scheme:dark}}
:root[data-theme="dark"]{
  --bg:#0E1626; --ink:#F4F6FA; --mute:#93A0B8; --line:#25324B; --surface:#17223A;
  --accent:#5B9BFF; --accent-ink:#0E1626; --pill:#131D32; --nav:#131D32; --navon:#22304D; --shadow:0 6px 24px rgba(0,0,0,.5);
  --p1:#FF7066; --p2:#FFC20E; --p3:#5B9BFF; --p4:#8190AA; --od:#FF7066; --td:#3CC49A; --tm:#FFC20E; --wk:#A58AF0; --scrim:rgba(0,0,0,.6); color-scheme:dark}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{min-height:100%}
body{background:var(--bg);color:var(--ink);font-family:var(--f);font-size:15px;line-height:1.3;padding-inline:16px;-webkit-font-smoothing:antialiased}
button{font-family:inherit;color:inherit}
svg{display:block}
.app{max-width:560px;margin:0 auto;padding-block:0 150px}
.top{position:sticky;top:env(safe-area-inset-top,0px);z-index:10;display:flex;align-items:center;gap:8px;height:40px;background:var(--bg);border-bottom:1px solid var(--line);margin-inline:-16px;padding-inline:16px}
.logo{display:flex;align-items:center;gap:9px;line-height:1}
.logo img{width:26px;height:26px;display:block}
.wm{font:800 17px/1 var(--fl);letter-spacing:-.025em;color:var(--ink)}
.tools{display:flex;margin-left:auto;margin-right:-8px}
.ib{width:40px;height:40px;border:0;background:none;display:grid;place-items:center;cursor:pointer;border-radius:99px;color:var(--ink)}
.ib svg{width:21px;height:21px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.ib.on{color:var(--accent)}
.backb{margin-left:-10px;width:36px}
h1{font-size:26px;font-weight:800;letter-spacing:-.03em;line-height:1.1;margin:14px 0 4px;overflow-wrap:anywhere}
.sh{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:14px;font-weight:700;margin:8px 0 0;min-height:36px}
.sh .act{display:flex;align-items:center;gap:6px;font:600 14px var(--f);color:var(--accent);background:none;border:0;cursor:pointer;min-height:36px;padding:0}
.sh .act svg{width:20px;height:20px;stroke:var(--ink);fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;transition:transform .2s}
.sh .act.shut svg{transform:rotate(-90deg)}
.task{display:flex;gap:12px;align-items:flex-start}
.cb{flex:none;width:24px;height:24px;margin-top:7px;border-radius:50%;border:2px solid var(--c);background:color-mix(in srgb,var(--c) 10%,transparent);cursor:pointer;display:grid;place-items:center;padding:0;--c:var(--p4)}
.cb.p1{--c:var(--p1)}.cb.p2{--c:var(--p2)}.cb.p3{--c:var(--p3)}
.cb svg{width:13px;height:13px;stroke:var(--c);stroke-width:3.2;fill:none;stroke-linecap:round;stroke-linejoin:round;opacity:0}
.cb:hover svg{opacity:.55}
.task.done .cb{background:var(--c)}
.task.done .cb svg{opacity:1;stroke:var(--bg)}
.task.urg:not(.done) .tt{color:var(--p1);font-weight:600}
.task.urg:not(.done) .cb{background:color-mix(in srgb,var(--p1) 18%,transparent)}
.task.done .tt{color:var(--mute);text-decoration:line-through}
.task.leaving{opacity:0;transform:translateX(12px);transition:opacity .22s,transform .22s}
.tb{flex:1;min-width:0;border-bottom:1px solid var(--line);padding:6px 0 6px;cursor:pointer}
.tt{font-size:16.5px;line-height:1.25;overflow-wrap:anywhere}
.ds{font-size:13px;line-height:1.3;color:var(--mute);margin-top:0;overflow-wrap:anywhere}
.meta{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:1px;font-size:13px;color:var(--mute);min-height:0}
.meta>span{display:flex;align-items:center;gap:5px;min-width:0}
.meta svg{flex:none;width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.meta .pj{margin-left:auto;text-align:right;overflow-wrap:anywhere}
.od{color:var(--od)}.td{color:var(--td)}.tm{color:var(--tm)}.wk{color:var(--wk)}
.empty{text-align:center;color:var(--mute);padding:36px 8px 16px;font-size:14px}
.empty b{display:block;color:var(--ink);font-size:17px;margin-bottom:6px;font-weight:700}
.row{display:flex;align-items:center;gap:12px;min-height:46px;padding:4px 0;border-bottom:1px solid var(--line);width:100%;background:none;border-left:0;border-right:0;border-top:0;text-align:left;font-size:16px;cursor:pointer}
.row .dot{flex:none;width:14px;height:14px;border-radius:50%}
.row .nm{flex:1;min-width:0;overflow-wrap:anywhere}
.row .cnt{color:var(--mute);font-size:14px}
.row svg{flex:none;width:20px;height:20px;stroke:var(--mute);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.sw{flex:none;width:42px;height:25px;border-radius:99px;background:var(--line);position:relative;transition:background .2s}
.sw::after{content:"";position:absolute;top:3px;left:3px;width:19px;height:19px;border-radius:50%;background:#fff;transition:transform .2s}
.sw.on{background:var(--accent)}.sw.on::after{transform:translateX(17px)}
.nav{position:fixed;left:16px;right:16px;bottom:calc(12px + env(safe-area-inset-bottom,0px));max-width:528px;margin:0 auto;display:flex;background:var(--nav);border-radius:99px;padding:3px;box-shadow:var(--shadow);border:1px solid var(--line)}
.nav button{flex:1;min-width:0;border:0;background:none;border-radius:99px;padding:6px 2px 5px;display:flex;flex-direction:column;align-items:center;gap:2px;font-size:11px;font-weight:600;color:var(--ink);cursor:pointer}
.nav button.on{background:var(--navon);color:var(--accent)}
.nav button[data-t=urgent]:not(.on){color:var(--p1)}
.nav button span{font-size:10.5px}
.sbanner{display:block;width:100%;margin:8px 0 0;border:1px dashed var(--od);border-radius:12px;background:none;color:var(--od);font:600 14px var(--f);padding:10px 12px;text-align:left;cursor:pointer}
.pick textarea{width:100%;height:120px;font:12px/1.35 ui-monospace,Menlo,monospace;color:var(--ink);background:var(--surface);border:0;border-radius:12px;padding:10px;outline:0;margin:4px 0 8px;resize:none}
.conn{display:flex;flex-direction:column;gap:6px;border:1px dashed var(--line);border-radius:14px;padding:12px;margin:6px 0 4px;font-size:14px;color:var(--mute)}
.conn b{color:var(--ink);font-size:15px}
.gk2{display:flex;gap:8px;margin-top:4px}
.gk2 input{flex:1;min-width:0;font:500 16px var(--f);color:var(--ink);background:var(--surface);border:1.5px solid var(--line);border-radius:10px;padding:0 12px;height:42px;outline:0}
.gkb2{flex:none;border:0;border-radius:10px;background:var(--accent);color:var(--accent-ink);font:700 14px var(--f);padding:0 14px;min-height:42px;cursor:pointer}
.asel{margin-top:4px;max-width:100%;font:500 14px var(--f);color:var(--ink);background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:5px 8px;min-height:34px}
.exr{display:flex;align-items:center;gap:0;min-height:46px;border-bottom:1px solid var(--line)}
.exr .nm{flex:1;min-width:0;font-size:16px;overflow-wrap:anywhere}
.nav svg{width:23px;height:23px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.nav span{white-space:normal;text-align:center;line-height:1.1}
.fab{position:fixed;right:max(20px,calc(50% - 264px));bottom:calc(76px + env(safe-area-inset-bottom,0px));width:54px;height:54px;border-radius:50%;border:0;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;cursor:pointer;box-shadow:var(--shadow)}
.fab svg{width:26px;height:26px;stroke:currentColor;stroke-width:2.6;fill:none;stroke-linecap:round}
.back{position:fixed;inset:0;background:var(--scrim);z-index:20}
.sheet{position:fixed;left:0;right:0;bottom:0;z-index:21;background:var(--bg);border-radius:24px 24px 0 0;padding:14px 16px calc(12px + env(safe-area-inset-bottom,0px));max-width:560px;margin:0 auto;box-shadow:var(--shadow);animation:up .22s ease}
@keyframes up{from{transform:translateY(40px);opacity:0}to{transform:none;opacity:1}}
.sheet input[type=text]{width:100%;font:600 18px var(--f);color:var(--ink);background:var(--surface);border:1.5px solid var(--line);border-radius:12px;outline:0;padding:10px 12px}
.sheet input[type=text]:focus{border-color:var(--accent)}
.sheet input#st{margin-right:44px;width:calc(100% - 44px)}
.chip.exs select{position:absolute;inset:0;width:100%;height:100%;opacity:0;font-size:16px;cursor:pointer}
.chip.exs.on{color:var(--accent);border-color:var(--accent)}
.chip.exs.off{opacity:.45;cursor:not-allowed}
.chip.exs.off select{cursor:not-allowed}
.sheet input.sd{font-size:15px;font-weight:400;color:var(--ink)}
.sheet{max-height:92vh;overflow-y:auto}
.pwf{position:relative;display:block;flex:1;min-width:0}
.pwf input{width:100%;padding-right:48px!important}
.eye{position:absolute;right:4px;top:50%;transform:translateY(-50%);width:42px;height:42px;border:0;background:none;color:var(--mute);display:grid;place-items:center;cursor:pointer;border-radius:10px}
.pick .pwf .eye{top:calc(50% - 2px)}
.eye svg{width:21px;height:21px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.npr{display:flex;align-items:center;gap:8px;margin:0 0 10px}
.sheet .npr input[type=text]{flex:1 1 auto;width:auto;min-width:0;font-size:16px}
.npb,.npc{flex:0 0 auto;min-height:44px;padding:8px 14px;border-radius:12px;border:1.5px solid var(--line);background:var(--surface);color:var(--ink);font:600 15px var(--f);cursor:pointer}
.npb{background:var(--accent);border-color:var(--accent);color:#fff}
.pick{max-height:calc(100vh - 32px);overflow-y:auto}
.cal{padding:2px 2px 4px}
.calh{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:0 0 4px}
.calh b{font-size:16px;font-weight:700;text-align:center}
.calg{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:2px;margin-bottom:4px}
.calw{text-align:center;font-size:12px;font-weight:600;color:var(--mute);padding:4px 0}
.cald{min-height:42px;border:0;background:none;border-radius:12px;font:500 16px var(--f);color:var(--ink);cursor:pointer;padding:0}
.cald:hover{background:var(--surface)}
.cald.past{color:var(--mute)}
.cald.td{color:var(--accent);font-weight:700;box-shadow:inset 0 0 0 1.5px var(--accent)}
.cald.sel{background:var(--accent);color:#fff;font-weight:700}
.lnk{display:flex;flex-direction:column;gap:6px;margin:8px 0 2px}
.lnk:empty{display:none}
.lkh{font-size:12px;font-weight:700;color:var(--mute);letter-spacing:.03em;padding:0 2px}
.lka{display:flex;align-items:flex-start;gap:8px;padding:9px 12px;border-radius:12px;background:var(--surface);color:var(--accent);text-decoration:none;font-size:15px;line-height:1.35}
.lka span{min-width:0;overflow-wrap:anywhere;word-break:break-word}
.lka svg{flex:0 0 auto;width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;margin-top:1px}
.lki{color:var(--accent);overflow-wrap:anywhere;word-break:break-word}
.sh.sep{border-top:2px solid var(--line);margin-top:22px;padding-top:10px}
.sh.sub{margin-top:14px}
.histb{display:flex;align-items:center;gap:10px;width:100%;margin:22px 0 8px;min-height:50px;padding:0 14px;border:1px solid var(--line);border-radius:14px;background:var(--surface);color:var(--ink);font:600 15px var(--f);cursor:pointer;text-align:left}
.histb span{flex:1}
.histb svg{flex:none;width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.hrow{display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--line);padding:6px 0}
.hrow .hb{flex:1;min-width:0}
.hrow .tt{font-size:16px}
.hrow .ib{color:var(--mute)}
.avw{position:relative;display:inline-flex;flex:none}
.pdot{position:absolute;right:-2px;bottom:-2px;width:12px;height:12px;border-radius:50%;border:2px solid var(--surface,#fff);box-sizing:border-box}
.pdot.inl{position:static;display:inline-block;width:10px;height:10px;border:0;margin-right:8px;vertical-align:middle}
.pdot.g{background:#22B35E}.pdot.y{background:#F2B500}.pdot.r{background:#E5484D}
.plegend{display:flex;gap:16px;flex-wrap:wrap;font-size:12.5px;color:var(--mute);margin:8px 2px 0}
.plegend span{display:inline-flex;align-items:center}
.net{font-size:12px;color:var(--od);font-weight:600;margin-left:6px}
.perr{color:var(--od);font-size:14px;font-weight:600;line-height:1.35;padding:2px 12px 8px}
.perr:empty{display:none}
.sheet textarea.sd{display:block;width:100%;min-height:76px;font:400 15px/1.45 var(--f);color:var(--ink);background:var(--surface);border:0;border-radius:12px;padding:10px 12px;outline:0;resize:none;margin-top:6px}
.sheet textarea.sd::placeholder{color:var(--mute)}
.phr{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px}
.phr input{flex:1 1 170px;min-width:0;font:500 16px var(--f);color:var(--ink);background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:0 12px;height:42px;outline:0}
.phr input:focus{border-color:var(--accent)}
.phbs{display:flex;gap:6px;flex-wrap:wrap}
.phb{display:inline-flex;align-items:center;gap:6px;min-height:42px;padding:0 12px;border-radius:10px;border:1px solid var(--line);color:var(--accent);font:600 14px var(--f);text-decoration:none}
.phb svg,.phl svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
button.phb{background:none;cursor:pointer;text-align:left}
.phh{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;margin:6px 2px 0;color:var(--mute);font-size:13.5px;line-height:1.4}
.phh:empty{display:none}
.phh span{flex:1 1 100%;min-width:0}
/* напоминание исполнителю: кнопки в окне задачи «Чикаго» и строка результата */
.rmd{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0 0 10px}
.rmd[hidden]{display:none}
.rmb{display:inline-flex;align-items:center;gap:7px;min-height:42px;padding:6px 12px;border-radius:10px;border:1px solid var(--accent);background:none;color:var(--accent);font:600 14px var(--f);line-height:1.25;text-align:left;cursor:pointer;max-width:100%}
.rmb.sec{border-color:var(--line)}
.rmb:disabled{opacity:.5;cursor:default}
.rmb svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
.rms{flex:1 1 100%;padding:2px 2px 0}
.rms.ok{color:var(--td);font-weight:600}
.rms.err{color:var(--od);font-weight:600}
.ds.dsc{white-space:pre-line}
.ds.phl a{display:inline-flex;align-items:center;gap:6px;color:var(--accent);text-decoration:none;font-weight:600;padding:3px 0}
.sheet input::placeholder{color:var(--mute)}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 10px}
.chip{display:inline-flex;align-items:center;gap:7px;font:500 14px var(--f);color:var(--mute);background:none;border:1px solid var(--line);border-radius:10px;padding:0 10px;min-height:38px;cursor:pointer;position:relative;max-width:100%}
.chip.exs svg{width:17px;height:17px}
.chip.exs span,.chip.proj span{display:inline-flex;align-items:center;gap:6px}
.chip.exs span svg,.chip.proj span svg{display:inline-block}
.chip svg{flex:none;width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.chip.fl svg{fill:currentColor}
.srow{display:flex;align-items:center;justify-content:space-between;gap:10px;border-top:1px solid var(--line);padding-top:8px}
.chip.proj{border:0;padding-left:0;color:var(--ink)}
.chip.proj select{position:absolute;inset:0;width:100%;height:100%;opacity:0;font-size:16px}
.send{flex:none;width:42px;height:42px;border-radius:50%;border:0;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;cursor:pointer}
.send svg{width:22px;height:22px;stroke:currentColor;stroke-width:2.8;fill:none;stroke-linecap:round;stroke-linejoin:round}
.send:disabled{opacity:.4}
.trash{position:absolute;right:10px;top:8px;color:var(--mute)}
.cl{margin:4px 0 10px}
.clh{font-size:12px;font-weight:700;color:var(--mute);letter-spacing:.03em;padding:2px 2px 2px}
.clh:empty{display:none}
.cli{display:flex;align-items:flex-start;gap:4px}
.clc{flex:0 0 auto;position:relative;width:38px;height:38px;border:0;background:none;padding:0;cursor:pointer;color:#fff}
.clc::before{content:'';position:absolute;left:8px;top:8px;width:18px;height:18px;border-radius:50%;border:2px solid var(--mute)}
.clc svg{position:absolute;left:11px;top:11px;width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:3.2;stroke-linecap:round;stroke-linejoin:round;opacity:0}
.cli.dn .clc::before{background:var(--td);border-color:var(--td)}
.cli.dn .clc svg{opacity:1}
.clt{flex:1 1 auto;min-width:0;border:0;background:none;resize:none;outline:none;font:inherit;font-size:16px;line-height:1.35;color:var(--ink);padding:8px 2px;overflow:hidden}
.cli.dn .clt{text-decoration:line-through;color:var(--mute)}
.clx{flex:0 0 auto;width:34px;height:38px;color:var(--mute)}
.clx svg{width:17px;height:17px}
.cla{display:flex;align-items:center;gap:4px;border-top:1px dashed var(--line);margin-top:2px}
.cla .clp{flex:0 0 38px;text-align:center;font-size:22px;line-height:1;color:var(--mute)}
.sheet .cla input[type=text]{flex:1 1 auto;width:auto;min-width:0;border:0;background:none;border-radius:0;font:400 16px var(--f);color:var(--ink);padding:10px 2px}
.cla input::placeholder{color:var(--mute)}
.pick{position:fixed;left:16px;right:16px;bottom:calc(16px + env(safe-area-inset-bottom,0px));z-index:23;max-width:528px;margin:0 auto;background:var(--bg);border-radius:22px;padding:8px;box-shadow:var(--shadow);border:1px solid var(--line);animation:up .18s ease}
.pick h3{margin:6px 12px 2px;font-size:12px;color:var(--mute);font-weight:600;text-transform:uppercase;letter-spacing:.06em}
.popt{display:flex;align-items:center;gap:14px;width:100%;min-height:44px;padding:4px 12px;border:0;background:none;border-radius:14px;font:500 16px var(--f);text-align:left;cursor:pointer;position:relative}
.popt:active,.popt.sel{background:var(--surface)}
.popt svg{flex:none;width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.popt .r{margin-left:auto;color:var(--mute);font-size:13px}
.popt input[type=date]{position:absolute;inset:0;opacity:0;width:100%;height:100%;font-size:16px}
.pick input[type=text],.pick input[type=password]{width:100%;font:500 17px var(--f);color:var(--ink);background:var(--surface);border:0;border-radius:12px;padding:14px;outline:0;margin:4px 0 8px}
.menu{position:fixed;right:max(16px,calc(50% - 264px));top:calc(44px + env(safe-area-inset-top,0px));z-index:22;min-width:240px;background:var(--bg);border:1px solid var(--line);border-radius:18px;padding:6px;box-shadow:var(--shadow);animation:up .15s ease}
.toast{position:fixed;left:16px;right:16px;bottom:calc(82px + env(safe-area-inset-bottom,0px));max-width:528px;margin:0 auto;z-index:19;background:var(--ink);color:var(--bg);border-radius:12px;padding:2px 2px 2px 16px;display:flex;align-items:center;gap:10px;font-size:14px;font-weight:500}
.toast span{flex:1;min-width:0}
.toast button{font:700 14px var(--f);color:var(--accent);background:none;border:0;padding:0 14px;min-height:40px;cursor:pointer}
button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid var(--accent);outline-offset:2px}
@media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}
````
<!-- END FILE -->

### `planerka/fonts/Inter-cyrillic.woff2` — двоичный файл, 18748 байт (base64)

<!-- FILE planerka/fonts/Inter-cyrillic.woff2 base64 sha256=71d5ee93cc1e9f1d520a3a8b66456de18c7879d8df09d57fcd2eaff75fef0075 -->
````text
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
RhckF71o4FnwWw0UJoci8TvzGOJTd/HmiksAAA==
````
<!-- END FILE -->

### `planerka/fonts/Inter-latin.woff2` — двоичный файл, 48256 байт (base64)

<!-- FILE planerka/fonts/Inter-latin.woff2 base64 sha256=3100e775e8616cd2611beecfa23a4263d7037586789b43f035236a2e6fbd4c62 -->
````text
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
h8q/32PH77H8z9qVLwAAAA==
````
<!-- END FILE -->

### `planerka/fonts/OFL.txt` — лицензия шрифта Inter (92 строк)

<!-- FILE planerka/fonts/OFL.txt text sha256=262481e844521b326f5ecd053e59b98c8b2da78c8ee1bdbb6e8174305e54935a -->
````text
Copyright (c) 2016 The Inter Project Authors (https://github.com/rsms/inter)

This Font Software is licensed under the SIL Open Font License, Version 1.1.
This license is copied below, and is also available with a FAQ at:
http://scripts.sil.org/OFL

-----------------------------------------------------------
SIL OPEN FONT LICENSE Version 1.1 - 26 February 2007
-----------------------------------------------------------

PREAMBLE
The goals of the Open Font License (OFL) are to stimulate worldwide
development of collaborative font projects, to support the font creation
efforts of academic and linguistic communities, and to provide a free and
open framework in which fonts may be shared and improved in partnership
with others.

The OFL allows the licensed fonts to be used, studied, modified and
redistributed freely as long as they are not sold by themselves. The
fonts, including any derivative works, can be bundled, embedded,
redistributed and/or sold with any software provided that any reserved
names are not used by derivative works. The fonts and derivatives,
however, cannot be released under any other type of license. The
requirement for fonts to remain under this license does not apply
to any document created using the fonts or their derivatives.

DEFINITIONS
"Font Software" refers to the set of files released by the Copyright
Holder(s) under this license and clearly marked as such. This may
include source files, build scripts and documentation.

"Reserved Font Name" refers to any names specified as such after the
copyright statement(s).

"Original Version" refers to the collection of Font Software components as
distributed by the Copyright Holder(s).

"Modified Version" refers to any derivative made by adding to, deleting,
or substituting -- in part or in whole -- any of the components of the
Original Version, by changing formats or by porting the Font Software to a
new environment.

"Author" refers to any designer, engineer, programmer, technical
writer or other person who contributed to the Font Software.

PERMISSION AND CONDITIONS
Permission is hereby granted, free of charge, to any person obtaining
a copy of the Font Software, to use, study, copy, merge, embed, modify,
redistribute, and sell modified and unmodified copies of the Font
Software, subject to the following conditions:

1) Neither the Font Software nor any of its individual components,
in Original or Modified Versions, may be sold by itself.

2) Original or Modified Versions of the Font Software may be bundled,
redistributed and/or sold with any software, provided that each copy
contains the above copyright notice and this license. These can be
included either as stand-alone text files, human-readable headers or
in the appropriate machine-readable metadata fields within text or
binary files as long as those fields can be easily viewed by the user.

3) No Modified Version of the Font Software may use the Reserved Font
Name(s) unless explicit written permission is granted by the corresponding
Copyright Holder. This restriction only applies to the primary font name as
presented to the users.

4) The name(s) of the Copyright Holder(s) or the Author(s) of the Font
Software shall not be used to promote, endorse or advertise any
Modified Version, except to acknowledge the contribution(s) of the
Copyright Holder(s) and the Author(s) or with their explicit written
permission.

5) The Font Software, modified or unmodified, in part or in whole,
must be distributed entirely under this license, and must not be
distributed under any other license. The requirement for fonts to
remain under this license does not apply to any document created
using the Font Software.

TERMINATION
This license becomes null and void if any of the above conditions are
not met.

DISCLAIMER
THE FONT SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO ANY WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT
OF COPYRIGHT, PATENT, TRADEMARK, OR OTHER RIGHT. IN NO EVENT SHALL THE
COPYRIGHT HOLDER BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
INCLUDING ANY GENERAL, SPECIAL, INDIRECT, INCIDENTAL, OR CONSEQUENTIAL
DAMAGES, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
FROM, OUT OF THE USE OR INABILITY TO USE THE FONT SOFTWARE OR FROM
OTHER DEALINGS IN THE FONT SOFTWARE.
````
<!-- END FILE -->

### `planerka/assets/icon-192.png` — двоичный файл, 18732 байт (base64)

<!-- FILE planerka/assets/icon-192.png base64 sha256=6d03700708173640db870f9ab6b8c98258914e39f1fff6ea6383134bd74262fe -->
````text
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
AAAAAElFTkSuQmCC
````
<!-- END FILE -->

### `planerka/assets/icon-512.png` — двоичный файл, 113512 байт (base64)

<!-- FILE planerka/assets/icon-512.png base64 sha256=e7d01f0526c98667daf7897df33b347234a4544ffc95dba41bd2b715500b336b -->
````text
iVBORw0KGgoAAAANSUhEUgAAAgAAAAIACAIAAAB7GkOtAAEAAElEQVR42uz9ebxt2VUWDI9nrr3PuU3d6lKVhiah+VBafRU/fREVBZv3s/kUERRFGiEEsIlC
8hIhkIRGGiF0EekCISAKCIgiAtJL34nSJKFNqlKpVHNv3f6ec/be8/n+WGvO8Yw5177J6yd1uZU1fxir6p67z95rrzXnGM94GpC0ZS1rWcta1tveSsslWNay
lrWs5QBY1rKWtaxlLQfAspa1rGUtazkAlrWsZS1rWcsBsKxlLWtZy1oOgGUta1nLWtZyACxrWcta1rKWA2BZy1rWspa1HADLWtaylrWs5QBY1rKWtaxlLQfA
spa1rGUtazkAlrWsZS1rWcsBsKxlLWtZy1oOgGUta1nLWtZyACxrWcta1rKWA2BZy1rWspa1HADLWtaylrWs5QBY1rKWtaxlLQfAspa1rGUtazkAlrWsZS1r
WcsBsKxlLWtZy1oOgGUta1nLWtZyACxrWcta1rKWA2BZy1rWspYDYFnLWtaylrUcAMta1rKWtazlAFjWspa1rGUtB8CylrWsZS1rOQCWtaxlLWtZywGwrGUt
a1nLWg6AZS1rWcta1nIALGtZy1rWspYDYFnLWtaylrUcAMta1rKWtazlAFjWspa1rGUtB8CylrWsZS1rOQCWtaxlLWtZywGwrGUta1nLWg6AZS1rWcta1nIA
LGtZy1rWspYDYFnLWtaylrUcAMta1rKWtazlAFjWspa1rGUtB8CylrWsZS1rOQCWtaxlLWtZywGwrGUta1nLWg6AZS1rWctaDoBlLWtZy1rWcgAsa1nLWtay
lgNgWcta1rKWtRwAy1rWspa1rOUAWNaylrWsZS0HwLKWtaxlLWs5AJa1rGUta1nLAbCsZS1rWctaDoBlLWtZy1rWcgAsa1nLWtaylgNgWcta1rKWtRwAy1rW
spa1rOUAWNaylrWsZS0HwLKWtaxlLevJWavlEtzCRRppmVwNaP7o4fP2m2+8/tD5zYXL2zc+tHvw0cPffOOlxy9tL1/dXL+eN5udbWkZRjMzgxnH/y++Dmhm
xvof/adR/73+t30LKD9HQzKYGab/pcFIQF4L438vK4d3Mn7m6R9gyOV3w4wGmMGymXF6P2R4f/QfL++fZskwXsryG2lAKu+C5bOiXI3Zzwtj9v8KTteTMMt+
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
vwFRyFm68SS6XQAAAABJRU5ErkJggg==
````
<!-- END FILE -->

### `planerka/assets/badge.png` — двоичный файл, 355 байт (base64)

<!-- FILE planerka/assets/badge.png base64 sha256=7ab4367956f05265fa30399ea2662eb8b0700d865a74e6cfadfac6037c6f47d7 -->
````text
iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAYAAADimHc4AAABKklEQVR42u3d0Y0DIRAEUWhd/injFPxhbhh4lYDlqW2QWG0zBgAAAIDXmLt/YK212g9pztlK
wA1D/y8Z0+BrRUyDrxURw6/9/zH8Wgkx/FoJMfxaCTH8Wgkx/FoJMa5a4umvTYEEdEiAp39fCiSgyx6AIgGWn73LkAQU8zcueKvU+W2eBNiECQABBIAAAkAA
ASBgOIq4+QjgtCMRCSCAABBAAAggAAQQAAIIAAEEgAACQAABIIAAEEAACCAABBAAAggAAQSAAAJAAAEggACMze3pSpv2fZMmAZYgAq5su+pyuYMEdFmCpGDP
B+ES0GkTloLfzykv1E+efKtSXukAPbUbNS8VsZ5YTJvX2nBPq8DJi5XEJ/UPuUvyhrsk3abqPmHN7wAAAMD3fAAfhXCXLrjuGAAAAABJRU5ErkJggg==
````
<!-- END FILE -->

### `planerka/assets/favicon-64.png` — двоичный файл, 4599 байт (base64)

<!-- FILE planerka/assets/favicon-64.png base64 sha256=14e732dc21d1b5b0759183e7284d542ca5cd3cffabdbb711a3eaaa6cbfdafb76 -->
````text
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
AElFTkSuQmCC
````
<!-- END FILE -->

### `planerka/assets/apple-touch-icon.png` — двоичный файл, 14954 байт (base64)

<!-- FILE planerka/assets/apple-touch-icon.png base64 sha256=a4cbe2d473216eca4a45a3e8ad810549e632325fae41bc98cd82f3afe887deff -->
````text
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
zZPmii9u+MCX122Y6iPb0jrHLa1zvIBs1rHHoLicWUisnQVWzh0WUXCs84BHlUvIOd5lkXrRwI8wm2DnUMaML3qKwlwhBem0yhBVa1FTSd9eu5mTX7fkLa8c
zaAB9VjaxWNvHLnmqoXWTERrx9uf+Mamz14z8Ye1ut1msk1WqWJFZDI0VVQwhYwYc8y0gJxQr7rfgtEj26diyJS5CoGOfZWQEejc+4hgeA9elTPwApjPt7tZ
S8QNUvW+mt1/BY4+bMnrDhnpq/NC9OK71DjKJpIaXPerqau+v/nHv53+0zqkVhmjCCmhXbERIE4LqOOCAYlkc6EKwq5zwuBA4RhxsumgiJJsLgEUqVtJIaYY
4e2kW0awRJo4IdZJjeqqve+Kxt8/c/iVzx05cN9+mas9XsfjYxxlEyGiuSZuuWv2pjumf/1/m/eumbxvzez4DDdTr0YJMQRfSJqyn5RCmFG6u2KRc26KrlsQ
NAQEF7de/br7cLsY+5oG+eg3x7GIOU4mvBYFmEgr21/TO4/xsl3791zWf+C+/U9/yuDeKxpRCv/4Ho+ncfSOLj9U7xL0jp5x9I6ecfSOnnH0jp5x9I6ecfSO
nnH0jp5x9I6ecfSOnnH0jp5x9I7e0TOO3tEzjt7RM47e0TOO3tEzjt7RM47e0TOO3tEzjt7RM47e0TOO3tE7esbRO3rG0Tt6xtE7esbROx7L4/8B5UsCZz5I
/lAAAAAASUVORK5CYII=
````
<!-- END FILE -->

### `planerka/assets/mark-96.png` — двоичный файл, 9588 байт (base64)

<!-- FILE planerka/assets/mark-96.png base64 sha256=d5ee7cbdab1ca1ac1f5f471d610c33329c99435482c18d7679816aeb45c5032a -->
````text
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
````
<!-- END FILE -->

### `planerka/assets/og.png` — двоичный файл, 196095 байт (base64)

<!-- FILE planerka/assets/og.png base64 sha256=0c7e398ccfcd9d52cac662091f0ab4f93f384c55721c0362de11676deea04644 -->
````text
iVBORw0KGgoAAAANSUhEUgAABLAAAAJ2CAIAAADAIuwLAAEAAElEQVR42uz9abRtWXoVBn5znXvvexGREZGtpEQIEEIFBoZsMMbYGMzANKYTwxhjFQW2KdMJ
CoxtZGynhLDoZECF5ZJMX4AMhcBCGBlUCIQAAxLCYFlGDU1KSilTUnYRGf1795571lc/9lrfmt+31j73pcpj1Ki434SRevnevefss/c+O9dcc35zotYqiUQi
kUgkEolEIpG4fyh5ChKJRCKRSCQSiUQiCWEikUgkEolEIpFIJJIQJhKJRCKRSCQSiUQiCWEikUgkEolEIpFIJJIQJhKJRCKRSCQSiUQiCWEikUgkEolEIpFI
JJIQJhKJRCKRSCQSiUQiCWEikUgkEolEIpFIJJIQJhKJRCKRSCQSiUQiCWEikUgkEolEIpFIJJIQJhKJRCKRSCQSiUQiCWEikUgkEolEIpFIJJIQJhKJRCKR
SCQSiUQiCWEikUgkEolEIpFIJJIQJhKJRCKRSCQSiUQiCWEikUgkEolEIpFIJJIQJhKJRCKRSCQSiUQiCWEikUgkEolEIpFIJJIQJhKJRCKRSCQSiUQiCWEi
kUgkEolEIpFIJJIQJhKJRCKRSCQSiUQiCWEikUgkEolEIpFIJJIQJhKJRCKRSCQSiUQiCWEikUgkEolEIpFIJCFMJBKJRCKRSCQSiUQSwkQikUgkEolEIpFI
JCFMJBKJRCKRSCQSiUQSwkQikUgkEolEIpFIJCFMJBKJRCKRSCQSiUQSwkQikUgkEolEIpFIJCFMJBKJRCKRSCQSiUQSwkQikUgkEolEIpFIJCFMJBKJRCKR
SCQSiUQSwkQikUgkEolEIpFIJCFMJBKJRCKRSCQSiUQSwkQikUgkEolEIpFIJCFMJBKJRCKRSCQSiUQSwkQikUgkEolEIpFIJCFMJBKJRCKRSCQSiUQSwkQi
kUgkEolEIpFIJCFMJBKJRCKRSCQSiUQSwkQikUgkEolEIpFIJCFMJBKJRCKRSCQSiUQSwkQikUgkEolEIpFIJCFMJBKJRCKRSCQSiUQSwkQikUgkEolEIpFI
JCFMJBKJRCKRSCQSiSSEiUQikUgkEolEIpFIQphIJBKJRCKRSCQSiSSEiUQikUgkEolEIpFIQphIJBKJRCKRSCQSiSSEiUQikUgkEolEIpFIQphIJBKJRCKR
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
1UOYC6/GQj/MBThKySkHwZJR5DlAg5sgwM0jNP1PGb0WNoHuG3VMMsWpO6SxldEoFjV732ot68Y5LmMnsAFK3GzJRX8p/1r2nbWCBkuVKlWqFBSGsFSpUqVK
lSpVqlSpUqVK/Y8vKpegVKlSpUqVKlWqVKlSpQogLFWqVKlSpUqVKlWqVKlSBRCWKlWqVKlSpUqVKlWqVKkCCEuVKlWqVKlSpUqVKlWqVAGEpUqVKlWqVKlS
pUqVKlWqAMJSpUqVKlWqVKlSpUqVKlUAYalSpUqVKlWqVKlSpUqVKoCwVKlSpUqVKlWqVKlSpUoVQFiqVKlSpUqVKlWqVKlSpQogLFWqVKlSpUqVKlWqVKlS
BRCWKlWqVKlSpUqVKlWqVKkCCEuVKlWqVKlSpUqVKlWqVAGEpUqVKlWqVKlSpUqVKlWqAMJSpUqVKlWqVKlSpUqVKlUAYalSpUqVKlWqVKlSpUqVKoCwVKlS
pUqVKlWqVKlSpUoVQFiqVKlSpUqVKlWqVKlSpQogLFWqVKlSpUqVKlWqVKlSBRCWKlWqVKlSpUqVKlWqVKkCCEuVKlWqVKlSpUqVKlWqVAGEpUqVKlWqVKlS
pUr9f9qvYwEAAACAQf7Wo9hXFgFCCAAAgBACAAAghAAAAEIIAACAEAIAACCEAAAACCEAAABCCAAAgBACAAAghAAAAAghAAAAQggAAIAQAgAAIIQAAAAIIQAA
AEIIAACAEAIAACCEAAAACCEAAABCCAAAgBACAAAghAAAAAghAAAAQggAAIAQAgAAIIQAAABCCAAAgBACAAAghAAAAAghAAAAQggAAIAQAgAAIIQAAAAIIQAA
AEIIAACAEAIAACCEAAAACCEAAABCCAAAgBACAAAghAAAAAghAAAAQggAAIAQAgAAIIQAAAAIIQAAAEIIAACAEAIAACCEAAAACCEAAIAQAgAAIIQAAAAIIQAA
AEIIAACAEAIAACCEAAAACCEAAABCCAAAgBACAAAghAAAAAghAAAAQggAAIAQAgAAIIQAAADcAgbPxnbEC3mEAAAAAElFTkSuQmCC
````
<!-- END FILE -->
