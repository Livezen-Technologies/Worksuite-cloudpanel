/**
 * Marketing website copy, from the design canvas (Website page). Everything a person reads on the
 * public site is here, so it can be edited without touching layout code.
 *
 * PLACEHOLDER content to replace before launch: customer logos, the stats on Home and About, the
 * team, the blog posts and the changelog entries are design samples, not facts about the company.
 */
export const BRAND = { name: 'Livezen Cloud', company: 'Livezen Technologies (Pvt) Ltd', place: 'Colombo, Sri Lanka' }

export const MENU: [string, string][] = [['Product', '/'], ['Templates', '/templates'], ['Pricing', '/pricing'], ['Docs', '/docs'], ['Changelog', '/changelog'], ['Blog', '/blog']]

export const FOOTER: { k: string; items: [string, string][] }[] = [
  { k: 'Product', items: [['Deploy', '/'], ['Databases', '/docs'], ['Networking', '/docs'], ['Pricing', '/pricing']] },
  { k: 'Resources', items: [['Docs', '/docs'], ['Templates', '/templates'], ['Changelog', '/changelog'], ['Status', '/status']] },
  { k: 'Company', items: [['About', '/about'], ['Blog', '/blog'], ['Contact sales', '/contact']] },
  { k: 'Legal', items: [['Terms', '/legal'], ['Privacy', '/legal#privacy'], ['DPA', '/legal#dpa'], ['Security', '/legal#security']] },
]

export const HOME = {
  badge: 'New · Kubernetes and private networking are live',
  title: ['Deploy anything.', 'Forget the servers.'],
  sub: 'Push code, pick a template or drop in an image. Livezen builds it, runs it, puts it behind HTTPS and scales it — on our cloud or your own servers.',
  logos: ['ACME', 'Northwind', 'Kandy Hotels', 'Studio Nila', 'Lanka Pay', 'Orbit'], // PLACEHOLDER
  canvas: [
    { k: 'web', d: 'Node 22 · 2 replicas', s: 'Online · acme-store.livezen.app', ab: 'WB', c: '#8B5CF6' },
    { k: 'api', d: 'Go 1.23 · 3 replicas', s: 'Online · 2.9k req/s', ab: 'AP', c: '#3B82F6' },
    { k: 'worker', d: 'Python 3.13 · queue', s: 'Online · 120 jobs/min', ab: 'WK', c: '#F59E0B' },
    { k: 'Postgres', d: '16 · 10 GB', s: 'Online · 2 replicas', ab: 'PG', c: '#336791' },
    { k: 'Redis', d: '7 · 256 MB', s: 'Online · hit rate 97%', ab: 'RD', c: '#DC2626' },
  ],
  features: [
    ['Git push to deploy', 'Connect GitHub, GitLab or Gitea. Every push builds from your Dockerfile or detected stack and rolls out with zero downtime.', '#8B5CF6'],
    ['Databases in one click', 'Postgres, MySQL, Redis and MongoDB with backups and connection strings injected into your services.', '#336791'],
    ['Private networking', 'Services talk over an encrypted WireGuard mesh using private names. Nothing exposed by default.', '#0EA5E9'],
    ['Environments & previews', 'Production, staging and a fresh environment for every pull request — each with its own data and URL.', '#16A34A'],
    ['Autoscaling', 'Scale replicas on CPU or traffic, split traffic for canary releases, scale idle apps to zero.', '#F59E0B'],
    ['Observability built in', 'Live logs, metrics, uptime checks and alerts to Slack, email or Telegram — no extra tools.', '#EC4899'],
    ['Domains & HTTPS', 'Custom domains, automatic certificates, DNS hosting, Cloudflare Tunnel for servers without public IPs.', '#7C3AED'],
    ['Your servers or ours', 'Run on Livezen Cloud, attach your own VPS, a Docker Swarm or a Kubernetes cluster — same workflow everywhere.', '#64748B'],
    ['Built-in security', 'Firewall, bot protection with CrowdSec, malware scanning, 2FA and audit logs for every action.', '#DC2626'],
  ] as [string, string, string][],
  steps: [
    ['Connect a repo or pick a template', 'We detect your language and framework automatically.'],
    ['Add a database or service', 'Variables and private networking are wired up for you.'],
    ['Push to deploy', 'Every commit ships with health checks and instant rollback.'],
  ] as [string, string][],
  terminal: ['~/acme-store $ git push', '→ Detected Node 22 + Vite', '→ Building with BuildKit … cache 82%', '→ Deploying 2 replicas to Singapore', '✔ Live at https://acme-store.livezen.app (1m 12s)'],
  cta: ['Your next deploy is one push away.', 'Start free. Bring your own servers any time — same dashboard, same workflow.'],
}

export type Plan = { k: string; monthly: string; yearly: string; per: string; d: string; items: string[]; btn: string; to: string; popular?: boolean }
export const PLANS: Plan[] = [
  { k: 'Hobby', monthly: '$5', yearly: '$4', per: '/ month', d: 'For side projects and learning.', items: ['$5 usage included', 'Up to 8 GB RAM per service', 'Community support', '1 project member'], btn: 'Start free', to: '/register' },
  { k: 'Pro', monthly: '$20', yearly: '$16', per: '/ seat / month', d: 'For teams shipping production apps.', items: ['$20 usage included', 'Up to 32 GB RAM per service', 'Environments & previews', 'Autoscaling & private network', 'Email support'], btn: 'Upgrade to Pro', to: '/register', popular: true },
  { k: 'Business', monthly: '$99', yearly: '$79', per: '/ month', d: 'For companies that need control.', items: ['SSO and audit logs', 'Dedicated edge & IPs', '99.95% uptime SLA', 'Priority support'], btn: 'Contact sales', to: '/contact' },
  { k: 'Your own servers', monthly: '$0', yearly: '$0', per: '+ usage', d: 'Bring your own VPS, bare metal or Kubernetes.', items: ['Unlimited servers', 'All platform features', 'Pay only for managed add-ons', 'Self-hosted option'], btn: 'Connect a server', to: '/register' },
]
export const RATES: [string, string, string][] = [['vCPU', 'per vCPU-minute', '$0.000463'], ['Memory', 'per GB-minute', '$0.000231'], ['Volume storage', 'per GB-month', '$0.25'], ['Network egress', 'per GB', '$0.05'], ['Database backups', 'per GB-month', '$0.03'], ['Extra IPv4', 'per month', '$4.00']]
/** The calculator's estimate, as on the design: $20 vCPU, $10 GB memory, $0.25 GB storage, less $20 included. */
export const estimate = (cpu: number, ram: number, gb: number) => Math.max(0, cpu * 20 + ram * 10 + gb * 0.25 - 20)
export const FAQ: [string, string][] = [
  ['Is there a free trial?', 'Yes — 14 days with $5 of usage, no card needed.'],
  ['What counts as usage?', 'CPU and memory per second, persistent storage per GB-month and outbound network per GB. Inbound traffic is free.'],
  ['Can I set a spending limit?', 'Yes. Set a hard limit per project; services pause and you are emailed before it is reached.'],
  ['Can I use my own servers?', 'Yes. Connect any VPS, bare-metal server or Kubernetes cluster and keep the same dashboard.'],
  ['Do you offer discounts?', 'Yearly billing saves 20%. Startups, non-profits and education get credits — contact sales.'],
]

export const TEMPLATES: { k: string; cat: string; d: string; n: string; c: string }[] = [
  { k: 'WordPress', cat: 'CMS', d: 'Blog or business site with MariaDB and backups.', n: '12.4k', c: '#21759B' },
  { k: 'Ghost', cat: 'CMS', d: 'Publishing with members and newsletters.', n: '3.1k', c: '#334155' },
  { k: 'n8n', cat: 'Automation', d: 'Workflow automation with queue workers.', n: '8.7k', c: '#EA4B71' },
  { k: 'Supabase', cat: 'Backend', d: 'Postgres, auth, storage and realtime.', n: '2.2k', c: '#16A34A' },
  { k: 'Plausible', cat: 'Analytics', d: 'Privacy-friendly web analytics.', n: '4.0k', c: '#4F46E5' },
  { k: 'Umami', cat: 'Analytics', d: 'Simple, fast analytics.', n: '3.6k', c: '#0F172A' },
  { k: 'Strapi', cat: 'CMS', d: 'Headless CMS and API.', n: '1.9k', c: '#4945FF' },
  { k: 'Uptime Kuma', cat: 'Monitoring', d: 'Status pages and uptime checks.', n: '6.1k', c: '#22C55E' },
  { k: 'Grafana', cat: 'Monitoring', d: 'Dashboards for your metrics.', n: '3.3k', c: '#F97316' },
  { k: 'Nextcloud', cat: 'Files', d: 'Files, calendar and contacts.', n: '2.9k', c: '#0082C9' },
  { k: 'Keycloak', cat: 'Auth', d: 'Single sign-on and identity.', n: '1.4k', c: '#4B5563' },
  { k: 'Metabase', cat: 'Analytics', d: 'BI dashboards on your database.', n: '1.7k', c: '#509EE3' },
]
export const TEMPLATE_CATS = ['All', 'CMS', 'Automation', 'Backend', 'Analytics', 'Monitoring', 'Auth', 'Files']

export const DOC_NAV: [string, string[]][] = [
  ['GETTING STARTED', ['Introduction', 'Quick start', 'Projects & environments']],
  ['DEPLOY', ['From GitHub', 'Docker images', 'Templates', 'Builds']],
  ['RUN', ['Domains & HTTPS', 'Databases', 'Private networking', 'Scaling', 'Cron jobs']],
  ['OPERATE', ['Logs & metrics', 'Backups', 'Security', 'Billing']],
]
export const DOC_STEPS: { k: string; d: string; code?: string }[] = [
  { k: '1. Create an account', d: 'Sign up and create your first project. The free trial needs no card.' },
  { k: '2. Connect your code', d: 'Connect GitHub, GitLab or Gitea, or paste an image name such as nginx:1.27.' },
  { k: '3. Deploy', d: 'We detect your stack (or use your Dockerfile), build it and roll it out behind HTTPS.', code: 'git push origin main\n✔ Live at https://acme-store.livezen.app' },
  { k: '4. Add a database', d: 'Connection variables are injected into your services automatically.' },
]

export const CHANGES: { date: string; tag: 'Feature' | 'Improvement' | 'Security' | 'Fix'; k: string; d: string; items: string[] }[] = [
  // PLACEHOLDER samples from the design: replace with real release notes (see CHANGELOG.md).
  { date: '30 Oct 2026', tag: 'Feature', k: 'Kubernetes', d: 'Connect your own cluster — same dashboard for nodes, deployments and scaling.', items: ['Kubeconfig or token', 'Scale, restart and deploy', 'Encrypted tokens'] },
  { date: '22 Oct 2026', tag: 'Feature', k: 'Project canvas', d: 'See every service in a project as a live graph.', items: ['Connections from variables', 'Per-service status and cost'] },
  { date: '15 Oct 2026', tag: 'Improvement', k: 'Faster builds with BuildKit cache', d: 'Builds reuse layers across builders through your registry.', items: ['Shared layer cache per app'] },
  { date: '8 Oct 2026', tag: 'Security', k: 'CrowdSec bot protection', d: 'Edge-level bot and brute-force blocking with community blocklists.', items: ['Under Attack mode', 'Email alerts to owners'] },
  { date: '1 Oct 2026', tag: 'Fix', k: 'Certificate renewals', d: 'Renewals for DNS-01 wildcards now retry with backoff.', items: [] },
]

export const POSTS: { cat: string; k: string; d: string; meta: string; g: string }[] = [
  // PLACEHOLDER samples from the design.
  { cat: 'Engineering', k: 'How we built a WireGuard mesh for every customer', d: 'Encrypted private networking across regions without a VPN appliance.', meta: '12 min · 28 Oct', g: '#8B5CF6,#0EA5E9' },
  { cat: 'Product', k: 'Introducing the project canvas', d: 'A new way to see and manage everything your app needs.', meta: '4 min · 22 Oct', g: '#F0ABFC,#8B5CF6' },
  { cat: 'Guide', k: 'Moving 40 WordPress sites in a weekend', d: 'A migration playbook for agencies.', meta: '9 min · 18 Oct', g: '#21759B,#22C55E' },
  { cat: 'Engineering', k: 'Per-second billing at scale', d: 'How we meter CPU and memory for thousands of containers.', meta: '10 min · 10 Oct', g: '#F59E0B,#DC2626' },
  { cat: 'Company', k: 'Growing the team in Colombo', d: 'We are hiring engineers and support.', meta: '3 min · 2 Oct', g: '#16A34A,#0EA5E9' },
  { cat: 'Guide', k: 'Zero-downtime deploys explained', d: 'Health checks, rolling updates and instant rollback.', meta: '7 min · 25 Sep', g: '#4F46E5,#EC4899' },
]

export const ABOUT = {
  stats: [['12k+', 'apps deployed'], ['40+', 'countries'], ['99.98%', 'platform uptime'], ['2021', 'founded in Colombo']] as [string, string][], // PLACEHOLDER
  values: [['Ship, don’t configure', 'Sensible defaults first, full control when you need it.'], ['No lock-in', 'Your servers, your data, standard Docker and Kubernetes.'], ['Honest pricing', 'Per-second usage and spend limits.'], ['Secure by default', 'Private networks, 2FA and audit logs on every plan.']] as [string, string][],
  team: [['Hasarinda M.', 'Founder & CEO', '#8B5CF6'], ['Sara K.', 'Head of Engineering', '#0EA5E9'], ['Nimal P.', 'Platform', '#16A34A'], ['Dilan F.', 'Design', '#F59E0B']] as [string, string, string][], // PLACEHOLDER
}

export const CONTACT_WHY: [string, string][] = [
  ['Migrations handled for you', 'We move your apps, databases and DNS with zero downtime.'],
  ['Dedicated infrastructure', 'Private edge, dedicated IPs and your own servers.'],
  ['Enterprise controls', 'SSO, audit log export and custom roles.'],
  ['SLA and support', '99.95% uptime SLA and a named engineer.'],
]
export const BUDGETS = ['Under $1,000', '$1,000 – $5,000', '$5,000 – $20,000', 'Over $20,000']

export const LEGAL_NAV: [string, string][] = [['Terms of Service', 'terms'], ['Privacy Policy', 'privacy'], ['Acceptable Use', 'aup'], ['Data Processing (DPA)', 'dpa'], ['SLA', 'sla'], ['Security', 'security']]
export const TERMS: [string, string][] = [
  ['1. Agreement', 'By creating an account you agree to these terms on behalf of yourself or the organisation you represent.'],
  ['2. The service', 'We provide hosting, deployment and related services. Features may change; we will give notice of material changes.'],
  ['3. Your content', 'You keep all rights to your code and data. You grant us only the rights needed to run the service.'],
  ['4. Acceptable use', 'No malware, spam, mining on shared plans, or illegal content. We may suspend resources that harm others.'],
  ['5. Billing', 'Plans are billed in advance; usage in arrears. Unpaid invoices may lead to suspension after notice.'],
  ['6. Liability', 'Our liability is limited to the fees paid in the 12 months before a claim, except where the law says otherwise.'],
]

export const HERO: Record<string, [string, string, string]> = {
  pricing: ['PRICING', 'Simple plans. Usage billed by the second.', 'Start free, add your team, and only pay for the CPU, memory and storage your services actually use.'],
  templates: ['TEMPLATES', 'Deploy popular apps in one click', 'Databases, CMSs, automation, analytics and more — wired up with domains, secrets and backups.'],
  docs: ['DOCUMENTATION', 'Everything you need to ship', 'Guides, references and examples for apps, databases and networking.'],
  changelog: ['CHANGELOG', 'What’s new in Livezen', 'Every improvement, shipped weekly.'],
  blog: ['BLOG', 'Notes from the team', 'Engineering deep dives, product news and guides.'],
  about: ['ABOUT', 'Hosting that gets out of your way', 'A Sri Lankan team building a global cloud platform for developers and agencies.'],
  contact: ['SALES', 'Talk to our team', 'Migrations, dedicated infrastructure, SSO, SLAs and volume pricing.'],
  legal: ['LEGAL', 'Terms of Service', 'The agreement between you and Livezen Technologies (Pvt) Ltd.'],
}
