export function emptyTelemetry()
{
  return {
    pass_rate: null,
    top_failure_tags: [],
    retries_per_brief: null,
    duration_p50_seconds: null,
    duration_p95_seconds: null,
    cost_units_per_brief: null,
    queue_depth: 0,
    failed_jobs: 0,
    rate_limit_hits: 0,
  };
}

export function normalizeTelemetry(data)
{
  const source = data && typeof data === 'object' ? data : {};

  return {
    pass_rate: finiteOrNull(source.pass_rate),
    top_failure_tags: Array.isArray(source.top_failure_tags) ? source.top_failure_tags : [],
    retries_per_brief: finiteOrNull(source.retries_per_brief),
    duration_p50_seconds: finiteOrNull(source.duration_p50_seconds),
    duration_p95_seconds: finiteOrNull(source.duration_p95_seconds),
    cost_units_per_brief: finiteOrNull(source.cost_units_per_brief),
    queue_depth: finiteOrZero(source.queue_depth),
    failed_jobs: finiteOrZero(source.failed_jobs),
    rate_limit_hits: finiteOrZero(source.rate_limit_hits),
  };
}

function finiteOrNull(value)
{
  if (value === null || value === undefined || value === '')
  {
    return null;
  }

  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function finiteOrZero(value)
{
  const number = Number(value);
  return Number.isFinite(number) ? number : 0;
}
