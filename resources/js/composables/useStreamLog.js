import { ref } from 'vue';
import { BriefState, Verdict } from '../constants';
import { BroadcastEvent, eventIdentity } from '../broadcastEvents';
import { failTagFrom, latestJudgement } from '../shotJudgement';

export function useStreamLog()
{
  const streamEvents = ref([]);

  function addStreamEvent(name, detail, extra = {})
  {
    const time = new Date().toLocaleTimeString();
    streamEvents.value = [...streamEvents.value, { name, detail, time, ...extra }];
  }

  function addRecoveredEvent(name, detail, extra = {})
  {
    const event = { name, ...extra };
    const duplicate = streamEvents.value.some((existing) => eventIdentity(existing) === eventIdentity(event));

    if (!duplicate)
    {
      addStreamEvent(name, detail, extra);
    }
  }

  function clearStream()
  {
    streamEvents.value = [];
  }

  function seedStream(brief, appliedShots, progressForShot)
  {
    if (brief.state && brief.state !== BriefState.PENDING)
    {
      addRecoveredEvent(
        BroadcastEvent.BRIEF_STATE_CHANGED,
        `Brief state is ${brief.state}`,
        { state: brief.state },
      );
    }

    for (const shot of appliedShots)
    {
      seedShot(shot, progressForShot(shot.id));
    }
  }

  function seedShot(shot, progress)
  {
    addRecoveredEvent(
      BroadcastEvent.SHOT_UPDATED,
      `Shot #${shot.sequence} state is ${shot.state}`,
      { state: shot.state, shotId: shot.id },
    );

    if (progress > 0)
    {
      addRecoveredEvent(
        BroadcastEvent.VIDEO_PROGRESS_UPDATED,
        `Shot #${shot.sequence} video progress ${progress}%`,
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
      BroadcastEvent.JUDGE_VERDICT_RECORDED,
      detail,
      { verdict: Verdict.FAIL, shotId: shot.id, stage: latest.stage },
    );
  }

  return {
    streamEvents,
    addStreamEvent,
    clearStream,
    seedStream,
  };
}
