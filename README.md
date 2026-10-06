# Course dates API (local_editdates)

A web service API over the dates of a Moodle course, built so that an automated
consumer — a script or an AI agent — can read every date of a course, propose
changes, and apply them under review.

Version **0.1.0**, `MATURITY_ALPHA`. Target environment: Moodle 5.2 / PHP 8.3,
`$plugin->requires = 2026042001.00`.

## Why it exists

The Dates report (`report_editdates`) already knows, per module type, which fields
are dates, how to label them and how to validate them — but it is a browser form
with no external API. This plugin reuses those extractors and exposes them as REST
functions, and adds the section access-restriction dates that the report can only
link out to.

## What it exposes

| Function | Purpose |
|---|---|
| `local_editdates_get_course_dates` | Every date of a course with a stable address and an editable flag |
| `local_editdates_set_course_dates` | Set individual dates; dry run by default |
| `local_editdates_shift_dates` | Move all dates in scope by an offset; dry run by default |

Service shortname: `local_editdates_ws` (restricted to authorised users).

Covered: course start/end, all activity dates known to `report_editdates`,
expected completion, and the "available from"/"available until" date conditions of
sections and activities. Not covered: per-user and per-group overrides, assignment
extensions, non-date access restrictions — see `docs/AI_DATES_API.md`.

## Install

Requires `report_editdates` (declared as a plugin dependency).

Copy the `editdates` folder to `public/local/editdates` of a Moodle 5.2
installation, then run the administrator upgrade and purge caches:

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

Then, as administrator: enable web services and the REST protocol, authorise the
consuming user for the service `local_editdates_ws`, and create a token for it
(*Site administration → Server → Web services*).

## Access control

- `local/editdates:view` — read. Installed with `clonepermissionsfrom` of
  `report/editdates:view`, so the roles that may use the Dates report inherit it.
- `local/editdates:edit` — write. Granted to `manager` only by default.
- Every write additionally requires the ordinary Moodle capability for the item:
  `moodle/course:update` for course and section dates,
  `moodle/course:manageactivities` for an activity.

Give a read-only agent a token whose user has `:view` but not `:edit`; then no
prompt, bug or retry loop in the consumer can change a date.

## Safety model

- Both write functions default to `dryrun=1`. The preview is produced by the same
  code path that performs the write, so the diff is the plan, not an estimate.
- Each requested date is validated against the module's own rules before anything
  is written; rejections are reported per record with a stable code.
- `maxupdates` (default 500) and `maxshiftdays` (default 366) are site settings
  that stop oversized automated requests.
- An applied write triggers `\local_editdates\event\dates_updated`, so API writes
  appear in the standard Moodle log with the function name and the counts.

## Settings

*Site administration → Plugins → Local plugins → Course dates API*:
`maxupdates`, `maxshiftdays`.

## Tests

Two layers, because they need different things to run.

**Database-free checks — no Moodle, no database, runnable anywhere:**

```bash
php tools/tests/pure_logic_test.php      # 26 checks, exits non-zero on failure
```

They cover the logic where a bug is silent and expensive: patching date conditions
inside an availability tree (foreign conditions and their display flags must
survive) and the calendar-day versus literal arithmetic of a shift. **Executed and
passing**, and mutation-checked — deliberately breaking either piece makes them fail.

**PHPUnit suite — needs an initialised Moodle test environment:**

```bash
php public/admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_editdates_testsuite
```

`tests/` covers the read model, the write planner against real activities, and the
web service layer including the dry-run default and the capability split.

**Executed and passing** on Moodle 5.2.2 / PHP 8.3.15 / MariaDB 11.4.12 with
PHPUnit 11.5.55:

| File | Tests | Assertions |
|---|---|---|
| `availability_test.php` | 7 | 39 |
| `date_collector_test.php` | 5 | 29 |
| `date_writer_test.php` | 12 | 52 |
| `external_test.php` | 8 | 25 |
| **total** | **32** | **145** |

See `docs/DEVELOPER_GUIDE.md` §7.1 for how the test environment was set up.

## Tooling

Both scripts live in the repository root, not in the plugin, and need only
`EDITDATES_URL` and `EDITDATES_TOKEN`:

| Script | Purpose |
|---|---|
| `tools/editdates_client.php` | general client: read, set one date, shift a scope |
| `tools/quiz_from_ics.php` | align windows to the lecture dates of a timetable (ICS) |
| `tools/quiz_from_ics.sh` | the same in Bash, for exports without repeating events |
| `tools/tests/pure_logic_test.php` | database-free checks of the date logic |
| `tools/tests/ics_test.php` | database-free checks of the iCalendar reader |
| `tools/tests/parity_test.sh` | keeps the PHP and the Bash scheduler in step |
| `tools/phpunit_env.sh` | set up, run and tear down the PHPUnit environment safely |

## Documentation

| File | For | Contents |
|---|---|---|
| `docs/API_GUIDE.md` | client authors | setup, tokens, all parameters, recipes in curl/PHP/Python/JS, error handling |
| `docs/DEVELOPER_GUIDE.md` | plugin developers | architecture, internal PHP API, how to add a module type or a function, testing, pitfalls |
| `docs/AI_DATES_API.md` | autonomous consumers | the compact contract plus the limits an agent must not talk past |
