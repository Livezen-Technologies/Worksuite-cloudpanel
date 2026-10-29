# UI rebuild against the design canvas

Canvas: `claude.ai/artifact/FFTAAc3FPxWejM9jviUgpM` — "Docker Manager Portal",
35 artboards. This maps each to its page and records what is already done, so
the work can be picked up without rediscovering the codebase.

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
