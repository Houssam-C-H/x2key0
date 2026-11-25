import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PROVIDER_LABEL, ProviderLogo } from '@/Components/Auth/SocialButtons';
import { Field } from '@/Components/Field';
import { Icon } from '@/Components/Icon';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useT } from '@/lib/i18n';

interface Props {
    email: { address: string; verified: boolean };
    phone: { number: string | null; verified: boolean; pending: string | null };
    twoFactor: {
        enabled: boolean;
        pendingConfirmation: boolean;
        qrSvg: string | null;
        secret: string | null;
        recoveryCodes: string[] | null;
        staffRequired: boolean;
    };
    passwordConfirmed: boolean;
    privacyPassword: { set: boolean; setAt: string | null };
    sessions: { id: number; device: string; ip: string | null; lastActiveAt: string; current: boolean }[];
    loginHistory: { id: number; device: string; ip: string | null; succeeded: boolean; at: string }[];
    social: { provider: string; email: string | null; linked: boolean }[];
}

export default function Security(props: Props) {
    const t = useT();

    return (
        <DashboardLayout title={t('menu.settings.security')} bare>
            <EmailPanel email={props.email} />
            <PasswordPanel />
            <PhonePanel phone={props.phone} />
            <TwoFactorPanel twoFactor={props.twoFactor} passwordConfirmed={props.passwordConfirmed} />
            <PrivacyPasswordPanel privacy={props.privacyPassword} />
            <SocialPanel social={props.social} />
            <SessionsPanel sessions={props.sessions} />
            <HistoryPanel history={props.loginHistory} />
        </DashboardLayout>
    );
}

function Badge({ ok, children }: { ok: boolean; children: string }) {
    return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${ok ? 'bg-success/10 text-success' : 'bg-warning/15 text-warning'}`}>{children}</span>;
}

function EmailPanel({ email }: { email: Props['email'] }) {
    const t = useT();
    const form = useForm({ email: '', current_password: '' });
    const resend = useForm({});

    return (
        <Panel id="email" title={t('security.email.title')}>
            <p className="flex flex-wrap items-center gap-2">
                <span className="font-semibold">{email.address}</span>
                <Badge ok={email.verified}>{email.verified ? t('security.verified') : t('security.unverified')}</Badge>
                {!email.verified && (
                    <button type="button" className="text-sm text-primary hover:underline" disabled={resend.processing} onClick={() => resend.post('/email/verification-notification', { preserveScroll: true })}>
                        {t('auth.verify.resend')}
                    </button>
                )}
            </p>
            <form
                className="mt-4 grid gap-4 sm:grid-cols-3 sm:items-end"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/settings/security/email', { errorBag: 'changeEmail', preserveScroll: true, onSuccess: () => form.reset() });
                }}
            >
                <Field label={t('security.email.new')} name="new_email" type="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={form.errors.email} />
                <Field
                    label={t('auth.field.current_password')}
                    name="email_current_password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={form.data.current_password}
                    onChange={(e) => form.setData('current_password', e.target.value)}
                    error={form.errors.current_password}
                />
                <button type="submit" className="btn-primary" disabled={form.processing}>
                    {t('security.email.submit')}
                </button>
            </form>
        </Panel>
    );
}

function PasswordPanel() {
    const t = useT();
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    return (
        <Panel id="password" title={t('security.password.title')} description={t('security.password.hint')}>
            <form
                className="grid gap-4 sm:grid-cols-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/user/password', { errorBag: 'updatePassword', preserveScroll: true, onSuccess: () => form.reset() });
                }}
            >
                <Field
                    label={t('auth.field.current_password')}
                    name="current_password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={form.data.current_password}
                    onChange={(e) => form.setData('current_password', e.target.value)}
                    error={form.errors.current_password}
                />
                <Field
                    label={t('auth.field.new_password')}
                    name="password"
                    type="password"
                    autoComplete="new-password"
                    required
                    minLength={10}
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                />
                <Field
                    label={t('auth.field.password_confirmation')}
                    name="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.data.password_confirmation}
                    onChange={(e) => form.setData('password_confirmation', e.target.value)}
                />
                <div className="sm:col-span-3">
                    <button type="submit" className="btn-primary" disabled={form.processing}>
                        {t('security.password.submit')}
                    </button>
                </div>
            </form>
        </Panel>
    );
}

function PhonePanel({ phone }: { phone: Props['phone'] }) {
    const t = useT();
    const send = useForm({ phone: phone.pending ?? phone.number ?? '' });
    const confirm = useForm({ code: '' });

    return (
        <Panel id="phone" title={t('security.phone.title')}>
            {phone.number && (
                <p className="mb-4 flex items-center gap-2">
                    <span className="font-semibold">{phone.number}</span>
                    <Badge ok={phone.verified}>{phone.verified ? t('security.verified') : t('security.unverified')}</Badge>
                </p>
            )}
            <form
                className="grid gap-4 sm:grid-cols-3 sm:items-end"
                onSubmit={(e) => {
                    e.preventDefault();
                    send.post('/settings/security/phone/code', { errorBag: 'phone', preserveScroll: true });
                }}
            >
                <Field
                    label={t('security.phone.number')}
                    name="phone"
                    type="tel"
                    autoComplete="tel"
                    placeholder="+212600000000"
                    required
                    value={send.data.phone}
                    onChange={(e) => send.setData('phone', e.target.value)}
                    error={send.errors.phone}
                    hint={t('security.phone.format')}
                />
                <button type="submit" className="btn-outline-pill" disabled={send.processing}>
                    {t('security.phone.send')}
                </button>
            </form>
            {phone.pending && (
                <form
                    className="mt-4 grid gap-4 sm:grid-cols-3 sm:items-end"
                    onSubmit={(e) => {
                        e.preventDefault();
                        confirm.post('/settings/security/phone/confirm', { errorBag: 'phone', preserveScroll: true, onSuccess: () => confirm.reset() });
                    }}
                >
                    <Field
                        label={t('security.phone.code', { phone: phone.pending })}
                        name="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={6}
                        required
                        value={confirm.data.code}
                        onChange={(e) => confirm.setData('code', e.target.value)}
                        error={confirm.errors.code}
                    />
                    <button type="submit" className="btn-primary" disabled={confirm.processing}>
                        {t('security.phone.confirm')}
                    </button>
                </form>
            )}
        </Panel>
    );
}

function TwoFactorPanel({ twoFactor, passwordConfirmed }: { twoFactor: Props['twoFactor']; passwordConfirmed: boolean }) {
    const t = useT();
    const confirm = useForm({ code: '' });
    const busy = useForm({});
    const options = { preserveScroll: true } as const;
    const [copied, setCopied] = useState<string | null>(null);

    const needsPassword = !passwordConfirmed;
    const confirmLink = `/user/confirm-password?return=${encodeURIComponent('/settings/security#two-factor')}`;
    const copy = (key: string, text: string) => {
        void navigator.clipboard?.writeText(text).then(() => {
            setCopied(key);
            window.setTimeout(() => setCopied(null), 2000);
        });
    };
    const groupedSecret = (twoFactor.secret ?? '').replace(/\s+/g, '').replace(/(.{4})(?=.)/g, '$1 ');
    const downloadCodes = () => {
        const text = [t('security.two_factor.codes_file_title'), '', ...(twoFactor.recoveryCodes ?? [])].join('\n');
        const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
        const a = Object.assign(document.createElement('a'), { href: url, download: 'x2key-recovery-codes.txt' });
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <Panel id="two-factor" title={t('security.two_factor.title')} description={t('security.two_factor.hint')}>
            {twoFactor.staffRequired && !twoFactor.enabled && (
                <p className="mb-4 flex items-start gap-2 rounded-lg bg-danger/5 p-3 text-sm text-danger">
                    <Icon name="lock" size={18} className="mt-0.5 shrink-0" />
                    {t('security.two_factor.staff_required')}
                </p>
            )}

            {/* Off: explain, one button (password confirmation first when needed). */}
            {!twoFactor.enabled && !twoFactor.pendingConfirmation && (
                <div className="flex flex-wrap items-center gap-4 rounded-xl border border-line p-4">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary">
                        <Icon name="shield" size={22} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="flex items-center gap-2 font-semibold">
                            {t('security.two_factor.off_title')} <Badge ok={false}>{t('security.two_factor.off')}</Badge>
                        </p>
                        <p className="text-sm text-muted">{t('security.two_factor.off_text')}</p>
                    </div>
                    {needsPassword ? (
                        <a href={confirmLink} className="btn-primary">
                            {t('security.two_factor.enable')}
                        </a>
                    ) : (
                        <button type="button" className="btn-primary" disabled={busy.processing} onClick={() => busy.post('/user/two-factor-authentication', options)}>
                            {t('security.two_factor.enable')}
                        </button>
                    )}
                    {needsPassword && <p className="w-full text-xs text-muted">{t('security.two_factor.password_note')}</p>}
                </div>
            )}

            {/* Setup: three clear steps. */}
            {twoFactor.pendingConfirmation && (
                <ol className="space-y-6">
                    <SetupStep n={1} title={t('security.two_factor.step_app')}>
                        <p className="text-sm text-muted">{t('security.two_factor.step_app_text')}</p>
                    </SetupStep>

                    <SetupStep n={2} title={t('security.two_factor.step_scan')}>
                        <div className="flex flex-wrap items-start gap-5">
                            {twoFactor.qrSvg && (
                                // SVG generated server-side by Fortify from the user's own secret (no user content).
                                <div
                                    className="h-44 w-44 shrink-0 rounded-xl border border-line bg-white p-3 [&>svg]:h-full [&>svg]:w-full"
                                    aria-label={t('security.two_factor.qr_label')}
                                    role="img"
                                    dangerouslySetInnerHTML={{ __html: twoFactor.qrSvg }}
                                />
                            )}
                            {twoFactor.secret && (
                                <div className="min-w-0 flex-1 space-y-2">
                                    <p className="text-sm text-muted">{t('security.two_factor.cant_scan')}</p>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <code className="rounded-lg bg-canvas px-3 py-2 font-mono text-base tracking-wider">{groupedSecret}</code>
                                        <button type="button" className="btn-ghost text-sm" onClick={() => copy('secret', (twoFactor.secret ?? '').replace(/\s+/g, ''))}>
                                            <Icon name={copied === 'secret' ? 'check' : 'copy'} size={15} />
                                            {copied === 'secret' ? t('security.two_factor.copied') : t('security.two_factor.copy')}
                                        </button>
                                    </div>
                                    <p className="text-xs text-muted">{t('security.two_factor.key_note')}</p>
                                </div>
                            )}
                        </div>
                    </SetupStep>

                    <SetupStep n={3} title={t('security.two_factor.step_code')} last>
                        <form
                            className="flex flex-wrap items-start gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                confirm.post('/user/confirmed-two-factor-authentication', { ...options, errorBag: 'confirmTwoFactorAuthentication', onSuccess: () => confirm.reset() });
                            }}
                        >
                            <div>
                                <input
                                    name="code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    pattern="[0-9]{6}"
                                    maxLength={6}
                                    required
                                    placeholder="000000"
                                    aria-label={t('auth.two_factor.code')}
                                    value={confirm.data.code}
                                    onChange={(e) => confirm.setData('code', e.target.value.replace(/\D/g, '').slice(0, 6))}
                                    className="field h-12 w-48 text-center font-mono text-2xl tracking-[0.4em]"
                                />
                                {confirm.errors.code && <p className="mt-1 text-sm text-danger">{confirm.errors.code}</p>}
                            </div>
                            <button type="submit" className="btn-primary h-12 px-6" disabled={confirm.processing || confirm.data.code.length !== 6}>
                                {t('security.two_factor.confirm')}
                            </button>
                            <button type="button" className="btn-ghost h-12" disabled={busy.processing} onClick={() => busy.delete('/user/two-factor-authentication', options)}>
                                {t('security.two_factor.cancel_setup')}
                            </button>
                        </form>
                        <p className="mt-2 text-xs text-muted">{t('security.two_factor.code_note')}</p>
                    </SetupStep>
                </ol>
            )}

            {/* On: status and management. */}
            {twoFactor.enabled && (
                <div className="flex flex-wrap items-center gap-4 rounded-xl border border-success/30 bg-success/5 p-4">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-success text-white">
                        <Icon name="shield" size={22} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="flex items-center gap-2 font-semibold">
                            {t('security.two_factor.on_title')} <Badge ok>{t('security.two_factor.on')}</Badge>
                        </p>
                        <p className="text-sm text-muted">{t('security.two_factor.on_text')}</p>
                    </div>
                    {needsPassword ? (
                        <a href={confirmLink} className="btn-outline-pill">
                            {t('security.two_factor.confirm_password_first')}
                        </a>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            <button type="button" className="btn-outline-pill" disabled={busy.processing} onClick={() => busy.post('/user/two-factor-recovery-codes', options)}>
                                {t('security.two_factor.regenerate')}
                            </button>
                            {!twoFactor.staffRequired && (
                                <button
                                    type="button"
                                    className="btn bg-danger/10 text-danger hover:bg-danger/20"
                                    disabled={busy.processing}
                                    onClick={() => window.confirm(t('security.two_factor.disable_confirm')) && busy.delete('/user/two-factor-authentication', options)}
                                >
                                    {t('security.two_factor.disable')}
                                </button>
                            )}
                        </div>
                    )}
                </div>
            )}

            {/* Recovery codes: shown right after setup or regeneration. */}
            {twoFactor.recoveryCodes && (
                <div className="mt-5 rounded-xl border border-warning/40 bg-warning-soft p-4">
                    <p className="flex items-center gap-2 font-semibold">
                        <Icon name="lock" size={18} className="text-warning-ink" />
                        {t('security.two_factor.step_save')}
                    </p>
                    <p className="mt-1 text-sm">{t('security.two_factor.recovery_save')}</p>
                    <ul className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {twoFactor.recoveryCodes.map((c) => (
                            <li key={c} className="rounded-md bg-white px-2 py-1.5 text-center font-mono text-sm ring-1 ring-line">
                                {c}
                            </li>
                        ))}
                    </ul>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button type="button" className="btn-outline-pill" onClick={() => copy('codes', (twoFactor.recoveryCodes ?? []).join('\n'))}>
                            {copied === 'codes' ? t('security.two_factor.copied') : t('security.two_factor.copy_codes')}
                        </button>
                        <button type="button" className="btn-outline-pill" onClick={downloadCodes}>
                            {t('security.two_factor.download_codes')}
                        </button>
                    </div>
                    <p className="mt-2 text-xs text-muted">{t('security.two_factor.recovery_where')}</p>
                </div>
            )}
        </Panel>
    );
}

/** One numbered step of the 2FA setup, with a line joining it to the next. */
function SetupStep({ n, title, last = false, children }: { n: number; title: string; last?: boolean; children: React.ReactNode }) {
    return (
        <li className="relative flex gap-4">
            {!last && <span aria-hidden="true" className="absolute start-4 top-9 -bottom-6 w-px bg-line" />}
            <span className="relative flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-white">{n}</span>
            <div className="min-w-0 flex-1 pt-1">
                <p className="mb-2 font-semibold">{title}</p>
                {children}
            </div>
        </li>
    );
}

function PrivacyPasswordPanel({ privacy }: { privacy: Props['privacyPassword'] }) {
    const t = useT();
    const form = useForm({ current_password: '', privacy_password: '', privacy_password_confirmation: '' });

    return (
        <Panel id="privacy-password" title={t('security.privacy.title')} description={t('security.privacy.hint')}>
            <p className="mb-4">
                <Badge ok={privacy.set}>{privacy.set ? t('security.privacy.is_set') : t('security.privacy.not_set')}</Badge>
            </p>
            <form
                className="grid gap-4 sm:grid-cols-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/settings/security/privacy-password', { errorBag: 'privacyPassword', preserveScroll: true, onSuccess: () => form.reset() });
                }}
            >
                <Field
                    label={t('auth.field.current_password')}
                    name="privacy_current_password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={form.data.current_password}
                    onChange={(e) => form.setData('current_password', e.target.value)}
                    error={form.errors.current_password}
                />
                <Field
                    label={t('security.privacy.new')}
                    name="privacy_password"
                    type="password"
                    autoComplete="new-password"
                    required
                    minLength={8}
                    value={form.data.privacy_password}
                    onChange={(e) => form.setData('privacy_password', e.target.value)}
                    error={form.errors.privacy_password}
                />
                <Field
                    label={t('auth.field.password_confirmation')}
                    name="privacy_password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.data.privacy_password_confirmation}
                    onChange={(e) => form.setData('privacy_password_confirmation', e.target.value)}
                />
                <div className="sm:col-span-3">
                    <button type="submit" className="btn-primary" disabled={form.processing}>
                        {privacy.set ? t('security.privacy.change') : t('security.privacy.set')}
                    </button>
                </div>
            </form>
        </Panel>
    );
}

/** Connected accounts (ADR-033): connect goes through the provider; disconnect keeps the password login. */
function SocialPanel({ social }: { social: Props['social'] }) {
    const t = useT();
    if (social.length === 0) return null;

    return (
        <Panel id="connected" title={t('security.social.title')} description={t('security.social.hint')}>
            <ul className="divide-y divide-line">
                {social.map((s) => (
                    <li key={s.provider} className="flex flex-wrap items-center gap-3 py-3">
                        <ProviderLogo provider={s.provider} size={22} />
                        <span className="min-w-0 flex-1">
                            <span className="block font-semibold">{PROVIDER_LABEL[s.provider] ?? s.provider}</span>
                            <span className="block truncate text-sm text-muted">{s.linked ? (s.email ?? '—') : t('security.social.not_connected')}</span>
                        </span>
                        {s.linked ? (
                            <button
                                type="button"
                                className="btn-ghost text-sm"
                                onClick={() => router.delete(`/settings/security/social/${s.provider}`, { preserveScroll: true })}
                            >
                                {t('security.social.disconnect')}
                            </button>
                        ) : (
                            <a href={`/auth/${s.provider}/redirect`} className="btn-primary px-4 text-sm">
                                {t('security.social.connect')}
                            </a>
                        )}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

function SessionsPanel({ sessions }: { sessions: Props['sessions'] }) {
    const t = useT();
    const others = useForm({ current_password: '' });

    return (
        <Panel id="sessions" title={t('security.sessions.title')} description={t('security.sessions.hint')}>
            <ul className="divide-y divide-line">
                {sessions.map((s) => (
                    <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div>
                            <p className="font-semibold">
                                {s.device} {s.current && <span className="ms-1 rounded-full bg-primary-soft px-2 py-0.5 text-xs text-primary">{t('security.sessions.current')}</span>}
                            </p>
                            <p className="text-xs text-muted">
                                {s.ip ?? '—'} · {new Date(s.lastActiveAt).toLocaleString()}
                            </p>
                        </div>
                        {!s.current && (
                            <button type="button" className="text-sm text-danger hover:underline" onClick={() => router.delete(`/settings/security/sessions/${s.id}`, { preserveScroll: true })}>
                                {t('security.sessions.revoke')}
                            </button>
                        )}
                    </li>
                ))}
            </ul>
            <form
                className="mt-4 flex flex-wrap items-end gap-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    others.post('/settings/security/sessions/revoke-others', { preserveScroll: true, onSuccess: () => others.reset() });
                }}
            >
                <Field
                    label={t('auth.field.current_password')}
                    name="sessions_current_password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={others.data.current_password}
                    onChange={(e) => others.setData('current_password', e.target.value)}
                    error={others.errors.current_password}
                />
                <button type="submit" className="btn-outline-pill" disabled={others.processing}>
                    {t('security.sessions.revoke_others')}
                </button>
            </form>
        </Panel>
    );
}

function HistoryPanel({ history }: { history: Props['loginHistory'] }) {
    const t = useT();

    return (
        <Panel id="history" title={t('security.history.title')}>
            {history.length === 0 ? (
                <p className="text-muted">{t('security.history.empty')}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-start text-xs text-muted">
                            <tr>
                                <th className="py-2 text-start font-medium">{t('security.history.when')}</th>
                                <th className="py-2 text-start font-medium">{t('security.history.device')}</th>
                                <th className="py-2 text-start font-medium">IP</th>
                                <th className="py-2 text-start font-medium">{t('security.history.result')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {history.map((h) => (
                                <tr key={h.id}>
                                    <td className="py-2">{new Date(h.at).toLocaleString()}</td>
                                    <td className="py-2">{h.device}</td>
                                    <td className="py-2 font-mono text-xs">{h.ip ?? '—'}</td>
                                    <td className="py-2">
                                        <Badge ok={h.succeeded}>{h.succeeded ? t('security.history.ok') : t('security.history.failed')}</Badge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}
