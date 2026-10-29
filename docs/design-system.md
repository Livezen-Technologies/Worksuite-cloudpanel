# WorkSuite design system

The panel already ships a token layer in `web/src/index.css`. This records what
exists, what is missing, and what "modernised" has to mean in checkable terms.

## 1. Tokens

### 1.1 Colour — defined, keep

Declared on `:root` and redefined under `.dark`. Components must read the
`--color-*` aliases from `@theme`, never raw hex.

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `--bg` | `#F8FAFC` | `#0B1120` | page ground |
| `--side` | `#FFFFFF` | `#0F172A` | sidebar |
| `--card` | `#FFFFFF` | `#111827` | raised surface |
| `--head` | `#F8FAFC` | `#0F172A` | table header, toolbar |
| `--text` | `#0F172A` | `#F1F5F9` | body text |
| `--muted` | `#5B6475` | `#94A3B8` | secondary text |
| `--line` | `#E2E8F0` | `#1E293B` | borders, dividers |
| `--track` | `#EEF2F7` | `#1E293B` | progress track |
| `--sel` | `#EEF2FF` | `#1E1B4B` | selected row |
| `--accent` | `#4F46E5` | `#4F46E5` | primary action |
| `--accent-hover` | `#4338CA` | `#4338CA` | primary hover |
| `--accent-fg` | `#4F46E5` | `#A5B4FC` | links on ground |
| `--danger` | `#B91C1C` | `#F87171` | destructive text |

Status pairs follow `--<x>-bg` / `--<x>-fg`: `g` success, `y` warning, `r` error,
`b` info, `n` neutral. Semantic status colour is separate from the accent; a
badge never uses `--accent`.

### 1.2 Missing — add before component work

Without these, "consistent spacing" cannot be enforced or reviewed.

```css
/* spacing: 4px base, the only legal gaps */
--space-1: 4px;  --space-2: 8px;  --space-3: 12px; --space-4: 16px;
--space-5: 24px; --space-6: 32px; --space-7: 48px; --space-8: 64px;

/* radius */
--radius-sm: 6px;   /* chips, badges */
--radius-md: 8px;   /* inputs, buttons */
--radius-lg: 12px;  /* cards */
--radius-xl: 16px;  /* drawers, modals */
--radius-full: 9999px;

/* elevation — one shadow per layer, no ad-hoc values */
--shadow-card: 0 1px 2px rgb(0 0 0 / .04);
--shadow-pop:  0 8px 24px rgb(0 0 0 / .12);   /* dropdown, popover */
--shadow-over: 0 24px 48px rgb(0 0 0 / .20);  /* modal, drawer */

/* z-index — the whole scale, nothing outside it */
--z-sticky: 20; --z-drawer: 40; --z-modal: 50;
--z-dock: 55; --z-popover: 60; --z-toast: 70;

/* motion */
--ease-out: cubic-bezier(.16,1,.3,1);
--dur-fast: 120ms;  /* hover, focus */
--dur-base: 200ms;  /* dropdown, popover */
--dur-slow: 280ms;  /* drawer, modal */
```

### 1.3 Type

`--font-sans: Inter`, `--font-mono: "JetBrains Mono"`. Scale: 12 / 13 / 14 / 16 /
20 / 24 / 30px. Body 14px. Numeric columns use `tabular-nums`.

## 2. Component inventory

Source: `application-ui-v4-3/react/*`. Build each once in
`web/src/components/`, then replace call sites — never copy a pattern twice.

| Need | Source | Status |
| --- | --- | --- |
| Modal | `overlays/modal-dialogs` | missing — forms are inline today |
| Side drawer | `overlays/drawers` | partial (`Overlay.tsx`) |
| Dropdown menu | `elements/dropdowns` | missing |
| Notification toast | `feedback/notifications` | exists (`Toasts.tsx`) |
| Empty state | `feedback/empty-states` | exists (`Empty`) |
| Form controls | `forms/*` | partial — `.input`, custom `Select` |
| Data table | `lists/tables` | exists (`DataTable`) — no bulk actions |
| Page heading | `headings/page-headings` | exists (`PageHeader`) |
| Sidebar shell | `application-shells/sidebar` | exists (`AdminLayout`) |
| Stat cards | `data-display/stats` | partial |

## 3. Acceptance criteria

Measurable. A change is not done until its row passes.

### Layout
1. No page has a `max-width` on `<main>`; content fills the viewport with a
   16px gutter at ≤640px and 32px above.
2. No horizontal page scrollbar at 360px, 768px, 1024px, 1440px, 1920px.
3. No nested scrollbars except inside a table, log view or terminal.

### Spacing and tokens
4. Zero raw hex in `web/src/**` outside `index.css`. Enforced:
   `grep -rE "#[0-9a-fA-F]{3,6}" web/src --include=*.tsx` returns nothing.
5. Every gap, padding and margin is a `--space-*` step. No arbitrary `[13px]`.
6. Every `z-index` is a `--z-*` token; `grep -rE "z-\[[0-9]+\]"` returns nothing.

### Create and edit
7. Every create and edit opens a modal (≤6 fields) or drawer (>6 fields). No
   create/edit route navigates to a full page except Deploy and the wizard.
8. On save the list updates without a page reload and a success toast appears
   within 300ms.
9. Validation errors render against their field, not only as a toast.

### Overlays
10. One overlay at a time; opening a second closes the first.
11. Escape closes; focus is trapped while open and returns to the trigger on
    close; the background does not scroll.
12. Open and close animate with `--dur-slow` and `--ease-out`, and are disabled
    under `prefers-reduced-motion`.

### Accessibility
13. Every interactive element is a real `button`, `a[href]` or `input` — no
    `onClick` on a `div`. Enforced by lint.
14. Visible focus ring on every focusable element, both themes.
15. Icon-only controls have `aria-label`; decorative SVG has `aria-hidden`.
16. Text contrast ≥4.5:1 (≥3:1 at 24px+), verified in both themes.
17. Touch targets ≥44px.

### Themes
18. Every component renders correctly in light and dark; no component sets a
    background without also setting a colour.

### Regression
19. `npm run build` passes with zero TypeScript errors.
20. No API call, permission check or business rule changes: `git diff --stat`
    for a UI commit touches `web/` only.

## 4. Sequence

1. Tokens (§1.2) — nothing else can be consistent first.
2. Modal and Drawer primitives, with focus management.
3. Form controls on the new tokens.
4. Migrate create/edit flows to overlays, module by module.
5. Data table: bulk actions, filters, contextual menus.
6. Dashboards and chat last — they consume everything above.
