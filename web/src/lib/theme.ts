import { useEffect, useState } from 'react'

export type Theme = 'light' | 'dark'
function initial(): Theme {
  try {
    const t = localStorage.getItem('dmp.theme')
    if (t === 'light' || t === 'dark') return t
  } catch { /* ignore */ }
  return typeof matchMedia !== 'undefined' && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}
export function useTheme() {
  const [theme, setTheme] = useState<Theme>(initial)
  useEffect(() => {
    document.documentElement.classList.toggle('dark', theme === 'dark')
    try { localStorage.setItem('dmp.theme', theme) } catch { /* ignore */ }
  }, [theme])
  return { theme, toggle: () => setTheme((t) => (t === 'dark' ? 'light' : 'dark')) }
}
