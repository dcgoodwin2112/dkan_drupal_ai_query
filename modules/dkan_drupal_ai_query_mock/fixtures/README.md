# Parks Fixture

A 30-park × 7-year (2018-2024) snapshot of U.S. National Park Service annual
recreation visits, used by the shipped mock scenarios.

## Files

| File | Purpose |
|---|---|
| `files/national_parks_visitation.csv` | The data. 210 rows. |
| `fixture.template.json` | DKAN data.json catalog with a `<!*path*!>` placeholder for the absolute file path. |
| `fixture.json` | Generated at install time by `FixtureLoader::install()`. Not in git. |
| `harvest_plan.json` | Local-URI harvest plan. `extract.uri` is rewritten to absolute at install time. |

## Install

```bash
ddev drush dkan-aiq-mock:fixture:install
```

Registers the harvest plan, runs the harvest, and drains
`localize_import` / `datastore_import` / `post_import` queues synchronously.
On success the resolved `{identifier}__{version}` resource id is printed and
written to state key `dkan_drupal_ai_query_mock.fixture_resource_id`.

```bash
ddev drush dkan-aiq-mock:fixture:remove   # tear down
ddev drush dkan-aiq-mock:fixture:status   # check
```

## Columns

| Column | Type | Notes |
|---|---|---|
| `park_name` | string | Common name; no "National Park" suffix. |
| `state` | string | Two-letter postal code. Parks spanning multiple states list the headquarters state. |
| `region` | string | One of: Eastern, Mountain West, West Coast, Southwest, Alaska & Pacific. |
| `year` | integer | 2018-2024. |
| `recreation_visits` | integer | Annual recreation visits (NPS IRMA definition). |
| `area_acres` | integer | Park area in acres. Stable across years. |

## Provenance and license

Visitation counts are based on publicly published U.S. National Park Service
[IRMA Visitor Use Statistics](https://irma.nps.gov/STATS/). Area figures match
each park's NPS-published acreage. U.S. Government works are public domain
under 17 U.S.C. § 105. The fixture is published under
[CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/).

Some 2024 figures and a small number of 2020-2022 figures for low-volume parks
are approximations that preserve known year-over-year shape. The fixture is for
UI testing, not for citation.

## Resource id stability

The DKAN resource version is a Unix timestamp set at first import, so the full
`{identifier}__{version}` is **install-specific**. Scripted scenarios reference
`${FIXTURE_RESOURCE_ID}` instead of the literal id; the mock provider
substitutes the value from state key
`dkan_drupal_ai_query_mock.fixture_resource_id` at chat-call time, written by
`FixtureLoader::install()`.

## Regenerating

The CSV is hand-curated. Edit it, re-run `dkan-aiq-mock:fixture:remove` then
`:install`, and update any scripted final answers in `scenarios/*.yml` whose
specific numbers reference the row you changed.
