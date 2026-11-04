export const BroadcastEvent = Object.freeze({
  BRIEF_STATE_CHANGED: 'BriefStateChanged',
  VIDEO_PROGRESS_UPDATED: 'VideoProgressUpdated',
  SHOT_UPDATED: 'ShotUpdated',
  JUDGE_VERDICT_RECORDED: 'JudgeVerdictRecorded',
});

const IDENTITY_FIELDS = ['state', 'shotId', 'progress', 'verdict', 'stage'];

export function eventIdentity(event)
{
  const parts = [event.name];

  for (const field of IDENTITY_FIELDS)
  {
    parts.push(event[field] ?? '');
  }

  return parts.join('|');
}

export function listenToBriefChannel(channel, handlers)
{
  for (const [name, handler] of Object.entries(handlers))
  {
    channel.listen(`.${name}`, handler);
  }
}

export function stopBriefChannel(channel)
{
  if (!channel)
  {
    return;
  }

  for (const name of Object.values(BroadcastEvent))
  {
    channel.stopListening(`.${name}`);
  }
}
