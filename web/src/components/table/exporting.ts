/** TBL-5: export the rows a table is showing (after filters) as CSV, Excel or PDF (print). */
export interface ExportCol { header: string; get: (row: never) => unknown }
type Cell = string | number

const cell = (v: unknown): Cell => (typeof v === 'number' ? v : v == null ? '' : String(v))
const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

export function toCsv(headers: string[], rows: Cell[][]): string {
  const q = (v: Cell) => {
    let s = String(v)
    // Formula injection: a cell starting with = + - @ is read as a formula by spreadsheets.
    if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`
    return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
  }
  return [headers, ...rows].map((r) => r.map(q).join(',')).join('\r\n')
}

/** SpreadsheetML 2003: opens in Excel, Numbers and LibreOffice without a library. */
export function toExcelXml(sheet: string, headers: string[], rows: Cell[][]): string {
  const c = (v: Cell) => `<Cell><Data ss:Type="${typeof v === 'number' ? 'Number' : 'String'}">${esc(String(v))}</Data></Cell>`
  return `<?xml version="1.0"?><?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Styles><Style ss:ID="h"><Font ss:Bold="1"/></Style></Styles>
<Worksheet ss:Name="${esc(sheet.slice(0, 31))}"><Table>
<Row ss:StyleID="h">${headers.map(c).join('')}</Row>
${rows.map((r) => `<Row>${r.map(c).join('')}</Row>`).join('\n')}
</Table></Worksheet></Workbook>`
}

export function rowsFor<T>(rows: T[], cols: { header: string; get: (r: T) => unknown }[]): { headers: string[]; data: Cell[][] } {
  return { headers: cols.map((c) => c.header), data: rows.map((r) => cols.map((c) => cell(c.get(r)))) }
}

function download(name: string, type: string, body: string) {
  const url = URL.createObjectURL(new Blob([body], { type }))
  const a = Object.assign(document.createElement('a'), { href: url, download: name })
  document.body.appendChild(a); a.click(); a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export function exportTable(kind: 'csv' | 'xls' | 'pdf', title: string, headers: string[], data: Cell[][]) {
  const base = `${title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'export'}-${new Date().toISOString().slice(0, 10)}`
  if (kind === 'csv') return download(`${base}.csv`, 'text/csv;charset=utf-8', '﻿' + toCsv(headers, data))
  if (kind === 'xls') return download(`${base}.xls`, 'application/vnd.ms-excel', toExcelXml(title, headers, data))
  const w = window.open('', '_blank', 'noopener=no,width=1000,height=700')
  if (!w) return
  w.document.write(`<!doctype html><title>${esc(title)}</title><style>body{font:12px Inter,system-ui,sans-serif;margin:24px;color:#0f172a}h1{font-size:18px}table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left}th{background:#f1f5f9}</style>
<h1>${esc(title)}</h1><p>${data.length} rows · ${new Date().toLocaleString()}</p><table><thead><tr>${headers.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${data.map((r) => `<tr>${r.map((v) => `<td>${esc(String(v))}</td>`).join('')}</tr>`).join('')}</tbody></table>`)
  w.document.close(); w.focus(); setTimeout(() => w.print(), 200)
}
