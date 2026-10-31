import { useMemo, useRef } from 'react'

/**
 * FM syntax highlighting without an editor dependency: a transparent textarea over a
 * highlighted <pre> with the same font, padding and wrapping, scrolled together. The
 * textarea stays the real control, so typing, selection, undo and screen readers work
 * as for any textarea. Large files (over 200 KB) are shown plain to keep typing fast.
 */

type Lang = 'php' | 'js' | 'css' | 'html' | 'json' | 'yaml' | 'sh' | 'sql' | 'py' | 'ini' | 'md' | 'text'

const EXT: Record<string, Lang> = {
  php: 'php', phtml: 'php', js: 'js', mjs: 'js', cjs: 'js', ts: 'js', tsx: 'js', jsx: 'js', css: 'css', scss: 'css', less: 'css',
  html: 'html', htm: 'html', xml: 'html', svg: 'html', vue: 'html', twig: 'html', blade: 'html', json: 'json', yml: 'yaml', yaml: 'yaml',
  sh: 'sh', bash: 'sh', env: 'ini', sql: 'sql', py: 'py', ini: 'ini', conf: 'ini', htaccess: 'ini', toml: 'ini', md: 'md',
}

export function languageFor(path: string): Lang {
  const name = path.split('/').pop()?.toLowerCase() ?? ''
  if (name === '.htaccess' || name === '.env' || name.startsWith('.env.')) return 'ini'
  if (name === 'dockerfile' || name.endsWith('.blade.php')) return name === 'dockerfile' ? 'sh' : 'html'
  return EXT[name.split('.').pop() ?? ''] ?? 'text'
}

const KEYWORDS: Partial<Record<Lang, string>> = {
  php: 'abstract and array as break case catch class clone const continue declare default do echo else elseif empty endforeach endif extends final finally fn for foreach function global if implements include include_once instanceof interface isset list match namespace new null or private protected public readonly require require_once return static switch throw trait try unset use var while yield true false self parent',
  js: 'async await break case catch class const continue default delete do else export extends false finally for from function if import in instanceof let new null of return static super switch this throw true try typeof undefined var void while yield interface type',
  py: 'and as assert async await break class continue def del elif else except False finally for from global if import in is lambda None nonlocal not or pass raise return True try while with yield',
  sql: 'select from where and or not insert into values update set delete create table alter drop index primary key foreign references join left right inner outer on group by order having limit offset as null is in like distinct union all exists default',
  sh: 'if then else elif fi for while do done case esac in function return export local echo exit set unset source',
  css: 'important media supports keyframes import font-face',
}

/** One pattern per token kind, tried in order; group names give the CSS class. */
function rules(lang: Lang): RegExp | null {
  const kw = KEYWORDS[lang]
  const words = kw ? `(?<kw>\\b(?:${kw.split(' ').join('|')})\\b)` : null
  const parts: Record<Lang, string[]> = {
    php: ['(?<cm>\\/\\/[^\\n]*|#[^\\n]*|\\/\\*[\\s\\S]*?\\*\\/)', '(?<st>"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')', '(?<va>\\$[A-Za-z_]\\w*)', '(?<tg><\\?php|\\?>)'],
    js: ['(?<cm>\\/\\/[^\\n]*|\\/\\*[\\s\\S]*?\\*\\/)', '(?<st>"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|`(?:\\\\.|[^`\\\\])*`)'],
    py: ['(?<cm>#[^\\n]*)', '(?<st>"""[\\s\\S]*?"""|\'\'\'[\\s\\S]*?\'\'\'|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')'],
    sql: ['(?<cm>--[^\\n]*|\\/\\*[\\s\\S]*?\\*\\/)', '(?<st>\'(?:\'\'|[^\'])*\')'],
    sh: ['(?<cm>#[^\\n]*)', '(?<st>"(?:\\\\.|[^"\\\\])*"|\'[^\']*\')', '(?<va>\\$\\{?[A-Za-z_]\\w*\\}?)'],
    css: ['(?<cm>\\/\\*[\\s\\S]*?\\*\\/)', '(?<st>"[^"]*"|\'[^\']*\')', '(?<tg>[.#]?[A-Za-z_-][\\w-]*(?=\\s*[{,]))', '(?<va>--[\\w-]+|[a-z-]+(?=\\s*:))'],
    html: ['(?<cm><!--[\\s\\S]*?-->)', '(?<st>"[^"]*"|\'[^\']*\')', '(?<tg><\\/?[A-Za-z][\\w:-]*|\\/?>)', '(?<va>\\b[A-Za-z_:][\\w:.-]*(?==))'],
    json: ['(?<va>"(?:\\\\.|[^"\\\\])*"(?=\\s*:))', '(?<st>"(?:\\\\.|[^"\\\\])*")', '(?<kw>\\b(?:true|false|null)\\b)'],
    yaml: ['(?<cm>#[^\\n]*)', '(?<va>^[ \\t-]*[\\w.-]+(?=\\s*:))', '(?<st>"(?:\\\\.|[^"\\\\])*"|\'[^\']*\')', '(?<kw>\\b(?:true|false|null|yes|no)\\b)'],
    ini: ['(?<cm>^[ \\t]*[#;][^\\n]*)', '(?<tg>^[ \\t]*\\[[^\\]\\n]*\\])', '(?<va>^[ \\t]*[\\w.-]+(?=\\s*[=:]))', '(?<st>"[^"\\n]*"|\'[^\'\\n]*\')'],
    md: ['(?<tg>^#{1,6} [^\\n]*)', '(?<st>`[^`\\n]+`)', '(?<va>\\[[^\\]\\n]+\\]\\([^)\\n]+\\))'],
    text: [],
  }
  const list = [...parts[lang], ...(words ? [words] : []), '(?<nu>\\b\\d+(?:\\.\\d+)?\\b)']
  return lang === 'text' ? null : new RegExp(list.join('|'), lang === 'sql' ? 'gmi' : 'gm')
}

const escape = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

export function highlight(code: string, lang: Lang): string {
  const re = rules(lang)
  if (!re) return escape(code)
  let out = ''
  let last = 0
  for (const m of code.matchAll(re)) {
    if (!m[0]) continue
    const kind = Object.entries(m.groups ?? {}).find(([, v]) => v !== undefined)?.[0]
    out += escape(code.slice(last, m.index)) + `<span class="tok-${kind}">${escape(m[0])}</span>`
    last = (m.index ?? 0) + m[0].length
  }
  return out + escape(code.slice(last))
}

const MAX_HIGHLIGHT = 200_000

export function FileEditor({ value, onChange, path, label }: { value: string; onChange: (v: string) => void; path: string; label: string }) {
  const pre = useRef<HTMLPreElement>(null)
  const escaped = useRef(false)
  const lang = languageFor(path)
  const plain = lang === 'text' || value.length > MAX_HIGHLIGHT
  // A trailing newline needs a character after it, or the last line of the overlay collapses.
  const html = useMemo(() => (plain ? '' : highlight(value, lang) + '\n '), [value, lang, plain])
  const shared = 'font-mono text-xs leading-relaxed whitespace-pre-wrap break-words p-3 m-0 border-0 [tab-size:4] overflow-y-auto [scrollbar-gutter:stable]'

  return (
    <div className="relative rounded-lg border border-line bg-[var(--surface)] min-h-[60vh]" data-lang={lang}>
      {/* nosemgrep: react-dangerously-set-inner-html -- highlight() escapes &, < and > in every segment */}
      {!plain && <pre ref={pre} aria-hidden="true" className={`code-hl absolute inset-0 pointer-events-none ${shared}`} dangerouslySetInnerHTML={{ __html: html }} />}
      <textarea aria-label={label} spellCheck={false} value={value}
        className={`relative block w-full min-h-[60vh] resize-y bg-transparent outline-none ${shared} ${plain ? 'text-[var(--text)]' : 'text-transparent caret-[var(--text)] selection:bg-[var(--sel)]'}`}
        onChange={(e) => onChange(e.target.value)}
        onScroll={(e) => { if (pre.current) { pre.current.scrollTop = e.currentTarget.scrollTop; pre.current.scrollLeft = e.currentTarget.scrollLeft } }}
        onKeyDown={(e) => {
          if (e.key === 'Escape') { escaped.current = true; return }
          const leave = escaped.current
          escaped.current = false
          if (e.key !== 'Tab' || e.shiftKey || leave) return
          // Tab indents instead of leaving the editor; Esc then Tab still moves focus on.
          e.preventDefault()
          const t = e.currentTarget
          const { selectionStart: a, selectionEnd: b } = t
          onChange(value.slice(0, a) + '    ' + value.slice(b))
          requestAnimationFrame(() => { t.selectionStart = t.selectionEnd = a + 4 })
        }} />
    </div>
  )
}
