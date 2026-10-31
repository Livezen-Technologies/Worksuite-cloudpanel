import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router'
import type { ReactNode } from 'react'
import { isApiError } from './api/client'
import { AuthProvider, homeFor, useAuth } from './lib/auth'
import { ToastProvider } from './components/Toasts'
import { JobsProvider } from './api/jobs'
import { ForgotPassword, InviteAccept, Login, Register, ResetPassword, VerifyEmail } from './pages/auth/Auth'
import { AdminLayout } from './pages/admin/AdminLayout'
import Overview from './pages/admin/Overview'
import Servers from './pages/admin/Servers'
import SystemServices from './pages/admin/SystemServices'
import ServerDetail from './pages/admin/ServerDetail'
import Containers from './pages/admin/Containers'
import { Applications, Compose } from './pages/admin/Deploy'
import { Images, Networks, Volumes, Domains, Databases } from './pages/admin/Resources'
import { FileManager } from './pages/admin/FileManager'
import { Backups, Monitoring, Logs, Automation } from './pages/admin/Operations'
import { Users, Settings } from './pages/admin/Admin'
import { AdminAccount } from './pages/admin/Account'
import Firewall from './pages/admin/Firewall'
import Antivirus from './pages/admin/Antivirus'
import DnsZones from './pages/admin/DnsZones'
import WordPress from './pages/admin/WordPress'
import Migrations from './pages/admin/Migrations'
import Status from './pages/public/Status'
import Uptime from './pages/admin/Uptime'
import Mail from './pages/admin/Mail'
import PublicPages from './pages/admin/PublicPages'
import { CronManager } from './components/CronManager'
import { PanelLayout } from './pages/app/PanelLayout'
import { MyApps, AppDetail } from './pages/app/Apps'
import NewSite from './pages/app/NewSite'
import HostApp from './pages/app/HostApp'
import { UserCron, UserSecurity, UserMail, UserMigrations, UserFirewall } from './pages/app/Extras'
import { UserDomains, UserBackups, Billing, Account } from './pages/app/Panel'
import Catalog from './pages/admin/Catalog'
import Projects from './pages/admin/Projects'
import PrivateNetwork from './pages/admin/PrivateNetwork'
import { Packages, Customers } from './pages/admin/Hosting'
import AppManage from './pages/admin/AppManage'

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5000,
      retry: (n, e) => !(isApiError(e) && [401, 403, 404, 422].includes(e.status)) && n < 2,
      refetchOnWindowFocus: false,
    },
  },
})

function RequireAuth({ children }: { children: ReactNode }) {
  const { me, loading } = useAuth()
  const loc = useLocation()
  if (loading) return <div className="p-8 text-muted">Loading…</div>
  if (!me) return <Navigate to="/login" replace state={{ from: loc.pathname }} />
  return <>{children}</>
}
function Home() {
  const { me, loading } = useAuth()
  if (loading) return <div className="p-8 text-muted">Loading…</div>
  return <Navigate to={me ? homeFor(me) : '/login'} replace />
}

export function AppRoutes() {
  return (
    <Routes>
      <Route path="/" element={<Home />} />
      <Route path="/login" element={<Login />} />
      <Route path="/status" element={<Status />} />
      <Route path="/invite/:token" element={<InviteAccept />} />
      <Route path="/register" element={<Register />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route path="/verify-email" element={<VerifyEmail />} />
      <Route path="/admin" element={<RequireAuth><AdminLayout /></RequireAuth>}>
        <Route index element={<Overview />} />
        <Route path="servers" element={<Servers />} />
        <Route path="system-services" element={<SystemServices />} />
        <Route path="servers/:id" element={<ServerDetail />} />
        <Route path="containers" element={<Containers />} />
        <Route path="firewall" element={<Firewall />} />
        <Route path="antivirus" element={<Antivirus />} />
        <Route path="dns" element={<DnsZones />} />
        <Route path="wordpress" element={<WordPress />} />
        <Route path="migrations" element={<Migrations />} />
        <Route path="mail" element={<Mail />} />
        <Route path="public-pages" element={<PublicPages />} />
        <Route path="cron" element={<CronManager base="/cron" title="Cron & scheduler" subtitle="Every scheduled job, its runs and its alerts" />} />
        <Route path="applications" element={<Applications />} />
        <Route path="catalog" element={<Catalog />} />
        <Route path="projects" element={<Projects />} />
        <Route path="private-network" element={<PrivateNetwork />} />
        <Route path="applications/:id" element={<AppManage />} />
        <Route path="packages" element={<Packages />} />
        <Route path="customers" element={<Customers />} />
        <Route path="compose" element={<Compose />} />
        <Route path="images" element={<Images />} />
        <Route path="networks" element={<Networks />} />
        <Route path="volumes" element={<Volumes />} />
        <Route path="files" element={<FileManager />} />
        <Route path="domains" element={<Domains />} />
        <Route path="databases" element={<Databases />} />
        <Route path="backups" element={<Backups />} />
        <Route path="monitoring" element={<Monitoring />} />
        <Route path="uptime" element={<Uptime />} />
        <Route path="logs" element={<Logs />} />
        <Route path="automation" element={<Automation />} />
        <Route path="users" element={<Users />} />
        <Route path="settings" element={<Settings />} />
        <Route path="account" element={<AdminAccount />} />
      </Route>
      <Route path="/app" element={<RequireAuth><PanelLayout /></RequireAuth>}>
        <Route index element={<MyApps />} />
        <Route path="host" element={<HostApp />} />
        <Route path="new-site" element={<NewSite />} />
        <Route path="apps/:id" element={<AppDetail />} />
        <Route path="files" element={<FileManager customer />} />
        <Route path="domains" element={<UserDomains />} />
        <Route path="backups" element={<UserBackups />} />
        <Route path="email" element={<UserMail />} />
        <Route path="security" element={<UserSecurity />} />
        <Route path="firewall" element={<UserFirewall />} />
        <Route path="cron" element={<UserCron />} />
        <Route path="move" element={<UserMigrations />} />
        <Route path="billing" element={<Billing />} />
        <Route path="account" element={<Account />} />
      </Route>
      <Route path="*" element={<div className="p-8"><h1>Page not found</h1><a href="/">Go home</a></div>} />
    </Routes>
  )
}

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <JobsProvider>
          <BrowserRouter>
            <AuthProvider><AppRoutes /></AuthProvider>
          </BrowserRouter>
        </JobsProvider>
      </ToastProvider>
    </QueryClientProvider>
  )
}
