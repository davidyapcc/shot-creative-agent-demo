import { Verdict } from './constants';

export const UNKNOWN_FAIL_TAG = 'failed';

export function failTagFrom(tags)
{
  return Array.isArray(tags) && tags.length > 0 ? tags[0] : null;
}

export function latestJudgement(shot)
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

export function failTagFor(judgement)
{
  if (!judgement || judgement.verdict !== Verdict.FAIL)
  {
    return null;
  }

  return failTagFrom(judgement.hard_fail_tags) ?? UNKNOWN_FAIL_TAG;
}
