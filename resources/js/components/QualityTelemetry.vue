<template>
  <section
    class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-lg backdrop-blur-sm"
    aria-label="Quality telemetry"
  >
    <div class="mb-3 flex items-center justify-between">
      <h2 class="text-sm font-semibold uppercase tracking-wider text-zinc-300">Quality</h2>
      <span class="text-[11px] font-mono text-zinc-500">Live</span>
    </div>

    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
      <div v-for="metric in metrics" :key="metric.label">
        <dt class="text-[11px] font-medium uppercase tracking-wider text-zinc-500">{{ metric.label }}</dt>
        <dd class="mt-1 font-mono text-sm text-zinc-100">{{ metric.value }}</dd>
      </div>
    </dl>

    <div class="mt-4 border-t border-zinc-800/80 pt-3">
      <p class="text-[11px] font-medium uppercase tracking-wider text-zinc-500">Failure tags</p>
      <p v-if="tags.length === 0" class="mt-1 text-sm text-zinc-400">None</p>
      <ol v-else class="mt-2 flex flex-wrap gap-2">
        <li
          v-for="tag in tags"
          :key="tag.tag"
          class="rounded-full border border-rose-900/80 bg-rose-950/50 px-2.5 py-0.5 text-xs text-rose-200"
        >
          {{ tag.tag }}
          <span class="font-mono text-rose-300">{{ tag.count }}</span>
        </li>
      </ol>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { normalizeTelemetry } from '../telemetry';

const props = defineProps({
  telemetry: {
    type: Object,
    required: true,
  },
});

const view = computed(() => normalizeTelemetry(props.telemetry));

const tags = computed(() => view.value.top_failure_tags);

const metrics = computed(() =>
{
  const telemetry = view.value;

  return [
    { label: 'Pass rate', value: formatMetric(telemetry.pass_rate, '%') },
    { label: 'Retries / brief', value: formatMetric(telemetry.retries_per_brief) },
    { label: 'Duration p50', value: formatMetric(telemetry.duration_p50_seconds, 's') },
    { label: 'Duration p95', value: formatMetric(telemetry.duration_p95_seconds, 's') },
    { label: 'Cost / brief', value: formatMetric(telemetry.cost_units_per_brief) },
    { label: 'Queue depth', value: String(telemetry.queue_depth) },
    { label: 'Failed jobs', value: String(telemetry.failed_jobs) },
    { label: 'Rate limits', value: String(telemetry.rate_limit_hits) },
  ];
});

function formatMetric(value, suffix = '')
{
  if (value === null)
  {
    return '—';
  }

  return `${value}${suffix}`;
}
</script>
