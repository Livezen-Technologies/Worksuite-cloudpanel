import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/react'
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/react/16/solid'
import { Children, isValidElement, useMemo, type ChangeEvent, type MouseEvent as ReactMouseEvent, type ReactNode } from 'react'

type Opt = { value: string; label: string; disabled?: boolean }

/** Flatten an option's children to text: they may be a string, a number, or an
 *  array of both when the label interpolates values ({name} — {price}). */
function textOf(node: ReactNode): string {
  if (node === null || node === undefined || typeof node === 'boolean') return ''
  if (typeof node === 'string' || typeof node === 'number') return String(node)
  if (Array.isArray(node)) return node.map(textOf).join('')
  if (isValidElement(node)) return textOf((node.props as { children?: ReactNode }).children)
  return ''
}

/**
 * Drop-in replacement for a native select element: same props, same
 * e.target.value onChange contract, <option> children.
 *
 * Built on Headless UI's Listbox, which owns the parts that were previously
 * hand-rolled here and easy to get subtly wrong: focus management, ARIA
 * wiring, typeahead, and keeping the popover anchored through scroll and
 * resize. Colours stay on our tokens rather than the kit's palette, so both
 * themes keep working.
 */
export default function Select({
  value, onChange, children, className = '', disabled, id, 'aria-label': ariaLabel, name, onClick, title,
}: {
  value?: string | number
  onChange?: (e: ChangeEvent<HTMLSelectElement>) => void
  children?: ReactNode
  className?: string
  disabled?: boolean
  id?: string
  'aria-label'?: string
  name?: string
  onClick?: (e: ReactMouseEvent<HTMLButtonElement>) => void
  title?: string
}) {
  const opts = useMemo<Opt[]>(() => {
    const out: Opt[] = []
    const walk = (nodes: ReactNode) => Children.forEach(nodes, (c) => {
      if (!isValidElement(c)) return
      const p = c.props as { value?: string | number; children?: ReactNode; disabled?: boolean }
      if (c.type === 'option') {
        const label = textOf(p.children).trim()
        out.push({ value: p.value !== undefined ? String(p.value) : label, label: label || String(p.value ?? ''), disabled: p.disabled })
      } else if (p.children) walk(p.children)
    })
    walk(children)
    return out
  }, [children])

  const current = String(value ?? '')
  const selected = opts.find((o) => o.value === current)

  return (
    <Listbox
      value={current}
      disabled={disabled}
      onChange={(v: string) => onChange?.({ target: { value: v, name } } as unknown as ChangeEvent<HTMLSelectElement>)}
    >
      <ListboxButton
        id={id} title={title} aria-label={ariaLabel} onClick={onClick}
        className={`${className} flex items-center justify-between gap-2 text-left cursor-pointer data-disabled:opacity-50 data-disabled:cursor-not-allowed`}
      >
        <span className={`truncate ${selected ? '' : 'text-muted'}`}>{selected?.label ?? '—'}</span>
        <ChevronUpDownIcon aria-hidden="true" className="size-4 flex-none text-muted" />
      </ListboxButton>

      {/* anchor keeps it attached through scroll and resize, and flips it when
          there is no room below. --anchor-gap matches the old 4px offset. */}
      <ListboxOptions
        anchor="bottom start"
        transition
        className="z-80 max-h-72 min-w-(--button-width) w-max max-w-[min(92vw,28rem)] overflow-auto rounded-xl border border-line bg-card p-1.5
                   shadow-[0_12px_32px_-8px_rgba(15,23,42,.22),0_2px_6px_rgba(15,23,42,.06)] outline-none
                   [--anchor-gap:6px] origin-top transition duration-120 ease-out data-closed:-translate-y-1 data-closed:opacity-0"
      >
        {opts.length === 0 && <div className="px-3 py-2 text-sm text-muted">No options</div>}
        {opts.map((o, i) => (
          <ListboxOption
            key={`${o.value}-${i}`} value={o.value} disabled={o.disabled} data-value={String(o.value)}
            className="group flex min-h-9 cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm text-fg
                       data-focus:bg-head data-selected:bg-sel data-selected:text-accent
                       data-disabled:cursor-not-allowed data-disabled:opacity-40"
          >
            <span className="flex-1 whitespace-nowrap group-data-selected:font-semibold">{o.label}</span>
            <CheckIcon aria-hidden="true" className="size-4 flex-none text-accent invisible group-data-selected:visible" />
          </ListboxOption>
        ))}
      </ListboxOptions>
    </Listbox>
  )
}
