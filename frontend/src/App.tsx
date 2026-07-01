import { lazy, Suspense } from 'react';
import { Route, Routes } from 'react-router';
import { AppShell } from '@/components/layout/AppShell';
import { RequireAuth, RequireRole, RedirectIfAuthed } from '@/components/routing/guards';
import { useAuth } from '@/context/auth';

import { Landing } from '@/pages/Landing';
import { Login } from '@/pages/auth/Login';
import { Register } from '@/pages/auth/Register';
import { AcceptInvite } from '@/pages/auth/AcceptInvite';
import { ForgotPassword } from '@/pages/auth/ForgotPassword';
import { ResetPassword } from '@/pages/auth/ResetPassword';
import { VerifyEmail } from '@/pages/auth/VerifyEmail';
import { NotFound } from '@/pages/NotFound';
import { DashboardRouter } from '@/pages/DashboardRouter';

/* ---- Lazy page imports --------------------------------------------------- */

// Tenant
const BrowseListings    = lazy(() => import('@/pages/tenant/BrowseListings').then((m) => ({ default: m.BrowseListings })));
const ListingDetail     = lazy(() => import('@/pages/ListingDetail').then((m) => ({ default: m.ListingDetail })));
const SavedListings     = lazy(() => import('@/pages/tenant/SavedListings').then((m) => ({ default: m.SavedListings })));
const ApplicationsPage  = lazy(() => import('@/pages/tenant/ApplicationsPage').then((m) => ({ default: m.ApplicationsPage })));
const PaymentsPage      = lazy(() => import('@/pages/tenant/PaymentsPage').then((m) => ({ default: m.PaymentsPage })));
const MaintenancePage   = lazy(() => import('@/pages/tenant/MaintenancePage').then((m) => ({ default: m.MaintenancePage })));
const ComparePage       = lazy(() => import('@/pages/tenant/ComparePage').then((m) => ({ default: m.ComparePage })));
const VerificationCenter = lazy(() => import('@/pages/tenant/VerificationCenter').then((m) => ({ default: m.VerificationCenter })));
const MyReviews         = lazy(() => import('@/pages/tenant/MyReviews').then((m) => ({ default: m.MyReviews })));

// Landlord
const Properties        = lazy(() => import('@/pages/landlord/Properties').then((m) => ({ default: m.Properties })));
const PropertyDetail    = lazy(() => import('@/pages/landlord/PropertyDetail').then((m) => ({ default: m.PropertyDetail })));
const LandlordListings  = lazy(() => import('@/pages/landlord/LandlordListings').then((m) => ({ default: m.LandlordListings })));
const CreateListing     = lazy(() => import('@/pages/landlord/CreateListing').then((m) => ({ default: m.CreateListing })));
const Applicants        = lazy(() => import('@/pages/landlord/Applicants').then((m) => ({ default: m.Applicants })));
const TenantManagement  = lazy(() => import('@/pages/landlord/TenantManagement').then((m) => ({ default: m.TenantManagement })));
const LandlordMaintenance = lazy(() => import('@/pages/landlord/LandlordMaintenance').then((m) => ({ default: m.LandlordMaintenance })));
const LandlordLedger    = lazy(() => import('@/pages/landlord/LandlordLedger').then((m) => ({ default: m.LandlordLedger })));
const LandlordAnalytics    = lazy(() => import('@/pages/landlord/LandlordAnalytics').then((m) => ({ default: m.LandlordAnalytics })));
const LandlordVerification = lazy(() => import('@/pages/landlord/LandlordVerification').then((m) => ({ default: m.LandlordVerification })));
const LandlordReviews      = lazy(() => import('@/pages/landlord/LandlordReviews').then((m) => ({ default: m.LandlordReviews })));

// Admin
const Moderation              = lazy(() => import('@/pages/admin/Moderation').then((m) => ({ default: m.Moderation })));
const AuditLogs               = lazy(() => import('@/pages/admin/AuditLogs').then((m) => ({ default: m.AuditLogs })));
const AuditLogDetail          = lazy(() => import('@/pages/admin/AuditLogDetail').then((m) => ({ default: m.AuditLogDetail })));
const UsersPage               = lazy(() => import('@/pages/admin/UsersPage').then((m) => ({ default: m.UsersPage })));
const ManageAccessPage        = lazy(() => import('@/pages/admin/ManageAccessPage').then((m) => ({ default: m.ManageAccessPage })));
const VerificationModeration  = lazy(() => import('@/pages/admin/VerificationModeration').then((m) => ({ default: m.VerificationModeration })));
const ReviewModeration        = lazy(() => import('@/pages/admin/ReviewModeration').then((m) => ({ default: m.ReviewModeration })));

// Shared
const ContractsPage     = lazy(() => import('@/pages/shared/ContractsPage').then((m) => ({ default: m.ContractsPage })));
const ContractDetail    = lazy(() => import('@/pages/shared/ContractDetail').then((m) => ({ default: m.ContractDetail })));
const LedgerPage        = lazy(() => import('@/pages/shared/LedgerPage').then((m) => ({ default: m.LedgerPage })));
const Notifications     = lazy(() => import('@/pages/shared/Notifications').then((m) => ({ default: m.Notifications })));
const ProfilePage       = lazy(() => import('@/pages/shared/ProfilePage').then((m) => ({ default: m.ProfilePage })));
const SettingsPage      = lazy(() => import('@/pages/shared/SettingsPage').then((m) => ({ default: m.SettingsPage })));
const DocumentsPage     = lazy(() => import('@/pages/shared/DocumentsPage').then((m) => ({ default: m.DocumentsPage })));
const MessagesPage      = lazy(() => import('@/pages/shared/MessagesPage').then((m) => ({ default: m.MessagesPage })));

/* ---- Role-aware maintenance view ----------------------------------------
   Tenants raise/track requests; landlords triage/advance them. Same route,
   role-specific page (the API enforces the real scoping). */
function MaintenanceRouter() {
  const { user } = useAuth();
  if (user?.role === 'landlord') {
    return <Lazy><LandlordMaintenance /></Lazy>;
  }
  return <Lazy><MaintenancePage /></Lazy>;
}

/* ---- Role-aware rent ledger ---------------------------------------------
   Landlords get the operational Rent Ledger console; admins/tenants keep the
   shared ledger view. The API enforces the real owner scoping either way. */
function LedgerRouter() {
  const { user } = useAuth();
  if (user?.role === 'landlord') {
    return <Lazy><LandlordLedger /></Lazy>;
  }
  return <Lazy><LedgerPage /></Lazy>;
}

/* ---- Suspense wrapper ---------------------------------------------------- */
function Lazy({ children }: { children: React.ReactNode }) {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-[60vh] items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" />
        </div>
      }
    >
      {children}
    </Suspense>
  );
}

/* ---- App ----------------------------------------------------------------- */

export default function App() {
  return (
    <Routes>
      {/* Public */}
      <Route path="/" element={<Landing />} />
      <Route
        path="/login"
        element={
          <RedirectIfAuthed>
            <Login />
          </RedirectIfAuthed>
        }
      />
      <Route
        path="/register"
        element={
          <RedirectIfAuthed>
            <Register />
          </RedirectIfAuthed>
        }
      />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route path="/accept-invite" element={<AcceptInvite />} />
      <Route path="/verify-email" element={<VerifyEmail />} />

      {/* Authenticated app */}
      <Route
        path="/app"
        element={
          <RequireAuth>
            <AppShell />
          </RequireAuth>
        }
      >
        {/* Dashboard — role-switched inline (no lazy; small, always needed) */}
        <Route index element={<DashboardRouter />} />

        {/* Shared / any authenticated role */}
        <Route
          path="notifications"
          element={<Lazy><Notifications /></Lazy>}
        />
        <Route
          path="listing/:id"
          element={<Lazy><ListingDetail /></Lazy>}
        />
        <Route
          path="contracts"
          element={<Lazy><ContractsPage /></Lazy>}
        />
        <Route
          path="contracts/:id"
          element={<Lazy><ContractDetail /></Lazy>}
        />
        <Route
          path="ledger"
          element={<LedgerRouter />}
        />
        <Route
          path="profile"
          element={<Lazy><ProfilePage /></Lazy>}
        />
        <Route
          path="settings"
          element={<Lazy><SettingsPage /></Lazy>}
        />
        <Route
          path="documents"
          element={<Lazy><DocumentsPage /></Lazy>}
        />
        {/* Messages — self-contained two-pane page (no child routes) */}
        <Route
          path="messages"
          element={<Lazy><MessagesPage /></Lazy>}
        />

        {/* Tenant-only */}
        <Route
          path="browse"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><BrowseListings /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="saved"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><SavedListings /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="applications"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><ApplicationsPage /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="payments"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><PaymentsPage /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="maintenance"
          element={
            <RequireRole roles={['tenant', 'landlord']}>
              <MaintenanceRouter />
            </RequireRole>
          }
        />
        <Route
          path="compare"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><ComparePage /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="verification"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><VerificationCenter /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="reviews"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><MyReviews /></Lazy>
            </RequireRole>
          }
        />

        {/* Landlord-only */}
        <Route
          path="properties"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><Properties /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="properties/:id"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><PropertyDetail /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="listings"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LandlordListings /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="listings/create"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><CreateListing /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="applicants"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><Applicants /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="tenants"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><TenantManagement /></Lazy>
            </RequireRole>
          }
        />

        {/* Landlord analytics — real, scoped to the landlord's portfolio */}
        <Route
          path="analytics"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LandlordAnalytics /></Lazy>
            </RequireRole>
          }
        />

        {/* Landlord verification — same lifecycle as tenant verification */}
        <Route
          path="landlord-verification"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LandlordVerification /></Lazy>
            </RequireRole>
          }
        />

        {/* Landlord reviews — approved reviews on their properties + respond */}
        <Route
          path="landlord-reviews"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LandlordReviews /></Lazy>
            </RequireRole>
          }
        />

        {/* Admin-only */}
        <Route
          path="moderation"
          element={
            <RequireRole roles={['admin']}>
              <Lazy><Moderation /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="audit"
          element={
            <RequireRole roles={['admin']}>
              <Lazy><AuditLogs /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="audit/:id"
          element={
            <RequireRole roles={['admin']}>
              <Lazy><AuditLogDetail /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="users"
          element={
            <RequireRole roles={['admin']}>
              <Lazy><UsersPage /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="manage-access"
          element={
            <RequireRole roles={['admin']}>
              <Lazy><ManageAccessPage /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="verifications"
          element={
            <RequireRole roles={['admin']}>
              <Lazy><VerificationModeration /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="review-moderation"
          element={
            <RequireRole roles={['admin']}>
              <Lazy><ReviewModeration /></Lazy>
            </RequireRole>
          }
        />
      </Route>

      <Route path="*" element={<NotFound />} />
    </Routes>
  );
}
