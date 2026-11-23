import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { isBranch, type MenuGroup, type MenuNode } from '@/types';
import { Icon } from './Icon';

/** Dashboard sidebar (spec 1.7 / 21.1): accordion groups; open group has a solid primary header. */
export function Sidebar({ groups }: { groups: MenuGroup[] }) {
    return (
        <nav aria-label="Dashboard" className="card overflow-hidden">
            <ul>
                {groups.map((group) => (
                    <SidebarGroup key={group.key} group={group} />
                ))}
            </ul>
        </nav>
    );
}

function SidebarGroup({ group }: { group: MenuGroup }) {
    const [open, setOpen] = useState(group.open);

    return (
        <li className="border-b border-line last:border-b-0">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                className={`flex w-full items-center gap-3 px-4 py-3 text-start font-semibold transition-colors ${
                    open ? 'bg-primary text-white' : 'hover:bg-canvas'
                }`}
            >
                <Icon name={group.icon} size={18} className={open ? 'text-white' : 'text-primary'} />
                <span className="flex-1">{group.label}</span>
                <Icon name="chevron" size={16} className={`transition-transform rtl:-scale-x-100 ${open ? 'rotate-90 rtl:-rotate-90' : ''}`} />
            </button>
            {open && <NodeList nodes={group.children} depth={0} />}
        </li>
    );
}

function NodeList({ nodes, depth }: { nodes: MenuNode[]; depth: number }) {
    return (
        <ul className="py-1">
            {nodes.map((node) => (isBranch(node) ? <Branch key={node.key} node={node} depth={depth} /> : <Leaf key={node.key} node={node} depth={depth} />))}
        </ul>
    );
}

function Branch({ node, depth }: { node: Extract<MenuNode, { children: MenuNode[] }>; depth: number }) {
    const [open, setOpen] = useState(node.open);

    return (
        <li>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                className="flex w-full items-center gap-2 py-2 pe-4 text-start text-ink hover:text-primary"
                style={{ paddingInlineStart: `${2.25 + depth}rem` }}
            >
                <span className="flex-1">{node.label}</span>
                <Icon name="chevron" size={14} className={`transition-transform rtl:-scale-x-100 ${open ? 'rotate-90 rtl:-rotate-90' : ''}`} />
            </button>
            {open && <NodeList nodes={node.children} depth={depth + 1} />}
        </li>
    );
}

function Leaf({ node, depth }: { node: Extract<MenuNode, { href: string }>; depth: number }) {
    return (
        <li>
            <Link
                href={node.href}
                aria-current={node.active ? 'page' : undefined}
                className={`flex items-center gap-1.5 py-2 pe-4 ${node.active ? 'font-semibold text-primary' : 'text-ink hover:text-primary'}`}
                style={{ paddingInlineStart: `${2.25 + depth}rem` }}
            >
                <span aria-hidden="true" className="rtl:-scale-x-100">
                    ›
                </span>
                {node.label}
            </Link>
        </li>
    );
}
