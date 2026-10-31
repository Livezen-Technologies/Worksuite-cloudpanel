import {
  ArchiveBoxIcon, ArrowRightStartOnRectangleIcon, Bars3Icon, BellIcon, BoltIcon, BuildingStorefrontIcon,
  CheckIcon, ChevronRightIcon, CircleStackIcon, ClockIcon, CloudIcon, Cog6ToothIcon, CubeIcon,
  DocumentDuplicateIcon, DocumentTextIcon, GlobeAltIcon, MagnifyingGlassIcon, MoonIcon, PhotoIcon,
  RocketLaunchIcon, ServerStackIcon, ShareIcon, ShieldCheckIcon, Squares2X2Icon, SunIcon,
  TagIcon, UserCircleIcon, UserGroupIcon, XMarkIcon,
  EyeIcon, PencilSquareIcon, TrashIcon, ArrowDownTrayIcon, EllipsisHorizontalIcon, FunnelIcon, ViewColumnsIcon,
  AdjustmentsHorizontalIcon, ListBulletIcon, TableCellsIcon, BookmarkIcon, ArrowPathIcon, PlayIcon, StopIcon,
  ChevronUpIcon, ChevronDownIcon, ChevronLeftIcon, ExclamationTriangleIcon, InboxIcon, PlusIcon, Bars2Icon,
} from '@heroicons/react/24/outline'
import type { ComponentType, SVGProps } from 'react'

type IconComponent = ComponentType<SVGProps<SVGSVGElement>>

/**
 * Named icons for navigation and buttons.
 *
 * These were hand-drawn paths, which meant every new name had to be drawn by
 * hand and the set drifted in weight and detail. They now map onto Heroicons,
 * which Tailwind Plus is drawn against, so the panel and any component taken
 * from that kit share one icon language. The name keys and the component's
 * props are unchanged, so call sites are untouched.
 */
const ICONS: Record<string, IconComponent> = {
  logo: CubeIcon,
  search: MagnifyingGlassIcon,
  bell: BellIcon,
  menu: Bars3Icon,
  close: XMarkIcon,
  sun: SunIcon,
  moon: MoonIcon,
  copy: DocumentDuplicateIcon,
  check: CheckIcon,
  chevron: ChevronRightIcon,
  logout: ArrowRightStartOnRectangleIcon,
  grid: Squares2X2Icon,
  server: ServerStackIcon,
  box: CubeIcon,
  rocket: RocketLaunchIcon,
  store: BuildingStorefrontIcon,
  layers: Squares2X2Icon,
  image: PhotoIcon,
  share: ShareIcon,
  disk: CloudIcon,
  globe: GlobeAltIcon,
  shield: ShieldCheckIcon,
  db: CircleStackIcon,
  archive: ArchiveBoxIcon,
  pulse: BoltIcon,
  file: DocumentTextIcon,
  clock: ClockIcon,
  users: UserGroupIcon,
  tag: TagIcon,
  gear: Cog6ToothIcon,
  user: UserCircleIcon,
  eye: EyeIcon,
  pencil: PencilSquareIcon,
  trash: TrashIcon,
  duplicate: DocumentDuplicateIcon,
  download: ArrowDownTrayIcon,
  dots: EllipsisHorizontalIcon,
  funnel: FunnelIcon,
  columns: ViewColumnsIcon,
  adjust: AdjustmentsHorizontalIcon,
  list: ListBulletIcon,
  table: TableCellsIcon,
  bookmark: BookmarkIcon,
  refresh: ArrowPathIcon,
  play: PlayIcon,
  stop: StopIcon,
  up: ChevronUpIcon,
  down: ChevronDownIcon,
  left: ChevronLeftIcon,
  warn: ExclamationTriangleIcon,
  inbox: InboxIcon,
  plus: PlusIcon,
  density: Bars2Icon,
}

export function Icon({ name, size = 18, className }: { name: keyof typeof ICONS | string; size?: number; className?: string }) {
  const Glyph = ICONS[name]
  if (!Glyph) {
    // An unknown name reserves its space rather than collapsing the layout.
    return <span aria-hidden="true" style={{ width: size, height: size }} className={`inline-block ${className ?? ''}`} />
  }

  return <Glyph aria-hidden="true" width={size} height={size} className={className} />
}
