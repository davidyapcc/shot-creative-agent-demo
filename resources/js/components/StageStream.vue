<template>
  <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 shadow-lg backdrop-blur-sm">
    <div class="mb-4 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
        <h3 class="text-sm font-semibold uppercase tracking-wider text-zinc-300">Live Stage Stream</h3>
      </div>
      <span class="rounded-full bg-zinc-800 px-2.5 py-0.5 text-xs font-mono text-zinc-400">
        {{ briefState || 'idle' }}
      </span>
    </div>

    <!-- Live Stage Stream Pipeline Representation -->
    <div class="mb-5 overflow-x-auto pb-2">
      <div class="flex flex-wrap items-center gap-2 text-xs font-mono">
        <template v-for="(stage, idx) in stageSequence" :key="stage.id">
          <!-- Stage Chip -->
          <div
            :class="[
              'flex items-center gap-1.5 rounded-lg border px-3 py-1.5 transition-all duration-200',
              stage.status === 'completed'
                ? 'border-emerald-600/40 bg-emerald-950/40 text-emerald-300 shadow-sm'
                : stage.status === 'active'
                  ? 'border-sky-500 bg-sky-950/60 text-sky-200 ring-2 ring-sky-500/30 animate-pulse'
                  : stage.status === 'failed'
                    ? 'border-rose-600/60 bg-rose-950/50 text-rose-300 font-semibold'
                    : 'border-zinc-800 bg-zinc-900 text-zinc-500 opacity-60'
            ]"
          >
            <!-- Status Icon -->
            <span v-if="stage.status === 'completed'" class="text-emerald-400">✓</span>
            <span v-else-if="stage.status === 'active'" class="h-2 w-2 rounded-full bg-sky-400 animate-ping"></span>
            <span v-else-if="stage.status === 'failed'" class="text-rose-400">✕</span>
            <span v-else class="text-zinc-600">•</span>

            <span>{{ stage.label }}</span>
          </div>

          <!-- Arrow separator between stages -->
          <span
            v-if="idx < stageSequence.length - 1"
            :class="[
              'text-sm transition-colors',
              stage.status === 'completed' ? 'text-emerald-500' : 'text-zinc-700'
            ]"
          >
            →
          </span>
        </template>
      </div>
    </div>

    <!-- Live Event Feed / Log -->
    <div class="border-t border-zinc-800/80 pt-4">
      <div class="mb-2 flex items-center justify-between text-xs text-zinc-400">
        <span class="font-medium">Broadcast Stream Events</span>
        <span class="font-mono text-[11px] text-zinc-500">{{ events.length }} events received</span>
      </div>

      <div
        class="max-h-56 space-y-2 overflow-y-auto rounded-lg bg-zinc-950/80 p-3 font-mono text-[11px] border border-zinc-800/60"
      >
        <div v-if="events.length === 0" class="py-4 text-center text-zinc-600">
          Waiting for pipeline events on Reverb channel...
        </div>
        <div
          v-for="(ev, i) in reversedEvents"
          :key="i"
          class="flex items-start justify-between gap-2 border-b border-zinc-900 pb-1.5 last:border-0 last:pb-0"
        >
          <div class="flex items-center gap-2">
            <span
              :class="[
                'inline-block h-1.5 w-1.5 rounded-full',
                ev.type === 'error' || ev.verdict === 'fail'
                  ? 'bg-rose-500'
                  : ev.name === 'VideoProgressUpdated'
                    ? 'bg-amber-400'
                    : 'bg-emerald-400'
              ]"
            ></span>
            <span class="font-semibold text-zinc-300">{{ ev.name }}</span>
            <span class="text-zinc-400">{{ ev.detail }}</span>
          </div>
          <span class="text-[10px] text-zinc-600 shrink-0">{{ ev.time }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  briefState: {
    type: String,
    default: 'pending',
  },
  videoProgress: {
    type: Number,
    default: 0,
  },
  judgeFailureTag: {
    type: String,
    default: null,
  },
  isRegenerating: {
    type: Boolean,
    default: false,
  },
  events: {
    type: Array,
    default: () => [],
  },
});

const reversedEvents = computed(() => [...props.events].reverse());

const stageSequence = computed(() => {
  const state = props.briefState || 'pending';
  const progress = props.videoProgress;
  const failTag = props.judgeFailureTag;
  const regenerating = props.isRegenerating;

  // Ordering of states
  const order = ['pending', 'planning', 'keyframes', 'rendering', 'audio', 'muxing', 'done'];
  const currentIndex = order.indexOf(state);

  // Label for judging step: if failed, show `judging (fail: ${tag})`, else `judging`
  const judgingLabel = failTag ? `judging (fail: ${failTag})` : 'judging';

  // Label for video step: e.g. `video 40%` or `video`
  const videoLabel = progress > 0 && progress < 100
    ? `video ${progress}%`
    : state === 'rendering'
      ? `video ${progress || 40}%`
      : 'video';

  const stages = [
    {
      id: 'planning',
      label: 'planning',
      status: state === 'planning'
        ? 'active'
        : currentIndex > order.indexOf('planning')
          ? 'completed'
          : 'pending',
    },
    {
      id: 'keyframes',
      label: 'keyframes',
      status: state === 'keyframes'
        ? 'active'
        : currentIndex > order.indexOf('keyframes')
          ? 'completed'
          : 'pending',
    },
    {
      id: 'judging',
      label: judgingLabel,
      status: failTag
        ? 'failed'
        : state === 'keyframes'
          ? 'active'
          : currentIndex > order.indexOf('keyframes')
            ? 'completed'
            : 'pending',
    },
  ];

  if (regenerating || failTag) {
    stages.push({
      id: 'regenerating',
      label: 'regenerating',
      status: regenerating ? 'active' : 'pending',
    });
  }

  stages.push(
    {
      id: 'video',
      label: videoLabel,
      status: state === 'rendering'
        ? 'active'
        : currentIndex > order.indexOf('rendering')
          ? 'completed'
          : 'pending',
    },
    {
      id: 'audio',
      label: 'audio',
      status: state === 'audio'
        ? 'active'
        : currentIndex > order.indexOf('audio')
          ? 'completed'
          : 'pending',
    },
    {
      id: 'muxing',
      label: 'muxing',
      status: state === 'muxing'
        ? 'active'
        : currentIndex > order.indexOf('muxing')
          ? 'completed'
          : 'pending',
    },
    {
      id: 'done',
      label: 'done',
      status: state === 'done'
        ? 'completed'
        : state === 'failed'
          ? 'failed'
          : 'pending',
    }
  );

  return stages;
});
</script>
