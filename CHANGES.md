# 0.1.2 (2026091602)

- `local_editdates_get_course_dates` now also returns each activity's `idnumber`.
  In a course taught by several lecturers, the position of an activity says
  nothing about who owns it; the ID number is the only marker that is machine
  readable, invisible to students and survives backup and restore. A client can
  select "my" activities by it instead of by index.
- `tools/lib/ics.php` exposes `DESCRIPTION` and `CATEGORIES`, and
  `tools/quiz_from_ics.php` gained repeatable `--match FIELD:REGEX` /
  `--exclude FIELD:REGEX` plus `--idnumbers` / `--activities`. Real timetables put
  the lecturer in `DESCRIPTION` while `SUMMARY` is identical for all of them, so
  filtering on the title alone silently mixes two lecturers' dates.

## Operational note (2026-09-18)

Installing composer dev dependencies (PHPUnit, Behat) into the Moodle container as
root breaks its next boot: the entrypoint runs `composer install --no-dev` as an
unprivileged user, fails to delete the root-owned packages and aborts, leaving the
container in a restart loop. Repaired by restoring the prod
`vendor/composer/installed.json` through `docker cp` on the stopped container. The
plugin, its web service, the tokens and every scheduled date were unaffected —
they live in the database and in `public/local/editdates`, not in `vendor/`.

## Tooling removed (2026-09-18)

`tools/quiz_weeks.sh`, the plain weekday scheduler from 0.1.1, was deleted by the
owner together with the timetable export it was demonstrated on. The two ICS-driven
schedulers cover the job and handle the irregular reality of a timetable; the
changelog entry below is kept as the record of what existed.

## Tooling added alongside 0.1.2

- `tools/quiz_from_ics.sh`: the Bash counterpart of the PHP scheduler, for exports
  where every lecture is its own `VEVENT`. It refuses to run on an ICS containing
  `RRULE` rather than guess what "the next lecture" is.
- `tools/tests/parity_test.sh`: runs both schedulers over one fixture and compares
  their output verbatim — two implementations of one rule drift, and a drift here
  is silent. It found two real bugs in the shell version on its first run: the
  fallback window used `date -d "@epoch + 7 days"`, which GNU date rejects, and
  then `date -d "12:05:00 + 7 days"`, where GNU date reads the "+7" as a UTC
  offset. Both are the trap already documented for `quiz_weeks.sh`: do calendar
  arithmetic on a bare date and apply the time of day afterwards.
- It also found a display divergence: for an ICS with only UTC events the two
  tools named the timezone differently. Both now use the first named zone among
  the selected sessions, falling back to UTC.

# 0.1.1 (2026091601)

- `local_editdates_get_course_dates` now also returns `timezone`, the site
  timezone. A client that builds a wall-clock schedule ("every Monday 08:00")
  needs it: the stored timestamps are shown to users in that zone, and guessing
  the client's own zone instead is silently wrong by whole hours.
- Add `tools/quiz_weeks.sh`, a Bash client that lays out consecutive weekly
  open/close windows over the activities of a course. Dry run by default.
- Add `tools/quiz_from_ics.php` plus `tools/lib/ics.php`: derive the windows from
  the lecture dates of a timetable instead of from a weekday rule. The timetable
  is then the holiday list — a break week has no session, so the window around it
  stretches by itself. Handles RRULE expansion, EXDATE (how timetables encode
  holidays), RECURRENCE-ID overrides, TZID/UTC/all-day values and folded lines;
  covered by `tools/tests/ics_test.php` (18 checks, passing).

# 0.1.0 (2026091600)

First release.

- Read function `local_editdates_get_course_dates`: course start/end, activity
  dates from the `report_editdates` extractors, expected completion, and the
  date conditions of section and activity access restrictions. Each date carries a
  stable `target`/`id`/`key` address, an `editable` flag and a reason code.
- Write function `local_editdates_set_course_dates`: set individual dates by
  address. Defaults to a dry run; validation is delegated to the module and
  reported per record.
- Write function `local_editdates_shift_dates`: move every date in scope by an
  offset, with section and activity-type scoping. Defaults to a dry run. Whole-day
  offsets keep the local time of day across daylight-saving changes.
- Section access-restriction dates are patched inside the availability tree:
  foreign conditions and their display flags are preserved, and trees whose
  meaning would change (grouped dates, "or" operators, duplicate directions) are
  reported read-only instead of rewritten.
- Writes run in one transaction; afterwards the course cache is rebuilt and the
  calendar and expected-completion events of touched activities are refreshed
  through `course_module_calendar_event_update_process()`.
- Separate `local/editdates:edit` capability (manager only by default) on top of
  `local/editdates:view`, plus the per-item core capability check.
- Site settings `maxupdates` (500) and `maxshiftdays` (366) as guards against
  oversized automated requests; an applied write triggers
  `\local_editdates\event\dates_updated`.
- EN/DE strings, null privacy provider, PHPUnit tests, and a database-free
  standalone check runner (`tools/tests/pure_logic_test.php`).

## Verification status of this build

Verified by hand on Moodle 5.2.2 (2026042002), PHP 8.3.15, MariaDB 11.4, against a
scratch course with an assignment, a quiz and a section carrying both a date and a
user-profile restriction:

- read model, including locked section 0 and a mixed availability tree;
- dry run leaves the database untouched;
- applied writes reach `assign`, `quiz`, `course_modules.completionexpected`,
  `course_sections.availability` and `course`;
- the profile condition and its `showc` flag survive a section date change;
- calendar events (`due`, `gradingdue`, `open`, `close`, `expectcompletionon`)
  follow the new dates;
- rejections: module validation, general section, course end before start,
  unknown key, zero offset, offset guard, batch guard;
- the same calls through the REST endpoint with a token, including the dry-run
  default when `dryrun` is omitted;
- `\local_editdates\event\dates_updated` appears in `logstore_standard_log`.

The database-free runner `tools/tests/pure_logic_test.php` **passes** (26 checks)
and was mutation-checked: dropping the `showc` carry-over in `availability::write()`
or forcing the literal branch in `date_writer::shifted()` makes it fail.

The PHPUnit suite in `tests/` **passes**: 32 tests, 145 assertions, on Moodle 5.2.2
/ PHP 8.3.15 / MariaDB 11.4.12 with PHPUnit 11.5.55, in a test environment with its
own database (`moodle_phpunit`, prefix `phpu_`) and dataroot. One red test during
that first run was a fixture bug, not a plugin bug: the shift expectation was
written in `Europe/Vienna` while the fixture had been created in PHPUnit's default
`Australia/Perth`. The timezone is now pinned in `setUp()`.

Installation on a clean site, upgrade from a previous version and load testing on a
production-sized course are still outstanding, which is why this build is ALPHA.
