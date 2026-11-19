export interface User {
  id: number;
  name: string;
  email: string;
  role: string;
  roleLabel: string;
  avatarUrl: string | null;
}

export interface Agency {
  id: number;
  name: string;
  logoUrl: string | null;
}

export interface NavItem {
  module: string;
  label: string;
  icon: string;
  href: string;
  legacy?: boolean;
}

export interface NavGroup {
  key: string;
  label: string;
  icon?: string;
  items: NavItem[];
}

export interface SidebarProject {
  id: number;
  name: string;
  color: string;
  shared: boolean;
  /** Todas las tareas del proyecto están en columnas de tipo "done". */
  completed?: boolean;
}

export interface ProjectGroup {
  key: string;
  name: string;
  shared: boolean;
  projects: SidebarProject[];
}

export interface Bootstrap {
  user: User;
  agency: Agency;
  csrfToken: string;
  modules: Record<string, boolean>;
  navGroups: NavGroup[];
  projectsGroups: ProjectGroup[];
  settingsHref: string;
  today: string;
}

export interface DashboardData {
  stats: {
    totalProjects: number;
    activeProjects: number;
    openTickets: number;
    resolvedTickets: number;
    totalTasks: number;
    completedTasks: number;
    inProgressTasks: number;
    pendingTasks: number;
    completionRate: number;
  };
  taskStatusChart: {
    labels: string[];
    data: number[];
    colors: string[];
    labelsFooter: Array<{ label: string; value: number }>;
  };
  priorityChart: { labels: string[]; data: number[]; colors: string[] };
  categoryChart: { labels: string[]; data: number[] };
  projectProgress: Array<{
    id: number;
    name: string;
    color: string;
    category: string;
    status: string;
    progress: number;
    tasks: number;
    completedTasks: number;
    pendingTasks: number;
    shared: boolean;
    sharedBy?: string | null;
  }>;
  finance: null | {
    budget: Record<string, number>;
    collected: Record<string, number>;
    pending: Record<string, number>;
    payables: Record<string, number>;
    payablesCount: number;
  };
  date: string;
}

export interface ProjectListItem {
  id: number;
  name: string;
  clientName?: string | null;
  status: string;
  category: string | null;
  color: string;
  agencyName: string;
  shared: boolean;
  currency: string;
  startDate?: string | null;
  dueDate?: string | null;
  budget: number | null;
  tasks: number;
  members: number;
  overdueTasks: number;
  progress: number;
}

export interface Task {
  id: number;
  title: string;
  description?: string | null;
  status: string;
  statusLabel: string;
  priority: string;
  dueDate: string | null;
  createdAt?: string;
  projectId: number;
  projectName: string;
  projectColor: string;
  agencyId?: number;
  agencyName?: string;
  assignee: string | null;
  assignedUserId?: number | null;
  sortOrder?: number;
  parentTaskId?: number | null;
  editable?: boolean;
}

export interface ProjectDetail {
  project: {
    id: number;
    name: string;
    clientName?: string | null;
    description: string | null;
    status: string;
    category: string | null;
    color: string;
    currency: string;
    agencyName: string;
    isOwnerAgency: boolean;
    shared: boolean;
    canManage: boolean;
    canEditTasks: boolean;
    canManageShare?: boolean;
    canManageShareMembers?: boolean;
    sharedWithCount?: number;
    sharedBy?: string | null;
    budget: number | null;
    startDate: string | null;
    dueDate: string | null;
    createdAt: string | null;
  };
  members: Array<{
    id: number;
    name: string;
    email: string;
    role: string;
    projectRole: string | null;
    avatarUrl: string | null;
  }>;
  tasks: Array<{
    id: number;
    title: string;
    description: string | null;
    status: string;
    statusLabel: string;
    priority: string;
    dueDate: string | null;
    startDate: string | null;
    assignee: string | null;
    assignedUserId: number | null;
    commentsCount: number;
    sortOrder?: number;
  }>;
  stats: { total: number; done: number; progress: number; checklistTotal: number; checklistDone: number };
  isClient: boolean;
  canManageChecklists: boolean;
  canChangeStatus: boolean;
  canDeleteTasks: boolean;
  statuses: TaskStatus[];
}

export interface TaskStatus {
  key: string;
  label: string;
  kind: string;
}

export interface TasksResponse {
  tasks: Task[];
  statuses: TaskStatus[];
  statusCounts: Record<string, number>;
  collaborators: Array<{ id: number; name: string }>;
  projects: Array<{ id: number; name: string; color: string }>;
}

export interface CalendarEvent {
  id: number;
  type: 'task' | 'project' | 'invoice';
  date: string;
  title: string;
  subtitle: string;
  projectId: number | null;
  projectColor: string;
  done: boolean;
  priority?: string;
  status?: string;
  amount?: number;
  currency?: string;
  href: string;
}

export interface CalendarData {
  month: string;
  monthStart: string;
  monthEnd: string;
  view: 'month' | 'week' | 'agenda';
  rangeStart: string;
  rangeEnd: string;
  weekStart: string;
  dates: string[];
  today: string;
  events: CalendarEvent[];
  overdue: CalendarEvent[];
  projects: Array<{ id: number; name: string; color: string }>;
}

export interface CrmData {
  stats: {
    open_deals: number;
    open_value: number;
    won_deals: number;
    won_value: number;
    lost_deals: number;
    won_month: number;
    won_value_month: number;
    close_rate: number;
    companies: number;
    contacts: number;
  };
  stageCounts: Record<string, number>;
  stages: Array<{ key: string; label: string }>;
  deals: Array<{
    id: number;
    title: string;
    company: string | null;
    companyId: number | null;
    contact: string | null;
    contactId: number | null;
    contactEmail: string | null;
    contactPhone: string | null;
    companyPhone: string | null;
    description: string | null;
    stage: string;
    value: number;
    currency: string;
    expectedClose: string | null;
    owner: string | null;
    ownerId: number | null;
    source: string | null;
    lostReason: string | null;
    createdAt: string | null;
    updatedAt: string | null;
    wonAt: string | null;
    lostAt: string | null;
    convertedAt: string | null;
    lastActivityAt: string | null;
    nextActivityAt: string | null;
    nextActivityTitle: string | null;
    convertedProjectId: number | null;
    convertedProject: string | null;
    quotesCount: number;
    quotesValue: number;
    latestQuoteStatus: string | null;
  }>;
  companies: Array<{
    id: number;
    name: string;
    industry: string | null;
    website: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    notes: string | null;
    contacts: number;
    deals: number;
    projects: number;
    quotes: number;
    openValue: number;
  }>;
  contacts: Array<{
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    position: string | null;
    role: string | null;
    notes: string | null;
    companyId: number | null;
    company: string | null;
  }>;
  activities: Array<{
    id: number;
    type: string;
    subject: string;
    notes: string | null;
    dueAt: string | null;
    user: string | null;
    dealId: number | null;
    dealTitle: string | null;
    companyId: number | null;
    companyName: string | null;
    contactId: number | null;
    contactName: string | null;
  }>;
  owners: Array<{ id: number; name: string }>;
  sources: string[];
  activityTypes: Array<{ key: string; label: string }>;
  currencies: string[];
}

export interface BillingData {
  invoices: Array<{
    id: number;
    number: string;
    client: string | null;
    concept: string;
    notes: string | null;
    amount: number;
    paid: number;
    balance: number;
    currency: string;
    projectCurrency: string | null;
    status: string;
    displayStatus: string;
    dueDate: string | null;
    paidAt: string | null;
    createdAt: string | null;
    overdue: boolean;
    daysOverdue: number;
    projectId: number | null;
    projectName: string | null;
    projectColor: string;
    payments: Array<{
      id: number;
      amount: number;
      currency: string;
      paidAt: string;
      method: string | null;
      notes: string | null;
      user: string | null;
    }>;
  }>;
  stats: {
    pending: Record<string, number>;
    paid: Record<string, number>;
    overdue: Record<string, number>;
    total: Record<string, number>;
    cancelled: Record<string, number>;
    countPending: number;
    countPaid: number;
    countTotal: number;
    countOverdue: number;
    effectiveness: number;
  };
  pagination: { page: number; perPage: number; total: number; pages: number };
  projects: Array<{ id: number; name: string; currency: string; client: string | null; budget: number }>;
  clients: string[];
  currencies: string[];
}

export interface FinanceData {
  tab: string;
  receivables: Array<{
    id: number;
    number: string;
    client: string | null;
    concept: string;
    amount: number;
    paid: number;
    balance: number;
    currency: string;
    dueDate: string | null;
    status: string;
    displayStatus: string;
    paidAt: string | null;
    overdue: boolean;
    daysOverdue: number;
    projectId: number | null;
    projectName: string | null;
    projectColor: string;
    payments: Array<{
      id: number;
      amount: number;
      currency: string;
      paidAt: string;
      method: string | null;
      notes: string | null;
      user: string | null;
    }>;
  }>;
  payables: Array<{
    id: number;
    supplier: string;
    concept: string;
    category: string | null;
    kind: string;
    amount: number;
    paid: number;
    balance: number;
    currency: string;
    dueDate: string | null;
    notes: string | null;
    status: string;
    dbStatus: string;
    overdue: boolean;
    daysOverdue: number;
    projectId: number | null;
    projectName: string | null;
    projectColor: string;
    payments: Array<{
      id: number;
      amount: number;
      currency: string;
      paidAt: string;
      method: string | null;
      notes: string | null;
      user: string | null;
    }>;
  }>;
  stats: {
    receivables: {
      pending: Record<string, number>;
      overdue: Record<string, number>;
      paid: Record<string, number>;
      countPending: number;
      countOverdue: number;
    };
    payables: {
      outstanding: Record<string, number>;
      overdue: Record<string, number>;
      paid: Record<string, number>;
      countOpen: number;
      countOverdue: number;
    };
  };
  counts: { receivables: number; payables: number; filtered: number };
  pagination: { page: number; perPage: number; total: number; pages: number };
  projects: Array<{ id: number; name: string; currency: string }>;
  clients: string[];
  contacts: string[];
  currencies: string[];
  today: string;
}

export interface ProfitabilityRow {
  id: number;
  name: string;
  color: string;
  status: string;
  currency: string;
  metrics: {
    currency: string;
    budget: number;
    budget_by_currency: Record<string, number>;
    income: Record<string, number>;
    collected: Record<string, number>;
    pending: Record<string, number>;
    direct_expenses: Record<string, number>;
    direct_paid: Record<string, number>;
    direct_pending: Record<string, number>;
    collaborator_payments: Record<string, number>;
    collaborator_paid: Record<string, number>;
    collaborator_pending: Record<string, number>;
    margin: Record<string, number>;
    income_project: number;
    margin_project: number;
    margin_percent: number | null;
    has_other_currencies: boolean;
    counts: { invoices: number; payables: number; direct: number; collaborator: number };
  };
}

export interface ProfitabilityData {
  rows: ProfitabilityRow[];
  totals: {
    budget: Record<string, number>;
    income: Record<string, number>;
    collected: Record<string, number>;
    pending: Record<string, number>;
    direct_expenses: Record<string, number>;
    collaborator_payments: Record<string, number>;
    margin: Record<string, number>;
    project_count: number;
    positive_margin_projects: number;
    negative_margin_projects: number;
  };
  totalRows: number;
  hasNegativeMargin: boolean;
  pagination: { page: number; perPage: number; total: number; pages: number };
}

export interface ReportsData {
  section: string;
  report: string;
  types: Array<{ key: string; label: string }>;
  typeLabels: Record<string, string>;
  frequencyLabels: Record<string, string>;
  filters: { from: string; to: string; project_id: number };
  dataset: {
    title: string;
    headers: string[];
    rows: Array<Array<string | number | null>>;
    summary: Record<string, number | Record<string, number>>;
    chart: {
      type: string;
      labels: string[];
      series: Array<{ label: string; data: number[]; color?: string; colors?: string[] }>;
    };
  };
  projects: Array<{ id: number; name: string }>;
  savedViews: Array<{
    id: number;
    name: string;
    reportType: string;
    filters: { from: string; to: string; project_id: number };
    createdAt: string;
  }>;
  activeViewId: number | null;
  schedules: Array<{
    id: number;
    name: string;
    reportType: string;
    frequency: string;
    recipients: string;
    active: boolean;
    nextRunAt: string | null;
    lastRunAt: string | null;
    author: string | null;
    createdAt: string;
  }>;
  exportUrl: string;
  exportPdfUrl: string;
}

export interface SettingsData {
  isAdmin: boolean;
  profile: {
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    agency: string;
    avatarUrl: string | null;
  };
  permissions: null | {
    matrix: Record<string, Record<string, boolean>>;
    modules: Record<string, string>;
    groups: Record<string, string[]>;
    ceilings: Record<string, string[]>;
    core: string[];
    labels: Record<string, string>;
  };
  activity: Array<{
    id: number;
    action: string;
    description: string | null;
    user: string | null;
    createdAt: string;
    label: string;
    projectName: string | null;
  }>;
  activityLabels: Record<string, string>;
  activityProjects: Array<{ id: number; name: string }>;
  activityPagination: { page: number; perPage: number; total: number; pages: number };
  activityFilters: { action: string; projectId: number };
  avatars: Array<{ key: string; label: string; file: string; gender: string }>;
  twoFactorEnabled: boolean;
  taskStatuses: Array<{ key: string; label: string; kind: string }>;
  taskStatusDefaults: string[];
  branding: null | { name: string; slug: string; logoUrl: string | null };
  appUrl: string;
}

export interface UsersData {
  users: Array<{
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    projectIds: number[];
    projectNames: string[];
    createdAt: string;
  }>;
  roles: Array<{ key: string; label: string; className: string; dot: string }>;
  stats: { total: number; admins: number; managers: number; collaborators: number; clients: number };
  projects: Array<{ id: number; name: string; color: string }>;
  invitations: Array<{ id: number; email: string; role: string; roleLabel: string; expiresAt: string }>;
  pagination: { page: number; perPage: number; total: number; pages: number };
  currentUserId: number;
}

export interface InvitationResult {
  success: boolean;
  invitePath: string;
  expiresDays: number;
  message: string;
}

export interface QuotesData {
  quotes: Array<{
    id: number;
    number: string;
    title: string;
    client: string;
    company: string | null;
    clientEmail: string | null;
    issueDate: string;
    validUntil: string | null;
    total: number;
    currency: string;
    status: string;
    statusLabel: string;
    projectName: string | null;
    projectId: number | null;
    projectColor: string;
    itemCount: number;
    dealTitle: string | null;
    dealCompany: string | null;
    dealCompanyId: number | null;
    sentAt: string | null;
    acceptedAt: string | null;
    rejectedAt: string | null;
    lastEmailedAt: string | null;
    emailCount: number;
    version: number;
    parentQuoteId: number | null;
    invoiceId: number | null;
    invoiceNumber: string | null;
  }>;
  totals: Record<string, { count: number; total: number }>;
  acceptedTotals: Record<string, number>;
  pendingTotals: Record<string, number>;
  stats: { count: number; countAccepted: number; countPending: number; acceptanceRate: number };
  projects: Array<{ id: number; name: string }>;
  clients: string[];
  companies: string[];
  contacts: Array<{ id: number; name: string; email: string | null; phone: string | null; position: string | null; company: string | null }>;
  statuses: Array<{ key: string; label: string }>;
}

export interface QuoteDetailData {
  quote: {
    id: number;
    number: string;
    title: string;
    clientName: string;
    clientCompany: string | null;
    clientEmail: string | null;
    issueDate: string;
    validUntil: string | null;
    currency: string;
    subtotal: number;
    discountType: string;
    discountValue: number;
    discountAmount: number;
    taxRate: number;
    taxAmount: number;
    total: number;
    notes: string | null;
    terms: string | null;
    status: string;
    statusLabel: string;
    projectName: string | null;
    projectId: number | null;
    companyName: string | null;
    createdBy: string | null;
    dealTitle: string | null;
    sentAt: string | null;
    acceptedAt: string | null;
    rejectedAt: string | null;
    lastEmailedAt: string | null;
    emailCount: number;
    version: number;
    parentQuoteId: number | null;
    invoiceId: number | null;
    invoiceNumber: string | null;
    dealId: number | null;
    companyId: number | null;
  };
  items: Array<{ id: number; description: string; quantity: number; unitPrice: number; total: number }>;
  statuses: Array<{ key: string; label: string }>;
}

export interface TemplatesData {
  templates: Array<{
    id: number;
    name: string;
    description: string | null;
    category: string | null;
    color: string;
    createdAt: string;
    createdBy: string | null;
  }>;
}

export interface TrashData {
  projects: Array<{
    id: number;
    name: string;
    color: string;
    archivedAt: string;
    archivedBy: string | null;
  }>;
  isAdmin: boolean;
}

export interface SupportData {
  requests: Array<{
    id: number;
    subject: string;
    message: string;
    category: string;
    priority: string;
    status: string;
    user: string | null;
    contactEmail: string | null;
    createdAt: string;
    resolvedAt: string | null;
    imagePath: string | null;
  }>;
  pagination: { page: number; perPage: number; total: number; pages: number };
  supportEmail: string;
  brandName: string;
}

export interface ConnectionsData {
  code: string;
  connected: Array<{ id: number; agencyId: number; agencyName: string; agencyLogo: string | null; createdAt: string; acceptedAt: string | null }>;
  incoming: Array<{ id: number; agencyId: number; agencyName: string; agencyLogo: string | null; createdAt: string; acceptedAt: string | null }>;
  outgoing: Array<{ id: number; agencyId: number; agencyName: string; agencyLogo: string | null; createdAt: string; acceptedAt: string | null }>;
}

export interface ApiTokensData {
  tokens: Array<{
    id: number;
    name: string;
    prefix: string;
    scopes: string[];
    projects: number[];
    rateLimit: number;
    expiresAt: string | null;
    revokedAt: string | null;
    lastUsedAt: string | null;
    lastUsedIp: string | null;
    createdAt: string;
    creator: string | null;
    isRevoked: boolean;
    isExpired: boolean;
    webhookUrl: string | null;
    webhookActive: boolean;
    webhookEvents: string[];
    webhookSecret: string;
    deliveries: Array<{
      id: number;
      eventType: string;
      status: string;
      attempts: number;
      responseCode: number | null;
      responseBody: string | null;
      lastAttemptAt: string | null;
      createdAt: string;
    }>;
  }>;
  scopes: Record<string, string>;
  webhookEvents: Record<string, string>;
  projects: Array<{ id: number; name: string }>;
  appUrl: string;
}

export interface ProjectTabsData {
  comments: Array<{ id: number; parentId: number | null; taskId: number | null; taskTitle: string | null; userId: number | null; content: string; imagePath: string | null; user: string; avatarUrl: string | null; createdAt: string }>;
  files: Array<{ id: number; name: string; size: number; type: string; user: string | null; userId?: number | null; folderId?: number | null; taskId?: number | null; taskTitle?: string | null; createdAt: string; downloadUrl: string }>;
  folders: Array<{ id: number; name: string; color: string | null }>;
  links: Array<{ id: number; title: string; url: string; createdAt: string; taskId?: number | null; taskTitle?: string | null }>;
  tickets: Array<{ id: number; title: string; description: string; priority: string; status: string; user: string | null; createdAt: string; externalSource: string | null; externalRequesterName: string | null; externalUrl: string | null; repliesCount: number }>;
  checklists: Array<{
    id: number;
    title: string;
    taskId: number | null;
    taskTitle: string | null;
    items: Array<{ id: number; title: string; isCompleted: boolean }>;
    completed: number;
    total: number;
    percentage: number;
  }>;
  activity: Array<{ id: number; label: string; description: string | null; user: string | null; createdAt: string }>;
  permissions: {
    canComment: boolean;
    canManageStructure?: boolean;
    canCreateTickets: boolean;
    canUploadFiles: boolean;
    canManageChecklists: boolean;
    canChangeTicketStatus: boolean;
    canDeleteTickets: boolean;
    canModerateComments: boolean;
  };
  currentUserId: number;
  isClient: boolean;
}

export interface ProjectInsightsData {
  finances: null | {
    budget: number;
    currency: string;
    invoices: Array<{ id: number; number: string; concept: string; amount: number; paid: number; balance: number; currency: string; status: string; displayStatus: string; dueDate: string | null; clientName?: string | null; notes?: string | null; paidAt?: string | null }>;
    payables: Array<{ id: number; supplier: string; concept: string; amount: number; paid: number; balance: number; currency: string; status: string; dueDate: string | null; kind?: 'direct' | 'collaborator' }>;
    totals: { paid: number; pending: number; balance: number };
    profit?: ProjectProfit | null;
    clientSuggestions?: string[];
    canManage?: boolean;
  };
  gantt: Array<{ id: number; title: string; status: string; priority: string; start: string; due: string; parentId?: number | null; isMilestone?: boolean; assignee?: string | null; critical?: boolean; slack?: number }>;
  dependencies?: Array<{ from: number; to: number }>;
  canEditTasks?: boolean;
  milestones?: Array<{ id: number; title: string; date: string; done: boolean }>;
  canManageMilestones?: boolean;
  canEditFinances: boolean;
}

export interface CashData {
  tab: string;
  today: string;
  period: { from: string; to: string; defaultFrom: string; defaultTo: string };
  filtersActive: boolean;
  summary: {
    income: Record<string, number>;
    expense: Record<string, number>;
    net: Record<string, number>;
    balance: Record<string, number>;
    count: number;
    incomeCount: number;
    expenseCount: number;
    categories: Array<{
      name: string;
      color: string | null;
      type: string;
      currency: string;
      total: number;
      movements: number;
    }>;
  };
  accounts: Array<{
    id: number;
    name: string;
    type: string;
    currency: string;
    openingBalance: number;
    color: string;
    active: boolean;
    movements: number;
    balance: number;
  }>;
  categories: Array<{
    id: number;
    name: string;
    type: string;
    color: string;
    active: boolean;
    movements: number;
  }>;
  chart: {
    currencies: string[];
    currency: string;
    series: { labels: string[]; income: number[]; expense: number[] };
  };
  movements: Array<{
    id: number;
    type: string;
    amount: number;
    currency: string;
    occurredOn: string;
    description: string;
    reference: string | null;
    notes: string | null;
    sourceType: string;
    accountId: number;
    accountName: string;
    accountColor: string;
    categoryId: number;
    categoryName: string;
    categoryColor: string;
    projectId: number | null;
    projectName: string | null;
    projectColor: string;
    user: string | null;
  }>;
  pagination: { page: number; perPage: number; total: number; pages: number };
  projects: Array<{ id: number; name: string; currency: string }>;
  automation: null | {
    rules: Array<{
      id: number;
      kind: string;
      currency: string;
      accountId: number;
      categoryId: number;
      accountActive: boolean;
      categoryActive: boolean;
      isActive: boolean;
    }>;
    missing: Array<{ kind: string; currency: string }>;
    generated: Array<{
      id: number;
      type: string;
      amount: number;
      currency: string;
      occurredOn: string;
      description: string;
      accountName: string;
      categoryName: string;
      sourceType: string;
      invoiceNumber: string | null;
      supplier: string | null;
    }>;
    generatedPagination: { page: number; perPage: number; total: number; pages: number };
  };
  accountTypes: Record<string, string>;
  currencies: string[];
}

export type ShareAccess = 'view' | 'collaborate';

export interface SharePanelData {
  mode: 'owner' | 'guest';
  /** Solo agencia dueña: agencias conectadas y el estado de su share. */
  agencies?: Array<{
    agencyId: number;
    agencyName: string;
    agencyLogo: string | null;
    shareId: number | null;
    access: ShareAccess | null;
  }>;
  /** Solo agencia invitada. */
  share?: { id: number; access: ShareAccess; ownerAgencyName: string };
  agencyName?: string;
  members?: Array<{ id: number; name: string; email: string; role: string; avatarUrl: string | null }>;
  memberIds?: number[];
}

export interface ProjectProfit {
  budget: Record<string, number>;
  income: Record<string, number>;
  collected: Record<string, number>;
  pending: Record<string, number>;
  direct: Record<string, number>;
  directPaid: Record<string, number>;
  collaborator: Record<string, number>;
  collaboratorPaid: Record<string, number>;
  margin: Record<string, number>;
  marginPercent: number | null;
  incomeProject: number;
  marginProject: number;
  invoiceCount: number;
  mixedCurrencies: boolean;
}

export interface BaselinesData {
  baselines: Array<{ id: number; name: string; createdAt: string; author: string | null }>;
  selectedId: number | null;
  tasks: Record<string, { start: string; due: string }>;
  canManage: boolean;
}

export interface CrmDealSummary {
  deal: { id: number; title: string; stage: string; stageLabel: string; value: number; currency: string; company: { id: number; name: string } | null; convertedProjectId: number | null };
  prefill: { title: string; clientName: string; clientCompany: string; clientEmail: string; currency: string; projectId: string; item: { description: string; quantity: string; unitPrice: string } };
}

export interface CrmCompanyHistory {
  company: { id: number; name: string; industry: string | null; website: string | null; email: string | null; phone: string | null; address: string | null; notes: string | null };
  contacts: Array<{ id: number; name: string; role: string | null; email: string | null; phone: string | null }>;
  deals: Array<{
    id: number; title: string; stage: string; stageLabel: string; value: number; currency: string;
    contact: string | null; owner: string | null; expectedClose: string | null;
    convertedProjectId: number | null; convertedProjectName: string | null;
    quotes: CrmCompanyQuote[];
  }>;
  quotes: CrmCompanyQuote[];
  activities: Array<{ id: number; type: string; typeLabel: string; title: string; notes: string | null; occurredAt: string; user: string | null; dealTitle: string | null }>;
}

export interface CrmCompanyQuote { id: number; dealId: number | null; number: string; title: string; total: number; currency: string; status: string; statusLabel: string; issueDate: string }
