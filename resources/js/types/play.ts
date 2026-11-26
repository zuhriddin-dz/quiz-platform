/**
 * Wire types for the strict play loop.
 *
 * Note what is absent: nothing here carries a correct answer, a correct index
 * or the original option order. That is not an omission in the types — the
 * server never sends them (R5.5), and these types are the proof read at a
 * glance.
 */

export type FailureReason = 'wrong_answer' | 'timeout' | 'focus_lost';

export type PlayQuestion = {
    /** Zero-based cursor into the run. */
    index: number;
    /** One-based, for "Question 3 of 10". */
    number: number;
    prompt: string;
    /** In DISPLAY order. The original order is never sent. */
    options: string[];
};

export type StartedRun = {
    run: {
        id: string;
        mode: 'strict' | 'casual';
        question_count: number;
        seconds_per_question: number;
    };
    question: PlayQuestion;
};

/** Stats echoed back at the end of a run; null for guests. */
export type RunStats = {
    attempt: number | null;
    best_reached: number | null;
    passed: boolean | null;
};

export type CorrectVerdict = {
    verdict: 'correct';
    reached: number;
    question_count: number;
    question: PlayQuestion;
};

export type PassedVerdict = RunStats & {
    verdict: 'passed';
    reached: number;
    question_count: number;
};

export type FailedVerdict = RunStats & {
    verdict: 'failed';
    failure_reason: FailureReason;
    /** Which option the player clicked, so it can be highlighted. Null for a
     *  timeout or a focus loss, where nothing was clicked. */
    chosen_displayed_index: number | null;
    reached: number;
    question_count: number;
};

export type AnswerVerdict = CorrectVerdict | PassedVerdict | FailedVerdict;

/* ------------------------------------------------------------------ casual */

/**
 * R6.1 — the casual start payload. Everything at once, so play is instant.
 *
 * `options` are in ORIGINAL order; the client shuffles for display and keeps
 * its own mapping back, because the submitted log carries original indexes.
 *
 * `answer_hash` is sha256(salt + ":" + question_id + ":" + correct_option_text).
 * The salt is here too, which is what lets the browser check a click — and
 * also what lets a determined user read the answers. That is accepted: casual
 * results are labelled "self-reported".
 */
export type CasualQuestion = {
    id: number;
    prompt: string;
    options: string[];
    answer_hash: string;
};

export type StartedCasualRun = {
    run: {
        id: string;
        mode: 'casual';
        question_count: number;
        seconds_per_question: number;
        salt: string;
    };
    questions: CasualQuestion[];
};

/** One entry of the log posted to /runs/{run}/submit (R6.3). */
export type CasualAnswerEntry = {
    question_id: number;
    /** The ORIGINAL index, not the displayed one. Null with a reason. */
    chosen_option_index?: number | null;
    reason?: 'timeout' | 'focus_lost';
    time_ms: number;
};

export type CasualVerdict = RunStats & {
    verdict: 'passed' | 'failed';
    failure_reason: FailureReason | null;
    reached: number;
    question_count: number;
};

/** A question after the client has shuffled it for display. */
export type ShuffledQuestion = {
    id: number;
    prompt: string;
    /** Display order. */
    options: string[];
    /** Display position -> original index, so the log can report originals. */
    originalIndexes: number[];
    /** Display position -> whether that option is the correct one. */
    correctness: boolean[];
};

/** The play screen's state machine. */
export type PlayPhase =
    | 'idle'
    | 'countdown'
    | 'question'
    /** R5.8 — the network round trip. The timer is paused and focus loss is
     *  not fatal during this. */
    | 'checking'
    | 'passed'
    | 'failed';
