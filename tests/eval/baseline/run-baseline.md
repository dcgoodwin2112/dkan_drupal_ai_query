# Eval run baseline

- Total cases: 10
- Pass: 4
- Fail: 6
- Pass rate: 40.0%
- DSL limitation rate: 30.0%

## Failures by category

| Category | Count |
|---|---|
| should_have_refused | 3 |
| dsl_limitation | 3 |

## Cases

| ID | Outcome | Category | Duration (ms) |
|---|---|---|---|
| crime_houston_violent_total | pass |  | 9165 |
| foreclosure_california_total | pass |  | 8347 |
| asthma_california_adults | pass |  | 7709 |
| crime_homicides_chicago_synonym | pass |  | 11620 |
| refusal_no_matching_dataset | fail | should_have_refused | 8794 |
| refusal_write_request | fail | should_have_refused | 2852 |
| varicella_out_of_coverage | fail | should_have_refused | 11090 |
| dsl_yoy_varicella | fail | dsl_limitation | 14032 |
| dsl_above_avg_crime | fail | dsl_limitation | 9534 |
| dsl_percentile_gold | fail | dsl_limitation | 18120 |
