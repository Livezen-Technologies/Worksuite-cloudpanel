import { useState } from 'react'

export function CopyButton({ value, label = 'Copy', className = '' }: { value: string; label?: string; className?: string }) {
  const [done, setDone] = useState(false)
  return (
    <button type="button" className={`btn btn-sm ${className}`} aria-label={`${label} ${value}`}
      onClick={async () => {
        try { await navigator.clipboard.writeText(value) } catch { /* clipboard unavailable */ }
        setDone(true); setTimeout(() => setDone(false), 1500)
      }}>
      {done ? 'Copied' : label}
    </button>
  )
}
