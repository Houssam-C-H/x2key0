import type { InputHTMLAttributes, ReactNode } from 'react';

interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
    label: string;
    error?: string;
    hint?: ReactNode;
}

/** Labelled input with error and hint text, wired for accessibility. */
export function Field({ label, error, hint, id, name, className, ...input }: FieldProps) {
    const fieldId = id ?? `f-${name}`;
    const describedBy = error ? `${fieldId}-error` : hint ? `${fieldId}-hint` : undefined;

    return (
        <label htmlFor={fieldId} className={`flex flex-col gap-1 ${className ?? ''}`}>
            <span className="font-medium">{label}</span>
            <input
                id={fieldId}
                name={name}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className={`field ${error ? 'border-danger' : ''}`}
                {...input}
            />
            {error ? (
                <span id={`${fieldId}-error`} className="text-sm text-danger">
                    {error}
                </span>
            ) : hint ? (
                <span id={`${fieldId}-hint`} className="text-xs text-muted">
                    {hint}
                </span>
            ) : null}
        </label>
    );
}

interface SelectFieldProps {
    label: string;
    name: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
    error?: string;
}

export function SelectField({ label, name, value, onChange, options, error }: SelectFieldProps) {
    return (
        <label htmlFor={`f-${name}`} className="flex flex-col gap-1">
            <span className="font-medium">{label}</span>
            <select id={`f-${name}`} name={name} className={`field ${error ? 'border-danger' : ''}`} value={value} onChange={(e) => onChange(e.target.value)}>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            {error && <span className="text-sm text-danger">{error}</span>}
        </label>
    );
}
