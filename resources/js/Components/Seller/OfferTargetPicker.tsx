import { Link } from "@inertiajs/react";
import { useMemo, useState } from "react";
import { GameArt } from "@/Components/Home/HomeParts";
import { categoryIcon, Icon } from "@/Components/Icon";
import { useShared, useT } from "@/lib/i18n";

export interface OfferTargetService {
    id: number;
    title: string;
    typeCode: string;
    game: string;
}

interface Props {
    services: OfferTargetService[];
    /** Chosen game×category id ('' while nothing is chosen). */
    value: number | "";
    editing: boolean;
    onPick: (gameCategoryId: number) => void;
}

/**
 * Create offer, step 1 and 2: the category first (all category types, with how many of the seller's approved games
 * are in each), then the game inside it. Categories without an approved game point to the service application page.
 */
export function OfferTargetPicker({ services, value, editing, onPick }: Props) {
    const t = useT();
    const { categoryTypes } = useShared();
    const chosen = services.find((s) => s.id === value) ?? null;
    const [type, setType] = useState<string | null>(chosen?.typeCode ?? null);
    const [q, setQ] = useState("");

    const perType = useMemo(() => {
        const map: Record<string, OfferTargetService[]> = {};
        for (const s of services) (map[s.typeCode] ??= []).push(s);
        return map;
    }, [services]);

    const games = (type ? (perType[type] ?? []) : [])
        .filter((s) => s.game.toLowerCase().includes(q.trim().toLowerCase()))
        .sort((a, b) => a.game.localeCompare(b.game));
    const typeLabel = categoryTypes.find((c) => c.code === type)?.label ?? "";

    if (editing && chosen) {
        return (
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <Step
                    n={1}
                    done
                    label={
                        categoryTypes.find((c) => c.code === chosen.typeCode)
                            ?.label ?? chosen.typeCode
                    }
                />
                <Icon
                    name="chevron"
                    size={14}
                    className="text-muted rtl:rotate-180"
                />
                <Step n={2} done label={chosen.game} />
                <span className="text-xs text-muted">
                    {t("offer.form.target_locked")}
                </span>
            </div>
        );
    }

    return (
        <div className="space-y-5">
            {/* Step 1 — category */}
            <section>
                <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold">
                    <StepBadge n={1} done={type !== null} />
                    {t("offer.form.step_category")}
                </h3>
                <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    {categoryTypes.map((c) => {
                        const count = perType[c.code]?.length ?? 0;
                        const on = c.code === type;
                        return (
                            <li key={c.code}>
                                <button
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() => {
                                        setType(c.code);
                                        setQ("");
                                    }}
                                    className={
                                        "flex w-full items-center gap-2.5 rounded-lg border p-2.5 text-start transition " +
                                        (on
                                            ? "border-primary bg-primary-soft"
                                            : count === 0
                                              ? "border-line bg-canvas/60 text-muted hover:border-line"
                                              : "border-line bg-white hover:border-primary/50")
                                    }
                                >
                                    <span
                                        className={
                                            "flex h-8 w-8 shrink-0 items-center justify-center rounded-md " +
                                            (on
                                                ? "bg-primary text-white"
                                                : "bg-white text-primary ring-1 ring-line")
                                        }
                                    >
                                        <Icon
                                            name={categoryIcon[c.code] ?? "box"}
                                            size={16}
                                        />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span
                                            className={
                                                "block truncate text-sm font-semibold " +
                                                (on ? "text-primary" : "")
                                            }
                                        >
                                            {c.label}
                                        </span>
                                        <span className="block text-[11px] text-muted">
                                            {t("offer.form.approved_games", {
                                                count,
                                            })}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </section>

            {/* Step 2 — game */}
            {type !== null && (
                <section>
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h3 className="flex items-center gap-2 text-sm font-semibold">
                            <StepBadge
                                n={2}
                                done={
                                    chosen !== null && chosen.typeCode === type
                                }
                            />
                            {t("offer.form.step_game", { category: typeLabel })}
                        </h3>
                        {(perType[type]?.length ?? 0) > 8 && (
                            <label className="relative">
                                <Icon
                                    name="search"
                                    size={15}
                                    className="pointer-events-none absolute start-2.5 top-1/2 -translate-y-1/2 text-muted"
                                />
                                <input
                                    type="search"
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder={t("offer.form.find_game")}
                                    aria-label={t("offer.form.find_game")}
                                    className="field h-9 w-56 ps-8 text-sm"
                                />
                            </label>
                        )}
                    </div>

                    {(perType[type]?.length ?? 0) === 0 ? (
                        <p className="rounded-lg border border-dashed border-line p-4 text-sm text-muted">
                            {t("offer.form.no_games_in_category", {
                                category: typeLabel,
                            })}{" "}
                            <Link
                                href="/seller/services"
                                className="font-semibold text-primary hover:underline"
                            >
                                {t("offer.form.apply_for_games")}
                            </Link>
                        </p>
                    ) : (
                        <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {games.map((s) => {
                                const on = s.id === value;
                                return (
                                    <li key={s.id}>
                                        <button
                                            type="button"
                                            aria-pressed={on}
                                            onClick={() => onPick(s.id)}
                                            className={
                                                "flex w-full items-center gap-3 rounded-lg border p-2 text-start transition " +
                                                (on
                                                    ? "border-primary bg-primary-soft"
                                                    : "border-line bg-white hover:border-primary/50")
                                            }
                                        >
                                            <span className="h-9 w-9 shrink-0 overflow-hidden rounded-md">
                                                <GameArt
                                                    name={s.game}
                                                    compact
                                                />
                                            </span>
                                            <span
                                                className={
                                                    "min-w-0 flex-1 truncate text-sm font-semibold " +
                                                    (on ? "text-primary" : "")
                                                }
                                            >
                                                {s.game}
                                            </span>
                                            {on && (
                                                <Icon
                                                    name="check"
                                                    size={16}
                                                    className="text-primary"
                                                />
                                            )}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            )}
        </div>
    );
}

function StepBadge({ n, done }: { n: number; done: boolean }) {
    return (
        <span
            className={
                "flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold " +
                (done
                    ? "bg-primary text-white"
                    : "bg-canvas text-muted ring-1 ring-line")
            }
        >
            {done ? <Icon name="check" size={12} /> : n}
        </span>
    );
}

function Step({ n, done, label }: { n: number; done: boolean; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-canvas px-3 py-1 font-semibold">
            <StepBadge n={n} done={done} />
            {label}
        </span>
    );
}
