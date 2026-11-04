<template>
  <div class="rounded-xl border border-zinc-800 bg-zinc-900/70 p-5 shadow-lg backdrop-blur-sm transition-all duration-200 hover:border-zinc-700">
    <!-- Header: Shot #, Status, Attempt Count -->
    <div class="mb-3 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-600/30 text-xs font-bold text-indigo-300 border border-indigo-500/40">
          #{{ shot.sequence }}
        </span>
        <h4 class="text-sm font-semibold text-zinc-200">Shot {{ shot.sequence }}</h4>
      </div>

      <div class="flex items-center gap-2">
        <!-- Attempts Badge -->
        <span class="rounded-full bg-zinc-800 px-2.5 py-0.5 text-xs font-medium text-zinc-400 border border-zinc-700/60">
          Attempt {{ shot.attempts || 1 }}
        </span>

        <!-- State Badge -->
        <span
          :class="[
            'rounded-full px-2.5 py-0.5 text-xs font-mono font-medium border',
            stateBadgeClass
          ]"
        >
          {{ formatState(shot.state) }}
        </span>
      </div>
    </div>

    <!-- Shot Prompt -->
    <p class="mb-4 text-xs leading-relaxed text-zinc-400">
      {{ shot.prompt }}
    </p>

    <!-- Media Grid: Keyframe & Video Clip -->
    <div class="mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
      <!-- Keyframe Image -->
      <div class="flex flex-col">
        <span class="mb-1 text-[11px] font-medium uppercase tracking-wider text-zinc-400">Keyframe</span>
        <div class="relative aspect-video w-full overflow-hidden rounded-lg border border-zinc-800 bg-zinc-950 flex items-center justify-center">
          <img
            v-if="keyframeUrl"
            :src="keyframeUrl"
            :alt="`Keyframe for shot ${shot.sequence}`"
            class="h-full w-full object-cover transition-transform duration-300 hover:scale-105"
          />
          <div v-else class="flex flex-col items-center justify-center text-zinc-600 p-3 text-center">
            <svg class="h-6 w-6 mb-1 opacity-40 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <span class="text-[11px]">
              {{ shot.state === ShotState.GENERATING_IMAGE ? 'Generating keyframe...' : 'Awaiting keyframe' }}
            </span>
          </div>
        </div>
      </div>

      <!-- Video Clip -->
      <div class="flex flex-col">
        <div class="mb-1 flex items-center justify-between">
          <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-400">Video Clip</span>
          <span v-if="shot.state === ShotState.GENERATING_VIDEO && videoProgress > 0" class="text-[11px] font-mono text-amber-400">
            {{ videoProgress }}%
          </span>
        </div>
        <div class="relative aspect-video w-full overflow-hidden rounded-lg border border-zinc-800 bg-zinc-950 flex items-center justify-center">
          <video
            v-if="videoUrl"
            :src="videoUrl"
            controls
            autoplay
            loop
            muted
            playsinline
            class="h-full w-full object-cover"
          ></video>
          <div v-else-if="shot.state === ShotState.GENERATING_VIDEO" class="w-full p-4 flex flex-col items-center justify-center text-center">
            <div class="w-full bg-zinc-800 rounded-full h-2 mb-2 overflow-hidden">
              <div
                class="bg-amber-400 h-2 rounded-full transition-all duration-300"
                :style="{ width: `${videoProgress}%` }"
              ></div>
            </div>
            <span class="text-[11px] text-zinc-400 font-mono">
              Rendering video {{ videoProgress }}%...
            </span>
          </div>
          <div v-else class="flex flex-col items-center justify-center text-zinc-600 p-3 text-center">
            <svg class="h-6 w-6 mb-1 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
            <span class="text-[11px]">Awaiting video render</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Latest Verdict & Failure Tags -->
    <div
      v-if="latestJudgement"
      :class="[
        'rounded-lg border p-3 text-xs',
        latestJudgement.verdict === Verdict.PASS
          ? 'border-emerald-800/40 bg-emerald-950/20 text-emerald-200'
          : 'border-rose-800/50 bg-rose-950/30 text-rose-200'
      ]"
    >
      <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-1.5 font-semibold">
          <span
            :class="[
              'inline-flex h-2 w-2 rounded-full',
              latestJudgement.verdict === Verdict.PASS ? 'bg-emerald-400' : 'bg-rose-400'
            ]"
          ></span>
          <span>Judge Verdict: {{ latestJudgement.verdict.toUpperCase() }}</span>
          <span class="text-[11px] font-normal text-zinc-400">({{ latestJudgement.stage }} stage)</span>
        </div>

        <!-- Failure Tags Chips -->
        <div v-if="failureTags.length > 0" class="flex flex-wrap gap-1">
          <span
            v-for="tag in failureTags"
            :key="tag"
            class="rounded bg-rose-900/60 px-2 py-0.5 text-[11px] font-mono font-medium text-rose-200 border border-rose-700/60"
          >
            fail: {{ tag }}
          </span>
        </div>
      </div>

      <!-- Judge Reason -->
      <p v-if="latestJudgement.reason" class="text-zinc-300 text-[11px] leading-relaxed italic">
        "{{ latestJudgement.reason }}"
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { ShotState, Verdict } from '../constants';

const props = defineProps({
  shot: {
    type: Object,
    required: true,
  },
  videoProgress: {
    type: Number,
    default: 0,
  },
});

const keyframeUrl = computed(() =>
{
  if (props.shot.keyframe_url)
  {
    return props.shot.keyframe_url;
  }
  if (props.shot.keyframe_path)
  {
    return `/media/${props.shot.keyframe_path}`;
  }
  return null;
});

const videoUrl = computed(() =>
{
  if (props.shot.video_url)
  {
    return props.shot.video_url;
  }
  if (props.shot.video_path)
  {
    return `/media/${props.shot.video_path}`;
  }
  return null;
});

const latestJudgement = computed(() =>
{
  if (props.shot.latest_judgement)
  {
    return props.shot.latest_judgement;
  }
  if (props.shot.judgements && props.shot.judgements.length > 0)
  {
    const sorted = [...props.shot.judgements].sort((a, b) => a.id - b.id);
    return sorted[sorted.length - 1];
  }
  return null;
});

const failureTags = computed(() =>
{
  if (!latestJudgement.value)
  {
    return [];
  }
  const tags = latestJudgement.value.hard_fail_tags || [];
  return Array.isArray(tags) ? tags : [];
});

const stateBadgeClass = computed(() =>
{
  switch (props.shot.state)
  {
    case ShotState.DONE:
      return 'border-emerald-600/40 bg-emerald-950/40 text-emerald-300';
    case ShotState.FAILED:
      return 'border-rose-600/40 bg-rose-950/40 text-rose-300';
    case ShotState.GENERATING_IMAGE:
    case ShotState.GENERATING_VIDEO:
      return 'border-amber-600/40 bg-amber-950/40 text-amber-300 animate-pulse';
    case ShotState.JUDGING_IMAGE:
    case ShotState.JUDGING_VIDEO:
      return 'border-purple-600/40 bg-purple-950/40 text-purple-300 animate-pulse';
    default:
      return 'border-zinc-700 bg-zinc-800 text-zinc-400';
  }
});

function formatState(state)
{
  if (!state)
  {
    return ShotState.PENDING;
  }
  return state.replace(/_/g, ' ');
}
</script>
