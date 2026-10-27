export interface Paged<T> { data: T[]; meta: { page: number; per_page: number; total: number } }
export interface One<T> { data: T }
export type JobStatus = 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled'
export interface JobStage { name: string; status: string; started_at?: string | null; finished_at?: string | null; message?: string | null }
export interface Job { id: string; type: string; status: JobStatus; progress: number; stages: JobStage[]; result?: unknown; error?: unknown; created_at: string }
export interface Accepted { job_id: string; [k: string]: unknown }

export interface User { id: string; name: string; email: string; mfa_enabled?: boolean; [k: string]: unknown }
export interface Me { user: User; organization: { id: string; name: string; [k: string]: unknown }; role: string; permissions: string[] }

export type ServerStatus = 'pending' | 'online' | 'warning' | 'maintenance' | 'offline'
export interface Server {
  id: string; name: string; hostname: string; ip: string; environment: string; region: string; tags: string[]
  status: ServerStatus; os?: string; kernel?: string; docker_version?: string; cpu_cores?: number; ram_mb?: number; disk_gb?: number
  cpu_pct?: number; ram_pct?: number; disk_pct?: number; container_count?: number; agent_version?: string; last_heartbeat_at?: string | null
  provider?: string
}
export interface Container {
  id: string; name: string; image: string; server_id: string; server_name?: string; server?: { id: string; name: string }
  state: string; health?: string | null; cpu_pct?: number; mem_mb?: number; ports?: string | string[]; created?: string; uptime?: string
  limits?: { cpus?: number; memory_mb?: number }; user?: string; restart_policy?: string
}
