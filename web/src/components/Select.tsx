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
        className="z-80 max-h-72 w-(--button-width) overflow-auto rounded-xl border border-line bg-card p-1 shadow-2xl
                   [--anchor-gap:4px] origin-top transition duration-120 ease-out data-closed:scale-95 data-closed:opacity-0"
      >
        {opts.length === 0 && <div className="px-3 py-2 text-sm text-muted">No options</div>}
        {opts.map((o, i) => (
          <ListboxOption
            key={`${o.value}-${i}`} value={o.value} disabled={o.disabled}
            className="group flex min-h-9 cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm
                       data-focus:bg-accent data-focus:text-white
                       data-disabled:cursor-not-allowed data-disabled:opacity-40"
          >
            <CheckIcon aria-hidden="true" className="size-3.5 flex-none invisible group-data-selected:visible" />
            <span className="truncate group-data-selected:font-medium">{o.label}</span>
          </ListboxOption>
        ))}
      </ListboxOptions>
    </Listbox>
  )
}
