import { describe, expect, it } from 'vitest'
import { highlight, languageFor } from './FileEditor'

describe('FileEditor highlighting', () => {
  it('picks the language from the file name', () => {
    expect(languageFor('wp-config.php')).toBe('php')
    expect(languageFor('public/.htaccess')).toBe('ini')
    expect(languageFor('.env.production')).toBe('ini')
    expect(languageFor('docker-compose.yml')).toBe('yaml')
    expect(languageFor('README')).toBe('text')
  })

  it('marks tokens and escapes everything else', () => {
    const out = highlight("<?php\n// note\n$x = 'a<b>';\nif ($x) return 42;", 'php')
    expect(out).toContain('<span class="tok-tg">&lt;?php</span>')
    expect(out).toContain('<span class="tok-cm">// note</span>')
    expect(out).toContain('<span class="tok-va">$x</span>')
    expect(out).toContain("<span class=\"tok-st\">'a&lt;b&gt;'</span>")
    expect(out).toContain('<span class="tok-kw">return</span>')
    expect(out).toContain('<span class="tok-nu">42</span>')
    expect(out).not.toMatch(/<(?!\/?span)/)
  })

  it('never lets markup in a file become markup in the page', () => {
    const out = highlight('<img src=x onerror=alert(1)>', 'text')
    expect(out).toBe('&lt;img src=x onerror=alert(1)&gt;')
    expect(highlight('<script>alert(1)</script>', 'html')).not.toContain('<script')
  })
})
