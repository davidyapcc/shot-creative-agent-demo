export const BriefState = Object.freeze({
  PENDING: 'pending',
  PLANNING: 'planning',
  KEYFRAMES: 'keyframes',
  RENDERING: 'rendering',
  AUDIO: 'audio',
  MUXING: 'muxing',
  DONE: 'done',
  FAILED: 'failed',
});

export const ShotState = Object.freeze({
  PENDING: 'pending',
  GENERATING_IMAGE: 'generating_image',
  JUDGING_IMAGE: 'judging_image',
  GENERATING_VIDEO: 'generating_video',
  JUDGING_VIDEO: 'judging_video',
  COMPLETED: 'completed',
  FAILED: 'failed',
});

export const Verdict = Object.freeze({
  PASS: 'pass',
  FAIL: 'fail',
});

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
