import { useRef } from 'react'

/** Plain textarea code editor with a synced line-number gutter. */
export function CodeEditor({ value, onChange, label, rows = 18, errorLine }: { value: string; onChange: (v: string) => void; label: string; rows?: number; errorLine?: number | null }) {
  const gutter = useRef<HTMLDivElement>(null)
  const n = Math.max(rows, value.split('\n').length)
  return (
    <div className="flex rounded-lg overflow-hidden border border-line bg-code text-code-fg font-mono text-[12.5px] leading-[1.6]">
      <div ref={gutter} aria-hidden="true" className="select-none text-right py-3 px-2.5 text-[#64748B] overflow-hidden flex-none" style={{ height: `${rows * 1.6 + 1.5}em` }}>
        {Array.from({ length: n }, (_, i) => <div key={i} style={errorLine === i + 1 ? { color: '#F87171', fontWeight: 700 } : undefined}>{i + 1}</div>)}
      </div>
      <textarea aria-label={label} spellCheck={false} value={value} rows={rows} wrap="off"
        onChange={(e) => onChange(e.target.value)}
        onScroll={(e) => { if (gutter.current) gutter.current.scrollTop = e.currentTarget.scrollTop }}
        onKeyDown={(e) => {
          if (e.key === 'Tab' && !e.shiftKey && !e.ctrlKey) {
            // Two-space indent; Esc then Tab still moves focus out (keyboard users).
            e.preventDefault()
            const t = e.currentTarget, s = t.selectionStart
            onChange(value.slice(0, s) + '  ' + value.slice(t.selectionEnd))
            requestAnimationFrame(() => { t.selectionStart = t.selectionEnd = s + 2 })
          }
          if (e.key === 'Escape') (e.currentTarget.parentElement?.nextElementSibling as HTMLElement | null)?.focus?.()
        }}
        className="flex-1 min-w-0 py-3 px-3 bg-transparent text-inherit border-0 outline-none resize-y font-mono text-[12.5px] leading-[1.6]" />
    </div>
  )
}
