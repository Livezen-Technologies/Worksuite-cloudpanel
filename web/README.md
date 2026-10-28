# Docker Manager Portal — web

React 19 + TypeScript + Vite + Tailwind CSS v4 (`@tailwindcss/vite`), react-router 7, TanStack Query 5.
Admin portal at `/admin` (16-item sidebar), customer panel at `/app` (6-item sidebar). Contract: `../docs/API.md`.

## Scripts
```
npm install
npm run dev      # http://localhost:5173, proxies /api -> http://localhost:8000
npm run build    # type-check + production bundle in dist/
npm run lint     # tsc --noEmit
npm test         # vitest + Testing Library
```
`VITE_API_URL` (optional) sets an absolute API origin; requests go to `${VITE_API_URL}/api/v1`.

## Layout
- `src/api/client.ts` — fetch wrapper: bearer token, `Idempotency-Key` on every POST (reused on network retry), `ApiError` from the `{error:{code,message,fields,request_id}}` envelope, 401 → sign-out.
- `src/api/hooks.ts` — `useList`, `useOne`, `useJob` (polls `GET /jobs/{id}`), `useAction` (mutation + toast + job tracking).
- `src/api/jobs.tsx` — Activity dock: every 202 `job_id` shows a live stages timeline.
- `src/components/` — StatTile, Badge (always a word), Card, DataTable, Drawer/Modal/ConfirmDialog (typed confirmation), Toasts, Tabs, StepWizard, CommandPalette (Ctrl/⌘K → `/search`), SVG charts, LogView, Terminal, CodeEditor.
- `src/pages/admin/*`, `src/pages/app/*`, `src/pages/auth/*`.

Themes: CSS variables on `:root` / `.dark` mapped to Tailwind tokens; toggle in the header (stored in localStorage).
Role routing: `owner|developer|billing` land on `/app`, others on `/admin`.
