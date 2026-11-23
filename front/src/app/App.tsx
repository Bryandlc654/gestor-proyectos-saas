import { Suspense, lazy } from 'react';
import { Navigate, Route, Routes, RouterProvider, createBrowserRouter } from 'react-router-dom';
import { AppShell } from '../components/AppShell';
import { RouteError } from './RouteError';
import { BootstrapProvider } from '../features/auth/BootstrapProvider';
import { LoginPage, TwoFactorPage, ForgotPasswordPage, ResetPasswordPage, LegalPage, RegisterPage, VerifyEmailPage, InvitePage } from '../features/auth/AuthPages';

const DashboardPage = lazy(() => import('../features/dashboard/DashboardPage').then((module) => ({ default: module.DashboardPage })));
const ProjectsPage = lazy(() => import('../features/projects/ProjectsPage').then((module) => ({ default: module.ProjectsPage })));
const ProjectPage = lazy(() => import('../features/projects/ProjectPage').then((module) => ({ default: module.ProjectPage })));
const TasksPage = lazy(() => import('../features/tasks/TasksPage').then((module) => ({ default: module.TasksPage })));
const CalendarPage = lazy(() => import('../features/calendar/CalendarPage').then((module) => ({ default: module.CalendarPage })));
const CrmPage = lazy(() => import('../features/crm/CrmPage').then((module) => ({ default: module.CrmPage })));
const CrmCompanyPage = lazy(() => import('../features/crm/CrmCompanyPage').then((module) => ({ default: module.CrmCompanyPage })));
const BillingPage = lazy(() => import('../features/billing/BillingPage').then((module) => ({ default: module.BillingPage })));
const FinancePage = lazy(() => import('../features/finance/FinancePage').then((module) => ({ default: module.FinancePage })));
const ProfitabilityPage = lazy(() => import('../features/profitability/ProfitabilityPage').then((module) => ({ default: module.ProfitabilityPage })));
const ReportsPage = lazy(() => import('../features/reports/ReportsPage').then((module) => ({ default: module.ReportsPage })));
const QuotesPage = lazy(() => import('../features/quotes/QuotesPage').then((module) => ({ default: module.QuotesPage })));
const QuoteDetailPage = lazy(() => import('../features/quotes/QuoteDetailPage').then((module) => ({ default: module.QuoteDetailPage })));
const QuoteFormPage = lazy(() => import('../features/quotes/QuoteFormPage').then((module) => ({ default: module.QuoteFormPage })));
const SettingsPage = lazy(() => import('../features/settings/SettingsPage').then((module) => ({ default: module.SettingsPage })));
const UsersPage = lazy(() => import('../features/users/UsersPage').then((module) => ({ default: module.UsersPage })));
const TemplatesPage = lazy(() => import('../features/templates/TemplatesPage').then((module) => ({ default: module.TemplatesPage })));
const TrashPage = lazy(() => import('../features/trash/TrashPage').then((module) => ({ default: module.TrashPage })));
const SupportPage = lazy(() => import('../features/support/SupportPage').then((module) => ({ default: module.SupportPage })));
const ConnectionsPage = lazy(() => import('../features/connections/ConnectionsPage').then((module) => ({ default: module.ConnectionsPage })));
const ApiTokensPage = lazy(() => import('../features/apiTokens/ApiTokensPage').then((module) => ({ default: module.ApiTokensPage })));
const CashPage = lazy(() => import('../features/cash/CashPage').then((module) => ({ default: module.CashPage })));

function NotFoundPage() {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-10 text-center">
      <h1 className="text-base font-semibold text-slate-900">Página no encontrada</h1>
      <p className="mt-1 text-xs text-slate-500">La ruta no existe en la nueva interfaz.</p>
      <a href="/app/dashboard" className="mt-4 inline-flex rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white">Volver al dashboard</a>
    </div>
  );
}

function PageFallback() {
  return (
    <div className="space-y-4">
      <div className="h-9 w-48 animate-pulse rounded-xl bg-slate-200/70" />
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, index) => <div key={index} className="h-28 animate-pulse rounded-2xl bg-slate-200/60" />)}
      </div>
      <div className="h-72 animate-pulse rounded-2xl bg-slate-200/50" />
    </div>
  );
}

function PrivateRoutes() {
  return (
    <Suspense fallback={<PageFallback />}>
      <Routes>
        <Route element={<AppShell />}>
          <Route index element={<Navigate to="/dashboard" replace />} />
          <Route path="dashboard" element={<DashboardPage />} />
          <Route path="projects" element={<ProjectsPage />} />
          <Route path="projects/:id" element={<ProjectPage />} />
          <Route path="tasks" element={<TasksPage />} />
          <Route path="calendar" element={<CalendarPage />} />
          <Route path="crm" element={<CrmPage />} />
          <Route path="crm/companies/:id" element={<CrmCompanyPage />} />
          <Route path="billing" element={<BillingPage />} />
          <Route path="finance" element={<FinancePage />} />
          <Route path="profitability" element={<ProfitabilityPage />} />
          <Route path="reports" element={<ReportsPage />} />
          <Route path="quotes" element={<QuotesPage />} />
          <Route path="quotes/new" element={<QuoteFormPage />} />
          <Route path="quotes/:id" element={<QuoteDetailPage />} />
          <Route path="quotes/:id/edit" element={<QuoteFormPage />} />
          <Route path="settings" element={<SettingsPage />} />
          <Route path="users" element={<UsersPage />} />
          <Route path="templates" element={<TemplatesPage />} />
          <Route path="trash" element={<TrashPage />} />
          <Route path="support" element={<SupportPage />} />
          <Route path="connections" element={<ConnectionsPage />} />
          <Route path="api-tokens" element={<ApiTokensPage />} />
          <Route path="cash" element={<CashPage />} />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Routes>
    </Suspense>
  );
}

/** La landing vive en "/" (fuera del router con basename /app). */
function RedirectToHome() {
  if (typeof window !== 'undefined') window.location.replace('/');
  return null;
}

function AppRoutes() {
  return (
    <Routes>
      <Route path="login" element={<LoginPage />} />
      <Route path="welcome" element={<RedirectToHome />} />
      <Route path="register" element={<RegisterPage />} />
      <Route path="verify-email" element={<VerifyEmailPage />} />
      <Route path="invite" element={<InvitePage />} />
      <Route path="2fa" element={<TwoFactorPage />} />
      <Route path="forgot-password" element={<ForgotPasswordPage />} />
      <Route path="reset-password" element={<ResetPasswordPage />} />
      <Route path="legal/:page" element={<LegalPage />} />
      <Route path="*" element={<BootstrapProvider><PrivateRoutes /></BootstrapProvider>} />
    </Routes>
  );
}

const router = createBrowserRouter([{ path: '*', element: <AppRoutes />, errorElement: <RouteError /> }], { basename: '/app' });

export function App() {
  return <RouterProvider router={router} />;
}
