import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { api, isApiError } from '../api/client'
import { Captcha, type CaptchaConfig, type CaptchaHandle } from '../components/Captcha'
import { PageHero } from './SiteLayout'
import { ABOUT, BRAND, BUDGETS, CHANGES, CONTACT_WHY, DOC_NAV, DOC_STEPS, FAQ, HOME, LEGAL_NAV, PLANS, POSTS, RATES, TEMPLATE_CATS, TEMPLATES, TERMS, estimate } from './content'

const ab = (s: string) => s.replace(/[^A-Za-z]/g, '').slice(0, 2).toUpperCase()

export function SiteHome() {
  useEffect(() => { document.title = `${BRAND.name} — ${HOME.title.join(' ')}` }, [])
  return <>
    <section className="lz-hero">
      <div className="lz-wrap">
        <span className="lz-badge">{HOME.badge}</span>
        <h1 className="lz-h1">{HOME.title[0]}<br />{HOME.title[1]}</h1>
        <p className="lz-sub">{HOME.sub}</p>
        <div style={{ display: 'flex', gap: 10, justifyContent: 'center', margin: '28px 0 40px', flexWrap: 'wrap' }}>
          <Link className="lz-btn pri" to="/register">Start deploying</Link>
          <Link className="lz-btn" to="/contact">Talk to sales</Link>
        </div>
        <div className="lz-card" style={{ maxWidth: 900, margin: '0 auto', textAlign: 'left' }} aria-label="Project canvas">
          <div className="lz-grid" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(160px,1fr))' }}>
            {HOME.canvas.map((n) => <div key={n.k} className="lz-card" style={{ padding: 14, boxShadow: 'none' }}>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}><span className="lz-dot" style={{ background: n.c }}>{n.ab}</span><b>{n.k}</b></div>
              <p style={{ marginTop: 8 }}>{n.d}</p><p style={{ color: '#16A34A', fontSize: 12 }}>● {n.s}</p>
            </div>)}
          </div>
        </div>
      </div>
    </section>
    <section className="lz-wrap" style={{ padding: '28px 20px', display: 'flex', gap: 32, justifyContent: 'center', flexWrap: 'wrap' }}>
      {HOME.logos.map((l) => <span key={l} className="lz-muted" style={{ fontWeight: 700, letterSpacing: '.05em' }}>{l}</span>)}
    </section>
    <section className="lz-sec"><div className="lz-wrap">
      <h2 className="lz-h2">Everything your app needs</h2>
      <div className="lz-grid" style={{ marginTop: 24 }}>
        {HOME.features.map(([k, d, c]) => <div key={k} className="lz-card"><span className="lz-dot" style={{ background: c }}>{ab(k)}</span><h3>{k}</h3><p>{d}</p></div>)}
      </div>
    </div></section>
    <section className="lz-sec" style={{ paddingTop: 0 }}><div className="lz-wrap lz-grid" style={{ alignItems: 'center' }}>
      <div><h2 className="lz-h2">From repo to production in minutes</h2>
        <ol style={{ paddingLeft: 18 }}>{HOME.steps.map(([k, d]) => <li key={k} style={{ marginBottom: 12 }}><b>{k}</b><div className="lz-muted">{d}</div></li>)}</ol></div>
      <div className="lz-term">{HOME.terminal.map((l) => <div key={l}>{l}</div>)}</div>
    </div></section>
    <section className="lz-hero"><div className="lz-wrap">
      <h2 className="lz-h2">{HOME.cta[0]}</h2><p className="lz-sub">{HOME.cta[1]}</p>
      <div style={{ marginTop: 24 }}><Link className="lz-btn pri" to="/register">Start free</Link></div>
    </div></section>
  </>
}

export function Pricing() {
  const [yearly, setYearly] = useState(false)
  const [c, setC] = useState({ cpu: 1, ram: 2, gb: 10 })
  return <>
    <PageHero page="pricing">
      <div style={{ marginTop: 20, display: 'inline-flex', gap: 4 }} role="group" aria-label="Billing period">
        <button className={'lz-pill' + (!yearly ? ' on' : '')} onClick={() => setYearly(false)}>Monthly</button>
        <button className={'lz-pill' + (yearly ? ' on' : '')} onClick={() => setYearly(true)}>Yearly · save 20%</button>
      </div>
    </PageHero>
    <section className="lz-sec"><div className="lz-wrap">
      <div className="lz-grid">
        {PLANS.map((p) => <div key={p.k} className="lz-card" style={p.popular ? { borderColor: '#b060ff', borderWidth: 2 } : undefined}>
          <h3 style={{ marginTop: 0 }}>{p.k} {p.popular && <span className="lz-badge" style={{ fontSize: 11 }}>Most popular</span>}</h3>
          <p>{p.d}</p>
          <div style={{ margin: '16px 0' }}><b style={{ fontSize: 32 }} data-testid={`price-${p.k}`}>{yearly ? p.yearly : p.monthly}</b> <span className="lz-muted">{p.per}</span></div>
          <ul style={{ paddingLeft: 18, color: 'var(--fg2)', fontSize: 14 }}>{p.items.map((i) => <li key={i}>{i}</li>)}</ul>
          <Link className={'lz-btn' + (p.popular ? ' pri' : '')} style={{ width: '100%', boxSizing: 'border-box' }} to={p.to}>{p.btn}</Link>
        </div>)}
      </div>
      <div className="lz-grid" style={{ marginTop: 48 }}>
        <div className="lz-card"><h3 style={{ marginTop: 0 }}>Usage rates</h3>
          <table style={{ width: '100%', fontSize: 14 }}><tbody>{RATES.map(([k, u, v]) => <tr key={k}><td>{k}</td><td className="lz-muted">{u}</td><td style={{ textAlign: 'right' }}>{v}</td></tr>)}</tbody></table></div>
        <div className="lz-card"><h3 style={{ marginTop: 0 }}>Estimate your bill</h3>
          {([['cpu', 'vCPU', 16], ['ram', 'Memory (GB)', 64], ['gb', 'Storage (GB)', 500]] as const).map(([k, l, max]) => <label key={k}>{l}: {c[k]}
            <input type="range" min={0} max={max} value={c[k]} onChange={(e) => setC({ ...c, [k]: Number(e.target.value) })} style={{ width: '100%' }} aria-label={l} /></label>)}
          <p>About <b data-testid="estimate">${estimate(c.cpu, c.ram, c.gb).toFixed(2)}</b> / month after the included usage.</p></div>
      </div>
      <h2 className="lz-h2" style={{ marginTop: 56 }}>Questions</h2>
      {FAQ.map(([q, a]) => <details key={q} className="lz-card" style={{ marginBottom: 10 }}><summary style={{ fontWeight: 600, cursor: 'pointer' }}>{q}</summary><p style={{ marginTop: 8 }}>{a}</p></details>)}
    </div></section>
  </>
}

export function Templates() {
  const [q, setQ] = useState('')
  const [cat, setCat] = useState('All')
  const list = TEMPLATES.filter((t) => (cat === 'All' || t.cat === cat) && (t.k + ' ' + t.d).toLowerCase().includes(q.trim().toLowerCase()))
  return <>
    <PageHero page="templates" />
    <section className="lz-sec"><div className="lz-wrap">
      <input className="lz-in" placeholder="Search templates" aria-label="Search templates" value={q} onChange={(e) => setQ(e.target.value)} style={{ maxWidth: 420 }} />
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', margin: '16px 0 24px' }}>
        {TEMPLATE_CATS.map((c) => <button key={c} className={'lz-pill' + (c === cat ? ' on' : '')} onClick={() => setCat(c)}>{c}</button>)}
      </div>
      <div className="lz-grid">
        {list.map((t) => <div key={t.k} className="lz-card"><span className="lz-dot" style={{ background: t.c }}>{ab(t.k)}</span><h3>{t.k}</h3><p>{t.d}</p>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 14 }}><span className="lz-muted" style={{ fontSize: 13 }}>{t.cat} · {t.n} deploys</span><Link className="lz-btn" to="/register">Deploy</Link></div></div>)}
      </div>
      {!list.length && <p className="lz-muted">No templates match.</p>}
    </div></section>
  </>
}

export function Docs() {
  return <>
    <PageHero page="docs" />
    <section className="lz-sec"><div className="lz-wrap lz-side">
      <nav aria-label="Docs">{DOC_NAV.map(([g, items]) => <div key={g} style={{ marginBottom: 18 }}><div className="lz-eyebrow">{g}</div>{items.map((i) => <a key={i} href="#quick-start">{i}</a>)}</div>)}</nav>
      <article id="quick-start"><h2 className="lz-h2">Quick start</h2><p className="lz-muted">Deploy your first app in about five minutes.</p>
        {DOC_STEPS.map((s) => <div key={s.k} style={{ marginTop: 24 }}><h3>{s.k}</h3><p style={{ color: 'var(--fg2)' }}>{s.d}</p>{s.code && <div className="lz-code">{s.code}</div>}</div>)}
      </article>
    </div></section>
  </>
}

const TAG: Record<string, string> = { Feature: '#8B5CF6', Improvement: '#0EA5E9', Security: '#DC2626', Fix: '#16A34A' }
export function Changelog() {
  return <>
    <PageHero page="changelog" />
    <section className="lz-sec"><div className="lz-wrap" style={{ maxWidth: 820 }}>
      {CHANGES.map((c) => <div key={c.k} className="lz-side" style={{ gridTemplateColumns: '140px 1fr', marginBottom: 36 }}>
        <div className="lz-muted">{c.date}</div>
        <div><span className="lz-badge" style={{ color: TAG[c.tag], borderColor: TAG[c.tag] }}>{c.tag}</span><h3>{c.k}</h3><p style={{ color: 'var(--fg2)' }}>{c.d}</p>
          {c.items.length > 0 && <ul style={{ color: 'var(--fg2)' }}>{c.items.map((i) => <li key={i}>{i}</li>)}</ul>}</div>
      </div>)}
    </div></section>
  </>
}

export function Blog() {
  return <>
    <PageHero page="blog" />
    <section className="lz-sec"><div className="lz-wrap lz-grid">
      {POSTS.map((p) => <article key={p.k} className="lz-card" style={{ padding: 0, overflow: 'hidden' }}>
        <div style={{ height: 140, background: `linear-gradient(135deg,${p.g})` }} />
        <div style={{ padding: 20 }}><div className="lz-eyebrow">{p.cat}</div><h3>{p.k}</h3><p>{p.d}</p><p className="lz-muted" style={{ marginTop: 10, fontSize: 13 }}>{p.meta}</p></div>
      </article>)}
    </div></section>
  </>
}

export function About() {
  return <>
    <PageHero page="about" />
    <section className="lz-sec"><div className="lz-wrap">
      <div className="lz-grid">{ABOUT.stats.map(([n, l]) => <div key={l} className="lz-card" style={{ textAlign: 'center' }}><b style={{ fontSize: 32 }}>{n}</b><p>{l}</p></div>)}</div>
      <h2 className="lz-h2" style={{ marginTop: 56 }}>What we believe</h2>
      <div className="lz-grid">{ABOUT.values.map(([k, d]) => <div key={k} className="lz-card"><h3 style={{ marginTop: 0 }}>{k}</h3><p>{d}</p></div>)}</div>
      <h2 className="lz-h2" style={{ marginTop: 56 }}>The team</h2>
      <div className="lz-grid">{ABOUT.team.map(([n, r, c]) => <div key={n} className="lz-card"><span className="lz-dot" style={{ background: c, width: 48, height: 48, borderRadius: 999 }}>{ab(n)}</span><h3>{n}</h3><p>{r}</p></div>)}</div>
    </div></section>
  </>
}

export function Contact() {
  const [f, setF] = useState({ name: '', email: '', company: '', budget: '', message: '', website: '' })
  const [state, setState] = useState<'idle' | 'busy' | 'sent'>('idle')
  const [err, setErr] = useState<{ msg?: string; fields?: Record<string, string[]> }>({})
  const [cfg, setCfg] = useState<CaptchaConfig>(null)
  const captcha = useRef<CaptchaHandle>(null)
  useEffect(() => { api.get<{ data: { captcha: CaptchaConfig } }>('/auth/config').then((r) => setCfg(r?.data?.captcha ?? null)).catch(() => {}) }, [])
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setState('busy'); setErr({})
    try {
      await api.post('/site/contact', { ...f, budget: f.budget || null, captcha: await captcha.current?.token() })
      setState('sent')
    } catch (ex) {
      setErr(isApiError(ex) ? { msg: ex.message, fields: ex.fields } : { msg: 'Could not send. Try again.' }); setState('idle')
    }
  }
  const field = (k: keyof typeof f, label: string, type = 'text') => <label>{label}
    <input className="lz-in" type={type} value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} required={k === 'name' || k === 'email'} />
    {err.fields?.[k] && <span className="lz-err">{err.fields[k][0]}</span>}</label>
  return <>
    <PageHero page="contact" />
    <section className="lz-sec"><div className="lz-wrap lz-grid" style={{ alignItems: 'start' }}>
      <div>{CONTACT_WHY.map(([k, d]) => <div key={k} style={{ marginBottom: 20 }}><h3 style={{ margin: 0 }}>{k}</h3><p className="lz-muted" style={{ margin: 0 }}>{d}</p></div>)}</div>
      <div className="lz-card">
        {state === 'sent' ? <div role="status"><h3 style={{ marginTop: 0 }}>Thanks — we got your message.</h3><p>Our team will reply by email within one business day.</p></div> :
          <form onSubmit={submit}>
            {field('name', 'Name')}{field('email', 'Work email', 'email')}{field('company', 'Company')}
            <label>Monthly budget<select className="lz-in" value={f.budget} onChange={(e) => setF({ ...f, budget: e.target.value })}><option value="">Choose…</option>{BUDGETS.map((b) => <option key={b}>{b}</option>)}</select></label>
            <label>How can we help?<textarea className="lz-in" rows={4} value={f.message} onChange={(e) => setF({ ...f, message: e.target.value })} />{err.fields?.message && <span className="lz-err">{err.fields.message[0]}</span>}</label>
            <input type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" value={f.website} onChange={(e) => setF({ ...f, website: e.target.value })} style={{ position: 'absolute', left: -9999, width: 1, height: 1 }} />
            <Captcha ref={captcha} config={cfg} action="contact" />
            {err.msg && !err.fields && <p className="lz-err" role="alert">{err.msg}</p>}
            <button className="lz-btn pri" style={{ width: '100%' }} disabled={state === 'busy'}>{state === 'busy' ? 'Sending…' : 'Send message'}</button>
          </form>}
      </div>
    </div></section>
  </>
}

export function Legal() {
  return <>
    <PageHero page="legal" />
    <section className="lz-sec"><div className="lz-wrap lz-side">
      <nav aria-label="Legal">{LEGAL_NAV.map(([k, id]) => <a key={id} href={`#${id}`}>{k}</a>)}</nav>
      <article>
        <div id="terms">{TERMS.map(([k, d]) => <div key={k}><h3>{k}</h3><p style={{ color: 'var(--fg2)' }}>{d}</p></div>)}</div>
        {LEGAL_NAV.slice(1).map(([k, id]) => <div key={id} id={id} style={{ marginTop: 40 }}><h2 className="lz-h2" style={{ fontSize: 24 }}>{k}</h2>
          <p style={{ color: 'var(--fg2)' }}>The full {k.toLowerCase()} is available on request from {BRAND.company}. Contact us through the <Link to="/contact" style={{ textDecoration: 'underline' }}>contact page</Link>.</p></div>)}
      </article>
    </div></section>
  </>
}
