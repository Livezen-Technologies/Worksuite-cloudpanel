// DRAFT policy texts for a hosting provider. Have them reviewed by a lawyer before launch.
import { BRAND } from './content'

export const LEGAL_DRAFT_NOTE = 'Draft — these policies are being reviewed and may change before launch.'

export const POLICIES: Record<'privacy' | 'aup' | 'dpa' | 'sla' | 'security', [string, string][]> = {
  privacy: [
    ['Who we are', `${BRAND.company}, ${BRAND.place}, runs ${BRAND.name} and is responsible for the personal data described here.`],
    ['What we collect', 'Account details (name, email, organisation), billing details handled by our payment providers, sign-in and audit records (IP address, device, time), support messages, and usage metrics needed to run and bill the service.'],
    ['What we do not do', 'We do not sell personal data and we do not look inside the apps, files or databases you host, except when you ask for support or the law requires it.'],
    ['Why we use it', 'To provide and secure the service, bill you, answer support requests, send service and security emails, and meet legal obligations. Product emails can be turned off in your account.'],
    ['Who we share it with', 'Payment processors, email delivery, infrastructure and data-centre providers, each bound by a contract to protect the data. A current list is available on request.'],
    ['How long we keep it', 'Account data while your account is open and up to 90 days after it closes; invoices for as long as tax law requires; security logs for up to 12 months.'],
    ['Your rights', 'You can see, correct, export or delete your personal data, and object to some uses. Contact us through the contact page and we will reply within 30 days.'],
    ['Cookies', 'The website and panel use only cookies and local storage needed to sign you in and remember settings such as the theme.'],
  ],
  aup: [
    ['Not allowed', 'Malware, phishing, spam or bulk unsolicited email; content that is illegal where it is hosted or served; infringing others’ rights; attacking, scanning or probing systems you do not own.'],
    ['Resource abuse', 'Cryptocurrency mining on shared plans, open proxies or relays, and anything that harms the performance of other customers.'],
    ['Enforcement', 'We may limit, suspend or remove resources that break these rules, normally after notice. Where there is ongoing harm to others we may act first and tell you straight after.'],
    ['Reporting abuse', 'Report abuse through the contact page with the address involved and evidence. We review reports within one business day.'],
  ],
  dpa: [
    ['Roles', 'For data inside the apps you host, you are the controller and we are the processor. We process it only on your documented instructions — that is, to run the service you configured.'],
    ['Confidentiality', 'People who can access customer data are bound by confidentiality and get access only when needed.'],
    ['Security measures', 'Encryption in transit, encrypted secrets at rest, two-factor sign-in, least-privilege access, audit logging and backups, as described in the Security section.'],
    ['Sub-processors', 'We use infrastructure, email and payment sub-processors under written contracts with equivalent protections, and give notice before adding new ones.'],
    ['Breaches', 'We tell you without undue delay, and within 72 hours of becoming aware, of a personal data breach affecting your data.'],
    ['End of service', 'When your account closes you can export your data; we delete it within 90 days unless the law requires us to keep it.'],
    ['Signing', 'Business customers can request a signed DPA through the contact page.'],
  ],
  sla: [
    ['Commitment', 'Business plan: 99.95% monthly uptime for the platform’s control plane and edge on Livezen Cloud. Other plans are best effort.'],
    ['What counts as downtime', 'Minutes when your running services cannot be reached because of a fault in our infrastructure. Scheduled maintenance announced 48 hours ahead, faults in your code, your own servers, or third parties are excluded.'],
    ['Service credits', 'Below 99.95%: 10% of that month’s plan fee; below 99.0%: 25%; below 95.0%: 50%. Request credits within 30 days through the contact page.'],
    ['Status', 'Live status and incident history are on the status page.'],
  ],
  security: [
    ['Accounts', 'Two-factor authentication, single sign-on on Business, session management, and an audit log of every action.'],
    ['Network', 'Services talk over an encrypted WireGuard private network; nothing is exposed unless you publish it. Firewall, rate limiting and CrowdSec bot protection at the edge.'],
    ['Data', 'HTTPS everywhere with automatic certificates; secrets and credentials are stored encrypted; backups can be encrypted and tested by restoring them.'],
    ['Operations', 'Staff access is limited, logged and requires two-factor sign-in. Customers can see when support signs in to their account.'],
    ['Reporting a vulnerability', 'Tell us through the contact page. We acknowledge reports within two business days and ask that you give us time to fix the issue before sharing it.'],
  ],
}
