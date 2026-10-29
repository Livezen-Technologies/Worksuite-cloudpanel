# UI rebuild against the design canvas

## Status at 29 Oct 2026

```
Infrastructure     ~95%   7 sites live with TLS, limits, rate limiting
                          monitoring, hardened SSH, backups in two places
UI modernisation   ~67%   foundations done; 1 of 35 artboards applied
Monitoring         ~55%   websites + containers + servers every 5 min
WorkSuite Server     0%   blocked: no source for the image
```

Next action: the shell. `TopBar` and `Sidebar` are built but predate the
canvas, so they need a pass against it before any page is rebuilt.

Canvas: `claude.ai/artifact/FFTAAc3FPxWejM9jviUgpM` — "Docker Manager Portal",
35 artboards. This maps each to its page and records what is already done, so
the work can be picked up without rediscovering the codebase.

## Task list

Ordered. Each line is one commit; verify the route returns 200 before moving on.

### Shell — blocks every page
- [x] `TopBar` → `Shell.tsx` header — commit `71ca388`
      four-quadrant mark in token colours, product name at 19px, organisation
      switcher linking to settings. Assistant control omitted (no such feature);
      incidents triangle omitted (the alerts bell already serves it).
- [x] `Sidebar` → `Shell.tsx` — commit `79620c2`; rail icons labelled, sidebar headed by its section and description
- [~] `Main` / Overview — commit `1de44e2`; alert severity breakdown added.
      **Blocked on API:** Fleet health distribution and "Needs attention" need a
      per-server health score and an `emergency` tier, neither of which `/dashboard` returns.

### Admin screens
- [x] Servers — no change needed; the form already carries every field the artboard
      shows, plus hostname, region and tags
- [ ] Containers
- [ ] Applications · Compose
- [ ] Images · Networks · Volumes · Databases
- [ ] Domains · Backups · Monitoring · Logs · Automation
- [ ] Users · Settings
- [ ] Catalog · Packages · Customers

### New construction
- [ ] Firewall page (backend: rate limiting + headers exist; country
      blocking, challenges, reCAPTCHA do not)

### Customer panel — 10 artboards, untouched
- [ ] Pass the rail and nav to `/app` so it gets the same two-level navigation
- [ ] UserPanel · UserHost · UserHostConfig · UserHostLive · UserNewSite
- [ ] UserDomains · UserAppDetail · UserBackups · UserBilling · UserAccount

### Outside the canvas
- [ ] DataTable: bulk actions, filters, contextual menus
- [ ] Notification centre: dropdown, panel, history
- [ ] Responsive audit at 360 / 768 / 1440
- [ ] Sidebar and rail badges wired to live counts (component supports them)

### Monitoring to finish
- [ ] Auto-restart policies for failed containers
- [ ] Uptime reports and downtime history
- [ ] Per-customer scoped incident views
- [ ] Alert dedup, cooldowns, maintenance windows, escalation
- [ ] WhatsApp / Telegram / SMS channels

### Operations
- [ ] Restore shopexpress.lk · diygeek.cc · softkeymart.com · livezenbiz.com
      (check each for a live copy first — see the seliknaent note below)
- [ ] Retire the old copies on 51.89.192.43 once restored sites are verified
- [ ] Decide on CloudPanel: 8433:8443/tcp is open to the world, self-signed
- [ ] WorkSuite Server: needs read access to its source before anything

> seliknaent was restored from a two-day-old backup while it was still
> trading on another server. Always check where a site currently lives, and
> whether it is still being written to, before restoring from backup.


## Already in place (do not rebuild)

Built before the canvas arrived; the rebuild composes on top rather than
replacing these.

```
docs/design-system.md     tokens, component inventory, 20 acceptance criteria
web/src/index.css         colour · spacing · radius · elevation · z-index · motion
components/Overlay.tsx    Modal, Drawer, ConfirmDialog — focus trap, scroll lock,
                          animation, tokens
components/Card.tsx       Card, Field, PageHeader, StatTile (valueTone), Meter, Empty
components/Select.tsx     themed listbox replacing native select
components/Shell.tsx      icon rail + context sidebar + full-width header
components/DataTable.tsx  sorting, filters, pagination — no bulk actions yet
index.css @layer          .btn .input .check .radio .file .field-error .choice
```

## Order of work

The shell first: every screen inherits it, so rebuilding a page before the
shell means doing it twice.

### 1. Shell (blocks everything)
| Artboard | Target | Status |
| --- | --- | --- |
| `TopBar` 1440×54 | `components/Shell.tsx` header | built, needs canvas pass |
| `Sidebar` 334×1000 | `components/Shell.tsx` rail + nav | built, needs canvas pass |
| `Main` 1440×1360 | `pages/admin/Overview.tsx` | 7 KPIs + quick actions; canvas is taller — more content below |

### 2. Admin screens
| Artboard | Target |
| --- | --- |
| `Servers` 1440×1100 | `pages/admin/Servers.tsx` |
| `Containers` 1440×1000 | `pages/admin/Containers.tsx` |
| `Applications` | `pages/admin/Deploy.tsx` → `Applications` |
| `Compose` | `pages/admin/Deploy.tsx` → `Compose` |
| `Images` `Networks` `Volumes` | `pages/admin/Resources.tsx` |
| `Databases` | `pages/admin/Resources.tsx` |
| `Domains` | `pages/admin/Operations.tsx` |
| `Backups` `Monitoring` `Logs` `Automation` | `pages/admin/Operations.tsx` |
| `Users` `Settings` | `pages/admin/Admin.tsx` |
| `Catalog` 1440×1054 | `pages/admin/Catalog.tsx` |
| `Packages` `Customers` 1440×1054 | `pages/admin/Hosting.tsx` |

### 3. New construction
| Artboard | Note |
| --- | --- |
| `Firewall` 1440×1100 | **No page exists.** Traefik already enforces rate limiting and security headers (`/etc/dockermgr/traefik/dynamic/security.yml`); country blocking, challenges and reCAPTCHA are unbuilt — see the notes in that file. |

### 4. Customer panel (9 artboards)
`UserPanel` `UserHost` `UserHostConfig` `UserHostLive` `UserDomains`
`UserAppDetail` `UserBackups` `UserBilling` `UserAccount` `UserNewSite`
→ `pages/app/*`. The shell already accepts a `rail` and `nav`, so the customer
panel can take the same two-level navigation by passing its own lists.

## Method

1. Read `project/canvas.json` for frames, then the artboard's `.dc.html`.
2. Compare its palette to `index.css`. Extend the tokens rather than adding
   literals — only third-party brand colours stay fixed (see the colour commit).
3. Rebuild one page, `npm run build`, verify the route returns 200, commit.
   One page per commit: a broken rebuild should never span screens.
4. Preserve the data layer. The canvas is static markup; every page is wired to
   live APIs with permissions. Take the markup, keep `useList`/`useAction`.

## Still open outside the canvas

```
data table      bulk actions, filters, contextual menus
notifications   centre: dropdown, panel, history
responsive      audit at 360 / 768 / 1440
monitoring      auto-restart, uptime reports, per-customer views,
                escalation, dedup, maintenance windows
WorkSuite Server  blocked: no source for the image
```
