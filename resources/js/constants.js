import pipelineStates from './pipeline-states.json';

// Written by `php artisan frontend:export-states` from the PHP enums.
export const BriefState = Object.freeze(pipelineStates.brief);
export const ShotState = Object.freeze(pipelineStates.shot);
export const Verdict = Object.freeze(pipelineStates.verdict);

export const StageRank = Object.freeze({
  PENDING: 0,
  PLANNING: 1,
  KEYFRAMES: 2,
  JUDGING: 3,
  RENDERING: 4,
  AUDIO: 5,
  MUXING: 6,
  DONE: 7,
});
