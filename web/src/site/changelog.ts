/** Turns CHANGELOG.md (Keep a Changelog) into releases for the website's Changelog page. */
export type Release = { version: string; date: string; groups: { k: string; items: string[] }[] }

const clean = (s: string) => s.replace(/`([^`]*)`/g, '$1').replace(/\*\*([^*]*)\*\*/g, '$1').replace(/\[([^\]]+)\]\([^)]*\)/g, '$1').replace(/\s+/g, ' ').trim()

export function parseChangelog(md: string): Release[] {
  const out: Release[] = []
  let rel: Release | null = null
  let group: { k: string; items: string[] } | null = null
  let item: string[] | null = null
  const flush = () => { if (item && group) group.items.push(clean(item.join(' '))); item = null }
  for (const line of md.split(/\r?\n/)) {
    const r = line.match(/^## \[(\d+\.\d+\.\d+)\]\s*[—–-]\s*(\S+)/)
    if (r) { flush(); rel = { version: r[1], date: r[2], groups: [] }; out.push(rel); group = null; continue }
    if (line.startsWith('## ')) { flush(); rel = null; group = null; continue } // Unreleased and anything else
    if (!rel) continue
    const g = line.match(/^### (.+)/)
    if (g) { flush(); group = { k: g[1].trim(), items: [] }; rel.groups.push(group); continue }
    if (!group) continue
    if (/^[-*] /.test(line)) { flush(); item = [line.slice(2)] }
    else if (item && /^\s+\S/.test(line)) item.push(line.trim())
    else flush()
  }
  flush()
  return out.map((x) => ({ ...x, groups: x.groups.filter((g) => g.items.length) }))
}
