import pipelineStates from './pipeline-states.json';

// Values are generated from the PHP enums. See FrontendEnumCatalog.
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
