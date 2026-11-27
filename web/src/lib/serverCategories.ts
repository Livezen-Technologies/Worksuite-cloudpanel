/** What a server is for. Order is the order "Group by → Category" shows them in. */
export const SERVER_CATEGORIES = [
  ['servers', 'Servers'], ['dns', 'DNS'], ['hosting', 'Hosting'], ['database', 'Database'],
  ['backup', 'Backup'], ['storage', 'Storage'], ['cache', 'Cache'],
] as const

export type ServerCategory = (typeof SERVER_CATEGORIES)[number][0]

export function categoryLabel(v: string | null | undefined): string {
  return SERVER_CATEGORIES.find(([k]) => k === v)?.[1] ?? 'Hosting'
}
