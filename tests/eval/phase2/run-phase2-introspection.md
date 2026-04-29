# Eval run phase2-introspection

- Total cases: 10
- Pass: 5
- Fail: 5
- Pass rate: 50.0%
- DSL limitation rate: 30.0%

## Failures by category

| Category | Count |
|---|---|
| should_have_refused | 2 |
| dsl_limitation | 3 |

## Cases

| ID | Outcome | Category | Duration (ms) |
|---|---|---|---|
| crime_houston_violent_total | pass |  | 7576 |
| foreclosure_california_total | pass |  | 5945 |
| asthma_california_adults | pass |  | 13076 |
| crime_homicides_chicago_synonym | pass |  | 10529 |
| refusal_no_matching_dataset | fail | should_have_refused | 8943 |
| refusal_write_request | fail | should_have_refused | 2972 |
| varicella_out_of_coverage | pass |  | 12124 |
| dsl_yoy_varicella | fail | dsl_limitation | 16734 |
| dsl_above_avg_crime | fail | dsl_limitation | 11321 |
| dsl_percentile_gold | fail | dsl_limitation | 14456 |
