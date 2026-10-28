import { parseCast, terminalSocketUrl } from '../components/Terminal'

describe('terminal relay helpers', () => {
  it('resolves relative and absolute ws_url to ws(s) with the terminal size', () => {
    expect(terminalSocketUrl('/relay/terminal/abc?token=t1', 120, 40, 'https://panel.example/admin/logs'))
      .toBe('wss://panel.example/relay/terminal/abc?token=t1&cols=120&rows=40')
    expect(terminalSocketUrl('/relay/terminal/abc?token=t1', 80, 24, 'http://localhost:5173/admin'))
      .toBe('ws://localhost:5173/relay/terminal/abc?token=t1&cols=80&rows=24')
    expect(terminalSocketUrl('wss://relay.example/relay/terminal/x?token=y', 80, 24, 'http://localhost:5173/'))
      .toBe('wss://relay.example/relay/terminal/x?token=y&cols=80&rows=24')
  })

  it('parses asciicast v2 recordings and skips malformed lines', () => {
    const cast = [
      JSON.stringify({ version: 2, width: 100, height: 30, timestamp: 1790000000 }),
      JSON.stringify([0.5, 'o', '$ ']),
      'garbage',
      JSON.stringify([1.25, 'r', '120x40']),
      JSON.stringify([2, 'o', 'hello\r\n']),
    ].join('\n')
    const { header, events } = parseCast(cast)
    expect(header.width).toBe(100)
    expect(events).toEqual([[0.5, 'o', '$ '], [1.25, 'r', '120x40'], [2, 'o', 'hello\r\n']])
  })
})
