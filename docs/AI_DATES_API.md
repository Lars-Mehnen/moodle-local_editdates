# Course dates API for automated consumers (0.1.0)

Three REST functions in the service `local_editdates_ws`:

| Function | Type | Capability | Default |
|---|---|---|---|
| `local_editdates_get_course_dates` | read | `local/editdates:view` | — |
| `local_editdates_set_course_dates` | write | `local/editdates:view` + `local/editdates:edit` | **dry run** |
| `local_editdates_shift_dates` | write | `local/editdates:view` + `local/editdates:edit` | **dry run** |

Both write functions preview by default. A caller that does not send `dryrun=0`
never changes anything, and the preview has the same shape as the applied result,
so a consumer can show a diff, get approval, and repeat the call with `dryrun=0`.

`local/editdates:view` is installed with `clonepermissionsfrom` of
`report/editdates:view`, so whoever may open the Dates report may read this API.
`local/editdates:edit` is granted to `manager` only by default; a write also needs
the ordinary Moodle capability for the item: `moodle/course:update` for course and
section dates, `moodle/course:manageactivities` for an activity.

## Addressing a date

Every date has the same address: `target` (`course`, `section` or `module`), `id`
(`0` for the course, the section id, or the course module id) and `key`. The read
function reports exactly the addresses the write functions accept — a consumer
should never construct a key it has not seen in a read response.

```
course / 0     / startdate, enddate
section/ <id>  / availablefrom, availableuntil
module / <cmid>/ <module date keys>, completionexpected, availablefrom, availableuntil
```

The module date keys come from `report_editdates`, so they are the column names of
the activity: `duedate`, `cutoffdate`, `allowsubmissionsfromdate`, `gradingduedate`
for an assignment, `timeopen`/`timeclose` for a quiz, and so on for the 23 module
types it covers plus any module shipping its own
`mod_x_report_editdates_integration` class. `label` gives every date a
`label` string in the response language; use `key` for automation and `label`
only for display.

`value` is a Unix timestamp. `0` means "not set"; it is accepted on write only
when `optional` is true. `editable` says whether this API can write the date;
when it is false, `reason` gives a stable code (see the table below).

## Reading

```bash
curl --fail --silent --show-error 'https://moodle.example/webservice/rest/server.php' \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode 'wsfunction=local_editdates_get_course_dates' \
  --data-urlencode 'moodlewsrestformat=json' \
  --data-urlencode "courseid=$COURSEID"
```

Optional `activitytype=quiz` restricts the activity list to one module type, which
matters on real courses: a 260-activity course produces a large payload.
`includeinvisible=1` adds items the caller cannot see on the course page.

The envelope carries `servertime` (the reference for anything relative),
`timezone` (the zone the site displays dates in — build any wall-clock time such
as "Monday 08:00" in that zone, never in the consumer's own),
`enableavailability`, `enablecompletion`, the `course` block, `sections`,
`activities` and `warnings`. `hasotherrestrictions` on a section or activity means
it also has access restrictions that are not dates; this API never touches those,
so a date change alone may not make the item accessible.

## Writing single dates

```bash
curl --fail --silent --show-error 'https://moodle.example/webservice/rest/server.php' \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode 'wsfunction=local_editdates_set_course_dates' \
  --data-urlencode 'moodlewsrestformat=json' \
  --data-urlencode "courseid=$COURSEID" \
  --data-urlencode 'updates[0][target]=module' \
  --data-urlencode 'updates[0][id]=30798' \
  --data-urlencode 'updates[0][key]=timeclose' \
  --data-urlencode 'updates[0][value]=1800000000' \
  --data-urlencode 'dryrun=0'
```

The response reports one record per requested date:

```json
{"courseid": 216, "dryrun": false,
 "summary": {"planned": 0, "applied": 1, "unchanged": 0, "error": 0},
 "changes": [{"target": "module", "id": 30798, "key": "timeclose",
              "label": "Close the quiz", "name": "Scratch quiz",
              "oldvalue": 1794873540, "newvalue": 1800000000,
              "status": "applied", "code": "", "message": ""}]}
```

`status` is `planned` (dry run), `applied`, `unchanged` (the request matches the
current value) or `error`. Validation happens before anything is written: if one
date of a request is rejected, that record carries the reason and the rest of the
batch is still applied, so a consumer must read `summary` and not assume success
from the HTTP status.

Cross-field validation is delegated to the activity: an assignment due date before
its "allow submissions from" date comes back as `code=modulevalidation` with the
module's own message. Send the whole intended window in one call — the merged
result is validated, not the single field.

## Shifting a whole course

```bash
curl ... --data-urlencode 'wsfunction=local_editdates_shift_dates' \
         --data-urlencode "courseid=$COURSEID" \
         --data-urlencode 'offsetseconds=604800' \
         --data-urlencode 'includecourse=1' \
         --data-urlencode 'dryrun=1'
```

Scope parameters: `sectionnums[]`, `activitytypes[]`, `includecourse` (course start
and end date, default off), `includeavailability` (section and activity restriction
dates, default on).

Dates that are switched off stay off: shifting a `0` would invent a restriction the
course does not have. Section restriction dates are included only when no
`activitytypes[]` filter is given: narrowing a shift to quizzes shifts quizzes, not
the sections around them.

`preservetimeofday` defaults to `1`: an offset that is a whole number of days is
applied as calendar days, so 23:59 stays 23:59 when the shift crosses a
daylight-saving change. With `preservetimeofday=0`, or for any offset that is not a
whole number of days, the seconds are added literally and a European week in late
October moves 23:59 to 22:59. Both are correct; pick deliberately.

## Reason and error codes

| Code | Meaning |
|---|---|
| `nopermission` | The caller lacks the Moodle capability for that item. |
| `generalsection` | Section 0 cannot carry access-restriction dates. |
| `availabilitydisabled` | Restricted access is off site-wide. |
| `unreadableavailability` | The availability JSON cannot be parsed. |
| `nesteddateavailability` | A date sits inside a grouped restriction; edit it in the UI. |
| `notandavailability` | Restrictions are combined with "or"; a single date change would alter their meaning. |
| `duplicatedateavailability` | Two restriction dates in the same direction; the target is ambiguous. |
| `unknowndate` | No such target/key in this course. |
| `invalidvalue` | Negative timestamp. |
| `noteditable` | Not writable for a reason with no more specific code. |
| `notoptional` | The date is required and cannot be set to 0. |
| `enddatebeforestartdate` | Course end would precede course start. |
| `nostartdate` | The course start date cannot be removed. |
| `fromafteruntil` | The "from" date would be at or after the "until" date. |
| `modulevalidation` | The activity rejected the combination; `message` is the module's text. |

Whole calls fail (not single records) with a `moodle_exception` when a guard trips:
an offset beyond `maxshiftdays` (default 366), or a batch beyond `maxupdates`
(default 500). Both are site settings under *Site administration → Plugins → Local
plugins → Course dates API*.

## What this API does not do

- It does not touch per-user or per-group overrides, assignment extensions, or
  user flags. After a shift, individual exceptions keep their old dates and may
  now sit outside the activity window — a consumer must not report a shift as
  "the course moved for everyone".
- It does not touch non-date access restrictions, group or grouping conditions,
  completion rules other than the expected date, grade item settings, or anything
  in the calendar beyond the events Moodle derives from the dates it writes.
- It does not create, delete, hide or move activities and sections.
- In a course using relative dates mode, the dates shown to students are computed
  from enrolment; changing the stored dates does not change that mapping.
- In the `weeks` format, section headings are derived from the course start date,
  so shifting `course/startdate` renames the week headings as a side effect.

## Operational notes

Writes run in one transaction; the course cache is rebuilt afterwards and the
calendar events of every touched activity are refreshed through
`course_module_calendar_event_update_process()`, including expected-completion
events. An applied write triggers `\local_editdates\event\dates_updated` with the
function name and the number of applied and rejected records, so an automated
consumer is visible in the standard Moodle log.

The plugin depends on `report_editdates` for its per-module date definitions and
validation. It adds section restriction dates, which the Dates report only links
out to, and it refreshes calendar events for modules whose extractor does not.
