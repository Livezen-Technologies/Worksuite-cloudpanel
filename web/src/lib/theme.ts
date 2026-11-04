import { useCallback, useEffect, useState } from 'react'

/** What the viewer picked. 'system' follows the operating system and keeps following it. */
export type ThemePref = 'light' | 'dark' | 'system'
/** What is actually painted. */
export type Theme = 'light' | 'dark'

const KEY = 'dmp.theme'
const systemDark = () => typeof matchMedia !== 'undefined' && matchMedia('(prefers-color-scheme: dark)').matches

function initial(): ThemePref {
  try {
    const t = localStorage.getItem(KEY)
    if (t === 'light' || t === 'dark' || t === 'system') return t
  } catch { /* ignore */ }
  // No stored choice means nobody has chosen, which is exactly what 'system' is.
  return 'system'
}

export function useTheme() {
  const [pref, setPref] = useState<ThemePref>(initial)
  // Kept in state rather than read during render so a change of the OS setting
  // repaints the page while it is open, not only on the next reload.
  const [sysDark, setSysDark] = useState(systemDark)

  useEffect(() => {
    if (typeof matchMedia === 'undefined') return
    const mq = matchMedia('(prefers-color-scheme: dark)')
    const on = () => setSysDark(mq.matches)
    mq.addEventListener('change', on)
    return () => mq.removeEventListener('change', on)
  }, [])

  const theme: Theme = pref === 'system' ? (sysDark ? 'dark' : 'light') : pref

  useEffect(() => {
    document.documentElement.classList.toggle('dark', theme === 'dark')
    try { localStorage.setItem(KEY, pref) } catch { /* ignore */ }
  }, [theme, pref])

  // The header's single button still flips between the two concrete themes; it
  // leaves 'system' behind deliberately, because the viewer has now chosen.
  const toggle = useCallback(() => setPref(theme === 'dark' ? 'light' : 'dark'), [theme])

  return { theme, pref, setPref, toggle }
}
