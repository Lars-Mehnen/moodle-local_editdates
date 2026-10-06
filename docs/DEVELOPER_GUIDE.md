# Developer guide — local_editdates

For programmers who install, modify, extend or embed this plugin. If you only want
to *call* the API, read `API_GUIDE.md` instead.

---

## 1. What the plugin is, in one paragraph

`report_editdates` (the OU "Dates" report) already knows, per module type, which
database fields are dates, what to call them, how to validate them and how to save
them — but it is a browser form with no external interface. `local_editdates`
reuses those extractors and exposes them as three web service functions, adds the
section access-restriction dates the report can only link out to, and wraps the
whole thing in a plan/apply model with a dry run, per-record validation and an
audit event.

Requirements: Moodle **5.2** (`$plugin->requires = 2026042001.00`), PHP 8.3,
`report_editdates` (a declared plugin dependency — Moodle refuses to install
without it).

---

## 2. Install and development loop

### 2.1 Install

```bash
# Moodle 5.1+ layout: plugin code lives under public/
cp -r local/editdates /path/to/moodle/public/local/editdates
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

Then, as administrator: enable web services and REST, authorise the consuming user
for the service `local_editdates_ws`, and create a token.

### 2.2 Development loop against a Docker Moodle

```bash
docker cp local/editdates nomad_moodle:/var/www/html/public/local/editdates
docker exec -u 0 nomad_moodle sh -c 'chown -R nobody:nobody /var/www/html/public/local/editdates; \
  find /var/www/html/public/local/editdates -type d -exec chmod 755 {} + ; \
  find /var/www/html/public/local/editdates -type f -exec chmod 644 {} +'
docker exec nomad_moodle php /var/www/html/admin/cli/purge_caches.php
```

- `docker exec` runs as `nobody` in this image; anything touching ownership needs
  `-u 0`.
- While iterating, copying `classes/` plus a cache purge is enough. Bumping
  `$plugin->version` requires `admin/cli/upgrade.php` as well.
- CLI scripts stay **outside** `public/`: `/var/www/html/admin/cli/…`, while
  `$CFG->dirroot` points at `/var/www/html/public`.

### 2.3 Lint

```bash
php -l local/editdates/classes/local/date_writer.php
find local/editdates -name '*.php' -exec php -l {} \; | grep -v 'No syntax errors'
```

Moodle code style is checked with `moodle-plugin-ci` / `phpcs` using Moodle's
`phpcs.xml.dist` if you have it wired up; the code here is written to that style
(short array syntax, 132 char lines, one-line-per-property PHPDoc, `defined(…)||die`).

---

## 3. Code map

```
classes/
  external/
    get_course_dates.php     read function: parameters, capability check, return schema
    set_course_dates.php     write function: per-date updates
    shift_dates.php          write function: offset over a scope
    structures.php           shared external_* return structures
    guard.php                batch/offset limits + the audit event
  local/
    bridge.php               the only door to report_editdates
    availability.php         read/patch date conditions in an availability tree
    date_collector.php       the read model
    date_writer.php          plan() / apply() / plan_shift()
    change_report.php        status counts for the response envelope
  event/dates_updated.php    logged on every applied write
  privacy/provider.php       null provider
db/{services,access}.php     function + service definitions, capabilities
lang/{en,de}/                strings, including reason_<code> messages
settings.php                 maxupdates, maxshiftdays
tests/                       PHPUnit suite
docs/                        API_GUIDE.md, DEVELOPER_GUIDE.md, AI_DATES_API.md
```

### Request flow

```
external/*::execute()
    validate_parameters()            typed parameters
    validate_context() + require_capability()
    guard::check_offset/check_batch_size()
        date_writer::plan()          resolve → check editability → validate
            date_collector::collect()    current state + editable flags
            bridge::settings()           labels, current values (report_editdates)
            extractor->validate_dates()  the module's own cross-field rules
        date_writer::apply()         only when dryrun=0
            update_course() / formatactions::section()->update() / extractor->save_dates()
            commit → rebuild_course_cache() → course_module_calendar_event_update_process()
        guard::log_write()           \local_editdates\event\dates_updated
    change_report::summary()
```

A dry run is a plan that is never applied. That is deliberate: the preview a client
inspects comes from the same code that performs the write, so the two cannot drift
apart.

---

## 4. The addressing model

```
course  / 0      / startdate | enddate
section / <id>   / availablefrom | availableuntil
module  / <cmid> / <module date keys> | completionexpected | availablefrom | availableuntil
```

Rules baked into the code, worth keeping if you extend it:

- The read model is the single source of truth for what exists and what is
  writable. `date_writer::plan()` resolves every request against it, so an address
  that a read did not report can never be written.
- `0` means "not set". It is only accepted where the date reports
  `optional: true` (`notoptional` otherwise).
- `target: course` accepts id `0` or the course id; `plan()` normalises it to `0`.
- `sectionnums` in `shift_dates` are section *positions*; `id` for a section
  target is the section *id*. Two different numbers, both in the read response.

---

## 5. Using the plugin from PHP (no REST)

The internal classes are usable from any Moodle context: another plugin, a
scheduled task, an `admin/cli` script. Capability checks live in the external
layer, so **a direct caller is responsible for its own access control**.

```php
<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');

use local_editdates\local\date_collector;
use local_editdates\local\date_writer;

$course = get_course(216);
$context = context_course::instance($course->id);
require_capability('local/editdates:edit', $context);   // your responsibility

// 1. Read.
$model = (new date_collector($course))->collect('quiz');

// 2. Plan: push every quiz close date to 23:59 local time.
$updates = [];
foreach ($model['activities'] as $activity) {
    foreach ($activity['dates'] as $date) {
        if ($date['key'] !== 'timeclose' || $date['value'] === 0 || !$date['editable']) {
            continue;
        }
        $updates[] = [
            'target' => date_collector::TARGET_MODULE,
            'id' => $activity['cmid'],
            'key' => 'timeclose',
            'value' => strtotime('today 23:59', $date['value']),
        ];
    }
}

$writer = new date_writer($course);
$plan = $writer->plan($updates);

// 3. Inspect, then apply.
foreach ($plan as $change) {
    if ($change['status'] === date_writer::STATUS_ERROR) {
        mtrace("rejected {$change['key']} on {$change['id']}: {$change['code']} {$change['message']}");
    }
}
$applied = $writer->apply($plan);
mtrace(json_encode(\local_editdates\local\change_report::summary($applied)));
```

`plan_shift()` is the same thing for an offset:

```php
$plan = $writer->plan_shift(
    offsetseconds: WEEKSECS,
    sectionnums: [3, 4],
    activitytypes: ['quiz', 'assign'],
    includecourse: false,
    includeavailability: true,
    preservetimeofday: true
);
```

The availability patcher is standalone and side-effect free, which makes it handy
on its own:

```php
use local_editdates\local\availability;

$state = availability::read($cm->availability);
// ['from' => int, 'until' => int, 'editable' => bool, 'reason' => string]

if ($state['editable']) {
    $json = availability::write($cm->availability, $state['from'] + WEEKSECS, $state['until']);
    // $json may be null: that means "no restriction left at all".
}
```

`availability::write()` throws a `coding_exception` when the tree is not writable —
check `read()['editable']` first, the same way `date_writer` does.

---

## 6. Extending

### 6.1 Support the dates of another activity module

You do not extend this plugin for that; you extend `report_editdates`, and both the
report and this API pick it up. Two routes:

**Preferred — ship the integration in your own module.** `report_editdates` looks
for a class named `mod_<yourmod>_report_editdates_integration`, autoloaded from
your module, before it looks at its own `mod/<yourmod>dates.php`:

```php
// mod/yourmod/classes/... or anywhere autoloadable under the mod_yourmod component.
class mod_yourmod_report_editdates_integration
        extends report_editdates_mod_date_extractor {

    public function __construct($course) {
        parent::__construct($course, 'yourmod');   // second argument = table name
        parent::load_data();                        // fills $this->mods, keyed by instance id
    }

    public function get_settings(cm_info $cm) {
        $instance = $this->mods[$cm->instance];
        return [
            'timeopen' => new report_editdates_date_setting(
                get_string('timeopen', 'mod_yourmod'),
                $instance->timeopen,
                self::DATETIME,   // or self::DATE when the time of day is irrelevant
                true              // optional: may be 0
            ),
        ];
    }

    public function validate_dates(cm_info $cm, array $dates) {
        // Return ['fieldkey' => 'message'] for anything inconsistent.
        return [];
    }

    public function save_dates(cm_info $cm, array $dates) {
        parent::save_dates($cm, $dates);            // writes the columns
        // Then refresh whatever your module derives from them, e.g. its events.
    }
}
```

**Alternative** — add `mod/<name>dates.php` to `report_editdates` itself, defining
`report_editdates_mod_<name>_date_extractor`. Fine for a local fork, upstreamable
for a public module.

Once the extractor exists, no change is needed here: `bridge::settings()` finds it,
`date_collector` reports its keys, `date_writer` validates and saves through it.

Three contracts to respect, learned from the shipped extractors:

1. **`get_settings()` keys may be conditional.** A forum only exposes
   `assesstimestart`/`assesstimefinish` when ratings are enabled; Turnitin exposes
   `duedate<partid>` per part. Never assume a fixed key set — including in your own
   client code.
2. **`validate_dates()` and `save_dates()` receive the complete merged set.**
   `date_writer` merges current values with the requested ones before calling them,
   because the assign extractor reads every key directly and a partial array is a
   PHP warning plus a wrong write.
3. `report_editdates_mod_date_extractor::make()` caches instances **statically per
   module name**, and each instance caches the module rows it loaded at
   construction. Read current values before you save, not after.

### 6.2 Add a new web service function

1. Class in `classes/external/`, extending `core_external\external_api`, with
   `execute_parameters()`, `execute()`, `execute_returns()`.
2. Inside `execute()`: `validate_parameters()`, fetch the course,
   `self::validate_context()`, `require_capability()` — view, plus `:edit` for a
   write — then the guard calls.
3. Register it in `db/services.php` under `$functions` *and* in the
   `local_editdates_ws` function list.
4. Reuse `structures::date()` / `structures::change()` / `structures::write_result()`
   so the response shape stays uniform.
5. Bump `$plugin->version`, add a `CHANGES.md` entry, extend `API_GUIDE.md`.
6. New strings go into **both** `lang/en` and `lang/de`.

Every field you return must be declared in `execute_returns()`. The fastest way to
catch a mismatch is to run the payload through `clean_returnvalue()` (see §7.2) —
that is exactly what the REST layer does.

### 6.3 Add a rejection reason

Reasons are stable machine codes. Use one in `date_collector` (as a `reason`) or in
`date_writer::reject()` (as a `code`), then add `reason_<code>` to `lang/en` and
`lang/de`. `date_writer::message_for()` resolves it; a missing string degrades to
the bare code rather than throwing. Document it in the `API_GUIDE.md` table.

### 6.4 Deliberate gaps

Not implemented, in rough order of how often it will be missed:

- **Per-user / per-group overrides and assignment extensions.** These live in
  `quiz_overrides`, `assign_overrides`, `assign_user_flags`. `report_editdates`
  ignores them too. Shifting a course therefore leaves individual exceptions
  behind. Implementing this means new addressing (`override/<id>`), new
  capabilities (`mod/quiz:manageoverrides`) and its own guard rails.
- **Block dates.** `report_editdates` supports block date extractors
  (`report_editdates_block_date_extractor`, see its `blocks/example.php`), and this
  API does not expose them. A `target: block` would slot into the same model:
  collect from the extractor, write through it, capability
  `moodle/site:manageblocks`.
- **Non-date availability conditions.** By design: this plugin patches `date`
  conditions only and reports any tree whose meaning a single date edit would alter
  as read-only (`nesteddateavailability`, `notandavailability`,
  `duplicatedateavailability`).
- **Course-level date bulk tools** such as recomputing relative dates mode, or
  restore-style "shift by course start difference".

---

## 7. Testing

Three layers: database-free checks that run anywhere, a PHPUnit suite that needs a
Moodle test environment, and manual verification against a real instance.

### 7.0 Database-free checks

```bash
php tools/tests/pure_logic_test.php
```

No Moodle bootstrap, no database — the runner defines the handful of Moodle
constants the two classes touch and stubs `coding_exception`. It covers
`availability::read()`/`write()` and `date_writer::shifted()`, which is where a
regression would be silent: a lost `showc` flag or a literal offset across a clock
change produces plausible-looking data that is quietly wrong. 26 checks, `ok`/`FAIL`
per line, non-zero exit on failure, so it belongs in any pre-commit hook or CI job.

Keep it honest: after adding a check, break the code on purpose and confirm the
check fails. Both pieces above were mutation-tested that way (drop the `showc`
carry-over, force the literal branch → 5 failures).

Anything that needs `$DB`, `cm_info` or a capability belongs in the PHPUnit suite
instead — do not grow a fake Moodle here.

### 7.1 PHPUnit

`tests/` covers the availability patcher, the read model, the write planner
(validation, section restriction dates, daylight-saving behaviour) and the web
service layer, including the dry-run default and the capability split.

```bash
vendor/bin/phpunit --testsuite local_editdates_testsuite
vendor/bin/phpunit public/local/editdates/tests/date_writer_test.php
vendor/bin/phpunit --filter test_plan_shift_preserves_time_of_day
```

Setting the environment up from scratch (what was done in the development
container — note the `public/` path for the init script in the 5.1+ layout):

```bash
# 1. A database for the test tables, kept apart from the production one.
mariadb -u root -p"$ROOTPW" -e "CREATE DATABASE moodle_phpunit \
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
  GRANT ALL PRIVILEGES ON moodle_phpunit.* TO 'moodle'@'%'; FLUSH PRIVILEGES;"

# 2. config.php, before the require_once of lib/setup.php.
#    $CFG->phpunit_prefix   = 'phpu_';
#    $CFG->phpunit_dataroot = '/var/www/phpunitdata';
#    $CFG->phpunit_dbname   = 'moodle_phpunit';
#    phpunit_dbtype/dbhost/dbuser/dbpass exist too; unset ones fall back to production.

# 3. Dev dependencies (phpunit, vfsstream, behat) and the test site.
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist
php public/admin/tool/phpunit/cli/init.php        # ~8 min, creates ~590 phpu_ tables
```

Re-run `init.php` after installing a plugin or changing a `db/install.xml`.

Runtimes to expect, because they shape how you work: the whole suite is about
12 minutes (`availability_test` under a second, the three database-backed files
2–4 minutes each), so run a single file or `--filter` while iterating.

The daylight-saving test pins the timezone with
`$this->setTimezone('Europe/Vienna', 'Europe/Vienna')`, because the whole point is
the difference between calendar-day and literal-second arithmetic. Anything else
in the suite is timezone independent.

> **Status:** 32 tests, 145 assertions, all passing on Moodle 5.2.2 / PHP 8.3.15 /
> MariaDB 11.4.12 with PHPUnit 11.5.55.
>
> **Before you install dev dependencies into a container, check what its entrypoint
> does on boot.** The `erseco/alpine-moodle` image runs `composer install --no-dev`
> at every start, as an unprivileged user. Dev packages installed as root cannot be
> removed by it, the entrypoint aborts, and the container restart-loops with the
> site down — which is what happened here two days after the setup, on the next host
> reboot. Install them as the web user instead
> (`docker exec -u 65534 -e COMPOSER_HOME=/tmp/composer …`), expect them to be gone
> after every restart, and keep a tarball of the working `vendor/` so the boot can be
> repaired with `docker cp` alone.
>
> In the development container the environment is **not persistent**: `config.php`
> is generated by the entrypoint and `vendor/` lives in the container's writable
> layer, so a container recreate removes both the settings and the dev
> dependencies. The test *database* is on a bind mount and survives; after a
> recreate, redo steps 2 and 3 above.

### 7.2 Manual verification against a real Moodle

The pattern that found every bug during development — bootstrap Moodle, act as
admin, call the external class, and push the result through `clean_returnvalue()`:

```bash
docker exec -i nomad_moodle php <<'PHP'
<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
global $DB;
\core\session\manager::set_user($DB->get_record('user', ['username' => 'admin']));

$result = \local_editdates\external\get_course_dates::execute(216);
$clean = \core_external\external_api::clean_returnvalue(
    \local_editdates\external\get_course_dates::execute_returns(), $result);
print_r($clean['sections']);
PHP
```

Checks worth repeating after any change to the write path:

| What | How to see it |
|---|---|
| dry run writes nothing | compare the module column before and after |
| module dates | `SELECT duedate FROM mdl_assign WHERE id = …` |
| expected completion | `mdl_course_modules.completionexpected` |
| section dates | `mdl_course_sections.availability` — foreign conditions and `showc` must survive |
| calendar | `mdl_event.timestart` for `due`, `open`, `close`, `expectcompletionon` |
| audit | `mdl_logstore_standard_log` where `eventname` is `\local_editdates\event\dates_updated` |
| read-only token | a write must fail with `errorcode: nopermissions` |

A useful fixture is a section carrying **both** a date condition and a
profile/group condition: that is the regression case for `availability::write()`.

### 7.3 REST

`tools/editdates_client.php` (see `API_GUIDE.md` §1.3) exercises the full stack
with a token, which is the only way to test what a real consumer sees — including
that omitting `dryrun` does not write.

---

## 8. Things that will bite you

- **`public/` layout.** Plugin code under `public/`, CLI scripts outside it.
  `$CFG->dirroot` already points inside `public/`, so `$CFG->dirroot . '/report/editdates/lib.php'`
  is correct and `… . '/public/report/…'` is not.
- **Cache and calendar after commit, not inside the transaction.**
  `rebuild_course_cache()` inside an uncommitted transaction can cache data a
  rollback throws away. `date_writer::apply()` commits first, then rebuilds, then
  refreshes calendar events.
- **`formatactions::section()->update()` rebuilds the cache itself** and fires
  `course_section_updated`. That is why section writes go through it rather than a
  raw `set_field`.
- **`update_course()` tolerates a partial object** but runs `course_validate_dates()`
  on what it receives, so always pass both `startdate` and `enddate` merged with the
  current values.
- **Extractor statics.** See §6.1 point 3.
- **Fixtures and expectations must share a timezone.** Moodle's PHPUnit default is
  `Australia/Perth`. A fixture built in `setUp()` and an expectation written after a
  later `setTimezone()` call are then six or seven hours apart, which looks exactly
  like a shift bug — it cost one red test here. Call `setTimezone()` *before*
  creating anything whose timestamps you will assert on.
- **Whole-day offsets are calendar arithmetic.** `strtotime('+7 days')`, not
  `+ 604800`, whenever `preservetimeofday` is on. On `Europe/Vienna`,
  `2026-10-19 23:59` + 604800 s is `22:59` the next week.
- **Payload size.** 260-activity courses are normal; use the `activitytype` filter
  and do not call the read function per date.
- **The boot-time plugin installer only installs what is missing.** It skips any
  plugin directory that already exists, so dropping a newer zip into the staging
  directory never upgrades an installed plugin — sync the code and run
  `admin/cli/upgrade.php` for that. A `docker start` does re-resolve the bind mount,
  so edits to the installer script take effect on the next container start.

---

## 9. Release and packaging

`$plugin->version` is `YYYYMMDDXX`; keep `$plugin->release` semantic
(`0.1.0`) and move `$plugin->maturity` up only when the outstanding verification in
`CHANGES.md` is actually done.

Package for Moodle's plugin installer, or for a staging directory that unpacks by
frankenstyle name:

```bash
(cd local && zip -qr /tmp/local_editdates.zip editdates -x '*/.*')
```

The archive must contain a single top-level folder named after the part behind the
underscore (`editdates/`), which is what both Moodle's installer and the
`016-install-plugins.sh` boot script in this environment expect.

Per release: `CHANGES.md` entry, version bump, EN+DE strings complete, docs updated
in the same commit as the behaviour they describe — in particular the "what the API
will not do" list, which is what an automated consumer relies on.
