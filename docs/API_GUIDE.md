# Using the Course dates API

How to read and change the dates of a Moodle course over REST with
`local_editdates`. Written for whoever builds the client: a deployment script, a
term-rollover job, a dashboard, an AI agent.

- Working on the plugin itself instead? See `DEVELOPER_GUIDE.md`.
- Building an autonomous consumer? Also read `AI_DATES_API.md`, which states the
  limits an agent must not talk past.

---

## 1. Quick start

### 1.1 One-time setup in Moodle

As administrator:

1. *Site administration → Advanced features* → enable **Web services**.
2. *Site administration → Server → Web services → Manage protocols* → enable **REST**.
3. Create the user the client will act as (a dedicated account is better than a
   personal one), and give it a role in the courses it may touch.
4. *Manage services* → **Course dates API** (`local_editdates_ws`) → *Authorised
   users* → add that user.
5. *Manage tokens* → create a token for user + service. Optionally restrict it to
   the client's IP and set a validity date.

Capabilities the user needs:

| Capability | For | Default roles |
|---|---|---|
| `local/editdates:view` | every call | the roles that may use the Dates report |
| `local/editdates:edit` | writing | `manager` |
| `moodle/course:update` | writing course and section dates | `editingteacher`, `manager` |
| `moodle/course:manageactivities` | writing activity dates | `editingteacher`, `manager` |

A token whose user has `:view` but not `:edit` is a **read-only** token. That is
the right default for anything experimental: no bug or retry loop in your client
can then move a deadline.

### 1.2 First call

```bash
export MOODLE=http://192.168.2.124:8300
export TOKEN=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

curl --fail --silent --show-error "$MOODLE/webservice/rest/server.php" \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode 'wsfunction=local_editdates_get_course_dates' \
  --data-urlencode 'moodlewsrestformat=json' \
  --data-urlencode 'courseid=49' | jq '.activities[0]'
```

Conventions that apply to every call: **POST** (a token in a URL ends up in logs),
`moodlewsrestformat=json`, and one `wsfunction` per request. Moodle's REST endpoint
answers **HTTP 200 even for errors** — see §6.

### 1.3 The bundled client

`tools/editdates_client.php` in this repository speaks the API without any
dependencies (it falls back to PHP streams when curl is missing):

```bash
export EDITDATES_URL=http://192.168.2.124:8300
export EDITDATES_TOKEN=$TOKEN

php tools/editdates_client.php get 49                 # readable tree
php tools/editdates_client.php get 49 quiz            # quizzes only
php tools/editdates_client.php get 49 --json          # raw JSON

php tools/editdates_client.php set 49 module 3251 duedate "2026-11-30 23:59"
php tools/editdates_client.php set 49 module 3251 duedate "2026-11-30 23:59" --apply

php tools/editdates_client.php shift 49 7d --course            # preview
php tools/editdates_client.php shift 49 -2w --sections=3,4 --apply
php tools/editdates_client.php raw local_editdates_get_course_dates courseid=49
```

It exits non-zero when any record was rejected, so it drops straight into CI.

Both task-shaped clients derive the windows from a timetable rather than from a
weekday rule, because a real timetable is rarely a clean weekly grid: they read
`timezone` from the API, so the times they write are the times Moodle shows.

```bash
tools/quiz_from_ics.php --ics timetable.ics --filter 'pProg VO' --list      # inspect
tools/quiz_from_ics.php --ics timetable.ics --filter 'pProg VO' --course 211
tools/quiz_from_ics.php --ics timetable.ics --filter 'pProg VO' --course 211 --apply
```

Quiz *i* opens when lecture *i* ends and closes shortly before lecture *i+1*
begins (`--anchor`, `--close-before`, `--span`, `--last-days` change the rule).
See §7.3 for why that handles holidays without a holiday list.

`tools/quiz_from_ics.sh` is the same thing in Bash, with the same options, for
people who would rather read a shell script. It handles exports where every
lecture is its own `VEVENT` — which is what many university timetables produce —
and **refuses to run** when it finds a repeating event, because expanding `RRULE`
and honouring `EXDATE` is what the PHP version is for:

```
error: timetable.ics contains repeating events (RRULE).
       Use tools/quiz_from_ics.php, which expands RRULE and EXDATE.
```

Both produce the same plan for the same input; that equality is the acceptance
test for the Bash version.

---

## 2. How dates are addressed

Every date in the API has one address — the triple **`target` / `id` / `key`**:

```
course  / 0      / startdate | enddate
section / <id>   / availablefrom | availableuntil
module  / <cmid> / <module date keys> | completionexpected | availablefrom | availableuntil
```

The read function reports exactly the addresses the write functions accept.
**Read before you write, and never construct a key you have not seen in a
response** — the available keys depend on the activity's own configuration (a
forum only exposes `assesstimestart`/`assesstimefinish` when ratings are on, a
Turnitin assignment exposes `duedate<partid>` per part).

Where the ids come from: `sectionid` and `cmid` are in the read response. `cmid` is
also the `id` in Moodle's own `core_course_get_contents`, and the `id=` in a
`/mod/*/view.php?id=…` URL.

Values are **Unix timestamps**. `0` means "not set"; it is accepted on write only
where the date reports `"optional": true`, and it is the way to *remove* a date.

### Date sources

| `source` | Stored in | Notes |
|---|---|---|
| `course` | `course.startdate` / `enddate` | `startdate` is not optional |
| `module` | the activity's own table | keys and validation come from the activity |
| `completion` | `course_modules.completionexpected` | only when the course tracks completion |
| `availability` | availability JSON of the section or activity | the "Restrict access → Date" conditions |

---

## 3. Reading: `local_editdates_get_course_dates`

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `courseid` | int | required | Course to read |
| `activitytype` | string | `''` | Restrict activities to one module name (`quiz`, `assign`, …) |
| `includeinvisible` | bool | `0` | Include items hidden from the calling user |

Response:

```jsonc
{
  "courseid": 49, "shortname": "PAS", "fullname": "…",
  "format": "topics",
  "servertime": 1789543200,          // reference point for anything relative
  "timezone": "Europe/Vienna",       // zone the site shows dates in; build wall-clock times here
  "enableavailability": true,
  "enablecompletion": false,
  "course":  { "canedit": true, "dates": [ /* date objects */ ] },
  "sections": [{
     "sectionid": 3488, "sectionnum": 2, "name": "Week 2",
     "visible": true, "canedit": true,
     "hasotherrestrictions": true,    // also restricted by something that is not a date
     "dates": [ /* availablefrom, availableuntil */ ]
  }],
  "activities": [{
     "cmid": 30798, "modname": "quiz", "instance": 1860, "name": "Midterm",
     "idnumber": "MVA-01",           // ownership marker, empty when unset
     "sectionnum": 2, "visible": true, "canedit": true,
     "hasotherrestrictions": false,
     "dates": [ /* date objects */ ]
  }],
  "warnings": [ { "item": "module:30799", "message": "…" } ]
}
```

A date object:

```jsonc
{
  "key": "timeclose",            // use this for automation
  "label": "Close the quiz",     // response language, for display only
  "value": 1794873540,           // 0 = not set
  "source": "module",
  "type": "datetime",            // "date" when the time of day is not used
  "optional": true,              // may be set to 0
  "editable": true,              // this API can write it
  "reason": ""                   // why not, when editable is false (see §6)
}
```

**Use `timezone` for anything wall-clock.** Stored dates are timestamps, but a user
sees them in the site timezone (or their own, when they set one). "Every Monday at
08:00" therefore has to be computed in `timezone`, not in the zone your client
happens to run in — otherwise the schedule is silently off by whole hours, and the
error is invisible in the API response because the timestamps are accepted either
way.

`hasotherrestrictions` matters when you report results to a human: the item may
still be closed by a group, grade or profile condition that this API never touches,
so "the date is open now" does not mean "students can get in".

### Payload size

Real courses are big: course 142 on this instance yields 276 dated activities.
Filter with `activitytype` when you know what you want, and cache the read for the
duration of one operation rather than per date.

---

## 4. Writing single dates: `local_editdates_set_course_dates`

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `courseid` | int | required | Course to change |
| `updates[n][target]` | string | required | `course`, `section` or `module` |
| `updates[n][id]` | int | required | `0`, section id, or cmid |
| `updates[n][key]` | string | required | Date key from a read response |
| `updates[n][value]` | int | required | New timestamp, or `0` to switch off |
| `dryrun` | bool | **`1`** | Preview only; pass `0` to write |

```bash
curl --fail --silent --show-error "$MOODLE/webservice/rest/server.php" \
  --data-urlencode "wstoken=$TOKEN" \
  --data-urlencode 'wsfunction=local_editdates_set_course_dates' \
  --data-urlencode 'moodlewsrestformat=json' \
  --data-urlencode 'courseid=216' \
  --data-urlencode 'updates[0][target]=module' \
  --data-urlencode 'updates[0][id]=30798' \
  --data-urlencode 'updates[0][key]=timeopen' \
  --data-urlencode 'updates[0][value]=1794211200' \
  --data-urlencode 'updates[1][target]=module' \
  --data-urlencode 'updates[1][id]=30798' \
  --data-urlencode 'updates[1][key]=timeclose' \
  --data-urlencode 'updates[1][value]=1794873540' \
  --data-urlencode 'dryrun=0'
```

Response (same shape for both write functions):

```jsonc
{
  "courseid": 216,
  "dryrun": false,
  "summary": { "planned": 0, "applied": 2, "unchanged": 0, "error": 0 },
  "changes": [{
      "target": "module", "id": 30798, "key": "timeclose",
      "label": "Close the quiz", "name": "Midterm",
      "oldvalue": 1794873540, "newvalue": 1800000000,
      "status": "applied",     // planned | applied | unchanged | error
      "code": "", "message": ""
  }]
}
```

Three rules worth building into your client:

1. **Send a whole window in one call.** Validation runs on the merged result, so
   moving an assignment's `duedate` and `allowsubmissionsfromdate` together
   succeeds where two separate calls would trip the activity's own rule.
2. **Read `summary`, not the HTTP status.** Records are validated individually;
   a rejected one carries `code` and `message` while the rest of the batch is
   still applied.
3. **`unchanged` is not an error.** It means the stored value already equals your
   request — useful for idempotent jobs.

---

## 5. Shifting a course: `local_editdates_shift_dates`

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `courseid` | int | required | Course to shift |
| `offsetseconds` | int | required | Signed offset; `604800` is a week later |
| `sectionnums[n]` | int | `[]` | Restrict to these section *numbers* |
| `activitytypes[n]` | string | `[]` | Restrict to these module names |
| `includecourse` | bool | `0` | Also move course start and end date |
| `includeavailability` | bool | `1` | Also move restriction dates |
| `preservetimeofday` | bool | `1` | Whole-day offsets keep the local time of day |
| `dryrun` | bool | **`1`** | Preview only; pass `0` to write |

```bash
curl ... --data-urlencode 'wsfunction=local_editdates_shift_dates' \
         --data-urlencode 'courseid=216' \
         --data-urlencode 'offsetseconds=604800' \
         --data-urlencode 'includecourse=1' \
         --data-urlencode 'dryrun=1'
```

Semantics to know:

- Dates that are **off stay off**. Shifting a `0` would invent a restriction.
- Section restriction dates are included only when **no** `activitytypes[]` filter
  is given: narrowing a shift to quizzes shifts quizzes, not the sections
  around them.
- `sectionnums[]` uses the position (`sectionnum`), while `set_course_dates` uses
  the section **id**. They are different numbers; both appear in the read response.
- **Daylight saving.** With `preservetimeofday=1` (default) a whole-day offset is
  applied as calendar days, so a deadline at 23:59 stays at 23:59 across the
  clock change. With `0`, or for any offset that is not a whole number of days,
  the seconds are added literally and that same deadline becomes 22:59. Verified
  on `Europe/Vienna`: `2026-10-19 23:59` + 604800 s = `2026-10-26 22:59`, while
  +7 calendar days = `2026-10-26 23:59`.
- Guards: the call is refused outright when `|offsetseconds|` exceeds
  `maxshiftdays` (default 366) or the plan exceeds `maxupdates` (default 500)
  dates. Both are site settings under *Plugins → Local plugins → Course dates API*.

---

## 6. Errors

### Whole-call failures

Moodle REST answers **HTTP 200** and a body with `exception`:

```json
{"exception":"core\\exception\\moodle_exception",
 "errorcode":"erroroffsettoolarge",
 "message":"The requested offset of 463 days exceeds the configured maximum of 366 days."}
```

Branch on `errorcode`, never on the message text. Codes you can hit:

| `errorcode` | Cause |
|---|---|
| `erroroffsettoolarge` | Offset beyond `maxshiftdays` |
| `erroroffsetzero` | `offsetseconds=0` |
| `errortoomanyupdates` | Batch beyond `maxupdates` |
| `invalidrecordunknown` | No such course |
| `nopermissions` | Token user lacks a required capability |
| `invalidtoken` | Wrong, deleted or expired token |
| `accessexception` | User not authorised for `local_editdates_ws` |
| `invalidparameter` | A parameter failed its type check |

A read-only token produces exactly this on any write, which is worth testing once
when you set the integration up:

```json
{"exception":"core\\exception\\required_capability_exception",
 "errorcode":"nopermissions",
 "message":"Sorry, but you do not currently have permissions to do that (Change course dates through the dates API)."}
```

### Per-record rejections

`status: "error"` with a stable `code` and a human `message`:

| Code | Meaning | What to do |
|---|---|---|
| `unknowndate` | No such target/key in this course | re-read; do not guess keys |
| `notoptional` | Required date cannot be set to `0` | send a real timestamp |
| `invalidvalue` | Negative timestamp | fix the client arithmetic |
| `nopermission` | Token user lacks the core capability for that item | fix the role, or skip the item |
| `modulevalidation` | The activity refused the combination; `message` is its own text | send the whole window at once |
| `enddatebeforestartdate` | Course end would precede course start | shift both together |
| `nostartdate` | Course start cannot be removed | — |
| `fromafteruntil` | Restriction window would be inverted | swap the two values |
| `generalsection` | Section 0 cannot hold restriction dates | use a numbered section |
| `availabilitydisabled` | Restricted access is off site-wide | an admin must enable it |
| `nesteddateavailability` | The date sits inside a grouped restriction | edit it in the Moodle UI |
| `notandavailability` | Restrictions are combined with "or" | edit it in the Moodle UI |
| `duplicatedateavailability` | Two restriction dates in the same direction | clean it up in the Moodle UI |
| `unreadableavailability` | Availability JSON cannot be parsed | inspect that item by hand |

The last four appear as `reason` on a read too, with `editable: false`. They are
deliberate: this API refuses to rewrite an availability tree whose meaning a single
date change would alter.

---

## 7. Recipes

### 7.1 List every deadline of a course

```bash
php tools/editdates_client.php get 49 --json |
  jq -r '.activities[] | .name as $n | .dates[]
         | select(.value > 0 and (.key | test("due|close|cutoff")))
         | "\($n)\t\(.key)\t\(.value | strftime("%Y-%m-%d %H:%M"))"'
```

### 7.2 Building dates from a calendar rule

Two rules are worth copying into any client that computes dates itself, whichever
language it is written in:

- do the calendar arithmetic on **bare dates** and apply the time of day
  afterwards in the site timezone — adding 604800 seconds per week drifts by an
  hour across a daylight-saving change, and `date -d "08:00 +6 days"` makes
  GNU date read `+6` as a UTC offset;
- send both ends of a window in the same call, so the activity validates the pair.

### 7.3 Windows that follow a timetable (and holidays for free)

A weekday rule has to know about holidays; a timetable already does. If the
lecture does not take place in the break week, the timetable has no session
there, so the window around the gap simply stretches:

```
  3. Mon 19.10.2026 10:00-11:30  pProg VO
  4. Mon 02.11.2026 10:00-11:30  pProg VO   (+14 days since the previous one)

  Pre unit 3 quiz:  Mon 19.10. 10:30 -> Mon 02.11. 08:45   (two weeks, holiday inside)
```

Timetables encode a cancelled single date as `EXDATE` and a moved one as
`RECURRENCE-ID`; both change what "the next lecture" is, so a consumer must
expand recurrences properly rather than assume "every seven days".
`tools/lib/ics.php` does that and reports anything it cannot expand instead of
silently using only the first date.

### 7.4 A course with several lecturers

Two problems appear as soon as more than one person teaches a course, and both are
silent failures rather than errors.

**Which lecture is mine?** A timetable export of the whole course repeats the same
`SUMMARY` for every lecturer — only the room differs — and names the lecturer in
`DESCRIPTION`:

```
SUMMARY:MBDM-ILV  EDV_F1.01 - MMB-1
DESCRIPTION:MBDM-ILV\nMehnenLa\nMMB-1\nEDV_F1.01
```

Filtering on the title therefore mixes both lecturers, and because the two teach
back to back on some days, "close 15 minutes before the next lecture" then produces
windows that end before they start. Address the field explicitly instead:

```bash
tools/quiz_from_ics.php --ics timetable.ics --course 192 \
  --match description:MehnenLa \
  --match 'summary:MBDM-ILV +EDV' \
  --exclude 'summary:UE|EXAM' \
  --idnumbers '^MVA-'
```

**Which activities are mine?** Position in the course says nothing about ownership.
Use the activity **ID number** (*Activity settings → Common module settings → ID
number*): invisible to students, editable in the interface, and it survives backup
and restore. The read function reports it as `idnumber`, so a client can target
exactly the activities that carry the marker — and cannot touch a colleague's, even
if the course is reordered.

Give each lecturer their own token user; then every write also carries their name
in the Moodle log (`\local_editdates\event\dates_updated`).

### 7.5 Preview, show, then apply (the pattern for anything automated)

```bash
PREVIEW=$(php tools/editdates_client.php shift 216 7d --course)
echo "$PREVIEW"                       # let a human look at the diff
php tools/editdates_client.php shift 216 7d --course --apply
```

Over plain REST it is the same call twice, with `dryrun=1` then `dryrun=0`. Because
the preview is produced by the code that performs the write, the diff is the plan
rather than an estimate — but it is a *plan*, so re-run it if the course may have
changed in between.

### 7.6 Open a section for a date window

```bash
curl ... --data-urlencode 'wsfunction=local_editdates_set_course_dates' \
  --data-urlencode 'courseid=216' \
  --data-urlencode 'updates[0][target]=section' \
  --data-urlencode 'updates[0][id]=3488' \
  --data-urlencode 'updates[0][key]=availablefrom' \
  --data-urlencode 'updates[0][value]=1794096000' \
  --data-urlencode 'updates[1][target]=section' \
  --data-urlencode 'updates[1][id]=3488' \
  --data-urlencode 'updates[1][key]=availableuntil' \
  --data-urlencode 'updates[1][value]=1796688000' \
  --data-urlencode 'dryrun=0'
```

Any non-date restriction on that section (group, profile, grade) survives
unchanged, including whether it is displayed greyed out or hidden.

### 7.7 Remove a restriction date

Send `value=0`. When the last date condition of an item goes, the whole
restriction is removed if nothing else is left; other conditions stay.

### 7.8 Python

```python
import requests, datetime

MOODLE, TOKEN = "http://192.168.2.124:8300", "…"
ENDPOINT = f"{MOODLE}/webservice/rest/server.php"

def call(function, **params):
    payload = {"wstoken": TOKEN, "wsfunction": function, "moodlewsrestformat": "json"}
    payload.update(params)
    data = requests.post(ENDPOINT, data=payload, timeout=120).json()
    if isinstance(data, dict) and "exception" in data:
        raise RuntimeError(f"{data['errorcode']}: {data['message']}")
    return data

dates = call("local_editdates_get_course_dates", courseid=216, activitytype="quiz")
quiz = dates["activities"][0]
close = next(d for d in quiz["dates"] if d["key"] == "timeclose")

new = int(datetime.datetime.fromtimestamp(close["value"]).timestamp()) + 7 * 86400
result = call(
    "local_editdates_set_course_dates",
    courseid=216,
    dryrun=0,
    **{
        "updates[0][target]": "module",
        "updates[0][id]": quiz["cmid"],
        "updates[0][key]": "timeclose",
        "updates[0][value]": new,
    },
)
print(result["summary"])
for change in result["changes"]:
    if change["status"] == "error":
        print("rejected:", change["key"], change["code"], change["message"])
```

### 7.9 PHP, outside Moodle

```php
function call(string $function, array $params): array {
    $payload = http_build_query($params + [
        'wstoken' => getenv('TOKEN'),
        'wsfunction' => $function,
        'moodlewsrestformat' => 'json',
    ]);
    $body = file_get_contents(getenv('MOODLE') . '/webservice/rest/server.php', false,
        stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'ignore_errors' => true,
        ]]));
    $data = json_decode((string) $body, true);
    if (isset($data['exception'])) {
        throw new RuntimeException("{$data['errorcode']}: {$data['message']}");
    }
    return $data;
}

$plan = call('local_editdates_shift_dates', [
    'courseid' => 216, 'offsetseconds' => 604800, 'includecourse' => 1, 'dryrun' => 1,
]);
printf("%d dates would move\n", $plan['summary']['planned']);
```

`http_build_query()` produces the `updates[0][key]=…` form Moodle expects from a
nested PHP array, so you can pass `['updates' => [[...]]]` directly.

### 7.10 JavaScript / TypeScript

```ts
const call = async (fn: string, params: Record<string, string | number>) => {
  const body = new URLSearchParams({
    wstoken: TOKEN, wsfunction: fn, moodlewsrestformat: 'json',
    ...Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)])),
  });
  const data = await (await fetch(`${MOODLE}/webservice/rest/server.php`,
    { method: 'POST', body })).json();
  if (data?.exception) throw new Error(`${data.errorcode}: ${data.message}`);
  return data;
};

const preview = await call('local_editdates_shift_dates',
  { courseid: 216, offsetseconds: 604800, dryrun: 1 });
```

Inside Moodle itself, all three functions are `'ajax' => true`, so
`core/ajax`'s `call()` works from a plugin's AMD module with the session, no token.

---

## 8. What the API will not do for you

- **Per-user and per-group overrides, assignment extensions and user flags are not
  touched.** After a shift, individual exceptions keep their old dates and may end
  up outside the new window. Do not report a shift as "moved for everyone".
- Non-date access restrictions, group/grouping conditions, completion rules other
  than the expected date, and grade item settings are left alone.
- Nothing is created, deleted, hidden or moved — dates only.
- In a course using **relative dates mode**, students see dates computed from their
  enrolment; changing the stored dates does not change that mapping.
- In the **weeks** format, section headings derive from the course start date, so
  moving `course/startdate` renames the week headings as a side effect.
- Calendar events for the activities it writes *are* refreshed, including
  expected-completion events. Events you created by hand in the calendar are not.

---

## 9. Checklist for a production client

- [ ] Separate Moodle user for the integration, with the narrowest role that works.
- [ ] Read-only token unless the job really writes; token in a secret store, sent by POST.
- [ ] Always `dryrun=1` first for anything a human has not explicitly approved.
- [ ] Branch on `errorcode` and on per-record `code`, never on message text.
- [ ] Log the returned `changes` array — it is your audit trail alongside Moodle's
      own log entry (`\local_editdates\event\dates_updated`).
- [ ] Re-read after writing if a later decision depends on the new state.
- [ ] Tell users what was *not* changed: overrides, extensions, other restrictions.
