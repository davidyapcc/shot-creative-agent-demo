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

        <!-- Connection & Active Brief Status -->
        <div class="flex items-center gap-3 text-xs">
          <div v-if="activeBriefId" class="hidden sm:flex items-center gap-1.5 rounded-full bg-zinc-800/80 px-3 py-1 font-mono text-[11px] border border-zinc-700/60">
            <span class="text-zinc-400">Brief:</span>
            <span class="text-indigo-300 font-semibold">#{{ activeBriefId }}</span>
            <span class="text-zinc-500">|</span>
            <span class="text-emerald-400">{{ briefState }}</span>
          </div>

          <!-- WebSocket Connection Status -->
          <div class="flex items-center gap-1.5 rounded-full bg-zinc-800/80 px-2.5 py-1 text-[11px] font-mono border border-zinc-700/60">
            <span
              :class="[
                'h-2 w-2 rounded-full',
                connectionStatus === 'connected'
                  ? 'bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.6)] animate-pulse'
                  : connectionStatus === 'connecting'
                    ? 'bg-amber-400 animate-ping'
                    : 'bg-zinc-500'
              ]"
            ></span>
            <span class="text-zinc-400 capitalize">{{ connectionStatus }}</span>
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

            <!-- Preset prompts -->
            <div class="mb-3">
              <span class="text-[11px] text-zinc-400 mb-1.5 block">Preset Examples:</span>
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="(preset, idx) in presets"
                  :key="idx"
                  type="button"
                  @click="promptInput = preset"
                  class="rounded-md bg-zinc-800 hover:bg-zinc-700/80 px-2.5 py-1 text-[11px] text-zinc-300 border border-zinc-700/60 transition-colors text-left"
                >
                  {{ preset }}
                </button>
              </div>
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

            <!-- Active Style Anchor -->
            <div v-if="styleAnchor" class="mt-4 rounded-lg bg-zinc-950/60 border border-zinc-800 p-2.5 text-xs">
              <span class="text-[11px] font-medium uppercase tracking-wider text-indigo-400 block mb-0.5">Style Anchor:</span>
              <p class="text-zinc-300 font-mono text-[11px]">{{ styleAnchor }}</p>
            </div>
          </div>

          <!-- Live Stage Stream Component -->
          <StageStream
            :brief-state="briefState"
            :video-progress="videoProgress"
            :judge-failure-tag="judgeFailureTag"
            :is-regenerating="isRegenerating"
            :events="streamEvents"
          />
        </section>

        <!-- RIGHT COLUMN: Shot Cards & Final Assembly (7 cols on lg) -->
        <section class="lg:col-span-7 flex flex-col gap-6">
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
              :video-progress="shotVideoProgress[shot.id] || videoProgress"
            />
          </div>

          <!-- Final Assembly Output Card (when audio or final video ready) -->
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
                {{ briefState === 'done' ? 'Pipeline Complete' : 'Assembling' }}
              </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <!-- Final Muxed MP4 -->
              <div v-if="finalVideoPath" class="flex flex-col">
                <span class="mb-1 text-[11px] font-medium uppercase tracking-wider text-zinc-400">Final Muxed Video (MP4)</span>
                <video
                  :src="`/media/${finalVideoPath}`"
                  controls
                  playsinline
                  class="aspect-video w-full rounded-lg border border-zinc-800 bg-zinc-950 object-cover"
                ></video>
              </div>

              <!-- Audio Voiceover & SFX -->
              <div v-if="audioPath" class="flex flex-col justify-center">
                <span class="mb-1 text-[11px] font-medium uppercase tracking-wider text-zinc-400">Audio (Voiceover + SFX)</span>
                <div class="rounded-lg border border-zinc-800 bg-zinc-950 p-3">
                  <audio :src="`/media/${audioPath}`" controls class="w-full"></audio>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { initEcho } from '../echo';
import StageStream from './StageStream.vue';
import ShotCard from './ShotCard.vue';

const presets = [
  '15s product teaser, rainy Tokyo, neon',
  '10s cinematic drone shot, snow-capped alpine mountains at sunrise',
  '12s cyberpunk street market, holographic ramen stall',
];

const promptInput = ref(presets[0]);
const isSubmitting = ref(false);
const errorMessage = ref(null);

const activeBriefId = ref(null);
const briefState = ref('pending');
const styleAnchor = ref(null);
const audioPath = ref(null);
const finalVideoPath = ref(null);

const shots = ref([]);
const shotVideoProgress = ref({});
const videoProgress = ref(0);
const judgeFailureTag = ref(null);
const isRegenerating = ref(false);

const streamEvents = ref([]);
const connectionStatus = ref('connecting');

let echo = null;
let activeChannel = null;

onMounted(() => {
  setupEcho();

  // Check URL query param for existing brief: e.g. ?brief_id=1
  const params = new URLSearchParams(window.location.search);
  const briefId = params.get('brief_id');
  if (briefId) {
    loadBrief(Number(briefId));
  }
});

onBeforeUnmount(() => {
  if (activeChannel) {
    activeChannel.stopListening('.BriefStateChanged');
    activeChannel.stopListening('.VideoProgressUpdated');
    activeChannel.stopListening('.ShotUpdated');
    activeChannel.stopListening('.JudgeVerdictRecorded');
  }
  if (echo) {
    echo.disconnect();
  }
});

function setupEcho() {
  try {
    echo = initEcho();

    if (echo.connector && echo.connector.pusher) {
      echo.connector.pusher.connection.bind('connected', () => {
        connectionStatus.value = 'connected';
      });
      echo.connector.pusher.connection.bind('disconnected', () => {
        connectionStatus.value = 'disconnected';
      });
      echo.connector.pusher.connection.bind('connecting', () => {
        connectionStatus.value = 'connecting';
      });
    }
  } catch (err) {
    console.error('Failed to initialize Echo:', err);
    connectionStatus.value = 'error';
  }
}

async function submitBrief() {
  if (!promptInput.value.trim()) return;

  isSubmitting.value = true;
  errorMessage.value = null;

  try {
    const response = await fetch('/briefs', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({ prompt: promptInput.value }),
    });

    const json = await response.json();

    if (!response.ok || !json.success) {
      errorMessage.value = json.error?.message || 'Failed to submit brief';
      return;
    }

    const brief = json.data.brief;
    activeBriefId.value = brief.id;
    briefState.value = brief.state;
    shots.value = [];
    shotVideoProgress.value = {};
    videoProgress.value = 0;
    judgeFailureTag.value = null;
    isRegenerating.value = false;
    audioPath.value = null;
    finalVideoPath.value = null;
    streamEvents.value = [];

    // Push initial submission event to stage stream
    addStreamEvent('BriefSubmitted', `Brief #${brief.id} enqueued: "${brief.prompt.slice(0, 35)}..."`);

    // Subscribe to Reverb WebSocket channel without reload!
    subscribeToBrief(brief.id);

    // Update browser URL without reloading
    const newUrl = `${window.location.pathname}?brief_id=${brief.id}`;
    window.history.pushState({ path: newUrl }, '', newUrl);

  } catch (err) {
    errorMessage.value = err.message || 'Network error submitting brief';
  } finally {
    isSubmitting.value = false;
  }
}

async function loadBrief(id) {
  try {
    const response = await fetch(`/briefs/${id}`, {
      headers: { 'Accept': 'application/json' },
    });
    if (!response.ok) return;

    const json = await response.json();
    if (json.success && json.data.brief) {
      const b = json.data.brief;
      activeBriefId.value = b.id;
      briefState.value = b.state;
      styleAnchor.value = b.style_anchor;
      audioPath.value = b.audio_path;
      finalVideoPath.value = b.final_video_path;
      shots.value = b.shots || [];
      subscribeToBrief(b.id);
    }
  } catch (e) {
    console.error('Error loading brief:', e);
  }
}

function subscribeToBrief(briefId) {
  if (!echo) {
    setupEcho();
  }

  if (activeChannel) {
    activeChannel.stopListening('.BriefStateChanged');
    activeChannel.stopListening('.VideoProgressUpdated');
    activeChannel.stopListening('.ShotUpdated');
    activeChannel.stopListening('.JudgeVerdictRecorded');
  }

  const channelName = `briefs.${briefId}`;
  activeChannel = echo.channel(channelName);

  activeChannel.listen('.BriefStateChanged', (data) => {
    briefState.value = data.to;
    addStreamEvent('BriefStateChanged', `Transitioned from ${data.from} to ${data.to}`);

    if (data.to === 'audio' || data.to === 'muxing' || data.to === 'done') {
      // Refresh full brief to capture audio_path or final_video_path
      fetchBriefMetadata(briefId);
    }
  });

  activeChannel.listen('.VideoProgressUpdated', (data) => {
    videoProgress.value = data.progress;
    if (data.shotId) {
      shotVideoProgress.value[data.shotId] = data.progress;
    }
    addStreamEvent('VideoProgressUpdated', `Shot #${data.shotId || ''} video progress ${data.progress}%`);
  });

  activeChannel.listen('.ShotUpdated', (data) => {
    const updatedShot = data.shot;
    if (!updatedShot) return;

    const existingIndex = shots.value.findIndex(s => s.id === updatedShot.id);
    if (existingIndex >= 0) {
      shots.value[existingIndex] = { ...shots.value[existingIndex], ...updatedShot };
    } else {
      shots.value.push(updatedShot);
      shots.value.sort((a, b) => a.sequence - b.sequence);
    }

    addStreamEvent('ShotUpdated', `Shot #${updatedShot.sequence} state is ${updatedShot.state}`);
  });

  activeChannel.listen('.JudgeVerdictRecorded', (data) => {
    addStreamEvent('JudgeVerdictRecorded', `${data.stage} verdict: ${data.verdict.toUpperCase()}${data.tag ? ` (fail: ${data.tag})` : ''}`, data.verdict);

    if (data.verdict === 'fail') {
      judgeFailureTag.value = data.tag || (data.hardFailTags ? data.hardFailTags[0] : 'failed');
    }
  });
}

async function fetchBriefMetadata(briefId) {
  try {
    const response = await fetch(`/briefs/${briefId}`);
    if (response.ok) {
      const json = await response.json();
      if (json.data?.brief) {
        audioPath.value = json.data.brief.audio_path;
        finalVideoPath.value = json.data.brief.final_video_path;
        styleAnchor.value = json.data.brief.style_anchor;
      }
    }
  } catch (e) {
    // silently catch background refresh
  }
}

function addStreamEvent(name, detail, verdict = null) {
  const time = new Date().toLocaleTimeString();
  streamEvents.value.push({ name, detail, time, verdict });
}
</script>
