<template>
  <div class="min-h-screen bg-zinc-950 text-zinc-100 antialiased selection:bg-indigo-500 selection:text-white">
    <!-- Navbar / Header -->
    <header class="border-b border-zinc-800/80 bg-zinc-900/60 backdrop-blur-md sticky top-0 z-50">
      <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6">
        <div class="flex items-center gap-3">
          <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 shadow-md shadow-indigo-500/20">
            <span class="text-base font-black text-white">S</span>
          </div>
          <div>
            <h1 class="text-sm font-bold tracking-tight text-white flex items-center gap-2">
              Brief → Shot
              <span class="rounded bg-indigo-500/20 px-1.5 py-0.5 text-[10px] font-mono font-medium text-indigo-300 border border-indigo-500/30">
                Agent Demo
              </span>
            </h1>
            <p class="text-[11px] text-zinc-400">Shared evaluations & live async quality monitoring</p>
          </div>
        </div>

      </div>
    </header>

    <!-- Main Content Area: Split View -->
    <main class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- LEFT COLUMN: Brief Submission Form & Live Stage Stream (5 cols on lg) -->
        <section class="lg:col-span-5 flex flex-col gap-6">
          <!-- Creative Brief Form Card -->
          <div class="rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 shadow-lg backdrop-blur-sm">
            <div class="mb-3 flex items-center justify-between">
              <h2 class="text-sm font-semibold uppercase tracking-wider text-zinc-300 flex items-center gap-2">
                <span>Submit Creative Brief</span>
              </h2>
              <span class="text-[11px] text-zinc-500 font-mono">POST /briefs</span>
            </div>

            <!-- Form -->
            <form @submit.prevent="submitBrief" class="flex flex-col gap-3">
              <div>
                <textarea
                  v-model="promptInput"
                  rows="3"
                  required
                  placeholder="e.g. 15s product teaser, rainy Tokyo, neon..."
                  class="w-full rounded-lg border border-zinc-700/80 bg-zinc-950 p-3 text-xs leading-relaxed text-zinc-200 placeholder-zinc-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                ></textarea>
              </div>

              <!-- Error display -->
              <div v-if="errorMessage" class="rounded-lg bg-rose-950/60 border border-rose-800/80 p-2.5 text-xs text-rose-300">
                {{ errorMessage }}
              </div>

              <button
                type="submit"
                :disabled="isSubmitting || !promptInput.trim()"
                class="flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 py-2.5 px-4 text-xs font-semibold text-white shadow-md shadow-indigo-600/20 disabled:cursor-not-allowed disabled:opacity-50 transition-all"
              >
                <svg v-if="isSubmitting" class="h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>{{ isSubmitting ? 'Dispatching Pipeline...' : 'Generate Shots & Watch Live' }}</span>
              </button>
            </form>
          </div>

          <!-- Live Stage Stream Component -->
          <StageStream
            :brief-state="briefState"
            :video-progress="overallVideoProgress"
            :judge-failure-tag="judgeFailureTag"
            :is-judging="isJudging"
            :is-regenerating="isRegenerating"
            :events="streamEvents"
          />
        </section>

        <!-- RIGHT COLUMN: Shot Cards & Final Assembly (7 cols on lg) -->
        <section class="lg:col-span-7 flex flex-col gap-6">
          <QualityTelemetry :telemetry="telemetry" />

          <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider text-zinc-300 flex items-center gap-2">
              <span>Shot Cards</span>
              <span class="rounded-full bg-zinc-800 px-2 py-0.5 text-xs font-mono text-zinc-400">
                {{ shots.length }}
              </span>
            </h2>
            <span v-if="shots.length > 0" class="text-xs text-zinc-500 font-mono">
              Live Reverb Updates
            </span>
          </div>

          <!-- Empty State -->
          <div
            v-if="shots.length === 0"
            class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-800 bg-zinc-900/30 p-12 text-center"
          >
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-800/80 text-zinc-500">
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
              </svg>
            </div>
            <h3 class="text-sm font-medium text-zinc-300 mb-1">No shots planned yet</h3>
            <p class="text-xs text-zinc-500 max-w-sm">
              Submit a creative brief on the left. The agent will plan shots, generate style-anchored keyframes, run parallel video renders, and evaluate each output live.
            </p>
          </div>

          <!-- Shot Cards Stack -->
          <div v-else class="flex flex-col gap-4">
            <ShotCard
              v-for="shot in shots"
              :key="shot.id"
              :shot="shot"
              :video-progress="shotVideoProgress[shot.id] || 0"
            />
          </div>
        </section>
      </div>
    </main>
  </div>
</template>

<script setup>
import { useLiveBrief } from '../composables/useLiveBrief';
import QualityTelemetry from './QualityTelemetry.vue';
import ShotCard from './ShotCard.vue';
import StageStream from './StageStream.vue';

const {
  telemetry,
  promptInput,
  isSubmitting,
  errorMessage,
  briefState,
  shots,
  shotVideoProgress,
  judgeFailureTag,
  isRegenerating,
  streamEvents,
  isJudging,
  overallVideoProgress,
  submitBrief,
} = useLiveBrief();
</script>
