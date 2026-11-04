<template>
  <div
    v-if="finalVideoPath || audioPath"
    class="rounded-xl border border-emerald-800/40 bg-zinc-900/80 p-5 shadow-lg backdrop-blur-sm"
  >
    <div class="mb-3 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="flex h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
        <h3 class="text-sm font-semibold uppercase tracking-wider text-emerald-300">Final Assembly Output</h3>
      </div>
      <span class="rounded-full bg-emerald-950/60 border border-emerald-700/60 px-2.5 py-0.5 text-xs font-mono text-emerald-300">
        {{ briefState === BriefState.DONE ? 'Pipeline Complete' : 'Assembling' }}
      </span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div v-if="finalVideoPath" class="flex flex-col">
        <span class="mb-1 text-[11px] font-medium uppercase tracking-wider text-zinc-400">Final Muxed Video (MP4)</span>
        <video
          :src="`/media/${finalVideoPath}`"
          controls
          playsinline
          class="aspect-video w-full rounded-lg border border-zinc-800 bg-zinc-950 object-cover"
        ></video>
      </div>

      <div v-if="audioPath" class="flex flex-col justify-center">
        <span class="mb-1 text-[11px] font-medium uppercase tracking-wider text-zinc-400">Audio (Voiceover + SFX)</span>
        <div class="rounded-lg border border-zinc-800 bg-zinc-950 p-3">
          <audio :src="`/media/${audioPath}`" controls class="w-full"></audio>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { BriefState } from '../constants';

defineProps({
  briefState: {
    type: String,
    default: BriefState.PENDING,
  },
  audioPath: {
    type: String,
    default: null,
  },
  finalVideoPath: {
    type: String,
    default: null,
  },
});
</script>
