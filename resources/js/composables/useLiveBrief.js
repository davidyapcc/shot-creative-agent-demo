import { computed, onMounted, ref } from 'vue';
import { fetchBrief, fetchTelemetry, postBrief } from '../briefApi';
import { BroadcastEvent } from '../broadcastEvents';
import { BriefState, ShotState } from '../constants';
import { failTagFor, failTagFrom, latestJudgement } from '../shotJudgement';
import { emptyTelemetry, normalizeTelemetry } from '../telemetry';
import { useBriefChannel } from './useBriefChannel';
import { useStreamLog } from './useStreamLog';

const MAX_PROMPT_PREVIEW_LENGTH = 35;
const COMPLETED_VIDEO_PROGRESS = 100;

const STATES_THAT_REFRESH = new Set([
  BriefState.AUDIO,
  BriefState.MUXING,
  BriefState.DONE,
  BriefState.DEGRADED,
]);

export function useLiveBrief()
{
  const promptInput = ref('');
  const isSubmitting = ref(false);
  const errorMessage = ref(null);

  const briefState = ref(BriefState.PENDING);
  const shots = ref([]);
  const shotVideoProgress = ref({});
  const judgeFailureTag = ref(null);
  // Issue #6 owns retries. Leave this false so a fail tag does not look like a retry.
  const isRegenerating = ref(false);

  let shotRevision = 0;
  let briefRevision = 0;
  let syncSerial = 0;
  let telemetrySerial = 0;
  const shotRevisions = new Map();

  const { streamEvents, addStreamEvent, clearStream, seedStream } = useStreamLog();
  const telemetry = ref(emptyTelemetry());

  const { subscribe, subscribeTelemetry } = useBriefChannel((error) =>
  {
    errorMessage.value = error.message;
  });

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
    subscribeTelemetry({
      [BroadcastEvent.TELEMETRY_UPDATED]: (payload) =>
      {
        telemetrySerial += 1;
        telemetry.value = normalizeTelemetry(payload?.data);
      },
    });
    loadTelemetry();

    const params = new URLSearchParams(window.location.search);
    const briefId = params.get('brief_id');
    if (!briefId)
    {
      return;
    }

    const id = Number(briefId);
    subscribeToBrief(id);
    syncBrief(id);
  });

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
      addStreamEvent(
        'BriefSubmitted',
        `Brief #${brief.id} enqueued: "${preview}..."`,
      );

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

  function resetActiveState(brief)
  {
    briefState.value = brief.state;
    shots.value = [];
    shotVideoProgress.value = {};
    judgeFailureTag.value = null;
    isRegenerating.value = false;
    clearStream();
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
      seedStream(
        { ...brief, state: briefState.value },
        appliedShots,
        (shotId) => shotVideoProgress.value[shotId] ?? 0,
      );
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

  function applyBriefRecord(brief, briefRevisionAtStart)
  {
    if (briefRevision === briefRevisionAtStart)
    {
      briefState.value = brief.state;
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

    shotVideoProgress.value = progressForShots(applied);
    shots.value = applied;
    refreshRegenerating(applied);

    return applied;
  }

  function progressForShots(applied)
  {
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

    return nextProgress;
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
      const tag = failTagFor(latest);
      if (tag)
      {
        nextTag = tag;
        break;
      }
    }

    if (nextTag || sawJudgement)
    {
      judgeFailureTag.value = nextTag;
    }
  }

  function handleBriefStateChanged(data, briefId)
  {
    briefRevision += 1;
    briefState.value = data.to;
    addStreamEvent(
      BroadcastEvent.BRIEF_STATE_CHANGED,
      `Transitioned from ${data.from} to ${data.to}`,
      { state: data.to },
    );

    if (STATES_THAT_REFRESH.has(data.to))
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
      BroadcastEvent.VIDEO_PROGRESS_UPDATED,
      progressDetail(data.shotId, data.progress),
      { shotId: data.shotId, progress: data.progress },
    );
  }

  function progressDetail(shotId, progress)
  {
    const shot = shots.value.find((item) => item.id === shotId);
    const number = shot?.sequence ?? shotId;

    return `Shot #${number} video progress ${progress}%`;
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
      BroadcastEvent.SHOT_UPDATED,
      `Shot #${updatedShot.sequence} state is ${updatedShot.state}`,
      { state: updatedShot.state, shotId: updatedShot.id },
    );
    refreshRegenerating(shots.value);
  }

  function refreshRegenerating(shotList)
  {
    isRegenerating.value = shotList.some((shot) =>
      Number(shot.attempts) > 1 && (
        shot.state === ShotState.GENERATING_IMAGE
        || shot.state === ShotState.GENERATING_VIDEO
      )
    );
  }

  function handleJudgeVerdictRecorded(data)
  {
    const tag = failTagFrom(data.hardFailTags);
    const verdictDetail = `${data.stage} verdict: ${data.verdict.toUpperCase()}` +
      (tag ? ` (fail: ${tag})` : '');

    addStreamEvent(BroadcastEvent.JUDGE_VERDICT_RECORDED, verdictDetail, {
      verdict: data.verdict,
      shotId: data.shotId,
      stage: data.stage,
    });

    const failureTag = failTagFor({
      verdict: data.verdict,
      hard_fail_tags: data.hardFailTags,
    });
    if (failureTag)
    {
      judgeFailureTag.value = failureTag;
    }
  }

  function subscribeToBrief(briefId)
  {
    subscribe(briefId, {
      [BroadcastEvent.BRIEF_STATE_CHANGED]: (data) => handleBriefStateChanged(data, briefId),
      [BroadcastEvent.VIDEO_PROGRESS_UPDATED]: handleVideoProgressUpdated,
      [BroadcastEvent.SHOT_UPDATED]: handleShotUpdated,
      [BroadcastEvent.JUDGE_VERDICT_RECORDED]: handleJudgeVerdictRecorded,
    });
  }

  function noteShotRevision(shotId)
  {
    shotRevision += 1;
    shotRevisions.set(shotId, shotRevision);
  }

  async function loadTelemetry()
  {
    const serial = ++telemetrySerial;

    try
    {
      const next = normalizeTelemetry(await fetchTelemetry());
      if (serial !== telemetrySerial)
      {
        return;
      }

      telemetry.value = next;
    }
    catch (error)
    {
      console.error('Failed to load quality telemetry', error);
    }
  }

  return {
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
  };
}
