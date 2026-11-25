import { router, useForm } from '@inertiajs/react';
import { PROVIDER_LABEL, ProviderLogo } from '@/Components/Auth/SocialButtons';
import { Field } from '@/Components/Field';
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

    const needsPassword = !passwordConfirmed;
    const confirmLink = `/user/confirm-password?return=${encodeURIComponent('/settings/security#two-factor')}`;

    return (
        <Panel id="two-factor" title={t('security.two_factor.title')} description={t('security.two_factor.hint')}>
            <p className="mb-4 flex items-center gap-2">
                <Badge ok={twoFactor.enabled}>{twoFactor.enabled ? t('security.two_factor.on') : t('security.two_factor.off')}</Badge>
                {twoFactor.staffRequired && !twoFactor.enabled && <span className="text-sm font-semibold text-danger">{t('security.two_factor.staff_required')}</span>}
            </p>

            {needsPassword && (twoFactor.enabled || !twoFactor.pendingConfirmation) ? (
                <a href={confirmLink} className="btn-outline-pill">
                    {t('security.two_factor.confirm_password_first')}
                </a>
            ) : null}

            {!needsPassword && !twoFactor.enabled && !twoFactor.pendingConfirmation && (
                <button type="button" className="btn-primary" disabled={busy.processing} onClick={() => busy.post('/user/two-factor-authentication', options)}>
                    {t('security.two_factor.enable')}
                </button>
            )}

            {twoFactor.pendingConfirmation && (
                <div className="grid gap-6 md:grid-cols-[auto_1fr]">
                    {twoFactor.qrSvg && (
                        // SVG generated server-side by Fortify from the user's own secret (no user content).
                        <div className="h-48 w-48 rounded-md border border-line bg-white p-2" dangerouslySetInnerHTML={{ __html: twoFactor.qrSvg }} />
                    )}
                    <div>
                        <p className="text-sm">{t('security.two_factor.scan')}</p>
                        {twoFactor.secret && (
                            <p className="mt-2 text-sm">
                                {t('security.two_factor.secret')}: <code className="rounded bg-canvas px-1.5 py-0.5 font-mono">{twoFactor.secret}</code>
                            </p>
                        )}
                        <form
                            className="mt-4 flex flex-wrap items-end gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                confirm.post('/user/confirmed-two-factor-authentication', { ...options, errorBag: 'confirmTwoFactorAuthentication', onSuccess: () => confirm.reset() });
                            }}
                        >
                            <Field
                                label={t('auth.two_factor.code')}
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
                                {t('security.two_factor.confirm')}
                            </button>
                        </form>
                    </div>
                </div>
            )}

            {twoFactor.recoveryCodes && (
                <div className="mt-6 rounded-md border-s-4 border-warning bg-warning-soft p-4">
                    <p className="text-sm font-semibold">{t('security.two_factor.recovery_save')}</p>
                    <ul className="mt-2 grid grid-cols-2 gap-1 font-mono text-sm">
                        {twoFactor.recoveryCodes.map((c) => (
                            <li key={c}>{c}</li>
                        ))}
                    </ul>
                </div>
            )}

            {twoFactor.enabled && !needsPassword && (
                <div className="mt-4 flex flex-wrap gap-3">
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
        </Panel>
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
