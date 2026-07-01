/**
 * Form field primitives for the Create Listing builder.
 *
 * Thin, accessible wrappers around native inputs that use the `cl-*` classes
 * (see pages/landlord/create-listing.css). Kept dependency-light so each step
 * stays declarative. All inputs surface validation/error + helper text and
 * forward the standard native props.
 */
import type { ReactNode, SelectHTMLAttributes, InputHTMLAttributes, TextareaHTMLAttributes } from 'react';

function Chevron() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="m6 9 6 6 6-6" />
    </svg>
  );
}

interface FieldProps {
  label: string;
  required?: boolean;
  htmlFor?: string;
  error?: string;
  help?: string;
  /** Right-aligned counter text, e.g. "12/100". */
  counter?: string;
  children: ReactNode;
}

export function Field({ label, required, htmlFor, error, help, counter, children }: FieldProps) {
  return (
    <div className="cl-field">
      <div className="cl-label-row">
        <label className="cl-label" htmlFor={htmlFor}>
          {label}
          {required && <span className="cl-req" aria-hidden="true">*</span>}
        </label>
        {counter && <span className="cl-counter">{counter}</span>}
      </div>
      {children}
      {error ? <span className="cl-error" role="alert">{error}</span> : help ? <span className="cl-help">{help}</span> : null}
    </div>
  );
}

export function TextInput({
  invalid,
  prefix,
  suffix,
  className = '',
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean; prefix?: string; suffix?: string }) {
  const cls = `cl-input${invalid ? ' is-invalid' : ''}${prefix ? ' has-prefix' : ''}${suffix ? ' has-suffix' : ''} ${className}`.trim();
  if (prefix || suffix) {
    return (
      <span className="cl-input-wrap">
        {prefix && <span className="cl-input-prefix">{prefix}</span>}
        <input className={cls} aria-invalid={invalid || undefined} {...props} />
        {suffix && <span className="cl-input-suffix">{suffix}</span>}
      </span>
    );
  }
  return <input className={cls} aria-invalid={invalid || undefined} {...props} />;
}

export function Textarea({
  invalid,
  ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }) {
  return <textarea className={`cl-textarea${invalid ? ' is-invalid' : ''}`} aria-invalid={invalid || undefined} {...props} />;
}

interface NativeSelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  invalid?: boolean;
  placeholder?: string;
  options: Array<{ value: string; label: string }>;
}

export function NativeSelect({ invalid, placeholder, options, value, ...props }: NativeSelectProps) {
  return (
    <span className="cl-select-wrap">
      <select className={`cl-select${invalid ? ' is-invalid' : ''}`} aria-invalid={invalid || undefined} value={value} {...props}>
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
      <Chevron />
    </span>
  );
}
