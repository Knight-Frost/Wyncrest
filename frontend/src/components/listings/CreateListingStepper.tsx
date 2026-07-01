/**
 * CreateListingStepper — the vertical step navigation.
 *
 * Shows all six steps with numbered circles (active = filled, completed = check,
 * upcoming = outline). A step is clickable only if it has already been reached
 * (<= furthest reached step) so the landlord can jump back without skipping
 * ahead past unsaved work. Keyboard accessible (real buttons, tablist roles).
 */
import { STEPS } from './types';

interface CreateListingStepperProps {
  /** 1-based index of the active step. */
  current: number;
  /** Highest step the user has reached (so earlier steps are navigable). */
  reached: number;
  onJump: (index: number) => void;
}

function CheckIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}

export function CreateListingStepper({ current, reached, onJump }: CreateListingStepperProps) {
  return (
    <nav className="cl-stepper" aria-label="Create listing steps">
      {STEPS.map((s) => {
        const isActive = s.index === current;
        const isDone = s.index < current;
        const navigable = s.index <= reached;
        return (
          <button
            key={s.key}
            type="button"
            className={`cl-step${isActive ? ' is-active' : ''}${isDone ? ' is-done' : ''}`}
            onClick={() => navigable && onJump(s.index)}
            disabled={!navigable}
            aria-current={isActive ? 'step' : undefined}
          >
            <span className="cl-step-num">{isDone ? <CheckIcon /> : s.index}</span>
            <span className="cl-step-txt">
              <span className="cl-step-name">{s.name}</span>
              <span className="cl-step-desc">{s.desc}</span>
            </span>
          </button>
        );
      })}
    </nav>
  );
}
