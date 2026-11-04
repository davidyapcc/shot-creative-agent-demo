<template>
  <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 shadow-lg backdrop-blur-sm">
    <div class="mb-4 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span
          :class="[
            'inline-flex h-2.5 w-2.5 rounded-full',
            briefState === BriefState.FAILED ? 'bg-rose-400' : 'bg-emerald-400 animate-pulse'
          ]"
        ></span>
        <h3 class="text-sm font-semibold uppercase tracking-wider text-zinc-300">Live Stage Stream</h3>
      </div>
      <span
        :class="[
          'rounded-full px-2.5 py-0.5 text-xs font-mono font-medium border',
          briefState === BriefState.FAILED
            ? 'bg-rose-950/60 text-rose-300 border-rose-800/60'
            : briefState === BriefState.DONE
              ? 'bg-emerald-950/60 text-emerald-300 border-emerald-800/60'
              : 'bg-zinc-800 text-zinc-400 border-zinc-700/60'
        ]"
      >
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
              stage.status === ChipStatus.COMPLETED
                ? 'border-emerald-600/40 bg-emerald-950/40 text-emerald-300 shadow-sm'
                : stage.status === ChipStatus.ACTIVE
                  ? 'border-sky-500 bg-sky-950/60 text-sky-200 ring-2 ring-sky-500/30 animate-pulse'
                  : stage.status === ChipStatus.FAILED
                    ? 'border-rose-600/60 bg-rose-950/50 text-rose-300 font-semibold'
                    : 'border-zinc-800 bg-zinc-900 text-zinc-500 opacity-60'
            ]"
          >
            <!-- Status Icon -->
            <span v-if="stage.status === ChipStatus.COMPLETED" class="text-emerald-400">✓</span>
            <span v-else-if="stage.status === ChipStatus.ACTIVE" class="h-2 w-2 rounded-full bg-sky-400 animate-ping"></span>
            <span v-else-if="stage.status === ChipStatus.FAILED" class="text-rose-400">✕</span>
            <span v-else class="text-zinc-600">•</span>

            <span>{{ stage.label }}</span>
          </div>

          <!-- Arrow separator between stages -->
          <span
            v-if="idx < stageSequence.length - 1"
            :class="[
              'text-sm transition-colors',
              stage.status === ChipStatus.COMPLETED ? 'text-emerald-500' : 'text-zinc-700'
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
                ev.verdict === Verdict.FAIL
                  ? 'bg-rose-500'
                  : ev.name === BroadcastEvent.VIDEO_PROGRESS_UPDATED
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
import { BroadcastEvent } from '../broadcastEvents';
import { BriefState, StageRank, Verdict } from '../constants';

const ChipStatus = Object.freeze({
  COMPLETED: 'completed',
  ACTIVE: 'active',
  FAILED: 'failed',
  PENDING: 'pending',
});

const STAGE_JUDGING = 'judging';
const STAGE_VIDEO = 'video';
const STAGE_REGENERATING = 'regenerating';

const BRIEF_STATE_RANK = Object.freeze({
  [BriefState.PENDING]: StageRank.PENDING,
  [BriefState.PLANNING]: StageRank.PLANNING,
  [BriefState.KEYFRAMES]: StageRank.KEYFRAMES,
  [BriefState.RENDERING]: StageRank.RENDERING,
  [BriefState.AUDIO]: StageRank.AUDIO,
  [BriefState.MUXING]: StageRank.MUXING,
  [BriefState.DONE]: StageRank.DONE,
  [BriefState.DEGRADED]: StageRank.DONE,
});

const props = defineProps({
  briefState: {
    type: String,
    default: BriefState.PENDING,
  },
  videoProgress: {
    type: Number,
    default: 0,
  },
  judgeFailureTag: {
    type: String,
    default: null,
  },
  isJudging: {
    type: Boolean,
    default: false,
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

const reversedEvents = computed(() =>
{
  return [...props.events].reverse();
});

function computeStageLabel(stageId, failTag, progress)
{
  if (stageId === STAGE_JUDGING)
  {
    return failTag ? `judging (fail: ${failTag})` : STAGE_JUDGING;
  }

  if (stageId === STAGE_VIDEO)
  {
    return progress > 0 ? `video ${progress}%` : STAGE_VIDEO;
  }

  return stageId;
}

function resolveStageStatus(stageRank, currentRank, isFailed, isCurrentActive, isStageFailed)
{
  if (isStageFailed)
  {
    return ChipStatus.FAILED;
  }

  if (isCurrentActive)
  {
    return ChipStatus.ACTIVE;
  }

  if (currentRank > stageRank)
  {
    return ChipStatus.COMPLETED;
  }

  if (isFailed && currentRank === stageRank)
  {
    return ChipStatus.FAILED;
  }

  return ChipStatus.PENDING;
}

const stageSequence = computed(() =>
{
  const state = props.briefState || BriefState.PENDING;
  const isFailed = state === BriefState.FAILED;
  const failTag = props.judgeFailureTag;
  const currentRank = isFailed
    ? (failTag ? StageRank.JUDGING : StageRank.KEYFRAMES)
    : (BRIEF_STATE_RANK[state] ?? StageRank.PENDING);

  const baseStages = [
    {
      id: BriefState.PLANNING,
      rank: StageRank.PLANNING,
      isCurrentActive: state === BriefState.PLANNING,
      isFailed: false,
    },
    {
      id: BriefState.KEYFRAMES,
      rank: StageRank.KEYFRAMES,
      isCurrentActive: state === BriefState.KEYFRAMES && !props.isJudging,
      isFailed: isFailed && !failTag,
    },
    {
      id: STAGE_JUDGING,
      rank: StageRank.JUDGING,
      isCurrentActive: (state === BriefState.KEYFRAMES && props.isJudging)
        || (state === BriefState.RENDERING && props.isJudging),
      isFailed: Boolean(failTag),
    },
  ];

  if (props.isRegenerating)
  {
    baseStages.push({
      id: STAGE_REGENERATING,
      rank: StageRank.JUDGING,
      isCurrentActive: true,
      isFailed: false,
    });
  }

  baseStages.push(
    {
      id: STAGE_VIDEO,
      rank: StageRank.RENDERING,
      isCurrentActive: state === BriefState.RENDERING && !props.isJudging,
      isFailed: false,
    },
    {
      id: BriefState.AUDIO,
      rank: StageRank.AUDIO,
      isCurrentActive: state === BriefState.AUDIO,
      isFailed: false,
    },
    {
      id: BriefState.MUXING,
      rank: StageRank.MUXING,
      isCurrentActive: state === BriefState.MUXING,
      isFailed: false,
    },
    {
      id: BriefState.DONE,
      rank: StageRank.DONE,
      isCurrentActive: false,
      isFailed: state === BriefState.DEGRADED,
    },
  );

  return baseStages.map((s) => ({
    id: s.id,
    label: computeStageLabel(s.id, failTag, props.videoProgress),
    status: resolveStageStatus(s.rank, currentRank, isFailed, s.isCurrentActive, s.isFailed),
  }));
});
</script>
