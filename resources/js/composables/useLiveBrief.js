import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { initEcho } from '../echo';
import { BriefState, ShotState, Verdict } from '../constants';

const MAX_PROMPT_PREVIEW_LENGTH = 35;
const COMPLETED_VIDEO_PROGRESS = 100;

const presets = [
  '15s product teaser, rainy Tokyo, neon',
  '10s cinematic drone shot, snow-capped alpine mountains at sunrise',
  '12s cyberpunk street market, holographic ramen stall',
];

export function useLiveBrief()
{
  const promptInput = ref(presets[0]);
  const isSubmitting = ref(false);
  const errorMessage = ref(null);

  const activeBriefId = ref(null);
  const briefState = ref(BriefState.PENDING);
  const styleAnchor = ref(null);
  const audioPath = ref(null);
  const finalVideoPath = ref(null);

  const shots = ref([]);
  const shotVideoProgress = ref({});
  const judgeFailureTag = ref(null);
  // Issue #6 owns retries. Leave this false so a fail tag does not look like a retry.
  const isRegenerating = ref(false);

  const streamEvents = ref([]);
  const connectionStatus = ref('connecting');

  let echo = null;
  let activeChannel = null;
  let shotRevision = 0;
  let briefRevision = 0;
  let syncSerial = 0;
  const shotRevisions = new Map();

  const isJudging = computed(() =>
  {
    return shots.value.some((shot) =>
      shot.state === ShotState.JUDGING_IMAGE || shot.state === ShotState.JUDGING_VIDEO
    );
  });

  const overallVideoProgress = computed(() =>
  {
    if (shots.value.length === 0)
    {
      return 0;
    }

    const total = shots.value.reduce(
      (sum, shot) => sum + (shotVideoProgress.value[shot.id] || 0),
      0,
    );

    return Math.round(total / shots.value.length);
  });

  onMounted(() =>
  {
    setupEcho();

    const params = new URLSearchParams(window.location.search);
    const briefId = params.get('brief_id');
    if (briefId)
    {
      const id = Number(briefId);
      subscribeToBrief(id);
      syncBrief(id);
    }
  });

  onBeforeUnmount(() =>
  {
    detachChannelListeners(activeChannel);
    if (echo)
    {
      echo.disconnect();
    }
  });

  function setupEcho()
  {
    try
    {
      echo = initEcho();
      bindConnectionStatus(echo);
    }
    catch (error)
    {
      console.error('Failed to initialize Echo:', error);
      connectionStatus.value = 'error';
      errorMessage.value = error.message;
    }
  }

  function bindConnectionStatus(client)
  {
    const connection = client.connector?.pusher?.connection;
    if (!connection)
    {
      return;
    }

    connection.bind('connected', () =>
    {
      connectionStatus.value = 'connected';
    });
    connection.bind('disconnected', () =>
    {
      connectionStatus.value = 'disconnected';
    });
    connection.bind('connecting', () =>
    {
      connectionStatus.value = 'connecting';
    });
  }

  async function submitBrief()
  {
    if (!promptInput.value.trim())
    {
      return;
    }

    isSubmitting.value = true;
    errorMessage.value = null;

    try
    {
      const brief = await postBrief(promptInput.value);
      resetActiveState(brief);

      const preview = brief.prompt.slice(0, MAX_PROMPT_PREVIEW_LENGTH);
      addStreamEvent('BriefSubmitted', `Brief #${brief.id} enqueued: "${preview}..."`);

      subscribeToBrief(brief.id);
      await syncBrief(brief.id);

      const newUrl = `${window.location.pathname}?brief_id=${brief.id}`;
      window.history.pushState({ path: newUrl }, '', newUrl);
    }
    catch (error)
    {
      console.error('Failed to submit brief', error);
      errorMessage.value = error.message || 'Network error submitting brief';
    }
    finally
    {
      isSubmitting.value = false;
    }
  }

  async function postBrief(prompt)
  {
    const response = await fetch('/briefs', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({ prompt }),
    });

    const json = await response.json();
    if (!response.ok || !json.success)
    {
      throw new Error(json.error?.message || 'Failed to submit brief');
    }

    return json.data.brief;
  }

  function resetActiveState(brief)
  {
    activeBriefId.value = brief.id;
    briefState.value = brief.state;
    shots.value = [];
    shotVideoProgress.value = {};
    judgeFailureTag.value = null;
    isRegenerating.value = false;
    audioPath.value = null;
    finalVideoPath.value = null;
    streamEvents.value = [];
  }

  async function syncBrief(id)
  {
    const serial = ++syncSerial;
    const knownShotIds = new Set(shots.value.map((shot) => shot.id));
    const shotRevisionAtStart = shotRevision;
    const briefRevisionAtStart = briefRevision;

    try
    {
      const brief = await fetchBrief(id);
      if (serial !== syncSerial)
      {
        return;
      }

      applyBriefRecord(brief, briefRevisionAtStart);

      const appliedShots = applySyncedShots(brief.shots, knownShotIds, shotRevisionAtStart);
      refreshFailureTag(appliedShots);
      seedStream({ ...brief, state: briefState.value }, appliedShots);
    }
    catch (error)
    {
      console.error(`Failed to sync brief #${id}`, error);
      if (serial !== syncSerial)
      {
        return;
      }

      errorMessage.value = error.message || `Error loading brief #${id}`;
    }
  }

  async function fetchBrief(id)
  {
    const response = await fetch(`/briefs/${id}`, {
      headers: { 'Accept': 'application/json' },
    });

    if (!response.ok)
    {
      throw new Error(`Failed to load brief #${id}: ${response.statusText}`);
    }

    const json = await response.json();
    if (!json.success || !json.data?.brief)
    {
      throw new Error(`Failed to load brief #${id}`);
    }

    return json.data.brief;
  }

  function applyBriefRecord(brief, briefRevisionAtStart)
  {
    activeBriefId.value = brief.id;

    const snapshotIsCurrent = briefRevision === briefRevisionAtStart;
    if (snapshotIsCurrent)
    {
      briefState.value = brief.state;
      styleAnchor.value = brief.style_anchor ?? null;
      audioPath.value = brief.audio_path ?? null;
      finalVideoPath.value = brief.final_video_path ?? null;
      return;
    }

    if (brief.style_anchor)
    {
      styleAnchor.value = brief.style_anchor;
    }
    if (brief.audio_path)
    {
      audioPath.value = brief.audio_path;
    }
    if (brief.final_video_path)
    {
      finalVideoPath.value = brief.final_video_path;
    }
  }

  function applySyncedShots(serverShots, knownShotIds, revisionAtStart)
  {
    const incoming = Array.isArray(serverShots) ? serverShots : [];
    const incomingIds = new Set(incoming.map((shot) => shot.id));
    const localById = new Map(shots.value.map((shot) => [shot.id, shot]));
    // A socket update that landed while this request was open is newer than the snapshot.
    const fromServer = incoming.map((shot) =>
    {
      const local = localById.get(shot.id);
      if (local && (shotRevisions.get(shot.id) ?? 0) > revisionAtStart)
      {
        return local;
      }

      return shot;
    });
    const arrivedDuringFetch = shots.value.filter((shot) =>
      !knownShotIds.has(shot.id) && !incomingIds.has(shot.id)
    );

    const applied = [...fromServer, ...arrivedDuringFetch]
      .sort((left, right) => left.sequence - right.sequence);

    const previousProgress = shotVideoProgress.value;
    const nextProgress = {};

    for (const shot of applied)
    {
      const progress = Math.max(progressForShot(shot), previousProgress[shot.id] ?? 0);
      if (progress > 0)
      {
        nextProgress[shot.id] = progress;
      }
    }

    shots.value = applied;
    shotVideoProgress.value = nextProgress;

    return applied;
  }

  function progressForShot(shot)
  {
    const stored = shot.metadata?.video_progress;
    if (Number.isFinite(stored))
    {
      return stored;
    }

    const videoFinished = Boolean(shot.video_path)
      || shot.state === ShotState.JUDGING_VIDEO
      || shot.state === ShotState.DONE;

    return videoFinished ? COMPLETED_VIDEO_PROGRESS : 0;
  }

  function refreshFailureTag(appliedShots)
  {
    let sawJudgement = false;
    let nextTag = null;

    for (const shot of appliedShots)
    {
      const latest = latestJudgement(shot);
      if (!latest)
      {
        continue;
      }

      sawJudgement = true;
      if (latest.verdict === Verdict.FAIL)
      {
        nextTag = failTagFrom(latest.hard_fail_tags);
        break;
      }
    }

    if (nextTag || sawJudgement)
    {
      judgeFailureTag.value = nextTag;
    }
  }

  function seedStream(brief, appliedShots)
  {
    if (brief.state && brief.state !== BriefState.PENDING)
    {
      addRecoveredEvent(
        'BriefStateChanged',
        `Brief state is ${brief.state}`,
        { state: brief.state },
      );
    }

    for (const shot of appliedShots)
    {
      seedShot(shot);
    }
  }

  function seedShot(shot)
  {
    addRecoveredEvent(
      'ShotUpdated',
      `Shot #${shot.sequence} state is ${shot.state}`,
      { state: shot.state, shotId: shot.id },
    );

    const progress = shotVideoProgress.value[shot.id] ?? 0;
    if (progress > 0)
    {
      addRecoveredEvent(
        'VideoProgressUpdated',
        `Shot #${shot.id} video progress ${progress}%`,
        { shotId: shot.id, progress },
      );
    }

    const latest = latestJudgement(shot);
    if (latest?.verdict !== Verdict.FAIL)
    {
      return;
    }

    const tag = failTagFrom(latest.hard_fail_tags);
    const detail = `${latest.stage} verdict: FAIL` + (tag ? ` (fail: ${tag})` : '');
    addRecoveredEvent(
      'JudgeVerdictRecorded',
      detail,
      { verdict: Verdict.FAIL, shotId: shot.id, stage: latest.stage },
    );
  }

  function latestJudgement(shot)
  {
    if (shot.latest_judgement)
    {
      return shot.latest_judgement;
    }

    if (!Array.isArray(shot.judgements) || shot.judgements.length === 0)
    {
      return null;
    }

    return [...shot.judgements].sort((left, right) => left.id - right.id).at(-1);
  }

  function failTagFrom(tags)
  {
    return Array.isArray(tags) && tags.length > 0 ? tags[0] : null;
  }

  function handleBriefStateChanged(data, briefId)
  {
    briefRevision += 1;
    briefState.value = data.to;
    addStreamEvent(
      'BriefStateChanged',
      `Transitioned from ${data.from} to ${data.to}`,
      { state: data.to },
    );

    if (
      data.to === BriefState.AUDIO
      || data.to === BriefState.MUXING
      || data.to === BriefState.DONE
      || data.to === BriefState.DEGRADED
    )
    {
      syncBrief(briefId);
    }
  }

  function handleVideoProgressUpdated(data)
  {
    if (data.shotId)
    {
      shotVideoProgress.value = {
        ...shotVideoProgress.value,
        [data.shotId]: data.progress,
      };
    }

    addStreamEvent(
      'VideoProgressUpdated',
      `Shot #${data.shotId || ''} video progress ${data.progress}%`,
      { shotId: data.shotId, progress: data.progress },
    );
  }

  function handleShotUpdated(data)
  {
    const updatedShot = data.shot;
    if (!updatedShot)
    {
      return;
    }

    noteShotRevision(updatedShot.id);

    const existingIndex = shots.value.findIndex((shot) => shot.id === updatedShot.id);
    if (existingIndex >= 0)
    {
      shots.value = shots.value.map((shot, index) =>
        index === existingIndex ? { ...shot, ...updatedShot } : shot
      );
    }
    else
    {
      shots.value = [...shots.value, updatedShot].sort((left, right) => left.sequence - right.sequence);
    }

    addStreamEvent(
      'ShotUpdated',
      `Shot #${updatedShot.sequence} state is ${updatedShot.state}`,
      { state: updatedShot.state, shotId: updatedShot.id },
    );
  }

  function handleJudgeVerdictRecorded(data)
  {
    const tag = failTagFrom(data.hardFailTags);
    const verdictDetail = `${data.stage} verdict: ${data.verdict.toUpperCase()}` +
      (tag ? ` (fail: ${tag})` : '');

    addStreamEvent('JudgeVerdictRecorded', verdictDetail, {
      verdict: data.verdict,
      shotId: data.shotId,
      stage: data.stage,
    });

    if (data.verdict === Verdict.FAIL)
    {
      judgeFailureTag.value = tag || 'failed';
    }
  }

  function subscribeToBrief(briefId)
  {
    if (!echo)
    {
      setupEcho();
    }

    if (!echo)
    {
      return;
    }

    detachChannelListeners(activeChannel);

    activeChannel = echo.channel(`briefs.${briefId}`);
    activeChannel.listen('.BriefStateChanged', (data) => handleBriefStateChanged(data, briefId));
    activeChannel.listen('.VideoProgressUpdated', handleVideoProgressUpdated);
    activeChannel.listen('.ShotUpdated', handleShotUpdated);
    activeChannel.listen('.JudgeVerdictRecorded', handleJudgeVerdictRecorded);
  }

  function noteShotRevision(shotId)
  {
    shotRevision += 1;
    shotRevisions.set(shotId, shotRevision);
  }

  function detachChannelListeners(channel)
  {
    if (!channel)
    {
      return;
    }

    channel.stopListening('.BriefStateChanged');
    channel.stopListening('.VideoProgressUpdated');
    channel.stopListening('.ShotUpdated');
    channel.stopListening('.JudgeVerdictRecorded');
  }

  function addStreamEvent(name, detail, extra = {})
  {
    const time = new Date().toLocaleTimeString();
    streamEvents.value = [...streamEvents.value, { name, detail, time, ...extra }];
  }

  function addRecoveredEvent(name, detail, extra = {})
  {
    const event = { name, ...extra };
    const duplicate = streamEvents.value.some((existing) =>
      existing.name === event.name
      && existing.state === event.state
      && existing.shotId === event.shotId
      && existing.progress === event.progress
      && existing.verdict === event.verdict
      && existing.stage === event.stage
    );

    if (!duplicate)
    {
      addStreamEvent(name, detail, extra);
    }
  }

  return {
    presets,
    promptInput,
    isSubmitting,
    errorMessage,
    activeBriefId,
    briefState,
    styleAnchor,
    audioPath,
    finalVideoPath,
    shots,
    shotVideoProgress,
    judgeFailureTag,
    isRegenerating,
    streamEvents,
    connectionStatus,
    isJudging,
    overallVideoProgress,
    submitBrief,
  };
}
